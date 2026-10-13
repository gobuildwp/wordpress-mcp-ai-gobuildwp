<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom table; WordPress has no API for it. The table name is $wpdb->prefix plus a fixed suffix, never input, and every value goes through $wpdb->prepare(). Not cached: a row recorded or rolled back must be visible to the very next read.

/**
 * Change ledger storage: one table row per change.
 *
 * The ledger used to be one option holding at most 500 rows or 4 MB, rewritten whole on every
 * row. Two requests recording at once could each read the same log and the slower write erased
 * the faster one's row, every bulk call paid for a full rewrite, and a busy site lost its undo
 * history within days. Here each change is one row: recording is an INSERT that touches nothing
 * else, and retention is a daily prune instead of an eviction on every write.
 *
 * The row a caller stores is the row it reads back, key for key: the indexed columns are copies
 * taken for filtering, and the row itself is kept serialized the way the option kept it — PHP
 * serialization, not JSON, because before-images hold post meta as WordPress unserialized it, and
 * JSON would hand an object back as an array and so restore something the post never held.
 * The large payloads (input, result, rollback with its snapshot, design findings, bulk item and
 * rollback result) sit in their own columns, which is what lets the prune drop an old snapshot
 * without rewriting the rest of the row.
 *
 * Storage only switches to the table after every option row has been copied and the copy
 * verified. A site that cannot create the table (no CREATE privilege) keeps the option, says so
 * on the Diagnostics screen, and tries again hourly.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * 2 (1.16.0): session_id, so one agent session's writes can be found and undone together, and
 * redo_data, the state an undo replaced, so the undo can itself be undone.
 */
const WPPILOT_CHANGES_SCHEMA_VERSION = 2;

const WPPILOT_CHANGES_SCHEMA_OPTION = 'wppilot_changes_schema_version';

/** `table` once the migration is verified; absent while the option is still the store. */
const WPPILOT_CHANGES_STORAGE_OPTION = 'wppilot_changes_storage';

/** Where an interrupted migration resumes: the id of the last row copied. */
const WPPILOT_CHANGES_MIGRATION_OPTION = 'wppilot_changes_migration';

/** Why the table is not in use, shown on the Diagnostics screen. */
const WPPILOT_CHANGES_ERROR_OPTION = 'wppilot_changes_storage_error';

const WPPILOT_CHANGES_PRUNE_HOOK = 'wppilot_changes_prune';

/** How long after a failure the install and migration are tried again. */
const WPPILOT_CHANGES_RETRY_SECONDS = 3600;

/** Option rows copied per migration step; the migration saves its place after each one. */
const WPPILOT_CHANGES_MIGRATION_BATCH = 50;

/**
 * Bytes one INSERT may carry. Hosts still ship max_allowed_packet as low as 1 MB, and a batch of
 * rows with half-megabyte before-images would otherwise be refused whole.
 */
const WPPILOT_CHANGES_INSERT_BATCH_BYTES = 786_432;

/** Rows removed per DELETE, so a first prune of a large backlog does not hold a long lock. */
const WPPILOT_CHANGES_PRUNE_BATCH = 500;

/** A rollback payload at or under this size is kept by the snapshot prune: it holds no image. */
const WPPILOT_CHANGES_PRUNE_MIN_BYTES = 1024;

/** Row keys whose values live in their own column, and that column. */
const WPPILOT_CHANGES_PAYLOAD_COLUMNS = [
    'input' => 'input_data',
    'result' => 'result_data',
    'rollback' => 'rollback_data',
    'design' => 'design_data',
    'bulk_item' => 'bulk_item_data',
    'rollback_result' => 'rollback_result_data',
    'redo' => 'redo_data',
];

/** @return wpdb|null */
function wppilot_changes_wpdb(): ?object
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;

    return is_object($wpdb) && method_exists($wpdb, 'prepare') && method_exists($wpdb, 'get_results') ? $wpdb : null;
}

function wppilot_changes_table(): string
{
    $wpdb = wppilot_changes_wpdb();

    return ($wpdb !== null ? (string) $wpdb->prefix : 'wp_') . 'wppilot_changes';
}

/**
 * Whether the ledger lives in the table. False until the migration from the option is verified.
 */
function wppilot_change_table_active(): bool
{
    return get_option(WPPILOT_CHANGES_STORAGE_OPTION, default_value: '') === 'table' && wppilot_changes_wpdb() !== null;
}

function wppilot_changes_schema_sql(string $table, string $charset_collate): string
{
    // change_id is unique because a rollback addresses a row by it, and it is what makes the
    // migration safe to repeat. seq, not recorded_at, orders the ledger: rows recorded in the same
    // second must still come back in the order they were written.
    return "CREATE TABLE {$table} (
            seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            change_id VARCHAR(191) NOT NULL,
            recorded_at DATETIME NULL DEFAULT NULL,
            kind VARCHAR(32) NOT NULL DEFAULT 'change',
            ability VARCHAR(191) NOT NULL DEFAULT '',
            risk VARCHAR(32) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            agent_credential VARCHAR(191) NOT NULL DEFAULT '',
            agent_label VARCHAR(191) NOT NULL DEFAULT '',
            agent_client VARCHAR(191) NOT NULL DEFAULT '',
            group_id VARCHAR(191) NOT NULL DEFAULT '',
            session_id VARCHAR(64) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'not-reversible',
            rolled_back_at DATETIME NULL DEFAULT NULL,
            entry_data LONGTEXT NOT NULL,
            input_data LONGTEXT NULL,
            result_data LONGTEXT NULL,
            rollback_data LONGTEXT NULL,
            design_data LONGTEXT NULL,
            bulk_item_data LONGTEXT NULL,
            rollback_result_data LONGTEXT NULL,
            redo_data LONGTEXT NULL,
            PRIMARY KEY  (seq),
            UNIQUE KEY change_id (change_id),
            KEY recorded_at (recorded_at),
            KEY ability (ability),
            KEY user_id (user_id),
            KEY group_id (group_id),
            KEY session_id (session_id),
            KEY status (status)
        ) {$charset_collate};";
}

/**
 * Create or upgrade the table, and prove it is there.
 *
 * dbDelta() reports nothing useful when CREATE is denied, so success is judged by reading every
 * column back rather than by what dbDelta() returned.
 *
 * @return string|null null when the table is ready, otherwise why not.
 */
function wppilot_changes_schema_install(): ?string
{
    $wpdb = wppilot_changes_wpdb();
    if ($wpdb === null) {
        return 'No database connection.';
    }
    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    $table = wppilot_changes_table();
    $charset = method_exists($wpdb, 'get_charset_collate') ? (string) $wpdb->get_charset_collate() : '';
    $suppressed = method_exists($wpdb, 'suppress_errors') ? $wpdb->suppress_errors(true) : false;
    dbDelta(wppilot_changes_schema_sql($table, $charset));
    $create_error = (string) ($wpdb->last_error ?? '');
    $columns = implode(', ', array_merge(
        ['seq', 'change_id', 'recorded_at', 'kind', 'ability', 'risk', 'user_id', 'agent_credential'],
        ['agent_label', 'agent_client', 'group_id', 'session_id', 'status', 'rolled_back_at', 'entry_data'],
        array_values(WPPILOT_CHANGES_PAYLOAD_COLUMNS),
    ));
    $ready = $wpdb->query("SELECT {$columns} FROM {$table} LIMIT 1") !== false;
    $read_error = (string) ($wpdb->last_error ?? '');
    if (method_exists($wpdb, 'suppress_errors')) {
        $wpdb->suppress_errors((bool) $suppressed);
    }

    if (!$ready) {
        $reason = $create_error !== '' ? $create_error : $read_error;
        return $reason !== '' ? $reason : 'The table could not be created.';
    }
    update_option(WPPILOT_CHANGES_SCHEMA_OPTION, WPPILOT_CHANGES_SCHEMA_VERSION, autoload: true);

    return null;
}

/**
 * Install and migrate when this site has not yet, or has not since a failure an hour ago.
 *
 * Runs on every request (plugins_loaded), so the settled case is two autoloaded option reads.
 */
function wppilot_changes_maybe_upgrade(): void
{
    if (
        wppilot_change_table_active()
        && (int) get_option(WPPILOT_CHANGES_SCHEMA_OPTION, default_value: 0) >= WPPILOT_CHANGES_SCHEMA_VERSION
    ) {
        return;
    }
    /** @var mixed $error */
    $error = get_option(WPPILOT_CHANGES_ERROR_OPTION, default_value: null);
    if (is_array($error) && time() - (int) ($error['at'] ?? 0) < WPPILOT_CHANGES_RETRY_SECONDS) {
        return;
    }
    wppilot_changes_install_and_migrate();
}

/**
 * Create the table, copy the option's rows into it, and switch storage once the copy is verified.
 *
 * Safe to run any number of times and to interrupt: rows are copied by id, so a repeated batch
 * inserts nothing twice, and the place reached is saved after every batch.
 *
 * @param int $max_batches Stop after this many batches (the rest resume on a later request).
 * @return array{storage: string, copied: int, done: bool, error: string}
 */
function wppilot_changes_install_and_migrate(int $max_batches = PHP_INT_MAX): array
{
    $wpdb = wppilot_changes_wpdb();
    if ($wpdb === null) {
        return ['storage' => 'option', 'copied' => 0, 'done' => false, 'error' => 'No database connection.'];
    }
    $error = wppilot_changes_schema_install();
    if ($error !== null) {
        wppilot_changes_record_error('create', $error);
        return ['storage' => 'option', 'copied' => 0, 'done' => false, 'error' => $error];
    }
    if (wppilot_change_table_active()) {
        delete_option(WPPILOT_CHANGES_ERROR_OPTION);
        return ['storage' => 'table', 'copied' => 0, 'done' => true, 'error' => ''];
    }

    // The option writer takes this same lock, so no row can be appended to the option between the
    // copy being verified and the option being deleted. Waiting is pointless: whoever holds it is
    // either writing one row or running this migration, and a later request resumes either way.
    if (!wppilot_changes_try_lock($wpdb, 0)) {
        return ['storage' => 'option', 'copied' => 0, 'done' => false, 'error' => ''];
    }
    try {
        return wppilot_changes_migrate_locked($max_batches);
    } finally {
        wppilot_changes_release_lock($wpdb);
    }
}

/**
 * @return array{storage: string, copied: int, done: bool, error: string}
 */
function wppilot_changes_migrate_locked(int $max_batches): array
{
    if (function_exists('wp_cache_delete')) {
        wp_cache_delete(WPPILOT_CHANGE_LOG_OPTION, 'options');
    }
    $rows = array_map('wppilot_change_row_with_id', wppilot_change_log_option_rows());
    $ids = array_map(static fn(array $row): string => (string) $row['id'], $rows);

    /** @var mixed $state */
    $state = get_option(WPPILOT_CHANGES_MIGRATION_OPTION, default_value: []);
    $state = is_array($state) ? $state : [];
    $copied = (int) ($state['copied'] ?? 0);
    $cursor = (string) ($state['cursor'] ?? '');
    // The option may have been rewritten since the last attempt (rows appended, the oldest capped
    // away), so the cursor is an id to find rather than an index to trust. Not found means start
    // over, which costs a lookup per row and copies nothing twice.
    $position = $cursor !== '' ? array_search($cursor, $ids, strict: true) : false;
    $start = $position === false ? 0 : ((int) $position) + 1;

    $batches = 0;
    for ($offset = $start; $offset < count($rows); $offset += WPPILOT_CHANGES_MIGRATION_BATCH) {
        if ($batches >= $max_batches) {
            return ['storage' => 'option', 'copied' => $copied, 'done' => false, 'error' => ''];
        }
        $batch = array_slice($rows, $offset, WPPILOT_CHANGES_MIGRATION_BATCH);
        $inserted = wppilot_change_table_insert_missing($batch);
        if ($inserted === null) {
            $error = wppilot_changes_last_error('A batch of ledger rows could not be copied into the table.');
            wppilot_changes_record_error('migrate', $error);
            return ['storage' => 'option', 'copied' => $copied, 'done' => false, 'error' => $error];
        }
        $copied += $inserted;
        $last = $batch[count($batch) - 1];
        update_option(WPPILOT_CHANGES_MIGRATION_OPTION, [
            'cursor' => (string) $last['id'],
            'copied' => $copied,
            'total' => count($rows),
            'at' => time(),
        ], autoload: false);
        $batches++;
    }

    $missing = wppilot_change_table_missing_ids($ids);
    if ($missing === null || $missing !== []) {
        $error = $missing === null
            ? wppilot_changes_last_error('The copied rows could not be read back.')
            : sprintf('%d of %d ledger rows were not found in the table after copying.', count($missing), count(array_unique($ids)));
        wppilot_changes_record_error('verify', $error);
        return ['storage' => 'option', 'copied' => $copied, 'done' => false, 'error' => $error];
    }

    update_option(WPPILOT_CHANGES_STORAGE_OPTION, 'table', autoload: true);
    delete_option(WPPILOT_CHANGES_MIGRATION_OPTION);
    delete_option(WPPILOT_CHANGES_ERROR_OPTION);
    delete_option(WPPILOT_CHANGE_LOG_OPTION);
    wppilot_changes_schedule_prune();

    return ['storage' => 'table', 'copied' => $copied, 'done' => true, 'error' => ''];
}

function wppilot_changes_try_lock(object $wpdb, int $timeout): bool
{
    if (!method_exists($wpdb, 'get_var')) {
        return true;
    }
    $lock = $wpdb->prefix . 'wppilot_change_log';

    return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, $timeout)) === '1';
}

function wppilot_changes_release_lock(object $wpdb): void
{
    if (method_exists($wpdb, 'get_var')) {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $wpdb->prefix . 'wppilot_change_log'));
    }
}

function wppilot_changes_record_error(string $stage, string $message): void
{
    update_option(WPPILOT_CHANGES_ERROR_OPTION, [
        'stage' => $stage,
        'message' => wppilot_change_clip($message, 500),
        'at' => time(),
    ], autoload: true);
}

function wppilot_changes_last_error(string $fallback): string
{
    $wpdb = wppilot_changes_wpdb();
    $error = $wpdb !== null ? (string) ($wpdb->last_error ?? '') : '';

    return $error !== '' ? $error : $fallback;
}

/**
 * Where the ledger is kept and, when that is not the table, why.
 *
 * @return array{storage: string, table: string, rows: int, error: array{stage: string, message: string, at: int}|null, migration: array<string, mixed>|null}
 */
function wppilot_change_storage_status(): array
{
    /** @var mixed $error */
    $error = get_option(WPPILOT_CHANGES_ERROR_OPTION, default_value: null);
    /** @var mixed $migration */
    $migration = get_option(WPPILOT_CHANGES_MIGRATION_OPTION, default_value: null);

    return [
        'storage' => wppilot_change_table_active() ? 'table' : 'option',
        'table' => wppilot_changes_table(),
        'rows' => wppilot_count_change_log(),
        'error' => is_array($error)
            ? ['stage' => (string) ($error['stage'] ?? ''), 'message' => (string) ($error['message'] ?? ''), 'at' => (int) ($error['at'] ?? 0)]
            : null,
        'migration' => is_array($migration) ? wppilot_string_keyed_array($migration) : null,
    ];
}

/**
 * A row carrying an id. The option never required one; the table addresses rows by it.
 *
 * The id given to a row without one is derived from its content, so a migration that is
 * interrupted and run again gives it the same id and does not copy it twice.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function wppilot_change_row_with_id(array $row): array
{
    $id = $row['id'] ?? null;
    if (!is_string($id) || $id === '' || strlen($id) > 191 || !wppilot_change_is_storable_text($id)) {
        $row['id'] = 'legacy-' . md5(serialize($row));
    }

    return $row;
}

/**
 * A row about to be recorded, with an id the table can hold. Unlike the migration's derived id,
 * a fresh one: two id-less rows recorded in the same second can be identical.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function wppilot_change_row_with_new_id(array $row): array
{
    $id = $row['id'] ?? null;
    if (!is_string($id) || $id === '' || strlen($id) > 191 || !wppilot_change_is_storable_text($id)) {
        $row['id'] = wp_generate_uuid4();
    }

    return $row;
}

/**
 * Whether a string can go into a text column as it is: valid UTF-8 without four-byte characters.
 *
 * wpdb::query() refuses a whole statement that carries invalid UTF-8, and a table created with the
 * three-byte utf8 charset refuses emoji. One binary meta value in a before-image would otherwise
 * fail the insert of its row.
 */
function wppilot_change_is_storable_text(string $value): bool
{
    // A pattern that fails outright (a multi-megabyte value can exhaust the PCRE stack) answers
    // "no", and the value is then encoded, which is always safe.
    return preg_match('/^[\x{0}-\x{FFFF}]*$/u', $value) === 1;
}

/**
 * A serialized value as stored: as is when a text column takes it, otherwise base64 behind a
 * `b64:` marker. No serialized value starts with those four characters (a boolean is `b:`).
 */
function wppilot_change_encode_payload(string $serialized): string
{
    return wppilot_change_is_storable_text($serialized) ? $serialized : 'b64:' . base64_encode($serialized);
}

/**
 * Text copied into an indexed column for filtering. The row itself keeps the original.
 */
function wppilot_change_index_text(mixed $value, int $length): string
{
    $text = is_scalar($value) ? (string) $value : '';
    if (!wppilot_change_is_storable_text($text)) {
        if (preg_match('//u', $text) !== 1) {
            $text = function_exists('mb_convert_encoding') ? (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8') : '';
        }
        $text = (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\u{FFFD}", $text);
    }

    return wppilot_change_clip($text, $length);
}

function wppilot_change_clip(string $value, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length);
    }

    return substr($value, 0, $length);
}

function wppilot_change_sql_time(mixed $value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    $time = strtotime($value);

    return $time === false ? null : gmdate('Y-m-d H:i:s', $time);
}

/**
 * The columns one ledger row is stored as.
 *
 * @param array<string, mixed> $entry
 * @return array<string, string|int|null>
 */
function wppilot_change_row_columns(array $entry): array
{
    $shell = $entry;
    $payloads = [];
    foreach (WPPILOT_CHANGES_PAYLOAD_COLUMNS as $key => $column) {
        if (array_key_exists($key, $entry)) {
            $payloads[$column] = wppilot_change_encode_payload(serialize($entry[$key]));
            // Left in place as null so the row reads back with its keys in their original order.
            $shell[$key] = null;
            continue;
        }
        $payloads[$column] = null;
    }
    $user = is_array($entry['user'] ?? null) ? $entry['user'] : [];
    $agent = is_array($entry['agent'] ?? null) ? $entry['agent'] : [];
    $group = $entry['group'] ?? '';

    return array_merge([
        'change_id' => (string) ($entry['id'] ?? ''),
        'recorded_at' => wppilot_change_sql_time($entry['recorded_at'] ?? null),
        'kind' => wppilot_change_index_text(is_string($entry['kind'] ?? null) ? $entry['kind'] : 'change', 32),
        'ability' => wppilot_change_index_text($entry['ability'] ?? '', 191),
        'risk' => wppilot_change_index_text($entry['risk'] ?? '', 32),
        'user_id' => max(0, is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0),
        'agent_credential' => wppilot_change_index_text($agent['credential'] ?? '', 191),
        'agent_label' => wppilot_change_index_text($agent['label'] ?? '', 191),
        'agent_client' => wppilot_change_index_text($agent['client'] ?? '', 191),
        'group_id' => wppilot_change_index_text($group, 191),
        'session_id' => wppilot_change_index_text($entry['session'] ?? '', 64),
        'status' => wppilot_change_status($entry),
        'rolled_back_at' => wppilot_change_sql_time($entry['rolled_back_at'] ?? null),
        'entry_data' => wppilot_change_encode_payload(serialize($shell)),
    ], $payloads);
}

/**
 * @return array{0: bool, 1: mixed} whether the value could be read, and the value.
 */
function wppilot_change_unserialize(mixed $raw): array
{
    if (!is_string($raw)) {
        return [false, null];
    }
    if (str_starts_with($raw, 'b64:')) {
        $raw = base64_decode(substr($raw, 4), true);
        if ($raw === false) {
            return [false, null];
        }
    }
    if ($raw === 'b:0;') {
        return [true, false];
    }
    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- The ledger's own rows, written by wppilot_change_row_columns(); the same trust as the option they replace, which WordPress unserialized the same way. A damaged row is reported by the false return rather than a notice.
    $value = @unserialize($raw);

    return $value === false ? [false, null] : [true, $value];
}

/**
 * One stored row back as the array that was recorded.
 *
 * @param array<string, mixed> $record
 * @return array<string, mixed>
 */
function wppilot_change_row_from_record(array $record): array
{
    [$ok, $shell] = wppilot_change_unserialize($record['entry_data'] ?? null);
    if (!$ok || !is_array($shell)) {
        // The row's own record is unreadable (a damaged write, a charset conversion that broke the
        // serialized lengths). What the indexed columns hold is still worth listing.
        $shell = [
            'id' => (string) ($record['change_id'] ?? ''),
            'kind' => (string) ($record['kind'] ?? 'change'),
            'ability' => (string) ($record['ability'] ?? ''),
            'risk' => (string) ($record['risk'] ?? ''),
            'recorded_at' => is_string($record['recorded_at'] ?? null) ? gmdate('c', (int) strtotime($record['recorded_at'] . ' UTC')) : '',
            'group' => (string) ($record['group_id'] ?? ''),
            'session' => (string) ($record['session_id'] ?? ''),
            'user' => ['id' => (int) ($record['user_id'] ?? 0), 'login' => ''],
            'agent' => ['label' => (string) ($record['agent_label'] ?? ''), 'client' => (string) ($record['agent_client'] ?? '')],
            'rolled_back' => ($record['status'] ?? '') === 'rolled-back',
        ];
    }
    $row = wppilot_string_keyed_array($shell);
    foreach (WPPILOT_CHANGES_PAYLOAD_COLUMNS as $key => $column) {
        $raw = $record[$column] ?? null;
        if ($raw === null) {
            continue;
        }
        [$read, $value] = wppilot_change_unserialize($raw);
        if ($read) {
            $row[$key] = $value;
        } elseif ($key === 'rollback') {
            // Never offer an undo from a before-image that cannot be read whole.
            $row[$key] = ['reversible' => false, 'reason' => 'The stored before-image could not be read back, so this change cannot be undone.'];
        }
    }
    if (!array_key_exists('rollback', $row) && ($ok === false)) {
        $row['rollback'] = ['reversible' => false, 'reason' => 'The stored record could not be read back, so this change cannot be undone.'];
    }

    return $row;
}

/**
 * Filters as a WHERE clause with placeholders, and its arguments.
 *
 * The same filters wppilot_query_change_log() applies over the option, as SQL. INSTR rather than
 * LIKE for the prefix and substring matches, so nothing a person types can act as a wildcard.
 *
 * @param array<string, mixed> $filters
 * @return array{0: string, 1: list<string|int>}
 */
function wppilot_change_filter_sql(array $filters): array
{
    $where = [];
    $args = [];

    $kind = (string) ($filters['kind'] ?? '');
    if ($kind !== '') {
        $where[] = 'kind = %s';
        $args[] = $kind;
    }
    $ability = (string) ($filters['ability'] ?? '');
    if ($ability !== '') {
        $where[] = 'INSTR(ability, %s) = 1';
        $args[] = $ability;
    }
    $user_id = (int) ($filters['user_id'] ?? 0);
    if ($user_id > 0) {
        $where[] = 'user_id = %d';
        $args[] = $user_id;
    }
    $agent = strtolower(trim((string) ($filters['agent'] ?? '')));
    if ($agent !== '') {
        $where[] = '(LOWER(agent_credential) = %s OR INSTR(LOWER(agent_label), %s) > 0 OR INSTR(LOWER(agent_client), %s) > 0)';
        array_push($args, $agent, $agent, $agent);
    }
    $group = (string) ($filters['group'] ?? '');
    if ($group !== '') {
        $where[] = 'group_id = %s';
        $args[] = $group;
    }
    $session = (string) ($filters['session'] ?? '');
    if ($session !== '') {
        $where[] = 'session_id = %s';
        $args[] = $session;
    }
    $status = (string) ($filters['status'] ?? '');
    if ($status !== '') {
        $where[] = 'status = %s';
        $args[] = $status;
    }
    $since = wppilot_change_filter_time((string) ($filters['since'] ?? ''), end_of_day: false);
    if ($since !== null) {
        $where[] = 'recorded_at >= %s';
        $args[] = gmdate('Y-m-d H:i:s', $since);
    }
    $until = wppilot_change_filter_time((string) ($filters['until'] ?? ''), end_of_day: true);
    if ($until !== null) {
        $where[] = 'recorded_at <= %s';
        $args[] = gmdate('Y-m-d H:i:s', $until);
    }

    return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $args];
}

/**
 * @param list<string|int> $args
 */
function wppilot_changes_prepare(string $sql, array $args): string
{
    $wpdb = wppilot_changes_wpdb();
    if ($wpdb === null || $args === []) {
        return $sql;
    }

    return (string) $wpdb->prepare($sql, $args);
}

/**
 * @param list<string|int> $args
 * @return list<array<string, mixed>>
 */
function wppilot_changes_results(string $sql, array $args = []): array
{
    $wpdb = wppilot_changes_wpdb();
    if ($wpdb === null) {
        return [];
    }
    /** @var mixed $results */
    $results = $wpdb->get_results(wppilot_changes_prepare($sql, $args), ARRAY_A);
    $records = [];
    // @mago-expect analysis:mixed-assignment -- Database rows are normalized below.
    foreach (is_array($results) ? $results : [] as $record) {
        if (is_array($record)) {
            $records[] = wppilot_string_keyed_array($record);
        }
    }

    return $records;
}

/**
 * @param array<string, mixed> $filters
 * @return list<array<string, mixed>>
 */
function wppilot_change_table_query(array $filters, int $limit = 0, int $offset = 0): array
{
    [$where, $args] = wppilot_change_filter_sql($filters);
    $table = wppilot_changes_table();
    $sql = "SELECT * FROM {$table}{$where} ORDER BY seq DESC";
    if ($limit > 0 || $offset > 0) {
        $sql .= ' LIMIT %d OFFSET %d';
        $args[] = $limit > 0 ? $limit : PHP_INT_MAX;
        $args[] = max(0, $offset);
    }

    return array_map('wppilot_change_row_from_record', wppilot_changes_results($sql, $args));
}

/** @param array<string, mixed> $filters */
function wppilot_change_table_count(array $filters): int
{
    $wpdb = wppilot_changes_wpdb();
    if ($wpdb === null) {
        return 0;
    }
    [$where, $args] = wppilot_change_filter_sql($filters);
    $table = wppilot_changes_table();

    return (int) $wpdb->get_var(wppilot_changes_prepare("SELECT COUNT(*) FROM {$table}{$where}", $args));
}

/**
 * Rows per group, for the groups named.
 *
 * @param list<string> $groups
 * @return array<string, int>
 */
function wppilot_change_table_group_sizes(array $groups): array
{
    $groups = array_values(array_unique(array_filter($groups, static fn(string $group): bool => $group !== '')));
    if ($groups === []) {
        return [];
    }
    $table = wppilot_changes_table();
    $placeholders = implode(', ', array_fill(0, count($groups), '%s'));
    $sizes = [];
    foreach (wppilot_changes_results(
        "SELECT group_id, COUNT(*) AS rows_in_group FROM {$table} WHERE group_id IN ({$placeholders}) GROUP BY group_id",
        $groups,
    ) as $record) {
        $sizes[(string) $record['group_id']] = (int) $record['rows_in_group'];
    }

    return $sizes;
}

/** @return array<string, mixed>|null */
function wppilot_change_table_get(string $id): ?array
{
    $table = wppilot_changes_table();
    $records = wppilot_changes_results("SELECT * FROM {$table} WHERE change_id = %s LIMIT 1", [$id]);

    return $records === [] ? null : wppilot_change_row_from_record($records[0]);
}

/**
 * The rows with these ids, newest first.
 *
 * @param list<string> $ids
 * @return list<array<string, mixed>>
 */
function wppilot_change_table_get_many(array $ids): array
{
    $ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));
    $table = wppilot_changes_table();
    $records = [];
    foreach (array_chunk($ids, 200) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '%s'));
        foreach (wppilot_changes_results("SELECT * FROM {$table} WHERE change_id IN ({$placeholders})", $chunk) as $record) {
            $records[] = $record;
        }
    }
    usort($records, static fn(array $a, array $b): int => (int) $b['seq'] <=> (int) $a['seq']);

    return array_map('wppilot_change_row_from_record', $records);
}

/**
 * Ids of the rows recorded under a group, oldest first.
 *
 * @return list<string>
 */
function wppilot_change_table_group_ids(string $group): array
{
    $table = wppilot_changes_table();

    return array_map(
        static fn(array $record): string => (string) $record['change_id'],
        wppilot_changes_results("SELECT change_id FROM {$table} WHERE group_id = %s ORDER BY seq ASC", [$group]),
    );
}

/**
 * The newest rows, oldest first, within the option's old bounds of 500 rows and 4 MB.
 *
 * wppilot_get_change_log() has always returned the whole (bounded) log, and callers outside this
 * plugin read its last element as "the row just recorded". The table holds far more than any
 * caller should load at once, so the same bounds are applied on the way out. Sizes are measured
 * in the database, so rows past the budget are never transferred.
 *
 * @return list<array<string, mixed>>
 */
function wppilot_change_table_recent(): array
{
    $table = wppilot_changes_table();
    $length = 'LENGTH(entry_data)';
    foreach (WPPILOT_CHANGES_PAYLOAD_COLUMNS as $column) {
        $length .= " + COALESCE(LENGTH({$column}), 0)";
    }
    $bytes = 0;
    $floor = null;
    foreach (wppilot_changes_results(
        "SELECT seq, ({$length}) AS row_bytes FROM {$table} ORDER BY seq DESC LIMIT %d",
        [WPPILOT_CHANGE_LOG_MAX],
    ) as $record) {
        $size = (int) $record['row_bytes'];
        if ($floor !== null && $bytes + $size > WPPILOT_CHANGE_LOG_MAX_BYTES) {
            break;
        }
        $bytes += $size;
        $floor = (int) $record['seq'];
    }
    if ($floor === null) {
        return [];
    }

    return array_map(
        'wppilot_change_row_from_record',
        wppilot_changes_results("SELECT * FROM {$table} WHERE seq >= %d ORDER BY seq ASC", [$floor]),
    );
}

/**
 * Insert rows, several to a statement, within the per-statement byte budget.
 *
 * @param list<array<string, mixed>> $rows
 */
function wppilot_change_table_insert(array $rows): bool
{
    $wpdb = wppilot_changes_wpdb();
    if ($wpdb === null) {
        return false;
    }
    $pending = [];
    $bytes = 0;
    foreach ($rows as $row) {
        $columns = wppilot_change_row_columns($row);
        $size = 0;
        foreach ($columns as $value) {
            $size += is_string($value) ? strlen($value) : 8;
        }
        if ($pending !== [] && $bytes + $size > WPPILOT_CHANGES_INSERT_BATCH_BYTES) {
            if (!wppilot_change_table_insert_statement($wpdb, $pending)) {
                return false;
            }
            $pending = [];
            $bytes = 0;
        }
        $pending[] = $columns;
        $bytes += $size;
    }

    return $pending === [] || wppilot_change_table_insert_statement($wpdb, $pending);
}

/**
 * @param list<array<string, string|int|null>> $records
 */
function wppilot_change_table_insert_statement(object $wpdb, array $records): bool
{
    $table = wppilot_changes_table();
    $names = array_keys($records[0]);
    $tuples = [];
    $args = [];
    foreach ($records as $record) {
        $slots = [];
        foreach ($names as $name) {
            $value = $record[$name] ?? null;
            if ($value === null) {
                // prepare() has no NULL placeholder; %s would store '' and a NULL date is what
                // keeps a row out of every since/until filter, as it was over the option.
                $slots[] = 'NULL';
                continue;
            }
            $slots[] = is_int($value) ? '%d' : '%s';
            $args[] = $value;
        }
        $tuples[] = '(' . implode(', ', $slots) . ')';
    }
    $sql = "INSERT INTO {$table} (" . implode(', ', $names) . ') VALUES ' . implode(', ', $tuples);

    return $wpdb->query(wppilot_changes_prepare($sql, $args)) !== false;
}

/**
 * Insert the rows whose ids the table does not have yet.
 *
 * @param list<array<string, mixed>> $rows
 * @return int|null rows inserted, or null when the database refused them.
 */
function wppilot_change_table_insert_missing(array $rows): ?int
{
    $rows = array_map('wppilot_change_row_with_id', $rows);
    $existing = wppilot_change_table_existing_ids(array_map(static fn(array $row): string => (string) $row['id'], $rows));
    if ($existing === null) {
        return null;
    }
    $fresh = [];
    foreach ($rows as $row) {
        $id = (string) $row['id'];
        if (isset($existing[$id])) {
            continue;
        }
        $existing[$id] = true;
        $fresh[] = $row;
    }
    if ($fresh === []) {
        return 0;
    }

    return wppilot_change_table_insert($fresh) ? count($fresh) : null;
}

/**
 * @param list<string> $ids
 * @return array<string, true>|null null when the lookup failed.
 */
function wppilot_change_table_existing_ids(array $ids): ?array
{
    $wpdb = wppilot_changes_wpdb();
    if ($wpdb === null) {
        return null;
    }
    $table = wppilot_changes_table();
    $found = [];
    foreach (array_chunk(array_values(array_unique($ids)), 200) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '%s'));
        /** @var mixed $column */
        $column = $wpdb->get_col(wppilot_changes_prepare(
            "SELECT change_id FROM {$table} WHERE change_id IN ({$placeholders})",
            $chunk,
        ));
        if (!is_array($column)) {
            return null;
        }
        if ($column === [] && (string) ($wpdb->last_error ?? '') !== '') {
            return null;
        }
        // @mago-expect analysis:mixed-assignment -- Column values are cast below.
        foreach ($column as $id) {
            $found[(string) $id] = true;
        }
    }

    return $found;
}

/**
 * @param list<string> $ids
 * @return list<string>|null the ids not in the table, or null when the lookup failed.
 */
function wppilot_change_table_missing_ids(array $ids): ?array
{
    $existing = wppilot_change_table_existing_ids($ids);
    if ($existing === null) {
        return null;
    }

    return array_values(array_filter(array_unique($ids), static fn(string $id): bool => !isset($existing[$id])));
}

/**
 * Rewrite one row in place.
 *
 * @param array<string, mixed> $replacement
 */
function wppilot_change_table_replace(string $id, array $replacement): bool
{
    $wpdb = wppilot_changes_wpdb();
    if ($wpdb === null) {
        return false;
    }
    $table = wppilot_changes_table();
    // Looked up first because an UPDATE that changes nothing reports zero rows on MySQL, and a
    // replacement identical to the stored row is still a row that was found.
    $seq = $wpdb->get_var(wppilot_changes_prepare("SELECT seq FROM {$table} WHERE change_id = %s LIMIT 1", [$id]));
    if ($seq === null || $seq === false) {
        return false;
    }
    $sets = [];
    $args = [];
    foreach (wppilot_change_row_columns($replacement) as $name => $value) {
        if ($value === null) {
            $sets[] = "{$name} = NULL";
            continue;
        }
        $sets[] = $name . ' = ' . (is_int($value) ? '%d' : '%s');
        $args[] = $value;
    }
    $args[] = (int) $seq;

    return $wpdb->query(wppilot_changes_prepare("UPDATE {$table} SET " . implode(', ', $sets) . ' WHERE seq = %d', $args)) !== false;
}

/**
 * Record rows in the table; when the database refuses them, keep them in the option instead.
 *
 * A write already happened on the site by the time its row is recorded, so losing the row would
 * lose the only way back. Storage drops to the option (reads there see only rows recorded from
 * now on until the next migration merges them back), and Diagnostics says why.
 *
 * @param list<array<string, mixed>> $entries
 */
function wppilot_change_table_store(array $entries): void
{
    $entries = array_map('wppilot_change_row_with_new_id', $entries);
    if (wppilot_change_table_insert($entries)) {
        return;
    }
    wppilot_change_table_fail_over(wppilot_changes_last_error('The ledger table refused a row.'));
    wppilot_change_option_store($entries);
}

function wppilot_change_table_fail_over(string $error): void
{
    delete_option(WPPILOT_CHANGES_STORAGE_OPTION);
    wppilot_changes_record_error('write', $error);
}

/**
 * Whether the rows being stored are passing through the option's pre-update filter for the
 * benefit of existing listeners, rather than being written to the option by someone else.
 */
function wppilot_change_legacy_bridge(?bool $set = null): bool
{
    static $active = false;
    if ($set !== null) {
        $active = $set;
    }

    return $active;
}

/**
 * Let listeners amend rows before they are recorded.
 *
 * `wppilot_change_rows_before_store` receives the list of rows about to be recorded, newest last,
 * and returns it. It is the supported way to annotate a row as it is stored.
 *
 * Before the table, the only way to do that was `pre_update_option_wppilot_change_log`, which saw
 * the whole log with the new rows last and the stored log as its second argument; GoBuildWP Pro's
 * ledger annotates rows that way. In table storage nothing writes that option any more, so the
 * filter is applied to the new rows with an empty stored log — the same answer a listener that
 * only looks at rows not already stored would have given.
 *
 * @param list<array<string, mixed>> $entries
 * @return list<array<string, mixed>>
 */
function wppilot_change_rows_before_store(array $entries, bool $legacy_filter): array
{
    // Session and after-state first, so a listener sees the row as it will be stored.
    $entries = array_map('wppilot_change_row_with_session', $entries);
    $entries = wppilot_change_rows_or(apply_filters('wppilot_change_rows_before_store', $entries), $entries);
    if (!$legacy_filter) {
        return $entries;
    }
    wppilot_change_legacy_bridge(true);
    try {
        $filtered = apply_filters('pre_update_option_' . WPPILOT_CHANGE_LOG_OPTION, $entries, [], WPPILOT_CHANGE_LOG_OPTION);
    } finally {
        wppilot_change_legacy_bridge(false);
    }

    return wppilot_change_rows_or($filtered, $entries);
}

/**
 * @param list<array<string, mixed>> $fallback
 * @return list<array<string, mixed>>
 */
function wppilot_change_rows_or(mixed $value, array $fallback): array
{
    if (!is_array($value)) {
        return $fallback;
    }
    $rows = [];
    // @mago-expect analysis:mixed-assignment -- Filter output is normalized here.
    foreach ($value as $row) {
        if (is_array($row)) {
            $rows[] = wppilot_string_keyed_array($row);
        }
    }

    return $rows;
}

/**
 * Take rows written straight to the old option into the table, and keep the option unwritten.
 *
 * Code written for the option (GoBuildWP Pro's WooCommerce bulk ledger, for one) reads
 * wppilot_get_change_log(), appends its rows and saves the option. In table storage that save is
 * intercepted here, last, after every other listener has had its say: rows whose ids the table
 * does not have are inserted, and the option write is cancelled by handing back the old value.
 * Rows already stored are left alone; they change through wppilot_replace_change().
 */
function wppilot_change_intercept_option_write(mixed $value, mixed $old_value = null): mixed
{
    if (wppilot_change_legacy_bridge() || !wppilot_change_table_active()) {
        return $value;
    }
    $rows = wppilot_change_rows_or($value, []);
    if ($rows !== []) {
        // Rows the table does not have yet were written in this request, so they belong to its
        // session. Rows it has are older and keep whatever they were recorded with.
        $existing = wppilot_change_table_existing_ids(array_map(
            static fn(array $row): string => (string) wppilot_change_row_with_id($row)['id'],
            $rows,
        )) ?? [];
        foreach ($rows as $index => $row) {
            if (!isset($existing[(string) wppilot_change_row_with_id($row)['id']])) {
                $rows[$index] = wppilot_change_row_with_session($row);
            }
        }
    }
    if ($rows !== [] && wppilot_change_table_insert_missing($rows) === null) {
        // Let the write through so the rows are not lost; a later migration merges them back.
        wppilot_change_table_fail_over(wppilot_changes_last_error('The ledger table refused a row.'));
        return $value;
    }

    return $old_value;
}

/**
 * How much history the table keeps.
 *
 * @return array{max_rows: int, max_days: int, snapshot_days: int}
 */
function wppilot_change_retention(): array
{
    $defaults = ['max_rows' => 10_000, 'max_days' => 90, 'snapshot_days' => 30];
    /**
     * Filters how long the change ledger keeps rows.
     *
     * - max_rows: the newest rows kept; older ones are deleted. 0 keeps any number.
     * - max_days: rows older than this are deleted. 0 keeps them however old.
     * - snapshot_days: before-images older than this are dropped and the row stays, marked as no
     *   longer reversible. 0 keeps them. Restoring a month-old image would also undo every edit
     *   made since, and the images are nearly all of the table's size.
     *
     * @param array{max_rows: int, max_days: int, snapshot_days: int} $defaults
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_change_retention', $defaults);
    $policy = is_array($filtered) ? $filtered : [];

    return [
        'max_rows' => max(0, (int) ($policy['max_rows'] ?? $defaults['max_rows'])),
        'max_days' => max(0, (int) ($policy['max_days'] ?? $defaults['max_days'])),
        'snapshot_days' => max(0, (int) ($policy['snapshot_days'] ?? $defaults['snapshot_days'])),
    ];
}

/**
 * The daily prune: old before-images first, then rows past the age and count limits.
 *
 * @return array{snapshots_pruned: int, deleted: int}
 */
function wppilot_changes_prune(?int $now = null): array
{
    $summary = ['snapshots_pruned' => 0, 'deleted' => 0];
    $wpdb = wppilot_changes_wpdb();
    if ($wpdb === null || !wppilot_change_table_active()) {
        return $summary;
    }
    $now ??= time();
    $policy = wppilot_change_retention();
    $table = wppilot_changes_table();

    if ($policy['snapshot_days'] > 0) {
        $cutoff = gmdate('Y-m-d H:i:s', $now - ($policy['snapshot_days'] * DAY_IN_SECONDS));
        $reason = sprintf(
            'The before-image was removed %d days after the change to keep the ledger small, so this change can no longer be undone from here.',
            $policy['snapshot_days'],
        );
        $undoable = $wpdb->query(wppilot_changes_prepare(
            "UPDATE {$table} SET rollback_data = %s, status = 'not-reversible'"
            . " WHERE status = 'undoable' AND recorded_at < %s AND LENGTH(rollback_data) > %d",
            [serialize(['reversible' => false, 'reason' => $reason, 'pruned_at' => gmdate('c', $now)]), $cutoff, WPPILOT_CHANGES_PRUNE_MIN_BYTES],
        ));
        // Already undone: the image only documents a restore that has happened.
        // The redo image goes with it: redoing a month-old undo would overwrite a month of edits.
        $undone = $wpdb->query(wppilot_changes_prepare(
            "UPDATE {$table} SET rollback_data = %s, redo_data = %s WHERE status = 'rolled-back' AND recorded_at < %s AND LENGTH(rollback_data) > %d",
            [
                serialize(['reversible' => false, 'reason' => 'This change was undone; its before-image has since been removed to keep the ledger small.', 'pruned_at' => gmdate('c', $now)]),
                serialize(['available' => false, 'reason' => 'The state this undo replaced was removed to keep the ledger small, so it can no longer be redone.']),
                $cutoff,
                WPPILOT_CHANGES_PRUNE_MIN_BYTES,
            ],
        ));
        $summary['snapshots_pruned'] = (is_int($undoable) ? $undoable : 0) + (is_int($undone) ? $undone : 0);
    }

    if ($policy['max_days'] > 0) {
        $cutoff = gmdate('Y-m-d H:i:s', $now - ($policy['max_days'] * DAY_IN_SECONDS));
        $summary['deleted'] += wppilot_changes_delete_where('recorded_at < %s', [$cutoff]);
    }

    if ($policy['max_rows'] > 0) {
        $boundary = $wpdb->get_var(wppilot_changes_prepare(
            "SELECT seq FROM {$table} ORDER BY seq DESC LIMIT 1 OFFSET %d",
            [$policy['max_rows']],
        ));
        if ($boundary !== null && $boundary !== false) {
            $summary['deleted'] += wppilot_changes_delete_where('seq <= %d', [(int) $boundary]);
        }
    }

    wppilot_changes_drop_leftover_option();

    return $summary;
}

/**
 * Delete matching rows a batch at a time; a bounded number of batches per run.
 *
 * @param list<string|int> $args
 */
function wppilot_changes_delete_where(string $condition, array $args): int
{
    $wpdb = wppilot_changes_wpdb();
    if ($wpdb === null) {
        return 0;
    }
    $table = wppilot_changes_table();
    $deleted = 0;
    for ($round = 0; $round < 100; $round++) {
        $seqs = array_map(
            static fn(array $record): int => (int) $record['seq'],
            wppilot_changes_results(
                "SELECT seq FROM {$table} WHERE {$condition} ORDER BY seq ASC LIMIT %d",
                array_merge($args, [WPPILOT_CHANGES_PRUNE_BATCH]),
            ),
        );
        if ($seqs === []) {
            break;
        }
        $placeholders = implode(', ', array_fill(0, count($seqs), '%d'));
        $result = $wpdb->query(wppilot_changes_prepare("DELETE FROM {$table} WHERE seq IN ({$placeholders})", $seqs));
        if (!is_int($result) || $result === 0) {
            break;
        }
        $deleted += $result;
    }

    return $deleted;
}

/**
 * Remove an option the migration copied but did not get to delete (the request ended between).
 */
function wppilot_changes_drop_leftover_option(): void
{
    $rows = wppilot_change_log_option_rows();
    if ($rows === [] && get_option(WPPILOT_CHANGE_LOG_OPTION, default_value: null) === null) {
        return;
    }
    $ids = array_map(static fn(array $row): string => (string) wppilot_change_row_with_id($row)['id'], $rows);
    $missing = wppilot_change_table_missing_ids($ids);
    if ($missing === null) {
        return;
    }
    // Rows the option has and the table does not (written while storage had fallen back) are
    // copied first: the option is only ever deleted once everything in it is in the table.
    if ($missing !== [] && wppilot_change_table_insert_missing($rows) === null) {
        return;
    }
    delete_option(WPPILOT_CHANGE_LOG_OPTION);
}

function wppilot_changes_schedule_prune(): void
{
    if (function_exists('wp_next_scheduled') && !wp_next_scheduled(WPPILOT_CHANGES_PRUNE_HOOK)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', WPPILOT_CHANGES_PRUNE_HOOK);
    }
}

function wppilot_changes_register_hooks(): void
{
    // After the safety profile (4) and before anything can execute an ability.
    add_action('plugins_loaded', 'wppilot_changes_maybe_upgrade', priority: 6);
    add_action('init', static function (): void {
        if (wppilot_change_table_active()) {
            wppilot_changes_schedule_prune();
        }
    });
    add_action(WPPILOT_CHANGES_PRUNE_HOOK, static function (): void {
        wppilot_changes_prune();
    });
    // Last, so rows arrive here with every other listener's annotations on them.
    add_filter('pre_update_option_' . WPPILOT_CHANGE_LOG_OPTION, 'wppilot_change_intercept_option_write', PHP_INT_MAX, 2);
}
