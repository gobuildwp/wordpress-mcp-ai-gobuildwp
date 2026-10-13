<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Plugin lifecycle: activation, deactivation, and new-site provisioning.
 *
 * One owner for everything that must happen when GoBuildWP is switched on or off.
 * Registering several activation hooks from several files made it easy for one
 * of them to miss multisite: only the first looped the network, so a
 * network-activated install provisioned exactly one site.
 *
 * Uninstall is deliberately not here — WordPress loads uninstall.php in a
 * request where the plugin itself is not loaded, so it has to stand alone.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Run a per-site routine on every site the current operation covers.
 *
 * On a network-wide operation this is every site in the network; otherwise it
 * is the current site only. Single-site installs always take the second path.
 *
 * @param bool     $network_wide Whether WordPress reported a network-wide operation.
 * @param callable $callback     Per-site routine, invoked with no arguments.
 */
function wppilot_for_each_site(bool $network_wide, callable $callback): void
{
    if (!$network_wide || !is_multisite()) {
        $callback();
        return;
    }

    // @mago-expect analysis:mixed-assignment -- WordPress returns site ids when fields=ids.
    $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    if (!is_array($site_ids)) {
        $callback();
        return;
    }

    // @mago-expect analysis:mixed-assignment
    foreach ($site_ids as $site_id) {
        switch_to_blog((int) $site_id);
        try {
            $callback();
        } finally {
            // restore_current_blog() must run even if a site throws, or every
            // later site — and the rest of the request — runs against the
            // wrong database tables.
            restore_current_blog();
        }
    }
}

/**
 * Activation entry point.
 *
 * @param bool $network_wide Passed by WordPress; true when network-activated.
 */
function wppilot_activate(bool $network_wide = false): void
{
    // The sandbox lives in wp-content and is shared by the whole network, so it
    // is provisioned once rather than per site.
    if (!wppilot_is_wordpress_org_edition()) {
        wppilot_get_sandbox_dir(ensure_exists: true);
    }

    wppilot_for_each_site($network_wide, callback: 'wppilot_activate_current_site');
}

/**
 * Everything activation installs into a single site's tables and options.
 *
 * Must stay idempotent: it also runs when a new site is added to a network that
 * already has GoBuildWP network-activated.
 */
function wppilot_activate_current_site(): void
{
    wppilot_chat_schema_install_current_site();
    wppilot_connections_schema_install();
    wppilot_tokens_schema_install();
    wppilot_changes_install_and_migrate();
    wppilot_safety_maybe_install();
    wppilot_enable_ai_abilities_on_activate();
    wppilot_pro_upsell_on_activate();
    wppilot_telemetry_on_activate();
}

/**
 * Put the daily usage report on the schedule.
 *
 * Guarded on the function existing: includes/telemetry/ is deleted from the
 * wordpress.org build, and activation still has to work there.
 *
 * Reporting is opt-in, so on a fresh install this does nothing at all:
 * enabled() is false until somebody stores a decision. It exists for the site
 * that already switched reporting on and is reactivating or joining a network,
 * where the stored '1' has to survive — and, in the other direction, a stored
 * '0' is never overridden, exactly like the AI abilities toggle above.
 */
function wppilot_telemetry_on_activate(): void
{
    if (!function_exists('WPPilot\Telemetry\enabled')) {
        return;
    }

    if (\WPPilot\Telemetry\enabled()) {
        \WPPilot\Telemetry\schedule();
    }
}

/**
 * Turn AI Abilities on when the plugin is first activated.
 *
 * A site that installs an MCP server almost always means to run one, and the
 * off-by-default state produced a dead endpoint until someone found the toggle.
 *
 * Two things this deliberately does not do:
 *
 * - It never re-enables a site that turned abilities off. Activation runs again
 *   on every reactivation and on every new site added to a network, so the
 *   stored '0' is treated as a decision and left alone. Only a site that has
 *   never made a choice is switched on.
 * - It does not bypass the domain lock. Enabling records the host it was
 *   enabled on, which is what stops a database copied to a staging or clone
 *   domain from exposing an endpoint there. Cloning still requires a
 *   deliberate re-enable on the new domain.
 *
 * The safety profile is untouched, so a fresh install still comes up on
 * Production Safe with code execution, filesystem and database access blocked.
 */
function wppilot_enable_ai_abilities_on_activate(): void
{
    if (!function_exists('wppilot_enable_ai_abilities')) {
        return;
    }

    // A stored value of any kind means the operator already chose.
    if (get_option('wppilot_ai_abilities_enabled', default_value: null) !== null) {
        return;
    }

    wppilot_enable_ai_abilities();
}

/**
 * Deactivation entry point.
 *
 * @param bool $network_wide Passed by WordPress; true when network-deactivated.
 */
function wppilot_deactivate(bool $network_wide = false): void
{
    wppilot_for_each_site($network_wide, callback: 'wppilot_deactivate_current_site');
}

/**
 * Tear down the scheduled work GoBuildWP owns on a single site.
 *
 * Options and tables are intentionally left in place: deactivation is
 * reversible and must not destroy configuration. Cron events are not
 * configuration — an event whose callback is no longer registered just wakes
 * WordPress up on a schedule to do nothing, every day, forever.
 */
function wppilot_deactivate_current_site(): void
{
    require_once __DIR__ . '/abilities/gutenberg/bootstrap.php';
    \WPPilot\Abilities\Gutenberg\unschedule_cleanup();

    wp_clear_scheduled_hook('wppilot_oauth_gc');
    // Re-created on the next admin request after reactivation while the site is still paired.
    wp_clear_scheduled_hook(WPPILOT_CLOUD_HEARTBEAT_HOOK);
    wp_clear_scheduled_hook(WPPILOT_CHANGES_PRUNE_HOOK);

    // Report the deactivation before dropping the schedule, then drop it.
    // Without the report an abandoned install reads as active until it falls
    // out of the 45-day window, turning a real churn signal into a slow fade.
    if (function_exists('WPPilot\Telemetry\send_deactivation')) {
        \WPPilot\Telemetry\send_deactivation();
        \WPPilot\Telemetry\unschedule();
    }
}

/**
 * Provision a site created after GoBuildWP was already network-activated.
 *
 * Without this, such a site has no chat sessions table and no safety profile
 * until something else happens to trigger the lazy installers.
 */
function wppilot_initialize_new_site(mixed $new_site): void
{
    if (!$new_site instanceof WP_Site) {
        return;
    }

    if (!is_plugin_active_for_network(plugin_basename(WPPILOT_PLUGIN_FILE))) {
        return;
    }

    switch_to_blog((int) $new_site->blog_id);
    try {
        wppilot_activate_current_site();
    } finally {
        restore_current_blog();
    }
}

/**
 * Register the lifecycle hooks.
 *
 * `is_plugin_active_for_network()` lives in wp-admin, and wp_initialize_site
 * can fire from the front end or WP-CLI, so the file is required on demand
 * inside the callback rather than at load time.
 */
function wppilot_register_lifecycle_hooks(): void
{
    register_activation_hook(WPPILOT_PLUGIN_FILE, callback: 'wppilot_activate');
    register_deactivation_hook(WPPILOT_PLUGIN_FILE, callback: 'wppilot_deactivate');

    if (is_multisite()) {
        add_action(
            'wp_initialize_site',
            callback: static function (mixed $new_site): void {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                wppilot_initialize_new_site($new_site);
            },
            priority: 20,
        );
    }
}
