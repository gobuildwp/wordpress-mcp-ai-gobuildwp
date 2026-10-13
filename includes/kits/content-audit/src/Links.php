<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ContentAudit;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Finding and classifying the links in stored content.
 *
 * Reads what is stored — post_content and Elementor's JSON — not served HTML. Fetching every
 * page to read its links would cost one HTTP request per post; the links an editor put in the
 * content are the ones an editor can fix. Links a theme, a menu or a shortcode prints are not
 * seen, and the README says so.
 */
final class Links
{
    /** Keys under which page-builder JSON keeps the text a visitor reads. */
    private const BUILDER_TEXT_KEYS = ['title', 'editor', 'text', 'description', 'content', 'caption', 'html', 'testimonial_content', 'tab_content', 'item_description', 'alert_description'];

    /**
     * Every href in a chunk of HTML, entity-decoded, in document order.
     *
     * @return list<string>
     */
    public static function from_html(string $html): array
    {
        if (stripos($html, 'href') === false) {
            return [];
        }
        preg_match_all('/<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $matches, PREG_SET_ORDER);
        $links = [];
        foreach ($matches as $match) {
            $href = $match[1] !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
            $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($href !== '') {
                $links[] = $href;
            }
        }
        return $links;
    }

    /**
     * Links inside page-builder JSON: link controls (`{"url": …}`) and hrefs in text fields.
     *
     * @return list<string>
     */
    public static function from_builder(string $json): array
    {
        if ($json === '') {
            return [];
        }
        /** @var mixed $data */
        $data = json_decode($json, true);
        $links = [];
        self::walk($data, $links, null, 0);
        return $links;
    }

    /**
     * The visible text of stored content and builder data, for a word count.
     */
    public static function text(string $content, string $builder): string
    {
        // Block delimiters and other comments are markup, not words.
        $text = (string) preg_replace('/<!--.*?-->/s', ' ', $content);
        $text = (string) preg_replace('/\[[^\]]+\]/', ' ', $text);
        $parts = [strip_tags($text)];
        if ($builder !== '') {
            /** @var mixed $data */
            $data = json_decode($builder, true);
            $strings = [];
            self::builder_text($data, $strings, 0);
            $parts[] = strip_tags(implode(' ', $strings));
        }
        return trim(html_entity_decode(implode(' ', $parts), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function word_count(string $text): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'\x{2019}-]*/u', $text);
    }

    /**
     * `internal`, `external` or `skip` (anchors, mail, phone, script, data, relative paths
     * that name no page).
     */
    public static function classify(string $url, string $home): string
    {
        $url = trim($url);
        if ($url === '' || $url[0] === '#' || $url[0] === '?') {
            return 'skip';
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $scheme) === 1 && !in_array(strtolower($scheme[1]), ['http', 'https'], true)) {
            return 'skip';
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        } elseif ($url[0] === '/') {
            return 'internal';
        } elseif (preg_match('#^https?://#i', $url) !== 1) {
            // `page-2.html` relative to wherever the post is shown: not resolvable to one target.
            return 'skip';
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $home_host = strtolower((string) parse_url($home, PHP_URL_HOST));
        if ($host === '') {
            return 'skip';
        }
        return self::bare_host($host) === self::bare_host($home_host) ? 'internal' : 'external';
    }

    /**
     * An internal link as an absolute URL on the home host, without its fragment.
     */
    public static function absolute(string $url, string $home): string
    {
        $url = trim($url);
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }
        if (str_starts_with($url, '//')) {
            $url = (string) parse_url($home, PHP_URL_SCHEME) . ':' . $url;
        }
        if ($url !== '' && $url[0] === '/') {
            $scheme = (string) parse_url($home, PHP_URL_SCHEME);
            $host = (string) parse_url($home, PHP_URL_HOST);
            $port = parse_url($home, PHP_URL_PORT);
            $url = $scheme . '://' . $host . ($port !== null && $port !== false ? ':' . $port : '') . $url;
        }
        return $url;
    }

    /** An external link without its fragment, so one page linked twice is checked once. */
    public static function external_key(string $url): string
    {
        $url = trim($url);
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        $hash = strpos($url, '#');
        return $hash === false ? $url : substr($url, 0, $hash);
    }

    private static function bare_host(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * @param list<string> $links
     */
    private static function walk(mixed $node, array &$links, ?string $key, int $depth): void
    {
        if ($depth > 32) {
            return;
        }
        if (is_array($node)) {
            foreach ($node as $child_key => $child) {
                self::walk($child, $links, is_string($child_key) ? $child_key : $key, $depth + 1);
            }
            return;
        }
        if (!is_string($node) || $node === '') {
            return;
        }
        if ($key === 'url') {
            $links[] = trim($node);
        } elseif (stripos($node, '<a ') !== false) {
            foreach (self::from_html($node) as $href) {
                $links[] = $href;
            }
        }
    }

    /**
     * @param list<string> $strings
     */
    private static function builder_text(mixed $node, array &$strings, int $depth): void
    {
        if ($depth > 32 || !is_array($node)) {
            return;
        }
        foreach ($node as $key => $child) {
            if (is_string($child) && is_string($key) && in_array($key, self::BUILDER_TEXT_KEYS, true)) {
                $strings[] = $child;
            } elseif (is_array($child)) {
                self::builder_text($child, $strings, $depth + 1);
            }
        }
    }
}
