<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityLive;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/security-live-configure', [
    'label' => __('Configure Live Security', domain: 'wppilot'),
    'description' => __(
        'Changes live security settings; only the fields given change, and the previous settings are returned so the change can be reverted. enabled: on or off. interval_minutes: how often the change watch runs (5-1440). excluded_paths: up to 50 folders or files, relative to the WordPress folder, that the change watch leaves out (replaces the list).',
        domain: 'wppilot',
    ),
    'category' => 'security-live',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'enabled' => ['type' => 'boolean'],
            'interval_minutes' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 1440],
            'excluded_paths' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string', 'maxLength' => 200]],
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
        $previous = settings();
        $next = configure($input);
        return $next instanceof \WP_Error ? $next : ['settings' => $next, 'previous' => $previous];
    },
    // Security Guardian is a GoBuildWP Cloud Pro feature: this changes the site, so it runs only for
    // a signed Cloud request. Reading what it reports stays local.
    'permission_callback' => static fn(): bool|\WP_Error => (Runtime\can_run() && current_user_can('manage_options')) ? Runtime\require_cloud_request() : false,
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);
