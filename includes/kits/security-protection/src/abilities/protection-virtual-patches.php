<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/protection-virtual-patches', [
    'label' => __('Virtual Patches', domain: 'wppilot'),
    'description' => __(
        'Lists, or replaces, the virtual patches GoBuildWP Cloud keeps on this site: narrow rules that block a known exploit against a plugin or theme version the site still runs, until it is updated. With rules, the whole set is replaced (an empty list removes them all); without, the set is listed. Each rule names a component and version range, an endpoint (admin-ajax or admin-post action, REST route or path), optionally a request field and a test on its value, methods, and who it applies to. The site checks installed versions itself and applies a rule only while the installed version is in its range. Signed-in administrators are never blocked. Blocks are recorded as firewall events.',
        domain: 'wppilot',
    ),
    'category' => 'security-protection',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'rules' => [
                'type' => 'array',
                'maxItems' => MAX_VPATCHES,
                'items' => ['type' => 'object'],
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'stored' => ['type' => 'integer'],
            'active' => ['type' => 'integer'],
            'set_at' => ['type' => ['string', 'null']],
            'rules' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
    ],
    'execute_callback' => static function (array $input = []): array|\WP_Error {
        require_once __DIR__ . '/../load.php';
        if (array_key_exists('rules', $input)) {
            if (!is_array($input['rules'])) {
                return new \WP_Error('gobuildwp_vpatch_invalid', 'Invalid virtual patch: rules must be a list.');
            }
            return set_virtual_patches($input['rules']);
        }
        recheck_virtual_patches();
        return virtual_patch_state();
    },
    // Rules come from GoBuildWP Cloud's advisories (a Cloud Pro feature), so only a signed Cloud
    // request may replace or list them.
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
