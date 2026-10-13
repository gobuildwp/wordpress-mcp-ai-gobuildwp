<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Doubles for the server card, skill resources and Connection Doctor tests.
 *
 * Kept apart from wordpress.php and loaded only by those tests, and every one is guarded, so a
 * general double added to wordpress.php later simply wins.
 */

if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return 'https://example.test/wp-json/' . ltrim($path, '/');
    }
}

if (!function_exists('site_url')) {
    function site_url(string $path = ''): string
    {
        return (string) ($GLOBALS['wppilot_test_site_url'] ?? 'https://example.test') . $path;
    }
}

if (!function_exists('is_ssl')) {
    function is_ssl(): bool
    {
        return (bool) ($GLOBALS['wppilot_test_is_ssl'] ?? true);
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $expiration = 0): bool
    {
        $GLOBALS['wppilot_test_transients'][$key] = $value;

        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $key): mixed
    {
        return $GLOBALS['wppilot_test_transients'][$key] ?? false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        unset($GLOBALS['wppilot_test_transients'][$key]);

        return true;
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $special_chars = true): string
    {
        return substr(bin2hex(random_bytes($length)), 0, $length);
    }
}

if (!function_exists('wp_remote_request')) {
    /** @param array<string, mixed> $args */
    function wp_remote_request(string $url, array $args = []): array
    {
        throw new LogicException('A test sent a real request to ' . $url . '; inject a fixture instead.');
    }
}

if (!class_exists('WPPilot_Test_Rest_Request')) {
    /**
     * A REST request with the route, method and headers the post-dispatch filters read.
     */
    final class WPPilot_Test_Rest_Request extends WP_REST_Request
    {
        /** @var array<string, string> */
        private array $test_headers = [];

        /** @param array<string, string> $headers */
        public function __construct(
            private string $test_route = '',
            private string $test_method = 'POST',
            mixed $json = null,
            array $headers = [],
        ) {
            parent::__construct($json);
            foreach ($headers as $name => $value) {
                $this->test_headers[strtolower(str_replace('-', '_', $name))] = $value;
            }
        }

        public function get_route(): string
        {
            return $this->test_route;
        }

        public function get_method(): string
        {
            return $this->test_method;
        }

        public function get_header(string $key): ?string
        {
            return $this->test_headers[strtolower(str_replace('-', '_', $key))] ?? null;
        }
    }
}

if (!class_exists('WPPilot_Test_Rest_Response')) {
    /**
     * A REST response with the accessors and header store of WP_HTTP_Response.
     */
    final class WPPilot_Test_Rest_Response extends WP_REST_Response
    {
        /** @var array<string, string> */
        private array $test_headers = [];

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function set_data(mixed $data): void
        {
            $this->data = $data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function set_status(int $status): void
        {
            $this->status = $status;
        }

        public function header(string $key, string $value, bool $replace = true): void
        {
            $this->test_headers[$key] = $value;
        }

        /** @return array<string, string> */
        public function get_headers(): array
        {
            return $this->test_headers;
        }

        /** @param array<string, string> $headers */
        public function set_headers(array $headers): void
        {
            $this->test_headers = $headers;
        }
    }
}
