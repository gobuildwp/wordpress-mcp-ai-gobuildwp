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

wp_register_ability('wppilot/get-global-styles', [
    'label' => __('Get Global Styles', domain: 'wppilot'),
    'description' => __(
        'Reads the site\'s Global Styles (Appearance → Editor → Styles) for the active theme: the user layer the site editor saves - settings (colour palettes, font families, sizes, spacing) and styles (colours, typography, spacing for the site, elements and blocks) - plus the theme\'s own palette and font families for reference. Change them with wppilot/update-global-styles. Works with block themes and classic themes that support theme.json.',
        domain: 'wppilot',
    ),
    'category' => 'appearance',
    'input_schema' => ['type' => 'object', 'default' => [], 'properties' => [], 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (): array|WP_Error {
        $id = global_styles_id();
        if ($id <= 0) {
            return new WP_Error('kit_block_theme_no_global_styles', 'This theme has no Global Styles to read.');
        }
        $user = rest('GET', '/wp/v2/global-styles/' . $id, ['context' => 'edit']);
        if ($user instanceof WP_Error) {
            return $user;
        }
        $theme = rest('GET', '/wp/v2/global-styles/themes/' . get_stylesheet(), ['context' => 'edit']);
        $theme_settings = $theme instanceof WP_Error ? [] : (array) ($theme['settings'] ?? []);

        return [
            'id' => $id,
            'theme' => get_stylesheet(),
            'block_theme' => wp_is_block_theme(),
            'user' => ['settings' => $user['settings'] ?? (object) [], 'styles' => $user['styles'] ?? (object) []],
            'theme_palette' => $theme_settings['color']['palette']['theme'] ?? [],
            'theme_font_families' => $theme_settings['typography']['fontFamilies']['theme'] ?? [],
        ];
    },
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('edit_theme_options'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('wppilot/update-global-styles', [
    'label' => __('Update Global Styles', domain: 'wppilot'),
    'description' => __(
        'Changes the site\'s Global Styles, the way the site editor\'s Styles panel does. settings and styles are theme.json objects (version 3), for example styles: {color: {background: "#fff"}, elements: {link: {color: {text: "var(--wp--preset--color--accent)"}}}} or settings: {color: {palette: [{slug, name, color}]}}. By default they are merged into what is saved now: keys you send replace or add, null removes a key, lists such as palettes are replaced whole. merge: false replaces the user layer outright. WordPress validates and sanitises the result; keys it does not accept are dropped and reported. Can be undone from the change log.',
        domain: 'wppilot',
    ),
    'category' => 'appearance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'settings' => ['type' => 'object', 'description' => 'theme.json settings to save.'],
            'styles' => ['type' => 'object', 'description' => 'theme.json styles to save.'],
            'merge' => ['type' => 'boolean', 'default' => true],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input): array|WP_Error {
        if (!isset($input['settings']) && !isset($input['styles'])) {
            return new WP_Error('kit_block_theme_nothing', 'Send settings, styles or both.', ['status' => 400]);
        }
        $id = global_styles_id();
        if ($id <= 0) {
            return new WP_Error('kit_block_theme_no_global_styles', 'This theme has no Global Styles to change.');
        }
        $current = rest('GET', '/wp/v2/global-styles/' . $id, ['context' => 'edit']);
        if ($current instanceof WP_Error) {
            return $current;
        }
        $merge = ($input['merge'] ?? true) !== false;
        $params = [];
        foreach (['settings', 'styles'] as $key) {
            if (!isset($input[$key])) {
                continue;
            }
            $patch = json_decode((string) wp_json_encode($input[$key]), true);
            $params[$key] = $merge ? merge(json_decode((string) wp_json_encode($current[$key] ?? []), true) ?: [], is_array($patch) ? $patch : []) : (is_array($patch) ? $patch : []);
        }
        $saved = rest('POST', '/wp/v2/global-styles/' . $id, $params);
        if ($saved instanceof WP_Error) {
            return $saved;
        }

        // What WordPress kept, compared with what was asked for: sanitising drops what theme.json does not allow.
        $dropped = [];
        foreach ($params as $key => $wanted) {
            $kept = json_decode((string) wp_json_encode($saved[$key] ?? []), true) ?: [];
            foreach (array_keys($wanted) as $top) {
                if (!array_key_exists($top, $kept)) {
                    $dropped[] = $key . '.' . $top;
                }
            }
        }

        return ['id' => $id, 'merged' => $merge, 'settings' => $saved['settings'] ?? (object) [], 'styles' => $saved['styles'] ?? (object) [], 'not_saved' => $dropped];
    },
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('edit_theme_options'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);
