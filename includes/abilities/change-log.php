<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/list-changes', [
    'label' => __('List GoBuildWP Changes', domain: 'wppilot'),
    'description' => __(
        'Lists the verified GoBuildWP change ledger newest first, including ability, actor, the agent credential and client behind the write, risk, timing, rollback availability, and rollback state. Secrets are redacted at write time.',
        domain: 'wppilot',
    ),
    'category' => 'changes',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50]],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input): array {
        $limit = min(200, max(1, (int) ($input['limit'] ?? 50)));
        $rows = wppilot_query_change_log([], $limit);
        return ['items' => array_map('wppilot_change_public_summary', $rows), 'count' => count($rows)];
    },
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('wppilot/get-change', [
    'label' => __('Get GoBuildWP Change', domain: 'wppilot'),
    'description' => __(
        'Returns one redacted GoBuildWP change record with rollback eligibility and verification details.',
        domain: 'wppilot',
    ),
    'category' => 'changes',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['change_id' => ['type' => 'string']],
        'required' => ['change_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input): array|WP_Error {
        $entry = wppilot_get_change((string) $input['change_id']);
        return $entry !== null ? wppilot_change_for_output($entry) : new WP_Error('wppilot_change_not_found', __('Change record not found.', domain: 'wppilot'));
    },
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('wppilot/rollback-change', [
    'label' => __('Rollback GoBuildWP Change', domain: 'wppilot'),
    'description' => __(
        'Rolls back a reversible change and verifies the observed state against the stored before-image fingerprint. It never marks success when writes fail or verification differs. A file change whose files changed again afterwards is refused with changed_since, so later edits are not overwritten; pass force=true only when the person agrees to lose them. Requires explicit confirmation.',
        domain: 'wppilot',
    ),
    'category' => 'changes',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'change_id' => ['type' => 'string'],
            'force' => [
                'type' => 'boolean',
                'description' => 'Undo a file change even though its files changed after it, overwriting those later changes.',
            ],
        ],
        'required' => ['change_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input): array|WP_Error => wppilot_rollback_change(
        (string) $input['change_id'],
        ($input['force'] ?? false) === true,
    ),
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

/** @param array<string, mixed> $entry @return array<string, mixed> */
function wppilot_change_public_summary(array $entry): array
{
    $rollback = is_array($entry['rollback'] ?? null) ? $entry['rollback'] : [];
    $summary = [
        'id' => (string) ($entry['id'] ?? ''),
        // Rows written before 1.14.0 carry no kind; every one of them was a change.
        'kind' => (string) ($entry['kind'] ?? 'change'),
        'ability' => (string) ($entry['ability'] ?? ''),
        'risk' => (string) ($entry['risk'] ?? ''),
        'recorded_at' => (string) ($entry['recorded_at'] ?? ''),
        // @mago-expect analysis:invalid-type-cast -- Ledger durations are stored internally as floats.
        'duration_ms' => (float) ($entry['duration_ms'] ?? 0),
        'user' => $entry['user'] ?? [],
        'agent' => $entry['agent'] ?? [],
        // The agent session the write belongs to, for wppilot/undo-session. Empty for writes no
        // agent made and for rows recorded before 1.16.0.
        'session_id' => (string) ($entry['session'] ?? ''),
        'reversible' => ($rollback['reversible'] ?? false) === true,
        'rollback_reason' => (string) ($rollback['reason'] ?? ''),
        'rolled_back' => ($entry['rolled_back'] ?? false) === true,
        // Only present when the design gate found something. Omitted rather
        // than reported empty, so a clean ledger stays readable.
        'design' => is_array($entry['design'] ?? null) && $entry['design'] !== [] ? $entry['design'] : null,
    ];
    // A row undone from a restore point says until when (undo_until, undo_window_hours), and reads
    // as not reversible once the restore point has expired.
    return function_exists('wppilot_restore_point_summary_fields')
        ? array_merge($summary, wppilot_restore_point_summary_fields($entry))
        : $summary;
}
