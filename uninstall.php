<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes this plugin's own custom tables and options; direct queries and the DROP are the entire job of this file, and it runs only when WordPress itself invokes an uninstall.

/**
 * Remove everything GoBuildWP stored.
 *
 * WordPress runs this file in a request where the plugin is NOT loaded, so it
 * cannot call any GoBuildWP function or reference any GoBuildWP constant. Every
 * key is repeated literally here on purpose; the lists are grouped to match the
 * files that own them so a new key is easy to add in the right place.
 *
 * Two things are deliberately left on disk:
 *
 * - wp-content/wppilot-sandbox/ holds PHP written through the sandbox. That is
 *   the site owner's own code, and deleting source is not recoverable, so it
 *   stays. The directory is inert once the plugin is gone.
 * - Designs and skills are documents somebody wrote, for the same reason. They
 *   are removed only when the site has explicitly opted in by setting
 *   wppilot_delete_content_on_uninstall; absent means keep.
 * - Nothing outside GoBuildWP's own prefixes is touched.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

// The two Free distributions own the same tables, options and authored data.
// Removing one installed copy must not erase the surviving copy's state,
// including when that copy is temporarily inactive during a channel switch.
$wppilot_removing_directory = basename(__DIR__);
$wppilot_other_directory = $wppilot_removing_directory === 'gobuildwp' ? 'wppilot' : 'gobuildwp';
$wppilot_other_main = $wppilot_other_directory === 'gobuildwp' ? 'gobuildwp.php' : 'wppilot.php';
if (is_file(WP_PLUGIN_DIR . '/' . $wppilot_other_directory . '/' . $wppilot_other_main)) {
    return;
}

function wppilot_uninstall_wpdb(): wpdb
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;

    /** @var wpdb $wpdb */
    return $wpdb;
}

/**
 * Tables created by GoBuildWP on each site.
 *
 * @return list<string>
 */
function wppilot_uninstall_tables(): array
{
    $wpdb = wppilot_uninstall_wpdb();

    return [
        $wpdb->prefix . 'wppilot_chat_sessions',
        $wpdb->prefix . 'wppilot_connections',
        $wpdb->prefix . 'wppilot_tokens',
        $wpdb->prefix . 'wppilot_changes',
        $wpdb->prefix . 'wppilot_oauth_clients',
        $wpdb->prefix . 'wppilot_oauth_auth_codes',
        $wpdb->prefix . 'wppilot_oauth_access_tokens',
        $wpdb->prefix . 'wppilot_oauth_refresh_tokens',
    ];
}

/**
 * Options written by GoBuildWP, by owning file.
 *
 * @return list<string>
 */
function wppilot_uninstall_options(): array
{
    return [
        // includes/chat/schema.php
        'wppilot_chat_schema_version',
        // includes/connections.php
        'wppilot_connections_schema_version',
        // includes/tokens.php
        'wppilot_tokens_schema_version',
        // Legacy pre-table chat storage.
        'wppilot_chat_sessions',
        // includes/oauth/
        'wppilot_oauth_schema_version',
        'wppilot_oauth_private_key',
        'wppilot_oauth_public_key',
        'wppilot_oauth_encryption_key',
        // includes/environment.php + includes/abilities/policy.php
        'wppilot_ai_abilities_enabled',
        // Read before this loop runs; see wppilot_uninstall_current_site().
        'wppilot_delete_content_on_uninstall',
        'wppilot_ai_abilities_domain',
        'wppilot_ability_rules',
        // includes/safety.php
        'wppilot_safety_profile',
        // includes/preview/gate.php + includes/preview/store.php
        'wppilot_require_preview_before_write',
        'wppilot_preview_index',
        'wppilot_safety_policy_version',
        // includes/change-log.php + includes/change-log-table.php
        'wppilot_change_log',
        'wppilot_changes_schema_version',
        'wppilot_changes_storage',
        'wppilot_changes_migration',
        'wppilot_changes_storage_error',
        // includes/admin/instructions.php
        'wppilot_instructions_enabled',
        'wppilot_instructions_content',
        // Pre-rename Pro instructions, still read as a fallback.
        'wppilot_pro_instructions_enabled',
        'wppilot_pro_instructions_content',
        // includes/confirmation.php: the mode, pending requests (raw ability inputs and logins)
        // and the one-time nonces that approve them.
        'wppilot_confirmation_mode',
        'wppilot_confirmation_requests',
        'wppilot_confirmation_nonces',
        // includes/foreign-gate.php
        'wppilot_foreign_mcp_gate',
        // includes/kits/pagespeed: the site owner's Google API key.
        'wppilot_kit_pagespeed_api_key',
        // includes/kits/content-audit: the job index.
        'wppilot_kit_content_audit_jobs',
        // includes/kits/search-replace: the plan index (plans and locks are prefix-swept below).
        'wppilot_kit_sr_plans',
        // includes/design/cpt.php
        'wppilot_active_design',
        // includes/design/gate.php
        'wppilot_design_gate_mode',
        // includes/design/visual-runtime.php
        'wppilot_visual_jobs',
        // includes/admin/pro-upsell.php
        'wppilot_pro_upsell_installed_at',
        // includes/troubleshoot/bootstrap.php
        'wppilot_mcp_last_request',
        // includes/abilities/gutenberg/bootstrap.php
        'wppilot_gb_finalizer_poll_token',
        // includes/telemetry/settings.php. Absent from the wordpress.org build,
        // which never writes them; deleting an option that does not exist is a
        // no-op, so the list does not need to know which build this is.
        'wppilot_telemetry_enabled',
        'wppilot_install_id',
        // Written only by the first-run notice that 1.6.0 shipped and 1.6.1
        // removed. Listed so an install that saw it does not keep the row.
        'wppilot_telemetry_notice_acknowledged',
        // includes/cloud/. The Cloud's access token goes with the tokens table
        // above, so a Cloud still holding it is refused from then on. Pairings
        // in flight are transients, removed by the prefix sweep below.
        'wppilot_cloud_link',
        'wppilot_cloud_keys',
        'wppilot_cloud_seen_version',
        'wppilot_cloud_manage',
        'wppilot_cloud_policy',
        'wppilot_cloud_event_push',
        'gobuildwp_cloud_inventory_hash',
        'gobuildwp_cloud_key_heartbeat_at',
        // includes/restore-points.php: the restore-point index and the plan's undo window. The
        // zips themselves are removed by wppilot_uninstall_restore_points().
        'gobuildwp_restore_points',
        'gobuildwp_restore_point_hours',
        // includes/kits/security-live/src/logins.php: recent failed logins.
        'gobuildwp_live_login_failures',
        // includes/kits/site-issues: the PHP fatal error log.
        'wppilot_kit_site_issues_php_errors',
        // includes/kits/not-found-log: the rolled-up 404 log.
        'wppilot_kit_not_found_log',
    ];
}

/**
 * Post types holding data GoBuildWP generated, which goes with the plugin.
 *
 * @return list<string>
 */
function wppilot_uninstall_machine_post_types(): array
{
    return [
        // The Block Editor queue: pending batches for a runtime that no longer
        // exists once the plugin is gone.
        'wppilot_gb_change',
    ];
}

/**
 * Post types holding what the site owner wrote, which does not.
 *
 * A design is a document somebody authored: a palette argued for, rules about
 * what the site must never do, reasoning that took a conversation to produce.
 * A skill is the same. Neither can be regenerated from anything left behind,
 * and both used to be deleted here without asking — the same decision as
 * deleting a site's posts, made silently on the way out.
 *
 * This file already draws that line for the sandbox directory, on the grounds
 * that it holds the owner's own code and deleting source is not recoverable.
 * Authored content is the same category and now gets the same treatment.
 *
 * @return list<string>
 */
function wppilot_uninstall_authored_post_types(): array
{
    return [
        'wppilot_skill',
        'wppilot_design',
    ];
}

/**
 * Whether the site asked for its authored content to be destroyed too.
 *
 * Opt-in, and read before any option is deleted. Absent means keep, so a site
 * that never made the choice keeps its designs and skills.
 */
function wppilot_uninstall_should_delete_authored(): bool
{
    $choice = get_option('wppilot_delete_content_on_uninstall', false);

    return $choice === '1' || $choice === 1 || $choice === true;
}

/**
 * User meta keys, including the dismissal prefix handled by LIKE below.
 *
 * @return list<string>
 */
function wppilot_uninstall_user_meta_keys(): array
{
    return [
        'wppilot_production_warning_dismissed',
        'wppilot_troubleshoot_dismissed',
        'wppilot_chat_consent',
        // includes/admin/notifications.php
        'wppilot_changes_seen_at',
    ];
}

/**
 * Cron hooks GoBuildWP schedules.
 *
 * @return list<string>
 */
function wppilot_uninstall_cron_hooks(): array
{
    return [
        'wppilot_oauth_gc',
        'wppilot_gutenberg_cleanup',
        // includes/change-log-table.php
        'wppilot_changes_prune',
        // includes/telemetry/settings.php
        'wppilot_telemetry_ping',
        // includes/kits/scheduled-audits: the hourly repair of the routines' schedule.
        'wppilot_kit_routines_reconcile',
        // includes/cloud/heartbeat.php
        'wppilot_cloud_heartbeat',
        // includes/cloud/events.php
        'wppilot_cloud_event_push',
        // includes/kits/security-live: the change watch, and the one-off run after an update.
        'gobuildwp_kit_sl_tick',
        'gobuildwp_kit_sl_tick_soon',
        // includes/restore-points.php
        'gobuildwp_restore_points_cleanup',
    ];
}

/**
 * Remove the restore-point zips under uploads. includes/ is not loaded during uninstall, so the
 * folder name is repeated here; links inside it are removed, never followed.
 */
function wppilot_uninstall_restore_points(): void
{
    $uploads = wp_upload_dir(null, false);
    $base = is_array($uploads) ? (string) ($uploads['basedir'] ?? '') : '';
    if ($base === '') {
        return;
    }
    $dir = rtrim(wp_normalize_path($base), '/') . '/gobuildwp-restore-points';
    if (is_dir($dir) && !is_link($dir)) {
        wppilot_uninstall_delete_tree($dir);
    }
}

/**
 * The security-protection kit's pre-WordPress firewall: its auto_prepend_file lines in the site's
 * .user.ini and .htaccess, and its folder under wp-content. The markers and folder name are
 * repeated from includes/kits/security-protection/src/prepend.php.
 */
function wppilot_uninstall_prepend_firewall(): void
{
    $root = rtrim(wp_normalize_path(ABSPATH), '/');
    $ini_name = (string) ini_get('user_ini.filename');
    $files = [
        [$root . '/.htaccess', '# BEGIN GoBuildWP protection', '# END GoBuildWP protection'],
    ];
    if ($ini_name !== '' && $ini_name !== '0') {
        $files[] = [$root . '/' . $ini_name, '; BEGIN GoBuildWP protection', '; END GoBuildWP protection'];
    }
    foreach ($files as [$file, $begin, $end]) {
        if (is_link($file) || !is_file($file)) {
            continue;
        }
        $content = (string) file_get_contents($file);
        $kept = (string) preg_replace('/[ \t]*' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '[ \t]*(?:\r?\n|$)/s', '', $content);
        if ($kept === $content) {
            continue;
        }
        if (trim($kept) === '') {
            @unlink($file);
        } else {
            @file_put_contents($file, $kept, LOCK_EX);
        }
    }
    $dir = rtrim(wp_normalize_path((string) WP_CONTENT_DIR), '/') . '/gobuildwp-protection';
    if (is_dir($dir) && !is_link($dir) && is_file($dir . '/firewall.php')) {
        wppilot_uninstall_delete_tree($dir);
    }
}

function wppilot_uninstall_delete_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach ((array) scandir($path) as $name) {
        $name = (string) $name;
        if ($name !== '.' && $name !== '..') {
            wppilot_uninstall_delete_tree($path . '/' . $name);
        }
    }
    @rmdir($path);
}

/**
 * Delete posts of a post type without relying on the type being registered.
 *
 * wp_delete_post() is used rather than a direct DELETE so that post meta,
 * revisions, and term relationships go with them.
 */
function wppilot_uninstall_delete_posts(string $post_type): void
{
    $wpdb = wppilot_uninstall_wpdb();

    /** @var list<int>|null $ids */
    // @mago-expect analysis:possibly-invalid-argument -- $wpdb->posts is a trusted table name.
    $ids = $wpdb->get_col(
        // @mago-expect analysis:possibly-invalid-argument
        $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $post_type),
    );
    if (!is_array($ids)) {
        return;
    }

    foreach ($ids as $id) {
        wp_delete_post((int) $id, force_delete: true);
    }
}

/**
 * Delete every option whose name starts with the given prefix.
 *
 * Covers the generated keys — pending OAuth authorizations and the transients
 * GoBuildWP stores under its own prefixes — that cannot be listed literally.
 */
function wppilot_uninstall_delete_options_like(string $prefix): void
{
    $wpdb = wppilot_uninstall_wpdb();

    /** @var list<string>|null $names */
    // @mago-expect analysis:possibly-invalid-argument -- $wpdb->options is a trusted table name.
    $names = $wpdb->get_col(
        // @mago-expect analysis:possibly-invalid-argument
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like($prefix) . '%',
        ),
    );
    if (!is_array($names)) {
        return;
    }

    foreach ($names as $name) {
        delete_option((string) $name);
    }
}

/**
 * Remove every GoBuildWP trace from the current site.
 */
function wppilot_uninstall_current_site(): void
{
    $wpdb = wppilot_uninstall_wpdb();

    foreach (wppilot_uninstall_cron_hooks() as $hook) {
        wp_clear_scheduled_hook($hook);
    }
    // includes/kits/scheduled-audits: one tick event per routine, each with the routine's id as
    // its argument, which wp_clear_scheduled_hook() without that argument would leave behind.
    wp_unschedule_hook('wppilot_kit_routines_tick');

    // Read before the options are deleted below, or the answer is gone by the
    // time it is asked for.
    $delete_authored = wppilot_uninstall_should_delete_authored();

    foreach (wppilot_uninstall_machine_post_types() as $post_type) {
        wppilot_uninstall_delete_posts($post_type);
    }
    if ($delete_authored) {
        foreach (wppilot_uninstall_authored_post_types() as $post_type) {
            wppilot_uninstall_delete_posts($post_type);
        }
    }

    wppilot_uninstall_restore_points();
    wppilot_uninstall_prepend_firewall();

    foreach (wppilot_uninstall_options() as $option) {
        delete_option($option);
    }

    // Generated option names: pending OAuth authorizations, plus GoBuildWP's own
    // transients and their timeout twins.
    foreach ([
        'wppilot_oauth_pending_',
        // includes/kits/scheduled-audits: the routines, and each routine's state, reports and lock
        // (wppilot_kit_routines_state_<id>, _report_<id>, _lock_<id>).
        'wppilot_kit_routines',
        // includes/kits/_runtime/jobs: one option per background job.
        'wppilot_kit_job_',
        // includes/kits/search-replace: each plan and its apply lock.
        'wppilot_kit_sr_plan_',
        'wppilot_kit_sr_lock_',
        // includes/kits/security-integrity: the scan index, stored result pages and start mutexes.
        'gobuildwp_kit_si_',
        // includes/kits/security-protection: settings, blocks, events, counters and hit transients.
        'gobuildwp_kit_sp_',
        '_transient_gobuildwp_kit_sp_',
        '_transient_timeout_gobuildwp_kit_sp_',
        // includes/kits/security-live: settings, events, the change-watch index, cursor, queue and lock.
        'gobuildwp_kit_sl_',
        '_transient_gobuildwp_kit_sl_',
        '_transient_timeout_gobuildwp_kit_sl_',
        // includes/kits/security-2fa: the role policy, interim logins, failure counts and codes
        // waiting to be shown once.
        'gobuildwp_kit_2fa_',
        '_transient_gobuildwp_kit_2fa_',
        '_transient_timeout_gobuildwp_kit_2fa_',
        // includes/cloud/request-signing.php: Cloud request nonces seen in the last fifteen minutes.
        'gobuildwp_cloud_nonce_',
        '_transient_gobuildwp_cloud_nonce_',
        '_transient_timeout_gobuildwp_cloud_nonce_',
        // includes/preview/store.php: one option per pending preview, plus the
        // compare-and-set lock each apply claims.
        'wppilot_preview_',
        '_transient_wppilot_',
        '_transient_timeout_wppilot_',
        '_site_transient_wppilot_',
        '_site_transient_timeout_wppilot_',
    ] as $prefix) {
        wppilot_uninstall_delete_options_like($prefix);
    }

    foreach (wppilot_uninstall_user_meta_keys() as $meta_key) {
        // @mago-expect analysis:possibly-invalid-argument -- $wpdb->usermeta is a trusted table name.
        $wpdb->query(
            // @mago-expect analysis:possibly-invalid-argument
            $wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", $meta_key),
        );
    }

    // Per-notice dismissal flags: includes/admin/pro-upsell.php writes one key
    // per notice under this prefix.
    // @mago-expect analysis:possibly-invalid-argument -- $wpdb->usermeta is a trusted table name.
    $wpdb->query(
        // @mago-expect analysis:possibly-invalid-argument
        $wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like('wppilot_pro_dismissed_') . '%',
        ),
    );

    // includes/kits/security-2fa: each user's encrypted secret, backup-code hashes and state.
    // Removing them turns two-factor login off for everyone, which is what uninstalling means.
    // @mago-expect analysis:possibly-invalid-argument -- $wpdb->usermeta is a trusted table name.
    $wpdb->query(
        // @mago-expect analysis:possibly-invalid-argument
        $wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like('gobuildwp_kit_2fa_') . '%',
        ),
    );

    // Tables last: the post deletions above read from them. Each name comes from
    // wppilot_uninstall_tables() ($wpdb->prefix plus a literal) and is bound as a
    // %i identifier.
    foreach (wppilot_uninstall_tables() as $table) {
        // @mago-expect analysis:possibly-invalid-argument
        $wpdb->query(
            // @mago-expect analysis:possibly-invalid-argument
            $wpdb->prepare('DROP TABLE IF EXISTS %i', $table),
        );
    }
}

if (is_multisite()) {
    // @mago-expect analysis:mixed-assignment -- WordPress returns site ids when fields=ids.
    $wppilot_site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    if (is_array($wppilot_site_ids)) {
        // @mago-expect analysis:mixed-assignment
        foreach ($wppilot_site_ids as $wppilot_site_id) {
            switch_to_blog((int) $wppilot_site_id);
            try {
                wppilot_uninstall_current_site();
            } finally {
                restore_current_blog();
            }
        }
    }
    // includes/cloud/inventory.php keeps its hash cache network-wide on multisite.
    delete_site_option('gobuildwp_cloud_inventory_hash');

    return;
}

wppilot_uninstall_current_site();
