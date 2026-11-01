<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\NotFoundLog;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/not-found-log', [
    'label' => __('List 404 Pages', domain: 'wppilot'),
    'description' => __(
        'Lists the paths visitors hit that returned "page not found" (404), most-hit first: path (no query string), hit count, the referring site\'s host and when it was last seen (Unix time). Up to 200 distinct paths are kept; a new path replaces the least-hit one. Use it to find broken internal links and moved pages that need a redirect.',
        domain: 'wppilot',
    ),
    'category' => 'diagnostics',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MAX_PATHS, 'default' => 100],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input = []): array => entries($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
