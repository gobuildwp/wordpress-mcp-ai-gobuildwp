<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\WordPress;

use WP_Error;
use WP_Post;
use WP_User;

if (!defined('ABSPATH')) {
    exit();
}

register_core_ability('wppilot/list-users', [
    'label' => __('List Users', domain: 'wppilot'),
    'description' => __(
        'Lists WordPress users with bounded pagination, role/search filters, capabilities, and safe profile fields. Passwords and secrets are never returned.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'search' => ['type' => 'string', 'default' => ''],
            'role' => ['type' => 'string', 'default' => ''],
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_list_users',
    'permission_callback' => static fn(): bool => current_user_can('list_users'),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/get-user', [
    'label' => __('Get User', domain: 'wppilot'),
    'description' => __(
        'Returns a WordPress user by ID with roles, safe profile fields, and public capability names. Password hashes, sessions, application passwords, and private user meta are never returned.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['user_id' => ['type' => 'integer', 'minimum' => 1]],
        'required' => ['user_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_get_user',
    'permission_callback' => static fn(): bool => current_user_can('list_users'),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/create-user', [
    'label' => __('Create User', domain: 'wppilot'),
    'description' => __(
        'Creates a WordPress account with an explicit role. Critical account creation is blocked by Production Safe and requires explicit confirmation in Developer Full Access.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'username' => ['type' => 'string', 'minLength' => 1],
            'email' => ['type' => 'string', 'format' => 'email'],
            'password' => ['type' => 'string', 'minLength' => 12],
            'role' => ['type' => 'string'],
            'display_name' => ['type' => 'string'],
            'first_name' => ['type' => 'string'],
            'last_name' => ['type' => 'string'],
        ],
        'required' => ['username', 'email', 'role'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_create_user',
    'permission_callback' => static fn(): bool => current_user_can('create_users') && current_user_can('promote_users'),
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true, idempotent: false),
]);

register_core_ability('wppilot/update-user', [
    'label' => __('Update User', domain: 'wppilot'),
    'description' => __(
        'Partially updates a WordPress account profile, email, password, or role. Critical account changes are blocked by Production Safe and require explicit confirmation in Developer Full Access.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'user_id' => ['type' => 'integer', 'minimum' => 1],
            'email' => ['type' => 'string', 'format' => 'email'],
            'password' => ['type' => 'string', 'minLength' => 12],
            'role' => ['type' => 'string'],
            'display_name' => ['type' => 'string'],
            'first_name' => ['type' => 'string'],
            'last_name' => ['type' => 'string'],
        ],
        'required' => ['user_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_update_user',
    'permission_callback' => __NAMESPACE__ . '\\wordpress_update_user_permission',
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true),
]);

register_core_ability('wppilot/get-site-settings', [
    'label' => __('Get Site Settings', domain: 'wppilot'),
    'description' => __(
        'Returns a curated, non-secret set of WordPress reading, discussion, locale, date, URL, and front-page settings.',
        domain: 'wppilot',
    ),
    'input_schema' => WPPILOT_NO_INPUT_SCHEMA,
    'category' => 'wordpress',
    'execute_callback' => __NAMESPACE__ . '\\wordpress_get_site_settings',
    'permission_callback' => static fn(): bool => current_user_can('manage_options'),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/update-site-settings', [
    'label' => __('Update Site Settings', domain: 'wppilot'),
    'description' => __(
        'Partially updates an allowlisted set of ordinary WordPress settings. Secrets, arbitrary options, rewrite internals, and authentication settings are not accepted.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'blogname' => ['type' => 'string'],
            'blogdescription' => ['type' => 'string'],
            'timezone_string' => ['type' => 'string'],
            'date_format' => ['type' => 'string'],
            'time_format' => ['type' => 'string'],
            'start_of_week' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 6],
            'posts_per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'show_on_front' => ['type' => 'string', 'enum' => ['posts', 'page']],
            'page_on_front' => ['type' => 'integer', 'minimum' => 0],
            'page_for_posts' => ['type' => 'integer', 'minimum' => 0],
            'default_comment_status' => ['type' => 'string', 'enum' => ['open', 'closed']],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_update_site_settings',
    'permission_callback' => static fn(): bool => current_user_can('manage_options'),
    'meta' => wordpress_core_mcp_meta(readonly: false),
]);

register_core_ability('wppilot/list-extensions', [
    'label' => __('List Plugins and Themes', domain: 'wppilot'),
    'description' => __(
        'Lists installed plugins and themes with active state, versions, update availability, network activation, and current theme identity. Read-only and does not expose filesystem contents.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => ['type' => 'object', 'default' => [], 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_list_extensions',
    'permission_callback' => static fn(): bool => (
        current_user_can('activate_plugins') || current_user_can('switch_themes')
    ),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/list-menus', [
    'label' => __('List Navigation Menus', domain: 'wppilot'),
    'description' => __(
        'Lists classic WordPress navigation menus and their registered theme locations.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => ['type' => 'object', 'default' => [], 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_list_menus',
    'permission_callback' => static fn(): bool => current_user_can('edit_theme_options'),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/list-menu-items', [
    'label' => __('List Navigation Menu Items', domain: 'wppilot'),
    'description' => __(
        'Lists one classic navigation menu as an ordered flat collection with parent relationships, object targets, URLs, labels, and CSS classes.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['menu_id' => ['type' => 'integer', 'minimum' => 1]],
        'required' => ['menu_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_list_menu_items',
    'permission_callback' => static fn(): bool => current_user_can('edit_theme_options'),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/upsert-menu-item', [
    'label' => __('Create or Update Menu Item', domain: 'wppilot'),
    'description' => __(
        'Creates or partially updates a classic navigation menu item. Supports custom URLs and post, page, taxonomy, or other registered object targets.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'menu_id' => ['type' => 'integer', 'minimum' => 1],
            'item_id' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            'title' => ['type' => 'string'],
            'url' => ['type' => 'string'],
            'object' => ['type' => 'string'],
            'object_id' => ['type' => 'integer', 'minimum' => 0],
            'type' => ['type' => 'string', 'enum' => ['custom', 'post_type', 'taxonomy', 'post_type_archive']],
            'parent' => ['type' => 'integer', 'minimum' => 0],
            'position' => ['type' => 'integer', 'minimum' => 0],
            'target' => ['type' => 'string'],
            'classes' => ['type' => 'array', 'items' => ['type' => 'string']],
            'status' => ['type' => 'string', 'enum' => ['publish', 'draft']],
        ],
        'required' => ['menu_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_upsert_menu_item',
    'permission_callback' => static fn(): bool => current_user_can('edit_theme_options'),
    'meta' => wordpress_core_mcp_meta(readonly: false, idempotent: false),
]);

register_core_ability('wppilot/delete-menu-item', [
    'label' => __('Delete Menu Item', domain: 'wppilot'),
    'description' => __(
        'Permanently deletes a classic navigation menu item. Requires explicit confirmation through GoBuildWP safety enforcement.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['item_id' => ['type' => 'integer', 'minimum' => 1]],
        'required' => ['item_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_delete_menu_item',
    'permission_callback' => static fn(): bool => current_user_can('edit_theme_options'),
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true, idempotent: false),
]);

/** @param array<string, mixed> $input @return array<string, mixed> */
function wordpress_list_users(array $input): array
{
    $per_page = min(100, max(1, (int) ($input['per_page'] ?? 20)));
    $page = max(1, (int) ($input['page'] ?? 1));
    $args = [
        'number' => $per_page,
        'offset' => ($page - 1) * $per_page,
        'count_total' => true,
        'orderby' => 'registered',
        'order' => 'DESC',
    ];
    if (trim((string) ($input['search'] ?? '')) !== '') {
        $args['search'] = '*' . sanitize_text_field((string) $input['search']) . '*';
        $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
    }
    if (trim((string) ($input['role'] ?? '')) !== '') {
        $args['role'] = sanitize_key((string) $input['role']);
    }
    $query = new \WP_User_Query($args);
    return [
        'items' => array_map(__NAMESPACE__ . '\\wordpress_user_summary', $query->get_results()),
        'page' => $page,
        'per_page' => $per_page,
        'total' => (int) $query->get_total(),
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_get_user(array $input): array|WP_Error
{
    $user = get_userdata((int) $input['user_id']);
    return $user instanceof WP_User
        ? wordpress_user_summary($user, include_capabilities: true)
        : new WP_Error('wppilot_user_not_found', __('User not found.', domain: 'wppilot'));
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_create_user(array $input): array|WP_Error
{
    $role = sanitize_key((string) $input['role']);
    if (!array_key_exists($role, wp_roles()->roles)) {
        return new WP_Error('wppilot_invalid_role', __('The requested role is not registered.', domain: 'wppilot'));
    }
    $generated = !array_key_exists('password', $input);
    $password = $generated
        ? wp_generate_password(24, special_chars: true, extra_special_chars: true)
        : (string) $input['password'];
    $user_id = wp_insert_user([
        'user_login' => sanitize_user((string) $input['username'], strict: true),
        'user_email' => sanitize_email((string) $input['email']),
        'user_pass' => $password,
        'role' => $role,
        'display_name' => sanitize_text_field((string) ($input['display_name'] ?? $input['username'])),
        'first_name' => sanitize_text_field((string) ($input['first_name'] ?? '')),
        'last_name' => sanitize_text_field((string) ($input['last_name'] ?? '')),
    ]);
    if (is_wp_error($user_id)) {
        return $user_id;
    }
    $user = get_userdata((int) $user_id);
    $result = $user instanceof WP_User ? wordpress_user_summary($user) : ['id' => (int) $user_id];
    $result['generated_password'] = $generated ? $password : '';
    $result['password_was_generated'] = $generated;
    return $result;
}

/** @param array<string, mixed> $input */
function wordpress_update_user_permission(array $input): bool
{
    $user_id = (int) ($input['user_id'] ?? 0);
    return (
        $user_id > 0
        && current_user_can('edit_user', $user_id)
        && (!array_key_exists('role', $input) || current_user_can('promote_users'))
    );
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_update_user(array $input): array|WP_Error
{
    $user_id = (int) $input['user_id'];
    if (!get_userdata($user_id) instanceof WP_User) {
        return new WP_Error('wppilot_user_not_found', __('User not found.', domain: 'wppilot'));
    }
    $data = ['ID' => $user_id];
    $field_map = [
        'email' => 'user_email',
        // @mago-expect lint:no-literal-password -- This is a user-field name, never a credential value.
        'password' => 'user_pass',
        'display_name' => 'display_name',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
    ];
    foreach ($field_map as $source => $target) {
        if (!array_key_exists($source, $input)) {
            continue;
        }

        $data[$target] = $source === 'email' ? sanitize_email((string) $input[$source]) : (string) $input[$source];
    }
    if (array_key_exists('role', $input)) {
        $role = sanitize_key((string) $input['role']);
        if (!array_key_exists($role, wp_roles()->roles)) {
            return new WP_Error('wppilot_invalid_role', __(
                'The requested role is not registered.',
                domain: 'wppilot',
            ));
        }
        $data['role'] = $role;
    }
    $updated = wp_update_user($data);
    if (is_wp_error($updated)) {
        return $updated;
    }
    $user = get_userdata($user_id);
    return $user instanceof WP_User ? wordpress_user_summary($user) : ['id' => $user_id];
}

/** @return array<string, mixed> */
function wordpress_user_summary(WP_User $user, bool $include_capabilities = false): array
{
    // Privacy-minimized by default. Display name, avatar, description and
    // profile URL are already public on a WordPress site; login name, email
    // address and registration date are not, and an agent summarising a site
    // has no need for them. They are released only to an account that can
    // already read them on the Users screen.
    $result = [
        'id' => $user->ID,
        'display_name' => $user->display_name,
        'description' => (string) $user->description,
        'url' => (string) $user->user_url,
        'avatar_url' => (string) get_avatar_url($user->ID),
        'posts_url' => (string) get_author_posts_url($user->ID),
    ];

    if (!current_user_can('list_users')) {
        return $result;
    }

    // Roles describe privilege on this site, so they follow the same gate.
    $result['username'] = $user->user_login;
    $result['first_name'] = $user->first_name;
    $result['last_name'] = $user->last_name;
    $result['roles'] = array_values($user->roles);
    $result['registered'] = $user->user_registered;

    // An email address is the most re-identifying field WordPress stores, so it
    // needs the capability that governs editing accounts, not merely listing
    // them.
    if (current_user_can('edit_users')) {
        $result['email'] = $user->user_email;
    }

    if ($include_capabilities) {
        $result['capabilities'] = array_values(array_keys(array_filter($user->allcaps)));
    }

    return $result;
}

/** @return array<string, mixed> */
function wordpress_get_site_settings(): array
{
    $result = [];
    foreach (array_keys(wordpress_setting_sanitizers()) as $key) {
        $result[$key] = get_option($key);
    }
    $result['home_url'] = home_url('/');
    $result['site_url'] = site_url('/');
    $result['locale'] = get_locale();
    return $result;
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_update_site_settings(array $input): array|WP_Error
{
    $invalid = wordpress_validate_site_settings($input);
    if ($invalid instanceof WP_Error) {
        return $invalid;
    }

    $sanitizers = wordpress_setting_sanitizers();
    $updated = [];
    foreach ($input as $key => $value) {
        if (!array_key_exists($key, $sanitizers)) {
            continue;
        }
        $clean = $sanitizers[$key]($value);
        update_option($key, $clean);
        $updated[$key] = get_option($key);
    }
    return ['updated' => $updated, 'settings' => wordpress_get_site_settings()];
}

/** @param array<string, mixed> $input */
function wordpress_validate_site_settings(array $input): ?WP_Error
{
    $sanitizers = wordpress_setting_sanitizers();
    $effective = [];
    foreach (['show_on_front', 'page_on_front', 'page_for_posts'] as $key) {
        $value = array_key_exists($key, $input) ? $input[$key] : get_option($key);
        $effective[$key] = $sanitizers[$key]($value);
    }

    if (array_key_exists('timezone_string', $input)) {
        // An identifier core cannot resolve makes wp_timezone() throw from
        // new DateTimeZone(), which fatals every request that formats a date.
        $timezone = $sanitizers['timezone_string']($input['timezone_string']);
        if ($timezone !== '' && !in_array($timezone, timezone_identifiers_list(), strict: true)) {
            return new WP_Error(
                'wppilot_invalid_timezone',
                'timezone_string must be a PHP timezone identifier, such as Europe/Copenhagen.',
                ['status' => 422, 'setting' => 'timezone_string', 'value' => $timezone],
            );
        }
    }

    foreach (['page_on_front', 'page_for_posts'] as $key) {
        $post_id = (int) $effective[$key];
        if ($post_id === 0) {
            continue;
        }
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || $post->post_type !== 'page' || $post->post_status !== 'publish') {
            return new WP_Error(
                'wppilot_invalid_reading_page',
                sprintf('%s must identify an existing published page.', $key),
                ['status' => 422, 'setting' => $key, 'post_id' => $post_id],
            );
        }
    }

    if ($effective['show_on_front'] === 'page' && (int) $effective['page_on_front'] === 0) {
        return new WP_Error(
            'wppilot_front_page_required',
            'page_on_front must identify a published page when show_on_front is page.',
            ['status' => 422],
        );
    }
    if ((int) $effective['page_on_front'] > 0 && $effective['page_on_front'] === $effective['page_for_posts']) {
        return new WP_Error(
            'wppilot_reading_pages_conflict',
            'page_on_front and page_for_posts must be different pages.',
            ['status' => 422],
        );
    }

    return null;
}

/** @return array<string, callable(mixed): mixed> */
function wordpress_setting_sanitizers(): array
{
    return [
        'blogname' => static fn(mixed $v): string => sanitize_text_field((string) $v),
        'blogdescription' => static fn(mixed $v): string => sanitize_text_field((string) $v),
        'timezone_string' => static fn(mixed $v): string => sanitize_text_field((string) $v),
        'date_format' => static fn(mixed $v): string => sanitize_text_field((string) $v),
        'time_format' => static fn(mixed $v): string => sanitize_text_field((string) $v),
        'start_of_week' => static fn(mixed $v): int => min(6, max(0, (int) $v)),
        'posts_per_page' => static fn(mixed $v): int => min(100, max(1, (int) $v)),
        'show_on_front' => static fn(mixed $v): string => (string) $v === 'page' ? 'page' : 'posts',
        'page_on_front' => static fn(mixed $v): int => max(0, (int) $v),
        'page_for_posts' => static fn(mixed $v): int => max(0, (int) $v),
        'default_comment_status' => static fn(mixed $v): string => (string) $v === 'open' ? 'open' : 'closed',
    ];
}

/** @return array<string, mixed> */
function wordpress_list_extensions(): array
{
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $updates = get_site_transient('update_plugins');
    $plugin_updates = is_object($updates) && is_array($updates->response ?? null) ? $updates->response : [];
    $plugins = [];
    foreach (get_plugins() as $file => $data) {
        if (!is_string($file)) {
            continue;
        }
        $plugins[] = [
            'file' => $file,
            'name' => (string) ($data['Name'] ?? $file),
            'version' => (string) ($data['Version'] ?? ''),
            'active' => is_plugin_active($file),
            'network_active' => is_plugin_active_for_network($file),
            'update_version' => array_key_exists($file, $plugin_updates)
                ? (string) ($plugin_updates[$file]->new_version ?? '')
                : '',
        ];
    }
    $themes = [];
    $active = get_stylesheet();
    foreach (wp_get_themes() as $slug => $theme) {
        $parent = $theme->parent();
        $themes[] = [
            'slug' => $slug,
            'name' => $theme->get('Name'),
            'version' => $theme->get('Version'),
            'active' => $slug === $active,
            'parent' => $parent !== false ? $parent->get_stylesheet() : '',
        ];
    }
    return ['plugins' => $plugins, 'themes' => $themes, 'active_theme' => $active];
}

/** @return array<string, mixed> */
function wordpress_list_menus(): array
{
    $locations = get_nav_menu_locations();
    return [
        'menus' => array_map(static fn($menu): array => [
            'id' => (int) $menu->term_id,
            'name' => (string) $menu->name,
            'slug' => (string) $menu->slug,
            'count' => (int) $menu->count,
            'locations' => array_values(array_keys($locations, (int) $menu->term_id, strict: true)),
        ], wp_get_nav_menus()),
        'registered_locations' => get_registered_nav_menus(),
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_list_menu_items(array $input): array|WP_Error
{
    $menu = wp_get_nav_menu_object((int) $input['menu_id']);
    if ($menu === false) {
        return new WP_Error('wppilot_menu_not_found', __('Navigation menu not found.', domain: 'wppilot'));
    }
    $items = wp_get_nav_menu_items($menu->term_id, ['post_status' => 'any']);
    return [
        'menu' => ['id' => (int) $menu->term_id, 'name' => (string) $menu->name],
        'items' => array_map(__NAMESPACE__ . '\\wordpress_menu_item_summary', is_array($items) ? $items : []),
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_upsert_menu_item(array $input): array|WP_Error
{
    $menu_id = (int) $input['menu_id'];
    if (wp_get_nav_menu_object($menu_id) === false) {
        return new WP_Error('wppilot_menu_not_found', __('Navigation menu not found.', domain: 'wppilot'));
    }
    $item_id = (int) ($input['item_id'] ?? 0);

    // A menu item's URL is rendered straight into an href, so a javascript: or
    // data: value here is stored XSS. Validated before anything is written.
    if (array_key_exists('url', $input)) {
        $unsafe = wordpress_unsafe_url_error((string) $input['url']);
        if ($unsafe !== null) {
            return $unsafe;
        }
    }

    $args = [];
    if ($item_id > 0) {
        $item = get_post($item_id);
        if (!$item instanceof WP_Post || $item->post_type !== 'nav_menu_item') {
            return new WP_Error(
                'wppilot_menu_item_not_found',
                __('Navigation menu item not found.', domain: 'wppilot'),
                ['status' => 404],
            );
        }
        $membership = is_object_in_term($item_id, 'nav_menu', $menu_id);
        if (is_wp_error($membership)) {
            return $membership;
        }
        if ($membership !== true) {
            return new WP_Error(
                'wppilot_menu_item_wrong_menu',
                __('The navigation menu item does not belong to the requested menu.', domain: 'wppilot'),
                ['status' => 409, 'item_id' => $item_id, 'menu_id' => $menu_id],
            );
        }
        $args = wordpress_existing_menu_item_args($item);
    }

    foreach ([
        'title' => 'menu-item-title',
        'url' => 'menu-item-url',
        'object' => 'menu-item-object',
        'type' => 'menu-item-type',
        'target' => 'menu-item-target',
        'status' => 'menu-item-status',
    ] as $source => $target) {
        if (!array_key_exists($source, $input)) {
            continue;
        }

        $value = (string) $input[$source];
        $args[$target] = $source === 'title' ? wp_slash($value) : $value;
    }
    foreach ([
        'object_id' => 'menu-item-object-id',
        'parent' => 'menu-item-parent-id',
        'position' => 'menu-item-position',
    ] as $source => $target) {
        if (!array_key_exists($source, $input)) {
            continue;
        }

        $args[$target] = (int) $input[$source];
    }
    if (array_key_exists('classes', $input)) {
        $args['menu-item-classes'] = implode(' ', array_filter(array_map(
            static fn(mixed $class): string => sanitize_html_class((string) $class),
            (array) $input['classes'],
        )));
    }

    $parent_id = (int) ($args['menu-item-parent-id'] ?? 0);
    if ($parent_id > 0) {
        if ($parent_id === $item_id) {
            return new WP_Error(
                'wppilot_menu_item_parent_invalid',
                __('A navigation menu item cannot be its own parent.', domain: 'wppilot'),
                ['status' => 422],
            );
        }
        $parent = get_post($parent_id);
        $parent_membership = $parent instanceof WP_Post && $parent->post_type === 'nav_menu_item'
            ? is_object_in_term($parent_id, 'nav_menu', $menu_id)
            : false;
        if (is_wp_error($parent_membership)) {
            return $parent_membership;
        }
        if ($parent_membership !== true) {
            return new WP_Error(
                'wppilot_menu_item_parent_wrong_menu',
                __('The parent navigation item does not belong to the requested menu.', domain: 'wppilot'),
                ['status' => 422, 'parent_id' => $parent_id, 'menu_id' => $menu_id],
            );
        }
    }
    if ($item_id === 0) {
        $args['menu-item-status'] ??= 'publish';
    }
    $updated = wp_update_nav_menu_item($menu_id, $item_id, $args);
    if (is_wp_error($updated)) {
        return $updated;
    }
    $item = get_post((int) $updated);
    return $item instanceof WP_Post ? wordpress_menu_item_summary($item) : ['id' => (int) $updated];
}

/** @return array<string, mixed> */
function wordpress_existing_menu_item_args(WP_Post $item): array
{
    $classes = get_post_meta($item->ID, '_menu_item_classes', single: true);
    return [
        'menu-item-object-id' => (int) get_post_meta($item->ID, '_menu_item_object_id', single: true),
        'menu-item-object' => (string) get_post_meta($item->ID, '_menu_item_object', single: true),
        'menu-item-parent-id' => (int) get_post_meta($item->ID, '_menu_item_menu_item_parent', single: true),
        'menu-item-position' => (int) $item->menu_order,
        'menu-item-type' => (string) get_post_meta($item->ID, '_menu_item_type', single: true),
        'menu-item-title' => wp_slash($item->post_title),
        'menu-item-url' => (string) get_post_meta($item->ID, '_menu_item_url', single: true),
        'menu-item-description' => wp_slash($item->post_content),
        'menu-item-attr-title' => wp_slash($item->post_excerpt),
        'menu-item-target' => (string) get_post_meta($item->ID, '_menu_item_target', single: true),
        'menu-item-classes' => implode(' ', array_filter(array_map(
            static fn(mixed $class): string => sanitize_html_class((string) $class),
            is_array($classes) ? $classes : (preg_split('/\s+/', (string) $classes) ?: []),
        ))),
        'menu-item-xfn' => (string) get_post_meta($item->ID, '_menu_item_xfn', single: true),
        'menu-item-status' => $item->post_status,
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_delete_menu_item(array $input): array|WP_Error
{
    $item_id = (int) $input['item_id'];
    $item = get_post($item_id);
    if (!$item instanceof WP_Post || $item->post_type !== 'nav_menu_item') {
        return new WP_Error('wppilot_menu_item_not_found', __(
            'Navigation menu item not found.',
            domain: 'wppilot',
        ));
    }
    if (wp_delete_post($item_id, force_delete: true) === false) {
        return new WP_Error('wppilot_menu_item_delete_failed', __(
            'WordPress could not delete the menu item.',
            domain: 'wppilot',
        ));
    }
    return ['item_id' => $item_id, 'deleted' => true];
}

/** @return array<string, mixed> */
function wordpress_menu_item_summary(object $item): array
{
    if ($item instanceof \WP_Post) {
        $item = wp_setup_nav_menu_item($item);
    }
    return [
        'id' => (int) $item->ID,
        'title' => (string) $item->title,
        'url' => (string) $item->url,
        'type' => (string) $item->type,
        'object' => (string) $item->object,
        'object_id' => (int) $item->object_id,
        'parent' => (int) $item->menu_item_parent,
        'position' => (int) $item->menu_order,
        'target' => (string) $item->target,
        'classes' => array_values(array_filter(array: (array) $item->classes, callback: 'is_string')),
    ];
}
