<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Revisions;

use WP_Error;
use WP_Post;
use WPPilot\Design\Contract;
use WPPilot\Design\Cpt;
use WPPilot\Design\Store;

if (!defined('ABSPATH')) {
    exit();
}

const MAX_REVISIONS = 5;

/**
 * Cap design history at five snapshots while respecting a stricter site-wide
 * setting, including revisions being disabled entirely.
 */
function limit(int $number, WP_Post $post): int
{
    if ($number === 0) {
        return 0;
    }
    return $number < 0 ? MAX_REVISIONS : min($number, MAX_REVISIONS);
}

/**
 * Ensure the current state exists as a revision. WordPress normally saves the
 * new state after an update, but a post inserted directly as published has no
 * initial revision. Calling this before and after writes makes the first design
 * version recoverable too; WordPress de-duplicates an unchanged latest state.
 */
function snapshot_current(WP_Post $post): void
{
    if ($post->post_type !== Cpt\POST_TYPE || !wp_revisions_enabled($post)) {
        return;
    }
    wp_save_post_revision($post->ID);
}

/**
 * Previous restorable states, newest first. The revision matching the current
 * post is omitted.
 *
 * @return list<WP_Post>
 */
function history(WP_Post $post): array
{
    /** @var list<WP_Post> $history */
    $history = [];
    foreach (wp_get_post_revisions($post->ID, ['order' => 'DESC', 'orderby' => 'date ID']) as $revision) {
        if (!$revision instanceof WP_Post || wp_is_post_autosave($revision) !== false) {
            continue;
        }
        if ($revision->post_title === $post->post_title && $revision->post_content === $post->post_content) {
            continue;
        }
        $history[] = $revision;
    }
    return $history;
}

/** A revision only when it belongs to this design. */
function find(WP_Post $post, int $revision_id): ?WP_Post
{
    $revision = wp_get_post_revision($revision_id);
    if (!$revision instanceof WP_Post || (int) $revision->post_parent !== $post->ID) {
        return null;
    }
    return $revision;
}

/**
 * Restore a revision while preserving the semantic guarantee of an active
 * design. Inactive designs may restore an incomplete historical draft so it
 * can be repaired in the editor.
 */
function restore(WP_Post $post, WP_Post $revision, string $actor = 'user'): int|WP_Error
{
    if ((int) $revision->post_parent !== $post->ID) {
        return new WP_Error('revision_foreign', __('That revision does not belong to this design.', domain: 'wppilot'));
    }

    $inspection = Contract\inspect($revision->post_content);
    if (Store\get_active_slug() === $post->post_name && !$inspection['readiness']['ready']) {
        return new WP_Error(
            'revision_not_ready',
            __('This revision cannot replace the active design because it is incomplete. ', domain: 'wppilot')
                . Contract\activation_error($inspection),
        );
    }

    snapshot_current($post);
    $restored = wp_restore_post_revision($revision->ID);
    if (!is_int($restored) || $restored !== $post->ID) {
        return new WP_Error('revision_restore_failed', __(
            'The design revision could not be restored.',
            domain: 'wppilot',
        ));
    }

    update_post_meta($post->ID, Cpt\META_LAST_ACTOR, $actor === 'user' ? 'user' : 'agent');
    return $restored;
}
