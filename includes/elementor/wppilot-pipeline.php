<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Shared post-processing pipeline for atomic Elementor trees.
 *
 * Every atomic write surface should run these steps, regardless of
 * whether it's a fresh write (set-content), a subtree insert
 * (add-element), or an in-place mutation (edit-element). The order is
 * load-bearing — IDs come first (downstream steps use them as seeds for
 * style ids), widget-type normalization runs before the schema-aware
 * coerce, sanitize runs before sync so the rename propagates into
 * `settings.classes` correctly.
 *
 * 1. `el_ensure_tree_ids`           — generate ids for nodes that lack one
 * 2. `el_normalize_tree_widget_types` — rewrite `elType: "e-heading"` into
 *                                       `{elType: "widget", widgetType: "e-heading"}`
 * 3. `el_coerce_tree_settings`      — wrap scalars into `{$$type, value}` using prop schema
 * 4. `el_normalize_style_breakpoints` — `breakpoint: null` → `"desktop"`
 * 5. `el_sanitize_style_class_ids`  — rename per-element style ids that would be
 *                                     invalid CSS class selectors (digit-leading)
 * 6. `el_sync_style_classes`        — mirror style ids into `settings.classes`
 *
 * The v3-specific steps (boxed splitter, v3→v4 settings translator) are
 * NOT part of this pipeline. They live in `wppilot-add-element.php` and run
 * BEFORE this finalize is called, because they only make sense when
 * converting a v3 source — running them on a page an agent wrote as v4
 * atomic from scratch would corrupt legitimate values.
 *
 * The caller passes `&$dynamic_tag_errors` because
 * `el_coerce_tree_settings` accumulates tag parsing errors into it; the
 * caller then decides whether to abort the write and surface them.
 *
 * @param array<string, mixed> $node
 * @param list<array{element_id: string, widget_type: string, setting_name: string, tag: mixed, reason: string}> $dynamic_tag_errors
 * @return array<string, mixed>
 */
function el_finalize_atomic_node(array $node, array &$dynamic_tag_errors): array
{
    $node = el_ensure_tree_ids($node);
    $node = el_normalize_tree_widget_types($node);
    $node = el_coerce_tree_settings($node, $dynamic_tag_errors);
    $node = el_normalize_style_breakpoints($node);
    $node = el_sanitize_style_class_ids($node);
    return el_sync_style_classes($node);
}

/**
 * Run the universal atomic finalize pipeline over a list of top-level
 * nodes (the shape `set-content` writes with).
 *
 * @param list<array<string, mixed>> $nodes
 * @param list<array{element_id: string, widget_type: string, setting_name: string, tag: mixed, reason: string}> $dynamic_tag_errors
 * @return list<array<string, mixed>>
 */
function el_finalize_atomic_forest(array $nodes, array &$dynamic_tag_errors): array
{
    $out = [];
    foreach ($nodes as $node) {
        $out[] = el_finalize_atomic_node($node, $dynamic_tag_errors);
    }
    return $out;
}
