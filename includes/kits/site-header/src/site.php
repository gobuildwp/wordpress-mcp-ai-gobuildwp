<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteHeader;

use WPPilot\Kits\Runtime\Page;

if (!defined('ABSPATH')) {
    exit();
}

/*
 * What the site already is, for the caller designing its header: its colours and fonts
 * (Elementor kit globals, else theme.json, else the logo) and whether the home page opens with
 * a full-bleed hero.
 */

/** Elementor's factory typography (Roboto, Roboto Slab) is nobody's brand. */
const FACTORY_FONTS = ['Roboto', 'Roboto Slab'];

/**
 * Title and menu fonts from the Elementor kit's global typography, when someone has set them.
 *
 * @return array<string, string>
 */
function kit_fonts(): array
{
    if (!class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance->kits_manager)) {
        return [];
    }
    $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
    $rows = is_object($kit) ? $kit->get_settings('system_typography') : null;
    $found = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (is_array($row) && isset($row['_id']) && is_string($row['typography_font_family'] ?? null) && $row['typography_font_family'] !== '') {
            $found[(string) $row['_id']] = $row['typography_font_family'];
        }
    }
    $fonts = [];
    foreach (['title' => 'primary', 'menu' => 'text'] as $ours => $theirs) {
        $family = $found[$theirs] ?? '';
        if ($family !== '' && !in_array($family, FACTORY_FONTS, true) && preg_match('/^[\p{L}\p{N} _-]{1,60}$/u', $family) === 1) {
            $fonts[$ours] = $family;
        }
    }

    return $fonts;
}

/** The Elementor kit's page background (Site Settings > Background), when set. */
function kit_background(): string
{
    if (!class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance->kits_manager)) {
        return '';
    }
    $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
    if (!is_object($kit) || $kit->get_settings('body_background_background') !== 'classic') {
        return '';
    }
    $hex = sanitize_hex_color((string) $kit->get_settings('body_background_color'));

    return is_string($hex) ? $hex : '';
}

/**
 * Background, text and accent from the block theme's global styles (theme.json plus the
 * site editor's changes), presets resolved to their hex values.
 *
 * @return array<string, string>
 */
function theme_json_palette(): array
{
    if (!function_exists('wp_get_global_styles') || !function_exists('wp_get_global_settings')) {
        return [];
    }
    $presets = [];
    foreach ((array) wp_get_global_settings(['color', 'palette']) as $origin) {
        foreach ((array) $origin as $row) {
            if (is_array($row) && isset($row['slug'], $row['color'])) {
                $presets[(string) $row['slug']] = (string) $row['color'];
            }
        }
    }
    $resolve = static function (mixed $value) use ($presets): string {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^var:preset\|color\|([a-z0-9-]+)$/i', $value, $m) === 1 || preg_match('/^var\(--wp--preset--color--([a-z0-9-]+)\)$/i', $value, $m) === 1) {
            $value = $presets[$m[1]] ?? '';
        }
        $hex = sanitize_hex_color($value);

        return is_string($hex) ? $hex : '';
    };
    $styles = (array) wp_get_global_styles();
    $out = array_filter([
        'background' => $resolve($styles['color']['background'] ?? ''),
        'text' => $resolve($styles['color']['text'] ?? ''),
        'accent' => $resolve($styles['elements']['link']['color']['text'] ?? ''),
    ]);
    if (!isset($out['accent']) || ($out['accent'] === ($out['text'] ?? ''))) {
        foreach (['accent', 'accent-1', 'primary', 'vivid-cyan-blue'] as $slug) {
            $hex = isset($presets[$slug]) ? $resolve($presets[$slug]) : '';
            if ($hex !== '') {
                $out['accent'] = $hex;
                break;
            }
        }
    }

    return $out;
}

/**
 * The colours a logo is drawn with: an SVG's fills and strokes, or a raster sampled on a grid.
 *
 * @return array<string, string>
 */
function logo_palette(int $logo_id): array
{
    $file = $logo_id > 0 ? get_attached_file($logo_id) : false;
    if (!is_string($file) || !is_readable($file) || filesize($file) > 2 * 1024 * 1024) {
        return [];
    }
    $mime = (string) get_post_mime_type($logo_id);
    $weights = [];
    if ($mime === 'image/svg+xml') {
        $svg = (string) file_get_contents($file);
        preg_match_all('/(?:fill|stroke|stop-color)\s*[:=]\s*["\']?\s*(#[0-9a-f]{6}|#[0-9a-f]{3})\b/i', $svg, $m);
        foreach ($m[1] as $hex) {
            $hex = strtolower(strlen($hex) === 4 ? '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3] : $hex);
            $weights[$hex] = ($weights[$hex] ?? 0) + 1;
        }
    } elseif (function_exists('imagecreatefromstring') && in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
        $image = @imagecreatefromstring((string) file_get_contents($file));
        if ($image !== false) {
            $w = imagesx($image);
            $h = imagesy($image);
            for ($x = 0; $x < 24; $x++) {
                for ($y = 0; $y < 24; $y++) {
                    $rgba = imagecolorat($image, (int) ($x * $w / 24), (int) ($y * $h / 24));
                    $color = imagecolorsforindex($image, $rgba);
                    if (($color['alpha'] ?? 0) > 60) {
                        continue;
                    }
                    // Quantised, so anti-aliased edges count toward the colour they blur.
                    $hex = sprintf('#%02x%02x%02x', $color['red'] & 0xF0, $color['green'] & 0xF0, $color['blue'] & 0xF0);
                    $weights[$hex] = ($weights[$hex] ?? 0) + 1;
                }
            }
            imagedestroy($image);
        }
    }

    return logo_colors($weights);
}

/**
 * The site's own colours and fonts, and where each came from.
 *
 * @return array{colors: array<string, string>, fonts: array<string, string>, sources: list<string>}
 */
function site_look(string $builder, int $logo_id): array
{
    $colors = [];
    $sources = [];
    if ($builder === 'elementor') {
        $kit = kit_palette();
        $bg = kit_background();
        if ($bg !== '') {
            $kit['background'] = $bg;
        }
        if ($kit !== []) {
            $colors = $kit;
            $sources[] = 'Elementor kit global colours (' . implode(', ', array_keys($kit)) . ')';
        }
    } else {
        $theme = theme_json_palette();
        if ($theme !== []) {
            $colors = $theme;
            $sources[] = 'theme.json global styles (' . implode(', ', array_keys($theme)) . ')';
        }
    }
    $from_logo = array_diff_key(logo_palette($logo_id), $colors);
    if ($from_logo !== []) {
        $colors += $from_logo;
        $sources[] = 'the logo (' . implode(', ', array_keys($from_logo)) . ')';
    }
    $fonts = $builder === 'elementor' ? kit_fonts() : [];
    if ($fonts !== []) {
        $sources[] = 'Elementor kit global fonts (' . implode(', ', array_keys($fonts)) . ')';
    }

    return ['colors' => $colors, 'fonts' => $fonts, 'sources' => $sources];
}

/**
 * Whether the home page opens with a full-bleed hero: its first section has a background image,
 * video or slideshow (Elementor), or is a cover block or a group with a background image (blocks).
 *
 * @return array{has_hero: bool, detail: string}
 */
function front_hero(): array
{
    $front = get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0;
    if ($front <= 0) {
        return ['has_hero' => false, 'detail' => 'The home page is the latest posts, which has no hero.'];
    }
    if (get_post_meta($front, '_elementor_edit_mode', true) === 'builder') {
        $data = json_decode((string) get_post_meta($front, '_elementor_data', true), true);
        $first = is_array($data) ? ($data[0] ?? null) : null;
        if (!is_array($first)) {
            return ['has_hero' => false, 'detail' => 'The home page has no Elementor content.'];
        }
        $candidates = [$first];
        // An atomic page often wraps its sections in one main container: look one level in.
        if (is_array($first['elements'][0] ?? null)) {
            $candidates[] = $first['elements'][0];
        }
        foreach ($candidates as $element) {
            $s = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            $kind = (string) ($s['background_background'] ?? '');
            if (($kind === 'classic' && !empty($s['background_image']['url'])) || ($kind === 'video' && !empty($s['background_video_link'])) || ($kind === 'slideshow' && !empty($s['background_slideshow_gallery']))) {
                return ['has_hero' => true, 'detail' => 'The home page opens with a full-width section with a background ' . ($kind === 'classic' ? 'image' : $kind) . '.'];
            }
            $styles = (string) wp_json_encode($element['styles'] ?? []);
            if (str_contains($styles, 'background-image-overlay')) {
                return ['has_hero' => true, 'detail' => 'The home page opens with a section with a background image.'];
            }
        }

        return ['has_hero' => false, 'detail' => 'The home page\'s first section has no background image, video or slideshow.'];
    }
    foreach (parse_blocks((string) get_post_field('post_content', $front)) as $block) {
        if (($block['blockName'] ?? null) === null) {
            continue;
        }
        $attrs = (array) ($block['attrs'] ?? []);
        if ($block['blockName'] === 'core/cover' || !empty($attrs['style']['background']['backgroundImage'])) {
            return ['has_hero' => true, 'detail' => 'The home page opens with a cover block.'];
        }

        return ['has_hero' => false, 'detail' => 'The home page\'s first block is not a cover.'];
    }

    return ['has_hero' => false, 'detail' => 'The home page is empty.'];
}

/**
 * Every colour of the Elementor kit by its id (system and custom), for designs that point at
 * kit globals ("__globals__": {"title_color": "globals/colors?id=primary"}).
 *
 * @return array<string, string>
 */
function kit_palette_ids(): array
{
    if (!class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance->kits_manager)) {
        return [];
    }
    $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
    $out = [];
    foreach (['system_colors', 'custom_colors'] as $group) {
        $rows = is_object($kit) ? $kit->get_settings($group) : null;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['_id'], $row['color']) && is_string($row['color'])) {
                $out[(string) $row['_id']] = $row['color'];
            }
        }
    }

    return $out;
}

/**
 * The colours and font families the home page is actually painted with, most used first: what
 * a header should agree with when the site has set no global colours or fonts. Read from the
 * page's own stylesheets (Elementor's per-post CSS and inline styles), not the theme's defaults.
 *
 * @return array{colors: list<array{color: string, uses: int}>, fonts: list<string>}
 */
function home_palette(): array
{
    $page = Page::fetch(home_url('/'));
    if (!is_array($page)) {
        return ['colors' => [], 'fonts' => []];
    }
    $html = $page['html'];
    $css = '';
    if (preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $html, $m) > 0) {
        foreach ($m[1] as $block) {
            // WordPress's own preset variables are every theme's, not this site's.
            if (!str_contains($block, '--wp--preset--')) {
                $css .= $block;
            }
        }
    }
    $kit = (int) get_option('elementor_active_kit');
    $fetched = 0;
    if (preg_match_all('/href=["\']([^"\']*\/elementor\/css\/post-(\d+)\.css[^"\']*)["\']/', $html, $links, PREG_SET_ORDER) > 0) {
        foreach ($links as $link) {
            if ((int) $link[2] === $kit || $fetched >= 4) {
                continue;
            }
            $file = Page::fetch(html_entity_decode($link[1]), 10);
            if (is_array($file) && $file['status'] === 200) {
                $css .= $file['html'];
                $fetched++;
            }
        }
    }
    $counts = [];
    if (preg_match_all('/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $css, $hex) > 0) {
        foreach ($hex[0] as $value) {
            $parsed = parse_color($value);
            if ($parsed !== null) {
                $counts[$parsed[0]] = ($counts[$parsed[0]] ?? 0) + 1;
            }
        }
    }
    arsort($counts);
    $fonts = [];
    if (preg_match_all('/font-family\s*:\s*["\']?([^"\',;}]+)/i', $css, $families) > 0) {
        foreach ($families[1] as $family) {
            $family = trim($family);
            if ($family !== '' && !str_starts_with($family, 'var(') && !in_array(strtolower($family), ['inherit', 'sans-serif', 'serif', 'monospace', 'eicons', 'font awesome 5 free', 'font awesome 5 brands'], true)) {
                $fonts[$family] = ($fonts[$family] ?? 0) + 1;
            }
        }
    }
    arsort($fonts);

    return [
        'colors' => array_map(static fn(string $c, int $n): array => ['color' => $c, 'uses' => $n], array_slice(array_keys($counts), 0, 10), array_slice(array_values($counts), 0, 10)),
        'fonts' => array_slice(array_keys($fonts), 0, 6),
    ];
}

/**
 * Images that look like a logo, when the site has no logo set: the caller picks one.
 *
 * @return list<array{id: int, title: string, url: string}>
 */
function logo_candidates(): array
{
    $ids = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => 5, 's' => 'logo', 'fields' => 'ids']);
    $out = [];
    foreach ($ids as $id) {
        $out[] = ['id' => (int) $id, 'title' => (string) get_the_title((int) $id), 'url' => (string) wp_get_attachment_image_url((int) $id, 'full')];
    }

    return $out;
}

/** The site title as text: get_bloginfo() returns it HTML-escaped for display. */
function site_name(): string
{
    return html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
}
