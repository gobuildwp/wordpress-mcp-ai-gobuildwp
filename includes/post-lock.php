<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Edit-lock respect for agent writes.
 *
 * WordPress marks a post as "being edited" with the `_edit_lock` post meta
 * (`<unix time>:<user id>`), refreshed by the editor's heartbeat. The block
 * editor shows a takeover dialog to a second human, but REST and MCP writes
 * never see that dialog, so an agent could overwrite a page someone has open.
 * The write abilities call wppilot_post_lock_guard() after their own
 * permission checks and refuse with `post_locked` while another user holds a
 * fresh lock.
 *
 * wp_check_post_lock() lives in wp-admin/includes/post.php, which is not loaded
 * for REST or MCP requests, so the meta is read here the way core reads it.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Longest an agent may ask to wait for a lock to clear, in seconds. */
const WPPILOT_POST_LOCK_MAX_WAIT = 120;

/** How often the wait re-reads the lock, in seconds. */
const WPPILOT_POST_LOCK_POLL_INTERVAL = 5;

/**
 * Who holds a live edit lock on the post, other than the current user.
 *
 * Returns null when the post is free: no lock, a lock older than core's
 * `wp_check_post_lock_window` (150 s by default, the heartbeat stops refreshing
 * once the tab closes), a lock without a user, or a lock held by the current
 * user (the person's own open editor must not block their own agent).
 *
 * @return array{user_id: int, name: string, locked_at: int, expires_in: int}|null
 */
function wppilot_post_lock_holder(int $post_id): ?array
{
    if ($post_id <= 0) {
        return null;
    }

    /** @var mixed $lock */
    $lock = get_post_meta($post_id, '_edit_lock', true);
    $parts = is_string($lock) ? explode(':', $lock) : [];
    $time = (int) ($parts[0] ?? 0);
    $user = (int) ($parts[1] ?? 0);
    if ($user <= 0) {
        // Core falls back to _edit_last for a user-less lock; that only happens for locks
        // written before 3.6, which no live editor produces any more.
        return null;
    }

    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's post-lock window filter, read the way wp_check_post_lock() reads it.
    $window = max(1, (int) apply_filters('wp_check_post_lock_window', 150));
    $now = time();
    if ($time <= $now - $window || $user === get_current_user_id()) {
        return null;
    }

    $holder = function_exists('get_userdata') ? get_userdata($user) : false;
    $name = is_object($holder) && isset($holder->display_name) && (string) $holder->display_name !== ''
        ? (string) $holder->display_name
        : 'another user';

    return [
        'user_id' => $user,
        'name' => $name,
        'locked_at' => $time,
        'expires_in' => max(0, $time + $window - $now),
    ];
}

/**
 * Clamp an agent-supplied wait to 0..WPPILOT_POST_LOCK_MAX_WAIT seconds.
 */
function wppilot_post_lock_wait_seconds(mixed $value): int
{
    if (!is_int($value) && !(is_string($value) && ctype_digit($value)) && !is_float($value)) {
        return 0;
    }

    return max(0, min(WPPILOT_POST_LOCK_MAX_WAIT, (int) $value));
}

/**
 * Refuse a write while another user holds the post's edit lock.
 *
 * With $wait_seconds > 0 the guard re-reads the lock every few seconds until it
 * clears or the wait runs out, so an agent can let a person finish and close the
 * editor instead of failing at once. The lock is re-read from the database on
 * each pass because the heartbeat writes it from another request.
 *
 * @param callable(int): void|null $sleep Test seam; defaults to sleep().
 */
function wppilot_post_lock_guard(int $post_id, int $wait_seconds = 0, ?callable $sleep = null): ?WP_Error
{
    $holder = wppilot_post_lock_holder($post_id);
    if ($holder === null) {
        return null;
    }

    $wait_seconds = max(0, min(WPPILOT_POST_LOCK_MAX_WAIT, $wait_seconds));
    $sleep = $sleep ?? static function (int $seconds): void {
        sleep($seconds);
    };
    $waited = 0;
    $limit = (int) ini_get('max_execution_time');
    if ($wait_seconds > 0 && $limit > 0 && $limit < $wait_seconds + 60 && function_exists('set_time_limit')) {
        // Keep PHP's own limit from killing the request mid-wait; the write still has to run after.
        // A limit of 0 (CLI, or a host that already lifted it) is left alone.
        @set_time_limit($wait_seconds + 60); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled on some hosts; the wait still works within the existing limit.
    }
    while ($holder !== null && $waited < $wait_seconds) {
        $step = min(WPPILOT_POST_LOCK_POLL_INTERVAL, $wait_seconds - $waited);
        $sleep($step);
        $waited += $step;
        wp_cache_delete($post_id, 'post_meta');
        $holder = wppilot_post_lock_holder($post_id);
    }
    if ($holder === null) {
        return null;
    }

    return new WP_Error(
        'post_locked',
        sprintf(
            '%1$s is editing post %2$d right now, so the change was not made. Ask them to finish and close the editor, or retry with wait_seconds (up to %3$d) to wait for the lock to clear. The lock expires in about %4$d seconds if the editor is closed.',
            $holder['name'],
            $post_id,
            WPPILOT_POST_LOCK_MAX_WAIT,
            $holder['expires_in'],
        ),
        [
            'status' => 409,
            'post_id' => $post_id,
            'locked_by' => $holder['name'],
            'locked_by_user_id' => $holder['user_id'],
            'expires_in' => $holder['expires_in'],
            'waited_seconds' => $waited,
            'max_wait_seconds' => WPPILOT_POST_LOCK_MAX_WAIT,
        ],
    );
}

/**
 * The `wait_seconds` input property the guarded write abilities share.
 *
 * @return array<string, mixed>
 */
function wppilot_post_lock_wait_schema(): array
{
    return [
        'type' => 'integer',
        'minimum' => 0,
        'maximum' => WPPILOT_POST_LOCK_MAX_WAIT,
        'default' => 0,
        'description' => 'When another user has this post open in the editor, wait up to this many seconds (max 120) for them to close it before refusing with post_locked. Default 0 refuses at once.',
    ];
}
