<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Closed or abandoned components: asks the WordPress.org info API about each installed plugin and
 * theme. WordPress shows a closed plugin as "up to date" (it simply gets no more updates), and
 * WordPress.org closes plugins for security issues, guideline breaches or at the author's request.
 * One without a release in over 730 whole days is reported as abandoned.
 *
 * The same verdicts as GoBuildWP Cloud's fleet check (platform/lib/security/wporg-status.ts):
 * closed when the answer says `closed: true` or `error: "closed"` (HTTP 404 today); any other
 * non-200, "not found" included, is unknown, never fine. Everything WordPress.org sends back is
 * treated as untrusted: dates must be real YYYY-MM-DD dates, the reason code a short slug, and
 * text is stripped of markup and control characters and cut to length.
 *
 * WordPress.org's update check leaves closed plugins out, so a component it lists is identified
 * by that, and one it does not list is identified by its folder name only when its Update URI is
 * empty or on WordPress.org and no other updater claims it. Such a slug-only match stands only
 * when WordPress.org's name for the slug matches the installed name; otherwise a custom plugin that
 * happens to share a folder name could be called closed, so it is reported as unknown.
 *
 * Answers are cached per component for 20 hours (a failed request for 15 minutes), each call
 * looks at no more than WPORG_MAX_ITEMS components and stops asking after WPORG_SECONDS, handing
 * back a cursor for the rest.
 */

const WPORG_ABANDONED_DAYS = 730;
const WPORG_MAX_ITEMS = 50;
const WPORG_SECONDS = 20.0;
const WPORG_TIMEOUT = 5;
const WPORG_CACHE_TTL = 20 * HOUR_IN_SECONDS;
const WPORG_FAILURE_TTL = 15 * MINUTE_IN_SECONDS;
const WPORG_CACHE_PREFIX = 'gobuildwp_kit_si_wo_';
const WPORG_SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,199}$/i';

const WPORG_INFO_FIELDS = 'request%5Bfields%5D%5Bsections%5D=0&request%5Bfields%5D%5Bdescription%5D=0&request%5Bfields%5D%5Bversions%5D=0'
    . '&request%5Bfields%5D%5Breviews%5D=0&request%5Bfields%5D%5Bscreenshots%5D=0&request%5Bfields%5D%5Bbanners%5D=0'
    . '&request%5Bfields%5D%5Bicons%5D=0&request%5Bfields%5D%5Bcontributors%5D=0&request%5Bfields%5D%5Bratings%5D=0&request%5Bfields%5D%5Btags%5D=0';

/** The info API address for one component. */
function wporg_info_url(string $type, string $slug): string
{
    $s = rawurlencode($slug);
    return $type === 'plugin'
        ? 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=' . $s . '&' . WPORG_INFO_FIELDS
        : 'https://api.wordpress.org/themes/info/1.2/?action=theme_information&request%5Bslug%5D=' . $s . '&' . WPORG_INFO_FIELDS;
}

/** Remote or header text, without markup or control characters, trimmed and cut to $max characters. */
function wporg_text(mixed $value, int $max): string
{
    if (!is_string($value)) {
        return '';
    }
    $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
    return trim(mb_substr(trim($text), 0, $max));
}

/** The leading YYYY-MM-DD of a WordPress.org date ("2025-10-24 4:13am GMT", "2026-08-19"), or null. */
function wporg_date(mixed $raw): ?string
{
    if (!is_string($raw) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($raw), $m) !== 1) {
        return null;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $m[1] . '-' . $m[2] . '-' . $m[3] : null;
}

/** More than WPORG_ABANDONED_DAYS whole days between the last release (UTC midnight) and $now. */
function wporg_abandoned(?string $last_updated, int $now): bool
{
    if ($last_updated === null) {
        return false;
    }
    $t = strtotime($last_updated . 'T00:00:00Z');
    return $t !== false && intdiv($now - $t, DAY_IN_SECONDS) > WPORG_ABANDONED_DAYS;
}

/**
 * The verdict on one info API answer, without the abandoned test (that depends on the day it is
 * read): state closed, live or unknown, plus WordPress.org's name for the slug.
 *
 * @return array{state: string, name: string, reason: ?string, reason_text: ?string, closed_date: ?string, last_updated: ?string, error: ?string}
 */
function wporg_parse(int $code, string $body): array
{
    $unknown = static fn(string $error): array => ['state' => 'unknown', 'name' => '', 'reason' => null, 'reason_text' => null, 'closed_date' => null, 'last_updated' => null, 'error' => $error];
    /** @var mixed $data */
    $data = json_decode($body, true);
    if (!is_array($data) || ($data !== [] && array_keys($data) === range(0, count($data) - 1))) {
        return $unknown(sprintf('WordPress.org answered %d with an unreadable body.', $code));
    }
    $name = wporg_text($data['name'] ?? null, 200);
    if (($data['closed'] ?? null) === true || ($data['error'] ?? null) === 'closed') {
        $reason = is_string($data['reason'] ?? null) && preg_match('/^[a-z0-9-]{1,100}$/i', $data['reason']) === 1 ? strtolower($data['reason']) : null;
        $reason_text = wporg_text($data['reason_text'] ?? null, 200);
        return [
            'state' => 'closed',
            'name' => $name,
            'reason' => $reason,
            'reason_text' => $reason_text === '' ? null : $reason_text,
            'closed_date' => wporg_date($data['closed_date'] ?? null),
            'last_updated' => wporg_date($data['last_updated'] ?? null),
            'error' => null,
        ];
    }
    if ($code !== 200) {
        return $unknown(is_string($data['error'] ?? null) && stripos($data['error'], 'not found') !== false
            ? 'Not listed on WordPress.org.'
            : sprintf('WordPress.org answered %d.', $code));
    }
    if (is_string($data['error'] ?? null)) {
        return $unknown(wporg_text('WordPress.org: ' . $data['error'], 300));
    }
    $last_updated = wporg_date($data['last_updated'] ?? null);
    if ($last_updated === null) {
        return $unknown('WordPress.org gave no release date.');
    }
    return ['state' => 'live', 'name' => $name, 'reason' => null, 'reason_text' => null, 'closed_date' => null, 'last_updated' => $last_updated, 'error' => null];
}

function wporg_cache_key(string $type, string $slug): string
{
    return WPORG_CACHE_PREFIX . md5($type . ':' . strtolower($slug));
}

/**
 * WordPress.org's answer for one component: from the cache, or fetched when $may_fetch allows.
 * Null when it is not cached and may not be fetched.
 *
 * @return array{state: string, name: string, reason: ?string, reason_text: ?string, closed_date: ?string, last_updated: ?string, error: ?string}|null
 */
function wporg_lookup(string $type, string $slug, bool $may_fetch): ?array
{
    /** @var mixed $cached */
    $cached = get_transient(wporg_cache_key($type, $slug));
    if (is_array($cached) && in_array($cached['state'] ?? null, ['closed', 'live', 'unknown'], strict: true)) {
        return $cached + ['name' => '', 'reason' => null, 'reason_text' => null, 'closed_date' => null, 'last_updated' => null, 'error' => null];
    }
    if (!$may_fetch) {
        return null;
    }
    $response = wp_remote_get(wporg_info_url($type, $slug), [
        'timeout' => WPORG_TIMEOUT,
        'redirection' => 1,
        'limit_response_size' => 512 * 1024,
        'headers' => ['Accept' => 'application/json'],
    ]);
    if (is_wp_error($response)) {
        $info = ['state' => 'unknown', 'name' => '', 'reason' => null, 'reason_text' => null, 'closed_date' => null, 'last_updated' => null,
            'error' => wporg_text('Could not reach WordPress.org: ' . $response->get_error_message(), 300)];
        set_transient(wporg_cache_key($type, $slug), $info, WPORG_FAILURE_TTL);
        return $info;
    }
    $info = wporg_parse((int) wp_remote_retrieve_response_code($response), (string) wp_remote_retrieve_body($response));
    set_transient(wporg_cache_key($type, $slug), $info, WPORG_CACHE_TTL);
    return $info;
}

/** No Update URI, or one on wordpress.org / w.org, leaves WordPress checking WordPress.org. */
function wporg_update_uri_ok(string $update_uri): bool
{
    $update_uri = trim($update_uri);
    if ($update_uri === '') {
        return true;
    }
    $host = wp_parse_url($update_uri, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        // WordPress treats a bare value as the host.
        $host = explode('/', $update_uri)[0];
    }
    return in_array(strtolower($host), ['wordpress.org', 'w.org'], strict: true);
}

/** Two names for the same component: equal, or one holding the other, ignoring case and punctuation. */
function wporg_same_name(string $installed, string $listed): bool
{
    $a = (string) preg_replace('/[^a-z0-9]+/', '', strtolower($installed));
    $b = (string) preg_replace('/[^a-z0-9]+/', '', strtolower($listed));
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    return min(strlen($a), strlen($b)) >= 4 && (str_contains($a, $b) || str_contains($b, $a));
}

/**
 * The keys of an update transient's response and no_update lists that some updater answered for,
 * with the entry's id (plugins) or whether it points at WordPress.org (themes).
 *
 * @return array<string, mixed>
 */
function wporg_update_entries(string $transient): array
{
    /** @var mixed $updates */
    $updates = get_site_transient($transient);
    $entries = [];
    foreach (['response', 'no_update'] as $list) {
        $items = is_object($updates) && isset($updates->{$list}) && is_array($updates->{$list}) ? $updates->{$list} : [];
        foreach ($items as $key => $entry) {
            $entries[(string) $key] = $entry;
        }
    }
    return $entries;
}

/**
 * Installed plugins and themes, plugins first, each sorted by slug, with what decides whether
 * WordPress.org can be asked about them: `source` update_check (WordPress.org's update check lists
 * it), slug (only the folder name matches), or none, with `note` saying why.
 *
 * @return list<array{type: string, slug: string, name: string, version: string, active: bool, source: string, note: string}>
 */
function wporg_components(string $type): array
{
    $components = [];
    if ($type !== 'theme') {
        if (!function_exists('get_plugins') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = function_exists('get_plugins') ? get_plugins() : [];
        $active = array_map('strval', (array) get_option('active_plugins', []));
        $listed = wporg_plugins();
        $claimed = wporg_update_entries('update_plugins');
        $rows = [];
        foreach ($plugins as $file => $headers) {
            $file = str_replace('\\', '/', (string) $file);
            $headers = (array) $headers;
            $slash = strpos($file, '/');
            $slug = $slash === false ? (string) preg_replace('/\.php$/i', '', $file) : substr($file, 0, $slash);
            $row = [
                'type' => 'plugin',
                'slug' => wporg_text($slug, 200),
                'name' => wporg_text($headers['Name'] ?? '', 200),
                'version' => wporg_text($headers['Version'] ?? '', 64),
                'active' => in_array($file, $active, strict: true),
                'source' => 'none',
                'note' => '',
            ];
            $id = $claimed[$file] ?? null;
            $id = is_object($id) ? ($id->id ?? '') : (is_array($id) ? ($id['id'] ?? '') : '');
            if ($slash === false) {
                $row['note'] = 'A single-file plugin; WordPress.org plugins live in their own folder.';
            } elseif (preg_match(WPORG_SLUG_PATTERN, $slug) !== 1) {
                $row['note'] = 'The folder name is not one WordPress.org uses.';
            } elseif (!wporg_update_uri_ok((string) ($headers['UpdateURI'] ?? ''))) {
                $row['note'] = 'Its Update URI header points away from WordPress.org.';
            } elseif (in_array($file, $listed, strict: true)) {
                $row['source'] = 'update_check';
            } elseif (isset($claimed[$file]) && !(is_string($id) && str_starts_with($id, 'w.org/'))) {
                $row['note'] = 'Its updates come from somewhere other than WordPress.org.';
            } else {
                $row['source'] = 'slug';
            }
            $rows[] = $row;
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']) ?: strcmp($a['name'], $b['name']));
        array_push($components, ...$rows);
    }
    if ($type !== 'plugin') {
        $themes = function_exists('wp_get_themes') ? wp_get_themes() : [];
        $in_use = function_exists('get_stylesheet') ? [(string) get_stylesheet(), (string) get_template()] : [];
        $listed = wporg_themes();
        $claimed = wporg_update_entries('update_themes');
        $rows = [];
        foreach ($themes as $slug => $theme) {
            $slug = (string) $slug;
            $get = static fn(string $header): string => is_object($theme) && method_exists($theme, 'get') ? (string) $theme->get($header) : '';
            $row = [
                'type' => 'theme',
                'slug' => wporg_text($slug, 200),
                'name' => wporg_text($get('Name'), 200),
                'version' => wporg_text($get('Version'), 64),
                'active' => in_array($slug, $in_use, strict: true),
                'source' => 'none',
                'note' => '',
            ];
            if (preg_match(WPORG_SLUG_PATTERN, $slug) !== 1) {
                $row['note'] = 'The folder name is not one WordPress.org uses.';
            } elseif (!wporg_update_uri_ok($get('UpdateURI'))) {
                $row['note'] = 'Its Update URI header points away from WordPress.org.';
            } elseif (in_array($slug, $listed, strict: true)) {
                $row['source'] = 'update_check';
            } elseif (isset($claimed[$slug])) {
                $row['note'] = 'Its updates come from somewhere other than WordPress.org.';
            } else {
                $row['source'] = 'slug';
            }
            $rows[] = $row;
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        array_push($components, ...$rows);
    }
    return $components;
}

/**
 * One component's result row.
 *
 * @param array{type: string, slug: string, name: string, version: string, active: bool, source: string, note: string} $c
 * @param array{state: string, name: string, reason: ?string, reason_text: ?string, closed_date: ?string, last_updated: ?string, error: ?string}|null $info
 * @return array<string, mixed>
 */
function wporg_row(array $c, ?array $info, int $now): array
{
    $row = [
        'type' => $c['type'],
        'slug' => $c['slug'],
        'name' => $c['name'],
        'version' => $c['version'],
        'active' => $c['active'],
        'status' => 'unknown',
        'closed_date' => null,
        'reason' => null,
        'reason_text' => null,
        'last_updated' => null,
        'source' => $c['source'],
        'note' => $c['note'],
    ];
    if ($info === null) {
        return $row;
    }
    $row['closed_date'] = $info['closed_date'];
    $row['reason'] = $info['reason'];
    $row['reason_text'] = $info['reason_text'];
    $row['last_updated'] = $info['last_updated'];
    if ($info['state'] === 'unknown') {
        $row['note'] = (string) $info['error'];
        return $row;
    }
    if ($c['source'] === 'slug' && !wporg_same_name($c['name'], $info['name'])) {
        $row['note'] = sprintf(
            'WordPress.org lists this slug as "%s", which does not match the installed name, and its update check does not list it, so it may be a different %s.',
            $info['name'],
            $c['type'],
        );
        return $row;
    }
    if ($info['state'] === 'closed') {
        $row['status'] = 'closed';
        $row['note'] = sprintf(
            'WordPress.org closed this %s%s.%s It gets no more updates, including security fixes, though WordPress still shows it as up to date. Remove or replace it.',
            $c['type'],
            $info['closed_date'] !== null ? ' on ' . $info['closed_date'] : '',
            $info['reason_text'] !== null ? ' Reason given: ' . $info['reason_text'] . '.' : '',
        );
        return $row;
    }
    if (wporg_abandoned($info['last_updated'], $now)) {
        $row['status'] = 'abandoned';
        $row['note'] = sprintf('No release on WordPress.org since %s, over 2 years. Check that it is still maintained, or replace it.', (string) $info['last_updated']);
        return $row;
    }
    $row['status'] = 'ok';
    $row['note'] = '';
    return $row;
}

/**
 * The check behind gobuildwp/wporg-status-check: one page of installed plugins and themes with
 * their WordPress.org status.
 *
 * @param array<string, mixed> $input type (any|plugin|theme), slugs, cursor, limit
 * @return array{items: list<array<string, mixed>>, summary: array<string, int>, total: int, next_cursor: ?int}
 */
function wporg_status_check(array $input, ?int $now = null, ?float $seconds = null): array
{
    $now ??= time();
    $seconds ??= WPORG_SECONDS;
    $type = in_array($input['type'] ?? 'any', ['plugin', 'theme'], strict: true) ? (string) $input['type'] : 'any';
    $limit = max(1, min(WPORG_MAX_ITEMS, (int) ($input['limit'] ?? WPORG_MAX_ITEMS)));
    $cursor = max(0, (int) ($input['cursor'] ?? 0));

    $components = wporg_components($type);
    $slugs = is_array($input['slugs'] ?? null)
        ? array_map(static fn(mixed $s): string => strtolower((string) $s), array_filter($input['slugs'], 'is_string'))
        : [];
    if ($slugs !== []) {
        $components = array_values(array_filter($components, static fn(array $c): bool => in_array(strtolower($c['slug']), $slugs, strict: true)));
    }
    $total = count($components);

    $started = microtime(true);
    $looked = 0;
    $items = [];
    $summary = ['closed' => 0, 'abandoned' => 0, 'ok' => 0, 'unknown' => 0];
    $next = null;
    foreach (array_slice($components, $cursor, $limit) as $offset => $c) {
        $info = null;
        if ($c['source'] !== 'none') {
            // At least one request per call, so a slow WordPress.org never stalls the cursor.
            $info = wporg_lookup($c['type'], $c['slug'], $looked === 0 || microtime(true) - $started < $seconds);
            if ($info === null) {
                $next = $cursor + $offset;
                break;
            }
            $looked += 1;
        }
        $row = wporg_row($c, $info, $now);
        $summary[$row['status']] += 1;
        $items[] = $row;
    }
    if ($next === null && $cursor + $limit < $total) {
        $next = $cursor + $limit;
    }

    return ['items' => $items, 'summary' => $summary, 'total' => $total, 'next_cursor' => $next];
}
