<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * Plugin Name: GoBuildWP
 * Plugin URI: https://gobuildwp.com
 * Description: WordPress MCP server with free Elementor MCP editing. Connects Claude, Codex, Cursor and other AI clients to typed WordPress abilities over MCP, with OAuth 2.1, safety profiles, change evidence and rollback.
 * Version: 1.18.6
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Update URI: https://gobuildwp.com/wppilot/
 * Author: GoBuildWP
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wppilot
 * Copyright: GoBuildWP
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

if (!defined('ABSPATH')) {
    exit();
}

// Both distributions share namespaces and stored data. Keep the first active
// base authoritative; never auto-deactivate, uninstall or delete either copy.
if (defined('WPPILOT_PLUGIN_FILE') && constant('WPPILOT_PLUGIN_FILE') !== __FILE__) {
    $conflict_notice = static function (): void {
        echo '<div class="notice notice-error"><p>' . esc_html__(
            'GoBuildWP cannot load two Free editions together. Keep one Free edition active before enabling the other. Your stored data has not been deleted.',
            'wppilot',
        ) . '</p></div>';
    };
    add_action('admin_notices', $conflict_notice);
    register_activation_hook(__FILE__, static function (): void {
        wp_die(esc_html__('Another GoBuildWP Free edition is already active. Deactivate it before activating this edition; do not uninstall it to switch editions.', 'wppilot'));
    });
    return;
}

require_once __DIR__ . '/includes/compatibility.php';
require_once __DIR__ . '/includes/distribution.php';

define(constant_name: 'WPPILOT_MAX_EXECUTION_TIME', value: 30);
define(constant_name: 'WPPILOT_PLUGIN_FILE', value: __FILE__);
define('WPPILOT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPPILOT_SANDBOX_DIR', WP_CONTENT_DIR . '/wppilot-sandbox/');
define(constant_name: 'WPPILOT_VENDOR_AUTOLOAD', value: __DIR__ . '/vendor/autoload_packages.php');
define(constant_name: 'WPPILOT_MCP_ADAPTER_CLASS', value: 'WP\\MCP\\Core\\McpAdapter');

// Load named functions only after the duplicate-edition guard has returned.
// PHP registers unconditional declarations before executing an early return.
require_once __DIR__ . '/includes/bootstrap-functions.php';

















// Which copy of the MCP Adapter won. Loaded before the dependency check so the
// answer is available to every consumer below, including the server registration
// that must not rename a default server another plugin owns.
require_once __DIR__ . '/includes/mcp/adapter-origin.php';
require_once __DIR__ . '/includes/mcp/adapter-abilities.php';

$wppilot_dependency_error = wppilot_load_bundled_dependencies();
if ($wppilot_dependency_error !== null) {
    wppilot_set_mcp_dependency_error($wppilot_dependency_error);
}

require_once __DIR__ . '/includes/chat/schema.php';

add_action('admin_notices', callback: 'wppilot_render_mcp_dependency_notice');
add_action('network_admin_notices', callback: 'wppilot_render_mcp_dependency_notice');
add_action('plugins_loaded', callback: 'wppilot_chat_schema_maybe_install');
add_action('rest_api_init', callback: 'wppilot_register_missing_mcp_endpoint', priority: 999);

// Foundations, in dependency order: paths before the sandbox boundary that
// uses them, capabilities and environment before anything that gates on them.
require_once __DIR__ . '/includes/filesystem.php';
require_once __DIR__ . '/includes/sandbox/guards.php';
require_once __DIR__ . '/includes/capabilities.php';
require_once __DIR__ . '/includes/environment.php';
require_once __DIR__ . '/includes/app-password-blockers.php';
require_once __DIR__ . '/includes/agent-context.php';
require_once __DIR__ . '/includes/admin/nav.php';
require_once __DIR__ . '/includes/admin/ui.php';
require_once __DIR__ . '/includes/abilities/registry.php';
require_once __DIR__ . '/includes/abilities/policy.php';
require_once __DIR__ . '/includes/safety.php';
require_once __DIR__ . '/includes/rate-limit.php';
require_once __DIR__ . '/includes/gate-pipeline.php';
require_once __DIR__ . '/includes/confirmation.php';
require_once __DIR__ . '/includes/foreign-gate.php';
require_once __DIR__ . '/includes/change-log.php';
require_once __DIR__ . '/includes/change-log-table.php';
require_once __DIR__ . '/includes/change-sessions.php';
require_once __DIR__ . '/includes/clients.php';
require_once __DIR__ . '/includes/connections.php';
require_once __DIR__ . '/includes/tokens.php';
require_once __DIR__ . '/includes/agent-identities.php';
require_once __DIR__ . '/includes/agent-sessions.php';
require_once __DIR__ . '/includes/ledger-map.php';
require_once __DIR__ . '/includes/privacy.php';
require_once __DIR__ . '/includes/rest/transport-hardening.php';

add_filter('rest_pre_dispatch', callback: 'wppilot_guard_rest_host', priority: 5, accepted_args: 3);
add_filter('rest_post_dispatch', callback: 'wppilot_harden_rest_response', priority: 20, accepted_args: 3);

// Dual-era MCP. The bundled adapter serves the legacy, session-based revisions;
// these modules answer MCP 2026-07-28, which removed the handshake and sessions
// entirely. The dispatcher only claims a request that carries modern per-request
// _meta, so legacy traffic reaches the adapter untouched.
foreach (['protocol', 'errors', 'headers', 'results', 'discover', 'transport', 'confirmation'] as $wppilot_mcp_module) {
    require_once __DIR__ . '/includes/mcp/' . $wppilot_mcp_module . '.php';
}
unset($wppilot_mcp_module);
\WPPilot\Mcp\register_modern_transport();
// The legacy half: what GoBuildWP's servers on the bundled adapter admit and publish, scoped to
// those servers because other plugins' servers can share the adapter.
require_once __DIR__ . '/includes/mcp/legacy-access.php';
// Public server card, and skills/briefs as skill:// resources (SEP-2640) on both eras.
require_once __DIR__ . '/includes/mcp/server-card.php';
require_once __DIR__ . '/includes/mcp/skill-resources.php';
// Tasks (task-augmented tools/call backed by the kit jobs Runner) and the MCP Apps preview card, on
// the modern transport. extensions.php is the transport's single hook-in point for both.
require_once __DIR__ . '/includes/mcp/tasks.php';
require_once __DIR__ . '/includes/mcp/apps.php';
require_once __DIR__ . '/includes/mcp/extensions.php';
\WPPilot\Mcp\ServerCard\register();
\WPPilot\Mcp\SkillResources\register();

require_once __DIR__ . '/includes/abilities/bootstrap.php';
// Portable feature kits: the runtime, GoBuildWP's host for it, and the kits in includes/kits/.
require_once __DIR__ . '/includes/kits/loader.php';
require_once __DIR__ . '/includes/kits/skills.php';
// Self-hosted update checks, for builds distributed from gobuildwp.com.
//
// Conditional because the WordPress.org build must not contain this file at all:
// the directory forbids a plugin serving its own updates from anywhere else, and
// this checker deliberately reports "no update" to stop .org overriding it -
// exactly the behaviour that gets a plugin pulled. The packaging script drops the
// file for the .org build, and this guard is what makes that safe.
if (file_exists(__DIR__ . '/includes/updater.php')) {
    require_once __DIR__ . '/includes/updater.php';
}
// Anonymous usage reporting. Guarded for the same reason as the updater above:
// scripts/package.sh deletes includes/telemetry/ from the .org build, so the
// directory's opt-in rule is satisfied by that build being incapable of
// reporting rather than by a flag someone could get wrong at runtime.
if (file_exists(__DIR__ . '/includes/telemetry/bootstrap.php')) {
    require_once __DIR__ . '/includes/telemetry/bootstrap.php';
}
require_once __DIR__ . '/includes/admin/abilities-hub.php';
require_once __DIR__ . '/includes/admin/connect/connect.php';
require_once __DIR__ . '/includes/admin/pro-upsell.php';
// A minute-long session for the site's own loopback fetches, so a check can read
// a draft the way its author sees it. Not edition-gated: it issues no link and
// grants nobody anything, and the rendered check ships everywhere.
require_once __DIR__ . '/includes/links/loopback.php';
if (!wppilot_is_wordpress_org_edition()) {
    require_once __DIR__ . '/includes/links/upload.php';
    require_once __DIR__ . '/includes/links/admin-access.php';
}
require_once __DIR__ . '/includes/skills/bootstrap.php';
require_once __DIR__ . '/includes/design/bootstrap.php';
require_once __DIR__ . '/includes/admin/sidebar.php';
require_once __DIR__ . '/includes/prompt-library/packs.php';
require_once __DIR__ . '/includes/prompt-library/admin.php';
require_once __DIR__ . '/includes/oauth/bootstrap.php';
// GoBuildWP Cloud pairing. After the token module and the OAuth middleware it
// relies on: the Cloud reaches this site with an ordinary access token.
require_once __DIR__ . '/includes/cloud/bootstrap.php';
// `wp wppilot mcp serve`. Registers nothing outside WP-CLI.
require_once __DIR__ . '/includes/cli/mcp-serve.php';
\WPPilot\Cli\McpServe\register();
require_once __DIR__ . '/includes/troubleshoot/bootstrap.php';
require_once __DIR__ . '/includes/admin/instructions.php';
require_once __DIR__ . '/includes/admin/settings.php';
require_once __DIR__ . '/includes/admin/confirm.php';
require_once __DIR__ . '/includes/admin/dashboard.php';
require_once __DIR__ . '/includes/admin/home.php';
require_once __DIR__ . '/includes/preview/bootstrap.php';
if (is_admin()) {
    // admin-post.php counts as admin, so the screen's undo and download handlers load too.
    require_once __DIR__ . '/includes/admin/changes/changes.php';
}
// Basic Elementor support. Self-gating: the module checks for Elementor and its
// version floor before loading anything, so this require is inert on a site
// without it.
require_once __DIR__ . '/includes/elementor/bootstrap.php';

// Loaded last: the lifecycle routines call into chat-schema, safety, helpers,
// and the upsell, so every one of those must already be defined.
require_once __DIR__ . '/includes/lifecycle.php';
wppilot_register_lifecycle_hooks();

add_action('plugins_loaded', callback: 'wppilot_safety_maybe_install', priority: 4);
add_filter('mcp_adapter_pre_tool_call', callback: 'wppilot_safety_pre_mcp_tool_call', priority: 5, accepted_args: 2);
// WordPress 7.1 and newer. Inert on 6.9 and 7.0, where the filter never fires
// and the ability policy's unregistration remains the only enforcement.
add_filter(
    'wp_ability_permission_result',
    callback: 'wppilot_safety_filter_ability_permission',
    priority: 10,
    accepted_args: 4,
);
// WordPress 7.1 appends the WP_Ability instance to both hooks; on 6.9 and 7.0
// the extra argument simply never arrives and both callbacks default it.
add_action('wp_before_execute_ability', callback: 'wppilot_change_before', priority: 10, accepted_args: 3);
add_action('wp_after_execute_ability', callback: 'wppilot_change_after', priority: 10, accepted_args: 4);
wppilot_changes_register_hooks();

\WPPilot\Context\boot_context_admin();
wppilot_register_wordpress_compatibility_notice();
wppilot_boot_ability_rest_surface();
wppilot_register_ability_policy_hook();
require_once __DIR__ . '/includes/chat/chat.php';

add_action('admin_post_wppilot_toggle_ai_abilities', callback: 'wppilot_handle_admin_bar_toggle');
add_action('admin_post_wppilot_download_mcpb', callback: 'wppilot_handle_download_mcpb');







add_filter('wppilot_discover_abilities_instructions', callback: 'wppilot_inject_custom_instructions', priority: 6);





add_action('admin_bar_menu', callback: 'wppilot_register_admin_bar_toggle', priority: 999);
add_action('admin_head', callback: 'wppilot_render_admin_bar_toggle_assets');
add_action('wp_head', callback: 'wppilot_render_admin_bar_toggle_assets');

// Optional dev mock for the external-skills source. Gitignored. Loaded
// only when the constant is set (e.g. in wp-config.php) so it never ships
// to production builds.
if (
    defined('WPPILOT_DEV_MOCK_PRO')
    && constant('WPPILOT_DEV_MOCK_PRO') === true
    && file_exists(__DIR__ . '/includes/skills/dev-mock.php')
) {
    require_once __DIR__ . '/includes/skills/dev-mock.php';
}

// Add an "X" link to the plugin row meta on the Plugins page.
add_filter(
    'plugin_row_meta',
    /** @param string[] $plugin_meta */
    static function (array $plugin_meta, string $plugin_file): array {
        if ($plugin_file === plugin_basename(__FILE__)) {
            $plugin_meta[] =
                '<a href="https://x.com/gobuildwp" target="_blank" rel="noopener noreferrer">'
                . esc_html__('X', domain: 'wppilot')
                . '</a>';
        }
        return $plugin_meta;
    },
    priority: 10,
    accepted_args: 2,
);

// Suppress noisy admin notices on the Configuration page via CSS: hide notices that are not
// emitted by GoBuildWP or GoBuildWP Pro. Cheap and side-effect free, unlike iterating $wp_filter
// with Reflection (which causes memory blow-ups when Query Monitor captures every remove_action).
add_action('admin_head', static function () {
    if (!in_array($_GET['page'] ?? null, ['wppilot-connect', WPPILOT_SETUP_PAGE], strict: true)) {
        return;
    }
    ?>
    <style id="wppilot-suppress-foreign-notices">
        .wrap > .notice:not(.wppilot-pro-notice):not(.wppilot-keep),
        #wpbody-content > .notice:not(.wppilot-pro-notice):not(.wppilot-keep),
        #wpbody-content > .updated:not(.wppilot-keep),
        #wpbody-content > .error:not(.wppilot-keep) {
            display: none !important;
        }
    </style>
    <?php
});

// Handle form actions early (before headers are sent) for PRG redirect.
add_action('admin_init', static function () {
    $page = $_GET['page'] ?? null;
    if ($page === 'wppilot-sandbox') {
        wppilot_handle_sandbox_actions();
    }
    if ($page === WPPILOT_SETUP_PAGE || $page === 'wppilot-connect') {
        wppilot_handle_revoke_password();
        wppilot_handle_revoke_token();
        wppilot_handle_update_token();
        wppilot_handle_dismiss_production_warning();
    }
    if ($page === 'wppilot-abilities') {
        wppilot_handle_ability_hub_actions();
    }
});

// Single-row toggle over AJAX so the page state (open sections) is preserved.
add_action('wp_ajax_wppilot_toggle_ability', callback: 'wppilot_handle_ability_toggle_ajax');

// Admin page stylesheets.
add_action('admin_enqueue_scripts', static function (string $hook): void {
    // The shared design system loads on every GoBuildWP screen. Screen-specific
    // sheets below depend on it, so it is registered first and named as their
    // dependency rather than relying on enqueue order.
    if (str_contains($hook, 'wppilot')) {
        wp_enqueue_style(
            'wppilot-admin',
            (string) WPPILOT_PLUGIN_URL . 'includes/assets/admin.css',
            [],
            WPPILOT_VERSION,
        );
    }

    if ($hook === 'wppilot_page_wppilot-abilities') {
        wp_enqueue_style(
            'wppilot-hub-admin',
            (string) WPPILOT_PLUGIN_URL . 'includes/assets/hub.css',
            ['wppilot-admin'],
            WPPILOT_VERSION,
        );
        // phpcs:disable WordPress.WP.EnqueuedResourceParameters.NotInFooter -- `args: true` IS the in_footer flag; the sniff cannot read PHP named arguments.
        wp_enqueue_script(
            'wppilot-hub-admin',
            (string) WPPILOT_PLUGIN_URL . 'includes/assets/hub.js',
            [],
            WPPILOT_VERSION,
            args: true,
        );

        // phpcs:enable WordPress.WP.EnqueuedResourceParameters.NotInFooter
    }

    if ($hook === 'wppilot_page_wppilot-sandbox') {
        wp_enqueue_style(
            'wppilot-sandbox-admin',
            (string) WPPILOT_PLUGIN_URL . 'includes/assets/sandbox.css',
            ['wppilot-admin'],
            WPPILOT_VERSION,
        );
    }
});

// Register admin menus.
// Menu order uses spaced admin_menu priorities (multiples of 10) so entries
// can be positioned without post-hoc reordering.
add_action(
    'admin_menu',
    static function () {
        // Top-level menu item (shows the Connect page).
        add_menu_page(
            page_title: wppilot_nav_label('wppilot-connect'),
            menu_title: 'GoBuildWP',
            capability: wppilot_manage_capability(),
            menu_slug: 'wppilot-connect',
            callback: 'wppilot_render_home_page',
            // Use a native admin icon at menu scale. The detailed GoBuildWP mark
            // remains reserved for plugin screens and larger brand surfaces.
            icon_url: 'dashicons-rest-api',
            position: 3,
        );
        // WordPress names every submenu hook and screen ID after the sanitized
        // menu title. Pin it to the pre-rename 'wppilot' so `wppilot_page_*`
        // hooks (ours, Pro's and third parties') keep matching the GoBuildWP title.
        $GLOBALS['admin_page_hooks']['wppilot-connect'] = 'wppilot';

        // Rename the auto-created first submenu entry to match the page title.
        add_submenu_page(
            parent_slug: 'wppilot-connect',
            page_title: wppilot_nav_label('wppilot-connect'),
            menu_title: wppilot_nav_label('wppilot-connect'),
            capability: wppilot_manage_capability(),
            menu_slug: 'wppilot-connect',
            callback: 'wppilot_render_home_page',
        );

        // The setup wizard, endpoints and credentials. It was the home screen until
        // the dashboard replaced it; someone checking on a connected site should not
        // have to scroll past a three-step wizard to see what agents did today.
        add_submenu_page(
            parent_slug: 'wppilot-connect',
            page_title: wppilot_nav_label('wppilot-setup'),
            menu_title: wppilot_nav_label('wppilot-setup'),
            capability: wppilot_manage_capability(),
            menu_slug: WPPILOT_SETUP_PAGE,
            callback: 'wppilot_render_connect_page',
        );
    },
    priority: 10,
);

// Abilities Hub - priority 25 places it after Troubleshoot (20) rather than in the priority-10
// Configuration group, so the connection diagnostics sit directly below Connect. (30 is taken by
// Context, so 25 keeps it between Troubleshoot and Context without renumbering the rest.)
add_action(
    'admin_menu',
    static function () {
        add_submenu_page(
            parent_slug: 'wppilot-connect',
            page_title: wppilot_nav_label('wppilot-abilities'),
            menu_title: wppilot_nav_label('wppilot-abilities'),
            capability: wppilot_manage_capability(),
            menu_slug: 'wppilot-abilities',
            callback: 'wppilot_render_settings_page',
        );
    },
    priority: 25,
);

// Sandbox sub-page - priority 50 places it after Context (30) and Skills (40).
add_action(
    'admin_menu',
    static function () {
        add_submenu_page(
            parent_slug: 'wppilot-connect',
            page_title: wppilot_nav_label('wppilot-sandbox'),
            menu_title: wppilot_nav_label('wppilot-sandbox'),
            capability: wppilot_manage_capability(),
            menu_slug: 'wppilot-sandbox',
            callback: 'wppilot_render_sandbox_page',
        );
    },
    priority: 50,
);

$is_enabled = wppilot_is_enabled();

if (!$is_enabled && wppilot_is_domain_mismatch()) {
    add_action('admin_notices', static function () {
        if (!wppilot_current_user_can_manage()) {
            return;
        }
        /** @var string $locked */
        $locked = get_option('wppilot_ai_abilities_domain', default_value: '');
        wp_admin_notice(
            sprintf(
                /* translators: %s: the domain the abilities were originally enabled on */
                esc_html__(
                    'GoBuildWP AI Abilities were disabled because the site domain changed (enabled on %s). Re-enable them from the Connect page if this is intentional.',
                    domain: 'wppilot',
                ),
                '<code>' . esc_html($locked) . '</code>',
            ),
            ['type' => 'warning', 'dismissible' => true],
        );
    });
}

$wppilot_abilities_supported = wppilot_wordpress_abilities_supported();
$wppilot_adapter_initialized = false;

if ($is_enabled && $wppilot_abilities_supported) {
    wppilot_load_gutenberg_runtime();
    wppilot_register_ability_hooks();

    // MCP clients commonly leave sessions behind when they disconnect. Keep enough short-lived
    // sessions to avoid the adapter evicting active sessions when its default 32-session cap is reached.
    add_filter('mcp_adapter_session_max_per_user', static fn(): int => 128);
    add_filter('mcp_adapter_session_inactivity_timeout', static fn(): int => 4 * HOUR_IN_SECONDS);

    // Brand the default MCP server, but only when the loaded adapter is the copy
    // GoBuildWP shipped. The adapter's default server belongs to whoever bundled
    // the adapter, and since Elementor 4.3 that can be Elementor: renaming their
    // default server to `wppilot` would take a route they own and leave their own
    // clients pointing at nothing. Usage instructions are returned from the
    // discover-abilities tool instead of the initialize handshake.
    if (wppilot_owns_mcp_adapter()) {
        add_filter('mcp_adapter_default_server_config', static function (mixed $config): mixed {
            if (!is_array($config)) {
                return $config;
            }
            $config['server_id'] = 'wppilot';
            $config['server_route'] = 'wppilot';
            $config['server_name'] = 'GoBuildWP';
            // Without this the adapter's own default is used, and legacy clients
            // read that from initialize's serverInfo - it reported v1.0.0 for the
            // whole 1.1.0 cycle because only the mirror servers set a version.
            $config['server_version'] = 'v' . WPPILOT_VERSION;
            // The adapter discovers these by `mcp.public` alone; GoBuildWP's own rule is the one
            // the mirrors and the modern transport publish by.
            $config['resources'] = wppilot_discover_public_abilities('resource');
            $config['prompts'] = wppilot_discover_public_abilities('prompt');
            return $config;
        });

        // The default server is the one GoBuildWP server that cannot be given a transport
        // permission callback (DefaultServerFactory drops it), so it is gated through the
        // adapter's capability filter instead - for GoBuildWP's routes only. See
        // includes/mcp/legacy-access.php.
        add_filter(
            'mcp_adapter_default_transport_permission_user_capability',
            callback: 'WPPilot\Mcp\Legacy\default_transport_capability',
            priority: 10,
            accepted_args: 2,
        );

        // Register a legacy alias server at the old slug so configs that still point at
        // /wp-json/mcp/mcp-adapter-default-server keep working after the rename. Skipped
        // on a foreign adapter, where that slug is the real default server's own.
        add_action('mcp_adapter_init', callback: 'wppilot_register_legacy_mcp_server', priority: 20);
    } else {
        // Someone else's adapter won the version arbitration. Their default server
        // keeps its identity; GoBuildWP registers its canonical endpoint beside it so
        // /wp-json/mcp/wppilot exists either way and no client configuration breaks.
        add_action('mcp_adapter_init', callback: 'wppilot_register_canonical_mcp_server', priority: 20);
    }

    // Abilities public through WordPress 7.1's core `public` flag are published by GoBuildWP's rule
    // but refused by the adapter's get-ability-info and execute-ability, which read `mcp.public`
    // only. On GoBuildWP's servers that refusal is reconsidered, so legacy and modern clients reach
    // the same abilities. Added before the adapter registers its meta-tools on wp_abilities_api_init.
    add_filter('wp_register_ability_args', callback: 'WPPilot\Mcp\Legacy\bridge_meta_tool_permission', accepted_args: 2);

    // Register the OAuth-only server at /mcp/wppilot-oauth. Keeping the OAuth Bearer flow on a
    // route of its own means the OAuth middleware never touches the canonical /mcp/wppilot
    // endpoint that the existing Application Password installs use. Gated on the same transport
    // check as the OAuth bootstrap so the endpoint never exists without its token/authorize peers.
    add_action('mcp_adapter_init', callback: 'wppilot_register_oauth_mcp_server', priority: 20);

    // Initialize the optional bundled adapter after the transport-neutral Ability and REST hooks.
    // An adapter failure must not remove those hooks or make the REST Ability surface disappear.
    $wppilot_adapter_initialized = wppilot_initialize_mcp_adapter();

    if ($wppilot_adapter_initialized) {
        add_action('wp_abilities_api_categories_init', callback: 'wppilot_ensure_mcp_adapter_category', priority: 15);
        add_action('wp_abilities_api_init', callback: 'wppilot_ensure_mcp_adapter_abilities', priority: 15);
    }
}











if ($wppilot_adapter_initialized) {
    // The `mcp-adapter/execute-ability` dispatcher wraps every ability return in
    // `{ success: true, data: <inner> }`. When the inner value is itself
    // `{ success: false, error: "..." }` the outer `success: true` masks a real
    // logical failure, and agents that check the top-level flag - a very
    // reasonable default - silently march past the error. Unwrap that shape
    // here so the adapter's backward-compat path (ToolsHandler) turns it into a
    // proper `isError: true` CallToolResult.
    //
    // ToolsHandler::create_error_result flattens the response to a bare
    // `content: [text(error)], structuredContent: null, isError: true` - every
    // sibling field on the ability's return is discarded. Validators attach
    // structured repair hints (`invalid_values`, `unknown_properties`,
    // `collision_paths`, `suggested_name`, `failed_paths`, `overwritten_paths`,
    // `errors`, `schemas`, `style_errors`, `dynamic_tag_errors`, `dropped_keys`,
    // `schema`, …) that the agent needs to self-correct without a
    // round-trip - so embed whatever else the ability returned as a JSON
    // suffix on the error message. The suffix rides inside the string and
    // survives the downstream flatten.
    add_filter(
        'mcp_adapter_tool_call_result',
        static function (mixed $result, array $args, string $tool_name): mixed {
            // Tool names are MCP-sanitized from ability slugs - `/` becomes `-`.
            if ($tool_name !== 'mcp-adapter-execute-ability') {
                return $result;
            }
            if (!is_array($result) || ($result['success'] ?? null) !== true) {
                return $result;
            }
            /** @var array<array-key, mixed>|null $data */
            $data = $result['data'] ?? null;
            if (!is_array($data) || ($data['success'] ?? null) !== false) {
                return $result;
            }
            /** @var string|null $error */
            $error = $data['error'] ?? null;
            if (!is_string($error) || trim($error) === '') {
                return $result;
            }

            $detail = $data;
            unset($detail['success'], $detail['error']);
            if ($detail !== []) {
                $encoded = wp_json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($encoded)) {
                    $data['error'] = $error . "\n\nStructured detail (JSON):\n" . $encoded;
                }
            }

            return $data;
        },
        priority: 10,
        accepted_args: 3,
    );

    // Fix empty "properties" in JSON Schema: PHP json_encode outputs [] instead of {}.
    // MCP clients reject tools with invalid schemas, so we fix this in the REST response.
    add_filter('rest_pre_echo_response', static function (mixed $result): mixed {
        if (!is_array($result)) {
            return $result;
        }
        /** @var \stdClass|null $resultObj */
        $resultObj = $result['result'] ?? null;
        if (!$resultObj instanceof \stdClass) {
            return $result;
        }
        /** @var list<array<string, mixed>>|null $tools */
        $tools = $resultObj->tools ?? null;
        if (!is_array($tools)) {
            return $result;
        }
        foreach ($tools as &$tool) {
            foreach (['inputSchema', 'outputSchema'] as $key) {
                /** @var array<string, mixed>|null $schema */
                $schema = $tool[$key] ?? null;
                if (!is_array($schema) || ($schema['properties'] ?? null) !== []) {
                    continue;
                }
                $schema['properties'] = new \stdClass();
                $tool[$key] = $schema;
            }
        }
        $resultObj->tools = $tools;
        return $result;
    });

    // Info notice if the standalone MCP Adapter plugin is still active.
    if (function_exists('is_plugin_active') && is_plugin_active('mcp-adapter/mcp-adapter.php')) {
        add_action('admin_notices', static function () {
            if (!wppilot_current_user_can_manage()) {
                return;
            }
            wp_admin_notice(
                esc_html__(
                    'GoBuildWP bundles the MCP Adapter. You can safely deactivate the standalone MCP Adapter plugin.',
                    domain: 'wppilot',
                ),
                [
                    'type' => 'info',
                    'dismissible' => true,
                ],
            );
        });
    }
}
add_filter(
    'mcp_adapter_tool_call_result',
    callback: 'wppilot_enrich_disabled_ability_error',
    priority: 10,
    accepted_args: 2,
);

// Load sandbox plugins. The directory itself is created on activation and
// lazily by wppilot_get_sandbox_dir(ensure_exists: true) at the moment an
// ability first writes to it - never on every request, which would stat (and
// on read-only filesystems, fail to create) wp-content on every page load.
if (!wppilot_is_wordpress_org_edition()) {
    require_once __DIR__ . '/includes/sandbox/loader.php';
}
