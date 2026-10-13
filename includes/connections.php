<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom tables created in includes/oauth/schema.php; WordPress has no API for them. Table names come from $wpdb->prefix plus fixed suffixes - never from input - and every value goes through $wpdb->prepare().

/**
 * A site-wide record of which credentials have actually reached the MCP endpoint.
 *
 * Before this, the only signal was `wppilot_mcp_last_request`: two integers for
 * the whole site, throttled to one write a minute. It could not answer who is
 * connected, how much they call, or whether anything connected overnight.
 * Application passwords were the worst case — they are per-user, so an
 * administrator could only ever see their own, and a site with several admins
 * had no way to see the rest.
 *
 * One row per credential, upserted in a single statement so concurrent agent
 * traffic cannot lose a count the way a read-modify-write option would.
 *
 * Deliberately not stored: IP addresses, tokens, or anything about the request
 * body. This answers "who is connected and how much", nothing more.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_CONNECTIONS_SCHEMA_VERSION = 2;

const WPPILOT_CONNECTIONS_SCHEMA_OPTION = 'wppilot_connections_schema_version';

function wppilot_connections_table(): string
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    return $wpdb->prefix . 'wppilot_connections';
}

function wppilot_connections_schema_maybe_install(): void
{
    if ((int) get_option(WPPILOT_CONNECTIONS_SCHEMA_OPTION, default_value: 0) >= WPPILOT_CONNECTIONS_SCHEMA_VERSION) {
        return;
    }

    wppilot_connections_schema_install();
}

function wppilot_connections_schema_install(): void
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = wppilot_connections_table();
    $charset_collate = $wpdb->get_charset_collate();

    dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            method VARCHAR(16) NOT NULL,
            credential_key VARCHAR(64) NOT NULL,
            label VARCHAR(191) NOT NULL,
            client_key VARCHAR(32) NOT NULL DEFAULT '',
            client_name VARCHAR(100) NOT NULL DEFAULT '',
            client_version VARCHAR(50) NOT NULL DEFAULT '',
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            request_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY credential (method,credential_key,client_key),
            KEY last_seen (last_seen)
        ) {$charset_collate};");

    update_option(WPPILOT_CONNECTIONS_SCHEMA_OPTION, WPPILOT_CONNECTIONS_SCHEMA_VERSION, autoload: false);
}

/**
 * Identify the credential behind the current request.
 *
 * Application passwords carry a UUID that WordPress publishes after a
 * successful authentication, which is the stable per-credential identity.
 *
 * OAuth is recorded per registered client when the validated token exposes the
 * request-local client identity. Legacy/internal calls fall back to the user.
 *
 * @return array{key: string, label: string}|null
 */
function wppilot_connection_credential(string $method, int $user_id): ?array
{
    // @mago-expect lint:no-insecure-comparison -- transport slug, not a secret.
    if ($method === 'token') {
        return wppilot_connection_token_credential();
    }

    // `wp wppilot mcp serve`: no credential at all, only the user WP-CLI runs as.
    // @mago-expect lint:no-insecure-comparison -- transport slug, not a secret.
    if ($method === 'stdio') {
        return $user_id > 0 ? ['key' => 'stdio-user-' . $user_id, 'label' => __('WP-CLI stdio', domain: 'wppilot')] : null;
    }

    if ($method === 'oauth') {
        $identity = function_exists('WPPilot\\OAuth\\Middleware\\request_oauth_identity')
            ? \WPPilot\OAuth\Middleware\request_oauth_identity()
            : null;
        $client_id = is_array($identity) && is_string($identity['client_id'] ?? null) ? $identity['client_id'] : '';

        return [
            'key' => $client_id !== '' ? 'client-' . hash('sha256', $client_id) : 'user-' . $user_id,
            'label' => __('OAuth client', domain: 'wppilot'),
        ];
    }

    // 'password' here is the transport slug from mcp_route_method(), not a
    // credential — nothing sensitive is compared.
    // @mago-expect lint:no-insecure-comparison
    if ($method !== 'password') {
        return null;
    }

    // WordPress publishes the authenticated application password's UUID here
    // and offers no accessor for it, so the global is the only source.
    // @mago-expect lint:no-global
    /** @var mixed $uuid */
    $uuid = $GLOBALS['wp_rest_application_password_uuid'] ?? null;
    if (!is_string($uuid) || $uuid === '') {
        return null;
    }

    return ['key' => $uuid, 'label' => wppilot_connection_password_label($user_id, $uuid)];
}

/**
 * The credential identity of an access-token request.
 *
 * An access token is its own credential: the row id is stable for the life of the
 * token, and the name its creator gave it is what the Overview screen should show
 * rather than a row number. The id travels on the request-local identity the
 * middleware recorded, prefixed `token-` there so it can never collide with an
 * OAuth client key.
 *
 * @return array{key: string, label: string}|null
 */
function wppilot_connection_token_credential(): ?array
{
    $identity = function_exists('WPPilot\\OAuth\\Middleware\\request_oauth_identity')
        ? \WPPilot\OAuth\Middleware\request_oauth_identity()
        : null;
    $client_id = is_array($identity) && is_string($identity['client_id'] ?? null) ? $identity['client_id'] : '';
    if (!str_starts_with($client_id, 'token-')) {
        return null;
    }

    $token_id = (int) substr($client_id, offset: strlen('token-'));
    $name = $token_id > 0 ? wppilot_token_name($token_id) : '';

    return ['key' => $client_id, 'label' => $name !== '' ? $name : __('Access token', domain: 'wppilot')];
}

/**
 * Which agent is behind the current request.
 *
 * The WordPress user is not an agent identity. Claude Code, Cursor and Codex all
 * connect as the same administrator on most sites, so a ledger entry naming only
 * the user cannot answer which of them made a change. The credential can: an
 * OAuth client id or an application-password UUID is issued per agent and stays
 * stable across that agent's requests.
 *
 * Resolved once, at the MCP entry point, because that is the only place the
 * pieces are reachable — the credential comes from request-local OAuth state or
 * WordPress' application-password global, and the client name needs the
 * WP_REST_Request. The change ledger reads it back later in the same request.
 *
 * Anything that is not an authenticated MCP request — wp-admin, WP-CLI, cron, or
 * another plugin calling an ability directly — keeps `direct`, so a write with no
 * agent behind it is never credited to the last agent seen.
 *
 * @param array{method: string, credential: string, label: string, client: string, client_version: string}|null $set
 *     Resolved identity, set by the MCP entry point only.
 * @return array{method: string, credential: string, label: string, client: string, client_version: string}
 */
function wppilot_current_agent(?array $set = null): array
{
    /** @var array{method: string, credential: string, label: string, client: string, client_version: string} $agent */
    static $agent = [
        'method' => 'direct',
        'credential' => '',
        'label' => '',
        'client' => '',
        'client_version' => '',
    ];

    if ($set !== null) {
        $agent = $set;
    }

    return $agent;
}

/**
 * Build the agent identity for an authenticated MCP request.
 *
 * An empty credential is left empty rather than filled in with the user: a blank
 * reads as "not attributable", where a user id would read as a positive claim
 * about which agent acted.
 *
 * @param array{name: string, version: string} $client
 * @return array{method: string, credential: string, label: string, client: string, client_version: string}
 */
function wppilot_resolve_agent(string $method, int $user_id, array $client): array
{
    $credential = wppilot_connection_credential($method, $user_id);

    return [
        'method' => $method,
        'credential' => $credential === null ? '' : $credential['key'],
        'label' => $credential === null ? '' : $credential['label'],
        'client' => $client['name'],
        'client_version' => $client['version'],
    ];
}

/**
 * The display name of an application password, falling back to its short UUID.
 */
function wppilot_connection_password_label(int $user_id, string $uuid): string
{
    if (!class_exists('WP_Application_Passwords')) {
        return $uuid;
    }

    /** @var mixed $passwords */
    $passwords = WP_Application_Passwords::get_user_application_passwords($user_id);
    if (!is_array($passwords)) {
        return $uuid;
    }

    /** @var mixed $password */
    foreach ($passwords as $password) {
        // Matching a public identifier to find its label, not verifying a
        // credential — WordPress has already authenticated the request.
        if (!is_array($password) || ($password['uuid'] ?? null) !== $uuid) {
            continue;
        }
        $name = (string) ($password['name'] ?? '');
        if ($name !== '') {
            return $name;
        }
    }

    return $uuid;
}

/**
 * How long a session's client identity is remembered. Comfortably longer than an
 * editing session, short enough that an abandoned session id does not linger.
 *
 * A function rather than a constant because WordPress' time constants are not
 * typed, so the product reads as float|int at every call site.
 */
function wppilot_connection_client_ttl(): int
{
    return (int) (12 * HOUR_IN_SECONDS);
}

/**
 * Which software is on the other end of this request.
 *
 * MCP clients introduce themselves in `initialize` with `clientInfo.name`, and
 * that is the protocol's only self-description — every later call in the session
 * carries none. The adapter does hand out an `Mcp-Session-Id` that the client
 * echoes back, so the name is learned once and looked up by session afterwards.
 * Without that, only the first call of a session could be attributed and every
 * tool call would land in an anonymous bucket.
 *
 * The User-Agent is the last fallback, and only when it resolves to a client the
 * registry recognises. An unrecognised UA is usually the HTTP library rather
 * than the product ("node", "python-httpx"), and recording that would replace an
 * honest blank with a misleading one.
 *
 * @return array{name: string, version: string}
 */
function wppilot_connection_client(WP_REST_Request $request): array
{
    $session = trim((string) $request->get_header('mcp_session_id'));

    $introduced = wppilot_connection_client_from_handshake($request->get_json_params());
    if ($introduced !== null) {
        // The response carries the session id the client will echo back, but it
        // is not readable from here — so remember against the id sent with this
        // request when there is one, and leave the very first handshake of a
        // brand-new session to wppilot_remember_connection_client().
        if ($session !== '') {
            set_transient(wppilot_connection_session_key($session), $introduced, wppilot_connection_client_ttl());
        }

        return $introduced;
    }

    if ($session !== '') {
        /** @var mixed $remembered */
        $remembered = get_transient(wppilot_connection_session_key($session));
        if (is_array($remembered) && is_string($remembered['name'] ?? null)) {
            return [
                'name' => (string) $remembered['name'],
                'version' => is_string($remembered['version'] ?? null) ? (string) $remembered['version'] : '',
            ];
        }
    }

    $agent = (string) $request->get_header('user_agent');
    if ($agent !== '' && wppilot_client_key($agent) !== null) {
        return ['name' => substr($agent, offset: 0, length: 100), 'version' => ''];
    }

    return ['name' => '', 'version' => ''];
}

/**
 * The clientInfo an MCP `initialize` body introduces itself with, if any.
 *
 * @param mixed $params Decoded JSON-RPC body.
 * @return array{name: string, version: string}|null
 */
function wppilot_connection_client_from_handshake(mixed $params): ?array
{
    if (!is_array($params) || ($params['method'] ?? null) !== 'initialize') {
        return null;
    }

    /** @var mixed $info */
    $info = $params['params']['clientInfo'] ?? null;
    if (!is_array($info)) {
        return null;
    }

    $name = is_string($info['name'] ?? null) ? trim((string) $info['name']) : '';
    if ($name === '') {
        return null;
    }

    $version = is_string($info['version'] ?? null) ? trim((string) $info['version']) : '';

    return [
        'name' => substr($name, offset: 0, length: 100),
        'version' => substr($version, offset: 0, length: 50),
    ];
}

/** Transient key for a session's remembered client identity. */
function wppilot_connection_session_key(string $session): string
{
    return 'wppilot_client_' . md5($session);
}

/**
 * Learn the session id the adapter just issued, and file this client under it.
 *
 * The handshake that carries `clientInfo` is also the one that has no session id
 * yet — the adapter mints it and returns it in a response header. So the request
 * side can read the name but not the key to store it under, and only here, after
 * dispatch, are both in hand. Without this every call after the handshake would
 * arrive anonymous and be recorded as a separate unidentified connection.
 *
 * @param mixed $response
 * @param mixed $server
 * @return mixed
 */
function wppilot_remember_connection_client(mixed $response, mixed $server, WP_REST_Request $request): mixed
{
    if (!$response instanceof WP_REST_Response || !str_starts_with($request->get_route(), '/mcp/')) {
        return $response;
    }

    $client = wppilot_connection_client_from_handshake($request->get_json_params());
    if ($client === null) {
        return $response;
    }

    $session = '';
    /** @var mixed $value */
    foreach ($response->get_headers() as $header => $value) {
        if (strcasecmp((string) $header, 'Mcp-Session-Id') === 0) {
            $session = trim((string) $value);
            break;
        }
    }

    if ($session !== '') {
        set_transient(wppilot_connection_session_key($session), $client, wppilot_connection_client_ttl());
    }

    return $response;
}

/**
 * Bucket a connection row by client.
 *
 * Recognised clients collapse to their registry key so "claude-ai" and
 * "Claude Desktop" are one connection rather than two. Anything else is hashed:
 * the raw name can be long and arbitrary, and this only has to be stable and
 * short enough to sit in a unique index.
 */
function wppilot_connection_client_key(string $client_name): string
{
    if ($client_name === '') {
        return '';
    }

    return wppilot_client_key($client_name) ?? substr(md5(strtolower($client_name)), offset: 0, length: 32);
}

/**
 * Record one authenticated MCP request against its credential.
 *
 * A single upsert: no read first, so two agents calling at once both count.
 *
 * @param array{name: string, version: string} $client Empty name means "this
 *        request did not say", which must not erase what a previous one did.
 */
function wppilot_record_connection(string $method, int $user_id, array $client = ['name' => '', 'version' => '']): void
{
    if ($user_id <= 0) {
        return;
    }

    $credential = wppilot_connection_credential($method, $user_id);
    if ($credential === null) {
        return;
    }

    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    $table = wppilot_connections_table();
    $now = gmdate('Y-m-d H:i:s');

    // @mago-expect analysis:possibly-invalid-argument
    // One row per credential *and* client. A single application password used
    // from both Claude Code and Cursor is two connections, and the whole point of
    // the screen is to show that; merging them would also hide every OAuth client
    // behind one row, since OAuth records per user rather than per client.
    $sql = $wpdb->prepare(
        "INSERT INTO `{$table}`
            (user_id, method, credential_key, label, client_key, client_name, client_version,
             first_seen, last_seen, request_count)
         VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, 1)
         ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            label = VALUES(label),
            client_name = IF(VALUES(client_name) <> '', VALUES(client_name), client_name),
            client_version = IF(VALUES(client_name) <> '', VALUES(client_version), client_version),
            last_seen = VALUES(last_seen),
            request_count = request_count + 1",
        $user_id,
        $method,
        $credential['key'],
        $credential['label'],
        wppilot_connection_client_key($client['name']),
        $client['name'],
        $client['version'],
        $now,
        $now,
    );

    if (is_string($sql)) {
        // @mago-expect analysis:possibly-invalid-argument
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values are bound by $wpdb->prepare() above; Plugin Check cannot follow the prepared statement through the variable. The only interpolation is the table name, which prepare() has no placeholder for. Not cached: this reads live per-request state.
        $wpdb->query($sql);
    }
}

/**
 * Every recorded connection, busiest-recent first.
 *
 * @return list<array<string, mixed>>
 */
function wppilot_get_connections(int $limit = 50): array
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    $table = wppilot_connections_table();

    // @mago-expect analysis:possibly-invalid-argument
    $sql = $wpdb->prepare("SELECT * FROM `{$table}` ORDER BY last_seen DESC LIMIT %d", $limit);
    if (!is_string($sql)) {
        return [];
    }

    // @mago-expect analysis:possibly-invalid-argument
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values are bound by $wpdb->prepare() above; Plugin Check cannot follow the prepared statement through the variable. The only interpolation is the table name, which prepare() has no placeholder for. Not cached: this reads live per-request state.
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if (!is_array($rows)) {
        return [];
    }

    $out = [];
    /** @var mixed $row */
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        /** @var array<string, mixed> $connection */
        $connection = $row;
        $out[] = $connection;
    }

    return $out;
}

/**
 * Whether the credential a connection was made with still exists.
 *
 * A revoked application password leaves its connection row behind, and the row
 * carries the client's name and last-seen time — so the dashboard went on
 * presenting a dead credential as though the client could still walk in. It
 * cannot: the password is gone and that client is locked out. Saying so is the
 * difference between a list of connections and a list of things that once
 * happened.
 *
 * OAuth connections are keyed by user rather than by a revocable secret, so
 * they are reported live as long as the user exists; individual OAuth grants
 * are revoked on the Connected Apps screen, which owns that state.
 */
function wppilot_connection_credential_exists(string $method, string $credential_key, int $user_id): bool
{
    // 'password' is the transport slug, not a secret.
    // @mago-expect lint:no-insecure-comparison
    if ($method !== 'password') {
        return get_userdata($user_id) instanceof WP_User;
    }

    if (!class_exists('WP_Application_Passwords')) {
        return false;
    }

    /** @var mixed $passwords */
    $passwords = WP_Application_Passwords::get_user_application_passwords($user_id);
    if (!is_array($passwords)) {
        return false;
    }

    /** @var mixed $password */
    foreach ($passwords as $password) {
        if (is_array($password) && ($password['uuid'] ?? null) === $credential_key) {
            return true;
        }
    }

    return false;
}

/**
 * Forget one recorded connection.
 *
 * Deliberately a delete rather than a flag. The row is a record that a client
 * introduced itself once; there is no state to preserve, and if that client
 * connects again it records itself afresh on the next handshake. Returns the
 * number of rows removed so the caller can tell "gone" from "was not there".
 */
function wppilot_forget_connection(int $id): int
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; WordPress has no API for it. The value is bound by $wpdb->delete().
    $deleted = $wpdb->delete(wppilot_connections_table(), ['id' => $id], ['%d']);

    return is_int($deleted) ? $deleted : 0;
}

/**
 * Forget every connection whose credential no longer exists.
 *
 * Housekeeping for the common case: someone revokes an application password and
 * expects the clients that used it to stop being listed. Returns how many rows
 * went, so the screen can say what it did.
 */
function wppilot_forget_stale_connections(): int
{
    $removed = 0;

    foreach (wppilot_get_connections(limit: 200) as $connection) {
        $method = is_string($connection['method'] ?? null) ? $connection['method'] : '';
        $key = is_string($connection['credential_key'] ?? null) ? $connection['credential_key'] : '';
        $user_id = is_numeric($connection['user_id'] ?? null) ? (int) $connection['user_id'] : 0;
        $id = is_numeric($connection['id'] ?? null) ? (int) $connection['id'] : 0;

        if ($id <= 0 || wppilot_connection_credential_exists($method, $key, $user_id)) {
            continue;
        }

        $removed += wppilot_forget_connection($id);
    }

    return $removed;
}

add_action('plugins_loaded', callback: 'wppilot_connections_schema_maybe_install');

add_filter('rest_post_dispatch', callback: 'wppilot_remember_connection_client', priority: 20, accepted_args: 3);
