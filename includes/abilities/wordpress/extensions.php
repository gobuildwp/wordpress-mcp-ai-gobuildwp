<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\WordPress;

use WP_Error;
use WP_Theme;

/**
 * Plugin and theme lifecycle.
 *
 * `list-extensions` in site-management.php answers "what is installed"; this
 * module is everything that changes that answer. The split across risk classes
 * is deliberate and is enforced by includes/safety.php, not here:
 *
 *   - search / get               read      every profile
 *   - activate / deactivate      write     Production Safe, confirmation required
 *   - update / switch-theme      write     Production Safe, confirmation required
 *   - install / delete           critical  Developer Full Access only
 *
 * Installing and deleting write to the server filesystem and pull code from a
 * remote host, which is the same class of operation as execute-php: a site on
 * Production Safe must not reach it. Activating an already-installed plugin
 * does not fetch or write code, so it stays available on a live site — but it
 * can still fatal a page, so it is confirmation-gated like a deletion.
 */

if (!defined('ABSPATH')) {
    exit();
}

register_core_ability('wppilot/search-extensions', [
    'label' => __('Search Plugins and Themes', domain: 'wppilot'),
    'description' => __(
        'Searches the WordPress.org directory for plugins or themes and returns slug, name, version, author, rating, active-install count, last update, and the WordPress/PHP versions each one requires. Read-only: it installs nothing. Call this before wppilot/install-plugin or wppilot/install-theme to resolve a human name ("a contact form plugin") into the exact slug those abilities need, and check the returned `requires` and `requires_php` against wppilot/system-status before installing.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'search' => ['type' => 'string', 'minLength' => 1],
            'type' => ['type' => 'string', 'enum' => ['plugin', 'theme'], 'default' => 'plugin'],
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
        ],
        'required' => ['search'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_search_extensions',
    'permission_callback' => static fn(): bool => wordpress_core_permission() && (
        current_user_can('install_plugins') || current_user_can('install_themes')
    ),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/get-extension', [
    'label' => __('Get Plugin or Theme', domain: 'wppilot'),
    'description' => __(
        'Returns one plugin or theme in detail: the installed copy (version, active state, network activation, update availability, plugin file path) and, when the slug exists on WordPress.org, the directory record (latest version, requirements, last update, homepage). Read-only. Use it to confirm a slug before installing, to read the exact `file` value that wppilot/activate-plugin needs, or to check whether an update is actually available before calling wppilot/update-plugin.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'slug' => ['type' => 'string', 'minLength' => 1],
            'type' => ['type' => 'string', 'enum' => ['plugin', 'theme'], 'default' => 'plugin'],
        ],
        'required' => ['slug'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_get_extension',
    'permission_callback' => static fn(): bool => wordpress_core_permission() && (
        current_user_can('activate_plugins') || current_user_can('switch_themes')
    ),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/activate-plugin', [
    'label' => __('Activate Plugin', domain: 'wppilot'),
    'description' => __(
        'Activates an installed plugin by its plugin file (the "dir/file.php" value returned by wppilot/list-extensions or wppilot/get-extension). Activation runs the plugin\'s own activation hooks and can fatal the site, so it requires explicit confirmation. On multisite, `network_wide: true` activates across the network and needs network administrator rights. The change is recorded in the change ledger and can be rolled back with wppilot/rollback-change.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'file' => ['type' => 'string', 'minLength' => 1],
            'network_wide' => ['type' => 'boolean', 'default' => false],
        ],
        'required' => ['file'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_activate_plugin',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('activate_plugins'),
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true, idempotent: true),
]);

register_core_ability('wppilot/deactivate-plugin', [
    'label' => __('Deactivate Plugin', domain: 'wppilot'),
    'description' => __(
        'Deactivates an active plugin by its plugin file. Deactivation removes whatever the plugin provides — shortcodes stop rendering, blocks disappear from saved content, integrations go dark — so it requires explicit confirmation. GoBuildWP and GoBuildWP Pro cannot be deactivated through this ability: doing so would sever the connection carrying the call. The change is recorded in the change ledger and can be rolled back.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'file' => ['type' => 'string', 'minLength' => 1],
            'network_wide' => ['type' => 'boolean', 'default' => false],
        ],
        'required' => ['file'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_deactivate_plugin',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('activate_plugins'),
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true, idempotent: true),
]);

register_core_ability('wppilot/update-plugin', [
    'label' => __('Update Plugin', domain: 'wppilot'),
    'description' => __(
        'Updates one installed plugin to the latest version its update source offers, refreshing the update check first. Replaces code on the server and can introduce breaking changes, so it requires explicit confirmation. Returns the version before and after; when no update is available it reports that without touching the files. Not reversible through the change ledger — the previous version is not retained.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['file' => ['type' => 'string', 'minLength' => 1]],
        'required' => ['file'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_update_plugin',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('update_plugins'),
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true, idempotent: false),
]);

register_core_ability('wppilot/update-theme', [
    'label' => __('Update Theme', domain: 'wppilot'),
    'description' => __(
        'Updates one installed theme to the latest version its update source offers, refreshing the update check first. Overwrites theme files — customizations made directly to a non-child theme are lost — so it requires explicit confirmation. Returns the version before and after. Not reversible through the change ledger.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['stylesheet' => ['type' => 'string', 'minLength' => 1]],
        'required' => ['stylesheet'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_update_theme',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('update_themes'),
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true, idempotent: false),
]);

register_core_ability('wppilot/switch-theme', [
    'label' => __('Switch Theme', domain: 'wppilot'),
    'description' => __(
        'Activates an installed theme by its stylesheet directory name. Switching themes changes every page on the front end, drops widget assignments the new theme has no sidebars for, and re-maps navigation menu locations, so it requires explicit confirmation. Themes reporting an error (a missing parent, for instance) are refused. The previous theme is recorded in the change ledger and the switch can be rolled back.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['stylesheet' => ['type' => 'string', 'minLength' => 1]],
        'required' => ['stylesheet'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_switch_theme',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('switch_themes'),
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true, idempotent: true),
]);

register_core_ability('wppilot/install-plugin', [
    'label' => __('Install Plugin', domain: 'wppilot'),
    'description' => __(
        'Downloads and installs a plugin from the WordPress.org directory by slug, or from an explicit HTTPS ZIP URL. Writes executable code to the server, so it is a critical operation: blocked by Production Safe and Read Only, available in Developer Full Access with explicit confirmation. The plugin is left INACTIVE — review it, then call wppilot/activate-plugin separately. Resolve the slug with wppilot/search-extensions first. Not reversible through the change ledger; remove it with wppilot/delete-plugin.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'slug' => ['type' => 'string', 'minLength' => 1],
            'zip_url' => ['type' => 'string', 'minLength' => 1],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_install_plugin',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('install_plugins'),
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true, idempotent: false),
]);

register_core_ability('wppilot/install-theme', [
    'label' => __('Install Theme', domain: 'wppilot'),
    'description' => __(
        'Downloads and installs a theme from the WordPress.org directory by slug, or from an explicit HTTPS ZIP URL. Writes code to the server, so it is a critical operation: blocked by Production Safe and Read Only, available in Developer Full Access with explicit confirmation. The theme is installed but NOT activated — call wppilot/switch-theme separately once the site is ready for it. Not reversible through the change ledger; remove it with wppilot/delete-theme.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'slug' => ['type' => 'string', 'minLength' => 1],
            'zip_url' => ['type' => 'string', 'minLength' => 1],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_install_theme',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('install_themes'),
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true, idempotent: false),
]);

register_core_ability('wppilot/delete-plugin', [
    'label' => __('Delete Plugin', domain: 'wppilot'),
    'description' => __(
        'Permanently deletes an installed plugin\'s files from the server. Critical and irreversible: blocked by Production Safe and Read Only, available in Developer Full Access with explicit confirmation. The plugin must be deactivated first — deactivate it with wppilot/deactivate-plugin, confirm the site still behaves, then delete. Data the plugin stored in the database is not removed unless the plugin ships an uninstall routine that WordPress runs.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['file' => ['type' => 'string', 'minLength' => 1]],
        'required' => ['file'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_delete_plugin',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('delete_plugins'),
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true, idempotent: false),
]);

register_core_ability('wppilot/delete-theme', [
    'label' => __('Delete Theme', domain: 'wppilot'),
    'description' => __(
        'Permanently deletes an installed theme\'s files from the server. Critical and irreversible: blocked by Production Safe and Read Only, available in Developer Full Access with explicit confirmation. The active theme, and the parent of the active theme, are refused — switch away with wppilot/switch-theme first.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['stylesheet' => ['type' => 'string', 'minLength' => 1]],
        'required' => ['stylesheet'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_delete_theme',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('delete_themes'),
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true, idempotent: false),
]);

// --------------------------------------------------------------------- reads

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_search_extensions(array $input): array|WP_Error
{
    $is_theme = (string) ($input['type'] ?? 'plugin') === 'theme';
    $per_page = min(50, max(1, (int) ($input['per_page'] ?? 10)));
    $page = max(1, (int) ($input['page'] ?? 1));
    $args = [
        'search' => sanitize_text_field((string) $input['search']),
        'page' => $page,
        'per_page' => $per_page,
        'fields' => ['sections' => false, 'short_description' => true],
    ];

    if ($is_theme) {
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/theme-install.php';
        /** @var mixed $response */
        $response = themes_api('query_themes', $args);
    } else {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        /** @var mixed $response */
        $response = plugins_api('query_plugins', $args);
    }

    if (is_wp_error($response)) {
        return $response;
    }
    if (!is_object($response)) {
        return new WP_Error('wppilot_directory_unavailable', __(
            'The WordPress.org directory did not return a usable response.',
            domain: 'wppilot',
        ));
    }

    $key = $is_theme ? 'themes' : 'plugins';
    /** @var mixed $rows */
    $rows = $response->{$key} ?? [];
    $items = [];
    /** @var mixed $row */
    foreach (is_array($rows) ? $rows : [] as $row) {
        $items[] = wordpress_directory_summary(wordpress_extension_to_array($row));
    }

    /** @var mixed $info */
    $info = $response->info ?? [];
    $info = is_object($info) ? get_object_vars($info) : (is_array($info) ? $info : []);

    return [
        'type' => $is_theme ? 'theme' : 'plugin',
        'items' => $items,
        'page' => $page,
        'per_page' => $per_page,
        'total' => (int) ($info['results'] ?? count($items)),
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_get_extension(array $input): array|WP_Error
{
    $slug = wordpress_extension_slug((string) $input['slug']);
    if ($slug === '') {
        return wordpress_extension_invalid_slug();
    }
    $is_theme = (string) ($input['type'] ?? 'plugin') === 'theme';

    $result = [
        'type' => $is_theme ? 'theme' : 'plugin',
        'slug' => $slug,
        'installed' => null,
        'directory' => null,
    ];

    if ($is_theme) {
        $theme = wp_get_theme($slug);
        if ($theme->exists()) {
            $result['installed'] = wordpress_theme_summary($theme);
        }
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/theme-install.php';
        /** @var mixed $api */
        $api = themes_api('theme_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
    } else {
        $file = wordpress_plugin_file_for_slug($slug);
        if ($file !== '') {
            $result['installed'] = wordpress_plugin_summary($file);
        }
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        /** @var mixed $api */
        $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
    }

    // A slug that is not in the directory is an ordinary outcome for a premium
    // or client-built extension, so the absent record is reported rather than
    // raised: the installed half of the answer is still useful.
    if (!is_wp_error($api) && (is_object($api) || is_array($api))) {
        $result['directory'] = wordpress_directory_summary(wordpress_extension_to_array($api));
    }

    if ($result['installed'] === null && $result['directory'] === null) {
        return new WP_Error(
            'wppilot_extension_not_found',
            sprintf(
                /* translators: %s: the requested plugin or theme slug */
                __('"%s" is neither installed on this site nor listed on WordPress.org.', domain: 'wppilot'),
                $slug,
            ),
            ['status' => 404],
        );
    }

    return $result;
}

// ------------------------------------------------------------- activation

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_activate_plugin(array $input): array|WP_Error
{
    $file = wordpress_plugin_file((string) $input['file']);
    if ($file instanceof WP_Error) {
        return $file;
    }
    $network_wide = ($input['network_wide'] ?? false) === true;
    $network_error = wordpress_network_scope_error($network_wide);
    if ($network_error !== null) {
        return $network_error;
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (is_plugin_active($file) && !$network_wide) {
        return ['file' => $file, 'active' => true, 'result' => 'already_active'] + wordpress_plugin_summary($file);
    }

    // $silent stays false: a plugin's activation hook is what creates its
    // tables and default options, and skipping it produces a plugin that is
    // "active" but half-initialised.
    $activated = activate_plugin($file, redirect: '', network_wide: $network_wide, silent: false);
    if (is_wp_error($activated)) {
        return $activated;
    }

    return ['file' => $file, 'result' => 'activated'] + wordpress_plugin_summary($file);
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_deactivate_plugin(array $input): array|WP_Error
{
    $file = wordpress_plugin_file((string) $input['file']);
    if ($file instanceof WP_Error) {
        return $file;
    }
    $self = wordpress_extension_self_error($file);
    if ($self !== null) {
        return $self;
    }
    $network_wide = ($input['network_wide'] ?? false) === true;
    $network_error = wordpress_network_scope_error($network_wide);
    if ($network_error !== null) {
        return $network_error;
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (!is_plugin_active($file) && !is_plugin_active_for_network($file)) {
        return ['file' => $file, 'active' => false, 'result' => 'already_inactive'] + wordpress_plugin_summary($file);
    }

    deactivate_plugins([$file], silent: false, network_wide: $network_wide ? true : null);

    if (is_plugin_active($file)) {
        return new WP_Error(
            'wppilot_plugin_still_active',
            __('WordPress reported no error but the plugin is still active.', domain: 'wppilot'),
        );
    }

    return ['file' => $file, 'result' => 'deactivated'] + wordpress_plugin_summary($file);
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_switch_theme(array $input): array|WP_Error
{
    $stylesheet = wordpress_extension_slug((string) $input['stylesheet']);
    if ($stylesheet === '') {
        return wordpress_extension_invalid_slug('stylesheet');
    }

    $theme = wp_get_theme($stylesheet);
    if (!$theme->exists()) {
        return new WP_Error(
            'wppilot_theme_not_found',
            sprintf(
                /* translators: %s: the requested theme stylesheet directory */
                __('Theme "%s" is not installed.', domain: 'wppilot'),
                $stylesheet,
            ),
            ['status' => 404],
        );
    }

    // A theme whose parent is missing renders a broken site the moment it is
    // switched to, and WordPress's own Themes screen hides it for that reason.
    $errors = $theme->errors();
    if ($errors instanceof WP_Error) {
        return new WP_Error(
            'wppilot_theme_broken',
            sprintf(
                /* translators: 1: theme stylesheet directory, 2: the underlying WordPress error */
                __('Theme "%1$s" cannot be activated: %2$s', domain: 'wppilot'),
                $stylesheet,
                $errors->get_error_message(),
            ),
            ['status' => 409],
        );
    }

    $previous = get_stylesheet();
    if ($previous === $stylesheet) {
        return ['stylesheet' => $stylesheet, 'result' => 'already_active', 'previous' => $previous];
    }

    switch_theme($stylesheet);

    $now = get_stylesheet();
    if ($now !== $stylesheet) {
        return new WP_Error(
            'wppilot_theme_switch_failed',
            __('WordPress did not switch the active theme.', domain: 'wppilot'),
        );
    }

    return [
        'stylesheet' => $stylesheet,
        'result' => 'switched',
        'previous' => $previous,
        'theme' => wordpress_theme_summary($theme),
    ];
}

// ---------------------------------------------------------------- updates

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_update_plugin(array $input): array|WP_Error
{
    $file = wordpress_plugin_file((string) $input['file']);
    if ($file instanceof WP_Error) {
        return $file;
    }
    $blocked = wordpress_file_mod_error('update_plugin');
    if ($blocked !== null) {
        return $blocked;
    }
    $filesystem = wordpress_filesystem_error();
    if ($filesystem !== null) {
        return $filesystem;
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    $before = wordpress_plugin_summary($file);

    // The update transient is what the upgrader reads; without a refresh a site
    // whose last check predates the release reports "up to date" incorrectly.
    wp_update_plugins();
    $updates = get_site_transient('update_plugins');
    $available = is_object($updates) && is_array($updates->response ?? null)
        ? array_key_exists($file, $updates->response)
        : false;
    if (!$available) {
        return ['file' => $file, 'result' => 'no_update_available'] + $before;
    }

    $skin = new \WP_Ajax_Upgrader_Skin();
    $upgrader = new \Plugin_Upgrader($skin);
    /** @var mixed $result */
    $result = $upgrader->upgrade($file);

    $failure = wordpress_upgrader_error($result, $skin, __('plugin update', domain: 'wppilot'));
    if ($failure !== null) {
        return $failure;
    }

    // The plugin file list is cached for the request; the post-update version
    // read has to come from a fresh parse or it reports the pre-update value.
    wp_clean_plugins_cache();

    $after = wordpress_plugin_summary($file);

    return [
        'file' => $file,
        'result' => 'updated',
        'version_before' => (string) ($before['version'] ?? ''),
        'version_after' => (string) ($after['version'] ?? ''),
    ] + $after;
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_update_theme(array $input): array|WP_Error
{
    $stylesheet = wordpress_extension_slug((string) $input['stylesheet']);
    if ($stylesheet === '') {
        return wordpress_extension_invalid_slug('stylesheet');
    }
    if (!wp_get_theme($stylesheet)->exists()) {
        return new WP_Error(
            'wppilot_theme_not_found',
            sprintf(
                /* translators: %s: the requested theme stylesheet directory */
                __('Theme "%s" is not installed.', domain: 'wppilot'),
                $stylesheet,
            ),
            ['status' => 404],
        );
    }
    $blocked = wordpress_file_mod_error('update_theme');
    if ($blocked !== null) {
        return $blocked;
    }
    $filesystem = wordpress_filesystem_error();
    if ($filesystem !== null) {
        return $filesystem;
    }

    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    $before = wordpress_theme_summary(wp_get_theme($stylesheet));

    wp_update_themes();
    $updates = get_site_transient('update_themes');
    $available = is_object($updates) && is_array($updates->response ?? null)
        ? array_key_exists($stylesheet, $updates->response)
        : false;
    if (!$available) {
        return ['stylesheet' => $stylesheet, 'result' => 'no_update_available'] + $before;
    }

    $skin = new \WP_Ajax_Upgrader_Skin();
    $upgrader = new \Theme_Upgrader($skin);
    /** @var mixed $result */
    $result = $upgrader->upgrade($stylesheet);

    $failure = wordpress_upgrader_error($result, $skin, __('theme update', domain: 'wppilot'));
    if ($failure !== null) {
        return $failure;
    }

    wp_clean_themes_cache();

    $after = wordpress_theme_summary(wp_get_theme($stylesheet));

    return [
        'stylesheet' => $stylesheet,
        'result' => 'updated',
        'version_before' => (string) ($before['version'] ?? ''),
        'version_after' => (string) ($after['version'] ?? ''),
    ] + $after;
}

// --------------------------------------------------------------- installs

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_install_plugin(array $input): array|WP_Error
{
    $source = wordpress_install_source($input, is_theme: false);
    if ($source instanceof WP_Error) {
        return $source;
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    $skin = new \WP_Ajax_Upgrader_Skin();
    $upgrader = new \Plugin_Upgrader($skin);
    /** @var mixed $result */
    $result = $upgrader->install($source['download']);

    $failure = wordpress_upgrader_error($result, $skin, __('plugin install', domain: 'wppilot'));
    if ($failure !== null) {
        return $failure;
    }

    wp_clean_plugins_cache();

    // plugin_info() names the entry file inside the package, which is what
    // activate-plugin needs — the slug alone is not enough for plugins whose
    // main file is not named after their directory.
    $file = (string) ($upgrader->plugin_info() ?? '');
    if ($file === '' && $source['slug'] !== '') {
        $file = wordpress_plugin_file_for_slug($source['slug']);
    }

    return [
        'result' => 'installed',
        'slug' => $source['slug'],
        'file' => $file,
        'active' => false,
        'next_step' => 'Review the plugin, then call wppilot/activate-plugin with this "file" value.',
    ] + ($file !== '' ? wordpress_plugin_summary($file) : []);
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_install_theme(array $input): array|WP_Error
{
    $source = wordpress_install_source($input, is_theme: true);
    if ($source instanceof WP_Error) {
        return $source;
    }

    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    $skin = new \WP_Ajax_Upgrader_Skin();
    $upgrader = new \Theme_Upgrader($skin);
    /** @var mixed $result */
    $result = $upgrader->install($source['download']);

    $failure = wordpress_upgrader_error($result, $skin, __('theme install', domain: 'wppilot'));
    if ($failure !== null) {
        return $failure;
    }

    wp_clean_themes_cache();

    // theme_info() names the stylesheet directory inside the package, which is
    // not always the slug — a ZIP can unpack to any directory name.
    $installed = $upgrader->theme_info();
    $stylesheet = $installed instanceof WP_Theme ? $installed->get_stylesheet() : $source['slug'];

    $theme = wp_get_theme($stylesheet);

    return [
        'result' => 'installed',
        'slug' => $source['slug'],
        'stylesheet' => $stylesheet,
        'active' => false,
        'next_step' => 'Call wppilot/switch-theme with this "stylesheet" value when the site is ready for it.',
    ] + ($theme->exists() ? wordpress_theme_summary($theme) : []);
}

// ---------------------------------------------------------------- deletes

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_delete_plugin(array $input): array|WP_Error
{
    $file = wordpress_plugin_file((string) $input['file']);
    if ($file instanceof WP_Error) {
        return $file;
    }
    $self = wordpress_extension_self_error($file, deleting: true);
    if ($self !== null) {
        return $self;
    }
    $blocked = wordpress_file_mod_error('delete_plugin');
    if ($blocked !== null) {
        return $blocked;
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    // WordPress's delete_plugins() does not refuse an active plugin — the
    // Plugins screen refuses it before calling. Deleting the files under a
    // running plugin fatals the site on the next request, so the refusal has
    // to live here.
    if (is_plugin_active($file) || is_plugin_active_for_network($file)) {
        return new WP_Error(
            'wppilot_plugin_still_active',
            __(
                'This plugin is active. Deactivate it with wppilot/deactivate-plugin and confirm the site still behaves before deleting its files.',
                domain: 'wppilot',
            ),
            ['status' => 409],
        );
    }

    $filesystem = wordpress_filesystem_error();
    if ($filesystem !== null) {
        return $filesystem;
    }

    $summary = wordpress_plugin_summary($file);
    /** @var mixed $deleted */
    $deleted = delete_plugins([$file]);
    if (is_wp_error($deleted)) {
        return $deleted;
    }
    if ($deleted !== true) {
        return new WP_Error('wppilot_plugin_delete_failed', __(
            'WordPress could not delete the plugin files.',
            domain: 'wppilot',
        ));
    }

    wp_clean_plugins_cache();

    return [
        'file' => $file,
        'result' => 'deleted',
        'name' => (string) ($summary['name'] ?? $file),
        'version' => (string) ($summary['version'] ?? ''),
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_delete_theme(array $input): array|WP_Error
{
    $stylesheet = wordpress_extension_slug((string) $input['stylesheet']);
    if ($stylesheet === '') {
        return wordpress_extension_invalid_slug('stylesheet');
    }

    $theme = wp_get_theme($stylesheet);
    if (!$theme->exists()) {
        return new WP_Error(
            'wppilot_theme_not_found',
            sprintf(
                /* translators: %s: the requested theme stylesheet directory */
                __('Theme "%s" is not installed.', domain: 'wppilot'),
                $stylesheet,
            ),
            ['status' => 404],
        );
    }

    // Both the active theme and the parent underneath an active child theme
    // are load-bearing: deleting either leaves the front end with no template.
    if ($stylesheet === get_stylesheet() || $stylesheet === get_template()) {
        return new WP_Error(
            'wppilot_theme_in_use',
            __(
                'This theme is in use — it is the active theme or the parent of the active child theme. Switch to another theme with wppilot/switch-theme before deleting it.',
                domain: 'wppilot',
            ),
            ['status' => 409],
        );
    }

    $blocked = wordpress_file_mod_error('delete_theme');
    if ($blocked !== null) {
        return $blocked;
    }
    $filesystem = wordpress_filesystem_error();
    if ($filesystem !== null) {
        return $filesystem;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    // delete_theme() lives here, and wp-admin/includes is not loaded on a REST or MCP request.
    require_once ABSPATH . 'wp-admin/includes/theme.php';

    $summary = wordpress_theme_summary($theme);
    /** @var mixed $deleted */
    $deleted = delete_theme($stylesheet);
    if (is_wp_error($deleted)) {
        return $deleted;
    }
    if ($deleted !== true) {
        return new WP_Error('wppilot_theme_delete_failed', __(
            'WordPress could not delete the theme files.',
            domain: 'wppilot',
        ));
    }

    wp_clean_themes_cache();

    return [
        'stylesheet' => $stylesheet,
        'result' => 'deleted',
        'name' => (string) ($summary['name'] ?? $stylesheet),
        'version' => (string) ($summary['version'] ?? ''),
    ];
}

// ---------------------------------------------------------------- helpers

/**
 * Normalize and validate a WordPress.org-style slug or a theme stylesheet.
 *
 * Returns an empty string for anything that is not a plain directory name.
 * Path traversal is the risk being closed here: a stylesheet is concatenated
 * into a filesystem path by WordPress itself.
 */
function wordpress_extension_slug(string $value): string
{
    $slug = trim($value);
    if ($slug === '' || preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*$#', $slug) !== 1) {
        return '';
    }
    // validate_file() returns 0 only for a path with no traversal, no drive
    // letter, and no "./" segment.
    return validate_file($slug) === 0 ? $slug : '';
}

function wordpress_extension_invalid_slug(string $field = 'slug'): WP_Error
{
    return new WP_Error(
        'wppilot_invalid_extension_slug',
        sprintf(
            /* translators: %s: the rejected input field name */
            __(
                'The %s must be a plain directory name (letters, numbers, dots, dashes and underscores) with no path segments.',
                domain: 'wppilot',
            ),
            $field,
        ),
        ['status' => 422],
    );
}

/**
 * Validate a "dir/file.php" plugin identifier against the installed set.
 *
 * @return string|WP_Error
 */
function wordpress_plugin_file(string $value): string|WP_Error
{
    $file = trim(wp_normalize_path($value), '/');
    if ($file === '' || validate_file($file) !== 0 || !str_ends_with(strtolower($file), '.php')) {
        return new WP_Error(
            'wppilot_invalid_plugin_file',
            __(
                'The plugin file must be the "directory/file.php" identifier reported by wppilot/list-extensions, with no path traversal.',
                domain: 'wppilot',
            ),
            ['status' => 422],
        );
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (!array_key_exists($file, get_plugins())) {
        return new WP_Error(
            'wppilot_plugin_not_found',
            sprintf(
                /* translators: %s: the requested plugin file identifier */
                __('Plugin "%s" is not installed on this site.', domain: 'wppilot'),
                $file,
            ),
            ['status' => 404],
        );
    }

    return $file;
}

/**
 * Refuse operations that would sever the connection carrying the call.
 *
 * Deactivating or deleting GoBuildWP mid-request leaves the agent with no way to
 * undo what it just did, and on a remote site no way back in at all.
 */
function wordpress_extension_self_error(string $file, bool $deleting = false): ?WP_Error
{
    $own = [];
    if (defined('WPPILOT_PLUGIN_FILE')) {
        $own[] = plugin_basename((string) constant('WPPILOT_PLUGIN_FILE'));
    }
    if (defined('WPPILOT_PRO_FILE')) {
        $own[] = plugin_basename((string) constant('WPPILOT_PRO_FILE'));
    }
    if (!in_array($file, $own, strict: true)) {
        return null;
    }

    return new WP_Error(
        'wppilot_cannot_target_self',
        $deleting
            ? __(
                'GoBuildWP cannot delete itself: the request is being served by this plugin. Remove it from the Plugins screen in wp-admin instead.',
                domain: 'wppilot',
            )
            : __(
                'GoBuildWP cannot deactivate itself: doing so would sever the connection carrying this call. Deactivate it from the Plugins screen in wp-admin instead.',
                domain: 'wppilot',
            ),
        ['status' => 409],
    );
}

/**
 * Network-wide activation is a network administrator's decision, not a site
 * administrator's, and it is meaningless on a single site.
 */
function wordpress_network_scope_error(bool $network_wide): ?WP_Error
{
    if (!$network_wide) {
        return null;
    }
    if (!is_multisite()) {
        return new WP_Error(
            'wppilot_not_multisite',
            __('network_wide only applies to a multisite network.', domain: 'wppilot'),
            ['status' => 422],
        );
    }
    if (!current_user_can('manage_network_plugins')) {
        return new WP_Error(
            'wppilot_cannot_manage_network_plugins',
            __('Network-wide activation requires network administrator rights.', domain: 'wppilot'),
            ['status' => 403],
        );
    }

    return null;
}

/**
 * Honour DISALLOW_FILE_MODS and the file-mod filters before touching anything.
 *
 * Managed hosts commonly set DISALLOW_FILE_MODS, and a site that has opted out
 * of code changes must get a named refusal rather than an opaque filesystem
 * failure halfway through an install.
 */
function wordpress_file_mod_error(string $context): ?WP_Error
{
    if (!function_exists('wp_is_file_mod_allowed') || wp_is_file_mod_allowed($context)) {
        return null;
    }

    return new WP_Error(
        'wppilot_file_mods_disallowed',
        __(
            'This site does not allow plugin or theme file changes (DISALLOW_FILE_MODS is set, or a plugin blocks them). Install and update from the host\'s own tooling instead.',
            domain: 'wppilot',
        ),
        ['status' => 403],
    );
}

/**
 * Require a filesystem WordPress can write to without asking a human.
 *
 * GoBuildWP runs headless: `request_filesystem_credentials()` has no screen to
 * render an FTP form on, so anything other than the direct method has to fail
 * with an explanation instead of stalling or half-writing.
 */
function wordpress_filesystem_error(): ?WP_Error
{
    require_once ABSPATH . 'wp-admin/includes/file.php';

    $method = get_filesystem_method();
    if ($method !== 'direct') {
        return new WP_Error(
            'wppilot_filesystem_not_direct',
            sprintf(
                /* translators: %s: the filesystem method WordPress selected, e.g. "ftpext" */
                __(
                    'WordPress wants to write to the filesystem over "%s", which needs credentials a human enters in wp-admin. Agent-driven installs need direct filesystem access (correct ownership on wp-content, or an FS_METHOD of direct with credentials in wp-config.php).',
                    domain: 'wppilot',
                ),
                $method,
            ),
            ['status' => 409],
        );
    }

    if (!WP_Filesystem()) {
        return new WP_Error(
            'wppilot_filesystem_unavailable',
            __('WordPress could not initialise its filesystem API.', domain: 'wppilot'),
            ['status' => 409],
        );
    }

    return null;
}

/**
 * Resolve an install request to a download URL, from a WordPress.org directory
 * slug or, outside the WordPress.org edition, an explicit HTTPS ZIP.
 *
 * The WordPress.org edition installs from the WordPress.org directory only: its
 * schema has no zip_url, and a zip_url that reaches this function anyway (a
 * direct PHP caller, say) is refused before anything is fetched.
 *
 * @param array<string, mixed> $input
 * @param bool|null $directory_only Defaults to the running edition.
 * @return array{slug: string, download: string}|WP_Error
 */
function wordpress_install_source(array $input, bool $is_theme, ?bool $directory_only = null): array|WP_Error
{
    $directory_only ??= \wppilot_is_wordpress_org_edition();
    $has_slug = trim((string) ($input['slug'] ?? '')) !== '';
    $has_zip = trim((string) ($input['zip_url'] ?? '')) !== '';
    if ($directory_only && ($has_zip || !$has_slug)) {
        return new WP_Error(
            'wppilot_install_source_directory_only',
            __('Provide "slug": plugins and themes are installed from the WordPress.org directory only.', domain: 'wppilot'),
            ['status' => 422],
        );
    }
    if ($has_slug === $has_zip) {
        return new WP_Error(
            'wppilot_install_source_ambiguous',
            __('Provide exactly one of "slug" (WordPress.org) or "zip_url" (an HTTPS package URL).', domain: 'wppilot'),
            ['status' => 422],
        );
    }

    $blocked = wordpress_file_mod_error('can_install_plugin_or_theme');
    if ($blocked !== null) {
        return $blocked;
    }
    $filesystem = wordpress_filesystem_error();
    if ($filesystem !== null) {
        return $filesystem;
    }

    if ($has_zip) {
        $url = trim((string) $input['zip_url']);
        // Plain HTTP would let anything on the path swap the package for its
        // own code, which then runs as the site.
        if (strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return new WP_Error(
                'wppilot_zip_url_not_https',
                __('A package URL must use HTTPS.', domain: 'wppilot'),
                ['status' => 422],
            );
        }
        if (esc_url_raw($url) === '') {
            return new WP_Error(
                'wppilot_zip_url_invalid',
                __('The package URL is not a valid URL.', domain: 'wppilot'),
                ['status' => 422],
            );
        }

        return ['slug' => '', 'download' => $url];
    }

    $slug = wordpress_extension_slug((string) $input['slug']);
    if ($slug === '') {
        return wordpress_extension_invalid_slug();
    }

    if ($is_theme) {
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/theme-install.php';
        /** @var mixed $api */
        $api = themes_api('theme_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
    } else {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        /** @var mixed $api */
        $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
    }

    if (is_wp_error($api)) {
        return $api;
    }

    $record = wordpress_extension_to_array($api);
    $download = trim((string) ($record['download_link'] ?? ''));
    if ($download === '') {
        return new WP_Error(
            'wppilot_no_download_link',
            sprintf(
                /* translators: %s: the requested slug */
                __('WordPress.org returned no download package for "%s".', domain: 'wppilot'),
                $slug,
            ),
            ['status' => 404],
        );
    }

    return ['slug' => $slug, 'download' => $download];
}

/**
 * Turn an upgrader outcome into a WP_Error, or null when it succeeded.
 *
 * The upgraders report failure three different ways — a WP_Error return, a
 * false return, and a clean return with errors collected on the skin — so all
 * three are checked before a run is called successful.
 */
function wordpress_upgrader_error(mixed $result, \WP_Upgrader_Skin $skin, string $operation): ?WP_Error
{
    if (is_wp_error($result)) {
        return $result;
    }

    $errors = method_exists($skin, 'get_errors') ? $skin->get_errors() : null;
    if ($errors instanceof WP_Error && $errors->has_errors()) {
        return new WP_Error(
            'wppilot_upgrader_failed',
            sprintf(
                /* translators: 1: the operation, e.g. "plugin install", 2: the underlying WordPress error */
                __('The %1$s failed: %2$s', domain: 'wppilot'),
                $operation,
                $errors->get_error_message(),
            ),
        );
    }

    if ($result === false || $result === null) {
        return new WP_Error(
            'wppilot_upgrader_failed',
            sprintf(
                /* translators: %s: the operation, e.g. "plugin install" */
                __('The %s did not complete and WordPress reported no reason.', domain: 'wppilot'),
                $operation,
            ),
        );
    }

    return null;
}

/**
 * Find the plugin file belonging to a directory slug, or an empty string.
 */
function wordpress_plugin_file_for_slug(string $slug): string
{
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    foreach (array_keys(get_plugins()) as $file) {
        if (!is_string($file)) {
            continue;
        }
        if (str_contains($file, '/') && strtok($file, '/') === $slug) {
            return $file;
        }
        // Single-file plugins live at the root as "hello.php".
        if ($file === $slug . '.php') {
            return $file;
        }
    }

    return '';
}

/** @return array<string, mixed> */
function wordpress_plugin_summary(string $file): array
{
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    /** @var array<string, mixed> $data */
    $data = get_plugins()[$file] ?? [];
    $updates = get_site_transient('update_plugins');
    $responses = is_object($updates) && is_array($updates->response ?? null) ? $updates->response : [];

    return [
        'file' => $file,
        'slug' => str_contains($file, '/') ? (string) strtok($file, '/') : basename($file, '.php'),
        'name' => (string) ($data['Name'] ?? $file),
        'version' => (string) ($data['Version'] ?? ''),
        'active' => is_plugin_active($file),
        'network_active' => is_plugin_active_for_network($file),
        'update_version' => array_key_exists($file, $responses)
            ? (string) ($responses[$file]->new_version ?? '')
            : '',
    ];
}

/** @return array<string, mixed> */
function wordpress_theme_summary(WP_Theme $theme): array
{
    $parent = $theme->parent();
    $stylesheet = $theme->get_stylesheet();
    $updates = get_site_transient('update_themes');
    $responses = is_object($updates) && is_array($updates->response ?? null) ? $updates->response : [];

    return [
        'stylesheet' => $stylesheet,
        'name' => (string) $theme->get('Name'),
        'version' => (string) $theme->get('Version'),
        'active' => $stylesheet === get_stylesheet(),
        'parent' => $parent instanceof WP_Theme ? $parent->get_stylesheet() : '',
        'block_theme' => method_exists($theme, 'is_block_theme') && $theme->is_block_theme(),
        'update_version' => array_key_exists($stylesheet, $responses)
            ? (string) ($responses[$stylesheet]['new_version'] ?? '')
            : '',
    ];
}

/**
 * Flatten a directory API record, which arrives as an object or an array
 * depending on the endpoint and the requested fields.
 *
 * @return array<string, mixed>
 */
function wordpress_extension_to_array(mixed $record): array
{
    if (is_object($record)) {
        return get_object_vars($record);
    }

    /** @var array<string, mixed> $normalized */
    $normalized = is_array($record) ? $record : [];

    return $normalized;
}

/**
 * The subset of a WordPress.org record worth spending an agent's context on.
 *
 * Descriptions and changelog sections are deliberately excluded: they are long,
 * and the decision an agent makes here (install this or not) turns on identity,
 * requirements, and how maintained the extension is.
 *
 * @param array<string, mixed> $record
 * @return array<string, mixed>
 */
function wordpress_directory_summary(array $record): array
{
    return [
        'slug' => wordpress_directory_text($record['slug'] ?? ''),
        'name' => wp_strip_all_tags(wordpress_directory_text($record['name'] ?? '')),
        'version' => wordpress_directory_text($record['version'] ?? ''),
        'author' => wp_strip_all_tags(wordpress_directory_author($record['author'] ?? '')),
        'short_description' => wp_strip_all_tags(wordpress_directory_text($record['short_description'] ?? '')),
        'rating' => (float) ($record['rating'] ?? 0),
        'num_ratings' => (int) ($record['num_ratings'] ?? 0),
        // Themes report `downloaded` where plugins report `active_installs`.
        'active_installs' => (int) ($record['active_installs'] ?? $record['downloaded'] ?? 0),
        'last_updated' => wordpress_directory_text($record['last_updated'] ?? ''),
        'requires' => wordpress_directory_text($record['requires'] ?? ''),
        'requires_php' => wordpress_directory_text($record['requires_php'] ?? ''),
        'homepage' => wordpress_directory_text($record['homepage'] ?? ''),
    ];
}

/**
 * Flatten one directory field to a string.
 *
 * The two endpoints disagree about types for the same field. `requires` comes
 * back as `false` when an extension declares no minimum, and the themes
 * endpoint answers with arrays where the plugins endpoint answers with strings.
 * Casting those directly emits an "Array to string conversion" warning and
 * hands the agent the literal word "Array".
 */
function wordpress_directory_text(mixed $value): string
{
    if (is_string($value)) {
        return $value;
    }
    // A "declared nothing" field is false, which must read as empty rather than
    // as the "" / "1" a bool cast produces.
    if (is_bool($value) || $value === null) {
        return '';
    }

    return is_scalar($value) ? (string) $value : '';
}

/**
 * Resolve the author field.
 *
 * The plugins endpoint returns an HTML link; the themes endpoint returns a
 * record of the author's WordPress.org profile.
 */
function wordpress_directory_author(mixed $value): string
{
    if (!is_array($value) && !is_object($value)) {
        return wordpress_directory_text($value);
    }

    $author = wordpress_extension_to_array($value);
    foreach (['display_name', 'author', 'user_nicename', 'author_name'] as $key) {
        $candidate = wordpress_directory_text($author[$key] ?? '');
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}
