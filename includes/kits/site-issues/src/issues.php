<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteIssues;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Everything that went wrong on the site recently, from the sources that record it: this kit's
 * PHP error log, WordPress's paused extensions (recovery mode), failed backups read through the
 * backup-status ability, and whatever other kits contribute through the COLLECT_FILTER (the
 * safe-updates kit adds updates it rolled back).
 */

const COLLECT_FILTER = 'wppilot_kit_site_issues_collect';

/** How far back the report looks for update and backup failures. */
const DEFAULT_DAYS = 30;

const BACKUP_STATUS_ABILITY = 'wppilot/backup-status';

/**
 * wppilot/site-issues
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function report(array $input = []): array
{
    $days = is_int($input['days'] ?? null) ? max(1, min(90, $input['days'])) : DEFAULT_DAYS;
    $limit = is_int($input['limit'] ?? null) ? max(1, min(MAX_ERRORS, $input['limit'])) : 20;
    $since = time() - $days * DAY_IN_SECONDS;

    $errors = array_map(__NAMESPACE__ . '\\public_error', errors_since($since, $limit));
    $paused = paused_extensions();
    $other = collected($since);
    $updates = array_values(array_filter($other, static fn(array $i): bool => $i['kind'] === 'update_rolled_back'));
    $backups = backup_failures($since);

    $iso = static fn(array $item): array => array_merge($item, ['at' => gmdate('c', (int) $item['at'])]);
    return [
        'since' => gmdate('c', $since),
        'counts' => counts($errors, $updates, $backups, $paused),
        'php_errors' => $errors,
        'paused_extensions' => $paused,
        'update_failures' => array_map($iso, array_slice($updates, 0, $limit)),
        'backup_failures' => array_map($iso, $backups),
        'error_log' => [
            'kept' => MAX_ERRORS,
            'note' => 'Fatal errors are recorded by WordPress\'s fatal error handler on this site, deduplicated by message, file and line; count is a lower bound. A fatal in a plugin that loads before this one, while it is being loaded, is not recorded.',
        ],
    ];
}

/**
 * A stored error as reported: times as ISO 8601, plus the one-line description.
 *
 * @param array<string, mixed> $entry
 * @return array<string, mixed>
 */
function public_error(array $entry): array
{
    return [
        'id' => (string) $entry['id'],
        'type' => (string) ($entry['type'] ?? ''),
        'message' => (string) ($entry['message'] ?? ''),
        'file' => (string) ($entry['file'] ?? ''),
        'line' => (int) ($entry['line'] ?? 0),
        'source' => (string) ($entry['source'] ?? ''),
        'cause' => (string) ($entry['cause'] ?? 'error'),
        'url_path' => (string) ($entry['url_path'] ?? ''),
        'count' => (int) ($entry['count'] ?? 1),
        'first_at' => gmdate('c', (int) ($entry['first_at'] ?? 0)),
        'last_at' => gmdate('c', (int) ($entry['last_at'] ?? 0)),
        'summary' => describe($entry),
    ];
}

/**
 * Plugins and themes WordPress paused after they caused a fatal error (recovery mode). Paused
 * only while an administrator is in recovery mode; for everyone else they still run.
 *
 * @return list<array<string, mixed>>
 */
function paused_extensions(): array
{
    $out = [];
    $stores = [];
    if (function_exists('wp_paused_plugins')) {
        $stores['plugin'] = wp_paused_plugins();
    }
    if (function_exists('wp_paused_themes')) {
        $stores['theme'] = wp_paused_themes();
    }
    foreach ($stores as $type => $store) {
        if (!$store instanceof \WP_Paused_Extensions_Storage) {
            continue;
        }
        foreach ($store->get_all() as $slug => $error) {
            if (!is_array($error)) {
                continue;
            }
            $entry = normalize_error($error);
            $out[] = [
                'type' => $type,
                'slug' => (string) $slug,
                'message' => $entry['message'],
                'file' => $entry['file'],
                'line' => $entry['line'],
                'summary' => sprintf('WordPress paused the %1$s %2$s after: %3$s', $type, $slug, describe($entry)),
            ];
        }
    }
    return $out;
}

/**
 * Issues other kits report. Each must be {kind, id, at (unix), summary, source?}; anything else is
 * dropped, so a broken contributor cannot break the report.
 *
 * @return list<array{kind: string, id: string, at: int, summary: string, source: string}>
 */
function collected(int $since): array
{
    /** @var mixed $raw */
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- COLLECT_FILTER is this kit's own prefixed filter name (wppilot_kit_site_issues_collect).
    $raw = apply_filters(COLLECT_FILTER, [], $since);
    $out = [];
    foreach (is_array($raw) ? $raw : [] as $item) {
        if (!is_array($item) || !is_string($item['kind'] ?? null) || !is_string($item['id'] ?? null) || !is_int($item['at'] ?? null)) {
            continue;
        }
        if ($item['at'] < $since) {
            continue;
        }
        $out[] = [
            'kind' => sanitize_key($item['kind']),
            'id' => substr(preg_replace('/[^A-Za-z0-9_-]/', '', $item['id']) ?? '', 0, 40),
            'at' => $item['at'],
            'summary' => mb_substr(wp_strip_all_tags((string) ($item['summary'] ?? '')), 0, MAX_MESSAGE),
            'source' => mb_substr(sanitize_text_field((string) ($item['source'] ?? '')), 0, 100),
        ];
    }
    usort($out, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);
    return $out;
}

/**
 * The backup plugins' last runs that failed, read through wppilot/backup-status when it is
 * registered (a site with no supported backup plugin has none to report).
 *
 * @return list<array{provider: string, at: int, summary: string, id: string}>
 */
function backup_failures(int $since): array
{
    static $memo = null;
    if (is_array($memo)) {
        return array_values(array_filter($memo, static fn(array $f): bool => $f['at'] >= $since));
    }
    $memo = [];
    $ability = function_exists('wp_get_ability') ? wp_get_ability(BACKUP_STATUS_ABILITY) : null;
    if (!$ability instanceof \WP_Ability) {
        return [];
    }
    try {
        /** @var mixed $status */
        $status = Runtime\run_ability($ability, []);
    } catch (\Throwable $error) {
        // A backup plugin that throws while being read must not take the whole report with it.
        unset($error);
        return [];
    }
    if (!is_array($status) || !is_array($status['providers'] ?? null)) {
        return [];
    }
    foreach ($status['providers'] as $provider) {
        $last = is_array($provider) && is_array($provider['last_backup'] ?? null) ? $provider['last_backup'] : null;
        if ($last === null || !in_array((string) ($last['result'] ?? ''), ['failed', 'error'], true)) {
            continue;
        }
        $at = (int) ($last['finished_timestamp'] ?? $last['timestamp'] ?? 0);
        $label = (string) ($provider['label'] ?? $provider['provider'] ?? 'backup plugin');
        $errors = (int) ($last['errors'] ?? 0);
        $memo[] = [
            'provider' => (string) ($provider['provider'] ?? ''),
            'at' => $at,
            'summary' => sprintf('The last %1$s backup failed%2$s.', $label, $errors > 0 ? sprintf(' with %d error(s)', $errors) : ''),
            'id' => substr(hash('sha256', 'backup|' . ($provider['provider'] ?? '') . '|' . $at), 0, 32),
        ];
    }
    return array_values(array_filter($memo, static fn(array $f): bool => $f['at'] >= $since));
}

/**
 * @param list<array<string, mixed>> $errors
 * @param list<array<string, mixed>> $updates
 * @param list<array<string, mixed>> $backups
 * @param list<array<string, mixed>> $paused
 * @return array{php_fatal: int, update_rolled_back: int, backup_failed: int, paused_extensions: int}
 */
function counts(array $errors, array $updates, array $backups, array $paused): array
{
    return [
        'php_fatal' => count($errors),
        'update_rolled_back' => count($updates),
        'backup_failed' => count($backups),
        'paused_extensions' => count($paused),
    ];
}

/**
 * The compact form the Cloud reads in cloud/status: counts, when the newest fatal was, and the
 * newest few issues of every kind. File paths are relative to the WordPress root; nothing else
 * about the server is included.
 *
 * @return array<string, mixed>
 */
function cloud_summary(int $recent = 10): array
{
    $since = time() - DEFAULT_DAYS * DAY_IN_SECONDS;
    $errors = errors_since($since, MAX_ERRORS);
    $paused = paused_extensions();
    $updates = array_values(array_filter(collected($since), static fn(array $i): bool => $i['kind'] === 'update_rolled_back'));
    $backups = backup_failures($since);

    $items = [];
    foreach ($errors as $e) {
        $items[] = [
            'id' => (string) $e['id'],
            'kind' => 'php_fatal',
            'first_at' => (int) $e['first_at'],
            'last_at' => (int) $e['last_at'],
            'count' => (int) $e['count'],
            'summary' => describe($e),
            'source' => (string) $e['source'],
            'file' => (string) $e['file'],
            'line' => (int) $e['line'],
            'url_path' => (string) ($e['url_path'] ?? ''),
        ];
    }
    foreach ($updates as $u) {
        $items[] = ['id' => $u['id'], 'kind' => 'update_rolled_back', 'first_at' => $u['at'], 'last_at' => $u['at'], 'count' => 1, 'summary' => $u['summary'], 'source' => $u['source'], 'file' => '', 'line' => 0, 'url_path' => ''];
    }
    foreach ($backups as $b) {
        $items[] = ['id' => $b['id'], 'kind' => 'backup_failed', 'first_at' => $b['at'], 'last_at' => $b['at'], 'count' => 1, 'summary' => $b['summary'], 'source' => $b['provider'], 'file' => '', 'line' => 0, 'url_path' => ''];
    }
    $now = time();
    foreach ($paused as $p) {
        $items[] = [
            'id' => substr(hash('sha256', 'paused|' . $p['type'] . '|' . $p['slug'] . '|' . $p['message']), 0, 32),
            'kind' => 'paused_extension',
            'first_at' => $now,
            'last_at' => $now,
            'count' => 1,
            'summary' => $p['summary'],
            'source' => $p['type'] === 'theme' ? 'theme:' . $p['slug'] : $p['slug'],
            'file' => $p['file'],
            'line' => $p['line'],
            'url_path' => '',
        ];
    }
    usort($items, static fn(array $a, array $b): int => $b['last_at'] <=> $a['last_at']);
    $items = array_slice($items, 0, max(1, $recent));
    foreach ($items as &$item) {
        $item['first_at'] = gmdate('c', $item['first_at']);
        $item['last_at'] = gmdate('c', $item['last_at']);
    }
    unset($item);

    $newest = $errors !== [] ? (int) $errors[0]['last_at'] : 0;
    return [
        'counts' => counts($errors, $updates, $backups, $paused),
        'newest_fatal_at' => $newest > 0 ? gmdate('c', $newest) : null,
        'recent' => $items,
    ];
}
