<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BlockNotes;

use WP_Error;
use WPPilot\Kits\Runtime\Ledger;

if (!defined('ABSPATH')) {
    exit();
}

/** Comment meta marking a note an agent wrote; its value is the host that wrote it. */
const META_AGENT = '_wppilot_kit_note_agent';

/** Undo for a note or reply this kit created: delete it (and its block anchor). */
const STRATEGY_CREATED = 'kits/block-note';

/** Undo for a resolve: reopen the thread and remove the resolution marker. */
const STRATEGY_RESOLVED = 'kits/block-note-resolution';

const MAX_CONTENT = 5000;

/**
 * The store the abilities use. Replaceable so tests can hand in a fake.
 */
function store(?Store $set = null): Store
{
    /** @var Store|null $store */
    static $store = null;
    if ($set !== null) {
        $store = $set;
    }
    return $store ??= new WpStore();
}

/**
 * wppilot/list-block-notes.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function list_notes(array $input): array|WP_Error
{
    $store = store();
    $post = editable_post($store, (int) ($input['post_id'] ?? 0));
    if ($post instanceof WP_Error) {
        return $post;
    }
    $want = (string) ($input['status'] ?? 'all');
    $outline = Blocks::outline($post['content']);
    $anchors = [];
    foreach ($outline as $block) {
        foreach ($block['note_ids'] as $id) {
            $anchors[$id] ??= block_ref($block);
        }
    }

    $threads = [];
    $children = [];
    foreach ($store->notes($post['id']) as $note) {
        if ((int) $note['parent'] === 0) {
            $threads[(int) $note['id']] = $note;
        } else {
            $children[(int) $note['parent']][] = $note;
        }
    }

    $listed = [];
    $counts = ['open' => 0, 'resolved' => 0];
    foreach ($threads as $id => $note) {
        $status = $note['status'] === '1' ? 'resolved' : 'open';
        $counts[$status]++;
        if ($want !== 'all' && $want !== $status) {
            continue;
        }
        $replies = [];
        $history = [];
        foreach ($children[$id] ?? [] as $child) {
            if (in_array($child['note_status'], ['resolved', 'reopen'], true)) {
                $history[] = [
                    'event' => $child['note_status'] === 'resolved' ? 'resolved' : 'reopened',
                    'author' => author($child),
                    'date_gmt' => $child['date_gmt'],
                    'comment' => $child['content'],
                ];
                continue;
            }
            $replies[] = [
                'id' => (int) $child['id'],
                'content' => $child['content'],
                'author' => author($child),
                'date_gmt' => $child['date_gmt'],
            ];
        }
        $listed[] = [
            'id' => $id,
            'status' => $status,
            'content' => $note['content'],
            'author' => author($note),
            'date_gmt' => $note['date_gmt'],
            // null: the block that carried the note was deleted or edited without its anchor;
            // the editor lists such notes apart from any block.
            'block' => $anchors[$id] ?? null,
            'inline' => Blocks::has_inline_marker($post['content'], $id),
            'replies' => $replies,
            'history' => $history,
        ];
    }

    $result = [
        'post_id' => $post['id'],
        'notes' => $listed,
        'counts' => $counts,
    ];
    if (($input['include_blocks'] ?? false) === true) {
        $result['blocks'] = array_map('WPPilot\\Kits\\BlockNotes\\block_ref', $outline);
    }
    return $result;
}

/**
 * wppilot/add-block-note.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function add_note(array $input): array|WP_Error
{
    $store = store();
    $post = editable_post($store, (int) ($input['post_id'] ?? 0));
    if ($post instanceof WP_Error) {
        return $post;
    }
    if (!$store->writes_supported()) {
        return new WP_Error(
            'kit_block_notes_needs_71',
            'Attaching a note to a block is verified against WordPress 7.1\'s editor only; this site runs an earlier release. Notes can still be read.',
            ['status' => 501],
        );
    }
    $content = note_content($input);
    if ($content instanceof WP_Error) {
        return $content;
    }
    $block = Blocks::find(Blocks::outline($post['content']), (string) ($input['block_path'] ?? ''), (string) ($input['block_anchor'] ?? ''));
    if ($block instanceof WP_Error) {
        return $block;
    }
    $expected = (string) ($input['block_name'] ?? '');
    if ($expected !== '' && $expected !== $block['name'] && 'core/' . $expected !== $block['name']) {
        return new WP_Error(
            'kit_block_notes_block_changed',
            sprintf('The block at that position is %s, not %s; the content has changed since you read it.', $block['name'], $expected),
            ['status' => 409],
        );
    }

    $note_id = $store->insert(['post_id' => $post['id'], 'parent' => 0, 'content' => $content, 'approved' => '0']);
    if ($note_id instanceof WP_Error) {
        return $note_id;
    }
    $updated = $store->update_content($post['id'], Blocks::add_note_id($post['content'], $block, $note_id));
    $saved = $store->post($post['id']);
    $anchored = !$updated instanceof WP_Error && $saved !== null && Blocks::referencing($saved['content'], $note_id) !== [];
    if (!$anchored) {
        // A note with no block is shown apart from the content, which is not what was asked
        // for; take it back rather than leave it half made.
        $store->delete($note_id);
        return new WP_Error(
            'kit_block_notes_anchor_failed',
            'The note could not be attached to the block, so it was not kept.' . ($updated instanceof WP_Error ? ' ' . $updated->get_error_message() : ''),
            ['status' => 500],
        );
    }
    $store->notify($note_id);

    $result = [
        'note_id' => $note_id,
        'post_id' => $post['id'],
        'status' => 'open',
        'block' => block_ref($block),
        'by_agent' => true,
    ];
    $holder = $store->lock_holder($post['id']);
    if ($holder !== '') {
        $result['warning'] = sprintf(
            '%s has this post open in the editor. If they save without reloading, their copy of the content drops the note\'s block anchor and the note shows apart from any block.',
            $holder,
        );
    }
    return $result;
}

/**
 * wppilot/reply-block-note.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function reply_note(array $input): array|WP_Error
{
    $store = store();
    $thread = thread($store, (int) ($input['note_id'] ?? 0));
    if ($thread instanceof WP_Error) {
        return $thread;
    }
    $content = note_content($input);
    if ($content instanceof WP_Error) {
        return $content;
    }
    // Replies carry the thread's status, as the editor's do, so a reply never reopens or
    // resolves a thread by itself.
    $reply_id = $store->insert(['post_id' => $thread['post_id'], 'parent' => $thread['id'], 'content' => $content, 'approved' => $thread['status'] === '1' ? '1' : '0']);
    if ($reply_id instanceof WP_Error) {
        return $reply_id;
    }
    $store->notify($reply_id);
    return [
        'note_id' => $reply_id,
        'thread_id' => $thread['id'],
        'post_id' => $thread['post_id'],
        'thread_status' => $thread['status'] === '1' ? 'resolved' : 'open',
        'by_agent' => true,
    ];
}

/**
 * wppilot/resolve-block-note.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function resolve_note(array $input): array|WP_Error
{
    $store = store();
    $thread = thread($store, (int) ($input['note_id'] ?? 0));
    if ($thread instanceof WP_Error) {
        return $thread;
    }
    if ($thread['status'] === '1') {
        return ['note_id' => $thread['id'], 'post_id' => $thread['post_id'], 'status' => 'resolved', 'changed' => false, 'resolution_id' => 0];
    }
    $comment = '';
    if (isset($input['content']) && trim((string) $input['content']) !== '') {
        $comment = note_content($input);
        if ($comment instanceof WP_Error) {
            return $comment;
        }
    }
    if (!$store->set_status($thread['id'], '1')) {
        return new WP_Error('kit_block_notes_resolve_failed', 'WordPress did not change the note\'s status.', ['status' => 500]);
    }
    $marker = $store->insert(['post_id' => $thread['post_id'], 'parent' => $thread['id'], 'content' => $comment, 'approved' => '1', 'note_status' => 'resolved']);
    if ($marker instanceof WP_Error) {
        // Leave nothing half done: a resolved thread with no resolution entry reads, in the
        // editor, as resolved by nobody.
        $store->set_status($thread['id'], '0');
        return $marker;
    }
    return ['note_id' => $thread['id'], 'post_id' => $thread['post_id'], 'status' => 'resolved', 'changed' => true, 'resolution_id' => $marker, 'previous_status' => 'open'];
}

/**
 * Undo a created note or reply: delete it, and for a thread take its ID off the block.
 *
 * Refused while the thread has replies: deleting a thread moves its replies up to top level
 * (wp_delete_comment() re-parents children), and they are someone else's words.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|WP_Error
 */
function undo_created(array $payload): array|WP_Error
{
    $store = store();
    $note_id = (int) ($payload['note_id'] ?? 0);
    $post_id = (int) ($payload['post_id'] ?? 0);
    if ($note_id <= 0 || $post_id <= 0) {
        return new WP_Error('kit_block_notes_undo_payload', 'The change record does not name the note it created.');
    }
    $note = $store->note($note_id);
    if ($note !== null) {
        if ($store->children($note_id) !== []) {
            return new WP_Error(
                'kit_block_notes_has_replies',
                'Someone has replied to this note since. Undoing it would detach their replies, so it was not deleted; remove the thread in the editor if that is intended.',
                ['status' => 409],
            );
        }
        if (!$store->delete($note_id)) {
            return new WP_Error('kit_block_notes_undo_failed', 'WordPress did not delete the note.');
        }
    }
    $post = $store->post($post_id);
    if ($post !== null && Blocks::referencing($post['content'], $note_id) !== []) {
        $updated = $store->update_content($post_id, Blocks::remove_note_id($post['content'], $note_id));
        if ($updated instanceof WP_Error) {
            return $updated;
        }
        $post = $store->post($post_id);
    }
    $gone = $store->note($note_id) === null;
    $unanchored = $post === null || Blocks::referencing($post['content'], $note_id) === [];
    return [
        'note_id' => $note_id,
        'post_id' => $post_id,
        'note_deleted' => $gone,
        'anchor_removed' => $unanchored,
        'verified' => $gone && $unanchored,
    ];
}

/**
 * Undo a resolve: the thread open again and the resolution marker gone.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|WP_Error
 */
function undo_resolved(array $payload): array|WP_Error
{
    $store = store();
    $note_id = (int) ($payload['note_id'] ?? 0);
    $marker = (int) ($payload['resolution_id'] ?? 0);
    if ($store->note($note_id) === null) {
        return new WP_Error('kit_rollback_target_missing', 'The note this change resolved no longer exists.');
    }
    if (!$store->set_status($note_id, '0') && ($store->note($note_id)['status'] ?? '') !== '0') {
        return new WP_Error('kit_block_notes_undo_failed', 'WordPress did not reopen the note.');
    }
    if ($marker > 0 && $store->note($marker) !== null) {
        $store->delete($marker);
    }
    $open = ($store->note($note_id)['status'] ?? '') === '0';
    $marker_gone = $marker <= 0 || $store->note($marker) === null;
    return [
        'note_id' => $note_id,
        'reopened' => $open,
        'resolution_removed' => $marker_gone,
        'verified' => $open && $marker_gone,
    ];
}

/**
 * Register both undo strategies and the before-images that route each write to one.
 */
function register_ledger(Ledger $ledger): void
{
    $ledger->register_strategy(
        STRATEGY_CREATED,
        static fn(array $payload): array|WP_Error => undo_created($payload),
        static function (array $before, mixed $result): array {
            if (!is_array($result) || (int) ($result['note_id'] ?? 0) <= 0) {
                return ['reversible' => false, 'reason' => 'No note was created.'];
            }
            return ['note_id' => (int) $result['note_id'], 'post_id' => (int) ($result['post_id'] ?? 0)];
        },
    );
    $ledger->register_strategy(
        STRATEGY_RESOLVED,
        static fn(array $payload): array|WP_Error => undo_resolved($payload),
        static function (array $before, mixed $result): array {
            if (!is_array($result) || ($result['changed'] ?? false) !== true) {
                return ['reversible' => false, 'reason' => 'The note was already resolved, so nothing changed.'];
            }
            return ['note_id' => (int) $result['note_id'], 'resolution_id' => (int) ($result['resolution_id'] ?? 0)];
        },
    );
    $created = static fn(array $input): array => ['type' => STRATEGY_CREATED];
    $ledger->capture_for('wppilot/add-block-note', $created);
    $ledger->capture_for('wppilot/reply-block-note', $created);
    $ledger->capture_for('wppilot/resolve-block-note', static fn(array $input): array => ['type' => STRATEGY_RESOLVED]);
}

/**
 * Whether the current user may work with notes on the post a note or post ID points at. Used
 * as the permission callback, so a refusal happens before any write.
 *
 * @param array<string, mixed> $input
 */
function can_note(array $input): bool
{
    $store = store();
    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0 && (int) ($input['note_id'] ?? 0) > 0) {
        $post_id = (int) ($store->note((int) $input['note_id'])['post_id'] ?? 0);
    }
    // A note or post that does not exist has no per-post rule to check. Refusing here turned a
    // mistyped ID into "Permission denied", which sends the agent looking for a missing grant;
    // Runtime\can_run() has already required a site manager, and the ability itself answers
    // with the precise "No note/post has that ID".
    if ($post_id <= 0 || $store->post($post_id) === null) {
        return true;
    }
    return $store->can_edit($post_id);
}

/**
 * @return array{id: int, type: string, status: string, content: string, author: int}|WP_Error
 */
function editable_post(Store $store, int $post_id): array|WP_Error
{
    $post = $post_id > 0 ? $store->post($post_id) : null;
    if ($post === null) {
        return new WP_Error('kit_block_notes_post_not_found', 'No post has that ID.', ['status' => 404]);
    }
    if (!$store->can_edit($post_id)) {
        // Core's own rule for notes: editorial content, only on posts the user can edit.
        return new WP_Error('kit_block_notes_forbidden', 'Notes can only be read or left on a post you can edit.', ['status' => 403]);
    }
    if (!$store->supports_notes($post['type'])) {
        return new WP_Error('kit_block_notes_unsupported', sprintf('The %s post type does not have Notes turned on.', $post['type']), ['status' => 400]);
    }
    return $post;
}

/**
 * The top-level note (thread) a note ID belongs to: itself, or a reply's parent.
 *
 * @return array<string, mixed>|WP_Error
 */
function thread(Store $store, int $note_id): array|WP_Error
{
    $note = $store->note($note_id);
    if ($note === null || !in_array($note['status'], ['0', '1'], true)) {
        return new WP_Error('kit_block_notes_note_not_found', 'No note has that ID.', ['status' => 404]);
    }
    $post = editable_post($store, $note['post_id']);
    if ($post instanceof WP_Error) {
        return $post;
    }
    if ($note['parent'] > 0) {
        $note = $store->note($note['parent']);
        if ($note === null) {
            return new WP_Error('kit_block_notes_note_not_found', 'That reply\'s thread no longer exists.', ['status' => 404]);
        }
    }
    return $note;
}

/**
 * @param array<string, mixed> $input
 */
function note_content(array $input): string|WP_Error
{
    $content = trim((string) ($input['content'] ?? ''));
    if ($content === '') {
        return new WP_Error('kit_block_notes_empty', 'A note needs some text.', ['status' => 400]);
    }
    if (mb_strlen($content) > MAX_CONTENT) {
        return new WP_Error('kit_block_notes_too_long', sprintf('A note is at most %d characters.', MAX_CONTENT), ['status' => 400]);
    }
    return $content;
}

/**
 * @param array<string, mixed> $block
 * @return array{path: string, name: string, anchor: string, label: string, excerpt: string, note_ids: list<int>}
 */
function block_ref(array $block): array
{
    return [
        'path' => (string) $block['path'],
        'name' => (string) $block['name'],
        'anchor' => (string) $block['anchor'],
        'label' => (string) $block['label'],
        'excerpt' => (string) $block['excerpt'],
        'note_ids' => $block['note_ids'],
    ];
}

/**
 * @param array<string, mixed> $note
 * @return array{id: int, name: string, is_agent: bool}
 */
function author(array $note): array
{
    return ['id' => (int) $note['author_id'], 'name' => (string) $note['author_name'], 'is_agent' => (bool) $note['by_agent']];
}
