<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\AdminLock;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/admin-lock-status', [
    'label' => __('Get Admin IP Lock', domain: 'wppilot'),
    'description' => __(
        'Shows the admin IP lock: whether it is enforced, the allowed addresses and ranges, how the visitor address is read (ip_source), the address of this request (your_ip) and whether it is allowed, proxy headers seen on this request, and the two ways to switch it off when locked out (a wp-config.php constant and a WP-CLI command). The lock covers wp-login.php and wp-admin only.',
        domain: 'wppilot',
    ),
    'category' => 'admin-lock',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input = []): array => status($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('wppilot/set-admin-lock', [
    'label' => __('Set Admin IP Lock', domain: 'wppilot'),
    'description' => __(
        'Restricts wp-login.php and wp-admin to a list of IP addresses and CIDR ranges (up to 100); every other address gets a 403 page there. The front end, the REST API, admin-ajax.php, admin-post.php and WP-Cron are never restricted, nor are the password form of a protected post, privacy-request links and signing out. Pass only what changes: enabled, allow, ip_source (remote_addr by default; cloudflare behind Cloudflare; x_forwarded_for with trusted_proxies behind your own proxy). A list that does not include the address of this request is refused, so call wppilot/admin-lock-status first and make sure your_ip is the person\'s own address. Switching it on or changing the list while on needs confirm=true after the user approves; switching off does not. Locked out: define the constant named in the status in wp-config.php, or run the WP-CLI command it names.',
        domain: 'wppilot',
    ),
    'category' => 'admin-lock',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'enabled' => ['type' => 'boolean'],
            'allow' => ['type' => 'array', 'maxItems' => MAX_ENTRIES, 'items' => ['type' => 'string'], 'description' => 'IP addresses and CIDR ranges, such as 203.0.113.7 or 2001:db8::/48. Replaces the list.'],
            'ip_source' => ['type' => 'string', 'enum' => IP_SOURCES],
            'trusted_proxies' => ['type' => 'array', 'maxItems' => MAX_ENTRIES, 'items' => ['type' => 'string'], 'description' => 'Your own proxy addresses, for ip_source x_forwarded_for.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required as true to switch it on or change the list while on, after the user approves.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input): array|WP_Error => set($input, $_SERVER),
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_options'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);
