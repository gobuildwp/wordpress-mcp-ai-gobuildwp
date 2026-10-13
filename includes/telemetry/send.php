<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Telemetry;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Build the report and send it.
 *
 * What goes out is fixed and listed in one place below, so that "what does this
 * send" is answerable by reading a single function rather than by tracing the
 * code. Everything in it describes the software and the environment. Nothing in
 * it describes a person or anything a person wrote.
 */

/**
 * The whole report.
 *
 * Deliberately absent, and worth stating so a future change has to argue with
 * it: no usernames, no email addresses, no post or page content, no titles, no
 * record of which abilities an agent called or what it changed, no IP address
 * (the receiving end hashes the connecting address for rate limiting and stores
 * nothing else), no licence key.
 *
 * @return array<string, mixed>
 */
function payload(string $event): array
{
    $connections = function_exists('wppilot_get_connections') ? count(wppilot_get_connections(limit: 200)) : 0;

    return [
        'install_id' => install_id(),
        'event' => $event,
        'site' => home_url(),
        'version' => defined('WPPILOT_VERSION') ? WPPILOT_VERSION : null,
        'pro' => function_exists('wppilot_pro_is_active') ? wppilot_pro_is_active() : defined('WPPILOT_PRO_VERSION'),
        'pro_version' => defined('WPPILOT_PRO_VERSION') ? WPPILOT_PRO_VERSION : null,
        'wp' => get_bloginfo('version'),
        'php' => PHP_VERSION,
        'locale' => get_locale(),
        'multisite' => is_multisite(),
        'profile' => function_exists('wppilot_get_safety_profile') ? wppilot_get_safety_profile() : null,
        'abilities_enabled' => function_exists('wppilot_is_enabled') ? (bool) wppilot_is_enabled() : false,
        'connections' => $connections,
    ];
}

/**
 * POST one report.
 *
 * The daily ping is non-blocking: a site owner must never wait on our server,
 * and a report that is dropped costs nothing — the next one is a day away.
 *
 * One-off events block briefly, because there is no next one. A deactivation or
 * an opt-out that is never delivered leaves the install looking active forever,
 * and an opt-out in particular is a deletion request. Three seconds is the cap.
 *
 * Failures are swallowed at every level. Telemetry must never produce a warning
 * on someone's dashboard, and there is nothing a site owner could do about it.
 */
function send(string $event = 'ping'): void
{
    if (!enabled() && $event !== 'opt-out') {
        return;
    }

    $one_off = $event !== 'ping';

    wp_remote_post(ENDPOINT, [
        'timeout' => $one_off ? 3 : 5,
        'blocking' => $one_off,
        'redirection' => 0,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode(payload($event)),
        'user-agent' => 'GoBuildWP/' . (defined('WPPILOT_VERSION') ? WPPILOT_VERSION : '0') . '; ' . home_url(),
    ]);
}

/**
 * Put the daily report on the schedule, jittered by one to seven hours.
 *
 * The jitter spreads load across the day instead of stacking every install that
 * switched reporting on in the same hour onto the same minute. It also means an
 * administrator who turns it on, reconsiders and turns it off again in that
 * first session is never counted at all — the first report has not fired yet.
 */
function schedule(): void
{
    if (wp_next_scheduled(CRON_HOOK) !== false) {
        return;
    }

    wp_schedule_event(time() + random_int(3600, 7 * 3600), 'daily', CRON_HOOK);
}

function unschedule(): void
{
    wp_clear_scheduled_hook(CRON_HOOK);
}

/**
 * Tell us the plugin is going away.
 *
 * Called from deactivation. Without it an abandoned install stays "active" in
 * the numbers until it falls out of the 45-day window, which turns a real
 * churn signal into a slow fade.
 */
function send_deactivation(): void
{
    if (!enabled()) {
        return;
    }

    send('deactivate');
}
