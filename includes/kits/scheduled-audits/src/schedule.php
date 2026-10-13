<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ScheduledAudits;

use DateTimeImmutable;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * When routines run, and keeping WP-Cron in step with what is stored.
 *
 * Each routine has at most one event on HOOK, with its id as the argument: at its next run
 * time while idle, a few seconds out while a run is in progress. The schedule is derived from
 * the stored definitions by reconcile(), which runs whenever the definitions option changes —
 * a save, a delete, or an undo from the change log, which writes the option without going
 * through this kit's abilities — and hourly, so an event lost to a restored database or a cron
 * outage comes back on its own.
 */

/** Seconds a tick holds a routine; longer than a tick's working budget. */
const LEASE = 120;

/** Seconds of audit calls one tick makes before handing over to the next. */
const STEP_BUDGET = 20;

/** Seconds between ticks while a run waits on a background audit. */
const POLL_DELAY = 30;

/**
 * The first run time strictly after $after: the schedule's hour, on the schedule's day for a
 * weekly routine, in the site's timezone. Computed on the calendar rather than by adding
 * seconds, so a routine at 07:00 stays at 07:00 across a daylight-saving change.
 *
 * @param array<string, mixed> $schedule
 */
function next_run(array $schedule, int $after): int
{
    $zone = wp_timezone();
    $local = (new DateTimeImmutable('@' . $after))->setTimezone($zone);
    $hour = max(0, min(23, (int) ($schedule['hour'] ?? 0)));
    $weekly = ($schedule['frequency'] ?? '') === 'weekly';
    $day = (string) ($schedule['day'] ?? '');
    for ($offset = 0; $offset <= 8; $offset++) {
        $candidate = $local->setTime(0, 0)->modify('+' . $offset . ' days')->setTime($hour, 0);
        if ($weekly && strtolower($candidate->format('l')) !== $day) {
            continue;
        }
        if ($candidate->getTimestamp() > $after) {
            return $candidate->getTimestamp();
        }
    }
    // Unreachable for a valid schedule; a day later is a safe answer for a broken one.
    return $after + DAY_IN_SECONDS;
}

/**
 * "Weekly on Monday at 07:00 (Europe/London)".
 *
 * @param array<string, mixed> $schedule
 */
function describe_schedule(array $schedule): string
{
    $time = sprintf('%02d:00', (int) ($schedule['hour'] ?? 0));
    $zone = wp_timezone_string();
    if (($schedule['frequency'] ?? '') === 'weekly') {
        return sprintf('Weekly on %s at %s (%s)', ucfirst((string) ($schedule['day'] ?? '')), $time, $zone);
    }
    return sprintf('Daily at %s (%s)', $time, $zone);
}

/**
 * @return array{unix: int, local: string, utc: string}|null
 */
function time_view(?int $timestamp): ?array
{
    if ($timestamp === null || $timestamp <= 0) {
        return null;
    }
    return [
        'unix' => $timestamp,
        'local' => wp_date('Y-m-d H:i', $timestamp) . ' ' . wp_timezone_string(),
        'utc' => gmdate('c', $timestamp),
    ];
}

/**
 * Put the routine's one event at $timestamp, replacing whatever it had.
 */
function schedule_event(string $id, int $timestamp): void
{
    wp_clear_scheduled_hook(HOOK, [$id]);
    wp_schedule_single_event($timestamp, HOOK, [$id]);
}

/**
 * Stop tracking a routine that no longer exists: its events, state and lock. Its reports are
 * left to the caller, because a delete keeps them in the ledger's before-image first.
 */
function forget(string $id): void
{
    wp_clear_scheduled_hook(HOOK, [$id]);
    set_state($id, null);
    delete_option(LOCK_PREFIX . $id);
}

/**
 * Bring WP-Cron and the state options in line with the stored definitions.
 */
function reconcile(): void
{
    $routines = all_routines();
    $now = time();
    foreach ($routines as $id => $routine) {
        $id = (string) $id;
        $state = state($id);
        $scheduled = wp_next_scheduled(HOOK, [$id]);
        // A tick holding the routine owns its state until it lets go; writing it here would be
        // overwritten, or overwrite the run it is saving.
        wp_cache_delete(LOCK_PREFIX . $id, 'options');
        $locked = (int) get_option(LOCK_PREFIX . $id, 0) > $now;
        if ($locked || is_array($state['run'] ?? null) || ($state['manual_pending'] ?? false) === true) {
            // A run is in progress or asked for: keep it moving, whatever the schedule says.
            if ($scheduled === false) {
                schedule_event($id, $now + 5);
            }
            continue;
        }
        if (($routine['enabled'] ?? true) !== true || !is_array($routine['schedule'] ?? null)) {
            wp_clear_scheduled_hook(HOOK, [$id]);
            if (isset($state['next_run'])) {
                unset($state['next_run'], $state['schedule_key']);
                set_state($id, $state);
            }
            continue;
        }
        // A changed schedule starts over from now; an unchanged one keeps its time, even one
        // that has passed while cron was down, so the missed run happens once, now.
        $key = hash('sha256', (string) wp_json_encode($routine['schedule']));
        $next = (int) ($state['next_run'] ?? 0);
        if ($next <= 0 || ($state['schedule_key'] ?? '') !== $key) {
            $next = next_run($routine['schedule'], $now);
            $state['next_run'] = $next;
            $state['schedule_key'] = $key;
            set_state($id, $state);
        }
        $stale = $next > $now ? (int) $scheduled !== $next : (int) $scheduled > $now + POLL_DELAY;
        if ($scheduled === false || $stale) {
            schedule_event($id, max($next, $now));
        }
    }

    // Events and state left behind by routines that are gone.
    foreach (scheduled_ids() as $id) {
        if (!isset($routines[$id])) {
            forget($id);
        }
    }

    if ($routines === []) {
        wp_clear_scheduled_hook(RECONCILE_HOOK);
    } elseif (wp_next_scheduled(RECONCILE_HOOK) === false) {
        wp_schedule_event($now + HOUR_IN_SECONDS, 'hourly', RECONCILE_HOOK);
    }
}

/**
 * Routine ids with an event on HOOK.
 *
 * @return list<string>
 */
function scheduled_ids(): array
{
    $crons = function_exists('_get_cron_array') ? _get_cron_array() : [];
    $ids = [];
    foreach (is_array($crons) ? $crons : [] as $hooks) {
        foreach (is_array($hooks[HOOK] ?? null) ? $hooks[HOOK] : [] as $event) {
            $args = is_array($event['args'] ?? null) ? $event['args'] : [];
            if (is_string($args[0] ?? null)) {
                $ids[] = $args[0];
            }
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Take the routine's lease. add_option() is an INSERT on a unique key, so exactly one request
 * wins; an expired lease is removed and contested again — the same claim free's job runner uses.
 */
function claim(string $id): bool
{
    $key = LOCK_PREFIX . $id;
    if (add_option($key, time() + LEASE, '', false)) {
        return true;
    }
    wp_cache_delete($key, 'options');
    if ((int) get_option($key, 0) > time()) {
        return false;
    }
    delete_option($key);
    return add_option($key, time() + LEASE, '', false);
}

function release(string $id): void
{
    delete_option(LOCK_PREFIX . $id);
}
