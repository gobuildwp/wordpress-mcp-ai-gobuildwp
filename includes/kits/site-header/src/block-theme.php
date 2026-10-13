<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteHeader;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/*
 * The block-theme header: the theme's `header` template part, saved with the caller's own block
 * markup. The plumbing is ours: each language's classic menu becomes a navigation post of its
 * own (Polylang's switcher as its last item), linked as translations, and {{navigation_ref}} in
 * the markup points at it; Polylang Pro serves one translated part per language.
 */

/**
 * Navigation-link blocks for a classic menu's items, nested one level.
 *
 * @param list<array{id: int, parent: int, title: string, url: string, object: string, object_id: int, type: string}> $items
 */
function navigation_markup(array $items, bool $switcher): string
{
    $children = [];
    foreach ($items as $item) {
        $children[$item['parent']][] = $item;
    }
    $link = static function (array $item): array {
        $attrs = ['label' => $item['title'], 'url' => $item['url']];
        if ($item['type'] === 'post_type' && $item['object_id'] > 0) {
            $attrs += ['type' => $item['object'], 'id' => $item['object_id'], 'kind' => 'post-type'];
        } else {
            $attrs['kind'] = 'custom';
        }

        return $attrs;
    };
    $json = static fn(array $attrs): string => (string) wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $out = '';
    foreach ($children[0] ?? [] as $item) {
        $sub = $children[$item['id']] ?? [];
        if ($sub === []) {
            $out .= '<!-- wp:navigation-link ' . $json($link($item)) . ' /-->' . "\n";
            continue;
        }
        $out .= '<!-- wp:navigation-submenu ' . $json($link($item)) . ' -->' . "\n";
        foreach ($sub as $child) {
            $out .= '<!-- wp:navigation-link ' . $json($link($child)) . ' /-->' . "\n";
        }
        $out .= '<!-- /wp:navigation-submenu -->' . "\n";
    }
    if ($switcher) {
        $out .= '<!-- wp:polylang/navigation-language-switcher {"dropdown":true,"show_names":true} /-->' . "\n";
    }

    return $out;
}

/**
 * Items of a classic menu as navigation_markup() takes them.
 *
 * @return list<array{id: int, parent: int, title: string, url: string, object: string, object_id: int, type: string}>
 */
function menu_items(\WP_Term $menu): array
{
    $items = wp_get_nav_menu_items($menu->term_id, ['update_post_term_cache' => false]);
    $out = [];
    foreach (is_array($items) ? $items : [] as $item) {
        $out[] = [
            'id' => (int) $item->ID,
            'parent' => (int) $item->menu_item_parent,
            'title' => wp_strip_all_tags((string) $item->title),
            'url' => (string) $item->url,
            'object' => (string) $item->object,
            'object_id' => (int) $item->object_id,
            'type' => (string) $item->type,
        ];
    }

    return $out;
}

/**
 * A page of this language that the theme itself renders, so its header template part shows.
 * A page on an Elementor page template (Canvas, Full Width) prints Elementor's header locations
 * instead, so the home page is skipped when it uses one.
 */
function theme_template_url(string $language): string
{
    $home = $language !== '' && function_exists('pll_home_url') ? (string) pll_home_url($language) : home_url('/');
    $front = (int) get_option('page_on_front');
    if ($front > 0 && $language !== '' && function_exists('pll_get_post')) {
        // A language without its own front page is served the default one.
        $front = (int) pll_get_post($front, $language) ?: $front;
    }
    if (get_option('show_on_front') !== 'page' || $front <= 0 || !str_starts_with((string) get_page_template_slug($front), 'elementor_')) {
        return $home;
    }
    $args = ['post_type' => ['post', 'page'], 'post_status' => 'publish', 'posts_per_page' => 20, 'orderby' => 'date', 'order' => 'DESC'];
    if ($language !== '') {
        $args['lang'] = $language;
    }
    foreach (get_posts($args) as $post) {
        $slug = (string) get_page_template_slug($post);
        if (!str_starts_with($slug, 'elementor_') && get_post_meta($post->ID, '_elementor_edit_mode', true) !== 'builder') {
            return (string) get_permalink($post);
        }
    }

    return '';
}

/** The active theme's header template part, as the site editor sees it. */
function header_part(): ?\WP_Block_Template
{
    $found = get_block_templates(['slug__in' => ['header']], 'wp_template_part');

    return $found[0] ?? null;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function build_block_theme(array $input): array|WP_Error
{
    $part = header_part();
    if (!$part instanceof \WP_Block_Template) {
        return new WP_Error('kit_site_header_no_part', 'The active block theme has no "header" template part to build.', ['status' => 400]);
    }
    $langs = languages();
    $language_list = $langs['languages'] !== [] ? $langs['languages'] : [''];
    $default = $language_list[0];
    $want_switcher = $langs['plugin'] === 'polylang' && (string) ($input['show_language_switcher'] ?? 'auto') !== 'no';
    $woo = class_exists('WooCommerce');
    $logo_id = (int) ($input['logo_id'] ?? 0);
    if ($logo_id === 0) {
        $logo_id = (int) get_theme_mod('custom_logo', 0);
    }
    $found = language_menus($input, $language_list);
    $menus = $found['menus'];
    $facts = site_facts('block-theme', $language_list, $menus, $logo_id);
    $languages = $langs['plugin'] !== '' ? count($language_list) : 1;
    $cart_hidden = get_option('woocommerce_coming_soon') === 'yes' && get_option('woocommerce_store_pages_only') === 'yes';

    if (!empty($input['check_only'])) {
        $checks = [];
        foreach ($language_list as $language) {
            $url = theme_template_url($language);
            if ($url !== '') {
                $checks[$language !== '' ? $language : 'site'] = check_page($url, ['kind' => 'block-theme', 'languages' => $languages, 'cart' => false]);
            }
        }

        return ['check_only' => true, 'builder' => 'block-theme', 'site' => $facts, 'checks' => $checks, 'probe' => probe_instructions($checks)];
    }

    $shared = is_string($input['block_markup'] ?? null) ? (string) $input['block_markup'] : '';
    $own = is_array($input['block_markup_by_language'] ?? null) ? $input['block_markup_by_language'] : [];
    if (trim($shared) === '' && $own === []) {
        if (!empty($input['dry_run'])) {
            return ['dry_run' => true, 'builder' => 'block-theme', 'site' => $facts, 'template_part' => $part->id, 'current_markup' => (string) $part->content, 'notes' => $found['notes'], 'next' => 'Design the header for this brand from site (colours, fonts, logo, tone) as block markup, then call again with block_markup and dry_run: true to have it checked before it is saved.'];
        }

        return new WP_Error('kit_site_header_no_design', 'Pass the header design as block_markup (the header template part\'s blocks). Call with dry_run: true first for the site\'s colours, fonts, menus and the building blocks to use.', ['status' => 400]);
    }

    $labels = is_array($input['labels'] ?? null) ? $input['labels'] : [];
    $findings = [];
    $sources = [];
    foreach ($language_list as $language) {
        $source = is_string($own[$language] ?? null) && trim((string) $own[$language]) !== '' ? (string) $own[$language] : $shared;
        if (trim($source) === '') {
            return new WP_Error('kit_site_header_no_design', sprintf('No design for language "%s": pass block_markup (for every language) or block_markup_by_language.%s.', $language, $language), ['status' => 400]);
        }
        $sources[$language] = $source;
        foreach (precheck_blocks(parse_blocks($source), $source, ['woo' => $woo, 'languages' => $languages, 'unfiltered_html' => current_user_can('unfiltered_html')]) as $f) {
            $findings[] = ['language' => $language] + $f;
        }
        // Tokens with no value, found before anything is made (the navigation ref is made later).
        $unknown = [];
        substitute($source, [
            'navigation_ref' => 1,
            'home_url' => home_url('/'),
            'site_title' => site_name(),
            'labels' => labels_for($labels, $language, $default),
        ], $unknown, true);
        foreach (array_unique($unknown) as $token) {
            $findings[] = ['language' => $language] + finding('error', 'unknown_token', '', sprintf('%s has no value here.', $token), 'Use one of the tokens listed in site.tokens, or add the label to labels.');
        }
    }
    $findings = dedupe_findings($findings);
    $blocking = has_errors($findings);
    $summary = [
        'builder' => 'block-theme',
        'site' => $facts,
        'template_part' => $part->id,
        'languages' => $langs['languages'],
        'language_switcher' => $want_switcher,
        'findings' => $findings,
        'notes' => $found['notes'],
    ];
    if (!empty($input['dry_run'])) {
        return $summary + ['dry_run' => true, 'ready' => !$blocking, 'next' => $blocking ? 'Fix the error findings in the design, then dry-run again.' : 'Ready: call again without dry_run and with confirm: true.'];
    }
    if ($blocking) {
        return new WP_Error('kit_site_header_design_problems', 'The design was not saved: ' . findings_line($findings), ['status' => 422, 'findings' => $findings]);
    }

    $before = ['source' => (string) $part->source, 'wp_id' => (int) $part->wp_id, 'content' => (string) $part->content];
    $created_navigations = [];
    $created_parts = [];
    $restore = static function () use (&$created_navigations, &$created_parts, $before, $part): void {
        foreach (array_merge($created_parts, $created_navigations) as $id) {
            wp_delete_post($id, true);
        }
        restore_part($part->id, $before);
    };
    try {
        $refs = [];
        $uses_navigation = false;
        foreach ($sources as $source) {
            $uses_navigation = $uses_navigation || str_contains($source, '{{navigation_ref}}');
        }
        foreach ($language_list as $language) {
            if (!$uses_navigation || !$menus[$language] instanceof \WP_Term) {
                continue;
            }
            $nav_id = wp_insert_post(wp_slash([
                'post_type' => 'wp_navigation',
                'post_status' => 'publish',
                'post_title' => $language !== '' ? sprintf('Header navigation (%s)', strtoupper($language)) : 'Header navigation',
                'post_content' => navigation_markup(menu_items($menus[$language]), $want_switcher),
            ]), true);
            if ($nav_id instanceof WP_Error) {
                $restore();

                return $nav_id;
            }
            $created_navigations[] = (int) $nav_id;
            if ($language !== '' && function_exists('pll_set_post_language')) {
                pll_set_post_language((int) $nav_id, $language);
            }
            $refs[$language] = (int) $nav_id;
        }
        if (count($refs) > 1 && function_exists('pll_save_post_translations')) {
            pll_save_post_translations($refs);
        }
        $markup = static function (string $language) use ($sources, $refs, $labels, $default): string {
            $unknown = [];

            return (string) substitute($sources[$language], [
                'navigation_ref' => $refs[$language] ?? 0,
                'home_url' => $language !== '' && function_exists('pll_home_url') ? (string) pll_home_url($language) : home_url('/'),
                'site_title' => site_name(),
                'labels' => labels_for($labels, $language, $default),
            ], $unknown, true);
        };
        $saved = save_part($part->id, $markup($default));
        if ($saved instanceof WP_Error) {
            $restore();

            return $saved;
        }
        // Polylang Pro serves a translated template part by slug: "header___ru" for Russian,
        // linked to the default one as its translation. Each carries its own language's menu.
        $notes = $found['notes'];
        if ($langs['plugin'] === 'polylang' && function_exists('pll_set_post_language')) {
            $saved_part = header_part();
            $group = [$default => $saved_part instanceof \WP_Block_Template ? (int) $saved_part->wp_id : 0];
            if ($group[$default] > 0) {
                pll_set_post_language($group[$default], $default);
            }
            foreach (array_slice($language_list, 1) as $language) {
                $existing = get_posts(['post_type' => 'wp_template_part', 'name' => $part->slug . '___' . $language, 'post_status' => 'any', 'posts_per_page' => 1, 'lang' => '']);
                if ($existing !== []) {
                    // A translation someone made by hand: left alone, and said so.
                    $notes[] = sprintf('The %s header template part already exists and was left as it is.', strtoupper($language));
                    continue;
                }
                $id = wp_insert_post(wp_slash([
                    'post_type' => 'wp_template_part',
                    'post_status' => 'publish',
                    'post_name' => $part->slug . '___' . $language,
                    'post_title' => sprintf('Header (%s)', strtoupper($language)),
                    'post_content' => $markup($language),
                ]), true);
                if ($id instanceof WP_Error) {
                    $restore();

                    return $id;
                }
                $created_parts[] = (int) $id;
                wp_set_post_terms((int) $id, [get_stylesheet()], 'wp_theme');
                wp_set_post_terms((int) $id, ['header'], 'wp_template_part_area');
                pll_set_post_language((int) $id, $language);
                $group[$language] = (int) $id;
            }
            if (count(array_filter($group)) > 1 && function_exists('pll_save_post_translations')) {
                pll_save_post_translations(array_filter($group));
            }
        }

        $checks = [];
        $failed = false;
        foreach ($language_list as $language) {
            $url = theme_template_url($language);
            if ($url === '') {
                $notes[] = sprintf('%s: not checked, every page in this language uses an Elementor page template, which prints the Elementor header instead of the theme header.', $language !== '' ? strtoupper($language) : 'Site');
                continue;
            }
            $result = check_page($url, ['kind' => 'block-theme', 'languages' => $want_switcher ? $languages : 1, 'cart' => $woo && !$cart_hidden && str_contains($markup($language), 'woocommerce/mini-cart')]);
            $checks[$language !== '' ? $language : 'site'] = $result;
            $failed = $failed || !$result['passed'];
        }
        if ($failed && empty($input['keep_on_fail'])) {
            $restore();

            return new WP_Error('kit_site_header_check_failed', 'The header was saved but did not pass its checks on the served page, so the previous header was restored. Fix the design and build again (or pass keep_on_fail: true to keep it while you fix it). ' . findings_line(array_merge(...array_values(array_map(static fn(array $c): array => $c['findings'], $checks)))), ['status' => 422, 'checks' => $checks]);
        }
    } catch (\Throwable $e) {
        $restore();

        return new WP_Error('kit_site_header_failed', 'Building the header failed and the previous header was restored: ' . $e->getMessage());
    }

    return array_merge($summary, [
        'notes' => $notes,
        'kind' => 'block-theme',
        'navigations' => $refs,
        'created_navigation_ids' => $created_navigations,
        'created_part_ids' => $created_parts,
        'part_before' => $before,
        'checks' => $checks,
        'passed' => !$failed,
        'probe' => probe_instructions($checks),
    ]);
}

/** Save the header template part through WordPress's own templates controller. */
function save_part(string $id, string $content): bool|WP_Error
{
    $request = new \WP_REST_Request('POST', '/wp/v2/template-parts/' . $id);
    $request->set_param('content', $content);
    $response = rest_do_request($request);

    return $response->is_error() ? $response->as_error() : true;
}

/**
 * Put the template part back: its earlier database copy, or the theme file when there was none.
 *
 * @param array{source: string, wp_id: int, content: string} $before
 */
function restore_part(string $id, array $before): bool|WP_Error
{
    if ($before['source'] === 'custom' && $before['wp_id'] > 0) {
        return save_part($id, $before['content']);
    }
    $request = new \WP_REST_Request('DELETE', '/wp/v2/template-parts/' . $id);
    $request->set_param('force', true);
    $response = rest_do_request($request);
    // Nothing to delete means it is already the theme file's.
    return $response->is_error() && $response->get_status() !== 404 ? $response->as_error() : true;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|WP_Error
 */
function undo_block_theme(array $payload): array|WP_Error
{
    foreach ((array) ($payload['created_part_ids'] ?? []) as $id) {
        $post = get_post((int) $id);
        if ($post instanceof \WP_Post && $post->post_type === 'wp_template_part') {
            wp_delete_post((int) $id, true);
        }
    }
    foreach ((array) ($payload['created_navigation_ids'] ?? []) as $id) {
        $post = get_post((int) $id);
        if ($post instanceof \WP_Post && $post->post_type === 'wp_navigation') {
            wp_delete_post((int) $id, true);
        }
    }
    $before = (array) ($payload['part_before'] ?? []);
    $id = (string) ($payload['template_part'] ?? '');
    $restored = restore_part($id, ['source' => (string) ($before['source'] ?? ''), 'wp_id' => (int) ($before['wp_id'] ?? 0), 'content' => (string) ($before['content'] ?? '')]);
    if ($restored instanceof WP_Error) {
        return $restored;
    }
    $part = header_part();
    $verified = $part instanceof \WP_Block_Template
        && (($before['source'] ?? '') === 'custom' ? $part->content === (string) $before['content'] : $part->source === 'theme');
    foreach ((array) ($payload['created_navigation_ids'] ?? []) as $nav) {
        $verified = $verified && !get_post((int) $nav) instanceof \WP_Post;
    }

    return ['template_part' => $id, 'navigations_deleted' => (array) ($payload['created_navigation_ids'] ?? []), 'verified' => $verified];
}
