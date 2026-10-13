<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The server instructions handed to a connected agent.
 *
 * Describes this specific site — its languages, its builders, what is
 * safe to touch — so the agent does not have to discover it by trial.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Detect active languages from multilingual plugins (WPML, Polylang, TranslatePress).
 *
 * @return array{plugin: string, languages: string[]}|null Plugin name and language codes, or null if no multilingual plugin is active.
 */
function wppilot_get_active_languages()
{
    // Polylang. Checked before WPML because Polylang's WPML compatibility
    // layer defines icl_get_languages(), which made a Polylang site report
    // itself as WPML.
    if (function_exists('pll_languages_list')) {
        /** @var string[]|false $languages */
        $languages = pll_languages_list();
        if (is_array($languages)) {
            return ['plugin' => 'Polylang', 'languages' => $languages];
        }
    }

    // WPML.
    if (function_exists('icl_get_languages')) {
        /** @var array<string, array{language_code: string}>|false $wpml_languages */
        $wpml_languages = icl_get_languages('skip_missing=0');
        if (is_array($wpml_languages)) {
            return ['plugin' => 'WPML', 'languages' => array_column($wpml_languages, 'language_code')];
        }
    }

    // TranslatePress.
    if (class_exists('TRP_Translate_Press')) {
        /** @var array{translation-languages?: string[]} $trp_settings */
        $trp_settings = get_option('trp_settings', default_value: []);
        return ['plugin' => 'TranslatePress', 'languages' => $trp_settings['translation-languages'] ?? []];
    }

    return null;
}

/**
 * Markdown lines that report the active theme and ask the user to choose a
 * working mode before content/layout work. Page builders and block libraries
 * are intentionally not hardcoded: the AI identifies them from the
 * installed-plugins inventory above, which stays correct as new ones ship.
 *
 * @return list<string>
 */
function wppilot_build_building_context_lines(): array
{
    $theme = wp_get_theme();
    $theme_desc = $theme->get('Name');
    if ($theme->get_template() !== $theme->get_stylesheet()) {
        $parent = $theme->parent();
        $theme_desc .=
            ' (child theme of ' . ($parent instanceof WP_Theme ? $parent->get('Name') : $theme->get_template()) . ')';
    }

    return [
        '## Building pages and layout',
        '',
        'Active theme: ' . $theme_desc . '.',
        '',
        'Before any visual work (building or restyling a page, template, section, or component), load the `wppilot-design` skill and follow it.',
        '',
        function_exists('wp_has_ability') && wp_has_ability('wppilot/build-site-header')
            ? 'The site-wide header (logo, menu, language switcher, cart) is built with `wppilot/build-site-header`, which sizes the layout to the menu, makes one header per language and checks the served result. Do not hand-build a header template from element trees.'
            : '',
        '',
        'Before building or restructuring a page\'s content or layout, check the installed-plugins inventory above for page builders (which replace the editor) and block libraries (which extend Gutenberg), then ask the user which approach to use: a page builder, Gutenberg, classic theme templates, a child theme, or a custom theme. Ask once and follow that choice; do not mix approaches (e.g. Gutenberg blocks in a page-builder page).',
    ];
}

/**
 * Markdown lines on measuring speed, and the one-time Site Kit sharing offer.
 *
 * The offer is a line in the instructions rather than something the reads push on every call:
 * an agent told once what `fix` means asks the person once, where a hint repeated in every result
 * turns into a question asked on every turn. It is only included while Site Kit is active and at
 * least one of its three modules is not yet shared with Administrators; after that it has nothing
 * to offer.
 *
 * @return list<string>
 */
function wppilot_build_speed_context_lines(): array
{
    $lines = [
        '## Page speed',
        '',
        'Measure a page with `wppilot/pagespeed-check` (Google PageSpeed Insights: scores, lab metrics, field data, opportunities). It needs no Google API key from the user.',
    ];

    if (!defined('GOOGLESITEKIT_VERSION')) {
        return $lines;
    }

    /** @var mixed $sharing */
    $sharing = get_option('googlesitekit_dashboard_sharing', default_value: []);
    $unshared = false;
    foreach (['search-console', 'analytics-4', 'pagespeed-insights'] as $module) {
        $roles = is_array($sharing) && is_array($sharing[$module]['sharedRoles'] ?? null) ? $sharing[$module]['sharedRoles'] : [];
        if (!in_array('administrator', $roles, strict: true)) {
            $unshared = true;
        }
    }
    if ($unshared) {
        $lines[] = '';
        $lines[] = 'Site Kit by Google is active, and some of its data is not shared with Administrators. When a Site Kit read or a PageSpeed check returns `fix` naming `wppilot/site-kit-enable-sharing`, offer once to turn on read-only Site Kit dashboard sharing for Administrators, and run it (confirm=true) only if the user agrees. If they decline, do not offer it again in this conversation.';
    }

    return $lines;
}

/**
 * Build the MCP server instructions sent to AI agents during initialization.
 *
 * Includes environment info (PHP/WP versions, plugins) and guidance on using
 * WordPress-native features instead of hardcoding data in PHP.
 *
 * @return string
 */
function wppilot_build_server_instructions()
{
    $lines = [
        'GoBuildWP gives you unrestricted control over this WordPress installation.',
        '',
        '## Environment',
        '',
        'WordPress ' . get_bloginfo('version') . ' — PHP ' . PHP_VERSION . ' — Locale: ' . get_locale(),
    ];

    // Detect active languages from multilingual plugins.
    $multilingual = wppilot_get_active_languages();
    if ($multilingual !== null && $multilingual['languages'] !== []) {
        $lines[] = 'Multilingual (' . $multilingual['plugin'] . '): ' . implode(', ', $multilingual['languages']);
    }

    $lines[] = '';

    if (function_exists('get_plugins')) {
        /** @var array<string, array{Name?: string, Version?: string}> $all_plugins */
        $all_plugins = get_plugins();
        if ($all_plugins !== []) {
            $lines[] = 'Installed plugins:';
            foreach ($all_plugins as $plugin_file => $plugin_data) {
                $name = $plugin_data['Name'] ?? $plugin_file;
                $version = $plugin_data['Version'] ?? '';
                $version_suffix = $version !== '' ? ' v' . $version : '';
                $active = is_plugin_active($plugin_file) ? 'active' : 'inactive';
                $lines[] = '- ' . $name . $version_suffix . ' (' . $active . ')';
            }
            $lines[] = '';
        }
    }

    $lines = array_merge($lines, [
        '## WordPress-native development',
        '',
        'IMPORTANT: Prefer WordPress-native features to store and manage data.',
        'Do not hardcode content in PHP arrays when WordPress has a better mechanism:',
        '- Custom post types (register_post_type) for structured content (unless a data-modeling plugin owns it — see below)',
        '- Taxonomies (register_taxonomy) for categorization (same caveat)',
        '- Post meta / custom fields (update_post_meta) for additional data on posts (same caveat)',
        '- Options API (update_option) for settings and configuration',
        '- Custom database tables via $wpdb only when the above are insufficient',
        '',
        'Take advantage of active plugins. If a data-modeling plugin is in the',
        'installed-plugins inventory above (ACF / ACF Pro, JetEngine, Pods, ACPT,',
        'Meta Box, Toolset, Custom Post Type UI, WooCommerce, etc.), use it for the',
        'task it owns — never write a custom register_post_type / register_taxonomy /',
        'register_meta call in PHP for content the active plugin can model through its',
        'own UI/API. Splitting the source of truth between custom PHP and a plugin UI',
        'produces broken slugs, labels, and capabilities the next time the user touches',
        'either side, and that recovery is hard. If two or more such plugins are active,',
        'ask the user which one to use before persisting anything.',
        '',
        'Use WordPress hooks (actions/filters), template hierarchy, and REST API',
        'conventions. Write code that integrates with WordPress, not code that ignores it.',
    ]);

    $lines = array_merge($lines, wppilot_build_building_context_lines());
    $lines[] = '';
    $lines = array_merge($lines, wppilot_build_speed_context_lines());

    return implode("\n", $lines);
}

/**
 * A one-line input signature for an ability: each top-level parameter with its
 * type, optional ones marked with a trailing `?`, required ones first.
 *
 * The list used to carry names and descriptions only, so an agent guessed at
 * parameter names — `post_type` for `post_types`, `widget_type` for
 * `widget_types` — and spent a failed call learning each one.
 *
 * @param array<string, mixed> $schema
 */
function wppilot_ability_param_signature(array $schema): string
{
    $properties = $schema['properties'] ?? null;
    if (!is_array($properties) || $properties === []) {
        return '';
    }
    $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

    $parts = ['required' => [], 'optional' => []];
    foreach (array_keys($properties) as $name) {
        $property = $properties[$name];
        $type = is_array($property) && (is_string($property['type'] ?? null) || is_array($property['type'] ?? null))
            ? $property['type']
            : 'mixed';
        $type = is_array($type) ? implode('|', array_map('strval', $type)) : $type;
        $is_required = in_array($name, $required, strict: true);
        $parts[$is_required ? 'required' : 'optional'][] = sprintf('%s%s: %s', $name, $is_required ? '' : '?', $type);
    }

    return implode(', ', [...$parts['required'], ...$parts['optional']]);
}
