<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Skills\Prompts;

use WPPilot\Skills\Parser;
use WPPilot\Skills\Sources;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Register one ability per discoverable prompt-mode skill. The MCP Adapter
 * auto-discovers abilities whose `meta.mcp.type` is `prompt` and exposes
 * them through the protocol's prompts/list and prompts/get endpoints.
 */
function register_dynamic_abilities(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    $skills = Sources\discoverable('prompt');

    foreach ($skills as $skill) {
        // The slug here is already decorated (e.g. `custom_landing-page`)
        // because `Sources\discoverable()` returns records with the
        // agent-facing slug.
        $slug = (string) ($skill['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $name = (string) ($skill['name'] ?? $slug);
        $description = (string) ($skill['description'] ?? '');
        $body = Parser\render_skill_md([
            'slug' => $slug,
            'description' => $description,
            'content' => (string) ($skill['content'] ?? ''),
            'enable_prompt' => boolval($skill['enable_prompt'] ?? true),
            'enable_agentic' => boolval($skill['enable_agentic'] ?? true),
        ]);

        wp_register_ability("wppilot/skill-prompt-{$slug}", [
            'label' => $name,
            'description' => $description,
            'category' => 'skill',
            'input_schema' => [
                'type' => 'object',
                'default' => [],
                'properties' => new \stdClass(),
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'messages' => ['type' => 'array'],
                ],
            ],
            'execute_callback' => static fn(): array => [
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => ['type' => 'text', 'text' => $body],
                    ],
                ],
            ],
            'permission_callback' => 'wppilot_permission_callback',
            'meta' => [
                'mcp' => ['public' => true, 'type' => 'prompt'],
            ],
        ]);
    }
}
