<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\SteppableJobs;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Scan jobs on the kit runtime's job runner: one open job per kind, resumable when a scan stopped
 * at its time limit, and steppable by the caller ("Cloud-pumped"), so a scan finishes on sites
 * where WP-Cron does not run.
 *
 * The kit keeps a small index of its jobs (id, kind, results token, scope) so it can find the
 * open one and delete the result pages of jobs the runner has forgotten.
 */

const STATES = ['queued', 'running', 'done', 'incomplete', 'failed', 'cancelled'];
const RESULT_RETENTION = 7 * DAY_IN_SECONDS;

function kind_label(string $kind): string
{
    return $kind === KIND_DB ? 'db' : 'integrity';
}

/**
 * One step of a scan job. The runner calls it with the job's payload and saved state.
 *
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function step(string $kind, array $payload, array $state): array
{
    if (!isset($state['coverage'])) {
        $state += [
            'phase' => 'starting',
            'started_at' => time(),
            'finished_at' => null,
            'elapsed' => 0.0,
            'outcome' => '',
            'error' => '',
            'files_checked' => 0,
            'files_skipped' => 0,
            'bytes_read' => 0,
            'checksum_fetches' => 0,
            'rows_checked' => 0,
            'results' => 0,
            'delay' => 0,
            'not_before' => 0,
            'progress' => 0.0,
        ];
        if ($kind === KIND_DB) {
            db_init($payload, $state);
        } else {
            files_init($payload, $state);
        }
    }
    if (updates_running()) {
        $state['phase'] = 'waiting_for_updates';
        return ['state' => $state, 'progress' => $state['progress'], 'message' => 'Waiting for an update to finish.', 'yield' => true];
    }
    if (time() < (int) $state['not_before']) {
        return ['state' => $state, 'progress' => $state['progress'], 'message' => 'Paused while the server is busy.', 'yield' => true];
    }
    $limits = budgets();
    $seconds = $kind === KIND_DB ? $limits['db_seconds'] : $limits['seconds'];
    $budget = new Budget($limits, $seconds);
    $out = new Collector($state);
    $began = microtime(true);
    $finished = $kind === KIND_DB ? db_run($payload, $state, $budget, $out) : files_run($payload, $state, $budget, $out);
    $took = microtime(true) - $began;
    $state['elapsed'] = (float) $state['elapsed'] + $took;
    $state['checksum_fetches'] = (int) $state['checksum_fetches'] + $budget->fetches;
    $out->flush((string) ($payload['token'] ?? ''));
    $state['progress'] = $finished ? 1.0 : ($kind === KIND_DB ? db_progress($state) : files_progress($state));

    // Back off when a step ran long or the server is loaded; otherwise run at the runner's pace.
    if (!$finished && ($took > $seconds * 1.25 || server_busy())) {
        $state['delay'] = min(MAX_DELAY, max(5, (int) $state['delay'] * 2));
        $state['not_before'] = time() + (int) $state['delay'];
    } else {
        $state['delay'] = 0;
        $state['not_before'] = 0;
    }

    if ($finished) {
        $state['finished_at'] = time();
        return ['state' => $state, 'progress' => 1.0, 'message' => '', 'done' => true];
    }
    if ($state['elapsed'] >= $limits['job_seconds']) {
        // Kept as it stands: a later start with the same scope resumes from this cursor.
        $state['outcome'] = 'incomplete';
        $state['finished_at'] = time();
        $state['error'] = sprintf('Stopped after %d minutes of scanning; start the same scan again to continue.', intdiv($limits['job_seconds'], 60));
        return ['state' => $state, 'progress' => $state['progress'], 'message' => $state['error'], 'done' => true];
    }
    return ['state' => $state, 'progress' => $state['progress'], 'message' => ''];
}

/** @param array<string, mixed> $job */
function job_state(array $job): string
{
    $status = (string) ($job['status'] ?? 'queued');
    if ($status === 'done' && (($job['state']['outcome'] ?? '') === 'incomplete')) {
        return 'incomplete';
    }
    return in_array($status, STATES, strict: true) ? $status : 'queued';
}

/**
 * @param array<string, mixed> $entry
 * @param array<string, mixed>|null $job
 * @return array{job_id: string, kind: string, state: string, reused: bool}
 */
function job_ref(array $entry, ?array $job, bool $reused): array
{
    return [
        'job_id' => (string) $entry['job_id'],
        'kind' => kind_label((string) $entry['kind']),
        'state' => $job === null ? 'failed' : job_state($job),
        'reused' => $reused,
    ];
}

/** @return list<array<string, mixed>> */
function index(): array
{
    wp_cache_delete(INDEX_OPTION, 'options');
    /** @var mixed $index */
    $index = get_option(INDEX_OPTION, []);
    return is_array($index) ? array_values(array_filter($index, static fn(mixed $e): bool => is_array($e) && isset($e['job_id'], $e['kind'], $e['token']))) : [];
}

/** @param list<array<string, mixed>> $index */
function save_index(array $index): void
{
    update_option(INDEX_OPTION, array_values($index), false);
}

/**
 * Drop jobs the runner no longer has, and finished jobs older than the retention period, with
 * their result pages.
 *
 * @return list<array<string, mixed>>
 */
function prune(Runtime\Jobs $jobs): array
{
    $kept = [];
    $changed = false;
    foreach (index() as $entry) {
        $job = $jobs->get((string) $entry['job_id']);
        $finished = $job !== null && !in_array(job_state($job), ['queued', 'running'], strict: true);
        if ($job === null || ($finished && (int) ($job['updated_at'] ?? 0) < time() - RESULT_RETENTION)) {
            delete_results((string) $entry['token']);
            $changed = true;
            continue;
        }
        $kept[] = $entry;
    }
    if ($changed) {
        save_index($kept);
    }
    return $kept;
}

/** @return array<string, mixed>|null */
function find_entry(string $job_id, string $kind): ?array
{
    foreach (index() as $entry) {
        if ($entry['job_id'] === $job_id && $entry['kind'] === $kind) {
            return $entry;
        }
    }
    return null;
}

/**
 * Starting is serialised across all scan kinds, because every kind rewrites the same index
 * option: add_option() is an INSERT on a unique key, so one request at a time gets past it, and a
 * stale mutex from a crashed request is contested again after 30 s. A start that finds it held
 * waits a few seconds (an integrity and a DB scan are often asked for together) before giving up.
 */
function take_start_mutex(): bool
{
    $key = START_MUTEX_PREFIX . 'any';
    $give_up = microtime(true) + START_MUTEX_WAIT;
    do {
        if (add_option($key, time() + 30, '', false)) {
            return true;
        }
        wp_cache_delete($key, 'options');
        if ((int) get_option($key, 0) <= time()) {
            delete_option($key);
            if (add_option($key, time() + 30, '', false)) {
                return true;
            }
        }
        usleep(100_000);
    } while (microtime(true) < $give_up);
    return false;
}

function release_start_mutex(): void
{
    delete_option(START_MUTEX_PREFIX . 'any');
}

/**
 * Start a scan, or return the open one of the same kind (reused), or resume an incomplete one
 * asked for again with the same scope.
 *
 * @param array<string, mixed> $request scope and baseline, or mode
 * @return array<string, mixed>|WP_Error
 */
function start(string $kind, array $request): array|WP_Error
{
    $jobs = Runtime\host()->jobs();
    if (!take_start_mutex()) {
        return new WP_Error('kit_security_integrity_scan_starting', 'Another scan is being started; try again in a moment.', ['status' => 409]);
    }
    try {
        $index = prune($jobs);
        $incomplete = null;
        foreach (array_reverse($index) as $entry) {
            if ($entry['kind'] !== $kind) {
                continue;
            }
            $job = $jobs->get((string) $entry['job_id']);
            if ($job === null) {
                continue;
            }
            $state = job_state($job);
            if ($state === 'queued' || $state === 'running') {
                return job_ref($entry, $job, true);
            }
            if ($incomplete === null && $state === 'incomplete' && ($entry['request'] ?? null) == $request) {
                $incomplete = [$entry, $job];
            }
        }
        if ($incomplete !== null && $jobs instanceof SteppableJobs) {
            [$entry, $job] = $incomplete;
            $saved = is_array($job['state'] ?? null) ? $job['state'] : [];
            $saved['outcome'] = '';
            $saved['error'] = '';
            $saved['elapsed'] = 0.0;
            $saved['finished_at'] = null;
            $saved['not_before'] = 0;
            if ($jobs->resume((string) $entry['job_id'], $saved)) {
                return job_ref($entry, $jobs->get((string) $entry['job_id']), true);
            }
        }
        $token = bin2hex(random_bytes(8));
        $id = $jobs->enqueue($kind, $request + ['token' => $token]);
        if ($id instanceof WP_Error) {
            return $id;
        }
        $entry = ['job_id' => $id, 'kind' => $kind, 'token' => $token, 'request' => $request, 'created_at' => time()];
        $index[] = $entry;
        save_index($index);
        return job_ref($entry, $jobs->get($id), false);
    } finally {
        release_start_mutex();
    }
}

function not_found(): WP_Error
{
    return new WP_Error('kit_security_integrity_scan_not_found', 'No scan with that job_id.', ['status' => 404]);
}

/**
 * A scan's status. With $advance, one step runs first when the job is waiting and no other
 * request is stepping it.
 *
 * @return array<string, mixed>|WP_Error
 */
function status(string $kind, string $job_id, bool $advance): array|WP_Error
{
    $entry = find_entry($job_id, $kind);
    if ($entry === null) {
        return not_found();
    }
    $jobs = Runtime\host()->jobs();
    $job = $advance && $jobs instanceof SteppableJobs ? $jobs->advance($job_id) : $jobs->get($job_id);
    if ($job === null) {
        return not_found();
    }
    return status_of($entry, $job);
}

/**
 * @param array<string, mixed> $entry
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function status_of(array $entry, array $job): array
{
    $kind = (string) $entry['kind'];
    $state = is_array($job['state'] ?? null) ? $job['state'] : [];
    $job_state = job_state($job);
    $request = is_array($entry['request'] ?? null) ? $entry['request'] : [];
    $coverage = [];
    foreach ($kind === KIND_DB ? DB_AREAS : FILE_AREAS as $area) {
        $row = is_array($state['coverage'][$area] ?? null) ? $state['coverage'][$area] : ['state' => 'pending', 'checked' => 0, 'skipped' => 0, 'reason' => ''];
        $area_state = (string) $row['state'];
        $reason = (string) ($row['reason'] ?? '');
        if ($area_state === 'pending') {
            // Not finished: partial when it was started, skipped when the scan has not got to it.
            $area_state = (int) $row['checked'] > 0 || !empty($row['degraded']) ? 'partial' : 'skipped';
            if ($reason === '') {
                $reason = in_array($job_state, ['queued', 'running'], strict: true) ? 'Not finished yet.' : 'The scan stopped before finishing this area.';
            }
        }
        $coverage[$area] = ['state' => $area_state, 'checked' => (int) $row['checked'], 'skipped' => (int) $row['skipped'], 'reason' => $reason];
        // Themes: how many were compared with WordPress.org's files, and how many had nothing to compare with.
        foreach (['compared', 'unverified'] as $count) {
            if (isset($row[$count])) {
                $coverage[$area][$count] = (int) $row[$count];
            }
        }
    }
    $error = '';
    if ($job_state === 'failed') {
        $error = public_error((string) ($job['message'] ?? ''));
    } elseif ($job_state === 'incomplete') {
        $error = (string) ($state['error'] ?? '');
    }
    return [
        'job_id' => (string) $entry['job_id'],
        'kind' => kind_label($kind),
        'state' => $job_state,
        'scope' => $kind === KIND_DB ? (string) ($request['mode'] ?? 'full') : (string) ($request['scope'] ?? 'all'),
        'started_at' => isset($state['started_at']) ? (int) $state['started_at'] : null,
        'finished_at' => isset($state['finished_at']) ? (int) $state['finished_at'] : null,
        'progress' => [
            'phase' => (string) ($state['phase'] ?? 'queued'),
            'done' => round((float) ($job_state === 'done' ? 1.0 : ($state['progress'] ?? 0.0)), 3),
            'files_checked' => (int) ($state['files_checked'] ?? 0),
            'files_skipped' => (int) ($state['files_skipped'] ?? 0),
            'bytes_read' => (int) ($state['bytes_read'] ?? 0),
            'checksum_fetches' => (int) ($state['checksum_fetches'] ?? 0),
            'rows_checked' => (int) ($state['rows_checked'] ?? 0),
        ],
        'coverage' => $coverage,
        'results_count' => (int) ($state['results'] ?? 0),
        'error' => $error,
        'env' => [
            'wp_version' => wp_version(),
            'locale' => (string) get_locale(),
            'php_version' => PHP_VERSION,
            'cron_disabled' => defined('DISABLE_WP_CRON') && (bool) constant('DISABLE_WP_CRON'),
        ],
    ];
}

/** A failure message without server paths. */
function public_error(string $message): string
{
    $message = str_replace([normalize(ABSPATH) . '/', rtrim(ABSPATH, '/\\') . DIRECTORY_SEPARATOR], '', $message);
    $message = (string) preg_replace('#(?:[A-Za-z]:)?[\\\\/][^\s:"\']*\.php#', '[file]', $message);
    return mb_substr($message !== '' ? $message : 'The scan failed.', 0, 300);
}

/**
 * @return array{items: list<array<string, mixed>>, next_cursor: int|null, total: int}|WP_Error
 */
function results(string $kind, string $job_id, int $cursor): array|WP_Error
{
    $entry = find_entry($job_id, $kind);
    $job = $entry === null ? null : Runtime\host()->jobs()->get($job_id);
    if ($entry === null || $job === null) {
        return not_found();
    }
    $total = (int) ($job['state']['results'] ?? 0);
    $cursor = max(0, $cursor);
    $items = $cursor < $total ? read_results((string) $entry['token'], $cursor, $total) : [];
    $next = $cursor + count($items);
    return ['items' => $items, 'next_cursor' => $items !== [] && $next < $total ? $next : null, 'total' => $total];
}

/**
 * @return array<string, mixed>|WP_Error
 */
function cancel(string $kind, string $job_id): array|WP_Error
{
    $entry = find_entry($job_id, $kind);
    if ($entry === null) {
        return not_found();
    }
    $jobs = Runtime\host()->jobs();
    $jobs->cancel($job_id);
    $job = $jobs->get($job_id);
    return $job === null ? not_found() : job_ref($entry, $job, false);
}
