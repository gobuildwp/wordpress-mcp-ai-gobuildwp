<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The wp-admin screen where an administrator approves or denies a device sign-in code.
 *
 * See includes/device-auth.php. The code in the URL only fills the field; the
 * decision is a POST checked against the manage capability and a nonce, so a
 * link someone sends cannot approve anything by being opened.
 */

if (!defined('ABSPATH')) {
    exit();
}

function wppilot_device_register_page(): void
{
    $hook = add_submenu_page(
        parent_slug: '',
        page_title: __('Approve a device sign-in', domain: 'wppilot'),
        menu_title: '',
        capability: wppilot_manage_capability(),
        menu_slug: WPPILOT_DEVICE_ADMIN_PAGE,
        callback: 'wppilot_device_render_page',
    );

    if (is_string($hook) && $hook !== '') {
        add_action('load-' . $hook, 'wppilot_device_handle_load');
        add_action('load-' . $hook, static function (): void {
            add_filter('admin_title', static fn (string $title): string => __('Approve a device sign-in', domain: 'wppilot') . ' ' . $title);
        });
    }
}

function wppilot_device_typed_user_code(string $source): string
{
    // phpcs:ignore WordPress.Security.NonceVerification -- GET only fills the field; the POST that acts on it is nonce-checked in wppilot_device_handle_load().
    $raw = $source === 'post' ? ($_POST['user_code'] ?? null) : ($_GET['user_code'] ?? null);
    if (!is_string($raw)) {
        return '';
    }
    $code = wppilot_device_normalize_user_code(sanitize_text_field(wp_unslash($raw)));

    return strlen($code) === 8 ? substr($code, 0, 4) . '-' . substr($code, 4) : '';
}

function wppilot_device_handle_load(): void
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to approve device sign-ins.', domain: 'wppilot'), '', ['response' => 403]);
    }
    check_admin_referer('wppilot_device_decide');

    $code = wppilot_device_typed_user_code('post');
    $decision = is_string($_POST['wppilot_device_decision'] ?? null) ? sanitize_key(wp_unslash($_POST['wppilot_device_decision'])) : '';
    if ($code === '' || ($decision !== 'approve' && $decision !== 'deny')) {
        wp_die(esc_html__('Enter the code and choose Approve or Deny.', domain: 'wppilot'), '', ['response' => 400]);
    }

    $ceiling = is_string($_POST['wppilot_device_ceiling'] ?? null) ? sanitize_key(wp_unslash($_POST['wppilot_device_ceiling'])) : '';
    $ttl_raw = is_string($_POST['wppilot_device_ttl'] ?? null) ? sanitize_text_field(wp_unslash($_POST['wppilot_device_ttl'])) : '';
    $ttl = ctype_digit($ttl_raw) ? (int) $ttl_raw : -1;
    if ($decision === 'approve' && !array_key_exists($ttl, wppilot_token_expiry_choices())) {
        wp_die(esc_html__('Choose how long the access token lasts.', domain: 'wppilot'), '', ['response' => 400]);
    }

    $done = wppilot_device_decide($code, $decision === 'approve', get_current_user_id(), $ceiling, max(0, $ttl));

    wp_safe_redirect(add_query_arg(
        ['decided' => $done ? $decision : 'stale'],
        admin_url('admin.php?page=' . WPPILOT_DEVICE_ADMIN_PAGE),
    ));
    exit();
}

function wppilot_device_render_page(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to approve device sign-ins.', domain: 'wppilot'));
    }

    $code = wppilot_device_typed_user_code('get');
    $pending = $code === '' ? null : wppilot_device_find_by_user_code($code);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only: selects one of three fixed notices after this page's own redirect.
    $decided = is_string($_GET['decided'] ?? null) ? sanitize_key(wp_unslash($_GET['decided'])) : '';
    $page_url = admin_url('admin.php');

    wppilot_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Approve a device sign-in', domain: 'wppilot'); ?></h1>
        <?php if ($decided === 'approve') { ?>
            <div class="notice notice-success"><p><?php esc_html_e('Approved. The agent receives its access token the next time it checks, within a few seconds. You can revoke it on the Connect screen.', domain: 'wppilot'); ?></p></div>
        <?php } elseif ($decided === 'deny') { ?>
            <div class="notice notice-warning"><p><?php esc_html_e('Denied. The agent was not given access.', domain: 'wppilot'); ?></p></div>
        <?php } elseif ($decided === 'stale') { ?>
            <div class="notice notice-error"><p><?php esc_html_e('That code was already used, was decided already or has expired, so nothing changed.', domain: 'wppilot'); ?></p></div>
        <?php } ?>

        <div class="notice notice-warning inline"><p><strong><?php esc_html_e('Only approve a code you started yourself, just now, in an agent you trust.', domain: 'wppilot'); ?></strong>
            <?php esc_html_e('Approving gives that agent an access token that acts as you. If someone sent you this code or link, deny it.', domain: 'wppilot'); ?></p></div>

        <form method="get" action="<?php echo esc_url($page_url); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(WPPILOT_DEVICE_ADMIN_PAGE); ?>" />
            <p>
                <label for="wppilot-device-code"><strong><?php esc_html_e('Code shown by the agent', domain: 'wppilot'); ?></strong></label><br />
                <input id="wppilot-device-code" name="user_code" type="text" class="regular-text code" autocomplete="off" spellcheck="false" maxlength="12" placeholder="XXXX-XXXX" value="<?php echo esc_attr($code); ?>" />
                <button type="submit" class="button"><?php esc_html_e('Look up', domain: 'wppilot'); ?></button>
            </p>
        </form>

        <?php if ($code !== '' && $pending === null) { ?>
            <p><?php esc_html_e('No pending sign-in matches that code. It may have expired (codes last 10 minutes), been used already, or been mistyped. Ask the agent for a new one.', domain: 'wppilot'); ?></p>
        <?php } ?>

        <?php if ($pending !== null) { ?>
            <section class="wppilot-panel">
                <h2 class="wppilot-setting-group__title"><?php echo esc_html($pending['client']); ?></h2>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><?php esc_html_e('Requested from', domain: 'wppilot'); ?></th><td><code><?php echo esc_html($pending['ip'] !== '' ? $pending['ip'] : __('unknown address', domain: 'wppilot')); ?></code></td></tr>
                    <?php if ($pending['agent'] !== '') { ?>
                        <tr><th scope="row"><?php esc_html_e('Client software', domain: 'wppilot'); ?></th><td><?php echo esc_html($pending['agent']); ?></td></tr>
                    <?php } ?>
                    <tr><th scope="row"><?php esc_html_e('Requested', domain: 'wppilot'); ?></th><td><?php echo esc_html(gmdate('Y-m-d H:i:s', $pending['created']) . ' UTC'); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Code expires', domain: 'wppilot'); ?></th><td><?php echo esc_html(gmdate('Y-m-d H:i:s', $pending['expires']) . ' UTC'); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Acts as', domain: 'wppilot'); ?></th><td><?php echo esc_html(wp_get_current_user()->user_login); ?></td></tr>
                </table>
                <form method="post" action="<?php echo esc_url(add_query_arg('page', WPPILOT_DEVICE_ADMIN_PAGE, $page_url)); ?>">
                    <?php wp_nonce_field('wppilot_device_decide'); ?>
                    <input type="hidden" name="user_code" value="<?php echo esc_attr($code); ?>" />
                    <p>
                        <label for="wppilot-device-ceiling"><strong><?php esc_html_e('Safety profile ceiling', domain: 'wppilot'); ?></strong></label><br />
                        <select id="wppilot-device-ceiling" name="wppilot_device_ceiling">
                            <?php foreach (wppilot_token_ceiling_choices() as $value => $label) { ?>
                                <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                            <?php } ?>
                        </select>
                    </p>
                    <p>
                        <label for="wppilot-device-ttl"><strong><?php esc_html_e('Token expires after', domain: 'wppilot'); ?></strong></label><br />
                        <select id="wppilot-device-ttl" name="wppilot_device_ttl">
                            <option value="" selected disabled><?php esc_html_e('Choose…', domain: 'wppilot'); ?></option>
                            <?php foreach (wppilot_token_expiry_choices() as $days => $label) { ?>
                                <option value="<?php echo esc_attr((string) $days); ?>"><?php echo esc_html($label); ?></option>
                            <?php } ?>
                        </select>
                    </p>
                    <p>
                        <button type="submit" name="wppilot_device_decision" value="approve" class="button button-primary"><?php esc_html_e('Approve and issue a token', domain: 'wppilot'); ?></button>
                        <button type="submit" name="wppilot_device_decision" value="deny" class="button" formnovalidate><?php esc_html_e('Deny', domain: 'wppilot'); ?></button>
                    </p>
                </form>
            </section>
        <?php } ?>
    </div>
    <?php
}

add_action('admin_menu', 'wppilot_device_register_page');
