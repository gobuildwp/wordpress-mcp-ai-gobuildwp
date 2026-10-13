<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Preview;

use WP_Ability;
use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Build, apply and discard previews.
 *
 * Both entry points — the wppilot/apply-preview ability and the admin screen's
 * Apply button — funnel through apply() rather than each running their own
 * sequence. Letting a screen and an ability diverge on a security-relevant path
 * is the mistake this plugin already carries elsewhere, where four transports
 * each re-implement the confirmation gate.
 */

/**
 * Whether the current request is the trusted inner caller.
 *
 * apply() executes the underlying ability directly, which happens to miss the
 * REST and legacy-MCP filters the require-preview gate attaches to. Relying on
 * that would be relying on an implementation detail of where the filters sit,
 * so the state is set explicitly instead — the role wppilot_change_is_suppressed()
 * already plays for rollback, with the same try/finally discipline.
 */
function apply_in_progress(?bool $set = null): bool
{
    static $in_progress = false;
    if ($set !== null) {
        $in_progress = $set;
    }
    return $in_progress;
}

/**
 * Compute a preview without writing anything.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity -- Every branch is a distinct refusal a caller must be able to tell apart.
// @mago-expect lint:halstead -- Same: naming each outcome explicitly is the contract.
function build(string $ability_name, array $input): array|WP_Error
{
    $ability_name = (string) \wppilot_sanitize_requested_ability_name($ability_name);
    if (!\wppilot_is_valid_ability_name($ability_name)) {
        return new WP_Error('wppilot_preview_invalid_ability', 'Ability name is not valid.', ['status' => 400]);
    }

    $unsupported = Projectors\unsupported_reason($ability_name);
    if ($unsupported !== null) {
        return [
            'supported' => false,
            'ability' => $ability_name,
            'reason' => $unsupported['reason'],
            'message' => $unsupported['message'],
            'diff' => null,
        ];
    }

    $ability = resolve_ability($ability_name);
    if (!$ability instanceof WP_Ability) {
        return unavailable_error($ability_name);
    }

    // The preview must not describe a write the profile forbids.
    $allowed = \wppilot_safety_check_ability($ability);
    if (is_wp_error($allowed)) {
        return $allowed;
    }

    $before = \wppilot_capture_before_image($ability_name, $input);
    if ($before === null) {
        return [
            'supported' => false,
            'ability' => $ability_name,
            'reason' => 'target_not_found',
            'message' => 'The target of this call could not be read, so there is no before-state to compare against. '
                . 'Check the id in the input.',
            'diff' => null,
        ];
    }

    if (($before['type'] ?? '') === 'oversize') {
        return [
            'supported' => false,
            'ability' => $ability_name,
            'reason' => 'target_too_large',
            'message' => sprintf(
                'The target is %d bytes, above the %d-byte snapshot limit, so only a partial before-image could be '
                    . 'captured. A diff against a partial snapshot would be misleading, so none is offered.',
                (int) ($before['bytes'] ?? 0),
                WPPILOT_CHANGE_SNAPSHOT_MAX_BYTES,
            ),
            'diff' => null,
        ];
    }

    // Run the real precondition chain. A preview that cannot predict a refusal
    // is worse than no preview: it launders an uncertain write into a reviewed
    // one, and the human's trust in the Apply button is the whole feature.
    $would_fail = precondition_error($ability, $input);
    if ($would_fail instanceof WP_Error) {
        return [
            'supported' => true,
            'would_fail' => true,
            'ability' => $ability_name,
            'error' => ['code' => $would_fail->get_error_code(), 'message' => $would_fail->get_error_message()],
            'diff' => null,
        ];
    }

    $projector = Projectors\registry()[$ability_name];
    /** @var array<string, mixed>|WP_Error $after */
    $after = $projector($before, $input);
    if (is_wp_error($after)) {
        return $after;
    }

    /** @var list<string> $excluded */
    $excluded = is_array($before['excluded_meta_keys'] ?? null) ? $before['excluded_meta_keys'] : [];
    $diff = Diff\compare($before, $after, $excluded);
    $diff['unpredicted'] = Projectors\unpredicted_for($ability_name, $input);

    $warnings = [];
    if ($excluded !== []) {
        $warnings[] = sprintf(
            'This target has %d credential-shaped meta key(s) that snapshots never capture. A change to those is not '
                . 'shown here and does not affect drift detection.',
            count($excluded),
        );
    }
    if ($diff['changed_count'] === 0) {
        $warnings[] = 'This call would not change anything. The values it sends already match what is stored.';
    }

    $input_payload = Store\encode_input($input);
    if (is_wp_error($input_payload)) {
        return $input_payload;
    }

    $now = time();
    $record = [
        'ability' => $ability_name,
        // This copy is safe for logs and diagnostics only. Apply always uses
        // the authenticated encrypted payload, never the lossy redaction.
        'input' => \wppilot_redact_for_log($input),
        'input_payload' => $input_payload,
        'risk' => \wppilot_ability_risk($ability),
        'supported' => true,
        'would_fail' => false,
        'target' => target_summary($before),
        'base_fingerprint' => (string) ($before['fingerprint'] ?? \wppilot_snapshot_fingerprint($before)),
        'scope_fingerprint' => scope_fingerprint($before, $diff),
        'diff' => $diff,
        'side_effects' => Projectors\side_effects_for($ability_name, $input),
        'warnings' => $warnings,
        'created_by' => ['id' => get_current_user_id(), 'login' => wp_get_current_user()->user_login ?? ''],
        'agent' => \wppilot_current_agent(),
        'created_at' => gmdate('c', $now),
        'expires_at' => gmdate('c', $now + Store\TTL_SECONDS),
    ];

    $id = Store\create($record);
    if (is_wp_error($id)) {
        return $id;
    }
    $record = Store\get($id) ?? $record;
    $record['preview_url'] = preview_url($id);
    $record['apply_ability'] = 'wppilot/apply-preview';
    $record['apply_params'] = ['preview_id' => $id, 'confirm' => true];
    $record['user_instruction'] = sprintf(
        'Open %s to review this diff and apply or discard it, or call wppilot/apply-preview with preview_id "%s" '
            . 'once the user has approved it. Nothing has been written yet.',
        $record['preview_url'],
        $id,
    );

    return $record;
}

/**
 * Apply a stored preview.
 *
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity -- The re-checks before execute() are the security contract; each must be its own refusal.
// @mago-expect lint:halstead -- Same.
// @mago-expect lint:no-boolean-flag-parameter -- `confirm` mirrors the safety layer's own control field.
function apply(string $preview_id, bool $confirm): array|WP_Error
{
    $record = Store\get($preview_id);
    if ($record === null) {
        return new WP_Error('wppilot_preview_not_found', 'No such preview.', ['status' => 404]);
    }

    $status = (string) ($record['status'] ?? '');
    if ($status === Store\STATUS_EXPIRED) {
        return new WP_Error(
            'wppilot_preview_expired',
            'This preview has expired. Run wppilot/preview-ability again to see the current diff.',
            ['status' => 409, 'preview_id' => $preview_id],
        );
    }
    if ($status !== Store\STATUS_PENDING) {
        return new WP_Error(
            'wppilot_preview_not_pending',
            sprintf('This preview is %s and cannot be applied.', $status),
            ['status' => 409, 'preview_id' => $preview_id, 'preview_status' => $status],
        );
    }

    // Compare-and-set, so two administrators clicking Apply cannot both proceed.
    if (!Store\claim_lock($preview_id)) {
        return new WP_Error(
            'wppilot_preview_apply_raced',
            'Another request is already applying this preview.',
            ['status' => 409, 'preview_id' => $preview_id],
        );
    }

    try {
        // The status checked before claim_lock() may have changed while this
        // request was waiting. Re-read it under the shared apply/discard lock.
        $record = Store\get($preview_id);
        if ($record === null) {
            return new WP_Error('wppilot_preview_not_found', 'No such preview.', ['status' => 404]);
        }
        $status = (string) ($record['status'] ?? '');
        if ($status !== Store\STATUS_PENDING) {
            return new WP_Error(
                'wppilot_preview_not_pending',
                sprintf('This preview is %s and cannot be applied.', $status),
                ['status' => 409, 'preview_id' => $preview_id, 'preview_status' => $status],
            );
        }

        $ability_name = (string) ($record['ability'] ?? '');
        $ability = resolve_ability($ability_name);
        if (!$ability instanceof WP_Ability) {
            return unavailable_error($ability_name);
        }

        // The safety profile and confirmation gates live in the transport layers,
        // not inside WP_Ability::execute(). Executing an ability by name without
        // re-running them here would make apply-preview a universal dispatcher
        // that bypasses both — the same hazard the safety layer special-cases
        // mcp-adapter-execute-ability by name to close.
        $allowed = \wppilot_safety_check_ability($ability);
        if (is_wp_error($allowed)) {
            return $allowed;
        }
        if (\wppilot_ability_requires_confirmation($ability) && !$confirm) {
            return \wppilot_confirmation_required_error($ability);
        }

        $payload = is_string($record['input_payload'] ?? null) ? $record['input_payload'] : '';
        if ($payload === '') {
            Store\update($preview_id, ['status' => Store\STATUS_FAILED]);
            return new WP_Error(
                'wppilot_preview_legacy_payload',
                'This preview predates secure exact-input storage and cannot be applied. Create a new preview.',
                ['status' => 409, 'preview_id' => $preview_id],
            );
        }

        /** @var mixed $decoded_input */
        $decoded_input = Store\decode_input($payload);
        if (is_wp_error($decoded_input)) {
            Store\update($preview_id, [
                'status' => Store\STATUS_FAILED,
                'error' => [
                    'code' => $decoded_input->get_error_code(),
                    'message' => $decoded_input->get_error_message(),
                ],
            ]);
            return $decoded_input;
        }
        if (!is_array($decoded_input)) {
            Store\update($preview_id, ['status' => Store\STATUS_FAILED]);
            return new WP_Error(
                'wppilot_preview_payload_invalid',
                'The stored preview input is not an object.',
                ['status' => 409, 'preview_id' => $preview_id],
            );
        }
        /** @var array<string, mixed> $input */
        $input = $decoded_input;

        // Re-read through the ledger's own capture, so a preview and the ledger
        // can never disagree about what the target is.
        $current = \wppilot_capture_before_image($ability_name, $input);
        if ($current === null || ($current['type'] ?? '') === 'oversize') {
            Store\update($preview_id, ['status' => Store\STATUS_CONFLICTED]);
            return new WP_Error(
                'wppilot_preview_target_missing',
                'The target could not be re-read, so this preview cannot be applied safely.',
                ['status' => 409, 'preview_id' => $preview_id],
            );
        }

        /** @var array<string, mixed> $diff */
        $diff = is_array($record['diff'] ?? null) ? $record['diff'] : [];
        $observed_scope = scope_fingerprint($current, $diff);
        $expected_scope = (string) ($record['scope_fingerprint'] ?? '');

        if ($expected_scope !== '' && !hash_equals($expected_scope, $observed_scope)) {
            Store\update($preview_id, ['status' => Store\STATUS_CONFLICTED]);
            return new WP_Error(
                'wppilot_preview_drifted',
                'The target changed after this preview was created. Nothing was applied. Run '
                    . 'wppilot/preview-ability again to see the current diff.',
                [
                    'status' => 409,
                    'preview_id' => $preview_id,
                    'ability' => $ability_name,
                    'expected_fingerprint' => $expected_scope,
                    'observed_fingerprint' => $observed_scope,
                ],
            );
        }

        $warnings = [];
        $observed_base = (string) ($current['fingerprint'] ?? '');
        $expected_base = (string) ($record['base_fingerprint'] ?? '');
        if ($expected_base !== '' && $observed_base !== '' && !hash_equals($expected_base, $observed_base)) {
            // Something moved, but not in a field this write touches or the
            // reviewer saw. Reported, not refused: gating on the full snapshot
            // means any plugin bumping an unrelated meta key blocks every apply.
            $warnings[] = 'The target changed in fields outside this diff since the preview was created. The fields '
                . 'this write touches are unchanged, so it was applied.';
        }

        // Fail closed: a crash between here and the result leaves the record
        // non-reapplyable rather than pending.
        Store\update($preview_id, ['status' => Store\STATUS_APPLYING, 'applying_at' => gmdate('c')]);

        apply_in_progress(true);
        try {
            /** @var mixed $result */
            $result = $ability->execute($input);
        } finally {
            apply_in_progress(false);
        }

        if (is_wp_error($result)) {
            Store\update($preview_id, [
                'status' => Store\STATUS_FAILED,
                'error' => ['code' => $result->get_error_code(), 'message' => $result->get_error_message()],
            ]);
            return $result;
        }

        Store\update($preview_id, ['status' => Store\STATUS_APPLIED, 'applied_at' => gmdate('c')]);

        return [
            'preview_id' => $preview_id,
            'applied' => true,
            'ability' => $ability_name,
            'result' => $result,
            'warnings' => $warnings,
        ];
    } finally {
        Store\release_lock($preview_id);
    }
}

/**
 * @return array<string, mixed>|WP_Error
 */
function discard(string $preview_id): array|WP_Error
{
    $record = Store\get($preview_id);
    if ($record === null) {
        return new WP_Error('wppilot_preview_not_found', 'No such preview.', ['status' => 404]);
    }
    if (!Store\claim_lock($preview_id)) {
        return new WP_Error(
            'wppilot_preview_discard_raced',
            'Another request is applying or discarding this preview.',
            ['status' => 409, 'preview_id' => $preview_id],
        );
    }

    try {
        $record = Store\get($preview_id);
        if ($record === null) {
            return new WP_Error('wppilot_preview_not_found', 'No such preview.', ['status' => 404]);
        }
        $status = (string) ($record['status'] ?? '');
        if ($status !== Store\STATUS_PENDING) {
            return new WP_Error(
                'wppilot_preview_not_pending',
                sprintf('This preview is %s and cannot be discarded.', $status),
                ['status' => 409, 'preview_id' => $preview_id, 'preview_status' => $status],
            );
        }

        Store\update($preview_id, ['status' => Store\STATUS_DISCARDED, 'discarded_at' => gmdate('c')]);
        return ['preview_id' => $preview_id, 'discarded' => true];
    } finally {
        Store\release_lock($preview_id);
    }
}

/**
 * Hash only what the reviewer saw plus what the projection read.
 *
 * Gating drift on the full snapshot fingerprint would trip on any plugin that
 * touches an unrelated meta key between preview and apply — _edit_lock, a view
 * counter, an SEO recalculation — and a feature that refuses constantly gets
 * switched off. Scoping is also the more correct rule: drift matters exactly
 * when someone changed something this write would overwrite, or something the
 * human was shown.
 *
 * @param array<string, mixed> $snapshot
 * @param array<string, mixed> $diff
 */
function scope_fingerprint(array $snapshot, array $diff): string
{
    /** @var list<array<string, mixed>> $entries */
    $entries = is_array($diff['entries'] ?? null) ? $diff['entries'] : [];

    $scoped = [];
    foreach ($entries as $entry) {
        /** @var list<string> $path */
        $path = is_array($entry['path'] ?? null) ? array_map('strval', $entry['path']) : [];
        if ($path === []) {
            continue;
        }
        $scoped[implode('.', $path)] = value_at($snapshot, $path);
    }

    ksort($scoped);
    return \wppilot_snapshot_fingerprint($scoped);
}

/**
 * @param array<string, mixed> $snapshot
 * @param list<string> $path
 */
function value_at(array $snapshot, array $path): mixed
{
    /** @var mixed $cursor */
    $cursor = $snapshot;
    foreach ($path as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            return null;
        }
        /** @var mixed $cursor */
        $cursor = $cursor[$segment];
    }
    return $cursor;
}

/**
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>
 */
function target_summary(array $snapshot): array
{
    $type = (string) ($snapshot['type'] ?? 'unknown');
    /** @var array<string, mixed> $post */
    $post = is_array($snapshot['post'] ?? null) ? $snapshot['post'] : [];

    if ($post !== []) {
        return [
            'kind' => $type,
            'id' => (int) ($post['ID'] ?? 0),
            'label' => (string) ($post['post_title'] ?? ''),
            'post_type' => (string) ($post['post_type'] ?? ''),
        ];
    }

    if ($type === 'settings') {
        /** @var array<string, mixed> $values */
        $values = is_array($snapshot['values'] ?? null) ? $snapshot['values'] : [];
        return ['kind' => 'settings', 'id' => 0, 'label' => implode(', ', array_map('strval', array_keys($values)))];
    }

    return ['kind' => $type, 'id' => 0, 'label' => ''];
}

/**
 * Run the ability's own gates without writing.
 *
 * Schema validation and the permission callback are the two the Abilities API
 * exposes; the per-object capability and builder guards run inside the execute
 * callback and cannot be reached without calling it, which is why a preview
 * never promises the apply will succeed.
 *
 * @param array<string, mixed> $input
 */
function precondition_error(WP_Ability $ability, array $input): ?WP_Error
{
    if (method_exists($ability, 'has_permission')) {
        /** @var mixed $permitted */
        $permitted = $ability->has_permission($input);
        if (is_wp_error($permitted)) {
            return $permitted;
        }
        if ($permitted === false) {
            return new WP_Error(
                'ability_invalid_permissions',
                sprintf('Ability "%s" does not have necessary permission.', $ability->get_name()),
                ['status' => 403],
            );
        }
    }

    return null;
}

/**
 * Look an ability up without tripping a notice when it is absent.
 *
 * wp_get_ability() calls _doing_it_wrong() for a name the registry does not
 * hold, and an absent name is an ordinary outcome here rather than a mistake:
 * the safety layer unregisters abilities the active profile blocks, so asking
 * about one on Read Only is expected and must not fill the debug log. Guarding
 * with wp_has_ability() first is the pattern discover-abilities.php already uses.
 */
function resolve_ability(string $ability_name): ?WP_Ability
{
    if (!function_exists('wp_has_ability') || !function_exists('wp_get_ability')) {
        return null;
    }
    if (!wp_has_ability($ability_name)) {
        return null;
    }

    $ability = wp_get_ability($ability_name);

    return $ability instanceof WP_Ability ? $ability : null;
}

function unavailable_error(string $ability_name): WP_Error
{
    $hint = 'It may never have been registered, it may be switched off on the Abilities screen, or the active '
        . 'safety profile may block it.';

    if (function_exists('wppilot_get_ability_rules')) {
        $rules = \wppilot_get_ability_rules();
        if (($rules[$ability_name]['disabled'] ?? false) === true) {
            $hint = 'It is switched off on the GoBuildWP Abilities screen.';
        }
    }

    if (function_exists('wppilot_get_safety_profile')) {
        $profile = \wppilot_get_safety_profile();
        $hint .= sprintf(' The active safety profile is "%s".', $profile);
    }

    return new WP_Error(
        'wppilot_preview_ability_unavailable',
        sprintf('Ability "%s" is not available on this site. %s', $ability_name, $hint),
        ['status' => 404, 'ability' => $ability_name, 'reason' => 'ability_unavailable'],
    );
}

function preview_url(string $id): string
{
    return add_query_arg(
        ['page' => 'wppilot-preview', 'preview' => $id],
        admin_url('admin.php'),
    );
}
