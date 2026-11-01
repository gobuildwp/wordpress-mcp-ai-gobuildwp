<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/protection-events', [
    'label' => __('List Website Protection Events', domain: 'wppilot'),
    'description' => __(
        'Lists website protection events, newest first: firewall hits, failed logins and lockouts, blocks and unblocks, enumeration and 404-flood blocks, and settings changes. Each has the address, rule and path, never request values. Kept 30 days, at most 500. Read-only.',
        domain: 'wppilot',
    ),
    'category' => 'security-protection',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100],
            'type' => ['type' => 'string', 'enum' => ['firewall', 'login', 'block', 'enumeration', 'flood', 'settings']],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'events' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
    ],
    'execute_callback' => static function (array $input = []): array {
        require_once __DIR__ . '/../load.php';
        $type = isset($input['type']) && in_array($input['type'], EVENT_TYPES, true) ? $input['type'] : null;
        return ['events' => events(max(1, min(500, (int) ($input['limit'] ?? 100))), $type)];
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
