<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/security-fix-undo', [
    'label' => __('Undo Security Fixes', domain: 'wppilot'),
    'description' => __(
        'Reverses fixes made by security-fix-apply, 1 to 25 fix_ids per call: a quarantined file goes back where it was (only if its stored copy is unchanged), a repaired core file gets its previous content back, a demoted user gets their previous roles, an unpublished post its previous status. Each fix_id is undone or refused on its own. Destructive: needs confirm=true.',
        domain: 'wppilot',
    ),
    'category' => 'security-integrity',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'fix_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 25, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64]],
            'confirm' => ['type' => 'boolean', 'description' => 'Must be true: the person approved the undo.'],
        ],
        'required' => ['fix_ids'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'undone' => ['type' => 'integer'],
            'refused' => ['type' => 'integer'],
            'results' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
    ],
    'execute_callback' => static function (array $input = []): array|\WP_Error {
        require_once __DIR__ . '/../load.php';
        return undo_fixes($input);
    },
    // Security Guardian is a GoBuildWP Cloud Pro feature: this changes the site, so it runs only for
    // a signed Cloud request. Reading what it reports stays local.
    'permission_callback' => static fn(): bool|\WP_Error => (Runtime\can_run() && current_user_can('manage_options')) ? Runtime\require_cloud_request() : false,
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);
