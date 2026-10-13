<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor v4: style variants across breakpoints.
 *
 * A style holds one variant per breakpoint; injecting a prop means merging it
 * into the right variant rather than replacing the style.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Merge a breakpoint-keyed map of wrapped props into the first style
 * definition of a node. Creates a new style definition when the node
 * has none yet, otherwise grafts each breakpoint's props onto a new
 * or existing variant on the first style. Never overwrites existing
 * props — agent-provided style values win over translated v3 settings.
 *
 * Each key of `$props_by_bp` is either an Elementor v4 breakpoint name
 * (`tablet`, `mobile`, `widescreen`, ...) or `__null__` for the base
 * desktop variant.
 *
 * @param array<string, mixed> $styles
 * @param array<string, array<string, mixed>> $props_by_bp
 * @return array<string, mixed>
 */
function el_inject_props_into_first_style(array $styles, string $element_id, array $props_by_bp): array
{
    if ($props_by_bp === []) {
        return $styles;
    }
    if ($styles === []) {
        $style_id = $element_id . '-auto';
        $styles[$style_id] = [
            'id' => $style_id,
            'type' => 'class',
            // "local" is the canonical label Elementor's own atomic widgets use
            // for per-element styles (the editor displays it as the badge text).
            'label' => 'local',
            'variants' => el_build_variants_for_props_by_bp($props_by_bp),
        ];
        return $styles;
    }

    $first_style_id = array_key_first($styles);
    /** @var array<string, mixed> $first_style */
    $first_style = is_array($styles[$first_style_id]) ? $styles[$first_style_id] : [];
    /** @var list<array<string, mixed>> $variants */
    $variants = is_array($first_style['variants'] ?? null) ? $first_style['variants'] : [];

    foreach ($props_by_bp as $bp_key => $props) {
        $variants = el_merge_props_into_variant_for_bp($variants, $bp_key, $props);
    }

    $first_style['variants'] = $variants;
    $styles[$first_style_id] = $first_style;

    return $styles;
}

/**
 * Build a list of style variants from a breakpoint-keyed map of props.
 * `__null__` becomes a null-breakpoint (desktop default) variant, every
 * other key becomes a named-breakpoint variant. Used when the first
 * style on a node has no pre-existing variants to merge into.
 *
 * @param array<string, array<string, mixed>> $props_by_bp
 * @return list<array<string, mixed>>
 */
function el_build_variants_for_props_by_bp(array $props_by_bp): array
{
    $variants = [];
    foreach ($props_by_bp as $bp_key => $props) {
        $variants[] = [
            'meta' => ['breakpoint' => $bp_key === '__null__' ? null : $bp_key, 'state' => null],
            'props' => $props,
        ];
    }
    return $variants;
}

/**
 * Add a single breakpoint's props onto an existing variants list. If a
 * variant already exists for that breakpoint, the new props are merged
 * into it without overwriting existing entries (agent values win).
 * Otherwise a new variant is appended. `__null__` matches both null and
 * `desktop` so the base variant is treated as the same surface
 * regardless of which spelling Elementor wrote it under.
 *
 * @param list<array<string, mixed>> $variants
 * @param array<string, mixed> $props
 * @return list<array<string, mixed>>
 */
function el_merge_props_into_variant_for_bp(array $variants, string $bp_key, array $props): array
{
    $bp_normalized = $bp_key === '__null__' ? null : $bp_key;
    $found = -1;
    foreach (array_keys($variants) as $i) {
        $variant = $variants[$i];
        /** @var mixed $existing */
        $existing = $variant['meta']['breakpoint'] ?? null;
        $is_base = $existing === null || $existing === 'desktop';
        $bp_is_base = $bp_normalized === null || $bp_normalized === 'desktop';
        if ($bp_is_base ? $is_base : $existing === $bp_normalized) {
            $found = $i;
            break;
        }
    }
    if ($found === -1) {
        $variants[] = [
            'meta' => ['breakpoint' => $bp_normalized, 'state' => null],
            'props' => $props,
        ];
        return $variants;
    }
    $variant = $variants[$found];
    /** @var array<string, mixed> $existing_props */
    $existing_props = is_array($variant['props'] ?? null) ? $variant['props'] : [];
    foreach (array_keys($props) as $key) {
        if (array_key_exists($key, $existing_props)) {
            continue;
        }
        $existing_props[$key] = $props[$key];
    }
    $variant['props'] = $existing_props;
    $variants[$found] = $variant;
    return $variants;
}

/**
 * Normalize breakpoints in a bare per-element styles map (no surrounding
 * element node). Used by edit-element so the null-breakpoint base variant
 * shape — which add-element tree: already rewrites silently — is accepted
 * through the update path too instead of bouncing off Style_Parser with a
 * `missing_or_invalid_value` at `meta.breakpoint`.
 *
 * @param array<string, mixed> $styles
 * @return array<string, mixed>
 */
function el_normalize_styles_map_breakpoints(array $styles): array
{
    $out = [];
    foreach (array_keys($styles) as $sid) {
        $normalized = el_normalize_style_breakpoint_definition($styles[$sid]);
        $out[$sid] = $normalized ?? $styles[$sid];
    }
    return $out;
}

/**
 * Recursively normalize style variant breakpoints in a subtree.
 *
 * Elementor's Style_Parser rejects `breakpoint: null` even though
 * Style_Variant::build() produces it. Replace null breakpoints with
 * "desktop" which is what Atomic_Styles_Manager uses as DEFAULT_BREAKPOINT.
 *
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function el_normalize_style_breakpoints(array $node): array
{
    /** @var array<string, mixed> $styles */
    $styles = is_array($node['styles'] ?? null) ? $node['styles'] : [];
    foreach (array_keys($styles) as $sid) {
        $normalized_style = el_normalize_style_breakpoint_definition($styles[$sid]);
        if ($normalized_style === null) {
            continue;
        }
        $styles[$sid] = $normalized_style;
    }
    $node['styles'] = $styles;

    /** @var list<array<string, mixed>> $children */
    $children = is_array($node['elements'] ?? null) ? $node['elements'] : [];
    $new_children = [];
    foreach ($children as $child) {
        $new_children[] = el_normalize_style_breakpoints($child);
    }
    $node['elements'] = $new_children;

    return $node;
}

/**
 * Normalize null breakpoints inside a single style definition.
 *
 * @param mixed $style
 * @return array<string, mixed>|null
 */
function el_normalize_style_breakpoint_definition(mixed $style): ?array
{
    if (!is_array($style) || !is_array($style['variants'] ?? null)) {
        return null;
    }

    /** @var list<array<string, mixed>> $variants */
    $variants = $style['variants'];
    foreach ($variants as $vi => $variant) {
        if (!is_array($variant['meta'] ?? null)) {
            continue;
        }
        if (($variant['meta']['breakpoint'] ?? null) !== null) {
            continue;
        }
        /** @var array<string, mixed> $meta */
        $meta = $variant['meta'];
        $meta['breakpoint'] = 'desktop';
        $variants[$vi]['meta'] = $meta;
    }

    $style['variants'] = $variants;
    /** @var array<string, mixed> $style */
    return $style;
}
