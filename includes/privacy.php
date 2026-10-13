<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Privacy operations must read and erase GoBuildWP's custom tables. Table names are fixed plugin tables and every value is prepared.

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_PRIVACY_PAGE_SIZE = 25;

/**
 * Add accurate suggested text to WordPress's Privacy Policy Guide.
 */
function wppilot_add_privacy_policy_content(): void
{
    $content =
        '<p>'
        . wp_kses_post(__(
            'GoBuildWP stores AI-client connection history, the associated WordPress user, client identity, request counts, and last-used times. Its change ledger records each write an AI client makes, with the WordPress user, the agent credential identifier and client name behind it, and a redacted summary of the change. When GoBuildWP Chat is used, it stores chat sessions, messages, image attachments, tool calls, and tool results in this site’s database.',
            domain: 'wppilot',
        ))
        . '</p>';
    $content .=
        '<p>'
        . wp_kses_post(__(
            'GoBuildWP Chat sends the conversation history, selected attachments, site instructions, available tool definitions, and relevant tool results to the AI provider and model selected through the WordPress AI Client. That provider is an external service and its own terms and privacy policy apply. The MCP server itself connects AI clients directly to this WordPress site and does not require a GoBuildWP-hosted content relay.',
            domain: 'wppilot',
        ))
        . '</p>';
    $content .=
        '<p>'
        . wp_kses_post(__(
            'OAuth records include registered client names, redirect addresses, a one-way registration IP hash, user-linked authorization records, expiry times, and revocation status. Access tokens are stored against the user who created them as a one-way digest with a name, the last four characters, and created, last-used and expiry times; the token itself is never stored and cannot be exported. Site administrators can revoke connected applications and individual access tokens. WordPress personal-data export and erase tools include GoBuildWP records associated with a user email address.',
            domain: 'wppilot',
        ))
        . '</p>';

    // Only builds from gobuildwp.com carry the telemetry code; the wordpress.org
    // build has the directory removed by scripts/package.sh. Suggesting text
    // about reporting that cannot happen would be its own kind of inaccuracy.
    if (function_exists('WPPilot\Telemetry\enabled')) {
        $content .=
            '<p>'
            . wp_kses_post(__(
                'If anonymous usage reporting has been switched on under GoBuildWP → Settings, GoBuildWP sends a daily report to gobuildwp.com containing this site’s address, a random installation identifier, the GoBuildWP, WordPress and PHP versions, the site locale, whether GoBuildWP Pro is active, the active safety profile, and the number of recorded AI-client connections. It is off unless somebody switches it on, and it contains no usernames, no email addresses, no page or post content, and no record of what an AI client did. Switching it back off also sends a request to delete the reports already collected. Detailed reports are kept for 90 days and then reduced to daily totals, and an installation that stops reporting for 400 days is deleted.',
                domain: 'wppilot',
            ))
            . '</p>';
    }

    wp_add_privacy_policy_content(__('GoBuildWP', domain: 'wppilot'), wpautop($content, br: false));
}

/** @param array<string, array{exporter_friendly_name: string, callback: callable}> $exporters */
function wppilot_register_personal_data_exporter(array $exporters): array
{
    $exporters['wppilot'] = [
        'exporter_friendly_name' => __('GoBuildWP data', domain: 'wppilot'),
        'callback' => 'wppilot_personal_data_exporter',
    ];

    return $exporters;
}

/** @param array<string, array{eraser_friendly_name: string, callback: callable}> $erasers */
function wppilot_register_personal_data_eraser(array $erasers): array
{
    $erasers['wppilot'] = [
        'eraser_friendly_name' => __('GoBuildWP data', domain: 'wppilot'),
        'callback' => 'wppilot_personal_data_eraser',
    ];

    return $erasers;
}

/**
 * @return array{data: list<array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: mixed}>}>, done: bool}
 */
function wppilot_personal_data_exporter(string $email_address, int $page = 1): array
{
    $user = get_user_by('email', $email_address);
    if (!$user instanceof WP_User) {
        return ['data' => [], 'done' => true];
    }

    $offset = max(0, $page - 1) * WPPILOT_PRIVACY_PAGE_SIZE;
    $groups = wppilot_privacy_user_rows((int) $user->ID, $offset, WPPILOT_PRIVACY_PAGE_SIZE);
    $data = [];
    $done = true;

    foreach ($groups as $group_id => $group) {
        if (count($group['rows']) >= WPPILOT_PRIVACY_PAGE_SIZE) {
            $done = false;
        }
        foreach ($group['rows'] as $index => $row) {
            $fields = [];
            foreach ($row as $name => $value) {
                $fields[] = [
                    'name' => ucwords(str_replace(search: '_', replace: ' ', subject: (string) $name)),
                    'value' => is_scalar($value) || $value === null ? $value : wp_json_encode($value),
                ];
            }
            $data[] = [
                'group_id' => $group_id,
                'group_label' => $group['label'],
                'item_id' => $group_id . '-' . ($offset + $index + 1),
                'data' => $fields,
            ];
        }
    }

    return ['data' => $data, 'done' => $done];
}

/**
 * @return array<string, array{label: string, rows: list<array<string, mixed>>}>
 */
function wppilot_privacy_user_rows(int $user_id, int $offset, int $limit): array
{
    // @mago-expect lint:no-global -- WordPress exposes its database connection through this global.
    global $wpdb;
    /** @var wpdb $wpdb */

    $groups = [
        'wppilot-connections' => ['label' => __('GoBuildWP connections', domain: 'wppilot'), 'rows' => []],
        'wppilot-chat' => ['label' => __('GoBuildWP Chat sessions', domain: 'wppilot'), 'rows' => []],
        'wppilot-oauth-codes' => ['label' => __('GoBuildWP OAuth authorizations', domain: 'wppilot'), 'rows' => []],
        'wppilot-oauth-tokens' => ['label' => __('GoBuildWP OAuth access grants', domain: 'wppilot'), 'rows' => []],
        'wppilot-access-tokens' => ['label' => __('GoBuildWP access tokens', domain: 'wppilot'), 'rows' => []],
    ];

    $connections = wppilot_connections_table();
    if (wppilot_privacy_table_exists($connections)) {
        // @mago-expect analysis:possibly-invalid-argument -- Trusted custom table name; every value is prepared.
        $groups['wppilot-connections']['rows'] = wppilot_privacy_results((string) $wpdb->prepare(
            "SELECT method, label, client_name, client_version, first_seen, last_seen, request_count FROM {$connections} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset,
        ));
    }

    $chat = wppilot_chat_sessions_table();
    if (wppilot_privacy_table_exists($chat)) {
        // @mago-expect analysis:possibly-invalid-argument -- Trusted custom table name; every value is prepared.
        $groups['wppilot-chat']['rows'] = wppilot_privacy_results((string) $wpdb->prepare(
            "SELECT id, created_at, updated_at, data FROM {$chat} WHERE user_id = %d ORDER BY created_at ASC LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset,
        ));
    }

    $codes = $wpdb->prefix . 'wppilot_oauth_auth_codes';
    if (wppilot_privacy_table_exists($codes)) {
        // @mago-expect analysis:possibly-invalid-argument -- Trusted custom table name; every value is prepared.
        $groups['wppilot-oauth-codes']['rows'] = wppilot_privacy_results((string) $wpdb->prepare(
            "SELECT client_id, expires_at, scopes, redirect_uri, revoked FROM {$codes} WHERE user_id = %d ORDER BY expires_at ASC LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset,
        ));
    }

    $tokens = $wpdb->prefix . 'wppilot_oauth_access_tokens';
    if (wppilot_privacy_table_exists($tokens)) {
        // @mago-expect analysis:possibly-invalid-argument -- Trusted custom table name; every value is prepared.
        $groups['wppilot-oauth-tokens']['rows'] = wppilot_privacy_results((string) $wpdb->prepare(
            "SELECT client_id, expires_at, scopes, revoked FROM {$tokens} WHERE user_id = %d ORDER BY expires_at ASC LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset,
        ));
    }

    $access_tokens = wppilot_tokens_table();
    if (wppilot_privacy_table_exists($access_tokens)) {
        // The digest is deliberately not exported: it is the credential's stored
        // form, and an export is a document that leaves the site.
        // @mago-expect analysis:possibly-invalid-argument -- Trusted custom table name; every value is prepared.
        $groups['wppilot-access-tokens']['rows'] = wppilot_privacy_results((string) $wpdb->prepare(
            "SELECT name, last_four, created, last_used, expires FROM {$access_tokens} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset,
        ));
    }

    return $groups;
}

/** @return list<array<string, mixed>> */
function wppilot_privacy_results(string $sql): array
{
    // @mago-expect lint:no-global -- WordPress exposes its database connection through this global.
    global $wpdb;
    /** @var wpdb $wpdb */
    /** @var mixed $rows */
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Every caller passes a fully prepared query built from trusted GoBuildWP table names.
    $rows = $wpdb->get_results($sql, ARRAY_A);

    if (!is_array($rows)) {
        return [];
    }

    $normalized = [];
    // @mago-expect analysis:mixed-assignment -- ARRAY_A rows are runtime-typed associative arrays.
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $item = [];
        // @mago-expect analysis:mixed-assignment
        foreach ($row as $key => $value) {
            $item[(string) $key] = $value;
        }
        $normalized[] = $item;
    }

    return $normalized;
}

function wppilot_privacy_table_exists(string $table): bool
{
    // @mago-expect lint:no-global -- WordPress exposes its database connection through this global.
    global $wpdb;
    /** @var wpdb $wpdb */
    $found = $wpdb->get_var((string) $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));

    return is_string($found) && hash_equals($table, $found);
}

/** @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool} */
function wppilot_personal_data_eraser(string $email_address, int $page = 1): array
{
    $user = get_user_by('email', $email_address);
    if (!$user instanceof WP_User) {
        return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    $removed = wppilot_erase_user_data((int) $user->ID);

    return [
        'items_removed' => $removed,
        'items_retained' => false,
        'messages' => $removed ? [__('GoBuildWP records associated with this user were erased.', domain: 'wppilot')] : [],
        'done' => true,
    ];
}

/**
 * Erase current-site records associated with a WordPress user.
 */
function wppilot_erase_user_data(int $user_id): bool
{
    if ($user_id <= 0) {
        return false;
    }

    // @mago-expect lint:no-global -- WordPress exposes its database connection through this global.
    global $wpdb;
    /** @var wpdb $wpdb */
    $removed = false;

    foreach ([wppilot_connections_table(), wppilot_chat_sessions_table(), wppilot_tokens_table()] as $table) {
        if (wppilot_privacy_table_exists($table)) {
            $deleted = $wpdb->delete($table, ['user_id' => $user_id], ['%d']);
            $removed = is_int($deleted) && $deleted > 0 || $removed;
        }
    }

    $access = $wpdb->prefix . 'wppilot_oauth_access_tokens';
    $refresh = $wpdb->prefix . 'wppilot_oauth_refresh_tokens';
    if (wppilot_privacy_table_exists($access)) {
        /** @var mixed $hashes */
        // @mago-expect analysis:possibly-invalid-argument -- Trusted custom table name; user id is prepared.
        $hashes = $wpdb->get_col((string) $wpdb->prepare(
            "SELECT identifier_hash FROM {$access} WHERE user_id = %d",
            $user_id,
        ));
        if (is_array($hashes) && $hashes !== [] && wppilot_privacy_table_exists($refresh)) {
            $placeholders = implode(', ', array_fill(start_index: 0, count: count($hashes), value: '%s'));
            // @mago-expect analysis:possibly-invalid-argument -- Trusted custom table name and generated placeholders.
            $wpdb->query((string) $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The placeholder list is generated from the number of stored hashes and every value is passed to prepare().
                "DELETE FROM {$refresh} WHERE access_token_hash IN ({$placeholders})",
                array_values($hashes),
            ));
        }
        $deleted = $wpdb->delete($access, ['user_id' => $user_id], ['%d']);
        $removed = is_int($deleted) && $deleted > 0 || $removed;
    }

    $codes = $wpdb->prefix . 'wppilot_oauth_auth_codes';
    if (wppilot_privacy_table_exists($codes)) {
        $deleted = $wpdb->delete($codes, ['user_id' => $user_id], ['%d']);
        $removed = is_int($deleted) && $deleted > 0 || $removed;
    }

    foreach ([
        'wppilot_chat_consent',
        'wppilot_production_warning_dismissed',
        'wppilot_troubleshoot_dismissed',
    ] as $key) {
        delete_user_meta($user_id, $key);
    }

    return $removed;
}

function wppilot_privacy_deleted_user(int $user_id): void
{
    wppilot_erase_user_data($user_id);
}

add_action('admin_init', callback: 'wppilot_add_privacy_policy_content');
add_filter('wp_privacy_personal_data_exporters', callback: 'wppilot_register_personal_data_exporter');
add_filter('wp_privacy_personal_data_erasers', callback: 'wppilot_register_personal_data_eraser');
add_action('deleted_user', callback: 'wppilot_privacy_deleted_user');
add_action('wpmu_delete_user', callback: 'wppilot_privacy_deleted_user');
