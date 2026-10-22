<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The wp-admin page where a person approves or denies one exact destructive call.
 *
 * Reached from the link an agent is given when the site's confirmation mode is `human` and its
 * client cannot ask the user itself (see includes/confirmation.php). The link carries only an
 * unguessable request id; the decision is a POST protected by a nonce and the manage capability,
 * so an agent holding the link cannot approve through it, and nothing it could put in the page's
 * URL changes what is approved: the ability and the input hash were fixed when the link was made.
 *
 * Any administrator may decide, not only the account the agent is acting as. Agents often run as
 * a dedicated account nobody signs in to, and every account an agent can connect as already
 * manages GoBuildWP, so no privilege is borrowed. Who decided is recorded and reaches the ledger.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_CONFIRM_PAGE = 'wppilot-confirm';

function wppilot_confirm_register_page(): void
{
    $hook = add_submenu_page(
        parent_slug: '',
        page_title: __('Approve an agent action', domain: 'wppilot'),
        menu_title: '',
        capability: wppilot_manage_capability(),
        menu_slug: WPPILOT_CONFIRM_PAGE,
        callback: 'wppilot_confirm_render_page',
    );

    // The decision redirects, so it must be handled before the admin header sends output.
    if (is_string($hook) && $hook !== '') {
        add_action('load-' . $hook, 'wppilot_confirm_handle_load');
        // A page with no parent menu has no title WordPress can find, so the browser tab read
        // "‹ Site — WordPress" with nothing in front.
        add_action('load-' . $hook, static function (): void {
            add_filter('admin_title', static fn(string $title): string => __('Approve an agent action', domain: 'wppilot') . ' ' . $title);
        });
    }
}

function wppilot_confirm_request_id(): string
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The id only selects which pending request to show; the Approve/Deny POST that acts on it is nonce-checked in wppilot_confirm_handle_load().
    $raw = is_string($_GET['request'] ?? null) ? sanitize_text_field(wp_unslash($_GET['request'])) : '';

    return preg_match('/^[a-f0-9]{32}$/', $raw) === 1 ? $raw : '';
}

function wppilot_confirm_handle_load(): void
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to approve agent actions.', domain: 'wppilot'), '', ['response' => 403]);
    }

    $id = wppilot_confirm_request_id();
    if ($id === '') {
        wp_die(esc_html__('This approval link is not valid.', domain: 'wppilot'), '', ['response' => 400]);
    }
    check_admin_referer('wppilot_confirm_' . $id);

    $decision = is_string($_POST['wppilot_confirm_decision'] ?? null)
        ? sanitize_text_field(wp_unslash($_POST['wppilot_confirm_decision']))
        : '';
    if ($decision !== 'approve' && $decision !== 'deny') {
        wp_die(esc_html__('Choose Approve or Deny.', domain: 'wppilot'), '', ['response' => 400]);
    }

    $done = wppilot_confirmation_decide($id, approve: $decision === 'approve', decided_by: get_current_user_id());

    wp_safe_redirect(add_query_arg(
        ['request' => $id, 'decided' => $done ? $decision : 'stale'],
        admin_url('admin.php?page=' . WPPILOT_CONFIRM_PAGE),
    ));
    exit();
}

function wppilot_confirm_render_page(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to approve agent actions.', domain: 'wppilot'));
    }

    $id = wppilot_confirm_request_id();
    $request = $id === '' ? null : wppilot_confirmation_get_request($id);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only: the outcome code from this page's own redirect selects one of three fixed notices.
    $decided = is_string($_GET['decided'] ?? null) ? sanitize_key(wp_unslash($_GET['decided'])) : '';

    wppilot_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Approve an agent action', domain: 'wppilot'); ?></h1>
        <?php if ($decided === 'approve') { ?>
            <div class="notice notice-success"><p><?php esc_html_e('Approved. Tell the agent to retry the same call; the approval works once, within 5 minutes.', domain: 'wppilot'); ?></p></div>
        <?php } elseif ($decided === 'deny') { ?>
            <div class="notice notice-warning"><p><?php esc_html_e('Denied. The agent will be told not to retry this call.', domain: 'wppilot'); ?></p></div>
        <?php } elseif ($decided === 'stale') { ?>
            <div class="notice notice-error"><p><?php esc_html_e('This request was already decided or has expired, so nothing changed.', domain: 'wppilot'); ?></p></div>
        <?php } ?>

        <?php if ($request === null) { ?>
            <p><?php esc_html_e('This approval link has expired or does not exist. If the agent still needs to run the action, it will get a new link when it tries again.', domain: 'wppilot'); ?></p>
        <?php
            echo '</div>';
            return;
        }

        $status = (string) ($request['status'] ?? '');
        $label = (string) ($request['label'] ?? '');
        $agent = (string) ($request['agent'] ?? '');
        ?>
        <section class="wppilot-panel">
            <h2 class="wppilot-setting-group__title"><?php echo esc_html($label !== '' ? $label : (string) ($request['ability'] ?? '')); ?></h2>
            <table class="form-table" role="presentation">
                <tr><th scope="row"><?php esc_html_e('Ability', domain: 'wppilot'); ?></th><td><code><?php echo esc_html((string) ($request['ability'] ?? '')); ?></code></td></tr>
                <tr><th scope="row"><?php esc_html_e('Risk', domain: 'wppilot'); ?></th><td><?php echo esc_html((string) ($request['risk'] ?? '')); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e('Runs as', domain: 'wppilot'); ?></th><td><?php echo esc_html((string) ($request['user_login'] ?? '')); ?></td></tr>
                <?php if ($agent !== '') { ?>
                    <tr><th scope="row"><?php esc_html_e('Agent', domain: 'wppilot'); ?></th><td><?php echo esc_html($agent); ?></td></tr>
                <?php } ?>
                <tr><th scope="row"><?php esc_html_e('Requested', domain: 'wppilot'); ?></th><td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int) ($request['created_at'] ?? 0)) . ' UTC'); ?></td></tr>
                <tr><th scope="row"><?php esc_html_e('Status', domain: 'wppilot'); ?></th><td><?php echo esc_html($status); ?></td></tr>
            </table>
            <h3><?php esc_html_e('Exactly what will run', domain: 'wppilot'); ?></h3>
            <p class="description"><?php esc_html_e('The approval is bound to this input. If the agent changes anything, it will need a new approval. Fields that look like secrets are masked here but run as sent.', domain: 'wppilot'); ?></p>
            <pre style="max-height:32em;overflow:auto;white-space:pre-wrap;word-break:break-word;"><?php echo esc_html((string) ($request['input_json'] ?? '')); ?></pre>
            <p class="description"><?php
                /* translators: %s: SHA-256 hash of the input */
                echo esc_html(sprintf(__('Input fingerprint (SHA-256): %s', domain: 'wppilot'), (string) ($request['input_sha256'] ?? '')));
            ?></p>

            <?php if ($status === 'pending') { ?>
                <form method="post" action="">
                    <?php wp_nonce_field('wppilot_confirm_' . $id); ?>
                    <p>
                        <button type="submit" name="wppilot_confirm_decision" value="approve" class="button button-primary"><?php esc_html_e('Approve this once', domain: 'wppilot'); ?></button>
                        <button type="submit" name="wppilot_confirm_decision" value="deny" class="button"><?php esc_html_e('Deny', domain: 'wppilot'); ?></button>
                    </p>
                </form>
            <?php } ?>
        </section>
    </div>
    <?php
}

/**
 * The Confirmation mode switch on the Settings screen.
 */
function wppilot_confirm_register_setting(mixed $sections): mixed
{
    if (!is_array($sections)) {
        return $sections;
    }

    $sections[] = [
        'id' => 'wppilot-confirmation',
        'title' => __('Confirmation', domain: 'wppilot'),
        'description' => __(
            'How a destructive or critical agent call proves that a person approved it.',
            domain: 'wppilot',
        ),
        'fields' => [
            [
                'type' => 'select',
                'name' => WPPILOT_CONFIRMATION_MODE_OPTION,
                'label' => __('Confirmation mode', domain: 'wppilot'),
                'help' => __(
                    'Agent flag: the agent sends confirm=true, which the AI model sets itself. A person approves: MCP and REST calls also need the person to approve the exact call, either in their MCP client (when it supports elicitation) or on a one-time wp-admin link the agent is given. The built-in Chat and Pro\'s approval queue already have a person approve, and are unaffected.',
                    domain: 'wppilot',
                ),
                'value' => wppilot_confirmation_mode(),
                'options' => [
                    'argument' => __('Agent flag (confirm=true)', domain: 'wppilot'),
                    'human' => __('A person approves', domain: 'wppilot'),
                ],
            ],
        ],
        'save' => 'wppilot_confirm_save_setting',
    ];

    return $sections;
}

/**
 * @param array<string, mixed> $post
 */
function wppilot_confirm_save_setting(array $post): void
{
    $mode = is_string($post[WPPILOT_CONFIRMATION_MODE_OPTION] ?? null) ? (string) $post[WPPILOT_CONFIRMATION_MODE_OPTION] : '';
    if (in_array($mode, WPPILOT_CONFIRMATION_MODES, strict: true)) {
        update_option(WPPILOT_CONFIRMATION_MODE_OPTION, $mode, autoload: true);
    }
}

add_action('admin_menu', 'wppilot_confirm_register_page');
add_filter('wppilot_settings_sections', 'wppilot_confirm_register_setting');
