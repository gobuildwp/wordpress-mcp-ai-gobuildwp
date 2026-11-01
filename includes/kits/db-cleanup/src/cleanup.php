<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\DbCleanup;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Ledger;

if (!defined('ABSPATH')) {
    exit();
}

/** The undo strategy for one removed comment or revision. */
const STRATEGY = 'kits/db-cleanup-row';

/** What can be cleaned. */
const TARGETS = ['spam_comments', 'trash_comments', 'revisions'];

/** Most rows removed in one call, across all targets. */
const MAX_LIMIT = 500;

const DEFAULT_DAYS = 30;

/**
 * @param array<string, mixed> $input
 * @return array{targets: list<string>, days: int, limit: int, cutoff: string}
 */
function normalise(array $input): array
{
    $raw = $input['targets'] ?? TARGETS;
    $targets = array_values(array_filter(TARGETS, static fn(string $t): bool => is_array($raw) && in_array($t, $raw, true)));
    $days = isset($input['older_than_days']) ? max(1, min(3650, (int) $input['older_than_days'])) : DEFAULT_DAYS;
    $limit = isset($input['limit']) ? max(1, min(MAX_LIMIT, (int) $input['limit'])) : MAX_LIMIT;
    return [
        'targets' => $targets,
        'days' => $days,
        'limit' => $limit,
        'cutoff' => gmdate('Y-m-d H:i:s', time() - $days * 86400),
    ];
}

/** SQL selecting the ids of one target's rows older than the cutoff, oldest first. */
function ids_sql(string $target, string $cutoff, int $limit): string
{
    global $wpdb;
    if ($target === 'revisions') {
        return $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_modified_gmt < %s ORDER BY ID ASC LIMIT %d",
            $cutoff,
            $limit,
        );
    }
    return $wpdb->prepare(
        "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s AND comment_date_gmt < %s ORDER BY comment_ID ASC LIMIT %d",
        $target === 'spam_comments' ? 'spam' : 'trash',
        $cutoff,
        $limit,
    );
}

function count_target(string $target, string $cutoff): int
{
    global $wpdb;
    $sql = $target === 'revisions'
        ? $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_modified_gmt < %s", $cutoff)
        : $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s AND comment_date_gmt < %s",
            $target === 'spam_comments' ? 'spam' : 'trash',
            $cutoff,
        );
    return (int) $wpdb->get_var($sql);
}

/**
 * How many rows each target would remove. Changes nothing.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function preview(array $input): array
{
    $n = normalise($input);
    $counts = [];
    foreach ($n['targets'] as $target) {
        $counts[$target] = count_target($target, $n['cutoff']);
    }
    return [
        'counts' => $counts,
        'older_than_days' => $n['days'],
        'cutoff_gmt' => $n['cutoff'],
        'per_call_limit' => $n['limit'],
    ];
}

/**
 * The row and its meta, enough to put it back with its own ids.
 *
 * @return array<string, mixed>|null
 */
function snapshot(string $target, int $id): ?array
{
    global $wpdb;
    if ($target === 'revisions') {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id), ARRAY_A);
        $meta = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC", $id), ARRAY_A);
    } else {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->comments} WHERE comment_ID = %d", $id), ARRAY_A);
        $meta = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->commentmeta} WHERE comment_id = %d ORDER BY meta_id ASC", $id), ARRAY_A);
    }
    if (!is_array($row)) {
        return null;
    }
    return ['type' => STRATEGY, 'target' => $target, 'row' => $row, 'meta' => is_array($meta) ? $meta : []];
}

/**
 * Removes old spam comments, trashed comments and revisions, recording each one so it can be
 * put back. Stops at the limit, or earlier when the change log's snapshot budget is spent.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function cleanup(array $input): array|WP_Error
{
    $confirmed = Runtime\confirm_guard('wppilot/db-cleanup', $input);
    if ($confirmed instanceof WP_Error) {
        return $confirmed;
    }
    $n = normalise($input);
    if ($n['targets'] === []) {
        return new WP_Error('kit_db_cleanup_no_targets', 'Name at least one of: ' . implode(', ', TARGETS) . '.');
    }

    global $wpdb;
    $budget = Runtime\host()->ledger()->snapshot_budget();
    $used = 0;
    $left = $n['limit'];
    $deleted = array_fill_keys($n['targets'], 0);
    $failed = 0;
    $budget_hit = false;
    $items = [];

    foreach ($n['targets'] as $target) {
        if ($left <= 0 || $budget_hit) {
            break;
        }
        $ids = array_map('intval', $wpdb->get_col(ids_sql($target, $n['cutoff'], $left)));
        foreach ($ids as $id) {
            $snapshot = snapshot($target, $id);
            if ($snapshot === null) {
                continue;
            }
            $size = strlen(serialize($snapshot));
            if ($used + $size > $budget) {
                $budget_hit = true;
                break;
            }
            $ok = $target === 'revisions' ? wp_delete_post_revision($id) : wp_delete_comment($id, true);
            if (!$ok || $ok instanceof WP_Error) {
                $failed++;
                continue;
            }
            $used += $size;
            $left--;
            $deleted[$target]++;
            $items[] = [
                'input' => ['target' => $target, 'id' => $id],
                'before' => $snapshot,
                'result' => ['deleted' => true],
                'item' => ['target' => $target, 'id' => $id],
            ];
        }
    }

    $recorded = $items === [] ? ['change_ids' => []] : Runtime\host()->ledger()->record_items('wppilot/db-cleanup', $items);
    $remaining = [];
    foreach ($n['targets'] as $target) {
        $remaining[$target] = count_target($target, $n['cutoff']);
    }
    return [
        'deleted' => $deleted,
        'failed' => $failed,
        'remaining' => $remaining,
        'stopped_at_snapshot_budget' => $budget_hit,
        'older_than_days' => $n['days'],
        'change_ids' => $recorded['change_ids'] ?? [],
    ];
}

/**
 * Put one removed comment or revision back with its own id and meta.
 *
 * Refused when the id is taken again, or when the post a revision or comment belonged to is gone.
 *
 * @param array<string, mixed> $payload A ledger rollback payload; the snapshot is under `snapshot`.
 * @return array<string, mixed>|WP_Error
 */
function restore(array $payload): array|WP_Error
{
    global $wpdb;
    $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $target = (string) ($snapshot['target'] ?? '');
    $row = is_array($snapshot['row'] ?? null) ? $snapshot['row'] : null;
    $meta = is_array($snapshot['meta'] ?? null) ? $snapshot['meta'] : [];
    if (!in_array($target, TARGETS, true) || $row === null) {
        return new WP_Error('kit_rollback_bad_snapshot', 'The change record does not hold a removed comment or revision.');
    }

    $revision = $target === 'revisions';
    $id = (int) ($revision ? ($row['ID'] ?? 0) : ($row['comment_ID'] ?? 0));
    $parent = (int) ($revision ? ($row['post_parent'] ?? 0) : ($row['comment_post_ID'] ?? 0));
    $table = $revision ? $wpdb->posts : $wpdb->comments;
    $id_column = $revision ? 'ID' : 'comment_ID';
    if ($id <= 0) {
        return new WP_Error('kit_rollback_bad_snapshot', 'The change record has no row id.');
    }
    if ($wpdb->get_var($wpdb->prepare("SELECT {$id_column} FROM {$table} WHERE {$id_column} = %d", $id)) !== null) {
        return new WP_Error('kit_db_cleanup_id_taken', sprintf('Row %d exists again, so it was not overwritten.', $id));
    }
    if ($parent > 0 && $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $parent)) === null) {
        return new WP_Error('kit_db_cleanup_parent_gone', sprintf('Post %d it belonged to no longer exists.', $parent));
    }

    $columns = [];
    foreach ($row as $column => $value) {
        if (is_string($column) && preg_match('/^[A-Za-z0-9_]+$/', $column) === 1) {
            $columns[$column] = $value;
        }
    }
    if ($wpdb->insert($table, $columns) === false) {
        return new WP_Error('kit_db_cleanup_restore_failed', 'The row could not be inserted again: ' . (string) $wpdb->last_error);
    }
    $meta_table = $revision ? $wpdb->postmeta : $wpdb->commentmeta;
    $restored_meta = 0;
    foreach ($meta as $m) {
        if (!is_array($m)) {
            continue;
        }
        $clean = [];
        foreach (['meta_id', $revision ? 'post_id' : 'comment_id', 'meta_key', 'meta_value'] as $column) {
            if (array_key_exists($column, $m)) {
                $clean[$column] = $m[$column];
            }
        }
        if ($wpdb->insert($meta_table, $clean) !== false) {
            $restored_meta++;
        }
    }

    if ($revision) {
        clean_post_cache($id);
    } else {
        clean_comment_cache($id);
        if ($parent > 0) {
            wp_update_comment_count($parent);
        }
    }
    return [
        'target' => $target,
        'id' => $id,
        'restored' => true,
        'meta_restored' => $restored_meta,
        'meta_expected' => count($meta),
        'verified' => $wpdb->get_var($wpdb->prepare("SELECT {$id_column} FROM {$table} WHERE {$id_column} = %d", $id)) !== null,
    ];
}

function register(Ledger $ledger): void
{
    $ledger->register_strategy(STRATEGY, static fn(array $payload): array|WP_Error => restore($payload));
}
