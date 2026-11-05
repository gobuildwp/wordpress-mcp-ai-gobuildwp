<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/protection-prepend', [
    'label' => __('Pre-WordPress Firewall', domain: 'wppilot'),
    'description' => __(
        'Installs or removes the pre-WordPress firewall: a small PHP file under wp-content/gobuildwp-protection that PHP loads through auto_prepend_file (from .user.ini, and .htaccess under mod_php) before WordPress and every plugin, and that refuses blocked addresses, the reputation blocklist, XML-RPC when it is off and, with the firewall in block mode, the firewall rules. Blocks keep working while the site is broken. Requests with a WordPress login cookie or an Authorization header are left to WordPress. The reply says whether it is installed, how, whether this request passed through it, and what is in the way if it could not be installed.',
        domain: 'wppilot',
    ),
    'category' => 'security-protection',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'enabled' => ['type' => 'boolean'],
        ],
        'required' => ['enabled'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'installed' => ['type' => 'boolean'],
            'method' => ['type' => ['string', 'null']],
            'active' => ['type' => 'boolean'],
            'problem' => ['type' => ['string', 'null']],
        ],
    ],
    'execute_callback' => static function (array $input = []): array|\WP_Error {
        require_once __DIR__ . '/../load.php';
        if (!is_bool($input['enabled'] ?? null)) {
            return new \WP_Error('gobuildwp_prepend_invalid', 'Invalid setting: enabled must be true or false.');
        }
        return set_prepend($input['enabled']);
    },
    // Writing a file PHP loads on every request is a Cloud Pro action: only a signed Cloud request.
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
