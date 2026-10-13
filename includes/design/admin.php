<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

namespace WPPilot\Design\Admin;

if (!defined('ABSPATH')) {
    exit();
}

const PAGE_SLUG = 'wppilot-design';

function capability(): string
{
    return \wppilot_manage_capability();
}

function current_user_can_manage(): bool
{
    return \wppilot_current_user_can_manage();
}

function register_menu(): void
{
    add_submenu_page(
        parent_slug: 'wppilot-connect',
        page_title: \wppilot_nav_label('wppilot-design'),
        menu_title: \wppilot_nav_label('wppilot-design'),
        capability: capability(),
        menu_slug: PAGE_SLUG,
        callback: __NAMESPACE__ . '\\render_page',
    );
}

/** Place the Design entry immediately after Skills (`wppilot-skills`). */
// @mago-expect lint:no-global
function reorder_submenu(): void
{
    global $submenu;
    if (!is_array($submenu ?? null) || !is_array($submenu['wppilot-connect'] ?? null)) {
        return;
    }
    /** @var array<int, array<int, string>> $entries */
    $entries = $submenu['wppilot-connect'];
    $self = null;
    foreach ($entries as $key => $entry) {
        if (($entry[2] ?? null) !== PAGE_SLUG) {
            continue;
        }
        $self = $entry;
        unset($entries[$key]);
        break;
    }
    if ($self === null) {
        return;
    }
    $reordered = [];
    $inserted = false;
    foreach ($entries as $entry) {
        $reordered[] = $entry;
        if (!$inserted && ($entry[2] ?? null) === 'wppilot-skills') {
            $reordered[] = $self;
            $inserted = true;
        }
    }
    if (!$inserted) {
        $reordered[] = $self;
    }
    $submenu['wppilot-connect'] = $reordered;
}

function render_page(): void
{
    if (!current_user_can_manage()) {
        wp_die(esc_html__('You do not have permission to manage the design system.', domain: 'wppilot'));
    }
    if (($_GET['view'] ?? null) !== null) {
        require __DIR__ . '/templates/detail.php';
        return;
    }
    if (($_GET['design'] ?? null) !== null) {
        require __DIR__ . '/templates/edit.php';
        return;
    }
    if (($_GET['import'] ?? null) !== null) {
        require __DIR__ . '/templates/import.php';
        return;
    }
    require __DIR__ . '/templates/panel.php';
}

function register_post_handlers(): void
{
    add_action('admin_post_wppilot_design_activate', __NAMESPACE__ . '\\handle_activate');
    add_action('admin_post_wppilot_design_import', __NAMESPACE__ . '\\handle_import');
    add_action('admin_post_wppilot_design_save', __NAMESPACE__ . '\\handle_save');
    add_action('admin_post_wppilot_design_duplicate', __NAMESPACE__ . '\\handle_duplicate');
    add_action('admin_post_wppilot_design_use_starter', __NAMESPACE__ . '\\handle_use_starter');
    add_action('admin_post_wppilot_design_restore', __NAMESPACE__ . '\\handle_restore');
    add_action('admin_post_wppilot_design_delete', __NAMESPACE__ . '\\handle_delete');
}

function enqueue_assets(string $hook): void
{
    if ($hook !== 'wppilot_page_' . PAGE_SLUG) {
        return;
    }
    wp_enqueue_style(
        'wppilot-design-admin',
        (string) WPPILOT_PLUGIN_URL . 'includes/design/assets/admin.css',
        [],
        WPPILOT_VERSION,
    );
    // phpcs:disable WordPress.WP.EnqueuedResourceParameters.NotInFooter -- `args: true` IS the in_footer flag; the sniff cannot read PHP named arguments.
    wp_enqueue_script(
        'wppilot-design-admin',
        (string) WPPILOT_PLUGIN_URL . 'includes/design/assets/admin.js',
        [],
        WPPILOT_VERSION,
        args: true,
    );
    // phpcs:enable WordPress.WP.EnqueuedResourceParameters.NotInFooter
    wp_add_inline_script(
        'wppilot-design-admin',
        'window.wppilotDesignFontNote = '
        . (string) wp_json_encode([
            /* translators: %s: comma separated list of font names */
            'missing' => __(
                'This preview is not showing the design\'s real fonts (%s). The site itself displays them normally. To see them here, press:',
                domain: 'wppilot',
            ),
            'load' => __('Preview with Google Fonts', domain: 'wppilot'),
            /* translators: %s: the font name Google Fonts does not carry */
            'notOnGoogle' => __('Google Fonts does not have %s.', domain: 'wppilot'),
            'copied' => __('Copied', domain: 'wppilot'),
        ])
        . ';',
        position: 'before',
    );
    // Fonts the site itself hosts (theme.json, Font Library) render faithfully
    // in the previews; everything is served same-origin.
    add_action('admin_head', static function (): void {
        if (function_exists('wp_print_font_faces')) {
            wp_print_font_faces();
        }
    });
    if (($_GET['design'] ?? null) === null) {
        return;
    }
    $settings = wp_enqueue_code_editor(['type' => 'text/markdown']);
    if ($settings !== false) {
        wp_add_inline_script('code-editor', sprintf(
            'jQuery(function($){ var el=document.getElementById("wppilot-design-content"); if(el&&window.wp&&wp.codeEditor){ var inst=wp.codeEditor.initialize(el,%s); window.wppilotDesignEditor=inst&&inst.codemirror?inst.codemirror:null; window.dispatchEvent(new CustomEvent("wppilot-design-editor-ready")); } });',
            (string) wp_json_encode($settings),
        ));
    }
}

function require_capability_and_nonce(string $nonce_action): void
{
    if (!current_user_can_manage()) {
        wp_die(esc_html__('Not allowed.', domain: 'wppilot'), title: '', args: ['response' => 403]);
    }
    check_admin_referer($nonce_action);
}

/** @param array<string, int|string> $args */
function redirect_with_notice(string $type, string $message, array $args = []): void
{
    set_transient(
        'wppilot_design_admin_notice_' . get_current_user_id(),
        ['type' => $type, 'message' => $message],
        expiration: 30,
    );
    wp_safe_redirect(add_query_arg(array_merge(['page' => PAGE_SLUG], $args), admin_url('admin.php')));
    exit();
}

function handle_activate(): void
{
    require_capability_and_nonce('wppilot_design_activate');
    $slug_raw = $_POST['slug'] ?? '';
    $slug = \WPPilot\Design\Parser\normalize_slug(is_string($slug_raw) ? $slug_raw : '');
    $record = $slug !== '' ? \WPPilot\Design\Library\find($slug) : null;
    if ($record === null) {
        redirect_with_notice('error', __('That design does not exist.', domain: 'wppilot'));
        return;
    }
    $inspection = \WPPilot\Design\Contract\inspect($record['content']);
    if (!$inspection['readiness']['ready']) {
        redirect_with_notice('error', \WPPilot\Design\Contract\activation_error($inspection));
        return;
    }
    \WPPilot\Design\Store\set_active($slug);
    \WPPilot\Design\Notices\set_pending_reload_notice();
    redirect_with_notice('success', __('Design activated.', domain: 'wppilot'));
}

/**
 * Resolve import content from either the uploaded file or the pasted textarea.
 * Returns null and redirects on upload validation errors; returns empty string
 * when no file was uploaded (caller falls through to empty-content guard).
 *
 * @param array<string, mixed>|null $file
 */
function resolve_import_content(?array $file): ?string
{
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $pasted = $_POST['design_content'] ?? '';
        return is_string($pasted) ? wp_unslash($pasted) : '';
    }
    $name = is_string($file['name'] ?? null) ? $file['name'] : '';
    $tmp = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
    if (!str_ends_with(strtolower($name), '.md')) {
        redirect_with_notice('error', __('Please upload a .md file.', domain: 'wppilot'));
        return null;
    }
    if ((int) ($file['size'] ?? 0) > \WPPilot\Design\Parser\MAX_BYTES) {
        redirect_with_notice('error', __('File too large (max 1 MB).', domain: 'wppilot'));
        return null;
    }
    $raw = $tmp !== '' && is_readable($tmp) ? file_get_contents($tmp) : false;
    if ($raw === false) {
        redirect_with_notice('error', __('Could not read the uploaded file.', domain: 'wppilot'));
        return null;
    }
    return $raw;
}

function handle_import(): void
{
    require_capability_and_nonce('wppilot_design_import');

    $file = $_FILES['design_file'] ?? null;
    $content = resolve_import_content(is_array($file) ? $file : null);
    if ($content === null) {
        return;
    }

    if (trim($content) === '') {
        redirect_with_notice('error', __('Nothing to import. Upload a file or paste a DESIGN.md.', domain: 'wppilot'));
        return;
    }
    if (strlen($content) > \WPPilot\Design\Parser\MAX_BYTES) {
        redirect_with_notice('error', __('DESIGN.md exceeds the size limit (max 1 MB).', domain: 'wppilot'));
        return;
    }
    if (!\WPPilot\Design\Parser\is_valid($content)) {
        redirect_with_notice('error', __(
            'Not a valid DESIGN.md (could not find a name: add YAML front matter or a # heading).',
            domain: 'wppilot',
        ));
        return;
    }

    $inspection = \WPPilot\Design\Contract\inspect($content);
    $parsed = \WPPilot\Design\Parser\parse($content);
    $prospective_slug = \WPPilot\Design\Parser\normalize_slug($parsed['name']);
    if (\WPPilot\Design\Store\get_active_slug() === $prospective_slug && !$inspection['readiness']['ready']) {
        redirect_with_notice(
            'error',
            __('The active design was not overwritten because the import is incomplete. ', domain: 'wppilot')
                . \WPPilot\Design\Contract\activation_error($inspection),
        );
        return;
    }

    $result = \WPPilot\Design\Store\save($content, slug: null, actor: 'user');
    if ($result['slug'] === '') {
        redirect_with_notice('error', __('Could not save the imported design.', domain: 'wppilot'));
        return;
    }
    $activation_blocked = maybe_activate_import($result['slug'], $inspection['readiness']);
    $message = import_success_message($result, $content);
    if ($activation_blocked) {
        $message .=
            ' '
            . __('Saved, but not activated: ', domain: 'wppilot')
            . \WPPilot\Design\Contract\activation_error($inspection);
    }
    redirect_with_notice($activation_blocked ? 'warning' : 'success', $message);
}

/**
 * Activate a newly imported design when requested and ready. Returns true when
 * the request was intentionally blocked by the semantic contract.
 *
 * @param array{ready: bool, sync_ready: bool, errors: list<string>, warnings: list<string>} $readiness
 */
function maybe_activate_import(string $slug, array $readiness): bool
{
    if (($_POST['activate'] ?? null) === null) {
        return false;
    }
    if (!$readiness['ready']) {
        return true;
    }
    \WPPilot\Design\Store\set_active($slug);
    \WPPilot\Design\Notices\set_pending_reload_notice();
    return false;
}

/** @param array{slug: string, name: string} $result */
function import_success_message(array $result, string $content): string
{
    $message = sprintf(
        /* translators: %s: design name */
        __('Imported "%s".', domain: 'wppilot'),
        $result['name'] !== '' ? $result['name'] : $result['slug'],
    );
    $waivers = \WPPilot\Design\Preflight\waivers($content);
    if ($waivers !== []) {
        $message .= sprintf(
            /* translators: %s: list of anti-slop rules this design waives */
            __(' Allows: %s.', domain: 'wppilot'),
            implode(' · ', $waivers),
        );
    }
    return $message;
}

function read_design_id(): int
{
    $raw = $_POST['design_id'] ?? $_GET['design'] ?? null;
    return is_scalar($raw) ? (int) $raw : 0;
}

function load_user_design(int $post_id): \WP_Post
{
    // @mago-expect analysis:mixed-assignment
    $post = get_post($post_id);
    if (!$post instanceof \WP_Post || $post->post_type !== \WPPilot\Design\Cpt\POST_TYPE) {
        wp_die(esc_html__('Design not found.', domain: 'wppilot'));
    }
    /** @var \WP_Post $post */
    return $post;
}

function handle_save(): void
{
    $post_id = read_design_id();
    require_capability_and_nonce('wppilot_design_save_' . $post_id);
    $post = load_user_design($post_id);

    $content_raw = $_POST['content'] ?? '';
    $content = is_string($content_raw) ? wp_unslash($content_raw) : '';
    if (strlen($content) > \WPPilot\Design\Parser\MAX_BYTES) {
        redirect_with_notice('error', __('DESIGN.md exceeds the size limit.', domain: 'wppilot'));
        return;
    }
    if (!\WPPilot\Design\Parser\is_valid($content)) {
        redirect_with_notice('error', __(
            'Not a valid DESIGN.md (could not find a name: add YAML front matter or a # heading).',
            domain: 'wppilot',
        ));
        return;
    }

    $parsed = \WPPilot\Design\Parser\parse($content);
    $new_slug = \WPPilot\Design\Parser\normalize_slug($parsed['name'] !== '' ? $parsed['name'] : $post->post_name);
    if ($new_slug === '') {
        redirect_with_notice('error', __('Could not derive a slug from the name.', domain: 'wppilot'));
        return;
    }

    $was_active = \WPPilot\Design\Store\get_active_slug() === $post->post_name;
    $inspection = \WPPilot\Design\Contract\inspect($content);
    if ($was_active && !$inspection['readiness']['ready']) {
        redirect_with_notice(
            'error',
            __('The active design was not changed because the edited version is incomplete. ', domain: 'wppilot')
                . \WPPilot\Design\Contract\activation_error($inspection),
        );
        return;
    }
    \WPPilot\Design\Revisions\snapshot_current($post);
    $updated = wp_update_post([
        'ID' => $post_id,
        // wp_update_post() unslashes every string it is given, so the title
        // needs the same slashing the content already gets.
        'post_title' => wp_slash($parsed['name'] !== '' ? $parsed['name'] : $new_slug),
        'post_name' => $new_slug,
        'post_content' => wp_slash($content),
    ], wp_error: true);
    if (is_wp_error($updated)) {
        redirect_with_notice('error', __('The design could not be saved.', domain: 'wppilot'));
        return;
    }
    update_post_meta($post_id, \WPPilot\Design\Cpt\META_LAST_ACTOR, meta_value: 'user');
    // WordPress may append a suffix if the slug collides (design -> design-2);
    // read the slug it actually stored so the active pointer never drifts.
    // @mago-expect analysis:mixed-assignment
    $saved_post = get_post($post_id);
    $actual_slug =
        $saved_post instanceof \WP_Post && $saved_post->post_name !== '' ? $saved_post->post_name : $new_slug;
    if ($was_active) {
        \WPPilot\Design\Store\set_active($actual_slug);
        \WPPilot\Design\Notices\set_pending_reload_notice();
    }

    set_transient(
        'wppilot_design_admin_notice_' . get_current_user_id(),
        ['type' => 'success', 'message' => __('Design saved.', domain: 'wppilot')],
        expiration: 30,
    );
    wp_safe_redirect(add_query_arg(['page' => PAGE_SLUG, 'design' => $post_id], admin_url('admin.php')));
    exit();
}

function unique_user_slug(string $base): string
{
    $base = \WPPilot\Design\Parser\normalize_slug($base);
    if ($base === '') {
        $base = 'design';
    }
    $slug = $base;
    $n = 2;
    while (\WPPilot\Design\Store\find_user_post($slug) !== null) {
        $slug = $base . '-' . $n;
        $n++;
    }
    return $slug;
}

function handle_duplicate(): void
{
    require_capability_and_nonce('wppilot_design_duplicate');
    $slug_raw = $_POST['slug'] ?? '';
    $slug = \WPPilot\Design\Parser\normalize_slug(is_string($slug_raw) ? $slug_raw : '');
    $source = $slug !== '' ? \WPPilot\Design\Library\find($slug) : null;
    if ($source === null) {
        redirect_with_notice('error', __('That design does not exist.', domain: 'wppilot'));
        return;
    }
    $new_slug = unique_user_slug($slug . '-copy');
    $result = \WPPilot\Design\Store\save($source['content'], $new_slug, actor: 'user');
    if ($result['slug'] === '') {
        redirect_with_notice('error', __('Could not duplicate the design.', domain: 'wppilot'));
        return;
    }
    $post = \WPPilot\Design\Store\find_user_post($result['slug']);
    set_transient(
        'wppilot_design_admin_notice_' . get_current_user_id(),
        ['type' => 'success', 'message' => __('Design duplicated. Edit your copy.', domain: 'wppilot')],
        expiration: 30,
    );
    $args = ['page' => PAGE_SLUG];
    if ($post instanceof \WP_Post) {
        $args['design'] = $post->ID;
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit();
}

function handle_restore(): void
{
    $post_id = read_design_id();
    $revision_raw = $_POST['revision_id'] ?? 0;
    $revision_id = is_scalar($revision_raw) ? (int) $revision_raw : 0;
    require_capability_and_nonce('wppilot_design_restore_' . $revision_id);
    $post = load_user_design($post_id);

    $revision = \WPPilot\Design\Revisions\find($post, $revision_id);
    if ($revision === null) {
        redirect_with_notice('error', __('That revision does not belong to this design.', domain: 'wppilot'), [
            'view' => $post->post_name,
        ]);
        return;
    }

    $result = \WPPilot\Design\Revisions\restore($post, $revision, actor: 'user');
    if (is_wp_error($result)) {
        redirect_with_notice('error', $result->get_error_message(), ['view' => $post->post_name]);
        return;
    }

    if (\WPPilot\Design\Store\get_active_slug() === $post->post_name) {
        \WPPilot\Design\Notices\set_pending_reload_notice();
    }
    redirect_with_notice('success', __('Design revision restored.', domain: 'wppilot'), [
        'view' => $post->post_name,
    ]);
}

function handle_delete(): void
{
    $post_id = read_design_id();
    require_capability_and_nonce('wppilot_design_delete_' . $post_id);
    $post = load_user_design($post_id);

    if (\WPPilot\Design\Store\get_active_slug() === $post->post_name) {
        delete_option(\WPPilot\Design\Cpt\OPTION_ACTIVE);
        \WPPilot\Design\Notices\set_pending_reload_notice();
    }
    wp_delete_post($post_id, force_delete: true);
    redirect_with_notice('success', __('Design deleted.', domain: 'wppilot'));
}

/**
 * Copy a starter direction into this site's own library.
 *
 * A copy, never a reference. The starter is a place to begin, and the moment a
 * site adopts one it becomes that site's document to edit, rename and argue
 * with. Nothing is shared back, so a later change to the shipped starters cannot
 * alter a live site's design.
 *
 * Deliberately does not activate it. Adopting a palette somebody else chose and
 * putting it straight into production is the failure this whole module exists to
 * prevent; the copy opens in the editor so the reasoning gets rewritten for this
 * business first.
 */
function handle_use_starter(): void
{
    require_capability_and_nonce('wppilot_design_use_starter');

    $slug_raw = $_POST['slug'] ?? '';
    $starter = \WPPilot\Design\Examples\find(is_string($slug_raw) ? $slug_raw : '');
    if ($starter === null) {
        redirect_with_notice('error', __('That starter kit does not exist.', domain: 'wppilot'));
        return;
    }

    $new_slug = unique_user_slug($starter['slug']);
    $result = \WPPilot\Design\Store\save($starter['content'], $new_slug, actor: 'user');
    if ($result['slug'] === '') {
        redirect_with_notice('error', __('Could not copy that starter kit.', domain: 'wppilot'));
        return;
    }

    $post = \WPPilot\Design\Store\find_user_post($result['slug']);
    set_transient(
        'wppilot_design_admin_notice_' . get_current_user_id(),
        [
            'type' => 'success',
            'message' => __(
                'Copied into your designs. Change the palette and the wording to suit this business, then activate it. A starter used unchanged will look like a starter.',
                domain: 'wppilot',
            ),
        ],
        expiration: 30,
    );

    $args = ['page' => PAGE_SLUG];
    if ($post instanceof \WP_Post) {
        $args['design'] = $post->ID;
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit();
}
