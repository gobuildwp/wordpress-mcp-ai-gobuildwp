<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * GoBuildWP > Approvals: the always-ask list, live session approvals, and the approval log.
 *
 * Loaded in wp-admin only (admin-post.php counts as admin, so the save, revoke and download
 * handlers load too). Every POST checks the manage capability and its own nonce.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_APPROVALS_PAGE = 'wppilot-approvals';

const WPPILOT_APPROVALS_PER_PAGE = 50;

function wppilot_approvals_register_menu(): void
{
    add_submenu_page(
        parent_slug: 'wppilot-connect',
        page_title: wppilot_nav_label(WPPILOT_APPROVALS_PAGE, fallback: __('Approvals', domain: 'wppilot')),
        menu_title: wppilot_nav_label(WPPILOT_APPROVALS_PAGE, fallback: __('Approvals', domain: 'wppilot')),
        capability: wppilot_manage_capability(),
        menu_slug: WPPILOT_APPROVALS_PAGE,
        callback: 'wppilot_approvals_render_page',
    );
}

function wppilot_approvals_register_nav(mixed $map): mixed
{
    if (!is_array($map)) {
        return $map;
    }
    $map[WPPILOT_APPROVALS_PAGE] = ['label' => __('Approvals', domain: 'wppilot'), 'group' => 'activity'];

    return $map;
}

function wppilot_approvals_page_url(array $args = []): string
{
    return add_query_arg(array_merge(['page' => WPPILOT_APPROVALS_PAGE], $args), admin_url('admin.php'));
}

function wppilot_approvals_require(string $nonce_action): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('Not allowed.', domain: 'wppilot'), '', ['response' => 403]);
    }
    check_admin_referer($nonce_action);
}

function wppilot_approvals_handle_always_ask(): void
{
    wppilot_approvals_require('wppilot_approvals_always_ask');
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in wppilot_approvals_require().
    $raw = is_string($_POST['always_ask'] ?? null) ? sanitize_textarea_field(wp_unslash($_POST['always_ask'])) : '';
    $names = preg_split('/[\s,]+/', $raw) ?: [];
    wppilot_confirmation_set_always_ask($names, get_current_user_id());

    wp_safe_redirect(wppilot_approvals_page_url(['saved' => '1']));
    exit();
}

function wppilot_approvals_handle_revoke(): void
{
    wppilot_approvals_require('wppilot_approvals_revoke');
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in wppilot_approvals_require().
    $id = is_string($_POST['session'] ?? null) ? sanitize_key(wp_unslash($_POST['session'])) : '';
    $done = $id !== '' && wppilot_confirmation_revoke_session($id, get_current_user_id());

    wp_safe_redirect(wppilot_approvals_page_url(['revoked' => $done ? '1' : '0']));
    exit();
}

function wppilot_approvals_handle_export(): void
{
    wppilot_approvals_require('wppilot_approvals_export');
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in wppilot_approvals_require().
    $filters = wppilot_approval_log_read_filters(wp_unslash($_POST));

    $rows = [];
    for ($offset = 0; $offset < WPPILOT_APPROVAL_LOG_EXPORT_MAX; $offset += 500) {
        $page = wppilot_approval_log_query($filters, 500, $offset);
        array_push($rows, ...$page);
        if (count($page) < 500) {
            break;
        }
    }

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gobuildwp-approvals-' . gmdate('Y-m-d') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    // The CSV is built from cells each quoted and defused in wppilot_approval_log_csv_cell().
    echo wppilot_approval_log_csv($rows); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV download, not HTML.
    exit();
}

function wppilot_approvals_user_label(int $user_id): string
{
    if ($user_id <= 0) {
        return '';
    }
    $user = get_userdata($user_id);

    return $user instanceof WP_User ? $user->user_login . ' (#' . $user_id . ')' : '#' . $user_id;
}

// @mago-expect lint:halstead
function wppilot_approvals_render_page(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('Not allowed.', domain: 'wppilot'), '', ['response' => 403]);
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters and paging for the list.
    $filters = wppilot_approval_log_read_filters(wp_unslash($_GET));
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Paging only.
    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    $rows = wppilot_approval_log_query($filters, WPPILOT_APPROVALS_PER_PAGE + 1, ($paged - 1) * WPPILOT_APPROVALS_PER_PAGE);
    $has_next = count($rows) > WPPILOT_APPROVALS_PER_PAGE;
    $rows = array_slice($rows, 0, WPPILOT_APPROVALS_PER_PAGE);
    $events = wppilot_approval_log_events();
    $sessions = wppilot_confirmation_sessions();
    $limits = wppilot_confirmation_session_limits();

    wppilot_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Approvals', domain: 'wppilot'); ?></h1>
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flags from this page's own redirects.
        if (isset($_GET['saved'])) { ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Always-ask list saved.', domain: 'wppilot'); ?></p></div>
        <?php } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['revoked'])) { ?>
            <div class="notice notice-<?php echo $_GET['revoked'] === '1' ? 'success' : 'warning'; ?> is-dismissible"><p><?php
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                echo esc_html($_GET['revoked'] === '1' ? __('Session approval revoked.', domain: 'wppilot') : __('That session had already ended.', domain: 'wppilot'));
            ?></p></div>
        <?php } ?>

        <?php if (wppilot_confirmation_mode() !== 'human') { ?>
            <div class="notice notice-info inline"><p><?php esc_html_e('Confirmation mode is "Agent flag", so agents confirm destructive calls themselves and nothing here is asked of a person. Switch to "A person approves" under Settings > Confirmation to use approvals.', domain: 'wppilot'); ?></p></div>
        <?php } ?>

        <section class="wppilot-panel">
            <h2 class="wppilot-setting-group__title"><?php esc_html_e('Always ask', domain: 'wppilot'); ?></h2>
            <p class="description"><?php esc_html_e('Abilities listed here always need a person to approve each call, even when their risk level would not, and are never covered by a session approval. One ability name per line, for example wppilot/delete-post. Adding an ability ends any session approval that covers it.', domain: 'wppilot'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wppilot_approvals_always_ask">
                <?php wp_nonce_field('wppilot_approvals_always_ask'); ?>
                <textarea name="always_ask" rows="6" cols="60" class="large-text code"><?php echo esc_textarea(implode("\n", wppilot_confirmation_always_ask())); ?></textarea>
                <p><button type="submit" class="button button-primary"><?php esc_html_e('Save always-ask list', domain: 'wppilot'); ?></button></p>
            </form>
        </section>

        <section class="wppilot-panel">
            <h2 class="wppilot-setting-group__title"><?php esc_html_e('Session approvals', domain: 'wppilot'); ?></h2>
            <p class="description"><?php echo esc_html(sprintf(
                /* translators: 1: minutes, 2: number of calls */
                __('Given from an approval link. Each lets one agent connection run one ability without asking, for %1$d minutes or %2$d calls, whichever ends first.', domain: 'wppilot'),
                intdiv($limits['seconds'], MINUTE_IN_SECONDS),
                $limits['calls'],
            )); ?></p>
            <?php if ($sessions === []) { ?>
                <p><?php esc_html_e('No session approvals are active.', domain: 'wppilot'); ?></p>
            <?php } else { ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr>
                        <th><?php esc_html_e('Ability', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Agent', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Runs as', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Approved by', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Calls left', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Ends (UTC)', domain: 'wppilot'); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($sessions as $id => $grant) { ?>
                        <tr>
                            <td><code><?php echo esc_html((string) ($grant['ability'] ?? '')); ?></code></td>
                            <td><?php echo esc_html((string) ($grant['agent'] ?? '')); ?></td>
                            <td><?php echo esc_html(wppilot_approvals_user_label((int) ($grant['user_id'] ?? 0))); ?></td>
                            <td><?php echo esc_html(wppilot_approvals_user_label((int) ($grant['granted_by'] ?? 0))); ?></td>
                            <td><?php echo esc_html((string) (int) ($grant['calls_left'] ?? 0)); ?></td>
                            <td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int) ($grant['expires_at'] ?? 0))); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="wppilot_approvals_revoke">
                                    <input type="hidden" name="session" value="<?php echo esc_attr((string) $id); ?>">
                                    <?php wp_nonce_field('wppilot_approvals_revoke'); ?>
                                    <button type="submit" class="button"><?php esc_html_e('Revoke', domain: 'wppilot'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            <?php } ?>
        </section>

        <section class="wppilot-panel">
            <h2 class="wppilot-setting-group__title"><?php esc_html_e('Approval log', domain: 'wppilot'); ?></h2>
            <p class="description"><?php echo esc_html(sprintf(
                /* translators: %d: number of days */
                __('Every decision a person made about an agent call, newest first. Rows cannot be edited or deleted; they are removed only after %d days. The input of a call is never stored, only its SHA-256 fingerprint.', domain: 'wppilot'),
                wppilot_approval_log_retention_days(),
            )); ?></p>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr(WPPILOT_APPROVALS_PAGE); ?>">
                <select name="event">
                    <option value=""><?php esc_html_e('All events', domain: 'wppilot'); ?></option>
                    <?php foreach ($events as $slug => $label) { ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($filters['event'], $slug); ?>><?php echo esc_html($label); ?></option>
                    <?php } ?>
                </select>
                <input type="text" name="ability" value="<?php echo esc_attr($filters['ability']); ?>" placeholder="<?php esc_attr_e('Ability name', domain: 'wppilot'); ?>">
                <label><?php esc_html_e('From', domain: 'wppilot'); ?> <input type="date" name="since" value="<?php echo esc_attr($filters['since']); ?>"></label>
                <label><?php esc_html_e('To', domain: 'wppilot'); ?> <input type="date" name="until" value="<?php echo esc_attr($filters['until']); ?>"></label>
                <button type="submit" class="button"><?php esc_html_e('Filter', domain: 'wppilot'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
                <input type="hidden" name="action" value="wppilot_approvals_export">
                <?php wp_nonce_field('wppilot_approvals_export'); ?>
                <?php foreach ($filters as $key => $value) { ?>
                    <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                <?php } ?>
                <button type="submit" class="button"><?php esc_html_e('Download CSV', domain: 'wppilot'); ?></button>
            </form>

            <?php if ($rows === []) { ?>
                <p><?php esc_html_e('No approval events match.', domain: 'wppilot'); ?></p>
            <?php } else { ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
                    <thead><tr>
                        <th><?php esc_html_e('Time (UTC)', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Event', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Ability', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Agent', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Runs as', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Decided by', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Detail', domain: 'wppilot'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row) { ?>
                        <tr>
                            <td><?php echo esc_html($row['created_at']); ?></td>
                            <td><?php echo esc_html($events[$row['event']] ?? $row['event']); ?></td>
                            <td><code><?php echo esc_html($row['ability']); ?></code></td>
                            <td><?php echo esc_html($row['agent']); ?></td>
                            <td><?php echo esc_html(wppilot_approvals_user_label((int) $row['user_id'])); ?></td>
                            <td><?php echo esc_html(wppilot_approvals_user_label((int) $row['actor_id'])); ?></td>
                            <td><?php echo esc_html($row['detail']); ?><?php if ($row['input_sha256'] !== '') { ?><br><code title="<?php echo esc_attr($row['input_sha256']); ?>"><?php echo esc_html(substr($row['input_sha256'], 0, 12)); ?></code><?php } ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
                <p>
                    <?php if ($paged > 1) { ?>
                        <a class="button" href="<?php echo esc_url(wppilot_approvals_page_url(array_merge(array_filter($filters), ['paged' => $paged - 1]))); ?>"><?php esc_html_e('Newer', domain: 'wppilot'); ?></a>
                    <?php } ?>
                    <?php if ($has_next) { ?>
                        <a class="button" href="<?php echo esc_url(wppilot_approvals_page_url(array_merge(array_filter($filters), ['paged' => $paged + 1]))); ?>"><?php esc_html_e('Older', domain: 'wppilot'); ?></a>
                    <?php } ?>
                </p>
            <?php } ?>
        </section>
    </div>
    <?php
}

add_action('admin_menu', 'wppilot_approvals_register_menu', priority: 37);
add_filter('wppilot_nav_map', 'wppilot_approvals_register_nav');
add_action('admin_post_wppilot_approvals_always_ask', 'wppilot_approvals_handle_always_ask');
add_action('admin_post_wppilot_approvals_revoke', 'wppilot_approvals_handle_revoke');
add_action('admin_post_wppilot_approvals_export', 'wppilot_approvals_handle_export');
