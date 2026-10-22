<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Configuration page, step 1: turning AI Abilities on and off.
 *
 * Owns the toggle itself, the admin-bar shortcut that drives the same
 * transition, and the production warning shown before enabling.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Enable AI Abilities for the current site domain.
 */
function wppilot_enable_ai_abilities(): bool
{
    if (function_exists('wppilot_get_mcp_dependency_error') && wppilot_get_mcp_dependency_error() !== null) {
        return false;
    }

    update_option(option: 'wppilot_ai_abilities_enabled', value: '1');
    update_option(option: 'wppilot_ai_abilities_domain', value: (string) wp_parse_url(home_url(), PHP_URL_HOST));
    return true;
}

/**
 * Disable AI Abilities and clear the domain lock.
 */
function wppilot_disable_ai_abilities(): bool
{
    update_option(option: 'wppilot_ai_abilities_enabled', value: '0');
    delete_option('wppilot_ai_abilities_domain');
    return true;
}

/**
 * Handle the enable/disable AI Abilities toggle submission.
 * Returns true on save, null when no submission.
 */
function wppilot_handle_toggle_enabled(): ?bool
{
    if (!isset($_POST['wppilot_submit'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence check only; check_admin_referer('wppilot_settings') below runs before any value is read or saved.
        return null;
    }
    if (!wppilot_current_user_can_manage()) {
        return null;
    }

    check_admin_referer('wppilot_settings');

    $profile = is_string($_POST['wppilot_safety_profile'] ?? null)
        ? sanitize_key(wp_unslash($_POST['wppilot_safety_profile']))
        : 'production';
    if (!wppilot_update_safety_profile($profile)) {
        return false;
    }

    $enabled = isset($_POST['wppilot_ai_abilities_enabled']);
    return $enabled ? wppilot_enable_ai_abilities() : wppilot_disable_ai_abilities();
}

/**
 * Handle the admin-bar AI Abilities toggle.
 */
function wppilot_handle_admin_bar_toggle(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to manage GoBuildWP settings.', domain: 'wppilot'));
    }

    check_admin_referer('wppilot_toggle_ai_abilities');

    $target = isset($_GET['wppilot_target']) && is_string($_GET['wppilot_target']) ? sanitize_text_field(wp_unslash($_GET['wppilot_target'])) : '';
    $result = null;
    if ($target === 'on') {
        $result = wppilot_enable_ai_abilities();
    }
    if ($target === 'off') {
        $result = wppilot_disable_ai_abilities();
    }

    $redirect = wp_get_referer();
    if (!is_string($redirect) || $redirect === '') {
        $redirect = admin_url('admin.php?page=wppilot-connect');
    }

    $redirect = add_query_arg([
        'wppilot_toggle_result' => $result === true ? $target : 'failed',
    ], $redirect);

    wp_safe_redirect($redirect);
    exit();
}

function wppilot_render_enable_toggle(): void
{
    $enabled = wppilot_is_enabled();
    $dependency_error = function_exists('wppilot_get_mcp_dependency_error') ? wppilot_get_mcp_dependency_error() : null;
    $toggle_disabled = $dependency_error !== null && !$enabled;
    $submit_attributes = $toggle_disabled ? ['disabled' => 'disabled'] : [];
    $looks_production = wppilot_looks_like_production();
    $profile = wppilot_get_safety_profile();
    $profiles = wppilot_safety_profiles();
    ?>
    <h2 class="wppilot-step-heading">
        <span class="wppilot-step-badge">4</span>
        <?php esc_html_e('Enable AI Abilities', domain: 'wppilot'); ?>
    </h2>
    <form method="post" action="" id="wppilot-settings-form" style="margin: 16px 0 0;">
        <?php wp_nonce_field('wppilot_settings'); ?>
        <label style="display:flex; align-items:center; gap:10px; font-size:16px; font-weight:600; color:#1d2327; margin:0 0 12px;">
            <input type="checkbox" name="wppilot_ai_abilities_enabled" value="1" id="wppilot-enable-checkbox" style="width:18px; height:18px;" <?php checked(
                checked: $enabled,
                current: true,
            ); ?> <?php disabled($toggle_disabled); ?> />
            <span><?php esc_html_e('Turn on AI Abilities for this site', domain: 'wppilot'); ?></span>
        </label>
        <p class="description" style="margin:0 0 8px;">
            <strong style="color:#d63638;"><?php esc_html_e('Security note:', domain: 'wppilot'); ?></strong>
            <?php esc_html_e(
                'GoBuildWP enforces the selected safety profile on the server for MCP and REST calls. Critical and destructive calls also require explicit confirmation.',
                domain: 'wppilot',
            ); ?>
        </p>
        <label for="wppilot-safety-profile" style="display:block; font-weight:600; margin:14px 0 6px;">
            <?php esc_html_e('Safety profile', domain: 'wppilot'); ?>
        </label>
        <select name="wppilot_safety_profile" id="wppilot-safety-profile" style="min-width:260px;">
            <?php foreach ($profiles as $slug => $details): ?>
                <option value="<?php echo esc_attr($slug); ?>" <?php selected($profile, $slug); ?>>
                    <?php echo esc_html($details['label']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div style="margin:8px 0 14px; max-width:760px;">
            <?php foreach ($profiles as $slug => $details): ?>
                <p class="description wppilot-profile-description" data-profile="<?php echo
                    esc_attr($slug)
                ; ?>" <?php echo $slug === $profile ? '' : 'hidden'; ?>>
                    <?php echo esc_html($details['description']); ?>
                </p>
            <?php endforeach; ?>
        </div>
        <?php submit_button(
            text: __('Save Settings', domain: 'wppilot'),
            type: 'primary',
            name: 'wppilot_submit',
            wrap: false,
            other_attributes: $submit_attributes,
        ); ?>
    </form>
    <?php
    wppilot_enqueue_admin_script(
        'wppilot-enable-toggle',
        'connect-enable-toggle.js',
        data_var: 'wppilotEnableToggle',
        data: [
            'savedProfile' => $profile,
            'confirmDeveloper' =>
                wppilot_is_wordpress_org_edition()
                    ? ($looks_production
                        ? __(
                            'This looks like a production site. Developer Full Access lets agents install and delete plugins and themes and change user accounts. Use Production Safe unless that is intentional. Continue?',
                            domain: 'wppilot',
                        )
                        : __(
                            'Developer Full Access lets agents install and delete plugins and themes and change user accounts. Continue?',
                            domain: 'wppilot',
                        ))
                    : ($looks_production
                        ? __(
                            'This looks like a production site. Developer Full Access permits PHP execution, WP-CLI, filesystem access, and temporary administrator links. Use Production Safe unless unrestricted access is intentional. Continue?',
                            domain: 'wppilot',
                        )
                        : __(
                            'Developer Full Access permits PHP execution, WP-CLI, filesystem access, and temporary administrator links. Continue?',
                            domain: 'wppilot',
                        )),
        ],
    );
}

function wppilot_render_enable_prompt(?WP_Error $dependency_error): void
{
    if (wppilot_is_enabled() || $dependency_error !== null) {
        return;
    }

    ?>
    <p style="color:#666; font-size:14px;">
        <?php esc_html_e('Enable AI Abilities above to connect your AI client.', domain: 'wppilot'); ?>
    </p>
    <?php
}

/**
 * Render the production-site warning banner above the enable toggle.
 *
 * Shown only when: AI Abilities are currently enabled AND the site looks like production
 * AND the current user has not dismissed the warning.
 */
function wppilot_render_production_warning(): void
{
    if (!wppilot_is_enabled()) {
        return;
    }
    if (!wppilot_looks_like_production()) {
        return;
    }
    if (wppilot_production_warning_dismissed()) {
        return;
    }
    ?>
    <div class="wppilot-production-warning" role="alert">
        <p>
            <strong><?php esc_html_e('⚠️ This looks like a production site.', domain: 'wppilot'); ?></strong>
            <?php esc_html_e(
                'Keeping the plugin installed here is fine, but AI Abilities should only be active on a staging or development copy. Make your changes there, then deploy the result the regular way. On production, keep AI Abilities off.',
                domain: 'wppilot',
            ); ?>
        </p>
        <form method="post" style="margin:0;">
            <?php wp_nonce_field('wppilot_dismiss_production_warning'); ?>
            <button type="submit" name="wppilot_dismiss_production_warning" class="button button-small">
                <?php esc_html_e('Dismiss', domain: 'wppilot'); ?>
            </button>
        </form>
    </div>
    <?php
}
