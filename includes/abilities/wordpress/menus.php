<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\WordPress;

use WP_Error;
use WP_Term;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Classic navigation-menu operations that site-management.php does not cover.
 *
 * The menu item CRUD lives in site-management.php (list-menus, list-menu-items,
 * upsert-menu-item, delete-menu-item). This file adds the menu container itself,
 * theme location assignment, and bulk reordering.
 */

register_core_ability('wppilot/create-menu', [
    'label' => __('Create Menu', domain: 'wppilot'),
    'description' => __(
        'Creates an empty classic navigation menu and returns its ID. Add entries with wppilot/upsert-menu-item and put it on the site with wppilot/assign-menu-location.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string', 'minLength' => 1]],
        'required' => ['name'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_create_menu',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('edit_theme_options'),
    'meta' => wordpress_core_mcp_meta(readonly: false, idempotent: false),
]);

register_core_ability('wppilot/update-menu', [
    'label' => __('Update Menu', domain: 'wppilot'),
    'description' => __('Renames an existing classic navigation menu.', domain: 'wppilot'),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'menu_id' => ['type' => 'integer'],
            'name' => ['type' => 'string', 'minLength' => 1],
        ],
        'required' => ['menu_id', 'name'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_update_menu',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('edit_theme_options'),
    'meta' => wordpress_core_mcp_meta(readonly: false),
]);

register_core_ability('wppilot/delete-menu', [
    'label' => __('Delete Menu', domain: 'wppilot'),
    'description' => __(
        'Permanently deletes a navigation menu and every item in it, and clears any theme location it occupied. There is no trash for menus, so this cannot be undone and requires explicit confirmation.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'menu_id' => ['type' => 'integer'],
            'confirm' => ['type' => 'boolean', 'description' => 'Must be true. Not rollbackable.'],
        ],
        'required' => ['menu_id', 'confirm'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_delete_menu',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('edit_theme_options'),
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: true, idempotent: false),
]);

register_core_ability('wppilot/list-menu-locations', [
    'label' => __('List Menu Locations', domain: 'wppilot'),
    'description' => __(
        'Lists the navigation locations the active theme registers, with the human label of each and the menu currently assigned to it. Call this before assigning a menu so the location slug is known rather than guessed.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => ['type' => 'object', 'default' => [], 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_list_menu_locations',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('edit_theme_options'),
    'meta' => wordpress_core_mcp_meta(readonly: true),
]);

register_core_ability('wppilot/assign-menu-location', [
    'label' => __('Assign Menu Location', domain: 'wppilot'),
    'description' => __(
        'Puts a menu into one of the theme\'s navigation locations, or clears the location when menu_id is 0. The previous assignment map is captured first, so this is reversible from the change ledger.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'location' => ['type' => 'string'],
            'menu_id' => ['type' => 'integer', 'description' => '0 clears the location.'],
        ],
        'required' => ['location', 'menu_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_assign_menu_location',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('edit_theme_options'),
    'meta' => wordpress_core_mcp_meta(readonly: false),
]);

register_core_ability('wppilot/reorder-menu-items', [
    'label' => __('Reorder Menu Items', domain: 'wppilot'),
    'description' => __(
        'Sets the order, and optionally the nesting, of a menu\'s items in one call. Supply the item IDs in the order they should appear; each item\'s position becomes its index in the list. Items may only be re-parented to another item in the same menu.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'menu_id' => ['type' => 'integer'],
            'items' => [
                'type' => 'array',
                'maxItems' => 500,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'item_id' => ['type' => 'integer'],
                        'parent_id' => ['type' => 'integer', 'description' => '0 for a top-level item.'],
                    ],
                    'required' => ['item_id'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['menu_id', 'items'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_reorder_menu_items',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('edit_theme_options'),
    'meta' => wordpress_core_mcp_meta(readonly: false),
]);

/**
 * Load a navigation menu by ID.
 */
function wordpress_require_menu(int $menu_id): WP_Term|WP_Error
{
    $menu = wp_get_nav_menu_object($menu_id);

    return $menu instanceof WP_Term
        ? $menu
        : new WP_Error('menu_not_found', sprintf('Navigation menu %d was not found.', $menu_id), ['status' => 404]);
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_create_menu(array $input): array|WP_Error
{
    $name = trim((string) $input['name']);
    if ($name === '') {
        return new WP_Error('menu_name_required', 'A menu name is required.', ['status' => 422]);
    }
    if (wp_get_nav_menu_object($name) !== false) {
        return new WP_Error(
            'menu_exists',
            sprintf('A navigation menu named "%s" already exists.', $name),
            ['status' => 409],
        );
    }

    $menu_id = wp_create_nav_menu($name);
    if (is_wp_error($menu_id)) {
        return $menu_id;
    }

    return ['menu_id' => (int) $menu_id, 'name' => $name, 'item_count' => 0];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_update_menu(array $input): array|WP_Error
{
    $menu_id = (int) $input['menu_id'];
    $menu = wordpress_require_menu($menu_id);
    if ($menu instanceof WP_Error) {
        return $menu;
    }

    $name = trim((string) $input['name']);
    if ($name === '') {
        return new WP_Error('menu_name_required', 'A menu name is required.', ['status' => 422]);
    }

    $result = wp_update_nav_menu_object($menu_id, ['menu-name' => $name]);
    if (is_wp_error($result)) {
        return $result;
    }

    return ['menu_id' => $menu_id, 'name' => $name, 'previous_name' => (string) $menu->name];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_delete_menu(array $input): array|WP_Error
{
    if (($input['confirm'] ?? false) !== true) {
        return new WP_Error(
            'confirmation_required',
            'Deleting a menu removes every item in it and cannot be rolled back. Re-call with confirm: true only after the user has explicitly agreed.',
            ['status' => 422],
        );
    }

    $menu_id = (int) $input['menu_id'];
    $menu = wordpress_require_menu($menu_id);
    if ($menu instanceof WP_Error) {
        return $menu;
    }

    $items = wp_get_nav_menu_items($menu_id);
    $item_count = is_array($items) ? count($items) : 0;
    $name = (string) $menu->name;

    $result = wp_delete_nav_menu($menu_id);
    if (is_wp_error($result)) {
        return $result;
    }
    if ($result === false) {
        return new WP_Error(
            'delete_menu_failed',
            sprintf('Navigation menu %d could not be deleted.', $menu_id),
            ['status' => 500],
        );
    }

    return [
        'menu_id' => $menu_id,
        'name' => $name,
        'deleted_items' => $item_count,
        'result' => 'deleted',
        'reversible' => false,
    ];
}

/** @return array<string, mixed> */
function wordpress_list_menu_locations(): array
{
    $assigned = get_nav_menu_locations();
    $items = [];

    foreach (get_registered_nav_menus() as $slug => $label) {
        $menu_id = (int) ($assigned[$slug] ?? 0);
        $menu = $menu_id > 0 ? wp_get_nav_menu_object($menu_id) : false;
        $items[] = [
            'location' => (string) $slug,
            'label' => (string) $label,
            'menu_id' => $menu_id,
            'menu_name' => $menu instanceof WP_Term ? (string) $menu->name : '',
        ];
    }

    return ['items' => $items, 'count' => count($items), 'theme' => (string) get_stylesheet()];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_assign_menu_location(array $input): array|WP_Error
{
    $location = (string) $input['location'];
    $menu_id = (int) $input['menu_id'];

    if (!array_key_exists($location, get_registered_nav_menus())) {
        return new WP_Error(
            'menu_location_not_registered',
            sprintf(
                'The active theme does not register a navigation location named "%s". Call wppilot/list-menu-locations for the available slugs.',
                $location,
            ),
            ['status' => 422],
        );
    }

    if ($menu_id > 0) {
        $menu = wordpress_require_menu($menu_id);
        if ($menu instanceof WP_Error) {
            return $menu;
        }
    }

    $locations = get_nav_menu_locations();
    $previous = (int) ($locations[$location] ?? 0);

    if ($menu_id > 0) {
        $locations[$location] = $menu_id;
    } else {
        unset($locations[$location]);
    }
    set_theme_mod('nav_menu_locations', $locations);

    return [
        'location' => $location,
        'menu_id' => $menu_id,
        'previous_menu_id' => $previous,
        'cleared' => $menu_id === 0,
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
function wordpress_reorder_menu_items(array $input): array|WP_Error
{
    $menu_id = (int) $input['menu_id'];
    $menu = wordpress_require_menu($menu_id);
    if ($menu instanceof WP_Error) {
        return $menu;
    }

    // post_status "any" so draft items — which list-menu-items reports — can be
    // reordered too instead of being refused as not-in-menu.
    $existing = wp_get_nav_menu_items($menu_id, ['post_status' => 'any']);
    if (!is_array($existing)) {
        return new WP_Error(
            'menu_items_unavailable',
            sprintf('The items of menu %d could not be read.', $menu_id),
            ['status' => 500],
        );
    }

    $owned = [];
    foreach ($existing as $item) {
        $owned[(int) $item->ID] = $item;
    }

    // Validate the whole list before writing any of it: a partially applied
    // reorder leaves the menu in an order nobody asked for.
    $plan = [];
    foreach ((array) $input['items'] as $index => $raw) {
        if (!is_array($raw)) {
            return new WP_Error('invalid_item', 'Each entry of items must be an object.', ['status' => 422]);
        }
        $item_id = (int) ($raw['item_id'] ?? 0);
        if (!isset($owned[$item_id])) {
            return new WP_Error(
                'menu_item_not_in_menu',
                sprintf('Menu item %1$d does not belong to menu %2$d.', $item_id, $menu_id),
                ['status' => 422],
            );
        }
        $parent_id = (int) ($raw['parent_id'] ?? 0);
        if ($parent_id !== 0 && !isset($owned[$parent_id])) {
            return new WP_Error(
                'menu_parent_not_in_menu',
                sprintf('Parent menu item %1$d does not belong to menu %2$d.', $parent_id, $menu_id),
                ['status' => 422],
            );
        }
        if ($parent_id === $item_id) {
            return new WP_Error(
                'menu_item_parent_self',
                sprintf('Menu item %d cannot be its own parent.', $item_id),
                ['status' => 422],
            );
        }
        $plan[] = [
            'item_id' => $item_id,
            'parent_id' => $parent_id,
            'position' => (int) $index + 1,
            'set_parent' => array_key_exists('parent_id', $raw),
        ];
    }

    $updated = [];
    foreach ($plan as $step) {
        // wp_update_nav_menu_item() merges its defaults and rewrites the whole
        // item, so a position-only update would blank the label, URL, type and
        // parent of every touched item. Seed the item's existing values first,
        // the same way upsert-menu-item does.
        $args = wordpress_existing_menu_item_args($owned[$step['item_id']]);
        $args['menu-item-position'] = $step['position'];
        if ($step['set_parent']) {
            $args['menu-item-parent-id'] = $step['parent_id'];
        }
        $result = wp_update_nav_menu_item($menu_id, $step['item_id'], $args);
        if (is_wp_error($result)) {
            return new WP_Error(
                'reorder_partially_applied',
                sprintf(
                    'Menu item %1$d could not be moved: %2$s. %3$d of %4$d items were already updated, so the menu order is now inconsistent and should be re-read.',
                    $step['item_id'],
                    $result->get_error_message(),
                    count($updated),
                    count($plan),
                ),
                ['status' => 500],
            );
        }
        $updated[] = $step['item_id'];
    }

    return ['menu_id' => $menu_id, 'reordered' => $updated, 'count' => count($updated)];
}
