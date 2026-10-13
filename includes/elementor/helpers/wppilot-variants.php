<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Style variants: the base look plus every breakpoint and state that departs
 * from it.
 *
 * Elementor v4 stores a style as a list of variants rather than as one flat
 * declaration, and the same assembly runs for a style bound to a single element
 * and for one shared as a global class. It lives here because the local case is
 * part of writing an element at all, and duplicating it would let the two
 * drift — a hover state that works on an element and not on a class is exactly
 * the kind of difference nobody would think to look for.
 */

if (!defined('ABSPATH')) {
    exit();
}

// Breakpoint id for the base (unconditional) style variant. Elementor's own
// style-parser rejects a base variant whose meta.breakpoint is null
// ("missing_or_invalid_value"), and its atomic styles manager registers
// "desktop" as the canonical base breakpoint (DEFAULT_BREAKPOINT). Writing the
// base with breakpoint "desktop" (not null) is what makes the v4 editor treat
// the values as explicitly defined instead of inherited. The frontend renders
// either way because group_by_breakpoint() maps null -> "desktop", but the
// editor validates variants through the parser, which is why null must not ship.
const GC_BASE_BREAKPOINT = 'desktop';

/**
 * Build the full variant list: the base properties, then the caller's extras.
 *
 * @param array<string, mixed> $styles Base properties, in Style Schema form.
 * @param array<string, mixed> $input  Carries an optional `variants` list.
 * @return array{variants: list<array<string, mixed>>}|array{error: string, unknown_properties?: list<string>, invalid_values?: array<string, mixed>}
 */
function gc_assemble_variants(array $styles, array $input): array
{
    $validation = el_validate_style_props($styles);
    if (!array_key_exists('props', $validation)) {
        /** @var array{error: string, unknown_properties?: list<string>, invalid_values?: array<string, mixed>} $validation */
        return $validation;
    }

    $all = [
        ['meta' => ['breakpoint' => GC_BASE_BREAKPOINT, 'state' => null], 'props' => $validation['props']],
    ];

    /** @var list<array<string, mixed>> $extra_raw */
    $extra_raw = is_array($input['variants'] ?? null) ? $input['variants'] : [];
    $extra_result = gc_build_extra_variants($extra_raw);
    if (array_key_exists('error', $extra_result)) {
        return $extra_result;
    }

    $extra_built = $extra_result['variants'] ?? [];

    return ['variants' => array_merge($all, $extra_built)];
}

/**
 * Validate and build extra variants (responsive breakpoints, hover states)
 * from the caller's `variants` array. Each entry must have `meta` (with
 * `breakpoint` and/or `state`) and `styles` (validated against Style Schema).
 *
 * @param list<array<string, mixed>> $raw_variants
 * @return array{variants: list<array<string, mixed>>}|array{error: string, unknown_properties?: list<string>, invalid_values?: array<string, mixed>}
 */
function gc_build_extra_variants(array $raw_variants): array
{
    if ($raw_variants === []) {
        return ['variants' => []];
    }

    $built = [];
    foreach ($raw_variants as $i => $variant) {
        $parsed = gc_parse_single_variant($variant, $i);
        if (array_key_exists('error', $parsed)) {
            return $parsed;
        }
        $v = $parsed['variant'] ?? [];
        $built[] = $v;
    }

    return ['variants' => $built];
}

/**
 * Parse and validate a single variant entry from the caller's `variants` array.
 *
 * @param array<string, mixed> $variant
 * @return array{variant: array<string, mixed>}|array{error: string, unknown_properties?: list<string>, invalid_values?: array<string, mixed>}
 */
function gc_parse_single_variant(array $variant, int $index): array
{
    /** @var array<string, mixed>|null $meta */
    $meta = is_array($variant['meta'] ?? null) ? $variant['meta'] : null;
    if ($meta === null) {
        return ['error' => sprintf('variants[%d].meta is required (object with breakpoint and/or state).', $index)];
    }

    /** @var array<string, mixed> $styles */
    $styles = is_array($variant['styles'] ?? null) ? $variant['styles'] : [];
    if ($styles === []) {
        return ['error' => sprintf('variants[%d].styles must be a non-empty object.', $index)];
    }

    $validation = el_validate_style_props($styles);
    if (!array_key_exists('props', $validation)) {
        $err = $validation['error'] ?? 'Invalid style payload.';
        $validation['error'] = sprintf('variants[%d]: %s', $index, $err);
        /** @var array{error: string, unknown_properties?: list<string>, invalid_values?: array<string, mixed>} $validation */
        return $validation;
    }

    // A null/absent breakpoint means the base variant. Elementor rejects a null
    // breakpoint, so normalize it to the canonical base id "desktop".
    $breakpoint = is_string($meta['breakpoint'] ?? null) ? $meta['breakpoint'] : GC_BASE_BREAKPOINT;
    $state = is_string($meta['state'] ?? null) ? $meta['state'] : null;

    return [
        'variant' => [
            'meta' => ['breakpoint' => $breakpoint, 'state' => $state],
            'props' => $validation['props'],
        ],
    ];
}
