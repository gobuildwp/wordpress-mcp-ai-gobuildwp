<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

namespace WPPilot\Troubleshoot\Notice;

if (!defined('ABSPATH')) {
    exit();
}

const DISMISS_META = 'wppilot_troubleshoot_dismissed';

/**
 * Regressions worth interrupting an admin for: conditions that (a) certainly break connections
 * that were working, and (b) cost nothing to detect (no HTTP, one small indexed query at most).
 * Anything fuzzier belongs in the on-demand troubleshooter, not a nag.
 *
 * @return list<string> Human-readable regression descriptions; empty when all is well.
 */
function regressions(): array
{
    $found = [];

    // Application Passwords turned off while GoBuildWP passwords have actually been used.
    $status = \wppilot_app_passwords_status();
    if (!$status['available'] && $status['reason'] === 'filtered') {
        foreach (\wppilot_get_mcp_passwords() as $password) {
            if (($password['last_used'] ?? null) === null) {
                continue;
            }
            // Name the plugin when it is known: that turns the notice into the fix.
            $found[] = is_array($status['blocker'] ?? null)
                ? $status['message']
                : __(
                    'Application Passwords have been disabled (likely by a security plugin), so AI clients connected with the password method cannot authenticate anymore.',
                    domain: 'wppilot',
                );
            break;
        }
    }

    // Site dropped to plain HTTP while OAuth connections are active: the endpoints are gone.
    if (!\wppilot_oauth_transport_allowed() && \WPPilot\OAuth\ClientValidation\active_client_count() > 0) {
        $found[] = __(
            'This site is no longer served over HTTPS, so the OAuth endpoints are disabled and connected AI clients cannot authenticate anymore.',
            domain: 'wppilot',
        );
    }

    return $found;
}

function maybe_render(): void
{
    // Nothing to render outside a browser request, and the dismiss link below
    // is built from the current URL — which does not exist under WP-CLI or
    // WP-Cron. Calling add_query_arg() there makes it fall back to an undefined
    // $_SERVER['REQUEST_URI'] and emit a run of PHP deprecation notices.
    if (($_SERVER['REQUEST_URI'] ?? null) === null) {
        return;
    }
    if (defined('WP_CLI') && constant('WP_CLI') === true) {
        return;
    }

    if (!\wppilot_current_user_can_manage()) {
        return;
    }
    $found = regressions();
    if ($found === []) {
        return;
    }
    $hash = md5((string) wp_json_encode($found));
    if (get_user_meta(get_current_user_id(), DISMISS_META, single: true) === $hash) {
        return;
    }
    $dismiss_url = wp_nonce_url(
        add_query_arg('wppilot_troubleshoot_dismiss', $hash),
        action: 'wppilot_troubleshoot_dismiss',
    );
    $connect_url = admin_url('admin.php?page=wppilot-connect');
    echo
        '<div class="notice notice-error"><p><strong>'
            . esc_html__('GoBuildWP connections are broken.', domain: 'wppilot')
            . '</strong></p>'
    ;
    foreach ($found as $message) {
        echo '<p>' . esc_html($message) . '</p>';
    }
    echo
        '<p><a href="'
            . esc_url($connect_url)
            . '">'
            . esc_html__('Open the Connect page to run diagnostics', domain: 'wppilot')
            . '</a> · <a href="'
            . esc_url($dismiss_url)
            . '">'
            . esc_html__('Dismiss', domain: 'wppilot')
            . '</a></p></div>'
    ;
}

/**
 * Persist the dismissal keyed to the exact regression set, so the notice stays gone for this
 * state but re-arms the moment a different regression appears.
 */
function handle_dismiss(): void
{
    $raw = $_GET['wppilot_troubleshoot_dismiss'] ?? null;
    if (!is_string($raw) || $raw === '') {
        return;
    }
    check_admin_referer('wppilot_troubleshoot_dismiss');
    if (!\wppilot_current_user_can_manage()) {
        return;
    }
    update_user_meta(get_current_user_id(), DISMISS_META, sanitize_key($raw));
    wp_safe_redirect(remove_query_arg(['wppilot_troubleshoot_dismiss', '_wpnonce']));
    exit();
}
