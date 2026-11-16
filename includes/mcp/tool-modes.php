<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp;

use WP_Ability;
use WP_Error;

/**
 * Two switches for how the modern transport's tools/list is shaped.
 *
 * Compact tool list. A site with every kit loaded advertises a few hundred tools, and some clients
 * cap a connection's tools (or spend a large part of the context window on their definitions).
 * With this on, tools/list advertises only the three discovery meta-tools the legacy adapter has
 * always served: discover-abilities, get-ability-info and execute-ability. Nothing becomes
 * unreachable: execute-ability runs any ability the full list would have shown, and a direct call
 * to one of those abilities by its tool name still works for a client with a cached list.
 *
 * Strict schemas. OpenAI's strict function calling only accepts a schema in which every object
 * declares `additionalProperties: false` and lists every property in `required`. With this on,
 * every advertised input schema is rewritten that way, and each property that was optional becomes
 * nullable instead, which is how strict mode spells "may be left out". A strict client then sends
 * `null` for an optional field it means to omit; call_tool() drops those nulls again before the
 * gate, so the ability sees exactly the input it would have without the switch.
 *
 * Limits of strict mode: an object that declares no properties (a free-form map, such as
 * execute-ability's `parameters` or an Elementor `settings` object) is left open, because closing
 * it would make the argument always empty, and a client that rejects open objects will still
 * refuse that one tool. Composition keywords (`anyOf`, `oneOf`) are rewritten branch by branch but
 * a null inside one is not stripped, since which branch was meant cannot be known. Output schemas
 * are not touched: they constrain what the site returns, not what the model sends.
 *
 * Both are off by default and change only what is advertised. What a call may do is still decided
 * by the same gate pipeline and the ability's own schema.
 */

if (!defined('ABSPATH')) {
    exit();
}

const COMPACT_TOOLS_OPTION = 'wppilot_mcp_compact_tools';
const STRICT_SCHEMAS_OPTION = 'wppilot_mcp_strict_schemas';

/** The legacy adapter's meta-tools, which are the whole tool list in compact mode. */
const META_TOOL_ABILITIES = [
    'mcp-adapter/discover-abilities',
    'mcp-adapter/get-ability-info',
    'mcp-adapter/execute-ability',
];

const EXECUTE_META_ABILITY = 'mcp-adapter/execute-ability';

function compact_tools_enabled(): bool
{
    return get_option(COMPACT_TOOLS_OPTION, '0') === '1';
}

function strict_schemas_enabled(): bool
{
    return get_option(STRICT_SCHEMAS_OPTION, '0') === '1';
}

function is_meta_tool_ability(string $ability_name): bool
{
    return in_array($ability_name, META_TOOL_ABILITIES, strict: true);
}

/**
 * A tool definition with its input schema made strict, when the switch is on.
 *
 * @param array<string, mixed> $tool
 * @return array<string, mixed>
 */
function apply_strict_mode(array $tool): array
{
    if (!strict_schemas_enabled() || !is_array($tool['inputSchema'] ?? null)) {
        return $tool;
    }
    $tool['inputSchema'] = strict_schema($tool['inputSchema']);

    return $tool;
}

/**
 * Rewrite one schema for OpenAI strict mode; see the file header for what is left alone.
 *
 * @param array<array-key, mixed> $schema
 * @return array<array-key, mixed>
 */
function strict_schema(array $schema): array
{
    foreach (['anyOf', 'oneOf', 'allOf'] as $keyword) {
        if (is_array($schema[$keyword] ?? null)) {
            $schema[$keyword] = array_map(
                static fn(mixed $branch): mixed => is_array($branch) ? strict_schema($branch) : $branch,
                $schema[$keyword],
            );
        }
    }
    foreach (['$defs', 'definitions'] as $keyword) {
        if (is_array($schema[$keyword] ?? null)) {
            $schema[$keyword] = array_map(
                static fn(mixed $child): mixed => is_array($child) ? strict_schema($child) : $child,
                $schema[$keyword],
            );
        }
    }
    $items = $schema['items'] ?? null;
    if (is_array($items)) {
        $schema['items'] = array_is_list($items)
            ? array_map(static fn(mixed $child): mixed => is_array($child) ? strict_schema($child) : $child, $items)
            : strict_schema($items);
    }

    $properties = $schema['properties'] ?? null;
    // force_schema_objects() has already turned an empty `properties` into an object: an object
    // that takes no input at all, which strict mode can close without losing anything.
    if ($properties instanceof \stdClass) {
        $schema['required'] = [];
        $schema['additionalProperties'] = false;
        return $schema;
    }
    if (!is_array($properties) || $properties === []) {
        return $schema;
    }

    $required = array_values(array_filter(
        is_array($schema['required'] ?? null) ? $schema['required'] : [],
        'is_string',
    ));
    $all = [];
    foreach ($properties as $name => $child) {
        $name = (string) $name;
        $all[] = $name;
        if (!is_array($child)) {
            continue;
        }
        $child = strict_schema($child);
        $properties[$name] = in_array($name, $required, strict: true) ? $child : nullable_schema($child);
    }
    $schema['properties'] = $properties;
    $schema['required'] = $all;
    $schema['additionalProperties'] = false;

    return $schema;
}

/**
 * Let a schema also accept null: the strict-mode spelling of an optional property.
 *
 * @param array<array-key, mixed> $schema
 * @return array<array-key, mixed>
 */
function nullable_schema(array $schema): array
{
    if ($schema === []) {
        return $schema;
    }

    $type = $schema['type'] ?? null;
    if (is_string($type) || is_array($type)) {
        $types = is_string($type) ? [$type] : array_values($type);
        if (!in_array('null', $types, strict: true)) {
            $types[] = 'null';
        }
        $schema['type'] = count($types) === 1 ? $types[0] : $types;
        if (is_array($schema['enum'] ?? null) && !in_array(null, $schema['enum'], strict: true)) {
            $schema['enum'][] = null;
        }
        return $schema;
    }

    if (is_array($schema['anyOf'] ?? null)) {
        foreach ($schema['anyOf'] as $branch) {
            if (is_array($branch) && ($branch['type'] ?? null) === 'null') {
                return $schema;
            }
        }
        $schema['anyOf'][] = ['type' => 'null'];
        return $schema;
    }

    return ['anyOf' => [$schema, ['type' => 'null']]];
}

/**
 * Remove the nulls a strict client sends for optional properties it means to leave out.
 *
 * Driven by the ability's own schema, so a property that was required, or declared nullable by its
 * author, keeps its null. Only declared properties are looked at; nested objects and the items of
 * arrays are followed.
 *
 * @param array<array-key, mixed> $schema The ability's input schema as normalize_schema() returns it.
 */
function strip_strict_nulls(mixed $value, array $schema): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    $items = $schema['items'] ?? null;
    if (array_is_list($value) && is_array($items) && $items !== [] && !array_is_list($items)) {
        return array_map(static fn(mixed $item): mixed => strip_strict_nulls($item, $items), $value);
    }

    $properties = $schema['properties'] ?? null;
    if (!is_array($properties)) {
        return $value;
    }
    $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
    foreach ($properties as $name => $child) {
        $name = (string) $name;
        if (!array_key_exists($name, $value)) {
            continue;
        }
        if ($value[$name] === null) {
            if (!in_array($name, $required, strict: true) && !schema_accepts_null($child)) {
                unset($value[$name]);
            }
            continue;
        }
        if (is_array($child)) {
            $value[$name] = strip_strict_nulls($value[$name], $child);
        }
    }

    return $value;
}

function schema_accepts_null(mixed $schema): bool
{
    if (!is_array($schema)) {
        return false;
    }
    $type = $schema['type'] ?? null;

    return $type === 'null' || (is_array($type) && in_array('null', $type, strict: true));
}

/**
 * Resolve a modern-transport call to execute-ability into the ability it names.
 *
 * Calling the adapter's execute-ability ability directly would run the target inside the adapter,
 * after GoBuildWP's gate had checked only the meta-tool: the target's confirmation, rate limit and
 * holds would never run, because the legacy path applies them in `mcp_adapter_pre_tool_call`,
 * which only the adapter's own server fires. So the modern transport unwraps the call and gates the
 * target itself, exactly as if the client had called it by its own tool name.
 *
 * @param array<array-key, mixed> $arguments
 * @return array{0: WP_Ability, 1: array<array-key, mixed>}|WP_Error
 */
function unwrap_execute_ability(array $arguments): array|WP_Error
{
    $name = is_string($arguments['ability_name'] ?? null) ? $arguments['ability_name'] : '';
    $ability = $name !== '' && function_exists('wp_get_ability') ? \wp_get_ability($name) : null;
    if (!$ability instanceof WP_Ability || is_meta_tool_ability($name)) {
        return new WP_Error('wppilot_unknown_ability', sprintf('Unknown ability: %s', $name), ['status' => 404]);
    }
    $meta = \wppilot_ability_meta($ability);
    if (!\wppilot_ability_is_exposed($meta) || \wppilot_ability_mcp_type($meta) !== 'tool') {
        return new WP_Error('wppilot_unknown_ability', sprintf('Unknown ability: %s', $name), ['status' => 404]);
    }
    $parameters = is_array($arguments['parameters'] ?? null) ? $arguments['parameters'] : [];

    return [$ability, $parameters];
}

/**
 * The MCP tools section on the Settings screen.
 */
function register_tool_mode_settings(mixed $sections): mixed
{
    if (!is_array($sections)) {
        return $sections;
    }

    $sections[] = [
        'id' => 'wppilot-mcp-tools',
        'title' => __('MCP tool list', domain: 'wppilot'),
        'description' => __(
            'How the tool list is shaped for MCP clients. Neither switch changes what a call is allowed to do.',
            domain: 'wppilot',
        ),
        'fields' => [
            [
                'type' => 'toggle',
                'name' => COMPACT_TOOLS_OPTION,
                'label' => __('Compact tool list', domain: 'wppilot'),
                'help' => __(
                    'List only discover-abilities, get-ability-info and execute-ability, instead of one tool per ability. For clients that limit how many tools a connection may have, or that spend too much context on them. Every ability stays reachable through execute-ability.',
                    domain: 'wppilot',
                ),
                'value' => compact_tools_enabled(),
            ],
            [
                'type' => 'toggle',
                'name' => STRICT_SCHEMAS_OPTION,
                'label' => __('Strict schemas (OpenAI)', domain: 'wppilot'),
                'help' => __(
                    'Advertise input schemas that OpenAI strict mode accepts: every object closed with additionalProperties false, every property required, optional ones nullable. Nulls sent for optional fields are dropped before the ability runs. Objects with no declared properties stay open.',
                    domain: 'wppilot',
                ),
                'value' => strict_schemas_enabled(),
            ],
        ],
        'save' => __NAMESPACE__ . '\\save_tool_mode_settings',
    ];

    return $sections;
}

/**
 * @param array<string, mixed> $post
 */
function save_tool_mode_settings(array $post): void
{
    update_option(COMPACT_TOOLS_OPTION, ($post[COMPACT_TOOLS_OPTION] ?? null) !== null ? '1' : '0', autoload: true);
    update_option(STRICT_SCHEMAS_OPTION, ($post[STRICT_SCHEMAS_OPTION] ?? null) !== null ? '1' : '0', autoload: true);
}

add_filter('wppilot_settings_sections', __NAMESPACE__ . '\\register_tool_mode_settings');
