<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The MCP request log: one row per JSON-RPC request an MCP endpoint answered, reads included.
 *
 * A row says when, which credential (access token, OAuth client or application password) and
 * which client, which method and tool, how it ended, how long it took and, when it failed, the
 * error message. It never stores the arguments of a call or what it returned: the only part of a
 * call's input kept is the ability name execute-ability was asked to run, because that names the
 * tool rather than carrying data.
 *
 * Bounded twice: the newest WPPILOT_MCP_REQUEST_LOG_MAX_ROWS rows are kept, and nothing older than
 * WPPILOT_MCP_REQUEST_LOG_MAX_DAYS days. Both are enforced after every insert, so the table
 * never grows past the cap however busy the site is, and again when the screen is read, so a site
 * that stopped being called does not keep month-old rows.
 *
 * Notifications (`notifications/*`) and JSON-RPC responses a client sends back are not requests
 * and are not logged. A batch is logged as one row. Logging can be switched off with the
 * `wppilot_mcp_request_log_enabled` filter; a request is never failed because it could not be
 * logged.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_MCP_REQUEST_LOG_SCHEMA_VERSION = 1;

const WPPILOT_MCP_REQUEST_LOG_SCHEMA_OPTION = 'wppilot_mcp_request_log_schema_version';

const WPPILOT_MCP_REQUEST_LOG_MAX_ROWS = 500;

const WPPILOT_MCP_REQUEST_LOG_MAX_DAYS = 30;

/**
 * Outcome slugs and their labels.
 *
 * @return array<string, string>
 */
function wppilot_mcp_request_log_outcomes(): array
{
    return [
        'ok' => __('Succeeded', domain: 'wppilot'),
        'tool-error' => __('Tool reported an error', domain: 'wppilot'),
        'error' => __('JSON-RPC error', domain: 'wppilot'),
        'denied' => __('Refused (401/403)', domain: 'wppilot'),
        'http-error' => __('HTTP error', domain: 'wppilot'),
    ];
}

function wppilot_mcp_request_log_table(): string
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    return $wpdb->prefix . 'wppilot_mcp_request_log';
}

function wppilot_mcp_request_log_installed(): bool
{
    return (int) get_option(WPPILOT_MCP_REQUEST_LOG_SCHEMA_OPTION, 0) >= WPPILOT_MCP_REQUEST_LOG_SCHEMA_VERSION;
}

function wppilot_mcp_request_log_install(): bool
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    if (!is_object($wpdb)) {
        return false;
    }
    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    $table = wppilot_mcp_request_log_table();
    $charset_collate = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            auth_method VARCHAR(16) NOT NULL DEFAULT '',
            credential VARCHAR(191) NOT NULL DEFAULT '',
            credential_label VARCHAR(191) NOT NULL DEFAULT '',
            client VARCHAR(191) NOT NULL DEFAULT '',
            rpc_method VARCHAR(64) NOT NULL DEFAULT '',
            tool VARCHAR(191) NOT NULL DEFAULT '',
            outcome VARCHAR(16) NOT NULL DEFAULT '',
            http_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            error VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY outcome (outcome)
        ) {$charset_collate};");

    // dbDelta() says nothing useful when CREATE is denied, so the table is read back.
    $suppress = $wpdb->suppress_errors(true);
    $exists = (string) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table)) !== '';
    $wpdb->suppress_errors($suppress);
    if ($exists) {
        update_option(WPPILOT_MCP_REQUEST_LOG_SCHEMA_OPTION, WPPILOT_MCP_REQUEST_LOG_SCHEMA_VERSION, autoload: true);
    }

    return $exists;
}

/**
 * When the current MCP request reached WordPress' REST dispatch, in seconds.
 */
function wppilot_mcp_request_log_started_at(?float $set = null): ?float
{
    static $started = null;
    if ($set !== null) {
        $started = $set;
    }

    return $started;
}

function wppilot_mcp_request_log_is_mcp_route(string $route): bool
{
    return str_starts_with($route, '/mcp/');
}

/**
 * rest_pre_dispatch, before every other GoBuildWP filter: start the clock.
 */
function wppilot_mcp_request_log_start(mixed $result, mixed $server, WP_REST_Request $request): mixed
{
    if (wppilot_mcp_request_log_is_mcp_route($request->get_route())) {
        wppilot_mcp_request_log_started_at(microtime(true));
    }

    return $result;
}

/**
 * rest_post_dispatch, after every other GoBuildWP filter has shaped the response: write the row.
 */
function wppilot_mcp_request_log_finish(mixed $response, mixed $server, WP_REST_Request $request): mixed
{
    if (!$response instanceof WP_REST_Response || !wppilot_mcp_request_log_is_mcp_route($request->get_route())) {
        return $response;
    }
    if (!(bool) apply_filters('wppilot_mcp_request_log_enabled', true)) {
        return $response;
    }

    $row = wppilot_mcp_request_log_row($request, $response);
    if ($row !== null) {
        wppilot_mcp_request_log_record($row);
    }

    return $response;
}

/**
 * The row one request/response pair produces, or null for something that is not a request.
 *
 * @return array<string, int|string>|null
 */
function wppilot_mcp_request_log_row(WP_REST_Request $request, WP_REST_Response $response): ?array
{
    /** @var mixed $body */
    $body = $request->get_json_params();
    $messages = is_array($body) && array_is_list($body) ? $body : [$body];
    $requests = array_values(array_filter(
        $messages,
        static fn(mixed $m): bool => is_array($m)
            && is_string($m['method'] ?? null)
            && !str_starts_with((string) $m['method'], 'notifications/'),
    ));
    $status = $response->get_status();
    // A body that never parsed still counts when it was refused: that is the request an
    // administrator most wants to see.
    if ($requests === [] && ($status < 400 || $messages !== [null])) {
        return null;
    }

    $first = $requests[0] ?? [];
    $method = is_string($first['method'] ?? null) ? $first['method'] : '';
    $tool = $requests === [] ? '' : wppilot_mcp_request_log_tool($first);
    if (count($requests) > 1) {
        $method = 'batch';
        $tool = sprintf('%d requests', count($requests));
    }
    [$outcome, $error] = wppilot_mcp_request_log_outcome($status, $response->get_data());

    $agent = function_exists('wppilot_current_agent') ? wppilot_current_agent() : [];
    $client = is_string($agent['client'] ?? null) ? $agent['client'] : '';
    if ($client === '' && function_exists('wppilot_connection_client_from_handshake')) {
        $client = (string) (wppilot_connection_client_from_handshake($first)['name'] ?? '');
    }
    $auth_method = is_string($agent['method'] ?? null) && $agent['method'] !== 'direct' ? $agent['method'] : '';
    $started = wppilot_mcp_request_log_started_at();
    $server_start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
    $since = $started ?? (is_numeric($server_start) ? (float) $server_start : microtime(true));

    return [
        'created_at' => gmdate('Y-m-d H:i:s'),
        'duration_ms' => max(0, (int) round((microtime(true) - $since) * 1000)),
        'user_id' => max(0, get_current_user_id()),
        'auth_method' => $auth_method,
        'credential' => is_string($agent['credential'] ?? null) ? $agent['credential'] : '',
        'credential_label' => is_string($agent['label'] ?? null) ? $agent['label'] : '',
        'client' => $client,
        'rpc_method' => $method,
        'tool' => $tool,
        'outcome' => $outcome,
        'http_status' => max(0, min(999, $status)),
        'error' => $error,
    ];
}

/**
 * The tool, prompt or resource a request names. For execute-ability, the ability it runs.
 *
 * @param array<array-key, mixed> $message
 */
function wppilot_mcp_request_log_tool(array $message): string
{
    $params = is_array($message['params'] ?? null) ? $message['params'] : [];
    $method = (string) ($message['method'] ?? '');
    if ($method === 'resources/read') {
        $uri = is_string($params['uri'] ?? null) ? $params['uri'] : '';
        // Only the resource itself: a query string could carry data.
        return (string) strtok($uri, '?#');
    }
    $name = is_string($params['name'] ?? null) ? $params['name'] : '';
    if ($method === 'tools/call' && preg_match('/execute[-_]ability$/', $name) === 1) {
        $target = $params['arguments']['ability_name'] ?? null;
        if (is_string($target) && preg_match('#^[a-z0-9_-]+/[a-z0-9_-]+$#i', $target) === 1) {
            return $name . ' > ' . $target;
        }
    }

    return $name;
}

/**
 * How a response ended, and the error message when it failed.
 *
 * @return array{0: string, 1: string}
 */
function wppilot_mcp_request_log_outcome(int $status, mixed $data): array
{
    $bodies = is_array($data) && array_is_list($data) ? $data : [$data];
    foreach ($bodies as $body) {
        if (!is_array($body)) {
            continue;
        }
        // A WP_Error turned into a REST response: {code, message, data}.
        if ($status >= 400 && is_string($body['message'] ?? null)) {
            return [wppilot_mcp_request_log_status_outcome($status), $body['message']];
        }
        $error = $body['error'] ?? null;
        if (is_array($error)) {
            return ['error', is_string($error['message'] ?? null) ? $error['message'] : ''];
        }
        $result = $body['result'] ?? null;
        if (is_array($result) && ($result['isError'] ?? false) === true) {
            $text = '';
            foreach (is_array($result['content'] ?? null) ? $result['content'] : [] as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $text = $part['text'];
                    break;
                }
            }
            return ['tool-error', $text];
        }
    }

    return $status >= 400 ? [wppilot_mcp_request_log_status_outcome($status), ''] : ['ok', ''];
}

function wppilot_mcp_request_log_status_outcome(int $status): string
{
    return $status === 401 || $status === 403 ? 'denied' : 'http-error';
}

/**
 * Append one row and enforce both bounds. Returns false when the table cannot be written.
 *
 * @param array<string, int|string> $row
 */
function wppilot_mcp_request_log_record(array $row): bool
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    if (!is_object($wpdb)) {
        return false;
    }
    if (!wppilot_mcp_request_log_installed()) {
        // A host that denies CREATE would otherwise run dbDelta() on every MCP request.
        if (get_transient('wppilot_mcp_request_log_install_failed') !== false) {
            return false;
        }
        if (!wppilot_mcp_request_log_install()) {
            set_transient('wppilot_mcp_request_log_install_failed', 1, HOUR_IN_SECONDS);
            return false;
        }
    }

    $widths = [
        'auth_method' => 16, 'credential' => 191, 'credential_label' => 191, 'client' => 191,
        'rpc_method' => 64, 'tool' => 191, 'outcome' => 16, 'error' => 255,
    ];
    foreach ($widths as $column => $width) {
        $row[$column] = wppilot_mcp_request_log_clip((string) ($row[$column] ?? ''), $width);
    }

    $suppress = $wpdb->suppress_errors(true);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The request log table has no WordPress API.
    $written = $wpdb->insert(wppilot_mcp_request_log_table(), $row) !== false;
    $wpdb->suppress_errors($suppress);
    if ($written) {
        wppilot_mcp_request_log_prune();
    }

    return $written;
}

function wppilot_mcp_request_log_clip(string $value, int $max): string
{
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '');
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }

    return substr($value, 0, $max);
}

/**
 * Keep the newest WPPILOT_MCP_REQUEST_LOG_MAX_ROWS rows, none older than the day limit.
 */
function wppilot_mcp_request_log_prune(?int $now = null): int
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    if (!is_object($wpdb) || !wppilot_mcp_request_log_installed()) {
        return 0;
    }
    $table = wppilot_mcp_request_log_table();
    $cutoff = gmdate('Y-m-d H:i:s', ($now ?? time()) - WPPILOT_MCP_REQUEST_LOG_MAX_DAYS * DAY_IN_SECONDS);
    // The id of the oldest row that may stay; everything below it goes.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounding a plugin table.
    $keep_from = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d',
        $table,
        WPPILOT_MCP_REQUEST_LOG_MAX_ROWS - 1,
    ));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounding a plugin table.
    $deleted = $wpdb->query($wpdb->prepare(
        'DELETE FROM %i WHERE created_at < %s OR id < %d',
        $table,
        $cutoff,
        $keep_from,
    ));

    return is_int($deleted) ? $deleted : 0;
}

/**
 * Normalise filters from a request. Unknown keys are dropped; dates must be Y-m-d.
 *
 * @param array<array-key, mixed> $source Unslashed request values.
 * @return array{outcome: string, rpc_method: string, tool: string, client: string, since: string, until: string}
 */
function wppilot_mcp_request_log_read_filters(array $source): array
{
    $text = static fn(string $key): string => is_string($source[$key] ?? null) ? sanitize_text_field((string) $source[$key]) : '';
    $date = static function (string $key) use ($text): string {
        $value = $text($key);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    };
    $outcome = $text('outcome');

    return [
        'outcome' => array_key_exists($outcome, wppilot_mcp_request_log_outcomes()) ? $outcome : '',
        'rpc_method' => substr($text('rpc_method'), 0, 64),
        'tool' => substr($text('tool'), 0, 191),
        'client' => substr($text('client'), 0, 191),
        'since' => $date('since'),
        'until' => $date('until'),
    ];
}

/**
 * Matching rows, newest first. Tool and client match anywhere in the value.
 *
 * @param array{outcome?: string, rpc_method?: string, tool?: string, client?: string, since?: string, until?: string} $filters
 * @return list<array<string, string>>
 */
function wppilot_mcp_request_log_query(array $filters, int $limit = 50, int $offset = 0): array
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    if (!is_object($wpdb) || !wppilot_mcp_request_log_installed()) {
        return [];
    }

    $where = ['1=1'];
    $args = [wppilot_mcp_request_log_table()];
    foreach (['outcome', 'rpc_method'] as $exact) {
        if (($filters[$exact] ?? '') !== '') {
            $where[] = $exact . ' = %s';
            $args[] = (string) $filters[$exact];
        }
    }
    foreach (['tool', 'client'] as $partial) {
        if (($filters[$partial] ?? '') !== '') {
            // INSTR() rather than LIKE: the filter is a plain substring, with no wildcards to escape.
            $where[] = 'INSTR(' . $partial . ', %s) > 0';
            $args[] = (string) $filters[$partial];
        }
    }
    if (($filters['since'] ?? '') !== '') {
        $where[] = 'created_at >= %s';
        $args[] = (string) $filters['since'] . ' 00:00:00';
    }
    if (($filters['until'] ?? '') !== '') {
        $where[] = 'created_at <= %s';
        $args[] = (string) $filters['until'] . ' 23:59:59';
    }
    $args[] = max(1, min(WPPILOT_MCP_REQUEST_LOG_MAX_ROWS, $limit));
    $args[] = max(0, $offset);

    // The WHERE clause is assembled from fixed fragments only; every value is a placeholder.
    $sql = 'SELECT id, created_at, duration_ms, user_id, auth_method, credential, credential_label, client, rpc_method, tool, outcome, http_status, error FROM %i WHERE '
        . implode(' AND ', $where)
        . ' ORDER BY id DESC LIMIT %d OFFSET %d';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared on this line; not cached because the log must show the request made a moment ago.
    $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (is_array($row)) {
            $out[] = array_map(static fn(mixed $v): string => (string) $v, $row);
        }
    }

    return $out;
}

/**
 * The CSV a download carries. Cells are quoted and defused against formula injection.
 *
 * @param list<array<string, string>> $rows
 */
function wppilot_mcp_request_log_csv(array $rows): string
{
    $columns = [
        'id', 'created_at', 'duration_ms', 'user_id', 'auth_method', 'credential', 'credential_label',
        'client', 'rpc_method', 'tool', 'outcome', 'http_status', 'error',
    ];
    $lines = [implode(',', array_map('wppilot_approval_log_csv_cell', $columns))];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(
            static fn(string $c): string => wppilot_approval_log_csv_cell((string) ($row[$c] ?? '')),
            $columns,
        ));
    }

    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
}

// Priority 0: before the IP allowlist (1) can refuse the request, so a refusal is timed too.
add_filter('rest_pre_dispatch', 'wppilot_mcp_request_log_start', priority: 0, accepted_args: 3);
// Priority 100: after every GoBuildWP response filter (the last is skill resources at 35).
add_filter('rest_post_dispatch', 'wppilot_mcp_request_log_finish', priority: 100, accepted_args: 3);
