<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * One entry in the WordPress sidebar, not fifteen.
 *
 * GoBuildWP has thirteen screens of its own and gains more from Pro. Listed
 * individually in the admin sidebar they pushed every other plugin — and half
 * of WordPress' own menu — below the fold, and they did it on every screen of
 * the site, not just GoBuildWP's. A plugin does not get to own the sidebar
 * because it happens to have a lot of pages. Navigation between GoBuildWP screens
 * belongs on the grouped tab rail inside the plugin, which is easier to read
 * than a flat list of fifteen anyway.
 *
 * This is done with CSS, and that is a deliberate choice rather than a
 * shortcut. The obvious implementation — remove_submenu_page() for each entry —
 * breaks every page it hides:
 *
 *   WordPress resolves a plugin page's hook through get_admin_page_parent(),
 *   which finds the parent by *searching $submenu for the page's slug*. Empty
 *   that array and the parent is unknown, so get_plugin_page_hookname() returns
 *   `admin_page_<slug>` instead of `wppilot_page_<slug>`, no callback is
 *   registered under that name, and admin.php answers "Sorry, you are not
 *   allowed to access this page."
 *
 * So the entries stay registered and stay in $submenu, exactly as WordPress
 * expects. Only their pixels go. The tab rail keeps reading $submenu as it
 * always has, and every page keeps loading.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The top-level menu slug GoBuildWP registers everything under.
 */
const WPPILOT_SIDEBAR_PARENT = 'wppilot-connect';

/**
 * Whether to collapse the sidebar to the single top-level item.
 *
 * Filterable because it is a presentation choice, and a site that genuinely
 * wants the flat list — or an agency pinning one screen for a client — should
 * be able to say so without editing the plugin.
 */
function wppilot_sidebar_is_collapsed(): bool
{
    return apply_filters('wppilot_collapse_admin_submenu', value: true) !== false;
}

/**
 * Hide GoBuildWP's submenu in the admin sidebar.
 *
 * Scoped to GoBuildWP's own top-level item, so no other plugin's menu is
 * affected, and applied with `display: none` on the container rather than on
 * individual items — WordPress renders that list both as the hover flyout and
 * as the expanded tree on GoBuildWP screens, and both should go.
 *
 * `aria-hidden` is not used and `display: none` is: the items are removed from
 * the accessibility tree along with the visual one, which is correct here
 * because they are duplicated by the tab rail on the page itself. A keyboard or
 * screen-reader user reaches every screen through the rail.
 */
function wppilot_sidebar_hide_submenu(): void
{
    if (!wppilot_sidebar_is_collapsed()) {
        return;
    }

    $selector = '#toplevel_page_' . WPPILOT_SIDEBAR_PARENT;
    $visible = wppilot_sidebar_visible_pages();

    if ($visible === []) {
        printf(
            '<style id="wppilot-collapse-submenu">%s .wp-submenu{display:none !important;}</style>',
            esc_html($selector),
        );
        return;
    }

    // Keep the few screens people look for in the sidebar (owners could not find Prompts
    // once it sat behind Studio in the tab rail) and hide the rest, which the rail covers.
    $keep = [];
    foreach ($visible as $slug) {
        $keep[] = sprintf('%1$s .wp-submenu li:has(> a[href$="page=%2$s"])', $selector, $slug);
    }
    printf(
        '<style id="wppilot-collapse-submenu">%1$s .wp-submenu li:not(.wp-submenu-head){display:none !important;}%2$s{display:list-item !important;}</style>',
        esc_html($selector),
        // Slugs are limited to [a-z0-9-] above and the selector is a constant, so the rule
        // needs no escaping; esc_html() would turn the attribute quotes into &quot;.
        implode(',', $keep), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    );
}

/**
 * GoBuildWP screens that stay listed in the admin sidebar while the rest collapse into the tab rail.
 *
 * @return list<string> Page slugs.
 */
function wppilot_sidebar_visible_pages(): array
{
    /** @var mixed $slugs */
    $slugs = apply_filters('wppilot_sidebar_visible_pages', ['wppilot-connect', 'wppilot-setup', 'wppilot-prompts', 'wppilot-design', 'wppilot-changes', 'wppilot-settings']);
    if (!is_array($slugs)) {
        return [];
    }
    $clean = [];
    foreach ($slugs as $slug) {
        if (is_string($slug) && preg_match('/^[a-z0-9-]+$/', $slug) === 1) {
            $clean[] = $slug;
        }
    }
    return $clean;
}

add_action('admin_head', callback: 'wppilot_sidebar_hide_submenu');

/** Keep common destinations in task order without removing registered pages. */
function wppilot_sidebar_order_pages(): void
{
    global $submenu;
    if (!isset($submenu[WPPILOT_SIDEBAR_PARENT]) || !wppilot_sidebar_is_collapsed()) {
        return;
    }
    $order = array_flip(wppilot_sidebar_visible_pages());
    usort($submenu[WPPILOT_SIDEBAR_PARENT], static fn(array $a, array $b): int =>
        ($order[$a[2] ?? ''] ?? 1000) <=> ($order[$b[2] ?? ''] ?? 1000));
}
add_action('admin_menu', callback: 'wppilot_sidebar_order_pages', priority: 9999);
