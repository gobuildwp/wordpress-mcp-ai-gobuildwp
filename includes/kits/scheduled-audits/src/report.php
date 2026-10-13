<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ScheduledAudits;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * What a finished run leaves behind: a stored report, a comparison with the run before, and an
 * email to the routine's recipients.
 *
 * Only the newest run keeps its issue list, because the next run's comparison is all it is for;
 * older runs keep their summary and their comparison. That caps a routine's history at 20 small
 * records plus one issue list of at most MAX_ISSUES per audit.
 */

/** Runs kept per routine when its history is on. */
const HISTORY = 20;

/** Issues listed per kind (new, resolved, changed) in a comparison. */
const DIFF_LIST = 25;

/**
 * @param array<string, mixed> $routine
 * @param array<string, mixed> $run
 * @return array<string, mixed> The stored record.
 */
function finish_run(string $id, array $routine, array $run): array
{
    $steps = [];
    $issues = [];
    $done = 0;
    foreach ($run['steps'] as $step) {
        $steps[] = [
            'ability' => $step['ability'],
            'kind' => $step['kind'],
            'label' => $step['label'],
            'signature' => $step['signature'],
            'status' => $step['status'],
            'error' => $step['error'],
            'summary' => $step['summary'],
            'issue_count' => count($step['issues']),
            'truncated' => $step['truncated'] === true,
        ];
        $issues[] = $step['issues'];
        $done += $step['status'] === 'done' ? 1 : 0;
    }
    $status = $done === count($steps) ? 'done' : ($done === 0 ? 'failed' : 'partial');

    $runs = reports($id);
    $record = [
        'id' => $run['id'],
        'trigger' => $run['trigger'],
        'started_at' => (int) $run['started_at'],
        'finished_at' => time(),
        'status' => $status,
        'steps' => $steps,
        'issues' => $issues,
    ];
    $record['totals'] = totals($record);
    $record['diff'] = diff($runs[0] ?? null, $record);
    $record['email'] = send_email($id, $routine, $record);

    foreach ($runs as $index => $old) {
        unset($runs[$index]['issues']);
    }
    array_unshift($runs, $record);
    $keep = (($routine['delivery']['report'] ?? true) === true) ? HISTORY : 1;
    store_reports($id, array_slice($runs, 0, $keep));
    return $record;
}

/**
 * @param array<string, mixed> $record
 * @return array{issues: int, audits_done: int, audits_failed: int}
 */
function totals(array $record): array
{
    $total = 0;
    $done = 0;
    foreach ($record['steps'] as $step) {
        if ($step['status'] === 'done') {
            $total += (int) $step['issue_count'];
            $done++;
        }
    }
    return ['issues' => $total, 'audits_done' => $done, 'audits_failed' => count($record['steps']) - $done];
}

/**
 * Compare a run with the one before it, audit by audit.
 *
 * Only audits that finished in both runs, configured the same way, are compared: an audit that
 * failed this time has not had its issues resolved, and one that failed last time has not
 * gained all of its issues now. When either run hit the issue cap for an audit, its counts are
 * marked approximate rather than presented as exact.
 *
 * @param array<string, mixed>|null $previous
 * @param array<string, mixed> $current
 * @return array<string, mixed>|null Null for a routine's first run.
 */
function diff(?array $previous, array $current): ?array
{
    if ($previous === null || !is_array($previous['issues'] ?? null)) {
        return null;
    }
    $new = [];
    $resolved = [];
    $changed = [];
    $compared = [];
    $not_compared = [];
    $approximate = false;
    foreach ($current['steps'] as $index => $step) {
        $match = null;
        foreach ($previous['steps'] as $previous_index => $previous_step) {
            if ($previous_step['signature'] === $step['signature'] && $previous_step['status'] === 'done') {
                $match = $previous_index;
                break;
            }
        }
        if ($step['status'] !== 'done' || $match === null) {
            $not_compared[] = $step['label'];
            continue;
        }
        $compared[] = $step['label'];
        $approximate = $approximate || $step['truncated'] || ($previous['steps'][$match]['truncated'] ?? false) === true;
        $now = is_array($current['issues'][$index] ?? null) ? $current['issues'][$index] : [];
        $before = is_array($previous['issues'][$match] ?? null) ? $previous['issues'][$match] : [];
        foreach ($now as $key => $issue) {
            if (!isset($before[$key])) {
                $new[] = array_merge(['audit' => $step['label']], $issue);
            } elseif ((int) ($issue['count'] ?? 1) !== (int) ($before[$key]['count'] ?? 1)) {
                $changed[] = array_merge(['audit' => $step['label']], $issue, ['previous_count' => (int) ($before[$key]['count'] ?? 1)]);
            }
        }
        foreach ($before as $key => $issue) {
            if (!isset($now[$key])) {
                $resolved[] = array_merge(['audit' => $step['label']], $issue);
            }
        }
    }
    return [
        'compared_with' => (string) ($previous['id'] ?? ''),
        'previous_finished_at' => (int) ($previous['finished_at'] ?? 0),
        'new_count' => count($new),
        'resolved_count' => count($resolved),
        'changed_count' => count($changed),
        'new' => array_slice($new, 0, DIFF_LIST),
        'resolved' => array_slice($resolved, 0, DIFF_LIST),
        'changed' => array_slice($changed, 0, DIFF_LIST),
        'compared' => $compared,
        'not_compared' => $not_compared,
        'approximate' => $approximate,
    ];
}

/**
 * Where a person reads the report. The host that owns an admin screen for routines supplies it.
 */
function report_url(string $id): string
{
    /** @var mixed $url */
    $url = apply_filters('wppilot_kit_routines_report_url', admin_url('admin.php'), $id);
    return is_string($url) ? $url : admin_url('admin.php');
}

/**
 * Email the run's summary to each recipient separately, so no recipient sees another's address.
 *
 * @param array<string, mixed> $routine
 * @param array<string, mixed> $record
 * @return array{recipients: int, sent: int, failed: int, skipped: int}
 */
function send_email(string $id, array $routine, array $record): array
{
    $ids = is_array($routine['delivery']['email_user_ids'] ?? null) ? $routine['delivery']['email_user_ids'] : [];
    $outcome = ['recipients' => count($ids), 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    if ($ids === []) {
        return $outcome;
    }
    [$subject, $body] = email_text($id, $routine, $record);
    foreach ($ids as $user_id) {
        // Checked again at send time: someone demoted since the routine was saved no longer
        // receives the site's audit results.
        $user = get_userdata((int) $user_id);
        if (!$user instanceof \WP_User || !is_admin_user((int) $user_id) || !is_email($user->user_email)) {
            $outcome['skipped']++;
            continue;
        }
        if (wp_mail($user->user_email, $subject, $body)) {
            $outcome['sent']++;
        } else {
            $outcome['failed']++;
        }
    }
    return $outcome;
}

/**
 * A short plain-text summary: counts and what changed, never page content, post titles, markup
 * or anyone's details.
 *
 * @param array<string, mixed> $routine
 * @param array<string, mixed> $record
 * @return array{0: string, 1: string}
 */
function email_text(string $id, array $routine, array $record): array
{
    $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
    $label = (string) ($routine['label'] ?? $id);
    $diff = is_array($record['diff'] ?? null) ? $record['diff'] : null;
    $total = (int) $record['totals']['issues'];

    if ($record['status'] === 'failed') {
        $headline = 'the run failed';
    } elseif ($diff === null) {
        $headline = sprintf('%d issue%s found (first run)', $total, $total === 1 ? '' : 's');
    } else {
        $headline = sprintf('%d new, %d resolved, %d open', (int) $diff['new_count'], (int) $diff['resolved_count'], $total);
    }
    $subject = sprintf('[%s] Routine "%s": %s', $site, $label, $headline);

    $lines = [
        sprintf(
            'The routine "%s" %s at %s.',
            $label,
            $record['trigger'] === 'manual' ? 'was run on request and finished' : 'ran on schedule and finished',
            wp_date('Y-m-d H:i', (int) $record['finished_at']) . ' ' . wp_timezone_string(),
        ),
        '',
    ];
    foreach ($record['steps'] as $step) {
        $lines[] = '- ' . $step['label'] . ': ' . step_line($step);
    }
    $lines[] = '';
    if ($diff === null) {
        $lines[] = 'This is the first run; later emails say what changed since the one before.';
    } else {
        $lines[] = sprintf(
            'Since the run of %s: %d new issue%s, %d resolved, %d changed in count%s.',
            wp_date('Y-m-d H:i', (int) $diff['previous_finished_at']),
            (int) $diff['new_count'],
            (int) $diff['new_count'] === 1 ? '' : 's',
            (int) $diff['resolved_count'],
            (int) $diff['changed_count'],
            $diff['approximate'] === true ? ' (approximate: an audit had more issues than a report keeps)' : '',
        );
        if ($diff['not_compared'] !== []) {
            $lines[] = 'Not compared (failed, or new since the last run): ' . implode('; ', $diff['not_compared']) . '.';
        }
    }
    $lines[] = '';
    $lines[] = 'Full report: ' . report_url($id);
    $lines[] = '';
    $lines[] = 'Routines only read the site; this run changed nothing.';

    return [$subject, implode("\n", $lines)];
}

/**
 * @param array<string, mixed> $step
 */
function step_line(array $step): string
{
    if ($step['status'] !== 'done') {
        return 'did not finish (' . mb_substr((string) $step['error'], 0, 160) . ')';
    }
    $summary = is_array($step['summary']) ? $step['summary'] : [];
    if ($step['kind'] === 'accessibility') {
        return sprintf(
            '%d page%s checked, average score %s, %d rule failure%s%s',
            (int) $summary['pages_checked'],
            (int) $summary['pages_checked'] === 1 ? '' : 's',
            $summary['average_score'] === null ? 'n/a' : (string) $summary['average_score'],
            (int) $summary['rules_failing'],
            (int) $summary['rules_failing'] === 1 ? '' : 's',
            severity_suffix(is_array($summary['by_severity'] ?? null) ? $summary['by_severity'] : []),
        );
    }
    if ($step['kind'] === 'content') {
        return sprintf(
            '%d finding%s across %d posts%s',
            (int) $summary['findings'],
            (int) $summary['findings'] === 1 ? '' : 's',
            (int) $summary['posts_scanned'],
            severity_suffix(is_array($summary['by_severity'] ?? null) ? $summary['by_severity'] : []),
        );
    }
    $counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
    return sprintf(
        '%d image%s need alt text (%d missing, %d just a file name) of %d scanned%s',
        (int) ($summary['needs_alt'] ?? 0),
        (int) ($summary['needs_alt'] ?? 0) === 1 ? '' : 's',
        (int) ($counts['missing'] ?? 0),
        (int) ($counts['filename'] ?? 0),
        (int) ($summary['images_scanned'] ?? 0),
        $step['truncated'] ? ' (library larger than one run scans)' : '',
    );
}

/**
 * @param array<string, mixed> $by_severity
 */
function severity_suffix(array $by_severity): string
{
    $parts = [];
    foreach (['critical', 'high', 'serious', 'medium', 'moderate', 'low', 'minor', 'info'] as $severity) {
        if ((int) ($by_severity[$severity] ?? 0) > 0) {
            $parts[] = (int) $by_severity[$severity] . ' ' . $severity;
        }
    }
    return $parts === [] ? '' : ' (' . implode(', ', $parts) . ')';
}

/**
 * One routine as wppilot/routines-list returns it.
 *
 * @param array<string, mixed> $routine
 * @return array<string, mixed>
 */
function view_routine(string $id, array $routine): array
{
    $state = state($id);
    $schedule = is_array($routine['schedule'] ?? null) ? $routine['schedule'] : [];
    $run = is_array($state['run'] ?? null) ? $state['run'] : null;
    $enabled = ($routine['enabled'] ?? true) === true;
    $last = is_array($state['last_run'] ?? null) ? $state['last_run'] : null;
    return [
        'id' => $id,
        'label' => (string) ($routine['label'] ?? ''),
        'enabled' => $enabled,
        'audits' => array_values(is_array($routine['audits'] ?? null) ? $routine['audits'] : []),
        'schedule' => array_merge($schedule, ['description' => describe_schedule($schedule), 'timezone' => wp_timezone_string()]),
        'delivery' => $routine['delivery'] ?? ['email_user_ids' => [], 'report' => true],
        'run_as' => (int) ($routine['run_as'] ?? 0),
        'next_run' => $enabled ? time_view(isset($state['next_run']) ? (int) $state['next_run'] : null) : null,
        'running' => $run === null ? null : [
            'trigger' => $run['trigger'],
            'started' => time_view((int) $run['started_at']),
            'audits' => array_map(
                static fn(array $step): array => ['label' => $step['label'], 'status' => $step['status']],
                $run['steps'],
            ),
        ],
        'run_now_queued' => ($state['manual_pending'] ?? false) === true,
        'last_run' => $last === null ? null : array_merge($last, ['finished' => time_view((int) $last['finished_at'])]),
    ];
}

/**
 * Stored runs as wppilot/routines-report returns them.
 *
 * @return array<string, mixed>|\WP_Error
 */
function report_view(string $id, int $limit, bool $include_issues): array|\WP_Error
{
    $routine = get_routine($id);
    if ($routine === null) {
        return new \WP_Error('kit_routines_not_found', sprintf('No routine has the id "%s"; see wppilot/routines-list.', $id), ['status' => 404]);
    }
    $runs = reports($id);
    $views = [];
    foreach (array_slice($runs, 0, max(1, min(HISTORY, $limit))) as $index => $run) {
        $view = [
            'id' => $run['id'],
            'trigger' => $run['trigger'],
            'started' => time_view((int) $run['started_at']),
            'finished' => time_view((int) $run['finished_at']),
            'status' => $run['status'],
            'totals' => $run['totals'],
            'audits' => array_map(static function (array $step): array {
                unset($step['signature']);
                return $step;
            }, $run['steps']),
            'diff' => $run['diff'],
            'email' => $run['email'],
        ];
        if ($index === 0 && $include_issues && is_array($run['issues'] ?? null)) {
            $view['issues'] = [];
            foreach ($run['steps'] as $step_index => $step) {
                $view['issues'][] = [
                    'audit' => $step['label'],
                    'truncated' => $step['truncated'],
                    'issues' => array_values(is_array($run['issues'][$step_index] ?? null) ? $run['issues'][$step_index] : []),
                ];
            }
        }
        $views[] = $view;
    }
    return [
        'routine_id' => $id,
        'label' => (string) ($routine['label'] ?? ''),
        'history' => (($routine['delivery']['report'] ?? true) === true) ? sprintf('kept (last %d runs)', HISTORY) : 'off (latest run only)',
        'total_runs' => count($runs),
        'runs' => $views,
        'report_url' => report_url($id),
    ];
}
