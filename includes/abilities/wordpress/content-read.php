<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\WordPress;

use WP_Error;
use WP_Post;
use WP_Query;

if (!defined('ABSPATH')) {
    exit();
}

register_core_ability('wppilot/list-content', [
    'label' => __('List WordPress Content', domain: 'wppilot'),
    'description' => __(
        'Lists posts, pages, attachments, and custom post types with bounded pagination and search. Returns stable summaries for discovery before a targeted read or edit.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'post_types' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => ['post', 'page']],
            'statuses' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'default' => ['publish', 'draft', 'pending', 'private', 'future'],
            ],
            'search' => ['type' => 'string', 'default' => ''],
            'author' => ['type' => 'integer'],
            'parent' => ['type' => 'integer'],
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            'orderby' => [
                'type' => 'string',
                'enum' => ['date', 'modified', 'title', 'ID', 'menu_order'],
                'default' => 'modified',
            ],
            'order' => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_list_content',
    'permission_callback' => static fn(): bool => \wppilot_current_user_can_manage(),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/get-content', [
    'label' => __('Get WordPress Content', domain: 'wppilot'),
    'description' => __(
        'Returns a normalized post, page, attachment, or CPT snapshot with content, author, dates, terms, featured image, permalink, edit URL, and optionally non-protected metadata.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'minimum' => 1],
            'include_content' => ['type' => 'boolean', 'default' => true],
            'include_meta' => ['type' => 'boolean', 'default' => false],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_get_content',
    'permission_callback' => static fn(): bool => \wppilot_current_user_can_manage(),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/search-content', [
    'label' => __('Search Site Content', domain: 'wppilot'),
    'description' => __(
        'Searches public WordPress content types and returns ranked, bounded excerpts with edit targets. This is a live lexical search and creates no persistent index.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string', 'minLength' => 1],
            'post_types' => ['type' => 'array', 'items' => ['type' => 'string']],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
        ],
        'required' => ['query'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_search_content',
    'permission_callback' => static fn(): bool => \wppilot_current_user_can_manage(),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/get-page-snapshot', [
    'label' => __('Get Page Snapshot', domain: 'wppilot'),
    'description' => __(
        'Returns a compact normalized page digest: identity, template, content fingerprints, block outline, builder signals, SEO candidates, and modification state.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['post_id' => ['type' => 'integer', 'minimum' => 1]],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_get_page_snapshot',
    'permission_callback' => static fn(): bool => \wppilot_current_user_can_manage(),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

/** @return array<string, mixed> */
function wordpress_core_mcp_meta(bool $readonly, bool $destructive = false, bool $idempotent = true): array
{
    return [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'readonly' => $readonly,
            'destructive' => $destructive,
            'idempotent' => $idempotent,
        ],
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_list_content(array $input): array|WP_Error
{
    $post_types = wordpress_valid_post_types($input['post_types'] ?? ['post', 'page']);
    if ($post_types === []) {
        return new WP_Error('wppilot_no_valid_post_types', __(
            'No valid post types were requested.',
            domain: 'wppilot',
        ));
    }

    $per_page = min(100, max(1, (int) ($input['per_page'] ?? 20)));
    $page = max(1, (int) ($input['page'] ?? 1));
    $query = new WP_Query([
        'post_type' => $post_types,
        'post_status' => wordpress_valid_post_statuses($input['statuses'] ?? []),
        's' => (string) ($input['search'] ?? ''),
        'author' => array_key_exists('author', $input) ? (int) $input['author'] : '',
        'post_parent' => array_key_exists('parent', $input) ? (int) $input['parent'] : '',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'orderby' => (string) ($input['orderby'] ?? 'modified'),
        'order' => (string) ($input['order'] ?? 'DESC'),
        'no_found_rows' => false,
    ]);

    $items = [];
    foreach ($query->posts as $post) {
        if ($post instanceof WP_Post) {
            $items[] = wordpress_content_summary($post);
        }
    }
    return [
        'items' => $items,
        'page' => $page,
        'per_page' => $per_page,
        'total' => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
        'post_types' => $post_types,
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_get_content(array $input): array|WP_Error
{
    $post = get_post((int) $input['post_id']);
    if (!$post instanceof WP_Post || !current_user_can('read_post', $post->ID)) {
        return new WP_Error('wppilot_content_not_found', __(
            'Content was not found or is not readable.',
            domain: 'wppilot',
        ));
    }

    $result = wordpress_content_summary($post);
    $result['excerpt'] = $post->post_excerpt;
    $result['parent'] = (int) $post->post_parent;
    $result['menu_order'] = (int) $post->menu_order;
    $result['template'] = (string) get_page_template_slug($post->ID);
    $result['featured_image_id'] = (int) get_post_thumbnail_id($post->ID);
    $result['taxonomies'] = wordpress_content_terms($post);
    if (($input['include_content'] ?? true) === true) {
        $result['content'] = $post->post_content;
        $result['content_sha256'] = hash('sha256', $post->post_content);
    }
    if (($input['include_meta'] ?? false) === true) {
        $result['meta'] = wordpress_public_post_meta($post->ID);
    }
    return $result;
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function wordpress_search_content(array $input): array
{
    $requested = $input['post_types'] ?? get_post_types(['public' => true], output: 'names');
    $post_types = wordpress_valid_post_types($requested);
    $limit = min(100, max(1, (int) ($input['limit'] ?? 20)));
    $query = new WP_Query([
        'post_type' => $post_types,
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        's' => (string) $input['query'],
        'posts_per_page' => $limit,
        'orderby' => 'relevance',
        'order' => 'DESC',
        'no_found_rows' => false,
    ]);
    $items = [];
    foreach ($query->posts as $post) {
        if (!$post instanceof WP_Post) {
            continue;
        }
        $row = wordpress_content_summary($post);
        $plain = trim(wp_strip_all_tags(strip_shortcodes($post->post_content)));
        $row['excerpt'] = mb_substr($plain, start: 0, length: 320);
        $items[] = $row;
    }
    return ['query' => (string) $input['query'], 'items' => $items, 'total' => (int) $query->found_posts];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_get_page_snapshot(array $input): array|WP_Error
{
    $post = get_post((int) $input['post_id']);
    if (!$post instanceof WP_Post || !current_user_can('read_post', $post->ID)) {
        return new WP_Error('wppilot_content_not_found', __(
            'Content was not found or is not readable.',
            domain: 'wppilot',
        ));
    }

    $outline = [];
    $blocks = parse_blocks($post->post_content);
    wordpress_collect_block_outline($blocks, $outline);
    $plain = trim(wp_strip_all_tags(strip_shortcodes($post->post_content)));
    return [
        'identity' => wordpress_content_summary($post),
        'template' => (string) get_page_template_slug($post->ID),
        'content' => [
            'bytes' => strlen($post->post_content),
            'words' => str_word_count($plain),
            'sha256' => hash('sha256', $post->post_content),
            'plain_text_sha256' => hash('sha256', $plain),
        ],
        'blocks' => [
            'count' => count($outline),
            'outline' => array_slice(array: $outline, offset: 0, length: 250),
        ],
        'builder_signals' => wordpress_builder_signals($post->ID),
        'seo' => [
            'title' => wordpress_first_meta($post->ID, [
                '_yoast_wpseo_title',
                'rank_math_title',
                '_aioseo_title',
                '_seopress_titles_title',
                '_genesis_title',
                'slim_seo[title]',
                '_wds_title',
            ]),
            'description' => wordpress_first_meta($post->ID, [
                '_yoast_wpseo_metadesc',
                'rank_math_description',
                '_aioseo_description',
                '_seopress_titles_desc',
                '_genesis_description',
                'slim_seo[description]',
                '_wds_metadesc',
            ]),
        ],
    ];
}

/** @param mixed $requested @return list<string> */
function wordpress_valid_post_types(mixed $requested): array
{
    $values = is_array($requested) ? $requested : [$requested];
    $valid = [];
    foreach ($values as $value) {
        $post_type = is_string($value) ? sanitize_key($value) : '';
        if ($post_type !== '' && post_type_exists($post_type)) {
            $valid[] = $post_type;
        }
    }
    return array_values(array_unique($valid));
}

/** @param mixed $requested @return list<string> */
function wordpress_valid_post_statuses(mixed $requested): array
{
    $values = is_array($requested) && $requested !== []
        ? $requested
        : ['publish', 'draft', 'pending', 'private', 'future'];
    $valid = [];
    foreach ($values as $value) {
        $status = is_string($value) ? sanitize_key($value) : '';
        if ($status !== '' && get_post_status_object($status) !== null) {
            $valid[] = $status;
        }
    }
    return $valid !== [] ? array_values(array_unique($valid)) : ['publish'];
}

/** @return array<string, mixed> */
function wordpress_content_summary(WP_Post $post): array
{
    return [
        'id' => $post->ID,
        'type' => $post->post_type,
        'status' => $post->post_status,
        'title' => get_the_title($post),
        'slug' => $post->post_name,
        'author' => (int) $post->post_author,
        'created' => $post->post_date,
        'modified' => $post->post_modified,
        'permalink' => (string) get_permalink($post),
        'edit_url' => (string) get_edit_post_link($post->ID, context: 'raw'),
    ];
}

/** @return array<string, list<array{term_id: int, name: string, slug: string}>> */
function wordpress_content_terms(WP_Post $post): array
{
    $result = [];
    $taxonomies = get_object_taxonomies($post->post_type, output: 'names');
    if (!is_array($taxonomies)) {
        return $result;
    }
    foreach ($taxonomies as $taxonomy) {
        if (!is_string($taxonomy)) {
            continue;
        }
        $terms = wp_get_object_terms($post->ID, $taxonomy);
        if (is_wp_error($terms) || !is_array($terms) || $terms === []) {
            continue;
        }
        $summaries = [];
        foreach ($terms as $term) {
            if (!$term instanceof \WP_Term) {
                continue;
            }
            $summaries[] = [
                'term_id' => (int) $term->term_id,
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
            ];
        }
        if ($summaries !== []) {
            $result[$taxonomy] = $summaries;
        }
    }
    return $result;
}

/** @return array<string, mixed> */
function wordpress_public_post_meta(int $post_id): array
{
    $result = [];
    $metadata = get_post_meta($post_id);
    if (!is_array($metadata)) {
        return $result;
    }
    foreach ($metadata as $key => $values) {
        if (!is_string($key) || !is_array($values) || is_protected_meta($key, meta_type: 'post')) {
            continue;
        }
        $serialized = array_values(array_filter(array: $values, callback: 'is_string'));
        $result[$key] = count($serialized) === 1
            ? maybe_unserialize($serialized[0])
            : array_map('maybe_unserialize', $serialized);
    }
    return $result;
}

/** @param array<array-key, mixed> $blocks @param list<array{name: string, client_id: string, depth: int}> $outline */
function wordpress_collect_block_outline(array $blocks, array &$outline, int $depth = 0): void
{
    foreach ($blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : 'core/freeform';
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $outline[] = [
            'name' => $name,
            'client_id' => is_string($attrs['clientId'] ?? null) ? $attrs['clientId'] : '',
            'depth' => $depth,
        ];
        /** @var array<array-key, mixed> $inner */
        $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
        wordpress_collect_block_outline($inner, $outline, $depth + 1);
    }
}

/** @return array<string, bool> */
function wordpress_builder_signals(int $post_id): array
{
    return [
        'gutenberg_blocks' => has_blocks($post_id),
        'elementor' => get_post_meta($post_id, key: '_elementor_edit_mode', single: true) === 'builder',
        'bricks' => get_post_meta($post_id, key: '_bricks_editor_mode', single: true) !== '',
        'breakdance' => get_post_meta($post_id, key: '_breakdance_data', single: true) !== '',
        'beaver_builder' => get_post_meta($post_id, key: '_fl_builder_enabled', single: true) !== '',
        'divi' => str_contains((string) get_post_field('post_content', $post_id), '[et_pb_'),
    ];
}

/** @param list<string> $keys */
function wordpress_first_meta(int $post_id, array $keys): string
{
    foreach ($keys as $key) {
        // `base[field]` reads one field of an array meta value: Slim SEO keeps every per-post
        // setting in a single `slim_seo` array rather than a key each.
        if (preg_match('/^([^\[\]]+)\[([^\[\]]+)\]$/', $key, $match) === 1) {
            $row = get_post_meta($post_id, $match[1], single: true);
            $value = is_array($row) ? ($row[$match[2]] ?? '') : '';
        } else {
            $value = get_post_meta($post_id, $key, single: true);
        }
        if (is_scalar($value) && trim((string) $value) !== '') {
            return (string) $value;
        }
    }
    return '';
}
