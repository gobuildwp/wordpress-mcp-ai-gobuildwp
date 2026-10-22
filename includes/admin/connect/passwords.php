<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Configuration page: the Application Password connection method.
 *
 * Creating, reusing, listing, and revoking the password an MCP client
 * authenticates with. A generated password is shown once and never stored
 * in plaintext by GoBuildWP.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handle the "use existing password" form submission.
 *
 * Returns the pasted plaintext value (only for the current request — never persisted),
 * a WP_Error on validation failure, or null when no submission.
 *
 * @return string|WP_Error|null
 */
function wppilot_handle_use_existing_password()
{
    if (!isset($_POST['wppilot_use_existing_password'])) {
        return null;
    }

    if (!wppilot_current_user_can_manage()) {
        return new WP_Error('forbidden', __(
            'You do not have permission to use application passwords.',
            domain: 'wppilot',
        ));
    }

    check_admin_referer('wppilot_use_existing_password');

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A pasted application password is a credential shown back once for the client config; only surrounding whitespace is trimmed and its length checked, as sanitize_text_field() must not rewrite it.
    $value = is_string($_POST['wppilot_existing_password'] ?? null) ? trim(wp_unslash($_POST['wppilot_existing_password'])) : '';
    if ($value === '') {
        return new WP_Error('empty', __('Paste the application password value before submitting.', domain: 'wppilot'));
    }
    if (strlen($value) < 16) {
        return new WP_Error('too_short', __(
            'That does not look like an application password. WordPress application passwords are at least 16 characters long.',
            domain: 'wppilot',
        ));
    }
    return $value;
}

/**
 * Handle the create-password form submission.
 * Returns the plaintext password on success, a WP_Error on failure, or null when no submission.
 *
 * @return string|WP_Error|null
 */
function wppilot_handle_create_password()
{
    if (!isset($_POST['wppilot_create_password'])) {
        return null;
    }

    if (!wppilot_current_user_can_manage()) {
        return new WP_Error('forbidden', __(
            'You do not have permission to create application passwords.',
            domain: 'wppilot',
        ));
    }

    check_admin_referer('wppilot_create_password');

    $status = wppilot_app_passwords_status();
    if (!$status['available']) {
        return new WP_Error('not_available', $status['message']);
    }

    $user_id = get_current_user_id();
    $input_name = is_string($_POST['wppilot_password_name'] ?? null)
        ? trim(sanitize_text_field(wp_unslash($_POST['wppilot_password_name'])))
        : '';
    $app_name = $input_name !== '' ? 'GoBuildWP: ' . $input_name : 'GoBuildWP';

    // Avoid duplicate names — append a counter if one already exists.
    $existing = WP_Application_Passwords::get_user_application_passwords($user_id);
    $names = array_column($existing, 'name');
    if (in_array(needle: $app_name, haystack: $names, strict: true)) {
        $i = 2;
        while (in_array(needle: $app_name . ' ' . $i, haystack: $names, strict: true)) {
            $i++;
        }
        $app_name = $app_name . ' ' . $i;
    }

    $result = WP_Application_Passwords::create_new_application_password($user_id, ['name' => $app_name]);

    if (is_wp_error($result)) {
        return $result;
    }

    // $result[0] is the plaintext password.
    return $result[0];
}

/**
 * Handle the revoke-password form submission. Redirects on success.
 * Called from admin_init so headers have not been sent yet.
 */
function wppilot_handle_revoke_password(): void
{
    if (!isset($_POST['wppilot_revoke_password'])) {
        return;
    }

    if (!wppilot_current_user_can_manage()) {
        return;
    }

    $uuid = is_string($_POST['wppilot_revoke_uuid'] ?? null) ? sanitize_text_field(wp_unslash($_POST['wppilot_revoke_uuid'])) : '';
    if ($uuid === '') {
        return;
    }

    check_admin_referer('wppilot_revoke_password_' . $uuid);

    $user_id = get_current_user_id();
    WP_Application_Passwords::delete_application_password($user_id, $uuid);

    wp_safe_redirect(admin_url('admin.php?page=' . WPPILOT_SETUP_PAGE . '&wppilot_result=revoked'));
    exit();
}

/**
 * Return all application passwords for the current user whose name begins with "GoBuildWP",
 * or "WPPilot" for those created before the rename, which still authenticate.
 *
 * @return array<int, array<string, mixed>>
 */
function wppilot_get_mcp_passwords(): array
{
    $user_id = get_current_user_id();
    $all = WP_Application_Passwords::get_user_application_passwords($user_id);
    return array_values(array_filter($all, static fn($item) => str_starts_with($item['name'], 'GoBuildWP') || str_starts_with($item['name'], 'WPPilot')));
}

/**
 * Whether the app-password method is pre-selected on load: true when a password action happened
 * this request (a new or existing password, or an error to surface). OAuth is never pre-selected;
 * the user picks it. Shared by the chooser and the step 3 section so both agree on the initial
 * panel visibility.
 */
function wppilot_password_method_preselected(
    ?string $new_password,
    ?string $existing_password,
    ?WP_Error $existing_error,
): bool {
    return $new_password !== null || $existing_password !== null || $existing_error !== null;
}

/**
 * Render a single password row for the passwords table.
 *
 * @param array<string, mixed> $pw        Password item from WP_Application_Passwords.
 * @param string               $dt_format Date/time format string.
 */
function wppilot_render_password_row(array $pw, string $dt_format): void
{
    $uuid = (string) ($pw['uuid'] ?? '');
    $name = (string) ($pw['name'] ?? '');
    $created_date = ($pw['created'] ?? null) !== null ? wp_date($dt_format, (int) $pw['created']) : false;
    $created = $created_date !== false ? $created_date : __('Unknown', domain: 'wppilot');
    $last_used_date = ($pw['last_used'] ?? null) !== null ? wp_date($dt_format, (int) $pw['last_used']) : false;
    $last_used = $last_used_date !== false ? $last_used_date : __('Never', domain: 'wppilot');
    $revoke_nonce = (string) wp_create_nonce('wppilot_revoke_password_' . $uuid);
    ?>
    <tr>
        <td><strong><?php echo esc_html($name); ?></strong></td>
        <td><?php echo esc_html($created); ?></td>
        <td><?php echo esc_html($last_used); ?></td>
        <td>
            <form method="post" style="margin:0;" onsubmit="return confirm('<?php echo
                esc_js(__('Revoke this password? Any clients using it will lose access.', domain: 'wppilot'))
            ; ?>');">
                <input type="hidden" name="wppilot_revoke_uuid" value="<?php echo esc_attr($uuid); ?>" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($revoke_nonce); ?>" />
                <button type="submit" name="wppilot_revoke_password" class="button button-small wppilot-revoke-btn"><?php esc_html_e(
                    'Revoke',
                    domain: 'wppilot',
                ); ?></button>
            </form>
        </td>
    </tr>
    <?php
}

/**
 * Render the "Step 2 — Application Password" card.
 *
 * Just the generate button (with a collapsible name input) and a success notice after generation.
 * The list of existing passwords lives in the separate manage section at the bottom of the page.
 */
// Complexity is inherent: this is a single HTML template whose branches (password availability,
// newly generated vs. pasted vs. no password, has-existing toggles, error notices) each gate a
// distinct piece of inline markup. Splitting them into helpers would fragment one cohesive view.
// @mago-expect lint:cyclomatic-complexity
function wppilot_render_password_step(
    ?string $new_password,
    ?string $existing_password = null,
    ?WP_Error $existing_error = null,
): void {
    $pw_status = wppilot_app_passwords_status();
    $has_existing = wppilot_get_mcp_passwords() !== [];
    $existing_section_open = $existing_password !== null || $existing_error !== null;
    ?>
    <p class="description" style="margin:0 0 12px;">
        <?php esc_html_e(
            'Generate an application password that your AI client will use to authenticate with WordPress. The password is embedded into the connection text in step 3.',
            domain: 'wppilot',
        ); ?>
    </p>

    <?php if (!$pw_status['available']): ?>
        <div class="notice notice-error inline" style="margin:12px 0 16px;">
            <p><strong><?php echo esc_html($pw_status['message']); ?></strong></p>
            <?php if ($pw_status['reason'] === 'unsupported' && wppilot_likely_local_http()): ?>
                <p style="margin:8px 0 0;">
                    <?php esc_html_e(
                        'This site is on a local hostname over HTTP. Add this line to your wp-config.php (above the "/* That\'s all" comment), then reload:',
                        domain: 'wppilot',
                    ); ?>
                </p>
                <pre style="background:#f6f7f7; border:1px solid #c3c4c7; padding:8px 12px; margin:6px 0 0; font-size:13px; border-radius:3px;">define('WP_ENVIRONMENT_TYPE', 'local');</pre>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($new_password !== null): ?>
        <div class="notice notice-success inline" style="margin:8px 0 16px;">
            <p style="margin:0 0 8px;"><?php esc_html_e(
                'Application password generated. It is now embedded in the connection text in step 3. Save it somewhere safe: it will not be shown in full again.',
                domain: 'wppilot',
            ); ?></p>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <code id="wppilot-new-pw-value" style="font-size:14px; font-weight:600; padding:6px 10px; background:#fff; border:1px solid #c3c4c7; border-radius:3px;"><?php echo
                    esc_html($new_password)
                ; ?></code>
                <button type="button" class="button button-small" onclick="wppilotCopy('wppilot-new-pw-value', this)">
                    <?php esc_html_e('Copy password only', domain: 'wppilot'); ?>
                </button>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($new_password === null && $existing_password !== null): ?>
        <div class="notice notice-success inline" style="margin:8px 0 16px;">
            <p style="margin:0;"><?php esc_html_e(
                'Password accepted. It is now embedded in the connection text in step 4.',
                domain: 'wppilot',
            ); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" style="margin: 0;">
        <?php wp_nonce_field('wppilot_create_password'); ?>
        <?php if (!$has_existing): ?>
            <p style="margin:0 0 10px;">
                <button
                    type="button"
                    class="button-link"
                    id="wppilot-password-name-toggle"
                    aria-expanded="false"
                    aria-controls="wppilot-password-name-field"
                    onclick="wppilotTogglePasswordName(this)"
                ><?php esc_html_e('Customize password name (optional)', domain: 'wppilot'); ?></button>
            </p>
        <?php endif; ?>
        <div
            id="wppilot-password-name-field"
            <?php echo $has_existing ? '' : 'hidden'; ?>
            style="margin: 0 0 12px; <?php echo $has_existing ? '' : 'display:none;'; ?>"
        >
            <label for="wppilot-password-name" style="display:block; margin-bottom:4px;">
                <strong><?php esc_html_e('Name', domain: 'wppilot'); ?></strong>
            </label>
            <input
                type="text"
                id="wppilot-password-name"
                name="wppilot_password_name"
                placeholder="<?php esc_attr_e('e.g. Cursor on laptop, Claude Desktop', domain: 'wppilot'); ?>"
                style="width:300px;"
                class="regular-text"
                maxlength="70"
            />
            <p class="description" style="margin-top:4px;">
                <?php esc_html_e(
                    'A label to identify this credential later. Leave blank to use "GoBuildWP".',
                    domain: 'wppilot',
                ); ?>
            </p>
        </div>
        <button
            type="submit"
            name="wppilot_create_password"
            class="button button-primary"
            <?php echo !$pw_status['available'] ? 'disabled' : ''; ?>>
            <?php echo
                $has_existing
                    ? esc_html__('Generate another application password', domain: 'wppilot')
                    : esc_html__('Generate application password', domain: 'wppilot')
            ; ?>
        </button>
    </form>

    <p style="margin:14px 0 4px;">
        <button
            type="button"
            class="button-link"
            id="wppilot-use-existing-toggle"
            aria-expanded="<?php echo $existing_section_open ? 'true' : 'false'; ?>"
            aria-controls="wppilot-use-existing-field"
            onclick="wppilotToggleUseExisting(this)"
        ><?php esc_html_e('I already have an application password', domain: 'wppilot'); ?></button>
    </p>
    <div
        id="wppilot-use-existing-field"
        <?php echo $existing_section_open ? '' : 'hidden'; ?>
        style="margin:6px 0 0; <?php echo $existing_section_open ? '' : 'display:none;'; ?>"
    >
        <form method="post" style="margin:0;">
            <?php wp_nonce_field('wppilot_use_existing_password'); ?>
            <label for="wppilot-existing-password" style="display:block; margin-bottom:4px;">
                <strong><?php esc_html_e('Paste the password value', domain: 'wppilot'); ?></strong>
            </label>
            <input
                type="text"
                id="wppilot-existing-password"
                name="wppilot_existing_password"
                placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"
                style="width:340px; font-family:monospace;"
                class="regular-text"
                autocomplete="off"
            />
            <button type="submit" name="wppilot_use_existing_password" class="button">
                <?php esc_html_e('Use this password', domain: 'wppilot'); ?>
            </button>
            <?php if ($existing_error !== null): ?>
                <div class="notice notice-error inline" style="margin:8px 0 0;">
                    <p style="margin:0;"><?php echo esc_html($existing_error->get_error_message()); ?></p>
                </div>
            <?php endif; ?>
            <p class="description" style="margin-top:4px;">
                <?php esc_html_e(
                    'For reusing an application password you already saved (e.g. from a password manager). It is used only to fill the connection text and never stored on this site.',
                    domain: 'wppilot',
                ); ?>
            </p>
        </form>
    </div>
    <?php
}

/**
 * Render the "Manage existing application passwords" collapsible section at the bottom of the page.
 *
 * Only meaningful when at least one GoBuildWP-tagged password exists. Hosts the list with revoke
 * buttons. Used both when AI Abilities are enabled (revoke + create lives elsewhere) and when
 * disabled (revoke only).
 */
function wppilot_render_manage_passwords_section(string $context = 'enabled'): void
{
    $mcp_passwords = wppilot_get_mcp_passwords();
    if ($mcp_passwords === []) {
        return;
    }

    $dt_format = wppilot_get_datetime_format('Y-m-d H:i');
    $count = count($mcp_passwords);
    $open_by_default = $count <= 3;
    $summary = sprintf(
        /* translators: %d: count of existing application passwords */
        _n(
            single: 'Manage existing application password (%d)',
            plural: 'Manage existing application passwords (%d)',
            number: $count,
            domain: 'wppilot',
        ),
        $count,
    );
    ?>
    <details class="wppilot-manage-passwords"<?php echo $open_by_default ? ' open' : ''; ?>>
        <summary class="wppilot-manage-passwords-summary">
            <?php echo esc_html($summary); ?>
        </summary>
        <div class="wppilot-manage-passwords-body">
            <?php if ($context === 'disabled'): ?>
                <p class="description" style="margin:0 0 12px;">
                    <?php esc_html_e(
                        'AI Abilities are disabled. These credentials remain valid for WordPress authentication, but the GoBuildWP MCP endpoint will reject requests until AI Abilities are turned back on.',
                        domain: 'wppilot',
                    ); ?>
                </p>
            <?php endif; ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', domain: 'wppilot'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Created', domain: 'wppilot'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Last Used', domain: 'wppilot'); ?></th>
                        <th style="width:80px;"><?php esc_html_e('Actions', domain: 'wppilot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mcp_passwords as $pw): ?>
                        <?php wppilot_render_password_row($pw, $dt_format); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php
}

/**
 * Informational notice above the connect prompt: pasting the prompt hands the
 * application password to the AI agent. Links to the manual configuration,
 * which reaches the same result without exposing the password to the AI.
 */
function wppilot_render_prompt_password_notice(): void
{ ?>
    <div id="wppilot-prompt-password-notice" class="notice notice-info inline" style="margin:0 0 12px;">
        <p style="margin:0;">
            <strong><?php esc_html_e(
                'This prompt shares your application password with your AI agent.',
                domain: 'wppilot',
            ); ?></strong>
            <?php printf(
                /* translators: %s: link that opens the manual configuration section */
                esc_html__(
                    'Prefer to keep it private? Use the %s and paste the snippet into the config file yourself.',
                    domain: 'wppilot',
                ),
                '<button type="button" class="button-link" onclick="wppilotOpenManualConfig()">'
                . esc_html__('manual configuration', domain: 'wppilot')
                . '</button>',
            ); ?>
        </p>
    </div>
    <?php }
