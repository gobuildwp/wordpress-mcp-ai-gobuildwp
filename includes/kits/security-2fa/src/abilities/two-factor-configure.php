<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityTwoFactor;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/two-factor-configure', [
    'label' => __('Configure Two-Factor Login', domain: 'wppilot'),
    'description' => __(
        'Changes the two-factor login policy; only the fields given change, and the previous policy is returned so the change can be reverted. required_roles: role slugs whose users must use two-factor login (an empty list makes it optional for everyone). grace_logins (0-10): sign-ins such a user gets without it before being asked to set it up at login. Nobody is locked out: a user without it sets it up during the login after the grace sign-ins.',
        domain: 'wppilot',
    ),
    'category' => 'security-2fa',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'required_roles' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string']],
            'grace_logins' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'policy' => ['type' => 'object'],
            'previous' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input = []): array|\WP_Error {
        require_once __DIR__ . '/../load.php';
        return configure($input);
    },
    // Two-factor login is free: unlike the protection settings, changing the policy needs no Cloud plan.
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_options'),
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
