<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ScheduledAudits;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Routines: what is configured, and the checks every definition passes before it is stored.
 *
 * A routine is stored configuration only — label, audits, schedule, delivery, the user it runs
 * as. What happens to it at run time (next run, the run in progress, the lock) lives in a state
 * option per routine, and its results in a report option per routine, so undoing an edit to the
 * definition never rewinds a run or its history.
 */

/** Every routine's definition, keyed by id. */
const OPTION = 'wppilot_kit_routines';

/** Run state for one routine: + id. */
const STATE_PREFIX = 'wppilot_kit_routines_state_';

/** Stored runs for one routine: + id. */
const REPORT_PREFIX = 'wppilot_kit_routines_report_';

/** A tick's claim on one routine: + id. */
const LOCK_PREFIX = 'wppilot_kit_routines_lock_';

/** The event that starts and advances one routine's run; its one argument is the routine id. */
const HOOK = 'wppilot_kit_routines_tick';

/** Hourly repair of the schedule, for events lost to a cron outage or a restored database. */
const RECONCILE_HOOK = 'wppilot_kit_routines_reconcile';

/** The ledger strategy that puts one routine (and, after a delete, its reports) back. */
const STRATEGY = 'kits/routines-routine';

const MAX_ROUTINES = 20;
const MAX_AUDITS = 5;
const MAX_URLS = 10;
const MAX_RECIPIENTS = 10;

/**
 * The audits a routine may run, and the kind of runner each needs. An allowlist rather than
 * "any read-only ability": each needs its own way of turning a result into issues that can be
 * compared across runs, and a routine may never run a write, whatever an ability's author later
 * changes its annotations to — the runner re-checks `readonly` before every call as well.
 */
const AUDITS = [
    'wppilot/audit-accessibility' => 'accessibility',
    'wppilot/audit-content' => 'content',
    'wppilot/audit-media-alt' => 'media-alt',
];

/** Inputs the runner sets itself, so a routine may not: they pick a mode the runner does not drive. */
const RESERVED_INPUTS = [
    'accessibility' => ['url', 'post_id'],
    'content' => ['mode', 'post_id', 'cursor', 'limit'],
    'media-alt' => ['page', 'include'],
];

const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

/**
 * @return array<string, array<string, mixed>>
 */
function all_routines(): array
{
    /** @var mixed $stored */
    $stored = get_option(OPTION, []);
    $routines = is_array($stored) && is_array($stored['routines'] ?? null) ? $stored['routines'] : [];
    return array_filter($routines, static fn(mixed $routine): bool => is_array($routine));
}

/**
 * @return array<string, mixed>|null
 */
function get_routine(string $id): ?array
{
    return all_routines()[$id] ?? null;
}

/**
 * @param array<string, array<string, mixed>> $routines
 */
function store_routines(array $routines): void
{
    if ($routines === []) {
        // Nothing configured leaves nothing behind.
        delete_option(OPTION);
        return;
    }
    ksort($routines);
    update_option(OPTION, ['version' => 1, 'routines' => $routines], false);
}

/**
 * One routine's entry written or removed on its own, so an undo of one routine never rewinds
 * another routine saved since.
 *
 * @param array<string, mixed>|null $routine
 */
function put_routine(string $id, ?array $routine): void
{
    $routines = all_routines();
    if ($routine === null) {
        unset($routines[$id]);
    } else {
        $routines[$id] = $routine;
    }
    store_routines($routines);
}

/**
 * @return array<string, mixed>
 */
function state(string $id): array
{
    // Another request (a cron tick, the admin screen) may have written it since this one read it.
    wp_cache_delete(STATE_PREFIX . $id, 'options');
    /** @var mixed $state */
    $state = get_option(STATE_PREFIX . $id, []);
    return is_array($state) ? $state : [];
}

/**
 * @param array<string, mixed>|null $state
 */
function set_state(string $id, ?array $state): void
{
    if ($state === null) {
        delete_option(STATE_PREFIX . $id);
        return;
    }
    update_option(STATE_PREFIX . $id, $state, false);
}

/**
 * Stored runs, newest first.
 *
 * @return list<array<string, mixed>>
 */
function reports(string $id): array
{
    wp_cache_delete(REPORT_PREFIX . $id, 'options');
    /** @var mixed $stored */
    $stored = get_option(REPORT_PREFIX . $id, []);
    return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
}

/**
 * @param list<array<string, mixed>> $runs
 */
function store_reports(string $id, array $runs): void
{
    if ($runs === []) {
        delete_option(REPORT_PREFIX . $id);
        return;
    }
    update_option(REPORT_PREFIX . $id, $runs, false);
}

/**
 * Whether a user may run routines and receive their results: an administrator of this site
 * (a super admin on a network, where only they may run GoBuildWP abilities).
 */
function is_admin_user(int $user_id): bool
{
    if ($user_id <= 0) {
        return false;
    }
    $user = get_userdata($user_id);
    if (!$user instanceof \WP_User) {
        return false;
    }
    return is_multisite() ? is_super_admin($user_id) : user_can($user, 'manage_options');
}

/**
 * The administrators a routine may run as or email, for an agent to choose from by id.
 *
 * Display names only: an agent picks recipients by id and never sees an address.
 *
 * @return list<array{user_id: int, display_name: string, is_you: bool}>
 */
function admins(): array
{
    $users = get_users(['role' => 'administrator', 'number' => 50, 'orderby' => 'ID', 'fields' => ['ID', 'display_name']]);
    $current = get_current_user_id();
    $list = [];
    foreach (is_array($users) ? $users : [] as $user) {
        $id = (int) ($user->ID ?? 0);
        if (!is_admin_user($id)) {
            continue;
        }
        $list[] = ['user_id' => $id, 'display_name' => (string) ($user->display_name ?? ''), 'is_you' => $id === $current];
    }
    return $list;
}

/**
 * Validate a routines-save input into a stored definition.
 *
 * On update, a field left out keeps its stored value; on create, label, audits and schedule are
 * required. Audit inputs are checked against the ability's own input schema, so a routine that
 * would be refused at 3 a.m. is refused now, while someone is there to fix it.
 *
 * @param array<string, mixed> $input
 * @param array<string, mixed>|null $existing
 * @return array<string, mixed>|WP_Error
 */
function normalize(array $input, ?array $existing, string $id): array|WP_Error
{
    $routine = $existing ?? [
        'id' => $id,
        'enabled' => true,
        'delivery' => ['email_user_ids' => [], 'report' => true],
        'run_as' => get_current_user_id(),
        'created_at' => time(),
    ];
    $routine['id'] = $id;

    if (array_key_exists('label', $input)) {
        $label = trim(sanitize_text_field((string) $input['label']));
        if ($label === '') {
            return new WP_Error('kit_routines_invalid_label', 'label must not be empty.', ['status' => 400]);
        }
        $routine['label'] = mb_substr($label, 0, 80);
    }
    if (array_key_exists('enabled', $input)) {
        $routine['enabled'] = $input['enabled'] === true;
    }
    if (array_key_exists('audits', $input)) {
        $audits = normalize_audits(is_array($input['audits']) ? $input['audits'] : []);
        if ($audits instanceof WP_Error) {
            return $audits;
        }
        $routine['audits'] = $audits;
    }
    if (array_key_exists('schedule', $input)) {
        $schedule = normalize_schedule(is_array($input['schedule']) ? $input['schedule'] : []);
        if ($schedule instanceof WP_Error) {
            return $schedule;
        }
        $routine['schedule'] = $schedule;
    }
    if (array_key_exists('delivery', $input)) {
        $delivery = normalize_delivery(
            is_array($input['delivery']) ? $input['delivery'] : [],
            is_array($routine['delivery'] ?? null) ? $routine['delivery'] : [],
        );
        if ($delivery instanceof WP_Error) {
            return $delivery;
        }
        $routine['delivery'] = $delivery;
    }
    if (array_key_exists('run_as', $input)) {
        $run_as = (int) $input['run_as'];
        if (!is_admin_user($run_as)) {
            return new WP_Error(
                'kit_routines_invalid_run_as',
                sprintf('User %d is not an administrator of this site; a routine runs as one, so the audits pass the same permission checks as an agent\'s call. See admins in wppilot/routines-list.', $run_as),
                ['status' => 400],
            );
        }
        $routine['run_as'] = $run_as;
    }

    foreach (['label', 'audits', 'schedule'] as $required) {
        if (!isset($routine[$required])) {
            return new WP_Error('kit_routines_missing_field', sprintf('A new routine needs %s.', $required), ['status' => 400]);
        }
    }
    if (!is_admin_user((int) ($routine['run_as'] ?? 0))) {
        return new WP_Error('kit_routines_invalid_run_as', 'The routine would run as a user who is not an administrator; pass run_as.', ['status' => 400]);
    }
    $routine['updated_at'] = time();
    return $routine;
}

/**
 * @param list<mixed> $audits
 * @return list<array<string, mixed>>|WP_Error
 */
function normalize_audits(array $audits): array|WP_Error
{
    if ($audits === [] || count($audits) > MAX_AUDITS) {
        return new WP_Error('kit_routines_invalid_audits', sprintf('audits needs 1 to %d entries.', MAX_AUDITS), ['status' => 400]);
    }
    $normalized = [];
    foreach (array_values($audits) as $index => $audit) {
        $audit = is_array($audit) ? $audit : [];
        $name = (string) ($audit['ability'] ?? '');
        $kind = AUDITS[$name] ?? null;
        if ($kind === null) {
            return new WP_Error(
                'kit_routines_ability_not_allowed',
                sprintf('audits[%d]: a routine can run only %s.', $index, implode(', ', array_keys(AUDITS))),
                ['status' => 400],
            );
        }
        $ability = wp_get_ability($name);
        if (!$ability instanceof \WP_Ability) {
            return new WP_Error('kit_routines_ability_missing', sprintf('audits[%d]: %s is not registered on this site.', $index, $name), ['status' => 400]);
        }
        $annotations = $ability->get_meta()['annotations'] ?? [];
        if (!is_array($annotations) || ($annotations['readonly'] ?? false) !== true) {
            return new WP_Error('kit_routines_ability_not_readonly', sprintf('audits[%d]: %s is not read-only, and a routine never writes.', $index, $name), ['status' => 400]);
        }

        $ability_input = is_array($audit['input'] ?? null) ? $audit['input'] : [];
        foreach (RESERVED_INPUTS[$kind] as $reserved) {
            if (array_key_exists($reserved, $ability_input)) {
                return new WP_Error(
                    'kit_routines_reserved_input',
                    sprintf('audits[%d]: %s is set by the routine runner, not in input (%s).', $index, $reserved, reserved_hint($kind)),
                    ['status' => 400],
                );
            }
        }

        $entry = ['ability' => $name, 'input' => $ability_input];
        $probe = $ability_input;
        if ($kind === 'accessibility') {
            $targets = normalize_targets($audit, $index);
            if ($targets instanceof WP_Error) {
                return $targets;
            }
            $entry = array_merge($entry, $targets);
            $probe['url'] = home_url('/');
        } elseif ($kind === 'content') {
            $probe['mode'] = 'background';
        } else {
            $probe['page'] = 1;
            $probe['include'] = 'issues';
        }

        $schema = $ability->get_input_schema();
        if (is_array($schema) && $schema !== [] && function_exists('rest_validate_value_from_schema')) {
            $valid = rest_validate_value_from_schema($probe, $schema, 'input');
            if ($valid instanceof WP_Error) {
                return new WP_Error(
                    'kit_routines_invalid_input',
                    sprintf('audits[%d] input is not valid for %s: %s', $index, $name, $valid->get_error_message()),
                    ['status' => 400],
                );
            }
        }
        $normalized[] = $entry;
    }
    return $normalized;
}

function reserved_hint(string $kind): string
{
    return match ($kind) {
        'accessibility' => 'name pages with urls, front_page or top_pages',
        'content' => 'a routine always audits the whole site in the background',
        default => 'a routine reads up to 10 pages of the most recently uploaded images and keeps only images that need work',
    };
}

/**
 * Which pages an accessibility audit checks: explicit URLs on this site, the front page, and the
 * first N pages of the primary navigation.
 *
 * @param array<string, mixed> $audit
 * @return array{urls: list<string>, front_page: bool, top_pages: int}|WP_Error
 */
function normalize_targets(array $audit, int $index): array|WP_Error
{
    $urls = [];
    foreach (is_array($audit['urls'] ?? null) ? $audit['urls'] : [] as $url) {
        $path = site_path((string) $url);
        if ($path === null) {
            return new WP_Error(
                'kit_routines_invalid_url',
                sprintf('audits[%d]: %s is not a page on this site; give a path such as /contact/ or a URL under %s.', $index, mb_substr((string) $url, 0, 200), home_url('/')),
                ['status' => 400],
            );
        }
        $urls[] = $path;
    }
    $urls = array_values(array_unique($urls));
    $front = ($audit['front_page'] ?? false) === true;
    $top = max(0, min(MAX_URLS, (int) ($audit['top_pages'] ?? 0)));
    if ($urls === [] && !$front && $top === 0) {
        return new WP_Error('kit_routines_no_pages', sprintf('audits[%d]: name the pages to check with urls, front_page or top_pages.', $index), ['status' => 400]);
    }
    if (count($urls) + ($front ? 1 : 0) + $top > MAX_URLS) {
        return new WP_Error('kit_routines_too_many_pages', sprintf('audits[%d]: at most %d pages per accessibility audit.', $index, MAX_URLS), ['status' => 400]);
    }
    return ['urls' => $urls, 'front_page' => $front, 'top_pages' => $top];
}

/**
 * A URL or path on this site as a path relative to the home URL's host, or null for anything else.
 *
 * Kept as a path so a routine survives the site moving between http and https or domains, and so
 * nothing but this site is ever fetched.
 */
function site_path(string $url): ?string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2000) {
        return null;
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return $url;
    }
    $parts = wp_parse_url($url);
    $home = wp_parse_url(home_url('/'));
    if (!is_array($parts) || !is_array($home) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
        return null;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '' || $host !== strtolower((string) ($home['host'] ?? ''))) {
        return null;
    }
    $home_port = (int) ($home['port'] ?? 0);
    if ((int) ($parts['port'] ?? 0) !== $home_port) {
        return null;
    }
    $path = (string) ($parts['path'] ?? '/');
    $path = $path === '' ? '/' : $path;
    return isset($parts['query']) ? $path . '?' . $parts['query'] : $path;
}

/**
 * @param array<string, mixed> $schedule
 * @return array{frequency: string, day: string|null, hour: int}|WP_Error
 */
function normalize_schedule(array $schedule): array|WP_Error
{
    $frequency = (string) ($schedule['frequency'] ?? '');
    if (!in_array($frequency, ['daily', 'weekly'], true)) {
        return new WP_Error('kit_routines_invalid_schedule', 'schedule.frequency is daily or weekly.', ['status' => 400]);
    }
    $hour = $schedule['hour'] ?? null;
    if (!is_int($hour) || $hour < 0 || $hour > 23) {
        return new WP_Error('kit_routines_invalid_schedule', 'schedule.hour is 0 to 23, in the site\'s timezone.', ['status' => 400]);
    }
    $day = null;
    if ($frequency === 'weekly') {
        $day = strtolower((string) ($schedule['day'] ?? ''));
        if (!in_array($day, DAYS, true)) {
            return new WP_Error('kit_routines_invalid_schedule', 'A weekly schedule needs schedule.day (monday to sunday).', ['status' => 400]);
        }
    }
    return ['frequency' => $frequency, 'day' => $day, 'hour' => $hour];
}

/**
 * Recipients are administrators named by user id, never addresses: an agent that could type an
 * address could have the site mail its audit results anywhere.
 *
 * @param array<string, mixed> $delivery
 * @param array<string, mixed> $current
 * @return array{email_user_ids: list<int>, report: bool}|WP_Error
 */
function normalize_delivery(array $delivery, array $current): array|WP_Error
{
    $ids = array_key_exists('email_user_ids', $delivery)
        ? (is_array($delivery['email_user_ids']) ? $delivery['email_user_ids'] : [])
        : (is_array($current['email_user_ids'] ?? null) ? $current['email_user_ids'] : []);
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (count($ids) > MAX_RECIPIENTS) {
        return new WP_Error('kit_routines_too_many_recipients', sprintf('At most %d recipients.', MAX_RECIPIENTS), ['status' => 400]);
    }
    foreach ($ids as $user_id) {
        if (!is_admin_user($user_id)) {
            return new WP_Error(
                'kit_routines_invalid_recipient',
                sprintf('User %d is not an administrator of this site. Results go only to administrators; see admins in wppilot/routines-list.', $user_id),
                ['status' => 400],
            );
        }
    }
    $report = array_key_exists('report', $delivery) ? $delivery['report'] === true : (($current['report'] ?? true) === true);
    if ($ids === [] && !$report) {
        return new WP_Error('kit_routines_no_delivery', 'With no email recipients the report is the only place results go, so delivery.report cannot be false.', ['status' => 400]);
    }
    return ['email_user_ids' => $ids, 'report' => $report];
}

/**
 * A new id from the label: lowercase, hyphenated, unique among the stored routines.
 *
 * @param array<string, array<string, mixed>> $routines
 */
function new_id(string $label, array $routines): string
{
    $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(remove_accents($label))), '-');
    $base = substr($base !== '' ? $base : 'routine', 0, 32);
    $base = rtrim($base, '-');
    $id = $base;
    for ($n = 2; isset($routines[$id]); $n++) {
        $id = $base . '-' . $n;
    }
    return $id;
}

/**
 * The before-image of one routine: its definition and, when a delete will remove them, its
 * stored runs. `null` records that it did not exist, so an undo of a create removes it again.
 *
 * @return array<string, mixed>
 */
function snapshot(string $id, bool $with_reports): array
{
    $snapshot = [
        'type' => STRATEGY,
        'routine_id' => $id,
        'routine' => get_routine($id),
    ];
    if ($with_reports) {
        $snapshot['reports'] = reports($id);
    }
    $snapshot['fingerprint'] = fingerprint($snapshot['routine'], $snapshot['reports'] ?? null);
    return $snapshot;
}

/**
 * @param array<string, mixed>|null $routine
 * @param list<array<string, mixed>>|null $reports
 */
function fingerprint(?array $routine, ?array $reports): string
{
    return hash('sha256', (string) wp_json_encode(['routine' => $routine, 'reports' => $reports]));
}

/**
 * The routine a before-image names, as it is now, in the before-image's shape (with its runs when
 * the before-image kept them): what a session undo checks nothing else has changed, and what a
 * redo hands back to restore().
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|null
 */
function current_state(array $snapshot): ?array
{
    $id = (string) ($snapshot['routine_id'] ?? '');
    return $id !== '' && array_key_exists('routine', $snapshot) ? snapshot($id, array_key_exists('reports', $snapshot)) : null;
}

/**
 * @param array<string, mixed> $snapshot
 */
function state_target(array $snapshot): string
{
    $id = (string) ($snapshot['routine_id'] ?? '');
    return $id === '' ? '' : $id . (array_key_exists('reports', $snapshot) ? ':with-reports' : ':definition');
}

/**
 * The undo for routines-save and routines-delete: put the one routine back as it was (or remove
 * it, if it did not exist), then re-read it. Other routines are left as they are now.
 *
 * @param array<string, mixed> $payload A ledger rollback payload; the snapshot is under `snapshot`.
 * @return array<string, mixed>|WP_Error
 */
function restore(array $payload): array|WP_Error
{
    $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $id = (string) ($snapshot['routine_id'] ?? '');
    if ($id === '' || !array_key_exists('routine', $snapshot)) {
        return new WP_Error('kit_routines_restore_empty', 'This change recorded no routine to restore.');
    }
    /** @var array<string, mixed>|null $routine */
    $routine = is_array($snapshot['routine']) ? $snapshot['routine'] : null;
    $with_reports = array_key_exists('reports', $snapshot);
    put_routine($id, $routine);
    if ($with_reports) {
        store_reports($id, is_array($snapshot['reports']) ? array_values($snapshot['reports']) : []);
    }
    if ($routine === null) {
        forget($id);
    }
    reconcile();

    $observed = get_routine($id);
    $observed_reports = $with_reports ? reports($id) : null;
    return [
        'routine_id' => $id,
        'restored' => $routine === null ? 'removed' : 'definition',
        'reports_restored' => $with_reports,
        'verified' => hash_equals((string) ($snapshot['fingerprint'] ?? ''), fingerprint($observed, $observed_reports)),
    ];
}
