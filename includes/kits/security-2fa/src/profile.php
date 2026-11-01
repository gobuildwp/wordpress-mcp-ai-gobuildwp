<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityTwoFactor;

use WP_Error;
use WP_User;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Users > Profile: a user sets up, re-keys backup codes for, or turns off their own two-factor
 * login, each confirmed with a current code. An administrator editing someone else can only reset
 * it (the user sets it up again on their next visit or, when their role requires it, at login).
 */
function install_profile_hooks(): void
{
    add_action('show_user_profile', __NAMESPACE__ . '\\render_profile_section', 20, 1);
    add_action('edit_user_profile', __NAMESPACE__ . '\\render_profile_section', 20, 1);
    // Late, so a code is only acted on when nothing else stopped the profile update.
    add_action('user_profile_update_errors', __NAMESPACE__ . '\\handle_profile_update', 99, 3);
    add_action('admin_notices', __NAMESPACE__ . '\\render_grace_notice');
}

function render_profile_section(WP_User $user): void
{
    $own = $user->ID === get_current_user_id();
    if (!$own && !current_user_can('edit_user', $user->ID)) {
        return;
    }
    $enabled = is_enabled($user->ID);
    echo '<h2 id="gobuildwp-2fa">' . esc_html__('Two-factor login', domain: 'wppilot') . '</h2>';
    wp_nonce_field('gobuildwp_kit_2fa_profile_' . $user->ID, '_gobuildwp_kit_2fa_nonce');
    echo '<table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__('Status', domain: 'wppilot') . '</th><td>';

    if (!$own) {
        if ($enabled) {
            $left = count(backup_hashes($user->ID));
            echo '<p>' . esc_html(sprintf(
                /* translators: %d: number of unused backup codes */
                _n('On. %d backup code left.', 'On. %d backup codes left.', $left, domain: 'wppilot'),
                $left,
            )) . '</p>';
            echo '<label><input type="checkbox" name="gobuildwp_kit_2fa_reset" value="1"> '
                . esc_html__('Reset two-factor login (for a lost phone). The user sets it up again.', domain: 'wppilot') . '</label>';
        } else {
            echo '<p>' . esc_html__('Off.', domain: 'wppilot') . '</p>';
        }
        echo '</td></tr></table>';
        return;
    }

    $codes = take_codes_for_display($user->ID);
    if ($codes !== []) {
        echo '<div class="notice notice-warning inline">' . backup_codes_html($codes) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in backup_codes_html().
    }

    $code_field = '<input type="text" name="gobuildwp_kit_2fa_code" id="gobuildwp_kit_2fa_code" class="regular-text" style="width:12em" autocomplete="one-time-code" spellcheck="false" maxlength="64">';
    if ($enabled) {
        $left = count(backup_hashes($user->ID));
        echo '<p>' . esc_html(sprintf(
            /* translators: %d: number of unused backup codes */
            _n('On. %d backup code left.', 'On. %d backup codes left.', $left, domain: 'wppilot'),
            $left,
        )) . '</p>';
        if (user_secret($user->ID) === null) {
            echo '<p class="description">' . esc_html__("The site's security keys changed, so the authenticator app's codes no longer work here. Sign in with a backup code, then turn two-factor login off and on again.", domain: 'wppilot') . '</p>';
        }
        echo '<p><label for="gobuildwp_kit_2fa_code">' . esc_html__('Current code (app or backup code)', domain: 'wppilot') . '</label><br>' . $code_field . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
        echo '<p><button type="submit" class="button" name="gobuildwp_kit_2fa_action" value="regenerate">' . esc_html__('New backup codes', domain: 'wppilot') . '</button> ';
        if (array_intersect(array_values($user->roles), policy()['required_roles']) === []) {
            echo '<button type="submit" class="button" name="gobuildwp_kit_2fa_action" value="disable">' . esc_html__('Turn off', domain: 'wppilot') . '</button>';
        } else {
            echo '<span class="description">' . esc_html__('Your role must use two-factor login, so it cannot be turned off.', domain: 'wppilot') . '</span>';
        }
        echo '</p>';
    } else {
        echo '<p>' . esc_html__('Off. Two-factor login asks for a code from your phone after your password.', domain: 'wppilot') . '</p>';
        echo setup_html(pending_secret($user->ID, time()), $user); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in setup_html(); the QR SVG is numbers only.
        echo '<p><label for="gobuildwp_kit_2fa_code">' . esc_html__('Code from the app', domain: 'wppilot') . '</label><br>' . $code_field . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
        echo '<p><button type="submit" class="button button-primary" name="gobuildwp_kit_2fa_action" value="enable">' . esc_html__('Turn on two-factor login', domain: 'wppilot') . '</button></p>';
    }
    echo '</td></tr></table>';
}

/**
 * @param object $user The user being saved (has ->ID).
 */
// @mago-expect lint:cyclomatic-complexity -- One handler for the three profile actions and the admin reset, each refused on a wrong code.
function handle_profile_update(WP_Error $errors, bool $update, object $user): void
{
    $uid = (int) ($user->ID ?? 0);
    if (!$update || $uid <= 0 || !isset($_POST['_gobuildwp_kit_2fa_nonce'])) {
        return;
    }
    $nonce = is_string($_POST['_gobuildwp_kit_2fa_nonce']) ? (string) wp_unslash($_POST['_gobuildwp_kit_2fa_nonce']) : '';
    if (wp_verify_nonce($nonce, 'gobuildwp_kit_2fa_profile_' . $uid) === false || $errors->has_errors()) {
        return;
    }
    $own = $uid === get_current_user_id();

    if (!$own) {
        if (($_POST['gobuildwp_kit_2fa_reset'] ?? '') === '1' && current_user_can('edit_user', $uid)) {
            disable($uid);
        }
        return;
    }

    $action = isset($_POST['gobuildwp_kit_2fa_action']) && is_string($_POST['gobuildwp_kit_2fa_action']) ? (string) wp_unslash($_POST['gobuildwp_kit_2fa_action']) : '';
    if (!in_array($action, ['enable', 'regenerate', 'disable'], true)) {
        return;
    }
    $code = isset($_POST['gobuildwp_kit_2fa_code']) && is_string($_POST['gobuildwp_kit_2fa_code']) ? substr(trim((string) wp_unslash($_POST['gobuildwp_kit_2fa_code'])), 0, 64) : '';
    $now = time();
    $wrong = static fn() => $errors->add('gobuildwp_kit_2fa_code', __('<strong>Error:</strong> The two-factor code is not right. Nothing was changed.', domain: 'wppilot'));
    if (user_throttled($uid)) {
        $errors->add('gobuildwp_kit_2fa_throttled', __('<strong>Error:</strong> Too many wrong two-factor codes. Try again in 15 minutes.', domain: 'wppilot'));
        return;
    }

    if ($action === 'enable') {
        $secret = read_pending_secret($uid, $now);
        $step = $secret === null ? null : verify_totp($secret, $code, $now);
        if ($secret === null || $step === null) {
            count_profile_failure($uid);
            $wrong();
            return;
        }
        stash_codes_for_display($uid, enable($uid, $secret, $step, $now));
        return;
    }

    if (verify_any($uid, $code, $now) === null) {
        count_profile_failure($uid);
        $wrong();
        return;
    }
    if ($action === 'regenerate') {
        stash_codes_for_display($uid, regenerate_backup_codes($uid));
        return;
    }
    $wp_user = get_userdata($uid);
    $roles = $wp_user instanceof WP_User ? array_values($wp_user->roles) : [];
    if (array_intersect($roles, policy()['required_roles']) !== []) {
        $errors->add('gobuildwp_kit_2fa_required', __('<strong>Error:</strong> Your role must use two-factor login, so it cannot be turned off.', domain: 'wppilot'));
        return;
    }
    disable($uid);
}

/** Wrong codes on the profile page count toward the same per-account limit as at login. */
function count_profile_failure(int $user_id): void
{
    count_failure($user_id);
}

/** Tell a user whose role requires two-factor login how many sign-ins they have before it is enforced. */
function render_grace_notice(): void
{
    $user = wp_get_current_user();
    if (!$user->exists() || is_enabled($user->ID)) {
        return;
    }
    $policy = policy();
    if (array_intersect(array_values($user->roles), $policy['required_roles']) === []) {
        return;
    }
    $left = grace_left($user->ID, $policy);
    $message = $left > 0
        ? sprintf(
            /* translators: %d: sign-ins left without two-factor login */
            _n('Your role must use two-factor login. You can sign in %d more time without it; after that you set it up at login.', 'Your role must use two-factor login. You can sign in %d more times without it; after that you set it up at login.', $left, domain: 'wppilot'),
            $left,
        )
        : __('Your role must use two-factor login. Set it up now, or you will be asked to at your next login.', domain: 'wppilot');
    echo '<div class="notice notice-warning"><p>' . esc_html($message) . ' <a href="' . esc_url(admin_url('profile.php#gobuildwp-2fa')) . '">' . esc_html__('Set up two-factor login', domain: 'wppilot') . '</a></p></div>';
}
