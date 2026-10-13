<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Ability: Expose the Elementor v4 atomic-widgets Style Schema as JSON so
 * MCP callers can learn which CSS properties are valid on a Global Class (or
 * any style variant) and the exact `{$$type, value}` shape expected for each.
 *
 * This is the authoritative schema straight from
 * `Elementor\Modules\AtomicWidgets\Styles\Style_Schema::get()`. It replaces
 * any "ergonomic translation" layer: pass values in the shapes described
 * here and the writes (create-global-class, edit-global-class, …) validate
 * against it fail-hard.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/elementor-get-style-schema', [
    'label' => __('Get Elementor v4 Style Schema', domain: 'wppilot'),
    'description' => __(
        'Returns the authoritative Elementor v4 atomic-widgets Style Schema — CSS property → prop-type descriptor. v4-ONLY: governs Global Classes, atomic widget base styles, and style variants. Does NOT apply to legacy v3 widgets (use wppilot/elementor-get-schema for those). Progressive disclosure: the full schema is ~35KB (60+ properties); prefer `overview:true` for a flat `{property: prop_kind}` discovery view, or `properties:["gap","padding",...]` to get detailed descriptors only for the props you plan to write. Per property, lists the prop kind (`size`, `color`, `string`, `number`, `union`, `dimensions`, `background`, `box-shadow`, …), the enum values when applicable, and for union / object props the nested alternatives.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'overview' => [
                'type' => 'boolean',
                'description' => 'When true, return a flat `{property: prop_kind}` map (tiny — a few hundred bytes) that lists every property and the family it belongs to. Use this first to discover what exists, then drill in with `properties` for the full descriptor shape. Defaults to false.',
            ],
            'properties' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Return the full descriptor only for the listed CSS properties (e.g. ["gap","padding","background"]). Unknown names land in `unknown_properties`. When empty or omitted the full schema is returned.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'schema' => [
                'type' => 'object',
                'description' => 'CSS property name → prop-type descriptor (full mode or when `properties` is used).',
            ],
            'overview' => [
                'type' => 'object',
                'description' => 'When `overview:true`: flat map of CSS property name → prop kind (e.g. {"gap":"size","color":"color"}).',
            ],
            'unknown_properties' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'When `properties` is used: names in the input that are not registered CSS properties. Call the ability without filters to see every valid name.',
            ],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'WPPilot\Elementor\elementor_get_style_schema',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array{success: bool, schema?: array<string, mixed>, overview?: array<string, string>, unknown_properties?: list<string>, error?: string}
 */
function elementor_get_style_schema(array $input = []): array
{
    if (!class_exists(WPPILOT_STYLE_SCHEMA_CLASS)) {
        return [
            'success' => false,
            'error' => 'Elementor Atomic Widgets Style Schema is not available. Requires Elementor v4 with the atomic-widgets module loaded.',
        ];
    }

    /** @var array<string, \Elementor\Modules\AtomicWidgets\PropTypes\Contracts\Prop_Type> $raw */
    $raw = \Elementor\Modules\AtomicWidgets\Styles\Style_Schema::get();

    $overview_mode = ($input['overview'] ?? false) === true;
    $requested = gss_collect_requested_properties($input['properties'] ?? null);

    if ($overview_mode) {
        $overview = [];
        foreach ($raw as $property => $prop_type) {
            $overview[$property] = gss_prop_kind($prop_type);
        }
        return ['success' => true, 'overview' => $overview];
    }

    if ($requested !== []) {
        $schema = [];
        $unknown = [];
        foreach ($requested as $property) {
            if (!array_key_exists($property, $raw)) {
                $unknown[] = $property;
                continue;
            }
            $schema[$property] = gss_describe_prop_type($raw[$property]);
        }
        $result = ['success' => true, 'schema' => $schema];
        if ($unknown !== []) {
            $result['unknown_properties'] = $unknown;
        }
        return $result;
    }

    $schema = [];
    foreach ($raw as $property => $prop_type) {
        $schema[$property] = gss_describe_prop_type($prop_type);
    }

    return ['success' => true, 'schema' => $schema];
}

/**
 * Parse the `properties` input into a clean list of strings. Non-string
 * entries are dropped silently rather than failing the whole call — the
 * ability is a discovery tool, not a write path, so being permissive about
 * junk inputs beats a hard rejection.
 *
 * @param mixed $raw
 * @return list<string>
 */
function gss_collect_requested_properties(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    /** @var mixed $entry */
    foreach ($raw as $entry) {
        if (!is_string($entry) || $entry === '') {
            continue;
        }
        $out[] = $entry;
    }
    return $out;
}

/**
 * Return the short "kind" label used by the overview mode. Pulls the prop
 * type's $$type key (same one `describe` emits) so the overview's vocabulary
 * matches the detailed descriptors without a second lookup.
 */
function gss_prop_kind(object $prop_type): string
{
    if (!method_exists($prop_type, 'get_key')) {
        return 'unknown';
    }
    /** @var mixed $key */
    $key = $prop_type::get_key();
    return is_string($key) ? $key : 'unknown';
}

/**
 * Produce a compact, LLM-friendly descriptor for an Elementor Prop_Type
 * instance. Recursively unfolds unions and object shapes so the consumer sees
 * the full nested structure without having to call the ability per leaf.
 *
 * @param \Elementor\Modules\AtomicWidgets\PropTypes\Contracts\Prop_Type $prop_type
 * @return array<string, mixed>
 */
function gss_describe_prop_type(object $prop_type): array
{
    $key = method_exists($prop_type, 'get_key') ? $prop_type::get_key() : 'unknown';
    $descriptor = ['$$type' => $key];

    // String with enum or regex.
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type) {
        /** @var list<string>|null $enum */
        $enum = $prop_type->get_enum();
        if ($enum !== null) {
            $descriptor['enum'] = $enum;
        }
        /** @var string|null $regex */
        $regex = $prop_type->get_regex();
        if ($regex !== null) {
            $descriptor['regex'] = $regex;
        }
    }

    // Union: surface the alternative branches so the caller can pick one.
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Union_Prop_Type) {
        $alternatives = [];
        /** @var array<string, \Elementor\Modules\AtomicWidgets\PropTypes\Contracts\Prop_Type> $alts */
        $alts = $prop_type->get_prop_types();
        foreach ($alts as $alt_key => $alt_prop) {
            $alternatives[$alt_key] = gss_describe_prop_type($alt_prop);
        }
        $descriptor['alternatives'] = $alternatives;
    }

    // Object shape: recurse into nested fields (background, dimensions, ...).
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Base\Object_Prop_Type) {
        $shape = [];
        /** @var array<string, \Elementor\Modules\AtomicWidgets\PropTypes\Contracts\Prop_Type> $fields */
        $fields = $prop_type->get_shape();
        foreach ($fields as $field => $field_prop) {
            $shape[$field] = gss_describe_prop_type($field_prop);
        }
        $descriptor['shape'] = $shape;
    }

    // Array-of: surface the item type.
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Base\Array_Prop_Type) {
        $descriptor['item'] = gss_describe_prop_type($prop_type->get_item_type());
    }

    // Size: surface the units accepted by this specific instance so the
    // caller knows whether `px`, `em`, `%`, `fr`, etc. are allowed here.
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Size_Prop_Type) {
        /** @var array<string, mixed> $settings */
        $settings = $prop_type->get_settings();
        /** @var list<string>|null $units */
        $units = $settings['units'] ?? null;
        if (is_array($units)) {
            $descriptor['units'] = array_values($units);
        }
    }

    return $descriptor;
}
