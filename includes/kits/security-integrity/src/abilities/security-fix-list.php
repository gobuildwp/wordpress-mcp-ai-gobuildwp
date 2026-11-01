<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/security-fix-list', [
    'label' => __('List Security Fixes', domain: 'wppilot'),
    'description' => __(
        'Lists security fixes made on this site, newest first: fix_id, action, target, state (applied or undone), when, and the reason given. Read-only.',
        domain: 'wppilot',
    ),
    'category' => 'security-integrity',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'fixes' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
    ],
    'execute_callback' => static function (array $input = []): array {
        require_once __DIR__ . '/../load.php';
        return ['fixes' => list_fixes(max(1, min(200, (int) ($input['limit'] ?? 50))))];
    },
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_options'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);
