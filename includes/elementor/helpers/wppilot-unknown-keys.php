<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor v4: reporting keys that were dropped.
 *
 * Silently discarding an unrecognized subkey leaves the agent believing a
 * setting applied. These walkers collect the dropped paths for the response.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Walk a normalized style entry and collect errors for any shape sub-keys
 * that don't exist in the schema. Object_Prop_Type::validate accepts missing
 * fields (defaults pass), so unknown sub-keys survive parse-time validation
 * and get silently ignored at render — this guards the call site so the agent
 * sees an explicit rejection instead of a write that looks successful.
 *
 * @param array<string, object> $schema
 * @return list<array{style_id: string, reason: string, path: string}>
 */
function el_collect_dropped_subkey_errors(string $style_id, mixed $style_entry, array $schema): array
{
    if ($schema === [] || !is_array($style_entry) || !is_array($style_entry['variants'] ?? null)) {
        return [];
    }
    /** @var list<mixed> $variants */
    $variants = $style_entry['variants'];
    $errors = [];
    foreach (array_keys($variants) as $vi) {
        /** @var mixed $variant */
        $variant = $variants[$vi];
        if (!is_array($variant) || !is_array($variant['props'] ?? null)) {
            continue;
        }
        /** @var array<string, mixed> $props */
        $props = $variant['props'];
        /** @var mixed $prop_value */
        foreach ($props as $prop_name => $prop_value) {
            if (!array_key_exists($prop_name, $schema)) {
                continue;
            }
            $prefix = "variants[{$vi}].props.{$prop_name}";
            foreach (el_walk_unknown_shape_subkeys($prop_value, $schema[$prop_name], $prefix) as $path) {
                $errors[] = ['style_id' => $style_id, 'reason' => 'unknown_shape_subkey', 'path' => $path];
            }
        }
    }
    return $errors;
}

/**
 * Recursively collect paths to sub-keys that don't exist in the prop type's
 * shape. Unwraps `$$type`/`value` envelopes, routes unions to the matching
 * alternative, and recurses into nested objects/arrays so a deep mismatch
 * (e.g. `shadow[0].h-offset` spelled wrong) is caught just like a shallow one.
 *
 * @return list<string>
 */
function el_walk_unknown_shape_subkeys(mixed $value, object $prop_type, string $path): array
{
    if (!is_array($value)) {
        return [];
    }
    if (($value['$$type'] ?? null) === 'dynamic') {
        return [];
    }
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Union_Prop_Type) {
        $type_key = is_string($value['$$type'] ?? null) ? $value['$$type'] : null;
        /** @var array<string, object> $alts */
        $alts = $prop_type->get_prop_types();
        if ($type_key === null || !array_key_exists($type_key, $alts)) {
            return [];
        }
        return el_walk_unknown_shape_subkeys($value, $alts[$type_key], $path);
    }
    // Size_Prop_Type extends Object_Prop_Type but its `size`/`unit` fields are
    // the canonical shape — no CSS-style aliasing applies.
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Size_Prop_Type) {
        return [];
    }
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Base\Object_Prop_Type) {
        return el_walk_unknown_object_subkeys($value, $prop_type, $path);
    }
    if ($prop_type instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Base\Array_Prop_Type) {
        return el_walk_unknown_array_subkeys($value, $prop_type, $path);
    }
    return [];
}

/**
 * Collect unknown sub-keys of an Object prop. Recurses into known sub-keys so
 * a bad `shadow[0].h-ofset` (under a valid `box-shadow`) is still flagged.
 *
 * @return list<string>
 */
function el_walk_unknown_object_subkeys(
    mixed $value,
    \Elementor\Modules\AtomicWidgets\PropTypes\Base\Object_Prop_Type $prop_type,
    string $path,
): array {
    if (!is_array($value)) {
        return [];
    }
    $inner = $value;
    if (array_key_exists('$$type', $value) && array_key_exists('value', $value)) {
        /** @var mixed $wrapped_inner */
        $wrapped_inner = $value['value'];
        if (!is_array($wrapped_inner)) {
            return [];
        }
        $inner = $wrapped_inner;
    }
    /** @var array<string, mixed> $inner */
    /** @var array<string, object> $shape */
    $shape = $prop_type->get_shape();
    $errors = [];
    /** @var mixed $sub_value */
    foreach ($inner as $sub_key => $sub_value) {
        $sub_path = $path . '.' . $sub_key;
        if (!array_key_exists($sub_key, $shape)) {
            $errors[] = $sub_path;
            continue;
        }
        $errors = [...$errors, ...el_walk_unknown_shape_subkeys($sub_value, $shape[$sub_key], $sub_path)];
    }
    return $errors;
}

/**
 * Collect unknown sub-keys inside each item of an Array prop (box-shadow,
 * transform, filter, ...). Items are indexed in the path so a caller can
 * pinpoint which entry of the list owns the bad sub-key.
 *
 * @return list<string>
 */
function el_walk_unknown_array_subkeys(
    mixed $value,
    \Elementor\Modules\AtomicWidgets\PropTypes\Base\Array_Prop_Type $prop_type,
    string $path,
): array {
    if (!is_array($value)) {
        return [];
    }
    $items = $value;
    if (array_key_exists('$$type', $value) && array_key_exists('value', $value)) {
        /** @var mixed $wrapped_items */
        $wrapped_items = $value['value'];
        if (!is_array($wrapped_items)) {
            return [];
        }
        $items = $wrapped_items;
    }
    if (!el_array_is_list($items)) {
        return [];
    }
    /** @var object $item_type */
    $item_type = $prop_type->get_item_type();
    $errors = [];
    /** @var mixed $item */
    foreach ($items as $i => $item) {
        $errors = [...$errors, ...el_walk_unknown_shape_subkeys($item, $item_type, "{$path}[{$i}]")];
    }
    return $errors;
}
