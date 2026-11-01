<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityTwoFactor;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/** The role policy: which roles must use two-factor login, and how many sign-ins they get first. */
const OPT_POLICY = 'gobuildwp_kit_2fa_policy';

/**
 * User meta. The authenticator secret is encrypted (see encrypt()); backup codes are only ever
 * stored as wp_hash_password() hashes. Meta is network-wide on multisite, like the password.
 */
// @mago-expect lint:no-literal-password -- a meta key name, not a secret.
const META_SECRET = 'gobuildwp_kit_2fa_secret';
const META_ENABLED_AT = 'gobuildwp_kit_2fa_enabled_at';
/** "s<step>": the last time step a code was accepted for, so a code works once. */
const META_LAST_STEP = 'gobuildwp_kit_2fa_last_step';
const META_BACKUP = 'gobuildwp_kit_2fa_backup';
/** A secret shown on the profile page and not yet confirmed with a code, with when it was made. */
const META_PENDING = 'gobuildwp_kit_2fa_pending';
/** Sign-ins used without two-factor login while the role policy required it. */
const META_GRACE_USED = 'gobuildwp_kit_2fa_grace_used';

/** Transients: the interim login state and backup codes waiting to be shown once. */
const TRANSIENT_LOGIN = 'gobuildwp_kit_2fa_t_';
const TRANSIENT_SHOW = 'gobuildwp_kit_2fa_show_';

/**
 * Wrong codes per account, counted atomically so parallel guesses cannot lose increments: in the
 * object cache when there is a persistent one, otherwise in one option row per account whose value
 * is window_start * FAIL_STAMP + count, so a single UPDATE can both count and restart the window.
 */
const OPT_FAILS = 'gobuildwp_kit_2fa_fails_';
const CACHE_GROUP = 'gobuildwp_kit_2fa';
const FAIL_STAMP = 1_000_000;

const BACKUP_COUNT = 10;
const BACKUP_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

/** How long the second step stays open after the password was accepted. */
const LOGIN_TTL = 300;
/** Longer when the second step is setting up the app, which means finding the phone. */
const ENROLL_TTL = 900;
const PENDING_TTL = 1800;
/** Wrong codes one interim login may take before the password has to be entered again. */
const MAX_ATTEMPTS = 5;
/** Wrong codes per account in FAIL_WINDOW before code entry is refused for the rest of it. */
const MAX_USER_FAILURES = 10;
const FAIL_WINDOW = 900;

const MAX_GRACE = 10;

// ---------------------------------------------------------------- policy

/** @return array{required_roles: list<string>, grace_logins: int} */
function default_policy(): array
{
    return ['required_roles' => [], 'grace_logins' => 3];
}

/**
 * @param list<string>|null $known_roles Role slugs that exist; null reads them from WordPress.
 * @return array{required_roles: list<string>, grace_logins: int}
 */
function policy(?array $known_roles = null): array
{
    $stored = get_option(OPT_POLICY, []);
    if (!is_array($stored)) {
        return default_policy();
    }
    // A role deleted since it was chosen is dropped, not an error.
    $known = $known_roles ?? known_roles();
    if (is_array($stored['required_roles'] ?? null)) {
        $stored['required_roles'] = array_values(array_filter($stored['required_roles'], static fn($r): bool => in_array($r, $known, true)));
    }
    $clean = sanitize_policy($stored, default_policy(), $known);
    return $clean instanceof WP_Error ? default_policy() : $clean;
}

/** @return list<string> */
function known_roles(): array
{
    return function_exists('wp_roles') ? array_map('strval', array_keys(wp_roles()->roles)) : [];
}

/**
 * @param array<mixed> $change
 * @param array{required_roles: list<string>, grace_logins: int} $base
 * @param list<string> $known_roles
 * @return array{required_roles: list<string>, grace_logins: int}|WP_Error
 */
function sanitize_policy(array $change, array $base, array $known_roles): array|WP_Error
{
    $out = $base;
    $bad = static fn(string $what): WP_Error => new WP_Error('gobuildwp_kit_2fa_invalid', sprintf('Invalid setting: %s.', $what), ['status' => 400]);
    foreach ($change as $key => $value) {
        switch ($key) {
            case 'required_roles':
                if (!is_array($value) || count($value) > 50) {
                    return $bad('required_roles must be a list of role slugs');
                }
                $roles = [];
                foreach ($value as $role) {
                    if (!is_string($role) || !in_array($role, $known_roles, true)) {
                        return $bad(sprintf('%s is not a role on this site', is_string($role) ? $role : gettype($role)));
                    }
                    $roles[$role] = true;
                }
                $out['required_roles'] = array_keys($roles);
                break;
            case 'grace_logins':
                if (!is_int($value) || $value < 0 || $value > MAX_GRACE) {
                    return $bad('grace_logins must be 0-' . MAX_GRACE);
                }
                $out['grace_logins'] = $value;
                break;
            default:
                return $bad("$key is unknown");
        }
    }
    return $out;
}

/**
 * @param array<mixed> $change
 * @return array{policy: array{required_roles: list<string>, grace_logins: int}, previous: array{required_roles: list<string>, grace_logins: int}}|WP_Error
 */
function configure(array $change): array|WP_Error
{
    $known = known_roles();
    $previous = policy($known);
    $next = sanitize_policy($change, $previous, $known);
    if ($next instanceof WP_Error) {
        return $next;
    }
    if ($next !== $previous) {
        update_option(OPT_POLICY, $next, true);
    }
    return ['policy' => $next, 'previous' => $previous];
}

// ---------------------------------------------------------------- secret encryption

/**
 * The key the authenticator secrets are encrypted with, derived from the site's SECURE_AUTH salt.
 *
 * The encryption keeps a database dump or backup from handing out working second factors. It is
 * tied to wp-config.php: a site whose salts are regenerated can no longer read the stored secrets,
 * and those users sign in with a backup code (hashed, so not affected) or an administrator resets
 * their two-factor login.
 */
function secret_key(): string
{
    return hash_hmac('sha256', 'gobuildwp-kit-2fa-secret-v1', wp_salt('secure_auth'), true);
}

/**
 * Encrypt with libsodium's secretbox (XSalsa20-Poly1305); WordPress ships sodium_compat, so it is
 * there even where the PHP extension is not.
 */
function encrypt(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, secret_key()));
}

/** Null when the value is not ours or fails authentication (wrong key, tampered). */
function decrypt(string $stored): ?string
{
    if (!str_starts_with($stored, 'v1:')) {
        return null;
    }
    $raw = base64_decode(substr($stored, 3), true);
    if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        return null;
    }
    try {
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            secret_key(),
        );
    } catch (\SodiumException) {
        return null;
    }
    return is_string($plain) ? $plain : null;
}

// ---------------------------------------------------------------- per-user state

function meta_string(int $user_id, string $key): string
{
    $value = get_user_meta($user_id, $key, true);
    return is_scalar($value) ? (string) $value : '';
}

function is_enabled(int $user_id): bool
{
    return meta_string($user_id, META_SECRET) !== '';
}

/** The decrypted secret, '' when there is none, null when one is stored but cannot be read. */
function user_secret(int $user_id): ?string
{
    $stored = meta_string($user_id, META_SECRET);
    return $stored === '' ? '' : decrypt($stored);
}

/**
 * Compare-and-set on one meta value: update_metadata() with a previous value is an
 * `UPDATE … WHERE meta_value = <previous>`, so of two requests racing with the same code or backup
 * code only one changes the row and the other gets false. That is what makes "once" hold.
 */
function swap_meta(int $user_id, string $key, string $previous, string $next): bool
{
    if ($previous === '' || $previous === $next) {
        return false;
    }
    return (bool) update_user_meta($user_id, $key, wp_slash($next), $previous);
}

/**
 * Turn two-factor login on with a secret the user just proved with a code from $step. Returns the
 * backup codes, which exist in plain text only in this return value.
 *
 * @return list<string>
 */
function enable(int $user_id, string $secret, int $step, int $now): array
{
    update_user_meta($user_id, META_SECRET, wp_slash(encrypt($secret)));
    update_user_meta($user_id, META_LAST_STEP, wp_slash('s' . $step));
    update_user_meta($user_id, META_ENABLED_AT, wp_slash((string) $now));
    delete_user_meta($user_id, META_PENDING);
    delete_user_meta($user_id, META_GRACE_USED);
    return regenerate_backup_codes($user_id);
}

/** Remove every trace of the user's two-factor login: the admin reset and the user's own "turn off". */
function disable(int $user_id): void
{
    foreach ([META_SECRET, META_ENABLED_AT, META_LAST_STEP, META_BACKUP, META_PENDING, META_GRACE_USED] as $key) {
        delete_user_meta($user_id, $key);
    }
    delete_transient(TRANSIENT_SHOW . $user_id);
    clear_failures($user_id);
}

/**
 * Check a TOTP code against the user's secret and consume its time step.
 */
function verify_user_totp(int $user_id, string $code, int $now): bool
{
    $secret = user_secret($user_id);
    if ($secret === null || $secret === '') {
        return false;
    }
    $last_raw = meta_string($user_id, META_LAST_STEP);
    $last = str_starts_with($last_raw, 's') ? (int) substr($last_raw, 1) : 0;
    $step = verify_totp($secret, $code, $now, $last);
    if ($step === null) {
        return false;
    }
    if ($last_raw === '') {
        // Enabled by an older copy without a step on record: claim it the plain way.
        return (bool) update_user_meta($user_id, META_LAST_STEP, wp_slash('s' . $step));
    }
    return swap_meta($user_id, META_LAST_STEP, $last_raw, 's' . $step);
}

// ---------------------------------------------------------------- backup codes

/** "xxxxx-xxxxx", from an alphabet without 0/o, 1/l/i. About 49 bits each. */
function new_backup_code(): string
{
    $code = '';
    $max = strlen(BACKUP_ALPHABET) - 1;
    for ($i = 0; $i < 10; $i++) {
        $code .= BACKUP_ALPHABET[random_int(0, $max)];
    }
    return substr($code, 0, 5) . '-' . substr($code, 5);
}

function normalize_backup_code(string $code): string
{
    return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
}

/** @return list<string> */
function backup_hashes(int $user_id): array
{
    $raw = meta_string($user_id, META_BACKUP);
    return $raw === '' ? [] : array_values(array_filter(explode(',', $raw)));
}

/** @return list<string> */
function regenerate_backup_codes(int $user_id): array
{
    $codes = [];
    $hashes = [];
    for ($i = 0; $i < BACKUP_COUNT; $i++) {
        $code = new_backup_code();
        $codes[] = $code;
        $hashes[] = wp_hash_password(normalize_backup_code($code));
    }
    // Neither bcrypt nor phpass hashes contain a comma.
    update_user_meta($user_id, META_BACKUP, wp_slash(implode(',', $hashes)));
    return $codes;
}

/**
 * Use one backup code: true if it matched an unused one, which is then gone.
 */
function use_backup_code(int $user_id, string $code): bool
{
    $code = normalize_backup_code($code);
    if (strlen($code) !== 10) {
        return false;
    }
    $previous = meta_string($user_id, META_BACKUP);
    $hashes = backup_hashes($user_id);
    foreach ($hashes as $i => $hash) {
        if (wp_check_password($code, $hash, $user_id)) {
            unset($hashes[$i]);
            return swap_meta($user_id, META_BACKUP, $previous, $hashes === [] ? ',' : implode(',', $hashes));
        }
    }
    return false;
}

/**
 * A code typed at the second step: six digits are an app code, anything else a backup code.
 *
 * @return 'totp'|'backup'|null
 */
function verify_any(int $user_id, string $code, int $now): ?string
{
    $digits = (string) preg_replace('/\s+/', '', $code);
    if (preg_match('/^\d{' . DIGITS . '}$/', $digits) === 1) {
        return verify_user_totp($user_id, $digits, $now) ? 'totp' : null;
    }
    return use_backup_code($user_id, $code) ? 'backup' : null;
}

// ---------------------------------------------------------------- pending setup and one-time display

/**
 * The secret the profile page offers for setup, made on first view and kept for PENDING_TTL so a
 * reload does not invalidate a code the user already scanned.
 */
function pending_secret(int $user_id, int $now): string
{
    $raw = meta_string($user_id, META_PENDING);
    if ($raw !== '' && str_contains($raw, '|')) {
        [$made, $box] = explode('|', $raw, 2);
        $secret = decrypt($box);
        if ($secret !== null && $secret !== '' && $now - (int) $made < PENDING_TTL) {
            return $secret;
        }
    }
    $secret = new_secret();
    update_user_meta($user_id, META_PENDING, wp_slash($now . '|' . encrypt($secret)));
    return $secret;
}

/** Null when there is no pending secret or it has expired. */
function read_pending_secret(int $user_id, int $now): ?string
{
    $raw = meta_string($user_id, META_PENDING);
    if ($raw === '' || !str_contains($raw, '|')) {
        return null;
    }
    [$made, $box] = explode('|', $raw, 2);
    if ($now - (int) $made >= PENDING_TTL) {
        return null;
    }
    $secret = decrypt($box);
    return $secret === null || $secret === '' ? null : $secret;
}

/**
 * Keep freshly made backup codes, encrypted, until the next page view shows them once.
 *
 * @param list<string> $codes
 */
function stash_codes_for_display(int $user_id, array $codes): void
{
    set_transient(TRANSIENT_SHOW . $user_id, encrypt(implode(',', $codes)), 600);
}

/** @return list<string> */
function take_codes_for_display(int $user_id): array
{
    $stored = get_transient(TRANSIENT_SHOW . $user_id);
    if (!is_string($stored) || $stored === '') {
        return [];
    }
    delete_transient(TRANSIENT_SHOW . $user_id);
    $plain = decrypt($stored);
    return $plain === null || $plain === '' ? [] : explode(',', $plain);
}

// ---------------------------------------------------------------- what the policy asks of a user

/**
 * What signing in asks of this user: 'challenge' (enter a code), 'enroll' (set up the app now),
 * 'grace' (allowed in without, counted) or 'none'.
 *
 * @param list<string> $roles
 * @param array{required_roles: list<string>, grace_logins: int} $policy
 */
function login_requirement(int $user_id, array $roles, array $policy): string
{
    if (is_enabled($user_id)) {
        return 'challenge';
    }
    if (array_intersect($roles, $policy['required_roles']) === []) {
        return 'none';
    }
    return grace_left($user_id, $policy) > 0 ? 'grace' : 'enroll';
}

/** @param array{required_roles: list<string>, grace_logins: int} $policy */
function grace_left(int $user_id, array $policy): int
{
    return max(0, $policy['grace_logins'] - (int) meta_string($user_id, META_GRACE_USED));
}

function use_grace_login(int $user_id): void
{
    update_user_meta($user_id, META_GRACE_USED, wp_slash((string) ((int) meta_string($user_id, META_GRACE_USED) + 1)));
}

// ---------------------------------------------------------------- interim login state

/**
 * Open the second step for a user whose password was just accepted. The returned token goes to the
 * browser (cookie and form field); only its hash names the transient, so a database read does not
 * yield a usable token.
 *
 * @param array<string, mixed> $extra redirect_to, remember, interim and, for 'enroll', the encrypted pending secret.
 */
function open_login(int $user_id, string $mode, array $extra = []): string
{
    $nonce = bin2hex(random_bytes(32));
    $ttl = $mode === 'enroll' ? ENROLL_TTL : LOGIN_TTL;
    set_transient(login_key($user_id, $nonce), ['user_id' => $user_id, 'mode' => $mode, 'attempts' => 0, 'expires' => time() + $ttl] + $extra, $ttl);
    return $user_id . ':' . $nonce;
}

function login_key(int $user_id, string $nonce): string
{
    return TRANSIENT_LOGIN . substr(hash('sha256', $user_id . ':' . $nonce), 0, 40);
}

/**
 * The interim state a token names, or null when it is malformed, unknown, expired or for another
 * user. Checked against its own expiry too, since an object cache may keep a transient longer.
 *
 * @return array<string, mixed>|null
 */
function read_login(string $token, ?int $now = null): ?array
{
    if (preg_match('/^(\d+):([0-9a-f]{64})$/', $token, $m) !== 1) {
        return null;
    }
    $state = get_transient(login_key((int) $m[1], $m[2]));
    if (!is_array($state) || ($state['user_id'] ?? null) !== (int) $m[1] || (int) ($state['expires'] ?? 0) <= ($now ?? time())) {
        return null;
    }
    /** @var array<string, mixed> $state */
    return $state;
}

/** Count a wrong code against the token; false once it has used its attempts (and is gone). */
function fail_login(string $token, array $state): bool
{
    [$uid, $nonce] = explode(':', $token, 2);
    $key = login_key((int) $uid, $nonce);
    $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
    count_failure((int) $uid);
    if ($state['attempts'] >= MAX_ATTEMPTS) {
        delete_transient($key);
        return false;
    }
    set_transient($key, $state, max(1, (int) $state['expires'] - time()));
    return true;
}

function close_login(string $token): void
{
    if (preg_match('/^(\d+):([0-9a-f]{64})$/', $token, $m) === 1) {
        delete_transient(login_key((int) $m[1], $m[2]));
    }
}

/** Whether this account has had too many wrong codes recently to take another. */
function user_throttled(int $user_id, ?int $now = null): bool
{
    return failure_count($user_id, $now) >= MAX_USER_FAILURES;
}

/**
 * Count one wrong code against the account and return the count in the current window. The
 * window starts at the first wrong code and lasts FAIL_WINDOW seconds.
 */
function count_failure(int $user_id, ?int $now = null): int
{
    $now ??= time();
    if (wp_using_ext_object_cache()) {
        // add() only creates, so the expiry is set once, by the first wrong code of the window.
        wp_cache_add(OPT_FAILS . $user_id, 0, CACHE_GROUP, FAIL_WINDOW);
        $count = wp_cache_incr(OPT_FAILS . $user_id, 1, CACHE_GROUP);
        return is_int($count) ? $count : failure_count($user_id, $now);
    }

    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var \wpdb $wpdb */
    $name = OPT_FAILS . $user_id;
    // One statement, so concurrent wrong codes each add one: a window that has run out restarts at
    // one, otherwise the count goes up. Rows are matched by name only; autoload is off.
    // @mago-expect analysis:possibly-invalid-argument -- $wpdb->options is a trusted table name.
    $update = $wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = CASE WHEN CAST(option_value AS SIGNED) < %d THEN %d ELSE CAST(option_value AS SIGNED) + 1 END WHERE option_name = %s",
        ($now - FAIL_WINDOW + 1) * FAIL_STAMP,
        $now * FAIL_STAMP + 1,
        $name,
    );
    // @mago-expect analysis:possibly-invalid-argument
    if ((int) $wpdb->query($update) === 0) {
        // No row yet. A plain INSERT fails on the unique option_name if a parallel request made
        // it first; then that row is counted against instead.
        $suppress = $wpdb->suppress_errors(true);
        // @mago-expect analysis:possibly-invalid-argument
        $inserted = $wpdb->query(
            // @mago-expect analysis:possibly-invalid-argument
            $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
                $name,
                (string) ($now * FAIL_STAMP + 1),
            ),
        );
        $wpdb->suppress_errors($suppress);
        if ($inserted === false) {
            // @mago-expect analysis:possibly-invalid-argument
            $wpdb->query($update);
        }
    }
    return failure_count($user_id, $now);
}

/** Wrong codes counted against the account in the current window. */
function failure_count(int $user_id, ?int $now = null): int
{
    if (wp_using_ext_object_cache()) {
        return (int) wp_cache_get(OPT_FAILS . $user_id, CACHE_GROUP);
    }

    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var \wpdb $wpdb */
    // Read from the table, not get_option(): the row is written behind the options cache.
    // @mago-expect analysis:possibly-invalid-argument
    $value = $wpdb->get_var(
        // @mago-expect analysis:possibly-invalid-argument
        $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", OPT_FAILS . $user_id),
    );
    if ($value === null || preg_match('/^\d+$/', $value) !== 1) {
        return 0;
    }
    $value = (int) $value;
    $started = intdiv($value, FAIL_STAMP);
    return $started > ($now ?? time()) - FAIL_WINDOW ? $value % FAIL_STAMP : 0;
}

/** Forget the account's wrong codes: after a successful sign-in, or when two-factor login is reset. */
function clear_failures(int $user_id): void
{
    if (wp_using_ext_object_cache()) {
        wp_cache_delete(OPT_FAILS . $user_id, CACHE_GROUP);
        return;
    }

    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var \wpdb $wpdb */
    $wpdb->delete($wpdb->options, ['option_name' => OPT_FAILS . $user_id], ['%s']);
}

// ---------------------------------------------------------------- status (no secrets)

/**
 * What the status ability reports: the policy and who has two-factor login. Never the secrets,
 * pending secrets or backup codes; only how many backup codes are left.
 *
 * @return array<string, mixed>
 */
function status(): array
{
    $policy = policy();
    $roles_of_interest = array_values(array_unique(array_merge(['administrator'], $policy['required_roles'])));
    $counts = count_users();
    $avail = is_array($counts['avail_roles'] ?? null) ? $counts['avail_roles'] : [];

    $roles = [];
    foreach ($roles_of_interest as $role) {
        $with = new \WP_User_Query([
            'role' => $role,
            'meta_key' => META_SECRET,
            'meta_compare' => 'EXISTS',
            'fields' => 'ID',
            'number' => 1,
            'count_total' => true,
        ]);
        $roles[] = [
            'role' => $role,
            'required' => in_array($role, $policy['required_roles'], true),
            'users' => (int) ($avail[$role] ?? 0),
            'with_2fa' => $with->get_total(),
        ];
    }

    $query = new \WP_User_Query([
        'meta_key' => META_SECRET,
        'meta_compare' => 'EXISTS',
        'number' => 200,
        'orderby' => 'ID',
        'count_total' => true,
    ]);
    $users = [];
    foreach ($query->get_results() as $user) {
        if ($user instanceof \WP_User) {
            $users[] = user_summary($user->ID, $user->user_login, array_values($user->roles), $policy);
        }
    }
    $admins = $roles[0];
    return [
        'policy' => $policy,
        'summary' => [
            'users_with_2fa' => $query->get_total(),
            'administrators' => $admins['users'],
            'administrators_with_2fa' => $admins['with_2fa'],
        ],
        'roles' => $roles,
        'users' => $users,
        'users_truncated' => $query->get_total() > count($users),
    ];
}

/**
 * @param list<string> $roles
 * @param array{required_roles: list<string>, grace_logins: int} $policy
 * @return array<string, mixed>
 */
function user_summary(int $user_id, string $login, array $roles, array $policy): array
{
    $enabled_at = (int) meta_string($user_id, META_ENABLED_AT);
    return [
        'user_id' => $user_id,
        'login' => $login,
        'roles' => $roles,
        'enabled' => is_enabled($user_id),
        'enabled_at' => $enabled_at > 0 ? gmdate('c', $enabled_at) : null,
        'backup_codes_left' => count(backup_hashes($user_id)),
        'secret_readable' => user_secret($user_id) !== null,
        'required_by_policy' => array_intersect($roles, $policy['required_roles']) !== [],
    ];
}
