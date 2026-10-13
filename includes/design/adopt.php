<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Adopt;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Read the design a site already has.
 *
 * For any site that is not brand new, this beats every readymade palette on
 * offer, because the brand already exists — it is in the theme's global styles,
 * in the customizer, in the pages somebody built. The failure mode this exists
 * to prevent is an agent inventing a fresh direction for a site that already had
 * one, and then building something that clashes with every existing page while
 * being perfectly consistent with itself.
 *
 * Sources are read in order of authority: theme.json global styles first,
 * because on a block theme that is the design system, then the customizer's
 * standard settings, then what the site actually serves. Nothing here writes;
 * the result is a draft for a person to review, name and save.
 *
 * Builder-specific palettes — Elementor's kit, Bricks variables, Divi globals —
 * are Pro's to read through its own abilities, and it extends this via the
 * `wppilot_design_adopt_sources` filter rather than this file knowing about
 * builders it cannot see.
 */

/**
 * Gather every readable source of design on this site.
 *
 * @return array{
 *   colors: array<string, string>,
 *   typography: array<string, array<string, string>>,
 *   spacing: array<string, string>,
 *   sources: list<array{source: string, colors: int, fonts: int}>,
 *   notes: list<string>
 * }
 */
function gather(): array
{
    $result = [
        'colors' => [],
        'typography' => [],
        'spacing' => [],
        'sources' => [],
        'notes' => [],
    ];

    foreach ([theme_json(), customizer()] as $source) {
        merge_source($result, $source);
    }

    /**
     * Let other plugins contribute what they can read.
     *
     * Pro uses this to add builder palettes. Each entry has the same shape a
     * built-in source returns, so a contributed source is not privileged over
     * theme.json — first writer of a token name wins, and theme.json goes first
     * because on a block theme it is the actual design system rather than one
     * plugin's copy of it.
     *
     * @param list<array<string, mixed>> $extra
     */
    $extra = apply_filters('wppilot_design_adopt_sources', []);
    if (is_array($extra)) {
        foreach ($extra as $source) {
            if (is_array($source)) {
                merge_source($result, $source);
            }
        }
    }

    if ($result['colors'] === []) {
        $result['notes'][] = __(
            'No palette could be read from this site. It may be using a theme that defines colours only in CSS, which this cannot see.',
            domain: 'wppilot',
        );
    }
    if ($result['typography'] === []) {
        $result['notes'][] = __(
            'No font families could be read. Check the theme\'s own typography settings before assuming the site has none.',
            domain: 'wppilot',
        );
    }

    return $result;
}

/**
 * @param array<string, mixed> $result
 * @param array<string, mixed> $source
 */
function merge_source(array &$result, array $source): void
{
    $name = (string) ($source['source'] ?? 'unknown');
    $colors = is_array($source['colors'] ?? null) ? $source['colors'] : [];
    $typography = is_array($source['typography'] ?? null) ? $source['typography'] : [];
    $spacing = is_array($source['spacing'] ?? null) ? $source['spacing'] : [];

    if ($colors === [] && $typography === [] && $spacing === []) {
        return;
    }

    foreach ($colors as $key => $value) {
        $key = token_key((string) $key);
        $hex = normalize((string) $value);
        // First writer wins: sources are visited most-authoritative first, and a
        // later source repeating a name is a copy rather than a correction.
        if ($key !== '' && $hex !== '' && !array_key_exists($key, $result['colors'])) {
            $result['colors'][$key] = $hex;
        }
    }
    foreach ($typography as $role => $props) {
        $role = token_key((string) $role);
        if ($role === '' || !is_array($props) || array_key_exists($role, $result['typography'])) {
            continue;
        }
        $clean = [];
        foreach (['fontFamily', 'fontWeight', 'fontSize', 'lineHeight', 'letterSpacing', 'measure'] as $prop) {
            if (($props[$prop] ?? '') !== '' && is_scalar($props[$prop])) {
                $clean[$prop] = (string) $props[$prop];
            }
        }
        if ($clean !== []) {
            $result['typography'][$role] = $clean;
        }
    }
    foreach ($spacing as $key => $value) {
        $key = token_key((string) $key);
        if ($key !== '' && !array_key_exists($key, $result['spacing']) && is_scalar($value)) {
            $result['spacing'][$key] = (string) $value;
        }
    }

    $result['sources'][] = [
        'source' => $name,
        'colors' => count($colors),
        'fonts' => count($typography),
    ];
}

/**
 * theme.json global styles: on a block theme, the site's actual design system.
 *
 * Read through wp_get_global_settings() rather than the file, so a child theme's
 * overrides and anything a user changed in the Styles editor are included —
 * reading theme.json off disk would report the theme's defaults as the site's
 * design, which is wrong on every site anybody has edited.
 *
 * @return array<string, mixed>
 */
function theme_json(): array
{
    if (!function_exists('wp_get_global_settings')) {
        return [];
    }
    /** @var mixed $settings */
    $settings = wp_get_global_settings();
    if (!is_array($settings)) {
        return [];
    }

    $colors = [];
    /** @var mixed $palettes */
    $palettes = $settings['color']['palette'] ?? [];
    if (is_array($palettes)) {
        // theme.json splits the palette by origin: default (core), theme, custom
        // (the user's own). Theme and custom are the site's brand; core's greys
        // are WordPress's and would drown a real palette in noise.
        foreach (['custom', 'theme'] as $origin) {
            $entries = $palettes[$origin] ?? null;
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $slug = (string) ($entry['slug'] ?? $entry['name'] ?? '');
                $color = (string) ($entry['color'] ?? '');
                if ($slug !== '' && $color !== '' && !array_key_exists($slug, $colors)) {
                    $colors[$slug] = $color;
                }
            }
        }
        // A flat palette (no origin keys) is what a classic theme's
        // add_theme_support('editor-color-palette') produces.
        if ($colors === []) {
            foreach ($palettes as $entry) {
                if (is_array($entry) && ($entry['color'] ?? '') !== '') {
                    $colors[(string) ($entry['slug'] ?? $entry['name'] ?? '')] = (string) $entry['color'];
                }
            }
        }
    }

    $typography = [];
    /** @var mixed $families */
    $families = $settings['typography']['fontFamilies'] ?? [];
    if (is_array($families)) {
        foreach (['custom', 'theme'] as $origin) {
            $entries = $families[$origin] ?? null;
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $slug = (string) ($entry['slug'] ?? '');
                $family = first_family((string) ($entry['fontFamily'] ?? ''));
                if ($slug !== '' && $family !== '' && !array_key_exists($slug, $typography)) {
                    $typography[$slug] = ['fontFamily' => $family];
                }
            }
        }
    }

    $spacing = [];
    /** @var mixed $sizes */
    $sizes = $settings['spacing']['spacingSizes'] ?? [];
    if (is_array($sizes)) {
        foreach (['custom', 'theme'] as $origin) {
            $entries = $sizes[$origin] ?? null;
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (is_array($entry) && ($entry['size'] ?? '') !== '') {
                    $spacing[(string) ($entry['slug'] ?? '')] = (string) $entry['size'];
                }
            }
        }
    }

    if ($colors === [] && $typography === [] && $spacing === []) {
        return [];
    }

    return [
        'source' => 'theme.json global styles',
        'colors' => $colors,
        'typography' => $typography,
        'spacing' => $spacing,
    ];
}

/**
 * Customizer settings every theme is likely to set.
 *
 * Deliberately narrow: the standard core mods plus the two names half the
 * classic-theme world uses. Guessing at a theme's own option names would produce
 * a palette that is right on one site and nonsense on the next, and Pro's
 * theme-aware modules read the specific ones properly.
 *
 * @return array<string, mixed>
 */
function customizer(): array
{
    $colors = [];
    foreach (['background_color' => 'background'] as $mod => $token) {
        $value = get_theme_mod($mod, '');
        if (is_string($value) && $value !== '') {
            $colors[$token] = str_starts_with($value, '#') ? $value : '#' . $value;
        }
    }
    // Core stores this one as an option rather than a theme mod.
    $background = get_option('background_color', '');
    if (is_string($background) && $background !== '' && !array_key_exists('background', $colors)) {
        $colors['background'] = '#' . ltrim($background, characters: '#');
    }

    if ($colors === []) {
        return [];
    }

    return ['source' => 'customizer', 'colors' => $colors, 'typography' => [], 'spacing' => []];
}

/**
 * Build a DESIGN.md draft from gathered tokens.
 *
 * Written here rather than left to the agent so the front matter is exactly what
 * the parser reads back. A draft that does not parse is worse than no draft.
 *
 * @param array<string, mixed> $gathered
 */
function to_markdown(string $name, array $gathered): string
{
    $lines = ['---', 'name: ' . scrub($name)];

    /** @var array<array-key, string> $colors */
    $colors = $gathered['colors'];
    if ($colors !== []) {
        $lines[] = 'colors:';
        foreach (array_slice($colors, offset: 0, length: 24, preserve_keys: true) as $key => $value) {
            $lines[] = sprintf('  %s: "%s"', scrub((string) $key), scrub($value));
        }
    }
    /** @var array<array-key, array<string, string>> $typography */
    $typography = $gathered['typography'];
    if ($typography !== []) {
        $lines[] = 'typography:';
        foreach (array_slice($typography, offset: 0, length: 8, preserve_keys: true) as $role => $props) {
            $lines[] = sprintf('  %s:', scrub((string) $role));
            foreach ($props as $prop => $value) {
                $lines[] = sprintf('    %s: "%s"', $prop, scrub((string) $value));
            }
        }
    }
    /** @var array<array-key, string> $spacing */
    $spacing = $gathered['spacing'];
    if ($spacing !== []) {
        $lines[] = 'spacing:';
        foreach (array_slice($spacing, offset: 0, length: 10, preserve_keys: true) as $key => $value) {
            // PHP turns a numeric slug such as Twenty Twenty-Five's "20" into an int key.
            $lines[] = sprintf('  %s: "%s"', scrub((string) $key), scrub($value));
        }
    }
    $lines[] = '---';
    $lines[] = '';
    $lines[] = '# ' . scrub($name);
    $lines[] = '';
    $lines[] = __(
        'Adopted from what this site already looks like, so new work matches the existing pages rather than competing with them.',
        domain: 'wppilot',
    );
    $lines[] = '';
    $lines[] = sprintf(
        /* translators: %s: comma-separated source names. */
        __('Read from: %s.', domain: 'wppilot'),
        implode(', ', array_map(
            static fn(array $s): string => (string) $s['source'],
            is_array($gathered['sources']) ? $gathered['sources'] : [],
        )),
    );
    $lines[] = '';
    $lines[] = '## Review before building';
    $lines[] = '';
    $lines[] = __(
        'This is a draft. It carries the tokens the site already uses and none of the reasoning behind them: which colour is the accent rather than merely present, what the site should never do, how much variation a page is allowed. Add those before anybody builds from it, or the design will be followed literally and still miss.',
        domain: 'wppilot',
    );

    return implode("\n", $lines) . "\n";
}

/** Normalise a colour to lowercase six-digit hex, or '' if it is not one. */
function normalize(string $value): string
{
    $value = trim($value);
    if (preg_match('/^#?([0-9a-f]{6})$/i', $value, $m) === 1) {
        return '#' . strtolower($m[1]);
    }
    if (preg_match('/^#?([0-9a-f]{3})$/i', $value, $m) === 1) {
        $s = strtolower($m[1]);

        return '#' . $s[0] . $s[0] . $s[1] . $s[1] . $s[2] . $s[2];
    }
    if (preg_match('/rgba?\(\s*(\d{1,3})\s*[,\s]\s*(\d{1,3})\s*[,\s]\s*(\d{1,3})/i', $value, $m) === 1) {
        foreach ([1, 2, 3] as $i) {
            if ((int) $m[$i] > 255) {
                return '';
            }
        }

        return sprintf('#%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3]);
    }

    return '';
}

/** The first real family in a CSS font stack. */
function first_family(string $stack): string
{
    foreach (explode(',', $stack) as $family) {
        $family = trim($family, " \t\n\r\0\x0B\"'");
        if ($family !== '' && !str_starts_with($family, 'var(')) {
            return $family;
        }
    }

    return '';
}

/** Token names in the lowercase-dashed form DESIGN.md uses. */
function token_key(string $name): string
{
    $name = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $name) ?? $name;
    $name = strtolower(str_replace([' ', '_', '/'], '-', $name));
    $name = preg_replace('/[^a-z0-9-]/', '', $name) ?? $name;
    // Collapse runs: a builder label like "Accent / Meta" turns three separators
    // into three dashes, and "accent---meta" reads as a typo in the file a person
    // is about to review.
    $name = preg_replace('/-{2,}/', '-', $name) ?? $name;

    return trim($name, characters: '-');
}

/** Keep a value on one line and out of the front matter's syntax. */
function scrub(string $value): string
{
    $value = str_replace(['"', "\r", "\n"], ['', ' ', ' '], $value);

    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}
