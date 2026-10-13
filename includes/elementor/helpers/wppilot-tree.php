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
 * like e-heading/e-paragraph/e-button). Used to decide whether to
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
        /** @var list<array<string, mixed>> $children */
        $children = is_array($element['elements'] ?? null) ? $element['elements'] : [];
        if ($children !== [] && el_tree_has_atomic_elements($children)) {
            return true;
        }
    }
    return false;
}
