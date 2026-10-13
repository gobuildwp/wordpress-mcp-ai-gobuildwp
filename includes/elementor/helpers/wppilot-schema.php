<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor: extracting a widget's control schema.
 *
 * Schemas come from Elementor's own widget registry, which differs in shape
 * between classic widgets and v4 atomic widgets.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Extract the full compact schema (widgetType, label, description, controls)
 * for a widget type or the container pseudo-type. Every call runs the
 * extraction against the live Elementor registry — we intentionally do not
 * cache because extraction is cheap, freshness matters more than the
 * micro-optimization, and caching would entangle unrelated write operations.
 *
 * Returns null when the widget cannot be resolved — validation then fails
 * open for that element and leaves its settings untouched.
 *
 * @return array<string, mixed>|null
 */
function el_extract_schema(string $widget_type): ?array
{
    // Validation needs every control regardless of tab (the caller may legitimately
    // set style / advanced / visibility values), so always include_styles.
    return resolve_compact_schema($widget_type, ['include_styles' => true]);
}

/**
 * Extract the `controls` map from a cached schema entry, or null when the
 * widget could not be resolved (fail-open path).
 *
 * @param array<string, mixed>|null $schema
 * @return array<string, mixed>|null
 */
function el_schema_controls(?array $schema): ?array
{
    /** @var array<string, mixed>|null $controls */
    $controls = $schema === null ? null : $schema['controls'] ?? null;
    return is_array($controls) ? $controls : null;
}

/**
 * Resolve the raw `Prop_Type` schema (keys → Prop_Type) for an atomic
 * widget or atomic element. Returns null for v3 widgets, the v3 container,
 * and widget types that cannot be resolved — the deep validator then skips.
 *
 * @return array<string, object>|null
 */
function el_get_raw_props_schema(string $widget_type): ?array
{
    if (!class_exists('Elementor\\Plugin')) {
        return null;
    }
    if ($widget_type === '' || $widget_type === WPPILOT_COMPACT_SCHEMA_CONTAINER_KEY) {
        return null;
    }

    /** @var object $plugin */
    $plugin = \Elementor\Plugin::$instance;

    if (in_array($widget_type, WPPILOT_ATOMIC_CONTAINER_TYPES, strict: true)) {
        /** @var mixed $elements_manager */
        $elements_manager = $plugin->elements_manager ?? null;
        if (!is_object($elements_manager) || !method_exists($elements_manager, 'get_element_types')) {
            return null;
        }
        /** @var mixed $element */
        $element = $elements_manager->get_element_types($widget_type);
        if (!is_object($element) || !method_exists($element, 'get_props_schema')) {
            return null;
        }
        /** @var array<string, object> */
        return $element::get_props_schema();
    }

    /** @var mixed $widgets_manager */
    $widgets_manager = $plugin->widgets_manager ?? null;
    if (!is_object($widgets_manager) || !method_exists($widgets_manager, 'get_widget_types')) {
        return null;
    }
    /** @var mixed $widget */
    $widget = $widgets_manager->get_widget_types($widget_type);
    if (!class_exists(\Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base::class)) {
        return null;
    }
    if (!$widget instanceof \Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base) {
        return null;
    }
    /** @var array<string, object> */
    return $widget::get_props_schema();
}

/**
 * Re-extract content-only compact schemas for every unique widget type that
 * had errors, so the response can embed them without dragging along the full
 * style / advanced tab payloads.
 *
 * @param list<array<string, mixed>> $errors
 * @return array<string, array<string, mixed>>
 */
function el_build_response_schemas(array $errors): array
{
    $schemas = [];
    foreach ($errors as $err) {
        $widget_type = (string) ($err['widget_type'] ?? '');
        if ($widget_type === '' || array_key_exists($widget_type, $schemas)) {
            continue;
        }
        $schema = resolve_compact_schema($widget_type, ['include_styles' => false]);
        if ($schema !== null) {
            $schemas[$widget_type] = $schema;
        }
    }
    return $schemas;
}

/**
 * Resolve the list of acceptable $$type values for a prop type. Union prop
 * types expand to each member's key; plain prop types report their own key.
 * The result is what an agent needs to correct a bad `$$type` without
 * fetching the whole Style Schema.
 *
 * @return list<string>
 */
function el_expected_prop_types(object $prop_type): array
{
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Union_Prop_Type) {
        /** @var list<string> */
        return array_keys($prop_type->get_prop_types());
    }
    if (method_exists($prop_type, 'get_key')) {
        return [(string) $prop_type::get_key()];
    }
    return [];
}
