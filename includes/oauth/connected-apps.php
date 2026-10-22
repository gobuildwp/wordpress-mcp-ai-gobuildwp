<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\OAuth\ConnectedApps;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    $hook = add_submenu_page(
        parent_slug: '',
        page_title: 'Connected Apps',
        menu_title: '',
        capability: \wppilot_manage_capability(),
        menu_slug: 'wppilot-connected-apps',
        callback: __NAMESPACE__ . '\\render',
    );

    // The Revoke POST must redirect back before any admin HTML is sent. The page callback runs
    // after the admin header (headers already flushed, so wp_redirect is a no-op and the browser is
    // left on a blank page), so the POST is handled on the load hook, which fires before any output.
    if (is_string($hook) && $hook !== '') {
        add_action('load-' . $hook, __NAMESPACE__ . '\\handle_load');
    }
}

/**
 * Fires before the admin header. Handles the Revoke POST (nonce check, revoke, redirect); GET
 * requests fall through untouched so the page callback can draw the list.
 */
function handle_load(): void
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!is_user_logged_in() || !\wppilot_current_user_can_manage()) {
        return;
    }
    handle_post(get_current_user_id());
}

function render(): void
{
    if (!is_user_logged_in()) {
        wp_die('You must be logged in.', title: '', args: ['response' => 403]);
        return;
    }
    if (!\wppilot_current_user_can_manage()) {
        wp_die('You are not allowed to manage GoBuildWP connected apps.', title: '', args: ['response' => 403]);
        return;
    }

    render_page(get_current_user_id());
}

function handle_post(int $user_id): void
{
    $action = is_string($_POST['wppilot_action'] ?? null) ? sanitize_text_field(wp_unslash($_POST['wppilot_action'])) : '';
    if ($action === 'delete_admin_client') {
        check_admin_referer('wppilot_connected_apps_delete');
        $client_id = is_string($_POST['client_id'] ?? null) ? sanitize_key(wp_unslash($_POST['client_id'])) : '';
        if ($client_id !== '') {
            // Belt and braces: revoke any tokens the row may have picked up, then drop it.
            revoke_client_access($client_id, $user_id);
            (new \WPPilot\OAuth\Repositories\ClientRepository())->revoke($client_id);
        }
        wp_safe_redirect(add_query_arg(['deleted' => '1'], admin_url('admin.php?page=wppilot-connected-apps')));
        exit();
    }

    check_admin_referer('wppilot_connected_apps_revoke');

    $client_id = is_string($_POST['client_id'] ?? null) ? sanitize_key(wp_unslash($_POST['client_id'])) : '';
    if ($client_id !== '') {
        revoke_client_access($client_id, $user_id);
    }

    wp_safe_redirect(add_query_arg(['revoked' => '1'], admin_url('admin.php?page=wppilot-connected-apps')));
    exit();
}

function revoke_client_access(string $client_id, int $user_id): void
{
    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $wpdb */
    $t = $wpdb->prefix . 'wppilot_oauth_access_tokens';
    $r = $wpdb->prefix . 'wppilot_oauth_refresh_tokens';

    // Revoke refresh tokens linked to this client's access tokens.
    // @mago-expect analysis:possibly-invalid-argument
    // @mago-expect analysis:possibly-invalid-argument
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The OAuth token tables have no WordPress API; a revocation must take effect on the very next request, so nothing is cached.
    $wpdb->query($wpdb->prepare(
        'UPDATE %i rt
         JOIN %i at ON at.identifier_hash = rt.access_token_hash
         SET rt.revoked = 1
         WHERE at.client_id = %s AND at.user_id = %d',
        $r,
        $t,
        $client_id,
        $user_id,
    ));

    // Revoke all access tokens for this client and user.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above: the token table has no WordPress API and a revocation must not be served from a cache.
    $wpdb->update($t, ['revoked' => 1], ['client_id' => $client_id, 'user_id' => $user_id]);

    // Hand the connection slot back now. Slot accounting reads the client's last use, so without
    // this a revoked connection went on occupying one until its grant expired on its own.
    \WPPilot\OAuth\ClientValidation\release_client_slot_if_unused($client_id);
}

// @mago-expect lint:halstead
function render_page(int $user_id): void
{
    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $wpdb */
    $t = $wpdb->prefix . 'wppilot_oauth_access_tokens';
    $r = $wpdb->prefix . 'wppilot_oauth_refresh_tokens';
    $c = $wpdb->prefix . 'wppilot_oauth_clients';
    $now = gmdate('Y-m-d H:i:s');

    // Key the list off refresh tokens, not access tokens. Access tokens live one hour, so
    // basing the list on them would drop a still-connected app from the view an hour after
    // its last use and show a misleading one-hour expiry. The refresh token (renewed on
    // each use) is the real connection lifetime, so its expiry is what we surface.
    // @mago-expect analysis:possibly-invalid-argument
    // @mago-expect analysis:possibly-invalid-argument
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The OAuth token tables have no WordPress API. Not cached: the list must show a revocation made a moment ago.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT c.client_name, at.client_id, at.scopes, MAX(rt.expires_at) AS expires_at
         FROM %i rt
         JOIN %i at ON at.identifier_hash = rt.access_token_hash
         JOIN %i c ON c.client_id = at.client_id
         WHERE at.user_id = %d AND rt.revoked = 0 AND rt.expires_at > %s
         GROUP BY at.client_id, c.client_name, at.scopes
         ORDER BY expires_at DESC',
            $r,
            $t,
            $c,
            $user_id,
            $now,
        ),
        ARRAY_A,
    );

    $apps = is_array($rows) ? $rows : [];

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only: the flag from this page's own post-revoke redirect shows a fixed notice.
    $was_revoked = isset($_GET['revoked']) && $_GET['revoked'] === '1';

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Connected Apps', domain: 'wppilot') . '</h1>';
    echo
        '<p>'
            . esc_html__(
                'These applications have been granted access to your WordPress account via GoBuildWP. The connection renews automatically while in use; the expiry shown is when it lapses if the app stops connecting.',
                domain: 'wppilot',
            )
            . '</p>'
    ;

    if ($was_revoked) {
        echo
            '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Access revoked successfully.', domain: 'wppilot')
                . '</p></div>'
        ;
    }

    if ($apps === []) {
        echo '<p>' . esc_html__('No apps are currently connected to your account.', domain: 'wppilot') . '</p>';
        render_admin_clients_section();
        echo '</div>';
        return;
    }

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('Application', domain: 'wppilot') . '</th>';
    echo '<th>' . esc_html__('Scope', domain: 'wppilot') . '</th>';
    echo '<th>' . esc_html__('Connection expires', domain: 'wppilot') . '</th>';
    echo '<th></th>';
    echo '</tr></thead><tbody>';

    foreach ($apps as $app) {
        $name = (string) $app['client_name'];
        $cid = (string) $app['client_id'];
        $scopes_raw = (string) $app['scopes'];
        $expires = (string) $app['expires_at'];

        // @mago-expect analysis:mixed-assignment
        $scopes_arr = json_decode($scopes_raw, associative: true);
        $scopes_str = is_array($scopes_arr)
            ? implode(' ', array_map(static fn(mixed $s): string => is_string($s) ? $s : '', $scopes_arr))
            : $scopes_raw;

        echo '<tr>';
        echo '<td><strong>' . esc_html($name) . '</strong></td>';
        echo '<td>' . esc_html($scopes_str) . '</td>';
        echo '<td>' . esc_html($expires) . '</td>';
        echo '<td>';
        echo '<form method="post">';
        wp_nonce_field('wppilot_connected_apps_revoke');
        echo '<input type="hidden" name="client_id" value="' . esc_attr($cid) . '">';
        echo '<button type="submit" class="button">' . esc_html__('Revoke Access', domain: 'wppilot') . '</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    render_admin_clients_section();
    echo '</div>';
}

/**
 * List client IDs minted from the troubleshooter. They are exempt from the pending cleanup, so
 * this table is where an admin sees and deletes the ones that were never used.
 */
function render_admin_clients_section(): void
{
    $clients = (new \WPPilot\OAuth\Repositories\ClientRepository())->list_admin_clients();

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only: the flag from this page's own post-delete redirect shows a fixed notice.
    if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
        echo
            '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Client ID deleted.', domain: 'wppilot')
                . '</p></div>'
        ;
    }

    if ($clients === []) {
        return;
    }

    echo '<h2 style="margin-top:24px;">' . esc_html__('Manually created client IDs', domain: 'wppilot') . '</h2>';
    echo
        '<p>'
            . esc_html__(
                'Created from the connection troubleshooter to bypass a failing automatic registration. Each stays valid until used or deleted here.',
                domain: 'wppilot',
            )
            . '</p>'
    ;
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('Application', domain: 'wppilot') . '</th>';
    echo '<th>' . esc_html__('Client ID', domain: 'wppilot') . '</th>';
    echo '<th>' . esc_html__('Created', domain: 'wppilot') . '</th>';
    echo '<th>' . esc_html__('First used', domain: 'wppilot') . '</th>';
    echo '<th></th>';
    echo '</tr></thead><tbody>';
    foreach ($clients as $client) {
        echo '<tr>';
        echo '<td><strong>' . esc_html($client['client_name']) . '</strong> ';
        echo
            '<span style="font-size:11px; font-weight:600; color:#646970; background:#f0f0f1; border-radius:10px; padding:1px 8px;">'
                . esc_html__('manually created', domain: 'wppilot')
                . '</span></td>'
        ;
        echo '<td><code>' . esc_html($client['client_id']) . '</code></td>';
        echo '<td>' . esc_html($client['created_at']) . '</td>';
        echo '<td>' . esc_html($client['last_used_at'] ?? __('Never', domain: 'wppilot')) . '</td>';
        echo '<td>';
        echo '<form method="post">';
        wp_nonce_field('wppilot_connected_apps_delete');
        echo '<input type="hidden" name="wppilot_action" value="delete_admin_client">';
        echo '<input type="hidden" name="client_id" value="' . esc_attr($client['client_id']) . '">';
        echo '<button type="submit" class="button">' . esc_html__('Delete', domain: 'wppilot') . '</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
