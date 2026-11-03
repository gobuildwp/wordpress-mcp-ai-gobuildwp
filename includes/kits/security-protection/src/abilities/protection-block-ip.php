<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/protection-block-ip', [
    'label' => __('Block an IP Address', domain: 'wppilot'),
    'description' => __(
        "Blocks an IPv4 or IPv6 address or a CIDR range from the whole site, for minutes (0 = until unblocked). Ranges wider than /16 (IPv4) or /48 (IPv6) are refused, as is any block that covers the caller's own address or an address the signed-in user logged in from. Allowlisted addresses can't be blocked, and signed-in administrators are never blocked. Undo with gobuildwp/protection-unblock-ip.",
        domain: 'wppilot',
    ),
    'category' => 'security-protection',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'ip' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 64],
            'minutes' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 525600, 'default' => 0],
            'reason' => ['type' => 'string', 'maxLength' => 200],
        ],
        'required' => ['ip'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'block' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input = []): array|\WP_Error {
        require_once __DIR__ . '/../load.php';
        $block = add_block((string) ($input['ip'] ?? ''), (int) ($input['minutes'] ?? 0), (string) ($input['reason'] ?? ''), 'manual');
        if ($block instanceof \WP_Error) {
            return $block;
        }
        foreach (block_list() as $entry) {
            if ($entry['ip'] === $block['ip']) {
                return ['block' => $entry];
            }
        }
        return ['block' => null];
    },
    // Security Guardian is a GoBuildWP Cloud Pro feature: this changes the site, so it runs only for
    // a signed Cloud request. Reading what it reports stays local.
    'permission_callback' => static fn(): bool|\WP_Error => (Runtime\can_run() && current_user_can('manage_options')) ? Runtime\require_cloud_request() : false,
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'readonly' => false,
            // Not destructive: GoBuildWP Cloud never sends confirm=true, so a confirm step here would
            // refuse every block. A block is undone with protection-unblock-ip, and add_block() refuses
            // wide ranges and any block covering the caller's or the signed-in user's address.
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);
