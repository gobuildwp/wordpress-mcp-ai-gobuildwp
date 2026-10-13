<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BlockNotes;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The WordPress calls behind Notes, so the review loop's rules are tested apart from them.
 *
 * A note is a comment of type `note` on the post. A top-level note is a thread: open while its
 * comment_approved is '0' (the REST API's `hold`), resolved at '1' (`approve`). Replies are notes
 * whose parent is the thread. Resolving or reopening also adds an empty-content child note whose
 * `_wp_note_status` meta is `resolved` or `reopen`: the thread's history. That is what the block
 * editor in WordPress 7.1 does (editor.js useNoteActions), and doing the same keeps an agent's
 * actions readable in the editor's sidebar.
 */
interface Store
{
    /** @return array{id: int, type: string, status: string, content: string, author: int}|null */
    public function post(int $id): ?array;

    public function can_edit(int $post_id): bool;

    /**
     * Whether this WordPress stores a block's notes the way this kit writes them: a list under
     * metadata.noteId, as the 7.1 editor reads and writes it. Earlier releases are read, but an
     * anchor written for them is unverified, so the kit does not write one there.
     */
    public function writes_supported(): bool;

    /** Whether the post type turns Notes on (its `editor` support carries `notes`). */
    public function supports_notes(string $post_type): bool;

    /**
     * Every note on a post, any status but trash and spam, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function notes(int $post_id): array;

    /**
     * One note, or null.
     *
     * @return array{id: int, post_id: int, parent: int, content: string, status: string, type: string, author_id: int, author_name: string, date_gmt: string, note_status: string, by_agent: bool}|null
     */
    public function note(int $id): ?array;

    /** @return list<int> The IDs of a note's children, any status. */
    public function children(int $id): array;

    /**
     * Insert a note as the current user, marked as written by an agent.
     *
     * @param array{post_id: int, parent: int, content: string, approved: string, note_status?: string} $note
     */
    public function insert(array $note): int|WP_Error;

    /** '0' (open) or '1' (resolved). */
    public function set_status(int $id, string $approved): bool;

    /** Delete permanently; notes are never trashed by this kit, so an undo leaves nothing behind. */
    public function delete(int $id): bool;

    public function update_content(int $post_id, string $content): bool|WP_Error;

    /** The display name of whoever holds the post's edit lock, when it is someone else. */
    public function lock_holder(int $post_id): string;

    /** Send the post author the notification the site sends for new notes, if it does. */
    public function notify(int $note_id): void;
}
