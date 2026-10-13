<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor v4: style class ids on an element.
 *
 * Class ids must be unique per document and safe as CSS identifiers, so an
 * incoming id may be rewritten — and every reference to it renamed in step.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Extract the plain list of class names from a `classes` setting value
 * which may be in wrapped `{$$type, value}` format or a plain array.
 *
 * @return list<string>
 */
function el_extract_classes_list(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    if (array_key_exists('value', $raw) && is_array($raw['value'])) {
        /** @var list<string> */
        return array_values($raw['value']);
    }

    if (el_array_is_list($raw)) {
        /** @var list<string> $raw */
        return $raw;
    }

    return [];
}

/**
 * Recursively rename per-element style IDs that are not valid CSS class
 * identifiers (e.g. start with a digit). Elementor element IDs are 7-hex
 * strings and frequently begin with a digit, and the agent commonly
 * derives style IDs from them (`8750d6d-bg`, `22a3022-h1`, ...). The
 * Elementor CSS generator emits these literally as selectors
 * (`.elementor .8750d6d-bg`) — but per the CSS Syntax spec, a class
 * selector may not start with a digit, so the browser silently drops
 * the entire rule and the page renders unstyled.
 *
 * For every per-element style id that begins with a digit (or any other
 * pattern that would be invalid as a CSS identifier), prepend `e-` so
 * the rule survives the parser. Update the matching `id` field inside
 * the style definition and rewrite the corresponding entries in
 * `settings.classes` so the HTML class attribute and the generated CSS
 * selector stay in sync.
 *
 * Global Class IDs referenced in `settings.classes` but not present in
 * the node's `styles` map are left alone — those are managed by the
 * Global Classes API, not per-element.
 *
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function el_sanitize_style_class_ids(array $node): array
{
    $node = el_sanitize_style_class_ids_on_node($node);

    /** @var list<array<string, mixed>> $children */
    $children = is_array($node['elements'] ?? null) ? $node['elements'] : [];
    $new_children = [];
    foreach ($children as $child) {
        $new_children[] = el_sanitize_style_class_ids($child);
    }
    $node['elements'] = $new_children;

    return $node;
}

/**
 * Apply the CSS-identifier rename to a single node's `styles` map and
 * mirror the renames into `settings.classes`.
 *
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function el_sanitize_style_class_ids_on_node(array $node): array
{
    /** @var array<string, mixed> $styles */
    $styles = is_array($node['styles'] ?? null) ? $node['styles'] : [];
    if ($styles === []) {
        return $node;
    }

    /** @var array<string, string> $renames */
    $renames = [];
    foreach (array_keys($styles) as $key) {
        $safe = el_make_safe_class_id($key);
        if ($safe !== $key) {
            $renames[$key] = $safe;
        }
    }

    if ($renames === []) {
        return $node;
    }

    /** @var array<string, mixed> $new_styles */
    $new_styles = [];
    foreach (array_keys($styles) as $key) {
        $new_key = $renames[$key] ?? $key;
        /** @var mixed $value */
        $value = $styles[$key];
        if (is_array($value) && ($value['id'] ?? null) === $key) {
            $value['id'] = $new_key;
        }
        $new_styles[$new_key] = $value;
    }
    $node['styles'] = $new_styles;

    /** @var array<string, mixed> $settings */
    $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
    $node['settings'] = el_apply_class_renames_to_settings($settings, $renames);

    return $node;
}

/**
 * Rewrite the `classes` value in a settings array so any per-element
 * style id mentioned there is replaced with its sanitized counterpart.
 * Accepts both the wrapped `{$$type: "classes", value: [...]}` shape
 * and the legacy bare list form.
 *
 * @param array<string, mixed> $settings
 * @param array<string, string> $renames
 * @return array<string, mixed>
 */
function el_apply_class_renames_to_settings(array $settings, array $renames): array
{
    if (!array_key_exists('classes', $settings)) {
        return $settings;
    }

    /** @var mixed $raw_classes */
    $raw_classes = $settings['classes'];

    if (
        is_array($raw_classes)
        && ($raw_classes['$$type'] ?? null) !== null
        && is_array($raw_classes['value'] ?? null)
    ) {
        /** @var list<mixed> $values */
        $values = $raw_classes['value'];
        $raw_classes['value'] = el_rename_class_list($values, $renames);
        $settings['classes'] = $raw_classes;
        return $settings;
    }

    if (is_array($raw_classes)) {
        /** @var list<mixed> $values */
        $values = $raw_classes;
        $settings['classes'] = el_rename_class_list($values, $renames);
    }

    return $settings;
}

/**
 * Apply rename map to a flat list of class names.
 *
 * @param list<mixed> $values
 * @param array<string, string> $renames
 * @return list<mixed>
 */
function el_rename_class_list(array $values, array $renames): array
{
    $out = [];
    foreach (array_keys($values) as $i) {
        /** @var mixed $cls */
        $cls = $values[$i];
        if (is_string($cls) && array_key_exists($cls, $renames)) {
            $out[] = $renames[$cls];
            continue;
        }
        $out[] = $cls;
    }
    return $out;
}

/**
 * Return a CSS-identifier-safe version of an arbitrary string id by
 * prepending `e-` when the input would be illegal as a class selector
 * (begins with a digit, with `-<digit>`, or with `--`). Leaves valid
 * ids untouched. Empty input is returned unchanged.
 */
function el_make_safe_class_id(string $id): string
{
    if ($id === '') {
        return $id;
    }
    $first = $id[0];
    if ($first >= '0' && $first <= '9') {
        return 'e-' . $id;
    }
    if ($first !== '-' || strlen($id) < 2) {
        return $id;
    }
    $second = $id[1];
    if ($second >= '0' && $second <= '9' || $second === '-') {
        return 'e' . $id;
    }
    return $id;
}

/**
 * Recursively sync per-element style IDs into settings.classes.
 *
 * Elementor v4 needs style IDs in TWO places: the `styles` field (for CSS
 * generation) and `settings.classes` (for the class to appear in HTML).
 * This function walks the tree and auto-adds any style IDs from `styles`
 * into `settings.classes` so the agent doesn't need to duplicate them.
 *
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function el_sync_style_classes(array $node): array
{
    /** @var array<string, mixed> $settings */
    $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];

    // Always normalize classes to wrapped {$$type, value} format.
    // Also merge per-element style IDs from the `styles` field.
    $existing = el_extract_classes_list($settings['classes'] ?? null);

    /** @var array<string, mixed> $styles */
    $styles = is_array($node['styles'] ?? null) ? $node['styles'] : [];
    foreach (array_keys($styles) as $sid) {
        if (in_array($sid, $existing, strict: true)) {
            continue;
        }
        $existing[] = $sid;
    }

    if ($existing !== []) {
        $settings['classes'] = ['$$type' => 'classes', 'value' => $existing];
        $node['settings'] = $settings;
    }

    // Recurse into children.
    /** @var list<array<string, mixed>> $children */
    $children = is_array($node['elements'] ?? null) ? $node['elements'] : [];
    $new_children = [];
    foreach ($children as $child) {
        $new_children[] = el_sync_style_classes($child);
    }
    $node['elements'] = $new_children;

    return $node;
}
