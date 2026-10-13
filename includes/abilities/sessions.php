<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/*
 * Session undo and redo over MCP. The work is in includes/change-sessions.php; these are its
 * typed entry points. Undo and redo are destructive (they overwrite the current state of every
 * target the session touched), so they go through the same confirmation contract as any other
 * destructive ability, and a read-only safety profile refuses them.
 */

wp_register_ability('wppilot/list-sessions', [
    'label' => __('List Agent Sessions', domain: 'wppilot'),
    'description' => __(
        'Lists agent sessions recorded in the GoBuildWP change ledger, most recently active first: one per MCP connection (or per stdio process, or per agent credential and client until it has been idle for 30 minutes). Each shows the agent, the time span, and how many of its changes can be undone, are undone, or cannot be undone. current_session_id is the session this call belongs to. Pass session_id to get that session\'s changes and what undo-session and redo-session would do right now: conflicts (targets changed by something else since), changes that cannot be undone or redone, and whether a run is ready.',
        domain: 'wppilot',
    ),
    'category' => 'changes',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'session_id' => ['type' => 'string', 'maxLength' => 64, 'description' => 'A session to show in full, with its undo and redo plan.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input): array|WP_Error {
        $current = wppilot_current_session_id();
        $session = trim((string) ($input['session_id'] ?? ''));
        if ($session !== '') {
            $detail = wppilot_session_detail($session, (int) ($input['limit'] ?? 100));
            return $detail instanceof WP_Error ? $detail : array_merge(['current_session_id' => $current], $detail);
        }
        $sessions = wppilot_list_change_sessions((int) ($input['limit'] ?? 20));
        return ['current_session_id' => $current, 'sessions' => $sessions, 'count' => count($sessions)];
    },
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

$wppilot_session_run_schema = [
    'type' => 'object',
    'properties' => [
        'session_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'description' => 'From wppilot/list-sessions.'],
        'allow_partial' => [
            'type' => 'boolean',
            'default' => false,
            'description' => 'Go ahead when some of the session\'s changes cannot be handled; they are listed in the result and left as they are. Without it, such a session is refused and nothing is changed.',
        ],
    ],
    'required' => ['session_id'],
    'additionalProperties' => false,
];

wp_register_ability('wppilot/undo-session', [
    'label' => __('Undo Agent Session', domain: 'wppilot'),
    'description' => __(
        'Undoes every change one agent session made, newest first, each restored and verified against its before-image. Before touching anything it checks that no target was changed by something else after the session wrote to it; if one was, nothing is changed and the conflicts are listed. It stops at the first change that fails verification and reports what was undone, what failed and what was not attempted. Changes that cannot be undone are listed, never skipped silently: the run is refused unless allow_partial is true. Check wppilot/list-sessions with the session_id first to see the plan. Undone changes can usually be put back with wppilot/redo-session; a created term or comment the undo deleted, or a target too large to copy, cannot be redone. Requires explicit confirmation.',
        domain: 'wppilot',
    ),
    'category' => 'changes',
    'input_schema' => $wppilot_session_run_schema,
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input): array|WP_Error => wppilot_undo_session(
        (string) $input['session_id'],
        allow_partial: ($input['allow_partial'] ?? false) === true,
    ),
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

wp_register_ability('wppilot/redo-session', [
    'label' => __('Redo Agent Session', domain: 'wppilot'),
    'description' => __(
        'Puts back the changes of one agent session that were undone, oldest first, each verified against the state recorded when the change was first made. Refuses without changing anything when a target was changed by something else after the undo, and stops at the first change that fails verification. Undone changes that cannot be redone (a created term or comment the undo deleted, a target too large to copy) are listed; the run is refused unless allow_partial is true. Requires explicit confirmation.',
        domain: 'wppilot',
    ),
    'category' => 'changes',
    'input_schema' => $wppilot_session_run_schema,
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input): array|WP_Error => wppilot_redo_session(
        (string) $input['session_id'],
        allow_partial: ($input['allow_partial'] ?? false) === true,
    ),
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

unset($wppilot_session_run_schema);
