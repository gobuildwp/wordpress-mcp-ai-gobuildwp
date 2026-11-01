<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/protection-unblock-ip', [
    'label' => __('Unblock an IP Address', domain: 'wppilot'),
    'description' => __(
        'Removes the block on an address or range, whether manual, a login lockout, or an automatic firewall or 404-flood block, and clears its failed-login count.',
        domain: 'wppilot',
    ),
    'category' => 'security-protection',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'ip' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 64],
        ],
        'required' => ['ip'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'removed' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input = []): array {
        require_once __DIR__ . '/../load.php';
        return ['removed' => remove_block((string) ($input['ip'] ?? ''))];
    },
    // Security Guardian is a GoBuildWP Cloud Pro feature: this changes the site, so it runs only for
    // a signed Cloud request. Reading what it reports stays local.
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
