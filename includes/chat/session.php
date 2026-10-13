<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Chat sessions live in a custom table (see schema in storage.php); there is no WP API for it. Identifiers are prefix-built constants, values are prepared.

/**
 * GoBuildWP Chat: the session record — reading, mutating, and persisting the
 * conversation shape.
 *
 * This is the in-memory view. The database layer is storage.php.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Apply an optional provider/model change carried in a session update request.
 *
 * @param array<string, mixed> $session
 * @param array<array-key, mixed> $params
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:halstead
function wppilot_chat_apply_model_change(array $session, array $params): array|WP_Error
{
    $provider = is_string($params['provider'] ?? null) ? sanitize_key($params['provider']) : '';
    $model = is_string($params['model'] ?? null) ? sanitize_text_field($params['model']) : '';
    if ($provider === '' && $model === '') {
        return $session;
    }

    if ($provider === '') {
        $provider = (string) ($session['provider'] ?? '');
    }
    if ($model === '') {
        $model = (string) ($session['model'] ?? '');
    }

    $selection = wppilot_chat_normalize_model_selection($provider, $model);
    if (is_wp_error($selection)) {
        return $selection;
    }

    $session['provider'] = $selection['provider'];
    $session['model'] = $selection['model'];

    return $session;
}

/**
 * @param list<array<string, mixed>> $messages
 */
function wppilot_chat_find_message_index(array $messages, string $message_id): ?int
{
    foreach ($messages as $index => $message) {
        if (($message['id'] ?? null) === $message_id) {
            return (int) $index;
        }
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $messages
 * @return list<string>
 */
function wppilot_chat_message_ids(array $messages): array
{
    $ids = [];
    foreach ($messages as $message) {
        if (!is_string($message['id'] ?? null) || $message['id'] === '') {
            continue;
        }
        $ids[] = $message['id'];
    }

    return $ids;
}

/**
 * @param array<string, mixed> $session
 * @param list<string> $message_ids
 * @return list<array<string, mixed>>
 */
function wppilot_chat_tool_calls_for_messages(array $session, array $message_ids, int $cutoff_created_at): array
{
    $allowed_ids = array_flip($message_ids);
    $tool_calls = [];
    foreach (wppilot_chat_session_list($session, key: 'tool_calls') as $tool_call) {
        $message_id = is_string($tool_call['message_id'] ?? null) ? $tool_call['message_id'] : '';
        if ($message_id !== '' && array_key_exists($message_id, $allowed_ids)) {
            $tool_calls[] = $tool_call;
            continue;
        }
        if ((int) ($tool_call['created_at'] ?? 0) < $cutoff_created_at) {
            $tool_calls[] = $tool_call;
        }
    }

    return $tool_calls;
}

/**
 * @param array<string, mixed> $session
 * @param array{content: string, complete: bool, tool_calls: list<array<string, mixed>>} $parsed
 * @param list<array<string, mixed>> $tools
 * @return array{session: array<string, mixed>, message: array<string, mixed>, tool_calls: list<array<string, mixed>>}
 */
function wppilot_chat_append_model_step(array $session, array $parsed, array $tools): array
{
    $now = time();
    $message_id = (string) wp_generate_uuid4();
    $message = [
        'id' => $message_id,
        'role' => 'assistant',
        'content' => $parsed['content'],
        'created_at' => $now,
    ];
    if ($parsed['content'] !== '') {
        $messages = wppilot_chat_session_list($session, key: 'messages');
        $messages[] = $message;
        $session['messages'] = $messages;
    }

    $created_calls = wppilot_chat_build_tool_calls($session, $parsed['tool_calls'], $tools, $now, $message_id);
    $tool_calls = wppilot_chat_session_list($session, key: 'tool_calls');

    foreach ($created_calls as $call) {
        $tool_calls[] = $call;
    }
    $session['tool_calls'] = $tool_calls;

    if ($created_calls !== []) {
        $session['status'] = 'waiting_for_tools';
    }
    if ($created_calls === [] && $parsed['complete']) {
        $session['status'] = 'completed';
    }
    if ($created_calls === [] && !$parsed['complete']) {
        $session['status'] = 'idle';
    }

    $session['updated_at'] = $now;

    return ['session' => $session, 'message' => $message, 'tool_calls' => $created_calls];
}

/**
 * @param array<string, mixed> $session
 * @return list<array<string, mixed>>
 */
function wppilot_chat_session_list(array $session, string $key): array
{
    $items = is_array($session[$key] ?? null) ? $session[$key] : [];
    $list = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $list[] = wppilot_chat_assoc_array($item);
    }

    return $list;
}

/**
 * @param array<string, mixed> $session
 * @return list<string>
 */
function wppilot_chat_string_list(array $session, string $key): array
{
    $items = is_array($session[$key] ?? null) ? $session[$key] : [];
    $list = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($items as $item) {
        if (!is_string($item)) {
            continue;
        }
        $list[] = $item;
    }

    return $list;
}

/**
 * @param array<array-key, mixed> $items
 * @return array<string, mixed>
 */
function wppilot_chat_assoc_array(array $items): array
{
    $assoc = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($items as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        $assoc[$key] = $value;
    }

    return $assoc;
}

/**
 * @return array<string, array<string, mixed>>
 */
function wppilot_chat_get_sessions(): array
{
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return [];
    }

    wppilot_chat_ensure_storage_ready();

    $wpdb = wppilot_chat_wpdb();
    $table = wppilot_chat_sessions_table();
    $rows = wppilot_chat_select_sessions_for_user($wpdb, $table, $user_id);

    $sessions = [];
    foreach ($rows as $row) {
        $session_id = is_string($row['id'] ?? null) ? $row['id'] : '';
        $session = wppilot_chat_decode_session_json($row['data'] ?? null, $session_id);
        if ($session_id === '' || $session === null) {
            continue;
        }
        $sessions[$session_id] = $session;
    }

    return $sessions;
}

/**
 * @return array<string, mixed>|null
 */
function wppilot_chat_get_session(string $session_id): ?array
{
    $user_id = get_current_user_id();
    if ($session_id === '' || $user_id <= 0) {
        return null;
    }

    wppilot_chat_ensure_storage_ready();

    $wpdb = wppilot_chat_wpdb();
    $table = wppilot_chat_sessions_table();
    $data = wppilot_chat_select_session_data($wpdb, $table, $session_id, $user_id);

    return wppilot_chat_decode_session_json($data, $session_id);
}

/**
 * @param array<string, mixed> $session
 */
function wppilot_chat_save_session(array $session): void
{
    $session_id = is_string($session['id'] ?? null) ? $session['id'] : '';
    $user_id = get_current_user_id();
    if ($session_id === '' || strlen($session_id) > 64 || $user_id <= 0) {
        return;
    }

    wppilot_chat_ensure_storage_ready();

    $wpdb = wppilot_chat_wpdb();
    $table = wppilot_chat_sessions_table();
    $existing_user_id = wppilot_chat_select_session_owner($wpdb, $table, $session_id);
    if ($existing_user_id !== null && (int) $existing_user_id !== $user_id) {
        return;
    }

    $now = time();
    if (!is_numeric($session['created_at'] ?? null)) {
        $session['created_at'] = $now;
    }
    if (!is_numeric($session['updated_at'] ?? null)) {
        $session['updated_at'] = $now;
    }

    $json = wppilot_chat_encode_session_for_storage($session);
    if ($json === null) {
        return;
    }

    $row = wppilot_chat_storage_row($session, $session_id, $user_id, $json);
    wppilot_chat_persist_session_row($session_id, $user_id, $row, $existing_user_id);

    wppilot_chat_prune_sessions_for_user($user_id);
}

function wppilot_chat_delete_session(string $session_id): void
{
    $user_id = get_current_user_id();
    if ($session_id === '' || $user_id <= 0) {
        return;
    }

    wppilot_chat_ensure_storage_ready();

    $wpdb = wppilot_chat_wpdb();
    $deleted = $wpdb->delete(
        wppilot_chat_sessions_table(),
        [
            'id' => $session_id,
            'user_id' => $user_id,
        ],
        ['%s', '%d'],
    );
    if ($deleted === false) {
        wppilot_chat_log_storage_failure($session_id);
    }
}

/**
 * @param array<string, mixed> $session
 */
function wppilot_chat_fail_session(array $session, string $message): WP_Error
{
    $session['status'] = 'failed';
    $session['error'] = $message;
    $session['updated_at'] = time();
    wppilot_chat_save_session($session);

    return new WP_Error('wppilot_chat_model_failed', $message, ['status' => 500]);
}

/**
 * @param array<string, mixed> $session
 */
function wppilot_chat_record_tool_error(array $session, int $call_index, string $message): array
{
    $now = time();
    $tool_calls = wppilot_chat_session_list($session, key: 'tool_calls');
    $tool_calls[$call_index]['status'] = 'failed';
    $tool_calls[$call_index]['error'] = $message;
    $tool_calls[$call_index]['updated_at'] = $now;
    $session['tool_calls'] = $tool_calls;
    $session['status'] = 'idle';
    $session['updated_at'] = $now;
    wppilot_chat_save_session($session);

    return ['session' => $session, 'tool_call' => $tool_calls[$call_index]];
}
