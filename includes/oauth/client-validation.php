<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom tables created in includes/oauth/schema.php; WordPress has no API for them. Table names come from $wpdb->prefix plus fixed suffixes - never from input - and every value goes through $wpdb->prepare().

namespace WPPilot\OAuth\ClientValidation;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Custom URI schemes an AI client may register a redirect for.
 *
 * Desktop MCP clients complete the OAuth flow either on a loopback port (handled
 * separately below, per RFC 8252) or on a private scheme the operating system
 * routes back to the app. A scheme missing from this list is not a cosmetic gap:
 * dynamic client registration is refused outright, so the client can never
 * finish sign-in and the user sees a failure with no explanation. VS Code,
 * Windsurf and Zed were all in that position.
 *
 * Widening this is low risk. A redirect_uri is only ever emitted in a 302 to the
 * user's own browser — this server never fetches it — and PKCE S256 is mandatory,
 * so a scheme hijack cannot complete an exchange without the verifier.
 */
const ALLOWED_SCHEMES = [
    'https',
    'claude',
    'cursor',
    'ms-onboarding-claude-code',
    'chatgpt',
    'code',
    'vscode',
    'vscode-insiders',
    'windsurf',
    'zed',
];

/**
 * The schemes actually accepted, after filtering.
 *
 * New AI clients appear faster than plugin releases, so a site can allow one
 * without waiting for an update.
 *
 * @return list<string>
 */
function allowed_schemes(): array
{
    /**
     * Filter the custom URI schemes accepted in an OAuth redirect_uri.
     *
     * @param list<string> $schemes Lowercase scheme names, without the '://'.
     */
    /** @var mixed $schemes */
    $schemes = apply_filters('wppilot_oauth_allowed_redirect_schemes', ALLOWED_SCHEMES);
    if (!is_array($schemes)) {
        return ALLOWED_SCHEMES;
    }

    $safe = [];
    /** @var mixed $scheme */
    foreach ($schemes as $scheme) {
        if (is_string($scheme) && $scheme !== '') {
            $safe[] = strtolower($scheme);
        }
    }

    return $safe === [] ? ALLOWED_SCHEMES : $safe;
}

const DCR_RATE_LIMIT_PER_HOUR = 10;

const MAX_CLIENTS_PER_SITE = 50;

/**
 * Connection-slot cap actually enforced. MAX_CLIENTS_PER_SITE guards the anonymous registration
 * endpoint against floods and is generous for real use (a slot is a connection active within the
 * refresh-token lifetime); sites that legitimately run more simultaneous AI connections can raise
 * it with the `wppilot_oauth_max_clients` filter.
 */
function max_clients_per_site(): int
{
    // @mago-expect analysis:mixed-assignment
    $cap = apply_filters('wppilot_oauth_max_clients', MAX_CLIENTS_PER_SITE);
    return is_int($cap) && $cap > 0 ? $cap : MAX_CLIENTS_PER_SITE;
}

const MAX_CLIENTS_PER_IP = 10;

const STALE_UNUSED_CLIENT_TTL = 86_400;

// A client counts as an active connection only while it has been used within the refresh-token
// lifetime (14 days, matching P14D in server-factory.php). Past that its grant has expired, so it is
// pruned and frees its slot instead of occupying it forever.
const ACTIVE_CLIENT_TTL = 14 * 86_400;

const MAX_REDIRECT_URI_LENGTH = 2048;

/**
 * Returns true if a redirect_uri may be registered.
 * Rejects schemes not in ALLOWED_SCHEMES and https URIs whose host resolves
 * to a blocked IP range (RFC 1918, loopback, link-local, reserved).
 * Loopback is allowed when $dev_mode is true (e.g. WP_DEBUG = true).
 */
// @mago-expect lint:no-boolean-flag-parameter
function is_allowed_redirect_uri(string $uri, bool $dev_mode = false): bool
{
    // RFC 6749 §3.1.2: the redirection endpoint URI MUST NOT include a fragment component. A literal
    // '#' is always the fragment delimiter (a '#' inside a path or query would be percent-encoded).
    if (str_contains($uri, '#')) {
        return false;
    }
    $parsed = wp_parse_url($uri);
    // parse_url always returns an array. isset() is used here to check key existence.
    // @mago-expect lint:no-isset
    if (!is_array($parsed) || !isset($parsed['scheme'])) {
        return false;
    }
    $scheme = strtolower((string) $parsed['scheme']);
    // Allow http only for loopback addresses — standard for native apps (RFC 8252).
    if ($scheme === 'http') {
        $host = normalize_uri_host($parsed);
        return $host === 'localhost' || is_loopback_ip($host);
    }
    if (!in_array($scheme, allowed_schemes(), strict: true)) {
        return false;
    }
    // Non-https custom schemes (claude://, cursor://) do not have a resolvable host.
    if ($scheme !== 'https') {
        return true;
    }
    $host = normalize_uri_host($parsed);
    if ($host === '') {
        return false;
    }
    // Raw IP literal in host — check blocked ranges without DNS.
    $raw_ip = filter_var($host, FILTER_VALIDATE_IP);
    if ($raw_ip !== false) {
        return !is_blocked_ip($raw_ip, $dev_mode);
    }
    // Block loopback/mDNS hostnames before DNS.
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return $dev_mode;
    }
    // Resolve hostname and reject if any A/AAAA answer maps to a blocked range.
    // SSRF is not a concern here: redirect_uris are used only in 302 responses
    // to the user's browser — our server never fetches them. The DNS check
    // prevents redirect_uris with private-IP hostnames (e.g. "internal.corp").
    // Fail open when DNS is unavailable (some restricted or managed hosting environments),
    // since raw private-IP literals are already blocked above and PKCE S256 is mandatory.
    $resolved_ips = resolve_host_ips($host);
    if ($resolved_ips === null) {
        return true;
    }
    foreach ($resolved_ips as $ip) {
        if (is_blocked_ip($ip, $dev_mode)) {
            return false;
        }
    }
    return true;
}

/** @param array<array-key, mixed> $parsed */
function normalize_uri_host(array $parsed): string
{
    $host = strtolower((string) ($parsed['host'] ?? ''));
    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $inner = substr($host, offset: 1, length: -1);
        if (filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $inner;
        }
    }
    return $host;
}

/** @return list<string>|null */
function resolve_host_ips(string $host): ?array
{
    $ips = [];

    $ipv4 = gethostbynamel($host);
    if (is_array($ipv4)) {
        foreach ($ipv4 as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }
            $ips[] = $ip;
        }
    }

    if (function_exists('dns_get_record')) {
        /** @var array<int, array{ipv6?: string}>|false $records */
        $records = dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = $record['ipv6'] ?? null;
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                    $ips[] = $ip;
                }
            }
        }
    }

    $ips = array_values(array_unique($ips));
    return $ips === [] ? null : $ips;
}

// @mago-expect lint:no-boolean-flag-parameter
// The logic is necessary to detect all blocked IP ranges (RFC 1918, link-local, loopback).
// Splitting into smaller functions would obscure the IP validation intent.
function is_blocked_ip(string $ip, bool $dev_mode): bool
{
    $ip = normalize_ip_literal($ip);
    if ($ip === '') {
        return true;
    }

    if ($dev_mode && is_loopback_ip($ip)) {
        return false;
    }

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function normalize_ip_literal(string $ip): string
{
    $ip = strtolower(trim($ip));
    if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
        $ip = substr($ip, offset: 1, length: -1);
    }
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
}

function is_loopback_ip(string $ip): bool
{
    $ip = normalize_ip_literal($ip);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return str_starts_with($ip, '127.');
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        return $ip === '::1';
    }
    return false;
}

/**
 * Increments a per-IP DCR counter (SHA-256 of IP, stored in WP transient).
 * Returns false if the hourly cap is already reached.
 */
function check_and_increment_rate_limit(string $client_ip): bool
{
    $key = 'wppilot_oauth_dcr_' . hash('sha256', $client_ip);
    $current = (int) get_transient($key);
    if ($current >= DCR_RATE_LIMIT_PER_HOUR) {
        return false;
    }
    set_transient($key, $current + 1, HOUR_IN_SECONDS);
    return true;
}

const ENDPOINT_RATE_LIMIT_PER_MINUTE = 30;

/**
 * Fixed-window per-IP throttle for the unauthenticated token and revoke endpoints, so a cheap flood
 * cannot tie PHP up on the deliberately expensive token crypto. Returns false once the per-minute
 * cap for this bucket + IP is reached. An empty IP (a proxy stripping REMOTE_ADDR) is not throttled,
 * since the request cannot be attributed to a source.
 */
function within_endpoint_rate_limit(string $bucket, string $client_ip): bool
{
    if ($client_ip === '') {
        return true;
    }
    $key = 'wppilot_oauth_rl_' . $bucket . '_' . hash('sha256', $client_ip);
    $current = (int) get_transient($key);
    if ($current >= ENDPOINT_RATE_LIMIT_PER_MINUTE) {
        return false;
    }
    set_transient($key, $current + 1, MINUTE_IN_SECONDS);
    return true;
}

/**
 * Whether the OAuth client table exists yet.
 *
 * The schema is installed by WPPilot\OAuth\boot(), which only runs once AI
 * Abilities are on AND the transport gate allows OAuth. Until then the table is
 * absent — but the read helpers below are deliberately callable with the gates
 * closed, because diagnostics ask how many connections exist precisely then.
 * Without this check every such call logs a "table doesn't exist" database
 * error, and the troubleshooter notice makes that once per admin page load.
 *
 * Cached per request: the answer cannot change mid-request, and this sits on
 * the admin_notices path.
 */
// @mago-expect lint:no-global
// WordPress core requires global $wpdb for database access.
function client_table_exists(): bool
{
    /** @var array{0?: bool} $cache */
    static $cache = [];
    if ($cache !== []) {
        return $cache[0] ?? false;
    }

    global $wpdb;
    /** @var \wpdb $wpdb */
    $table = $wpdb->prefix . 'wppilot_oauth_clients';
    // @mago-expect analysis:possibly-invalid-argument
    $cache[0] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

    return $cache[0];
}

/**
 * Number of active connections: clients that completed a token exchange within the refresh-token
 * lifetime. `last_used_at` is only set after the admin-approved authorize/consent flow, so an
 * anonymous registration flood — which never reaches a token exchange — cannot inflate this count.
 */
// @mago-expect lint:no-global
// WordPress core requires global $wpdb for database access.
function active_client_count(): int
{
    if (!client_table_exists()) {
        return 0;
    }

    global $wpdb;
    /** @var \wpdb $wpdb */
    $cutoff = gmdate('Y-m-d H:i:s', time() - ACTIVE_CLIENT_TTL);
    // @mago-expect analysis:possibly-invalid-argument
    $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wppilot_oauth_clients
         WHERE last_used_at IS NOT NULL AND last_used_at > %s", $cutoff);
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values are bound by $wpdb->prepare() above; Plugin Check cannot follow the prepared statement through the variable. The only interpolation is the table name, which prepare() has no placeholder for. Not cached: this reads live per-request state.
    return is_string($sql) ? (int) $wpdb->get_var($sql) : 0;
}

// @mago-expect lint:no-global
// WordPress core requires global $wpdb for database access.
function client_count_for_ip(string $client_ip): int
{
    if (!client_table_exists()) {
        return 0;
    }

    global $wpdb;
    /** @var \wpdb $wpdb */
    // @mago-expect analysis:possibly-invalid-argument
    $sql = $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}wppilot_oauth_clients WHERE registered_by_ip_hash = %s",
        hash('sha256', $client_ip),
    );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values are bound by $wpdb->prepare() above; Plugin Check cannot follow the prepared statement through the variable. The only interpolation is the table name, which prepare() has no placeholder for. Not cached: this reads live per-request state.
    return is_string($sql) ? (int) $wpdb->get_var($sql) : 0;
}

/**
 * Release the connection slot a client holds once its last live grant is gone.
 *
 * A slot is counted from `last_used_at`, so revoking a connection's tokens left the slot occupied
 * until the refresh-token lifetime ran out on its own — an administrator who revoked a connection
 * to make room for another was told the site was still full, for up to two weeks, with nothing on
 * screen explaining why. Called after a revocation, this frees the slot in the same request.
 *
 * A client granted by several accounts is only released once none of them has a live token left,
 * because the others are still connected through it. An admin-created client ID is kept and merely
 * returned to its unused state: it exists so somebody can connect with it again, and an unused one
 * occupies no slot.
 */
// @mago-expect lint:no-global
// WordPress core requires global $wpdb for database access.
function release_client_slot_if_unused(string $client_id): void
{
    if (!client_table_exists()) {
        return;
    }

    global $wpdb;
    /** @var \wpdb $wpdb */
    $tokens = $wpdb->prefix . 'wppilot_oauth_access_tokens';
    $refresh = $wpdb->prefix . 'wppilot_oauth_refresh_tokens';
    $now = gmdate('Y-m-d H:i:s');
    // A live refresh token counts as a live grant on its own: access tokens last an hour, so a
    // connection that is merely idle has none, and reading only that table would release a slot
    // another account is still connected through.
    // @mago-expect analysis:possibly-invalid-argument
    $sql = $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$tokens}` at
         LEFT JOIN `{$refresh}` rt ON rt.access_token_hash = at.identifier_hash
         WHERE at.client_id = %s
           AND ((at.revoked = 0 AND at.expires_at > %s) OR (rt.revoked = 0 AND rt.expires_at > %s))",
        $client_id,
        $now,
        $now,
    );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values are bound by $wpdb->prepare() above; Plugin Check cannot follow the prepared statement through the variable. The only interpolation is the table name, which prepare() has no placeholder for. Not cached: this reads live per-request state.
    $live = is_string($sql) ? (int) $wpdb->get_var($sql) : 0;
    if ($live > 0) {
        return;
    }

    $clients = $wpdb->prefix . 'wppilot_oauth_clients';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; values are escaped by $wpdb.
    $wpdb->delete($clients, ['client_id' => $client_id, 'admin_created' => 0], ['%s', '%d']);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; values are escaped by $wpdb.
    $wpdb->update(
        $clients,
        ['last_used_at' => null],
        ['client_id' => $client_id, 'admin_created' => 1],
        ['%s'],
        ['%s', '%d'],
    );
}

/**
 * Delete clients that no longer hold a live grant so they stop occupying connection slots: pending
 * registrations that never completed a token exchange (older than STALE_UNUSED_CLIENT_TTL, except
 * admin-created client IDs, which stay until used or deleted from Connected Apps), and clients not
 * used within the refresh-token lifetime (their tokens have all expired or been revoked).
 */
// @mago-expect lint:no-global
// WordPress core requires global $wpdb for database access.
function prune_dead_clients(): void
{
    global $wpdb;
    /** @var \wpdb $wpdb */
    $pending_cutoff = gmdate('Y-m-d H:i:s', time() - STALE_UNUSED_CLIENT_TTL);
    $used_cutoff = gmdate('Y-m-d H:i:s', time() - ACTIVE_CLIENT_TTL);
    // @mago-expect analysis:possibly-invalid-argument
    $sql = $wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}wppilot_oauth_clients
         WHERE (last_used_at IS NULL AND created_at < %s AND admin_created = 0)
            OR (last_used_at IS NOT NULL AND last_used_at < %s)",
        $pending_cutoff,
        $used_cutoff,
    );
    if (is_string($sql)) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values are bound by $wpdb->prepare() above; Plugin Check cannot follow the prepared statement through the variable. The only interpolation is the table name, which prepare() has no placeholder for. Not cached: this reads live per-request state.
        $wpdb->query($sql);
    }
}
