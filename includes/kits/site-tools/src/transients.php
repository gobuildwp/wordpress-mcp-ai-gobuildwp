<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteTools\Transients;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

/** Most transients one `all` call deletes; the rest are reported as remaining. */
const BATCH = 5000;

const IRREVERSIBLE = 'Transients are caches kept in the options table (or the object cache); deleted values are not kept, and the code that set them regenerates them when it next needs them.';

/**
 * wppilot/transients-flush.
 *
 * `expired` (the default) is WordPress's own delete_expired_transients(), forced to the database
 * so rows an object cache left behind go too. `all` deletes every transient through
 * delete_transient()/delete_site_transient(), so each one's hooks and cache entries go with it,
 * and needs confirm: true, because a site that leans on a slow remote API feels that at once.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function flush(array $input): array|WP_Error
{
    global $wpdb;
    $scope = (string) ($input['scope'] ?? 'expired');
    if (!in_array($scope, ['expired', 'all'], true)) {
        return new WP_Error('kit_transients_bad_scope', 'scope is expired or all.', ['status' => 400]);
    }
    if ($scope === 'all' && ($input['confirm'] ?? null) !== true) {
        return new WP_Error(
            'kit_confirmation_required',
            'Deleting every transient makes each cached value be rebuilt on its next use, which can slow the site for a while. Obtain explicit user approval, then retry with confirm=true.',
            ['status' => 409],
        );
    }
    if (!is_object($wpdb)) {
        return new WP_Error('kit_transients_unavailable', 'The WordPress database connection is not available.');
    }
    $object_cache = wp_using_ext_object_cache();
    // Site transients live in this options table only on a single site; on a network they are in
    // sitemeta and shared by every site, which is not one site's to flush.
    $site_transients = !is_multisite();

    if ($scope === 'expired') {
        $before = count_expired($wpdb, $site_transients);
        delete_expired_transients(true);
        $after = count_expired($wpdb, $site_transients);
        $result = [
            'scope' => 'expired',
            'deleted' => max(0, $before - $after),
            'remaining_expired' => $after,
            'object_cache' => $object_cache,
            'notes' => $object_cache
                ? ['An external object cache holds this site\'s transients and expires them itself; this removed only expired rows left in the database.']
                : [],
        ];
    } else {
        $names = names($wpdb, '_transient_', BATCH + 1);
        $site_names = $site_transients ? names($wpdb, '_site_transient_', BATCH + 1) : [];
        $deleted = 0;
        $failed = 0;
        foreach (array_slice($names, 0, BATCH) as $name) {
            // With an object cache, delete_transient() only touches the cache and the rows in the
            // table are stale leftovers; those are removed as options.
            $gone = $object_cache ? delete_rows('_transient_', $name) : delete_transient($name);
            $gone ? $deleted++ : $failed++;
        }
        foreach (array_slice($site_names, 0, max(0, BATCH - count($names))) as $name) {
            $gone = $object_cache ? delete_rows('_site_transient_', $name) : delete_site_transient($name);
            $gone ? $deleted++ : $failed++;
        }
        $flushed = [];
        // Core's cache-compat.php supplies wp_cache_supports() for a drop-in that lacks it.
        if ($object_cache && wp_cache_supports('flush_group')) {
            foreach (['transient', 'site-transient'] as $group) {
                if (wp_cache_flush_group($group)) {
                    $flushed[] = $group;
                }
            }
        }
        $result = [
            'scope' => 'all',
            'deleted' => $deleted,
            'failed' => $failed,
            'remaining' => count($names) + count($site_names) > BATCH ? count_all($wpdb, $site_transients) : 0,
            'object_cache' => $object_cache,
            'flushed_cache_groups' => $flushed,
            'notes' => $object_cache && $flushed === []
                ? ['An external object cache holds this site\'s transients and cannot flush them by group; only rows in the database were deleted.']
                : [],
        ];
    }

    Runtime\host()->ledger()->record_items('wppilot/transients-flush', [[
        'input' => ['scope' => $scope],
        'before' => null,
        'result' => $result,
        'irreversible_reason' => IRREVERSIBLE,
    ]]);
    return $result;
}

/**
 * Delete a transient's value and timeout rows; true when either existed.
 */
function delete_rows(string $prefix, string $name): bool
{
    $value = delete_option($prefix . $name);
    $timeout = delete_option($prefix . 'timeout_' . $name);
    return $value || $timeout;
}

/**
 * Transient names (without the storage prefix) in this site's options table.
 *
 * @return list<string>
 */
function names(object $wpdb, string $prefix, int $limit): array
{
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_id LIMIT %d",
        $wpdb->esc_like($prefix) . '%',
        $wpdb->esc_like($prefix . 'timeout_') . '%',
        $limit,
    ));
    $names = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $names[] = substr((string) $row, strlen($prefix));
    }
    return $names;
}

function count_expired(object $wpdb, bool $site_transients): int
{
    $count = 0;
    foreach ($site_transients ? ['_transient_timeout_', '_site_transient_timeout_'] : ['_transient_timeout_'] as $prefix) {
        $count += (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
            $wpdb->esc_like($prefix) . '%',
            time(),
        ));
    }
    return $count;
}

function count_all(object $wpdb, bool $site_transients): int
{
    $count = 0;
    foreach ($site_transients ? ['_transient_', '_site_transient_'] : ['_transient_'] as $prefix) {
        $count += (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
            $wpdb->esc_like($prefix) . '%',
            $wpdb->esc_like($prefix . 'timeout_') . '%',
        ));
    }
    return $count;
}
