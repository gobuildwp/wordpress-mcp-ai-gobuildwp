<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor v4: splitting a boxed container into outer and inner.
 *
 * A boxed container is two nested elements in v4 — a full-width outer and a
 * width-constrained inner. Layout props have to be routed to the correct one
 * or the result renders at the wrong width.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Recursively split atomic containers that carry a v3 `boxed_width`
 * setting into the canonical two-level outer/inner wrapper pattern.
 *
 * In v3, a `container` with `boxed_width: {size: 1300, unit: "px"}`
 * renders its background full-bleed but constrains its children to the
 * boxed width. Atomic v4 containers do not have an equivalent setting:
 * the same effect requires an outer full-width flexbox (background,
 * padding, justify-content: center) wrapping an inner max-width flexbox
 * (layout, gap). Agents converting v3 pages routinely either skip the
 * pattern (children render full-width) or apply it inconsistently across
 * sibling sections. Doing the split here makes the decision deterministic.
 *
 * The agent passes a single flat e-flexbox with `settings.boxed_width`
 * alongside any other v3 settings (background_color, padding,
 * flex_direction, ...). This step rewrites that node into:
 *
 *   outer e-flexbox (id preserved)
 *     ├─ visual settings (background_color, padding, margin, min_height,
 *     │   max_height, border_radius, tag/html_tag) + `justify_content: "center"`
 *     └─ inner e-flexbox (id `<original>-in`)
 *         ├─ layout settings (flex_direction, flex_wrap, flex_align_items,
 *         │   flex_justify_content, flex_gap) + `width: 100%`
 *         │   + `max_width: <boxed_width>`
 *         └─ original children
 *
 * The downstream `el_translate_v3_container_settings` step then turns
 * those v3-shaped settings on outer + inner into the right v4 style
 * props on each level.
 *
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function el_split_boxed_containers(array $node, string $context = 'top-level'): array
{
    $just_split = false;
    $el_type = (string) ($node['elType'] ?? '');
    if (in_array($el_type, WPPILOT_ATOMIC_CONTAINER_TYPES, strict: true)) {
        [$node, $just_split] = el_split_boxed_container_on_node($node, $context);
    }

    // Children become "inside a wrapper" when either this node just
    // produced one (split happened now) or the caller already was
    // inside one. A nested container without its own `boxed_width` then
    // inherits the boxing instead of producing a redundant outer/inner
    // of its own — matching v3's content-width inheritance rule and,
    // critically, preventing the walker from re-splitting the inner we
    // just generated.
    $child_context = $just_split || $context === 'inside-wrapper' ? 'inside-wrapper' : 'top-level';

    /** @var list<array<string, mixed>> $children */
    $children = is_array($node['elements'] ?? null) ? $node['elements'] : [];
    $new_children = [];
    foreach ($children as $child) {
        $new_children[] = el_split_boxed_containers($child, $child_context);
    }
    $node['elements'] = $new_children;

    return $node;
}

/**
 * Split a single atomic container node into the outer/inner wrapper
 * pattern when the container is boxed. Returns a tuple of the (maybe
 * rewritten) node and a flag telling the caller whether a split
 * actually happened — the walker uses that flag to know whether the
 * children are now inside a boxed wrapper and should not split again.
 *
 * `$context` is `"top-level"` (the node is rendered directly, so it
 * inherits no boxing from anywhere) or `"inside-wrapper"` (the node is
 * a descendant of a container that the walker has already split and is
 * therefore already inside a max-width band — a further split would
 * double-wrap and, if the inner is itself walked, recurse forever).
 *
 * Two server-side v3 default synthesizers run at the top, gated on a
 * v3-conversion-context marker (at least one v3-only key present,
 * unwrapped, in settings). Native v4 atomic writes pass through
 * unchanged because none of the markers match.
 *
 *   A. `flex_direction` absent → synthesize `"column"`. v3 containers
 *      default to column, v4 e-flexbox to the CSS default (row).
 *
 *   B. `boxed_width` absent AND `content_width != "full"` AND context
 *      is top-level → read `container_width` from the active
 *      Elementor kit, split at that value. Mirrors v3's default-boxed
 *      behavior without forcing the agent to read the kit themselves.
 *
 * Resolution rules (deterministic, mirror v3 semantics):
 *   1. Explicit `boxed_width<suffix>` → split at those sizes.
 *   2. Explicit `content_width: "full"` → no split (full-bleed is the
 *      agent's stated intent).
 *   3. `$context === "inside-wrapper"` → no split (inherit boxing;
 *      avoids a second redundant outer/inner).
 *   4. v3 context AND top-level AND kit available → split at kit
 *      container_width (synthesizer B above).
 *   5. Native v4 context OR kit value unavailable → no split, fallback
 *      to full-bleed. A misconfigured site is better than a broken
 *      write.
 *
 * @param array<string, mixed> $node
 * @return array{0: array<string, mixed>, 1: bool}
 */
function el_split_boxed_container_on_node(array $node, string $context): array
{
    /** @var array<string, mixed> $settings */
    $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];

    $settings = el_apply_v3_container_defaults($settings);

    [$boxed_sizes_by_bp, $settings] = el_extract_boxed_widths_by_breakpoint($settings);
    $boxed_sizes_by_bp = el_maybe_infer_default_boxed_width($boxed_sizes_by_bp, $settings, $context);

    // `content_width` keys never survive to v4 — the wrapper pattern
    // replaces them when split, and a full-width container doesn't
    // need the legacy toggle either.
    foreach (array_merge([''], array_keys(el_v3_breakpoint_suffixes())) as $suffix) {
        unset($settings['content_width' . $suffix]);
    }

    if ($boxed_sizes_by_bp === []) {
        // Explicitly full-width, inherited boxing, or kit value
        // unavailable — leave the node as-is. Persist any
        // boxed_width/content_width keys the extractor drained.
        $node['settings'] = $settings;
        return [$node, false];
    }

    [$outer_settings, $inner_settings] = el_partition_boxed_settings($settings, $boxed_sizes_by_bp);

    $el_type = (string) ($node['elType'] ?? '');
    $original_id = (string) ($node['id'] ?? 'el');

    /** @var list<array<string, mixed>> $original_children */
    $original_children = is_array($node['elements'] ?? null) ? $node['elements'] : [];
    /** @var array<string, mixed> $original_styles */
    $original_styles = is_array($node['styles'] ?? null) ? $node['styles'] : [];

    // The agent often pre-built `styles` on the boxed container including
    // child-layout properties (gap between cards, flex-direction of the
    // child row, flex-wrap, align-items). On the outer those props are
    // dead weight: the outer owns exactly one child (the inner wrapper),
    // so a `gap` between siblings has nothing to act on. Move those
    // properties onto the inner instead, where the agent's actual
    // children live.
    [$original_styles, $extra_inner_props_by_breakpoint] = el_extract_layout_props_from_styles($original_styles);
    $inner_styles = el_build_inner_layout_style($original_id, $extra_inner_props_by_breakpoint);

    $inner_node = [
        'id' => $original_id . '-in',
        'elType' => $el_type,
        'settings' => $inner_settings,
        'elements' => $original_children,
        'styles' => $inner_styles,
    ];

    $outer_node = [
        'id' => $original_id,
        'elType' => $el_type,
        'settings' => $outer_settings,
        'elements' => [$inner_node],
        // Remaining styles (background, padding, margin, justify-content
        // for centering the inner, etc.) stay on the outer.
        'styles' => $original_styles,
    ];

    return [$outer_node, true];
}

/**
 * Walk every variant in every style of an outer-bound styles map and
 * pull out child-layout properties that only make sense on the inner.
 * Returns the outer styles (with those props removed) and a map of
 * breakpoint key → props that the caller will graft onto the inner.
 *
 * The breakpoint key is the meta breakpoint string, with `__null__`
 * standing in for a null/desktop breakpoint so it can be used as an
 * array key.
 *
 * @param array<string, mixed> $styles
 * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>}
 */
function el_extract_layout_props_from_styles(array $styles): array
{
    /** @var array<string, array<string, mixed>> $extracted */
    $extracted = [];

    foreach (array_keys($styles) as $sid) {
        /** @var mixed $sdef_raw */
        $sdef_raw = $styles[$sid];
        if (!is_array($sdef_raw)) {
            continue;
        }
        /** @var array<string, mixed> $sdef */
        $sdef = $sdef_raw;
        /** @var list<array<string, mixed>> $variants */
        $variants = is_array($sdef['variants'] ?? null) ? $sdef['variants'] : [];
        $variants = el_strip_layout_props_from_variants($variants, $extracted);
        $sdef['variants'] = $variants;
        $styles[$sid] = $sdef;
    }

    return [$styles, $extracted];
}

/**
 * Walk a list of style variants, peel off the layout properties that
 * belong on the inner wrapper, and accumulate them into `$extracted`
 * keyed by the variant's breakpoint key. Returns the variants with
 * those props removed.
 *
 * @param list<array<string, mixed>> $variants
 * @param array<string, array<string, mixed>> $extracted
 * @return list<array<string, mixed>>
 */
function el_strip_layout_props_from_variants(array $variants, array &$extracted): array
{
    $layout_props = ['gap', 'flex-direction', 'flex-wrap', 'align-items', 'justify-content'];
    foreach (array_keys($variants) as $vi) {
        $variant = $variants[$vi];
        /** @var array<string, mixed> $props */
        $props = is_array($variant['props'] ?? null) ? $variant['props'] : [];
        /** @var mixed $bp */
        $bp = $variant['meta']['breakpoint'] ?? null;
        $bp_key = is_string($bp) ? $bp : '__null__';
        foreach ($layout_props as $lp) {
            if (!array_key_exists($lp, $props)) {
                continue;
            }
            if (!array_key_exists($bp_key, $extracted)) {
                $extracted[$bp_key] = [];
            }
            if (!array_key_exists($lp, $extracted[$bp_key])) {
                $extracted[$bp_key][$lp] = $props[$lp];
            }
            unset($props[$lp]);
        }
        $variant['props'] = $props;
        $variants[$vi] = $variant;
    }
    return $variants;
}

/**
 * Build the inner-wrapper style definition that holds the layout props
 * extracted from the agent's pre-built outer styles. Returns an empty
 * map when there are no extracted props (the typical case when the
 * agent passed only v3 settings, not pre-built styles).
 *
 * @param array<string, array<string, mixed>> $props_by_breakpoint
 * @return array<string, mixed>
 */
function el_build_inner_layout_style(string $original_id, array $props_by_breakpoint): array
{
    if ($props_by_breakpoint === []) {
        return [];
    }
    $style_id = el_make_safe_class_id($original_id . '-in') . '-extra';
    $variants = [];
    foreach ($props_by_breakpoint as $bp_key => $props) {
        $variants[] = [
            'meta' => ['breakpoint' => $bp_key === '__null__' ? null : $bp_key, 'state' => null],
            'props' => $props,
        ];
    }
    return [
        $style_id => [
            'id' => $style_id,
            'type' => 'class',
            'label' => '',
            'variants' => $variants,
        ],
    ];
}

/**
 * Partition a flat v3 container's settings into outer-level (visual)
 * and inner-level (layout) buckets, given the resolved boxed widths
 * per Elementor breakpoint.
 *
 * Outer keeps background/padding/margin/min-height/max-height/border-
 * radius/tag plus a forced `flex_justify_content: "center"` so the inner
 * sits in the middle of the full-bleed band. Inner gets `width: 100%`,
 * one `max_width<suffix>` per breakpoint that had a boxed_width, and
 * any flex-* layout keys (also per breakpoint). The caller passes the
 * input settings already stripped of every `boxed_width<suffix>` /
 * `content_width<suffix>`.
 *
 * Suffixed responsive keys (e.g. `padding_tablet`, `flex_gap_mobile`)
 * are routed to the same outer/inner bucket as their base key.
 *
 * @param array<string, mixed> $settings
 * @param array<string, array{unit: string, size: int|float}> $boxed_sizes_by_bp
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function el_partition_boxed_settings(array $settings, array $boxed_sizes_by_bp): array
{
    $outer_settings = ['flex_justify_content' => 'center'];

    /** @var mixed $tag_value */
    [$settings, $tag_value] = el_pop_v3_tag_setting($settings);
    if ($tag_value !== null) {
        $outer_settings['tag'] = $tag_value;
    }

    $outer_bases = ['background_color', 'padding', 'margin', 'min_height', 'max_height', 'border_radius'];
    [$settings, $outer_visual] = el_pop_v3_settings_with_bases($settings, $outer_bases);
    foreach (array_keys($outer_visual) as $k) {
        $outer_settings[$k] = $outer_visual[$k];
    }

    $inner_settings = el_build_inner_boxed_settings($boxed_sizes_by_bp);

    $inner_bases = ['flex_direction', 'flex_wrap', 'flex_align_items', 'flex_justify_content', 'flex_gap'];
    [$settings, $inner_layout] = el_pop_v3_settings_with_bases($settings, $inner_bases);
    foreach (array_keys($inner_layout) as $k) {
        $inner_settings[$k] = $inner_layout[$k];
    }

    // The agent's explicit `width<suffix>` is overridden by the inner's
    // 100% — drop every breakpoint variant so the v3 translator does
    // not also map them onto the outer.
    foreach (array_merge([''], array_keys(el_v3_breakpoint_suffixes())) as $suffix) {
        unset($settings['width' . $suffix]);
    }

    // Anything left over (unknown keys we didn't classify) lands on the
    // outer so the downstream translator/coercer decides what to do.
    foreach (array_keys($settings) as $key) {
        if (array_key_exists($key, $outer_settings)) {
            continue;
        }
        $outer_settings[$key] = $settings[$key];
    }

    return [$outer_settings, $inner_settings];
}

/**
 * Build the inner wrapper's seed settings: width 100% plus a
 * `max_width<suffix>` per breakpoint that had a boxed_width value.
 *
 * @param array<string, array{unit: string, size: int|float}> $boxed_sizes_by_bp
 * @return array<string, mixed>
 */
function el_build_inner_boxed_settings(array $boxed_sizes_by_bp): array
{
    $inner_settings = ['width' => ['unit' => '%', 'size' => 100]];
    foreach ($boxed_sizes_by_bp as $bp_key => $size) {
        $bp = $bp_key === '__null__' ? null : $bp_key;
        $inner_settings[el_v3_key_with_breakpoint('max_width', $bp)] = $size;
    }
    return $inner_settings;
}

/**
 * Find every `boxed_width<suffix>` setting on a container, resolve its
 * value to a normalized `{unit, size}` shape, and return the resulting
 * map of breakpoint key → size alongside the settings array stripped of
 * all `boxed_width*` keys (parsed or not).
 *
 * @param array<string, mixed> $settings
 * @return array{0: array<string, array{unit: string, size: int|float}>, 1: array<string, mixed>}
 */
function el_extract_boxed_widths_by_breakpoint(array $settings): array
{
    /** @var array<string, array{unit: string, size: int|float}> $sizes_by_bp */
    $sizes_by_bp = [];
    foreach (array_keys($settings) as $key) {
        $resolved = el_v3_resolve_breakpoint_key($key);
        if ($resolved['base'] !== 'boxed_width') {
            continue;
        }
        $size = el_extract_v3_size_value($settings[$key]);
        // Drop the v3 key whether or not it parsed — boxed_width has no
        // counterpart on v4 atomic containers.
        unset($settings[$key]);
        if ($size === null) {
            continue;
        }
        $bp_key = $resolved['breakpoint'] ?? '__null__';
        $sizes_by_bp[$bp_key] = $size;
    }
    return [$sizes_by_bp, $settings];
}

/**
 * Read the active Elementor kit's `container_width` setting and return
 * it normalized into a `{unit, size}` shape, or null when the value is
 * unavailable (older Elementor without kits, kit not configured,
 * etc.). Used by the boxed splitter to fill in a max-width when a v3
 * container relies on the kit default (neither `boxed_width` nor
 * `content_width: "full"` is set).
 *
 * @return array{unit: string, size: int|float}|null
 */
function el_get_kit_container_width(): ?array
{
    if (!class_exists('Elementor\\Plugin')) {
        return null;
    }
    /** @var object $plugin */
    $plugin = \Elementor\Plugin::$instance;
    /** @var object|null $kits_manager */
    $kits_manager = $plugin->kits_manager ?? null;
    if (!is_object($kits_manager) || !method_exists($kits_manager, 'get_current_settings')) {
        return null;
    }
    /** @var mixed $val */
    $val = $kits_manager->get_current_settings('container_width');
    return el_extract_v3_size_value($val);
}

/**
 * When no explicit `boxed_width` was drained AND the container is a
 * top-level v3 conversion without `content_width: "full"`, read the
 * kit's `container_width` and use it as the boxed size so the v4
 * output mirrors v3's default-boxed behavior.
 *
 * @param array<string, array{unit: string, size: int|float}> $boxed_sizes_by_bp
 * @param array<string, mixed> $settings
 * @return array<string, array{unit: string, size: int|float}>
 */
function el_maybe_infer_default_boxed_width(array $boxed_sizes_by_bp, array $settings, string $context): array
{
    if ($boxed_sizes_by_bp !== []) {
        return $boxed_sizes_by_bp;
    }
    if ($context === 'inside-wrapper') {
        return $boxed_sizes_by_bp;
    }
    if (($settings['content_width'] ?? null) === 'full') {
        return $boxed_sizes_by_bp;
    }
    if (!el_has_v3_container_markers($settings)) {
        return $boxed_sizes_by_bp;
    }
    $kit_width = el_get_kit_container_width();
    if ($kit_width === null) {
        return $boxed_sizes_by_bp;
    }
    return ['__null__' => $kit_width];
}
