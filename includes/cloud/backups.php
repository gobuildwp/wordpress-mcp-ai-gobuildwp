<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Backup summary for the Cloud dashboard, §4.
 *
 * Read through the backup-status kit's own ability rather than its functions:
 * the kit loads only when a backup plugin is active, and an ability call keeps
 * the kit boundary intact. The answer covers what the Cloud needs to show
 * "last backup" per site and to wait for a backup it asked for: which backup
 * plugins are active, the newest successful backup, and whether one is running.
 *
 * Since 1.18.4 each provider also says why a backup is stuck: whether it is
 * running, the site's own reason it cannot be started, and the export GoBuildWP
 * Pro started (All-in-One WP Migration: running, stalled, failed or finished,
 * with its plain reason), so Cloud can show it and stop waiting for an export
 * that failed. File names, folders, job ids and keys are never passed on: the
 * reasons are scrubbed of paths and archive names on the way out.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Export states the Cloud understands (backup-status providers[].export.state). */
const WPPILOT_CLOUD_EXPORT_STATES = ['running', 'stalled', 'failed', 'finished'];

/**
 * @return array{
 *     providers: list<array{
 *         provider: string, label: string, readable: bool, startable: bool, running: bool,
 *         trigger_reason?: string,
 *         export?: array{state: string, reason: string|null, step: string|null, started_at: int|null, last_progress_at: int|null, finished_at: int|null}|null
 *     }>,
 *     newest: array{provider: string, timestamp: int}|null,
 *     running: bool
 * }|null Null when no supported backup plugin is active (the kit is not loaded).
 */
function wppilot_cloud_backup_summary(): ?array
{
    if (!function_exists('wp_get_ability')) {
        return null;
    }
    $ability = wp_get_ability('wppilot/backup-status');
    if ($ability === null) {
        return null;
    }

    $status = $ability->execute([]);
    if (!is_array($status) || !is_array($status['providers'] ?? null)) {
        return null;
    }

    $providers = [];
    $running = false;
    foreach ($status['providers'] as $provider) {
        if (!is_array($provider) || !is_string($provider['provider'] ?? null)) {
            continue;
        }
        $trigger = is_array($provider['trigger'] ?? null) ? $provider['trigger'] : [];
        $startable = ($trigger['supported'] ?? false) === true;
        // Each adapter answers running as {running: bool, jobs: [...]}.
        $provider_running = is_array($provider['running'] ?? null) && ($provider['running']['running'] ?? false) === true;
        $entry = [
            'provider' => $provider['provider'],
            'label' => is_string($provider['label'] ?? null) ? $provider['label'] : $provider['provider'],
            'readable' => ($provider['readable'] ?? false) === true,
            // Whether GoBuildWP Pro could start a backup with it here (the adapter's trigger.supported),
            // so Cloud does not offer a start the site would refuse.
            'startable' => $startable,
            'running' => $provider_running,
        ];
        if (!$startable) {
            $why = wppilot_cloud_backup_text($trigger['reason'] ?? ($provider['error'] ?? null), 300);
            if ($why !== null) {
                $entry['trigger_reason'] = $why;
            }
        }
        if (array_key_exists('export', $provider)) {
            $entry['export'] = wppilot_cloud_backup_export($provider['export']);
        }
        $providers[] = $entry;
        if ($provider_running) {
            $running = true;
        }
    }

    $newest = $status['newest_successful_backup'] ?? null;

    return [
        'providers' => $providers,
        'newest' => is_array($newest) && is_string($newest['provider'] ?? null) && is_numeric($newest['timestamp'] ?? null)
            ? ['provider' => $newest['provider'], 'timestamp' => (int) $newest['timestamp']]
            : null,
        'running' => $running,
    ];
}

/**
 * The export GoBuildWP Pro started, as Cloud may see it: state, the plain reason, the step and its
 * times. backup_id and anything else the site adds are left out.
 *
 * @return array{state: string, reason: string|null, step: string|null, started_at: int|null, last_progress_at: int|null, finished_at: int|null}|null
 */
function wppilot_cloud_backup_export(mixed $export): ?array
{
    if (!is_array($export) || !in_array($export['state'] ?? null, WPPILOT_CLOUD_EXPORT_STATES, true)) {
        return null;
    }

    return [
        'state' => $export['state'],
        'reason' => wppilot_cloud_backup_text($export['reason'] ?? null, 600),
        'step' => wppilot_cloud_backup_text($export['step'] ?? null, 80),
        'started_at' => wppilot_cloud_backup_time($export['started'] ?? null),
        'last_progress_at' => wppilot_cloud_backup_time($export['last_progress'] ?? null),
        'finished_at' => wppilot_cloud_backup_time($export['finished'] ?? null),
    ];
}

/**
 * A plain reason for Cloud, or null: paths, archive names and long hex ids (job folders) are
 * replaced, so nothing that locates a backup file leaves the site.
 */
function wppilot_cloud_backup_text(mixed $text, int $max): ?string
{
    if (!is_string($text)) {
        return null;
    }
    $text = wp_strip_all_tags($text);
    $text = preg_replace('#(?<![\w.-])(?:[A-Za-z]:)?[\\\\/][^\s\'"()]+#', '[path]', $text) ?? '';
    $text = preg_replace('#[^\s\'"()\[\]]+\.(?:wpress|zip|gz|tar|sql|daf|log)\b#i', '[file]', $text) ?? '';
    $text = preg_replace('#\b[a-f0-9]{13,}\b#i', '[id]', $text) ?? '';
    $text = trim(preg_replace('#\s+#', ' ', $text) ?? '');

    return $text === '' ? null : mb_substr($text, 0, $max);
}

/**
 * Unix seconds from a backup-status time ({time, time_utc}, as its times() builds it) or a number.
 */
function wppilot_cloud_backup_time(mixed $time): ?int
{
    if (is_int($time) || (is_string($time) && ctype_digit($time))) {
        return (int) $time > 0 ? (int) $time : null;
    }
    $utc = is_array($time) ? ($time['time_utc'] ?? null) : null;
    if (!is_string($utc)) {
        return null;
    }
    $parsed = strtotime($utc);

    return $parsed === false || $parsed <= 0 ? null : $parsed;
}
