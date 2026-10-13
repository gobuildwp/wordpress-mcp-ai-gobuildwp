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
 * The execute callbacks: list, save, delete, run now, report.
 */

/**
 * wppilot/routines-list.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function list_routines(array $input): array
{
    $routines = all_routines();
    $only = (string) ($input['id'] ?? '');
    $views = [];
    foreach ($routines as $id => $routine) {
        if ($only !== '' && $only !== (string) $id) {
            continue;
        }
        $views[] = view_routine((string) $id, $routine);
    }
    return [
        'routines' => $views,
        'admins' => admins(),
        'timezone' => wp_timezone_string(),
        'wp_cron_disabled' => defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON') === true,
        'limits' => [
            'routines' => MAX_ROUTINES,
            'audits_per_routine' => MAX_AUDITS,
            'pages_per_accessibility_audit' => MAX_URLS,
            'recipients' => MAX_RECIPIENTS,
            'runs_kept' => HISTORY,
            'run_now_cooldown_seconds' => RUN_NOW_COOLDOWN,
        ],
        'runnable_audits' => array_keys(AUDITS),
    ];
}

/**
 * wppilot/routines-save.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function save(array $input): array|WP_Error
{
    $routines = all_routines();
    $id = (string) ($input['id'] ?? '');
    $existing = $id !== '' ? ($routines[$id] ?? null) : null;
    if ($existing === null) {
        if (count($routines) >= MAX_ROUTINES) {
            return new WP_Error('kit_routines_limit', sprintf('A site can have at most %d routines; delete one first.', MAX_ROUTINES), ['status' => 400]);
        }
        if ($id === '') {
            $id = new_id((string) ($input['label'] ?? ''), $routines);
        }
    }
    $routine = normalize($input, $existing, $id);
    if ($routine instanceof WP_Error) {
        return $routine;
    }

    $before = snapshot($id, false);
    put_routine($id, $routine);
    $stored = get_routine($id);
    if ($stored === null) {
        return new WP_Error('kit_routines_not_saved', 'The routine was not stored; the options table refused the write.', ['status' => 500]);
    }
    $view = view_routine($id, $stored);
    $recorded = Runtime\host()->ledger()->record_items('wppilot/routines-save', [[
        'input' => $input,
        'before' => $before,
        'result' => ['routine_id' => $id, 'created' => $existing === null],
        'item' => ['routine_id' => $id],
    ]]);
    return [
        'saved' => true,
        'created' => $existing === null,
        'routine' => $view,
        'change_id' => $recorded['change_ids'][0] ?? null,
    ];
}

/**
 * wppilot/routines-delete.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function delete(array $input): array|WP_Error
{
    $confirmed = Runtime\confirm_guard('wppilot/routines-delete', $input);
    if ($confirmed instanceof WP_Error) {
        return $confirmed;
    }
    $id = (string) ($input['id'] ?? '');
    if (get_routine($id) === null) {
        return new WP_Error('kit_routines_not_found', sprintf('No routine has the id "%s"; see wppilot/routines-list.', $id), ['status' => 404]);
    }
    $before = snapshot($id, true);
    put_routine($id, null);
    store_reports($id, []);
    forget($id);
    $recorded = Runtime\host()->ledger()->record_items('wppilot/routines-delete', [[
        'input' => ['id' => $id],
        'before' => $before,
        'result' => ['deleted' => true, 'routine_id' => $id],
        'item' => ['routine_id' => $id],
    ]]);
    return [
        'deleted' => true,
        'routine_id' => $id,
        'reports_removed' => count(is_array($before['reports'] ?? null) ? $before['reports'] : []),
        'still_scheduled' => wp_next_scheduled(HOOK, [$id]) !== false,
        'change_id' => $recorded['change_ids'][0] ?? null,
    ];
}

/**
 * wppilot/routines-run-now.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function run_now(array $input): array|WP_Error
{
    $id = (string) ($input['id'] ?? '');
    $queued = queue_run_now($id);
    if ($queued instanceof WP_Error) {
        return $queued;
    }
    $recorded = Runtime\host()->ledger()->record_items('wppilot/routines-run-now', [[
        'input' => ['id' => $id],
        'before' => null,
        'result' => ['queued' => true, 'routine_id' => $id],
        'irreversible_reason' => 'Queued one run of the routine\'s read-only audits on WP-Cron. The run changes nothing on the site; its report joins the routine\'s history.',
    ]]);
    return array_merge($queued, [
        'next' => 'The run starts on the next WP-Cron tick and takes one or more ticks (a content audit runs in the background). Poll wppilot/routines-list until running is null and last_run is newer than queued_at, then read wppilot/routines-report.',
        'change_id' => $recorded['change_ids'][0] ?? null,
    ]);
}
