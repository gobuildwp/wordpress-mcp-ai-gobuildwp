<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\DbRead;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/** Most rows one query returns. */
const MAX_ROWS = 200;

/** Longest string one cell returns before it is cut. */
const MAX_CELL = 1000;

/** Most bytes of rows one call returns; a wide result stops early and says so. */
const MAX_BYTES = 262_144;

/** Server-side statement timeout, where the server supports one. */
const TIMEOUT_MS = 5000;

/**
 * Tables that hold credentials, settings or personal data, without the table prefix.
 *
 * Each has an ability of its own that knows which parts are safe to show; raw SQL does not.
 * The filter can add names, never remove these.
 */
const DENIED_TABLES = [
    'users',
    'usermeta',
    'options',
    'sitemeta',
    'signups',
    'registration_log',
    'woocommerce_api_keys',
    'woocommerce_payment_tokens',
    'woocommerce_payment_tokenmeta',
];

/**
 * @return list<string> Lower-cased table names without the prefix.
 */
function denied_tables(): array
{
    /** @var mixed $extra */
    $extra = apply_filters('wppilot_kit_db_read_denied_tables', []);
    $names = DENIED_TABLES;
    foreach (is_array($extra) ? $extra : [] as $name) {
        if (is_string($name) && $name !== '') {
            $names[] = strtolower($name);
        }
    }
    return array_values(array_unique($names));
}

/**
 * WordPress's database object; typed loosely so a test can stand in for it.
 */
function wpdb(): ?object
{
    global $wpdb;
    return is_object($wpdb) ? $wpdb : null;
}

/**
 * Every table and view in this site's database, and which of them a query may read.
 *
 * @return array{known: array<string, true>, allowed: array<string, string>, refused: array<string, string>, rows: list<array<string, mixed>>}|WP_Error
 */
function tables(): array|WP_Error
{
    $db = wpdb();
    if ($db === null) {
        return new WP_Error('kit_db_read_unavailable', 'The WordPress database connection is not available.');
    }
    $rows = $db->get_results(
        'SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine, TABLE_ROWS AS rows_estimate,'
        . ' DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes, TABLE_COLLATION AS collation'
        . ' FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
        ARRAY_A,
    );
    if (!is_array($rows) || $rows === []) {
        return new WP_Error(
            'kit_db_read_no_tables',
            'The table list could not be read from information_schema' . ($db->last_error !== '' ? ': ' . $db->last_error : '.'),
        );
    }
    $denied = denied_tables();
    $result = ['known' => [], 'allowed' => [], 'refused' => [], 'rows' => []];
    foreach ($rows as $row) {
        $name = (string) ($row['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $lower = strtolower($name);
        $reason = Sql\table_refusal($name, (string) ($row['type'] ?? ''), (string) $db->prefix, (string) $db->base_prefix, is_multisite(), $denied);
        $result['known'][$lower] = true;
        if ($reason === '') {
            $result['allowed'][$lower] = $name;
        } else {
            $result['refused'][$lower] = $reason;
        }
        $row['refusal'] = $reason;
        $result['rows'][] = $row;
    }
    return $result;
}

/**
 * @return array{flavor: string, version: string}
 */
function server(): array
{
    $db = wpdb();
    return Sql\parse_server_version($db === null ? '' : (string) $db->get_var('SELECT VERSION()'));
}

/**
 * wppilot/database-tables: this site's tables with sizes and columns.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function list_tables(array $input): array|WP_Error
{
    $tables = tables();
    if ($tables instanceof WP_Error) {
        return $tables;
    }
    $db = wpdb();
    $prefix = strtolower((string) $db->prefix);
    $search = strtolower(trim((string) ($input['search'] ?? '')));
    $with_columns = ($input['include_columns'] ?? true) !== false;

    $listed = [];
    $hidden = 0;
    foreach ($tables['rows'] as $row) {
        $name = (string) $row['name'];
        $lower = strtolower($name);
        // Only this site's tables are described at all: another plugin's or another network
        // site's tables are not this site's business, and naming them is the first step to asking.
        if (!str_starts_with($lower, $prefix) || str_contains((string) $row['refusal'], 'another site')) {
            $hidden++;
            continue;
        }
        if ($search !== '' && !str_contains($lower, $search)) {
            continue;
        }
        $listed[$name] = [
            'name' => $name,
            'type' => strtoupper((string) $row['type']) === 'BASE TABLE' ? 'table' : 'view',
            'engine' => (string) ($row['engine'] ?? ''),
            'rows_estimate' => (int) ($row['rows_estimate'] ?? 0),
            'data_bytes' => (int) ($row['data_bytes'] ?? 0),
            'index_bytes' => (int) ($row['index_bytes'] ?? 0),
            'collation' => (string) ($row['collation'] ?? ''),
            'queryable' => $row['refusal'] === '',
            'refusal' => (string) $row['refusal'],
        ];
    }

    if ($with_columns && $listed !== []) {
        foreach (array_chunk(array_keys($listed), 100) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            $columns = $db->get_results(
                $db->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders -- the placeholder list is built to match $chunk.
                    "SELECT TABLE_NAME AS table_name, COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_KEY AS column_key FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders}) ORDER BY TABLE_NAME, ORDINAL_POSITION",
                    $chunk,
                ),
                ARRAY_A,
            );
            foreach (is_array($columns) ? $columns : [] as $column) {
                $table = (string) ($column['table_name'] ?? '');
                if (!isset($listed[$table])) {
                    continue;
                }
                $listed[$table]['columns'][] = [
                    'name' => (string) ($column['name'] ?? ''),
                    'type' => (string) ($column['type'] ?? ''),
                    'nullable' => ($column['nullable'] ?? '') === 'YES',
                    'key' => (string) ($column['column_key'] ?? ''),
                    'redacted_in_results' => Sql\is_sensitive_name((string) ($column['name'] ?? '')),
                ];
            }
        }
    }

    $server = server();
    return [
        'prefix' => (string) $db->prefix,
        'tables' => array_values($listed),
        'count' => count($listed),
        'other_tables_hidden' => $hidden,
        'server' => $server,
        'denied_tables' => array_map(static fn(string $name): string => $db->prefix . $name, denied_tables()),
    ];
}

/**
 * wppilot/database-query: one checked SELECT, read-only, capped and timed.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function query(array $input): array|WP_Error
{
    $tables = tables();
    if ($tables instanceof WP_Error) {
        return $tables;
    }
    $checked = Sql\validate((string) ($input['sql'] ?? ''), $tables);
    if ($checked instanceof WP_Error) {
        return $checked;
    }
    $limit = min(MAX_ROWS, max(1, (int) ($input['limit'] ?? 50)));
    $sql = $checked['sql'];
    if ($checked['order_without_limit']) {
        // MariaDB, and MySQL when it materializes a derived table, drop an ORDER BY inside a
        // subquery that has no LIMIT; the cap here keeps the order the caller asked for.
        $sql .= ' LIMIT ' . $limit;
    }
    $server = server();
    $wrapped = Sql\wrap($sql, $limit, TIMEOUT_MS, $server);

    $rows = execute_read_only($wrapped['sql']);
    if ($rows instanceof WP_Error) {
        return $rows;
    }
    return shape_result($rows, $limit, $checked, $server, $wrapped['timeout_ms']);
}

/**
 * Run one statement inside a read-only transaction, and roll it back whatever happens.
 *
 * The transaction is the backstop under the checks in sql.php: a function they did not know
 * about (a plugin's stored function, a UDF) that tries to write is refused by the server itself.
 *
 * @return list<array<string, mixed>>|WP_Error
 */
function execute_read_only(string $sql): array|WP_Error
{
    $db = wpdb();
    if ($db === null) {
        return new WP_Error('kit_db_read_unavailable', 'The WordPress database connection is not available.');
    }
    $suppressed = $db->suppress_errors(true);
    try {
        if ($db->query('START TRANSACTION READ ONLY') === false) {
            return new WP_Error(
                'kit_db_read_transaction',
                'The database refused to open a read-only transaction, so nothing was run: ' . $db->last_error,
            );
        }
        try {
            $rows = $db->get_results($sql, ARRAY_A);
            $error = (string) $db->last_error;
        } finally {
            $db->query('ROLLBACK');
        }
    } finally {
        $db->suppress_errors($suppressed);
    }
    if ($error !== '') {
        $hint = stripos($error, 'duplicate column') !== false
            ? ' Give every selected column a unique name (the query runs as a derived table).'
            : '';
        return new WP_Error('kit_db_read_query_failed', 'The database refused the query: ' . $error . $hint, ['status' => 400]);
    }
    return is_array($rows) ? array_values($rows) : [];
}

/**
 * Redact, cut and cap the rows.
 *
 * Redaction is by column name (see Sql\is_sensitive_name()), plus the value of a meta row whose
 * key names a secret. It guards against a secret landing in a transcript by accident; the
 * boundary against deliberate reads is the refused tables, the Developer profile and the audit
 * row every call leaves.
 *
 * @param list<array<string, mixed>> $rows
 * @param array{sql: string, tables: list<string>, sensitive: list<string>, order_without_limit: bool} $checked
 * @param array{flavor: string, version: string} $server
 * @return array<string, mixed>
 */
function shape_result(array $rows, int $limit, array $checked, array $server, ?int $timeout_ms): array
{
    $columns = $rows === [] ? [] : array_map('strval', array_keys($rows[0]));
    $redacted = array_values(array_filter($columns, static fn(string $column): bool => Sql\is_sensitive_name($column)));
    $pairs = [['meta_key', 'meta_value'], ['option_name', 'option_value']];

    $out = [];
    $bytes = 0;
    $cut = 0;
    $stopped = false;
    foreach ($rows as $row) {
        $clean = [];
        foreach ($row as $column => $value) {
            $column = (string) $column;
            if (in_array($column, $redacted, true)) {
                $clean[$column] = $value === null ? null : '[redacted]';
                continue;
            }
            if (is_string($value) && strlen($value) > MAX_CELL) {
                $value = substr($value, 0, MAX_CELL) . '…';
                $cut++;
            }
            $clean[$column] = $value;
        }
        foreach ($pairs as [$key, $value]) {
            if (isset($clean[$key], $clean[$value]) && is_string($clean[$key]) && Sql\is_sensitive_name($clean[$key]) && $clean[$value] !== null) {
                $clean[$value] = '[redacted]';
            }
        }
        $size = strlen((string) wp_json_encode($clean));
        if ($out !== [] && $bytes + $size > MAX_BYTES) {
            $stopped = true;
            break;
        }
        $bytes += $size;
        $out[] = $clean;
    }

    return [
        'columns' => $columns,
        'rows' => $out,
        'row_count' => count($out),
        'limit' => $limit,
        // Filled to the limit means there may be more; stopped means the byte budget ended it.
        'truncated' => $stopped || count($rows) >= $limit,
        'redacted_columns' => $redacted,
        'truncated_cells' => $cut,
        'tables' => $checked['tables'],
        'server' => $server,
        'timeout_ms' => $timeout_ms,
        'notes' => $timeout_ms === null
            ? ['This database server offers no statement timeout, so the query ran without one.']
            : [],
    ];
}
