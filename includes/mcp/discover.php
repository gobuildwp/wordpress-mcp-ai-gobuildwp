<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp;

/**
 * `server/discover` — the modern revision's required capability advertisement.
 *
 * The result must describe what this installation actually serves right now,
 * not what the plugin is capable of in principle. A GoBuildWP site with every
 * ability switched off, or running the Read Only safety profile, exposes a
 * different surface from the same plugin on another site, and a client that
 * trusts an over-broad advertisement will call methods that then fail.
 *
 * @link https://modelcontextprotocol.io/specification/2026-07-28/server/discover
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Build the capability map from what is actually registered.
 *
 * Capabilities are omitted rather than declared empty when nothing backs them.
 * Two are deliberately never advertised:
 *
 * - `subscriptions`: GoBuildWP has no change-notification producer. Nothing in
 *   the plugin emits a tools/prompts/resources list-changed event, so opening a
 *   `subscriptions/listen` stream would hold a connection open forever and
 *   deliver nothing. The spec is explicit that a server must not advertise
 *   subscription support merely to satisfy a checklist.
 * - `logging`: deprecated in this revision, and GoBuildWP emits no
 *   `notifications/message`.
 *
 * Tasks are declared by extensions.php, not here, and only while the extension is enabled.
 *
 * Each capability value is an stdClass, not an empty PHP array. `json_encode()`
 * renders an empty array as `[]`, and the schema types these values as objects —
 * a strictly-validating client rejects `"tools": []` where `"tools": {}` is
 * required.
 *
 * @param int $tool_count    Abilities currently exposed as MCP tools.
 * @param int $prompt_count  Skills currently exposed as MCP prompts.
 * @param int $resource_count Resources currently exposed.
 * @return array<string, object>
 */
function build_capabilities(int $tool_count, int $prompt_count, int $resource_count): array
{
    $capabilities = [];

    if ($tool_count > 0) {
        // No `listChanged`: GoBuildWP does not emit tools/list_changed, and
        // declaring it would promise a notification that never arrives.
        $capabilities['tools'] = new \stdClass();
    }
    if ($prompt_count > 0) {
        $capabilities['prompts'] = new \stdClass();
    }
    if ($resource_count > 0) {
        $capabilities['resources'] = new \stdClass();
    }

    return $capabilities;
}

/**
 * Concise usage guidance returned with discovery.
 *
 * Kept short on purpose: it is prepended to an LLM's context on every
 * connection, so it earns its tokens only by saying what a client cannot infer
 * from the tool list itself.
 */
function discover_instructions(string $profile): string
{
    return sprintf(
        'WordPress site controlled through typed abilities. The active safety profile is "%s"; '
        . 'operations outside it are refused server-side regardless of the tool list. '
        . 'Destructive operations require an explicit confirmation flag. '
        . 'Content is created as a draft unless publication is requested explicitly.',
        $profile,
    );
}

/**
 * Build the full `server/discover` result.
 *
 * `resultType`, `ttlMs`, `cacheScope`, and `_meta.serverInfo` are added by the
 * shared result decorator, so discovery is not a special case that could drift
 * from the other cacheable methods.
 *
 * @param array<string, array<string, mixed>> $capabilities
 * @return array<string, mixed>
 */
function build_discover_result(array $capabilities, string $profile): array
{
    return [
        'supportedVersions' => SUPPORTED_VERSIONS,
        'capabilities' => $capabilities,
        'instructions' => discover_instructions($profile),
    ];
}

/**
 * Resolve the live capability map from the WordPress runtime.
 *
 * Counts what the adapter would actually serve: registered abilities that
 * survive the ability policy and the active safety profile.
 *
 * @return array<string, array<string, mixed>>
 */
function runtime_capabilities(): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $tools = 0;
    $prompts = 0;

    /** @var mixed $ability */
    foreach (wp_get_abilities() as $ability) {
        $meta = \wppilot_ability_meta($ability);
        if ($meta === [] || !\wppilot_ability_is_exposed($meta)) {
            continue;
        }

        if (\wppilot_ability_mcp_type($meta) === 'prompt') {
            ++$prompts;
            continue;
        }
        ++$tools;
    }

    $skill_resources = function_exists('WPPilot\Mcp\SkillResources\resource_count')
        ? SkillResources\resource_count()
        : 0;
    $extension_resources = function_exists('WPPilot\Mcp\extension_resource_count') ? extension_resource_count() : 0;

    $capabilities = build_capabilities($tools, $prompts, $skill_resources + $extension_resources);
    // The Skills extension promises skills/list; an Apps card alone is a resource, not a skill.
    if ($skill_resources > 0) {
        $capabilities = with_skills_extension($capabilities);
    }

    return function_exists('WPPilot\Mcp\with_extension_capabilities')
        ? with_extension_capabilities($capabilities)
        : $capabilities;
}

/**
 * Declare the Skills extension (SEP-2640) alongside the resources that carry it.
 *
 * The extension commits the server to skills/list and skills/get and requires the `resources`
 * capability, so it is declared exactly when resources are: a site whose skills are all hidden
 * advertises neither rather than an extension with nothing behind it.
 *
 * @param array<string, object> $capabilities
 * @return array<string, object>
 */
function with_skills_extension(array $capabilities): array
{
    if (!isset($capabilities['resources'])) {
        return $capabilities;
    }

    $capabilities['extensions'] = (object) ['io.modelcontextprotocol/skills' => new \stdClass()];

    return $capabilities;
}
