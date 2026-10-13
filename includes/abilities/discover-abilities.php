<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Ability: Discover Abilities (GoBuildWP replacement).
 *
 * Replaces the MCP Adapter's bundled discover-abilities tool so the response
 * includes GoBuildWP's environment/usage instructions alongside the list of
 * abilities. These used to be sent via the MCP initialize handshake's
 * server_description, but some clients drop that; returning them here
 * guarantees the agent sees them on first tool discovery.
 */

if (!defined('ABSPATH')) {
    exit();
}

$wppilot_existing_ability = wp_has_ability('mcp-adapter/discover-abilities')
    ? wp_get_ability('mcp-adapter/discover-abilities')
    : null;
if ($wppilot_existing_ability !== null) {
    wp_unregister_ability('mcp-adapter/discover-abilities');
}

if (wp_has_ability('mcp-adapter/discover-abilities')) {
    return;
}

wp_register_ability('mcp-adapter/discover-abilities', [
    'label' => __('Discover Abilities', domain: 'wppilot'),
    'description' => __(
        'Discover all available WordPress abilities in the system. Returns a list of all registered abilities with their basic information and input signature (parameter names and types), plus GoBuildWP environment instructions.',
        domain: 'wppilot',
    ),
    'category' => 'mcp-adapter',
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'wppilot_instructions' => [
                'type' => 'string',
                'description' => 'GoBuildWP environment and usage guidance for the agent.',
            ],
            'abilities' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'label' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'params' => [
                            'type' => 'string',
                            'description' => 'Input signature, e.g. "post_id: integer, settings: object, replace?: boolean". A trailing ? marks an optional parameter; empty when the ability takes no input. Use get-ability-info for the full schema.',
                        ],
                    ],
                    'required' => ['name', 'label', 'description', 'params'],
                ],
            ],
        ],
        'required' => ['wppilot_instructions', 'abilities'],
    ],
    'permission_callback' => static function (): bool|\WP_Error {
        if (!is_user_logged_in()) {
            return new \WP_Error('authentication_required', 'User must be authenticated to access this ability');
        }
        /** @var string $cap */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- deliberately in the mcp_adapter_* filter family beside the adapter's own hooks; consumers already attach to this name.
        $cap = apply_filters('mcp_adapter_discover_abilities_capability', value: 'read');
        if (!current_user_can($cap)) {
            return new \WP_Error('insufficient_capability', sprintf('User lacks required capability: %s', $cap));
        }
        return true;
    },
    'execute_callback' => static function (): array {
        $ability_list = [];
        foreach (wp_get_abilities() as $ability) {
            $meta = wppilot_ability_meta($ability);
            // GoBuildWP's exposure rule on its own servers, so this list matches the modern
            // transport's tools/list; the adapter's rule on another plugin's server sharing it.
            if (!\WPPilot\Mcp\Legacy\publishes($meta)) {
                continue;
            }
            if (wppilot_ability_mcp_type($meta) !== 'tool') {
                continue;
            }
            $ability_list[] = [
                'name' => $ability->get_name(),
                'label' => $ability->get_label(),
                'description' => $ability->get_description(),
                'params' => wppilot_ability_param_signature($ability->get_input_schema()),
            ];
        }

        $instructions = '';
        if (wppilot_current_user_can_manage()) {
            $instructions = (string) apply_filters(
                'wppilot_discover_abilities_instructions',
                wppilot_build_server_instructions(),
            );
        }

        return [
            'wppilot_instructions' => $instructions,
            'abilities' => $ability_list,
        ];
    },
    'meta' => [
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
        'mcp' => [
            'public' => true,
            'type' => 'tool',
        ],
    ],
]);
