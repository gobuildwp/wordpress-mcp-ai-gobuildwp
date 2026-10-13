<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteTools;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

/** The properties that name one scheduled occurrence, as cron-list returns them. */
const EVENT_PROPERTIES = [
    'hook' => ['type' => 'string', 'minLength' => 1, 'description' => 'The event\'s hook.'],
    'timestamp' => ['type' => 'integer', 'minimum' => 1, 'description' => 'When the occurrence is scheduled (Unix time, UTC), from cron-list.'],
    'key' => ['type' => 'string', 'description' => 'The occurrence\'s argument key from cron-list. Pass this or args.'],
    'args' => ['type' => ['array', 'object'], 'description' => 'The occurrence\'s arguments, exactly as cron-list returned them. Pass this or key.'],
];

wp_register_ability('wppilot/cron-list', [
    'label' => __('List Scheduled Events', domain: 'wppilot'),
    'description' => __(
        'Lists WP-Cron events soonest first: hook, next run (Unix time and UTC), schedule and interval, arguments and their key, whether the event is due or overdue and by how long, and whether anything is hooked to it (an event with no callbacks usually belongs to a removed plugin). Also returns the registered schedules, whether WP-Cron is disabled, and whether a cron run is in progress. Filter by hook substring or due_only. Use it to diagnose missed or piled-up events, and to get the hook, timestamp and key that cron-run and cron-delete need.',
        domain: 'wppilot',
    ),
    'category' => 'diagnostics',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'hook' => ['type' => 'string', 'description' => 'Only hooks containing this text.'],
            'due_only' => ['type' => 'boolean', 'default' => false, 'description' => 'Only events whose time has come.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Cron\MAX_EVENTS, 'default' => 200],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input = []): array => Cron\list_events($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('wppilot/cron-run', [
    'label' => __('Run a Due Scheduled Event', domain: 'wppilot'),
    'description' => __(
        'Runs one due or overdue WP-Cron event now, the way wp-cron.php does: a recurring event is rescheduled for its next run, this occurrence is unscheduled, then its hook fires with its arguments, as no user and under the cron lock. Name it with hook, timestamp and key (or args) from wppilot/cron-list. Refuses an event that is not due yet, and refuses while another cron run holds the lock. Returns how long it took, any output or error, and the next run. Not undoable: whatever the hook\'s callbacks did (emails sent, posts published, data pruned) stays done, so check what the hook does before running it.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => EVENT_PROPERTIES,
        'required' => ['hook', 'timestamp'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input): array|WP_Error => Cron\run($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

wp_register_ability('wppilot/cron-delete', [
    'label' => __('Delete a Scheduled Event', domain: 'wppilot'),
    'description' => __(
        'Unschedules one WP-Cron occurrence, named by hook, timestamp and key (or args) from wppilot/cron-list; other occurrences of the same hook are left alone. Undoable from the change log: the undo schedules the same hook at the same time with the same schedule and arguments and checks it is back, and refuses if the owning plugin has already scheduled it again. A plugin usually re-adds its recurring events on its next load, so deleting one of an active plugin rarely lasts; this is for orphaned events of removed plugins and stuck single events. Destructive: requires explicit confirmation.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => EVENT_PROPERTIES + [
            'confirm' => ['type' => 'boolean', 'description' => 'true once the user has approved deleting this event.'],
        ],
        'required' => ['hook', 'timestamp'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input): array|WP_Error => Cron\delete($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
