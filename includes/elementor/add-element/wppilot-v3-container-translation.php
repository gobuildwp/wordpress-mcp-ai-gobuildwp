<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor: translating v3 container settings to v4.
 *
 * A document may carry classic container settings that have no direct v4
 * equivalent. These are drained from the input and re-expressed as v4 style
 * props, per breakpoint.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Inject missing v3 container defaults that don't map onto v4 e-flexbox
 * CSS defaults. Runs only when the node looks like it came from a v3
 * tree (at least one v3-only marker key present). Native v4 writes
 * pass through untouched and get pure CSS defaults.
 *
 * Today synthesizes:
 *   - `flex_direction: "column"` when absent (v3 default column vs
 *     v4 CSS default row).
 *
 * @param array<string, mixed> $settings
 * @return array<string, mixed>
 */
function el_apply_v3_container_defaults(array $settings): array
{
    if (!el_has_v3_container_markers($settings)) {
        return $settings;
    }
    if (!array_key_exists('flex_direction', $settings)) {
        $settings['flex_direction'] = 'column';
    }
    return $settings;
}

/**
 * Detect whether an atomic container's settings carry the fingerprint
 * of a v3 → v4 conversion. v3 container keys like `content_width`,
 * `boxed_width`, `flex_gap`, `flex_justify_content`, `html_tag`,
 * `__globals__`, etc. have no v4 atomic counterpart, so their
 * presence (unwrapped) in a node's `settings` is a strong signal that
 * the agent is converting a v3 tree rather than authoring v4 atomic
 * from scratch.
 *
 * Used to gate the v3-default synthesizers (flex_direction: column,
 * boxed_width from kit) — they must NOT fire on native v4 writes,
 * which are expected to behave like a plain `e-flexbox` with CSS
 * defaults.
 *
 * Ambiguous keys whose name also exists as a v4 style prop
 * (`padding`, `margin`, `background_color`, ...) are treated as v3
 * markers only when the value is NOT already `{$$type, value}`-
 * wrapped. A wrapped value means the agent speaks v4 and is
 * accidentally using a settings slot, so we do not flip into v3 mode.
 *
 * @param array<string, mixed> $settings
 */
function el_has_v3_container_markers(array $settings): bool
{
    $markers = [
        'content_width',
        'boxed_width',
        'html_tag',
        '__globals__',
        '__dynamic__',
        'flex_direction',
        'flex_wrap',
        'flex_gap',
        'flex_align_items',
        'flex_justify_content',
        'padding',
        'margin',
        'min_height',
        'max_height',
        'border_radius',
        'width',
        'min_width',
        'max_width',
        'background_color',
    ];
    foreach (array_keys($settings) as $key) {
        $resolved = el_v3_resolve_breakpoint_key($key);
        if (!in_array($resolved['base'], $markers, strict: true)) {
            continue;
        }
        /** @var mixed $value */
        $value = $settings[$key];
        if (is_array($value) && array_key_exists('$$type', $value) && array_key_exists('value', $value)) {
            continue;
        }
        return true;
    }
    return false;
}

/**
 * Pull a v3 `{unit, size}` shape (or a bare numeric) into a normalized
 * `{unit, size}` array. Returns null when the value is empty or
 * unparseable.
 *
 * @return array{unit: string, size: int|float}|null
 */
function el_extract_v3_size_value(mixed $raw): ?array
{
    if (is_array($raw)) {
        /** @var mixed $size */
        $size = $raw['size'] ?? null;
        if ($size === '' || $size === null || !is_numeric($size)) {
            return null;
        }
        $unit = is_string($raw['unit'] ?? null) && $raw['unit'] !== '' ? $raw['unit'] : 'px';
        $num = is_int($size) ? $size : (float) $size;
        return ['unit' => $unit, 'size' => $num];
    }
    if (is_numeric($raw)) {
        $num = is_int($raw) ? $raw : (float) $raw;
        return ['unit' => 'px', 'size' => $num];
    }
    return null;
}

/**
 * Recursively translate v3 container-level settings into v4 style props
 * on atomic containers (e-flexbox, e-div-block). Agents converting v3
 * pages commonly pass legacy settings like `width: {unit: "%", size: 65}`
 * or `flex_direction: "row"` directly on the atomic container; these are
 * NOT valid settings on atomic elements — they belong in `styles` as v4
 * style props. Without this translation, `el_coerce_tree_settings` would
 * silently drop them and the layout breaks (65%/35% columns become
 * 100%, row layouts collapse to column, etc.).
 *
 * Only a curated allowlist of well-known v3 container settings is
 * translated — responsive variants, `boxed_width`, `content_width` and
 * dynamic properties require agent-level decisions and are left alone.
 *
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function el_translate_v3_container_settings(array $node): array
{
    $el_type = (string) ($node['elType'] ?? '');
    if (in_array($el_type, WPPILOT_ATOMIC_CONTAINER_TYPES, strict: true)) {
        $node = el_translate_v3_container_settings_on_node($node);
    }

    /** @var list<array<string, mixed>> $children */
    $children = is_array($node['elements'] ?? null) ? $node['elements'] : [];
    $new_children = [];
    foreach ($children as $child) {
        $new_children[] = el_translate_v3_container_settings($child);
    }
    $node['elements'] = $new_children;

    return $node;
}

/**
 * Apply the v3→v4 setting translation to a single atomic container
 * node. Drains recognized keys from `settings` and injects them as
 * wrapped style props into the node's first style variant.
 *
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function el_translate_v3_container_settings_on_node(array $node): array
{
    /** @var array<string, mixed> $settings */
    $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
    if ($settings === []) {
        return $node;
    }

    [$settings, $new_props_by_bp, $touched] = el_drain_v3_container_settings($settings);

    if (!$touched) {
        return $node;
    }

    $node['settings'] = $settings;

    if ($new_props_by_bp === []) {
        return $node;
    }

    /** @var array<string, mixed> $existing_styles */
    $existing_styles = is_array($node['styles'] ?? null) ? $node['styles'] : [];
    $node['styles'] = el_inject_props_into_first_style(
        $existing_styles,
        (string) ($node['id'] ?? 'el'),
        $new_props_by_bp,
    );

    return $node;
}

/**
 * Walk a settings array, drain every key recognized by the v3 container
 * translation map (including responsive `_tablet` / `_mobile` / ...
 * variants), and return the stripped settings, a breakpoint-keyed map
 * of wrapped v4 props ready for injection into a style, and a flag
 * indicating whether any v3 key was consumed.
 *
 * @param array<string, mixed> $settings
 * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>, 2: bool}
 */
function el_drain_v3_container_settings(array $settings): array
{
    $map = el_v3_container_translation_map();
    /** @var array<string, array<string, mixed>> $new_props_by_bp */
    $new_props_by_bp = [];
    $touched = false;

    foreach (array_keys($settings) as $key) {
        $resolved = el_v3_resolve_breakpoint_key($key);
        if (!array_key_exists($resolved['base'], $map)) {
            continue;
        }
        /** @var mixed $raw */
        $raw = $settings[$key];
        // Skip values already in v4-wrapped form — defer to coercion.
        if (is_array($raw) && array_key_exists('$$type', $raw) && array_key_exists('value', $raw)) {
            continue;
        }
        // Consume the v3 key unconditionally: if the value is empty
        // (e.g. `width: {size: ""}` — v3's "unset" marker) we want a
        // silent no-op, not a downstream validator error on the
        // leftover v3-shaped value.
        unset($settings[$key]);
        $touched = true;

        $translation = $map[$resolved['base']];
        $wrapped = el_v3_container_translate_value($raw, $translation['kind']);
        if ($wrapped === null) {
            continue;
        }

        $bp_key = $resolved['breakpoint'] ?? '__null__';
        if (!array_key_exists($bp_key, $new_props_by_bp)) {
            $new_props_by_bp[$bp_key] = [];
        }
        $new_props_by_bp[$bp_key][$translation['prop']] = $wrapped;
    }

    return [$settings, $new_props_by_bp, $touched];
}

/**
 * Read the v3 `tag` / `html_tag` value (in that priority order) from a
 * settings array, remove both keys, and return the chosen value (or
 * null if neither was set).
 *
 * @param array<string, mixed> $settings
 * @return array{0: array<string, mixed>, 1: mixed}
 */
function el_pop_v3_tag_setting(array $settings): array
{
    /** @var mixed $tag */
    $tag = null;
    foreach (['html_tag', 'tag'] as $key) {
        if (!array_key_exists($key, $settings)) {
            continue;
        }
        /** @var mixed $tag */
        $tag = $settings[$key];
        unset($settings[$key]);
    }
    return [$settings, $tag];
}

/**
 * Pop every setting key (including responsive `_tablet` / `_mobile` /
 * ... variants) whose base name is in the given allowlist. Returns the
 * settings array stripped of those keys plus a map of the popped keys
 * to their values, preserving the original suffixed key so the
 * downstream translator can route each value to its own breakpoint
 * variant.
 *
 * @param array<string, mixed> $settings
 * @param list<string> $bases
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function el_pop_v3_settings_with_bases(array $settings, array $bases): array
{
    $popped = [];
    foreach (array_keys($settings) as $key) {
        $resolved = el_v3_resolve_breakpoint_key($key);
        if (!in_array($resolved['base'], $bases, strict: true)) {
            continue;
        }
        $popped[$key] = $settings[$key];
        unset($settings[$key]);
    }
    return [$settings, $popped];
}

/**
 * Map of v3 responsive suffixes to Elementor v4 breakpoint names. Used
 * by the translator and the boxed splitter to detect responsive
 * variants of a base v3 setting (e.g. `padding_tablet` → padding on
 * the `tablet` breakpoint variant).
 *
 * Order matters: longest suffix first, so `_tablet_extra` is matched
 * before the shorter `_tablet` would consume the prefix.
 *
 * @return array<string, string>
 */
function el_v3_breakpoint_suffixes(): array
{
    return [
        '_tablet_extra' => 'tablet_extra',
        '_mobile_extra' => 'mobile_extra',
        '_widescreen' => 'widescreen',
        '_laptop' => 'laptop',
        '_tablet' => 'tablet',
        '_mobile' => 'mobile',
    ];
}

/**
 * Decompose a v3 setting key into its base name and breakpoint. A key
 * that does not end in any known suffix returns breakpoint `null` and
 * the full key as the base — that is the desktop/default value.
 *
 * @return array{base: string, breakpoint: string|null}
 */
function el_v3_resolve_breakpoint_key(string $key): array
{
    foreach (el_v3_breakpoint_suffixes() as $suffix => $bp) {
        if (str_ends_with($key, $suffix)) {
            return ['base' => substr($key, offset: 0, length: -strlen($suffix)), 'breakpoint' => $bp];
        }
    }
    return ['base' => $key, 'breakpoint' => null];
}

/**
 * Compose a v3 setting key from a base name and an Elementor v4
 * breakpoint name. Returns the bare base name when the breakpoint is
 * null or unknown.
 */
function el_v3_key_with_breakpoint(string $base, ?string $breakpoint): string
{
    if ($breakpoint === null) {
        return $base;
    }
    foreach (el_v3_breakpoint_suffixes() as $suffix => $bp) {
        if ($bp === $breakpoint) {
            return $base . $suffix;
        }
    }
    return $base;
}

/**
 * The curated map of v3 container settings we translate automatically.
 * Each entry pairs the v3 setting key with the v4 style prop name and a
 * conversion kind that `el_v3_container_translate_value` knows how to
 * handle.
 *
 * @return array<string, array{prop: string, kind: string}>
 */
function el_v3_container_translation_map(): array
{
    return [
        'width' => ['prop' => 'width', 'kind' => 'size'],
        'min_width' => ['prop' => 'min-width', 'kind' => 'size'],
        'max_width' => ['prop' => 'max-width', 'kind' => 'size'],
        'min_height' => ['prop' => 'min-height', 'kind' => 'size'],
        'max_height' => ['prop' => 'max-height', 'kind' => 'size'],
        'flex_gap' => ['prop' => 'gap', 'kind' => 'size'],
        'border_radius' => ['prop' => 'border-radius', 'kind' => 'size'],
        'flex_direction' => ['prop' => 'flex-direction', 'kind' => 'string'],
        'flex_wrap' => ['prop' => 'flex-wrap', 'kind' => 'string'],
        'flex_align_items' => ['prop' => 'align-items', 'kind' => 'string'],
        'flex_justify_content' => ['prop' => 'justify-content', 'kind' => 'string'],
        'padding' => ['prop' => 'padding', 'kind' => 'dimensions'],
        'margin' => ['prop' => 'margin', 'kind' => 'dimensions'],
        'background_color' => ['prop' => 'background', 'kind' => 'background-color'],
    ];
}

/**
 * Convert a v3 setting value into the corresponding v4 wrapped style
 * prop. Returns null when the value is empty or cannot be converted.
 */
function el_v3_container_translate_value(mixed $raw, string $kind): ?array
{
    if ($kind === 'size' && is_array($raw)) {
        /** @var array<string, mixed> $raw */
        return el_v3_size_to_v4_wrapped($raw);
    }
    if ($kind === 'string' && is_string($raw) && $raw !== '') {
        return ['$$type' => 'string', 'value' => $raw];
    }
    if ($kind === 'dimensions' && is_array($raw)) {
        /** @var array<string, mixed> $raw */
        return el_v3_dimensions_to_v4_wrapped($raw);
    }
    if ($kind === 'background-color' && is_string($raw) && $raw !== '') {
        return el_v3_color_to_v4_background($raw);
    }
    return null;
}

/**
 * `{unit, size}` → `{$$type: "size", value: {size, unit}}`. Returns
 * null when size is empty (v3 "unset" marker) or invalid.
 *
 * @param array<string, mixed> $size
 */
function el_v3_size_to_v4_wrapped(array $size): ?array
{
    /** @var mixed $size_value */
    $size_value = $size['size'] ?? null;
    if ($size_value === '' || $size_value === null) {
        return null;
    }
    if (!is_numeric($size_value)) {
        return null;
    }
    $unit = is_string($size['unit'] ?? null) && $size['unit'] !== '' ? $size['unit'] : 'px';
    $num = is_int($size_value) ? $size_value : (float) $size_value;
    return [
        '$$type' => 'size',
        'value' => [
            'size' => $num,
            'unit' => $unit,
        ],
    ];
}

/**
 * `{unit, top, right, bottom, left, isLinked}` → v4 dimensions with
 * logical sides. Returns null when all four values are empty.
 *
 * @param array<string, mixed> $dim
 */
function el_v3_dimensions_to_v4_wrapped(array $dim): ?array
{
    $unit = is_string($dim['unit'] ?? null) && $dim['unit'] !== '' ? $dim['unit'] : 'px';
    $positions = [
        'block-start' => 'top',
        'inline-end' => 'right',
        'block-end' => 'bottom',
        'inline-start' => 'left',
    ];

    $value = [];
    foreach ($positions as $v4_side => $v3_side) {
        /** @var mixed $raw */
        $raw = $dim[$v3_side] ?? null;
        if ($raw === '' || $raw === null || !is_numeric($raw)) {
            continue;
        }
        $num = is_int($raw) ? $raw : (float) $raw;
        $value[$v4_side] = [
            '$$type' => 'size',
            'value' => ['size' => $num, 'unit' => $unit],
        ];
    }

    if ($value === []) {
        return null;
    }

    return ['$$type' => 'dimensions', 'value' => $value];
}

/**
 * `"#E52600"` → full background prop with color overlay.
 */
function el_v3_color_to_v4_background(string $color): array
{
    return [
        '$$type' => 'background',
        'value' => [
            'color' => ['$$type' => 'color', 'value' => $color],
        ],
    ];
}
