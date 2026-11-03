<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

const OPT_SETTINGS = 'gobuildwp_kit_sp_settings';
const OPT_BLOCKS = 'gobuildwp_kit_sp_blocks';
const OPT_EVENTS = 'gobuildwp_kit_sp_events';
const OPT_COUNTS = 'gobuildwp_kit_sp_counts';
const OPT_FAILURES = 'gobuildwp_kit_sp_failures';
const OPT_HITS = 'gobuildwp_kit_sp_hits';

const MAX_EVENTS = 500;
const MAX_BLOCKS = 2000;
const MAX_ALLOWLIST = 100;
const MAX_TRUSTED_PROXIES = 20;
/** The widest ranges a block may cover: a /16 of IPv4, a /48 of IPv6 (one site or office). */
const MIN_BLOCK_PREFIX_V4 = 16;
const MIN_BLOCK_PREFIX_V6 = 48;
const EVENT_DAYS = 30;

const EVENT_TYPES = ['firewall', 'login', 'block', 'enumeration', 'flood', 'settings'];

/**
 * Everything off: the kit changes nothing until someone turns a part on.
 *
 * @return array<string, mixed>
 */
function default_settings(): array
{
    return [
        'firewall' => 'off',
        'login' => ['enabled' => false, 'max_failures' => 5, 'window_minutes' => 15, 'lockout_minutes' => 30, 'generic_errors' => false],
        'xmlrpc' => 'on',
        'block_user_enumeration' => false,
        'flood' => ['enabled' => false, 'max_404_per_minute' => 60],
        'ip_source' => 'remote_addr',
        'allowlist' => [],
        'trusted_proxies' => [],
    ];
}

/** @return array<string, mixed> */
function settings(): array
{
    $stored = get_option(OPT_SETTINGS, []);
    $out = default_settings();
    if (!is_array($stored)) {
        return $out;
    }
    $clean = sanitize_settings($stored, $out);
    return $clean instanceof WP_Error ? $out : $clean;
}

/**
 * Merge a change into $base, refusing anything out of range. `login` and `flood` merge field by
 * field; `allowlist` replaces the list.
 *
 * @param array<mixed> $change
 * @param array<string, mixed> $base
 * @return array<string, mixed>|WP_Error
 */
function sanitize_settings(array $change, array $base): array|WP_Error
{
    $out = $base;
    $bad = static fn(string $what): WP_Error => new WP_Error('gobuildwp_protection_invalid', sprintf('Invalid setting: %s.', $what));
    foreach ($change as $key => $value) {
        switch ($key) {
            case 'firewall':
                if (!in_array($value, ['off', 'log', 'block'], true)) {
                    return $bad('firewall must be off, log or block');
                }
                $out['firewall'] = $value;
                break;
            case 'xmlrpc':
                if (!in_array($value, ['on', 'off'], true)) {
                    return $bad('xmlrpc must be on or off');
                }
                $out['xmlrpc'] = $value;
                break;
            case 'ip_source':
                if (!in_array($value, ['remote_addr', 'cloudflare', 'x_forwarded_for'], true)) {
                    return $bad('ip_source must be remote_addr, cloudflare or x_forwarded_for');
                }
                $out['ip_source'] = $value;
                break;
            case 'block_user_enumeration':
                if (!is_bool($value)) {
                    return $bad('block_user_enumeration must be true or false');
                }
                $out['block_user_enumeration'] = $value;
                break;
            case 'login':
                if (!is_array($value)) {
                    return $bad('login must be an object');
                }
                $ranges = ['max_failures' => [3, 50], 'window_minutes' => [1, 1440], 'lockout_minutes' => [1, 10080]];
                foreach ($value as $k => $v) {
                    if ($k === 'enabled' || $k === 'generic_errors') {
                        if (!is_bool($v)) {
                            return $bad("login.$k must be true or false");
                        }
                        $out['login'][$k] = $v;
                    } elseif (isset($ranges[$k])) {
                        if (!is_int($v) || $v < $ranges[$k][0] || $v > $ranges[$k][1]) {
                            return $bad("login.$k must be {$ranges[$k][0]}–{$ranges[$k][1]}");
                        }
                        $out['login'][$k] = $v;
                    } else {
                        return $bad("login.$k is unknown");
                    }
                }
                break;
            case 'flood':
                if (!is_array($value)) {
                    return $bad('flood must be an object');
                }
                foreach ($value as $k => $v) {
                    if ($k === 'enabled') {
                        if (!is_bool($v)) {
                            return $bad('flood.enabled must be true or false');
                        }
                        $out['flood']['enabled'] = $v;
                    } elseif ($k === 'max_404_per_minute') {
                        if (!is_int($v) || $v < 10 || $v > 1000) {
                            return $bad('flood.max_404_per_minute must be 10–1000');
                        }
                        $out['flood']['max_404_per_minute'] = $v;
                    } else {
                        return $bad("flood.$k is unknown");
                    }
                }
                break;
            case 'allowlist':
                if (!is_array($value) || count($value) > MAX_ALLOWLIST) {
                    return $bad('allowlist must be a list of at most ' . MAX_ALLOWLIST . ' addresses');
                }
                $list = [];
                foreach ($value as $entry) {
                    $cidr = is_string($entry) ? normalize_cidr($entry) : '';
                    if ($cidr === '') {
                        return $bad('allowlist entries must be IP addresses or CIDR ranges');
                    }
                    $list[$cidr] = true;
                }
                $out['allowlist'] = array_keys($list);
                break;
            case 'trusted_proxies':
                // Owners behind a load balancer or reverse proxy rarely can edit wp-config.php, and
                // without this every visitor shares the proxy's address: one block locks out them all.
                if (!is_array($value) || count($value) > MAX_TRUSTED_PROXIES) {
                    return $bad('trusted_proxies must be a list of at most ' . MAX_TRUSTED_PROXIES . ' addresses');
                }
                $list = [];
                foreach ($value as $entry) {
                    $cidr = is_string($entry) ? normalize_cidr($entry) : '';
                    if ($cidr === '') {
                        return $bad('trusted_proxies entries must be IP addresses or CIDR ranges');
                    }
                    // Trusting a huge range lets anyone inside it pick the address the guard sees.
                    $bits = str_contains($cidr, '/') ? (int) substr($cidr, (int) strrpos($cidr, '/') + 1) : 128;
                    if ($bits < (str_contains($cidr, ':') ? 32 : 8)) {
                        return $bad('trusted_proxies ranges must be /8 (IPv4) or /32 (IPv6) or narrower');
                    }
                    $list[$cidr] = true;
                }
                $out['trusted_proxies'] = array_keys($list);
                break;
            default:
                return $bad("$key is unknown");
        }
    }
    return $out;
}

/**
 * @param array<mixed> $change
 * @return array{settings: array<string, mixed>, previous: array<string, mixed>}|WP_Error
 */
function configure(array $change): array|WP_Error
{
    $previous = settings();
    $next = sanitize_settings($change, $previous);
    if ($next instanceof WP_Error) {
        return $next;
    }
    if ($next !== $previous) {
        update_option(OPT_SETTINGS, $next, true);
        $changed = [];
        foreach ($next as $k => $v) {
            if (($previous[$k] ?? null) !== $v) {
                $changed[] = $k;
            }
        }
        record_event('settings', 'changed', '', null, '', 'Changed: ' . implode(', ', $changed));
    }
    return ['settings' => $next, 'previous' => $previous];
}

// ---------------------------------------------------------------- blocks

/** @return array<string, array{ip: string, reason: string, source: string, created_at: int, expires_at: int|null}> */
function blocks(?int $now = null): array
{
    $now ??= time();
    $stored = get_option(OPT_BLOCKS, []);
    if (!is_array($stored)) {
        return [];
    }
    $out = [];
    foreach ($stored as $key => $b) {
        if (!is_array($b) || !is_string($b['ip'] ?? null)) {
            continue;
        }
        $expires = isset($b['expires_at']) ? (int) $b['expires_at'] : null;
        if ($expires !== null && $expires <= $now) {
            continue;
        }
        $out[(string) $key] = [
            'ip' => $b['ip'],
            'reason' => (string) ($b['reason'] ?? ''),
            'source' => (string) ($b['source'] ?? 'manual'),
            'created_at' => (int) ($b['created_at'] ?? $now),
            'expires_at' => $expires,
        ];
    }
    return $out;
}

/**
 * The block that covers $ip, or null. Login lockouts only cover the login form and XML-RPC, so
 * $login_only says whether they count here.
 *
 * @param array<string, array{ip: string, reason: string, source: string, created_at: int, expires_at: int|null}> $blocks
 * @return array{ip: string, reason: string, source: string, created_at: int, expires_at: int|null}|null
 */
function block_for(string $ip, array $blocks, bool $include_lockouts): ?array
{
    foreach ($blocks as $b) {
        if (!$include_lockouts && $b['source'] === 'login_lockout') {
            continue;
        }
        if (ip_matches($ip, $b['ip'])) {
            return $b;
        }
    }
    return null;
}

/**
 * @return array{ip: string, reason: string, source: string, created_at: int, expires_at: int|null}|WP_Error
 */
function add_block(string $ip, int $minutes, string $reason, string $source, ?int $now = null): array|WP_Error
{
    $now ??= time();
    $cidr = normalize_cidr($ip);
    if ($cidr === '') {
        return new WP_Error('gobuildwp_protection_invalid', 'Invalid setting: ip must be an IP address or CIDR range.');
    }
    if ($minutes < 0 || $minutes > 525600) {
        return new WP_Error('gobuildwp_protection_invalid', 'Invalid setting: minutes must be 0–525600.');
    }
    // A /8 or a /32 of IPv6 shuts out whole providers, the owner's own office among them.
    if (str_contains($cidr, '/')) {
        $bits = (int) substr($cidr, (int) strpos($cidr, '/') + 1);
        $min = str_contains($cidr, ':') ? MIN_BLOCK_PREFIX_V6 : MIN_BLOCK_PREFIX_V4;
        if ($bits < $min) {
            return new WP_Error('gobuildwp_protection_invalid', sprintf('Invalid setting: a blocked range must be /%d or narrower.', $min));
        }
    }
    if (ip_in_list($cidr, settings()['allowlist'])) {
        return new WP_Error('gobuildwp_protection_allowlisted', 'That address is on the allowlist; remove it there first.');
    }
    // A block asked for by a person or an agent must not shut out the person asking: the address of
    // this request, or the addresses the signed-in user logged in from.
    if ($source === 'manual') {
        foreach (array_merge([current_ip()], login_ips(get_current_user_id())) as $own) {
            if ($own !== '' && ip_matches($own, $cidr)) {
                return new WP_Error('gobuildwp_protection_self_block', 'That block would lock out your own address. Block a narrower range or a different address.');
            }
        }
    }
    $blocks = blocks($now);
    $block = [
        'ip' => $cidr,
        'reason' => substr(sanitize_text($reason), 0, 200),
        'source' => $source,
        'created_at' => $now,
        'expires_at' => $minutes === 0 ? null : $now + $minutes * 60,
    ];
    $blocks[$cidr] = $block;
    if (count($blocks) > MAX_BLOCKS) {
        // Drop the oldest expiring blocks first; permanent ones are kept as long as possible.
        uasort($blocks, static fn($a, $b) => [($a['expires_at'] === null) ? 1 : 0, $a['created_at']] <=> [($b['expires_at'] === null) ? 1 : 0, $b['created_at']]);
        $blocks = array_slice($blocks, count($blocks) - MAX_BLOCKS, null, true);
    }
    update_option(OPT_BLOCKS, $blocks, true);
    record_event('block', 'blocked', $cidr, null, '', $source . ($block['reason'] !== '' ? ': ' . $block['reason'] : ''));
    return $block;
}

/**
 * Addresses the user's live sessions were opened from, as WordPress records them on login.
 *
 * @return list<string>
 */
function login_ips(int $user_id): array
{
    if ($user_id <= 0) {
        return [];
    }
    $tokens = get_user_meta($user_id, 'session_tokens', true);
    $ips = [];
    foreach (is_array($tokens) ? $tokens : [] as $session) {
        $ip = is_array($session) && is_string($session['ip'] ?? null) ? trim($session['ip']) : '';
        if (is_ip($ip)) {
            $ips[] = normalize_ip($ip);
        }
    }
    return array_values(array_unique($ips));
}

function remove_block(string $ip): bool
{
    $cidr = normalize_cidr($ip);
    $blocks = blocks();
    if ($cidr === '' || !isset($blocks[$cidr])) {
        return false;
    }
    unset($blocks[$cidr]);
    update_option(OPT_BLOCKS, $blocks, true);
    clear_failures($cidr);
    record_event('block', 'unblocked', $cidr, null, '', '');
    return true;
}

// ---------------------------------------------------------------- login failures

/**
 * Count a failed login from $ip; returns true when this one crosses the limit.
 *
 * @param array<string, mixed> $login
 */
function note_failure(string $ip, array $login, ?int $now = null): bool
{
    $now ??= time();
    $window = (int) $login['window_minutes'] * 60;
    $all = get_option(OPT_FAILURES, []);
    $all = is_array($all) ? $all : [];
    foreach ($all as $k => $f) {
        if (!is_array($f) || ($f['first'] ?? 0) + $window <= $now) {
            unset($all[$k]);
        }
    }
    $f = $all[$ip] ?? ['first' => $now, 'count' => 0];
    $f['count'] = (int) $f['count'] + 1;
    $all[$ip] = $f;
    if (count($all) > 5000) {
        $all = array_slice($all, -5000, null, true);
    }
    $crossed = $f['count'] >= (int) $login['max_failures'];
    if ($crossed) {
        unset($all[$ip]);
    }
    update_option(OPT_FAILURES, $all, false);
    return $crossed;
}

function clear_failures(string $ip): void
{
    $all = get_option(OPT_FAILURES, []);
    if (is_array($all) && isset($all[$ip])) {
        unset($all[$ip]);
        update_option(OPT_FAILURES, $all, false);
    }
}

/**
 * Count one hit of $kind from $ip in a sliding minute-sized window; returns the count.
 */
function note_hit(string $kind, string $ip, int $window_seconds, ?int $now = null): int
{
    $now ??= time();
    $key = 'gobuildwp_kit_sp_' . $kind . '_' . md5($ip);
    $hit = get_transient($key);
    if (!is_array($hit) || ($hit['first'] ?? 0) + $window_seconds <= $now) {
        $hit = ['first' => $now, 'count' => 0];
    }
    $hit['count'] = (int) $hit['count'] + 1;
    set_transient($key, $hit, $window_seconds);
    return $hit['count'];
}

// ---------------------------------------------------------------- events and counters

/**
 * @return array{id: string, at: string, type: string, action: string, ip: string, rule: string|null, path: string, detail: string}
 */
function record_event(string $type, string $action, string $ip, ?string $rule, string $path, string $detail, ?int $now = null): array
{
    $now ??= time();
    $event = [
        'id' => bin2hex(random_bytes(6)),
        'at' => gmdate('Y-m-d\TH:i:s\Z', $now),
        'type' => $type,
        'action' => $action,
        'ip' => $ip,
        'rule' => $rule,
        'path' => substr(sanitize_text($path), 0, 200),
        'detail' => substr(sanitize_text($detail), 0, 300),
    ];
    $events = get_option(OPT_EVENTS, []);
    $events = is_array($events) ? $events : [];
    $events[] = $event;
    $cutoff = gmdate('Y-m-d\TH:i:s\Z', $now - EVENT_DAYS * 86400);
    $events = array_values(array_filter($events, static fn($e) => is_array($e) && ($e['at'] ?? '') >= $cutoff));
    if (count($events) > MAX_EVENTS) {
        $events = array_slice($events, -MAX_EVENTS);
    }
    update_option(OPT_EVENTS, $events, false);
    count_event($type . '_' . $action, $now);
    return $event;
}

/**
 * @return list<array<string, mixed>>
 */
function events(int $limit, ?string $type = null): array
{
    $events = get_option(OPT_EVENTS, []);
    $events = is_array($events) ? array_reverse($events) : [];
    if ($type !== null) {
        $events = array_filter($events, static fn($e) => is_array($e) && ($e['type'] ?? '') === $type);
    }
    return array_slice(array_values($events), 0, max(1, $limit));
}

/**
 * Hourly counters, kept for 25 hours: events can be dropped from the log under attack, counts
 * aren't.
 */
function count_event(string $key, ?int $now = null): void
{
    $now ??= time();
    $hour = intdiv($now, 3600);
    $counts = get_option(OPT_COUNTS, []);
    $counts = is_array($counts) ? $counts : [];
    foreach (array_keys($counts) as $h) {
        if ((int) $h <= $hour - 25) {
            unset($counts[$h]);
        }
    }
    $counts[$hour][$key] = (int) ($counts[$hour][$key] ?? 0) + 1;
    update_option(OPT_COUNTS, $counts, false);
}

/** @return array<string, int> */
function counters(?int $now = null): array
{
    $now ??= time();
    $hour = intdiv($now, 3600);
    $counts = get_option(OPT_COUNTS, []);
    $sum = static function (string $key) use ($counts, $hour): int {
        $n = 0;
        foreach (is_array($counts) ? $counts : [] as $h => $row) {
            if ((int) $h > $hour - 24 && is_array($row)) {
                $n += (int) ($row[$key] ?? 0);
            }
        }
        return $n;
    };
    return [
        'firewall_logged_24h' => $sum('firewall_logged'),
        'firewall_blocked_24h' => $sum('firewall_blocked'),
        'login_failures_24h' => $sum('login_failed'),
        'lockouts_24h' => $sum('login_locked'),
        'enumeration_blocked_24h' => $sum('enumeration_blocked'),
        'flood_blocked_24h' => $sum('flood_blocked'),
        'blocks_active' => count(blocks($now)),
    ];
}

/**
 * @param array<string, mixed> $server
 * @return array<string, mixed>
 */
function status(array $server): array
{
    $settings = settings();
    return [
        'version' => 1,
        'settings' => $settings,
        'counters' => counters(),
        'client_ip_source' => $settings['ip_source'],
        'client_ip' => client_ip($server, $settings['ip_source']),
        'trusted_proxies' => trusted_proxies(),
        'protection_disabled' => defined('GOBUILDWP_DISABLE_PROTECTION') && constant('GOBUILDWP_DISABLE_PROTECTION') === true,
        'proxy_headers_seen' => proxy_headers_seen($server),
        'recent' => events(20),
    ];
}

/** @return list<array{ip: string, reason: string, source: string, created_at: string, expires_at: string|null}> */
function block_list(): array
{
    $out = [];
    foreach (blocks() as $b) {
        $out[] = [
            'ip' => $b['ip'],
            'reason' => $b['reason'],
            'source' => $b['source'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', $b['created_at']),
            'expires_at' => $b['expires_at'] === null ? null : gmdate('Y-m-d\TH:i:s\Z', $b['expires_at']),
        ];
    }
    return $out;
}

/**
 * Single-line printable text: request paths and reasons come from visitors.
 */
function sanitize_text(string $s): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s) ?? '';
    return function_exists('mb_scrub') ? mb_scrub($s, 'UTF-8') : $s;
}
