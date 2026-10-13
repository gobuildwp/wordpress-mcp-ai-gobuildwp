<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ScheduledAudits;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Running a routine: one WP-Cron tick at a time, as the routine's user, through the host's gates.
 *
 * A run is a list of steps, one per audit. A tick claims the routine, advances its steps for up
 * to STEP_BUDGET seconds and saves where it got to, so nothing blocks a request: the content
 * audit is a background job of free's own, and the step that started it only asks its status on
 * each later tick until it is done. Every audit is called through Runtime\run_ability(), which
 * inside GoBuildWP is the gate pipeline — safety profile, Abilities Hub switches and every
 * wppilot_pre_ability_execute control — and then the ability's own permission check, all for the
 * user the routine runs as, because WP-Cron itself runs as nobody.
 */

/** A run still unfinished after this long is closed, with its unfinished steps failed. */
const RUN_TIMEOUT = 10800;

/** At most this many media library pages (of per_page images) are scanned per run. */
const MEDIA_MAX_PAGES = 10;

/** Issues kept per step for the comparison with the next run. */
const MAX_ISSUES = 300;

/** Findings read per audit-content-status call. */
const CONTENT_PAGE = 500;

/** Seconds between two run-now requests for one routine. */
const RUN_NOW_COOLDOWN = 600;

/**
 * The HOOK callback.
 */
function tick(mixed $id = ''): void
{
    $id = is_string($id) ? $id : '';
    if ($id === '') {
        return;
    }
    $routine = get_routine($id);
    if ($routine === null) {
        forget($id);
        return;
    }
    if (!claim($id)) {
        // Another tick has it; make sure one follows after it lets go.
        if (wp_next_scheduled(HOOK, [$id]) === false) {
            schedule_event($id, time() + POLL_DELAY);
        }
        return;
    }
    try {
        step_routine($id, $routine, microtime(true) + STEP_BUDGET);
    } finally {
        release($id);
    }
}

/**
 * Start a run if one is due or was asked for, advance it, and finish it when every step is done.
 *
 * @param array<string, mixed> $routine
 */
function step_routine(string $id, array $routine, float $deadline): void
{
    $state = state($id);
    $now = time();
    $enabled = ($routine['enabled'] ?? true) === true && is_array($routine['schedule'] ?? null);
    if ($enabled && (int) ($state['next_run'] ?? 0) <= 0) {
        $state['next_run'] = next_run($routine['schedule'], $now);
        $state['schedule_key'] = hash('sha256', (string) wp_json_encode($routine['schedule']));
    }

    $run = is_array($state['run'] ?? null) ? $state['run'] : null;
    if ($run === null) {
        $manual = ($state['manual_pending'] ?? false) === true;
        $due = $enabled && (int) $state['next_run'] <= $now;
        if (!$manual && !$due) {
            save_state($id, $state);
            if ($enabled && wp_next_scheduled(HOOK, [$id]) !== (int) $state['next_run']) {
                schedule_event($id, (int) $state['next_run']);
            }
            return;
        }
        $run = start_run($routine, $manual ? 'manual' : 'schedule', $now);
        $state['manual_pending'] = false;
        if ($due) {
            // Moved on now, not when the run ends, so a slow run is never started twice.
            $state['next_run'] = next_run($routine['schedule'], $now);
        }
    }

    $run = advance($run, $deadline);
    if (!finished($run)) {
        $state['run'] = $run;
        save_state($id, $state);
        schedule_event($id, time() + (waiting_only($run) ? POLL_DELAY : 5));
        return;
    }

    $record = finish_run($id, $routine, $run);
    $state['run'] = null;
    $state['last_run'] = [
        'id' => $record['id'],
        'trigger' => $record['trigger'],
        'finished_at' => $record['finished_at'],
        'status' => $record['status'],
        'totals' => $record['totals'],
        'new' => is_array($record['diff']) ? (int) $record['diff']['new_count'] : null,
        'resolved' => is_array($record['diff']) ? (int) $record['diff']['resolved_count'] : null,
    ];
    save_state($id, $state);
    if ($enabled) {
        schedule_event($id, max(time(), (int) $state['next_run']));
    } else {
        wp_clear_scheduled_hook(HOOK, [$id]);
    }
}

/**
 * Write a tick's state without losing a run-now that arrived while the tick worked: the run-now
 * request writes the same option, and the tick read it before that.
 *
 * @param array<string, mixed> $state
 */
function save_state(string $id, array $state): void
{
    $fresh = state($id);
    if ((int) ($fresh['manual_requested_at'] ?? 0) > (int) ($state['manual_requested_at'] ?? 0)) {
        $state['manual_requested_at'] = $fresh['manual_requested_at'];
        $state['manual_pending'] = ($fresh['manual_pending'] ?? false) === true;
    }
    set_state($id, $state);
}

/**
 * @param array<string, mixed> $routine
 * @return array<string, mixed>
 */
function start_run(array $routine, string $trigger, int $now): array
{
    $steps = [];
    foreach (is_array($routine['audits'] ?? null) ? $routine['audits'] : [] as $audit) {
        $steps[] = build_step(is_array($audit) ? $audit : []);
    }
    return [
        'id' => wp_generate_uuid4(),
        'trigger' => $trigger,
        'started_at' => $now,
        'run_as' => (int) ($routine['run_as'] ?? 0),
        'steps' => $steps,
    ];
}

/**
 * @param array<string, mixed> $audit
 * @return array<string, mixed>
 */
function build_step(array $audit): array
{
    $name = (string) ($audit['ability'] ?? '');
    $kind = AUDITS[$name] ?? 'unknown';
    $input = is_array($audit['input'] ?? null) ? $audit['input'] : [];
    $step = [
        'ability' => $name,
        'kind' => $kind,
        'label' => step_label($kind, $audit),
        // Two runs' results are compared step by step only where the audit was configured the
        // same way; an edited routine's changed audit starts a fresh baseline.
        'signature' => hash('sha256', (string) wp_json_encode([$name, $input, $audit['urls'] ?? [], $audit['front_page'] ?? false, $audit['top_pages'] ?? 0])),
        'input' => $input,
        'status' => 'pending',
        'cursor' => 0,
        'issues' => [],
        'truncated' => false,
        'summary' => [],
        'error' => '',
    ];
    if ($kind === 'accessibility') {
        $step['targets'] = resolve_targets($audit);
        $step['pages'] = [];
    }
    if ($kind === 'content') {
        $step['job_id'] = '';
    }
    return $step;
}

/**
 * @param array<string, mixed> $audit
 */
function step_label(string $kind, array $audit): string
{
    if ($kind === 'accessibility') {
        $parts = [];
        if (($audit['front_page'] ?? false) === true) {
            $parts[] = 'front page';
        }
        foreach (is_array($audit['urls'] ?? null) ? $audit['urls'] : [] as $path) {
            $parts[] = (string) $path;
        }
        $top = (int) ($audit['top_pages'] ?? 0);
        if ($top > 0) {
            $parts[] = sprintf('%d top page%s', $top, $top === 1 ? '' : 's');
        }
        return 'Accessibility (' . implode(', ', $parts) . ')';
    }
    if ($kind === 'content') {
        $checks = is_array($audit['input']['checks'] ?? null) ? $audit['input']['checks'] : [];
        return $checks === [] ? 'Content audit' : 'Content audit (' . implode(', ', array_map('strval', $checks)) . ')';
    }
    return 'Media alt text';
}

/**
 * The paths an accessibility step checks, fixed when the run starts.
 *
 * @param array<string, mixed> $audit
 * @return list<string>
 */
function resolve_targets(array $audit): array
{
    $paths = [];
    if (($audit['front_page'] ?? false) === true) {
        $paths[] = site_path(home_url('/')) ?? '/';
    }
    foreach (is_array($audit['urls'] ?? null) ? $audit['urls'] : [] as $path) {
        $paths[] = (string) $path;
    }
    $top = (int) ($audit['top_pages'] ?? 0);
    if ($top > 0) {
        $paths = array_merge($paths, top_page_paths($top, $paths));
    }
    return array_slice(array_values(array_unique($paths)), 0, MAX_URLS);
}

/**
 * "Top pages": pages linked from the site's classic menus, in menu order, then published pages
 * by menu order and title. There is no traffic data to rank by; a menu is the owner's own
 * statement of which pages matter.
 *
 * @param list<string> $exclude
 * @return list<string>
 */
function top_page_paths(int $count, array $exclude): array
{
    $ids = [];
    $locations = function_exists('get_nav_menu_locations') ? get_nav_menu_locations() : [];
    foreach (is_array($locations) ? $locations : [] as $menu_id) {
        $items = wp_get_nav_menu_items((int) $menu_id);
        foreach (is_array($items) ? $items : [] as $item) {
            if (($item->type ?? '') === 'post_type' && ($item->object ?? '') === 'page') {
                $ids[] = (int) $item->object_id;
            }
        }
    }
    $pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'numberposts' => $count * 2 + 2,
        'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
        'fields' => 'ids',
        'suppress_filters' => false,
    ]);
    $ids = array_merge($ids, array_map('intval', is_array($pages) ? $pages : []));

    $paths = [];
    foreach (array_unique($ids) as $post_id) {
        if (count($paths) >= $count) {
            break;
        }
        if (get_post_status($post_id) !== 'publish') {
            continue;
        }
        $link = get_permalink($post_id);
        $path = is_string($link) ? site_path($link) : null;
        if ($path !== null && !in_array($path, $exclude, true) && !in_array($path, $paths, true)) {
            $paths[] = $path;
        }
    }
    return $paths;
}

/**
 * A stored path as an absolute URL on this site's current origin.
 */
function absolute_url(string $path): string
{
    $home = wp_parse_url(home_url('/'));
    $origin = (string) ($home['scheme'] ?? 'https') . '://' . (string) ($home['host'] ?? '');
    if (isset($home['port'])) {
        $origin .= ':' . (int) $home['port'];
    }
    return $origin . $path;
}

/**
 * @param array<string, mixed> $run
 */
function finished(array $run): bool
{
    foreach ($run['steps'] as $step) {
        if (!in_array($step['status'], ['done', 'failed'], true)) {
            return false;
        }
    }
    return true;
}

/**
 * Whether the only unfinished work is waiting on a background job, so the next tick can wait.
 *
 * @param array<string, mixed> $run
 */
function waiting_only(array $run): bool
{
    foreach ($run['steps'] as $step) {
        if (in_array($step['status'], ['pending', 'running'], true)) {
            return false;
        }
    }
    return true;
}

/**
 * @param array<string, mixed> $run
 * @return array<string, mixed>
 */
function advance(array $run, float $deadline): array
{
    $user_id = (int) ($run['run_as'] ?? 0);
    if (!is_admin_user($user_id)) {
        return fail_unfinished($run, 'The user this routine runs as no longer exists or is no longer an administrator; set run_as with wppilot/routines-save.');
    }
    if (time() - (int) $run['started_at'] > RUN_TIMEOUT) {
        return fail_unfinished($run, 'The run did not finish within 3 hours. Check that WP-Cron runs on this site.');
    }

    $previous_user = get_current_user_id();
    wp_set_current_user($user_id);
    try {
        foreach ($run['steps'] as $index => $step) {
            if (in_array($step['status'], ['done', 'failed'], true)) {
                continue;
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                $run['steps'][$index] = match ($step['kind']) {
                    'accessibility' => advance_accessibility($step, $deadline),
                    'content' => advance_content($step, $deadline),
                    'media-alt' => advance_media($step, $deadline),
                    default => array_merge($step, ['status' => 'failed', 'error' => 'This audit is not one a routine can run.']),
                };
            } catch (\Throwable $error) {
                // One broken audit must not stop the others, or the run from being reported.
                $run['steps'][$index] = array_merge($step, ['status' => 'failed', 'error' => mb_substr($error->getMessage(), 0, 300)]);
            }
        }
    } finally {
        wp_set_current_user($previous_user);
    }
    return $run;
}

/**
 * @param array<string, mixed> $run
 * @return array<string, mixed>
 */
function fail_unfinished(array $run, string $reason): array
{
    foreach ($run['steps'] as $index => $step) {
        if (!in_array($step['status'], ['done', 'failed'], true)) {
            $run['steps'][$index]['status'] = 'failed';
            $run['steps'][$index]['error'] = $reason;
        }
    }
    return $run;
}

/**
 * Call one audit through the host's gates, refusing anything that is not read-only.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function call_audit(string $name, array $input): array|WP_Error
{
    $ability = wp_get_ability($name);
    if (!$ability instanceof \WP_Ability) {
        return new WP_Error('kit_routines_ability_missing', sprintf('%s is not registered on this site; is the plugin that provides it active?', $name));
    }
    $annotations = $ability->get_meta()['annotations'] ?? [];
    if (!is_array($annotations) || ($annotations['readonly'] ?? false) !== true) {
        return new WP_Error('kit_routines_ability_not_readonly', sprintf('%s is no longer read-only, and a routine never writes; it was not run.', $name));
    }
    /** @var mixed $result */
    $result = Runtime\run_ability($ability, $input);
    if ($result instanceof WP_Error) {
        return $result;
    }
    return is_array($result) ? $result : new WP_Error('kit_routines_unexpected_result', sprintf('%s returned something other than a result object.', $name));
}

/**
 * @param array<string, mixed> $step
 * @param array<string, mixed> $issue
 * @return array<string, mixed>
 */
function add_issue(array $step, string $key, array $issue): array
{
    if (isset($step['issues'][$key])) {
        $step['issues'][$key]['count'] = (int) ($step['issues'][$key]['count'] ?? 1) + (int) ($issue['count'] ?? 1);
        return $step;
    }
    if (count($step['issues']) >= MAX_ISSUES) {
        $step['truncated'] = true;
        return $step;
    }
    $step['issues'][$key] = $issue;
    return $step;
}

/**
 * One page per call: the audit fetches the page over HTTP, as a logged-out visitor sees it.
 *
 * @param array<string, mixed> $step
 * @return array<string, mixed>
 */
function advance_accessibility(array $step, float $deadline): array
{
    $targets = is_array($step['targets'] ?? null) ? $step['targets'] : [];
    $step['status'] = 'running';
    while ((int) $step['cursor'] < count($targets) && microtime(true) < $deadline) {
        $path = (string) $targets[(int) $step['cursor']];
        $result = call_audit($step['ability'], array_merge($step['input'], ['url' => absolute_url($path)]));
        if ($result instanceof WP_Error) {
            $step['pages'][] = ['path' => $path, 'error' => mb_substr($result->get_error_message(), 0, 300)];
        } else {
            $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
            $step['pages'][] = [
                'path' => $path,
                'http_status' => (int) ($result['status'] ?? 0),
                'score' => (int) ($result['score'] ?? 0),
                'rules_failed' => (int) ($summary['rules_failed'] ?? 0),
                'instances' => (int) ($summary['instances'] ?? 0),
            ];
            foreach (is_array($result['findings'] ?? null) ? $result['findings'] : [] as $finding) {
                if (!is_array($finding)) {
                    continue;
                }
                $rule = (string) ($finding['rule'] ?? '');
                $step = add_issue($step, 'a11y|' . $path . '|' . $rule, [
                    'severity' => (string) ($finding['severity'] ?? ''),
                    'what' => $rule,
                    'where' => $path,
                    'count' => (int) ($finding['count'] ?? 1),
                ]);
            }
        }
        $step['cursor'] = (int) $step['cursor'] + 1;
    }
    if ((int) $step['cursor'] < count($targets)) {
        return $step;
    }

    $checked = array_values(array_filter($step['pages'], static fn(array $page): bool => !isset($page['error'])));
    $by_severity = [];
    foreach ($step['issues'] as $issue) {
        $by_severity[$issue['severity']] = ($by_severity[$issue['severity']] ?? 0) + 1;
    }
    $step['summary'] = [
        'pages_checked' => count($checked),
        'pages_failed' => count($step['pages']) - count($checked),
        'average_score' => $checked === [] ? null : (int) round(array_sum(array_column($checked, 'score')) / count($checked)),
        'lowest_score' => $checked === [] ? null : min(array_column($checked, 'score')),
        'rules_failing' => count($step['issues']),
        'by_severity' => $by_severity,
        'pages' => $step['pages'],
    ];
    if ($checked === [] && $targets !== []) {
        $step['status'] = 'failed';
        $step['error'] = 'No page could be checked: ' . (string) ($step['pages'][0]['error'] ?? 'no pages');
        return $step;
    }
    $step['status'] = 'done';
    return $step;
}

/**
 * Start free's background content audit, then read its status on later ticks until it is done.
 *
 * @param array<string, mixed> $step
 * @return array<string, mixed>
 */
function advance_content(array $step, float $deadline): array
{
    if ((string) ($step['job_id'] ?? '') === '') {
        $started = call_audit($step['ability'], array_merge($step['input'], ['mode' => 'background']));
        if ($started instanceof WP_Error) {
            return array_merge($step, ['status' => 'failed', 'error' => mb_substr($started->get_error_message(), 0, 300)]);
        }
        $job_id = (string) ($started['job_id'] ?? '');
        if ($job_id === '') {
            return array_merge($step, ['status' => 'failed', 'error' => 'The content audit did not start a background job.']);
        }
        return array_merge($step, ['job_id' => $job_id, 'status' => 'waiting']);
    }

    while (microtime(true) < $deadline) {
        $status = call_audit('wppilot/audit-content-status', [
            'job_id' => (string) $step['job_id'],
            'offset' => (int) $step['cursor'],
            'limit' => CONTENT_PAGE,
        ]);
        if ($status instanceof WP_Error) {
            return array_merge($step, ['status' => 'failed', 'error' => mb_substr($status->get_error_message(), 0, 300)]);
        }
        $job_status = (string) ($status['status'] ?? '');
        if (in_array($job_status, ['queued', 'running'], true)) {
            $step['status'] = 'waiting';
            $step['progress'] = (float) ($status['progress'] ?? 0);
            return $step;
        }
        if ($job_status !== 'done') {
            $message = (string) ($status['message'] ?? '');
            return array_merge($step, ['status' => 'failed', 'error' => mb_substr(sprintf('The content audit ended %s%s', $job_status, $message !== '' ? ': ' . $message : '.'), 0, 300)]);
        }

        $step['status'] = 'running';
        foreach (is_array($status['findings'] ?? null) ? $status['findings'] : [] as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $post = is_array($finding['post'] ?? null) ? $finding['post'] : null;
            $evidence = is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [];
            $type = (string) ($finding['type'] ?? '');
            $where = $post === null ? 'site' : (site_path((string) ($post['url'] ?? '')) ?? sprintf('post %d', (int) ($post['id'] ?? 0)));
            $href = is_string($evidence['href'] ?? null) ? mb_substr($evidence['href'], 0, 300) : '';
            $issue = [
                'severity' => (string) ($finding['severity'] ?? ''),
                'what' => $type,
                'where' => $where,
                'post_id' => $post === null ? null : (int) ($post['id'] ?? 0),
            ];
            if ($href !== '') {
                $issue['link'] = $href;
            }
            $step = add_issue($step, 'content|' . $type . '|' . (int) ($post['id'] ?? 0) . '|' . $href, $issue);
        }
        $next = $status['next_offset'] ?? null;
        $step['cursor'] = is_int($next) ? $next : (int) $step['cursor'];
        if (!is_int($next) || count($step['issues']) >= MAX_ISSUES) {
            if (is_int($next)) {
                $step['truncated'] = true;
            }
            $counts = is_array($status['counts'] ?? null) ? $status['counts'] : [];
            $stats = is_array($status['stats'] ?? null) ? $status['stats'] : [];
            $step['summary'] = [
                'findings' => (int) ($status['total_findings'] ?? 0),
                'by_severity' => is_array($counts['by_severity'] ?? null) ? $counts['by_severity'] : [],
                'by_type' => is_array($counts['by_type'] ?? null) ? $counts['by_type'] : [],
                'posts_scanned' => (int) ($stats['scanned'] ?? 0),
                'complete' => ($stats['complete'] ?? false) === true,
            ];
            $step['status'] = 'done';
            return $step;
        }
    }
    return $step;
}

/**
 * Page through the media library's images that need alt text.
 *
 * @param array<string, mixed> $step
 * @return array<string, mixed>
 */
function advance_media(array $step, float $deadline): array
{
    $step['status'] = 'running';
    $page = max(1, (int) $step['cursor']);
    while (microtime(true) < $deadline) {
        $result = call_audit($step['ability'], array_merge($step['input'], ['page' => $page, 'include' => 'issues']));
        if ($result instanceof WP_Error) {
            return array_merge($step, ['status' => 'failed', 'error' => mb_substr($result->get_error_message(), 0, 300)]);
        }
        $counts = is_array($step['summary']['counts'] ?? null) ? $step['summary']['counts'] : [];
        foreach (is_array($result['page_summary'] ?? null) ? $result['page_summary'] : [] as $key => $value) {
            $counts[(string) $key] = (int) ($counts[(string) $key] ?? 0) + (int) $value;
        }
        $step['summary'] = [
            'counts' => $counts,
            'images_scanned' => (int) ($step['summary']['images_scanned'] ?? 0) + (int) ($result['scanned'] ?? 0),
            'total_images' => (int) ($result['total_images'] ?? 0),
        ];
        foreach (is_array($result['images'] ?? null) ? $result['images'] : [] as $image) {
            if (!is_array($image)) {
                continue;
            }
            $status = (string) ($image['alt_status'] ?? '');
            if (!in_array($status, ['missing', 'filename'], true)) {
                continue;
            }
            $attachment = (int) ($image['attachment_id'] ?? 0);
            $step = add_issue($step, 'media|' . $attachment . '|' . $status, [
                'severity' => $status === 'missing' ? 'medium' : 'low',
                'what' => 'alt_' . $status,
                'where' => sprintf('attachment %d', $attachment),
                'attachment_id' => $attachment,
            ]);
        }
        $next = $result['next_page'] ?? null;
        if (!is_int($next) || $page >= MEDIA_MAX_PAGES) {
            $step['truncated'] = $step['truncated'] || is_int($next);
            $step['summary']['needs_alt'] = count($step['issues']);
            $step['status'] = 'done';
            return $step;
        }
        $page = $next;
        $step['cursor'] = $page;
    }
    return $step;
}

/**
 * Queue one run now. Shared by wppilot/routines-run-now and the admin screen's button.
 *
 * @return array<string, mixed>|WP_Error
 */
function queue_run_now(string $id): array|WP_Error
{
    $routine = get_routine($id);
    if ($routine === null) {
        return new WP_Error('kit_routines_not_found', sprintf('No routine has the id "%s"; see wppilot/routines-list.', $id), ['status' => 404]);
    }
    $state = state($id);
    if (is_array($state['run'] ?? null)) {
        return new WP_Error(
            'kit_routines_running',
            sprintf('A run of this routine started at %s is still in progress; its report appears in wppilot/routines-report when it ends.', gmdate('c', (int) ($state['run']['started_at'] ?? 0))),
            ['status' => 409],
        );
    }
    if (($state['manual_pending'] ?? false) === true) {
        return new WP_Error('kit_routines_already_queued', 'A run of this routine is already queued; it starts on the next WP-Cron tick.', ['status' => 409]);
    }
    $now = time();
    $last = (int) ($state['manual_requested_at'] ?? 0);
    if ($now - $last < RUN_NOW_COOLDOWN) {
        return new WP_Error(
            'kit_routines_cooldown',
            sprintf('This routine was last run by hand %d seconds ago; runs on request are at least 10 minutes apart. Try again after %s.', $now - $last, gmdate('c', $last + RUN_NOW_COOLDOWN)),
            ['status' => 429, 'retry_after' => $last + RUN_NOW_COOLDOWN - $now],
        );
    }
    $state['manual_pending'] = true;
    $state['manual_requested_at'] = $now;
    set_state($id, $state);
    schedule_event($id, $now);
    // Without a visitor WP-Cron only runs on the next page load; this starts it now.
    if (function_exists('spawn_cron')) {
        spawn_cron();
    }
    return [
        'queued' => true,
        'routine_id' => $id,
        'queued_at' => time_view($now),
        'audits' => count(is_array($routine['audits'] ?? null) ? $routine['audits'] : []),
    ];
}
