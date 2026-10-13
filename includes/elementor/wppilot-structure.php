<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

use WP_Error;

/**
 * Elementor: structural edits that move existing elements rather than write new ones.
 *
 * Every one of these was previously a read-mutate-write loop the agent had to
 * perform itself: fetch the whole document, find the node, splice it, and post
 * the entire tree back with set-content. That is three round trips and a full
 * document in context for what is conceptually one instruction, and each step is
 * a chance to lose a subtree or duplicate an id. The tree walkers this file uses
 * already existed for add-element and delete-element; only the four verbs were
 * missing.
 *
 * Duplication regenerates ids for the whole copied subtree. That is not a
 * detail: two nodes sharing an id is not a visible error, it is a document that
 * behaves strangely later — styles keyed to the id apply to both, and the editor
 * writes one of them out on the next save.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Detach an element from the tree and return it.
 *
 * Returns null when the id is not present. The tree is modified in place, so a
 * caller that fails to re-insert has removed the element — every caller here
 * either re-inserts or aborts before writing.
 *
 * @param list<array<string, mixed>> $elements
 * @return array<string, mixed>|null
 */
function el_detach(array &$elements, string $element_id): ?array
{
    foreach ($elements as $index => $element) {
        if (($element['id'] ?? '') === $element_id) {
            array_splice($elements, $index, length: 1);

            return $element;
        }

        if (is_array($element['elements'] ?? null)) {
            /** @var list<array<string, mixed>> $children */
            $children = $element['elements'];
            $detached = el_detach($children, $element_id);
            if ($detached !== null) {
                $elements[$index]['elements'] = $children;

                return $detached;
            }
        }
    }

    return null;
}

/**
 * Whether $ancestor_id is the element itself or one of its descendants.
 *
 * Moving a container into its own subtree detaches a branch and then splices it
 * back inside itself, which silently loses everything under it. The check is
 * cheap and the failure is not recoverable from the response, so it runs before
 * anything is detached.
 *
 * @param list<array<string, mixed>> $elements
 */
function el_contains(array $elements, string $ancestor_id, string $needle_id): bool
{
    $ancestor = el_find($elements, $ancestor_id);
    if ($ancestor === null) {
        return false;
    }
    if ($ancestor_id === $needle_id) {
        return true;
    }

    /** @var list<array<string, mixed>> $children */
    $children = is_array($ancestor['elements'] ?? null) ? $ancestor['elements'] : [];

    return el_find($children, $needle_id) !== null;
}

/**
 * Read a document, hand the tree to a mutation, and write it back.
 *
 * The mutation returns an error string to abort — nothing is written and the
 * document is untouched, which is what makes a failed move a no-op rather than
 * a half-applied edit.
 *
 * @param callable(list<array<string, mixed>>&): (string|null) $mutate
 * @return array{success: bool, error?: string}
 */
function el_structural_write(int $post_id, callable $mutate): array
{
    if (!class_exists('Elementor\\Plugin')) {
        return ['success' => false, 'error' => 'Elementor is not active.'];
    }
    if ($post_id <= 0 || !get_post($post_id)) {
        return ['success' => false, 'error' => "Post {$post_id} not found."];
    }

    [$elements, $error] = el_read_page($post_id);
    if ($elements === null) {
        return ['success' => false, 'error' => $error ?? 'Unknown error.'];
    }

    $failure = $mutate($elements);
    if (is_string($failure)) {
        return ['success' => false, 'error' => $failure];
    }

    $result = el_write_page($post_id, $elements);
    if ($result instanceof WP_Error) {
        return ['success' => false, 'error' => $result->get_error_message()];
    }

    return ['success' => true];
}

wp_register_ability('wppilot/elementor-move-element', [
    'label' => __('Move Elementor Element', domain: 'wppilot'),
    'description' => __(
        'Moves an existing element to a different parent, a different position among its siblings, or both, keeping its id, settings, styles and children intact. Use this rather than deleting and re-adding: re-adding produces a new id, which breaks per-element styles named after it and any interaction that targets it. Omit parent_id to move to the document root; position is a zero-based index among the destination parent\'s children and appends when omitted. Moving a container into itself or into one of its own descendants is refused, because it would detach the branch and splice it back inside itself.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'element_id' => ['type' => 'string', 'description' => 'Element to move.'],
            'parent_id' => [
                'type' => 'string',
                'description' => 'Destination container. Omit for the document root.',
            ],
            'position' => [
                'type' => 'integer',
                'description' => 'Zero-based index among the destination\'s children. Omit to append.',
            ],
        ],
        'required' => ['post_id', 'element_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'error' => ['type' => 'string']],
        'required' => ['success'],
    ],
    'execute_callback' => 'WPPilot\Elementor\elementor_move_element',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array{success: bool, error?: string}
 */
function elementor_move_element(array $input): array
{
    $post_id = (int) ($input['post_id'] ?? 0);
    $element_id = (string) ($input['element_id'] ?? '');
    $parent_id = is_string($input['parent_id'] ?? null) && $input['parent_id'] !== ''
        ? (string) $input['parent_id']
        : null;
    $position = is_numeric($input['position'] ?? null) ? (int) $input['position'] : -1;

    if ($element_id === '') {
        return ['success' => false, 'error' => 'Parameter "element_id" is required.'];
    }

    return el_structural_write(
        $post_id,
        static function (array &$elements) use ($element_id, $parent_id, $position, $post_id): ?string {
            if (el_find($elements, $element_id) === null) {
                return "Element '{$element_id}' not found on post {$post_id}.";
            }
            if ($parent_id !== null) {
                if (el_find($elements, $parent_id) === null) {
                    return "Parent '{$parent_id}' not found on post {$post_id}.";
                }
                if (el_contains($elements, $element_id, $parent_id)) {
                    return "Cannot move '{$element_id}' into itself or one of its own descendants.";
                }
            }

            $node = el_detach($elements, $element_id);
            if ($node === null) {
                return "Element '{$element_id}' not found on post {$post_id}.";
            }

            return el_insert_into_tree($elements, $node, $parent_id, $position, $post_id);
        },
    );
}

wp_register_ability('wppilot/elementor-duplicate-element', [
    'label' => __('Duplicate Elementor Element', domain: 'wppilot'),
    'description' => __(
        'Copies an element and everything under it, giving every node in the copy a fresh id, and inserts the copy into the document. By default it lands directly after the original, which is what "duplicate this section" normally means; pass parent_id and position to place it somewhere else. Regenerating the ids is the point of this ability: a copy that keeps them produces two elements answering to the same id, which is not reported as an error but makes per-element styles apply to both and lets the editor overwrite one of them on the next save.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'element_id' => ['type' => 'string', 'description' => 'Element to copy.'],
            'parent_id' => [
                'type' => 'string',
                'description' => 'Destination container. Omit to place the copy beside the original.',
            ],
            'position' => [
                'type' => 'integer',
                'description' => 'Zero-based index in the destination. Omit to place after the original.',
            ],
        ],
        'required' => ['post_id', 'element_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'element_id' => ['type' => 'string', 'description' => 'Id of the new copy.'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'WPPilot\Elementor\elementor_duplicate_element',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        // Duplicating twice produces two copies, by design.
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array{success: bool, element_id?: string, error?: string}
 */
function elementor_duplicate_element(array $input): array
{
    $post_id = (int) ($input['post_id'] ?? 0);
    $element_id = (string) ($input['element_id'] ?? '');
    $explicit_parent = is_string($input['parent_id'] ?? null) && $input['parent_id'] !== ''
        ? (string) $input['parent_id']
        : null;
    $explicit_position = is_numeric($input['position'] ?? null) ? (int) $input['position'] : null;

    if ($element_id === '') {
        return ['success' => false, 'error' => 'Parameter "element_id" is required.'];
    }

    $copy_id = '';
    $result = el_structural_write(
        $post_id,
        static function (array &$elements) use (
            $element_id,
            $explicit_parent,
            $explicit_position,
            $post_id,
            &$copy_id,
        ): ?string {
            $original = el_find($elements, $element_id);
            if ($original === null) {
                return "Element '{$element_id}' not found on post {$post_id}.";
            }

            // Strip every id first, then regenerate: el_ensure_tree_ids only
            // fills ids that are absent, so a copy that kept the originals would
            // pass through it unchanged and collide with the source.
            $copy = el_ensure_tree_ids(el_strip_tree_ids($original));
            $copy_id = (string) ($copy['id'] ?? '');

            [$parent_id, $position] = $explicit_parent !== null
                ? [$explicit_parent, $explicit_position ?? -1]
                : el_sibling_slot($elements, $element_id, $explicit_position);

            return el_insert_into_tree($elements, $copy, $parent_id, $position, $post_id);
        },
    );

    if (($result['success'] ?? false) === true && $copy_id !== '') {
        $result['element_id'] = $copy_id;
    }

    return $result;
}

/**
 * Remove `id` from a node and everything under it.
 *
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function el_strip_tree_ids(array $node): array
{
    unset($node['id']);

    /** @var list<array<string, mixed>> $children */
    $children = is_array($node['elements'] ?? null) ? $node['elements'] : [];
    $stripped = [];
    foreach ($children as $child) {
        $stripped[] = el_strip_tree_ids($child);
    }
    $node['elements'] = $stripped;

    return $node;
}

/**
 * The parent and index that put a new node immediately after an existing one.
 *
 * @param list<array<string, mixed>> $elements
 * @return array{0: string|null, 1: int}
 */
function el_sibling_slot(array $elements, string $element_id, ?int $position): array
{
    $located = el_locate($elements, $element_id, parent_id: null);
    if ($located === null) {
        return [null, $position ?? -1];
    }

    [$parent_id, $index] = $located;

    return [$parent_id, $position ?? $index + 1];
}

/**
 * Find an element's parent id and its index among that parent's children.
 *
 * A root-level element reports a null parent, which is the same value the insert
 * helpers take to mean "the document root".
 *
 * @param list<array<string, mixed>> $elements
 * @return array{0: string|null, 1: int}|null
 */
function el_locate(array $elements, string $element_id, ?string $parent_id): ?array
{
    foreach ($elements as $index => $element) {
        if (($element['id'] ?? '') === $element_id) {
            return [$parent_id, $index];
        }
        if (is_array($element['elements'] ?? null)) {
            /** @var list<array<string, mixed>> $children */
            $children = $element['elements'];
            $found = el_locate($children, $element_id, (string) ($element['id'] ?? ''));
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

wp_register_ability('wppilot/elementor-reorder-children', [
    'label' => __('Reorder Elementor Children', domain: 'wppilot'),
    'description' => __(
        'Sets the order of a container\'s direct children in one call, by listing their ids in the order you want. Omit parent_id to reorder the document\'s top-level elements. The list must name exactly the children that container has — no additions, no omissions, no repeats — because a partial list is ambiguous about where the rest belong, and guessing would silently rearrange elements the caller never mentioned. Nothing is written unless the list matches.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'parent_id' => [
                'type' => 'string',
                'description' => 'Container whose children to reorder. Omit for the document root.',
            ],
            'order' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Every direct child id, in the desired order.',
            ],
        ],
        'required' => ['post_id', 'order'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => ['success' => ['type' => 'boolean'], 'error' => ['type' => 'string']],
        'required' => ['success'],
    ],
    'execute_callback' => 'WPPilot\Elementor\elementor_reorder_children',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array{success: bool, error?: string}
 */
function elementor_reorder_children(array $input): array
{
    $post_id = (int) ($input['post_id'] ?? 0);
    $parent_id = is_string($input['parent_id'] ?? null) && $input['parent_id'] !== ''
        ? (string) $input['parent_id']
        : null;

    /** @var list<string> $order */
    $order = [];
    /** @var mixed $raw_order */
    $raw_order = $input['order'] ?? [];
    if (is_array($raw_order)) {
        /** @var mixed $id */
        foreach ($raw_order as $id) {
            if (is_string($id) && $id !== '') {
                $order[] = $id;
            }
        }
    }

    if ($order === []) {
        return ['success' => false, 'error' => 'Parameter "order" must list at least one child id.'];
    }

    return el_structural_write(
        $post_id,
        static function (array &$elements) use ($order, $parent_id, $post_id): ?string {
            /** @var list<array<string, mixed>> $children */
            $children = $elements;
            if ($parent_id !== null) {
                $parent = el_find($elements, $parent_id);
                if ($parent === null) {
                    return "Parent '{$parent_id}' not found on post {$post_id}.";
                }
                $children = is_array($parent['elements'] ?? null) ? $parent['elements'] : [];
            }

            $existing = [];
            foreach ($children as $child) {
                $existing[] = (string) ($child['id'] ?? '');
            }

            $missing = array_diff($existing, $order);
            $unknown = array_diff($order, $existing);
            if ($missing !== [] || $unknown !== [] || count($order) !== count($existing)) {
                return sprintf(
                    'The order must name every direct child exactly once. Children are: %s.',
                    implode(', ', $existing),
                );
            }

            $by_id = [];
            foreach ($children as $child) {
                $by_id[(string) ($child['id'] ?? '')] = $child;
            }
            $reordered = [];
            foreach ($order as $id) {
                $reordered[] = $by_id[$id];
            }

            if ($parent_id === null) {
                $elements = $reordered;

                return null;
            }

            el_mutate($elements, $parent_id, static function (array $node) use ($reordered): array {
                $node['elements'] = $reordered;

                return $node;
            });

            return null;
        },
    );
}

wp_register_ability('wppilot/elementor-find-elements', [
    'label' => __('Find Elementor Elements', domain: 'wppilot'),
    'description' => __(
        'Searches a document for elements matching a widget type, a settings value, or free text, and returns their ids with the path to each. This is how you act on "every button on the page" or "the heading that says Pricing" without reading the whole document: the answer is a short list of ids to pass to edit-element, move-element or delete-element. widget_type matches exactly (heading, button, e-heading). setting_key with setting_value matches elements whose setting equals that value. text matches any string value anywhere in an element\'s settings, case-insensitively, which is the one to reach for when you know what the page says but not how it is built.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'widget_type' => ['type' => 'string', 'description' => 'Exact widget type to match.'],
            'element_type' => [
                'type' => 'string',
                'description' => 'Exact element type to match, e.g. container, e-flexbox.',
            ],
            'setting_key' => ['type' => 'string', 'description' => 'Settings key to test.'],
            'setting_value' => ['type' => 'string', 'description' => 'Value that key must equal.'],
            'text' => ['type' => 'string', 'description' => 'Case-insensitive substring of any settings value.'],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'count' => ['type' => 'integer'],
            'matches' => ['type' => 'array'],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'WPPilot\Elementor\elementor_find_elements',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array{success: bool, count?: int, matches?: list<array<string, mixed>>, error?: string}
 */
function elementor_find_elements(array $input): array
{
    if (!class_exists('Elementor\\Plugin')) {
        return ['success' => false, 'error' => 'Elementor is not active.'];
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id)) {
        return ['success' => false, 'error' => "Post {$post_id} not found."];
    }

    [$elements, $error] = el_read_page($post_id);
    if ($elements === null) {
        return ['success' => false, 'error' => $error ?? 'Unknown error.'];
    }

    $criteria = [
        'widget_type' => (string) ($input['widget_type'] ?? ''),
        'element_type' => (string) ($input['element_type'] ?? ''),
        'setting_key' => (string) ($input['setting_key'] ?? ''),
        'setting_value' => (string) ($input['setting_value'] ?? ''),
        'text' => (string) ($input['text'] ?? ''),
    ];

    $matches = [];
    el_collect_matches($elements, $criteria, parents: [], matches: $matches);

    return ['success' => true, 'count' => count($matches), 'matches' => $matches];
}

/**
 * Walk the tree, recording every element that satisfies the criteria.
 *
 * The path of ancestor ids travels with each match because an id alone does not
 * say where the element sits, and the usual next step after finding something is
 * moving it or inserting beside it.
 *
 * @param list<array<string, mixed>>          $elements
 * @param array<string, string>               $criteria
 * @param list<string>                        $parents
 * @param list<array<string, mixed>>          $matches
 */
function el_collect_matches(array $elements, array $criteria, array $parents, array &$matches): void
{
    foreach ($elements as $element) {
        $id = (string) ($element['id'] ?? '');
        if (el_element_matches($element, $criteria)) {
            $matches[] = [
                'element_id' => $id,
                'element_type' => (string) ($element['elType'] ?? ''),
                'widget_type' => el_element_widget_type($element),
                'path' => $parents,
            ];
        }

        if (is_array($element['elements'] ?? null)) {
            /** @var list<array<string, mixed>> $children */
            $children = $element['elements'];
            el_collect_matches($children, $criteria, [...$parents, $id], $matches);
        }
    }
}

/**
 * Whether one element satisfies every criterion that was supplied.
 *
 * Criteria are ANDed and blanks are ignored, so a call with no criteria at all
 * returns the whole document as a flat id list — which is a legitimate way to
 * ask "what is on this page" without the settings.
 *
 * @param array<string, mixed>  $element
 * @param array<string, string> $criteria
 */
function el_element_matches(array $element, array $criteria): bool
{
    if ($criteria['widget_type'] !== '' && el_element_widget_type($element) !== $criteria['widget_type']) {
        return false;
    }
    if ($criteria['element_type'] !== '' && (string) ($element['elType'] ?? '') !== $criteria['element_type']) {
        return false;
    }

    /** @var array<string, mixed> $settings */
    $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];

    if ($criteria['setting_key'] !== '') {
        if (!array_key_exists($criteria['setting_key'], $settings)) {
            return false;
        }
        if ($criteria['setting_value'] !== '') {
            /** @var mixed $value */
            $value = $settings[$criteria['setting_key']];
            if (!is_scalar($value) || (string) $value !== $criteria['setting_value']) {
                return false;
            }
        }
    }

    if ($criteria['text'] !== '' && !el_settings_contain_text($settings, $criteria['text'])) {
        return false;
    }

    return true;
}

/**
 * Whether any string anywhere in a settings array contains the needle.
 *
 * Settings nest arbitrarily — a heading's text is a top-level string, a button's
 * link is a URL inside an array, an atomic value is wrapped in {$$type, value} —
 * so the search recurses rather than assuming a shape.
 *
 * @param array<array-key, mixed> $settings
 */
function el_settings_contain_text(array $settings, string $needle): bool
{
    $needle = strtolower($needle);

    /** @var mixed $value */
    foreach ($settings as $value) {
        if (is_string($value) && str_contains(strtolower($value), $needle)) {
            return true;
        }
        if (is_array($value) && el_settings_contain_text($value, $needle)) {
            return true;
        }
    }

    return false;
}
