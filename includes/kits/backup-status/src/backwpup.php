<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BackupStatus;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * BackWPup (verified against 5.7.6).
 *
 * BackWPup backs up by job, and a job's result lives only in the header of the log it wrote:
 * `BackWPup_Job::read_logheader()` gives the start time, errors, warnings, runtime, archive size
 * and the job types that ran. So a backup here is a run read from BackWPup's logs folder, newest
 * first, the way its Logs screen lists them. The job option `lastrun` is not used for time: it is
 * stored shifted by the site's UTC offset (`time() + gmt_offset`), where the log header's date is
 * a real timestamp.
 *
 * Jobs flagged `tempjob` are BackWPup's own scaffolding (the onboarding "First backup" job) and
 * are hidden, as its own CLI and REST listings hide them.
 *
 * A job queued now on BackWPup's own `backwpup_cron` event (how GoBuildWP Pro's backup-trigger starts
 * one) shows as `queued` until WP-Cron picks it up. Only jobs that start with WP-Cron (activetype
 * `wpcron`) can be started that way, which is what each job's `can_trigger` says.
 *
 * A log outlives its archive: retention (the job's `maxbackups`), BackWPup's Backups screen and a
 * person with FTP all delete archives and leave the log, whose header still reads as a clean run.
 * So a successful run is checked against its archive where that is cheap: when the job keeps
 * archives in a local folder (destination `FOLDER`, its `backupdir`), the archive the log names
 * must still be there. A folder-only job whose archive is gone is `kind: "deleted"` and is not a
 * successful backup any more (the UpdraftPlus reader does the same). Where the archive went to
 * remote storage too, or only there, it is not looked up: the run still counts, with
 * `archive_verified: false` saying so.
 */

const BACKWPUP_CONTENTS = [
    'DBDUMP' => 'db',
    'FILE' => 'files',
    'WPPLUGIN' => 'plugin-list',
    'WPEXP' => 'content-export',
];

function backwpup_active(): bool
{
    return class_exists('BackWPup') && class_exists('BackWPup_Option') && class_exists('BackWPup_Job')
        && method_exists('BackWPup_Job', 'read_logheader');
}

/**
 * Every job an owner made, keyed by id: BackWPup's own scaffolding jobs left out.
 *
 * @return array<int, array<string, mixed>>
 */
function backwpup_jobs(): array
{
    $jobs = [];
    foreach ((array) \BackWPup_Option::get_job_ids() as $id) {
        $job = \BackWPup_Option::get_job((int) $id);
        if (!is_array($job) || !empty($job['tempjob'])) {
            continue;
        }
        $jobs[(int) $id] = $job;
    }

    return $jobs;
}

/**
 * @return list<string>
 */
function backwpup_destination_names(mixed $destinations): array
{
    $registered = method_exists('BackWPup', 'get_registered_destinations') ? \BackWPup::get_registered_destinations() : [];
    $names = [];
    foreach ((array) $destinations as $key) {
        if (!is_string($key)) {
            continue;
        }
        $name = $registered[$key]['info']['name'] ?? null;
        $names[] = is_string($name) && $name !== '' ? $name : $key;
    }

    return $names;
}

/**
 * What a job's types amount to, from the log header's `FILE+DBDUMP+…` or a job's type list.
 *
 * @param list<string>|string $types
 * @param array<string, mixed>|null $job The job, for which folders a FILE step covers.
 * @return list<string>
 */
function backwpup_contents(array|string $types, ?array $job = null): array
{
    $types = is_string($types) ? array_filter(explode('+', $types)) : $types;
    $contents = [];
    foreach ($types as $type) {
        $content = BACKWPUP_CONTENTS[(string) $type] ?? null;
        if ($content === null) {
            continue;
        }
        if ($content === 'files' && $job !== null) {
            foreach (['backuproot' => 'core', 'backupcontent' => 'wp-content', 'backupplugins' => 'plugins', 'backupthemes' => 'themes', 'backupuploads' => 'uploads'] as $key => $part) {
                if (!empty($job[$key])) {
                    $contents[] = $part;
                }
            }
            continue;
        }
        $contents[] = $content;
    }

    return array_values(array_unique($contents));
}

/**
 * The job BackWPup is running now, if any.
 */
function backwpup_working(): ?object
{
    $working = \BackWPup_Job::get_working_data();

    return is_object($working) ? $working : null;
}

/**
 * One run, from its log header.
 *
 * @param array<string, mixed> $header
 * @param array<int, array<string, mixed>> $jobs
 * @return array<string, mixed>
 */
function backwpup_record(array $header, array $jobs, ?object $working, string $logfile): array
{
    $jobid = (int) ($header['jobid'] ?? 0);
    $job = $jobs[$jobid] ?? null;
    $errors = (int) ($header['errors'] ?? 0);
    $warnings = (int) ($header['warnings'] ?? 0);
    $runtime = (int) ($header['runtime'] ?? 0);
    $size = bytes($header['backupfilesize'] ?? null);
    $timestamp = (int) ($header['logtime'] ?? 0);

    $is_running = $working !== null && property_exists($working, 'logfile') && (string) $working->logfile === $logfile;
    if ($is_running) {
        $result = 'running';
    } elseif ($errors > 0) {
        $result = 'failed';
    } elseif ($runtime > 0 || ($size ?? 0) > 0) {
        $result = 'success';
    } else {
        // No end written and no error: the run was killed before it could record either.
        $result = 'unknown';
    }

    $finished = !$is_running && $runtime > 0 ? $timestamp + $runtime : null;
    $archive = $result === 'success' ? backwpup_archive_state($job, $logfile) : null;

    return array_merge(
        record('backwpup', [
            'id' => $jobid . '@' . $timestamp,
            'timestamp' => $timestamp,
            'result' => $result,
            'contents' => backwpup_contents((string) ($header['type'] ?? ''), $job),
            'size_bytes' => $size !== null && $size > 0 ? $size : null,
            'storage' => $job !== null ? backwpup_destination_names($job['destinations'] ?? []) : [],
            'label' => (string) ($header['name'] ?? ''),
            'errors' => $errors,
            'warnings' => $warnings,
            'kind' => $archive === 'deleted' ? 'deleted' : 'job-run',
        ]),
        ['job_id' => $jobid],
        $archive !== null ? ['archive_verified' => $archive === 'present'] : [],
        $finished !== null ? ['finished_timestamp' => $finished, 'finished' => times($finished)] : [],
    );
}

/**
 * Whether a successful run's archive is still where the job keeps it: `present`, `deleted`, or
 * `unverified` when that cannot be checked cheaply (see the file comment).
 *
 * @param array<string, mixed>|null $job
 */
function backwpup_archive_state(?array $job, string $logfile): string
{
    if ($job === null || (string) ($job['backuptype'] ?? 'archive') !== 'archive') {
        return 'unverified';
    }
    $destinations = array_values(array_filter((array) ($job['destinations'] ?? []), 'is_string'));
    $folder = trim((string) ($job['backupdir'] ?? ''));
    if (!in_array('FOLDER', $destinations, true) || $folder === '' || $folder === '/' || !class_exists('BackWPup_File')) {
        return 'unverified';
    }
    $name = backwpup_archive_name($logfile);
    if ($name === null) {
        return 'unverified';
    }
    $dir = \BackWPup_File::get_absolute_path($folder);
    if (!is_string($dir) || $dir === '' || !is_dir($dir)) {
        // The folder itself is gone or was changed since: nothing here to say the archive is.
        return $destinations === ['FOLDER'] ? 'deleted' : 'unverified';
    }
    if (is_file(trailingslashit($dir) . $name)) {
        return 'present';
    }

    // Gone from the folder. With a remote destination as well, a copy may still exist there.
    return $destinations === ['FOLDER'] ? 'deleted' : 'unverified';
}

/**
 * The archive file name a run's log names, or null.
 *
 * BackWPup writes it into the log's opening block, "[INFO] Backup file is located at: …/<name>"
 * (BackWPup_Job::run(), with the folder shortened unless debugging). That sentence is translated
 * with the site's locale at run time, so the line is found by its shape - an [INFO] line ending
 * in an archive file name - not by its words. Only the opening block is read.
 */
function backwpup_archive_name(string $logfile): ?string
{
    $source = str_ends_with($logfile, '.gz') ? 'compress.zlib://' . $logfile : $logfile;
    $handle = @fopen($source, 'rb');
    if ($handle === false) {
        return null;
    }
    $head = fread($handle, 32768);
    fclose($handle);
    if (!is_string($head)) {
        return null;
    }
    $found = preg_match(
        '~\[INFO\][^<\n]*?([A-Za-z0-9][A-Za-z0-9._\-]*\.(?:zip|tar|tar\.gz|tar\.bz2))\s*(?:<|\n)~',
        $head,
        $match,
    );

    return $found === 1 ? $match[1] : null;
}

/**
 * BackWPup's log files, newest first, as its Logs screen orders them (by modification time).
 *
 * @return list<string>
 */
function backwpup_logfiles(int $limit): array
{
    $folder = \BackWPup_File::get_absolute_path((string) get_site_option('backwpup_cfg_logfolder'));
    if (!is_string($folder) || $folder === '' || !is_dir($folder) || !is_readable($folder)) {
        return [];
    }
    $files = [];
    foreach ((array) scandir($folder) as $name) {
        if (!is_string($name) || !str_starts_with($name, 'backwpup_log_') || !str_contains($name, '.html')) {
            continue;
        }
        $path = trailingslashit($folder) . $name;
        if (is_file($path) && is_readable($path)) {
            $files[$path] = (int) filemtime($path);
        }
    }
    arsort($files);

    return array_slice(array_keys($files), 0, max(0, $limit));
}

/**
 * @return list<array<string, mixed>>
 */
function backwpup_list(int $limit): array
{
    $jobs = backwpup_jobs();
    $working = backwpup_working();
    $records = [];
    foreach (backwpup_logfiles($limit) as $logfile) {
        $header = \BackWPup_Job::read_logheader($logfile);
        if (is_array($header) && (int) ($header['logtime'] ?? 0) > 0) {
            $records[] = backwpup_record($header, $jobs, $working, $logfile);
        }
    }

    return newest_first($records, $limit);
}

/**
 * @return array<string, mixed>
 */
function backwpup_status(): array
{
    $jobs = backwpup_jobs();
    $working = backwpup_working();
    $runs = [];
    foreach (backwpup_logfiles(50) as $logfile) {
        $header = \BackWPup_Job::read_logheader($logfile);
        if (is_array($header) && (int) ($header['logtime'] ?? 0) > 0) {
            $runs[] = backwpup_record($header, $jobs, $working, $logfile);
        }
    }
    $runs = newest_first($runs, 50);
    $success = null;
    foreach ($runs as $run) {
        // A run whose archive is gone is history, not a backup to fall back on.
        if ($run['result'] === 'success' && $run['kind'] !== 'deleted') {
            $success = $run;
            break;
        }
    }

    $next = [];
    $job_rows = [];
    $storage = [];
    $running = [];
    $offset = (int) round((float) get_option('gmt_offset') * HOUR_IN_SECONDS);
    foreach ($jobs as $id => $job) {
        $when = wp_next_scheduled('backwpup_cron', ['arg' => $id]);
        if (is_int($when)) {
            $next[] = array_merge(scheduled($when, (string) ($job['name'] ?? '')), ['job_id' => $id]);
            if ($when <= time()) {
                $running[] = ['state' => 'queued', 'job_id' => $id, 'job' => (string) ($job['name'] ?? '')];
            }
        }
        $names = backwpup_destination_names($job['destinations'] ?? []);
        $storage = array_merge($storage, $names);
        $job_rows[] = [
            'job_id' => $id,
            'name' => (string) ($job['name'] ?? ''),
            'contents' => backwpup_contents((array) ($job['type'] ?? []), $job),
            'storage' => $names,
            'start' => (string) ($job['activetype'] ?? '') === 'wpcron' ? 'wp-cron' : ((string) ($job['activetype'] ?? '') === 'link' ? 'external link' : 'manual'),
            'cron' => (string) ($job['activetype'] ?? '') === 'wpcron' ? (string) ($job['cron'] ?? '') : null,
            'can_trigger' => (string) ($job['activetype'] ?? '') === 'wpcron',
        ];
    }
    if ($working !== null) {
        $job = property_exists($working, 'job') && is_array($working->job) ? $working->job : [];
        // start_time is stored shifted by the UTC offset, like the job's lastrun.
        $started = property_exists($working, 'start_time') ? (int) $working->start_time - $offset : 0;
        $running[] = array_merge(
            ['state' => 'running', 'job_id' => (int) ($job['jobid'] ?? 0), 'job' => (string) ($job['name'] ?? '')],
            $started > 0 ? ['started' => times($started)] : [],
        );
    }
    usort($next, static fn(array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

    return [
        'version' => method_exists('BackWPup', 'get_plugin_data') ? (string) \BackWPup::get_plugin_data('Version') : null,
        'last_backup' => $runs[0] ?? null,
        'last_successful_backup' => $success,
        'running' => ['running' => $running !== [], 'jobs' => $running],
        'next_scheduled' => $next,
        'jobs' => $job_rows,
        'storage' => array_values(array_unique($storage)),
        'trigger' => [
            'supported' => true,
            'scopes' => [],
            'reason' => 'Runs one BackWPup job that is scheduled through WP-Cron (pass job_id when there are several); the job decides what is backed up.',
        ],
        'notes' => [
            'Each backup is one job run, read from BackWPup\'s logs; result "failed" means the run logged errors.',
            'A successful run is checked against its archive only when the job keeps archives in a local folder: kind "deleted" means that archive is gone, and archive_verified false means the archive went to remote storage and was not looked up.',
        ],
    ];
}
