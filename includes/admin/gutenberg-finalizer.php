<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\GutenbergFinalizer;

if (!defined('ABSPATH')) {
    exit();
}

function boot_gutenberg_finalizer_admin(): void
{
    add_action('admin_menu', __NAMESPACE__ . '\\register_gutenberg_finalizer_menu', priority: 60);
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_gutenberg_finalizer_assets');
    add_filter('block_editor_settings_all', __NAMESPACE__ . '\\quiet_autosave_in_hidden_editor');
}

function gutenberg_finalizer_page_slug(): string
{
    return 'wppilot-gutenberg-finalize';
}

/**
 * The screen's own title.
 *
 * Carried here rather than in the nav map because this page is not on the rail:
 * wppilot_nav_label() falls back to the raw slug for anything the map does not
 * list, and "wppilot-gutenberg-finalize" is not a heading. The lookup still runs
 * first so a site that has renamed the screen keeps its name.
 */
function gutenberg_finalizer_title(): string
{
    return \wppilot_nav_label(gutenberg_finalizer_page_slug(), __('Block Queue', domain: 'wppilot'));
}

/**
 * Register the finalizer page, without putting it in the menu.
 *
 * An empty parent slug registers a page that resolves by URL but appears in no
 * menu, which is what this one should always have been. It is not a screen
 * anybody visits on purpose: it loads a hidden block editor in an iframe so
 * WordPress' own serializer can turn agent-supplied blocks into post content,
 * and its own copy tells the reader to ignore it and leave the tab open.
 *
 * Listed under Studio as "Block Queue" it read as a feature, so people opened it
 * expecting a queue they could work, and found a page asking them to go away.
 * The URL still works — the finalizer runtime opens it itself, and the
 * troubleshooting docs link to it — it simply stops advertising.
 */
function register_gutenberg_finalizer_menu(): void
{
    if (!defined('WPPILOT_VERSION')) {
        return;
    }

    add_submenu_page(
        parent_slug: '',
        page_title: gutenberg_finalizer_title(),
        menu_title: '',
        capability: 'edit_posts',
        menu_slug: gutenberg_finalizer_page_slug(),
        callback: __NAMESPACE__ . '\\render_gutenberg_finalizer_page',
    );
}

function enqueue_gutenberg_finalizer_assets(string $hook_suffix): void
{
    if (!is_gutenberg_finalizer_request()) {
        return;
    }

    enqueue_gutenberg_finalizer_runtime_assets();

    unset($hook_suffix);
}

function enqueue_gutenberg_finalizer_runtime_assets(): void
{
    // phpcs:disable WordPress.WP.EnqueuedResourceParameters.NotInFooter -- `args: true` IS the in_footer flag; the sniff cannot read PHP named arguments.
    wp_register_script(
        handle: 'wppilot-gutenberg-finalizer',
        src: WPPILOT_PLUGIN_URL . 'includes/assets/gutenberg-finalizer.js',
        deps: ['wp-api-fetch', 'wp-blocks', 'wp-block-library', 'wp-format-library'],
        ver: WPPILOT_VERSION,
        args: true,
    );
    // phpcs:enable WordPress.WP.EnqueuedResourceParameters.NotInFooter

    $config = [
        'nonce' => wp_create_nonce('wp_rest'),
    ];
    $encoded_config = wp_json_encode($config);
    if (is_string($encoded_config)) {
        wp_add_inline_script(
            handle: 'wppilot-gutenberg-finalizer',
            data: 'window.wppilotGutenbergFinalizer = ' . $encoded_config . ';',
            position: 'before',
        );
    }
    wp_enqueue_script(handle: 'wppilot-gutenberg-finalizer');
}

/**
 * Stop the hidden editor autosaving what the queue puts in it.
 *
 * The queue loads the target post's editor in an offscreen frame and resets its blocks so each
 * block's own editor code can fill in the attributes it assigns on mount. That leaves the editor
 * dirty, and an editor left dirty long enough autosaves — which would store a draft of content
 * nobody has approved yet and offer the author a backup of a post they never edited. Only the
 * frame the queue opens is affected: every other editor screen keeps its usual interval, because
 * the marker is the query argument the queue puts on its own iframe URL.
 *
 * @param array<string, mixed> $settings
 * @return array<string, mixed>
 */
function quiet_autosave_in_hidden_editor(array $settings): array
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only marker the queue puts on its own iframe URL; it only lengthens the autosave interval for that editor.
    if (!isset($_GET['wppilot_gb_finalizer']) || sanitize_text_field(wp_unslash($_GET['wppilot_gb_finalizer'])) !== '1') {
        return $settings;
    }

    $settings['autosaveInterval'] = DAY_IN_SECONDS;
    $settings['localAutosaveInterval'] = DAY_IN_SECONDS;

    return $settings;
}

function is_gutenberg_finalizer_request(): bool
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: the admin page slug only decides whether this screen's assets load.
    return isset($_GET['page']) && is_string($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === gutenberg_finalizer_page_slug();
}

function render_gutenberg_finalizer_page(): void
{
    if (!current_user_can('edit_posts')) {
        return;
    }

    if (function_exists('wppilot_render_admin_header')) {
        wppilot_render_admin_header();
    }

    ?>
    <div class="wrap wppilot-gb-finalizer" id="wppilot-gb-finalizer">
        <h1 class="wp-heading-inline"><?php echo esc_html(gutenberg_finalizer_title()); ?></h1>
        <hr class="wp-header-end">
        <?php render_gutenberg_finalizer_styles(); ?>

        <?php render_gutenberg_finalizer_page_content(); ?>
    </div>
    <?php
}

function render_gutenberg_finalizer_page_content(): void
{
    render_gutenberg_finalizer_dashboard();
}

function render_gutenberg_finalizer_dashboard(): void
{ ?>
    <div id="wppilot-gb-notice" class="notice" hidden><p></p></div>

    <section class="summary-panel" aria-live="polite">
        <p><?php esc_html_e(
            'This background utility page is used by GoBuildWP to safely validate and serialize Gutenberg blocks. During Gutenberg editing sessions, this page serves as a technical bridge, utilizing the native WordPress editor engine to serialize block structures securely.',
            domain: 'wppilot',
        ); ?></p>
        <p><strong><?php esc_html_e(
            'Please keep this tab open in the background while an active session is running. You can safely ignore this page, but closing it before the session completes will pause the updates.',
            domain: 'wppilot',
        ); ?></strong></p>
        <p id="wppilot-gb-progress" class="progress-line"><?php esc_html_e(
            'Checking for queued Gutenberg changes...',
            domain: 'wppilot',
        ); ?></p>
    </section>
    <div class="wppilot-gb-editor-frame-wrap" aria-hidden="true">
        <iframe
            id="wppilot-gb-editor-frame"
            class="wppilot-gb-editor-frame"
            title="<?php esc_attr_e('GoBuildWP hidden block editor', domain: 'wppilot'); ?>"
            tabindex="-1"
            src="about:blank"
        ></iframe>
    </div>
    <?php }

function render_gutenberg_finalizer_styles(): void
{ ?>
    <?php // Styles for this screen live in includes/assets/admin.css. ?>
    <?php }
