<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The approval log: an append-only record of every decision a person made about an agent.
 *
 * Rows are written for an approval or denial on the wp-admin link, a session approval and each
 * call it covers, a revoked session, an answer given in the MCP client, a change to the
 * always-ask list, and a new OAuth app approved or dismissed.
 *
 * Append-only by construction: nothing in GoBuildWP updates a row, and the only delete is the
 * daily retention sweep of rows older than wppilot_approval_log_retention_days(). The ability
 * input is never stored, only its SHA-256 fingerprint, so the log can show that the call a person
 * approved is the call that ran without keeping a second copy of what it contained.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_APPROVAL_LOG_SCHEMA_VERSION = 1;

const WPPILOT_APPROVAL_LOG_SCHEMA_OPTION = 'wppilot_approval_log_schema_version';

const WPPILOT_APPROVAL_LOG_PRUNE_HOOK = 'wppilot_approval_log_prune';

/** Default and bounds for how long rows are kept. */
const WPPILOT_APPROVAL_LOG_RETENTION_DAYS = 90;

const WPPILOT_APPROVAL_LOG_RETENTION_MIN_DAYS = 30;

const WPPILOT_APPROVAL_LOG_RETENTION_MAX_DAYS = 3650;

/** Rows a CSV download may carry, so one click cannot hold a request open for minutes. */
const WPPILOT_APPROVAL_LOG_EXPORT_MAX = 20000;

/**
 * Event slugs and their labels.
 *
 * @return array<string, string>
 */
function wppilot_approval_log_events(): array
{
    return [
        'approved' => __('Approved once', domain: 'wppilot'),
        'denied' => __('Denied', domain: 'wppilot'),
        'session-granted' => __('Approved for a session', domain: 'wppilot'),
        'session-used' => __('Call covered by a session', domain: 'wppilot'),
        'session-revoked' => __('Session revoked', domain: 'wppilot'),
        'elicitation-approved' => __('Approved in the MCP client', domain: 'wppilot'),
        'elicitation-declined' => __('Declined in the MCP client', domain: 'wppilot'),
        'card-approved' => __('Approved on the in-chat card', domain: 'wppilot'),
        'card-denied' => __('Denied on the in-chat card', domain: 'wppilot'),
        'always-ask-changed' => __('Always-ask list changed', domain: 'wppilot'),
        'app-approved' => __('New app approved', domain: 'wppilot'),
        'app-rejected' => __('New app dismissed', domain: 'wppilot'),
        'agents-paused' => __('Agents paused from GoBuildWP Cloud', domain: 'wppilot'),
        'agents-resumed' => __('Agents resumed from GoBuildWP Cloud', domain: 'wppilot'),
    ];
}

function wppilot_approval_log_table(): string
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    return $wpdb->prefix . 'wppilot_approval_log';
}

function wppilot_approval_log_retention_days(): int
{
    /** @var mixed $days */
    $days = apply_filters('wppilot_approval_log_retention_days', WPPILOT_APPROVAL_LOG_RETENTION_DAYS);
    $days = is_numeric($days) ? (int) $days : WPPILOT_APPROVAL_LOG_RETENTION_DAYS;

    return max(WPPILOT_APPROVAL_LOG_RETENTION_MIN_DAYS, min(WPPILOT_APPROVAL_LOG_RETENTION_MAX_DAYS, $days));
}

function wppilot_approval_log_maybe_install(): bool
{
    if ((int) get_option(WPPILOT_APPROVAL_LOG_SCHEMA_OPTION, 0) >= WPPILOT_APPROVAL_LOG_SCHEMA_VERSION) {
        return true;
    }

    return wppilot_approval_log_install();
}

function wppilot_approval_log_install(): bool
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

    $table = wppilot_approval_log_table();
    $charset_collate = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            event VARCHAR(32) NOT NULL,
            ability VARCHAR(191) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            agent VARCHAR(191) NOT NULL DEFAULT '',
            input_sha256 VARCHAR(64) NOT NULL DEFAULT '',
            detail VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY event (event)
        ) {$charset_collate};");

    // dbDelta() says nothing useful when CREATE is denied, so the table is read back. A missing
    // table makes the read fail, which is the answer, not an error worth printing.
    $suppress = $wpdb->suppress_errors(true);
    $exists = (string) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table)) !== '';
    $wpdb->suppress_errors($suppress);
    if ($exists) {
        update_option(WPPILOT_APPROVAL_LOG_SCHEMA_OPTION, WPPILOT_APPROVAL_LOG_SCHEMA_VERSION, autoload: false);
    }

    return $exists;
}

/**
 * Append one row. Returns false when the table cannot be written; a decision is never blocked
 * because it could not be logged.
 *
 * @param array{ability?: string, user_id?: int, actor_id?: int, agent?: string, input_sha256?: string, detail?: string} $fields
 */
function wppilot_approval_log_record(string $event, array $fields = []): bool
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    if (!is_object($wpdb) || !array_key_exists($event, wppilot_approval_log_events())) {
        return false;
    }
    if (!wppilot_approval_log_maybe_install()) {
        return false;
    }

    $hash = (string) ($fields['input_sha256'] ?? '');
    $row = [
        'created_at' => gmdate('Y-m-d H:i:s'),
        'event' => $event,
        'ability' => wppilot_approval_log_clip((string) ($fields['ability'] ?? ''), 191),
        'user_id' => max(0, (int) ($fields['user_id'] ?? 0)),
        'actor_id' => max(0, (int) ($fields['actor_id'] ?? 0)),
        'agent' => wppilot_approval_log_clip((string) ($fields['agent'] ?? ''), 191),
        'input_sha256' => preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : '',
        'detail' => wppilot_approval_log_clip((string) ($fields['detail'] ?? ''), 255),
    ];

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The approval log table has no WordPress API.
    return $wpdb->insert(wppilot_approval_log_table(), $row) !== false;
}

function wppilot_approval_log_clip(string $value, int $max): string
{
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '');

    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

/**
 * Normalise filters from a request. Unknown keys are dropped; dates must be Y-m-d.
 *
 * @param array<array-key, mixed> $source Unslashed request values.
 * @return array{event: string, ability: string, since: string, until: string}
 */
function wppilot_approval_log_read_filters(array $source): array
{
    $text = static fn(string $key): string => is_string($source[$key] ?? null) ? sanitize_text_field((string) $source[$key]) : '';
    $date = static function (string $key) use ($text): string {
        $value = $text($key);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    };
    $event = $text('event');

    return [
        'event' => array_key_exists($event, wppilot_approval_log_events()) ? $event : '',
        'ability' => substr($text('ability'), 0, 191),
        'since' => $date('since'),
        'until' => $date('until'),
    ];
}

/**
 * Matching rows, newest first.
 *
 * @param array{event?: string, ability?: string, since?: string, until?: string} $filters
 * @return list<array<string, string>>
 */
function wppilot_approval_log_query(array $filters, int $limit = 50, int $offset = 0): array
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    if (!is_object($wpdb) || (int) get_option(WPPILOT_APPROVAL_LOG_SCHEMA_OPTION, 0) < 1) {
        return [];
    }

    $where = ['1=1'];
    $args = [wppilot_approval_log_table()];
    if (($filters['event'] ?? '') !== '') {
        $where[] = 'event = %s';
        $args[] = (string) $filters['event'];
    }
    if (($filters['ability'] ?? '') !== '') {
        $where[] = 'ability = %s';
        $args[] = (string) $filters['ability'];
    }
    if (($filters['since'] ?? '') !== '') {
        $where[] = 'created_at >= %s';
        $args[] = (string) $filters['since'] . ' 00:00:00';
    }
    if (($filters['until'] ?? '') !== '') {
        $where[] = 'created_at <= %s';
        $args[] = (string) $filters['until'] . ' 23:59:59';
    }
    $args[] = max(1, min(500, $limit));
    $args[] = max(0, $offset);

    // The WHERE clause is assembled from fixed fragments only; every value is a placeholder.
    $sql = 'SELECT id, created_at, event, ability, user_id, actor_id, agent, input_sha256, detail FROM %i WHERE '
        . implode(' AND ', $where)
        . ' ORDER BY id DESC LIMIT %d OFFSET %d';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared on this line; not cached because the log must show a decision made a moment ago.
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
 * Delete rows older than the retention window. The only delete the log has.
 */
function wppilot_approval_log_prune(?int $now = null): int
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    if (!is_object($wpdb) || (int) get_option(WPPILOT_APPROVAL_LOG_SCHEMA_OPTION, 0) < 1) {
        return 0;
    }
    $cutoff = gmdate('Y-m-d H:i:s', ($now ?? time()) - wppilot_approval_log_retention_days() * DAY_IN_SECONDS);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention sweep of a plugin table.
    $deleted = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE created_at < %s', wppilot_approval_log_table(), $cutoff));

    return is_int($deleted) ? $deleted : 0;
}

/**
 * The CSV a download carries. Cells are quoted and defused against formula injection.
 *
 * @param list<array<string, string>> $rows
 */
function wppilot_approval_log_csv(array $rows): string
{
    $columns = ['id', 'created_at', 'event', 'ability', 'user_id', 'actor_id', 'agent', 'input_sha256', 'detail'];
    $lines = [implode(',', array_map('wppilot_approval_log_csv_cell', $columns))];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(static fn(string $c): string => wppilot_approval_log_csv_cell((string) ($row[$c] ?? '')), $columns));
    }

    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
}

/**
 * Agent names and ability names are agent-supplied text: a leading =, +, -, @, tab or CR would
 * be run as a formula by a spreadsheet, so such a cell is prefixed with an apostrophe.
 */
function wppilot_approval_log_csv_cell(string $value): string
{
    if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
        $value = "'" . $value;
    }

    return '"' . str_replace('"', '""', $value) . '"';
}

/**
 * The agent behind the current request, as the log names it.
 */
function wppilot_approval_log_current_agent(): string
{
    $agent = function_exists('wppilot_current_agent') ? wppilot_current_agent() : [];
    $label = is_string($agent['label'] ?? null) ? $agent['label'] : '';
    $client = is_string($agent['client'] ?? null) ? $agent['client'] : '';

    return $label !== '' && $client !== '' ? $label . ' (' . $client . ')' : ($label !== '' ? $label : $client);
}

function wppilot_approval_log_on_app_decision(string $event, string $client_id, string $name, int $user_id): void
{
    wppilot_approval_log_record($event, [
        'actor_id' => $user_id,
        'agent' => $name,
        'detail' => 'client ' . substr($client_id, 0, 200),
    ]);
}

function wppilot_approval_log_register_hooks(): void
{
    add_action('init', static function (): void {
        if (
            function_exists('wp_next_scheduled')
            && (int) get_option(WPPILOT_APPROVAL_LOG_SCHEMA_OPTION, 0) >= 1
            && !wp_next_scheduled(WPPILOT_APPROVAL_LOG_PRUNE_HOOK)
        ) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', WPPILOT_APPROVAL_LOG_PRUNE_HOOK);
        }
    });
    add_action(WPPILOT_APPROVAL_LOG_PRUNE_HOOK, static function (): void {
        wppilot_approval_log_prune();
    });
    add_action('wppilot_oauth_client_approved', static function (string $client_id, string $name = '', int $user_id = 0): void {
        wppilot_approval_log_on_app_decision('app-approved', $client_id, $name, $user_id);
    }, 10, 3);
    add_action('wppilot_oauth_client_rejected', static function (string $client_id, string $name = '', int $user_id = 0): void {
        wppilot_approval_log_on_app_decision('app-rejected', $client_id, $name, $user_id);
    }, 10, 3);
}
