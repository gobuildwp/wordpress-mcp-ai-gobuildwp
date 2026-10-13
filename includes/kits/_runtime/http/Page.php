<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Runtime;

use DOMDocument;
use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Fetch a page of this site as a visitor receives it, and parse it.
 *
 * Audits read served HTML, not post content: what a builder, a theme and three plugins made of
 * the content is what a screen reader and a search engine see. Modelled on GoBuildWP's design
 * checker (includes/design/rendered.php) and kept separate from it, so a kit carries it into a
 * plugin that has no design checker.
 *
 * Only this site's own pages are fetched. An agent choosing the URL must not be able to point
 * the server at an internal address, and wp_safe_remote_get() re-checks every redirect hop.
 */
final class Page
{
    /**
     * @return array{url: string, status: int, html: string, bytes: int, elapsed_ms: int}|WP_Error
     */
    public static function fetch(string $url, int $timeout = 20): array|WP_Error
    {
        return self::request($url, $timeout, []);
    }

    /**
     * Fetch a page as the signed-in user sees it, for an unpublished page (a draft's preview).
     *
     * A session made for this one fetch: it lasts a minute, is sent only to this site over the
     * site's own scheme and port, never follows a redirect (WordPress re-sends cookies to every
     * hop), and is destroyed as soon as the page is back.
     *
     * @return array{url: string, status: int, html: string, bytes: int, elapsed_ms: int}|WP_Error
     */
    public static function fetch_as_current_user(string $url, int $timeout = 20): array|WP_Error
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0 || !class_exists('WP_Session_Tokens') || !function_exists('wp_generate_auth_cookie') || !defined('LOGGED_IN_COOKIE')) {
            return new WP_Error('kit_page_no_session', 'An unpublished page can only be read as a signed-in user, and this request has none.');
        }
        if (!self::is_same_origin($url)) {
            return new WP_Error('kit_page_bad_url', 'A signed-in fetch only reads this site, over the scheme and port of the site address.');
        }
        $expiration = time() + 60;
        $sessions = \WP_Session_Tokens::get_instance($user_id);
        $token = $sessions->create($expiration);
        try {
            $values = [LOGGED_IN_COOKIE => wp_generate_auth_cookie($user_id, $expiration, 'logged_in', $token)];
            if (is_ssl() && defined('SECURE_AUTH_COOKIE')) {
                $values[SECURE_AUTH_COOKIE] = wp_generate_auth_cookie($user_id, $expiration, 'secure_auth', $token);
            } elseif (defined('AUTH_COOKIE')) {
                $values[AUTH_COOKIE] = wp_generate_auth_cookie($user_id, $expiration, 'auth', $token);
            }
            $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            $cookies = [];
            foreach ($values as $name => $value) {
                $cookies[] = new \WP_Http_Cookie(['name' => (string) $name, 'value' => (string) $value, 'domain' => $host, 'path' => '/']);
            }
            $page = self::request($url, $timeout, ['redirection' => 0, 'cookies' => $cookies]);
        } finally {
            $sessions->destroy($token);
        }
        if (is_array($page) && $page['status'] >= 300 && $page['status'] < 400) {
            return new WP_Error('kit_page_redirected', 'The preview redirected, and a signed-in fetch does not follow redirects. Publish the page, or check the redirect rule.');
        }

        return $page;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array{url: string, status: int, html: string, bytes: int, elapsed_ms: int}|WP_Error
     */
    private static function request(string $url, int $timeout, array $extra): array|WP_Error
    {
        $url = esc_url_raw($url);
        if ($url === '' || !wp_http_validate_url($url) || !self::is_same_site($url)) {
            return new WP_Error('kit_page_bad_url', 'Only pages on this site can be fetched.');
        }
        $started = microtime(true);
        $response = wp_safe_remote_get($url, array_merge([
            'timeout' => max(5, min(30, $timeout)),
            'redirection' => 3,
            'user-agent' => 'WordPress kit audit (' . home_url('/') . ')',
        ], $extra));
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        if ($response instanceof WP_Error) {
            return new WP_Error('kit_page_unreachable', 'Could not fetch the page: ' . $response->get_error_message());
        }
        $html = (string) wp_remote_retrieve_body($response);
        return [
            'url' => $url,
            'status' => (int) wp_remote_retrieve_response_code($response),
            'html' => $html,
            'bytes' => strlen($html),
            'elapsed_ms' => $elapsed,
        ];
    }

    /** Parse HTML into a tree to walk, without letting libxml's complaints reach the response. */
    public static function parse(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        // Real pages are not valid XML; the goal is a tree to walk, not a verdict on the markup.
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $loaded ? $document : null;
    }

    /** Same host, scheme and port as the site address: where a session cookie may be sent. */
    public static function is_same_origin(string $url): bool
    {
        $target = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!is_array($target) || !is_array($home) || !self::is_same_site($url)) {
            return false;
        }
        $scheme = strtolower((string) ($target['scheme'] ?? ''));
        $home_scheme = strtolower((string) ($home['scheme'] ?? ''));
        $port = (int) ($target['port'] ?? ($scheme === 'https' ? 443 : 80));
        $home_port = (int) ($home['port'] ?? ($home_scheme === 'https' ? 443 : 80));

        return $scheme !== '' && $scheme === $home_scheme && $port === $home_port;
    }

    public static function is_same_site(string $url): bool
    {
        $target = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        return is_array($target) && is_array($home)
            && strtolower((string) ($target['host'] ?? '')) === strtolower((string) ($home['host'] ?? ''));
    }
}
