<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BlockNotes;

use WP_Comment;
use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Notes through WordPress's comment API, the way the REST comments controller writes them.
 */
final class WpStore implements Store
{
    public function post(int $id): ?array
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post) {
            return null;
        }
        return [
            'id' => (int) $post->ID,
            'type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'content' => (string) $post->post_content,
            'author' => (int) $post->post_author,
        ];
    }

    public function can_edit(int $post_id): bool
    {
        return current_user_can('edit_post', $post_id);
    }

    public function writes_supported(): bool
    {
        return version_compare((string) get_bloginfo('version'), '7.1-alpha', '>=');
    }

    public function supports_notes(string $post_type): bool
    {
        // As WP_REST_Comments_Controller::check_post_type_supports_notes() decides it.
        $supports = get_all_post_type_supports($post_type);
        if (!isset($supports['editor']) || !is_array($supports['editor'])) {
            return false;
        }
        foreach ($supports['editor'] as $item) {
            if (is_array($item) && !empty($item['notes'])) {
                return true;
            }
        }
        return false;
    }

    public function notes(int $post_id): array
    {
        $comments = get_comments([
            'post_id' => $post_id,
            'type' => 'note',
            'status' => 'all',
            'orderby' => 'comment_date_gmt',
            'order' => 'ASC',
            'number' => 500,
            'update_comment_meta_cache' => true,
        ]);
        $notes = [];
        foreach (is_array($comments) ? $comments : [] as $comment) {
            if ($comment instanceof WP_Comment) {
                $notes[] = $this->shape($comment);
            }
        }
        return $notes;
    }

    public function note(int $id): ?array
    {
        $comment = $id > 0 ? get_comment($id) : null;
        return $comment instanceof WP_Comment && $comment->comment_type === 'note' ? $this->shape($comment) : null;
    }

    public function children(int $id): array
    {
        $ids = get_comments(['parent' => $id, 'type' => 'note', 'status' => 'all', 'fields' => 'ids']);
        return array_map('intval', is_array($ids) ? $ids : []);
    }

    public function insert(array $note): int|WP_Error
    {
        $user = wp_get_current_user();
        $data = [
            'comment_post_ID' => $note['post_id'],
            // Basic inline HTML only, whoever the account is: the note is rendered for other
            // editors, and an administrator's unfiltered_html should not pass through an agent.
            'comment_content' => wp_kses_data($note['content']),
            'comment_type' => 'note',
            'comment_parent' => $note['parent'],
            'comment_approved' => $note['approved'],
            'user_id' => (int) $user->ID,
            // Shown as the note's author in the editor sidebar, so a person can tell at a glance
            // that an agent wrote it, not the colleague whose account it runs under.
            'comment_author' => trim((string) $user->display_name) . ' (AI agent)',
            'comment_author_email' => (string) $user->user_email,
            'comment_author_url' => '',
            // wp_filter_comment() reads every one of these keys; a missing one is a PHP warning.
            'comment_author_IP' => '',
            'comment_agent' => Runtime\host()->id() . ' AI agent (block-notes kit)',
            'comment_date_gmt' => current_time('mysql', true),
        ];
        // The REST controller's own sequence: slash, run the comment filters (kses for users
        // without unfiltered_html), then insert, which unslashes.
        $id = wp_insert_comment(wp_filter_comment(wp_slash($data)));
        if (!is_int($id) || $id <= 0) {
            return new WP_Error('kit_block_notes_insert_failed', 'WordPress did not create the note.', ['status' => 500]);
        }
        add_comment_meta($id, META_AGENT, wp_slash(Runtime\host()->id()), true);
        if (($note['note_status'] ?? '') !== '') {
            add_comment_meta($id, '_wp_note_status', wp_slash((string) $note['note_status']), true);
        }
        return $id;
    }

    public function set_status(int $id, string $approved): bool
    {
        return wp_set_comment_status($id, $approved === '1' ? 'approve' : 'hold') === true;
    }

    public function delete(int $id): bool
    {
        return (bool) wp_delete_comment($id, true);
    }

    public function update_content(int $post_id, string $content): bool|WP_Error
    {
        // kit-lint: slashed — post_content is wp_slash()ed in the array itself.
        $updated = wp_update_post(['ID' => $post_id, 'post_content' => wp_slash($content)], true);
        return $updated instanceof WP_Error ? $updated : true;
    }

    public function lock_holder(int $post_id): string
    {
        /** @var mixed $lock */
        $lock = get_post_meta($post_id, '_edit_lock', true);
        $parts = is_string($lock) ? explode(':', $lock) : [];
        $time = (int) ($parts[0] ?? 0);
        $user = (int) ($parts[1] ?? 0);
        // wp_check_post_lock() lives in wp-admin and is not loaded for REST or MCP requests.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's post-lock window filter, read the way wp_check_post_lock() reads it.
        $window = (int) apply_filters('wp_check_post_lock_window', 150);
        if ($user <= 0 || $user === get_current_user_id() || $time <= time() - $window) {
            return '';
        }
        $holder = get_userdata($user);
        return $holder instanceof \WP_User ? (string) $holder->display_name : 'another user';
    }

    public function notify(int $note_id): void
    {
        if (function_exists('wp_new_comment_notify_postauthor')) {
            wp_new_comment_notify_postauthor($note_id);
        }
    }

    /**
     * @return array{id: int, post_id: int, parent: int, content: string, status: string, type: string, author_id: int, author_name: string, date_gmt: string, note_status: string, by_agent: bool}
     */
    private function shape(WP_Comment $comment): array
    {
        $id = (int) $comment->comment_ID;
        /** @var mixed $note_status */
        $note_status = get_comment_meta($id, '_wp_note_status', true);
        return [
            'id' => $id,
            'post_id' => (int) $comment->comment_post_ID,
            'parent' => (int) $comment->comment_parent,
            'content' => (string) $comment->comment_content,
            'status' => (string) $comment->comment_approved,
            'type' => (string) $comment->comment_type,
            'author_id' => (int) $comment->user_id,
            'author_name' => (string) $comment->comment_author,
            'date_gmt' => (string) $comment->comment_date_gmt,
            'note_status' => is_string($note_status) ? $note_status : '',
            'by_agent' => metadata_exists('comment', $id, META_AGENT),
        ];
    }
}
