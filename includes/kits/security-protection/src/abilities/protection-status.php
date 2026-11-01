<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/protection-status', [
    'label' => __('Website Protection Status', domain: 'wppilot'),
    'description' => __(
        "Shows this site's website protection: firewall mode, login protection, XML-RPC, user-enumeration and 404-flood settings, the allowlist, counts for the last 24 hours, the number of active blocks, the 20 newest events, and how many virtual patches are stored and active. Read-only.",
        domain: 'wppilot',
    ),
    'category' => 'security-protection',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'version' => ['type' => 'integer'],
            'settings' => ['type' => 'object'],
            'counters' => ['type' => 'object'],
            'client_ip_source' => ['type' => 'string'],
            'proxy_headers_seen' => ['type' => 'array', 'items' => ['type' => 'string']],
            'recent' => ['type' => 'array', 'items' => ['type' => 'object']],
            'virtual_patches' => [
                'type' => 'object',
                'properties' => [
                    'stored' => ['type' => 'integer'],
                    'active' => ['type' => 'integer'],
                    'active_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
        ],
    ],
    'execute_callback' => static function (array $input = []): array {
        require_once __DIR__ . '/../load.php';
        $active = array_map(static fn(array $rule): string => (string) $rule['id'], active_virtual_patches());
        return status($_SERVER) + [
            'virtual_patches' => ['stored' => count(stored_virtual_patches()['rules']), 'active' => count($active), 'active_ids' => $active],
        ];
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
