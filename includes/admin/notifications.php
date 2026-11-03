<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * AI change notifications in wp-admin: a Dashboard widget with the latest changes AI agents made
 * through GoBuildWP, an admin notice when there are changes this user has not looked at yet, and
 * a count on the GoBuildWP menu. Free, in every edition, read-only over the change ledger.
 *
 * Site alerts (PHP errors, updates, backups) are GoBuildWP Cloud's job, not wp-admin's.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** User meta: when this user last looked at the changes (Unix time). */
const WPPILOT_NOTIFICATIONS_SEEN_META = 'wppilot_changes_seen_at';

const WPPILOT_NOTIFICATIONS_WIDGET = 'wppilot_notifications';

/** How many recent changes are read; the unseen count stops there ("20+"). */
const WPPILOT_NOTIFICATIONS_LIMIT = 20;

/**
 * The latest AI changes from the change ledger, newest first. Read once per request.
 *
 * @return list<array{id: string, ability: string, who: string, at: int, status: string}>
 */
function wppilot_notifications_recent_changes(): array
{
    static $changes = null;
    if ($changes !== null) {
        return $changes;
    }
    $changes = [];
    if (!function_exists('wppilot_query_change_log')) {
        return $changes;
    }
    foreach (wppilot_query_change_log(['kind' => 'change'], WPPILOT_NOTIFICATIONS_LIMIT) as $entry) {
        $agent = is_array($entry['agent'] ?? null) ? $entry['agent'] : [];
        $user = is_array($entry['user'] ?? null) ? $entry['user'] : [];
        $who = trim((string) ($agent['label'] ?? '')) ?: trim((string) ($agent['client'] ?? '')) ?: (string) ($user['login'] ?? '');
        $ability = (string) ($entry['ability'] ?? '');
        $registered = function_exists('wp_get_ability') && $ability !== '' ? wp_get_ability($ability) : null;
        $changes[] = [
            'id' => (string) ($entry['id'] ?? ''),
            'ability' => $registered !== null ? (string) $registered->get_label() : $ability,
            'who' => $who,
            'at' => (int) strtotime((string) ($entry['recorded_at'] ?? '')),
            'status' => wppilot_change_status($entry),
        ];
    }

    return $changes;
}

/**
 * Changes made after the user last looked. Pure.
 *
 * @param list<array{at: int}> $changes
 * @return list<array{at: int}>
 */
function wppilot_notifications_unseen(array $changes, int $seen_at): array
{
    return array_values(array_filter($changes, static fn(array $c): bool => $c['at'] > $seen_at));
}

/**
 * When the user last looked at the changes. A user who never did starts now, so turning the
 * feature on does not flag the whole history.
 */
function wppilot_notifications_seen_at(int $user_id): int
{
    $stored = get_user_meta($user_id, WPPILOT_NOTIFICATIONS_SEEN_META, single: true);
    if (is_numeric($stored)) {
        return (int) $stored;
    }
    $now = time();
    update_user_meta($user_id, WPPILOT_NOTIFICATIONS_SEEN_META, $now);

    return $now;
}

function wppilot_notifications_mark_seen(int $user_id): void
{
    update_user_meta($user_id, WPPILOT_NOTIFICATIONS_SEEN_META, time());
}

/** @return list<array{id: string, ability: string, who: string, at: int, status: string}> */
function wppilot_notifications_unseen_for_current_user(): array
{
    /** @var list<array{id: string, ability: string, who: string, at: int, status: string}> */
    return wppilot_notifications_unseen(wppilot_notifications_recent_changes(), wppilot_notifications_seen_at(get_current_user_id()));
}

function wppilot_notifications_count_label(int $count): string
{
    return $count >= WPPILOT_NOTIFICATIONS_LIMIT ? WPPILOT_NOTIFICATIONS_LIMIT . '+' : (string) $count;
}

function wppilot_notifications_seen_url(): string
{
    return wp_nonce_url(add_query_arg(['action' => 'wppilot_notification_seen'], admin_url('admin-post.php')), action: 'wppilot_notification_seen');
}

function wppilot_notifications_handle_seen(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to do that.', domain: 'wppilot'), '', ['response' => 403]);
    }
    check_admin_referer('wppilot_notification_seen');
    wppilot_notifications_mark_seen(get_current_user_id());
    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit();
}

/** Opening the Changes screen counts as having looked. */
function wppilot_notifications_seen_on_changes_screen(): void
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen is open; only the user's own seen time is written.
    if (isset($_GET['page']) && $_GET['page'] === 'wppilot-changes' && wppilot_current_user_can_manage()) {
        wppilot_notifications_mark_seen(get_current_user_id());
    }
}

function wppilot_notifications_ago(int $timestamp): string
{
    if ($timestamp <= 0) {
        return '';
    }
    /* translators: %s: human-readable time difference, e.g. "5 mins". */
    return sprintf(__('%s ago', domain: 'wppilot'), human_time_diff($timestamp, time()));
}

function wppilot_notifications_render_notice(): void
{
    if (!wppilot_current_user_can_manage()) {
        return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $screen_id = $screen ? (string) $screen->id : '';
    // The Changes screen already lists them; the WordPress.org edition keeps notices to GoBuildWP's own screens.
    if (str_contains($screen_id, 'wppilot-changes') || (wppilot_is_wordpress_org_edition() && !str_contains($screen_id, 'wppilot'))) {
        return;
    }
    $unseen = wppilot_notifications_unseen_for_current_user();
    if ($unseen === []) {
        return;
    }
    $newest = $unseen[0];
    $count = count($unseen);
    ?>
    <div class="notice notice-info" data-wppilot-notification>
        <p>
            <strong><?php esc_html_e('GoBuildWP:', domain: 'wppilot'); ?></strong>
            <?php
            echo esc_html(sprintf(
                /* translators: %s: number of changes, e.g. "3" or "20+". */
                _n('AI made %s change since you last looked.', 'AI made %s changes since you last looked.', $count, 'wppilot'),
                wppilot_notifications_count_label($count),
            ));
            ?>
            <span class="description">
                <?php
                /* translators: 1: change name, 2: who made it, 3: how long ago. */
                echo esc_html(sprintf(__('Latest: %1$s by %2$s, %3$s.', domain: 'wppilot'), $newest['ability'], $newest['who'] ?: __('an AI agent', domain: 'wppilot'), wppilot_notifications_ago($newest['at'])));
                ?>
            </span>
        </p>
        <p>
            <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=wppilot-changes')); ?>"><?php esc_html_e('Review changes', domain: 'wppilot'); ?></a>
            <a class="button-link" href="<?php echo esc_url(wppilot_notifications_seen_url()); ?>"><?php esc_html_e('Mark as seen', domain: 'wppilot'); ?></a>
        </p>
    </div>
    <?php
}

function wppilot_notifications_register_widget(): void
{
    if (!wppilot_current_user_can_manage()) {
        return;
    }
    wp_add_dashboard_widget(WPPILOT_NOTIFICATIONS_WIDGET, esc_html__('GoBuildWP: AI changes', domain: 'wppilot'), 'wppilot_notifications_render_widget');
}

function wppilot_notifications_render_widget(): void
{
    $changes = array_slice(wppilot_notifications_recent_changes(), 0, 8);
    $seen_at = wppilot_notifications_seen_at(get_current_user_id());
    $link = function_exists('wppilot_cloud_link') ? wppilot_cloud_link() : null;
    ?>
    <div class="wppilot-notifications" data-wppilot-widget>
        <?php if ($changes === []) { ?>
            <p><?php esc_html_e('No AI changes yet. Changes your AI agents make through GoBuildWP show here, each with undo.', domain: 'wppilot'); ?></p>
        <?php } else { ?>
            <ul style="margin:0;">
                <?php foreach ($changes as $change) { ?>
                    <li style="display:flex;gap:8px;align-items:flex-start;margin-bottom:8px;"<?php echo $change['at'] > $seen_at ? ' data-unseen' : ''; ?>>
                        <span aria-hidden="true" style="flex:none;width:8px;height:8px;margin-top:6px;border-radius:50%;background:<?php echo $change['at'] > $seen_at ? '#2271b1' : '#dcdcde'; ?>;"></span>
                        <span style="flex:1;">
                            <a href="<?php echo esc_url(add_query_arg(['page' => 'wppilot-changes', 'change' => rawurlencode($change['id'])], admin_url('admin.php'))); ?>"><?php echo esc_html($change['ability']); ?></a>
                            <br><span class="description">
                                <?php echo esc_html(implode(' · ', array_filter([$change['who'], wppilot_notifications_ago($change['at']), $change['status'] === 'rolled-back' ? __('Undone', domain: 'wppilot') : '']))); ?>
                            </span>
                        </span>
                    </li>
                <?php } ?>
            </ul>
            <p style="margin:4px 0 0;"><a href="<?php echo esc_url(admin_url('admin.php?page=wppilot-changes')); ?>"><?php esc_html_e('All changes and undo', domain: 'wppilot'); ?></a></p>
        <?php } ?>

        <p style="margin:16px 0 0;padding-top:12px;border-top:1px solid #f0f0f1;">
            <?php if ($link !== null) { ?>
                <?php esc_html_e('Site alerts (errors, updates, backups) are in GoBuildWP Cloud.', domain: 'wppilot'); ?>
                <a href="<?php echo esc_url($link['cloud_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open Cloud', domain: 'wppilot'); ?></a>
                ·
                <a href="<?php echo esc_url(WPPILOT_CLOUD_PRO_URL . '?utm_source=plugin&utm_medium=dashboard_widget'); ?>" target="_blank" rel="noopener"><?php esc_html_e('Cloud Pro: vulnerability and email alerts', domain: 'wppilot'); ?></a>
            <?php } else { ?>
                <a href="<?php echo esc_url(wppilot_cloud_admin_url()); ?>"><?php esc_html_e('Connect to GoBuildWP Cloud', domain: 'wppilot'); ?></a>
                <?php esc_html_e('(free) for site alerts: errors, updates and backups across every site.', domain: 'wppilot'); ?>
            <?php } ?>
        </p>
    </div>
    <?php
}

/**
 * The number of unseen AI changes on the GoBuildWP menu, like WordPress's own update count.
 */
function wppilot_notifications_menu_count(): void
{
    if (!wppilot_current_user_can_manage()) {
        return;
    }
    // @mago-expect lint:no-global
    global $menu;
    if (!is_array($menu)) {
        return;
    }
    $count = count(wppilot_notifications_unseen_for_current_user());
    if ($count < 1) {
        return;
    }
    foreach ($menu as $position => $entry) {
        if (is_array($entry) && ($entry[2] ?? '') === 'wppilot-connect') {
            $menu[$position][0] .= sprintf(' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>', $count, esc_html(wppilot_notifications_count_label($count)));
            break;
        }
    }
}

add_action('wp_dashboard_setup', 'wppilot_notifications_register_widget');
add_action('admin_notices', 'wppilot_notifications_render_notice');
add_action('admin_post_wppilot_notification_seen', 'wppilot_notifications_handle_seen');
add_action('admin_init', 'wppilot_notifications_seen_on_changes_screen');
add_action('admin_menu', 'wppilot_notifications_menu_count', priority: 100);
