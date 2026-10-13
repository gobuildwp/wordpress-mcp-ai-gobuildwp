<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\WordPress;

use WP_Error;
use WP_Post;

/**
 * Ability: Update a WordPress post (any post type).
 *
 * Thin wrapper over wp_update_post / update_post_meta / set_post_thumbnail
 * with partial-update semantics: only fields actually present in the input
 * are touched.
 */

if (!defined('ABSPATH')) {
    exit();
}

register_core_ability('wppilot/update-post', [
    'label' => __('Update Post', domain: 'wppilot'),
    'description' => __(
        'Updates an existing WordPress post of any post type with partial-update semantics. A non-empty `content` / `post_content` write to a Breakdance-owned post is gated and rejected unless the user explicitly confirms the raw WordPress write and the re-call sets `allow_raw_content_on_breakdance_post:true`; use `wppilot/breakdance-set-content` plus the element abilities for the native canvas. Use this ability for status, title/slug, featured-image, and ordinary-meta changes. Identifies the target via `post_id` (short alias: `id`). Accepts both short names (title, slug, status, content, excerpt, parent, author, date) and WordPress-native aliases (post_title, post_name, post_status, post_content, post_excerpt, post_parent, post_author, post_date).',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'The ID of the post to update. Short alias: `id`.',
            ],
            'id' => [
                'type' => 'integer',
                'description' => 'Short alias for `post_id`.',
            ],
            'title' => [
                'type' => 'string',
                'description' => 'New post title.',
            ],
            'slug' => [
                'type' => 'string',
                'description' => 'New URL slug (post_name).',
            ],
            'status' => [
                'type' => 'string',
                'enum' => ['publish', 'draft', 'pending', 'private', 'future'],
                'description' => 'New post status. Goes through wp_update_post so transition_post_status hooks fire (Elementor and other builders rely on these).',
            ],
            'content' => [
                'type' => 'string',
                'description' => 'New post_content. A non-empty value on a Breakdance-owned post requires the explicit allow_raw_content_on_breakdance_post handshake, and on a post owned by Elementor, Bricks or Beaver Builder the allow_raw_content_on_builder_post handshake. Proprietary builder markup is always rejected.',
            ],
            'allow_raw_content_on_breakdance_post' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Set true ONLY after the user has explicitly confirmed they want raw post_content on a Breakdance-owned post; never set it on your own initiative.',
            ],
            'allow_raw_content_on_builder_post' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Set true ONLY after the user has explicitly confirmed they want raw post_content on a post owned by Elementor, Bricks or Beaver Builder — where it changes nothing visitors see. Never set it on your own initiative; use that builder\'s own abilities to change the page.',
            ],
            'excerpt' => [
                'type' => 'string',
                'description' => 'New post excerpt.',
            ],
            'parent' => [
                'type' => 'integer',
                'description' => 'New parent post ID (for hierarchical post types). Pass 0 to clear.',
            ],
            'menu_order' => [
                'type' => 'integer',
                'description' => 'New menu order.',
            ],
            'author' => [
                'type' => 'integer',
                'description' => 'New author user ID.',
            ],
            'date' => [
                'type' => 'string',
                'description' => 'New post date in site local time, format Y-m-d H:i:s.',
            ],
            'meta' => [
                'type' => 'object',
                'description' => 'Map of ordinary meta_key => meta_value to upsert via update_post_meta. Only the keys you pass are touched. Builder storage keys (_elementor_data, _bricks_page_content_2, _fl_builder_data, _et_pb_*, …) are REJECTED — use the builder-specific abilities instead.',
            ],
            'featured_image_id' => [
                'type' => 'integer',
                'description' => 'Attachment ID to set as the featured image. Pass 0 to remove the current featured image.',
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
        'additionalProperties' => false,
        'anyOf' => [
            ['required' => ['post_id']],
            ['required' => ['id']],
        ],
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'post_type' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'permalink' => ['type' => 'string'],
            'edit_url' => ['type' => 'string'],
            'updated_fields' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Names of the fields that were actually changed by this call.',
            ],
            'warnings' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Non-fatal hints the caller should surface to the user.',
            ],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\wordpress_update_post',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Partial update — only fields you pass are touched. Common uses: flip a draft to publish (status="publish"), set a featured image (featured_image_id=<attachment id>), or set ordinary post meta. Content carrying proprietary builder markup and meta carrying builder storage keys are rejected. Non-empty post_content on a Breakdance-owned post is rejected with breakdance_post_content_needs_confirmation; use wppilot/breakdance-set-content and the element abilities instead. If the user explicitly confirms they still want raw post_content, re-call with allow_raw_content_on_breakdance_post:true; never set that flag on your own initiative. A successful opt-in includes an audit warning.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function wordpress_update_post(array $input): array|WP_Error
{
    // Accept `id` as a short alias for `post_id` so first-try `{id, status}`
    // calls don't bounce off a "post_id is required" schema error.
    if (!array_key_exists('post_id', $input) && array_key_exists('id', $input)) {
        $input['post_id'] = $input['id'];
    }

    if (!array_key_exists('post_id', $input)) {
        return new WP_Error('missing_id', 'One of `post_id` or `id` is required.');
    }

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

    $post_id = (int) $input['post_id'];

    /** @var WP_Post|null $post */
    $post = get_post($post_id);

    if (!$post) {
        return new WP_Error('not_found', sprintf('Post %d not found.', $post_id));
    }

    $agent_facing = wordpress_post_type_is_agent_facing((string) $post->post_type);
    if ($agent_facing !== null) {
        return $agent_facing;
    }

    // Object-level: `edit_post` resolves the post type's capability object and
    // the post's ownership together, so an Author editing someone else's post is
    // refused here rather than at a blanket type-level check.
    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error(
            'cannot_edit_post',
            sprintf('You are not allowed to edit post %d.', $post_id),
            ['status' => 403],
        );
    }

    // A status change is a separate grant from an edit: moving a draft to
    // publish must satisfy the type's publish capability even when the caller
    // may otherwise edit the post freely.
    $status_error = wordpress_update_status_capability_error($post, $input);
    if ($status_error !== null) {
        return $status_error;
    }

    // Builder-managed content and builder storage meta are never written
    // through this generic wrapper — reject before touching anything.
    $guard = wordpress_builder_write_guard($input);
    if ($guard !== null) {
        return $guard;
    }
    $breakdance_gate = wordpress_breakdance_content_gate($input, $post_id);
    if ($breakdance_gate !== null) {
        return $breakdance_gate;
    }
    // Elementor, Bricks and Beaver Builder leave post_content looking ordinary,
    // so the marker guard above cannot see them. Without this the write is
    // accepted, stored, and invisible on the page.
    $builder_gate = wordpress_postmeta_builder_content_gate($input, $post_id);
    if ($builder_gate !== null) {
        return $builder_gate;
    }

    $updated_fields = [];

    $core_error = wordpress_update_post_core_fields($post_id, $input, $updated_fields);
    if ($core_error !== null) {
        return $core_error;
    }

    $thumb_error = wordpress_update_post_thumbnail($post_id, $input, $updated_fields);
    if ($thumb_error !== null) {
        return $thumb_error;
    }

    wordpress_update_post_meta_fields($post_id, $input, $updated_fields);
    $warnings = [
        ...wordpress_breakdance_content_warnings($input, $post_id),
        ...wordpress_postmeta_builder_content_warnings($input, $post_id),
    ];

    return [
        'post_id' => $post_id,
        'post_type' => (string) get_post_type($post_id),
        'status' => (string) get_post_status($post_id),
        'permalink' => (string) get_permalink($post_id),
        'edit_url' => (string) get_edit_post_link($post_id, context: 'raw'),
        'updated_fields' => $updated_fields,
        'warnings' => $warnings,
    ];
}

/**
 * Apply the wp_update_post fields from the ability input.
 *
 * @param array<string, mixed> $input
 * @param list<string> $updated_fields
 */
function wordpress_update_post_core_fields(int $post_id, array $input, array &$updated_fields): ?WP_Error
{
    $string_fields = [
        'title' => 'post_title',
        'slug' => 'post_name',
        'status' => 'post_status',
        'content' => 'post_content',
        'excerpt' => 'post_excerpt',
        'date' => 'post_date',
    ];
    $int_fields = [
        'parent' => 'post_parent',
        'menu_order' => 'menu_order',
        'author' => 'post_author',
    ];

    $postarr = ['ID' => $post_id];

    foreach ($string_fields as $input_key => $wp_key) {
        if (!array_key_exists($input_key, $input)) {
            continue;
        }
        // wp_update_post() runs wp_unslash() on the data, which would strip
        // literal backslashes — e.g. the \uXXXX escapes in Gutenberg / Divi 5
        // block-attribute JSON. Slash so the value is stored verbatim.
        $postarr[$wp_key] = wp_slash((string) $input[$input_key]);
        $updated_fields[] = $input_key;
    }

    foreach ($int_fields as $input_key => $wp_key) {
        if (!array_key_exists($input_key, $input)) {
            continue;
        }
        $postarr[$wp_key] = (int) $input[$input_key];
        $updated_fields[] = $input_key;
    }

    if (array_key_exists('date', $input)) {
        // Force WP to recompute post_date_gmt from the new local date.
        $postarr['post_date_gmt'] = '0000-00-00 00:00:00';
    }

    if (count($postarr) === 1) {
        return null;
    }

    $result = wp_update_post($postarr, wp_error: true);
    if (is_wp_error($result)) {
        return $result;
    }

    return null;
}

/**
 * Apply the featured_image_id from the ability input.
 *
 * @param array<string, mixed> $input
 * @param list<string> $updated_fields
 */
function wordpress_update_post_thumbnail(int $post_id, array $input, array &$updated_fields): ?WP_Error
{
    if (!array_key_exists('featured_image_id', $input)) {
        return null;
    }

    $thumb_id = (int) $input['featured_image_id'];

    if ($thumb_id === 0) {
        delete_post_thumbnail($post_id);
        $updated_fields[] = 'featured_image_id';
        return null;
    }

    /** @var WP_Post|null $attachment */
    $attachment = get_post($thumb_id);
    if (!$attachment || $attachment->post_type !== 'attachment') {
        return new WP_Error('invalid_attachment', sprintf('Attachment %d not found.', $thumb_id));
    }

    // set_post_thumbnail() answers false when the attachment is already the
    // thumbnail, because update_post_meta() reports no change. That is the
    // requested state, not a failure.
    if (set_post_thumbnail($post_id, $thumb_id) === false && (int) get_post_thumbnail_id($post_id) !== $thumb_id) {
        return new WP_Error('thumbnail_failed', 'Failed to set featured image.');
    }

    $updated_fields[] = 'featured_image_id';
    return null;
}

/**
 * Upsert post meta from the ability input.
 *
 * @param array<string, mixed> $input
 * @param list<string> $updated_fields
 */
function wordpress_update_post_meta_fields(int $post_id, array $input, array &$updated_fields): void
{
    if (!array_key_exists('meta', $input) || !is_array($input['meta'])) {
        return;
    }

    /** @var array<string, mixed> $meta */
    $meta = $input['meta'];
    if ($meta === []) {
        return;
    }

    /** @var mixed $meta_value */
    foreach ($meta as $meta_key => $meta_value) {
        // update_post_meta() also runs wp_unslash() on its value; slash
        // strings/arrays so literal backslashes survive the round-trip.
        if (is_string($meta_value) || is_array($meta_value)) {
            /** @var mixed $meta_value */
            $meta_value = wp_slash($meta_value);
        }
        update_post_meta($post_id, $meta_key, $meta_value);
    }
    $updated_fields[] = 'meta';
}
