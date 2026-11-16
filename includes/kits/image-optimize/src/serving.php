<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\ImageOptimize;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Serving the WebP/AVIF copies.
 *
 * The kit never writes server rewrite rules. On OpenLiteSpeed an .htaccess rule is ignored unless
 * the server is set to read it, so a rule-based setup reports success and serves nothing; nginx
 * ignores .htaccess entirely. Markup works on every server: with mode `picture`, a front-end
 * <img> of an optimised attachment is wrapped in <picture> with an AVIF and/or WebP <source>,
 * and the browser falls back to the <img> it understands. An image is wrapped only when every
 * file its srcset names has a copy in that format, so a browser never picks a missing file.
 */

/**
 * @return array{software: string, kind: string, root_htaccess: bool, rewrite_rules: string}
 */
function server_info(): array
{
    $software = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash((string) $_SERVER['SERVER_SOFTWARE'])) : '';
    $edition = isset($_SERVER['LSWS_EDITION']) ? sanitize_text_field(wp_unslash((string) $_SERVER['LSWS_EDITION'])) : '';
    $lower = strtolower($software . ' ' . $edition);
    $kind = match (true) {
        str_contains($lower, 'openlitespeed') => 'openlitespeed',
        str_contains($lower, 'litespeed') => 'litespeed',
        str_contains($lower, 'apache') => 'apache',
        str_contains($lower, 'nginx') => 'nginx',
        str_contains($lower, 'microsoft-iis') => 'iis',
        default => 'unknown',
    };
    $rules = match ($kind) {
        'openlitespeed' => 'not used: OpenLiteSpeed ignores .htaccess unless the server is set to read it, so rewrite rules would report success and do nothing',
        'litespeed' => 'not used: LiteSpeed reports the same name for OpenLiteSpeed, which ignores .htaccess by default, so rewrite rules cannot be trusted here',
        'apache' => 'not used: Apache could serve copies with .htaccess rules, but this kit never writes server configuration',
        'nginx', 'iis' => 'not used: this server ignores .htaccess and needs its own configuration, which this kit never writes',
        default => 'not used: the server is not recognised, so rewrite rules cannot be checked',
    };
    return [
        'software' => $software,
        'kind' => $kind,
        'root_htaccess' => defined('ABSPATH') && is_file(ABSPATH . '.htaccess'),
        'rewrite_rules' => $rules,
    ];
}

function serving_description(string $mode): string
{
    return $mode === 'picture'
        ? 'Front-end images of optimised attachments are wrapped in <picture> with AVIF/WebP <source> elements; browsers without support use the original <img>. Works on any server, no rewrite rules.'
        : 'Off: copies are kept on disk but pages still load the original files.';
}

/** Whether this request renders a front-end page the copies may be served on. */
function should_serve(): bool
{
    if (serve_mode() !== 'picture' || is_admin() || is_feed() || wp_doing_ajax() || wp_is_json_request()) {
        return false;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return false;
    }
    return !(function_exists('amp_is_request') && amp_is_request());
}

function strip_query(string $url): string
{
    return (string) preg_replace('/[?#].*$/s', '', $url);
}

/** The uploads-relative file a URL names, or null when it is not under uploads. */
function url_to_relative(string $url): ?string
{
    $uploads = wp_get_upload_dir();
    $base = is_string($uploads['baseurl'] ?? null) ? $uploads['baseurl'] : '';
    $strip = static fn(string $u): string => (string) preg_replace('#^(https?:)?//#i', '', $u);
    $url = $strip(strip_query(html_entity_decode($url, ENT_QUOTES)));
    $base = rtrim($strip($base), '/');
    if ($base === '' || !str_starts_with($url, $base . '/')) {
        return null;
    }
    return rawurldecode(substr($url, strlen($base) + 1));
}

function attribute(string $tag, string $name): ?string
{
    if (preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $m) !== 1) {
        return null;
    }
    return html_entity_decode($m[2] !== '' ? $m[2] : ($m[3] ?? ''), ENT_QUOTES);
}

/**
 * <img> wrapped in <picture>, or the tag unchanged when any of its files lacks a copy.
 */
function wrap_img(string $img, int $id = 0): string
{
    if ($id <= 0) {
        $class = (string) attribute($img, 'class');
        if (preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class, $m) !== 1) {
            return $img;
        }
        $id = (int) $m[1];
    }
    $record = record_of($id);
    $copies = is_array($record['copies'] ?? null) ? $record['copies'] : [];
    $src = attribute($img, 'src');
    if ($copies === [] || $src === null || $src === '') {
        return $img;
    }
    $srcset = attribute($img, 'srcset');
    $candidates = [];
    foreach ($srcset !== null && trim($srcset) !== '' ? explode(',', $srcset) : [$src] as $part) {
        $bits = preg_split('/\s+/', trim($part), 2);
        if (is_array($bits) && $bits[0] !== '') {
            $candidates[] = [strip_query($bits[0]), trim($bits[1] ?? '')];
        }
    }
    // The src is what a browser without srcset support loads, so it needs a copy too.
    $required = array_unique(array_merge(array_column($candidates, 0), [strip_query($src)]));
    $sizes = attribute($img, 'sizes');

    $sources = '';
    foreach (array_keys(COPY_FORMATS) as $format) {
        foreach ($required as $url) {
            $relative = url_to_relative($url);
            if ($relative === null || !isset($copies[$relative][$format])) {
                continue 2;
            }
        }
        $set = array_map(static fn(array $c): string => trim($c[0] . '.' . $format . ' ' . $c[1]), $candidates);
        $sources .= '<source type="' . esc_attr(COPY_FORMATS[$format]) . '" srcset="' . esc_attr(implode(', ', $set)) . '"'
            . ($sizes !== null && $sizes !== '' ? ' sizes="' . esc_attr($sizes) . '"' : '') . '>';
    }
    return $sources === '' ? $img : '<picture>' . $sources . $img . '</picture>';
}

/** the_content, after core added srcset (priority 12): wrap every <img> not already in a <picture>. */
function filter_content(mixed $content): mixed
{
    if (!is_string($content) || stripos($content, '<img') === false || !should_serve()) {
        return $content;
    }
    $parts = preg_split('#(<picture\b.*?</picture>)#is', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) {
        return $content;
    }
    foreach ($parts as $index => $part) {
        if ($index % 2 === 1) {
            continue;
        }
        $parts[$index] = (string) preg_replace_callback('#<img\b[^>]*>#i', static fn(array $m): string => wrap_img($m[0]), $part);
    }
    return implode('', $parts);
}

/** wp_get_attachment_image: featured images, galleries and builders that render through core. */
function filter_attachment_image(mixed $html, mixed $id = 0): mixed
{
    if (!is_string($html) || stripos($html, '<picture') !== false || !should_serve()) {
        return $html;
    }
    return (string) preg_replace_callback('#<img\b[^>]*>#i', static fn(array $m): string => wrap_img($m[0], (int) $id), $html, 1);
}

function register_serving(): void
{
    add_filter('the_content', __NAMESPACE__ . '\\filter_content', 20);
    add_filter('wp_get_attachment_image', __NAMESPACE__ . '\\filter_attachment_image', 20, 2);
    add_action('delete_attachment', static function (mixed $id): void {
        forget_attachment((int) $id);
    });
}

/**
 * What the server and image editor can do, how copies are served, and how far the bulk run got.
 *
 * @return array<string, mixed>
 */
function status(): array
{
    $editor = [];
    foreach (['image/jpeg', 'image/png', 'image/webp', 'image/avif'] as $mime) {
        $editor[substr($mime, 6)] = wp_image_editor_supports(['mime_type' => $mime]);
    }
    $mode = serve_mode();
    $cursor = (int) get_option(CURSOR_OPTION, 0);
    return [
        'server' => server_info(),
        'editor_supports' => $editor,
        'serving' => [
            'mode' => $mode,
            'method' => $mode === 'picture' ? 'picture-markup' : 'none',
            'how' => serving_description($mode),
        ],
        'images' => [
            'jpeg_png_attachments' => count_candidates(),
            'optimized' => count_optimized(),
            'after_cursor' => count_candidates($cursor),
        ],
        'cursor' => $cursor > 0 ? $cursor : null,
        'defaults' => [
            'quality' => DEFAULT_QUALITY,
            'max_dimension' => DEFAULT_MAX_DIMENSION,
            'formats' => ['webp'],
            'batch' => DEFAULT_BATCH,
        ],
    ];
}
