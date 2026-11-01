<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityTwoFactor;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/two-factor-status', [
    'label' => __('Two-Factor Login Status', domain: 'wppilot'),
    'description' => __(
        'Shows two-factor login on this site: the role policy (required roles, grace sign-ins), how many administrators and users of the required roles have it on, and up to 200 users who have it on with their roles, when they turned it on and how many backup codes they have left. Never returns secrets or backup codes. Read-only.',
        domain: 'wppilot',
    ),
    'category' => 'security-2fa',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'policy' => [
                'type' => 'object',
                'properties' => [
                    'required_roles' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'grace_logins' => ['type' => 'integer'],
                ],
            ],
            'summary' => [
                'type' => 'object',
                'properties' => [
                    'users_with_2fa' => ['type' => 'integer'],
                    'administrators' => ['type' => 'integer'],
                    'administrators_with_2fa' => ['type' => 'integer'],
                ],
            ],
            'roles' => ['type' => 'array', 'items' => ['type' => 'object']],
            'users' => ['type' => 'array', 'items' => ['type' => 'object']],
            'users_truncated' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input = []): array {
        require_once __DIR__ . '/../load.php';
        return status();
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
