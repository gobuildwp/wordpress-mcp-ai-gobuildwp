<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\NotFoundLog;

if (!defined('ABSPATH')) {
    exit();
}

/** The rolled-up log: path => [hits, referrer host, last seen]. Not autoloaded. */
const OPTION = 'wppilot_kit_not_found_log';

/** Return false from this filter to stop recording. */
const ENABLED_FILTER = 'wppilot_kit_not_found_log_enabled';

/** Most distinct paths kept; a new path replaces the least-hit one. */
const MAX_PATHS = 200;

const MAX_PATH_LENGTH = 200;

/** Seconds between writes of the log, whichever paths the 404s are for. */
const WRITE_INTERVAL = 1;

function register_recorder(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    add_action('template_redirect', __NAMESPACE__ . '\\on_template_redirect', 99);
}

function on_template_redirect(): void
{
    if (!is_404() || !apply_filters(ENABLED_FILTER, true)) {
        return;
    }
    $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
    $referer = isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER']) ? wp_unslash($_SERVER['HTTP_REFERER']) : '';
    record((string) $uri, (string) $referer, time());
}

/** The path alone: no query or fragment, no control characters, at most MAX_PATH_LENGTH. */
function clean_path(string $uri): string
{
    $uri = (string) preg_replace('/[\x00-\x1f\x7f]+/', '', $uri);
    $path = wp_parse_url($uri, PHP_URL_PATH);
    $path = is_string($path) ? $path : '';
    if ($path === '' || $path[0] !== '/') {
        return '';
    }
    return substr($path, 0, MAX_PATH_LENGTH);
}

/** The referring host only, never the full URL (which can carry personal data). */
function clean_referrer(string $referer): ?string
{
    if ($referer === '') {
        return null;
    }
    $host = wp_parse_url($referer, PHP_URL_HOST);
    if (!is_string($host)) {
        return null;
    }
    $host = strtolower($host);
    return preg_match('/^[a-z0-9.-]{1,200}$/', $host) === 1 ? $host : null;
}

/**
 * Count one 404. The log is written at most once per WRITE_INTERVAL across all paths: a 404 in
 * the same second as the last write (the newest last_seen) is not written, so a scanner trying
 * new paths cannot turn the log into a write per request. Counts are therefore a floor.
 */
function record(string $uri, string $referer, int $now): void
{
    $path = clean_path($uri);
    if ($path === '') {
        return;
    }
    $log = read();
    $last_write = $log === [] ? 0 : max(array_column($log, 'last_seen'));
    // Only a write in this second holds the next one back; a clock that went backwards does not.
    if ($now >= $last_write && $now - $last_write < WRITE_INTERVAL) {
        return;
    }
    if (isset($log[$path])) {
        if ($log[$path]['last_seen'] >= $now) {
            return;
        }
        $log[$path]['hits']++;
        $log[$path]['last_seen'] = $now;
        $referrer = clean_referrer($referer);
        if ($referrer !== null) {
            $log[$path]['referrer'] = $referrer;
        }
    } else {
        if (count($log) >= MAX_PATHS) {
            uasort($log, static fn(array $a, array $b): int => [$a['hits'], $a['last_seen']] <=> [$b['hits'], $b['last_seen']]);
            array_splice($log, 0, count($log) - MAX_PATHS + 1);
            // array_splice renumbers integer-like keys; paths always start with "/", so none are.
        }
        $log[$path] = ['hits' => 1, 'referrer' => clean_referrer($referer), 'last_seen' => $now];
    }
    update_option(OPTION, $log, false);
}

/** @return array<string, array{hits: int, referrer: string|null, last_seen: int}> */
function read(): array
{
    $raw = get_option(OPTION, []);
    $out = [];
    if (!is_array($raw)) {
        return $out;
    }
    foreach ($raw as $path => $entry) {
        if (!is_string($path) || !is_array($entry)) {
            continue;
        }
        $out[$path] = [
            'hits' => max(0, (int) ($entry['hits'] ?? 0)),
            'referrer' => is_string($entry['referrer'] ?? null) ? $entry['referrer'] : null,
            'last_seen' => max(0, (int) ($entry['last_seen'] ?? 0)),
        ];
    }
    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function entries(array $input): array
{
    $limit = isset($input['limit']) ? max(1, min(MAX_PATHS, (int) $input['limit'])) : 100;
    $log = read();
    uasort($log, static fn(array $a, array $b): int => [$b['hits'], $b['last_seen']] <=> [$a['hits'], $a['last_seen']]);
    $entries = [];
    foreach (array_slice($log, 0, $limit, true) as $path => $entry) {
        $entries[] = ['path' => $path] + $entry;
    }
    return [
        'entries' => $entries,
        'tracked_paths' => count($log),
        'max_paths' => MAX_PATHS,
        'recording' => (bool) apply_filters(ENABLED_FILTER, true),
    ];
}
