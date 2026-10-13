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

register_core_ability('wppilot/list-revisions', [
    'label' => __('List Revisions', domain: 'wppilot'),
    'description' => __(
        'Lists the stored revisions of a post, newest first, marking which entries are autosaves rather than saved revisions. Returns the size of each revision\'s content and title so an agent can spot where a change happened without fetching every body.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'include_autosaves' => ['type' => 'boolean', 'default' => true],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_list_revisions',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/get-revision', [
    'label' => __('Get Revision', domain: 'wppilot'),
    'description' => __(
        'Returns one revision\'s title, content, and excerpt, alongside the parent post\'s current values so the two can be compared directly.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['revision_id' => ['type' => 'integer']],
        'required' => ['revision_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_get_revision',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/restore-revision', [
    'label' => __('Restore Revision', domain: 'wppilot'),
    'description' => __(
        'Restores a post to one of its revisions. This is a write to the live post: the current state is captured first, so the restore itself appears in the change ledger and can be rolled back.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['revision_id' => ['type' => 'integer']],
        'required' => ['revision_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_restore_revision',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: false, idempotent: false),
]);

/**
 * Load a revision and the post it belongs to, enforcing edit access to the parent.
 *
 * Authorization is answered by the parent post, never by the revision row: a
 * revision has no capability object of its own, and `edit_post` on a revision ID
 * does not evaluate the parent's rules.
 *
 * @return array{0: WP_Post, 1: WP_Post}|WP_Error Revision, then parent.
 */
function wordpress_require_revision(int $revision_id): array|WP_Error
{
    $revision = wp_get_post_revision($revision_id);
    if (!$revision instanceof WP_Post) {
        return new WP_Error(
            'revision_not_found',
            sprintf('Revision %d was not found.', $revision_id),
            ['status' => 404],
        );
    }

    $parent = get_post((int) $revision->post_parent);
    if (!$parent instanceof WP_Post) {
        return new WP_Error(
            'revision_parent_missing',
            sprintf('Revision %d has no readable parent post.', $revision_id),
            ['status' => 404],
        );
    }

    if (!current_user_can('edit_post', (int) $parent->ID)) {
        return new WP_Error(
            'cannot_edit_post',
            sprintf('You are not allowed to edit post %d.', (int) $parent->ID),
            ['status' => 403],
        );
    }

    return [$revision, $parent];
}

/**
 * Whether a revision row is an autosave rather than a saved revision.
 *
 * WordPress stores autosaves as revisions whose slug carries the `-autosave`
 * suffix. They are working copies, not history, and restoring one silently
 * would discard a saved revision the user believed was current.
 */
function wordpress_revision_is_autosave(WP_Post $revision): bool
{
    return str_contains((string) $revision->post_name, '-autosave');
}

/** @return array<string, mixed> */
function wordpress_revision_summary(WP_Post $revision): array
{
    return [
        'revision_id' => (int) $revision->ID,
        'post_id' => (int) $revision->post_parent,
        'author_id' => (int) $revision->post_author,
        'date_gmt' => (string) $revision->post_modified_gmt,
        'is_autosave' => wordpress_revision_is_autosave($revision),
        'title' => (string) $revision->post_title,
        'content_length' => strlen((string) $revision->post_content),
        'excerpt_length' => strlen((string) $revision->post_excerpt),
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_list_revisions(array $input): array|WP_Error
{
    $post_id = (int) $input['post_id'];
    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return new WP_Error('post_not_found', sprintf('Post %d was not found.', $post_id), ['status' => 404]);
    }
    if (!current_user_can('edit_post', $post_id)) {
        return new WP_Error(
            'cannot_edit_post',
            sprintf('You are not allowed to read revisions of post %d.', $post_id),
            ['status' => 403],
        );
    }

    $per_page = min(100, max(1, (int) ($input['per_page'] ?? 25)));
    $include_autosaves = ($input['include_autosaves'] ?? true) === true;

    $items = [];
    foreach (wp_get_post_revisions($post_id, ['posts_per_page' => $per_page]) as $revision) {
        if (!$revision instanceof WP_Post) {
            continue;
        }
        if (!$include_autosaves && wordpress_revision_is_autosave($revision)) {
            continue;
        }
        $items[] = wordpress_revision_summary($revision);
    }

    return [
        'post_id' => $post_id,
        'items' => $items,
        'count' => count($items),
        'revisions_enabled' => wp_revisions_enabled($post),
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_get_revision(array $input): array|WP_Error
{
    $loaded = wordpress_require_revision((int) $input['revision_id']);
    if ($loaded instanceof WP_Error) {
        return $loaded;
    }
    [$revision, $parent] = $loaded;

    return [
        'revision' => array_merge(wordpress_revision_summary($revision), [
            'content' => (string) $revision->post_content,
            'excerpt' => (string) $revision->post_excerpt,
        ]),
        'current' => [
            'post_id' => (int) $parent->ID,
            'title' => (string) $parent->post_title,
            'content' => (string) $parent->post_content,
            'excerpt' => (string) $parent->post_excerpt,
            'status' => (string) $parent->post_status,
        ],
        'differs' => [
            'title' => (string) $revision->post_title !== (string) $parent->post_title,
            'content' => (string) $revision->post_content !== (string) $parent->post_content,
            'excerpt' => (string) $revision->post_excerpt !== (string) $parent->post_excerpt,
        ],
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_restore_revision(array $input): array|WP_Error
{
    $revision_id = (int) $input['revision_id'];
    $loaded = wordpress_require_revision($revision_id);
    if ($loaded instanceof WP_Error) {
        return $loaded;
    }
    [$revision, $parent] = $loaded;

    $post_id = (int) $parent->ID;

    // wp_restore_post_revision() answers null on error, false when the revision
    // carried no restorable fields, and the post id on success. Only a positive
    // id is a restore that landed: reading false as success reported a revision
    // as restored while the post was never written.
    $restored = wp_restore_post_revision($revision_id);
    if (!is_int($restored) || $restored <= 0) {
        return new WP_Error(
            'restore_revision_failed',
            sprintf('Revision %1$d could not be restored onto post %2$d.', $revision_id, $post_id),
            ['status' => 500],
        );
    }

    $current = get_post($post_id);

    return [
        'post_id' => $post_id,
        'revision_id' => $revision_id,
        'was_autosave' => wordpress_revision_is_autosave($revision),
        'title' => $current instanceof WP_Post ? (string) $current->post_title : '',
        'status' => $current instanceof WP_Post ? (string) $current->post_status : '',
    ];
}
