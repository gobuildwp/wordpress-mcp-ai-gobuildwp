<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BlockTheme;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/list-navigation-menus', [
    'label' => __('List Navigation Menus', domain: 'wppilot'),
    'description' => __(
        'Lists the block theme navigation menus (the Navigation block\'s menus, Appearance → Editor → Navigation) with their block markup: navigation-link and navigation-submenu blocks. Classic menus (Appearance → Menus) are a different thing; use the menu abilities for those.',
        domain: 'wppilot',
    ),
    'category' => 'appearance',
    'input_schema' => ['type' => 'object', 'default' => [], 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (): array|WP_Error {
        $menus = rest('GET', '/wp/v2/navigation', ['context' => 'edit', 'per_page' => 100, 'status' => 'publish,draft']);
        if ($menus instanceof WP_Error) {
            return $menus;
        }

        return ['menus' => array_values(array_map(static fn(array $m): array => [
            'id' => (int) ($m['id'] ?? 0),
            'title' => raw($m['title'] ?? ''),
            'status' => (string) ($m['status'] ?? ''),
            'content' => raw($m['content'] ?? ''),
        ], array_filter($menus, 'is_array')))];
    },
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('edit_theme_options'),
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

wp_register_ability('wppilot/update-navigation-menu', [
    'label' => __('Update Navigation Menu', domain: 'wppilot'),
    'description' => __(
        'Saves new block markup for a block navigation menu, for example adding <!-- wp:navigation-link {"label":"Contact","url":"/contact/","kind":"custom"} /-->. content is the menu\'s complete markup: read it first with wppilot/list-navigation-menus. Every Navigation block that uses this menu changes. Can be undone from the change log.',
        domain: 'wppilot',
    ),
    'category' => 'appearance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'content' => ['type' => 'string'],
            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
        ],
        'required' => ['id', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input): array|WP_Error {
        $content = valid_markup($input['content'] ?? null);
        if ($content instanceof WP_Error) {
            return $content;
        }
        $params = ['content' => $content];
        if (isset($input['title'])) {
            $params['title'] = sanitize_text_field((string) $input['title']);
        }
        $saved = rest('POST', '/wp/v2/navigation/' . (int) $input['id'], $params);
        if ($saved instanceof WP_Error) {
            return $saved;
        }

        return ['id' => (int) ($saved['id'] ?? 0), 'title' => raw($saved['title'] ?? ''), 'content' => raw($saved['content'] ?? '')];
    },
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('edit_theme_options'),
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
]);
