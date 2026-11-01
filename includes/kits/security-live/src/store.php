<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityLive;

if (!defined('ABSPATH')) {
    exit();
}

/** Settings: on/off, how often the change watch runs, paths it leaves out. Autoloaded, small. */
const SETTINGS_OPTION = 'gobuildwp_kit_sl_settings';

/** The event ring buffer: {next: int, items: list<event>}. Not autoloaded. */
const EVENTS_OPTION = 'gobuildwp_kit_sl_events';

/** Events kept; the oldest go first. */
const MAX_EVENTS = 200;

/** The same event (kind, rule, subject) is not recorded again within this window. */
const DEDUPE_SECONDS = 6 * 3600;

const SEVERITIES = ['info', 'low', 'medium', 'high', 'critical'];

const INTERVAL_RANGE = [5, 1440];

const MAX_EXCLUDED = 50;

/** Events this severe make the site tell GoBuildWP Cloud right away (signed heartbeat). */
const PUSH_SEVERITIES = ['high', 'critical'];

/** @return array{enabled: bool, interval_minutes: int, excluded_paths: list<string>} */
function settings(): array
{
    /** @var mixed $stored */
    $stored = get_option(SETTINGS_OPTION, []);
    $stored = is_array($stored) ? $stored : [];
    $interval = (int) ($stored['interval_minutes'] ?? 15);
    $excluded = [];
    foreach ((array) ($stored['excluded_paths'] ?? []) as $path) {
        $clean = clean_excluded((string) $path);
        if ($clean !== null) {
            $excluded[] = $clean;
        }
    }
    return [
        'enabled' => (bool) ($stored['enabled'] ?? true),
        'interval_minutes' => max(INTERVAL_RANGE[0], min(INTERVAL_RANGE[1], $interval)),
        'excluded_paths' => array_slice(array_values(array_unique($excluded)), 0, MAX_EXCLUDED),
    ];
}

/**
 * An excluded path as stored: relative to the WordPress folder, forward slashes, no leading or
 * trailing slash, no `..`. Null when it can't be one.
 */
function clean_excluded(string $path): ?string
{
    $path = trim(str_replace('\\', '/', $path));
    $path = trim(preg_replace('#/+#', '/', $path) ?? '', '/');
    if ($path === '' || strlen($path) > 200 || preg_match('/[\x00-\x1f]/', $path) === 1) {
        return null;
    }
    if (in_array('..', explode('/', $path), strict: true) || in_array('.', explode('/', $path), strict: true)) {
        return null;
    }
    return $path;
}

/**
 * Change settings. Unknown keys and bad values are refused, not ignored.
 *
 * @param array<string, mixed> $input enabled, interval_minutes, excluded_paths (replaces the list)
 * @return array{enabled: bool, interval_minutes: int, excluded_paths: list<string>}|\WP_Error
 */
function configure(array $input): array|\WP_Error
{
    $next = settings();
    foreach ($input as $key => $value) {
        switch ($key) {
            case 'enabled':
                if (!is_bool($value)) {
                    return new \WP_Error('gobuildwp_live_invalid', 'enabled must be true or false.', ['status' => 400]);
                }
                $next['enabled'] = $value;
                break;
            case 'interval_minutes':
                if (!is_int($value) || $value < INTERVAL_RANGE[0] || $value > INTERVAL_RANGE[1]) {
                    return new \WP_Error('gobuildwp_live_invalid', sprintf('interval_minutes must be %d to %d.', INTERVAL_RANGE[0], INTERVAL_RANGE[1]), ['status' => 400]);
                }
                $next['interval_minutes'] = $value;
                break;
            case 'excluded_paths':
                if (!is_array($value) || count($value) > MAX_EXCLUDED) {
                    return new \WP_Error('gobuildwp_live_invalid', sprintf('excluded_paths must be a list of at most %d paths.', MAX_EXCLUDED), ['status' => 400]);
                }
                $paths = [];
                foreach ($value as $path) {
                    $clean = is_string($path) ? clean_excluded($path) : null;
                    if ($clean === null) {
                        return new \WP_Error('gobuildwp_live_invalid', 'Each excluded path must be a folder or file inside the WordPress folder, without "..".', ['status' => 400]);
                    }
                    $paths[] = $clean;
                }
                $next['excluded_paths'] = array_values(array_unique($paths));
                break;
            default:
                return new \WP_Error('gobuildwp_live_invalid', sprintf('Unknown setting: %s.', (string) $key), ['status' => 400]);
        }
    }
    $before = settings();
    update_option(SETTINGS_OPTION, $next, true);
    if ($before['interval_minutes'] !== $next['interval_minutes'] || $before['enabled'] !== $next['enabled']) {
        reschedule($next);
    }
    return $next;
}

/** @return array{next: int, items: list<array<string, mixed>>} */
function event_store(): array
{
    /** @var mixed $stored */
    $stored = get_option(EVENTS_OPTION, []);
    $stored = is_array($stored) ? $stored : [];
    $items = is_array($stored['items'] ?? null) ? array_values(array_filter($stored['items'], 'is_array')) : [];
    return ['next' => max(1, (int) ($stored['next'] ?? 1)), 'items' => $items];
}

function rank(string $severity): int
{
    $rank = array_search($severity, SEVERITIES, strict: true);
    return $rank === false ? 0 : (int) $rank;
}

/**
 * Record an event. Returns its id, or 0 when it repeats one recorded in the last DEDUPE_SECONDS
 * or live security is off. High and critical events also ask the plugin to tell GoBuildWP Cloud
 * now (the debounced, signed heartbeat).
 *
 * Read-modify-write on one option: two requests recording at the same instant can lose one
 * event. Every event that matters is also found again by the change watch or the next scan.
 *
 * @param array<string, mixed> $detail path, area, result, rule_id, line, table, entity_id, label, note
 */
function record(string $severity, string $kind, string $subject, array $detail = [], ?int $now = null): int
{
    if (!settings()['enabled']) {
        return 0;
    }
    $now ??= time();
    $severity = in_array($severity, SEVERITIES, strict: true) ? $severity : 'info';
    $kind = substr(preg_replace('/[^a-z0-9_.]/', '', strtolower($kind)) ?? '', 0, 40);
    $subject = clip($subject, 300);
    $detail = clean_detail($detail);
    $key = $kind . '|' . (string) ($detail['rule_id'] ?? '') . '|' . $subject;

    $store = event_store();
    foreach (array_reverse($store['items']) as $item) {
        if ((int) ($item['at'] ?? 0) < $now - DEDUPE_SECONDS) {
            break;
        }
        if (($item['key'] ?? '') === $key) {
            return 0;
        }
    }
    $id = $store['next'];
    $store['items'][] = ['id' => $id, 'at' => $now, 'severity' => $severity, 'kind' => $kind, 'subject' => $subject, 'detail' => $detail, 'key' => $key];
    if (count($store['items']) > MAX_EVENTS) {
        $store['items'] = array_slice($store['items'], -MAX_EVENTS);
    }
    $store['next'] = $id + 1;
    update_option(EVENTS_OPTION, $store, false);

    if (in_array($severity, PUSH_SEVERITIES, strict: true) && function_exists('wppilot_cloud_note_event')) {
        \wppilot_cloud_note_event('security_live');
    }
    return $id;
}

/** @param array<string, mixed> $detail @return array<string, string|int> */
function clean_detail(array $detail): array
{
    $out = [];
    foreach (['path', 'area', 'result', 'rule_id', 'table', 'entity_id', 'label', 'note', 'component', 'by', 'network', 'count', 'window'] as $field) {
        if (!isset($detail[$field]) || (!is_string($detail[$field]) && !is_int($detail[$field]))) {
            continue;
        }
        $out[$field] = is_int($detail[$field]) ? $detail[$field] : clip($detail[$field], $field === 'note' ? 300 : 200);
    }
    if (isset($detail['line']) && is_int($detail['line']) && $detail['line'] > 0) {
        $out['line'] = $detail['line'];
    }
    return $out;
}

function clip(string $text, int $max): string
{
    $text = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text) ?? '';
    return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
}

/**
 * Events after an id, oldest first.
 *
 * @return list<array{id: int, at: int, severity: string, kind: string, subject: string, detail: array<string, mixed>}>
 */
function events_since(int $after = 0, int $limit = 100, string $min_severity = 'info'): array
{
    $limit = max(1, min(MAX_EVENTS, $limit));
    $floor = rank($min_severity);
    $out = [];
    foreach (event_store()['items'] as $item) {
        if ((int) $item['id'] <= $after || rank((string) $item['severity']) < $floor) {
            continue;
        }
        unset($item['key']);
        $out[] = $item;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/** The newest event id (0 when none). */
function last_event_id(): int
{
    return event_store()['next'] - 1;
}
