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

register_core_ability('wppilot/restore-post', [
    'label' => __('Restore Post', domain: 'wppilot'),
    'description' => __(
        'Restores a trashed post, page, or custom post type back out of the trash. WordPress returns it to the status it held before it was trashed, which is usually draft rather than published — the response names the status it actually landed on.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'id' => ['type' => 'integer', 'description' => 'Short alias for post_id.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'post_type' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'permalink' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_restore_post',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: false),
]);

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_restore_post(array $input): array|WP_Error
{
    $post_id = (int) ($input['post_id'] ?? $input['id'] ?? 0);
    if ($post_id <= 0) {
        return new WP_Error('missing_post_id', 'post_id (or id) is required.', ['status' => 422]);
    }

    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return new WP_Error('post_not_found', sprintf('Post %d was not found.', $post_id), ['status' => 404]);
    }

    $gate = wordpress_post_type_is_agent_facing((string) $post->post_type);
    if ($gate !== null) {
        return $gate;
    }

    // Untrashing is an edit of the target post, so it is the post's own
    // capability object that decides — not a blanket administrator check.
    if (!current_user_can('delete_post', $post_id) || !current_user_can('edit_post', $post_id)) {
        return new WP_Error(
            'cannot_restore_post',
            sprintf('You are not allowed to restore post %d.', $post_id),
            ['status' => 403],
        );
    }

    if ((string) $post->post_status !== 'trash') {
        return new WP_Error(
            'post_not_trashed',
            sprintf(
                'Post %1$d is not in the trash (its status is "%2$s"), so there is nothing to restore.',
                $post_id,
                (string) $post->post_status,
            ),
            ['status' => 422],
        );
    }

    $restored = wp_untrash_post($post_id);
    if (!$restored instanceof WP_Post) {
        return new WP_Error(
            'restore_post_failed',
            sprintf('Post %d could not be restored from the trash.', $post_id),
            ['status' => 500],
        );
    }

    $status = (string) get_post_status($post_id);

    return [
        'post_id' => $post_id,
        'post_type' => (string) $restored->post_type,
        'status' => $status,
        'permalink' => (string) get_permalink($post_id),
        'warnings' => $status === 'draft'
            ? ['WordPress restored this post as a draft. Publish it explicitly if it should be live again.']
            : [],
    ];
}
