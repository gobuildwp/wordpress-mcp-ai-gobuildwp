<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The few wp-admin functions a request handler under test reaches.
 *
 * Kept out of doubles/wordpress.php because only tests that load an admin screen need them, and
 * wp_die() and wp_safe_redirect() have to stop the handler the way exit() would: they throw.
 */

final class WPPilot_Test_Halt extends RuntimeException
{
    public function __construct(public string $kind, string $message, public int $status = 0)
    {
        parent::__construct($message);
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    /** @param array<string, string> $args */
    function add_query_arg(array $args, string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

if (!function_exists('wp_die')) {
    /** @param array<string, mixed> $args */
    function wp_die(string $message = '', string $title = '', array $args = []): void
    {
        throw new WPPilot_Test_Halt('die', $message, (int) ($args['response'] ?? 500));
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302): bool
    {
        throw new WPPilot_Test_Halt('redirect', $location, $status);
    }
}

if (!function_exists('check_admin_referer')) {
    /** A nonce is valid in these tests when it reads "nonce-<action>", as wp_nonce_field() would have printed it. */
    function check_admin_referer(string $action = '-1', string $query_arg = '_wpnonce'): int
    {
        if (($_REQUEST[$query_arg] ?? null) !== 'nonce-' . $action) {
            wp_die('The link you followed has expired.', '', ['response' => 403]);
        }

        return 1;
    }
}
