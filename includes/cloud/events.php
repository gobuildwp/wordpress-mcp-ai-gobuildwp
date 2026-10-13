<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Event pushes: the §5 heartbeat, sent early with an `event` field.
 *
 * The hourly heartbeat leaves the Cloud dashboard up to an hour behind a backup
 * that just finished or a plugin that was just updated. When one of the events
 * below happens on a linked site, the same signed heartbeat is sent within
 * seconds, carrying `event`, and the Cloud refreshes that site's health.
 *
 * Never on a visitor's critical path: the hook that notices an event only
 * records it and schedules a single cron event; the HTTP call happens in cron.
 * Debounced per site: at most one push per WPPILOT_CLOUD_EVENT_DEBOUNCE
 * seconds. Events that arrive while a push is waiting collapse into it, and the
 * push carries the most significant of them (WPPILOT_CLOUD_EVENTS order).
 *
 * Vendor hooks are only those found in the vendor code itself (UpdraftPlus
 * 1.26.8, BackWPup 5.7.7, Duplicator 5.0.5, Site Kit 1.189.0). All-in-One WP
 * Migration is not hooked: its completion hook could not be verified.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Events the Cloud understands, most significant first. */
const WPPILOT_CLOUD_EVENTS = ['backup_failed', 'backup_finished', 'update_finished', 'extension_changed', 'site_kit_changed'];

/** Minimum seconds between two event pushes. */
const WPPILOT_CLOUD_EVENT_DEBOUNCE = 30;

/** A push still waiting this long after it was due was lost (cron cleared, restore) and is scheduled again. */
const WPPILOT_CLOUD_EVENT_STALE = 600;

/** {event: string|null, due: int, sent: int}. Not autoloaded: read only when an event happens and in cron. */
const WPPILOT_CLOUD_EVENT_OPTION = 'wppilot_cloud_event_push';

const WPPILOT_CLOUD_EVENT_HOOK = 'wppilot_cloud_event_push';

/** Site Kit options whose change means its connection, modules or sharing changed. */
const WPPILOT_CLOUD_SITE_KIT_OPTIONS = [
    'googlesitekit_credentials',
    'googlesitekit_active_modules',
    'googlesitekit_dashboard_sharing',
];

function wppilot_cloud_register_event_hooks(): void
{
    add_action(WPPILOT_CLOUD_EVENT_HOOK, callback: 'wppilot_cloud_send_event_push', priority: 10, accepted_args: 0);

    // Core: updates, installs, activation and theme switches.
    add_action('upgrader_process_complete', callback: 'wppilot_cloud_on_upgrader_event', priority: 20, accepted_args: 2);
    foreach (['activated_plugin', 'deactivated_plugin', 'deleted_plugin', 'switch_theme', 'deleted_theme'] as $hook) {
        add_action($hook, callback: 'wppilot_cloud_on_extension_changed', priority: 10, accepted_args: 0);
    }

    // GoBuildWP Pro's safe update, fired once per run whatever its outcome.
    add_action('wppilot_kit_safe_updates_finished', callback: 'wppilot_cloud_on_update_finished', priority: 10, accepted_args: 0);

    // UpdraftPlus: save_last_backup() filters the run's verdict (success 1/0) just before storing it.
    add_filter('updraftplus_save_last_backup', callback: 'wppilot_cloud_on_updraftplus_last_backup', priority: 10, accepted_args: 1);
    // BackWPup: Job::end() fires this for every job, with the job object carrying its error count.
    add_action('backwpup_end_job', callback: 'wppilot_cloud_on_backwpup_end_job', priority: 10, accepted_args: 3);
    // Duplicator: TraitPackageBuild fires these when a build completes or fails.
    add_action('duplicator_build_completed', callback: 'wppilot_cloud_on_backup_finished', priority: 10, accepted_args: 0);
    add_action('duplicator_build_fail', callback: 'wppilot_cloud_on_backup_failed', priority: 10, accepted_args: 0);

    // Site Kit: a user connects (OAuth_Client), the plugin is reset, the last
    // admin disconnects (Has_Connected_Admins is deleted), or the site
    // credentials, active modules or dashboard sharing change.
    add_action('googlesitekit_authorize_user', callback: 'wppilot_cloud_on_site_kit_changed', priority: 10, accepted_args: 0);
    add_action('googlesitekit_reset', callback: 'wppilot_cloud_on_site_kit_changed', priority: 10, accepted_args: 0);
    add_action('delete_option_googlesitekit_has_connected_admins', callback: 'wppilot_cloud_on_site_kit_changed', priority: 10, accepted_args: 0);
    foreach (WPPILOT_CLOUD_SITE_KIT_OPTIONS as $option) {
        foreach (['add_option_', 'update_option_', 'delete_option_'] as $prefix) {
            add_action($prefix . $option, callback: 'wppilot_cloud_on_site_kit_changed', priority: 10, accepted_args: 0);
        }
    }
}

/**
 * Record an event and make sure one push is scheduled for it.
 *
 * Only an option write and at most one wp_schedule_single_event(): nothing here
 * talks to the Cloud. A no-op while the site is not linked.
 */
function wppilot_cloud_note_event(string $event, ?int $now = null): void
{
    if (!in_array($event, WPPILOT_CLOUD_EVENTS, strict: true) || wppilot_cloud_link() === null) {
        return;
    }

    $now ??= time();
    $state = wppilot_cloud_event_state();

    if ($state['event'] !== null) {
        $state['event'] = wppilot_cloud_significant_event($state['event'], $event);
        if ($state['due'] >= $now - WPPILOT_CLOUD_EVENT_STALE) {
            // A push is already waiting; it will carry this event too. Its cron entry can be gone
            // (WP-Cron keeps every event in one option, and requests writing it at once can drop
            // one; seen on the WP 7.1 test site), so it is put back then, or the event would wait
            // out the stale window for nothing.
            update_option(WPPILOT_CLOUD_EVENT_OPTION, $state, autoload: false);
            if (wp_next_scheduled(WPPILOT_CLOUD_EVENT_HOOK) === false) {
                wp_schedule_single_event(max($now, $state['due']), WPPILOT_CLOUD_EVENT_HOOK);
            }
            return;
        }
    } else {
        $state['event'] = $event;
    }

    $state['due'] = max($now, $state['sent'] + WPPILOT_CLOUD_EVENT_DEBOUNCE);
    update_option(WPPILOT_CLOUD_EVENT_OPTION, $state, autoload: false);
    wp_schedule_single_event($state['due'], WPPILOT_CLOUD_EVENT_HOOK);
}

/**
 * Cron: send the waiting event as a heartbeat.
 *
 * The event is taken off before sending, so one that happens during the call
 * schedules its own push (no sooner than the debounce allows) instead of being
 * lost. A duplicate run finds nothing waiting and does nothing.
 */
function wppilot_cloud_send_event_push(?int $now = null): void
{
    $state = wppilot_cloud_event_state();
    $event = $state['event'];
    if ($event === null) {
        return;
    }

    update_option(WPPILOT_CLOUD_EVENT_OPTION, ['event' => null, 'due' => 0, 'sent' => $now ?? time()], autoload: false);
    wppilot_cloud_send_heartbeat($event);
}

/**
 * @return array{event: string|null, due: int, sent: int}
 */
function wppilot_cloud_event_state(): array
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_CLOUD_EVENT_OPTION, default_value: []);
    $stored = is_array($stored) ? $stored : [];
    $event = $stored['event'] ?? null;

    return [
        'event' => is_string($event) && in_array($event, WPPILOT_CLOUD_EVENTS, strict: true) ? $event : null,
        'due' => is_int($stored['due'] ?? null) ? $stored['due'] : 0,
        'sent' => is_int($stored['sent'] ?? null) ? $stored['sent'] : 0,
    ];
}

/** The more significant of two known events. */
function wppilot_cloud_significant_event(string $a, string $b): string
{
    $rank_a = array_search($a, WPPILOT_CLOUD_EVENTS, strict: true);
    $rank_b = array_search($b, WPPILOT_CLOUD_EVENTS, strict: true);
    if ($rank_a === false) {
        return $b;
    }
    if ($rank_b === false) {
        return $a;
    }

    return $rank_b < $rank_a ? $b : $a;
}

/**
 * upgrader_process_complete: an update of a plugin, theme or core is
 * update_finished; an install of a plugin or theme is extension_changed.
 * Translations are neither.
 */
function wppilot_cloud_on_upgrader_event(mixed $upgrader, mixed $options = []): void
{
    unset($upgrader);
    if (!is_array($options)) {
        return;
    }
    $type = $options['type'] ?? '';
    $action = $options['action'] ?? '';

    if ($action === 'update' && in_array($type, ['plugin', 'theme', 'core'], strict: true)) {
        wppilot_cloud_note_event('update_finished');
    } elseif ($action === 'install' && in_array($type, ['plugin', 'theme'], strict: true)) {
        wppilot_cloud_note_event('extension_changed');
    }
}

function wppilot_cloud_on_update_finished(): void
{
    wppilot_cloud_note_event('update_finished');
}

function wppilot_cloud_on_extension_changed(): void
{
    wppilot_cloud_note_event('extension_changed');
}

function wppilot_cloud_on_site_kit_changed(): void
{
    wppilot_cloud_note_event('site_kit_changed');
}

function wppilot_cloud_on_backup_finished(): void
{
    wppilot_cloud_note_event('backup_finished');
}

function wppilot_cloud_on_backup_failed(): void
{
    wppilot_cloud_note_event('backup_failed');
}

/**
 * updraftplus_save_last_backup (a filter): read the verdict, pass the value on unchanged.
 *
 * UpdraftPlus saves it at the end of every run, so success 0 can also mean a
 * run with errors that a scheduled resumption may still complete.
 */
function wppilot_cloud_on_updraftplus_last_backup(mixed $last_backup): mixed
{
    if (is_array($last_backup) && array_key_exists('success', $last_backup)) {
        wppilot_cloud_note_event(!empty($last_backup['success']) ? 'backup_finished' : 'backup_failed');
    }

    return $last_backup;
}

/**
 * backwpup_end_job: the job object's public $errors counts the errors of the run.
 */
function wppilot_cloud_on_backwpup_end_job(mixed $job = null, mixed $backup_file = null, mixed $job_object = null): void
{
    unset($job, $backup_file);
    $errors = is_object($job_object) && isset($job_object->errors) && is_numeric($job_object->errors) ? (int) $job_object->errors : 0;
    wppilot_cloud_note_event($errors > 0 ? 'backup_failed' : 'backup_finished');
}
