<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\WordPress;

use WP_Error;
use WP_Post;

/**
 * Ability: Delete a WordPress post (any post type).
 */

if (!defined('ABSPATH')) {
    exit();
}

register_core_ability('wppilot/delete-post', [
    'label' => __('Delete Post', domain: 'wppilot'),
    'description' => __(
        'Deletes a WordPress post of any post type. By default moves it to the trash; set force=true to bypass the trash and delete permanently. Identifies the target via `post_id` (short alias: `id`).',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'description' => 'The ID of the post to delete. Short alias: `id`.',
            ],
            'id' => [
                'type' => 'integer',
                'description' => 'Short alias for `post_id`.',
            ],
            'force' => [
                'type' => 'boolean',
                'description' => 'When true, permanently deletes the post (bypassing the trash). When false, moves it to the trash if the post type supports trashing.',
                'default' => false,
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
            'previous_status' => ['type' => 'string'],
            'result' => [
                'type' => 'string',
                'enum' => ['trashed', 'deleted'],
                'description' => '"trashed" when the post was moved to trash, "deleted" when it was permanently removed.',
            ],
        ],
    ],
    'execute_callback' => __NAMESPACE__ . '\wordpress_delete_post',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Default behavior moves the post to the trash (reversible). Pass force=true only when the user explicitly asks for a permanent delete. Note: if the post is already in the trash, a non-force call will permanently delete it — WordPress\' standard trash behavior.',
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function wordpress_delete_post(array $input): array|WP_Error
{
    // Accept `id` as a short alias for `post_id` for symmetry with update-post.
    if (!array_key_exists('post_id', $input) && array_key_exists('id', $input)) {
        $input['post_id'] = $input['id'];
    }

    if (!array_key_exists('post_id', $input)) {
        return new WP_Error('missing_id', 'One of `post_id` or `id` is required.');
    }

    $post_id = (int) $input['post_id'];
    $force = ($input['force'] ?? false) === true;

    /** @var WP_Post|null $post */
    $post = get_post($post_id);

    if (!$post) {
        return new WP_Error('not_found', sprintf('Post %d not found.', $post_id));
    }

    $agent_facing = wordpress_post_type_is_agent_facing((string) $post->post_type);
    if ($agent_facing !== null) {
        return $agent_facing;
    }

    // Object-level, not type-level: WordPress maps `delete_post` through the
    // post type's own capability object and additionally decides ownership, so
    // an Author may delete their own draft but not someone else's.
    if (!current_user_can('delete_post', $post_id)) {
        return new WP_Error(
            'cannot_delete_post',
            sprintf('You are not allowed to delete post %d.', $post_id),
            ['status' => 403],
        );
    }

    $previous_status = $post->post_status;
    $post_type = $post->post_type;

    $result = $force ? wp_delete_post($post_id, force_delete: true) : wp_trash_post($post_id);

    if (!$result) {
        return new WP_Error('delete_failed', sprintf('Failed to %s post %d.', $force ? 'delete' : 'trash', $post_id));
    }

    return [
        'post_id' => $post_id,
        'post_type' => $post_type,
        'previous_status' => $previous_status,
        'result' => $force || $previous_status === 'trash' ? 'deleted' : 'trashed',
    ];
}
