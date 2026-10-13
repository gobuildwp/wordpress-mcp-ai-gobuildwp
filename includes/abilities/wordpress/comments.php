<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\WordPress;

use WP_Comment;
use WP_Error;
use WP_Post;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Moderation actions, mapped to the WordPress call that performs each one.
 *
 * @var list<string>
 */
const WPPILOT_COMMENT_ACTIONS = ['approve', 'hold', 'spam', 'unspam', 'trash', 'untrash'];

register_core_ability('wppilot/list-comments', [
    'label' => __('List Comments', domain: 'wppilot'),
    'description' => __(
        'Lists comments, newest first, filtered by post, author, type, search term, or moderation status. Commenter email and IP address are omitted unless the connected account may moderate comments.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'status' => [
                'type' => 'string',
                'enum' => ['all', 'approve', 'hold', 'spam', 'trash'],
                'default' => 'all',
            ],
            'type' => [
                'type' => 'string',
                'description' => 'Comment type, e.g. "comment" or "pingback". Omit for all types.',
            ],
            'author_email' => ['type' => 'string', 'description' => 'Requires moderate_comments.'],
            'search' => ['type' => 'string'],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_list_comments',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/get-comment', [
    'label' => __('Get Comment', domain: 'wppilot'),
    'description' => __(
        'Returns one comment with its content, author display name, status, parent, and post. Email and IP are included only for accounts that may moderate comments.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['comment_id' => ['type' => 'integer']],
        'required' => ['comment_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_get_comment',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/create-comment', [
    'label' => __('Create Comment', domain: 'wppilot'),
    'description' => __(
        'Posts a comment, or a reply when parent_id is supplied. Refused when the target post has comments closed. The comment is attributed to the connected account; a different author may only be named by an account that can moderate comments.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'content' => ['type' => 'string', 'minLength' => 1],
            'parent_id' => ['type' => 'integer', 'description' => 'Comment being replied to.'],
            'author_name' => ['type' => 'string', 'description' => 'Requires moderate_comments.'],
            'author_email' => ['type' => 'string', 'description' => 'Requires moderate_comments.'],
            'status' => [
                'type' => 'string',
                'enum' => ['approve', 'hold'],
                'default' => 'hold',
                'description' => 'Held for moderation unless explicitly approved. Approving requires moderate_comments.',
            ],
        ],
        'required' => ['post_id', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_create_comment',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: false, idempotent: false),
]);

register_core_ability('wppilot/update-comment', [
    'label' => __('Update Comment', domain: 'wppilot'),
    'description' => __(
        'Edits a comment\'s content or author display name. Requires edit access to that specific comment.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'comment_id' => ['type' => 'integer'],
            'content' => ['type' => 'string', 'minLength' => 1],
            'author_name' => ['type' => 'string'],
        ],
        'required' => ['comment_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_update_comment',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_core_permission',
    'meta' => wordpress_core_mcp_meta(readonly: false),
]);

register_core_ability('wppilot/moderate-comment', [
    'label' => __('Moderate Comment', domain: 'wppilot'),
    'description' => __(
        'Approves, holds, marks as spam, unspams, trashes, or restores a comment. Every action is reversible and is recorded in the change ledger with the previous status. Requires moderate_comments.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'comment_id' => ['type' => 'integer'],
            'action' => ['type' => 'string', 'enum' => WPPILOT_COMMENT_ACTIONS],
        ],
        'required' => ['comment_id', 'action'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_moderate_comment',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('moderate_comments'),
    'meta' => wordpress_core_mcp_meta(readonly: false),
]);

register_core_ability('wppilot/delete-comment', [
    'label' => __('Delete Comment', domain: 'wppilot'),
    'description' => __(
        'Permanently deletes a comment, bypassing the trash. This cannot be undone and requires explicit confirmation. To reversibly remove a comment, use wppilot/moderate-comment with action "trash" instead.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'comment_id' => ['type' => 'integer'],
            'confirm' => [
                'type' => 'boolean',
                'description' => 'Must be true. Permanent deletion is not rollbackable.',
            ],
        ],
        'required' => ['comment_id', 'confirm'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_delete_comment',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('moderate_comments'),
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true, idempotent: false),
]);

/**
 * Whether the connected account may see commenter contact details.
 *
 * Email and IP are personal data that a comment reader has no need for. They are
 * released only to accounts that can act on them, which is the same bar
 * WordPress applies on the comment moderation screen.
 */
function wordpress_can_see_commenter_contact(): bool
{
    return current_user_can('moderate_comments');
}

/**
 * Shape a comment for output, withholding personal data by default.
 *
 * @return array<string, mixed>
 */
function wordpress_comment_summary(WP_Comment $comment): array
{
    $summary = [
        'comment_id' => (int) $comment->comment_ID,
        'post_id' => (int) $comment->comment_post_ID,
        'parent_id' => (int) $comment->comment_parent,
        'author_name' => (string) $comment->comment_author,
        'author_user_id' => (int) $comment->user_id,
        'content' => (string) $comment->comment_content,
        'status' => (string) wp_get_comment_status($comment),
        'type' => (string) $comment->comment_type,
        'date_gmt' => (string) $comment->comment_date_gmt,
        'link' => (string) get_comment_link($comment),
    ];

    if (wordpress_can_see_commenter_contact()) {
        $summary['author_email'] = (string) $comment->comment_author_email;
        $summary['author_ip'] = (string) $comment->comment_author_IP;
        $summary['author_url'] = (string) $comment->comment_author_url;
    }

    return $summary;
}

/**
 * Load a comment, or explain why it is unavailable.
 */
function wordpress_require_comment(int $comment_id): WP_Comment|WP_Error
{
    $comment = get_comment($comment_id);

    return $comment instanceof WP_Comment
        ? $comment
        : new WP_Error('comment_not_found', sprintf('Comment %d was not found.', $comment_id), ['status' => 404]);
}

/**
 * Whether the connected account may read a comment that is not publicly visible.
 *
 * An unapproved, spammed, or trashed comment is not public, so reading it needs
 * either moderation rights or edit access to the comment itself.
 */
function wordpress_comment_read_error(WP_Comment $comment): ?WP_Error
{
    if (wp_get_comment_status($comment) === 'approved') {
        return null;
    }
    if (current_user_can('moderate_comments') || current_user_can('edit_comment', (int) $comment->comment_ID)) {
        return null;
    }

    return new WP_Error(
        'cannot_read_comment',
        sprintf('Comment %d is not public and you are not allowed to read it.', (int) $comment->comment_ID),
        ['status' => 403],
    );
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_list_comments(array $input): array|WP_Error
{
    $status = (string) ($input['status'] ?? 'all');

    // Anything other than the approved set is non-public data.
    if ($status !== 'approve' && !current_user_can('moderate_comments')) {
        return new WP_Error(
            'cannot_list_unapproved_comments',
            'Listing comments that are not approved requires the moderate_comments capability. Pass status: "approve" to list public comments.',
            ['status' => 403],
        );
    }
    if (isset($input['author_email']) && !current_user_can('moderate_comments')) {
        return new WP_Error(
            'cannot_filter_by_email',
            'Filtering by commenter email requires the moderate_comments capability.',
            ['status' => 403],
        );
    }

    $per_page = min(100, max(1, (int) ($input['per_page'] ?? 25)));
    $page = max(1, (int) ($input['page'] ?? 1));

    $args = [
        'number' => $per_page,
        'paged' => $page,
        'orderby' => 'comment_date_gmt',
        'order' => 'DESC',
        'status' => $status,
    ];
    foreach (['post_id' => 'post_id', 'type' => 'type', 'search' => 'search'] as $key => $arg) {
        if (isset($input[$key]) && (string) $input[$key] !== '') {
            $args[$arg] = $key === 'post_id' ? (int) $input[$key] : (string) $input[$key];
        }
    }
    if (isset($input['author_email'])) {
        $args['author_email'] = (string) $input['author_email'];
    }

    $items = [];
    foreach (get_comments($args) as $comment) {
        if ($comment instanceof WP_Comment) {
            $items[] = wordpress_comment_summary($comment);
        }
    }

    return ['items' => $items, 'count' => count($items), 'page' => $page, 'per_page' => $per_page];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_get_comment(array $input): array|WP_Error
{
    $comment = wordpress_require_comment((int) $input['comment_id']);
    if ($comment instanceof WP_Error) {
        return $comment;
    }
    $readable = wordpress_comment_read_error($comment);

    return $readable ?? wordpress_comment_summary($comment);
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_create_comment(array $input): array|WP_Error
{
    $post_id = (int) $input['post_id'];
    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return new WP_Error('post_not_found', sprintf('Post %d was not found.', $post_id), ['status' => 404]);
    }
    if (!comments_open($post_id)) {
        return new WP_Error(
            'comments_closed',
            sprintf('Comments are closed on post %d.', $post_id),
            ['status' => 403],
        );
    }

    $moderator = current_user_can('moderate_comments');
    foreach (['author_name', 'author_email'] as $field) {
        if (isset($input[$field]) && !$moderator) {
            return new WP_Error(
                'cannot_set_comment_author',
                'Naming a different comment author requires the moderate_comments capability.',
                ['status' => 403],
            );
        }
    }

    $status = (string) ($input['status'] ?? 'hold');
    if ($status === 'approve' && !$moderator) {
        return new WP_Error(
            'cannot_approve_comment',
            'Approving a comment requires the moderate_comments capability. Omit status to leave it held for moderation.',
            ['status' => 403],
        );
    }

    if (isset($input['parent_id']) && (int) $input['parent_id'] > 0) {
        $parent = wordpress_require_comment((int) $input['parent_id']);
        if ($parent instanceof WP_Error) {
            return $parent;
        }
        if ((int) $parent->comment_post_ID !== $post_id) {
            return new WP_Error(
                'comment_parent_mismatch',
                sprintf('Comment %1$d is not on post %2$d.', (int) $parent->comment_ID, $post_id),
                ['status' => 422],
            );
        }
    }

    $user = wp_get_current_user();
    // wp_insert_comment()/wp_update_comment() unslash internally, so unslashed
    // input arrives one round of backslash-stripping short.
    $commentarr = [
        'comment_post_ID' => $post_id,
        'comment_content' => wp_slash((string) $input['content']),
        'comment_parent' => (int) ($input['parent_id'] ?? 0),
        'comment_type' => 'comment',
        'comment_approved' => $status === 'approve' ? 1 : 0,
        'user_id' => (int) $user->ID,
        'comment_author' => wp_slash((string) ($input['author_name'] ?? $user->display_name)),
        'comment_author_email' => wp_slash((string) ($input['author_email'] ?? $user->user_email)),
    ];

    $comment_id = wp_insert_comment($commentarr);
    if (!is_int($comment_id) || $comment_id <= 0) {
        return new WP_Error('create_comment_failed', 'The comment could not be created.', ['status' => 500]);
    }

    $created = get_comment($comment_id);

    return $created instanceof WP_Comment
        ? wordpress_comment_summary($created)
        : ['comment_id' => $comment_id, 'post_id' => $post_id];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_update_comment(array $input): array|WP_Error
{
    $comment_id = (int) $input['comment_id'];
    $comment = wordpress_require_comment($comment_id);
    if ($comment instanceof WP_Error) {
        return $comment;
    }
    if (!current_user_can('edit_comment', $comment_id)) {
        return new WP_Error(
            'cannot_edit_comment',
            sprintf('You are not allowed to edit comment %d.', $comment_id),
            ['status' => 403],
        );
    }

    $commentarr = ['comment_ID' => $comment_id];
    if (isset($input['content'])) {
        $commentarr['comment_content'] = wp_slash((string) $input['content']);
    }
    if (isset($input['author_name'])) {
        $commentarr['comment_author'] = wp_slash((string) $input['author_name']);
    }
    if (count($commentarr) === 1) {
        return new WP_Error('nothing_to_update', 'Supply at least one field to change.', ['status' => 422]);
    }

    $result = wp_update_comment($commentarr, wp_error: true);
    if (is_wp_error($result)) {
        return $result;
    }

    $updated = get_comment($comment_id);

    return $updated instanceof WP_Comment ? wordpress_comment_summary($updated) : ['comment_id' => $comment_id];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_moderate_comment(array $input): array|WP_Error
{
    $comment_id = (int) $input['comment_id'];
    $action = (string) $input['action'];

    if (!in_array($action, WPPILOT_COMMENT_ACTIONS, strict: true)) {
        return new WP_Error('invalid_action', sprintf('Unknown moderation action "%s".', $action), ['status' => 422]);
    }

    $comment = wordpress_require_comment($comment_id);
    if ($comment instanceof WP_Error) {
        return $comment;
    }
    if (!current_user_can('moderate_comments')) {
        return new WP_Error(
            'cannot_moderate_comments',
            'Moderating comments requires the moderate_comments capability.',
            ['status' => 403],
        );
    }

    $previous = (string) wp_get_comment_status($comment);

    $result = match ($action) {
        'approve' => wp_set_comment_status($comment_id, 'approve'),
        'hold' => wp_set_comment_status($comment_id, 'hold'),
        'spam' => wp_spam_comment($comment_id),
        'unspam' => wp_unspam_comment($comment_id),
        'trash' => wp_trash_comment($comment_id),
        'untrash' => wp_untrash_comment($comment_id),
        default => false,
    };

    if ($result !== true) {
        return new WP_Error(
            'moderate_comment_failed',
            sprintf('Action "%1$s" on comment %2$d did not succeed.', $action, $comment_id),
            ['status' => 500],
        );
    }

    $refreshed = get_comment($comment_id);

    return [
        'comment_id' => $comment_id,
        'action' => $action,
        'previous_status' => $previous,
        'status' => $refreshed instanceof WP_Comment ? (string) wp_get_comment_status($refreshed) : 'unknown',
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_delete_comment(array $input): array|WP_Error
{
    if (($input['confirm'] ?? false) !== true) {
        return new WP_Error(
            'confirmation_required',
            'Permanent deletion cannot be rolled back. Use wppilot/moderate-comment with action "trash" for a reversible removal, or re-call with confirm: true only after the user has explicitly agreed.',
            ['status' => 422],
        );
    }

    $comment_id = (int) $input['comment_id'];
    $comment = wordpress_require_comment($comment_id);
    if ($comment instanceof WP_Error) {
        return $comment;
    }
    if (!current_user_can('moderate_comments')) {
        return new WP_Error(
            'cannot_moderate_comments',
            'Deleting comments requires the moderate_comments capability.',
            ['status' => 403],
        );
    }

    if (wp_delete_comment($comment_id, force_delete: true) !== true) {
        return new WP_Error(
            'delete_comment_failed',
            sprintf('Comment %d could not be deleted.', $comment_id),
            ['status' => 500],
        );
    }

    return ['comment_id' => $comment_id, 'result' => 'deleted', 'reversible' => false];
}
