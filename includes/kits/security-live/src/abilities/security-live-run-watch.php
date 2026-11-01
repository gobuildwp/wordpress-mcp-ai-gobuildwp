<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityLive;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/security-live-run-watch', [
    'label' => __('Run Live Security Change Watch', domain: 'wppilot'),
    'description' => __(
        'Runs the change watch now for up to the given seconds (1-25). It picks up where the last run stopped, checks only code files whose size or time changed, and records events for what it finds. Changes nothing on the site except its own index and events.',
        domain: 'wppilot',
    ),
    'category' => 'security-live',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'seconds' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 10],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input = []): array {
        require_once __DIR__ . '/../load.php';
        return run_watch(max(1, min(MAX_RUN_SECONDS, (int) ($input['seconds'] ?? 10))));
    },
    // Security Guardian is a GoBuildWP Cloud Pro feature: this changes the site, so it runs only for
    // a signed Cloud request. Reading what it reports stays local.
    'permission_callback' => static fn(): bool|\WP_Error => (Runtime\can_run() && current_user_can('manage_options')) ? Runtime\require_cloud_request() : false,
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);
