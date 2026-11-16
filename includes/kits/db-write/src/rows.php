<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\DbWrite;

use WP_Error;
use WPPilot\Kits\DbRead;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Ledger;

if (!defined('ABSPATH')) {
    exit();
}

/** The undo strategy for one inserted, updated or deleted row. */
const STRATEGY = 'kits/db-write-row';

/** Most rows one db-update-rows call changes. */
const MAX_UPDATE = 200;

/** Most rows one db-delete-rows call removes. */
const MAX_DELETE = 50;

/**
 * WordPress's own tables, without the prefix. Each has typed abilities (posts, terms, comments,
 * links) that keep caches, counts and hooks right; a raw row write skips all of them.
 */
const CORE_TABLES = [
    'posts', 'postmeta', 'comments', 'commentmeta', 'terms', 'termmeta', 'term_taxonomy',
    'term_relationships', 'links', 'options', 'users', 'usermeta',
    'blogs', 'blogmeta', 'blog_versions', 'site', 'sitemeta', 'signups', 'registration_log',
];

/**
 * Why a table may not be written, or '' when it may. Checked against the table's name without
 * the site prefix.
 */
function protected_reason(string $suffix, string $full): string
{
    $suffix = strtolower($suffix);
    foreach (CORE_TABLES as $core) {
        if ($suffix === $core) {
            return 'it is a WordPress core table; use the abilities made for posts, terms, comments, users and settings instead.';
        }
    }

    // The host's own tables (the change ledger, tokens, connections) and the kits'.
    $own = [Runtime\host()->id() . '_'];
    /** @var mixed $extra */
    $extra = Runtime\host()->extension('protected-table-prefixes');
    foreach (is_array($extra) ? $extra : [] as $prefix) {
        if (is_string($prefix) && $prefix !== '') {
            $own[] = strtolower($prefix);
        }
    }
    foreach ($own as $prefix) {
        if (str_starts_with($suffix, $prefix)) {
            return 'it belongs to the plugin that runs these abilities (its change log, tokens or settings), which a row write could corrupt or erase.';
        }
    }

    /**
     * Tables db-write must never change, with or without the site prefix. Can only add names.
     * The host adds its own list through the `protected-tables` extension point.
     *
     * @param list<string> $tables
     */
    $filtered = apply_filters('wppilot_kit_db_protected_tables', []);
    /** @var mixed $hosted */
    $hosted = Runtime\host()->extension('protected-tables');
    $names = array_merge(is_array($filtered) ? $filtered : [], is_array($hosted) ? $hosted : []);
    foreach ($names as $name) {
        if (is_string($name) && $name !== '' && in_array(strtolower($name), [$suffix, strtolower($full)], true)) {
            return 'the site protects it from row writes.';
        }
    }

    return '';
}

/**
 * The table, its single-column primary key and its columns, or why it may not be written.
 *
 * @return array{name: string, pk: string, auto: bool, columns: array<string, true>}|WP_Error
 */
function table_info(mixed $table): array|WP_Error
{
    $db = DbRead\wpdb();
    if ($db === null) {
        return new WP_Error('kit_db_write_unavailable', 'The WordPress database connection is not available.');
    }
    if (!is_string($table) || preg_match('/^[A-Za-z0-9_]{1,64}$/', $table) !== 1) {
        return new WP_Error('kit_db_write_bad_table', 'table must be a full table name: letters, digits and underscores only.', ['status' => 400]);
    }
    $row = $db->get_row($db->prepare(
        'SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $table,
    ), ARRAY_A);
    if (!is_array($row)) {
        return new WP_Error('kit_db_write_no_table', sprintf('There is no table named %s in this database. Use wppilot/database-tables to see what exists.', $table), ['status' => 404]);
    }
    $name = (string) ($row['name'] ?? $table);
    $prefix = (string) $db->prefix;
    $reason = DbRead\Sql\table_refusal($name, (string) ($row['type'] ?? ''), $prefix, (string) $db->base_prefix, is_multisite(), DbRead\denied_tables());
    if ($reason === '') {
        $reason = protected_reason(substr($name, strlen($prefix)), $name);
    }
    if ($reason !== '') {
        return new WP_Error('kit_db_write_table_refused', sprintf('%s cannot be written: %s', $name, $reason), ['status' => 403]);
    }

    $columns = $db->get_results($db->prepare(
        'SELECT COLUMN_NAME AS name, COLUMN_KEY AS col_key, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
        $name,
    ), ARRAY_A);
    $info = ['name' => $name, 'pk' => '', 'auto' => false, 'columns' => []];
    $keys = [];
    foreach (is_array($columns) ? $columns : [] as $column) {
        $column_name = (string) ($column['name'] ?? '');
        if ($column_name === '') {
            continue;
        }
        $info['columns'][$column_name] = true;
        if (strtoupper((string) ($column['col_key'] ?? '')) === 'PRI') {
            $keys[] = $column_name;
            $info['auto'] = str_contains(strtolower((string) ($column['extra'] ?? '')), 'auto_increment');
        }
    }
    if (count($keys) !== 1) {
        return new WP_Error(
            'kit_db_write_no_primary_key',
            sprintf('%s cannot be written: it has %s, and each row write is recorded and undone by a single-column primary key.', $name, $keys === [] ? 'no primary key' : 'a primary key over several columns'),
            ['status' => 403],
        );
    }
    $info['pk'] = $keys[0];
    // Every column name used in SQL below comes from this list, and is quoted with %i as well.
    foreach (array_keys($info['columns']) as $column_name) {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $column_name) !== 1) {
            return new WP_Error('kit_db_write_odd_column', sprintf('%s cannot be written: its column "%s" has a name this ability does not handle.', $name, $column_name), ['status' => 403]);
        }
    }

    return $info;
}

/**
 * Column values from the input: known columns, scalar or null values.
 *
 * @param array{name: string, pk: string, auto: bool, columns: array<string, true>} $info
 * @return array<string, string|int|float|null>|WP_Error
 */
function clean_values(mixed $values, array $info, string $field): array|WP_Error
{
    // A list (array_is_list() is PHP 8.1) has no column names.
    if (!is_array($values) || $values === [] || array_keys($values) === range(0, count($values) - 1)) {
        return new WP_Error('kit_db_write_bad_values', sprintf('%s must be an object of column => value, with at least one column.', $field), ['status' => 400]);
    }
    $clean = [];
    foreach ($values as $column => $value) {
        $column = (string) $column;
        if (!isset($info['columns'][$column])) {
            return new WP_Error('kit_db_write_unknown_column', sprintf('%s has no column "%s".', $info['name'], $column), ['status' => 400]);
        }
        if (is_bool($value)) {
            $value = (int) $value;
        }
        if ($value !== null && !is_string($value) && !is_int($value) && !is_float($value)) {
            return new WP_Error('kit_db_write_bad_value', sprintf('%s.%s must be a string, number, boolean or null.', $field, $column), ['status' => 400]);
        }
        $clean[$column] = $value;
    }
    return $clean;
}

/**
 * An equality WHERE over known, non-sensitive columns.
 *
 * Filtering on a password or token column is refused for the reason db-read refuses it: the
 * matched count (in a dry run or a count mismatch) would answer "does any row hold this secret".
 *
 * @param array{name: string, pk: string, auto: bool, columns: array<string, true>} $info
 * @return string|WP_Error The condition, prepared.
 */
function where_sql(mixed $where, array $info): string|WP_Error
{
    $values = clean_values($where, $info, 'where');
    if ($values instanceof WP_Error) {
        return $values;
    }
    $db = DbRead\wpdb();
    $parts = [];
    foreach ($values as $column => $value) {
        if ($column !== $info['pk'] && DbRead\Sql\is_sensitive_name($column)) {
            return new WP_Error('kit_db_write_sensitive_where', sprintf('where may not filter on %s: it looks like a credential or personal data column. Filter on the primary key or another column.', $column), ['status' => 400]);
        }
        $parts[] = $value === null
            ? $db->prepare('%i IS NULL', $column)
            : $db->prepare('%i = %s', $column, (string) $value);
    }
    return implode(' AND ', $parts);
}

/**
 * The rows a WHERE matches, by primary key, at most $cap + 1 of them.
 *
 * @param array{name: string, pk: string, auto: bool, columns: array<string, true>} $info
 * @return list<array<string, mixed>>
 */
function matching_rows(array $info, string $where, int $cap): array
{
    $db = DbRead\wpdb();
    $rows = $db->get_results(
        $db->prepare('SELECT * FROM %i WHERE ', $info['name']) . $where . $db->prepare(' ORDER BY %i ASC LIMIT %d', $info['pk'], $cap + 1),
        ARRAY_A,
    );
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

/**
 * @param array{name: string, pk: string, auto: bool, columns: array<string, true>} $info
 * @return array<string, mixed>|null
 */
function fetch_row(array $info, mixed $id): ?array
{
    $db = DbRead\wpdb();
    $row = $db->get_row($db->prepare('SELECT * FROM %i WHERE %i = %s', $info['name'], $info['pk'], (string) $id), ARRAY_A);
    return is_array($row) ? $row : null;
}

/**
 * The matched count must be what the caller expected, and within the cap.
 *
 * @param list<array<string, mixed>> $rows
 */
function count_refusal(mixed $expected, array $rows, int $cap): ?WP_Error
{
    if (!is_int($expected) || $expected < 1 || $expected > $cap) {
        return new WP_Error('kit_db_write_bad_expected_count', sprintf('expected_count is required: the number of rows the where should match, 1 to %d.', $cap), ['status' => 400]);
    }
    $matched = count($rows);
    if ($matched !== $expected) {
        return new WP_Error(
            'kit_db_write_count_mismatch',
            $matched > $cap
                ? sprintf('The where matches more than %d rows; nothing was changed. Narrow it.', $cap)
                : sprintf('The where matches %d rows, not the %d expected; nothing was changed. Check the rows with wppilot/database-query and send the count you mean.', $matched, $expected),
            ['status' => 409, 'matched' => $matched > $cap ? null : $matched, 'expected_count' => $expected],
        );
    }
    return null;
}

/**
 * One before-image: the state to put back (`row`, null when the row did not exist), and the
 * state the write left (`expect`, null when it removed the row), over `columns`.
 *
 * @param array{name: string, pk: string, auto: bool, columns: array<string, true>} $info
 * @param list<string> $columns
 * @param array<string, mixed>|null $row
 * @param array<string, mixed>|null $expect
 * @return array<string, mixed>
 */
function snapshot(string $op, array $info, mixed $id, array $columns, ?array $row, ?array $expect): array
{
    return [
        'type' => STRATEGY,
        'op' => $op,
        'table' => $info['name'],
        'pk' => $info['pk'],
        'id' => (string) $id,
        'columns' => $columns,
        'row' => $row,
        'expect' => $expect,
    ];
}

/**
 * @param array<string, mixed> $row
 * @param list<string> $columns
 * @return array<string, mixed>
 */
function only(array $row, array $columns): array
{
    return array_intersect_key($row, array_flip($columns));
}

/**
 * Whether a row read back holds the expected values. The database returns strings, so values
 * compare as strings; null matches only null.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $expect
 */
function matches(array $row, array $expect): bool
{
    foreach ($expect as $column => $value) {
        if (!array_key_exists($column, $row)) {
            return false;
        }
        $actual = $row[$column];
        if ($actual === null || $value === null ? $actual !== $value : (string) $actual !== (string) $value) {
            return false;
        }
    }
    return true;
}

/**
 * The snapshots must fit the ledger's budget for one call, or nothing is written.
 *
 * @param list<array<string, mixed>> $snapshots
 */
function budget_refusal(array $snapshots): ?WP_Error
{
    $bytes = 0;
    foreach ($snapshots as $snapshot) {
        $bytes += strlen(serialize($snapshot));
    }
    $budget = Runtime\host()->ledger()->snapshot_budget();
    if ($bytes > $budget) {
        return new WP_Error(
            'kit_db_write_too_large',
            sprintf('These rows need %d bytes of before-images, more than the change log keeps for one call (%d). Nothing was changed; write fewer rows per call.', $bytes, $budget),
            ['status' => 413],
        );
    }
    return null;
}

/**
 * @param list<array<string, mixed>> $items
 * @return list<string>
 */
function record(string $ability, array $items): array
{
    if ($items === []) {
        return [];
    }
    $recorded = Runtime\host()->ledger()->record_items($ability, $items);
    return array_values(array_filter($recorded['change_ids'] ?? [], 'is_string'));
}

/**
 * wppilot/db-insert-row.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function insert_row(array $input): array|WP_Error
{
    $info = table_info($input['table'] ?? null);
    if ($info instanceof WP_Error) {
        return $info;
    }
    $values = clean_values($input['values'] ?? null, $info, 'values');
    if ($values instanceof WP_Error) {
        return $values;
    }
    $pk = $info['pk'];
    $id = $values[$pk] ?? null;
    if ($id === null && !$info['auto']) {
        return new WP_Error('kit_db_write_pk_required', sprintf('%s.%s is not filled in by the database; give it in values.', $info['name'], $pk), ['status' => 400]);
    }
    if ($id !== null && fetch_row($info, $id) !== null) {
        return new WP_Error('kit_db_write_pk_taken', sprintf('%s already has a row with %s = %s.', $info['name'], $pk, (string) $id), ['status' => 409]);
    }
    $columns = array_keys($values);
    sort($columns);
    if (($input['dry_run'] ?? false) === true) {
        return ['dry_run' => true, 'table' => $info['name'], 'primary_key' => $pk, 'columns' => $columns, 'would_insert' => 1];
    }

    $db = DbRead\wpdb();
    if ($db->insert($info['name'], $values) === false) {
        return new WP_Error('kit_db_write_failed', 'The row was not inserted: ' . (string) $db->last_error, ['status' => 500]);
    }
    $id ??= (int) $db->insert_id;
    $after = fetch_row($info, $id);
    if ($after === null) {
        return new WP_Error('kit_db_write_unverified', sprintf('The insert reported success but no row with %s = %s could be read back.', $pk, (string) $id), ['status' => 500]);
    }
    $all = array_map('strval', array_keys($after));
    $change_ids = record('wppilot/db-insert-row', [[
        'input' => ['table' => $info['name'], 'id' => (string) $id],
        'before' => snapshot('insert', $info, $id, $all, null, $after),
        'result' => ['inserted' => true],
        'item' => ['table' => $info['name'], 'id' => (string) $id],
    ]]);

    return [
        'dry_run' => false,
        'table' => $info['name'],
        'primary_key' => $pk,
        'id' => is_int($id) ? $id : (string) $id,
        'columns' => $columns,
        'change_ids' => $change_ids,
    ];
}

/**
 * wppilot/db-update-rows.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function update_rows(array $input): array|WP_Error
{
    $info = table_info($input['table'] ?? null);
    if ($info instanceof WP_Error) {
        return $info;
    }
    $set = clean_values($input['set'] ?? null, $info, 'set');
    if ($set instanceof WP_Error) {
        return $set;
    }
    if (array_key_exists($info['pk'], $set)) {
        return new WP_Error('kit_db_write_pk_in_set', sprintf('set may not change the primary key %s: the row would lose the identity its undo uses.', $info['pk']), ['status' => 400]);
    }
    $where = where_sql($input['where'] ?? null, $info);
    if ($where instanceof WP_Error) {
        return $where;
    }
    $rows = matching_rows($info, $where, MAX_UPDATE);
    $refusal = count_refusal($input['expected_count'] ?? null, $rows, MAX_UPDATE);
    if ($refusal instanceof WP_Error) {
        return $refusal;
    }

    $columns = array_keys($set);
    sort($columns);
    $pk = $info['pk'];
    $plan = [];
    foreach ($rows as $row) {
        $id = $row[$pk] ?? null;
        $changes = array_values(array_filter($columns, static fn(string $c): bool => !matches($row, [$c => $set[$c]])));
        $plan[] = ['id' => $id, 'before' => only($row, $columns), 'changes' => $changes];
    }
    if (($input['dry_run'] ?? false) === true) {
        return [
            'dry_run' => true,
            'table' => $info['name'],
            'primary_key' => $pk,
            'matched' => count($rows),
            'would_change' => count(array_filter($plan, static fn(array $p): bool => $p['changes'] !== [])),
            'rows' => array_map(static fn(array $p): array => ['id' => $p['id'], 'columns_changing' => $p['changes']], $plan),
        ];
    }
    $refusal = budget_refusal(array_map(
        static fn(array $p): array => snapshot('update', $info, $p['id'], $columns, $p['before'], $set),
        $plan,
    ));
    if ($refusal instanceof WP_Error) {
        return $refusal;
    }

    $db = DbRead\wpdb();
    $items = [];
    $ids = [];
    $error = '';
    foreach ($plan as $p) {
        if ($db->update($info['name'], $set, [$pk => $p['id']]) === false) {
            $error = sprintf('Row %s = %s was not updated: %s', $pk, (string) $p['id'], (string) $db->last_error);
            break;
        }
        $after = fetch_row($info, $p['id']);
        $ids[] = $p['id'];
        $items[] = [
            'input' => ['table' => $info['name'], 'id' => (string) $p['id'], 'set' => $columns],
            'before' => snapshot('update', $info, $p['id'], $columns, $p['before'], $after === null ? $set : only($after, $columns)),
            'result' => ['updated' => true],
            'item' => ['table' => $info['name'], 'id' => (string) $p['id']],
        ];
    }
    $change_ids = record('wppilot/db-update-rows', $items);

    $result = [
        'dry_run' => false,
        'table' => $info['name'],
        'primary_key' => $pk,
        'matched' => count($rows),
        'updated' => count($ids),
        'ids' => $ids,
        'columns' => $columns,
        'change_ids' => $change_ids,
    ];
    if ($error !== '') {
        // The rows already written stay written, and recorded, so each can still be undone.
        $result['stopped'] = $error;
        $result['not_updated'] = count($rows) - count($ids);
    }
    return $result;
}

/**
 * wppilot/db-delete-rows.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function delete_rows(array $input): array|WP_Error
{
    $dry_run = ($input['dry_run'] ?? false) === true;
    if (!$dry_run) {
        $confirmed = Runtime\confirm_guard('wppilot/db-delete-rows', $input);
        if ($confirmed instanceof WP_Error) {
            return $confirmed;
        }
    }
    $info = table_info($input['table'] ?? null);
    if ($info instanceof WP_Error) {
        return $info;
    }
    $where = where_sql($input['where'] ?? null, $info);
    if ($where instanceof WP_Error) {
        return $where;
    }
    $rows = matching_rows($info, $where, MAX_DELETE);
    $refusal = count_refusal($input['expected_count'] ?? null, $rows, MAX_DELETE);
    if ($refusal instanceof WP_Error) {
        return $refusal;
    }
    $pk = $info['pk'];
    if ($dry_run) {
        return [
            'dry_run' => true,
            'table' => $info['name'],
            'primary_key' => $pk,
            'matched' => count($rows),
            'ids' => array_map(static fn(array $row): mixed => $row[$pk] ?? null, $rows),
        ];
    }
    $refusal = budget_refusal(array_map(
        static fn(array $row): array => snapshot('delete', $info, $row[$pk] ?? '', array_map('strval', array_keys($row)), $row, null),
        $rows,
    ));
    if ($refusal instanceof WP_Error) {
        return $refusal;
    }

    $db = DbRead\wpdb();
    $items = [];
    $ids = [];
    $error = '';
    foreach ($rows as $row) {
        $id = $row[$pk] ?? null;
        $deleted = $db->delete($info['name'], [$pk => $id]);
        if ($deleted === false) {
            $error = sprintf('Row %s = %s was not deleted: %s', $pk, (string) $id, (string) $db->last_error);
            break;
        }
        if ($deleted === 0) {
            // Gone already: someone else removed it between the read and this delete.
            continue;
        }
        $ids[] = $id;
        $items[] = [
            'input' => ['table' => $info['name'], 'id' => (string) $id],
            'before' => snapshot('delete', $info, $id, array_map('strval', array_keys($row)), $row, null),
            'result' => ['deleted' => true],
            'item' => ['table' => $info['name'], 'id' => (string) $id],
        ];
    }
    $change_ids = record('wppilot/db-delete-rows', $items);

    $result = [
        'dry_run' => false,
        'table' => $info['name'],
        'primary_key' => $pk,
        'matched' => count($rows),
        'deleted' => count($ids),
        'ids' => $ids,
        'change_ids' => $change_ids,
    ];
    if ($error !== '') {
        $result['stopped'] = $error;
    }
    return $result;
}

/**
 * Put one row back as it was before the write, unless it changed again since.
 *
 * @param array<string, mixed> $payload A ledger rollback payload; the snapshot is under `snapshot`.
 * @return array<string, mixed>|WP_Error
 */
function restore(array $payload): array|WP_Error
{
    $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $columns = array_values(array_filter(is_array($snapshot['columns'] ?? null) ? $snapshot['columns'] : [], 'is_string'));
    $row = is_array($snapshot['row'] ?? null) ? $snapshot['row'] : null;
    $expect = is_array($snapshot['expect'] ?? null) ? $snapshot['expect'] : null;
    $id = $snapshot['id'] ?? null;
    if (($snapshot['type'] ?? '') !== STRATEGY || !is_string($id) || $id === '' || $columns === [] || ($row === null && $expect === null)) {
        return new WP_Error('kit_rollback_bad_snapshot', 'The change record does not hold a row write.');
    }
    // The table is checked again: a table protected since the write is not written by its undo.
    $info = table_info($snapshot['table'] ?? null);
    if ($info instanceof WP_Error) {
        return $info;
    }
    if ($info['pk'] !== ($snapshot['pk'] ?? null)) {
        return new WP_Error('kit_db_write_schema_changed', sprintf('%s no longer has %s as its primary key, so the row cannot be found safely.', $info['name'], (string) ($snapshot['pk'] ?? '')));
    }

    $current = fetch_row($info, $id);
    $changed = $expect === null ? $current !== null : ($current === null || !matches($current, only($expect, $columns)));
    if ($changed) {
        return new WP_Error(
            'kit_db_write_changed_since',
            sprintf('Row %s = %s in %s changed after this write, so undoing it would overwrite that later change. Nothing was written.', $info['pk'], $id, $info['name']),
            ['status' => 409],
        );
    }

    $db = DbRead\wpdb();
    if ($row === null) {
        $written = $db->delete($info['name'], [$info['pk'] => $id]);
    } elseif ($current === null) {
        $written = $db->insert($info['name'], only($row, array_keys($info['columns'])));
    } else {
        $written = $db->update($info['name'], only($row, $columns), [$info['pk'] => $id]);
    }
    if ($written === false) {
        return new WP_Error('kit_db_write_restore_failed', 'The row could not be put back: ' . (string) $db->last_error);
    }

    $now = fetch_row($info, $id);
    return [
        'table' => $info['name'],
        'id' => $id,
        'restored' => true,
        'verified' => $row === null ? $now === null : ($now !== null && matches($now, only($row, $columns))),
    ];
}

function register(Ledger $ledger): void
{
    $ledger->register_strategy(STRATEGY, static fn(array $payload): array|WP_Error => restore($payload));
}
