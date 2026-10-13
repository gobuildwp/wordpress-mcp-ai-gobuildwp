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

register_core_ability('wppilot/list-media', [
    'label' => __('List Media', domain: 'wppilot'),
    'description' => __(
        'Lists Media Library attachments with bounded pagination, filename/title search, MIME filtering, dimensions, alt text, URLs, and parent content.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'search' => ['type' => 'string', 'default' => ''],
            'mime_type' => ['type' => 'string', 'default' => ''],
            'parent' => ['type' => 'integer'],
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_list_media',
    'permission_callback' => static fn(): bool => current_user_can('upload_files'),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/get-media', [
    'label' => __('Get Media', domain: 'wppilot'),
    'description' => __(
        'Returns one attachment with file metadata, generated sizes, alt text, caption, description, parent, and edit URL.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['attachment_id' => ['type' => 'integer', 'minimum' => 1]],
        'required' => ['attachment_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_get_media',
    'permission_callback' => static fn(): bool => current_user_can('upload_files'),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/import-media-url', [
    'label' => __('Import Media from URL', domain: 'wppilot'),
    'description' => __(
        'Safely downloads an HTTP(S) asset through WordPress, validates the resulting upload, creates a Media Library attachment, and optionally sets title, caption, alt text, and a parent post.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'url' => ['type' => 'string', 'format' => 'uri'],
            'title' => ['type' => 'string'],
            'caption' => ['type' => 'string'],
            'alt' => ['type' => 'string'],
            'parent' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
        ],
        'required' => ['url'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_import_media_url',
    'permission_callback' => static fn(): bool => current_user_can('upload_files'),
    'meta' => wordpress_core_mcp_meta(readonly: false, idempotent: false),
]);

register_core_ability('wppilot/update-media', [
    'label' => __('Update Media', domain: 'wppilot'),
    'description' => __(
        'Partially updates an attachment title, caption, description, alt text, or parent without replacing the underlying file.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer', 'minimum' => 1],
            'title' => ['type' => 'string'],
            'caption' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'alt' => ['type' => 'string'],
            'parent' => ['type' => 'integer', 'minimum' => 0],
        ],
        'required' => ['attachment_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_update_media',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_media_mutation_permission',
    'meta' => wordpress_core_mcp_meta(readonly: false),
]);

register_core_ability('wppilot/delete-media', [
    'label' => __('Delete Media', domain: 'wppilot'),
    'description' => __(
        'Permanently deletes a Media Library attachment and its generated files. This is irreversible and requires explicit confirmation through GoBuildWP safety enforcement.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['attachment_id' => ['type' => 'integer', 'minimum' => 1]],
        'required' => ['attachment_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_delete_media',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_media_mutation_permission',
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true, idempotent: false),
]);

/** @param array<string, mixed> $input */
function wordpress_media_mutation_permission(array $input): bool
{
    $attachment_id = (int) ($input['attachment_id'] ?? 0);
    return current_user_can('upload_files') && ($attachment_id <= 0 || current_user_can('delete_post', $attachment_id));
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function wordpress_list_media(array $input): array
{
    $per_page = min(100, max(1, (int) ($input['per_page'] ?? 20)));
    $page = max(1, (int) ($input['page'] ?? 1));
    $args = [
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        's' => (string) ($input['search'] ?? ''),
        'posts_per_page' => $per_page,
        'paged' => $page,
        'orderby' => 'date',
        'order' => 'DESC',
        'no_found_rows' => false,
    ];
    if (array_key_exists('parent', $input)) {
        $args['post_parent'] = (int) $input['parent'];
    }
    if (is_string($input['mime_type'] ?? null) && trim($input['mime_type']) !== '') {
        $args['post_mime_type'] = sanitize_mime_type($input['mime_type']);
    }
    $query = new WP_Query($args);
    $items = [];
    foreach ($query->posts as $post) {
        if ($post instanceof WP_Post) {
            $items[] = wordpress_media_summary($post);
        }
    }
    return [
        'items' => $items,
        'page' => $page,
        'per_page' => $per_page,
        'total' => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_get_media(array $input): array|WP_Error
{
    $attachment = get_post((int) $input['attachment_id']);
    if (!$attachment instanceof WP_Post || $attachment->post_type !== 'attachment') {
        return new WP_Error('wppilot_media_not_found', __('Media attachment not found.', domain: 'wppilot'));
    }
    $result = wordpress_media_summary($attachment);
    $result['description'] = $attachment->post_content;
    $metadata = wp_get_attachment_metadata($attachment->ID);
    $result['metadata'] = $metadata !== false ? $metadata : [];
    return $result;
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_import_media_url(array $input): array|WP_Error
{
    $url = esc_url_raw((string) $input['url']);
    if ($url === '' || !wp_http_validate_url($url)) {
        return new WP_Error('wppilot_invalid_media_url', __(
            'A safe, public HTTP(S) URL is required.',
            domain: 'wppilot',
        ));
    }
    // These are WordPress core files, not GoBuildWP's. The middle one was
    // 'wppilot-media.php' — a rebrand find-and-replace that renamed a core
    // include path — so every call to this ability died on a require_once
    // before it reached the download.
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $temporary = download_url($url, timeout: 30);
    if (is_wp_error($temporary)) {
        return $temporary;
    }
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    $filename = sanitize_file_name(basename($path));
    if ($filename === '') {
        $filename = 'wppilot-import';
    }
    try {
        $attachment_id = media_handle_sideload(
            ['name' => $filename, 'tmp_name' => $temporary],
            (int) ($input['parent'] ?? 0),
            is_string($input['title'] ?? null) ? $input['title'] : '',
        );
    } finally {
        if (is_file($temporary)) {
            wp_delete_file($temporary);
        }
    }
    if (is_wp_error($attachment_id)) {
        return $attachment_id;
    }
    if (array_key_exists('caption', $input)) {
        wp_update_post(['ID' => (int) $attachment_id, 'post_excerpt' => wp_slash((string) $input['caption'])]);
    }
    if (array_key_exists('alt', $input)) {
        update_post_meta(
            (int) $attachment_id,
            meta_key: '_wp_attachment_image_alt',
            meta_value: wp_slash((string) $input['alt']),
        );
    }
    $attachment = get_post((int) $attachment_id);
    return $attachment instanceof WP_Post ? wordpress_media_summary($attachment) : ['id' => (int) $attachment_id];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_update_media(array $input): array|WP_Error
{
    $attachment_id = (int) $input['attachment_id'];
    $attachment = get_post($attachment_id);
    if (!$attachment instanceof WP_Post || $attachment->post_type !== 'attachment') {
        return new WP_Error('wppilot_media_not_found', __('Media attachment not found.', domain: 'wppilot'));
    }
    /** @var array{ID: int, post_title?: string, post_excerpt?: string, post_content?: string, post_parent?: int} $postarr */
    $postarr = ['ID' => $attachment_id];
    if (array_key_exists('title', $input)) {
        $postarr['post_title'] = wp_slash((string) $input['title']);
    }
    if (array_key_exists('caption', $input)) {
        $postarr['post_excerpt'] = wp_slash((string) $input['caption']);
    }
    if (array_key_exists('description', $input)) {
        $postarr['post_content'] = wp_slash((string) $input['description']);
    }
    if (array_key_exists('parent', $input)) {
        $postarr['post_parent'] = (int) $input['parent'];
    }
    if (count($postarr) > 1) {
        $updated = wp_update_post($postarr, wp_error: true);
        if (is_wp_error($updated)) {
            return $updated;
        }
    }
    if (array_key_exists('alt', $input)) {
        update_post_meta($attachment_id, meta_key: '_wp_attachment_image_alt', meta_value: wp_slash((string) $input['alt']));
    }
    $updated_attachment = get_post($attachment_id);
    return (
        $updated_attachment instanceof WP_Post ? wordpress_media_summary($updated_attachment) : ['id' => $attachment_id]
    );
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_delete_media(array $input): array|WP_Error
{
    $attachment_id = (int) $input['attachment_id'];
    $attachment = get_post($attachment_id);
    if (!$attachment instanceof WP_Post || $attachment->post_type !== 'attachment') {
        return new WP_Error('wppilot_media_not_found', __('Media attachment not found.', domain: 'wppilot'));
    }
    $file = (string) get_attached_file($attachment_id);
    if (wp_delete_attachment($attachment_id, force_delete: true) === false) {
        return new WP_Error('wppilot_media_delete_failed', __(
            'WordPress could not delete the attachment.',
            domain: 'wppilot',
        ));
    }
    return ['attachment_id' => $attachment_id, 'deleted' => true, 'former_file' => $file];
}

/** @return array<string, mixed> */
function wordpress_media_summary(WP_Post $attachment): array
{
    $metadata = wp_get_attachment_metadata($attachment->ID);
    return [
        'id' => $attachment->ID,
        'title' => get_the_title($attachment),
        'filename' => basename((string) get_attached_file($attachment->ID)),
        'mime_type' => $attachment->post_mime_type,
        'url' => (string) wp_get_attachment_url($attachment->ID),
        'alt' => (string) get_post_meta($attachment->ID, key: '_wp_attachment_image_alt', single: true),
        'caption' => $attachment->post_excerpt,
        'parent' => (int) $attachment->post_parent,
        'width' => is_array($metadata) ? (int) ($metadata['width'] ?? 0) : 0,
        'height' => is_array($metadata) ? (int) ($metadata['height'] ?? 0) : 0,
        'created' => $attachment->post_date,
        'edit_url' => (string) get_edit_post_link($attachment->ID, context: 'raw'),
    ];
}
