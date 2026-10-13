<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

use ReflectionClass;

/**
 * Schema extraction library. No abilities are registered here — the sole
 * public schema ability is `wppilot/elementor-get-schema` in
 * `wppilot-get-widget-wppilot-schema.php`, which delegates into the helpers below.
 *
 * Format produced by the extractors (compact representation used by the
 * validator and by the default output of get-schema):
 *
 *     t      control type (text, select, choose, switcher, slider, color, repeater, ...)
 *     opts   list of VALID option keys (select / choose / select2 / multiselect)
 *     def    default value (omitted when the default is the empty string)
 *     rv     return_value for switcher controls ("yes" on, "" off)
 *     if     condition array — do not emit this control when its condition is not met
 *     arr    true when the control expects an array value (select2, multiselect, gallery, ...)
 *     fields for repeaters, the flat shape of one row
 *
 * Structural controls (section / divider / heading / tab / notice / raw_html) are dropped.
 * Style and advanced tab controls are dropped unless `include_styles` is true.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Control types that only structure the editor UI and carry no settings value.
 */
const WPPILOT_COMPACT_SCHEMA_SKIP_TYPES = [
    'section',
    'divider',
    'heading',
    'raw_html',
    'deprecated_notice',
    'tab',
    'tabs',
    'notice',
    'alert',
];

/**
 * Tab names that belong to the style / advanced panels and are dropped when
 * `include_styles` is false.
 */
const WPPILOT_COMPACT_SCHEMA_STYLE_TABS = [
    'style',
    'advanced',
    'dce_visibility',
];

/**
 * Control types whose value must always be an array, regardless of what the
 * caller passes in. The validator coerces scalar values to a one-element array.
 */
const WPPILOT_COMPACT_SCHEMA_ARRAY_TYPES = [
    'select2',
    'multiselect',
    'checkbox_list',
    'gallery',
    'icons',
];

/**
 * Pseudo widget name that means "the container element" (containers are not
 * widgets in Elementor and live under elements_manager instead).
 */
const WPPILOT_COMPACT_SCHEMA_CONTAINER_KEY = '__container__';

/**
 * Atomic element types that are containers (not widgets). These live under
 * `elements_manager` and extend `Atomic_Element_Base` with `is_container: true`.
 */
const WPPILOT_ATOMIC_CONTAINER_TYPES = ['e-flexbox', 'e-div-block'];

/**
 * Classic layout elements that predate containers. Like the container they
 * live under `elements_manager`, and `elementor-get-content` returns them on
 * any page built before containers, so a tree read back from such a page has
 * to validate against their real controls or it cannot be written back.
 */
const WPPILOT_LEGACY_LAYOUT_TYPES = ['section', 'column'];

/**
 * Resolve the compact schema for a single widget type (or the container
 * pseudo-type), returning null when neither match.
 *
 * @param array{include_styles: bool} $opts
 * @return array{widgetType: string, label: string, description: string, is_atomic: bool, controls: array<string, array<string, mixed>>}|null
 */
function resolve_compact_schema(string $widget_type, array $opts): ?array
{
    if ($widget_type === WPPILOT_COMPACT_SCHEMA_CONTAINER_KEY) {
        return build_container_compact_schema($opts);
    }

    if (in_array($widget_type, WPPILOT_ATOMIC_CONTAINER_TYPES, strict: true)) {
        return build_atomic_element_compact_schema($widget_type);
    }

    if (in_array($widget_type, WPPILOT_LEGACY_LAYOUT_TYPES, strict: true)) {
        return build_legacy_layout_compact_schema($widget_type, $opts);
    }

    return build_widget_compact_schema($widget_type, $opts);
}

/**
 * Build the compact schema for a registered widget (v3 or v4).
 *
 * Atomic widgets are flagged with `is_atomic: true` so the validator knows
 * to expect the `{"$$type": "<prop_key>", "value": <scalar>}` wrapper format
 * around every setting value — scalars passed by the caller are coerced to
 * this shape automatically.
 *
 * @param array{include_styles: bool} $opts
 * @return array{widgetType: string, label: string, description: string, is_atomic: bool, controls: array<string, array<string, mixed>>}|null
 */
function build_widget_compact_schema(string $widget_type, array $opts): ?array
{
    $manager = \Elementor\Plugin::$instance->widgets_manager;
    $widget = $manager->get_widget_types($widget_type);

    if (!$widget instanceof \Elementor\Widget_Base) {
        return null;
    }

    $label = $widget->get_title();
    $description = build_compact_description($widget);

    if (is_atomic_widget($widget)) {
        /** @var \Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base $widget */
        return [
            'widgetType' => $widget_type,
            'label' => $label,
            'description' => $description,
            'is_atomic' => true,
            'controls' => compact_v4_props($widget),
        ];
    }

    /** @var array<string, array<string, mixed>> $raw_controls */
    $raw_controls = $widget->get_controls();

    return [
        'widgetType' => $widget_type,
        'label' => $label,
        'description' => $description,
        'is_atomic' => false,
        'controls' => compact_v3_controls($raw_controls, $opts),
    ];
}

/**
 * Build the compact schema for the container element.
 *
 * @param array{include_styles: bool} $opts
 * @return array{widgetType: string, label: string, description: string, is_atomic: bool, controls: array<string, array<string, mixed>>}|null
 */
function build_container_compact_schema(array $opts): ?array
{
    $elements_manager = \Elementor\Plugin::$instance->elements_manager;
    $container = $elements_manager->get_element_types('container');

    if (!is_object($container) || !method_exists($container, 'get_controls')) {
        return null;
    }

    /** @var array<string, array<string, mixed>> $controls */
    $controls = $container->get_controls();

    return [
        'widgetType' => WPPILOT_COMPACT_SCHEMA_CONTAINER_KEY,
        'label' => 'Container',
        'description' => 'Elementor container element (flex layout wrapper).',
        'is_atomic' => false,
        'controls' => compact_v3_controls($controls, $opts),
    ];
}

/**
 * Build the compact schema for a classic section or column.
 *
 * @param array{include_styles: bool} $opts
 * @return array{widgetType: string, label: string, description: string, is_atomic: bool, controls: array<string, array<string, mixed>>}|null
 */
function build_legacy_layout_compact_schema(string $element_type, array $opts): ?array
{
    $element = \Elementor\Plugin::$instance->elements_manager->get_element_types($element_type);

    if (!is_object($element) || !method_exists($element, 'get_controls')) {
        return null;
    }

    /** @var array<string, array<string, mixed>> $controls */
    $controls = $element->get_controls();

    return [
        'widgetType' => $element_type,
        'label' => $element_type === 'section' ? 'Section' : 'Column',
        'description' => $element_type === 'section'
            ? 'Classic Elementor section (pre-container layout row). Its children are columns.'
            : 'Classic Elementor column inside a section. Its children are widgets or inner sections.',
        'is_atomic' => false,
        'controls' => compact_v3_controls($controls, $opts),
    ];
}

/**
 * Build the compact schema for an atomic container element (e-flexbox, e-div-block).
 *
 * These live under `elements_manager` and extend `Atomic_Element_Base`. They
 * use the same `Has_Atomic_Base` trait as atomic widgets, so their props are
 * extracted with the same `compact_v4_props()` helper.
 *
 * @return array{widgetType: string, label: string, description: string, is_atomic: bool, controls: array<string, array<string, mixed>>}|null
 */
function build_atomic_element_compact_schema(string $element_type): ?array
{
    $elements_manager = \Elementor\Plugin::$instance->elements_manager;

    /** @var object|null $element */
    $element = $elements_manager->get_element_types($element_type);

    if (!is_object($element) || !method_exists($element, 'get_props_schema')) {
        return null;
    }

    $label = method_exists($element, 'get_title') ? (string) $element->get_title() : $element_type;

    /** @var list<string> $keywords */
    $keywords = method_exists($element, 'get_keywords') ? $element->get_keywords() : [];

    $description = $label . ' atomic container';
    if ($keywords !== []) {
        $description .= '. Related: ' . implode(', ', array_slice($keywords, offset: 0, length: 5));
    }

    /** @var array<string, object> $props_schema */
    $props_schema = $element::get_props_schema();

    return [
        'widgetType' => $element_type,
        'label' => $label,
        'description' => $description,
        'is_atomic' => true,
        'controls' => compact_v4_props_from_schema($props_schema),
    ];
}

/**
 * Reduce a v3 controls array to the compact {t, opts, def, if, rv, arr, fields}
 * shape, filtering out structural and (optionally) style / advanced tabs.
 *
 * @param array<string, array<string, mixed>> $controls
 * @param array{include_styles: bool} $opts
 * @return array<string, array<string, mixed>>
 */
function compact_v3_controls(array $controls, array $opts): array
{
    $compact = [];

    foreach ($controls as $id => $control) {
        $entry = compact_v3_control($control, $opts);
        if ($entry === null) {
            continue;
        }
        $compact[$id] = $entry;
    }

    return $compact;
}

/**
 * Reduce a single v3 control definition to the compact entry, or return null
 * when the control must be dropped (structural type or wrong tab).
 *
 * @param array<string, mixed> $control
 * @param array{include_styles: bool} $opts
 * @return array<string, mixed>|null
 */
function compact_v3_control(array $control, array $opts): ?array
{
    $type = (string) ($control['type'] ?? '');
    if (!control_passes_filters($type, $control, $opts)) {
        return null;
    }

    $entry = ['t' => $type];
    $entry += extract_control_options($control);
    $entry += extract_control_default($control);
    $entry += extract_control_return_value($control);
    $entry += extract_control_condition($control);
    $entry += extract_control_array_flag($type, $control);
    $entry += extract_control_responsive_flag($control);
    $entry += extract_control_repeater_fields($type, $control);

    return $entry;
}

/**
 * Whether a control passes the type-skip and tab filters. Pulled out so the
 * compact builder can early-return without bloating its own complexity.
 *
 * @param array<string, mixed> $control
 * @param array{include_styles: bool} $opts
 */
function control_passes_filters(string $type, array $control, array $opts): bool
{
    if (in_array($type, WPPILOT_COMPACT_SCHEMA_SKIP_TYPES, strict: true)) {
        return false;
    }

    if ($opts['include_styles']) {
        return true;
    }

    $tab = (string) ($control['tab'] ?? 'content');
    return !in_array($tab, WPPILOT_COMPACT_SCHEMA_STYLE_TABS, strict: true);
}

/**
 * @param array<string, mixed> $control
 * @return array<string, mixed>
 */
function extract_control_options(array $control): array
{
    if (!array_key_exists('options', $control)) {
        return [];
    }

    /** @var mixed $options */
    $options = $control['options'];
    if (!is_array($options) || $options === []) {
        return [];
    }

    $keys = array_keys($options);

    // A select whose only option is the empty placeholder is filled in by the
    // editor's JavaScript at edit time — the form widget's Reply-To lists the
    // form's own field IDs that way. Its PHP options are not the real choices,
    // so publishing them as an enum would reject every legitimate value.
    if (array_filter($keys, static fn(int|string $k): bool => $k !== '') === []) {
        return [];
    }

    return ['opts' => $keys];
}

/**
 * @param array<string, mixed> $control
 * @return array<string, mixed>
 */
function extract_control_default(array $control): array
{
    if (!array_key_exists('default', $control)) {
        return [];
    }

    /** @var mixed $default */
    $default = $control['default'];
    if ($default === '' || $default === null) {
        return [];
    }

    return ['def' => $default];
}

/**
 * @param array<string, mixed> $control
 * @return array<string, mixed>
 */
function extract_control_return_value(array $control): array
{
    if (!array_key_exists('return_value', $control)) {
        return [];
    }

    return ['rv' => $control['return_value']];
}

/**
 * @param array<string, mixed> $control
 * @return array<string, mixed>
 */
function extract_control_condition(array $control): array
{
    if (!array_key_exists('condition', $control)) {
        return [];
    }

    /** @var mixed $condition */
    $condition = $control['condition'];
    if (!is_array($condition) || $condition === []) {
        return [];
    }

    return ['if' => $condition];
}

/**
 * @param array<string, mixed> $control
 * @return array<string, mixed>
 */
function extract_control_array_flag(string $type, array $control): array
{
    if (control_expects_array($type, $control)) {
        return ['arr' => true];
    }

    return [];
}

/**
 * Preserve a compact `r` flag whenever the underlying Elementor v3 control
 * has the `responsive` property set. Elementor v3 exposes responsive
 * overrides as the suffixed keys `<key>_tablet`, `<key>_mobile`, etc.; the
 * `responsive` property in the control array is what makes those suffixed
 * keys legitimate at the data layer.
 *
 * Three flag shapes are emitted:
 *
 *  - `r: 1`                           — control accepts every breakpoint
 *                                       suffix (the common case: declared
 *                                       as `responsive => true`, `[]`, or
 *                                       with the per-instance desktop
 *                                       marker `max: desktop` that
 *                                       `controls-stack` adds during
 *                                       responsive control replication).
 *  - `r: {max: '<bp>'}`               — restricted to bp and below.
 *  - `r: {min: '<bp>'}`               — restricted to bp and above.
 *  - `r: {min: '<bp>', max: '<bp>'}`  — restricted to a closed range.
 *
 * The compact flag is intentionally small — typically a single integer.
 * Active breakpoint NAMES come from `check-setup.kit.active_breakpoints`.
 * The size ordering used to interpret min/max is:
 * `mobile < mobile_extra < tablet < tablet_extra < laptop < desktop <
 * widescreen` (as defined by Elementor's default `breakpoints/manager`).
 *
 * @param array<string, mixed> $control
 * @return array<string, mixed>
 */
function extract_control_responsive_flag(array $control): array
{
    if (!array_key_exists('responsive', $control)) {
        return [];
    }

    /** @var mixed $responsive */
    $responsive = $control['responsive'];
    if (!is_array($responsive)) {
        return ['r' => 1];
    }

    $constraints = [];
    foreach (['min', 'max'] as $bound) {
        /** @var mixed $value */
        $value = $responsive[$bound] ?? null;
        // Drop the implicit-base marker `max: desktop`: that value is
        // emitted by `controls-stack` on the desktop variant of every
        // expanded responsive control and means "this is the base entry",
        // not "restricted to desktop and below". Treat the control as
        // unrestricted in that case.
        if (!is_string($value) || $value === '' || $bound === 'max' && $value === 'desktop') {
            continue;
        }
        $constraints[$bound] = $value;
    }

    return $constraints === [] ? ['r' => 1] : ['r' => $constraints];
}

/**
 * @param array<string, mixed> $control
 * @return array<string, mixed>
 */
function extract_control_repeater_fields(string $type, array $control): array
{
    if ($type !== 'repeater') {
        return [];
    }

    if (!array_key_exists('fields', $control) || !is_array($control['fields'])) {
        return [];
    }

    /** @var array<string, array<string, mixed>> $fields */
    $fields = $control['fields'];
    return ['fields' => compact_v3_repeater_fields($fields)];
}

/**
 * Compact the inner `fields` definition of a repeater control. Repeater fields
 * never carry a tab, so we reuse the same helpers with an empty style flag.
 *
 * @param array<string, array<string, mixed>> $fields
 * @return array<string, array<string, mixed>>
 */
function compact_v3_repeater_fields(array $fields): array
{
    $compact = [];
    $opts = ['include_styles' => true];

    foreach ($fields as $fid => $field) {
        $entry = compact_v3_control($field, $opts);
        if ($entry === null) {
            continue;
        }
        $compact[$fid] = $entry;
    }

    return $compact;
}

/**
 * Whether a given control definition must receive an array value (as opposed
 * to a scalar). This drives the sanitizer's scalar → array coercion.
 *
 * @param array<string, mixed> $control
 */
function control_expects_array(string $type, array $control): bool
{
    if (in_array($type, WPPILOT_COMPACT_SCHEMA_ARRAY_TYPES, strict: true)) {
        return true;
    }

    if ($type === 'checkbox' || $type === 'checkbox_list') {
        if (array_key_exists('default', $control) && is_array($control['default'])) {
            return true;
        }
    }

    return false;
}

/**
 * Extract the compact `controls` map for an atomic (v4) widget by walking the
 * prop type tree returned by `get_props_schema()`.
 *
 * @return array<string, array<string, mixed>>
 */
function compact_v4_props(\Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base $widget): array
{
    /** @var array<string, object> $raw_schema */
    $raw_schema = $widget::get_props_schema();
    return compact_v4_props_from_schema($raw_schema);
}

/**
 * Extract the compact `controls` map from a raw v4 props schema array.
 * Shared by both atomic widgets and atomic elements (e-flexbox, e-div-block).
 *
 * @param array<string, object> $raw_schema
 * @return array<string, array<string, mixed>>
 */
function compact_v4_props_from_schema(array $raw_schema): array
{
    $compact = [];

    foreach ($raw_schema as $key => $prop_type) {
        $compact[$key] = compact_v4_prop_entry($prop_type);
    }

    return $compact;
}

/**
 * Build the compact entry for a single v4 prop type.
 *
 * The `t` field holds the `$$type` key the atomic widget runtime expects
 * in the wrapped settings — i.e. the value that goes into the `"$$type"`
 * slot of `{"$$type": "<t>", "value": <scalar>}`. For plain prop types
 * this is simply the prop's own key (string / number / color / ...);
 * for UNION prop types this is the key of the default sub-type (since a
 * union dispatches by `$$type` of its children, not by "union" itself).
 *
 * @return array<string, mixed>
 */
function compact_v4_prop_entry(object $prop_type): array
{
    $raw_default = null;
    if (method_exists($prop_type, 'get_default')) {
        /** @var mixed $raw_default */
        $raw_default = $prop_type->get_default();
    }

    $entry = [
        't' => resolve_v4_coerce_key($prop_type, $raw_default),
    ];

    if (method_exists($prop_type, 'get_enum')) {
        /** @var mixed $enum */
        $enum = $prop_type->get_enum();
        if (is_array($enum) && $enum !== []) {
            $entry['opts'] = array_values($enum);
        }
    }

    // Atomic widgets hide validated enums (e.g. e-heading.tag → h1..h6,
    // e-flexbox.tag → div/header/section/...) inside a Union_Prop_Type's
    // `string` alternative. The top-level union itself does not expose
    // get_enum(), so without this descent our compact schema would report
    // "t: string" with no opts and direct-mode writes would silently
    // accept any string — matching the tree-mode Props_Parser validator
    // that already rejects bogus tags.
    if (!array_key_exists('opts', $entry)) {
        $union_enum = extract_union_string_enum($prop_type);
        if ($union_enum !== []) {
            $entry['opts'] = $union_enum;
        }
    }

    if ($raw_default !== null && $raw_default !== '') {
        $entry['def'] = $raw_default;
    }

    return $entry;
}

/**
 * If the prop is a Union_Prop_Type whose `string` alternative carries an
 * enum, return those options. Used as a fallback by `compact_v4_prop_entry`
 * so callers see the validated tag set for atomic widgets/containers.
 *
 * Returns the empty list for anything that isn't a union or doesn't have a
 * string alternative with a non-empty enum, so the caller can treat the
 * result as "no extra opts" and fall through.
 *
 * @return list<string>
 */
function extract_union_string_enum(object $prop_type): array
{
    if (!$prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Union_Prop_Type) {
        return [];
    }

    /** @var array<string, object> $alternatives */
    $alternatives = $prop_type->get_prop_types();
    $string_alt = $alternatives['string'] ?? null;
    if ($string_alt === null || !method_exists($string_alt, 'get_enum')) {
        return [];
    }

    /** @var mixed $enum */
    $enum = $string_alt->get_enum();
    if (!is_array($enum) || $enum === []) {
        return [];
    }

    $out = [];
    /** @var mixed $entry */
    foreach ($enum as $entry) {
        if (!is_string($entry)) {
            continue;
        }
        $out[] = $entry;
    }
    return $out;
}

/**
 * Pick the `$$type` key that callers should use when wrapping a scalar
 * value for this prop. For unions we never wrap with the literal "union" — the
 * union dispatches to a concrete sub-prop-type by the inner key. Prefer the
 * default's inner `$$type` (e.g. e-heading.tag defaults to {$$type:"string"});
 * otherwise resolve the union's concrete SCALAR member (its `string` / `number`
 * / `size` alternative). Without this, a union whose default is not an enveloped
 * `{$$type,value}` — e.g. `_cssid`, which defaults to an empty string — falls
 * back to "union", and the ergonomic scalar then wraps as {$$type:"union"}: a
 * shape Elementor's own validator rejects on editor save ("_cssid: invalid_value"),
 * which also makes our validator's opts ["union"] reject the correct {$$type:"string"}.
 */
function resolve_v4_coerce_key(object $prop_type, mixed $default): string
{
    $raw_key = resolve_v4_prop_key($prop_type);

    if ($raw_key !== 'union') {
        return $raw_key;
    }

    if (is_array($default)) {
        /** @var mixed $inner_type */
        $inner_type = $default['$$type'] ?? null;
        if (is_string($inner_type) && $inner_type !== '') {
            return $inner_type;
        }
    }

    $scalar_key = resolve_v4_union_scalar_key($prop_type);
    if ($scalar_key !== null) {
        return $scalar_key;
    }

    return $raw_key;
}

/**
 * The key of a union's concrete SCALAR member — its `string` (then `number` /
 * `size`) alternative — so an ergonomic scalar wraps as that concrete type
 * instead of the abstract "union" container. Returns null for a non-union or a
 * union with no scalar member.
 */
function resolve_v4_union_scalar_key(object $prop_type): ?string
{
    if (!$prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Union_Prop_Type) {
        return null;
    }
    /** @var array<string, object> $alternatives */
    $alternatives = $prop_type->get_prop_types();
    foreach (['string', 'number', 'size'] as $scalar_key) {
        if (array_key_exists($scalar_key, $alternatives)) {
            return $scalar_key;
        }
    }
    return null;
}

/**
 * Resolve the transformable key for a prop type. Prefers the static
 * `get_key()` declared on `Transformable_Prop_Type`; falls back to the
 * instance `get_type()` on `Plain_Prop_Type` / `Array_Prop_Type`; last
 * resort is the short class name so the schema still carries a stable
 * identifier for unknown prop types.
 */
function resolve_v4_prop_key(object $prop_type): string
{
    if (method_exists($prop_type, 'get_type')) {
        /** @var mixed $type */
        $type = $prop_type->get_type();
        if (is_string($type) && $type !== '') {
            return $type;
        }
    }

    $class = get_class($prop_type);
    if (method_exists($class, 'get_key')) {
        /** @var mixed $key */
        $key = $class::get_key();
        if (is_string($key) && $key !== '') {
            return $key;
        }
    }

    return (new ReflectionClass($prop_type))->getShortName();
}

/**
 * Resolve a widget's compact description. Prefers the widget's own description
 * when present and only synthesizes one from title + keywords as a fallback.
 */
function build_compact_description(\Elementor\Widget_Base $widget): string
{
    if (is_atomic_widget($widget)) {
        /** @var \Elementor\Modules\AtomicWidgets\Elements\Base\Atomic_Widget_Base $widget */
        /** @var mixed $own */
        $own = $widget->get_meta_item('description');
        if (is_string($own) && $own !== '') {
            return $own;
        }
    }

    $title = $widget->get_title();
    /** @var list<string> $keywords */
    $keywords = $widget->get_keywords();

    // Every wppilot-generated widget carries the hard-coded 'wppilot' keyword; on
    // its own it's pure noise in the discovery surface, so treat it as no keywords.
    if ($keywords === [] || $keywords === ['wppilot']) {
        return $title . ' widget';
    }

    $keyword_str = implode(', ', array_slice($keywords, offset: 0, length: 5));
    return $title . ' widget. Related: ' . $keyword_str;
}
