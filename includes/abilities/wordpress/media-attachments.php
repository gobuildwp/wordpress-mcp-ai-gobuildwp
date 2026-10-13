<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\WordPress;

use WP_Error;
use WP_Post;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Relationships between media and content.
 *
 * media.php owns the attachments themselves (list, get, import, update, delete).
 * This file owns how they attach to posts: the featured image, and the
 * post_parent link that drives "uploaded to this post" in the media library.
 */

register_core_ability('wppilot/set-featured-image', [
    'label' => __('Set Featured Image', domain: 'wppilot'),
    'description' => __(
        'Sets a post\'s featured image (post thumbnail) to an existing attachment. The attachment must be an image and the post type must support thumbnails.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'attachment_id' => ['type' => 'integer'],
        ],
        'required' => ['post_id', 'attachment_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_set_featured_image',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: false),
]);

register_core_ability('wppilot/remove-featured-image', [
    'label' => __('Remove Featured Image', domain: 'wppilot'),
    'description' => __(
        'Clears a post\'s featured image. The attachment itself is not deleted and stays in the media library.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['post_id' => ['type' => 'integer']],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_remove_featured_image',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: false),
]);

register_core_ability('wppilot/attach-media', [
    'label' => __('Attach Media', domain: 'wppilot'),
    'description' => __(
        'Links an attachment to a post, which is what the media library shows as "uploaded to" that post. This does not place the media in the post\'s content and does not set a featured image.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer'],
            'post_id' => ['type' => 'integer'],
        ],
        'required' => ['attachment_id', 'post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_attach_media',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: false),
]);

register_core_ability('wppilot/detach-media', [
    'label' => __('Detach Media', domain: 'wppilot'),
    'description' => __(
        'Unlinks an attachment from its parent post, leaving it unattached in the media library. The file is not deleted.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['attachment_id' => ['type' => 'integer']],
        'required' => ['attachment_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_detach_media',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: false),
]);

/**
 * Load an attachment and prove it really is one.
 *
 * A post ID passed where an attachment ID belongs would otherwise be silently
 * accepted by the meta write and produce a featured image pointing at a page.
 */
function wordpress_require_attachment(int $attachment_id): WP_Post|WP_Error
{
    $attachment = get_post($attachment_id);

    if (!$attachment instanceof WP_Post || (string) $attachment->post_type !== 'attachment') {
        return new WP_Error(
            'attachment_not_found',
            sprintf('Attachment %d was not found.', $attachment_id),
            ['status' => 404],
        );
    }

    return $attachment;
}

/**
 * Load a post that the connected account may edit, refusing agent-closed types.
 */
function wordpress_require_editable_post(int $post_id): WP_Post|WP_Error
{
    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return new WP_Error('post_not_found', sprintf('Post %d was not found.', $post_id), ['status' => 404]);
    }

    $gate = wordpress_post_type_is_agent_facing((string) $post->post_type);
    if ($gate !== null) {
        return $gate;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error(
            'cannot_edit_post',
            sprintf('You are not allowed to edit post %d.', $post_id),
            ['status' => 403],
        );
    }

    return $post;
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_set_featured_image(array $input): array|WP_Error
{
    $post_id = (int) $input['post_id'];
    $attachment_id = (int) $input['attachment_id'];

    $post = wordpress_require_editable_post($post_id);
    if ($post instanceof WP_Error) {
        return $post;
    }

    $attachment = wordpress_require_attachment($attachment_id);
    if ($attachment instanceof WP_Error) {
        return $attachment;
    }

    if (!wp_attachment_is_image($attachment_id)) {
        return new WP_Error(
            'attachment_not_image',
            sprintf(
                'Attachment %1$d is a %2$s, not an image, so it cannot be a featured image.',
                $attachment_id,
                (string) get_post_mime_type($attachment_id),
            ),
            ['status' => 422],
        );
    }

    if (!post_type_supports((string) $post->post_type, 'thumbnail')) {
        return new WP_Error(
            'thumbnail_not_supported',
            sprintf('Post type "%s" does not support featured images.', (string) $post->post_type),
            ['status' => 422],
        );
    }

    $previous = (int) get_post_thumbnail_id($post_id);

    // set_post_thumbnail() answers false when the attachment is already the
    // thumbnail, because update_post_meta() reports no change. This ability is
    // annotated idempotent, so re-setting the same image must succeed.
    if (set_post_thumbnail($post_id, $attachment_id) === false && $previous !== $attachment_id) {
        return new WP_Error(
            'set_featured_image_failed',
            sprintf('The featured image of post %d could not be set.', $post_id),
            ['status' => 500],
        );
    }

    return [
        'post_id' => $post_id,
        'attachment_id' => $attachment_id,
        'previous_attachment_id' => $previous,
        'thumbnail_url' => (string) wp_get_attachment_image_url($attachment_id, size: 'full'),
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_remove_featured_image(array $input): array|WP_Error
{
    $post_id = (int) $input['post_id'];

    $post = wordpress_require_editable_post($post_id);
    if ($post instanceof WP_Error) {
        return $post;
    }

    $previous = (int) get_post_thumbnail_id($post_id);
    if ($previous === 0) {
        return ['post_id' => $post_id, 'previous_attachment_id' => 0, 'result' => 'no_featured_image'];
    }

    if (delete_post_thumbnail($post_id) === false) {
        return new WP_Error(
            'remove_featured_image_failed',
            sprintf('The featured image of post %d could not be removed.', $post_id),
            ['status' => 500],
        );
    }

    return ['post_id' => $post_id, 'previous_attachment_id' => $previous, 'result' => 'removed'];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_attach_media(array $input): array|WP_Error
{
    $attachment_id = (int) $input['attachment_id'];
    $post_id = (int) $input['post_id'];

    $attachment = wordpress_require_attachment($attachment_id);
    if ($attachment instanceof WP_Error) {
        return $attachment;
    }
    if (!current_user_can('edit_post', $attachment_id)) {
        return new WP_Error(
            'cannot_edit_attachment',
            sprintf('You are not allowed to edit attachment %d.', $attachment_id),
            ['status' => 403],
        );
    }

    $post = wordpress_require_editable_post($post_id);
    if ($post instanceof WP_Error) {
        return $post;
    }

    $previous = (int) $attachment->post_parent;

    $result = wp_update_post(['ID' => $attachment_id, 'post_parent' => $post_id], wp_error: true);
    if (is_wp_error($result)) {
        return $result;
    }

    return ['attachment_id' => $attachment_id, 'post_id' => $post_id, 'previous_post_id' => $previous];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_detach_media(array $input): array|WP_Error
{
    $attachment_id = (int) $input['attachment_id'];

    $attachment = wordpress_require_attachment($attachment_id);
    if ($attachment instanceof WP_Error) {
        return $attachment;
    }
    if (!current_user_can('edit_post', $attachment_id)) {
        return new WP_Error(
            'cannot_edit_attachment',
            sprintf('You are not allowed to edit attachment %d.', $attachment_id),
            ['status' => 403],
        );
    }

    $previous = (int) $attachment->post_parent;
    if ($previous === 0) {
        return ['attachment_id' => $attachment_id, 'previous_post_id' => 0, 'result' => 'already_unattached'];
    }

    $result = wp_update_post(['ID' => $attachment_id, 'post_parent' => 0], wp_error: true);
    if (is_wp_error($result)) {
        return $result;
    }

    return ['attachment_id' => $attachment_id, 'previous_post_id' => $previous, 'result' => 'detached'];
}
