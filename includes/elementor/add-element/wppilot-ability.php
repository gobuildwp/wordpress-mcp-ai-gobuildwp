<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor: the add-element ability.
 *
 * Entry points for adding a widget, a container, or a whole subtree, plus the
 * insert-and-write path they share.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function elementor_add_element(array $input): array
{
    // Tree mode: insert a pre-built subtree as-is after normalizing and validating it.
    if (is_array($input['tree'] ?? null) && $input['tree'] !== []) {
        return el_add_tree_element($input);
    }

    $parsed = el_parse_add_element_input($input);
    if (array_key_exists('error', $parsed)) {
        return ['success' => false, 'error' => $parsed['error']];
    }

    /** @var array{post_id: int, element_type: string, widget_type: string, settings: array<string, mixed>, styles: array<string, mixed>, parent_id: string|null, position: int, element_id: string} $parsed */
    if ($parsed['element_type'] === 'widget') {
        return el_add_widget_element($parsed);
    }

    if (in_array($parsed['element_type'], WPPILOT_ATOMIC_CONTAINER_TYPES, strict: true)) {
        return el_add_atomic_container_element($parsed);
    }

    return el_add_container_element($parsed);
}

/**
 * Build a new widget node, validate its settings, and insert it into the tree.
 *
 * @param array{post_id: int, element_type: string, widget_type: string, settings: array<string, mixed>, styles: array<string, mixed>, parent_id: string|null, position: int, element_id: string} $parsed
 * @return array<string, mixed>
 */
function el_add_widget_element(array $parsed): array
{
    $widget_type = $parsed['widget_type'];
    if ($widget_type === '') {
        return [
            'success' => false,
            'error' => 'Parameter "widget_type" is required when element_type="widget".',
        ];
    }

    if ($widget_type === WPPILOT_COMPACT_SCHEMA_CONTAINER_KEY) {
        return [
            'success' => false,
            'error' => '"__container__" is a schema-lookup pseudo-name, not a real widget. To insert a container call this ability with element_type="container" instead.',
        ];
    }

    $schema = el_extract_schema($widget_type);
    if ($schema === null) {
        return [
            'success' => false,
            'error' => sprintf(
                'Unknown widget_type "%s". It is not registered with Elementor on this site. Use wppilot/elementor-get-schema action:"list" to enumerate every available widget.',
                $widget_type,
            ),
            'widget_type' => $widget_type,
        ];
    }

    $validation = el_validate_settings($parsed['settings'], $schema);
    if (!$validation['ok']) {
        return build_single_element_error_response('widget', $widget_type, $validation);
    }

    $settings = el_fill_atomic_schema_defaults($validation['settings'], $schema);

    $id_resolution = el_resolve_new_element_id($parsed['post_id'], $parsed['element_id']);
    if (!$id_resolution['ok']) {
        return ['success' => false, 'error' => $id_resolution['error']];
    }
    $new_id = $id_resolution['id'];
    $node = [
        'id' => $new_id,
        'elType' => 'widget',
        'widgetType' => $widget_type,
        'settings' => $settings,
        'elements' => [],
    ];

    $with_styles = el_attach_add_element_styles($node, $parsed['styles']);
    if (array_key_exists('error_response', $with_styles)) {
        return $with_styles['error_response'];
    }
    $node = $with_styles['node'] ?? $node;

    return el_insert_and_write($parsed, $node, [
        'element_id' => $new_id,
        'element_type' => 'widget',
        'widget_type' => $widget_type,
    ]);
}

/**
 * Build a new container node, validate its settings, and insert it into the tree.
 *
 * @param array{post_id: int, element_type: string, widget_type: string, settings: array<string, mixed>, styles: array<string, mixed>, parent_id: string|null, position: int, element_id: string} $parsed
 * @return array<string, mixed>
 */
function el_add_container_element(array $parsed): array
{
    $schema = el_extract_schema(WPPILOT_COMPACT_SCHEMA_CONTAINER_KEY);
    if ($schema === null) {
        return [
            'success' => false,
            'error' => 'Container element is not available on this Elementor installation.',
        ];
    }

    $validation = el_validate_settings($parsed['settings'], $schema);
    if (!$validation['ok']) {
        return build_single_element_error_response('container', WPPILOT_COMPACT_SCHEMA_CONTAINER_KEY, $validation);
    }

    $settings = el_fill_atomic_schema_defaults($validation['settings'], $schema);

    $id_resolution = el_resolve_new_element_id($parsed['post_id'], $parsed['element_id']);
    if (!$id_resolution['ok']) {
        return ['success' => false, 'error' => $id_resolution['error']];
    }
    $new_id = $id_resolution['id'];
    $node = [
        'id' => $new_id,
        'elType' => 'container',
        'settings' => $settings,
        'elements' => [],
    ];

    $with_styles = el_attach_add_element_styles($node, $parsed['styles']);
    if (array_key_exists('error_response', $with_styles)) {
        return $with_styles['error_response'];
    }
    $node = $with_styles['node'] ?? $node;

    return el_insert_and_write($parsed, $node, [
        'element_id' => $new_id,
        'element_type' => 'container',
    ]);
}

/**
 * Build a new atomic container node (e-flexbox / e-div-block), validate its
 * settings, and insert it into the tree.
 *
 * @param array{post_id: int, element_type: string, widget_type: string, settings: array<string, mixed>, styles: array<string, mixed>, parent_id: string|null, position: int, element_id: string} $parsed
 * @return array<string, mixed>
 */
function el_add_atomic_container_element(array $parsed): array
{
    $element_type = $parsed['element_type'];
    $schema = el_extract_schema($element_type);
    if ($schema === null) {
        return [
            'success' => false,
            'error' => sprintf(
                'Atomic container type "%s" is not available on this Elementor installation. Requires Elementor v4.',
                $element_type,
            ),
        ];
    }

    $validation = el_validate_settings($parsed['settings'], $schema);
    if (!$validation['ok']) {
        return build_single_element_error_response($element_type, $element_type, $validation);
    }

    $settings = el_fill_atomic_schema_defaults($validation['settings'], $schema);

    $id_resolution = el_resolve_new_element_id($parsed['post_id'], $parsed['element_id']);
    if (!$id_resolution['ok']) {
        return ['success' => false, 'error' => $id_resolution['error']];
    }
    $new_id = $id_resolution['id'];
    $node = [
        'id' => $new_id,
        'elType' => $element_type,
        'settings' => $settings,
        'elements' => [],
    ];

    $with_styles = el_attach_add_element_styles($node, $parsed['styles']);
    if (array_key_exists('error_response', $with_styles)) {
        return $with_styles['error_response'];
    }
    $node = $with_styles['node'] ?? $node;

    return el_insert_and_write($parsed, $node, [
        'element_id' => $new_id,
        'element_type' => $element_type,
    ]);
}

/**
 * Insert a pre-built subtree (with nested children) into the page. The server
 * normalizes the subtree and validates its element settings before writing, so
 * malformed dynamic tags, unknown widget types, and invalid control values
 * fail hard instead of being silently persisted.
 *
 * The tree must have at minimum an `elType` field. Missing `id` fields are
 * auto-generated. The `elements` array may contain arbitrarily nested children.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function el_add_tree_element(array $input): array
{
    $parsed = el_parse_tree_insert_input($input);
    if (array_key_exists('error', $parsed)) {
        return ['success' => false, 'error' => $parsed['error']];
    }

    /** @var array{post_id: int, tree: array<string, mixed>, parent_id: string|null, position: int} $parsed */
    $prepared = el_prepare_tree_for_insert($parsed['tree']);
    if (array_key_exists('response', $prepared)) {
        return $prepared['response'];
    }

    /** @var array{tree: array<string, mixed>} $prepared */
    return el_insert_tree_and_write($parsed, $prepared['tree']);
}

/**
 * Normalize and validate a tree-mode subtree before insertion.
 *
 * @param array<string, mixed> $tree
 * @return array{tree: array<string, mixed>}|array{response: array<string, mixed>}
 */
function el_prepare_tree_for_insert(array $tree): array
{
    // V3-only steps run first so the universal finalize sees the final
    // node shape: the boxed splitter has produced its outer/inner pair,
    // and the v3 settings translator has drained the legacy keys into
    // wrapped style props. Then the universal pipeline (ensure ids,
    // normalize widget types, coerce settings, normalize breakpoints,
    // sanitize CSS class ids, sync classes) finishes the job.
    $tree = el_ensure_tree_ids($tree);
    $tree = el_split_boxed_containers($tree);
    $tree = el_translate_v3_container_settings($tree);

    $dynamic_tag_errors = [];
    $tree = el_finalize_atomic_node($tree, $dynamic_tag_errors);

    $validation = el_validate_tree([$tree]);
    if ($dynamic_tag_errors !== [] || !$validation['ok']) {
        return ['response' => build_tree_validation_error_response($validation, $dynamic_tag_errors)];
    }

    return ['tree' => $validation['tree'][0]];
}

/**
 * Shared tail for tree-mode inserts after the subtree passed validation.
 *
 * @param array{post_id: int, tree: array<string, mixed>, parent_id: string|null, position: int} $parsed
 * @param array<string, mixed> $tree
 * @return array<string, mixed>
 */
function el_insert_tree_and_write(array $parsed, array $tree): array
{
    $root_id = (string) $tree['id'];
    $element_type = (string) ($tree['elType'] ?? '');

    [$elements, $error] = el_read_page($parsed['post_id']);
    if ($elements === null) {
        return ['success' => false, 'error' => $error ?? 'Unknown error.'];
    }

    $insert_error = el_insert_into_tree(
        $elements,
        $tree,
        $parsed['parent_id'],
        $parsed['position'],
        $parsed['post_id'],
    );
    if ($insert_error !== null) {
        return ['success' => false, 'error' => $insert_error];
    }

    $result = el_write_page($parsed['post_id'], $elements);
    if (is_wp_error($result)) {
        return ['success' => false, 'error' => $result->get_error_message()];
    }

    /** @var string|null $widget_type */
    $widget_type = $tree['widgetType'] ?? null;

    $response = [
        'success' => true,
        'element_id' => $root_id,
        'element_type' => $element_type,
    ];
    if (is_string($widget_type) && $widget_type !== '') {
        $response['widget_type'] = $widget_type;
    }

    return $response;
}

/**
 * Shared tail: read the current page, insert the validated node, write it
 * back. Returns the success payload or an error.
 *
 * @param array{post_id: int, element_type: string, widget_type: string, settings: array<string, mixed>, styles: array<string, mixed>, parent_id: string|null, position: int, element_id: string} $parsed
 * @param array<string, mixed> $node
 * @param array<string, mixed> $success_extras Merged into the success response.
 * @return array<string, mixed>
 */
function el_insert_and_write(array $parsed, array $node, array $success_extras): array
{
    [$elements, $error] = el_read_page($parsed['post_id']);
    if ($elements === null) {
        return ['success' => false, 'error' => $error ?? 'Unknown error.'];
    }

    $insert_error = el_insert_into_tree(
        $elements,
        $node,
        $parsed['parent_id'],
        $parsed['position'],
        $parsed['post_id'],
    );
    if ($insert_error !== null) {
        return ['success' => false, 'error' => $insert_error];
    }

    $result = el_write_page($parsed['post_id'], $elements);
    if (is_wp_error($result)) {
        return ['success' => false, 'error' => $result->get_error_message()];
    }

    return ['success' => true] + $success_extras;
}

/**
 * Validate the user-supplied per-element styles map for a single-element
 * insert and attach it to the freshly built node. Reuses the same
 * `el_validate_update_styles` validator the edit-element path uses, so
 * non-atomic targets and malformed shapes get the same error reporting.
 * After attaching, runs the universal atomic finalize so style IDs sync
 * into `settings.classes` (otherwise the styles never bind on render).
 *
 * @param array<string, mixed> $node
 * @param array<string, mixed> $styles
 * @return array{node: array<string, mixed>}|array{error_response: array<string, mixed>}
 */
function el_attach_add_element_styles(array $node, array $styles): array
{
    if ($styles === []) {
        return ['node' => $node];
    }

    $validation = el_validate_update_styles($node, $styles);
    if (array_key_exists('error_response', $validation)) {
        return ['error_response' => $validation['error_response']];
    }

    $node['styles'] = $validation['styles'] ?? [];

    /** @var list<array{element_id: string, widget_type: string, setting_name: string, tag: mixed, reason: string}> $dynamic_tag_errors */
    $dynamic_tag_errors = [];
    $node = el_finalize_atomic_node($node, $dynamic_tag_errors);
    if ($dynamic_tag_errors !== []) {
        return [
            'error_response' => [
                'success' => false,
                'error' => sprintf(
                    'Add aborted: %d dynamic tag parse error(s) on the new element. Fix the reported tag string(s) and retry.',
                    count($dynamic_tag_errors),
                ),
                'dynamic_tag_errors' => $dynamic_tag_errors,
            ],
        ];
    }

    return ['node' => $node];
}

/**
 * Recursively ensure every element in a subtree has an `id` field.
 * Missing IDs are auto-generated.
 *
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function el_ensure_tree_ids(array $node): array
{
    /** @var string|null $id */
    $id = $node['id'] ?? null;
    if (!is_string($id) || $id === '') {
        $node['id'] = el_generate_id();
    }

    /** @var list<array<string, mixed>> $children */
    $children = is_array($node['elements'] ?? null) ? $node['elements'] : [];
    $new_children = [];
    foreach ($children as $child) {
        $new_children[] = el_ensure_tree_ids($child);
    }
    $node['elements'] = $new_children;

    return $node;
}

/**
 * Insert an element into a list at the given position (or append when position < 0).
 *
 * @param list<array<string, mixed>> $list
 * @param array<string, mixed>       $element
 */
function el_insert(array &$list, array $element, int $position): void
{
    if ($position < 0 || $position >= count($list)) {
        $list[] = $element;
        return;
    }
    array_splice($list, $position, length: 0, replacement: [$element]);
}

/**
 * Insert an element into the tree, either at the root or under a parent ID.
 *
 * @param list<array<string, mixed>> $elements
 * @param array<string, mixed>       $element
 * @return string|null Error message, or null on success.
 */
function el_insert_into_tree(array &$elements, array $element, ?string $parent_id, int $position, int $post_id): ?string
{
    if ($parent_id === null) {
        el_insert($elements, $element, $position);
        return null;
    }

    $found = el_mutate($elements, $parent_id, static function (array $node) use ($element, $position): array {
        /** @var list<array<string, mixed>> $children */
        $children = is_array($node['elements'] ?? null) ? $node['elements'] : [];
        el_insert($children, $element, $position);
        $node['elements'] = $children;
        return $node;
    });

    return $found ? null : "Parent '{$parent_id}' not found on post {$post_id}.";
}

/**
 * Resolve the id to assign to a new element. When the caller passed a custom
 * `element_id`, validate the slug and ensure no node in the existing tree
 * already uses it; otherwise generate a fresh 7-char hex id matching
 * Elementor's own auto-id format. The tree read is shared with the
 * subsequent insert path but is cheap (single post-meta read).
 *
 * @return array{ok: true, id: string}|array{ok: false, error: string}
 */
function el_resolve_new_element_id(int $post_id, string $raw): array
{
    if ($raw === '') {
        return ['ok' => true, 'id' => el_generate_id()];
    }

    $validation = el_validate_element_id_slug($raw);
    if (!$validation['ok']) {
        return $validation;
    }
    $slug = $validation['slug'];

    [$elements, $error] = el_read_page($post_id);
    if ($elements === null) {
        return ['ok' => false, 'error' => $error ?? 'Unknown error.'];
    }

    if (el_find($elements, $slug) !== null) {
        return [
            'ok' => false,
            'error' => sprintf(
                'element_id "%s" is already used by another element on this page. Pick a different slug or omit element_id to auto-generate.',
                $slug,
            ),
        ];
    }

    return ['ok' => true, 'id' => $slug];
}
