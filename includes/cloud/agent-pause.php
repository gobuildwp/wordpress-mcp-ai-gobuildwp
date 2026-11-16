<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The Cloud kill switch: POST /wppilot/v1/cloud/agents-pause and /wppilot/v1/cloud/agents-resume.
 *
 * Only the linked Cloud token reaches them (wppilot_cloud_rest_permission()), and only with a
 * site-bound signature this site verified: `X-GoBuildWP-Site-Sig` covers the method, the route (so
 * the command: a pause signature cannot be replayed as a resume), the body, this site's Cloud id,
 * the timestamp and a nonce claimed once (includes/cloud/request-signing.php). A site that holds no
 * Cloud key yet refuses both, so an unsigned request can never pause or resume anything.
 *
 * Both routes are exempt from the pause REST guard, or a paused site could never be resumed from
 * Cloud; the signature check still runs on them. Each command is written to the change ledger and
 * the approval log, with the reason and the Cloud person who pressed it.
 *
 * "Only resume locally" (WPPILOT_AGENTS_RESUME_LOCAL_ONLY_OPTION) makes the site refuse a remote
 * resume with `agents_resume_local_only`; pausing stays allowed, because stopping agents only ever
 * takes access away. A wp-admin administrator can always resume from the admin bar or Settings.
 */

if (!defined('ABSPATH')) {
    exit();
}

function wppilot_cloud_register_agent_pause_routes(): void
{
    $args = [
        'reason' => ['type' => 'string', 'required' => false],
        'actor' => ['type' => 'string', 'required' => false],
    ];
    register_rest_route('wppilot/v1', '/cloud/agents-pause', [
        'methods' => 'POST',
        'callback' => 'wppilot_cloud_rest_agents_pause',
        'permission_callback' => 'wppilot_cloud_rest_permission',
        'args' => $args,
    ]);
    register_rest_route('wppilot/v1', '/cloud/agents-resume', [
        'methods' => 'POST',
        'callback' => 'wppilot_cloud_rest_agents_resume',
        'permission_callback' => 'wppilot_cloud_rest_permission',
        'args' => $args,
    ]);
}

/**
 * Refuse anything but a verified, site-bound Cloud signature. Fails closed without a stored key.
 */
function wppilot_cloud_agent_pause_signed(WP_REST_Request $request): ?WP_Error
{
    $signed = function_exists('gobuildwp_cloud_request_verified')
        && gobuildwp_cloud_request_verified()
        && $request->get_header('X-GoBuildWP-Site-Sig') !== null;
    if ($signed) {
        return null;
    }

    return new WP_Error(
        'wppilot_cloud_signature_required',
        __('Pausing or resuming agents from GoBuildWP Cloud needs a request signed for this site.', domain: 'wppilot'),
        ['status' => 403],
    );
}

/**
 * The body Cloud sends: {reason, actor}, both plain text.
 *
 * @return array{reason: string, actor: string}
 */
function wppilot_cloud_agent_pause_body(WP_REST_Request $request): array
{
    /** @var mixed $json */
    $json = $request->get_json_params();
    $json = is_array($json) ? $json : [];
    /** @var mixed $reason */
    $reason = $json['reason'] ?? $request->get_param('reason');
    /** @var mixed $actor */
    $actor = $json['actor'] ?? $request->get_param('actor');

    return [
        'reason' => wppilot_agents_pause_clean(is_string($reason) ? $reason : '', 500),
        'actor' => wppilot_agents_pause_clean(is_string($actor) ? $actor : '', 191),
    ];
}

/**
 * One change-ledger row and one approval-log row for a Cloud command.
 *
 * @param array{reason: string, actor: string} $body
 * @return string The ledger row id, '' when the ledger could not record it.
 */
function wppilot_cloud_agent_pause_record(string $command, array $body): string
{
    $paused = $command === 'pause';
    $change_id = '';
    if (function_exists('wppilot_ledger_record_items')) {
        $recorded = wppilot_ledger_record_items($paused ? 'wppilot/cloud-agents-pause' : 'wppilot/cloud-agents-resume', [[
            'input' => ['reason' => $body['reason'], 'cloud_actor' => $body['actor'], 'source' => 'cloud'],
            'before' => null,
            'result' => ['paused' => $paused],
            'irreversible_reason' => $paused
                ? 'Undo a pause by resuming agents (admin bar, Settings, wp wppilot pause off, or GoBuildWP Cloud).'
                : 'Undo a resume by pausing agents again.',
        ]]);
        $change_id = (string) ($recorded['change_ids'][0] ?? '');
    }
    if (function_exists('wppilot_approval_log_record')) {
        wppilot_approval_log_record($paused ? 'agents-paused' : 'agents-resumed', [
            'user_id' => get_current_user_id(),
            'agent' => 'GoBuildWP Cloud' . ($body['actor'] !== '' ? ' (' . $body['actor'] . ')' : ''),
            'detail' => $body['reason'],
        ]);
    }

    return $change_id;
}

function wppilot_cloud_rest_agents_pause(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    $refused = wppilot_cloud_agent_pause_signed($request);
    if ($refused !== null) {
        return $refused;
    }
    $body = wppilot_cloud_agent_pause_body($request);
    $was = wppilot_agents_paused();
    wppilot_set_agents_paused(true, ['source' => 'cloud', 'reason' => $body['reason'], 'actor' => $body['actor']]);
    // Already paused (here or by Cloud): nothing changed, so nothing is written to the ledger.
    $change_id = $was ? '' : wppilot_cloud_agent_pause_record('pause', $body);

    return new WP_REST_Response([
        'paused' => wppilot_agents_paused(),
        'changed' => !$was,
        'change_id' => $change_id,
        'status' => wppilot_agents_pause_status(),
    ], 200);
}

function wppilot_cloud_rest_agents_resume(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    $refused = wppilot_cloud_agent_pause_signed($request);
    if ($refused !== null) {
        return $refused;
    }
    if (wppilot_agents_resume_local_only()) {
        return new WP_Error(
            'agents_resume_local_only',
            __('This site only lets a person in its wp-admin resume agents. Resume them there.', domain: 'wppilot'),
            ['status' => 403],
        );
    }
    if (wppilot_agents_pause_forced()) {
        return new WP_Error(
            'agents_pause_pinned',
            __('Agents are pinned paused by WPPILOT_AGENTS_PAUSED in wp-config.php.', domain: 'wppilot'),
            ['status' => 409],
        );
    }
    $body = wppilot_cloud_agent_pause_body($request);
    $was = wppilot_agents_paused();
    wppilot_set_agents_paused(false);
    $change_id = $was ? wppilot_cloud_agent_pause_record('resume', $body) : '';

    return new WP_REST_Response([
        'paused' => wppilot_agents_paused(),
        'changed' => $was,
        'change_id' => $change_id,
        'status' => wppilot_agents_pause_status(),
    ], 200);
}
