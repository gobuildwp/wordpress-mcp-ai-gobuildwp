<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SearchReplace;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/search-replace-status', [
    'label' => __('Search and Replace Status', domain: 'wppilot'),
    'description' => __(
        'Reports a background search-replace job (job_id from search-replace-apply with background=true): queued, running, done, failed or cancelled, with progress and how many posts were applied or skipped. With plan_id, or for the job plan, it also counts the plan posts by state (pending, applied, partial, skipped, failed), lists the problem posts with their reason, and gives the group every change row of the plan shares. Read-only; only your own jobs and plans are visible. Poll every few seconds, not in a tight loop.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'job_id' => ['type' => 'string'],
            'plan_id' => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'job' => ['type' => 'object'],
            'plan' => ['type' => ['object', 'null']],
            'plan_error' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static fn(array $input = []): array|\WP_Error => status($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
