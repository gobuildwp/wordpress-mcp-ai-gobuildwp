<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table created below; WordPress has no API for it. The table name comes from $wpdb->prefix plus a fixed suffix - never from input - and every value goes through $wpdb->prepare(). Caching is deliberately absent: an access token must be revocable in the request after the revoke.

/**
 * Long-lived access tokens: the third way in, next to OAuth and application passwords.
 *
 * The two existing methods both assume a human is present. OAuth needs a browser
 * sign-in, and its access tokens expire; an application password is per-user and
 * only travels as HTTP Basic. Neither fits the calling shape that has become the
 * common one — a server talking to this site with no interactive session at all:
 * Anthropic's Messages API MCP connector, which takes a URL and a bearer token
 * and nothing else; OpenAI's Responses API `mcp` tool, same shape; a cron job; an
 * automation platform. Those callers cannot run an authorization-code flow, and
 * several of them cannot send Basic at all.
 *
 * So this is deliberately the boring thing: a random 256-bit secret, stored as a
 * SHA-256 digest, presented as `Authorization: Bearer wpp_...`.
 *
 * Why a plain digest and not password hashing. Application passwords are hashed
 * with phpass because WordPress generates them at 24 characters of a reduced
 * alphabet and users see them as passwords. These tokens are 256 bits from the
 * CSPRNG, which puts a brute force of the digest out of reach without a work
 * factor, and unlike a password the digest is what the lookup keys on — a slow
 * hash would mean scanning and verifying every row on every request. GitHub's
 * personal access tokens are stored the same way for the same reason.
 *
 * What is stored is a digest, so a database dump does not yield working tokens,
 * and the plaintext is shown exactly once at creation.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_TOKENS_SCHEMA_VERSION = 2;

const WPPILOT_TOKENS_SCHEMA_OPTION = 'wppilot_tokens_schema_version';

/**
 * The prefix every GoBuildWP access token carries.
 *
 * It is what lets the Bearer path tell a GoBuildWP token from an OAuth JWT without
 * trying, and failing, to validate one as the other — an OAuth validation failure
 * is recorded as an authentication error and would deny the request before the
 * token was ever considered. Secret scanners key on prefixes like this too, which
 * is why it is a fixed literal rather than something per-site.
 */
const WPPILOT_TOKEN_PREFIX = 'wpp_';

function wppilot_tokens_table(): string
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    return $wpdb->prefix . 'wppilot_tokens';
}

function wppilot_tokens_schema_maybe_install(): void
{
    if ((int) get_option(WPPILOT_TOKENS_SCHEMA_OPTION, default_value: 0) >= WPPILOT_TOKENS_SCHEMA_VERSION) {
        return;
    }

    wppilot_tokens_schema_install();
}

function wppilot_tokens_schema_install(): void
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = wppilot_tokens_table();
    $charset_collate = $wpdb->get_charset_collate();

    // token_hash is the lookup key and is unique: two rows with the same digest
    // would mean the same secret authenticating as two identities.
    //
    // scope and ceiling arrived in schema 2. Both default to "no restriction" —
    // NULL scope is every ability, an empty ceiling is the site's own profile —
    // so a token minted before agent identities existed keeps exactly the access
    // it was created with when dbDelta adds the columns.
    dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL DEFAULT '',
            token_hash CHAR(64) NOT NULL,
            last_four CHAR(4) NOT NULL DEFAULT '',
            created DATETIME NOT NULL,
            last_used DATETIME NULL DEFAULT NULL,
            expires DATETIME NULL DEFAULT NULL,
            scope TEXT NULL,
            ceiling VARCHAR(16) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY user_id (user_id)
        ) {$charset_collate};");

    update_option(WPPILOT_TOKENS_SCHEMA_OPTION, WPPILOT_TOKENS_SCHEMA_VERSION, autoload: false);
}

/**
 * Whether a presented credential is shaped like a GoBuildWP access token.
 *
 * Shape only — this decides which validator runs, never whether the credential
 * is good.
 */
function wppilot_token_looks_like(string $secret): bool
{
    return str_starts_with($secret, WPPILOT_TOKEN_PREFIX);
}

/**
 * The digest a secret is stored and looked up under.
 */
function wppilot_token_hash(string $secret): string
{
    return hash('sha256', $secret);
}

/**
 * Mint a token for a user and return the plaintext secret with its stored row.
 *
 * The secret is returned once and never recoverable afterwards: only its digest
 * is written.
 *
 * @param int $ttl_days Days until the token expires; 0 means it does not expire.
 * @param array{abilities?: list<string>, categories?: list<string>}|null $scope Null is every ability.
 * @param string $ceiling A safety profile the token may never exceed; empty for the site's own.
 * @return array{secret: string, id: int, name: string}|WP_Error
 */
function wppilot_token_create(
    int $user_id,
    string $name,
    int $ttl_days = 0,
    ?array $scope = null,
    string $ceiling = '',
): array|WP_Error {
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */

    wppilot_tokens_schema_maybe_install();

    if ($user_id <= 0 || !wppilot_user_can_manage($user_id)) {
        return new WP_Error('wppilot_token_user', __(
            'Only a user who can manage GoBuildWP can hold an access token.',
            domain: 'wppilot',
        ));
    }

    $name = wppilot_token_clean_name($name);

    $policy = wppilot_token_validate_policy($scope, $ceiling);
    if ($policy instanceof WP_Error) {
        return $policy;
    }

    // 32 bytes from the CSPRNG, base64url so the token survives being pasted into
    // JSON, TOML, YAML, a shell command and an HTTP header without escaping.
    $base64 = base64_encode(random_bytes(32));
    $secret = WPPILOT_TOKEN_PREFIX . rtrim(strtr($base64, from: '+/', to: '-_'), characters: '=');

    $now = current_time('mysql', gmt: true);
    $expires = $ttl_days > 0 ? gmdate('Y-m-d H:i:s', time() + ($ttl_days * DAY_IN_SECONDS)) : null;

    $inserted = $wpdb->insert(wppilot_tokens_table(), [
        'user_id' => $user_id,
        'name' => $name,
        'token_hash' => wppilot_token_hash($secret),
        'last_four' => substr($secret, offset: -4),
        'created' => $now,
        'last_used' => null,
        'expires' => $expires,
        'scope' => $policy['scope'] === null ? null : wp_json_encode($policy['scope']),
        'ceiling' => $policy['ceiling'],
    ]);

    if ($inserted === false) {
        return new WP_Error('wppilot_token_store', __('Could not store the new access token.', domain: 'wppilot'));
    }

    return ['secret' => $secret, 'id' => (int) $wpdb->insert_id, 'name' => $name];
}

function wppilot_token_clean_name(string $name): string
{
    $name = trim(wp_strip_all_tags($name));
    if ($name === '') {
        $name = __('Access token', domain: 'wppilot');
    }

    return mb_substr($name, start: 0, length: 191);
}

/**
 * The profiles a token may be capped at.
 *
 * Developer is absent on purpose: it is the most permissive profile, so as a
 * ceiling it could never lower anything, and offering it would read as though a
 * token could be granted more than the site allows. A ceiling only ever takes
 * access away.
 *
 * @return list<string>
 */
function wppilot_token_ceiling_ids(): array
{
    return ['readonly', 'production'];
}

/**
 * Normalise a scope and ceiling before they are stored.
 *
 * A scope is an allowlist: ability names (`rank-math/set-link-settings`), a
 * whole provider (`rank-math/*`), or ability categories. An empty allowlist is
 * refused rather than stored, because it would mint a token that can call
 * nothing and looks like it works.
 *
 * @param array<string, mixed>|null $scope
 * @return array{scope: array{abilities: list<string>, categories: list<string>}|null, ceiling: string}|WP_Error
 */
function wppilot_token_validate_policy(?array $scope, string $ceiling): array|WP_Error
{
    $ceiling = sanitize_key($ceiling);
    if ($ceiling === 'developer') {
        $ceiling = '';
    }
    if ($ceiling !== '' && !in_array($ceiling, wppilot_token_ceiling_ids(), strict: true)) {
        return new WP_Error('wppilot_token_ceiling', __('Unknown safety profile ceiling.', domain: 'wppilot'));
    }

    if ($scope === null) {
        return ['scope' => null, 'ceiling' => $ceiling];
    }

    $normalized = wppilot_token_normalize_scope($scope);
    if ($normalized === null || ($normalized['abilities'] === [] && $normalized['categories'] === [])) {
        return new WP_Error('wppilot_token_scope_empty', __(
            'A restricted token needs at least one ability or category it may use.',
            domain: 'wppilot',
        ));
    }

    return ['scope' => $normalized, 'ceiling' => $ceiling];
}

/**
 * Read a stored or submitted scope into its canonical shape, or null for "every ability".
 *
 * Entries that are not an ability name, a `provider/*` wildcard or a category
 * slug are dropped rather than kept: a typo stored verbatim would silently match
 * nothing, and the screen shows what was kept.
 *
 * @return array{abilities: list<string>, categories: list<string>}|null
 */
function wppilot_token_normalize_scope(mixed $scope): ?array
{
    if (is_string($scope)) {
        if ($scope === '') {
            return null;
        }
        /** @var mixed $scope */
        $scope = json_decode($scope, associative: true);
    }
    if (!is_array($scope)) {
        return null;
    }

    $abilities = [];
    foreach (is_array($scope['abilities'] ?? null) ? $scope['abilities'] : [] as $entry) {
        $entry = is_string($entry) ? strtolower(trim($entry)) : '';
        if (preg_match('#^[a-z0-9-]+/(?:\*|[a-z0-9-/]+)$#', $entry) === 1) {
            $abilities[] = $entry;
        }
    }

    $categories = [];
    foreach (is_array($scope['categories'] ?? null) ? $scope['categories'] : [] as $entry) {
        $entry = is_string($entry) ? sanitize_key($entry) : '';
        if ($entry !== '') {
            $categories[] = $entry;
        }
    }

    return [
        'abilities' => array_values(array_unique($abilities)),
        'categories' => array_values(array_unique($categories)),
    ];
}

/**
 * Change what an existing token may do. Scoped to the owner, like revocation.
 *
 * The expiry is deliberately not editable: extending a leaked token's life is
 * the one edit that should need a new secret, so the way to "extend" is to mint
 * a replacement and revoke this one.
 *
 * @param array<string, mixed>|null $scope
 */
function wppilot_token_update_policy(
    int $token_id,
    int $user_id,
    string $name,
    ?array $scope,
    string $ceiling,
): bool|WP_Error {
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */

    if ($token_id <= 0 || $user_id <= 0) {
        return false;
    }

    $policy = wppilot_token_validate_policy($scope, $ceiling);
    if ($policy instanceof WP_Error) {
        return $policy;
    }

    $updated = $wpdb->update(
        wppilot_tokens_table(),
        [
            'name' => wppilot_token_clean_name($name),
            'scope' => $policy['scope'] === null ? null : wp_json_encode($policy['scope']),
            'ceiling' => $policy['ceiling'],
        ],
        ['id' => $token_id, 'user_id' => $user_id],
        null,
        ['%d', '%d'],
    );

    wppilot_token_policy_cache($token_id, forget: true);

    return $updated !== false;
}

/**
 * What a token may do: its scope and ceiling, looked up by id.
 *
 * Null means the row is gone — revoked, or its owner deleted — which the caller
 * must treat as "may do nothing", never as "unrestricted". Cached for the request
 * because the answer is asked once per ability during discovery.
 *
 * @return array{id: int, name: string, scope: array{abilities: list<string>, categories: list<string>}|null, ceiling: string}|null
 */
function wppilot_token_policy(int $token_id): ?array
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */

    if ($token_id <= 0) {
        return null;
    }

    $cached = wppilot_token_policy_cache($token_id);
    if ($cached !== false) {
        return $cached;
    }

    $table = wppilot_tokens_table();
    // @mago-expect analysis:mixed-assignment
    $row = $wpdb->get_row(
        (string) $wpdb->prepare("SELECT id, name, scope, ceiling FROM {$table} WHERE id = %d LIMIT 1", $token_id),
        ARRAY_A,
    );

    $policy = null;
    if (is_array($row)) {
        $ceiling = (string) ($row['ceiling'] ?? '');
        $policy = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'scope' => wppilot_token_normalize_scope($row['scope'] ?? null),
            // A value the code no longer recognises caps at Read Only rather than
            // being ignored: an unknown ceiling was meant to restrict something.
            'ceiling' => $ceiling === '' || in_array($ceiling, wppilot_token_ceiling_ids(), strict: true)
                ? $ceiling
                : 'readonly',
        ];
    }

    wppilot_token_policy_cache($token_id, $policy);

    return $policy;
}

/**
 * Request-local cache for wppilot_token_policy(). Returns false on a miss.
 *
 * @param array<string, mixed>|null|false $set
 * @return array{id: int, name: string, scope: array{abilities: list<string>, categories: list<string>}|null, ceiling: string}|null|false
 */
function wppilot_token_policy_cache(int $token_id, array|null|false $set = false, bool $forget = false): array|null|false
{
    /** @var array<int, array{id: int, name: string, scope: array{abilities: list<string>, categories: list<string>}|null, ceiling: string}|null> $cache */
    static $cache = [];

    if ($forget) {
        unset($cache[$token_id]);
        return false;
    }
    if ($set !== false) {
        /** @var array{id: int, name: string, scope: array{abilities: list<string>, categories: list<string>}|null, ceiling: string}|null $set */
        $cache[$token_id] = $set;
    }

    return array_key_exists($token_id, $cache) ? $cache[$token_id] : false;
}

/**
 * Resolve a presented secret to the identity behind it, or null.
 *
 * Every reason to refuse collapses to null on purpose: the caller answers 401
 * either way, and distinguishing "no such token" from "expired" from "the user
 * lost the capability" in the response would only tell an attacker which of those
 * they had found.
 *
 * @return array{id: int, user_id: int, name: string}|null
 */
function wppilot_token_authenticate(string $secret): ?array
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */

    wppilot_token_refusal('');

    if (!wppilot_token_looks_like($secret)) {
        return null;
    }

    $table = wppilot_tokens_table();

    // @mago-expect analysis:mixed-assignment
    $row = $wpdb->get_row(
        (string) $wpdb->prepare(
            "SELECT id, user_id, name, token_hash, expires FROM {$table} WHERE token_hash = %s LIMIT 1",
            wppilot_token_hash($secret),
        ),
        ARRAY_A,
    );

    if (!is_array($row)) {
        return null;
    }

    // The digest was the lookup key, so this can only fail on a collision — but a
    // timing-safe comparison is what makes that guarantee explicit rather than
    // implied by the index.
    if (!hash_equals((string) $row['token_hash'], wppilot_token_hash($secret))) {
        return null;
    }

    $expires = (string) ($row['expires'] ?? '');
    if ($expires !== '' && strtotime($expires . ' UTC') < time()) {
        // The one refusal that is named. Only the holder of the real secret can
        // reach this line, so saying "expired" tells them nothing an attacker
        // could use — and a generic 401 on a token that worked yesterday sends
        // people debugging proxies and firewalls instead of minting a new one.
        wppilot_token_refusal(sprintf(
            'This GoBuildWP access token expired on %s UTC. Create a new token on the GoBuildWP Configuration screen and update the client.',
            $expires,
        ));
        return null;
    }

    $user_id = (int) $row['user_id'];
    if ($user_id <= 0) {
        return null;
    }

    // A token cannot outlive its owner's access. The capability is re-checked on
    // every request rather than frozen at creation, so demoting or deleting the
    // user closes the token in the same moment.
    if (!wppilot_user_can_manage($user_id)) {
        return null;
    }

    return ['id' => (int) $row['id'], 'user_id' => $user_id, 'name' => (string) $row['name']];
}

/**
 * Why the last wppilot_token_authenticate() call refused, when that reason is safe to say.
 *
 * Empty for every refusal that must stay generic.
 */
function wppilot_token_refusal(?string $set = null): string
{
    static $reason = '';
    if ($set !== null) {
        $reason = $set;
    }

    return $reason;
}

/**
 * Record that a token was used.
 *
 * Throttled to one write a minute per token: the Connect screen only shows this
 * to the minute, and an agent can make hundreds of calls in that time.
 */
function wppilot_token_touch(int $token_id): void
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */

    $table = wppilot_tokens_table();
    $cutoff = gmdate('Y-m-d H:i:s', time() - MINUTE_IN_SECONDS);

    $wpdb->query((string) $wpdb->prepare(
        "UPDATE {$table} SET last_used = %s WHERE id = %d AND (last_used IS NULL OR last_used < %s)",
        current_time('mysql', gmt: true),
        $token_id,
        $cutoff,
    ));
}

/**
 * Every token belonging to a user, newest first.
 *
 * Never returns a secret — there is none stored to return.
 *
 * @return list<array{id: int, name: string, last_four: string, created: string, last_used: string, expires: string, scope: array{abilities: list<string>, categories: list<string>}|null, ceiling: string}>
 */
function wppilot_tokens_for_user(int $user_id): array
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */

    if ($user_id <= 0) {
        return [];
    }

    wppilot_tokens_schema_maybe_install();

    $table = wppilot_tokens_table();

    // @mago-expect analysis:mixed-assignment
    $rows = $wpdb->get_results(
        (string) $wpdb->prepare("SELECT id, name, last_four, created, last_used, expires, scope, ceiling FROM {$table}
             WHERE user_id = %d ORDER BY id DESC", $user_id),
        ARRAY_A,
    );

    if (!is_array($rows)) {
        return [];
    }

    $tokens = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tokens[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'last_four' => (string) $row['last_four'],
            'created' => (string) $row['created'],
            'last_used' => (string) ($row['last_used'] ?? ''),
            'expires' => (string) ($row['expires'] ?? ''),
            'scope' => wppilot_token_normalize_scope($row['scope'] ?? null),
            'ceiling' => (string) ($row['ceiling'] ?? ''),
        ];
    }

    return $tokens;
}

/**
 * The stored name of a token, for the connection ledger.
 */
function wppilot_token_name(int $token_id): string
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */

    $table = wppilot_tokens_table();
    // @mago-expect analysis:mixed-assignment
    $name = $wpdb->get_var((string) $wpdb->prepare("SELECT name FROM {$table} WHERE id = %d", $token_id));

    return is_string($name) ? $name : '';
}

/**
 * The user a token belongs to, or 0 when there is no such token.
 *
 * For the one caller that revokes a token on the site's behalf rather than the
 * owner's: GoBuildWP Cloud's Disconnect may be pressed by a different
 * administrator from the one who paired, and wppilot_token_revoke() is scoped
 * to the owner.
 */
function wppilot_token_owner(int $token_id): int
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */

    if ($token_id <= 0) {
        return 0;
    }

    $table = wppilot_tokens_table();
    // @mago-expect analysis:mixed-assignment
    $owner = $wpdb->get_var((string) $wpdb->prepare("SELECT user_id FROM {$table} WHERE id = %d", $token_id));

    return is_numeric($owner) ? (int) $owner : 0;
}

/**
 * Revoke one token.
 *
 * Scoped to the owner: an administrator managing their own tokens must not be
 * able to delete another user's row by guessing an id.
 */
function wppilot_token_revoke(int $token_id, int $user_id): bool
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */

    if ($token_id <= 0 || $user_id <= 0) {
        return false;
    }

    $deleted = $wpdb->delete(wppilot_tokens_table(), ['id' => $token_id, 'user_id' => $user_id], ['%d', '%d']);

    return is_int($deleted) && $deleted > 0;
}

/**
 * Delete every token belonging to a user. Called when the user is deleted.
 */
function wppilot_tokens_delete_for_user(int $user_id): void
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */

    if ($user_id <= 0) {
        return;
    }

    $wpdb->delete(wppilot_tokens_table(), ['user_id' => $user_id], ['%d']);
}

/**
 * Drop rows that expired more than 30 days ago.
 *
 * Expiry is already enforced at authentication, so this is housekeeping rather
 * than a security boundary — it keeps the Connect screen from listing tokens
 * that can never work again. The grace period is what lets a client still
 * sending an expired token be told "expired" rather than "invalid" for a month:
 * once the row is gone the secret matches nothing and the reason is lost.
 */
function wppilot_tokens_purge_expired(): void
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */

    $table = wppilot_tokens_table();
    $wpdb->query((string) $wpdb->prepare(
        "DELETE FROM {$table} WHERE expires IS NOT NULL AND expires < %s",
        gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS)),
    ));
}

// Every request, not only the Connect screen: every token-bearing request reads
// the schema-2 columns to learn the token's scope, and a missing column reads as
// "row not found", which denies everything. A site upgraded without anyone
// opening wp-admin must still get them first. One option read when current.
add_action('plugins_loaded', callback: 'wppilot_tokens_schema_maybe_install');

add_action('deleted_user', static function (mixed $user_id): void {
    wppilot_tokens_delete_for_user((int) $user_id);
});

// Piggybacks on the OAuth garbage collector rather than scheduling a second
// daily event to delete a handful of rows.
add_action('wppilot_oauth_gc', callback: 'wppilot_tokens_purge_expired');
