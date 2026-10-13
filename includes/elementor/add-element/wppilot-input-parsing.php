<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor add-element: parsing and validating ability input.
 *
 * Resolves the parent, the position, and the element id before any tree
 * mutation begins, so a rejected request never partially writes.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Validate and normalize the input for add-element.
 *
 * @param array<string, mixed> $input
 * @return array{error: string}|array{post_id: int, element_type: string, widget_type: string, settings: array<string, mixed>, styles: array<string, mixed>, parent_id: string|null, position: int, element_id: string}
 */
function el_parse_add_element_input(array $input): array
{
    $preflight = el_parse_add_element_preflight($input);
    if (array_key_exists('error', $preflight)) {
        return $preflight;
    }

    /** @var array{post_id: int, element_type: string} $preflight */
    /** @var array<string, mixed> $settings */
    $settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
    /** @var array<string, mixed> $styles */
    $styles = is_array($input['styles'] ?? null) ? $input['styles'] : [];

    return [
        'post_id' => $preflight['post_id'],
        'element_type' => $preflight['element_type'],
        'widget_type' => (string) ($input['widget_type'] ?? ''),
        'settings' => $settings,
        'styles' => $styles,
        'parent_id' => el_parse_parent_id($input['parent_id'] ?? null),
        'position' => el_parse_position($input['position'] ?? null),
        'element_id' => is_string($input['element_id'] ?? null) ? $input['element_id'] : '',
    ];
}

/**
 * Validate and normalize the input for tree-mode add-element.
 *
 * @param array<string, mixed> $input
 * @return array{error: string}|array{post_id: int, tree: array<string, mixed>, parent_id: string|null, position: int}
 */
function el_parse_tree_insert_input(array $input): array
{
    if (!class_exists('Elementor\\Plugin')) {
        return ['error' => 'Elementor is not active.'];
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id)) {
        return ['error' => "Post {$post_id} not found."];
    }

    if (!is_array($input['tree'] ?? null) || $input['tree'] === []) {
        return ['error' => 'tree is required in tree mode.'];
    }

    /** @var array<string, mixed> $tree */
    $tree = $input['tree'];
    if ((string) ($tree['elType'] ?? '') === '') {
        return ['error' => 'tree.elType is required.'];
    }

    return [
        'post_id' => $post_id,
        'tree' => $tree,
        'parent_id' => el_parse_parent_id($input['parent_id'] ?? null),
        'position' => el_parse_position($input['position'] ?? null),
    ];
}

/**
 * Preflight checks for add-element input. Enforces that Elementor is loaded,
 * the post exists, and element_type is one of the allowed values.
 *
 * @param array<string, mixed> $input
 * @return array{error: string}|array{post_id: int, element_type: string}
 */
function el_parse_add_element_preflight(array $input): array
{
    if (!class_exists('Elementor\\Plugin')) {
        return ['error' => 'Elementor is not active.'];
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id)) {
        return ['error' => "Post {$post_id} not found."];
    }

    $element_type = (string) ($input['element_type'] ?? '');
    if ($element_type === '') {
        return [
            'error' => 'Parameter "element_type" is required (unless using "tree" mode). Use "widget", "container", "e-flexbox", or "e-div-block".',
        ];
    }
    $valid_types = ['widget', 'container', ...WPPILOT_ATOMIC_CONTAINER_TYPES];
    if (!in_array($element_type, $valid_types, strict: true)) {
        return [
            'error' => sprintf(
                'Unknown element_type "%s". Valid values: "widget", "container", "e-flexbox", "e-div-block".',
                $element_type,
            ),
        ];
    }

    return ['post_id' => $post_id, 'element_type' => $element_type];
}

function el_parse_parent_id(mixed $raw): ?string
{
    return is_string($raw) && $raw !== '' ? $raw : null;
}

function el_parse_position(mixed $raw): int
{
    return $raw === null ? -1 : (int) $raw;
}

/**
 * Validate a user-supplied element_id slug. Pure check, no tree access.
 *
 * The id flows directly into rendered HTML (`data-id`, `elementor-element-<id>`)
 * and, for v4 atomic elements with synthesized local styles, into the rendered
 * CSS class name (`s-<id>`). The slug rules are the intersection of
 * "safe as a CSS class name" and "safe as an HTML attribute value".
 *
 * @return array{ok: true, slug: string}|array{ok: false, error: string}
 */
function el_validate_element_id_slug(string $raw): array
{
    if ($raw === '') {
        return ['ok' => false, 'error' => 'element_id cannot be empty.'];
    }
    $len = strlen($raw);
    if ($len < 2 || $len > 50) {
        return [
            'ok' => false,
            'error' => sprintf('element_id "%s" must be 2-50 characters long (got %d).', $raw, $len),
        ];
    }
    if (preg_match('/^[a-z][a-z0-9_-]*$/', $raw) !== 1) {
        return [
            'ok' => false,
            'error' => sprintf(
                'element_id "%s" must start with a lowercase letter and contain only lowercase letters, digits, hyphens, or underscores.',
                $raw,
            ),
        ];
    }
    if ($raw === 'container') {
        return [
            'ok' => false,
            'error' => 'element_id "container" is reserved by Elementor — pick a different slug.',
        ];
    }

    return ['ok' => true, 'slug' => $raw];
}

/**
 * Recursively normalize atomic widget elTypes in the tree.
 *
 * Agents pass `elType: "e-heading"` which is the ergonomic form, but
 * Elementor internally stores atomic widgets as `elType: "widget"` +
 * `widgetType: "e-heading"` (same as legacy widgets). Atomic containers
 * like e-flexbox/e-div-block are true element types and stay as-is.
 *
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function el_normalize_tree_widget_types(array $node): array
{
    $el_type = (string) ($node['elType'] ?? '');

    // Atomic widget types (e-heading, e-paragraph, …) are registered with
    // the widgets manager, not the elements manager. They need the standard
    // widget envelope: elType "widget" + widgetType "<name>".
    // Check at runtime against elements_manager so new Elementor versions
    // with additional element types (e-tabs, e-accordion, …) are handled
    // automatically without hardcoded lists.
    if (str_starts_with($el_type, 'e-') && !el_is_element_type($el_type)) {
        $node['widgetType'] = $el_type;
        $node['elType'] = 'widget';
    }

    // Move node-level __dynamic__ into settings. Agents may pass it at
    // either level, but Elementor and the coercion pipeline both expect
    // it inside settings.
    if (is_array($node['__dynamic__'] ?? null) && $node['__dynamic__'] !== []) {
        /** @var array<string, mixed> $settings */
        $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
        $settings['__dynamic__'] = $node['__dynamic__'];
        $node['settings'] = $settings;
        unset($node['__dynamic__']);
    }

    /** @var list<array<string, mixed>> $children */
    $children = is_array($node['elements'] ?? null) ? $node['elements'] : [];
    $new_children = [];
    foreach ($children as $child) {
        $new_children[] = el_normalize_tree_widget_types($child);
    }
    $node['elements'] = $new_children;

    return $node;
}

/**
 * Check whether an elType is a registered Elementor element type (as
 * opposed to a widget type). Element types live under elements_manager
 * and include containers (e-flexbox, e-div-block) and structural elements
 * (e-tabs, e-tab, etc.). Widget types (e-heading, e-paragraph, …) are
 * NOT element types.
 */
function el_is_element_type(string $el_type): bool
{
    /** @var list<string>|null $cache */
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        if (class_exists('Elementor\\Plugin')) {
            /** @var object $plugin */
            $plugin = \Elementor\Plugin::$instance;
            /** @var object|null $em */
            $em = $plugin->elements_manager ?? null;
            if (is_object($em) && method_exists($em, 'get_element_types')) {
                /** @var array<string, object> $types */
                $types = $em->get_element_types();
                $cache = array_keys($types);
            }
        }
    }
    return in_array($el_type, $cache, strict: true);
}

/**
 * Recursively auto-wrap settings of atomic elements in the tree into the
 * `{$$type, value}` format expected by Elementor v4. Uses the element's
 * prop schema to determine the correct `$$type` for each setting.
 *
 * This makes tree mode deterministic: the caller can pass plain strings
 * (e.g. `"title": "Hello"`) and the server wraps them into
 * `{"$$type": "html-v3", "value": {"content": {"$$type": "string", "value": "Hello"}, "children": []}}`
 * exactly like the single-element validation path does.
 *
 * @param array<string, mixed> $node
 * @param list<array{element_id: string, widget_type: string, setting_name: string, tag: mixed, reason: string}> $dynamic_tag_errors
 * @return array<string, mixed>
 */
function el_coerce_tree_settings(array $node, array &$dynamic_tag_errors): array
{
    $schema_key = el_element_widget_type($node);

    // Only coerce for elements that have a resolvable schema.
    if ($schema_key !== '' && $schema_key !== WPPILOT_COMPACT_SCHEMA_CONTAINER_KEY) {
        $schema = el_extract_schema($schema_key);
        if ($schema !== null && ($schema['is_atomic'] ?? false) === true) {
            $node = el_coerce_atomic_node_settings($node, $schema, $dynamic_tag_errors);
        }
    }

    /** @var list<array<string, mixed>> $children */
    $children = is_array($node['elements'] ?? null) ? $node['elements'] : [];
    $new_children = [];
    foreach ($children as $child) {
        $new_children[] = el_coerce_tree_settings($child, $dynamic_tag_errors);
    }
    $node['elements'] = $new_children;

    return $node;
}

/**
 * Auto-wrap the settings of a single atomic element using its schema.
 * Delegates to `el_coerce_atomic_value()` for each setting key.
 *
 * @param array<string, mixed> $node
 * @param array<string, mixed> $schema
 * @param list<array{element_id: string, widget_type: string, setting_name: string, tag: mixed, reason: string}> $dynamic_tag_errors
 * @return array<string, mixed>
 */
function el_coerce_atomic_node_settings(array $node, array $schema, array &$dynamic_tag_errors): array
{
    /** @var array<string, mixed> $settings */
    $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
    $controls = el_schema_controls($schema);
    if ($controls === null || $settings === []) {
        return $node;
    }

    // Convert v3 __dynamic__ tags to v4 inline {$$type: "dynamic"} values
    // and coerce all other settings to the {$$type, value} wrapped format.
    $widget_type = (string) ($schema['widgetType'] ?? '');
    $element_id = (string) ($node['id'] ?? '');
    $dynamic_result = el_build_dynamic_overrides($settings, $element_id, $widget_type);
    if ($dynamic_result['errors'] !== []) {
        array_push($dynamic_tag_errors, ...$dynamic_result['errors']);
    }
    $node['settings'] = el_coerce_settings_with_overrides($settings, $controls, $dynamic_result['overrides']);
    return $node;
}
