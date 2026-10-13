<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The hourly heartbeat, §5.
 *
 * Tells the Cloud which versions and safety profile this site runs, so its
 * dashboard is current without calling the site. Scheduled only while the site
 * is connected, and sent once more after a plugin upgrade so a new version
 * shows up without waiting out the hour.
 */

if (!defined('ABSPATH')) {
    exit();
}

function wppilot_cloud_schedule_heartbeat(): void
{
    if (wp_next_scheduled(WPPILOT_CLOUD_HEARTBEAT_HOOK) === false) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', WPPILOT_CLOUD_HEARTBEAT_HOOK);
    }
}

/**
 * The heartbeat payload after site_id, ts and nonce.
 *
 * `event` is present only on an event push (events.php); the hourly heartbeat
 * is unchanged.
 *
 * @return array{versions: array{wp: string, php: string, plugin: string, pro: string|null}, safety_profile: string, home_url: string, event?: string}
 */
function wppilot_cloud_heartbeat_payload(?string $event = null): array
{
    $payload = [
        'versions' => wppilot_cloud_versions(),
        'safety_profile' => wppilot_cloud_safety_profile(),
        'home_url' => home_url(),
    ];
    if ($event !== null) {
        $payload['event'] = $event;
    }

    return $payload;
}

/**
 * Cron: send one heartbeat, or an event push when $event is given.
 *
 * A link whose token is gone - revoked another way, or its owner deleted - is
 * a pairing that can no longer work, so it is disconnected properly (the Cloud
 * is told) rather than kept beating for a site the Cloud cannot reach.
 */
function wppilot_cloud_send_heartbeat(?string $event = null): void
{
    $link = wppilot_cloud_link();
    if ($link === null) {
        wp_clear_scheduled_hook(WPPILOT_CLOUD_HEARTBEAT_HOOK);
        return;
    }

    if (function_exists('wppilot_token_policy') && wppilot_token_policy($link['token_id']) === null && wppilot_cloud_token_row_gone($link['token_id'])) {
        wppilot_cloud_disconnect();
        return;
    }

    $base = wppilot_cloud_normalize_url($link['cloud_url']);
    if ($base === '') {
        return;
    }

    $response = wppilot_cloud_signed_post(
        $base,
        '/api/sites/heartbeat',
        $link['site_id'],
        wppilot_cloud_heartbeat_payload($event),
    );

    wppilot_cloud_handle_signed_response($response, $link['site_id']);
}

/**
 * Act on a §5 answer: 404 unknown_site means the Cloud has forgotten this
 * site, so the link goes too. Anything else changes nothing - a Cloud outage
 * must not disconnect every site it serves.
 *
 * @param array{status: int, body: array<array-key, mixed>}|WP_Error $response
 */
function wppilot_cloud_handle_signed_response(array|WP_Error $response, string|int $site_id): void
{
    if (is_wp_error($response)) {
        return;
    }

    if ($response['status'] === 404 && ($response['body']['error'] ?? null) === 'unknown_site') {
        wppilot_cloud_forget_link($site_id);
    }
}

/**
 * admin_init: keep the schedule in step with the link, and report an upgrade.
 *
 * Repairs a schedule lost to a database restore or a deactivate/reactivate,
 * and clears one that outlived its link. The version comparison is the
 * fallback for upgrades that did not go through the upgrader (a zip copied
 * into place); upgrader_process_complete below catches the rest.
 */
function wppilot_cloud_maintain_schedule(): void
{
    $link = wppilot_cloud_link();
    if ($link === null) {
        if (wp_next_scheduled(WPPILOT_CLOUD_HEARTBEAT_HOOK) !== false) {
            wp_clear_scheduled_hook(WPPILOT_CLOUD_HEARTBEAT_HOOK);
        }
        return;
    }

    wppilot_cloud_schedule_heartbeat();

    if (get_option(WPPILOT_CLOUD_SEEN_VERSION_OPTION, default_value: '') !== WPPILOT_VERSION) {
        update_option(WPPILOT_CLOUD_SEEN_VERSION_OPTION, WPPILOT_VERSION, autoload: false);
        wp_schedule_single_event(time(), WPPILOT_CLOUD_HEARTBEAT_HOOK);
    }
}

/**
 * True only when the tokens table answered and the row is not there. A failed
 * query (database hiccup during cron) must not unpair the site: the next
 * heartbeat tries again.
 */
function wppilot_cloud_token_row_gone(int $token_id): bool
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */

    if ($token_id <= 0) {
        return true;
    }
    $table = wppilot_tokens_table();
    // @mago-expect analysis:mixed-assignment
    $count = $wpdb->get_var((string) $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE id = %d", $token_id));
    if ($wpdb->last_error !== '' || $count === null) {
        return false;
    }

    return (int) $count === 0;
}

/**
 * upgrader_process_complete: queue a heartbeat for the next request.
 *
 * Queued rather than sent: this runs in the request that did the upgrade, with
 * the previous version's code and constants still loaded, so a heartbeat sent
 * now would report the old version.
 *
 * @param mixed $upgrader
 * @param mixed $options
 */
function wppilot_cloud_on_upgrade(mixed $upgrader, mixed $options = []): void
{
    unset($upgrader);
    if (!is_array($options) || ($options['type'] ?? '') !== 'plugin') {
        return;
    }

    // Bulk updates pass `plugins`; single and auto-updates pass `plugin`.
    $plugins = is_array($options['plugins'] ?? null) ? $options['plugins'] : [];
    if (is_string($options['plugin'] ?? null)) {
        $plugins[] = $options['plugin'];
    }
    if (!in_array(plugin_basename(WPPILOT_PLUGIN_FILE), $plugins, strict: true)) {
        return;
    }

    if (wppilot_cloud_link() !== null) {
        wp_schedule_single_event(time(), WPPILOT_CLOUD_HEARTBEAT_HOOK);
    }
}
