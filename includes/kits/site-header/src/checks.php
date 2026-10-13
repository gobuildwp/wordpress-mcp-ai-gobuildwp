<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteHeader;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/contrast.php';

/*
 * The header is the caller's design; this file only fills in the site's own pieces and judges
 * the result. Tokens put each language's menu, the language switcher, the home URL and labels
 * into the design; the checks report objective problems (a raw language list, no menu button,
 * text below WCAG AA, a cart without its icon, missing accessible names, broken images and
 * links) with the element to change and how. Nothing here rewrites the design. Plain data in
 * and out, so it is tested on its own.
 */

/** The tokens a design may use; {{label:key}} is a per-language text from `labels`. */
const TOKENS = [
    'menu' => 'This language\'s menu (a nav-menu widget\'s "menu" setting, Elementor).',
    'language_switcher' => 'The language switcher menu: Polylang\'s languages as one dropdown item (a nav-menu widget\'s "menu" setting, Elementor).',
    'navigation_ref' => 'This language\'s navigation post id (the navigation block\'s "ref", block themes; Polylang\'s switcher is its last item).',
    'home_url' => 'This language\'s home page URL.',
    'site_title' => 'The site title.',
];

/**
 * Put a language's values in place of the tokens, everywhere in a design.
 *
 * @param array{menu?: string, language_switcher?: string, navigation_ref?: int, home_url?: string, site_title?: string, labels?: array<string, string>} $vars
 * @param list<string> $unknown  Tokens with no value, collected.
 */
function substitute(mixed $value, array $vars, array &$unknown, bool $html = false): mixed
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = substitute($item, $vars, $unknown, $html);
        }

        return $out;
    }
    if (!is_string($value) || !str_contains($value, '{{')) {
        return $value;
    }
    if ($html) {
        // A navigation ref is a number in the block's JSON, so the quotes around it go too.
        $value = str_replace('"{{navigation_ref}}"', (string) (int) ($vars['navigation_ref'] ?? 0), $value);
    }

    return preg_replace_callback('/\{\{\s*([a-z_]+)(?::([a-z0-9_-]+))?\s*\}\}/i', static function (array $m) use ($vars, &$unknown, $html): string {
        $name = strtolower($m[1]);
        if ($name === 'label') {
            $key = (string) ($m[2] ?? '');
            if (!isset($vars['labels'][$key])) {
                $unknown[] = '{{label:' . $key . '}}';

                return '';
            }
            $text = (string) $vars['labels'][$key];

            return $html ? htmlspecialchars($text, ENT_QUOTES, 'UTF-8') : $text;
        }
        if (!array_key_exists($name, TOKENS) || !isset($vars[$name]) || $vars[$name] === '' || $vars[$name] === 0) {
            $unknown[] = $m[0];

            return '';
        }
        $text = (string) $vars[$name];

        return $html ? htmlspecialchars($text, ENT_QUOTES, 'UTF-8') : $text;
    }, $value) ?? $value;
}

/**
 * Every string in a design that holds markup, filtered (wp_kses_post for a user without
 * unfiltered_html): the design and the label texts substituted into it are that user's input, and
 * must not reach the page as HTML they could not have saved in the editor. Strings with no "<"
 * are left exactly as they are (kses would re-encode a "&" in a URL or a CSS value).
 *
 * @param callable(string): string $kses
 */
function kses_design(mixed $value, callable $kses): mixed
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = kses_design($item, $kses);
        }

        return $out;
    }

    return is_string($value) && str_contains($value, '<') ? $kses($value) : $value;
}

/** Whether a design (tree or markup) uses a token anywhere. */
function uses_token(mixed $design, string $token): bool
{
    $text = is_string($design) ? $design : (string) json_encode($design, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return preg_match('/\{\{\s*' . preg_quote($token, '/') . '\s*\}\}/', $text) === 1;
}

/**
 * A CSS colour as hex and alpha: #rgb, #rrggbb, #rrggbbaa, rgb()/rgba(), transparent.
 *
 * @return array{0: string, 1: float}|null
 */
function parse_color(string $value): ?array
{
    $v = strtolower(trim($value));
    if ($v === 'transparent') {
        return ['#000000', 0.0];
    }
    if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $v) === 1) {
        return [rgb_hex(hex_rgb($v)), 1.0];
    }
    if (preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})$/', $v, $m) === 1) {
        return ['#' . $m[1], hexdec($m[2]) / 255];
    }
    if (preg_match('/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:[\s,\/]+([\d.]+%?))?\s*\)$/', $v, $m) === 1) {
        $a = isset($m[4]) ? (str_ends_with($m[4], '%') ? (float) $m[4] / 100 : (float) $m[4]) : 1.0;

        return [rgb_hex([(float) $m[1], (float) $m[2], (float) $m[3]]), max(0.0, min(1.0, $a))];
    }

    return null;
}

/** A colour with alpha laid over an opaque one. */
function over(array $color, string $under): string
{
    return $color[1] >= 1.0 ? $color[0] : mix($under, $color[0], $color[1]);
}

/**
 * One finding: what is wrong, where, and what to change.
 *
 * @return array{severity: string, check: string, element_id: string, detail: string, fix: string}
 */
function finding(string $severity, string $check, string $element_id, string $detail, string $fix): array
{
    return ['severity' => $severity, 'check' => $check, 'element_id' => $element_id, 'detail' => $detail, 'fix' => $fix];
}

/**
 * Walk an Elementor tree, depth first, with each element's ancestors.
 *
 * @param list<array<string, mixed>> $elements
 * @param callable(array<string, mixed>, list<array<string, mixed>>, string): void $visit  The third argument is the element's path, such as elements[0].elements[2].
 * @param list<array<string, mixed>> $ancestors
 */
function walk(array $elements, callable $visit, array $ancestors = [], string $path = 'elements'): void
{
    foreach ($elements as $i => $element) {
        if (!is_array($element)) {
            continue;
        }
        $here = $path . '[' . $i . ']';
        $visit($element, $ancestors, $here);
        if (is_array($element['elements'] ?? null)) {
            walk($element['elements'], $visit, array_merge($ancestors, [$element]), $here . '.elements');
        }
    }
}

/** elType/element_type and widgetType/widget_type, as either spelling arrives. */
function el_type(array $element): string
{
    return (string) ($element['elType'] ?? $element['element_type'] ?? '');
}

function widget_type(array $element): string
{
    return (string) ($element['widgetType'] ?? $element['widget_type'] ?? '');
}

/**
 * A colour setting's value: the literal, or the kit global it points at.
 *
 * @param array<string, string> $globals  {"primary": "#hex", ...}
 */
function setting_color(array $settings, string $key, array $globals): ?array
{
    $ref = (string) ($settings['__globals__'][$key] ?? '');
    if ($ref !== '' && preg_match('/id=([a-z0-9_-]+)/i', $ref, $m) === 1 && isset($globals[$m[1]])) {
        return parse_color($globals[$m[1]]);
    }
    $value = $settings[$key] ?? '';

    return is_string($value) && $value !== '' ? parse_color($value) : null;
}

/** The background a widget sits on: the nearest ancestor container with a solid background, over the page. */
function backdrop(array $ancestors, array $globals, string $page): string
{
    $under = $page;
    foreach ($ancestors as $ancestor) {
        $s = is_array($ancestor['settings'] ?? null) ? $ancestor['settings'] : [];
        if (($s['background_background'] ?? '') === 'classic') {
            $color = setting_color($s, 'background_color', $globals);
            if ($color !== null) {
                $under = over($color, $under);
            }
        }
    }

    return $under;
}

/**
 * Check an Elementor header design before it is saved: problems that need no browser to see.
 *
 * @param list<array<string, mixed>> $tree
 * @param array{woo: bool, languages: int, globals: array<string, string>, page_background: string, switcher_slug?: string, unfiltered_html?: bool} $context
 * @return list<array{severity: string, check: string, element_id: string, detail: string, fix: string}>
 */
function precheck_elementor(array $tree, array $context): array
{
    $findings = [];
    if ($tree === []) {
        return [finding('error', 'empty_design', '', 'The design has no elements.', 'Pass elementor.elements: the header as Elementor elements, a container at the top.')];
    }
    $main_menus = 0;
    $switchers = 0;
    $menus = 0;
    walk($tree, static function (array $element, array $ancestors, string $path) use (&$findings, &$main_menus, &$switchers, &$menus, $context): void {
        // The element's id, or where it is in the design when it has none yet.
        $id = (string) ($element['id'] ?? '') !== '' ? (string) $element['id'] : $path . ' (' . (widget_type($element) !== '' ? widget_type($element) : el_type($element)) . ')';
        $s = is_array($element['settings'] ?? null) ? $element['settings'] : [];
        $type = widget_type($element);
        $parent = $ancestors === [] ? null : $ancestors[count($ancestors) - 1];
        if (!($context['unfiltered_html'] ?? true)) {
            // What WordPress would never let this user save by hand: raw HTML or script.
            if ($type === 'html') {
                $findings[] = finding('error', 'html_not_allowed', $id, 'You cannot save unfiltered HTML on this site, so an HTML widget is not allowed.', 'Build it from widgets (heading, button, icon, image) instead of an HTML widget.');
            }
            foreach (['custom_css', '_custom_css'] as $key) {
                if (is_string($s[$key] ?? null) && str_contains($s[$key], '<')) {
                    $findings[] = finding('error', 'html_not_allowed', $id, 'This custom CSS contains "<", which you cannot save on this site (it can close the style tag).', 'Remove the "<" from ' . $key . '.');
                }
            }
            if (is_string($s['_attributes'] ?? null) && preg_match('/(^|[\r\n])\s*on[a-z]*\s*\|/i', $s['_attributes']) === 1) {
                $findings[] = finding('error', 'html_not_allowed', $id, 'These custom attributes set an event handler (on...), which is script.', 'Remove the on... attribute.');
            }
        }
        if (el_type($element) !== 'widget') {
            // A row of three or more on phones: Elementor wraps a container's children on
            // phones by default, which turns a header row into two or three rows.
            $children = is_array($element['elements'] ?? null) ? count($element['elements']) : 0;
            $row = in_array($s['flex_direction'] ?? 'row', ['row', 'row-reverse'], true) && el_type($element) === 'container';
            if ($row && $children >= 3 && ($s['flex_wrap_mobile'] ?? '') !== 'nowrap' && ($s['flex_direction_mobile'] ?? 'row') !== 'column') {
                $findings[] = finding('warning', 'row_wraps_on_phones', $id, sprintf('This row of %d elements wraps onto more rows on phones (Elementor wraps flex rows on phones unless told not to).', $children), 'Set flex_wrap_mobile: "nowrap" (and flex_wrap / flex_wrap_tablet), and hide or shrink what does not fit on a phone.');
            }

            return;
        }
        if (in_array($type, ['polylang', 'wp-widget-polylang', 'polylang-language-switcher'], true)) {
            $findings[] = finding('error', 'raw_language_list', $id, 'Polylang\'s widget prints the languages as a bare list.', 'Use a nav-menu widget with "menu": "{{language_switcher}}", "layout": "horizontal", "dropdown": "none": one styled dropdown item with the other languages under it.');
        }
        if ($type === 'woocommerce-menu-cart' && !$context['woo']) {
            $findings[] = finding('error', 'cart_without_shop', $id, 'The design has a cart, and WooCommerce is not active, so it would render an empty box.', 'Remove the woocommerce-menu-cart widget.');
        }
        if ($type === 'nav-menu') {
            $menus++;
            $menu = (string) ($s['menu'] ?? '');
            // The switcher by its token, or by the switcher menu's slug in a design read back
            // from a saved header.
            $is_switcher = uses_token($menu, 'language_switcher') || ($menu !== '' && $menu === (string) ($context['switcher_slug'] ?? ''));
            $switchers += $is_switcher ? 1 : 0;
            if (!$is_switcher) {
                $main_menus++;
                $layout = (string) ($s['layout'] ?? 'horizontal');
                if ($layout !== 'dropdown' && ($s['dropdown'] ?? 'tablet') === 'none') {
                    $findings[] = finding('error', 'no_mobile_menu_button', $id, 'This menu has no menu button: it stays a row on tablets and phones, where it does not fit.', 'Set "dropdown": "tablet" (button on tablets and phones) or "mobile", and "toggle": "burger".');
                }
                if (($s['toggle'] ?? 'burger') === '' || ($s['toggle'] ?? 'burger') === 'none') {
                    $findings[] = finding('error', 'no_mobile_menu_button', $id, 'The menu button is switched off, so phones have no way to open the menu.', 'Set "toggle": "burger".');
                }
                if ($menu === '') {
                    $findings[] = finding('error', 'menu_not_set', $id, 'This nav-menu has no menu.', 'Set "menu": "{{menu}}" so each language gets its own menu.');
                }
            }
            if (trim((string) ($s['menu_name'] ?? '')) === '') {
                $findings[] = finding('warning', 'accessible_names', $id, 'This menu has no name, so screen readers announce every menu in the header as "Menu".', 'Set "menu_name", such as "Main menu" or "Language".');
            }
        }
        if (in_array($type, ['image', 'theme-site-logo'], true) && $type === 'image' && (int) ($s['image']['id'] ?? 0) <= 0 && trim((string) ($s['image']['url'] ?? '')) === '') {
            $findings[] = finding('error', 'broken_image', $id, 'This image has no picture.', 'Set image.id (and url) to a media library image, such as the logo.');
        }
        if (in_array($type, ['button', 'image'], true)) {
            $url = trim((string) ($s['link']['url'] ?? ''));
            $linked = $type === 'button' || ($s['link_to'] ?? '') === 'custom';
            if ($linked && ($url === '' || $url === '#')) {
                $findings[] = finding('warning', 'broken_link', $id, 'This ' . $type . ' links nowhere.', 'Set link.url, such as "{{home_url}}" for the logo or the page a button leads to.');
            }
        }
        // A widget in a row with no width of its own is zero wide, and its text spills over
        // the next element.
        $parent_settings = is_array($parent['settings'] ?? null) ? $parent['settings'] : [];
        if ($parent !== null && in_array($parent_settings['flex_direction'] ?? 'row', ['row', 'row-reverse'], true) && el_type($parent) === 'container'
            && !isset($s['_element_width']) && !in_array($s['_flex_size'] ?? '', ['grow', 'custom'], true)) {
            $findings[] = finding('warning', 'zero_width_in_row', $id, 'This ' . ($type !== '' ? $type : 'widget') . ' sits in a row with no width of its own, so it can collapse to nothing and overlap its neighbour.', 'Set "_element_width": "auto" (sized by content), or "_flex_size": "grow" for the one element that should take the free space.');
        }

        // Contrast of explicit colours against what is behind them.
        $under = backdrop($ancestors, $context['globals'], $context['page_background']);
        $pairs = [];
        $large = static fn(array $s, string $prefix): bool => (float) ($s[$prefix . '_font_size']['size'] ?? 0) >= 24 || ((float) ($s[$prefix . '_font_size']['size'] ?? 0) >= 18.66 && (int) ($s[$prefix . '_font_weight'] ?? 400) >= 700);
        if ($type === 'heading') {
            $pairs[] = ['title_color', $under, $large($s, 'typography') ? CONTRAST_LARGE : CONTRAST_TEXT, 'title'];
        } elseif ($type === 'nav-menu') {
            $pairs[] = ['color_menu_item', $under, CONTRAST_TEXT, 'menu items'];
            $pairs[] = ['color_menu_item_hover', $under, CONTRAST_TEXT, 'hovered menu items'];
            $pairs[] = ['color_menu_item_active', $under, CONTRAST_TEXT, 'the current menu item'];
            $dropdown = setting_color($s, 'background_color_dropdown_item', $context['globals']);
            $pairs[] = ['color_dropdown_item', $dropdown !== null ? over($dropdown, '#ffffff') : '#ffffff', CONTRAST_TEXT, 'dropdown items'];
            if (($s['pointer'] ?? '') === 'background') {
                $pill = setting_color($s, 'pointer_color_menu_item_hover', $context['globals']);
                if ($pill !== null) {
                    $pairs[] = ['color_menu_item_hover_pointer_bg', over($pill, $under), CONTRAST_TEXT, 'menu items on their hover background'];
                }
            }
        } elseif ($type === 'button') {
            $bg = setting_color($s, 'background_color', $context['globals']);
            $pairs[] = ['button_text_color', $bg !== null ? over($bg, $under) : $under, CONTRAST_TEXT, 'the button label'];
        } elseif (in_array($type, ['icon-list', 'text-editor'], true)) {
            $pairs[] = ['text_color', $under, CONTRAST_TEXT, 'the text'];
        }
        foreach ($pairs as [$key, $behind, $min, $what]) {
            $color = setting_color($s, $key, $context['globals']);
            if ($color === null) {
                continue;
            }
            $ratio = contrast(over($color, $behind), $behind);
            if ($ratio < $min) {
                $findings[] = finding('error', 'contrast', $id, sprintf('%s (%s) on %s is %.2f:1; WCAG AA needs %.1f:1.', ucfirst($what), $key, $behind, $ratio, $min), sprintf('Change %s (or the background behind it) to a colour with at least %.1f:1 against %s.', $key, $min, $behind));
            }
        }
    });
    if ($main_menus === 0 && $menus === 0) {
        $findings[] = finding('warning', 'no_menu', '', 'The design has no menu.', 'Add a nav-menu widget with "menu": "{{menu}}".');
    }
    if ($context['languages'] > 1 && $switchers === 0) {
        $findings[] = finding('warning', 'no_language_switcher', '', sprintf('The site has %d languages and the header has no language switcher.', $context['languages']), 'Add a nav-menu widget with "menu": "{{language_switcher}}", "layout": "horizontal", "dropdown": "none".');
    }

    return $findings;
}

/**
 * Check a block-theme header part before it is saved.
 *
 * @param list<array<string, mixed>> $blocks  parse_blocks() of the markup.
 * @param array{woo: bool, languages: int, unfiltered_html: bool} $context
 * @return list<array{severity: string, check: string, element_id: string, detail: string, fix: string}>
 */
function precheck_blocks(array $blocks, string $markup, array $context): array
{
    $findings = [];
    if (trim($markup) === '') {
        return [finding('error', 'empty_design', '', 'The design is empty.', 'Pass block_markup: the header template part\'s block markup.')];
    }
    $names = [];
    $collect = static function (array $blocks, string $path) use (&$collect, &$names): void {
        foreach ($blocks as $i => $block) {
            if (!is_array($block) || ($block['blockName'] ?? null) === null) {
                continue;
            }
            $names[] = ['name' => (string) $block['blockName'], 'attrs' => (array) ($block['attrs'] ?? []), 'path' => $path . '/' . $i, 'html' => (string) ($block['innerHTML'] ?? '')];
            $collect((array) ($block['innerBlocks'] ?? []), $path . '/' . $i);
        }
    };
    $collect($blocks, '');
    $navigations = array_values(array_filter($names, static fn(array $b): bool => $b['name'] === 'core/navigation'));
    foreach ($names as $block) {
        if ($block['name'] === 'polylang/language-switcher') {
            $findings[] = finding('error', 'raw_language_list', $block['path'], 'Polylang\'s language switcher block prints the languages as a bare list.', 'Leave it out: the navigation made from {{navigation_ref}} carries the switcher as its last item, styled as a dropdown.');
        }
        if (str_starts_with($block['name'], 'woocommerce/mini-cart') && !$context['woo']) {
            $findings[] = finding('error', 'cart_without_shop', $block['path'], 'The design has a mini-cart and WooCommerce is not active.', 'Remove the woocommerce/mini-cart block.');
        }
        if ($block['name'] === 'core/html' && !$context['unfiltered_html']) {
            $findings[] = finding('error', 'html_stripped', $block['path'], 'You cannot save unfiltered HTML on this site, so WordPress would strip this Custom HTML block (and any <style> in it).', 'Use block attributes (colours, spacing, typography) instead of a Custom HTML block.');
        }
    }
    foreach ($navigations as $nav) {
        if (($nav['attrs']['overlayMenu'] ?? 'mobile') === 'never') {
            $findings[] = finding('error', 'no_mobile_menu_button', $nav['path'], 'The navigation never collapses into a menu button, so on phones it wraps or runs off the screen.', 'Set "overlayMenu": "mobile" (or "always").');
        }
        if (!isset($nav['attrs']['ref']) && !uses_token($markup, 'navigation_ref')) {
            $findings[] = finding('warning', 'menu_not_set', $nav['path'], 'The navigation does not point at the site\'s menu.', 'Set "ref": "{{navigation_ref}}" so each language gets its own menu and the language switcher.');
        }
    }
    if ($navigations === []) {
        $findings[] = finding('warning', 'no_menu', '', 'The design has no navigation block.', 'Add <!-- wp:navigation {"ref":"{{navigation_ref}}","overlayMenu":"mobile"} /-->.');
    }

    return $findings;
}

/**
 * Check a served header for what can be read from its HTML: the right header, no raw language
 * list, a styled switcher, the cart icon, a menu button for every menu that needs one, accessible
 * names. The images and links it finds are returned for the caller to fetch, as {url: element}.
 *
 * @param array{kind: string, template_id?: int, languages: int, cart: bool} $expect  kind: elementor or block-theme.
 * @return array{passed: bool, findings: list<array{severity: string, check: string, element_id: string, detail: string, fix: string}>, images: array<string, string>, links: array<string, string>}
 */
function check_served(string $html, array $expect): array
{
    $findings = [];
    $images = [];
    $links = [];
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $html !== '' && $document->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        return ['passed' => false, 'findings' => [finding('error', 'served', '', 'The page could not be read.', 'Check that the home page loads for a visitor.')], 'images' => [], 'links' => []];
    }
    $xpath = new \DOMXPath($document);
    $class = static fn(string $c): string => 'contains(concat(" ", normalize-space(@class), " "), " ' . $c . ' ")';
    $elementor = $expect['kind'] === 'elementor';
    if ($elementor) {
        $found = $xpath->query('//*[@data-elementor-type="header"][@data-elementor-id="' . (int) ($expect['template_id'] ?? 0) . '"]');
    } else {
        $found = $xpath->query('//header[' . $class('wp-block-template-part') . ']|//*[' . $class('wp-block-template-part') . '][.//header]');
    }
    $root = $found !== false && $found->length > 0 ? $found->item(0) : null;
    if (!$root instanceof \DOMElement) {
        return ['passed' => false, 'findings' => [finding('error', 'header_served', '', $elementor
            ? 'The page does not render header template ' . (int) ($expect['template_id'] ?? 0) . ' (another header won, or the theme does not print Elementor headers).'
            : 'No block-theme header is served (another plugin\'s header may replace it).', 'Check the display conditions of other headers, or the page template.')], 'images' => [], 'links' => []];
    }
    $id_of = static function (\DOMNode $node) use ($elementor): string {
        for ($n = $node; $n instanceof \DOMElement; $n = $n->parentNode) {
            if ($elementor && $n->hasAttribute('data-id')) {
                return $n->getAttribute('data-id');
            }
            if (!$elementor && preg_match('/\bwp-block-[a-z0-9-]+/', $n->getAttribute('class'), $m) === 1) {
                return $m[0];
            }
        }

        return '';
    };

    // A language list outside any styled menu.
    $menu_class = $elementor ? 'elementor-nav-menu' : 'wp-block-navigation__container';
    $raw = $xpath->query('.//li[' . $class('lang-item') . '][not(ancestor::*[' . $class($menu_class) . '])][not(ancestor::*[' . $class('wp-block-navigation') . '])]', $root);
    if ($raw !== false && $raw->length > 0) {
        $findings[] = finding('error', 'raw_language_list', $id_of($raw->item(0)), 'The languages are printed as a bare, unstyled list.', $elementor
            ? 'Replace it with a nav-menu widget whose "menu" is "{{language_switcher}}", "layout": "horizontal", "dropdown": "none".'
            : 'Remove the language switcher block; the navigation from {{navigation_ref}} carries the switcher.');
    }
    if ($expect['languages'] > 1) {
        $switch = $xpath->query('.//*[' . $class($menu_class) . ']//li[' . $class('lang-item') . ']|.//*[' . $class('wp-block-navigation') . ']//*[' . $class('lang-item') . ']', $root);
        if ($switch === false || $switch->length === 0) {
            $findings[] = finding('warning', 'no_language_switcher', '', sprintf('The site has %d languages and the served header shows no language switcher.', $expect['languages']), $elementor ? 'Add a nav-menu widget with "menu": "{{language_switcher}}".' : 'Use "ref": "{{navigation_ref}}" on the navigation block.');
        }
    }

    // Every menu that is a row on desktop needs a button below it.
    if ($elementor) {
        $widgets = $xpath->query('.//*[' . $class('elementor-widget-nav-menu') . ']', $root);
        foreach ($widgets === false ? [] : $widgets as $widget) {
            if (!$widget instanceof \DOMElement) {
                continue;
            }
            $main = $xpath->query('.//nav[' . $class('elementor-nav-menu--main') . ']', $widget);
            $items = $main !== false && $main->length > 0 ? $xpath->query('./ul/li', $main->item(0)) : false;
            $count = $items === false ? 0 : $items->length;
            $is_switcher = $xpath->query('.//li[' . $class('lang-item') . ']', $widget);
            $toggle = $xpath->query('.//*[' . $class('elementor-menu-toggle') . ']', $widget);
            $no_dropdown = str_contains(' ' . $widget->getAttribute('class') . ' ', ' elementor-nav-menu--dropdown-none ');
            if ($count > 1 && ($is_switcher === false || $is_switcher->length === 0) && ($toggle === false || $toggle->length === 0 || $no_dropdown)) {
                $findings[] = finding('error', 'no_mobile_menu_button', $widget->getAttribute('data-id'), sprintf('This %d-item menu has no menu button for tablets and phones.', $count), 'Set "dropdown": "tablet" and "toggle": "burger" on it.');
            }
        }
        $toggles = $xpath->query('.//*[' . $class('elementor-menu-toggle') . ']', $root);
        foreach ($toggles === false ? [] : $toggles as $toggle) {
            if ($toggle instanceof \DOMElement && trim($toggle->getAttribute('aria-label')) === '') {
                $findings[] = finding('warning', 'accessible_names', $id_of($toggle), 'A menu button has no accessible name.', 'Elementor names it; check that nothing strips aria-label.');
            }
        }
        $labels = [];
        $navs = $xpath->query('.//nav[' . $class('elementor-nav-menu--main') . ']', $root);
        foreach ($navs === false ? [] : $navs as $nav) {
            if ($nav instanceof \DOMElement) {
                $labels[$id_of($nav)] = trim($nav->getAttribute('aria-label'));
            }
        }
        $counts = array_count_values(array_filter($labels));
        foreach ($labels as $element => $label) {
            if ($label === '' || ($counts[$label] ?? 0) > 1) {
                $findings[] = finding('warning', 'accessible_names', (string) $element, $label === '' ? 'A menu has no accessible name.' : sprintf('Several menus are all named "%s", so a screen reader cannot tell them apart.', $label), 'Set a distinct "menu_name" on each nav-menu widget, such as "Main menu" and "Language".');
            }
        }
        $carts = $xpath->query('.//*[' . $class('elementor-widget-woocommerce-menu-cart') . ']', $root);
        foreach ($carts === false ? [] : $carts as $cart) {
            $icon = $cart instanceof \DOMElement ? $xpath->query('.//*[' . $class('elementor-menu-cart__toggle_button') . ']//svg|.//*[' . $class('elementor-menu-cart__toggle_button') . ']//i', $cart) : false;
            if ($icon === false || $icon->length === 0) {
                $findings[] = finding('error', 'empty_cart', $cart instanceof \DOMElement ? $cart->getAttribute('data-id') : '', 'The cart button renders without its icon: an empty box.', 'Set "icon" on the woocommerce-menu-cart widget, such as "bag-medium".');
            }
        }
    } else {
        $navs = $xpath->query('.//nav[' . $class('wp-block-navigation') . ']', $root);
        foreach ($navs === false ? [] : $navs as $nav) {
            if (!$nav instanceof \DOMElement) {
                continue;
            }
            $open = $xpath->query('.//*[' . $class('wp-block-navigation__responsive-container-open') . ']', $nav);
            $items = $xpath->query('.//ul[' . $class('wp-block-navigation__container') . ']/li', $nav);
            if (($items !== false && $items->length > 2) && ($open === false || $open->length === 0)) {
                $findings[] = finding('error', 'no_mobile_menu_button', 'wp-block-navigation', 'The navigation has no menu button for phones.', 'Set "overlayMenu": "mobile" on the navigation block.');
            }
            if (trim($nav->getAttribute('aria-label')) === '') {
                $findings[] = finding('warning', 'accessible_names', 'wp-block-navigation', 'The navigation has no accessible name.', 'Give the navigation post a title.');
            }
        }
        if ($expect['cart']) {
            $cart = $xpath->query('.//*[' . $class('wc-block-mini-cart') . ']', $root);
            if ($cart === false || $cart->length === 0) {
                $findings[] = finding('warning', 'empty_cart', 'wp-block-woocommerce-mini-cart', 'The design has a mini-cart and none is rendered.', 'WooCommerce hides it while the store is in coming-soon mode; otherwise check the block.');
            }
        }
    }

    $imgs = $xpath->query('.//img', $root);
    foreach ($imgs === false ? [] : $imgs as $img) {
        if (!$img instanceof \DOMElement) {
            continue;
        }
        $src = trim($img->getAttribute('src'));
        if ($src === '') {
            $findings[] = finding('error', 'broken_image', $id_of($img), 'An image has no source.', 'Set the image (the logo) to a media library picture.');
            continue;
        }
        $images[$src] = $id_of($img);
        if (!$img->hasAttribute('alt')) {
            $findings[] = finding('warning', 'accessible_names', $id_of($img), 'An image has no alt text.', 'Give the logo alt text: the site name.');
        }
    }
    $anchors = $xpath->query('.//a[@href]', $root);
    foreach ($anchors === false ? [] : $anchors as $a) {
        if (!$a instanceof \DOMElement) {
            continue;
        }
        $href = trim($a->getAttribute('href'));
        $toggleish = $xpath->query('./ancestor-or-self::*[' . $class('elementor-menu-cart__toggle_button') . ' or ' . $class('menu-item-has-children') . ' or ' . $class('lang-item') . ']', $a);
        if (($href === '' || $href === '#') && ($toggleish === false || $toggleish->length === 0)) {
            $findings[] = finding('warning', 'broken_link', $id_of($a), sprintf('The link "%s" goes nowhere.', trim($a->textContent)), 'Point it at a page, or remove it.');
        } elseif ($href !== '' && $href !== '#' && !str_starts_with($href, '#') && !preg_match('/^(mailto|tel|javascript):/i', $href)) {
            $links[$href] = $id_of($a);
        }
    }

    $passed = true;
    foreach ($findings as $f) {
        $passed = $passed && $f['severity'] !== 'error';
    }

    return ['passed' => $passed, 'findings' => $findings, 'images' => $images, 'links' => $links];
}

/** Whether any finding is an error. */
function has_errors(array $findings): bool
{
    foreach ($findings as $f) {
        if (($f['severity'] ?? '') === 'error') {
            return true;
        }
    }

    return false;
}
