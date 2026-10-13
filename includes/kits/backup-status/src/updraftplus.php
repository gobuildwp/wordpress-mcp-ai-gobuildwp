<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BackupStatus;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * UpdraftPlus (verified against 1.26.8).
 *
 * Two records, and neither is complete on its own. `updraft_backup_history` lists every backup
 * set whose files exist, keyed by the time the job started, with per-entity file lists and
 * `<entity>-size` byte counts — but no verdict. `updraft_last_backup` holds the verdict
 * (`success` 1/0 and the errors) for the most recent job only. So a set is reported `success` or
 * `failed` only when it is that last job; every older set is `unknown`, because UpdraftPlus kept
 * no result for it, and a set being present only proves some files were written.
 *
 * Running jobs are the ones with an `updraft_backup_resume` cron event (the resumption UpdraftPlus
 * schedules for every job it boots; args [resumption, nonce]); that is how its own admin lists
 * active jobs. A Backup Now queued as one of UpdraftPlus's own `updraft_backupnow_*` WP-Cron events
 * (the way GoBuildWP Pro's wppilot/backup-trigger starts one) and not yet picked up is `queued`.
 */

const UPDRAFTPLUS_EVENTS = [
    'full' => 'updraft_backupnow_backup_all',
    'database' => 'updraft_backupnow_backup_database',
    'files' => 'updraft_backupnow_backup',
];

function updraftplus_active(): bool
{
    return defined('UPDRAFTPLUS_DIR') && class_exists('UpdraftPlus') && class_exists('UpdraftPlus_Options');
}

function updraftplus_option(string $name, mixed $default = null): mixed
{
    return \UpdraftPlus_Options::get_updraft_option($name, $default);
}

/**
 * @return array<int, array<string, mixed>>
 */
function updraftplus_history(): array
{
    $history = class_exists('UpdraftPlus_Backup_History') && method_exists('UpdraftPlus_Backup_History', 'get_history')
        ? \UpdraftPlus_Backup_History::get_history()
        : updraftplus_option('updraft_backup_history', []);

    return is_array($history) ? $history : [];
}

/**
 * Storage method ids to the names UpdraftPlus shows. `none` and empty mean the archives stay on
 * the web server.
 *
 * @return list<string>
 */
function updraftplus_storage_names(mixed $services): array
{
    global $updraftplus;
    $methods = is_object($updraftplus) && is_array($updraftplus->backup_methods ?? null) ? $updraftplus->backup_methods : [];
    $names = [];
    foreach ((array) $services as $service) {
        if (!is_string($service) || $service === '' || $service === 'none') {
            continue;
        }
        $names[] = isset($methods[$service]) && is_string($methods[$service]) ? $methods[$service] : $service;
    }
    $names = array_values(array_unique($names));

    return $names === [] ? ['Local (web server)'] : array_merge(['Local (web server)'], $names);
}

/**
 * @param array<string, mixed> $set
 * @param array<string, mixed>|null $last updraft_last_backup, when it describes this set
 * @return array<string, mixed>
 */
function updraftplus_record(int $timestamp, array $set, ?array $last): array
{
    $contents = [];
    $size = 0;
    $sized = false;
    foreach ($set as $key => $value) {
        $key = (string) $key;
        // plugins-size, db-size, and plugins1-size… for the later parts of a split archive.
        if (preg_match('/-size\d*$/', $key) === 1) {
            $bytes = bytes($value);
            if ($bytes !== null) {
                $size += $bytes;
                $sized = true;
            }
            continue;
        }
        // A set also carries bookkeeping (checksums, files_enumerated_at, last_saved_by_version,
        // is_multisite…), so an entity is a key UpdraftPlus recorded a size for; db, db1, db2… are
        // the site's and any extra databases.
        $is_db = preg_match('/^db\d*$/', $key) === 1 && is_string($value) && $value !== '';
        if ($is_db || (array_key_exists($key . '-size', $set) && $value !== [] && $value !== '')) {
            $contents[] = $is_db ? 'db' : $key;
        }
    }
    $contents = array_values(array_unique($contents));

    $result = 'unknown';
    $errors = null;
    $warnings = null;
    if ($last !== null) {
        $result = !empty($last['success']) ? 'success' : 'failed';
        [$errors, $warnings] = updraftplus_error_counts($last['errors'] ?? []);
    }

    return record('updraftplus', [
        'id' => (string) $timestamp,
        'timestamp' => $timestamp,
        'result' => $result,
        'contents' => $contents,
        'size_bytes' => $sized ? $size : null,
        'storage' => updraftplus_storage_names($set['service'] ?? null),
        'label' => is_string($set['label'] ?? null) ? $set['label'] : null,
        'errors' => $errors,
        'warnings' => $warnings,
        'kind' => ($set['native'] ?? true) === false ? 'imported' : 'backup',
    ]);
}

/**
 * @return array{0: int, 1: int}
 */
function updraftplus_error_counts(mixed $errors): array
{
    $error_count = 0;
    $warning_count = 0;
    foreach (is_array($errors) ? $errors : [] as $error) {
        if (is_array($error) && ($error['level'] ?? 'error') === 'warning') {
            ++$warning_count;
        } else {
            ++$error_count;
        }
    }

    return [$error_count, $warning_count];
}

/**
 * The last job's verdict, as a record even when its set left no files in the history.
 *
 * @return array{last: array<string, mixed>|null, raw: array<string, mixed>|null}
 */
function updraftplus_last(array $history): array
{
    $raw = updraftplus_option('updraft_last_backup', []);
    if (!is_array($raw) || !is_numeric($raw['backup_time'] ?? null)) {
        return ['last' => null, 'raw' => null];
    }
    $time = (int) $raw['backup_time'];
    $set = is_array($history[$time] ?? null)
        ? $history[$time]
        : (is_array($raw['backup_array'] ?? null) ? $raw['backup_array'] : []);

    $record = updraftplus_record($time, $set, $raw);
    if (!is_array($history[$time] ?? null)) {
        // The verdict outlives the set: once the archives are deleted (by hand or by retention)
        // UpdraftPlus keeps `updraft_last_backup`, and a backup that no longer exists must not
        // count as one.
        $record['kind'] = 'deleted';
    }

    return ['last' => $record, 'raw' => $raw];
}

/**
 * @return list<array<string, mixed>>
 */
function updraftplus_list(int $limit): array
{
    $history = updraftplus_history();
    $last = updraftplus_last($history);
    $last_time = $last['raw'] !== null ? (int) $last['raw']['backup_time'] : null;
    $records = [];
    foreach ($history as $timestamp => $set) {
        if (!is_array($set) || !is_numeric($timestamp)) {
            continue;
        }
        $records[] = updraftplus_record((int) $timestamp, $set, (int) $timestamp === $last_time ? $last['raw'] : null);
    }
    // A failed last job may have left no set in the history; it still belongs in the list.
    if ($last['last'] !== null && !isset($history[$last_time])) {
        $records[] = $last['last'];
    }

    return newest_first($records, $limit);
}

/**
 * Jobs UpdraftPlus is running, and Backup Now events WP-Cron has not started yet.
 *
 * @return array{running: bool, jobs: list<array<string, mixed>>}
 */
function updraftplus_running(): array
{
    // _get_cron_array() has been in core since 2.1; every WP-Cron function below reads it.
    $cron = _get_cron_array();
    $jobs = [];
    foreach (is_array($cron) ? $cron : [] as $time => $hooks) {
        if (!is_array($hooks)) {
            continue;
        }
        foreach ($hooks as $hook => $events) {
            $queued = in_array($hook, UPDRAFTPLUS_EVENTS, strict: true);
            if ($hook !== 'updraft_backup_resume' && !$queued) {
                continue;
            }
            foreach (is_array($events) ? $events : [] as $event) {
                $state = 'running';
                $started = null;
                if ($queued) {
                    $state = 'queued';
                } else {
                    $nonce = is_array($event['args'] ?? null) ? (string) ($event['args'][1] ?? '') : '';
                    $jobdata = $nonce !== '' ? get_site_option('updraft_jobdata_' . $nonce, []) : [];
                    if (is_array($jobdata) && ($jobdata['jobstatus'] ?? '') === 'finished') {
                        continue;
                    }
                    $started = is_array($jobdata) && is_numeric($jobdata['backup_time'] ?? null) ? (int) $jobdata['backup_time'] : null;
                    $state = is_array($jobdata) && is_string($jobdata['jobstatus'] ?? null) ? 'running: ' . $jobdata['jobstatus'] : 'running';
                }
                $jobs[] = array_merge(
                    ['state' => $state, 'next_step_at' => scheduled((int) $time, $hook)['time_utc']],
                    $started !== null ? ['started' => times($started)] : [],
                );
            }
        }
    }

    return ['running' => $jobs !== [], 'jobs' => $jobs];
}

/**
 * @return array<string, mixed>
 */
function updraftplus_status(): array
{
    $history = updraftplus_history();
    $last = updraftplus_last($history);

    // The newest set that is confirmed good is only ever the last job: older sets carry no verdict.
    $success = $last['last'] !== null && $last['last']['result'] === 'success' && $last['last']['kind'] !== 'deleted' ? $last['last'] : null;

    $next = [];
    $files = wp_next_scheduled('updraft_backup');
    if (is_int($files)) {
        $next[] = scheduled($files, 'files');
    }
    $database = wp_next_scheduled('updraft_backup_database');
    if (is_int($database)) {
        $next[] = scheduled($database, 'database');
    }
    usort($next, static fn(array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

    global $updraftplus;

    return [
        'version' => is_object($updraftplus) && is_string($updraftplus->version ?? null) ? $updraftplus->version : null,
        'last_backup' => $last['last'],
        'last_successful_backup' => $success,
        'running' => updraftplus_running(),
        'next_scheduled' => $next,
        'schedule' => [
            'files' => is_string(updraftplus_option('updraft_interval')) ? updraftplus_option('updraft_interval') : 'manual',
            'database' => is_string(updraftplus_option('updraft_interval_database')) ? updraftplus_option('updraft_interval_database') : 'manual',
        ],
        'storage' => updraftplus_storage_names(updraftplus_option('updraft_service')),
        'backup_sets_kept' => count($history),
        'trigger' => ['supported' => true, 'scopes' => array_keys(UPDRAFTPLUS_EVENTS)],
        'notes' => [
            'UpdraftPlus records a result (success or failed) for its most recent job only; older sets show result "unknown".',
            'Times are when the backup job started.',
        ],
    ];
}
