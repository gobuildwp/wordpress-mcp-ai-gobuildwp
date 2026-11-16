<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * New-app approval: optionally hold a newly seen OAuth client until an administrator approves it.
 *
 * Without it, any client that can register itself (Dynamic Client Registration is unauthenticated
 * by design) or publish a Client ID Metadata Document is one consent click away from a token: an
 * administrator who follows a crafted link and presses Authorize has connected it. With approval on,
 * a client this site has not approved stops at a "waiting for approval" page instead of the consent
 * screen, and the token endpoint refuses it, until an administrator approves it under Connected Apps
 * — a separate, deliberate step on a page the client cannot link to with its own parameters.
 *
 * Turning approval on approves every client the site already knows (registered, or holding a live
 * token), so existing connections keep working. Clients created from the troubleshooter are
 * created by an administrator and are always approved.
 */

namespace WPPilot\OAuth\AppApproval;

if (!defined('ABSPATH')) {
    exit();
}

const OPTION_ENABLED = 'wppilot_oauth_require_app_approval';

const APPROVED_OPTION = 'wppilot_oauth_approved_clients';

const PENDING_OPTION = 'wppilot_oauth_pending_clients';

/** Pending requests kept for review; the oldest is dropped past this, so a flood cannot grow it. */
const MAX_PENDING = 50;

const MAX_APPROVED = 500;

const APPROVE_ACTION = 'wppilot_oauth_approve_client';

const REJECT_ACTION = 'wppilot_oauth_reject_client';

function enabled(): bool
{
    return get_option(OPTION_ENABLED, default_value: '0') === '1';
}

/**
 * @return list<string>
 */
function approved_clients(): array
{
    /** @var mixed $stored */
    $stored = get_option(APPROVED_OPTION, default_value: []);

    return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
}

/**
 * @return array<string, array{name: string, at: int}>
 */
function pending_clients(): array
{
    /** @var mixed $stored */
    $stored = get_option(PENDING_OPTION, default_value: []);
    if (!is_array($stored)) {
        return [];
    }
    $pending = [];
    foreach ($stored as $client_id => $entry) {
        if (is_string($client_id) && is_array($entry)) {
            $pending[$client_id] = [
                'name' => is_string($entry['name'] ?? null) ? $entry['name'] : '',
                'at' => is_int($entry['at'] ?? null) ? $entry['at'] : 0,
            ];
        }
    }

    return $pending;
}

/**
 * Whether a client created from the troubleshooter, by an administrator.
 */
function is_admin_created(string $client_id): bool
{
    global $wpdb;
    if (!isset($wpdb) || !is_object($wpdb)) {
        return false;
    }
    /** @var \wpdb $wpdb */
    $value = $wpdb->get_var($wpdb->prepare(
        'SELECT admin_created FROM %i WHERE client_id = %s LIMIT 1',
        $wpdb->prefix . 'wppilot_oauth_clients',
        $client_id,
    ));

    return (string) $value === '1';
}

/**
 * Whether a client may authorize and use tokens. Always true while approval is off.
 */
function is_approved(string $client_id): bool
{
    if (!enabled()) {
        return true;
    }

    return in_array($client_id, approved_clients(), strict: true) || is_admin_created($client_id);
}

/**
 * Record a client that asked to connect while unapproved, for the Connected Apps screen.
 */
function note_pending(string $client_id, string $name): void
{
    $pending = pending_clients();
    if (isset($pending[$client_id])) {
        return;
    }
    $pending[$client_id] = ['name' => mb_substr($name, 0, 191), 'at' => time()];
    if (count($pending) > MAX_PENDING) {
        uasort($pending, static fn(array $a, array $b): int => $a['at'] <=> $b['at']);
        $pending = array_slice($pending, -MAX_PENDING, preserve_keys: true);
    }
    update_option(PENDING_OPTION, $pending, autoload: false);
}

function approve(string $client_id): void
{
    $approved = approved_clients();
    if (!in_array($client_id, $approved, strict: true)) {
        $approved[] = $client_id;
    }
    if (count($approved) > MAX_APPROVED) {
        $approved = array_slice($approved, -MAX_APPROVED);
    }
    update_option(APPROVED_OPTION, array_values($approved), autoload: true);

    $pending = pending_clients();
    $name = $pending[$client_id]['name'] ?? '';
    unset($pending[$client_id]);
    update_option(PENDING_OPTION, $pending, autoload: false);

    /**
     * Fires when an administrator approves an OAuth client under new-app approval.
     */
    do_action('wppilot_oauth_client_approved', $client_id, $name, get_current_user_id());
}

function reject(string $client_id): void
{
    $pending = pending_clients();
    $name = $pending[$client_id]['name'] ?? '';
    unset($pending[$client_id]);
    update_option(PENDING_OPTION, $pending, autoload: false);

    do_action('wppilot_oauth_client_rejected', $client_id, $name, get_current_user_id());
}

/**
 * Every client the site already knows: registered rows and clients holding a live grant.
 *
 * @return list<string>
 */
function known_clients(): array
{
    global $wpdb;
    if (!isset($wpdb) || !is_object($wpdb)) {
        return [];
    }
    /** @var \wpdb $wpdb */
    $p = $wpdb->prefix . 'wppilot_oauth_';
    $ids = [];
    foreach ([
        $wpdb->prepare('SELECT client_id FROM %i', $p . 'clients'),
        $wpdb->prepare('SELECT DISTINCT client_id FROM %i WHERE revoked = 0', $p . 'access_tokens'),
        $wpdb->prepare('SELECT DISTINCT client_id FROM %i WHERE revoked = 0', $p . 'refresh_tokens'),
    ] as $sql) {
        /** @var mixed $column */
        $column = $wpdb->get_col((string) $sql);
        if (is_array($column)) {
            foreach ($column as $id) {
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }
    }

    return array_values(array_unique($ids));
}

function set_enabled(bool $on): void
{
    if ($on && !enabled()) {
        update_option(APPROVED_OPTION, array_slice(array_values(array_unique(array_merge(approved_clients(), known_clients()))), -MAX_APPROVED), autoload: true);
    }
    update_option(OPTION_ENABLED, $on ? '1' : '0', autoload: true);
}

/**
 * The page an unapproved client's authorization request stops at.
 */
function die_pending(string $client_id, string $name): void
{
    note_pending($client_id, $name);
    wp_die(
        '<p>' . esc_html(sprintf(
            /* translators: %s: application name */
            __('"%s" has not been approved to connect to this site yet. New-app approval is on, so an administrator has to approve it under GoBuildWP > Connected Apps first. After that, start the connection again from the app.', domain: 'wppilot'),
            $name !== '' ? $name : $client_id,
        )) . '</p><p><a href="' . esc_url(admin_url('admin.php?page=wppilot-connected-apps#wppilot-pending-apps')) . '">'
            . esc_html__('Review apps waiting for approval', domain: 'wppilot') . '</a></p>',
        esc_html__('App waiting for approval', domain: 'wppilot'),
        ['response' => 403],
    );
}

/**
 * Pending apps, at the top of Connected Apps.
 */
function render_pending_section(): void
{
    if (!enabled()) {
        return;
    }
    $pending = pending_clients();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only: a fixed notice after this page's own redirect.
    $done = isset($_GET['wppilot_app']) && is_string($_GET['wppilot_app']) ? sanitize_key(wp_unslash($_GET['wppilot_app'])) : '';

    echo '<h2 id="wppilot-pending-apps">' . esc_html__('Apps waiting for approval', domain: 'wppilot') . '</h2>';
    if ($done === 'approved') {
        echo '<div class="notice notice-success inline"><p>' . esc_html__('App approved. Start the connection again from the app.', domain: 'wppilot') . '</p></div>';
    } elseif ($done === 'rejected') {
        echo '<div class="notice notice-success inline"><p>' . esc_html__('Request dismissed. The app stays unable to connect.', domain: 'wppilot') . '</p></div>';
    }
    if ($pending === []) {
        echo '<p>' . esc_html__('No app is waiting. New-app approval is on: an app this site has not approved stops at a waiting page and shows up here.', domain: 'wppilot') . '</p>';
        return;
    }
    echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>'
        . esc_html__('Application', domain: 'wppilot') . '</th><th>'
        . esc_html__('Client ID', domain: 'wppilot') . '</th><th>'
        . esc_html__('First asked', domain: 'wppilot') . '</th><th></th></tr></thead><tbody>';
    foreach ($pending as $client_id => $entry) {
        echo '<tr><td><strong>' . esc_html($entry['name']) . '</strong></td><td><code>' . esc_html($client_id) . '</code></td><td>'
            . esc_html(wp_date('Y-m-d H:i', $entry['at']) ?: '') . '</td><td>';
        foreach ([APPROVE_ACTION => __('Approve', domain: 'wppilot'), REJECT_ACTION => __('Dismiss', domain: 'wppilot')] as $action => $label) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:6px">';
            echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
            echo '<input type="hidden" name="client_id" value="' . esc_attr($client_id) . '">';
            wp_nonce_field($action . '_' . $client_id);
            echo '<button type="submit" class="button' . ($action === APPROVE_ACTION ? ' button-primary' : '') . '">' . esc_html($label) . '</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<p class="description">' . esc_html__('Approve only an app you started connecting yourself. The name is whatever the app calls itself.', domain: 'wppilot') . '</p>';
}

function handle_decision(): void
{
    if (!\wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to approve GoBuildWP apps.', domain: 'wppilot'), '', ['response' => 403]);
    }
    $action = isset($_POST['action']) && is_string($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : '';
    $client_id = isset($_POST['client_id']) && is_string($_POST['client_id']) ? sanitize_text_field(wp_unslash($_POST['client_id'])) : '';
    check_admin_referer($action . '_' . $client_id);
    if ($client_id === '' || !isset(pending_clients()[$client_id])) {
        wp_safe_redirect(admin_url('admin.php?page=wppilot-connected-apps'));
        exit();
    }
    if ($action === APPROVE_ACTION) {
        approve($client_id);
        $done = 'approved';
    } else {
        reject($client_id);
        $done = 'rejected';
    }
    wp_safe_redirect(add_query_arg('wppilot_app', $done, admin_url('admin.php?page=wppilot-connected-apps')) . '#wppilot-pending-apps');
    exit();
}

/**
 * The switch on the Settings screen.
 */
function register_setting(mixed $sections): mixed
{
    if (!is_array($sections)) {
        return $sections;
    }
    $sections[] = [
        'id' => 'wppilot-app-approval',
        'title' => __('New apps', domain: 'wppilot'),
        'description' => __('Who may start an OAuth connection to this site.', domain: 'wppilot'),
        'fields' => [
            [
                'type' => 'toggle',
                'name' => OPTION_ENABLED,
                'label' => __('Approve new apps before they can connect', domain: 'wppilot'),
                'help' => __(
                    'On: an OAuth app this site has not approved stops at a waiting page instead of the consent screen, and an administrator approves it under Connected Apps. Apps already connected when you switch this on stay approved.',
                    domain: 'wppilot',
                ),
                'value' => enabled(),
            ],
        ],
        'save' => __NAMESPACE__ . '\\save_setting',
    ];

    return $sections;
}

/**
 * @param array<string, mixed> $post
 */
function save_setting(array $post): void
{
    // An unchecked checkbox is absent from the POST entirely.
    set_enabled(($post[OPTION_ENABLED] ?? null) !== null);
}

function register(): void
{
    add_filter('wppilot_settings_sections', __NAMESPACE__ . '\\register_setting');
    add_action('admin_post_' . APPROVE_ACTION, __NAMESPACE__ . '\\handle_decision');
    add_action('admin_post_' . REJECT_ACTION, __NAMESPACE__ . '\\handle_decision');
}
