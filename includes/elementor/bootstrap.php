<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Basic Elementor support: read a document, inspect what the builder offers,
 * and edit the element tree.
 *
 * The design system has always shipped here — a direction, a type scale, the
 * compositions a page may be built from, and the checks that grade the result.
 * The builder that turns any of that into a page did not, so a free install
 * could derive a design it had no way to apply. That is not a teaser; it is a
 * dead end, and this closes it.
 *
 * What lives here is the machine and the primitives: what widgets exist, what
 * properties are valid, what is on the page, and how to add, edit, move and
 * remove elements. It is deliberately not the authoring layer — building a
 * whole page from a description or from a reproduction spec, templates, theme
 * parts, popups, forms, dynamic content, global classes and variables all stay
 * in Pro. The line is that this can edit an Elementor page and Pro can compose
 * one.
 *
 * Loaded only when Elementor is present and past its version floor. Pro reads
 * from here rather than carrying a second copy, so the two cannot drift.
 */

if (!defined('ABSPATH')) {
    exit();
}

// Minimum Elementor version the abilities below require. Older releases lack
// the container element and the get_controls() shape the schema extractor
// reads, so everything here stays unregistered rather than failing per call.
if (!defined('WPPILOT_ELEMENTOR_MIN_VERSION')) {
    define(constant_name: 'WPPILOT_ELEMENTOR_MIN_VERSION', value: '3.6.0');
}

// Fully-qualified class name that signals the Atomic Widgets module is loaded
// (an experiment in Elementor 3.27, stable in 4.0). Atomic-only paths test for
// it so they can decline on an older site instead of fataling.
if (!defined('WPPILOT_ELEMENTOR_ATOMIC_BASE_CLASS')) {
    define(
        constant_name: 'WPPILOT_ELEMENTOR_ATOMIC_BASE_CLASS',
        value: 'Elementor\\Modules\\AtomicWidgets\\Elements\\Base\\Atomic_Widget_Base',
    );
}

/**
 * Elementor version string, or the empty string when Elementor is inactive.
 */
function el_elementor_version(): string
{
    return defined('ELEMENTOR_VERSION') ? (string) constant('ELEMENTOR_VERSION') : '';
}

/**
 * Base Elementor surface required by every ability in this module.
 *
 * The version is checked alongside the class because an install one minor
 * release too old fails deep inside a call with a message about a missing
 * method, which reads as a GoBuildWP bug rather than as an unsupported version.
 */
function el_min_runtime_available(): bool
{
    return (
        defined('ELEMENTOR_VERSION')
        && version_compare(el_elementor_version(), version2: WPPILOT_ELEMENTOR_MIN_VERSION, operator: '>=')
        && class_exists('Elementor\\Plugin')
    );
}

/**
 * Pull in the machine and the abilities built on it.
 *
 * Order is for reading, not for resolution: PHP hoists function declarations
 * per file, so the helpers are listed first because everything else is written
 * against them.
 */
function load(): void
{
    $dir = __DIR__ . '/';

    foreach (
        [
            // The style and tree machine. Nothing here registers an ability.
            'helpers/wppilot-v3-v4-keys.php',
            'helpers/wppilot-schema.php',
            'helpers/wppilot-style-unions.php',
            'helpers/wppilot-style-values.php',
            'helpers/wppilot-unknown-keys.php',
            'helpers/wppilot-atomic-values.php',
            'helpers/wppilot-settings-validation.php',
            'helpers/wppilot-variants.php',
            'helpers/wppilot-styles-map.php',
            'helpers/wppilot-page-io.php',
            'helpers/wppilot-tree.php',
            'helpers/wppilot-atomic-equivalents.php',
            'wppilot-runtime.php',
            'wppilot-schema-extractor.php',
            'wppilot-validate-widget.php',
            'add-element/wppilot-input-parsing.php',
            'add-element/wppilot-style-classes.php',
            'add-element/wppilot-style-variants.php',
            'add-element/wppilot-dynamic-tags.php',
            'add-element/wppilot-boxed-containers.php',
            'add-element/wppilot-v3-container-translation.php',
            'add-element/wppilot-error-responses.php',
            'add-element/wppilot-ability.php',
            'wppilot-pipeline.php',

            // The abilities themselves.
            'wppilot-check-setup.php',
            'wppilot-audit-atomic-readiness.php',
            'wppilot-get-content.php',
            'wppilot-get-widget-schema.php',
            'wppilot-get-style-schema.php',
            'wppilot-get-widget-params.php',
            'wppilot-structure.php',
            'wppilot-add-element.php',
            'wppilot-edit-element.php',
            'wppilot-delete-element.php',
            'wppilot-set-content.php',
            'wppilot-manage-page-settings.php',
            'wppilot-clear-document-cache.php',
        ] as $file
    ) {
        if (is_readable($dir . $file)) {
            require_once $dir . $file;
        }
    }
}

/**
 * Register the category these abilities live in, unless something already has.
 *
 * Pro declares the same slug in its specialization manifest, and registering it
 * twice makes WordPress emit a doing_it_wrong notice on every request. Whoever
 * gets there first owns it; the other steps aside.
 */
function register_category(): void
{
    if (!function_exists('wp_register_ability_category') || !el_min_runtime_available()) {
        return;
    }
    if (function_exists('wp_has_ability_category') && wp_has_ability_category('elementor')) {
        return;
    }

    wp_register_ability_category('elementor', [
        'label' => __('Elementor', domain: 'wppilot'),
        'description' => __(
            'Read an Elementor document, inspect the widgets and style properties this install offers, and edit the element tree.',
            domain: 'wppilot',
        ),
    ]);
}

/**
 * Load on the abilities hook rather than at file scope.
 *
 * Elementor defines ELEMENTOR_VERSION while it boots on plugins_loaded, so a
 * check run when this file is required would see nothing and the whole module
 * would sit unused on a site that does have Elementor.
 *
 * Early on that hook, ahead of Pro's specialization engine at the default
 * priority: Pro's own Elementor abilities are written against the machine
 * below, and should never be the ones that define it.
 */
function boot(): void
{
    if (!el_min_runtime_available()) {
        return;
    }
    load();
}

/**
 * Before-image for the free Elementor writes, so an agent's Elementor edit can be undone without Pro.
 *
 * Every one of these writes the page's element tree into post meta, and the ledger had no capture
 * for them: on a site without Pro, an edit was recorded as "No supported before-image" and could
 * not be undone. A whole-post snapshot restores _elementor_data, its page settings and the CSS
 * meta Elementor derives from them. It runs late and only fills a gap, so Pro's own Elementor
 * capture, which knows more about the document, still wins when Pro is active.
 *
 * @param mixed $before
 * @param mixed $input
 * @return mixed
 */
function capture_before_image(mixed $before, string $ability_name, mixed $input): mixed
{
    if ($before !== null || !in_array($ability_name, [
        'wppilot/elementor-add-element',
        'wppilot/elementor-delete-element',
        'wppilot/elementor-duplicate-element',
        'wppilot/elementor-edit-element',
        'wppilot/elementor-move-element',
        'wppilot/elementor-reorder-children',
        'wppilot/elementor-set-content',
        'wppilot/elementor-set-page-settings',
    ], strict: true)) {
        return $before;
    }
    $post_id = is_array($input) ? (int) ($input['post_id'] ?? 0) : 0;
    return $post_id > 0 && function_exists('wppilot_snapshot_post') ? \wppilot_snapshot_post($post_id) : $before;
}

add_filter('wppilot_capture_before_image', __NAMESPACE__ . '\\capture_before_image', 99, 3);
add_action('wp_abilities_api_categories_init', __NAMESPACE__ . '\\register_category', priority: 20);
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\boot', priority: 5);
