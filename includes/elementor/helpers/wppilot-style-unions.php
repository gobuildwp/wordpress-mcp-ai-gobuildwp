<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor v4: choosing an alternative from a union-typed style prop.
 *
 * When a prop accepts several shapes, the best match is scored rather than
 * guessed, so an ambiguous value lands on the alternative it fits most fully.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Pick the right alternative inside a Union prop and recursively wrap. Routing:
 *   - already wrapped: pick the alt whose key matches `$$type`
 *   - int/float: prefer `number`, fall back to `size`
 *   - bool: prefer `boolean`
 *   - string starting with digit / "auto": prefer `size`, then `string`
 *   - other string: prefer `string` (then `color`)
 *   - assoc array with size+unit keys: prefer `size`
 *   - other assoc array: pick the Object alt whose shape matches the most keys
 *   - list array: pick the Array alt
 */
function el_wrap_style_union(
    mixed $value,
    \Elementor\Modules\AtomicWidgets\PropTypes\Union_Prop_Type $prop_type,
    string $prop_name = '',
): mixed {
    /** @var array<string, object> $alts */
    $alts = $prop_type->get_prop_types();

    if (is_array($value) && array_key_exists('$$type', $value) && array_key_exists('value', $value)) {
        $key = is_string($value['$$type']) ? $value['$$type'] : '';
        if ($key !== '' && array_key_exists($key, $alts)) {
            return el_wrap_style_value($value, $alts[$key], $prop_name);
        }
        return $value;
    }

    $picked = el_pick_union_alternative($value, $alts);
    if ($picked === null) {
        return $value;
    }
    return el_wrap_style_value($value, $picked, $prop_name);
}

/**
 * @param array<string, object> $alts
 */
function el_pick_union_alternative(mixed $value, array $alts): ?object
{
    if (is_int($value) || is_float($value)) {
        return $alts['number'] ?? $alts['size'] ?? null;
    }
    if (is_bool($value)) {
        return $alts['boolean'] ?? null;
    }
    if (is_string($value)) {
        return el_pick_union_string_alt($value, $alts);
    }
    if (is_array($value)) {
        return el_pick_union_array_alt($value, $alts);
    }
    return null;
}

/**
 * String routing inside a union: numeric-leading or "auto" → size; otherwise
 * plain string (or color when string isn't an alternative).
 *
 * @param array<string, object> $alts
 */
function el_pick_union_string_alt(string $value, array $alts): ?object
{
    if ($value === 'auto' || preg_match(pattern: '/^-?\d/', subject: $value) === 1) {
        return $alts['size'] ?? $alts['number'] ?? $alts['string'] ?? null;
    }
    return $alts['string'] ?? $alts['color'] ?? el_sole_string_alternative($alts);
}

/**
 * The one string-like alternative in a union that is not a variable reference.
 *
 * `font-family` is a union of `font-family` and `global-font-variable`, and
 * neither is keyed `string` or `color`, so routing by key alone found nothing
 * and passed the value through unwrapped — the caller then got a validation
 * failure for a plain, correct font name. Every other string-ish union in the
 * schema resolved, which made font-family look like a rule of its own rather
 * than a gap in the routing.
 *
 * A `global-*-variable` alternative is a reference to a token by id, never a
 * literal, so it is never the right home for a bare string. If exactly one
 * other string alternative remains it is unambiguous; more than one and the
 * value passes through so the parser reports a precise error rather than this
 * guessing.
 *
 * @param array<string, object> $alts
 */
function el_sole_string_alternative(array $alts): ?object
{
    $found = null;
    foreach ($alts as $key => $alt) {
        if (str_starts_with((string) $key, 'global-')) {
            continue;
        }
        if (!$alt instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type) {
            continue;
        }
        if ($found !== null) {
            return null;
        }
        $found = $alt;
    }
    return $found;
}

/**
 * Array routing inside a union: list → array alt; size-shaped assoc → size;
 * other assoc → best matching object alt.
 *
 * @param array<int|string, mixed> $value
 * @param array<string, object>    $alts
 */
function el_pick_union_array_alt(array $value, array $alts): ?object
{
    if (el_array_is_list($value)) {
        foreach ($alts as $alt) {
            if ($alt instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Base\Array_Prop_Type) {
                return $alt;
            }
        }
        return null;
    }
    if (array_key_exists('size', $value) && array_key_exists('unit', $value) && array_key_exists('size', $alts)) {
        return $alts['size'];
    }
    /** @var array<string, mixed> $value */
    return el_best_object_alternative($value, $alts);
}

/**
 * Pick the Object_Prop_Type alternative whose shape matches the most input
 * keys — handles e.g. `padding: {block-start, inline-end, ...}` choosing the
 * Dimensions branch in a Union(Dimensions, Size).
 *
 * @param array<string, mixed>  $value
 * @param array<string, object> $alts
 */
function el_best_object_alternative(array $value, array $alts): ?object
{
    $best = null;
    $best_match = 0;
    foreach ($alts as $alt) {
        if (!$alt instanceof \Elementor\Modules\AtomicWidgets\PropTypes\Base\Object_Prop_Type) {
            continue;
        }
        /** @var array<string, object> $shape */
        $shape = $alt->get_shape();
        $matches = el_count_shape_matches(array_keys($value), $shape);
        if ($matches > $best_match) {
            $best_match = $matches;
            $best = $alt;
        }
    }
    return $best;
}

/**
 * @param list<int|string>      $keys
 * @param array<string, object> $shape
 */
function el_count_shape_matches(array $keys, array $shape): int
{
    $matches = 0;
    foreach ($keys as $k) {
        if (!is_string($k) || !array_key_exists($k, $shape)) {
            continue;
        }
        $matches++;
    }
    return $matches;
}
