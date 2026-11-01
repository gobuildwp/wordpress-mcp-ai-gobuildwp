<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityLive;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/security-live-status', [
    'label' => __('Get Live Security Status', domain: 'wppilot'),
    'description' => __(
        'Shows live security: whether it is on, how often the change watch runs, excluded paths, whether the first pass (which learns the site and records nothing) is done, files watched, and the newest event id. Read-only.',
        domain: 'wppilot',
    ),
    'category' => 'security-live',
    'input_schema' => ['type' => 'object', 'default' => [], 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input = []): array {
        require_once __DIR__ . '/../load.php';
        return status();
    },
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_options'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
