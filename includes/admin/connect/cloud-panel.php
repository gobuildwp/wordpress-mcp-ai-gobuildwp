<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reads only: outcome codes and a pairing state set by this plugin's own redirects, each whitelist-compared or validated before use. Every action on this panel posts to admin-post.php with its own nonce.

/**
 * The GoBuildWP Cloud method card's panel.
 *
 * Three states: not connected (a Connect button), a pairing waiting for the
 * administrator to confirm what the Cloud asked for (§2), and connected. The
 * work happens in includes/cloud/pairing.php; this only renders it.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Whether the page should open on the Cloud card: a pairing step just
 * redirected back here.
 */
function wppilot_cloud_method_preselected(): bool
{
    return (isset($_GET['wppilot_method']) && $_GET['wppilot_method'] === 'cloud')
        || isset($_GET['wppilot_cloud_state']) || isset($_GET['wppilot_cloud_result']) || isset($_GET['wppilot_cloud_error']);
}

/**
 * The sentence for an error code a pairing step redirected with.
 */
function wppilot_cloud_error_message(string $code): string
{
    return match ($code) {
        'expired' => __('This connection request expired or was started by another user. Start again.', domain: 'wppilot'),
        'already_connected' => __('This site is already connected to GoBuildWP Cloud. Disconnect it first.', domain: 'wppilot'),
        'unavailable' => __('GoBuildWP Cloud cannot be used from this site. It needs HTTPS (or a local environment), and the configured GoBuildWP Cloud address must be HTTPS outside a local or development environment.', domain: 'wppilot'),
        'abilities_off' => __('Turn AI Abilities on before connecting GoBuildWP Cloud: it checks the connection works before it finishes.', domain: 'wppilot'),
        'declined' => __('The connection was cancelled on GoBuildWP Cloud. Nothing was changed on this site.', domain: 'wppilot'),
        'network' => __('This site could not reach GoBuildWP Cloud. Nothing was changed; try again in a moment.', domain: 'wppilot'),
        'policy' => __('GoBuildWP Cloud did not accept this connection request. Start again from this screen.', domain: 'wppilot'),
        'complete' => __('GoBuildWP Cloud could not finish connecting this site, so the access token created for it was revoked. GoBuildWP Cloud has to reach this site over the internet to finish.', domain: 'wppilot'),
        'invalid_response' => __('GoBuildWP Cloud sent an answer this site could not use. Nothing was changed.', domain: 'wppilot'),
        'token' => __('The access token for GoBuildWP Cloud could not be created.', domain: 'wppilot'),
        'keys' => __('This site cannot create the signing key GoBuildWP Cloud needs: PHP\'s sodium extension is unavailable.', domain: 'wppilot'),
        default => __('Connecting to GoBuildWP Cloud failed.', domain: 'wppilot'),
    };
}

/**
 * What to do about a failed connection check, from the detail GoBuildWP Cloud
 * returned (`verification_failed-{kind}[_{status}]`). Empty when there is no advice.
 */
function wppilot_cloud_detail_hint(string $detail): string
{
    if (!str_starts_with($detail, 'verification_failed-')) {
        return '';
    }
    $reason = substr($detail, strlen('verification_failed-'));

    return match (true) {
        str_starts_with($reason, 'unauthorized') => __('This site, or a firewall or security plugin in front of it, refused GoBuildWP Cloud\'s test request. Allow POST requests to /wp-json/mcp/ that carry an "Authorization: Bearer" header and the user agent WPPilot-Cloud/1. Connection Doctor does not test this kind of token.', domain: 'wppilot'),
        str_starts_with($reason, 'protocol') => __('This site answered GoBuildWP Cloud\'s test request with something other than an MCP response, usually a firewall or bot-protection challenge page, a redirect, or a cached page. Exclude /wp-json/mcp/ from those, and check the Site Address in Settings > General matches the address the site is served on.', domain: 'wppilot'),
        str_starts_with($reason, 'unreachable') => __('GoBuildWP Cloud could not reach this site, or the site returned a server error. Check the site is reachable from the internet and look in the server error log.', domain: 'wppilot'),
        str_starts_with($reason, 'remote') => __('The MCP server on this site returned an error when listing its tools. Check AI Abilities are on and look in the PHP error log.', domain: 'wppilot'),
        default => '',
    };
}

/**
 * Render the panel under the GoBuildWP Cloud card.
 */
// Inherent: a template reading four request values and choosing one of three states.
// @mago-expect lint:cyclomatic-complexity
function wppilot_render_cloud_panel(): void
{
    $result = is_string($_GET['wppilot_cloud_result'] ?? null) ? sanitize_key(wp_unslash($_GET['wppilot_cloud_result'])) : '';
    $error = is_string($_GET['wppilot_cloud_error'] ?? null) ? sanitize_key(wp_unslash($_GET['wppilot_cloud_error'])) : '';
    $detail = is_string($_GET['wppilot_cloud_detail'] ?? null) ? sanitize_key(wp_unslash($_GET['wppilot_cloud_detail'])) : '';
    $state = is_string($_GET['wppilot_cloud_state'] ?? null) ? sanitize_text_field(wp_unslash($_GET['wppilot_cloud_state'])) : '';

    $result_message = match ($result) {
        'connected' => __('This site is connected to GoBuildWP Cloud.', domain: 'wppilot'),
        'disconnected' => __('Disconnected from GoBuildWP Cloud. Its access token was revoked.', domain: 'wppilot'),
        'manage_saved' => __('Saved.', domain: 'wppilot'),
        'cancelled' => __('Connection cancelled. Nothing was changed on this site.', domain: 'wppilot'),
        default => '',
    };
    ?>
    <div id="wppilot-cloud-panel" style="margin-top:16px;">
        <?php if ($result_message !== ''): ?>
            <div class="notice notice-success inline" style="margin:0 0 12px;"><p><?php echo esc_html($result_message); ?></p></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="notice notice-error inline" style="margin:0 0 12px;"><p>
                <?php echo esc_html(wppilot_cloud_error_message($error)); ?>
                <?php if ($detail !== ''): ?>
                    <br /><span class="description"><?php echo esc_html(sprintf(
                        /* translators: %s: machine-readable error code returned by GoBuildWP Cloud */
                        __('GoBuildWP Cloud said: %s', domain: 'wppilot'),
                        $detail,
                    )); ?></span>
                    <?php $hint = wppilot_cloud_detail_hint($detail); ?>
                    <?php if ($hint !== ''): ?>
                        <br /><?php echo esc_html($hint); ?>
                    <?php endif; ?>
                <?php endif; ?>
            </p></div>
        <?php endif; ?>
        <?php
        $link = wppilot_cloud_link();
        $pending = $link === null && $state !== '' ? wppilot_cloud_pending($state, get_current_user_id()) : null;
        if ($link !== null) {
            wppilot_render_cloud_connected($link);
        } elseif ($pending !== null && is_array($pending['policy'] ?? null)) {
            wppilot_render_cloud_confirm($state, $pending['policy']);
        } else {
            wppilot_render_cloud_disconnected();
        }
        ?>
    </div>
    <?php
}

function wppilot_render_cloud_disconnected(): void
{
    ?>
    <p><?php esc_html_e(
        'Connect this site to your GoBuildWP Cloud workspace once, and every AI client connected to the workspace can reach it - no credential to paste into each one.',
        domain: 'wppilot',
    ); ?></p>
    <p class="description"><?php esc_html_e(
        'You sign in to GoBuildWP Cloud and choose the access level there, then confirm it here. This site creates an access token for the Cloud and gives it only to the Cloud; your safety profile, confirmations and change log still apply to every call.',
        domain: 'wppilot',
    ); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
        <input type="hidden" name="action" value="wppilot_cloud_begin" />
        <?php wp_nonce_field('wppilot_cloud_begin'); ?>
        <button type="submit" class="button button-primary"><?php esc_html_e('Connect to GoBuildWP Cloud', domain: 'wppilot'); ?></button>
    </form>
    <?php
}

/**
 * The §2 confirm screen.
 *
 * @param array<string, mixed> $policy As normalised by wppilot_cloud_parse_policy().
 */
function wppilot_render_cloud_confirm(string $state, array $policy): void
{
    $ceilings = wppilot_token_ceiling_choices();
    $ceiling = is_string($policy['ceiling'] ?? null) ? $policy['ceiling'] : 'readonly';
    $ceiling_label = $ceilings[$ceiling] ?? $ceiling;
    /** @var array{abilities: list<string>, categories: list<string>}|null $scope */
    $scope = is_array($policy['scope'] ?? null) ? $policy['scope'] : null;
    $workspace = is_string($policy['workspace'] ?? null) ? $policy['workspace'] : '';
    $label = is_string($policy['label'] ?? null) ? $policy['label'] : '';
    $login = (string) wp_get_current_user()->user_login;
    ?>
    <div class="notice notice-info inline" style="margin:0; padding:12px 16px;">
        <p style="font-size:14px; margin-top:0;"><strong><?php echo esc_html(sprintf(
            /* translators: 1: masked GoBuildWP Cloud account email, 2: safety profile label, 3: WordPress username */
            __('Connect this site to GoBuildWP Cloud account %1$s with %2$s access as WordPress user %3$s', domain: 'wppilot'),
            (string) ($policy['account_hint'] ?? ''),
            $ceiling_label,
            $login,
        )); ?></strong></p>
        <ul style="list-style:disc; margin-left:20px;">
            <?php if ($workspace !== ''): ?>
                <li><?php echo esc_html(sprintf(/* translators: %s: workspace name */ __('Workspace: %s', domain: 'wppilot'), $workspace)); ?></li>
            <?php endif; ?>
            <?php if ($label !== ''): ?>
                <li><?php echo esc_html(sprintf(/* translators: %s: site label chosen in GoBuildWP Cloud */ __('Site label: %s', domain: 'wppilot'), $label)); ?></li>
            <?php endif; ?>
            <li><?php echo esc_html(sprintf(
                /* translators: %s: abilities the token may use */
                __('May use: %s', domain: 'wppilot'),
                wppilot_token_policy_summary(['scope' => $scope, 'ceiling' => '']),
            )); ?></li>
            <li><?php esc_html_e(
                'An access token that does not expire is created for GoBuildWP Cloud. Disconnect here at any time to revoke it.',
                domain: 'wppilot',
            ); ?></li>
        </ul>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:8px;">
            <input type="hidden" name="action" value="wppilot_cloud_confirm" />
            <input type="hidden" name="state" value="<?php echo esc_attr($state); ?>" />
            <?php wp_nonce_field('wppilot_cloud_confirm_' . $state); ?>
            <button type="submit" class="button button-primary"><?php esc_html_e('Confirm', domain: 'wppilot'); ?></button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
            <input type="hidden" name="action" value="wppilot_cloud_cancel" />
            <input type="hidden" name="state" value="<?php echo esc_attr($state); ?>" />
            <?php wp_nonce_field('wppilot_cloud_confirm_' . $state); ?>
            <button type="submit" class="button"><?php esc_html_e('Cancel', domain: 'wppilot'); ?></button>
        </form>
    </div>
    <?php
}

/**
 * The connected state.
 *
 * @param array{site_id: string|int, token_id: int, account_hint: string, cloud_url: string, paired_at: string} $link
 */
function wppilot_render_cloud_connected(array $link): void
{
    $paired = strtotime($link['paired_at']);
    $paired_label = $paired !== false ? wp_date(wppilot_get_datetime_format('Y-m-d H:i'), $paired) : false;
    ?>
    <table class="form-table" role="presentation" style="margin-top:0;">
        <tr>
            <th scope="row"><?php esc_html_e('Account', domain: 'wppilot'); ?></th>
            <td><?php echo esc_html($link['account_hint'] !== '' ? $link['account_hint'] : __('Unknown', domain: 'wppilot')); ?></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Site ID', domain: 'wppilot'); ?></th>
            <td class="wppilot-mono"><?php echo esc_html((string) $link['site_id']); ?></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Connected', domain: 'wppilot'); ?></th>
            <td><?php echo esc_html(is_string($paired_label) ? $paired_label : __('Unknown', domain: 'wppilot')); ?></td>
        </tr>
    </table>
    <?php wppilot_render_cloud_manage(); ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo
        esc_js(__('Disconnect from GoBuildWP Cloud? Its access token is revoked and AI clients using this site through the Cloud lose access.', domain: 'wppilot'))
    ; ?>');">
        <input type="hidden" name="action" value="wppilot_cloud_disconnect" />
        <?php wp_nonce_field('wppilot_cloud_disconnect'); ?>
        <button type="submit" class="button wppilot-revoke-btn"><?php esc_html_e('Disconnect', domain: 'wppilot'); ?></button>
    </form>
    <?php
}

/**
 * The owner's opt-in to safety settings pushed from the Cloud, and what it last applied.
 */
function wppilot_render_cloud_manage(): void
{
    $manage = wppilot_cloud_manage_settings();
    $applied = wppilot_cloud_applied_policy();
    ?>
    <h3><?php esc_html_e('Safety settings from GoBuildWP Cloud', domain: 'wppilot'); ?></h3>
    <p class="description" style="max-width:640px;">
        <?php esc_html_e('Let your GoBuildWP Cloud workspace apply one safety policy to all its sites: the safety profile, the approval mode, and which abilities are switched off or need a confirmation. Developer Full Access can never be set from the Cloud, and blocks you set here are never lifted by it.', domain: 'wppilot'); ?>
    </p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="wppilot_cloud_manage" />
        <?php wp_nonce_field('wppilot_cloud_manage'); ?>
        <p>
            <label><input type="checkbox" name="wppilot_cloud_manage_tighten" value="1" <?php checked($manage['tighten']); ?> />
                <?php esc_html_e('GoBuildWP Cloud may make these settings stricter', domain: 'wppilot'); ?></label><br />
            <label><input type="checkbox" name="wppilot_cloud_manage_loosen" value="1" <?php checked($manage['loosen']); ?> />
                <?php esc_html_e('…and may also loosen them (never above Production Safe)', domain: 'wppilot'); ?></label>
        </p>
        <p><button type="submit" class="button"><?php esc_html_e('Save', domain: 'wppilot'); ?></button></p>
    </form>
    <?php if ($applied !== null) : ?>
        <p>
            <?php
            printf(
                /* translators: 1: policy name, 2: date and time */
                esc_html__('Managed by GoBuildWP Cloud: policy "%1$s", last applied %2$s. Changes made here may be replaced the next time the policy is pushed.', domain: 'wppilot'),
                esc_html($applied['name'] !== '' ? $applied['name'] : $applied['id']),
                esc_html(($t = strtotime($applied['applied_at'])) !== false ? wp_date(wppilot_get_datetime_format('Y-m-d H:i'), $t) : $applied['applied_at']),
            );
            ?>
        </p>
    <?php endif; ?>
    <?php
}
