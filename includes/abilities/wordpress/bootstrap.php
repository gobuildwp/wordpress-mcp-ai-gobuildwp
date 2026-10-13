<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * WordPress-core ability module.
 *
 * These are the typed wrappers for the platform itself — content, media, terms,
 * comments, revisions, menus, users, and the allowlisted settings surface. They
 * ship in GoBuildWP Free and never call into Pro: Pro is for plugin-aware
 * integrations (page builders, WooCommerce, forms, SEO suites), not for the
 * WordPress operations every site has.
 *
 * Loaded from includes/abilities/bootstrap.php on `wp_abilities_api_init`, and
 * directly by the unit-test bootstrap — which is why the shared schema below is
 * required here rather than relied on from the sibling loader.
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/../schema.php';

/**
 * Register the category the WordPress-core abilities are grouped under.
 *
 * Runs on `wp_abilities_api_categories_init`, before any ability in this module
 * is registered, because an ability naming an unregistered category is rejected.
 */
function wppilot_register_wordpress_ability_category(): void
{
    if (wp_has_ability_category('wordpress')) {
        return;
    }

    wp_register_ability_category('wordpress', [
        'label' => __('WordPress', domain: 'wppilot'),
        'description' => __(
            'Typed WordPress operations: content, taxonomies, media, comments, revisions, menus, users, and allowlisted site settings.',
            domain: 'wppilot',
        ),
    ]);
}

/**
 * Load every WordPress-core ability file.
 *
 * helpers.php first: it defines register_core_ability(), which every other file
 * in the module registers through.
 */
function wppilot_load_wordpress_abilities(): void
{
    $dir = __DIR__ . '/';

    require_once $dir . 'helpers.php';

    // content-read.php defines wordpress_core_mcp_meta(), which every later
    // registration calls at file scope, so it stays second.
    require_once $dir . 'content-read.php';
    require_once $dir . 'create-post.php';
    require_once $dir . 'update-post.php';
    require_once $dir . 'delete-post.php';
    require_once $dir . 'restore-post.php';
    require_once $dir . 'taxonomies.php';
    require_once $dir . 'comments.php';
    require_once $dir . 'revisions.php';
    require_once $dir . 'media.php';
    require_once $dir . 'search-images.php';
    require_once $dir . 'media-attachments.php';
    require_once $dir . 'site-management.php';
    require_once $dir . 'extensions.php';
    require_once $dir . 'menus.php';
}
