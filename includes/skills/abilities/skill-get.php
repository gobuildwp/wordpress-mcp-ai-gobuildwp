<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Skills\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Register the shared `skill` ability category. Other plugins may have
 * registered it first; we tolerate that (idempotent via the try/catch).
 */
function register_categories(): void
{
    if (!function_exists('wp_register_ability_category') || wp_has_ability_category('skill')) {
        return;
    }

    wp_register_ability_category('skill', [
        'label' => __('Skills', domain: 'wppilot'),
        'description' => __('Manage and load GoBuildWP skills.', domain: 'wppilot'),
    ]);
}

namespace WPPilot\Skills\Abilities\SkillGet;

use WP_Error;
use WPPilot\Skills\Parser;
use WPPilot\Skills\Sources;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Take canonical ownership of `wppilot/skill-get`. Registered at
 * `wp_abilities_api_init` priority 999 so any other plugin (e.g., a
 * pre-update Pro plugin) has already had a chance to register first.
 * The previous owner — whatever plugin it was — is captured here and
 * used as a fallback for slugs our sources do not know about.
 *
 * After enough downstream plugins migrate to the source-filter
 * (`wppilot_skill_lookup_sources`), the fallback path stops firing
 * naturally. Keeping the wrapper costs nothing.
 *
 * This intentionally keeps the full input/output JSON schema next to the
 * `wp_register_ability()` call so the registration remains easy to audit.
 */
function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    $previous = wp_has_ability('wppilot/skill-get') ? wp_get_ability('wppilot/skill-get') : null;
    if ($previous instanceof \WP_Ability) {
        wp_unregister_ability('wppilot/skill-get');
    }

    wp_register_ability('wppilot/skill-get', [
        'label' => __('Get Skill', domain: 'wppilot'),
        'description' => __(
            'Load a GoBuildWP skill by slug. Returns the full SKILL.md content plus metadata.',
            domain: 'wppilot',
        ),
        'category' => 'skill',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                    'description' => 'The slug of the skill to load.',
                ],
            ],
            'required' => ['slug'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'found' => ['type' => 'boolean'],
                'slug' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'enable_prompt' => ['type' => 'boolean'],
                'enable_agentic' => ['type' => 'boolean'],
                'source' => ['type' => 'string'],
            ],
            'required' => ['found'],
        ],
        'execute_callback' => static fn(array $input): array|WP_Error => execute_skill_get($input, $previous),
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
}

function execute_skill_get(array $input, ?\WP_Ability $previous): array|WP_Error
{
    $agent_slug = normalize_requested_slug((string) ($input['slug'] ?? ''));
    if ($agent_slug === '') {
        return new WP_Error('missing_slug', __('A slug is required.', domain: 'wppilot'));
    }

    $skill = Sources\find($agent_slug);
    if ($skill !== null) {
        return format_skill_response($skill);
    }

    return forward_to_previous_skill_get($agent_slug, $previous);
}

/**
 * @param array<string, mixed> $skill
 * @return array<string, mixed>
 */
function format_skill_response(array $skill): array
{
    $enable_prompt = boolval($skill['enable_prompt'] ?? false);
    $enable_agentic = boolval($skill['enable_agentic'] ?? true);

    return [
        'found' => true,
        'slug' => (string) $skill['slug'],
        'name' => (string) ($skill['name'] ?? $skill['slug']),
        'description' => (string) ($skill['description'] ?? ''),
        'content' => Parser\render_skill_md([
            'slug' => (string) $skill['slug'],
            'description' => (string) ($skill['description'] ?? ''),
            'content' => (string) ($skill['content'] ?? ''),
            'enable_prompt' => $enable_prompt,
            'enable_agentic' => $enable_agentic,
        ]),
        'enable_prompt' => $enable_prompt,
        'enable_agentic' => $enable_agentic,
        'source' => (string) ($skill['source'] ?? 'user-cpt'),
    ];
}

/**
 * @return array<array-key, mixed>
 */
function forward_to_previous_skill_get(string $agent_slug, ?\WP_Ability $previous): array
{
    if (!$previous instanceof \WP_Ability) {
        return ['found' => false];
    }

    /** @var mixed $forwarded */
    $forwarded = $previous->execute(['slug' => $agent_slug]);
    if (is_wp_error($forwarded)) {
        return ['found' => false];
    }

    return is_array($forwarded) ? $forwarded : ['found' => false];
}

function normalize_requested_slug(string $slug): string
{
    $normalized = trim($slug);
    if (str_starts_with($normalized, 'wppilot/')) {
        return substr($normalized, strlen('wppilot/'));
    }

    return $normalized;
}
