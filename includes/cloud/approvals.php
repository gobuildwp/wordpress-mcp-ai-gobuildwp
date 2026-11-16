<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Writes a GoBuildWP Cloud bulk job asks for, approved by a person on this site.
 *
 * Cloud never sends confirm=true and never approves for the site. When a Cloud bulk job (switch
 * maintenance mode on across sites, run a recipe for real) calls an ability that would need
 * confirm, the site queues the exact call as an approval request instead of refusing it, and
 * answers with the request id and a wp-admin link. A person approves there, on one screen for all
 * of the job's requests on this site; Cloud reads the decision from /cloud/status and retries the
 * identical call, which then spends the approval. Only then does the site set `confirm` itself.
 *
 * The job id travels in the signed tools/call body (`params._meta["co.gobuildwp/job"]`) and is
 * believed only when this site verified that signature, so no other client can open a Cloud
 * request. The approval is bound like every other: the exact input (confirm left out) plus the
 * state of what it writes to (wppilot_confirmation_target_state), so a changed target voids it.
 *
 * Which calls this covers: every ability that needs confirmation, plus the inputs listed by
 * wppilot_cloud_approval_rules() (an ability whose own code demands confirm for some inputs only).
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Where Cloud puts the bulk job id in a tools/call `_meta`. */
const WPPILOT_CLOUD_APPROVAL_JOB_META = 'co.gobuildwp/job';

/**
 * The verified Cloud job a tools/call belongs to, or '' for any other call.
 *
 * @param array<array-key, mixed> $meta The call's `params._meta`.
 */
function wppilot_cloud_approval_job_from_meta(array $meta): string
{
    $job = $meta[WPPILOT_CLOUD_APPROVAL_JOB_META] ?? null;
    if (!is_string($job) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $job) !== 1) {
        return '';
    }

    return function_exists('gobuildwp_cloud_request_verified') && gobuildwp_cloud_request_verified() ? $job : '';
}

/**
 * Inputs that need a person's approval when a Cloud job sends them, by ability: every listed key
 * must equal the given value. For abilities that check confirm in their own code, only for some inputs.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_cloud_approval_rules(): array
{
    $rules = [
        // Switching on turns visitors away; switching off stays one call with no approval.
        'wppilot/set-maintenance-mode' => ['enabled' => true],
    ];

    /**
     * Add the inputs of other abilities that need confirm only for some inputs.
     *
     * @param array<string, array<string, mixed>> $rules
     */
    $rules = apply_filters('wppilot_cloud_approval_rules', $rules);

    return is_array($rules) ? array_filter($rules, 'is_array') : [];
}

function wppilot_cloud_approval_needed(WP_Ability $ability, mixed $input): bool
{
    if (wppilot_ability_requires_confirmation($ability, $input)) {
        return true;
    }
    $rule = wppilot_cloud_approval_rules()[$ability->get_name()] ?? null;
    if (!is_array($rule) || $rule === []) {
        return false;
    }
    $values = is_array($input) ? $input : [];
    foreach ($rule as $key => $value) {
        if (!array_key_exists($key, $values) || $values[$key] !== $value) {
            return false;
        }
    }

    return true;
}

/**
 * The confirmation step for a call in a verified Cloud job.
 *
 * @return string|WP_Error|null `cloud-approval` when a person approved this exact call, the
 *         refusal (queued, denied, no user), or null when the call needs no approval.
 */
function wppilot_cloud_approval_gate(WP_Ability $ability, mixed $input, string $job): string|WP_Error|null
{
    if (!wppilot_cloud_approval_needed($ability, $input)) {
        return null;
    }
    $name = $ability->get_name();
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return new WP_Error(
            'wppilot_human_confirmation_unavailable',
            __('This call needs a person to approve it, and it is not made as a signed-in user.', domain: 'wppilot'),
            ['status' => 403, 'ability' => $name],
        );
    }

    $hash = wppilot_confirmation_call_hash($name, $input);
    if (wppilot_confirmation_claim_approval($name, $hash, $user_id)) {
        return 'cloud-approval';
    }
    if (wppilot_confirmation_find_request($name, $hash, $user_id, 'denied') !== null) {
        return new WP_Error(
            'wppilot_confirmation_denied',
            __('A person denied this exact call in wp-admin.', domain: 'wppilot'),
            ['status' => 403, 'ability' => $name],
        );
    }

    $id = wppilot_confirmation_open_request($ability, $input, 'mcp', $hash, $user_id, ['cloud_job' => $job]);
    $url = wppilot_cloud_approval_job_url($job);

    return new WP_Error(
        'wppilot_cloud_approval_required',
        sprintf(
            /* translators: 1: ability name, 2: wp-admin approval URL */
            __('"%1$s" needs a person on this site to approve it. It is waiting for approval in wp-admin: %2$s', domain: 'wppilot'),
            $name,
            $url,
        ),
        [
            'status' => 409,
            'ability' => $name,
            'cloud_approval' => [
                'request_id' => $id,
                'job' => $job,
                'approval_url' => $url,
                'expires_in' => WPPILOT_CONFIRMATION_CLOUD_REQUEST_TTL,
            ],
        ],
    );
}

/** The wp-admin screen with every request one Cloud job opened on this site. */
function wppilot_cloud_approval_job_url(string $job): string
{
    return admin_url('admin.php?page=wppilot-confirm&job=' . rawurlencode($job));
}

/**
 * A Cloud job's requests on this site, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function wppilot_cloud_approval_job_requests(string $job): array
{
    if ($job === '') {
        return [];
    }
    $found = array_values(array_filter(
        wppilot_confirmation_requests(),
        static fn(array $request): bool => (string) ($request['cloud_job'] ?? '') === $job,
    ));
    usort($found, static fn(array $a, array $b): int => (int) ($a['created_at'] ?? 0) <=> (int) ($b['created_at'] ?? 0));

    return $found;
}

/**
 * What /cloud/status reports about Cloud jobs' requests: no input, only where each one stands.
 * A request that is gone has lapsed (or was pushed out by newer ones).
 *
 * @return list<array{id: string, job: string, ability: string, status: string, created_at: int, decided_at: int|null, expires_at: int}>
 */
function wppilot_cloud_approvals_status(): array
{
    $out = [];
    foreach (wppilot_confirmation_requests() as $id => $request) {
        $job = (string) ($request['cloud_job'] ?? '');
        if ($job === '') {
            continue;
        }
        $out[] = [
            'id' => (string) $id,
            'job' => $job,
            'ability' => (string) ($request['ability'] ?? ''),
            'status' => (string) ($request['status'] ?? ''),
            'created_at' => (int) ($request['created_at'] ?? 0),
            'decided_at' => isset($request['decided_at']) ? (int) $request['decided_at'] : null,
            'expires_at' => (int) ($request['expires_at'] ?? 0),
        ];
    }

    return $out;
}

/**
 * Decide every pending request of one Cloud job at once, as one person.
 *
 * @return int How many were decided.
 */
// @mago-expect lint:no-boolean-flag-parameter
function wppilot_cloud_approval_decide_job(string $job, bool $approve, int $decided_by): int
{
    $count = 0;
    foreach (wppilot_cloud_approval_job_requests($job) as $request) {
        if (($request['status'] ?? null) === 'pending' && wppilot_confirmation_decide((string) $request['id'], $approve, $decided_by)) {
            $count++;
        }
    }

    return $count;
}
