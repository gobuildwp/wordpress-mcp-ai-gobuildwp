<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Chat sessions live in a custom table (see schema in storage.php); there is no WP API for it. Identifiers are prefix-built constants, values are prepared.

/**
 * GoBuildWP Chat: the $wpdb layer behind chat sessions.
 *
 * Every SQL statement touching the chat sessions table lives here, along with
 * the row-size budget and the pruning that keeps a session inside it.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Inserts a new session row, or updates the existing one, logging on failure.
 * A null $existing_user_id means no row exists yet.
 *
 * @param array{id: string, user_id: int, created_at: string, updated_at: string, data: string} $row
 */
function wppilot_chat_persist_session_row(string $session_id, int $user_id, array $row, ?int $existing_user_id): void
{
    $wpdb = wppilot_chat_wpdb();
    $table = wppilot_chat_sessions_table();

    if ($existing_user_id !== null) {
        if (wppilot_chat_update_session_row($wpdb, $table, $session_id, $user_id, $row) === false) {
            wppilot_chat_log_storage_failure($session_id);
        }
        return;
    }

    if ($wpdb->insert($table, $row, ['%s', '%d', '%s', '%s', '%s']) !== false) {
        return;
    }

    // A concurrent request created this id first (or the write failed). Retry as an
    // owner-scoped update so the save is not lost and a racing row belonging to
    // another user is never overwritten.
    $recovered = wppilot_chat_update_session_row($wpdb, $table, $session_id, $user_id, $row);
    if ($recovered === false || $recovered === 0) {
        wppilot_chat_log_storage_failure($session_id);
    }
}

/**
 * Updates an existing session row, scoped to its owner so a racing row that
 * belongs to another user is never overwritten.
 *
 * @param array{id: string, user_id: int, created_at: string, updated_at: string, data: string} $row
 * @return int|false Rows affected, or false on a database error.
 */
function wppilot_chat_update_session_row(
    wpdb $wpdb,
    string $table,
    string $session_id,
    int $user_id,
    array $row,
): int|false {
    return $wpdb->update(
        $table,
        [
            'updated_at' => $row['updated_at'],
            'data' => $row['data'],
        ],
        [
            'id' => $session_id,
            'user_id' => $user_id,
        ],
        ['%s', '%s'],
        ['%s', '%d'],
    );
}

/**
 * Records a session persistence failure. Logs the session id and the database
 * error only, never the session payload.
 */
function wppilot_chat_log_storage_failure(string $session_id): void
{
    $wpdb = wppilot_chat_wpdb();
    // Only when the site has asked for debugging. A storage failure is worth
    // recording, but a plugin should not write to a production error log
    // uninvited — and the directory guidelines treat unconditional error_log()
    // as a defect for exactly that reason.
    if (wppilot_debug_logging_enabled()) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(sprintf('GoBuildWP Chat: failed to persist session %s. %s', $session_id, $wpdb->last_error));
    }
}

function wppilot_chat_ensure_storage_ready(): void
{
    if (function_exists('wppilot_chat_schema_maybe_install')) {
        wppilot_chat_schema_maybe_install();
    }
}

/**
 * @return list<array<string, mixed>>
 */
function wppilot_chat_select_sessions_for_user(wpdb $wpdb, string $table, int $user_id): array
{
    // @mago-expect analysis:possibly-invalid-argument -- The table name is derived from $wpdb->prefix.
    $sql = $wpdb->prepare(
        "SELECT id, data FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d",
        $user_id,
        WPPILOT_CHAT_MAX_SESSIONS_PER_USER,
    );
    if (!is_string($sql)) {
        return [];
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values are bound by $wpdb->prepare() above; Plugin Check cannot follow the prepared statement through the variable. The only interpolation is the table name, which prepare() has no placeholder for. Not cached: this reads live per-request state.
    $rows = $wpdb->get_results($sql, 'ARRAY_A');
    if (!is_array($rows)) {
        return [];
    }

    $clean = [];
    foreach ($rows as $row) {
        $clean[] = wppilot_chat_assoc_array($row);
    }

    return $clean;
}

function wppilot_chat_select_session_data(wpdb $wpdb, string $table, string $session_id, int $user_id): ?string
{
    // @mago-expect analysis:possibly-invalid-argument -- The table name is derived from $wpdb->prefix.
    $sql = $wpdb->prepare("SELECT data FROM {$table} WHERE id = %s AND user_id = %d", $session_id, $user_id);
    if (!is_string($sql)) {
        return null;
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values are bound by $wpdb->prepare() above; Plugin Check cannot follow the prepared statement through the variable. The only interpolation is the table name, which prepare() has no placeholder for. Not cached: this reads live per-request state.
    $data = $wpdb->get_var($sql);

    return is_string($data) ? $data : null;
}

function wppilot_chat_select_session_owner(wpdb $wpdb, string $table, string $session_id): ?int
{
    // @mago-expect analysis:possibly-invalid-argument -- The table name is derived from $wpdb->prefix.
    $sql = $wpdb->prepare("SELECT user_id FROM {$table} WHERE id = %s", $session_id);
    if (!is_string($sql)) {
        return null;
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values are bound by $wpdb->prepare() above; Plugin Check cannot follow the prepared statement through the variable. The only interpolation is the table name, which prepare() has no placeholder for. Not cached: this reads live per-request state.
    $owner = $wpdb->get_var($sql);
    if ($owner === null) {
        return null;
    }

    return (int) $owner;
}

/**
 * @param array<string, mixed> $session
 * @return array{id: string, user_id: int, created_at: string, updated_at: string, data: string}
 */
function wppilot_chat_storage_row(array $session, string $session_id, int $user_id, string $json): array
{
    return [
        'id' => $session_id,
        'user_id' => $user_id,
        'created_at' => wppilot_chat_mysql_datetime((int) $session['created_at']),
        'updated_at' => wppilot_chat_mysql_datetime((int) $session['updated_at']),
        'data' => $json,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function wppilot_chat_decode_session_json(mixed $data, string $session_id): ?array
{
    if (!is_string($data) || $data === '') {
        return null;
    }

    // @mago-expect analysis:mixed-assignment -- JSON storage is decoded and validated before use.
    $decoded = json_decode($data, associative: true);
    if (!is_array($decoded)) {
        return null;
    }

    $session = wppilot_chat_assoc_array($decoded);
    if (!is_string($session['id'] ?? null) || $session['id'] === '') {
        $session['id'] = $session_id;
    }

    return $session;
}

function wppilot_chat_max_row_bytes(): int
{
    $max = (int) apply_filters('wppilot_chat_max_row_bytes', WPPILOT_CHAT_MAX_ROW_BYTES);

    return $max > 0 ? $max : WPPILOT_CHAT_MAX_ROW_BYTES;
}

/**
 * @param array<string, mixed> $session
 */
function wppilot_chat_encode_session_for_storage(array &$session): ?string
{
    $json = wppilot_chat_json_encode($session);
    if ($json === null) {
        return null;
    }

    $max_bytes = wppilot_chat_max_row_bytes();
    if (strlen($json) <= $max_bytes) {
        return $json;
    }

    $session = wppilot_chat_prune_attachment_data($session, $max_bytes);
    $json = wppilot_chat_json_encode($session);
    if ($json === null || strlen($json) > $max_bytes) {
        return null;
    }

    return $json;
}

/**
 * @param array<string, mixed> $session
 */
function wppilot_chat_json_encode(array $session): ?string
{
    $json = wp_json_encode($session, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return is_string($json) ? $json : null;
}

/**
 * @param array<string, mixed> $session
 * @return array<string, mixed>
 */
function wppilot_chat_prune_attachment_data(array $session, int $max_bytes): array
{
    $messages = wppilot_chat_session_list($session, key: 'messages');
    foreach ($messages as $message_index => $message) {
        $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
        // @mago-expect analysis:mixed-assignment
        foreach ($attachments as $attachment_index => $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            if (!is_string($attachment['data'] ?? null) || $attachment['data'] === '') {
                continue;
            }

            $attachment['data'] = '';
            $attachments[$attachment_index] = $attachment;
            $message['attachments'] = $attachments;
            $messages[$message_index] = $message;
            $session['messages'] = $messages;

            $json = wppilot_chat_json_encode($session);
            if ($json !== null && strlen($json) <= $max_bytes) {
                return $session;
            }
        }
    }

    return $session;
}

function wppilot_chat_mysql_datetime(int $timestamp): string
{
    if ($timestamp <= 0) {
        $timestamp = time();
    }

    return gmdate('Y-m-d H:i:s', $timestamp);
}

function wppilot_chat_prune_sessions_for_user(int $user_id): void
{
    if ($user_id <= 0) {
        return;
    }

    $wpdb = wppilot_chat_wpdb();
    $table = wppilot_chat_sessions_table();
    $old_ids = wppilot_chat_select_prunable_session_ids($wpdb, $table, $user_id);

    foreach ($old_ids as $old_id) {
        if ($old_id === '') {
            continue;
        }

        $wpdb->delete(
            $table,
            [
                'id' => $old_id,
                'user_id' => $user_id,
            ],
            ['%s', '%d'],
        );
    }
}

/**
 * @return list<string>
 */
function wppilot_chat_select_prunable_session_ids(wpdb $wpdb, string $table, int $user_id): array
{
    // @mago-expect analysis:possibly-invalid-argument -- The table name is derived from $wpdb->prefix.
    $sql = $wpdb->prepare(
        "SELECT id FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC, id DESC LIMIT 1000 OFFSET %d",
        $user_id,
        WPPILOT_CHAT_MAX_SESSIONS_PER_USER,
    );
    if (!is_string($sql)) {
        return [];
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values are bound by $wpdb->prepare() above; Plugin Check cannot follow the prepared statement through the variable. The only interpolation is the table name, which prepare() has no placeholder for. Not cached: this reads live per-request state.
    $old_ids = $wpdb->get_col($sql);
    $clean = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($old_ids as $old_id) {
        if (!is_string($old_id) || $old_id === '') {
            continue;
        }

        $clean[] = $old_id;
    }

    return $clean;
}
