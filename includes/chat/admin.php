<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * GoBuildWP Chat: the admin screen — menu entry, assets, and page render.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Add Chat to the menu, but only where Chat can actually run.
 *
 * wppilot_chat_is_enabled() is a filter and nothing else — it says whether the
 * site owner has switched Chat off, not whether the machinery behind it exists.
 * So the entry appeared on every install, including the majority that have no AI
 * provider configured and every site below WordPress 7, where the page can do
 * nothing but explain that it cannot work. A permanent menu item whose only
 * content is an apology is worse than no menu item.
 *
 * wppilot_chat_status() is a handful of class_exists() calls, so asking it on
 * every admin_menu costs nothing.
 */
function wppilot_register_chat_menu(): void
{
    if (!wppilot_chat_is_enabled() || wppilot_chat_status()['available'] !== true) {
        return;
    }

    add_submenu_page(
        parent_slug: 'wppilot-connect',
        page_title: wppilot_nav_label('wppilot-chat'),
        menu_title: wppilot_nav_label('wppilot-chat'),
        capability: wppilot_manage_capability(),
        menu_slug: WPPILOT_CHAT_PAGE,
        callback: 'wppilot_render_chat_page',
    );
}

function wppilot_enqueue_chat_assets(string $hook): void
{
    if (!wppilot_chat_is_enabled() || $hook !== 'wppilot_page_' . WPPILOT_CHAT_PAGE) {
        return;
    }

    $asset_file = dirname(__DIR__) . '/assets/chat/index.asset.php';
    // @mago-expect analysis:mixed-assignment
    $asset = is_file($asset_file) ? (require $asset_file) : ['dependencies' => [], 'version' => WPPILOT_VERSION];
    if (!is_array($asset)) {
        $asset = ['dependencies' => [], 'version' => WPPILOT_VERSION];
    }

    /** @var list<string> $dependencies */
    $dependencies = is_array($asset['dependencies'] ?? null) ? $asset['dependencies'] : [];
    $version = is_string($asset['version'] ?? null) ? $asset['version'] : WPPILOT_VERSION;

    // phpcs:disable WordPress.WP.EnqueuedResourceParameters.NotInFooter -- `args: true` IS the in_footer flag; the sniff cannot read PHP named arguments.
    wp_enqueue_script(
        'wppilot-chat',
        (string) WPPILOT_PLUGIN_URL . 'includes/assets/chat/index.js',
        $dependencies,
        $version,
        args: true,
    );
    // phpcs:enable WordPress.WP.EnqueuedResourceParameters.NotInFooter
    wp_enqueue_style(
        'wppilot-chat',
        (string) WPPILOT_PLUGIN_URL . 'includes/assets/chat/style-index.tsx.css',
        ['wp-components'],
        $version,
    );
    if (function_exists('WPPilot\\GutenbergFinalizer\\enqueue_gutenberg_finalizer_runtime_assets')) {
        \WPPilot\GutenbergFinalizer\enqueue_gutenberg_finalizer_runtime_assets();
    }
    wp_set_script_translations('wppilot-chat', domain: 'wppilot');
    $settings_json = wp_json_encode([
        'root' => esc_url_raw(rest_url(WPPILOT_CHAT_REST_NAMESPACE)),
        'nonce' => wp_create_nonce('wp_rest'),
        'status' => wppilot_chat_status(),
        'connectorsUrl' => admin_url('options-connectors.php'),
        'consented' => wppilot_chat_user_has_consented(),
        'backUrl' => add_query_arg(['page' => 'wppilot-connect'], admin_url('admin.php')),
    ]);
    if (!is_string($settings_json)) {
        $settings_json = '{}';
    }

    wp_add_inline_script('wppilot-chat', 'window.wppilotChat = ' . $settings_json . ';', position: 'before');
}

function wppilot_render_chat_page(): void
{
    if (!wppilot_chat_is_enabled() || !wppilot_current_user_can_manage()) {
        return;
    }

    wppilot_render_admin_header(legend: __('Chat', domain: 'wppilot'));
    ?>
    <div class="wrap wppilot-chat-wrap">
        <h1 class="screen-reader-text"><?php esc_html_e('GoBuildWP Chat', domain: 'wppilot'); ?></h1>
        <?php wppilot_chat_render_usage_meter(get_current_user_id()); ?>
        <div id="wppilot-chat-root"></div>
        <?php wppilot_render_chat_gutenberg_finalizer_runtime(); ?>
    </div>
    <?php
}

function wppilot_render_chat_gutenberg_finalizer_runtime(): void
{
    if (!function_exists('WPPilot\\GutenbergFinalizer\\enqueue_gutenberg_finalizer_runtime_assets')) {
        return;
    }

    ?>
    <div
        id="wppilot-gb-finalizer"
        class="wppilot-gb-finalizer-runtime wppilot-gb-finalizer-runtime--embedded"
        aria-hidden="true"
        style="position:absolute;top:0;left:-10000px;width:1280px;height:900px;overflow:hidden;opacity:0;pointer-events:none;"
    ></div>
    <?php
}
