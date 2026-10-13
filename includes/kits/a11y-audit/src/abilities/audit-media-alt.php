<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\A11yAudit;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/audit-media-alt', [
    'label' => __('Audit Media Alt Text', domain: 'wppilot'),
    'description' => __(
        'Scans the media library\'s images, newest first, one page at a time, and reports each image\'s alt text as missing, filename (the alt is just the file or camera name, e.g. IMG_0042.jpg) or ok, with a decorative_guess (tiny, a thin strip, or named like spacer, divider or background). The guess is a hint, not a verdict: look at the image with wppilot/get-media-image before writing alt="". include "issues" (default) lists only images needing work; "all" lists every image on the page. When next_page is not null, call again with page set to next_page. Read-only.',
        domain: 'wppilot',
    ),
    'category' => 'accessibility',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MAX_SCAN_PAGE, 'default' => 50],
            'include' => ['type' => 'string', 'enum' => ['issues', 'all'], 'default' => 'issues'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'images' => ['type' => 'array', 'items' => ['type' => 'object']],
            'page' => ['type' => 'integer'],
            'per_page' => ['type' => 'integer'],
            'scanned' => ['type' => 'integer'],
            'page_summary' => ['type' => 'object'],
            'total_images' => ['type' => 'integer'],
            'next_page' => ['type' => ['integer', 'null']],
        ],
    ],
    'execute_callback' => static fn(array $input = []): array => scan_media_alt($input),
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('upload_files'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
