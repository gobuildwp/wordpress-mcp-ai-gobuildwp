<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteHeader;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Page;
use WPPilot\Kits\Runtime\Ledger;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/checks.php';
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/probe.php';
require_once __DIR__ . '/block-theme.php';

/** Undo: delete the headers (and switcher menu) this call made, give the old headers their conditions back. */
const STRATEGY = 'site-header/replace';

/** On the default-language header: {language slug: template id} of its translations. */
const TRANSLATIONS_META = '_wppilot_kit_header_translations';

/** Marks a menu this kit made to hold the language switcher, so a later run reuses it. */
const SWITCHER_MENU_NAME = 'Language switcher (header)';

/**
 * What the site can build a header with.
 *
 * @return array{builder: string, reason: string}
 */
function detect_builder(string $wanted): array
{
    $elementor_pro = defined('ELEMENTOR_PRO_VERSION') && class_exists('\ElementorPro\Modules\ThemeBuilder\Module');
    $block_theme = function_exists('wp_is_block_theme') && wp_is_block_theme();
    if ($wanted === 'elementor' || ($wanted === 'auto' && $elementor_pro)) {
        return $elementor_pro
            ? ['builder' => 'elementor', 'reason' => '']
            : ['builder' => '', 'reason' => 'An Elementor header is a Theme Builder template, which needs Elementor Pro. Without it the header is the theme\'s own.'];
    }
    if ($wanted === 'block-theme' || ($wanted === 'auto' && $block_theme)) {
        return $block_theme
            ? ['builder' => 'block-theme', 'reason' => '']
            : ['builder' => '', 'reason' => 'The active theme is not a block theme.'];
    }

    return ['builder' => '', 'reason' => 'This site has neither Elementor Pro nor a block theme, so its header is the classic theme\'s own: assign the menu to the theme\'s primary menu location (Appearance > Menus > Manage Locations) and set the logo in the Customizer.'];
}

/**
 * Text and accent colours from the active Elementor kit, when someone has set them. Elementor's
 * factory palette (light blue, grey, green) is nobody's brand, so it is ignored.
 *
 * @return array<string, string>
 */
function kit_palette(): array
{
    if (!class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance->kits_manager)) {
        return [];
    }
    $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
    $system = is_object($kit) ? $kit->get_settings('system_colors') : null;
    $found = [];
    foreach (is_array($system) ? $system : [] as $row) {
        if (is_array($row) && isset($row['_id'], $row['color'])) {
            $found[(string) $row['_id']] = strtoupper((string) $row['color']);
        }
    }
    $factory = ['primary' => '#6EC1E4', 'secondary' => '#54595F', 'text' => '#7A7A7A', 'accent' => '#61CE70'];
    if ($found === [] || array_intersect_assoc($found, $factory) === $factory) {
        return [];
    }
    $palette = [];
    foreach (['text' => 'secondary', 'accent' => 'accent'] as $ours => $theirs) {
        $hex = sanitize_hex_color($found[$theirs] ?? '');
        if (is_string($hex) && $hex !== '') {
            $palette[$ours] = $hex;
        }
    }

    return $palette;
}

/**
 * The site's languages, default first, when a multilingual plugin manages them.
 *
 * @return array{plugin: string, default: string, languages: list<string>}
 */
function languages(): array
{
    if (function_exists('pll_languages_list') && function_exists('pll_default_language')) {
        $list = array_values(array_map('strval', (array) pll_languages_list(['fields' => 'slug'])));
        $default = (string) pll_default_language('slug');
        if (count($list) > 1) {
            usort($list, static fn(string $a, string $b): int => ($b === $default) <=> ($a === $default));

            return ['plugin' => 'polylang', 'default' => $default, 'languages' => $list];
        }
    }

    return ['plugin' => '', 'default' => '', 'languages' => []];
}

/** The language a request is in, as the multilingual plugin sees it. */
function current_language(): string
{
    return function_exists('pll_current_language') ? (string) pll_current_language('slug') : '';
}

/**
 * The menu for one language: given explicitly, else the one whose name or slug ends in the
 * language code, else (single-language sites) the menu with the most items.
 *
 * @param array<string, mixed> $input
 */
function pick_menu(array $input, string $language): ?\WP_Term
{
    $given = $language !== '' && is_array($input['menus'] ?? null) && isset($input['menus'][$language])
        ? $input['menus'][$language]
        : ($input['menu'] ?? null);
    if ($given !== null && $given !== '' && $given !== 0) {
        $menu = wp_get_nav_menu_object(is_numeric($given) ? (int) $given : (string) $given);

        return $menu instanceof \WP_Term ? $menu : null;
    }
    $candidates = [];
    foreach (wp_get_nav_menus() as $menu) {
        if ($menu->name === SWITCHER_MENU_NAME) {
            continue;
        }
        if ($language === '' || menu_is_for_language((string) $menu->name, $language)) {
            $candidates[] = $menu;
        }
    }
    // A menu named main, primary or header wins; then the longest.
    usort($candidates, static function (\WP_Term $a, \WP_Term $b): int {
        $main = static fn(\WP_Term $m): int => preg_match('/main|primary|header|главн|galven/iu', $m->name) === 1 ? 1 : 0;

        return [$main($b), $b->count] <=> [$main($a), $a->count];
    });

    return $candidates[0] ?? null;
}

/** "Main menu (EN)", "main-en", "Menu EN": the language code as the last word. */
function menu_is_for_language(string $name, string $language): bool
{
    return preg_match('/(^|[\s(_-])' . preg_quote($language, '/') . '\)?\s*$/iu', $name) === 1;
}

/**
 * Top-level items of a menu, as the width estimate needs them.
 *
 * @return list<array{title: string, children: bool}>
 */
function menu_top_level(\WP_Term $menu): array
{
    $items = wp_get_nav_menu_items($menu->term_id, ['update_post_term_cache' => false]);
    $items = is_array($items) ? $items : [];
    $parents = [];
    foreach ($items as $item) {
        $parents[(int) $item->menu_item_parent] = true;
    }
    $top = [];
    foreach ($items as $item) {
        if ((int) $item->menu_item_parent === 0) {
            $top[] = ['title' => wp_strip_all_tags((string) $item->title), 'children' => isset($parents[(int) $item->ID])];
        }
    }

    return $top;
}

/**
 * The menu holding Polylang's language switcher item, made once and reused.
 *
 * @return array{slug: string, id: int, created: bool}|WP_Error
 */
function ensure_switcher_menu(): array|WP_Error
{
    $existing = wp_get_nav_menu_object(SWITCHER_MENU_NAME);
    if ($existing instanceof \WP_Term) {
        return ['slug' => (string) $existing->slug, 'id' => (int) $existing->term_id, 'created' => false];
    }
    $id = wp_create_nav_menu(SWITCHER_MENU_NAME);
    if ($id instanceof WP_Error) {
        return $id;
    }
    $item = wp_update_nav_menu_item((int) $id, 0, [
        'menu-item-title' => __('Languages', domain: 'wppilot'),
        'menu-item-url' => '#pll_switcher',
        'menu-item-type' => 'custom',
        'menu-item-status' => 'publish',
    ]);
    if ($item instanceof WP_Error) {
        wp_delete_nav_menu((int) $id);

        return $item;
    }
    // Polylang's own menu-item options: the current language as the parent, the others under it.
    update_post_meta((int) $item, '_pll_menu_item', wp_slash([
        'hide_if_no_translation' => 0,
        'hide_current' => 0,
        'force_home' => 0,
        'show_flags' => 0,
        'show_names' => 1,
        'dropdown' => 1,
    ]));
    $menu = wp_get_nav_menu_object((int) $id);

    return ['slug' => $menu instanceof \WP_Term ? (string) $menu->slug : '', 'id' => (int) $id, 'created' => true];
}

/**
 * Header templates that currently have display conditions: the ones a new header replaces.
 *
 * @return array<int, list<string>>
 */
function active_headers(): array
{
    $found = [];
    $ids = get_posts([
        'post_type' => 'elementor_library',
        'post_status' => 'any',
        'posts_per_page' => 200,
        'fields' => 'ids',
        'meta_key' => '_elementor_template_type',
        'meta_value' => 'header',
        // Replacement must account for every active header, including other languages.
        // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- Filtering by the current language would miss headers being replaced.
        'suppress_filters' => true,
        'lang' => '',
    ]);
    foreach ($ids as $id) {
        $conditions = get_post_meta((int) $id, '_elementor_conditions', true);
        if (is_array($conditions) && $conditions !== []) {
            $found[(int) $id] = array_values(array_map('strval', $conditions));
        }
    }

    return $found;
}

/** @param list<string> $conditions */
function save_conditions(int $post_id, array $conditions): bool
{
    if (!class_exists(\ElementorPro\Modules\ThemeBuilder\Module::class)) {
        return false;
    }
    $manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
    $parts = [];
    foreach ($conditions as $condition) {
        // Elementor stores "include/singular/page/12"; save_conditions() wants it in parts.
        $parts[] = explode('/', $condition);
    }

    return (bool) $manager->save_conditions($post_id, $parts);
}

/**
 * The menu each language uses: given, else found by name, else the longest other language's.
 *
 * @param array<string, mixed> $input
 * @param list<string> $language_list
 * @return array{menus: array<string, ?\WP_Term>, notes: list<string>}
 */
function language_menus(array $input, array $language_list): array
{
    $menus = [];
    foreach ($language_list as $language) {
        $menus[$language] = pick_menu($input, $language);
    }
    // A language without a menu of its own gets the longest one found for another, so its pages
    // still have navigation (in that menu's language, which the notes say).
    $fallback = null;
    foreach ($menus as $menu) {
        if ($menu instanceof \WP_Term && (!$fallback instanceof \WP_Term || $menu->count > $fallback->count)) {
            $fallback = $menu;
        }
    }
    $notes = [];
    foreach ($language_list as $language) {
        if (!$menus[$language] instanceof \WP_Term && $language !== '' && $fallback instanceof \WP_Term) {
            $menus[$language] = $fallback;
            $notes[] = sprintf('No menu for language "%s" was found (one named like "Main menu (%s)"), so its header uses "%s". Pass menus.%s to choose another.', $language, strtoupper($language), $fallback->name, $language);
        } elseif (!$menus[$language] instanceof \WP_Term) {
            $notes[] = 'No menu was found. Create one under Appearance > Menus, or pass menu.';
        }
    }

    return ['menus' => $menus, 'notes' => $notes];
}

/**
 * What the caller needs to design the header: the site's colours, fonts, logo, hero, menus per
 * language, what is already there, and the building blocks it can use.
 *
 * @param list<string> $language_list
 * @param array<string, ?\WP_Term> $menus
 * @return array<string, mixed>
 */
function site_facts(string $builder, array $language_list, array $menus, int $logo_id): array
{
    $look = site_look($builder, $logo_id);
    $hero = front_hero();
    $menu_facts = [];
    foreach ($language_list as $language) {
        $menu = $menus[$language];
        $menu_facts[$language !== '' ? $language : 'site'] = $menu instanceof \WP_Term ? [
            'id' => (int) $menu->term_id,
            'name' => (string) $menu->name,
            'top_level' => array_column(menu_top_level($menu), 'title'),
        ] : null;
    }
    $logo_url = $logo_id > 0 ? (string) wp_get_attachment_image_url($logo_id, 'full') : '';

    return [
        'title' => site_name(),
        'tagline' => html_entity_decode((string) get_bloginfo('description'), ENT_QUOTES, 'UTF-8'),
        'logo' => $logo_id > 0 ? ['id' => $logo_id, 'url' => $logo_url] : null,
        'logo_candidates' => $logo_id > 0 ? [] : logo_candidates(),
        'colors' => $look['colors'],
        'home_page_paint' => home_palette(),
        'fonts' => $look['fonts'],
        'colors_from' => $look['sources'] !== [] ? $look['sources'] : ['no global colours or fonts are set: home_page_paint has the colours and fonts the home page is painted with'],
        'home_page' => ['url' => home_url('/'), 'hero' => $hero['detail'], 'has_hero' => $hero['has_hero']],
        'languages' => array_values(array_filter($language_list)),
        'menus' => $menu_facts,
        'shop' => class_exists('WooCommerce'),
        'current_headers' => $builder === 'elementor' ? array_keys(active_headers()) : [],
        'tokens' => TOKENS,
        'building_blocks' => building_blocks($builder),
    ];
}

/**
 * The pieces the caller places in its own design, as settings that are known to render well.
 *
 * @return array<string, mixed>
 */
function building_blocks(string $builder): array
{
    if ($builder === 'block-theme') {
        return [
            'navigation' => '<!-- wp:navigation {"ref":"{{navigation_ref}}","overlayMenu":"mobile","layout":{"type":"flex","justifyContent":"right","flexWrap":"nowrap"}} /-->  (each language\'s menu, with the language switcher as its last item when Polylang is active)',
            'cart' => '<!-- wp:woocommerce/mini-cart /-->',
            'logo' => '<!-- wp:site-logo {"width":44} /--> (the Customizer logo) or an image block with the logo id',
            'title' => '<!-- wp:site-title {"level":0} /-->',
        ];
    }

    return [
        'menu' => ['widgetType' => 'nav-menu', 'settings' => ['menu' => '{{menu}}', 'menu_name' => 'Main menu', 'layout' => 'horizontal', 'dropdown' => 'tablet', 'toggle' => 'burger', 'full_width' => 'stretch', '_element_width' => 'auto', '_flex_size' => 'grow', 'align_items' => 'end']],
        'language_switcher' => ['widgetType' => 'nav-menu', 'settings' => ['menu' => '{{language_switcher}}', 'menu_name' => 'Language', 'layout' => 'horizontal', 'dropdown' => 'none', 'pointer' => 'none', 'submenu_icon' => ['value' => 'fas fa-chevron-down', 'library' => 'fa-solid'], '_element_width' => 'auto', '_flex_size' => 'none'], 'note' => 'One item, the current language, with the others in its dropdown. Style it like the menu (menu_typography_*, color_menu_item, color_dropdown_item, background_color_dropdown_item).'],
        'cart' => ['widgetType' => 'woocommerce-menu-cart', 'settings' => ['icon' => 'bag-medium', 'items_indicator' => 'bubble', 'hide_empty_indicator' => 'hide', 'show_subtotal' => '', 'cart_type' => 'side-cart', 'toggle_button_border_width' => ['unit' => 'px', 'size' => 0], 'toggle_button_background_color' => 'rgba(0,0,0,0)', '_element_width' => 'auto', '_flex_size' => 'none'], 'note' => 'Only when WooCommerce is active (site.shop).'],
        'logo' => ['widgetType' => 'image', 'settings' => ['image' => ['id' => '<logo id>', 'url' => '<logo url>'], 'link_to' => 'custom', 'link' => ['url' => '{{home_url}}'], 'width' => ['unit' => 'px', 'size' => 44], '_element_width' => 'auto', '_flex_size' => 'none']],
        'row' => ['elType' => 'container', 'settings' => ['html_tag' => 'header', 'content_width' => 'boxed', 'flex_direction' => 'row', 'flex_direction_tablet' => 'row', 'flex_direction_mobile' => 'row', 'flex_wrap' => 'nowrap', 'flex_wrap_tablet' => 'nowrap', 'flex_wrap_mobile' => 'nowrap', 'flex_align_items' => 'center'], 'note' => 'Sticky: "sticky": "top", "sticky_on": ["desktop","tablet","mobile"], "sticky_effects_offset": 40, then style the scrolled state in custom_css under selector.elementor-sticky--effects.'],
    ];
}

/**
 * The labels for one language: {key: text} or {key: {language: text}}, the default language's
 * text standing in for a language without one.
 *
 * @param array<string, mixed> $labels
 * @return array<string, string>
 */
function labels_for(array $labels, string $language, string $default): array
{
    $out = [];
    foreach ($labels as $key => $value) {
        if (is_array($value)) {
            $text = $value[$language] ?? $value[$default] ?? reset($value);
            $out[(string) $key] = is_scalar($text) ? (string) $text : '';
        } elseif (is_scalar($value)) {
            $out[(string) $key] = (string) $value;
        }
    }

    return $out;
}

/**
 * Build the header from the caller's design.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function build(array $input): array|WP_Error
{
    $target = detect_builder((string) ($input['builder'] ?? 'auto'));
    if ($target['builder'] === 'block-theme') {
        return build_block_theme($input);
    }
    if ($target['builder'] !== 'elementor') {
        return new WP_Error('kit_site_header_unsupported', $target['reason'] !== '' ? $target['reason'] : 'No header builder is available on this site.', ['status' => 400]);
    }

    $langs = languages();
    $language_list = $langs['languages'] !== [] ? $langs['languages'] : [''];
    $default = $language_list[0];
    $logo_id = (int) ($input['logo_id'] ?? 0);
    if ($logo_id === 0) {
        $logo_id = (int) get_theme_mod('custom_logo', 0);
    }
    $found = language_menus($input, $language_list);
    $menus = $found['menus'];
    $facts = site_facts('elementor', $language_list, $menus, $logo_id);
    $woo = class_exists('WooCommerce');

    if (!empty($input['check_only'])) {
        return check_current_elementor($language_list, $langs['plugin'] !== '' ? count($language_list) : 1, $woo) + ['site' => $facts];
    }

    $elementor = is_array($input['elementor'] ?? null) ? $input['elementor'] : [];
    $shared = is_array($elementor['elements'] ?? null) ? array_values($elementor['elements']) : [];
    $own = is_array($elementor['elements_by_language'] ?? null) ? $elementor['elements_by_language'] : [];
    if ($shared === [] && $own === []) {
        if (!empty($input['dry_run'])) {
            return ['dry_run' => true, 'builder' => 'elementor', 'site' => $facts, 'notes' => $found['notes'], 'next' => 'Design the header for this brand from site (colours, fonts, logo, tone), then call again with elementor.elements and dry_run: true to have it checked before it is saved.'];
        }

        return new WP_Error('kit_site_header_no_design', 'Pass the header design as elementor.elements (Elementor elements, a container at the top). Call with dry_run: true first for the site\'s colours, fonts, logo, menus and the building blocks to use.', ['status' => 400]);
    }

    $switcher_slug = '';
    $existing_switcher = wp_get_nav_menu_object(SWITCHER_MENU_NAME);
    $uses_switcher = uses_token($elementor, 'language_switcher');
    if ($uses_switcher && $langs['plugin'] === '') {
        return new WP_Error('kit_site_header_no_languages', 'The design uses {{language_switcher}}, which needs Polylang with two or more languages; this site has none.', ['status' => 400]);
    }
    $switcher_slug = $existing_switcher instanceof \WP_Term ? (string) $existing_switcher->slug : sanitize_title(SWITCHER_MENU_NAME);
    $labels = is_array($input['labels'] ?? null) ? $input['labels'] : [];
    $globals = kit_palette_ids();
    $page_background = kit_background() !== '' ? kit_background() : '#ffffff';

    $unfiltered_html = current_user_can('unfiltered_html');
    $trees = [];
    $findings = [];
    foreach ($language_list as $language) {
        $source = is_array($own[$language] ?? null) ? array_values($own[$language]) : $shared;
        if ($source === []) {
            return new WP_Error('kit_site_header_no_design', sprintf('No design for language "%s": pass elementor.elements (for every language) or elementor.elements_by_language.%s.', $language, $language), ['status' => 400]);
        }
        $menu = $menus[$language];
        $unknown = [];
        $tree = substitute($source, [
            'menu' => $menu instanceof \WP_Term ? (string) $menu->slug : '',
            'language_switcher' => $switcher_slug,
            'home_url' => $language !== '' && function_exists('pll_home_url') ? (string) pll_home_url($language) : home_url('/'),
            'site_title' => site_name(),
            'labels' => labels_for($labels, $language, $default),
        ], $unknown);
        foreach (array_unique($unknown) as $token) {
            $findings[] = ['language' => $language] + finding('error', 'unknown_token', '', sprintf('%s has no value here.', $token), $token === '{{menu}}' ? 'Create the menu, or pass menu / menus.' : 'Use one of the tokens listed in site.tokens, or add the label to labels.');
        }
        foreach (precheck_elementor($source, ['woo' => $woo, 'languages' => $langs['plugin'] !== '' ? count($language_list) : 1, 'globals' => $globals, 'page_background' => $page_background, 'switcher_slug' => $switcher_slug, 'unfiltered_html' => $unfiltered_html]) as $f) {
            $findings[] = ['language' => $language] + $f;
        }
        if (!$unfiltered_html) {
            // Labels are substituted after the precheck above: check what they put in, too.
            foreach (precheck_elementor($tree, ['woo' => $woo, 'languages' => 1, 'globals' => [], 'page_background' => $page_background, 'unfiltered_html' => false]) as $f) {
                if ($f['check'] === 'html_not_allowed') {
                    $findings[] = ['language' => $language] + $f;
                }
            }
            $tree = kses_design($tree, 'wp_kses_post');
        }
        $trees[$language] = $tree;
    }
    $findings = dedupe_findings($findings);
    $blocking = has_errors($findings);
    $summary = [
        'builder' => 'elementor',
        'site' => $facts,
        'languages' => $langs['languages'],
        'replaces' => array_keys(active_headers()),
        'findings' => $findings,
        'notes' => $found['notes'],
    ];
    if (!empty($input['dry_run'])) {
        return $summary + ['dry_run' => true, 'ready' => !$blocking, 'next' => $blocking ? 'Fix the error findings in the design, then dry-run again.' : 'Ready: call again without dry_run and with confirm: true.'];
    }
    if ($blocking) {
        return new WP_Error('kit_site_header_design_problems', 'The design was not saved: ' . findings_line($findings), ['status' => 422, 'findings' => $findings]);
    }

    $created = [];
    $created_menu = 0;
    $demoted = [];
    $undo_partial = static function () use (&$created, &$created_menu, &$demoted): void {
        foreach ($created as $id) {
            // Clear the display conditions first so Elementor Pro's conditions cache
            // never points at a deleted template.
            save_conditions($id, []);
            wp_delete_post($id, true);
        }
        foreach ($demoted as $id => $conditions) {
            save_conditions($id, $conditions);
        }
        if ($created_menu > 0) {
            wp_delete_nav_menu($created_menu);
        }
    };

    try {
        return write($input, $summary, $trees, $uses_switcher, $woo, $language_list, $created, $created_menu, $demoted, $undo_partial);
    } catch (\Throwable $e) {
        $undo_partial();

        return new WP_Error('kit_site_header_failed', 'Building the header failed and what it had made was removed: ' . $e->getMessage());
    }
}

/**
 * Save each language's header, put it on every page, and check what visitors get.
 *
 * @param array<string, mixed> $input
 * @param array<string, mixed> $summary
 * @param array<string, list<array<string, mixed>>> $trees
 * @param list<string> $language_list
 * @param list<int> $created
 * @param array<int, list<string>> $demoted
 * @return array<string, mixed>|WP_Error
 */
function write(array $input, array $summary, array $trees, bool $uses_switcher, bool $woo, array $language_list, array &$created, int &$created_menu, array &$demoted, callable $undo_partial): array|WP_Error
{
    $switcher = ['slug' => '', 'id' => 0, 'created' => false];
    if ($uses_switcher) {
        $switcher = ensure_switcher_menu();
        if ($switcher instanceof WP_Error) {
            return $switcher;
        }
        $created_menu = $switcher['created'] ? $switcher['id'] : 0;
    }

    $templates = [];
    $dropped = [];
    foreach ($trees as $language => $tree) {
        $document = \Elementor\Plugin::$instance->documents->create('header', [
            'post_title' => $language !== '' ? sprintf('Site header (%s)', strtoupper((string) $language)) : 'Site header',
            'post_status' => 'publish',
        ]);
        if ($document instanceof WP_Error || !is_object($document)) {
            $undo_partial();

            return $document instanceof WP_Error ? $document : new WP_Error('kit_site_header_create_failed', 'Elementor could not create the header template.');
        }
        $post_id = (int) $document->get_main_id();
        $created[] = $post_id;
        $saved = save_elementor_tree($post_id, $tree, $document);
        if ($saved instanceof WP_Error) {
            $undo_partial();

            return $saved;
        }
        if ($saved !== []) {
            $dropped[$language !== '' ? $language : 'site'] = $saved;
        }
        $templates[] = ['id' => $post_id, 'language' => (string) $language, 'edit_url' => (string) $document->get_edit_url()];
    }

    // The new header takes over every page: the old ones keep their content, lose their conditions.
    foreach (active_headers() as $old_id => $conditions) {
        if (!in_array($old_id, $created, strict: true)) {
            $demoted[$old_id] = $conditions;
            save_conditions($old_id, []);
        }
    }
    $primary = $templates[0]['id'];
    save_conditions($primary, ['include/general']);
    if (count($templates) > 1) {
        $map = [];
        foreach ($templates as $template) {
            $map[$template['language']] = $template['id'];
        }
        update_post_meta($primary, TRANSLATIONS_META, wp_slash($map));
    }

    $checks = [];
    $failed = false;
    foreach ($templates as $template) {
        $language = $template['language'];
        $url = $language !== '' && function_exists('pll_home_url') ? (string) pll_home_url($language) : home_url('/');
        $result = check_page($url, ['kind' => 'elementor', 'template_id' => $template['id'], 'languages' => count($language_list) > 1 ? count($language_list) : 1, 'cart' => $woo]);
        $checks[$language !== '' ? $language : 'site'] = $result;
        $failed = $failed || !$result['passed'];
    }
    $keep = !empty($input['keep_on_fail']);
    if ($failed && !$keep) {
        // The default: a header that did not check out is not left on the site.
        $undo_partial();

        return new WP_Error('kit_site_header_check_failed', 'The header was saved but did not pass its checks on the served page, so it was removed and the previous header restored. Fix the design and build again (or pass keep_on_fail: true to keep it while you fix it). ' . findings_line(array_merge(...array_values(array_map(static fn(array $c): array => $c['findings'], $checks)))), [
            'status' => 422,
            'checks' => $checks,
        ]);
    }

    return $summary + [
        'templates' => $templates,
        'created_ids' => $created,
        'created_menu_id' => $created_menu,
        'switcher_menu_id' => $switcher['id'],
        'demoted' => $demoted,
        'checks' => $checks,
        'passed' => !$failed,
        'dropped' => $dropped,
        'probe' => probe_instructions($checks),
    ];
}

/**
 * Save an Elementor tree into a header template: through the host's Elementor content writer
 * when it has one (its normalisation and validation), else Elementor's own document save.
 *
 * @param list<array<string, mixed>> $tree
 * @return list<mixed>|WP_Error  What the validator dropped, when anything.
 */
function save_elementor_tree(int $post_id, array $tree, object $document): array|WP_Error
{
    $writer = Runtime\host()->extension('elementor-content-writer');
    $result = is_callable($writer) ? $writer($post_id, $tree, 'header') : null;
    if ($result instanceof WP_Error) {
        return $result;
    }
    if ($result !== null) {
        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            $error = is_array($result) ? (string) ($result['error'] ?? 'invalid design') : 'invalid design';

            return new WP_Error('kit_site_header_invalid_design', 'Elementor refused the design: ' . $error, ['status' => 422, 'validation' => $result]);
        }

        return array_values((array) ($result['dropped'] ?? []));
    }
    $counter = 0;
    $normalize = static function (array $elements) use (&$normalize, &$counter): array {
        $out = [];
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            $counter++;
            $node = [
                'id' => preg_match('/^[0-9a-f]{7,8}$/', (string) ($element['id'] ?? '')) === 1 ? (string) $element['id'] : substr(md5(uniqid('', true) . $counter), 0, 7),
                'elType' => el_type($element),
                'settings' => is_array($element['settings'] ?? null) ? $element['settings'] : [],
                'elements' => $normalize(is_array($element['elements'] ?? null) ? $element['elements'] : []),
            ];
            if ($node['elType'] === 'widget') {
                $node['widgetType'] = widget_type($element);
            }
            if (isset($element['isInner'])) {
                $node['isInner'] = (bool) $element['isInner'];
            }
            $out[] = $node;
        }

        return $out;
    };
    $document->save(['elements' => $normalize($tree), 'settings' => ['post_status' => 'publish']]);

    return [];
}

/**
 * Fetch a page as a visitor and check its header, its images and its same-site links.
 *
 * @param array{kind: string, template_id?: int, languages: int, cart: bool} $expect
 * @return array{url: string, probe_url: string, passed: bool, findings: list<array<string, string>>}
 */
function check_page(string $url, array $expect): array
{
    $page = Page::fetch(add_query_arg('wppilot-kit-header-check', (string) time(), $url));
    if (!is_array($page)) {
        return ['url' => $url, 'probe_url' => probe_url($url), 'passed' => false, 'findings' => [finding('error', 'served', '', $page->get_error_message(), 'Check that the home page loads for a visitor.')]];
    }
    $result = check_served($page['html'], $expect);
    $findings = $result['findings'];
    $budget = 12;
    foreach (['image' => $result['images'], 'link' => $result['links']] as $kind => $targets) {
        foreach ($targets as $target => $element) {
            $target = (string) $target;
            $absolute = str_starts_with($target, '/') && !str_starts_with($target, '//') ? home_url($target) : $target;
            if (!Page::is_same_site($absolute) || $budget-- <= 0) {
                continue;
            }
            $got = Page::fetch($absolute, 10);
            $status = is_array($got) ? $got['status'] : 0;
            if ($status >= 400 || $status === 0) {
                $findings[] = finding('error', 'broken_' . $kind, (string) $element, sprintf('The %s %s returns %s.', $kind, $target, $status === 0 ? 'no response' : 'HTTP ' . $status), $kind === 'image' ? 'Pick an image that exists in the media library.' : 'Point the link at a page that exists.');
            }
        }
    }

    return ['url' => $url, 'probe_url' => probe_url($url), 'passed' => !has_errors($findings), 'findings' => $findings];
}

/**
 * Check the header the site serves now, without writing: the same checks as after a build.
 *
 * @param list<string> $language_list
 * @return array<string, mixed>
 */
function check_current_elementor(array $language_list, int $languages, bool $woo): array
{
    $checks = [];
    foreach ($language_list as $language) {
        $url = $language !== '' && function_exists('pll_home_url') ? (string) pll_home_url($language) : home_url('/');
        $page = Page::fetch(add_query_arg('wppilot-kit-header-check', (string) time(), $url));
        $html = is_array($page) ? $page['html'] : '';
        $template = preg_match('/data-elementor-type="header"\s+data-elementor-id="(\d+)"|data-elementor-id="(\d+)"[^>]*data-elementor-type="header"/', $html, $m) === 1 ? (int) ($m[1] !== '' ? $m[1] : $m[2]) : 0;
        $checks[$language !== '' ? $language : 'site'] = ['template_id' => $template] + check_page($url, ['kind' => 'elementor', 'template_id' => $template, 'languages' => $languages, 'cart' => $woo]);
    }

    return ['check_only' => true, 'checks' => $checks, 'probe' => probe_instructions($checks)];
}

/**
 * How to run the in-browser half of the check.
 *
 * @param array<string, array{probe_url: string}> $checks
 * @return array<string, mixed>
 */
function probe_instructions(array $checks): array
{
    return [
        'urls' => array_map(static fn(array $c): string => $c['probe_url'], $checks),
        'widths' => [1440, 768, 390],
        'how' => 'The server cannot lay the page out, so overlap, menu rows, wrapping labels, horizontal scroll, header height, the menu button opening and computed contrast are checked in a browser: open each URL at 1440, 768 and 390 px wide and, once loaded, read window.siteHeaderProbe (or the data-wppilot-kit-header-probe attribute on <html>). Each finding names the element (Elementor id) and what to change. Fix your design and build again until every width passes, then look at the screenshots yourself. Without a browser tool, ask the person to look at those widths.',
    ];
}

/** Findings that repeat across languages, once. */
function dedupe_findings(array $findings): array
{
    $seen = [];
    $out = [];
    foreach ($findings as $f) {
        $key = $f['check'] . '|' . $f['element_id'] . '|' . $f['detail'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $f;
    }

    return $out;
}

/** The error findings as one line: MCP clients see an error's message, not its data. */
function findings_line(array $findings): string
{
    $lines = [];
    foreach ($findings as $f) {
        if (($f['severity'] ?? '') === 'error') {
            // The same problem in every language is said once.
            $lines[$f['check'] . $f['element_id'] . $f['detail']] = sprintf('[%s%s] %s Fix: %s', $f['check'], ($f['element_id'] ?? '') !== '' ? ' ' . $f['element_id'] : '', $f['detail'], $f['fix']);
        }
    }

    return implode(' ', $lines);
}

/**
 * Swap the default-language header for its translation on a translated page.
 *
 * @param mixed $template_id
 * @return mixed
 */
function translated_template(mixed $template_id): mixed
{
    if (!is_numeric($template_id)) {
        return $template_id;
    }
    $map = get_post_meta((int) $template_id, TRANSLATIONS_META, true);
    if (!is_array($map) || $map === []) {
        return $template_id;
    }
    $language = current_language();
    $other = $language !== '' ? (int) ($map[$language] ?? 0) : 0;

    return $other > 0 && get_post_status($other) === 'publish' ? $other : $template_id;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|WP_Error
 */
function undo(array $payload): array|WP_Error
{
    if (($payload['kind'] ?? '') === 'block-theme') {
        return undo_block_theme($payload);
    }
    if (!class_exists('\ElementorPro\Modules\ThemeBuilder\Module')) {
        return new WP_Error('kit_site_header_undo_no_elementor', 'Elementor Pro is not active, so the header templates cannot be put back.');
    }
    $created = array_map('intval', (array) ($payload['created_ids'] ?? []));
    foreach ($created as $id) {
        $post = get_post($id);
        if ($post instanceof \WP_Post && $post->post_type === 'elementor_library') {
            save_conditions($id, []);
            wp_delete_post($id, true);
        }
    }
    $restored = [];
    foreach ((array) ($payload['demoted'] ?? []) as $id => $conditions) {
        save_conditions((int) $id, array_values(array_map('strval', (array) $conditions)));
        $restored[(int) $id] = get_post_meta((int) $id, '_elementor_conditions', true);
    }
    $menu = (int) ($payload['created_menu_id'] ?? 0);
    if ($menu > 0) {
        $term = wp_get_nav_menu_object($menu);
        if ($term instanceof \WP_Term && $term->name === SWITCHER_MENU_NAME) {
            wp_delete_nav_menu($menu);
        }
    }

    $verified = true;
    foreach ($created as $id) {
        $verified = $verified && !get_post($id) instanceof \WP_Post;
    }
    foreach ((array) ($payload['demoted'] ?? []) as $id => $conditions) {
        $verified = $verified && array_values((array) $restored[(int) $id]) === array_values((array) $conditions);
    }

    return ['deleted' => $created, 'conditions_restored' => array_keys($restored), 'verified' => $verified];
}

function register_ledger(Ledger $ledger): void
{
    $ledger->register_strategy(
        STRATEGY,
        static fn(array $payload): array|WP_Error => undo($payload),
        static function (array $before, mixed $result): array {
            if (is_array($result) && ($result['kind'] ?? '') === 'block-theme') {
                return [
                    'kind' => 'block-theme',
                    'template_part' => (string) ($result['template_part'] ?? ''),
                    'part_before' => (array) ($result['part_before'] ?? []),
                    'created_navigation_ids' => array_map('intval', (array) ($result['created_navigation_ids'] ?? [])),
                    'created_part_ids' => array_map('intval', (array) ($result['created_part_ids'] ?? [])),
                ];
            }
            if (!is_array($result) || !empty($result['dry_run']) || empty($result['created_ids'])) {
                return ['reversible' => false, 'reason' => 'Nothing was built (a dry run, or the build failed and put everything back itself).'];
            }

            return [
                'created_ids' => array_map('intval', (array) $result['created_ids']),
                'created_menu_id' => (int) ($result['created_menu_id'] ?? 0),
                'demoted' => (array) ($result['demoted'] ?? []),
            ];
        },
    );
    $ledger->capture_for('wppilot/build-site-header', static fn(array $input): array => ['type' => STRATEGY]);
}
