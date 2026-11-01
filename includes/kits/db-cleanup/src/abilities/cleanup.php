<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\DbCleanup;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

const INPUT_PROPERTIES = [
    'targets' => [
        'type' => 'array',
        'items' => ['type' => 'string', 'enum' => TARGETS],
        'description' => 'What to clean: spam_comments, trash_comments, revisions. Defaults to all three.',
    ],
    'older_than_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650, 'default' => DEFAULT_DAYS, 'description' => 'Only rows older than this many days.'],
    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MAX_LIMIT, 'default' => MAX_LIMIT, 'description' => 'Most rows to remove in this call, across all targets.'],
];

wp_register_ability('wppilot/db-cleanup-preview', [
    'label' => __('Preview Database Cleanup', domain: 'wppilot'),
    'description' => __(
        'Counts the spam comments, trashed comments and post revisions older than older_than_days that wppilot/db-cleanup would remove. Changes nothing.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => INPUT_PROPERTIES,
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input = []): array => preview($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('wppilot/db-cleanup', [
    'label' => __('Clean Up the Database', domain: 'wppilot'),
    'description' => __(
        'Permanently removes spam comments, trashed comments and post revisions older than older_than_days, oldest first, up to limit rows. Each removed row is recorded with its meta, so the change log can put it back with its original id. Run wppilot/db-cleanup-preview first. Destructive: requires explicit confirmation.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => INPUT_PROPERTIES + [
            'confirm' => ['type' => 'boolean', 'description' => 'true once the user has approved this cleanup.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input = []): array|WP_Error => cleanup($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
