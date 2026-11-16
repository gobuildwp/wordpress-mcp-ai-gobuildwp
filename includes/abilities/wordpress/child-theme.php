<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Abilities\WordPress;

use WP_Error;
use WP_Theme;

/**
 * wppilot/create-child-theme. The files and the undo are in includes/child-theme.php.
 *
 * Creating the theme only adds a folder nobody sees until the theme is activated, so it is an
 * ordinary write. Activating it changes every page on the front end, so `activate` needs
 * `confirm: true`, the same bar wppilot/switch-theme sets. The ability is not annotated
 * destructive, because then every call would need confirming, including the harmless ones.
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once dirname(__DIR__, 2) . '/child-theme.php';

register_core_ability('wppilot/create-child-theme', [
    'label' => __('Create Child Theme', domain: 'wppilot'),
    'description' => __(
        'Creates a child theme of an installed theme, so the site can be customised without the changes being lost when the parent updates. Writes a new folder in wp-content/themes with style.css (whose Template header names the parent) and a functions.php that loads the parent\'s stylesheet, and copies the parent\'s screenshot unless copy_screenshot is false. Refuses when the folder already exists, and when the parent is itself a child theme. The child is NOT activated unless activate is true, and activating needs confirm: true because it changes every page on the front end. Recorded in the change ledger: undoing it switches back to the previous theme (when this call activated the child) and deletes the folder, and is refused while the folder holds files or edits added since.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'parent' => ['type' => 'string', 'minLength' => 1, 'description' => 'Stylesheet directory of the installed parent theme.'],
            'slug' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => 'Folder name for the child. Defaults to "<parent>-child".'],
            'name' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Theme Name header. Defaults to "<Parent Name> Child".'],
            'description' => ['type' => 'string', 'maxLength' => 200],
            'copy_screenshot' => ['type' => 'boolean', 'default' => true],
            'activate' => ['type' => 'boolean', 'default' => false],
            'confirm' => ['type' => 'boolean', 'default' => false, 'description' => 'Required with activate: true.'],
        ],
        'required' => ['parent'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => __NAMESPACE__ . '\\wordpress_create_child_theme',
    'permission_callback' => static fn(): bool => wordpress_core_permission_for('install_themes'),
    'meta' => wordpress_core_mcp_meta(readonly: false, destructive: false, idempotent: false),
]);

/** @param array<string, mixed> $input @return array<string, mixed>|WP_Error */
// @mago-expect lint:cyclomatic-complexity -- Each refusal is checked before anything is written.
function wordpress_create_child_theme(array $input): array|WP_Error
{
    $parent_slug = wordpress_extension_slug((string) ($input['parent'] ?? ''));
    if ($parent_slug === '') {
        return wordpress_extension_invalid_slug('parent');
    }
    $parent = wp_get_theme($parent_slug);
    if (!$parent->exists()) {
        return new WP_Error('wppilot_theme_not_found', sprintf(
            /* translators: %s: theme stylesheet directory */
            __('Theme "%s" is not installed.', domain: 'wppilot'),
            $parent_slug,
        ), ['status' => 404]);
    }
    $grandparent = $parent->parent();
    if ($grandparent instanceof WP_Theme) {
        return new WP_Error('wppilot_child_theme_of_child', sprintf(
            /* translators: 1: requested parent, 2: its own parent */
            __('"%1$s" is itself a child theme of "%2$s", and WordPress does not support a child of a child. Use "%2$s" as the parent, or edit "%1$s" directly.', domain: 'wppilot'),
            $parent_slug,
            $grandparent->get_stylesheet(),
        ), ['status' => 409]);
    }
    if ($parent->errors() instanceof WP_Error) {
        return new WP_Error('wppilot_theme_broken', sprintf(
            /* translators: 1: theme, 2: WordPress error */
            __('Theme "%1$s" reports an error, so a child of it would not load: %2$s', domain: 'wppilot'),
            $parent_slug,
            $parent->errors()->get_error_message(),
        ), ['status' => 409]);
    }

    $slug = isset($input['slug']) ? wordpress_extension_slug((string) $input['slug']) : $parent_slug . '-child';
    if ($slug === '') {
        return wordpress_extension_invalid_slug('slug');
    }
    if (wp_get_theme($slug)->exists()) {
        return new WP_Error('wppilot_child_theme_exists', sprintf(
            /* translators: %s: theme stylesheet directory */
            __('A theme named "%s" is already installed. Nothing was written. Pick another slug.', domain: 'wppilot'),
            $slug,
        ), ['status' => 409]);
    }

    $activate = ($input['activate'] ?? false) === true;
    if ($activate && ($input['confirm'] ?? false) !== true) {
        return new WP_Error('wppilot_confirmation_required', sprintf(
            /* translators: 1: child theme slug, 2: active theme */
            __('Activating "%1$s" replaces "%2$s" as the theme every visitor sees, and drops widget and menu-location assignments the new theme has no place for. Nothing was written. Call again with confirm: true to create and activate it, or without activate to only create it.', domain: 'wppilot'),
            $slug,
            get_stylesheet(),
        ), ['status' => 428]);
    }
    if ($activate && !current_user_can('switch_themes')) {
        return new WP_Error('wppilot_forbidden', __('You can create themes but not switch the active theme.', domain: 'wppilot'), ['status' => 403]);
    }
    $blocked = wordpress_file_mod_error('install_theme');
    if ($blocked !== null) {
        return $blocked;
    }

    $parent_name = (string) $parent->get('Name');
    $files = \wppilot_child_theme_files([
        'slug' => $slug,
        'name' => trim((string) ($input['name'] ?? '')) !== '' ? (string) $input['name'] : $parent_name . ' Child',
        'parent' => $parent_slug,
        'parent_name' => $parent_name,
        'description' => (string) ($input['description'] ?? ''),
    ]);
    $screenshot = ($input['copy_screenshot'] ?? true) !== false
        ? \wppilot_child_theme_screenshot($parent->get_stylesheet_directory())
        : null;
    $previous = ['stylesheet' => get_stylesheet(), 'template' => get_template()];

    $created = \wppilot_child_theme_create(get_theme_root(), $slug, $files, $screenshot);
    if ($created instanceof WP_Error) {
        return $created;
    }
    wp_clean_themes_cache();

    $child = wp_get_theme($slug);
    if (!$child->exists() || $child->errors() instanceof WP_Error) {
        \wppilot_child_theme_delete_files($created['dir'], array_keys($created['files']));
        wp_clean_themes_cache();
        return new WP_Error('wppilot_child_theme_unreadable', __(
            'The child theme was written but WordPress could not load it, so it was removed again. The themes directory may be on a path WordPress does not scan.',
            domain: 'wppilot',
        ), ['status' => 500]);
    }

    $activated = false;
    if ($activate) {
        switch_theme($slug);
        $activated = get_stylesheet() === $slug;
    }

    $result = [
        'result' => 'created',
        'stylesheet' => $slug,
        'parent' => $parent_slug,
        'path' => 'wp-content/themes/' . $slug,
        'files' => array_keys($created['files']),
        'activated' => $activated,
        'previous_theme' => $previous['stylesheet'],
    ];
    if ($activate && !$activated) {
        $result['warning'] = 'The child theme was created, but WordPress did not switch to it. Try wppilot/switch-theme.';
    } elseif (!$activate) {
        $result['next_step'] = 'Call wppilot/switch-theme with this "stylesheet" value when the site is ready for it.';
    }

    if (function_exists('wppilot_ledger_record_items')) {
        $ledger = \wppilot_ledger_record_items('wppilot/create-child-theme', [[
            'input' => $input,
            'before' => [
                'type' => WPPILOT_CHILD_THEME_TYPE,
                'slug' => $slug,
                'dir' => $created['dir'],
                'files' => $created['files'],
                'activated' => $activated,
                'previous' => $previous,
            ],
            'result' => $result,
        ]]);
        $result['change_id'] = $ledger['change_ids'][0] ?? '';
    }

    return $result;
}
