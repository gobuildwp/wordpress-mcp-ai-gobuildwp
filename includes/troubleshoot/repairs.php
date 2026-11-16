<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Troubleshoot\Repairs;

use WPPilot\Troubleshoot\Admin;

/**
 * One-click repairs on the Diagnostics screen.
 *
 * Plain admin-post forms, each behind the manage capability and its own nonce, so they work where
 * admin JavaScript or REST cookie authentication is what is broken. The outcome is kept for the
 * person who ran it (a short-lived user transient) and shown when the screen reloads.
 */

if (!defined('ABSPATH')) {
    exit();
}

const ANCHOR = 'wppilot-repairs';

const PURGE_ACTION = 'wppilot_purge_discovery_cache';

const REPAIR_TABLES_ACTION = 'wppilot_repair_oauth_tables';

const RESULT_TRANSIENT = 'wppilot_repair_result_';

/**
 * @param array{status: string, message: string, details: list<string>} $result
 */
function store_result(array $result): void
{
    set_transient(RESULT_TRANSIENT . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS);
}

/**
 * @return array{status: string, message: string, details: list<string>}|null
 */
function take_result(): ?array
{
    $key = RESULT_TRANSIENT . get_current_user_id();
    /** @var mixed $stored */
    $stored = get_transient($key);
    if (!is_array($stored) || !isset($stored['status'], $stored['message'])) {
        return null;
    }
    delete_transient($key);

    /** @var array{status: string, message: string, details: list<string>} $stored */
    return $stored;
}

/**
 * Refuse anyone who may not manage GoBuildWP, or a request without this form's nonce.
 */
function authorize(string $action): void
{
    if (!\wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to run GoBuildWP repairs.', domain: 'wppilot'), status_code: 403);
    }
    check_admin_referer($action);
}

function back(): void
{
    wp_safe_redirect(admin_url('admin.php?page=' . Admin\PAGE_SLUG) . '#' . ANCHOR);
    exit();
}

/**
 * Describe a discovery purge for the person who asked for it.
 *
 * @param array{urls: list<string>, purged: list<string>, unsupported: list<array{label: string, manual: string}>, errors: list<array{label: string, error: string}>, detected: int} $purge
 * @return array{status: string, message: string, details: list<string>}
 */
function describe_purge(array $purge): array
{
    $details = [];
    foreach ($purge['unsupported'] as $cache) {
        $details[] = $cache['label'] . ': ' . $cache['manual'];
    }
    foreach ($purge['errors'] as $error) {
        $details[] = sprintf(
            /* translators: 1: cache plugin name, 2: error message */
            __('%1$s refused the purge: %2$s', domain: 'wppilot'),
            $error['label'],
            $error['error'],
        );
    }
    $details[] = __('URLs:', domain: 'wppilot') . ' ' . implode(' ', $purge['urls']);

    if ($purge['detected'] === 0) {
        return [
            'status' => 'info',
            'message' => __('No supported page-cache plugin is active, so there was nothing to purge in WordPress. A CDN or host cache can still hold these URLs.', domain: 'wppilot'),
            'details' => $details,
        ];
    }
    $status = $purge['unsupported'] === [] && $purge['errors'] === [] ? 'success' : 'warning';
    $message = $purge['purged'] === []
        ? __('No detected cache could be purged by URL. Purge the URLs below by hand.', domain: 'wppilot')
        : sprintf(
            /* translators: %s: comma-separated cache plugin names */
            __('Purged the OAuth discovery URLs from: %s.', domain: 'wppilot'),
            implode(', ', $purge['purged']),
        );

    return ['status' => $status, 'message' => $message, 'details' => $details];
}

function handle_purge(): void
{
    authorize(PURGE_ACTION);
    store_result(describe_purge(\WPPilot\OAuth\DiscoveryCache\purge('manual')));
    back();
}

/**
 * Re-run the OAuth table installer and check the tables again.
 *
 * Safe to run on a healthy site: dbDelta() only creates what is missing and adds missing columns
 * or keys; it never drops a table, a column or a row. The stored schema version is cleared first
 * because the installer skips itself when the version matches, which is exactly the state a site
 * whose tables were dropped by hand (or never created) is stuck in.
 *
 * @return array{status: string, message: string, details: list<string>}
 */
function repair_oauth_tables(): array
{
    require_once dirname(__DIR__) . '/oauth/schema.php';

    $before = \WPPilot\Troubleshoot\Checks\missing_oauth_tables();
    delete_option(\WPPilot\OAuth\Schema\SCHEMA_VERSION_OPTION);
    \WPPilot\OAuth\Schema\maybe_install();
    $after = \WPPilot\Troubleshoot\Checks\missing_oauth_tables();

    if ($after === []) {
        return [
            'status' => 'success',
            'message' => $before === []
                ? __('The OAuth tables were already installed; the installer ran again and found nothing to fix.', domain: 'wppilot')
                : __('The OAuth tables are installed again. Run the checks above to confirm OAuth works.', domain: 'wppilot'),
            'details' => $before === [] ? [] : [__('Created:', domain: 'wppilot') . ' ' . implode(', ', $before)],
        ];
    }

    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $wpdb */
    $details = [__('Still missing:', domain: 'wppilot') . ' ' . implode(', ', $after)];
    if (is_string($wpdb->last_error) && $wpdb->last_error !== '') {
        $details[] = __('Database error:', domain: 'wppilot') . ' ' . $wpdb->last_error;
    }

    return [
        'status' => 'error',
        'message' => __('The installer ran but the OAuth tables are still missing. The database user most likely lacks the CREATE privilege: ask your host to grant it, then repair again.', domain: 'wppilot'),
        'details' => $details,
    ];
}

function handle_repair_tables(): void
{
    authorize(REPAIR_TABLES_ACTION);
    store_result(repair_oauth_tables());
    back();
}

/**
 * @param array<string, string> $fields Hidden fields besides the action and nonce.
 */
function render_form(string $action, string $label, string $help, array $fields = []): void
{
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0;">
        <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
        <?php wp_nonce_field($action); ?>
        <?php foreach ($fields as $name => $value) { ?>
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
        <?php } ?>
        <button type="submit" class="button"><?php echo esc_html($label); ?></button>
        <span class="description" style="margin-left:8px;"><?php echo esc_html($help); ?></span>
    </form>
    <?php
}

function render_section(): void
{
    if (!\wppilot_current_user_can_manage()) {
        return;
    }
    $result = take_result();
    ?>
    <section class="wppilot-panel" id="<?php echo esc_attr(ANCHOR); ?>" style="margin-top:24px;">
        <h2><?php esc_html_e('Repairs', domain: 'wppilot'); ?></h2>
        <?php if ($result !== null) {
            $class = match ($result['status']) {
                'success' => 'notice-success',
                'warning' => 'notice-warning',
                'error' => 'notice-error',
                default => 'notice-info',
            };
            ?>
            <div class="notice inline <?php echo esc_attr($class); ?>">
                <p><?php echo esc_html($result['message']); ?></p>
                <?php if (($result['details'] ?? []) !== []) { ?>
                    <ul style="list-style:disc;margin-left:20px;">
                        <?php foreach ($result['details'] as $line) { ?>
                            <li><?php echo esc_html($line); ?></li>
                        <?php } ?>
                    </ul>
                <?php } ?>
            </div>
        <?php } ?>
        <?php
        render_form(
            REPAIR_TABLES_ACTION,
            __('Repair OAuth tables', domain: 'wppilot'),
            __('Re-runs the OAuth table installer and checks again. Creates only what is missing; nothing is deleted.', domain: 'wppilot'),
        );
        render_form(
            PURGE_ACTION,
            __('Purge discovery cache', domain: 'wppilot'),
            __('Asks each active page-cache plugin to drop its copy of the /.well-known/ OAuth documents. By URL only; the rest of the cache is untouched.', domain: 'wppilot'),
        );
        /**
         * Fires inside the Repairs section, after the built-in repair forms.
         */
        do_action('wppilot_troubleshoot_repairs');
        ?>
    </section>
    <?php
}

function register(): void
{
    add_action('admin_post_' . PURGE_ACTION, callback: __NAMESPACE__ . '\\handle_purge');
    add_action('admin_post_' . REPAIR_TABLES_ACTION, callback: __NAMESPACE__ . '\\handle_repair_tables');
}
