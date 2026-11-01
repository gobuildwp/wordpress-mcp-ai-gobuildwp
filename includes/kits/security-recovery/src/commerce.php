<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityRecovery;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Counts of what was added or changed since a point in time.
 *
 * Every number is one COUNT(*) over a dated, indexed column, with a five-second
 * statement limit (MySQL reads the hint; MariaDB ignores it). A query that fails
 * gives null for its count rather than failing the whole answer: a restore
 * decision is better made with four numbers than with none. Nothing here reads
 * a row's contents.
 */

/** WooCommerce's order type, in the HPOS table's `type` column and in posts' `post_type`. */
const ORDER_TYPE = 'shop_order';

/** Milliseconds each count may run. */
const QUERY_TIMEOUT_MS = 5000;

/** Order states that never were an order: an unsaved admin screen and an abandoned block checkout. */
const NOT_ORDERS = ['auto-draft', 'checkout-draft', 'wc-checkout-draft'];

/** Form plugins with their own entry table: the table (without prefix), its date column and whether that column is UTC. */
const FORM_TABLES = [
    'wpforms' => ['table' => 'wpforms_entries', 'column' => 'date', 'utc' => true],
    'gravityforms' => ['table' => 'gf_entry', 'column' => 'date_created', 'utc' => true],
    // Fluent Forms stores created_at in the site's time zone.
    'fluentforms' => ['table' => 'fluentform_submissions', 'column' => 'created_at', 'utc' => false],
];

/**
 * The answer to gobuildwp/commerce-activity-since.
 *
 * $woo and $hpos are detected when null; tests pass them.
 *
 * @return array{since: int, now: int, woocommerce: bool, orders: int|null, customers: int|null, users: int|null, comments: int|null, posts_changed: int|null, form_entries: array<string, int>}
 */
function activity_since(int $since, ?int $now = null, ?bool $woo = null, ?bool $hpos = null, ?object $db = null): array
{
    $now ??= time();
    $woo ??= woocommerce_active();
    if ($db === null) {
        global $wpdb;
        $db = $wpdb;
    }
    $from = gmdate('Y-m-d H:i:s', $since);

    return [
        'since' => $since,
        'now' => $now,
        'woocommerce' => $woo,
        'orders' => $woo ? count_orders($db, $from, $hpos ?? hpos_enabled()) : null,
        'customers' => $woo ? count_customers($db, $from) : null,
        'users' => count_users($db, $from),
        'comments' => count_comments($db, $from),
        'posts_changed' => count_posts_changed($db, $from),
        'form_entries' => count_form_entries($db, $since),
    ];
}

function woocommerce_active(): bool
{
    return class_exists('WooCommerce') || defined('WC_VERSION');
}

/**
 * Whether orders live in WooCommerce's own tables (HPOS) rather than in posts.
 */
function hpos_enabled(): bool
{
    if (class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')) {
        return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    return get_option('woocommerce_custom_orders_table_enabled') === 'yes';
}

/**
 * Orders created since, in whichever table is authoritative. Refunds are their own type and are not counted.
 */
function count_orders(object $db, string $from, bool $hpos): ?int
{
    $skip = implode(', ', array_fill(0, count(NOT_ORDERS), '%s'));
    if ($hpos) {
        $sql = "SELECT COUNT(*) FROM {$db->prefix}wc_orders WHERE type = %s AND date_created_gmt >= %s AND status NOT IN ({$skip})";
    } else {
        $sql = "SELECT COUNT(*) FROM {$db->posts} WHERE post_type = %s AND post_date_gmt >= %s AND post_status NOT IN ({$skip})";
    }

    return count_query($db, $sql, array_merge([ORDER_TYPE, $from], NOT_ORDERS));
}

/**
 * Users with the customer role on this site, registered since.
 */
function count_customers(object $db, string $from): ?int
{
    $sql = "SELECT COUNT(*) FROM {$db->users} u INNER JOIN {$db->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s"
        . ' WHERE u.user_registered >= %s AND m.meta_value LIKE %s';

    return count_query($db, $sql, [capabilities_key($db), $from, '%' . $db->esc_like('"customer"') . '%']);
}

/**
 * Users registered since. On a network, only those with a role on this site.
 */
function count_users(object $db, string $from): ?int
{
    if (function_exists('is_multisite') && is_multisite()) {
        $sql = "SELECT COUNT(*) FROM {$db->users} u INNER JOIN {$db->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s"
            . ' WHERE u.user_registered >= %s';
        return count_query($db, $sql, [capabilities_key($db), $from]);
    }

    return count_query($db, "SELECT COUNT(*) FROM {$db->users} WHERE user_registered >= %s", [$from]);
}

/**
 * Approved and pending comments since. WooCommerce keeps order notes and webhook
 * logs as comments; those are not visitors' comments and are left out.
 */
function count_comments(object $db, string $from): ?int
{
    $sql = "SELECT COUNT(*) FROM {$db->comments} WHERE comment_date_gmt >= %s AND comment_approved IN ('0', '1')"
        . " AND comment_type NOT IN ('order_note', 'webhook_delivery')";

    return count_query($db, $sql, [$from]);
}

/**
 * Posts, pages and products edited since (published, private or draft).
 */
function count_posts_changed(object $db, string $from): ?int
{
    $sql = "SELECT COUNT(*) FROM {$db->posts} WHERE post_type IN ('post', 'page', 'product')"
        . " AND post_status IN ('publish', 'private', 'draft') AND post_modified_gmt >= %s";

    return count_query($db, $sql, [$from]);
}

/**
 * Entries since, per form plugin whose table exists. A plugin whose count fails is left out.
 *
 * @return array<string, int>
 */
function count_form_entries(object $db, int $since): array
{
    $counts = [];
    foreach (FORM_TABLES as $plugin => $spec) {
        $table = $db->prefix . $spec['table'];
        if (!table_exists($db, $table)) {
            continue;
        }
        $at = $spec['utc'] ? $since : $since + (int) round((float) get_option('gmt_offset', 0) * 3600);
        $count = count_query($db, "SELECT COUNT(*) FROM {$table} WHERE {$spec['column']} >= %s", [gmdate('Y-m-d H:i:s', $at)]);
        if ($count !== null) {
            $counts[$plugin] = $count;
        }
    }

    return $counts;
}

function table_exists(object $db, string $table): bool
{
    $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($table)));

    return is_string($found) && $found === $table;
}

/**
 * This site's capabilities meta key (wp_capabilities, or wp_2_capabilities on a network).
 */
function capabilities_key(object $db): string
{
    return $db->get_blog_prefix() . 'capabilities';
}

/**
 * One COUNT(*), or null when it failed.
 *
 * @param list<string|int> $args
 */
function count_query(object $db, string $sql, array $args): ?int
{
    $sql = preg_replace('/^SELECT /', 'SELECT /*+ MAX_EXECUTION_TIME(' . QUERY_TIMEOUT_MS . ') */ ', $sql, 1) ?? $sql;
    $value = $db->get_var($db->prepare($sql, ...$args));
    if (($db->last_error ?? '') !== '' || $value === null || !is_numeric($value)) {
        return null;
    }

    return max(0, (int) $value);
}
