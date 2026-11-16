<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * GoBuildWP > MCP requests: the request log with filters and a CSV download.
 *
 * Loaded in wp-admin only (admin-post.php counts as admin, so the download handler loads too).
 * The download checks the manage capability and its own nonce.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_REQUEST_LOG_PAGE = 'wppilot-request-log';

const WPPILOT_REQUEST_LOG_PER_PAGE = 50;

function wppilot_request_log_register_menu(): void
{
    add_submenu_page(
        parent_slug: 'wppilot-connect',
        page_title: wppilot_nav_label(WPPILOT_REQUEST_LOG_PAGE, fallback: __('MCP requests', domain: 'wppilot')),
        menu_title: wppilot_nav_label(WPPILOT_REQUEST_LOG_PAGE, fallback: __('MCP requests', domain: 'wppilot')),
        capability: wppilot_manage_capability(),
        menu_slug: WPPILOT_REQUEST_LOG_PAGE,
        callback: 'wppilot_request_log_render_page',
    );
}

function wppilot_request_log_register_nav(mixed $map): mixed
{
    if (!is_array($map)) {
        return $map;
    }
    $map[WPPILOT_REQUEST_LOG_PAGE] = ['label' => __('MCP requests', domain: 'wppilot'), 'group' => 'activity'];

    return $map;
}

function wppilot_request_log_page_url(array $args = []): string
{
    return add_query_arg(array_merge(['page' => WPPILOT_REQUEST_LOG_PAGE], $args), admin_url('admin.php'));
}

function wppilot_request_log_handle_export(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('Not allowed.', domain: 'wppilot'), '', ['response' => 403]);
    }
    check_admin_referer('wppilot_request_log_export');
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
    $filters = wppilot_mcp_request_log_read_filters(wp_unslash($_POST));
    wppilot_mcp_request_log_prune();
    // The table never holds more than WPPILOT_MCP_REQUEST_LOG_MAX_ROWS rows, so one query is all of it.
    $rows = wppilot_mcp_request_log_query($filters, WPPILOT_MCP_REQUEST_LOG_MAX_ROWS);

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gobuildwp-mcp-requests-' . gmdate('Y-m-d') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    // The CSV is built from cells each quoted and defused in wppilot_approval_log_csv_cell().
    echo wppilot_mcp_request_log_csv($rows); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV download, not HTML.
    exit();
}

function wppilot_request_log_user_label(int $user_id): string
{
    if ($user_id <= 0) {
        return '';
    }
    $user = get_userdata($user_id);

    return $user instanceof WP_User ? $user->user_login . ' (#' . $user_id . ')' : '#' . $user_id;
}

// @mago-expect lint:halstead
function wppilot_request_log_render_page(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('Not allowed.', domain: 'wppilot'), '', ['response' => 403]);
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters and paging for the list.
    $filters = wppilot_mcp_request_log_read_filters(wp_unslash($_GET));
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Paging only.
    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    // A site nobody has called for a while still drops rows past the day limit.
    wppilot_mcp_request_log_prune();
    $rows = wppilot_mcp_request_log_query($filters, WPPILOT_REQUEST_LOG_PER_PAGE + 1, ($paged - 1) * WPPILOT_REQUEST_LOG_PER_PAGE);
    $has_next = count($rows) > WPPILOT_REQUEST_LOG_PER_PAGE;
    $rows = array_slice($rows, 0, WPPILOT_REQUEST_LOG_PER_PAGE);
    $outcomes = wppilot_mcp_request_log_outcomes();
    $methods = ['initialize', 'ping', 'tools/list', 'tools/call', 'resources/list', 'resources/read', 'prompts/list', 'prompts/get', 'batch'];

    wppilot_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('MCP requests', domain: 'wppilot'); ?></h1>
        <section class="wppilot-panel">
            <p class="description"><?php echo esc_html(sprintf(
                /* translators: 1: number of rows, 2: number of days */
                __('Every request an MCP client made to this site, reads included, newest first. The newest %1$d requests are kept, none older than %2$d days. The arguments of a call and what it returned are never stored; for execute-ability the ability it ran is shown.', domain: 'wppilot'),
                WPPILOT_MCP_REQUEST_LOG_MAX_ROWS,
                WPPILOT_MCP_REQUEST_LOG_MAX_DAYS,
            )); ?></p>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr(WPPILOT_REQUEST_LOG_PAGE); ?>">
                <select name="outcome">
                    <option value=""><?php esc_html_e('All outcomes', domain: 'wppilot'); ?></option>
                    <?php foreach ($outcomes as $slug => $label) { ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($filters['outcome'], $slug); ?>><?php echo esc_html($label); ?></option>
                    <?php } ?>
                </select>
                <select name="rpc_method">
                    <option value=""><?php esc_html_e('All methods', domain: 'wppilot'); ?></option>
                    <?php foreach ($methods as $method) { ?>
                        <option value="<?php echo esc_attr($method); ?>" <?php selected($filters['rpc_method'], $method); ?>><?php echo esc_html($method); ?></option>
                    <?php } ?>
                </select>
                <input type="text" name="tool" value="<?php echo esc_attr($filters['tool']); ?>" placeholder="<?php esc_attr_e('Tool or ability', domain: 'wppilot'); ?>">
                <input type="text" name="client" value="<?php echo esc_attr($filters['client']); ?>" placeholder="<?php esc_attr_e('Client', domain: 'wppilot'); ?>">
                <label><?php esc_html_e('From', domain: 'wppilot'); ?> <input type="date" name="since" value="<?php echo esc_attr($filters['since']); ?>"></label>
                <label><?php esc_html_e('To', domain: 'wppilot'); ?> <input type="date" name="until" value="<?php echo esc_attr($filters['until']); ?>"></label>
                <button type="submit" class="button"><?php esc_html_e('Filter', domain: 'wppilot'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
                <input type="hidden" name="action" value="wppilot_request_log_export">
                <?php wp_nonce_field('wppilot_request_log_export'); ?>
                <?php foreach ($filters as $key => $value) { ?>
                    <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                <?php } ?>
                <button type="submit" class="button"><?php esc_html_e('Download CSV', domain: 'wppilot'); ?></button>
            </form>

            <?php if ($rows === []) { ?>
                <p><?php esc_html_e('No MCP requests match.', domain: 'wppilot'); ?></p>
            <?php } else { ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
                    <thead><tr>
                        <th><?php esc_html_e('Time (UTC)', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Connection', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Client', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Method', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Tool', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Outcome', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Duration', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Error', domain: 'wppilot'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row) { ?>
                        <tr>
                            <td><?php echo esc_html($row['created_at']); ?></td>
                            <td><?php
                                echo esc_html($row['credential_label'] !== '' ? $row['credential_label'] : $row['auth_method']);
                                $user = wppilot_request_log_user_label((int) $row['user_id']);
                                if ($user !== '') {
                                    echo '<br><small>' . esc_html($user) . '</small>';
                                }
                                if ($row['credential'] !== '') {
                                    echo '<br><code title="' . esc_attr($row['credential']) . '">' . esc_html(substr($row['credential'], 0, 18)) . '</code>';
                                }
                            ?></td>
                            <td><?php echo esc_html($row['client']); ?></td>
                            <td><code><?php echo esc_html($row['rpc_method']); ?></code></td>
                            <td><code><?php echo esc_html($row['tool']); ?></code></td>
                            <td><?php echo esc_html(($outcomes[$row['outcome']] ?? $row['outcome']) . ($row['http_status'] !== '200' ? ' (' . $row['http_status'] . ')' : '')); ?></td>
                            <td><?php echo esc_html($row['duration_ms'] . ' ms'); ?></td>
                            <td><?php echo esc_html($row['error']); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
                <p>
                    <?php if ($paged > 1) { ?>
                        <a class="button" href="<?php echo esc_url(wppilot_request_log_page_url(array_merge(array_filter($filters), ['paged' => $paged - 1]))); ?>"><?php esc_html_e('Newer', domain: 'wppilot'); ?></a>
                    <?php } ?>
                    <?php if ($has_next) { ?>
                        <a class="button" href="<?php echo esc_url(wppilot_request_log_page_url(array_merge(array_filter($filters), ['paged' => $paged + 1]))); ?>"><?php esc_html_e('Older', domain: 'wppilot'); ?></a>
                    <?php } ?>
                </p>
            <?php } ?>
        </section>
    </div>
    <?php
}

add_action('admin_menu', 'wppilot_request_log_register_menu', priority: 38);
add_filter('wppilot_nav_map', 'wppilot_request_log_register_nav');
add_action('admin_post_wppilot_request_log_export', 'wppilot_request_log_handle_export');
