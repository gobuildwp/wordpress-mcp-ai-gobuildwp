<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/protection-feed', [
    'label' => __('Protection Feed', domain: 'wppilot'),
    'description' => __(
        'Replaces the Cloud feed on this site: a pack of extra firewall rules (id, label, where to look: path, args, ua or files, and a pattern) that run with the site\'s firewall mode, and a fleet-wide reputation blocklist of addresses and ranges (with an optional expiry) that are refused everywhere. The whole feed is replaced each time; empty lists clear it. A digest names the feed version. The allowlist and signed-in administrators are never affected. The reply counts what is stored and reports the pre-WordPress firewall, which also enforces the feed when installed.',
        domain: 'wppilot',
    ),
    'category' => 'security-protection',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'rules' => [
                'type' => 'array',
                'maxItems' => MAX_FEED_RULES,
                'items' => ['type' => 'object'],
            ],
            'blocklist' => [
                'type' => 'array',
                'maxItems' => MAX_FEED_BLOCKLIST,
                'items' => ['type' => 'object'],
            ],
            'digest' => ['type' => ['string', 'null'], 'maxLength' => 128],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'rules' => ['type' => 'integer'],
            'blocklist' => ['type' => 'integer'],
            'digest' => ['type' => ['string', 'null']],
            'set_at' => ['type' => ['string', 'null']],
            'prepend' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input = []): array|\WP_Error {
        require_once __DIR__ . '/../load.php';
        return set_feed($input);
    },
    // The feed is GoBuildWP Cloud's: only a signed Cloud request may replace it.
    'permission_callback' => static fn(): bool|\WP_Error => (Runtime\can_run() && current_user_can('manage_options')) ? Runtime\require_cloud_request() : false,
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);
