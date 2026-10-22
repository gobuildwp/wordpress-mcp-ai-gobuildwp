<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteTools\Cron;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Ledger;

if (!defined('ABSPATH')) {
    exit();
}

/** The rollback strategy that puts a deleted event back exactly as it was. */
const STRATEGY = 'kits/cron-event';

/** WordPress's cron lock; wp-cron.php and spawn_cron() both honour it. */
const LOCK = 'doing_cron';

/** Most events one list returns. */
const MAX_EVENTS = 500;

/** Most bytes of a hook's output kept in the result. */
const MAX_OUTPUT = 2000;

const RUN_IRREVERSIBLE = 'The event\'s hook ran through wp-cron\'s action (do_action_ref_array); what its callbacks did is not recorded, so it cannot be undone. A recurring event was rescheduled for its next run, as wp-cron does.';

/**
 * WordPress's key for an event's arguments: events with the same hook and time are told apart
 * by md5(serialize($args)), exactly as wp_schedule_event() stores them.
 *
 * @param array<array-key, mixed> $args
 */
function args_key(array $args): string
{
    return md5(serialize($args));
}

/**
 * Every scheduled event, soonest first.
 *
 * @return list<array{hook: string, timestamp: int, key: string, schedule: string|false, interval: int|null, args: array<array-key, mixed>}>
 */
function events(): array
{
    $events = [];
    foreach (_get_cron_array() as $timestamp => $hooks) {
        if (!is_array($hooks)) {
            continue;
        }
        foreach ($hooks as $hook => $keyed) {
            foreach (is_array($keyed) ? $keyed : [] as $key => $event) {
                $events[] = [
                    'hook' => (string) $hook,
                    'timestamp' => (int) $timestamp,
                    'key' => (string) $key,
                    'schedule' => is_string($event['schedule'] ?? null) && $event['schedule'] !== '' ? $event['schedule'] : false,
                    'interval' => isset($event['interval']) ? (int) $event['interval'] : null,
                    'args' => is_array($event['args'] ?? null) ? $event['args'] : [],
                ];
            }
        }
    }
    usort($events, static fn(array $a, array $b): int => [$a['timestamp'], $a['hook']] <=> [$b['timestamp'], $b['hook']]);
    return $events;
}

/**
 * The one event a call names, by hook, time and either its args or their key.
 *
 * @param array<string, mixed> $input
 * @return array{hook: string, timestamp: int, key: string, schedule: string|false, interval: int|null, args: array<array-key, mixed>}|WP_Error
 */
function find(array $input): array|WP_Error
{
    $hook = (string) ($input['hook'] ?? '');
    $timestamp = (int) ($input['timestamp'] ?? 0);
    $key = (string) ($input['key'] ?? '');
    if (array_key_exists('args', $input)) {
        if (!is_array($input['args'])) {
            return new WP_Error('kit_cron_bad_args', 'args must be the event\'s argument array, exactly as cron-list returned it.', ['status' => 400]);
        }
        $from_args = args_key($input['args']);
        if ($key !== '' && $key !== $from_args) {
            return new WP_Error('kit_cron_bad_args', 'args and key name different events; pass one of them.', ['status' => 400]);
        }
        $key = $from_args;
    }
    if ($hook === '' || $timestamp <= 0 || $key === '') {
        return new WP_Error(
            'kit_cron_event_unnamed',
            'Name the event with hook, timestamp and key (or args), as wppilot/cron-list returns them.',
            ['status' => 400],
        );
    }
    foreach (events() as $event) {
        if ($event['hook'] === $hook && $event['timestamp'] === $timestamp && $event['key'] === $key) {
            return $event;
        }
    }
    return new WP_Error(
        'kit_cron_event_not_found',
        sprintf('No event for %s is scheduled at %d with those arguments. It may already have run; list the events again.', $hook, $timestamp),
        ['status' => 404],
    );
}

/**
 * Whether a wp-cron run holds the lock right now, as spawn_cron() decides it.
 */
function lock_held(float $now): bool
{
    $lock = (float) get_transient(LOCK);
    if ($lock > $now + 10 * MINUTE_IN_SECONDS) {
        // spawn_cron() treats a lock this far ahead as corrupt, and so does this.
        $lock = 0.0;
    }
    return $lock + (defined('WP_CRON_LOCK_TIMEOUT') ? (int) WP_CRON_LOCK_TIMEOUT : MINUTE_IN_SECONDS) > $now;
}

/**
 * wppilot/cron-list.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function list_events(array $input): array
{
    $now = time();
    $search = strtolower(trim((string) ($input['hook'] ?? '')));
    $due_only = ($input['due_only'] ?? false) === true;
    $limit = min(MAX_EVENTS, max(1, (int) ($input['limit'] ?? 200)));

    $all = events();
    $matched = 0;
    $overdue = 0;
    $listed = [];
    foreach ($all as $event) {
        $due = $event['timestamp'] <= $now;
        if ($due) {
            $overdue++;
        }
        if (($search !== '' && !str_contains(strtolower($event['hook']), $search)) || ($due_only && !$due)) {
            continue;
        }
        $matched++;
        if (count($listed) >= $limit) {
            continue;
        }
        $listed[] = [
            'hook' => $event['hook'],
            'timestamp' => $event['timestamp'],
            'time_utc' => gmdate('c', $event['timestamp']),
            'key' => $event['key'],
            'args' => $event['args'],
            'schedule' => $event['schedule'] === false ? null : $event['schedule'],
            'interval' => $event['interval'],
            'due' => $due,
            'overdue_seconds' => $due ? $now - $event['timestamp'] : 0,
            // An event nothing listens to runs and does nothing; usually its plugin is gone.
            'has_callbacks' => has_action($event['hook']) !== false,
        ];
    }

    $schedules = [];
    foreach (wp_get_schedules() as $name => $schedule) {
        $schedules[] = [
            'name' => (string) $name,
            'interval' => (int) ($schedule['interval'] ?? 0),
            'display' => (string) ($schedule['display'] ?? $name),
        ];
    }

    return [
        'now' => $now,
        'now_utc' => gmdate('c', $now),
        'events' => $listed,
        'count' => count($listed),
        'matched' => $matched,
        'total' => count($all),
        'due_or_overdue' => $overdue,
        'truncated' => $matched > count($listed),
        'schedules' => $schedules,
        'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        'alternate_cron' => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
        'cron_running' => lock_held(microtime(true)),
    ];
}

/**
 * wppilot/cron-run: what wp-cron.php does for one due event, now.
 *
 * Same order as wp-cron.php: reschedule a recurring event, unschedule this occurrence, then fire
 * the hook — as no user, with wp_doing_cron() true, under the cron lock so a wp-cron spawn in
 * another request cannot run the same occurrence at the same time. Only a due or overdue event
 * runs: running a future one early is a different request from "catch up what is late".
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function run(array $input): array|WP_Error
{
    $event = find($input);
    if ($event instanceof WP_Error) {
        return $event;
    }
    $now = microtime(true);
    if ($event['timestamp'] > $now) {
        return new WP_Error(
            'kit_cron_not_due',
            sprintf('This event is not due until %s; only a due or overdue event can be run now.', gmdate('c', $event['timestamp'])),
            ['status' => 409],
        );
    }
    if (lock_held($now)) {
        return new WP_Error(
            'kit_cron_locked',
            'wp-cron is running in another request right now. Wait a minute, list the events again, and run the event only if it is still due.',
            ['status' => 409],
        );
    }

    $lock = sprintf('%.22F', $now);
    set_transient(LOCK, $lock);
    $user = get_current_user_id();
    $doing_cron = static fn(): bool => true;
    $output = '';
    $error = null;
    $started = microtime(true);
    try {
        if ($event['schedule'] !== false) {
            $rescheduled = wp_reschedule_event($event['timestamp'], $event['schedule'], $event['hook'], $event['args'], true);
            if ($rescheduled instanceof WP_Error) {
                return new WP_Error(
                    'kit_cron_reschedule_failed',
                    'The recurring event could not be rescheduled, so it was not run: ' . $rescheduled->get_error_message(),
                );
            }
        }
        $unscheduled = wp_unschedule_event($event['timestamp'], $event['hook'], $event['args'], true);
        if ($unscheduled instanceof WP_Error) {
            return new WP_Error('kit_cron_unschedule_failed', 'The event could not be taken off the schedule, so it was not run: ' . $unscheduled->get_error_message());
        }

        // wp-cron runs as no one; a callback that checks capabilities must see what it sees there.
        wp_set_current_user(0);
        add_filter('wp_doing_cron', $doing_cron);
        ob_start();
        try {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Runs one existing WP-Cron event by its own hook name, as wp-cron.php does.
            do_action_ref_array($event['hook'], $event['args']);
        } catch (\Throwable $thrown) {
            $error = get_class($thrown) . ': ' . $thrown->getMessage();
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_doing_cron', $doing_cron);
            wp_set_current_user($user);
        }
    } finally {
        if ((string) get_transient(LOCK) === $lock) {
            delete_transient(LOCK);
        }
    }

    $next = $event['schedule'] !== false ? wp_next_scheduled($event['hook'], $event['args']) : false;
    $result = [
        'ran' => true,
        'hook' => $event['hook'],
        'timestamp' => $event['timestamp'],
        'key' => $event['key'],
        'schedule' => $event['schedule'] === false ? null : $event['schedule'],
        'next_run' => is_int($next) ? $next : null,
        'next_run_utc' => is_int($next) ? gmdate('c', $next) : null,
        'had_callbacks' => has_action($event['hook']) !== false,
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        'error' => $error,
        'output' => strlen($output) > MAX_OUTPUT ? substr($output, 0, MAX_OUTPUT) . '…' : $output,
    ];
    Runtime\host()->ledger()->record_items('wppilot/cron-run', [[
        'input' => ['hook' => $event['hook'], 'timestamp' => $event['timestamp'], 'key' => $event['key']],
        'before' => null,
        'result' => $result,
        'irreversible_reason' => RUN_IRREVERSIBLE,
    ]]);
    return $result;
}

/**
 * wppilot/cron-delete: unschedule one occurrence, keeping what it takes to put it back.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function delete(array $input): array|WP_Error
{
    $confirmed = Runtime\confirm_guard('wppilot/cron-delete', $input);
    if ($confirmed instanceof WP_Error) {
        return $confirmed;
    }
    $event = find($input);
    if ($event instanceof WP_Error) {
        return $event;
    }
    $removed = wp_unschedule_event($event['timestamp'], $event['hook'], $event['args'], true);
    if ($removed instanceof WP_Error || $removed === false) {
        return new WP_Error(
            'kit_cron_unschedule_failed',
            'The event could not be unscheduled' . ($removed instanceof WP_Error ? ': ' . $removed->get_error_message() : '.'),
        );
    }
    if (wp_get_scheduled_event($event['hook'], $event['args'], $event['timestamp']) !== false) {
        return new WP_Error('kit_cron_unschedule_unverified', 'WordPress reported the event unscheduled, but it is still on the schedule.');
    }

    $snapshot = snapshot($event);
    $recorded = Runtime\host()->ledger()->record_items('wppilot/cron-delete', [[
        'input' => ['hook' => $event['hook'], 'timestamp' => $event['timestamp'], 'key' => $event['key']],
        'before' => $snapshot,
        'result' => ['deleted' => true],
        'item' => ['hook' => $event['hook'], 'timestamp' => $event['timestamp']],
    ]]);
    return [
        'deleted' => true,
        'hook' => $event['hook'],
        'timestamp' => $event['timestamp'],
        'key' => $event['key'],
        'schedule' => $event['schedule'] === false ? null : $event['schedule'],
        'change_id' => $recorded['change_ids'][0] ?? null,
        'still_scheduled_later' => ($next = wp_next_scheduled($event['hook'], $event['args'])) !== false ? $next : null,
    ];
}

/**
 * @param array{hook: string, timestamp: int, key: string, schedule: string|false, interval: int|null, args: array<array-key, mixed>} $event
 * @return array<string, mixed>
 */
function snapshot(array $event): array
{
    return [
        'type' => STRATEGY,
        'hook' => $event['hook'],
        'timestamp' => $event['timestamp'],
        'args' => $event['args'],
        'key' => $event['key'],
        'schedule' => $event['schedule'],
        'interval' => $event['interval'],
    ];
}

/**
 * Put a deleted event back at its own time with its own schedule and arguments, then re-read it.
 *
 * Refused rather than duplicated when the same recurring hook and arguments are already on the
 * schedule again: the plugin that owns a recurring event usually re-adds it on its next load,
 * and a second copy would run it twice as often.
 *
 * @param array<string, mixed> $payload A ledger rollback payload; the snapshot is under `snapshot`.
 * @return array<string, mixed>|WP_Error
 */
function restore(array $payload): array|WP_Error
{
    $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $hook = (string) ($snapshot['hook'] ?? '');
    $timestamp = (int) ($snapshot['timestamp'] ?? 0);
    $args = is_array($snapshot['args'] ?? null) ? $snapshot['args'] : [];
    $schedule = is_string($snapshot['schedule'] ?? null) && $snapshot['schedule'] !== '' ? $snapshot['schedule'] : false;
    if ($hook === '' || $timestamp <= 0) {
        return new WP_Error('kit_rollback_bad_snapshot', 'The change record does not name a cron event.');
    }

    $existing = wp_get_scheduled_event($hook, $args, $timestamp);
    if ($existing === false) {
        $again = wp_next_scheduled($hook, $args);
        if ($schedule !== false && $again !== false) {
            return new WP_Error(
                'kit_cron_already_rescheduled',
                sprintf('%s is already scheduled again (next run %s), most likely by the plugin that owns it; restoring the deleted occurrence would run it twice.', $hook, gmdate('c', (int) $again)),
            );
        }
        $scheduled = $schedule === false
            ? wp_schedule_single_event($timestamp, $hook, $args, true)
            : wp_schedule_event($timestamp, $schedule, $hook, $args, true);
        if ($scheduled instanceof WP_Error || $scheduled === false) {
            return new WP_Error(
                'kit_cron_restore_failed',
                'WordPress refused to schedule the event again' . ($scheduled instanceof WP_Error ? ': ' . $scheduled->get_error_message() : '.')
                . ($schedule !== false ? sprintf(' Is the "%s" schedule still registered?', $schedule) : ''),
            );
        }
        $existing = wp_get_scheduled_event($hook, $args, $timestamp);
    }

    $observed_schedule = is_object($existing) && is_string($existing->schedule ?? null) && $existing->schedule !== '' ? $existing->schedule : false;
    return [
        'hook' => $hook,
        'timestamp' => $timestamp,
        'schedule' => $schedule,
        'observed_schedule' => is_object($existing) ? $observed_schedule : null,
        'verified' => is_object($existing) && $observed_schedule === $schedule,
    ];
}

function register(Ledger $ledger): void
{
    $ledger->register_strategy(STRATEGY, static fn(array $payload): array|WP_Error => restore($payload));
}
