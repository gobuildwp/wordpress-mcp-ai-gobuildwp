<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/protection-configure', [
    'label' => __('Configure Website Protection', domain: 'wppilot'),
    'description' => __(
        'Changes website protection settings; only the fields given change, and the previous settings are returned so the change can be reverted. firewall: off, log (record only) or block. login: enabled, max_failures (3-50), window_minutes, lockout_minutes, generic_errors. xmlrpc: on or off. block_user_enumeration. flood: enabled, max_404_per_minute. ip_source: remote_addr, cloudflare or x_forwarded_for (set only when the site really sits behind that proxy; Cloudflare\'s header is only believed from Cloudflare\'s own addresses, and X-Forwarded-For only from proxies listed in trusted_proxies or the GOBUILDWP_TRUSTED_PROXIES constant). trusted_proxies: addresses or CIDR ranges of the reverse proxy or load balancer (replaces the list; /8 or /32 at the widest); read client_ip and proxy_headers_seen from protection-status first. If protection ever locks the owner out, define GOBUILDWP_DISABLE_PROTECTION as true in wp-config.php to switch all of it off. allowlist: addresses or CIDR ranges that are never blocked. Signed-in administrators and administrator API calls are never blocked.',
        domain: 'wppilot',
    ),
    'category' => 'security-protection',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'firewall' => ['type' => 'string', 'enum' => ['off', 'log', 'block']],
            'login' => [
                'type' => 'object',
                'properties' => [
                    'enabled' => ['type' => 'boolean'],
                    'max_failures' => ['type' => 'integer', 'minimum' => 3, 'maximum' => 50],
                    'window_minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1440],
                    'lockout_minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10080],
                    'generic_errors' => ['type' => 'boolean'],
                ],
                'additionalProperties' => false,
            ],
            'xmlrpc' => ['type' => 'string', 'enum' => ['on', 'off']],
            'block_user_enumeration' => ['type' => 'boolean'],
            'flood' => [
                'type' => 'object',
                'properties' => [
                    'enabled' => ['type' => 'boolean'],
                    'max_404_per_minute' => ['type' => 'integer', 'minimum' => 10, 'maximum' => 1000],
                ],
                'additionalProperties' => false,
            ],
            'ip_source' => ['type' => 'string', 'enum' => ['remote_addr', 'cloudflare', 'x_forwarded_for']],
            'allowlist' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'string']],
            'trusted_proxies' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string']],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'settings' => ['type' => 'object'],
            'previous' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input = []): array|\WP_Error {
        require_once __DIR__ . '/../load.php';
        return configure($input);
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
