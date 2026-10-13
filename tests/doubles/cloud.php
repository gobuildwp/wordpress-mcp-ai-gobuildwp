<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Doubles for the GoBuildWP Cloud pairing tests.
 *
 * Loaded only by those tests, and every one is guarded, so a general double
 * added to wordpress.php later simply wins.
 */

if (!function_exists('wp_get_environment_type')) {
    function wp_get_environment_type(): string
    {
        return (string) ($GLOBALS['wppilot_test_environment_type'] ?? 'production');
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    /** @param array<string, mixed>|WP_Error $response */
    function wp_remote_retrieve_response_code(array|WP_Error $response): int|string
    {
        return is_array($response) && is_array($response['response'] ?? null) ? (int) ($response['response']['code'] ?? 0) : '';
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    /** @param array<string, mixed>|WP_Error $response */
    function wp_remote_retrieve_body(array|WP_Error $response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }
}

if (!function_exists('wp_specialchars_decode')) {
    function wp_specialchars_decode(string $text, int|string $quote_style = ENT_NOQUOTES): string
    {
        return htmlspecialchars_decode($text, is_int($quote_style) ? $quote_style : ENT_QUOTES);
    }
}

if (!function_exists('wp_schedule_single_event')) {
    /** @param array<array-key, mixed> $args */
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
    {
        $GLOBALS['wppilot_test_single_events'][] = ['hook' => $hook, 'timestamp' => $timestamp];
        // So wp_next_scheduled() sees it, as it would in WordPress.
        WPPilot_Test_State::$cron[$hook] ??= $timestamp;

        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string|int
    {
        return $type === 'mysql' ? gmdate('Y-m-d H:i:s') : time();
    }
}

if (!function_exists('user_can')) {
    function user_can(int|WP_User $user, string $capability): bool
    {
        $user_id = $user instanceof WP_User ? $user->ID : $user;

        return in_array($user_id, $GLOBALS['wppilot_test_managers'] ?? [], true);
    }
}

if (!function_exists('is_super_admin')) {
    function is_super_admin(int $user_id = 0): bool
    {
        return false;
    }
}

if (!function_exists('register_rest_route')) {
    /** @param array<string, mixed> $args */
    function register_rest_route(string $namespace, string $route, array $args = []): bool
    {
        $GLOBALS['wppilot_test_rest_routes'][$namespace . $route] = $args;

        return true;
    }
}

if (!function_exists('get_site_transient')) {
    function get_site_transient(string $transient): mixed
    {
        return $GLOBALS['wppilot_test_site_transients'][$transient] ?? false;
    }
}

if (!function_exists('get_site_option')) {
    function get_site_option(string $option, mixed $default_value = false): mixed
    {
        return WPPilot_Test_State::$options[$option] ?? $default_value;
    }
}

if (!function_exists('wp_get_theme')) {
    /** Installed themes are $GLOBALS['wppilot_test_themes']: stylesheet => [Name, Version]. */
    function wp_get_theme(string $stylesheet = ''): object
    {
        $headers = $GLOBALS['wppilot_test_themes'][$stylesheet] ?? null;

        return new class($headers) {
            /** @param array<string, string>|null $headers */
            public function __construct(private ?array $headers) {}

            public function exists(): bool
            {
                return $this->headers !== null;
            }

            public function get(string $header): string
            {
                return $this->headers[$header] ?? '';
            }
        };
    }
}
