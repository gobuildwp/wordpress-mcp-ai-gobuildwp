<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Redirects;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Ledger;

if (!defined('ABSPATH')) {
    exit();
}

/** The rules: id => rule. Autoloaded, because every front-end request reads it. */
const OPTION = 'wppilot_kit_redirects';

/** Hit counts: id => [hits, last_hit]. Not autoloaded; read only when listing. */
const HITS_OPTION = 'wppilot_kit_redirect_hits';

const STRATEGY = 'kits/redirect-rule';

const MAX_RULES = 500;

/** Redirects one request may pass through before it lands; one more is refused as a chain. */
const MAX_HOPS = 3;

const MAX_DELETE = 50;

const MAX_PATH_LENGTH = 400;

const MAX_TARGET_LENGTH = 2000;

const TYPES = [301, 302, 410];

const BUILTIN_MATCHES = ['exact', 'prefix'];

/** Seconds between writes of the hit counts, whichever redirects the hits are for. */
const HIT_WRITE_INTERVAL = 1;

/** Published descendants whose URLs move with a moved page, at most. */
const CHILD_LIMIT = 50;

/** Return false to stop serving redirects (the rules are kept). */
const ENABLED_FILTER = 'wppilot_kit_redirects_enabled';

/** Return false to stop creating redirects when a published URL changes. */
const AUTO_FILTER = 'wppilot_kit_redirects_auto';

/**
 * Match kinds beyond exact and prefix, as a list of names, for an extension that also answers
 * MATCH_FILTER for them. A rule of a kind nobody answers is kept but never served.
 */
const MATCH_TYPES_FILTER = 'wppilot_kit_redirect_match_types';

/**
 * (string|null $location, array $rule, string $path): the location a rule of an extension's
 * kind sends $path to, or null when it does not match.
 */
const MATCH_FILTER = 'wppilot_kit_redirect_match';

function register_hooks(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    // Before redirect_canonical and wp_old_slug_redirect (both at 10), so a rule wins over a guess.
    add_action('template_redirect', __NAMESPACE__ . '\\serve', 0);
    add_action('pre_post_update', __NAMESPACE__ . '\\remember_old_urls', 10, 2);
    add_action('post_updated', __NAMESPACE__ . '\\on_post_updated', 99, 3);
}

function register(Ledger $ledger): void
{
    $ledger->register_strategy(STRATEGY, static fn(array $payload): array|WP_Error => restore($payload));
}

// ---------------------------------------------------------------------------------------------
// Paths.
// ---------------------------------------------------------------------------------------------

/**
 * The form two paths are compared in: decoded, slashes collapsed, no trailing slash, ASCII lower
 * case. Lower-casing only ASCII keeps byte offsets, so a prefix's remainder can be cut from
 * decoded() by length.
 */
function key(string $path): string
{
    return strtolower(decoded($path));
}

function decoded(string $path): string
{
    $path = (string) preg_replace('#/{2,}#', '/', rawurldecode($path));
    return $path === '/' ? '/' : rtrim($path, '/');
}

/** The path of a URL on this site, '' for one elsewhere or with no path. */
function local_path(string $url): string
{
    if ($url !== '' && $url[0] === '/' && !str_starts_with($url, '//')) {
        $path = wp_parse_url($url, PHP_URL_PATH);
        return is_string($path) ? $path : '/';
    }
    $host = wp_parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || strtolower($host) !== site_host()) {
        return '';
    }
    $path = wp_parse_url($url, PHP_URL_PATH);
    return is_string($path) && $path !== '' ? $path : '/';
}

function site_host(): string
{
    $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    return is_string($host) ? strtolower($host) : '';
}

/**
 * Paths no redirect may take over: the dashboard, the login screen, the REST API and the other
 * WordPress endpoints, under both the home and the site URL's path.
 *
 * @return list<string> keys
 */
function protected_keys(): array
{
    $bases = [];
    foreach ([home_url('/'), function_exists('site_url') ? site_url('/') : home_url('/')] as $url) {
        $path = wp_parse_url($url, PHP_URL_PATH);
        $bases[] = rtrim(is_string($path) ? $path : '/', '/') . '/';
    }
    $rest = function_exists('rest_get_url_prefix') ? trim((string) rest_get_url_prefix(), '/') : 'wp-json';
    $keys = [];
    foreach (array_unique($bases) as $base) {
        foreach (['wp-admin', 'wp-login.php', $rest === '' ? 'wp-json' : $rest, 'xmlrpc.php', 'wp-cron.php'] as $endpoint) {
            $keys[] = key($base . $endpoint);
        }
    }
    return array_values(array_unique($keys));
}

function is_protected(string $path): bool
{
    $key = key($path);
    foreach (protected_keys() as $protected) {
        if ($key === $protected || str_starts_with($key, $protected . '/')) {
            return true;
        }
    }
    return false;
}

/** Why a source cannot be redirected, or null when it can. */
function source_refusal(string $source, string $match): ?string
{
    if (is_protected($source)) {
        return sprintf('%s is a WordPress endpoint (dashboard, login, REST API, XML-RPC or cron) and cannot be redirected.', $source);
    }
    if ($match === 'prefix') {
        $key = key($source);
        if ($key === '/') {
            return 'A prefix redirect of / would redirect the whole site.';
        }
        foreach (protected_keys() as $protected) {
            if (str_starts_with($protected, $key . '/')) {
                return sprintf('A prefix redirect of %s would cover WordPress endpoints such as %s.', $source, $protected);
            }
        }
    }
    return null;
}

/** @return string|WP_Error The source path, as given minus query, host and fragment. */
function clean_source(mixed $raw, string $match): string|WP_Error
{
    if (!is_string($raw) || trim($raw) === '') {
        return new WP_Error('kit_redirect_bad_source', 'source must be a path on this site, for example /old-page/.', ['status' => 400]);
    }
    $raw = trim($raw);
    if (preg_match('/[\x00-\x1f\x7f\s]/', $raw) === 1) {
        return new WP_Error('kit_redirect_bad_source', 'source cannot contain spaces or control characters; percent-encode them.', ['status' => 400]);
    }
    $path = local_path($raw);
    if ($path === '') {
        return new WP_Error('kit_redirect_bad_source', sprintf('source must be a path on this site (%s), for example /old-page/.', site_host()), ['status' => 400]);
    }
    if (wp_parse_url($raw, PHP_URL_QUERY) !== null) {
        return new WP_Error('kit_redirect_bad_source', 'Query strings are not matched: give source as a path only. The visitor\'s query string is passed on to the target.', ['status' => 400]);
    }
    if (strlen($path) > MAX_PATH_LENGTH) {
        return new WP_Error('kit_redirect_bad_source', sprintf('source is longer than %d characters.', MAX_PATH_LENGTH), ['status' => 400]);
    }
    if (in_array('..', explode('/', decoded($path)), true)) {
        return new WP_Error('kit_redirect_bad_source', 'source cannot contain .. segments.', ['status' => 400]);
    }
    $refusal = source_refusal($path, $match);
    if ($refusal !== null) {
        return new WP_Error('kit_redirect_protected', $refusal, ['status' => 403]);
    }
    return $path;
}

/** @return string|WP_Error '' for a 410; otherwise a local path or an http(s) URL. */
function clean_target(mixed $raw, int $type): string|WP_Error
{
    if ($type === 410) {
        if (is_string($raw) && trim($raw) !== '') {
            return new WP_Error('kit_redirect_bad_target', 'A 410 (gone) has no target; leave target out.', ['status' => 400]);
        }
        return '';
    }
    if (!is_string($raw) || trim($raw) === '') {
        return new WP_Error('kit_redirect_bad_target', 'target is required for a 301 or 302: a path on this site or an http(s) URL.', ['status' => 400]);
    }
    $target = trim($raw);
    if (strlen($target) > MAX_TARGET_LENGTH || preg_match('/[\x00-\x1f\x7f\s\\\\]/', $target) === 1) {
        return new WP_Error('kit_redirect_bad_target', sprintf('target must be at most %d characters with no spaces, backslashes or control characters.', MAX_TARGET_LENGTH), ['status' => 400]);
    }
    if ($target[0] === '/' && !str_starts_with($target, '//')) {
        return $target;
    }
    $parts = wp_parse_url($target);
    $scheme = is_array($parts) && is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : '';
    $host = is_array($parts) && is_string($parts['host'] ?? null) ? $parts['host'] : '';
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return new WP_Error('kit_redirect_bad_target', 'target must be a path starting with / or an http:// or https:// URL.', ['status' => 400]);
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return new WP_Error('kit_redirect_bad_target', 'target cannot carry a user name or password.', ['status' => 400]);
    }
    if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
        return new WP_Error('kit_redirect_bad_target', 'target cannot be an IP address; use a host name.', ['status' => 400]);
    }
    return $target;
}

/** @return list<string> */
function match_types(): array
{
    $extra = apply_filters(MATCH_TYPES_FILTER, []);
    $out = BUILTIN_MATCHES;
    foreach (is_array($extra) ? $extra : [] as $name) {
        if (is_string($name) && preg_match('/^[a-z][a-z0-9-]{0,31}$/', $name) === 1 && !in_array($name, $out, true)) {
            $out[] = $name;
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------
// Store.
// ---------------------------------------------------------------------------------------------

/** @return array<string, array{id: string, source: string, match: string, target: string, type: int, origin: string, post_id: int, created: int}> */
function read(): array
{
    $raw = get_option(OPTION, []);
    $out = [];
    foreach (is_array($raw) ? $raw : [] as $id => $rule) {
        if (!is_string($id) || !is_array($rule) || !is_string($rule['source'] ?? null) || !in_array((int) ($rule['type'] ?? 0), TYPES, true)) {
            continue;
        }
        $out[$id] = [
            'id' => $id,
            'source' => $rule['source'],
            'match' => is_string($rule['match'] ?? null) ? $rule['match'] : 'exact',
            'target' => is_string($rule['target'] ?? null) ? $rule['target'] : '',
            'type' => (int) $rule['type'],
            'origin' => ($rule['origin'] ?? '') === 'auto' ? 'auto' : 'manual',
            'post_id' => max(0, (int) ($rule['post_id'] ?? 0)),
            'created' => max(0, (int) ($rule['created'] ?? 0)),
        ];
    }
    return $out;
}

/** @param array<string, array<string, mixed>> $rules */
function write(array $rules): bool
{
    if ($rules === []) {
        delete_option(OPTION);
        return true;
    }
    update_option(OPTION, $rules, true);
    // update_option() is false both on failure and on "unchanged", so judge by reading back.
    return read() == $rules;
}

/** Row in the options table that serialises writes to OPTION. Never autoloaded. */
const LOCK_OPTION = 'wppilot_kit_redirects_lock';
/** Seconds a lock is honoured before a crashed holder's row counts as stale. */
const LOCK_TTL = 30;
/** Seconds a writer waits for the lock before giving up with a retryable error. */
const LOCK_WAIT = 5;

/**
 * Run $fn while holding the redirect store lock, so read-modify-write of OPTION (and the loop and
 * chain checks made on what was read) cannot interleave with another request. The store is
 * re-read from the database inside the lock. INSERT IGNORE on the unique option_name is atomic,
 * unlike add_option(), which upserts.
 *
 * @template T
 * @param callable(): T $fn
 * @return T|WP_Error
 */
function locked(callable $fn): mixed
{
    global $wpdb;
    if (!is_object($wpdb) || !isset($wpdb->options) || !method_exists($wpdb, 'query')) {
        return $fn(); // No database handle (unit tests): nothing to race with.
    }
    $token = '';
    $deadline = microtime(true) + LOCK_WAIT;
    do {
        $candidate = (time() + LOCK_TTL) . '|' . bin2hex(random_bytes(8));
        $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", LOCK_OPTION, $candidate));
        if ($inserted === 1) {
            $token = $candidate;
            break;
        }
        $held = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", LOCK_OPTION));
        if (is_string($held) && (int) strtok($held, '|') < time()) {
            // Stale (its holder died): remove exactly that row, then race for a fresh one.
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", LOCK_OPTION, $held));
            continue;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    if ($token === '') {
        return new WP_Error('kit_redirect_busy', 'Another redirect change is being saved right now. Nothing was written; try again in a few seconds.', ['status' => 409, 'retryable' => true]);
    }
    try {
        // Drop cached copies so read() inside the lock sees the last writer's rules.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            wp_cache_delete(OPTION, 'options');
        }
        return $fn();
    } finally {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", LOCK_OPTION, $token));
    }
}

/** @return array<string, array{hits: int, last_hit: int}> */
function hits(): array
{
    $raw = get_option(HITS_OPTION, []);
    $out = [];
    foreach (is_array($raw) ? $raw : [] as $id => $entry) {
        if (is_string($id) && is_array($entry)) {
            $out[$id] = ['hits' => max(0, (int) ($entry['hits'] ?? 0)), 'last_hit' => max(0, (int) ($entry['last_hit'] ?? 0))];
        }
    }
    return $out;
}

/**
 * Count one redirect served. Written at most once per HIT_WRITE_INTERVAL across all redirects, as
 * the 404 log is, so a burst of traffic is not a write per request; counts are a floor.
 */
function count_hit(string $id, int $now): void
{
    $hits = hits();
    $last = $hits === [] ? 0 : max(array_column($hits, 'last_hit'));
    if ($now >= $last && $now - $last < HIT_WRITE_INTERVAL) {
        return;
    }
    $entry = $hits[$id] ?? ['hits' => 0, 'last_hit' => 0];
    $hits[$id] = ['hits' => $entry['hits'] + 1, 'last_hit' => $now];
    update_option(HITS_OPTION, $hits, false);
}

/** @param list<string> $ids */
function forget_hits(array $ids): void
{
    $hits = hits();
    $before = count($hits);
    foreach ($ids as $id) {
        unset($hits[$id]);
    }
    if (count($hits) !== $before) {
        update_option(HITS_OPTION, $hits, false);
    }
}

/** @param array<string, array<string, mixed>> $rules */
function find_same_source(array $rules, string $source, string $match): ?array
{
    $key = key($source);
    foreach ($rules as $rule) {
        if ($rule['match'] === $match && key($rule['source']) === $key) {
            return $rule;
        }
    }
    return null;
}

function new_id(): string
{
    return 'r' . str_replace('-', '', wp_generate_uuid4());
}

// ---------------------------------------------------------------------------------------------
// Matching and tracing.
// ---------------------------------------------------------------------------------------------

/**
 * The rule a path matches and where it sends it: exact first, then the longest prefix, then any
 * extension kind in store order.
 *
 * @param array<string, array<string, mixed>> $rules
 * @return array{rule: array<string, mixed>, location: string}|null
 */
function find(string $path, array $rules): ?array
{
    $key = key($path);
    $best = null;
    $best_length = -1;
    $others = [];
    foreach ($rules as $rule) {
        if ($rule['match'] === 'exact') {
            if (key($rule['source']) === $key) {
                return ['rule' => $rule, 'location' => $rule['target']];
            }
        } elseif ($rule['match'] === 'prefix') {
            $source_key = key($rule['source']);
            if (($key === $source_key || str_starts_with($key, $source_key . '/')) && strlen($source_key) > $best_length) {
                $best = $rule;
                $best_length = strlen($source_key);
            }
        } else {
            $others[] = $rule;
        }
    }
    if ($best !== null) {
        return ['rule' => $best, 'location' => with_remainder($best['target'], substr(decoded($path), $best_length))];
    }
    foreach ($others as $rule) {
        $location = apply_filters(MATCH_FILTER, null, $rule, $path);
        if (is_string($location)) {
            return ['rule' => $rule, 'location' => $rule['type'] === 410 ? '' : $location];
        }
    }
    return null;
}

/** A prefix target with the rest of the matched path appended, before any query. */
function with_remainder(string $target, string $remainder): string
{
    if ($target === '' || $remainder === '' || $remainder === '/') {
        return $target;
    }
    $query = '';
    $at = strpos($target, '?');
    if ($at !== false) {
        $query = substr($target, $at);
        $target = substr($target, 0, $at);
    }
    $encoded = implode('/', array_map('rawurlencode', explode('/', $remainder)));
    return rtrim($target, '/') . $encoded . $query;
}

/**
 * Follow a path through the rules the way a browser would, without making a request. Stops at
 * a page no rule matches, a 410, a target on another site, a loop, or one hop past MAX_HOPS.
 *
 * @param array<string, array<string, mixed>> $rules
 * @return array{hops: list<array<string, mixed>>, final: string, gone: bool, loop: bool, too_long: bool, leaves_site: bool}
 */
function trace(string $path, array $rules): array
{
    $hops = [];
    $seen = [key($path) => true];
    $current = $path;
    $gone = false;
    $loop = false;
    $leaves = false;
    while (count($hops) <= MAX_HOPS) {
        $found = find($current, $rules);
        if ($found === null) {
            break;
        }
        $rule = $found['rule'];
        $hops[] = ['id' => $rule['id'], 'from' => $current, 'to' => $found['location'], 'type' => $rule['type'], 'match' => $rule['match']];
        if ($rule['type'] === 410) {
            $gone = true;
            break;
        }
        $next = local_path($found['location']);
        if ($next === '') {
            $leaves = true;
            $current = $found['location'];
            break;
        }
        if (isset($seen[key($next)])) {
            $loop = true;
            $current = $next;
            break;
        }
        $seen[key($next)] = true;
        $current = $next;
    }
    return [
        'hops' => $hops,
        'final' => $gone ? '' : $current,
        'gone' => $gone,
        'loop' => $loop,
        'too_long' => !$loop && count($hops) > MAX_HOPS,
        'leaves_site' => $leaves,
    ];
}

/**
 * Refuse a rule set in which the given rule takes part in a loop or a chain of more than
 * MAX_HOPS redirects. Chains are traced from every rule's own source, so a chain entering a
 * prefix rule below its source is not seen.
 *
 * @param array<string, array<string, mixed>> $rules
 */
function graph_refusal(array $rules, string $id): ?WP_Error
{
    foreach ($rules as $rule) {
        $trace = trace($rule['source'], $rules);
        if (!in_array($id, array_column($trace['hops'], 'id'), true)) {
            continue;
        }
        $route = implode(' -> ', array_merge([$trace['hops'][0]['from']], array_map(static fn(array $hop): string => (string) $hop['to'], $trace['hops'])));
        if ($trace['loop']) {
            return new WP_Error('kit_redirect_loop', sprintf('This would make a redirect loop: %s. Nothing was changed.', $route), ['status' => 409, 'hops' => $trace['hops']]);
        }
        if ($trace['too_long']) {
            return new WP_Error(
                'kit_redirect_chain',
                sprintf('This would make a chain of more than %d redirects: %s. Point the first redirect straight at the final page instead. Nothing was changed.', MAX_HOPS, $route),
                ['status' => 409, 'hops' => $trace['hops']],
            );
        }
    }
    return null;
}

// ---------------------------------------------------------------------------------------------
// Serving.
// ---------------------------------------------------------------------------------------------

/**
 * What a request for this URI should get, or null to let WordPress answer.
 *
 * @param array<string, array<string, mixed>> $rules
 * @return array{id: string, status: int, location: string}|null
 */
function resolve_request(string $uri, array $rules): ?array
{
    $uri = (string) preg_replace('/[\x00-\x1f\x7f]+/', '', $uri);
    $path = wp_parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '' || $path[0] !== '/' || is_protected($path)) {
        return null;
    }
    $found = find($path, $rules);
    if ($found === null) {
        return null;
    }
    $rule = $found['rule'];
    if ($rule['type'] === 410) {
        return ['id' => $rule['id'], 'status' => 410, 'location' => ''];
    }
    $location = $found['location'];
    if ($location === '') {
        return null;
    }
    $query = wp_parse_url($uri, PHP_URL_QUERY);
    if (is_string($query) && $query !== '' && !str_contains($location, '?')) {
        $location .= '?' . $query;
    }
    return ['id' => $rule['id'], 'status' => $rule['type'], 'location' => $location];
}

function serve(): void
{
    if (!apply_filters(ENABLED_FILTER, true)) {
        return;
    }
    $rules = read();
    if ($rules === []) {
        return;
    }
    $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    $answer = resolve_request($uri, $rules);
    if ($answer === null) {
        return;
    }
    count_hit($answer['id'], time());
    if ($answer['status'] === 410) {
        global $wp_query;
        if (is_object($wp_query) && method_exists($wp_query, 'set_404')) {
            $wp_query->set_404();
        }
        status_header(410);
        nocache_headers();
        // Neither may turn the gone page into a guessed redirect.
        remove_action('template_redirect', 'redirect_canonical');
        remove_action('template_redirect', 'wp_old_slug_redirect');
        return;
    }
    if (wp_redirect($answer['location'], $answer['status'], Runtime\host()->id())) {
        exit();
    }
}

// ---------------------------------------------------------------------------------------------
// Writes, shared by the abilities, the automatic redirects and undo.
// ---------------------------------------------------------------------------------------------

/**
 * @param array<string, mixed>|null $before
 * @param array<string, mixed>|null $after
 * @return array<string, mixed>
 */
function snapshot(string $id, ?array $before, ?array $after): array
{
    return ['type' => STRATEGY, 'id' => $id, 'rule' => $before, 'expect' => $after];
}

/**
 * @param list<array{id: string, before: array<string, mixed>|null, after: array<string, mixed>|null, input: array<string, mixed>, result: array<string, mixed>}> $changes
 * @return list<string>
 */
function record(string $ability, array $changes): array
{
    if ($changes === []) {
        return [];
    }
    $items = [];
    foreach ($changes as $change) {
        $items[] = [
            'input' => $change['input'],
            'before' => snapshot($change['id'], $change['before'], $change['after']),
            'result' => $change['result'],
            'item' => ['redirect' => $change['id']],
        ];
    }
    $recorded = Runtime\host()->ledger()->record_items($ability, $items);
    return array_values(array_filter($recorded['change_ids'] ?? [], 'is_string'));
}

/**
 * Put a rule back as it was before a change: a created rule is removed, a deleted one re-added,
 * a retargeted one pointed back. Refused when the rule changed again since.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|WP_Error
 */
function restore(array $payload): array|WP_Error
{
    return locked(static fn(): array|WP_Error => restore_unlocked($payload));
}

/** @param array<string, mixed> $payload */
function restore_unlocked(array $payload): array|WP_Error
{
    $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $id = $snapshot['id'] ?? null;
    $before = is_array($snapshot['rule'] ?? null) ? $snapshot['rule'] : null;
    $expect = is_array($snapshot['expect'] ?? null) ? $snapshot['expect'] : null;
    if (($snapshot['type'] ?? '') !== STRATEGY || !is_string($id) || $id === '' || ($before === null && $expect === null)) {
        return new WP_Error('kit_rollback_bad_snapshot', 'The change record does not hold a redirect change.');
    }
    $rules = read();
    $current = $rules[$id] ?? null;
    if (!same_rule($current, $expect)) {
        return new WP_Error('kit_redirect_changed_since', sprintf('Redirect %s changed after this write, so undoing it would overwrite that later change. Nothing was written.', $id), ['status' => 409]);
    }
    if ($before === null) {
        unset($rules[$id]);
    } else {
        $source = clean_source($before['source'] ?? null, (string) ($before['match'] ?? 'exact'));
        if ($source instanceof WP_Error) {
            return $source;
        }
        $clash = find_same_source(array_diff_key($rules, [$id => true]), $source, (string) $before['match']);
        if ($clash !== null) {
            return new WP_Error('kit_redirect_exists', sprintf('Another redirect (%s) now has source %s, so this one cannot be put back.', $clash['id'], $source), ['status' => 409]);
        }
        $rules[$id] = $before + ['id' => $id];
        $refusal = graph_refusal($rules, $id);
        if ($refusal !== null) {
            return $refusal;
        }
    }
    if (count($rules) > MAX_RULES) {
        return new WP_Error('kit_redirect_full', sprintf('The site already has %d redirects, the most it keeps.', MAX_RULES), ['status' => 409]);
    }
    if (!write($rules)) {
        return new WP_Error('kit_redirect_write_failed', 'The redirects could not be saved.', ['status' => 500]);
    }
    if ($before === null) {
        forget_hits([$id]);
    }
    return ['verified' => true, 'redirect' => $id, 'restored' => $before === null ? 'removed' : 'put back'];
}

/**
 * @param array<string, mixed>|null $a
 * @param array<string, mixed>|null $b
 */
function same_rule(?array $a, ?array $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    foreach (['source', 'match', 'target', 'type'] as $field) {
        if (($a[$field] ?? null) !== ($b[$field] ?? null)) {
            return false;
        }
    }
    return true;
}

// ---------------------------------------------------------------------------------------------
// Automatic redirects when a published URL changes.
// ---------------------------------------------------------------------------------------------

/**
 * 'elsewhere' when the host creates slug-change redirects itself (in a redirect plugin), 'off'
 * when filtered off, else 'on'.
 */
function auto_status(): string
{
    if (Runtime\host()->extension('auto-redirects-elsewhere') === true) {
        return 'elsewhere';
    }
    return apply_filters(AUTO_FILTER, true) ? 'on' : 'off';
}

function eligible_type(string $post_type): bool
{
    return $post_type !== 'attachment' && $post_type !== 'revision' && is_post_type_viewable($post_type);
}

/**
 * Old URLs captured before an update, keyed by the saved post: its own and its descendants'.
 *
 * @param array<int, string>|null $set
 * @return array<int, string>
 */
function memory(int $post_id, ?array $set = null): array
{
    /** @var array<int, array<int, string>> $memory */
    static $memory = [];
    if ($set !== null) {
        if ($set === []) {
            unset($memory[$post_id]);
        } else {
            $memory[$post_id] = $set;
        }
    }
    return $memory[$post_id] ?? [];
}

/**
 * pre_post_update: remember the published URLs this save may move, while the database still
 * holds the old parent chain (a moved page's children have the old path only now).
 *
 * @param array<string, mixed> $data
 */
function remember_old_urls(int $post_id, array $data = []): void
{
    try {
        if (auto_status() !== 'on' || get_post_status($post_id) !== 'publish') {
            return;
        }
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post || !eligible_type($post->post_type)) {
            return;
        }
        $urls = [];
        $link = get_permalink($post_id);
        if (is_string($link) && $link !== '') {
            $urls[$post_id] = $link;
        }
        $moving = (isset($data['post_name']) && (string) $data['post_name'] !== $post->post_name)
            || (isset($data['post_parent']) && (int) $data['post_parent'] !== (int) $post->post_parent);
        if ($moving && is_post_type_hierarchical($post->post_type)) {
            $children = get_pages(['child_of' => $post_id, 'post_type' => $post->post_type, 'post_status' => 'publish', 'number' => CHILD_LIMIT]);
            foreach (is_array($children) ? $children : [] as $child) {
                $child_link = $child instanceof \WP_Post ? get_permalink($child->ID) : false;
                if (is_string($child_link) && $child_link !== '') {
                    $urls[$child->ID] = $child_link;
                }
            }
        }
        memory($post_id, $urls);
    } catch (\Throwable) {
        // Best effort: the save goes ahead regardless.
        return;
    }
}

/** post_updated: redirect each old URL of a published post that moved. */
function on_post_updated(int $post_id, mixed $after, mixed $before): void
{
    $remembered = memory($post_id);
    memory($post_id, []);
    try {
        if (auto_status() !== 'on' || !$after instanceof \WP_Post || !$before instanceof \WP_Post) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if ($before->post_status !== 'publish' || $after->post_status !== 'publish' || !eligible_type($after->post_type)) {
            return;
        }
        $moves = [];
        $remembered[$post_id] ??= (string) get_permalink($before);
        foreach ($remembered as $id => $old) {
            $new = get_permalink($id);
            if (is_string($new) && $new !== '') {
                $moves[] = ['post_id' => (int) $id, 'old' => $old, 'new' => $new];
            }
        }
        auto_redirect($moves);
    } catch (\Throwable) {
        return;
    }
}

/**
 * Point each old URL at its new one in the store: a redirect for the new URL (the post moved
 * back) is removed, automatic redirects that pointed at the old URL are pointed straight at the
 * new one, and a redirect a person made for the old URL is left alone.
 *
 * @param list<array{post_id: int, old: string, new: string}> $moves
 * @return list<array<string, mixed>> what happened to each move
 */
function auto_redirect(array $moves): array
{
    // A post save must not lose its redirect over a busy lock, so this waits and then, at worst,
    // falls back to the old unlocked write rather than skipping the move.
    $result = locked(static fn(): array => auto_redirect_unlocked($moves));
    return $result instanceof WP_Error ? auto_redirect_unlocked($moves) : $result;
}

/**
 * @param list<array{post_id: int, old: string, new: string}> $moves
 * @return list<array<string, mixed>>
 */
function auto_redirect_unlocked(array $moves): array
{
    $rules = read();
    $changes = [];
    $outcomes = [];
    foreach ($moves as $move) {
        $old = local_path($move['old']);
        $new = local_path($move['new']);
        if ($old === '' || $new === '' || key($old) === key($new) || wp_parse_url($move['old'], PHP_URL_QUERY) !== null) {
            continue;
        }
        if (source_refusal($old, 'exact') !== null) {
            continue;
        }
        foreach ($rules as $id => $rule) {
            if ($rule['origin'] === 'auto' && $rule['match'] === 'exact' && key($rule['source']) === key($new)) {
                unset($rules[$id]);
                $changes[] = ['id' => $id, 'before' => $rule, 'after' => null, 'input' => ['origin' => 'auto', 'post_id' => $move['post_id']], 'result' => ['removed' => true, 'reason' => 'moved back']];
            }
        }
        foreach ($rules as $id => $rule) {
            if ($rule['origin'] === 'auto' && $rule['type'] !== 410 && local_path($rule['target']) !== '' && key(local_path($rule['target'])) === key($old)) {
                $after = ['target' => $new] + $rule;
                $rules[$id] = $after;
                $changes[] = ['id' => $id, 'before' => $rule, 'after' => $after, 'input' => ['origin' => 'auto', 'post_id' => $move['post_id']], 'result' => ['retargeted' => true]];
            }
        }
        $existing = find_same_source($rules, $old, 'exact');
        if ($existing !== null) {
            if ($existing['origin'] !== 'auto') {
                $outcomes[] = ['post_id' => $move['post_id'], 'from' => $old, 'to' => $new, 'action' => 'conflict', 'redirect' => $existing['id']];
                continue;
            }
            // Already in place (a second watcher, such as Pro's, or a repeat save): no write, no ledger row.
            if ($existing['type'] === 301 && local_path($existing['target']) !== '' && key(local_path($existing['target'])) === key($new)) {
                $outcomes[] = ['post_id' => $move['post_id'], 'from' => $old, 'to' => $new, 'action' => 'exists', 'redirect' => $existing['id']];
                continue;
            }
            $after = ['target' => $new, 'type' => 301, 'post_id' => $move['post_id']] + $existing;
            $rules[$existing['id']] = $after;
            $changes[] = ['id' => $existing['id'], 'before' => $existing, 'after' => $after, 'input' => ['origin' => 'auto', 'post_id' => $move['post_id']], 'result' => ['retargeted' => true]];
            $outcomes[] = ['post_id' => $move['post_id'], 'from' => $old, 'to' => $new, 'action' => 'updated', 'redirect' => $existing['id']];
            continue;
        }
        if (count($rules) >= MAX_RULES) {
            $outcomes[] = ['post_id' => $move['post_id'], 'from' => $old, 'to' => $new, 'action' => 'full'];
            continue;
        }
        $id = new_id();
        $rule = ['id' => $id, 'source' => $old, 'match' => 'exact', 'target' => $new, 'type' => 301, 'origin' => 'auto', 'post_id' => $move['post_id'], 'created' => time()];
        $rules[$id] = $rule;
        if (graph_refusal($rules, $id) !== null) {
            unset($rules[$id]);
            $outcomes[] = ['post_id' => $move['post_id'], 'from' => $old, 'to' => $new, 'action' => 'refused'];
            continue;
        }
        $changes[] = ['id' => $id, 'before' => null, 'after' => $rule, 'input' => ['origin' => 'auto', 'post_id' => $move['post_id'], 'source' => $old, 'target' => $new], 'result' => ['created' => true]];
        $outcomes[] = ['post_id' => $move['post_id'], 'from' => $old, 'to' => $new, 'action' => 'created', 'redirect' => $id];
    }
    if ($changes !== [] && write($rules)) {
        record('wppilot/redirect-create', $changes);
        forget_hits(array_values(array_map(static fn(array $c): string => $c['id'], array_filter($changes, static fn(array $c): bool => $c['after'] === null))));
    }
    return $outcomes;
}

// ---------------------------------------------------------------------------------------------
// Abilities.
// ---------------------------------------------------------------------------------------------

/**
 * @param array<string, mixed> $rule
 * @param array<string, array{hits: int, last_hit: int}> $hits
 * @return array<string, mixed>
 */
function public_rule(array $rule, array $hits): array
{
    return $rule + ['hits' => $hits[$rule['id']]['hits'] ?? 0, 'last_hit' => $hits[$rule['id']]['last_hit'] ?? null];
}

/**
 * wppilot/redirect-list.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function list_rules(array $input): array
{
    $limit = max(1, min(MAX_RULES, (int) ($input['limit'] ?? 100)));
    $offset = max(0, (int) ($input['offset'] ?? 0));
    $origin = in_array($input['origin'] ?? 'any', ['manual', 'auto'], true) ? $input['origin'] : 'any';
    $search = is_string($input['search'] ?? null) ? strtolower(trim($input['search'])) : '';
    $hits = hits();
    $matched = [];
    foreach (read() as $rule) {
        if ($origin !== 'any' && $rule['origin'] !== $origin) {
            continue;
        }
        if ($search !== '' && !str_contains(strtolower($rule['source'] . ' ' . $rule['target']), $search)) {
            continue;
        }
        $matched[] = public_rule($rule, $hits);
    }
    usort($matched, static fn(array $a, array $b): int => [$b['created'], $a['id']] <=> [$a['created'], $b['id']]);
    return [
        'redirects' => array_slice($matched, $offset, $limit),
        'total' => count($matched),
        'max_redirects' => MAX_RULES,
        'auto_redirects' => auto_status(),
        'serving' => (bool) apply_filters(ENABLED_FILTER, true),
    ];
}

/**
 * wppilot/redirect-create.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function create_rule(array $input): array|WP_Error
{
    return locked(static fn(): array|WP_Error => create_rule_unlocked($input));
}

/** @param array<string, mixed> $input */
function create_rule_unlocked(array $input): array|WP_Error
{
    $type = (int) ($input['type'] ?? 301);
    if (!in_array($type, TYPES, true)) {
        return new WP_Error('kit_redirect_bad_type', 'type must be 301 (moved permanently), 302 (moved temporarily) or 410 (gone).', ['status' => 400]);
    }
    $match = is_string($input['match'] ?? null) ? $input['match'] : 'exact';
    if (!in_array($match, match_types(), true)) {
        return new WP_Error('kit_redirect_bad_match', sprintf('match must be one of: %s.', implode(', ', match_types())), ['status' => 400]);
    }
    $source = clean_source($input['source'] ?? null, $match);
    if ($source instanceof WP_Error) {
        return $source;
    }
    $target = clean_target($input['target'] ?? null, $type);
    if ($target instanceof WP_Error) {
        return $target;
    }
    $rules = read();
    $existing = find_same_source($rules, $source, $match);
    if ($existing !== null) {
        return new WP_Error('kit_redirect_exists', sprintf('%s already redirects (%s, to %s). Delete it first to replace it.', $source, $existing['id'], $existing['target'] === '' ? 'gone' : $existing['target']), ['status' => 409, 'redirect' => $existing['id']]);
    }
    if (count($rules) >= MAX_RULES) {
        return new WP_Error('kit_redirect_full', sprintf('The site already has %d redirects, the most it keeps. Delete unused ones (wppilot/redirect-list shows hit counts).', MAX_RULES), ['status' => 409]);
    }
    $id = new_id();
    $rule = ['id' => $id, 'source' => $source, 'match' => $match, 'target' => $target, 'type' => $type, 'origin' => 'manual', 'post_id' => 0, 'created' => time()];
    $rules[$id] = $rule;
    $refusal = graph_refusal($rules, $id);
    if ($refusal !== null) {
        return $refusal;
    }
    $trace = trace($source, $rules);
    if (($input['dry_run'] ?? false) === true) {
        return ['dry_run' => true, 'would_create' => $rule, 'trace' => $trace];
    }
    if (!write($rules)) {
        return new WP_Error('kit_redirect_write_failed', 'The redirect could not be saved.', ['status' => 500]);
    }
    $change_ids = record('wppilot/redirect-create', [[
        'id' => $id,
        'before' => null,
        'after' => $rule,
        'input' => ['source' => $source, 'target' => $target, 'type' => $type, 'match' => $match],
        'result' => ['created' => true],
    ]]);
    return ['dry_run' => false, 'redirect' => $rule, 'trace' => $trace, 'change_ids' => $change_ids];
}

/**
 * wppilot/redirect-delete.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function delete_rules(array $input): array|WP_Error
{
    return locked(static fn(): array|WP_Error => delete_rules_unlocked($input));
}

/** @param array<string, mixed> $input */
function delete_rules_unlocked(array $input): array|WP_Error
{
    $dry_run = ($input['dry_run'] ?? false) === true;
    if (!$dry_run) {
        $confirmed = Runtime\confirm_guard('wppilot/redirect-delete', $input);
        if ($confirmed instanceof WP_Error) {
            return $confirmed;
        }
    }
    $ids = is_array($input['ids'] ?? null) ? array_values(array_unique(array_filter($input['ids'], 'is_string'))) : [];
    if ($ids === [] || count($ids) > MAX_DELETE) {
        return new WP_Error('kit_redirect_bad_ids', sprintf('ids must list 1 to %d redirect ids from wppilot/redirect-list.', MAX_DELETE), ['status' => 400]);
    }
    $rules = read();
    $missing = array_values(array_diff($ids, array_keys($rules)));
    if ($missing !== []) {
        return new WP_Error('kit_redirect_not_found', sprintf('No redirect has id %s. Nothing was deleted.', implode(', ', $missing)), ['status' => 404]);
    }
    $deleted = array_values(array_map(static fn(string $id): array => $rules[$id], $ids));
    if ($dry_run) {
        return ['dry_run' => true, 'would_delete' => $deleted];
    }
    foreach ($ids as $id) {
        unset($rules[$id]);
    }
    if (!write($rules)) {
        return new WP_Error('kit_redirect_write_failed', 'The redirects could not be saved.', ['status' => 500]);
    }
    forget_hits($ids);
    $change_ids = record('wppilot/redirect-delete', array_map(static fn(array $rule): array => [
        'id' => $rule['id'],
        'before' => $rule,
        'after' => null,
        'input' => ['id' => $rule['id']],
        'result' => ['deleted' => true],
    ], $deleted));
    return ['dry_run' => false, 'deleted' => $deleted, 'change_ids' => $change_ids];
}

/**
 * wppilot/redirect-test.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function test_path(array $input): array|WP_Error
{
    $raw = is_string($input['path'] ?? null) ? trim($input['path']) : '';
    $path = $raw === '' ? '' : local_path($raw);
    if ($path === '') {
        return new WP_Error('kit_redirect_bad_path', sprintf('path must be a path or URL on this site (%s).', site_host()), ['status' => 400]);
    }
    $trace = trace($path, read());
    return $trace + [
        'path' => $path,
        'protected' => is_protected($path),
        'note' => 'Simulated from the stored redirects only, without a request: other plugins\' redirects and WordPress\'s own canonical and old-slug redirects are not included.',
    ];
}

/**
 * wppilot/redirect-suggest: 404s from the not-found log with a published page they most likely
 * meant, matched by slug. Writes nothing.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function suggest(array $input): array
{
    $limit = max(1, min(50, (int) ($input['limit'] ?? 20)));
    $rules = read();
    $suggestions = [];
    $unmatched = [];
    $log = function_exists('WPPilot\\Kits\\NotFoundLog\\read') ? \WPPilot\Kits\NotFoundLog\read() : [];
    uasort($log, static fn(array $a, array $b): int => [$b['hits'], $b['last_seen']] <=> [$a['hits'], $a['last_seen']]);
    foreach ($log as $path => $entry) {
        if (count($suggestions) + count($unmatched) >= $limit) {
            break;
        }
        $path = (string) $path;
        if (find($path, $rules) !== null || source_refusal($path, 'exact') !== null) {
            continue;
        }
        $guess = guess_target($path);
        if ($guess === null) {
            $unmatched[] = ['path' => $path, 'hits' => $entry['hits'], 'last_seen' => $entry['last_seen']];
            continue;
        }
        $suggestions[] = ['source' => $path, 'hits' => $entry['hits'], 'last_seen' => $entry['last_seen']] + $guess;
    }
    return [
        'suggestions' => $suggestions,
        'unmatched' => $unmatched,
        'logged_paths' => count($log),
        'note' => 'Nothing was created. Check each target, then call wppilot/redirect-create with source and target.',
    ];
}

/** @return array{target: string, post_id: int, title: string, reason: string}|null */
function guess_target(string $path): ?array
{
    $segments = array_values(array_filter(explode('/', decoded($path)), static fn(string $s): bool => $s !== ''));
    $slug = $segments === [] ? '' : sanitize_title(end($segments));
    if ($slug === '') {
        return null;
    }
    $base = ['post_type' => 'any', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => false];
    $tries = [
        'old_slug' => $base + ['meta_key' => '_wp_old_slug', 'meta_value' => $slug],
        'same_slug' => $base + ['name' => $slug],
    ];
    foreach ($tries as $reason => $query) {
        $ids = get_posts($query);
        $id = is_array($ids) && isset($ids[0]) ? (int) (is_object($ids[0]) ? $ids[0]->ID : $ids[0]) : 0;
        if ($id <= 0) {
            continue;
        }
        $link = get_permalink($id);
        $target = is_string($link) ? local_path($link) : '';
        if ($target === '' || key($target) === key($path)) {
            continue;
        }
        return ['target' => $target, 'post_id' => $id, 'title' => (string) get_the_title($id), 'reason' => $reason];
    }
    return null;
}
