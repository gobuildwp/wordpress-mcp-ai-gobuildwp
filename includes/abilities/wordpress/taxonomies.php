<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\WordPress;

use WP_Error;
use WP_Term;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Taxonomies owned by another subsystem or by WordPress bookkeeping.
 *
 * `nav_menu` is menu storage and belongs to the menu abilities; the rest are
 * internal registries whose rows WordPress writes and reads itself.
 *
 * @var list<string>
 */
const WPPILOT_NON_AGENT_TAXONOMIES = [
    'nav_menu',
    'link_category',
    'wp_theme',
    'wp_template_part_area',
    'wp_pattern_category',
];

register_core_ability('wppilot/list-taxonomies', [
    'label' => __('List Taxonomies', domain: 'wppilot'),
    'description' => __(
        'Lists the taxonomies exposed to agents, with their labels, hierarchy, capability names, and the post types each applies to. Call this before working with terms so the taxonomy slug and hierarchy are known rather than guessed.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'post_type' => [
                'type' => 'string',
                'description' => 'Optional. Return only taxonomies registered for this post type.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_list_taxonomies',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/list-terms', [
    'label' => __('List Terms', domain: 'wppilot'),
    'description' => __(
        'Lists or searches the terms of one taxonomy, newest-count first by default. Supports a name search, parent filtering for hierarchical taxonomies, and bounded pagination.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'taxonomy' => ['type' => 'string'],
            'search' => ['type' => 'string', 'description' => 'Optional name/slug substring filter.'],
            'parent' => ['type' => 'integer', 'description' => 'Optional. Only children of this term ID.'],
            'hide_empty' => ['type' => 'boolean', 'default' => false],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
        ],
        'required' => ['taxonomy'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_list_terms',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/get-term', [
    'label' => __('Get Term', domain: 'wppilot'),
    'description' => __(
        'Returns one term with its name, slug, description, parent, count, and taxonomy.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'term_id' => ['type' => 'integer'],
            'taxonomy' => ['type' => 'string'],
        ],
        'required' => ['term_id', 'taxonomy'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_get_term',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/create-term', [
    'label' => __('Create Term', domain: 'wppilot'),
    'description' => __(
        'Creates a term in a taxonomy. Requires the taxonomy\'s own edit_terms capability. A parent may only be set on a hierarchical taxonomy.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'taxonomy' => ['type' => 'string'],
            'name' => ['type' => 'string', 'minLength' => 1],
            'slug' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'parent' => ['type' => 'integer', 'description' => 'Hierarchical taxonomies only.'],
        ],
        'required' => ['taxonomy', 'name'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_create_term',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: false, idempotent: false),
]);

register_core_ability('wppilot/update-term', [
    'label' => __('Update Term', domain: 'wppilot'),
    'description' => __(
        'Updates a term\'s name, slug, description, or parent. Only the supplied fields change.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'term_id' => ['type' => 'integer'],
            'taxonomy' => ['type' => 'string'],
            'name' => ['type' => 'string', 'minLength' => 1],
            'slug' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'parent' => ['type' => 'integer'],
        ],
        'required' => ['term_id', 'taxonomy'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_update_term',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: false),
]);

register_core_ability('wppilot/delete-term', [
    'label' => __('Delete Term', domain: 'wppilot'),
    'description' => __(
        'Permanently deletes a term. WordPress has no trash for terms, so this cannot be undone and requires explicit confirmation. Content assigned to the term is not deleted; on a hierarchical taxonomy the term\'s children are re-parented to its parent.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'term_id' => ['type' => 'integer'],
            'taxonomy' => ['type' => 'string'],
            'confirm' => [
                'type' => 'boolean',
                'description' => 'Must be true. Deleting a term is permanent and is not rollbackable.',
            ],
        ],
        'required' => ['term_id', 'taxonomy', 'confirm'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_delete_term',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true, idempotent: false),
]);

register_core_ability('wppilot/assign-terms', [
    'label' => __('Assign Terms', domain: 'wppilot'),
    'description' => __(
        'Assigns terms to a post, or removes them. Mode "replace" sets the exact term list, "add" appends, and "remove" detaches. Requires the taxonomy\'s assign_terms capability and edit access to the post.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'taxonomy' => ['type' => 'string'],
            'term_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'maxItems' => 200,
            ],
            'mode' => [
                'type' => 'string',
                'enum' => ['replace', 'add', 'remove'],
                'default' => 'replace',
            ],
        ],
        'required' => ['post_id', 'taxonomy', 'term_ids'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_assign_terms',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: false),
]);

/**
 * Reject taxonomies that are not appropriate for remote agent use.
 *
 * Same two rules as the post-type gate: the explicit blocklist, then a
 * visibility test. A taxonomy that is neither public nor REST-visible is
 * plugin-private storage.
 */
function wordpress_taxonomy_is_agent_facing(string $taxonomy): ?WP_Error
{
    if (in_array($taxonomy, WPPILOT_NON_AGENT_TAXONOMIES, strict: true)) {
        return new WP_Error(
            'taxonomy_not_agent_facing',
            sprintf(
                'Taxonomy "%s" is internal WordPress storage or is owned by a dedicated ability.',
                $taxonomy,
            ),
            ['status' => 403],
        );
    }

    $object = get_taxonomy($taxonomy);
    if ($object === false) {
        return new WP_Error(
            'invalid_taxonomy',
            sprintf('Taxonomy "%s" is not registered.', $taxonomy),
            ['status' => 404],
        );
    }

    if ($object->public !== true && $object->show_in_rest !== true) {
        return new WP_Error(
            'taxonomy_not_public',
            sprintf(
                'Taxonomy "%s" is registered as private, so it is not exposed to agents.%s',
                $taxonomy,
                wordpress_private_taxonomy_remedy($taxonomy),
            ),
            ['status' => 403],
        );
    }

    return null;
}

/**
 * Where to go instead when the private taxonomy belongs to Polylang.
 *
 * Polylang keeps a post's language and its translation group in private
 * taxonomies, so an agent asked to put a page in another language reaches for
 * them and is refused with no way forward.
 */
function wordpress_private_taxonomy_remedy(string $taxonomy): string
{
    if (!in_array($taxonomy, ['language', 'post_translations', 'term_language', 'term_translations'], strict: true)) {
        return '';
    }

    $ability = 'wppilot/polylang-set-content-language';
    if (function_exists('wp_has_ability') && wp_has_ability($ability)) {
        return sprintf(
            ' It belongs to Polylang: use %s to set a post\'s language and wppilot/polylang-link-content-translations to link it to its translations.',
            $ability,
        );
    }

    return ' It belongs to Polylang: set the language and translations in the Polylang editor — GoBuildWP Pro adds Polylang abilities that set a post\'s language and link its translations.';
}

/**
 * Check one of a taxonomy's four capabilities, read from its own capability object.
 *
 * `manage_categories` is not assumed: a taxonomy may declare an entirely
 * separate set, so the name is always resolved from the registration.
 */
function wordpress_taxonomy_capability_error(string $taxonomy, string $capability): ?WP_Error
{
    $object = get_taxonomy($taxonomy);
    if ($object === false) {
        return new WP_Error('invalid_taxonomy', sprintf('Taxonomy "%s" is not registered.', $taxonomy), [
            'status' => 404,
        ]);
    }

    $required = (string) ($object->cap->{$capability} ?? '');
    if ($required === '' || !current_user_can($required)) {
        return new WP_Error(
            'cannot_' . $capability,
            sprintf('You are not allowed to %1$s in taxonomy "%2$s".', str_replace('_', ' ', $capability), $taxonomy),
            ['status' => 403],
        );
    }

    return null;
}

/**
 * Load a term and prove it belongs to the taxonomy the caller named.
 *
 * Term IDs are unique across taxonomies in WordPress, so without this check a
 * caller could edit a term in a taxonomy they were never authorized against.
 */
function wordpress_require_term(int $term_id, string $taxonomy): WP_Term|WP_Error
{
    $gate = wordpress_taxonomy_is_agent_facing($taxonomy);
    if ($gate !== null) {
        return $gate;
    }

    $term = get_term($term_id, $taxonomy);
    if (is_wp_error($term)) {
        return $term;
    }
    if (!$term instanceof WP_Term) {
        return new WP_Error(
            'term_not_found',
            sprintf('Term %1$d was not found in taxonomy "%2$s".', $term_id, $taxonomy),
            ['status' => 404],
        );
    }

    return $term;
}

/** @return array<string, mixed> */
function wordpress_term_summary(WP_Term $term): array
{
    return [
        'term_id' => (int) $term->term_id,
        'taxonomy' => (string) $term->taxonomy,
        'name' => (string) $term->name,
        'slug' => (string) $term->slug,
        'description' => (string) $term->description,
        'parent' => (int) $term->parent,
        'count' => (int) $term->count,
        'link' => is_string($link = get_term_link($term)) ? $link : '',
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function wordpress_list_taxonomies(array $input): array
{
    $post_type = isset($input['post_type']) ? (string) $input['post_type'] : '';
    $names = $post_type !== ''
        ? get_object_taxonomies($post_type, output: 'names')
        : get_taxonomies([], output: 'names');

    $items = [];
    foreach ((array) $names as $name) {
        $name = (string) $name;
        if (wordpress_taxonomy_is_agent_facing($name) !== null) {
            continue;
        }
        $object = get_taxonomy($name);
        if ($object === false) {
            continue;
        }
        $items[] = [
            'taxonomy' => $name,
            'label' => (string) $object->label,
            'hierarchical' => $object->hierarchical === true,
            'post_types' => array_values(array_map('strval', (array) $object->object_type)),
            'capabilities' => [
                'manage_terms' => (string) $object->cap->manage_terms,
                'edit_terms' => (string) $object->cap->edit_terms,
                'delete_terms' => (string) $object->cap->delete_terms,
                'assign_terms' => (string) $object->cap->assign_terms,
            ],
        ];
    }

    return ['items' => $items, 'count' => count($items)];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_list_terms(array $input): array|WP_Error
{
    $taxonomy = (string) $input['taxonomy'];
    $gate = wordpress_taxonomy_is_agent_facing($taxonomy);
    if ($gate !== null) {
        return $gate;
    }

    $per_page = min(100, max(1, (int) ($input['per_page'] ?? 25)));
    $page = max(1, (int) ($input['page'] ?? 1));

    $args = [
        'taxonomy' => $taxonomy,
        'hide_empty' => ($input['hide_empty'] ?? false) === true,
        'number' => $per_page,
        'offset' => ($page - 1) * $per_page,
        'orderby' => 'count',
        'order' => 'DESC',
    ];
    if (isset($input['search']) && (string) $input['search'] !== '') {
        $args['search'] = (string) $input['search'];
    }
    if (isset($input['parent'])) {
        $args['parent'] = (int) $input['parent'];
    }

    $terms = get_terms($args);
    if (is_wp_error($terms)) {
        return $terms;
    }

    $items = [];
    foreach ((array) $terms as $term) {
        if ($term instanceof WP_Term) {
            $items[] = wordpress_term_summary($term);
        }
    }

    $total = wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => $args['hide_empty']]);

    return [
        'items' => $items,
        'count' => count($items),
        'page' => $page,
        'per_page' => $per_page,
        'total' => is_wp_error($total) ? null : (int) $total,
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_get_term(array $input): array|WP_Error
{
    $term = wordpress_require_term((int) $input['term_id'], (string) $input['taxonomy']);

    return $term instanceof WP_Error ? $term : wordpress_term_summary($term);
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_create_term(array $input): array|WP_Error
{
    $taxonomy = (string) $input['taxonomy'];
    $gate = wordpress_taxonomy_is_agent_facing($taxonomy);
    if ($gate !== null) {
        return $gate;
    }
    $capability = wordpress_taxonomy_capability_error($taxonomy, 'edit_terms');
    if ($capability !== null) {
        return $capability;
    }

    $args = [];
    foreach (['slug', 'description'] as $field) {
        if (isset($input[$field])) {
            $args[$field] = (string) $input[$field];
        }
    }

    $parent_error = wordpress_resolve_term_parent($input, $taxonomy, $args);
    if ($parent_error !== null) {
        return $parent_error;
    }

    $result = wp_insert_term((string) $input['name'], $taxonomy, $args);
    if (is_wp_error($result)) {
        return $result;
    }

    $term = get_term((int) $result['term_id'], $taxonomy);

    return $term instanceof WP_Term
        ? wordpress_term_summary($term)
        : ['term_id' => (int) $result['term_id'], 'taxonomy' => $taxonomy];
}

/**
 * Validate and apply a parent term, rejecting a parent on a flat taxonomy.
 *
 * @param array<string, mixed> $input
 * @param array<string, mixed> $args
 */
function wordpress_resolve_term_parent(array $input, string $taxonomy, array &$args): ?WP_Error
{
    if (!isset($input['parent'])) {
        return null;
    }

    $parent = (int) $input['parent'];
    if ($parent === 0) {
        $args['parent'] = 0;
        return null;
    }

    $object = get_taxonomy($taxonomy);
    if ($object === false || $object->hierarchical !== true) {
        return new WP_Error(
            'taxonomy_not_hierarchical',
            sprintf('Taxonomy "%s" is flat, so its terms cannot have a parent.', $taxonomy),
            ['status' => 422],
        );
    }

    $parent_term = wordpress_require_term($parent, $taxonomy);
    if ($parent_term instanceof WP_Error) {
        return $parent_term;
    }

    $args['parent'] = $parent;

    return null;
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_update_term(array $input): array|WP_Error
{
    $taxonomy = (string) $input['taxonomy'];
    $term_id = (int) $input['term_id'];

    $term = wordpress_require_term($term_id, $taxonomy);
    if ($term instanceof WP_Error) {
        return $term;
    }
    $capability = wordpress_taxonomy_capability_error($taxonomy, 'edit_terms');
    if ($capability !== null) {
        return $capability;
    }

    $args = [];
    foreach (['name', 'slug', 'description'] as $field) {
        if (isset($input[$field])) {
            $args[$field] = (string) $input[$field];
        }
    }

    if (isset($input['parent']) && (int) $input['parent'] === $term_id) {
        return new WP_Error('term_parent_self', 'A term cannot be its own parent.', ['status' => 422]);
    }
    $parent_error = wordpress_resolve_term_parent($input, $taxonomy, $args);
    if ($parent_error !== null) {
        return $parent_error;
    }

    if ($args === []) {
        return new WP_Error('nothing_to_update', 'Supply at least one field to change.', ['status' => 422]);
    }

    $result = wp_update_term($term_id, $taxonomy, $args);
    if (is_wp_error($result)) {
        return $result;
    }

    $updated = get_term($term_id, $taxonomy);

    return $updated instanceof WP_Term
        ? wordpress_term_summary($updated)
        : ['term_id' => $term_id, 'taxonomy' => $taxonomy];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_delete_term(array $input): array|WP_Error
{
    if (($input['confirm'] ?? false) !== true) {
        return new WP_Error(
            'confirmation_required',
            'Deleting a term is permanent and cannot be rolled back. Re-call with confirm: true only after the user has explicitly agreed.',
            ['status' => 422],
        );
    }

    $taxonomy = (string) $input['taxonomy'];
    $term_id = (int) $input['term_id'];

    $term = wordpress_require_term($term_id, $taxonomy);
    if ($term instanceof WP_Error) {
        return $term;
    }
    $capability = wordpress_taxonomy_capability_error($taxonomy, 'delete_terms');
    if ($capability !== null) {
        return $capability;
    }

    $name = (string) $term->name;
    $result = wp_delete_term($term_id, $taxonomy);
    if (is_wp_error($result)) {
        return $result;
    }
    if ($result !== true) {
        return new WP_Error(
            'delete_term_failed',
            sprintf('Term %1$d in taxonomy "%2$s" could not be deleted.', $term_id, $taxonomy),
            ['status' => 500],
        );
    }

    return [
        'term_id' => $term_id,
        'taxonomy' => $taxonomy,
        'name' => $name,
        'result' => 'deleted',
        'reversible' => false,
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_assign_terms(array $input): array|WP_Error
{
    $post_id = (int) $input['post_id'];
    $taxonomy = (string) $input['taxonomy'];

    $gate = wordpress_taxonomy_is_agent_facing($taxonomy);
    if ($gate !== null) {
        return $gate;
    }

    $post = get_post($post_id);
    if (!$post instanceof \WP_Post) {
        return new WP_Error('post_not_found', sprintf('Post %d was not found.', $post_id), ['status' => 404]);
    }
    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error(
            'cannot_edit_post',
            sprintf('You are not allowed to edit post %d.', $post_id),
            ['status' => 403],
        );
    }
    $capability = wordpress_taxonomy_capability_error($taxonomy, 'assign_terms');
    if ($capability !== null) {
        return $capability;
    }

    if (!in_array($taxonomy, get_object_taxonomies((string) $post->post_type, output: 'names'), strict: true)) {
        return new WP_Error(
            'taxonomy_not_registered_for_post_type',
            sprintf('Taxonomy "%1$s" is not registered for post type "%2$s".', $taxonomy, (string) $post->post_type),
            ['status' => 422],
        );
    }

    // Every term is proven to exist in this taxonomy before anything is written,
    // so a typo in one ID cannot leave a half-applied assignment behind.
    $term_ids = [];
    foreach ((array) $input['term_ids'] as $raw) {
        $term = wordpress_require_term((int) $raw, $taxonomy);
        if ($term instanceof WP_Error) {
            return $term;
        }
        $term_ids[] = (int) $term->term_id;
    }

    $mode = (string) ($input['mode'] ?? 'replace');
    $result = $mode === 'remove'
        ? wp_remove_object_terms($post_id, $term_ids, $taxonomy)
        : wp_set_object_terms($post_id, $term_ids, $taxonomy, append: $mode === 'add');

    if (is_wp_error($result)) {
        return $result;
    }

    $current = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);

    return [
        'post_id' => $post_id,
        'taxonomy' => $taxonomy,
        'mode' => $mode,
        'term_ids' => is_array($current) ? array_map('intval', $current) : [],
    ];
}
