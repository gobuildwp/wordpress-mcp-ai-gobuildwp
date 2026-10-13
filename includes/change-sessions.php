<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Undo and redo a whole agent session.
 *
 * The ledger could already undo one change, one bulk batch, or whatever a filter showed. None of
 * those is what a person means by "put back everything the agent just did": the batch is one call,
 * the filter is a guess at a time window. A session is the run itself (agent-sessions.php), so
 * undoing it is the one action that matches the mistake.
 *
 * Three rules make it safe to do in one step:
 *
 * 1. Newest first, and stop at the first change that does not verify. Each undo is the ledger's
 *    own verified rollback; an undo that cannot prove the target is back to its before-image stops
 *    the run, and the result names what was undone, what failed and what was not attempted. Never
 *    a partial run reported as a success.
 * 2. Nothing is touched when something else has changed a target since the session did. Every
 *    session write records the target's state straight afterwards (`after`); before anything is
 *    undone, each target's current state is compared with the last state the session left it in,
 *    and consecutive session writes to one target are checked against each other. A mismatch is a
 *    conflict — an edit a person or another agent made later — and undoing over it would silently
 *    destroy that edit, so the whole run is refused and the conflict reported.
 * 3. Changes that cannot be undone are never skipped silently. A session holding one refuses to
 *    start unless the caller says to go ahead without it (`allow_partial`), and the result lists it
 *    either way.
 *
 * Redo is the same in reverse: every undo keeps the state it replaced (`redo`), and redo-session
 * puts those back oldest first, under the same checks, verified against the state recorded when
 * the change was first made.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Most changes one session undo or redo will plan. A run past this is refused, never truncated. */
const WPPILOT_SESSION_MAX_CHANGES = 2000;

/** Longest session id accepted as input; the ledger column holds 64. */
const WPPILOT_SESSION_ID_MAX_LENGTH = 64;

/** Rows read per page while planning. */
const WPPILOT_SESSION_PAGE = 100;

/** Characters kept of each part hash; enough to tell two values apart, small enough to store per key. */
const WPPILOT_SESSION_PART_HASH = 16;

/**
 * Post meta that moves on its own and must not read as someone else's edit.
 *
 * Elementor rewrites its CSS and asset caches the first time a page is viewed after a save, the
 * editor sets its lock on open, WordPress remembers old slugs: none of it is an edit, and all of it
 * would otherwise make a session look conflicted the moment somebody looked at the page. Only the
 * conflict check ignores these; a restore still puts every key back and verifies all of them.
 *
 * @var list<string>
 */
const WPPILOT_SESSION_VOLATILE_META = [
    '_edit_lock',
    '_edit_last',
    '_elementor_css',
    '_elementor_page_assets',
    '_elementor_element_cache',
    '_elementor_screenshot',
    '_elementor_screenshot_failed',
    '_wp_old_slug',
    '_wp_old_date',
    '_encloseme',
    '_pingme',
    '_wp_trash_meta_status',
    '_wp_trash_meta_time',
    '_wp_desired_post_slug',
];

/**
 * Annotate a row about to be stored with its session and the state its write left behind.
 *
 * Only rows written inside an agent session carry either, so a person's own wp-admin edits and
 * every row written before 1.16.0 are stored exactly as they were.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function wppilot_change_row_with_session(array $row): array
{
    if (!array_key_exists('session', $row)) {
        $session = function_exists('wppilot_current_session_id') ? wppilot_current_session_id() : '';
        if ($session === '') {
            return $row;
        }
        $row['session'] = $session;
    }
    if (!is_string($row['session']) || $row['session'] === '' || array_key_exists('after', $row)) {
        return $row;
    }
    if (($row['kind'] ?? 'change') !== 'change') {
        return $row;
    }
    $rollback = is_array($row['rollback'] ?? null) ? wppilot_string_keyed_array($row['rollback']) : [];
    if (($rollback['reversible'] ?? false) !== true) {
        return $row;
    }
    $target = wppilot_change_payload_target($rollback);
    $digest = $target !== '' ? wppilot_change_state_digest(wppilot_change_current_state($rollback, bounded: false)) : null;
    if ($digest !== null) {
        $row['after'] = ['target' => $target] + $digest;
    }

    return $row;
}

/**
 * Which object a rollback payload restores, as a key two rows can be compared on.
 *
 * The key names the shape as well as the object, because only snapshots of one shape can be
 * compared: a partial snapshot of post 12 and a whole one are both "post 12", but never equal.
 * '' when the payload's target cannot be named, which leaves that row out of the conflict check
 * (and the result says so).
 *
 * @param array<string, mixed> $rollback
 */
function wppilot_change_payload_target(array $rollback): string
{
    $type = (string) ($rollback['type'] ?? '');
    $snapshot = wppilot_string_keyed_array($rollback['snapshot'] ?? null);
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);

    $target = match ($type) {
        'restore-post' => 'post:' . (int) (wppilot_string_keyed_array($snapshot['post'] ?? null)['ID'] ?? 0),
        'delete-created-post' => 'post:' . (int) ($rollback['post_id'] ?? 0),
        'restore-settings' => 'options:' . wppilot_change_key_list(array_keys($values)),
        'restore-order-status' => 'order-status:' . (int) ($values['order_id'] ?? 0),
        'restore-comment-status' => 'comment-status:' . (int) ($values['comment_id'] ?? 0),
        'restore-term' => 'term:' . (string) ($values['taxonomy'] ?? '') . ':' . (int) ($values['term_id'] ?? 0),
        'delete-created-term' => 'term:' . (string) ($rollback['taxonomy'] ?? '') . ':' . (int) ($rollback['term_id'] ?? 0),
        'restore-comment' => 'comment:' . (int) ($values['comment_id'] ?? 0),
        'delete-created-comment' => 'comment:' . (int) ($rollback['comment_id'] ?? 0),
        'restore-menu-locations' => 'menu-locations',
        'restore-menu-order' => 'menu-order:' . (int) ($snapshot['menu_id'] ?? 0),
        'restore-plugin-state' => 'plugin-state:' . (string) ($values['file'] ?? ''),
        'restore-active-theme' => 'active-theme',
        WPPILOT_SESSION_POST_PARTIAL_TYPE => 'post-partial:' . (int) ($snapshot['post_id'] ?? 0) . ':' . wppilot_change_key_list(array_merge(
            array_map(static fn(string $field): string => 'f.' . $field, array_keys(wppilot_string_keyed_array($snapshot['fields'] ?? null))),
            array_map(static fn(string $key): string => 'm.' . $key, array_keys(wppilot_string_keyed_array($snapshot['meta'] ?? null))),
        )),
        WPPILOT_SESSION_OPTION_SET_TYPE => 'option-set:' . wppilot_change_key_list(array_merge(
            array_keys($values),
            wppilot_string_list($snapshot['absent'] ?? null),
        )),
        default => '',
    };
    if ($target === '') {
        /**
         * Name the target of a rollback payload type GoBuildWP does not know, so rows of that type
         * take part in the session conflict check. Return '' to leave them out.
         *
         * @param string               $target   ''.
         * @param array<string, mixed> $rollback The row's rollback payload.
         */
        $filtered = apply_filters('wppilot_change_payload_target', '', $rollback);
        $target = is_string($filtered) ? $filtered : '';
    }
    // A target whose id did not resolve ("post:0") names nothing, and two of them would match.
    return preg_match('/:0$|:$/', $target) === 1 ? '' : $target;
}

/**
 * The before-image payload types a session can compare and redo, beyond GoBuildWP's built-ins.
 * Defined here, not read from their modules, so this file loads on its own.
 */
const WPPILOT_SESSION_POST_PARTIAL_TYPE = 'kits/post-partial';
const WPPILOT_SESSION_OPTION_SET_TYPE = 'ledger-map/option';

/**
 * A short stable key for a set of names: the names themselves when short, else their hash.
 *
 * @param list<string> $keys
 */
function wppilot_change_key_list(array $keys): string
{
    $keys = array_values(array_unique(array_map('strval', $keys)));
    sort($keys, SORT_STRING);
    $joined = implode(',', $keys);

    return strlen($joined) <= 120 ? $joined : 'h' . substr(md5($joined), 0, 16);
}

/**
 * The target's current state, in the same shape as the payload's before-image.
 *
 * Null when GoBuildWP cannot read this payload's target (a type only its own plugin knows). A target
 * that no longer exists comes back as `['type' => 'absent']`, which is a state like any other: a
 * post the session created and someone then deleted does not match what the session left.
 *
 * @param array<string, mixed> $rollback
 * @param bool $bounded False lets a post snapshot exceed the storage cap; see wppilot_snapshot_post().
 * @return array<string, mixed>|null
 */
// @mago-expect lint:cyclomatic-complexity -- One explicit branch per restore type, mirroring wppilot_change_run_restore().
function wppilot_change_current_state(array $rollback, bool $bounded = true): ?array
{
    $type = (string) ($rollback['type'] ?? '');
    $snapshot = wppilot_string_keyed_array($rollback['snapshot'] ?? null);
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    $absent = ['type' => 'absent'];

    switch ($type) {
        case 'restore-post':
            return wppilot_snapshot_post((int) (wppilot_string_keyed_array($snapshot['post'] ?? null)['ID'] ?? 0), $bounded) ?? $absent;
        case 'delete-created-post':
            return wppilot_snapshot_post((int) ($rollback['post_id'] ?? 0), $bounded) ?? $absent;
        case 'restore-settings':
            $current = [];
            foreach (array_keys($values) as $key) {
                // @mago-expect analysis:mixed-assignment -- Option values are fingerprinted without interpretation.
                $current[$key] = get_option($key);
            }
            return ['type' => 'settings', 'values' => $current, 'fingerprint' => wppilot_snapshot_fingerprint($current)];
        case 'restore-order-status':
            if (!function_exists('wc_get_order')) {
                return null;
            }
            $order = wc_get_order((int) ($values['order_id'] ?? 0));
            if (!$order instanceof WC_Order) {
                return $absent;
            }
            $current = ['order_id' => $order->get_id(), 'status' => $order->get_status()];
            return ['type' => 'order-status', 'values' => $current, 'fingerprint' => wppilot_snapshot_fingerprint($current)];
        case 'restore-comment-status':
            $comment_id = (int) ($values['comment_id'] ?? 0);
            // @mago-expect analysis:mixed-assignment -- WordPress returns WP_Comment|null for OBJECT output.
            $comment = $comment_id > 0 ? get_comment($comment_id) : null;
            if (!$comment instanceof WP_Comment) {
                return $absent;
            }
            $current = ['comment_id' => $comment_id, 'status' => wp_get_comment_status($comment)];
            return ['type' => 'comment-status', 'values' => $current, 'fingerprint' => wppilot_snapshot_fingerprint($current)];
        case 'restore-term':
            return wppilot_snapshot_term((int) ($values['term_id'] ?? 0), (string) ($values['taxonomy'] ?? '')) ?? $absent;
        case 'delete-created-term':
            return wppilot_snapshot_term((int) ($rollback['term_id'] ?? 0), (string) ($rollback['taxonomy'] ?? '')) ?? $absent;
        case 'restore-comment':
            return wppilot_snapshot_comment((int) ($values['comment_id'] ?? 0)) ?? $absent;
        case 'delete-created-comment':
            return wppilot_snapshot_comment((int) ($rollback['comment_id'] ?? 0)) ?? $absent;
        case 'restore-menu-locations':
            $current = array_map('intval', (array) get_nav_menu_locations());
            return ['type' => 'menu-locations', 'values' => $current, 'fingerprint' => wppilot_snapshot_fingerprint($current)];
        case 'restore-menu-order':
            return wppilot_snapshot_menu_order((int) ($snapshot['menu_id'] ?? 0)) ?? $absent;
        case 'restore-plugin-state':
            return wppilot_snapshot_plugin_state((string) ($values['file'] ?? ''));
        case 'restore-active-theme':
            $current = ['stylesheet' => get_stylesheet(), 'template' => get_template()];
            return ['type' => 'active-theme', 'values' => $current, 'fingerprint' => wppilot_snapshot_fingerprint($current)];
        case WPPILOT_SESSION_POST_PARTIAL_TYPE:
            if (!function_exists('WPPilot\\Kits\\Runtime\\PostPartial\\capture')) {
                return null;
            }
            $fields = array_values(array_diff(array_keys(wppilot_string_keyed_array($snapshot['fields'] ?? null)), ['post_date_gmt']));
            $meta_keys = array_keys(wppilot_string_keyed_array($snapshot['meta'] ?? null));
            return \WPPilot\Kits\Runtime\PostPartial\capture((int) ($snapshot['post_id'] ?? 0), $fields, $meta_keys) ?? $absent;
        case WPPILOT_SESSION_OPTION_SET_TYPE:
            if (!function_exists('wppilot_ledger_map_snapshot_options')) {
                return null;
            }
            return wppilot_ledger_map_snapshot_options(array_merge(array_keys($values), wppilot_string_list($snapshot['absent'] ?? null)));
    }

    /**
     * Read the current state of a rollback payload type GoBuildWP does not know, in the same shape
     * as its before-image (an array with a `type`). Returning one lets rows of that type take part
     * in the session conflict check and be redone: the state is handed back to the type's own
     * registered restore when a redo puts it back.
     *
     * @param array<string, mixed>|null $state    Null.
     * @param array<string, mixed>       $rollback The row's rollback payload.
     */
    // @mago-expect analysis:mixed-assignment -- Filter output is validated below.
    $filtered = apply_filters('wppilot_change_current_state', null, $rollback);

    return is_array($filtered) && is_string($filtered['type'] ?? null) ? wppilot_string_keyed_array($filtered) : null;
}

/**
 * A comparable fingerprint of a state, and a hash per part of it so a mismatch can be named.
 *
 * Post snapshots leave out the columns and meta that move on their own (see
 * WPPILOT_SESSION_VOLATILE_META); everything else is compared exactly.
 *
 * @param array<string, mixed>|null $state
 * @return array{fingerprint: string, parts: array<string, string>}|null
 */
function wppilot_change_state_digest(?array $state): ?array
{
    if ($state === null || !is_string($state['type'] ?? null)) {
        return null;
    }
    $type = (string) $state['type'];
    if ($type === 'absent') {
        return ['fingerprint' => 'absent', 'parts' => []];
    }
    if ($type === 'oversize') {
        return null;
    }

    $parts = [];
    if ($type === 'post') {
        $post = wppilot_string_keyed_array($state['post'] ?? null);
        foreach (WPPILOT_VOLATILE_POST_FIELDS as $volatile) {
            unset($post[$volatile]);
        }
        $volatile_meta = wppilot_session_volatile_meta_keys();
        foreach ($post as $field => $value) {
            $parts['post.' . $field] = wppilot_change_part_hash($value);
        }
        // @mago-expect analysis:mixed-assignment -- Meta values stay opaque; they are only hashed.
        foreach (wppilot_string_keyed_array($state['meta'] ?? null) as $key => $value) {
            if (!in_array($key, $volatile_meta, strict: true)) {
                $parts['meta.' . $key] = wppilot_change_part_hash($value);
            }
        }
        // @mago-expect analysis:mixed-assignment -- Term id lists are normalized below.
        foreach (wppilot_string_keyed_array($state['terms'] ?? null) as $taxonomy => $ids) {
            $ids = is_array($ids) ? array_map('intval', $ids) : [];
            sort($ids);
            $parts['terms.' . $taxonomy] = wppilot_change_part_hash($ids);
        }
    } elseif ($type === 'settings' || $type === WPPILOT_SESSION_OPTION_SET_TYPE) {
        // @mago-expect analysis:mixed-assignment -- Option values stay opaque; they are only hashed.
        foreach (wppilot_string_keyed_array($state['values'] ?? null) as $key => $value) {
            $parts['option.' . $key] = wppilot_change_part_hash($value);
        }
        foreach (wppilot_string_list($state['absent'] ?? null) as $key) {
            $parts['option.' . $key] = 'absent';
        }
    } elseif ($type === WPPILOT_SESSION_POST_PARTIAL_TYPE) {
        // @mago-expect analysis:mixed-assignment -- Field and meta values are only hashed.
        foreach (wppilot_string_keyed_array($state['fields'] ?? null) as $field => $value) {
            $parts['post.' . $field] = wppilot_change_part_hash($value);
        }
        // @mago-expect analysis:mixed-assignment -- Field and meta values are only hashed.
        foreach (wppilot_string_keyed_array($state['meta'] ?? null) as $key => $value) {
            $parts['meta.' . $key] = wppilot_change_part_hash($value);
        }
    }

    if ($parts !== []) {
        ksort($parts, SORT_STRING);
        return ['fingerprint' => hash('sha256', (string) wp_json_encode($parts)), 'parts' => $parts];
    }
    $copy = $state;
    unset($copy['fingerprint']);
    $fingerprint = is_string($state['fingerprint'] ?? null) && $state['fingerprint'] !== ''
        ? (string) $state['fingerprint']
        : wppilot_snapshot_fingerprint($copy);

    return ['fingerprint' => $fingerprint, 'parts' => []];
}

function wppilot_change_part_hash(mixed $value): string
{
    return substr(wppilot_snapshot_fingerprint($value), 0, WPPILOT_SESSION_PART_HASH);
}

/** @return list<string> */
function wppilot_session_volatile_meta_keys(): array
{
    /**
     * Post meta keys the session conflict check ignores because they change without anyone
     * editing the post (caches, locks, bookkeeping).
     *
     * @param list<string> $keys
     */
    $filtered = apply_filters('wppilot_session_volatile_meta_keys', WPPILOT_SESSION_VOLATILE_META);

    return is_array($filtered) ? wppilot_string_list($filtered) : WPPILOT_SESSION_VOLATILE_META;
}

/**
 * The part names that differ between two digests: which fields someone else changed.
 *
 * @param array{fingerprint: string, parts: array<string, string>} $expected
 * @param array{fingerprint: string, parts: array<string, string>} $observed
 * @return list<string>
 */
function wppilot_change_digest_diff(array $expected, array $observed, int $limit = 20): array
{
    if ($expected['fingerprint'] === 'absent' || $observed['fingerprint'] === 'absent') {
        return [$observed['fingerprint'] === 'absent' ? 'target deleted' : 'target recreated'];
    }
    $names = [];
    foreach (array_unique(array_merge(array_keys($expected['parts']), array_keys($observed['parts']))) as $name) {
        if (($expected['parts'][$name] ?? null) !== ($observed['parts'][$name] ?? null)) {
            $names[] = (string) $name;
        }
    }

    return array_slice($names, 0, max(1, $limit));
}

/**
 * The object a target names, whichever shape of it a before-image holds.
 *
 * A whole-post snapshot (`post:12`) and a post-partial one (`post-partial:12:m._yoast_wpseo_title`)
 * are different targets, since only snapshots of one shape compare, but the same post: a session
 * that updates a post and then sets its SEO title writes both.
 */
function wppilot_change_target_object(string $target): string
{
    return preg_match('/^post-partial:(\d+):/', $target, $match) === 1 ? 'post:' . $match[1] : $target;
}

/**
 * Whether every difference between two states of one target lies in parts this session writes
 * through its other targets on the same object.
 *
 * Then the difference is the session's own doing, not a conflict: undoing (or redoing) the session
 * in order puts those parts back before this target is reached, and the check made just before
 * this target is touched (wppilot_session_undo_one(), wppilot_session_redo_one()) compares the
 * whole state again. States without per-part hashes never qualify.
 *
 * @param array{fingerprint: string, parts: array<string, string>} $expected
 * @param array{fingerprint: string, parts: array<string, string>} $observed
 * @param array<string, true> $covered
 */
function wppilot_session_difference_is_own(array $expected, array $observed, array $covered): bool
{
    if ($covered === [] || $expected['parts'] === [] || $observed['parts'] === []) {
        return false;
    }
    foreach (wppilot_change_digest_diff($expected, $observed, PHP_INT_MAX) as $name) {
        if (!isset($covered[$name])) {
            return false;
        }
    }

    return true;
}

/**
 * The parts this session writes to a target's object through its other targets.
 *
 * @param array<string, array<string, array<string, true>>> $written Object, then target, then part names.
 * @return array<string, true>
 */
function wppilot_session_covered_parts(array $written, string $target): array
{
    $covered = [];
    foreach ($written[wppilot_change_target_object($target)] ?? [] as $other => $parts) {
        if ($other !== $target) {
            $covered += $parts;
        }
    }

    return $covered;
}

/**
 * Record the parts a row writes, for wppilot_session_covered_parts().
 *
 * @param array<string, array<string, array<string, true>>> $written
 * @param array{fingerprint: string, parts: array<string, string>}|null $after
 */
function wppilot_session_note_written(array &$written, string $target, ?array $after): void
{
    if ($target === '' || $after === null) {
        return;
    }
    foreach (array_keys($after['parts']) as $name) {
        $written[wppilot_change_target_object($target)][$target][(string) $name] = true;
    }
}

/**
 * What the undo is about to replace, kept so it can be put back by redo-session.
 *
 * @param array<string, mixed> $rollback
 * @return array{available: bool, reason?: string, snapshot?: array<string, mixed>}
 */
function wppilot_change_redo_image(array $rollback): array
{
    $type = (string) ($rollback['type'] ?? '');
    if ($type === 'delete-created-term' || $type === 'delete-created-comment') {
        return ['available' => false, 'reason' => 'The undo deletes what this change created permanently, so it cannot be redone. Make the change again instead.'];
    }
    $state = wppilot_change_current_state($rollback, bounded: true);
    if ($state === null) {
        return ['available' => false, 'reason' => 'GoBuildWP cannot read this change\'s target, so it kept nothing to redo it from.'];
    }
    $state_type = (string) ($state['type'] ?? '');
    if ($state_type === 'absent') {
        return ['available' => false, 'reason' => 'The target no longer existed when the change was undone, so there is nothing to redo.'];
    }
    if ($state_type === 'oversize') {
        return ['available' => false, 'reason' => 'The target was too large to keep a copy of, so this undo cannot be redone.'];
    }
    if (wppilot_change_redo_payload_type($state_type) === '') {
        return ['available' => false, 'reason' => 'No restore is registered for this kind of change, so it cannot be redone.'];
    }

    return ['available' => true, 'snapshot' => $state];
}

/**
 * The rollback payload type that puts a state of this snapshot type back, or '' when none does.
 */
function wppilot_change_redo_payload_type(string $snapshot_type): string
{
    $builtin = [
        'post' => 'restore-post',
        'settings' => 'restore-settings',
        'term' => 'restore-term',
        'comment' => 'restore-comment',
        'comment-status' => 'restore-comment-status',
        'order-status' => 'restore-order-status',
        'menu-locations' => 'restore-menu-locations',
        'menu-order' => 'restore-menu-order',
        'plugin-state' => 'restore-plugin-state',
        'active-theme' => 'restore-active-theme',
    ];
    if (isset($builtin[$snapshot_type])) {
        return $builtin[$snapshot_type];
    }

    return wppilot_get_rollback_strategy($snapshot_type) !== null ? $snapshot_type : '';
}

/**
 * Put a redo image back through the restore its type is undone with.
 *
 * @param array<string, mixed> $snapshot
 * @param array<string, mixed> $entry
 * @return array<string, mixed>|WP_Error
 */
function wppilot_change_restore_redo(array $snapshot, array $entry): array|WP_Error
{
    $payload_type = wppilot_change_redo_payload_type((string) ($snapshot['type'] ?? ''));
    if ($payload_type === '') {
        return new WP_Error('wppilot_redo_unknown', __('No restore is registered for this kind of change, so it cannot be redone.', domain: 'wppilot'));
    }

    return wppilot_change_run_restore(['reversible' => true, 'type' => $payload_type, 'snapshot' => $snapshot], $entry);
}

/**
 * The sessions recorded in the ledger, most recently active first.
 *
 * @return list<array<string, mixed>>
 */
function wppilot_list_change_sessions(int $limit = 20): array
{
    $limit = min(100, max(1, $limit));
    $summaries = wppilot_change_table_active()
        ? wppilot_change_table_sessions($limit)
        : wppilot_change_option_sessions($limit);

    return array_map('wppilot_change_session_public', $summaries);
}

/**
 * @param array<string, mixed> $summary
 * @return array<string, mixed>
 */
function wppilot_change_session_public(array $summary): array
{
    $agent = is_array($summary['agent'] ?? null) ? $summary['agent'] : [];
    $user = is_array($summary['user'] ?? null) ? $summary['user'] : [];

    return [
        'session_id' => (string) ($summary['session'] ?? ''),
        'agent' => [
            'method' => (string) ($agent['method'] ?? ''),
            'label' => (string) ($agent['label'] ?? ''),
            'client' => (string) ($agent['client'] ?? ''),
        ],
        'user' => ['id' => (int) ($user['id'] ?? 0), 'login' => (string) ($user['login'] ?? '')],
        'first_change_at' => (string) ($summary['first_at'] ?? ''),
        'last_change_at' => (string) ($summary['last_at'] ?? ''),
        'changes' => (int) ($summary['changes'] ?? 0),
        'undoable' => (int) ($summary['undoable'] ?? 0),
        'undone' => (int) ($summary['undone'] ?? 0),
        'not_reversible' => (int) ($summary['not_reversible'] ?? 0),
    ];
}

/**
 * Session summaries from the option ledger.
 *
 * @return list<array<string, mixed>>
 */
function wppilot_change_option_sessions(int $limit): array
{
    $sessions = [];
    foreach (wppilot_change_log_option_rows() as $row) {
        $session = (string) ($row['session'] ?? '');
        if ($session === '' || ($row['kind'] ?? 'change') !== 'change') {
            continue;
        }
        $status = wppilot_change_status($row);
        $summary = $sessions[$session] ?? [
            'session' => $session,
            'first_at' => (string) ($row['recorded_at'] ?? ''),
            'changes' => 0,
            'undoable' => 0,
            'undone' => 0,
            'not_reversible' => 0,
        ];
        $summary['changes']++;
        $summary[match ($status) {
            'undoable' => 'undoable',
            'rolled-back' => 'undone',
            default => 'not_reversible',
        }]++;
        $summary['last_at'] = (string) ($row['recorded_at'] ?? '');
        $summary['agent'] = is_array($row['agent'] ?? null) ? $row['agent'] : [];
        $summary['user'] = is_array($row['user'] ?? null) ? $row['user'] : [];
        // Re-inserted so the most recently active session ends up last.
        unset($sessions[$session]);
        $sessions[$session] = $summary;
    }

    return array_slice(array_reverse(array_values($sessions)), 0, $limit);
}

/**
 * Session summaries from the ledger table: one grouped read, then the newest row of each session
 * for who it was.
 *
 * @return list<array<string, mixed>>
 */
function wppilot_change_table_sessions(int $limit): array
{
    $table = wppilot_changes_table();
    $groups = wppilot_changes_results(
        "SELECT session_id, COUNT(*) AS changes, MAX(seq) AS last_seq, MIN(recorded_at) AS first_at, MAX(recorded_at) AS last_at,"
        . " SUM(CASE WHEN status = 'undoable' THEN 1 ELSE 0 END) AS undoable,"
        . " SUM(CASE WHEN status = 'rolled-back' THEN 1 ELSE 0 END) AS undone,"
        . " SUM(CASE WHEN status = 'not-reversible' THEN 1 ELSE 0 END) AS not_reversible"
        . " FROM {$table} WHERE session_id <> '' AND kind = 'change' GROUP BY session_id ORDER BY last_seq DESC LIMIT %d",
        [$limit],
    );
    if ($groups === []) {
        return [];
    }
    $seqs = array_map(static fn(array $group): int => (int) $group['last_seq'], $groups);
    $who = [];
    foreach (wppilot_changes_results(
        "SELECT seq, entry_data FROM {$table} WHERE seq IN (" . implode(', ', array_fill(0, count($seqs), '%d')) . ')',
        $seqs,
    ) as $record) {
        [$ok, $shell] = wppilot_change_unserialize($record['entry_data'] ?? null);
        $who[(int) $record['seq']] = $ok && is_array($shell) ? $shell : [];
    }

    $summaries = [];
    foreach ($groups as $group) {
        $shell = $who[(int) $group['last_seq']] ?? [];
        $summaries[] = [
            'session' => (string) $group['session_id'],
            'changes' => (int) $group['changes'],
            'undoable' => (int) $group['undoable'],
            'undone' => (int) $group['undone'],
            'not_reversible' => (int) $group['not_reversible'],
            'first_at' => wppilot_change_session_time($group['first_at'] ?? null),
            'last_at' => wppilot_change_session_time($group['last_at'] ?? null),
            'agent' => is_array($shell['agent'] ?? null) ? $shell['agent'] : [],
            'user' => is_array($shell['user'] ?? null) ? $shell['user'] : [],
        ];
    }

    return $summaries;
}

function wppilot_change_session_time(mixed $value): string
{
    return is_string($value) && $value !== '' ? gmdate('c', (int) strtotime($value . ' UTC')) : '';
}

/**
 * One session's changes, newest first, as far as WPPILOT_SESSION_MAX_CHANGES.
 *
 * Read a page at a time and reduced as it goes: a row carries its before-image, and a session of
 * two thousand changes held whole would be hundreds of megabytes. Each row keeps only what a plan
 * needs, and each distinct target's current state is read once, from the first row that names it.
 *
 * @return array{rows: list<array<string, mixed>>, current: array<string, array{fingerprint: string, parts: array<string, string>}|null>}|WP_Error
 */
function wppilot_session_rows(string $session): array|WP_Error
{
    $filters = ['session' => $session, 'kind' => 'change'];
    $total = wppilot_count_change_log($filters);
    if ($total > WPPILOT_SESSION_MAX_CHANGES) {
        return new WP_Error('wppilot_session_too_large', sprintf(
            /* translators: 1: number of changes, 2: the most one run handles */
            __('This session holds %1$d changes; one run handles at most %2$d. Undo it in parts from the Changes screen (filter by session and date), or change by change with wppilot/rollback-change.', domain: 'wppilot'),
            $total,
            WPPILOT_SESSION_MAX_CHANGES,
        ), ['status' => 413]);
    }

    $rows = [];
    $current = [];
    $seen = [];
    for ($offset = 0; $offset < $total + WPPILOT_SESSION_PAGE; $offset += WPPILOT_SESSION_PAGE) {
        $page = wppilot_query_change_log($filters, WPPILOT_SESSION_PAGE, $offset);
        foreach ($page as $entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $rollback = is_array($entry['rollback'] ?? null) ? wppilot_string_keyed_array($entry['rollback']) : [];
            $after = is_array($entry['after'] ?? null) ? $entry['after'] : null;
            $target = is_array($after) && is_string($after['target'] ?? null) ? (string) $after['target'] : wppilot_change_payload_target($rollback);
            if ($target !== '' && !array_key_exists($target, $current) && ($rollback['reversible'] ?? false) === true) {
                $current[$target] = wppilot_change_state_digest(wppilot_change_current_state($rollback, bounded: false));
            }
            $redo = is_array($entry['redo'] ?? null) ? $entry['redo'] : null;
            $rows[] = [
                'id' => $id,
                'ability' => (string) ($entry['ability'] ?? ''),
                'recorded_at' => (string) ($entry['recorded_at'] ?? ''),
                'status' => wppilot_change_status($entry),
                'reason' => (string) ($rollback['reason'] ?? ''),
                'target' => $target,
                'after' => wppilot_change_digest_or_null($after),
                'before' => ($rollback['reversible'] ?? false) === true && is_array($rollback['snapshot'] ?? null)
                    ? wppilot_change_state_digest(wppilot_string_keyed_array($rollback['snapshot']))
                    : null,
                'redo_available' => is_array($redo) && ($redo['available'] ?? false) === true,
                'redo_reason' => is_array($redo)
                    ? (string) ($redo['reason'] ?? '')
                    : 'This change was undone before GoBuildWP kept redo images (1.16.0), so it cannot be redone.',
                'redo' => is_array($redo) && is_array($redo['snapshot'] ?? null)
                    ? wppilot_change_state_digest(wppilot_string_keyed_array($redo['snapshot']))
                    : null,
                'undone' => wppilot_change_digest_or_null(is_array($entry['undone_digest'] ?? null) ? $entry['undone_digest'] : null),
            ];
        }
        if (count($page) < WPPILOT_SESSION_PAGE) {
            break;
        }
    }

    return ['rows' => $rows, 'current' => $current];
}

/**
 * @param array<array-key, mixed>|null $value
 * @return array{fingerprint: string, parts: array<string, string>}|null
 */
function wppilot_change_digest_or_null(?array $value): ?array
{
    if ($value === null || !is_string($value['fingerprint'] ?? null) || $value['fingerprint'] === '') {
        return null;
    }
    $parts = [];
    // @mago-expect analysis:mixed-assignment -- Stored part hashes are cast below.
    foreach (is_array($value['parts'] ?? null) ? $value['parts'] : [] as $name => $hash) {
        $parts[(string) $name] = (string) $hash;
    }

    return ['fingerprint' => (string) $value['fingerprint'], 'parts' => $parts];
}

/**
 * What undoing a session would do, without doing it.
 *
 * @param list<array<string, mixed>> $rows Newest first, from wppilot_session_rows().
 * @param array<string, array{fingerprint: string, parts: array<string, string>}|null> $current
 * @return array{pending: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, irreversible: list<array<string, mixed>>, unchecked: list<array<string, mixed>>, already: int}
 */
function wppilot_session_plan_undo(string $session, array $rows, array $current): array
{
    $plan = ['pending' => [], 'conflicts' => [], 'irreversible' => [], 'unchecked' => [], 'already' => 0];
    /** @var array<string, array<string, mixed>> $newer Per target, the newest pending row seen so far. */
    $newer = [];
    /** @var array<string, array<string, array<string, true>>> $written Parts newer pending rows write, per object and target. */
    $written = [];
    foreach ($rows as $row) {
        if ($row['status'] === 'rolled-back') {
            $plan['already']++;
            continue;
        }
        if ($row['status'] !== 'undoable') {
            $plan['irreversible'][] = wppilot_session_row_brief($row, ['reason' => $row['reason'] !== '' ? $row['reason'] : 'Not reversible.']);
            continue;
        }
        $plan['pending'][] = $row;
        $target = (string) $row['target'];
        $after = $row['after'];
        if ($target === '' || $after === null) {
            $plan['unchecked'][] = wppilot_session_row_brief($row, ['reason' => 'GoBuildWP recorded no after-state for this change, so it cannot tell whether anything changed the target since.']);
            continue;
        }
        if (!isset($newer[$target])) {
            // The newest session write to this target: the target must still look as it left it.
            $now = $current[$target] ?? null;
            if ($now === null) {
                $plan['unchecked'][] = wppilot_session_row_brief($row, ['reason' => 'The target\'s current state could not be read.']);
            } elseif (
                !hash_equals($after['fingerprint'], $now['fingerprint'])
                && !wppilot_session_difference_is_own($after, $now, wppilot_session_covered_parts($written, $target))
            ) {
                $plan['conflicts'][] = wppilot_session_conflict($session, $row, $after, $now, 'changed-since');
            }
        } else {
            // An older session write to the same target: the newer write's before-image must be
            // exactly what this one left, or something else wrote to it in between.
            $before = $newer[$target]['before'];
            if (
                is_array($before)
                && !hash_equals($after['fingerprint'], $before['fingerprint'])
                && !wppilot_session_difference_is_own($after, $before, wppilot_session_covered_parts($written, $target))
            ) {
                $plan['conflicts'][] = wppilot_session_conflict($session, $row, $after, $before, 'changed-between', (string) $newer[$target]['id']);
            }
        }
        $newer[$target] = $row;
        wppilot_session_note_written($written, $target, $after);
    }

    return $plan;
}

/**
 * What redoing a session would do, without doing it.
 *
 * @param list<array<string, mixed>> $rows Newest first, from wppilot_session_rows().
 * @param array<string, array{fingerprint: string, parts: array<string, string>}|null> $current
 * @return array{pending: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, unavailable: list<array<string, mixed>>, unchecked: list<array<string, mixed>>, active: int}
 */
function wppilot_session_plan_redo(string $session, array $rows, array $current): array
{
    $plan = ['pending' => [], 'conflicts' => [], 'unavailable' => [], 'unchecked' => [], 'active' => 0];
    /** @var array<string, array<string, mixed>> $older Per target, the oldest pending row seen so far. */
    $older = [];
    /** @var array<string, array<string, array<string, true>>> $written Parts older pending rows write back, per object and target. */
    $written = [];
    foreach (array_reverse($rows) as $row) {
        if ($row['status'] !== 'rolled-back') {
            $plan['active']++;
            continue;
        }
        if ($row['redo_available'] !== true) {
            $plan['unavailable'][] = wppilot_session_row_brief($row, ['reason' => $row['redo_reason'] !== '' ? $row['redo_reason'] : 'No redo image was kept.']);
            continue;
        }
        $plan['pending'][] = $row;
        $target = (string) $row['target'];
        $undone = $row['undone'];
        if ($target === '' || $undone === null) {
            $plan['unchecked'][] = wppilot_session_row_brief($row, ['reason' => 'GoBuildWP recorded no state after this undo, so it cannot tell whether anything changed the target since.']);
            continue;
        }
        if (!isset($older[$target])) {
            // The oldest undone write to this target: the target must still be as the undo left it.
            $now = $current[$target] ?? null;
            if ($now === null) {
                $plan['unchecked'][] = wppilot_session_row_brief($row, ['reason' => 'The target\'s current state could not be read.']);
            } elseif (
                !hash_equals($undone['fingerprint'], $now['fingerprint'])
                && !wppilot_session_difference_is_own($undone, $now, wppilot_session_covered_parts($written, $target))
            ) {
                $plan['conflicts'][] = wppilot_session_conflict($session, $row, $undone, $now, 'changed-since-undo');
            }
        } else {
            // A newer write to the same target: redoing the older one must land exactly where this
            // one's undo left off.
            $redo = $older[$target]['redo'];
            if (
                is_array($redo)
                && !hash_equals($undone['fingerprint'], $redo['fingerprint'])
                && !wppilot_session_difference_is_own($undone, $redo, wppilot_session_covered_parts($written, $target))
            ) {
                $plan['conflicts'][] = wppilot_session_conflict($session, $row, $undone, $redo, 'changed-between', (string) $older[$target]['id']);
            }
        }
        $older[$target] = $row;
        wppilot_session_note_written($written, $target, is_array($row['after']) ? $row['after'] : null);
    }

    return $plan;
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function wppilot_session_row_brief(array $row, array $extra = []): array
{
    return array_merge([
        'change_id' => (string) $row['id'],
        'ability' => (string) $row['ability'],
        'recorded_at' => (string) $row['recorded_at'],
        'target' => (string) $row['target'],
    ], $extra);
}

/**
 * Describe one conflict: which change, which parts differ, and who else wrote to the target.
 *
 * @param array<string, mixed> $row
 * @param array{fingerprint: string, parts: array<string, string>} $expected
 * @param array{fingerprint: string, parts: array<string, string>} $observed
 * @return array<string, mixed>
 */
function wppilot_session_conflict(string $session, array $row, array $expected, array $observed, string $kind, string $against = ''): array
{
    $conflict = wppilot_session_row_brief($row, [
        'kind' => $kind,
        'changed' => wppilot_change_digest_diff($expected, $observed),
        'reason' => match ($kind) {
            'changed-since' => 'The target was changed after this session last wrote to it. Undoing would overwrite that later edit.',
            'changed-since-undo' => 'The target was changed after this change was undone. Redoing would overwrite that later edit.',
            default => 'Something other than this session wrote to the target between this change and change ' . $against . '.',
        },
    ]);
    $later = wppilot_session_later_writers($session, (string) $row['target'], (string) $row['recorded_at']);
    if ($later !== []) {
        $conflict['later_changes'] = $later;
    }

    return $conflict;
}

/**
 * Ledger rows outside this session that wrote to the same target after a time: the edit a conflict
 * is most likely about, when it went through GoBuildWP. Bounded to the 500 newest rows since then.
 *
 * @return list<array<string, mixed>>
 */
function wppilot_session_later_writers(string $session, string $target, string $since): array
{
    if ($target === '' || $since === '') {
        return [];
    }
    $found = [];
    foreach (wppilot_query_change_log(['kind' => 'change', 'since' => $since], 500) as $entry) {
        if ((string) ($entry['session'] ?? '') === $session) {
            continue;
        }
        $after = is_array($entry['after'] ?? null) ? $entry['after'] : [];
        $rollback = is_array($entry['rollback'] ?? null) ? wppilot_string_keyed_array($entry['rollback']) : [];
        $their = is_string($after['target'] ?? null) ? (string) $after['target'] : wppilot_change_payload_target($rollback);
        if ($their !== $target) {
            continue;
        }
        $agent = is_array($entry['agent'] ?? null) ? $entry['agent'] : [];
        $user = is_array($entry['user'] ?? null) ? $entry['user'] : [];
        $found[] = [
            'change_id' => (string) ($entry['id'] ?? ''),
            'ability' => (string) ($entry['ability'] ?? ''),
            'recorded_at' => (string) ($entry['recorded_at'] ?? ''),
            'session_id' => (string) ($entry['session'] ?? ''),
            'by' => trim((string) ($agent['label'] ?? '') . ' ' . (string) ($agent['client'] ?? '')) ?: (string) ($user['login'] ?? ''),
        ];
        if (count($found) >= 5) {
            break;
        }
    }

    return $found;
}

/**
 * Undo every change a session made, newest first, verifying each.
 *
 * @return array<string, mixed>|WP_Error WP_Error only for a call that could not be planned at all
 *         (unknown session, too large, another run in progress). Everything else — refused,
 *         stopped part way, completed — is a result that says which.
 */
function wppilot_undo_session(string $session, bool $allow_partial = false): array|WP_Error
{
    return wppilot_session_run('undo', $session, $allow_partial);
}

/**
 * Put back every change of a session that was undone, oldest first, verifying each.
 *
 * @return array<string, mixed>|WP_Error
 */
function wppilot_redo_session(string $session, bool $allow_partial = false): array|WP_Error
{
    return wppilot_session_run('redo', $session, $allow_partial);
}

/**
 * @return array<string, mixed>|WP_Error
 */
function wppilot_session_run(string $operation, string $session, bool $allow_partial): array|WP_Error
{
    $session = trim($session);
    if ($session === '' || strlen($session) > WPPILOT_SESSION_ID_MAX_LENGTH) {
        return new WP_Error('wppilot_session_invalid', __('Pass a session_id from wppilot/list-sessions.', domain: 'wppilot'), ['status' => 400]);
    }
    if (wppilot_count_change_log(['session' => $session, 'kind' => 'change']) === 0) {
        return new WP_Error('wppilot_session_not_found', __('No changes were recorded under that session. List sessions with wppilot/list-sessions.', domain: 'wppilot'), ['status' => 404]);
    }
    if (!wppilot_session_lock($session, acquire: true)) {
        return new WP_Error('wppilot_session_busy', __('Another undo or redo of this session is running. Wait for it to finish, then list the session again.', domain: 'wppilot'), ['status' => 409]);
    }

    try {
        $loaded = wppilot_session_rows($session);
        if ($loaded instanceof WP_Error) {
            return $loaded;
        }
        return $operation === 'undo'
            ? wppilot_session_execute_undo($session, wppilot_session_plan_undo($session, $loaded['rows'], $loaded['current']), $allow_partial)
            : wppilot_session_execute_redo($session, wppilot_session_plan_redo($session, $loaded['rows'], $loaded['current']), $allow_partial);
    } finally {
        wppilot_session_lock($session, acquire: false);
    }
}

/**
 * @param array{pending: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, irreversible: list<array<string, mixed>>, unchecked: list<array<string, mixed>>, already: int} $plan
 * @return array<string, mixed>
 */
function wppilot_session_execute_undo(string $session, array $plan, bool $allow_partial): array
{
    $report = [
        'session_id' => $session,
        'operation' => 'undo',
        'status' => '',
        'message' => '',
        'undone' => [],
        'failed' => null,
        'not_attempted' => [],
        'conflicts' => $plan['conflicts'],
        'not_reversible' => $plan['irreversible'],
        'unchecked' => $plan['unchecked'],
        'already_undone' => $plan['already'],
    ];

    if ($plan['pending'] === []) {
        $report['status'] = 'nothing-to-do';
        $report['message'] = $plan['irreversible'] !== []
            ? sprintf('Nothing was changed. The session has no change left that can be undone; %d cannot be undone at all (listed under not_reversible).', count($plan['irreversible']))
            : 'Nothing was changed. Every change of this session is already undone.';
        return $report;
    }
    if ($plan['conflicts'] !== []) {
        $report['status'] = 'refused';
        $report['not_attempted'] = wppilot_session_ids($plan['pending']);
        $report['message'] = sprintf(
            'Nothing was changed. %d of this session\'s targets were changed by something else after the session wrote to them (listed under conflicts); undoing would overwrite those edits. Review them, undo the later changes first if they should go too, or undo this session\'s other changes one by one with wppilot/rollback-change.',
            count($plan['conflicts']),
        );
        return $report;
    }
    if ($plan['irreversible'] !== [] && !$allow_partial) {
        $report['status'] = 'refused';
        $report['not_attempted'] = wppilot_session_ids($plan['pending']);
        $report['message'] = sprintf(
            'Nothing was changed. %1$d of this session\'s changes cannot be undone (listed under not_reversible, each with the reason). Call again with allow_partial: true to undo the other %2$d and leave those as they are.',
            count($plan['irreversible']),
            count($plan['pending']),
        );
        return $report;
    }

    $ids = wppilot_session_ids($plan['pending']);
    foreach ($ids as $index => $id) {
        $failure = wppilot_session_undo_one($id);
        if ($failure === null) {
            $report['undone'][] = $id;
            continue;
        }
        $report['failed'] = $failure;
        $report['not_attempted'] = array_slice($ids, $index + 1);
        $report['status'] = 'stopped';
        $report['message'] = sprintf(
            'Stopped at change %1$s: %2$s %3$d change(s) were undone and verified before it; %4$d were not attempted. The site is part way back: redo-session puts the undone ones back, or fix the cause and run undo-session again to continue.',
            $id,
            (string) $failure['reason'],
            count($report['undone']),
            count($report['not_attempted']),
        );
        return $report;
    }

    $report['status'] = 'completed';
    $report['message'] = sprintf(
        'Undid and verified all %d change(s) this session could undo.%s',
        count($report['undone']),
        $plan['irreversible'] !== [] ? sprintf(' %d change(s) could not be undone and were left as they are (not_reversible).', count($plan['irreversible'])) : '',
    );

    return $report;
}

/**
 * Undo one change of a session run, re-checking its target just before touching it.
 *
 * @return array<string, mixed>|null Null on success, else what failed.
 */
function wppilot_session_undo_one(string $id): ?array
{
    $entry = wppilot_get_change($id);
    if ($entry === null) {
        return ['change_id' => $id, 'code' => 'wppilot_change_not_found', 'reason' => 'The change record disappeared.'];
    }
    if (($entry['rolled_back'] ?? false) === true) {
        return null;
    }
    $rollback = is_array($entry['rollback'] ?? null) ? wppilot_string_keyed_array($entry['rollback']) : [];
    $after = wppilot_change_digest_or_null(is_array($entry['after'] ?? null) ? $entry['after'] : null);
    if ($after !== null) {
        // The plan checked the newest write to each target. An older write is only reachable now,
        // after the newer ones were undone, and a request running alongside could have written
        // since the plan was made.
        $now = wppilot_change_state_digest(wppilot_change_current_state($rollback, bounded: false));
        if ($now !== null && !hash_equals($after['fingerprint'], $now['fingerprint'])) {
            return [
                'change_id' => $id,
                'ability' => (string) ($entry['ability'] ?? ''),
                'code' => 'wppilot_session_conflict',
                'reason' => 'Its target no longer matches the state this change left, so something else changed it.',
                'changed' => wppilot_change_digest_diff($after, $now),
            ];
        }
    }
    $result = wppilot_rollback_change($id);
    if ($result instanceof WP_Error) {
        return [
            'change_id' => $id,
            'ability' => (string) ($entry['ability'] ?? ''),
            'code' => $result->get_error_code(),
            'reason' => $result->get_error_message(),
        ];
    }

    return null;
}

/**
 * @param array{pending: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, unavailable: list<array<string, mixed>>, unchecked: list<array<string, mixed>>, active: int} $plan
 * @return array<string, mixed>
 */
function wppilot_session_execute_redo(string $session, array $plan, bool $allow_partial): array
{
    $report = [
        'session_id' => $session,
        'operation' => 'redo',
        'status' => '',
        'message' => '',
        'redone' => [],
        'failed' => null,
        'not_attempted' => [],
        'conflicts' => $plan['conflicts'],
        'not_redoable' => $plan['unavailable'],
        'unchecked' => $plan['unchecked'],
        'not_undone' => $plan['active'],
    ];

    if ($plan['pending'] === []) {
        $report['status'] = 'nothing-to-do';
        $report['message'] = $plan['unavailable'] !== []
            ? sprintf('Nothing was changed. %d undone change(s) cannot be redone (listed under not_redoable).', count($plan['unavailable']))
            : 'Nothing was changed. No change of this session is undone.';
        return $report;
    }
    if ($plan['conflicts'] !== []) {
        $report['status'] = 'refused';
        $report['not_attempted'] = wppilot_session_ids($plan['pending']);
        $report['message'] = sprintf(
            'Nothing was changed. %d target(s) were changed by something else after this session was undone (listed under conflicts); redoing would overwrite those edits.',
            count($plan['conflicts']),
        );
        return $report;
    }
    if ($plan['unavailable'] !== [] && !$allow_partial) {
        $report['status'] = 'refused';
        $report['not_attempted'] = wppilot_session_ids($plan['pending']);
        $report['message'] = sprintf(
            'Nothing was changed. %1$d undone change(s) cannot be redone (listed under not_redoable, each with the reason). Call again with allow_partial: true to redo the other %2$d.',
            count($plan['unavailable']),
            count($plan['pending']),
        );
        return $report;
    }

    $ids = wppilot_session_ids($plan['pending']);
    foreach ($ids as $index => $id) {
        $failure = wppilot_session_redo_one($id);
        if ($failure === null) {
            $report['redone'][] = $id;
            continue;
        }
        $report['failed'] = $failure;
        $report['not_attempted'] = array_slice($ids, $index + 1);
        $report['status'] = 'stopped';
        $report['message'] = sprintf(
            'Stopped at change %1$s: %2$s %3$d change(s) were redone and verified before it; %4$d were not attempted. undo-session takes the redone ones back out.',
            $id,
            (string) $failure['reason'],
            count($report['redone']),
            count($report['not_attempted']),
        );
        return $report;
    }

    $report['status'] = 'completed';
    $report['message'] = sprintf(
        'Redid and verified all %d undone change(s) of this session.%s',
        count($report['redone']),
        $plan['unavailable'] !== [] ? sprintf(' %d could not be redone and were left undone (not_redoable).', count($plan['unavailable'])) : '',
    );

    return $report;
}

/**
 * Redo one undone change: check the target is still as the undo left it, put the redo image back,
 * and prove the result is the state the change first produced.
 *
 * @return array<string, mixed>|null Null on success, else what failed.
 */
function wppilot_session_redo_one(string $id): ?array
{
    $entry = wppilot_get_change($id);
    if ($entry === null) {
        return ['change_id' => $id, 'code' => 'wppilot_change_not_found', 'reason' => 'The change record disappeared.'];
    }
    if (($entry['rolled_back'] ?? false) !== true) {
        return null;
    }
    $ability = (string) ($entry['ability'] ?? '');
    $redo = is_array($entry['redo'] ?? null) ? $entry['redo'] : [];
    $snapshot = is_array($redo['snapshot'] ?? null) ? wppilot_string_keyed_array($redo['snapshot']) : null;
    if (($redo['available'] ?? false) !== true || $snapshot === null) {
        return ['change_id' => $id, 'ability' => $ability, 'code' => 'wppilot_redo_unavailable', 'reason' => (string) ($redo['reason'] ?? 'No redo image was kept.')];
    }
    $rollback = is_array($entry['rollback'] ?? null) ? wppilot_string_keyed_array($entry['rollback']) : [];
    $undone = wppilot_change_digest_or_null(is_array($entry['undone_digest'] ?? null) ? $entry['undone_digest'] : null);
    if ($undone !== null) {
        $now = wppilot_change_state_digest(wppilot_change_current_state($rollback, bounded: false));
        if ($now !== null && !hash_equals($undone['fingerprint'], $now['fingerprint'])) {
            return [
                'change_id' => $id,
                'ability' => $ability,
                'code' => 'wppilot_session_conflict',
                'reason' => 'Its target no longer matches the state the undo left, so something else changed it.',
                'changed' => wppilot_change_digest_diff($undone, $now),
            ];
        }
    }

    $result = wppilot_change_restore_redo($snapshot, $entry);
    if ($result instanceof WP_Error) {
        return ['change_id' => $id, 'ability' => $ability, 'code' => $result->get_error_code(), 'reason' => $result->get_error_message()];
    }
    if (($result['verified'] ?? false) !== true) {
        $mismatched = is_array($result['mismatched'] ?? null) ? array_map('strval', array_slice($result['mismatched'], 0, 12)) : [];
        return [
            'change_id' => $id,
            'ability' => $ability,
            'code' => 'wppilot_redo_unverified',
            'reason' => 'The redo ran but the target did not read back as the state it restored.' . ($mismatched !== [] ? ' Still different: ' . implode(', ', $mismatched) . '.' : ''),
        ];
    }
    // The restore proved the image is back. This proves the image was the change's own result.
    $after = wppilot_change_digest_or_null(is_array($entry['after'] ?? null) ? $entry['after'] : null);
    if ($after !== null) {
        $now = wppilot_change_state_digest(wppilot_change_current_state($rollback, bounded: false));
        if ($now === null || !hash_equals($after['fingerprint'], $now['fingerprint'])) {
            return [
                'change_id' => $id,
                'ability' => $ability,
                'code' => 'wppilot_redo_unverified',
                'reason' => 'The redo ran but the target does not match the state recorded when the change was first made.',
                'changed' => $now === null ? [] : wppilot_change_digest_diff($after, $now),
            ];
        }
    }

    $entry['rolled_back'] = false;
    unset($entry['rolled_back_at'], $entry['redo'], $entry['undone_digest']);
    $entry['redone_at'] = gmdate('c');
    $entry['redo_result'] = $result;
    wppilot_replace_change($id, $entry);

    return null;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<string>
 */
function wppilot_session_ids(array $rows): array
{
    return array_map(static fn(array $row): string => (string) $row['id'], $rows);
}

/**
 * One session run at a time: two undos of one session racing would each undo half and both
 * report the other half as someone else's conflict.
 */
function wppilot_session_lock(string $session, bool $acquire): bool
{
    $wpdb = wppilot_changes_wpdb();
    if ($wpdb === null || !method_exists($wpdb, 'get_var')) {
        return true;
    }
    $name = 'wppilot_session_' . md5($session);
    if (!$acquire) {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        return true;
    }

    return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, 0)) === '1';
}

/**
 * A session with its changes and what undo and redo would do now, for list-sessions and the
 * Changes screen.
 *
 * @return array<string, mixed>|WP_Error
 */
function wppilot_session_detail(string $session, int $limit = 100): array|WP_Error
{
    $loaded = wppilot_session_rows($session);
    if ($loaded instanceof WP_Error) {
        return $loaded;
    }
    if ($loaded['rows'] === []) {
        return new WP_Error('wppilot_session_not_found', __('No changes were recorded under that session.', domain: 'wppilot'), ['status' => 404]);
    }
    $undo = wppilot_session_plan_undo($session, $loaded['rows'], $loaded['current']);
    $redo = wppilot_session_plan_redo($session, $loaded['rows'], $loaded['current']);

    return [
        'session_id' => $session,
        'changes' => array_map(
            static fn(array $row): array => [
                'change_id' => $row['id'],
                'ability' => $row['ability'],
                'recorded_at' => $row['recorded_at'],
                'status' => $row['status'],
                'target' => $row['target'],
            ],
            array_slice($loaded['rows'], 0, max(1, $limit)),
        ),
        'total_changes' => count($loaded['rows']),
        'undo' => [
            'can_undo' => count($undo['pending']),
            'ready' => $undo['pending'] !== [] && $undo['conflicts'] === [] && $undo['irreversible'] === [],
            'conflicts' => $undo['conflicts'],
            'not_reversible' => $undo['irreversible'],
            'unchecked' => $undo['unchecked'],
            'already_undone' => $undo['already'],
        ],
        'redo' => [
            'can_redo' => count($redo['pending']),
            'ready' => $redo['pending'] !== [] && $redo['conflicts'] === [] && $redo['unavailable'] === [],
            'conflicts' => $redo['conflicts'],
            'not_redoable' => $redo['unavailable'],
            'unchecked' => $redo['unchecked'],
        ],
    ];
}
