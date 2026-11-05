<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor: navigating and mutating the element tree.
 *
 * The tree is arbitrarily nested, so find/mutate/remove all recurse. Every
 * element carries an id that must stay unique within the document.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Recursively normalize an element tree, ensuring every node has an `id`,
 * `elType`, `settings`, and `elements` array. Tracks any IDs assigned along
 * the way so callers can return them.
 *
 * @param list<array<string, mixed>> $elements
 * @param list<string>               $assigned
 * @return list<array<string, mixed>>
 */
function el_normalize_tree(array $elements, array &$assigned): array
{
    $out = [];
    foreach ($elements as $element) {
        $out[] = el_normalize_element($element, $assigned);
    }
    return $out;
}

/**
 * @param array<string, mixed> $element
 * @param list<string>         $assigned
 * @return array<string, mixed>
 */
function el_normalize_element(array $element, array &$assigned): array
{
    /** @var mixed $existing_id */
    $existing_id = $element['id'] ?? null;
    if (!is_string($existing_id) || $existing_id === '') {
        $element['id'] = el_generate_id();
        $assigned[] = $element['id'];
    }

    // Accept add-element-style aliases (element_type/widget_type) alongside
    // native Elementor keys (elType/widgetType) so set-content and add-element
    // share the same input vocabulary.
    $element['elType'] ??= $element['element_type'] ?? 'widget';
    /** @var mixed $wt */
    $wt = $element['widgetType'] ?? $element['widget_type'] ?? null;
    if ($wt !== null) {
        $element['widgetType'] = $wt;
    }
    $element['settings'] = is_array($element['settings'] ?? null) ? $element['settings'] : [];

    /** @var list<array<string, mixed>> $children */
    $children = is_array($element['elements'] ?? null) ? array_values($element['elements']) : [];
    $element['elements'] = el_normalize_tree($children, $assigned);

    return $element;
}

/**
 * Find an element by ID anywhere in the tree.
 *
 * @param list<array<string, mixed>> $elements
 * @return array<string, mixed>|null
 */
function el_find(array $elements, string $element_id): ?array
{
    foreach ($elements as $element) {
        if (($element['id'] ?? '') === $element_id) {
            return $element;
        }
        if (is_array($element['elements'] ?? null)) {
            /** @var list<array<string, mixed>> $children */
            $children = $element['elements'];
            $found = el_find($children, $element_id);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

/**
 * Walk the tree and apply a mutation callback to the element with the given ID.
 * The callback receives the matching element by reference and may modify it
 * (return null) or replace its children. Returns whether the element was found.
 *
 * @param list<array<string, mixed>>                          $elements
 * @param callable(array<string, mixed>): array<string, mixed> $mutate
 */
function el_mutate(array &$elements, string $element_id, callable $mutate): bool
{
    foreach ($elements as &$element) {
        if (($element['id'] ?? '') === $element_id) {
            $element = $mutate($element);
            return true;
        }
        if (is_array($element['elements'] ?? null)) {
            /** @var list<array<string, mixed>> $children */
            $children = $element['elements'];
            if (el_mutate($children, $element_id, $mutate)) {
                $element['elements'] = $children;
                return true;
            }
        }
    }
    return false;
}

/**
 * Remove an element from the tree by ID.
 *
 * @param list<array<string, mixed>> $elements
 */
function el_remove(array &$elements, string $element_id): bool
{
    foreach ($elements as $i => &$element) {
        if (($element['id'] ?? '') === $element_id) {
            array_splice($elements, $i, length: 1);
            return true;
        }
        if (is_array($element['elements'] ?? null)) {
            /** @var list<array<string, mixed>> $children */
            $children = $element['elements'];
            if (el_remove($children, $element_id)) {
                $element['elements'] = $children;
                return true;
            }
        }
    }
    return false;
}

/**
 * Resolve the validation-schema key for an element: the widget type for
 * `elType: widget`, the container pseudo-key for `elType: container`, and
 * the element type itself for atomic elements and classic sections and
 * columns (which have schemas of their own). An empty string means the
 * widget type is missing and tells the caller to skip validation.
 *
 * @param array<string, mixed> $element
 */
function el_element_widget_type(array $element): string
{
    $el_type = (string) ($element['elType'] ?? 'widget');

    if ($el_type === 'container') {
        return WPPILOT_COMPACT_SCHEMA_CONTAINER_KEY;
    }

    // Atomic container types (e-flexbox, e-div-block) are their own elType
    // and carry their schema key directly — no widgetType field needed.
    if (in_array($el_type, WPPILOT_ATOMIC_CONTAINER_TYPES, strict: true)) {
        return $el_type;
    }

    if ($el_type === 'widget') {
        return (string) ($element['widgetType'] ?? '');
    }

    // Atomic widgets (e-heading, e-paragraph, e-button, e-divider, …) use
    // their elType as the schema key — no widgetType field.
    return $el_type;
}

/**
 * Check whether an element tree contains any v4 atomic elements
 * (atomic containers like e-flexbox/e-div-block, or atomic widgets
 * like e-heading/e-paragraph/e-button, whether stored as their own elType
 * or as `elType: widget` + `widgetType: e-*`). Used to decide whether to
 * bypass Document::save() which strips atomic widgets.
 *
 * @param list<array<string, mixed>> $elements
 */
function el_tree_has_atomic_elements(array $elements): bool
{
    foreach ($elements as $element) {
        $el_type = (string) ($element['elType'] ?? '');
        if (str_starts_with($el_type, 'e-')) {
            return true;
        }
        // add-element, build-page and the finalize pipeline store atomic
        // widgets as `elType: widget` + `widgetType: e-heading`, so the
        // elType alone misses them and Document::save() would strip them.
        if ($el_type === 'widget' && str_starts_with((string) ($element['widgetType'] ?? ''), 'e-')) {
            return true;
        }
        /** @var list<array<string, mixed>> $children */
        $children = is_array($element['elements'] ?? null) ? $element['elements'] : [];
        if ($children !== [] && el_tree_has_atomic_elements($children)) {
            return true;
        }
    }
    return false;
}

/**
 * Element ids that occur more than once across the given trees.
 *
 * An id is a duplicate when `$elements` uses it twice, or once while the page
 * content in `$existing` already has it. Repeats inside `$existing` alone are
 * not reported. Nodes without a string id are ignored; the normalizer gives
 * them one.
 *
 * @param list<array<string, mixed>> $elements
 * @param list<array<string, mixed>> $existing
 * @return list<string>
 */
function el_find_duplicate_ids(array $elements, array $existing = []): array
{
    $counts = [];
    el_count_tree_ids($elements, $counts);
    $on_page = [];
    el_count_tree_ids($existing, $on_page);

    // An id repeated only within the existing page is that page's problem,
    // not the new nodes'; refusing every append for it would leave no way to
    // add anything until the page is rewritten.
    $duplicates = [];
    foreach ($counts as $id => $count) {
        if ($count > 1 || isset($on_page[$id])) {
            $duplicates[] = (string) $id;
        }
    }
    return $duplicates;
}

/**
 * @param list<array<string, mixed>> $elements
 * @param array<string, int>         $counts
 */
function el_count_tree_ids(array $elements, array &$counts): void
{
    foreach ($elements as $element) {
        if (!is_array($element)) {
            continue;
        }
        /** @var mixed $id */
        $id = $element['id'] ?? null;
        if (is_string($id) && $id !== '') {
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }
        if (is_array($element['elements'] ?? null)) {
            /** @var list<array<string, mixed>> $children */
            $children = array_values($element['elements']);
            el_count_tree_ids($children, $counts);
        }
    }
}

/**
 * Refuse a document whose element ids are not unique.
 *
 * Two nodes sharing an id is not a visible error: styles keyed to the id apply
 * to both, edits by id land on whichever is found first, and the editor writes
 * one of them out on the next save.
 *
 * @param list<array<string, mixed>> $elements
 * @param list<array<string, mixed>> $existing Content already on the page, when appending.
 */
function el_check_unique_ids(array $elements, array $existing = []): ?\WP_Error
{
    $duplicates = el_find_duplicate_ids($elements, $existing);
    if ($duplicates === []) {
        return null;
    }

    return new \WP_Error(
        'wppilot_elementor_duplicate_ids',
        sprintf(
            'Element ids must be unique within the document, and these are used more than once%s: %s. Rename them or omit "id"/"element_id" to have one generated.',
            $existing !== [] ? ' (counting the content already on the page)' : '',
            implode(', ', $duplicates),
        ),
        ['duplicate_ids' => $duplicates],
    );
}
