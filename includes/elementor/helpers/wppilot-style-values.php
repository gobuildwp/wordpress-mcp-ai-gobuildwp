<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor v4: wrapping individual style property values.
 *
 * Sizes, objects, and arrays each have their own envelope shape. Unit
 * defaults matter: a bare number means px for a length but a unitless value
 * for line-height.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Auto-wrap a flat CSS-property → value map against the Style Schema. Used
 * both for per-element styles' inner `variant.props` and for the Global
 * Classes input (which is itself a flat props map at the API surface).
 *
 * @param array<string, mixed>  $props
 * @param array<string, object> $schema
 * @return array<string, mixed>
 */
function el_normalize_style_props(array $props, array $schema): array
{
    foreach (array_keys($props) as $prop_name) {
        if (!array_key_exists($prop_name, $schema)) {
            continue;
        }
        $props[$prop_name] = el_wrap_style_value($props[$prop_name], $schema[$prop_name], $prop_name);
    }
    return $props;
}

/**
 * Recursively wrap a single style value against its declared Prop_Type.
 * Dispatches by type kind (union, size, object, array, primitive) so each
 * branch can pick the right `$$type` and the right inner shape.
 */
function el_wrap_style_value(mixed $value, object $prop_type, string $prop_name = ''): mixed
{
    if ($value === null) {
        return null;
    }

    // Dynamic values resolve at render time — leave their inner structure
    // alone, same as the settings coercer skips dynamic.
    if (is_array($value) && ($value['$$type'] ?? null) === 'dynamic' && array_key_exists('value', $value)) {
        return $value;
    }

    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Union_Prop_Type) {
        return el_wrap_style_union($value, $prop_type, $prop_name);
    }
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Size_Prop_Type) {
        return el_wrap_style_size($value, $prop_type, $prop_name);
    }
    // Object_Prop_Type must be checked AFTER Size_Prop_Type — Size extends Object.
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Base\Object_Prop_Type) {
        return el_wrap_style_object($value, $prop_type);
    }
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Base\Array_Prop_Type) {
        return el_wrap_style_array($value, $prop_type);
    }
    // String_Prop_Type catches Color_Prop_Type too (Color extends String).
    // get_key() returns the right key for each ('string' / 'color').
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type) {
        return el_wrap_style_string_scalar($value, $prop_type::get_key());
    }
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Primitives\Number_Prop_Type) {
        return el_wrap_style_typed_scalar($value, type_key: 'number');
    }
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Primitives\Boolean_Prop_Type) {
        return el_wrap_style_typed_scalar($value, type_key: 'boolean');
    }
    return $value;
}

/**
 * Wrap a string-like scalar into `{$$type, value}`, casting numerics to a
 * string first — Style_Parser rejects a raw int for string/color props.
 */
function el_wrap_style_string_scalar(mixed $value, string $type_key): mixed
{
    if (is_array($value) && array_key_exists('$$type', $value) && array_key_exists('value', $value)) {
        return $value;
    }
    if (!is_scalar($value)) {
        return $value;
    }
    return ['$$type' => $type_key, 'value' => (string) $value];
}

/**
 * Wrap a typed scalar (number, boolean) into `{$$type, value}` without
 * mangling the underlying type — Style_Parser checks `is_numeric`/`is_bool`
 * on the inner value.
 */
function el_wrap_style_typed_scalar(mixed $value, string $type_key): mixed
{
    if (is_array($value) && array_key_exists('$$type', $value) && array_key_exists('value', $value)) {
        return $value;
    }
    if (!is_scalar($value)) {
        return $value;
    }
    return ['$$type' => $type_key, 'value' => $value];
}

/**
 * Wrap a value into a Size prop shape, defaulting the unit to whatever the
 * prop's settings declare (or px). Accepts:
 *   - bare number   72         → {size:72,  unit:"px"}
 *   - "72px"                   → {size:72,  unit:"px"}
 *   - "1.5rem"                 → {size:1.5, unit:"rem"}
 *   - "auto"                   → {size:"",  unit:"auto"}
 *   - {size, unit} (no $$type) → wrap envelope
 */
function el_wrap_style_size(
    mixed $value,
    \Elementor\Modules\AtomicWidgets\PropTypes\Size_Prop_Type $prop_type,
    string $prop_name = '',
): mixed {
    if (is_array($value) && array_key_exists('$$type', $value) && array_key_exists('value', $value)) {
        return $value;
    }

    $default_unit = el_size_default_unit($prop_type, $prop_name);

    if (is_array($value) && array_key_exists('size', $value) && array_key_exists('unit', $value)) {
        return ['$$type' => 'size', 'value' => $value];
    }

    if (is_int($value) || is_float($value)) {
        return ['$$type' => 'size', 'value' => el_size_value($value, $default_unit)];
    }

    if (is_string($value)) {
        $parsed = el_parse_size_string($value, $default_unit);
        if ($parsed !== null) {
            return ['$$type' => 'size', 'value' => $parsed];
        }
    }

    return $value;
}

/**
 * CSS properties where a bare number is a ratio rather than a length.
 *
 * `line-height: 1.4` is not 1.4 pixels, and wrapping it as one collapses every
 * line box on the page to a single pixel. Nothing in Elementor's own prop type
 * says so: line-height allows px like any other length, so the default-unit
 * logic reasonably picks px and quietly destroys the value. The knowledge that
 * this property reads a unitless number differently lives in CSS, so it has to
 * be stated here.
 */
const UNITLESS_SIZE_PROPS = ['line-height'];

/**
 * Build a Size value, representing a unitless number the way Elementor does.
 *
 * Elementor has no "no unit" unit; it carries a unitless value as the `custom`
 * unit with the number kept as a string, which is what its CSS renderer emits
 * verbatim.
 *
 * @return array{size: int|float|string, unit: string}
 */
function el_size_value(int|float|string $size, string $unit): array
{
    return $unit === 'custom' ? ['size' => (string) $size, 'unit' => 'custom'] : ['size' => $size, 'unit' => $unit];
}

/**
 * Pick the default unit for a Size prop. Honors `default_unit()` (e.g. opacity
 * uses %), then falls back to `px` if available, otherwise the first allowed
 * unit, otherwise `px` as a last-resort.
 */
function el_size_default_unit(
    \Elementor\Modules\AtomicWidgets\PropTypes\Size_Prop_Type $prop_type,
    string $prop_name = '',
): string {
    /** @var array<string, mixed> $settings */
    $settings = $prop_type->get_settings();

    // A ratio property takes the unitless representation, when the prop still
    // allows it. Checked against the prop's own units rather than assumed, so a
    // future Elementor that drops `custom` degrades to px instead of writing a
    // value the parser refuses.
    if (in_array($prop_name, UNITLESS_SIZE_PROPS, strict: true)) {
        /** @var mixed $allowed */
        $allowed = $settings['available_units'] ?? null;
        if (!is_array($allowed) || in_array('custom', $allowed, strict: true)) {
            return 'custom';
        }
    }
    /** @var mixed $explicit */
    $explicit = $settings['default_unit'] ?? null;
    if (is_string($explicit) && $explicit !== '') {
        return $explicit;
    }
    /** @var mixed $units */
    $units = $settings['available_units'] ?? null;
    if (is_array($units) && in_array(needle: 'px', haystack: $units, strict: true)) {
        return 'px';
    }
    if (is_array($units) && array_key_exists(0, $units) && is_string($units[0]) && $units[0] !== '') {
        return $units[0];
    }
    return 'px';
}

/**
 * Split a CSS size string (e.g. "72px", "1.5rem", "auto") into a Size value.
 * Returns null when the string doesn't parse — caller passes the raw value
 * through so Style_Parser can report a precise error.
 *
 * @return array{size: int|float|string, unit: string}|null
 */
function el_parse_size_string(string $value, string $default_unit): ?array
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }
    if ($trimmed === 'auto') {
        return ['size' => '', 'unit' => 'auto'];
    }
    $m = [];
    if (preg_match(pattern: '/^(-?\d+(?:\.\d+)?)\s*([a-zA-Z%]*)$/', subject: $trimmed, matches: $m) !== 1) {
        return null;
    }
    $num = $m[1];
    $unit = $m[2] === '' ? $default_unit : $m[2];
    // The regex guarantees $num is numeric — narrow it for the analyzer.
    if (!is_numeric($num)) {
        return null;
    }
    $size = !str_contains($num, '.') ? (int) $num : (float) $num;
    return el_size_value($size, $unit);
}

/**
 * Wrap an Object prop (dimensions, background, flex, shadow, ...). Each shape
 * field is recursively wrapped against its own declared prop type. A
 * pre-wrapped envelope is preserved — only the inner shape is normalized so
 * a half-wrapped value works too.
 */
function el_wrap_style_object(
    mixed $value,
    \Elementor\Modules\AtomicWidgets\PropTypes\Base\Object_Prop_Type $prop_type,
): mixed {
    if (!is_array($value)) {
        return $value;
    }
    /** @var array<string, mixed> $value */
    $type_key = $prop_type::get_key();
    /** @var array<string, object> $shape */
    $shape = $prop_type->get_shape();

    if (array_key_exists('$$type', $value) && array_key_exists('value', $value)) {
        if (is_array($value['value'])) {
            /** @var array<string, mixed> $inner */
            $inner = $value['value'];
            $value['value'] = el_wrap_object_shape_inner($inner, $shape);
        }
        return $value;
    }

    return [
        '$$type' => $type_key,
        'value' => el_wrap_object_shape_inner($value, $shape),
    ];
}

/**
 * Recursively wrap each known field inside an Object prop's inner value.
 * Accepts kebab-case aliases for shape keys that are declared in camelCase
 * (e.g. `flex-grow` → `flexGrow`, `h-offset` → `hOffset`) so the styles map
 * stays consistent with the rest of the kebab-case CSS prop ecosystem.
 * Truly unknown fields are left alone for downstream validation to flag.
 *
 * @param array<string, mixed>  $inner
 * @param array<string, object> $shape
 * @return array<string, mixed>
 */
function el_wrap_object_shape_inner(array $inner, array $shape): array
{
    $out = [];
    /** @var mixed $value */
    foreach ($inner as $key => $value) {
        // A list reaches here whenever a caller sends an array shape as a
        // sequence rather than as a keyed object — `transform` is the one that
        // invites it, since the CSS property really is a list. The key is then
        // an int, and the alias resolver is typed for a string, so the whole
        // request died with an uncaught TypeError instead of the per-property
        // validation error this file exists to produce. Passed through
        // untouched, exactly as an unrecognised string key already is.
        if (!is_string($key)) {
            $out[$key] = $value;
            continue;
        }

        $canonical = el_resolve_shape_key_alias($key, $shape);
        if ($canonical === null) {
            $out[$key] = $value;
            continue;
        }
        $out[$canonical] = el_wrap_style_value($value, $shape[$canonical]);
    }
    return $out;
}

/**
 * Map a shape key to its canonical form in $shape. Returns the key as-is when
 * $shape declares it, the camelCase equivalent when the key is a kebab-case
 * alias of a declared field, or null when the key is unknown.
 *
 * @param array<string, object> $shape
 */
function el_resolve_shape_key_alias(string $key, array $shape): ?string
{
    if (array_key_exists($key, $shape)) {
        return $key;
    }
    if (!str_contains($key, '-')) {
        return null;
    }
    $camel = lcfirst(str_replace(
        search: ' ',
        replace: '',
        subject: ucwords(str_replace(search: '-', replace: ' ', subject: $key)),
    ));
    return array_key_exists($camel, $shape) ? $camel : null;
}

/**
 * Wrap an Array prop (box-shadow, transform, filter, ...). Each item is
 * recursively wrapped against the declared item type. A pre-wrapped envelope
 * is preserved; raw lists become a wrapped list.
 */
function el_wrap_style_array(
    mixed $value,
    \Elementor\Modules\AtomicWidgets\PropTypes\Base\Array_Prop_Type $prop_type,
): mixed {
    if (!is_array($value)) {
        return $value;
    }
    /** @var array<string, mixed>|list<mixed> $value */
    $type_key = $prop_type::get_key();
    /** @var object $item_type */
    $item_type = $prop_type->get_item_type();

    if (array_key_exists('$$type', $value) && array_key_exists('value', $value)) {
        if (is_array($value['value'])) {
            $items = array_values($value['value']);
            $value['value'] = el_wrap_array_items($items, $item_type);
        }
        return $value;
    }

    if (!el_array_is_list($value)) {
        return $value;
    }
    $items = array_values($value);

    return [
        '$$type' => $type_key,
        'value' => el_wrap_array_items($items, $item_type),
    ];
}

/**
 * @param list<mixed> $items
 * @return list<mixed>
 */
function el_wrap_array_items(array $items, object $item_type): array
{
    $out = [];
    /** @var mixed $item */
    foreach ($items as $item) {
        $out[] = el_wrap_style_value($item, $item_type);
    }
    return $out;
}

const WPPILOT_STYLE_SCHEMA_CLASS = 'Elementor\\Modules\\AtomicWidgets\\Styles\\Style_Schema';

/**
 * Validate + auto-wrap a flat CSS-property → value map against the v4 Style
 * Schema. Shared by create-global-class, edit-global-class, and the atomic
 * widget generator's `base_styles` input so every surface reports the same
 * `unknown_properties` / `invalid_values` shape back to the caller.
 *
 * @param array<string, mixed> $styles
 * @return array{props: array<string, mixed>}|array{error: string, unknown_properties?: list<string>, invalid_values?: array<string, array{received_type: string, expected_types: list<string>, reason: string}>}
 */
function el_validate_style_props(array $styles): array
{
    if (!class_exists(WPPILOT_STYLE_SCHEMA_CLASS)) {
        return [
            'error' => 'Elementor Atomic Widgets Style Schema is not available. Requires Elementor v4 with the atomic-widgets module loaded.',
        ];
    }

    $schema = el_resolve_style_schema();
    $styles = el_normalize_style_props($styles, $schema);

    $unknown = [];
    $invalid = [];
    $props = [];

    foreach (array_keys($styles) as $property) {
        if (!array_key_exists($property, $schema)) {
            $unknown[] = $property;
            continue;
        }

        /** @var mixed $value */
        $value = $styles[$property];
        $prop_error = el_validate_single_style_prop($property, $value, $schema[$property]);
        if ($prop_error !== null) {
            $invalid[$property] = $prop_error;
            continue;
        }

        /** @var array<string, mixed> $value */
        $props[$property] = $value;
    }

    if ($unknown !== [] || $invalid !== []) {
        $parts = [];
        if ($unknown !== []) {
            $parts[] = sprintf('unknown properties: %s', implode(', ', $unknown));
        }
        if ($invalid !== []) {
            $parts[] = sprintf('invalid values for: %s', implode(', ', array_keys($invalid)));
        }

        $error = [
            'error' => sprintf(
                'Invalid style payload — %s. See "invalid_values"/"unknown_properties" for per-property details; call wppilot/elementor-get-style-schema only if you need the full list of valid CSS properties.',
                implode('; ', $parts),
            ),
        ];
        if ($unknown !== []) {
            $error['unknown_properties'] = $unknown;
        }
        if ($invalid !== []) {
            $error['invalid_values'] = $invalid;
        }
        return $error;
    }

    return ['props' => $props];
}

/**
 * Validate a single style prop against its schema entry. Returns `null` when
 * the value is acceptable, or a rich error entry describing what was received
 * vs. which `$$type`(s) are expected so the caller can correct without a
 * round-trip to `get-style-schema`.
 *
 * @param mixed $value
 * @return array{received_type: string, expected_types: list<string>, reason: string}|null
 */
function el_validate_single_style_prop(string $property, mixed $value, object $prop_type): ?array
{
    $expected_types = el_expected_prop_types($prop_type);

    if (!is_array($value) || !array_key_exists('$$type', $value) || !array_key_exists('value', $value)) {
        return [
            'received_type' => '(missing)',
            'expected_types' => $expected_types,
            'reason' => sprintf('Value must be an object with keys "$$type" and "value". Expected $$type one of: %s.', implode(
                ', ',
                $expected_types,
            )),
        ];
    }

    if (!method_exists($prop_type, 'validate') || !$prop_type->validate($value)) {
        $received_type = is_string($value['$$type'] ?? null) ? $value['$$type'] : '(unset)';
        return [
            'received_type' => $received_type,
            'expected_types' => $expected_types,
            'reason' => sprintf(
                'Value does not match. Got $$type="%s", expected one of: %s. Call wppilot/elementor-get-style-schema for the exact shape of "%s".',
                $received_type,
                implode(', ', $expected_types),
                $property,
            ),
        ];
    }

    return null;
}
