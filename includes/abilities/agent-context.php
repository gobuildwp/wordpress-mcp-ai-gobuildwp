<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/agent-context', [
    'label' => __('Agent Context', domain: 'wppilot'),
    'description' => __(
        'Return transport-neutral GoBuildWP site guidance, environment, and skill summaries.',
        domain: 'wppilot',
    ),
    'input_schema' => WPPILOT_NO_INPUT_SCHEMA,
    // A read-only guidance ability, the transport-neutral sibling of discover-abilities. It was
    // filed under code-execution, which the WordPress.org edition never registers, so it failed to
    // register there; mcp-adapter is registered on every edition (abilities/bootstrap.php).
    'category' => 'mcp-adapter',
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'server' => ['type' => 'object'],
            'instructions' => ['type' => 'string'],
            'skills' => ['type' => 'array', 'items' => ['type' => 'object']],
            'environment' => ['type' => 'object'],
        ],
        'required' => ['server', 'instructions', 'skills', 'environment'],
    ],
    'execute_callback' => 'wppilot_build_agent_context',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
        'mcp' => ['public' => true, 'type' => 'tool'],
    ],
]);

/**
 * Reuse the same instruction and skill source/filter pipeline as existing integrations.
 *
 * @return array{
 *     server: array<string, mixed>,
 *     instructions: string,
 *     skills: list<array{slug: string, description: string, source: string}>,
 *     environment: array{wordpress_version: string, php_version: string, locale: string}
 * }
 */
function wppilot_build_agent_context(): array
{
    $instructions = (string) apply_filters(
        'wppilot_discover_abilities_instructions',
        wppilot_build_server_instructions(),
    );

    $skills = [];
    foreach (\WPPilot\Skills\Sources\discoverable('agentic') as $skill) {
        $skills[] = [
            'slug' => (string) ($skill['slug'] ?? ''),
            'description' => (string) ($skill['description'] ?? ''),
            'source' => (string) ($skill['source'] ?? ''),
        ];
    }

    return [
        'server' => wppilot_server_compatibility(),
        'instructions' => $instructions,
        'skills' => $skills,
        'environment' => [
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'locale' => get_locale(),
        ],
    ];
}
