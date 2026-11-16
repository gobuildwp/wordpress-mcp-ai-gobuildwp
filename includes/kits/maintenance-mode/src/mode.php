<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\MaintenanceMode;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Ledger;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The switch: {enabled, message, until, started, by}. Autoloaded, because every front-end request
 * reads it.
 */
const OPTION = 'wppilot_kit_maintenance_mode';

/** Return true to let the current visitor see the site while it is down. Default: manage_options. */
const BYPASS_FILTER = 'wppilot_kit_maintenance_mode_bypass';

const STRATEGY = 'kits/maintenance-mode';

const MAX_MESSAGE_LENGTH = 1000;

const MIN_MINUTES = 1;
const MAX_MINUTES = 10080;
const DEFAULT_MINUTES = 60;

/** How far ahead a maintenance window may be scheduled: 30 days. */
const MAX_LEAD_SECONDS = 2592000;

const ADMIN_BAR_NODE = 'wppilot-kit-maintenance-mode';

function register_hooks(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    // Early, so a page cache plugin or a theme redirect running on the same hook comes after.
    add_action('template_redirect', __NAMESPACE__ . '\\on_template_redirect', 0);
    add_action('admin_bar_menu', __NAMESPACE__ . '\\on_admin_bar_menu', 999);
    add_action('admin_bar_init', __NAMESPACE__ . '\\on_admin_bar_init');
}

/**
 * The stored switch, normalised. An absent or damaged option reads as off.
 *
 * `starts` is when a scheduled window opens; 0 for one that started when it was switched on.
 *
 * @return array{enabled: bool, message: string, until: int, started: int, by: int, starts: int}
 */
function read(): array
{
    $stored = get_option(OPTION, []);
    $stored = is_array($stored) ? $stored : [];
    return [
        'enabled' => ($stored['enabled'] ?? false) === true,
        'message' => is_string($stored['message'] ?? null) ? $stored['message'] : '',
        'until' => max(0, (int) ($stored['until'] ?? 0)),
        'started' => max(0, (int) ($stored['started'] ?? 0)),
        'by' => max(0, (int) ($stored['by'] ?? 0)),
        'starts' => max(0, (int) ($stored['starts'] ?? 0)),
    ];
}

/**
 * Whether visitors are being turned away right now. Expiry is read, not written: the first
 * request after `until` simply serves the site, with no write on the front end.
 *
 * @param array{enabled: bool, until: int, starts?: int} $state
 */
function is_active(array $state, int $now): bool
{
    return $state['enabled'] && ($state['starts'] ?? 0) <= $now && $state['until'] > $now;
}

/**
 * Whether a window is set for later and has not opened yet.
 *
 * @param array{enabled: bool, until: int, starts?: int} $state
 */
function is_scheduled(array $state, int $now): bool
{
    return $state['enabled'] && ($state['starts'] ?? 0) > $now && $state['until'] > $now;
}

function default_message(): string
{
    return __('This site is briefly down for maintenance. Please check back soon.', domain: 'wppilot');
}

function visitor_bypasses(): bool
{
    return (bool) apply_filters(BYPASS_FILTER, current_user_can('manage_options'));
}

/**
 * What the current front-end request gets: null to serve the site, or the 503 to send.
 *
 * @return array{status: int, retry_after: int, message: string}|null
 */
function decision(int $now): ?array
{
    $state = read();
    if (!is_active($state, $now) || visitor_bypasses()) {
        return null;
    }
    return [
        'status' => 503,
        'retry_after' => max(1, $state['until'] - $now),
        'message' => $state['message'] !== '' ? $state['message'] : default_message(),
    ];
}

/**
 * Serve the maintenance page. Runs on template_redirect only, so wp-login.php, wp-admin, the REST
 * API, admin-ajax and WP-Cron keep working, which is what lets an administrator sign in and an
 * agent switch it off again.
 */
function on_template_redirect(): void
{
    $decision = decision(time());
    if ($decision === null) {
        return;
    }
    if (!headers_sent()) {
        nocache_headers();
        header('Retry-After: ' . $decision['retry_after']);
    }
    $title = __('Down for maintenance', domain: 'wppilot');
    wp_die(
        '<h1>' . esc_html($title) . '</h1><p>' . nl2br(esc_html($decision['message'])) . '</p>',
        esc_html($title),
        ['response' => $decision['status']],
    );
}

/**
 * The admin bar notice, shown to the people who still see the site, so nobody forgets it is down
 * for everyone else.
 */
function on_admin_bar_menu(object $admin_bar): void
{
    $now = time();
    $state = read();
    if (!is_active($state, $now) || !visitor_bypasses() || !method_exists($admin_bar, 'add_node')) {
        return;
    }
    $minutes = (int) ceil(($state['until'] - $now) / 60);
    $admin_bar->add_node([
        'id' => ADMIN_BAR_NODE,
        'title' => esc_html__('Maintenance mode', domain: 'wppilot'),
        'meta' => [
            'class' => ADMIN_BAR_NODE,
            'title' => sprintf(
                /* translators: %d: minutes until maintenance mode switches itself off. */
                __('Visitors get a maintenance page. It switches off by itself in %d min.', domain: 'wppilot'),
                $minutes,
            ),
        ],
    ]);
}

function on_admin_bar_init(): void
{
    if (!is_active(read(), time())) {
        return;
    }
    wp_add_inline_style(
        'admin-bar',
        '#wpadminbar .' . ADMIN_BAR_NODE . ' > .ab-item{background:#b32d2e;color:#fff}',
    );
}

/** @return array<string, mixed> */
function view(int $now): array
{
    $state = read();
    $active = is_active($state, $now);
    $scheduled = is_scheduled($state, $now);
    return [
        'active' => $active,
        'scheduled' => $scheduled,
        'starts_at' => $scheduled ? $state['starts'] : null,
        'starts_at_iso' => $scheduled ? gmdate('c', $state['starts']) : null,
        'expired' => $state['enabled'] && !$active && !$scheduled,
        'message' => $state['message'] !== '' ? $state['message'] : default_message(),
        'custom_message' => $state['message'] !== '',
        'until' => $state['enabled'] ? $state['until'] : null,
        'until_iso' => $state['enabled'] && $state['until'] > 0 ? gmdate('c', $state['until']) : null,
        'seconds_left' => $active ? $state['until'] - $now : 0,
        'started' => $state['enabled'] ? $state['started'] : null,
        'started_by' => $state['enabled'] && $state['by'] > 0 ? $state['by'] : null,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function status(array $input = []): array
{
    unset($input);
    return view(time());
}

/**
 * Switch maintenance mode on for N minutes, or off.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function set(array $input): array|WP_Error
{
    if (!array_key_exists('enabled', $input) || !is_bool($input['enabled'])) {
        return new WP_Error('kit_maintenance_bad_input', 'enabled is required: true to switch maintenance mode on, false to switch it off.', ['status' => 400]);
    }
    $enable = $input['enabled'];
    $now = time();
    $before_raw = get_option(OPTION, null);
    $before = read();

    if (!$enable) {
        if (!$before['enabled']) {
            return ['changed' => false, 'change_id' => null] + view($now);
        }
        $after = ['enabled' => false, 'message' => $before['message'], 'until' => 0, 'started' => 0, 'by' => 0, 'starts' => 0];
    } else {
        $minutes = (int) ($input['minutes'] ?? DEFAULT_MINUTES);
        if ($minutes < MIN_MINUTES || $minutes > MAX_MINUTES) {
            return new WP_Error('kit_maintenance_bad_minutes', sprintf('minutes is %d to %d.', MIN_MINUTES, MAX_MINUTES), ['status' => 400]);
        }
        $starts = 0;
        if (array_key_exists('starts_at', $input) && $input['starts_at'] !== null) {
            $starts = is_int($input['starts_at']) ? $input['starts_at'] : -1;
            if ($starts < 0 || $starts > $now + MAX_LEAD_SECONDS) {
                return new WP_Error('kit_maintenance_bad_start', 'starts_at is a Unix time from now to 30 days ahead.', ['status' => 400]);
            }
            // A start already passed (a retry after an approval, say) opens the window now.
            $starts = $starts > $now ? $starts : 0;
        }
        $opens = $starts > 0 ? $starts : $now;
        $message = array_key_exists('message', $input) ? sanitize_textarea_field((string) $input['message']) : $before['message'];
        if (mb_strlen($message) > MAX_MESSAGE_LENGTH) {
            return new WP_Error('kit_maintenance_message_too_long', sprintf('message is at most %d characters.', MAX_MESSAGE_LENGTH), ['status' => 400]);
        }
        if (($input['confirm'] ?? null) !== true) {
            return new WP_Error(
                'kit_confirmation_required',
                sprintf(
                    'This turns every visitor who is not an administrator away with a 503 "down for maintenance" page for %d minutes (%s to %s UTC), after which the site comes back by itself. Administrators, wp-admin, wp-login.php and the REST API are unaffected. Obtain explicit user approval, then retry with confirm=true.',
                    $minutes,
                    gmdate('Y-m-d H:i', $opens),
                    gmdate('Y-m-d H:i', $opens + $minutes * 60),
                ),
                ['status' => 409],
            );
        }
        $after = [
            'enabled' => true,
            'message' => $message,
            'until' => $opens + $minutes * 60,
            'started' => $starts > 0 ? $starts : (is_active($before, $now) ? $before['started'] : $now),
            'by' => get_current_user_id(),
            'starts' => $starts,
        ];
    }

    update_option(OPTION, $after, true);
    if (read() !== $after) {
        return new WP_Error('kit_maintenance_not_saved', 'The setting was not stored; the options table refused the write.', ['status' => 500]);
    }

    $recorded = Runtime\host()->ledger()->record_items('wppilot/set-maintenance-mode', [[
        'input' => ['enabled' => $enable, 'minutes' => $enable ? (int) ($input['minutes'] ?? DEFAULT_MINUTES) : null, 'starts_at' => $enable && $after['starts'] > 0 ? $after['starts'] : null],
        'before' => ['type' => STRATEGY, 'existed' => $before_raw !== null, 'value' => $before_raw],
        'result' => ['enabled' => $after['enabled'], 'until' => $after['until']],
        'item' => ['option' => OPTION],
    ]]);

    return ['changed' => true, 'change_id' => $recorded['change_ids'][0] ?? null] + view($now);
}

/**
 * Put the switch back as it was before a change, then re-read it.
 *
 * @param array<string, mixed> $payload A ledger rollback payload; the snapshot is under `snapshot`.
 * @return array<string, mixed>|WP_Error
 */
function restore(array $payload): array|WP_Error
{
    $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    if (!array_key_exists('existed', $snapshot)) {
        return new WP_Error('kit_rollback_bad_snapshot', 'The change record does not hold the maintenance mode setting.');
    }
    if ($snapshot['existed'] === true) {
        update_option(OPTION, $snapshot['value'] ?? null, true);
        $verified = get_option(OPTION, null) == ($snapshot['value'] ?? null);
    } else {
        delete_option(OPTION);
        $verified = get_option(OPTION, null) === null;
    }
    return ['option' => OPTION, 'active' => is_active(read(), time()), 'verified' => $verified];
}

function register(Ledger $ledger): void
{
    $ledger->register_strategy(STRATEGY, static fn(array $payload): array|WP_Error => restore($payload));
}
