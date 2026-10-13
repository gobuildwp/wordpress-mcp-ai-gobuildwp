<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\WordPress;

use WP_Error;

/**
 * Ability: Create a WordPress post (any post type).
 *
 * Thin wrapper over wp_insert_post so agents don't have to drop into
 * execute-php for the very mundane "make me a new page" operation.
 */

if (!defined('ABSPATH')) {
    exit();
}

register_core_ability('wppilot/create-post', [
    'label' => __('Create Post', domain: 'wppilot'),
    'description' => __(
        'Creates a WordPress post of any registered post type and returns its ID, permalink, and edit URL. Use this before opening a live builder editor or calling a builder-specific content ability. When Breakdance is active, non-empty `content` / `post_content` is gated and rejected unless the user explicitly confirms the raw WordPress write and the re-call sets `allow_raw_content_on_breakdance_post:true`; leave content empty and use `wppilot/breakdance-set-content` plus the element abilities for a Breakdance page. Accepts both short names (title, slug, status, content, excerpt, parent, author, date) and WordPress-native aliases (post_title, post_name, post_status, post_content, post_excerpt, post_parent, post_author, post_date).',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'title' => [
                'type' => 'string',
                'description' => 'Post title.',
            ],
            'slug' => [
                'type' => 'string',
                'description' => 'URL slug (post_name). Note: ignored in the rendered URL when the site uses plain permalinks.',
            ],
            'status' => [
                'type' => 'string',
                'enum' => ['publish', 'draft', 'pending', 'private', 'future'],
                'description' => 'Post status.',
                'default' => 'draft',
            ],
            'post_type' => [
                'type' => 'string',
                'description' => 'Registered post type.',
                'default' => 'page',
            ],
            'content' => [
                'type' => 'string',
                'description' => 'Initial post_content. Leave empty for builder-managed pages. When Breakdance is active, a non-empty value requires the explicit allow_raw_content_on_breakdance_post handshake. Proprietary builder markup is always rejected.',
                'default' => '',
            ],
            'allow_raw_content_on_breakdance_post' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Set true ONLY after the user has explicitly confirmed they want raw post_content while Breakdance is active; never set it on your own initiative.',
            ],
            'excerpt' => [
                'type' => 'string',
                'description' => 'Post excerpt.',
                'default' => '',
            ],
            'parent' => [
                'type' => 'integer',
                'description' => 'Parent post ID (for hierarchical post types).',
                'default' => 0,
            ],
            'menu_order' => [
                'type' => 'integer',
                'description' => 'Menu order (wp_insert_post `menu_order`, used by hierarchical post types and ordered lists).',
                'default' => 0,
            ],
            'author' => [
                'type' => 'integer',
                'description' => 'Author user ID. Defaults to the current user.',
            ],
            'date' => [
                'type' => 'string',
                'description' => 'Post date in site local time, format Y-m-d H:i:s. Required when status="future" to schedule the post at a specific time.',
            ],
            'meta' => [
                'type' => 'object',
                'description' => 'Map of meta_key => meta_value to set on the new post. Builder storage keys (_elementor_data, _bricks_page_content_2, _fl_builder_data, _et_pb_*, …) are REJECTED — use the builder-specific abilities instead.',
                'default' => [],
            ],
            'post_title' => [
                'type' => 'string',
                'description' => 'WordPress-native alias for title.',
            ],
            'post_name' => [
                'type' => 'string',
                'description' => 'WordPress-native alias for slug.',
            ],
            'post_status' => [
                'type' => 'string',
                'enum' => ['publish', 'draft', 'pending', 'private', 'future'],
                'description' => 'WordPress-native alias for status.',
            ],
            'post_content' => [
                'type' => 'string',
                'description' => 'WordPress-native alias for content; the same Breakdance raw-content confirmation gate applies.',
            ],
            'post_excerpt' => [
                'type' => 'string',
                'description' => 'WordPress-native alias for excerpt.',
            ],
            'post_parent' => [
                'type' => 'integer',
                'description' => 'WordPress-native alias for parent.',
            ],
            'post_author' => [
                'type' => 'integer',
                'description' => 'WordPress-native alias for author.',
            ],
            'post_date' => [
                'type' => 'string',
                'description' => 'WordPress-native alias for date.',
            ],
        ],
        'required' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'post_type' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'permalink' => ['type' => 'string'],
            'edit_url' => ['type' => 'string'],
            'permalink_style' => [
                'type' => 'string',
                'enum' => ['plain', 'pretty'],
                'description' => 'Whether the site uses plain (?p=ID) or pretty permalinks. When "plain", the returned permalink ignores the slug.',
            ],
            'warnings' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Non-fatal hints the caller should surface to the user.',
            ],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\wordpress_create_post',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Use this to create a new post/page before opening a live builder editor or populating the body with a builder-specific content ability. Leave content empty for builder-managed pages. On a Breakdance site, non-empty content is rejected with breakdance_post_content_needs_confirmation. Use wppilot/breakdance-set-content and the element abilities instead. If the user explicitly confirms they still want raw post_content, re-call with allow_raw_content_on_breakdance_post:true; never set that flag on your own initiative. A successful opt-in includes an audit warning. Do not synthesize builder storage metadata by hand. If permalink_style is "plain", warn the user that the slug is not reflected in the URL until they switch to pretty permalinks in Settings → Permalinks.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function wordpress_create_post(array $input): array|WP_Error
{
    // Accept WP-native field names as aliases for the short names.
    foreach ([
        'post_title' => 'title',
        'post_name' => 'slug',
        'post_status' => 'status',
        'post_content' => 'content',
        'post_excerpt' => 'excerpt',
        'post_parent' => 'parent',
        'post_author' => 'author',
        'post_date' => 'date',
    ] as $wp_name => $short_name) {
        if (array_key_exists($short_name, $input) || !array_key_exists($wp_name, $input)) {
            continue;
        }
        $input[$short_name] = $input[$wp_name];
    }

    if (!array_key_exists('title', $input)) {
        return new WP_Error('missing_title', 'title (or post_title) is required.');
    }

    $post_type = (string) ($input['post_type'] ?? 'page');

    if (!post_type_exists($post_type)) {
        return new WP_Error('invalid_post_type', sprintf('Post type "%s" is not registered.', $post_type));
    }

    $agent_facing = wordpress_post_type_is_agent_facing($post_type);
    if ($agent_facing !== null) {
        return $agent_facing;
    }

    // Draft-first: an absent, blank, or non-enumerated status is never a publish.
    // Resolved here rather than in wordpress_build_postarr() so the capability
    // gate below sees the same status that is about to be written.
    $input['status'] = wordpress_resolve_create_status($input['status'] ?? null);

    $capability_error = wordpress_create_capability_error($post_type, $input);
    if ($capability_error !== null) {
        return $capability_error;
    }

    $slug = array_key_exists('slug', $input) ? (string) $input['slug'] : null;

    // Builder-managed content and builder storage meta are never written
    // through this generic wrapper — reject before creating anything.
    $guard = wordpress_builder_write_guard($input);
    if ($guard !== null) {
        return $guard;
    }
    $breakdance_gate = wordpress_breakdance_content_gate($input);
    if ($breakdance_gate !== null) {
        return $breakdance_gate;
    }

    $postarr = wordpress_build_postarr($input, $post_type, $slug);
    /** @var array{post_title: string, post_type: string, post_status: string, post_content: string, post_excerpt: string, post_parent: int, post_name?: string, post_author?: int, meta_input?: array<string, mixed>} $postarr */
    $post_id = wp_insert_post($postarr, wp_error: true);

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    $permalink_style = get_option('permalink_structure') === '' ? 'plain' : 'pretty';

    $warnings = wordpress_breakdance_content_warnings($input);
    if ($permalink_style === 'plain' && $slug !== null && $slug !== '') {
        $warnings[] = 'Site uses plain permalinks: the slug is stored on the post but not reflected in the URL. Enable pretty permalinks in Settings → Permalinks to expose it.';
    }

    return [
        'post_id' => (int) $post_id,
        'post_type' => $post_type,
        'status' => (string) get_post_status($post_id),
        'permalink' => (string) get_permalink($post_id),
        'edit_url' => (string) get_edit_post_link($post_id, context: 'raw'),
        'permalink_style' => $permalink_style,
        'warnings' => $warnings,
    ];
}

/**
 * Build the wp_insert_post argument array from the ability input.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function wordpress_build_postarr(array $input, string $post_type, ?string $slug): array
{
    /** @var array<string, mixed> $meta */
    $meta = is_array($input['meta'] ?? null) ? $input['meta'] : [];

    // wp_insert_post() runs wp_unslash() on the data, which would strip
    // literal backslashes — e.g. the \uXXXX escapes in Gutenberg / Divi 5
    // block-attribute JSON. Slash the string fields so they're stored verbatim.
    $postarr = [
        'post_title' => wp_slash((string) $input['title']),
        'post_type' => $post_type,
        'post_status' => (string) ($input['status'] ?? 'draft'),
        'post_content' => wp_slash((string) ($input['content'] ?? '')),
        'post_excerpt' => wp_slash((string) ($input['excerpt'] ?? '')),
        'post_parent' => (int) ($input['parent'] ?? 0),
    ];

    if ($slug !== null) {
        $postarr['post_name'] = wp_slash($slug);
    }

    if (array_key_exists('menu_order', $input)) {
        $postarr['menu_order'] = (int) $input['menu_order'];
    }

    if (array_key_exists('author', $input)) {
        $postarr['post_author'] = (int) $input['author'];
    }

    if (array_key_exists('date', $input)) {
        $postarr['post_date'] = (string) $input['date'];
        // Force WP to recompute post_date_gmt from the new local date.
        $postarr['post_date_gmt'] = '0000-00-00 00:00:00';
    }

    if ($meta !== []) {
        // meta_input values flow into update_post_meta(), which unslashes too.
        $postarr['meta_input'] = wp_slash($meta);
    }

    return $postarr;
}
