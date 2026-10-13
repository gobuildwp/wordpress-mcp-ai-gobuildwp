<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The host, HTTP, transients and capabilities the pagespeed kit sees in these tests, defined in
 * the kit's own namespace so the rest of the suite keeps the global doubles. HTTP answers are
 * queued per test; every request is recorded with its URL and arguments.
 */

namespace WPPilot\Tests\Unit\Kits\Pagespeed {
    use WP_Error;
    use WPPilot\Kits\Runtime\Host;
    use WPPilot\Kits\Runtime\Jobs;
    use WPPilot\Kits\Runtime\Ledger;

    final class Net
    {
        /** @var list<array{method: string, url: string, args: array<string, mixed>}> */
        public static array $requests = [];

        /** @var list<array<string, mixed>|WP_Error> */
        public static array $answers = [];

        /** @var array<string, mixed> */
        public static array $transients = [];

        /** @var list<string> */
        public static array $caps = [];

        /** @var list<string> */
        public static array $abilities = [];

        /** @var array<string, mixed> */
        public static array $options = [];

        public static int $userId = 1;

        public static function reset(): void
        {
            self::$options = [];
            self::$userId = 1;
            self::$requests = [];
            self::$answers = [];
            self::$transients = [];
            self::$caps = ['manage_options'];
            self::$abilities = [];
        }

        /** @param array<string, mixed> $args */
        public static function take(string $method, string $url, array $args): array|WP_Error
        {
            self::$requests[] = ['method' => $method, 'url' => $url, 'args' => $args];
            if (self::$answers === []) {
                throw new \LogicException('Unexpected HTTP request to ' . $url);
            }
            return array_shift(self::$answers);
        }

        /** @param array<string, mixed> $json */
        public static function json(int $status, array $json, array $headers = []): array
        {
            return ['response' => ['code' => $status], 'body' => (string) json_encode($json), 'headers' => $headers];
        }
    }

    final class Registrations
    {
        /** @var array<string, array<string, mixed>> */
        public static array $args = [];
    }

    final class SpeedHost implements Host
    {
        public mixed $cloudUrl = 'https://cloud.example';

        public mixed $signer = null;

        public function id(): string
        {
            return 'test';
        }

        public function can_manage(): bool
        {
            return true;
        }

        public function is_enabled(): bool
        {
            return true;
        }

        public function safety_profile(): string
        {
            return 'production';
        }

        public function ledger(): Ledger
        {
            throw new \LogicException('A read-only kit never records a change.');
        }

        public function jobs(): Jobs
        {
            throw new \LogicException('not used');
        }

        public function extension(string $point): mixed
        {
            if ($point === 'cloud-sign') {
                return $this->signer;
            }
            return $point === 'cloud-url' ? $this->cloudUrl : null;
        }

        public function admin_parent_slug(): string
        {
            return 'tools.php';
        }

        public function confirm_guard(string $ability_name, array $input): bool|WP_Error
        {
            return true;
        }
    }
}

namespace WPPilot\Kits\Pagespeed {
    use WPPilot\Tests\Unit\Kits\Pagespeed\Net;

    if (!function_exists(__NAMESPACE__ . '\\wp_remote_get')) {
        /** @param array<string, mixed> $args */
        function wp_remote_get(string $url, array $args = []): array|\WP_Error
        {
            return Net::take('GET', $url, $args);
        }

        /** @param array<string, mixed> $args */
        function wp_remote_post(string $url, array $args = []): array|\WP_Error
        {
            return Net::take('POST', $url, $args);
        }

        /** @param array<string, mixed> $response */
        function wp_remote_retrieve_response_code(array $response): int
        {
            return (int) ($response['response']['code'] ?? 0);
        }

        /** @param array<string, mixed> $response */
        function wp_remote_retrieve_body(array $response): string
        {
            return (string) ($response['body'] ?? '');
        }

        /** @param array<string, mixed> $response */
        function wp_remote_retrieve_header(array $response, string $header): string
        {
            return (string) ($response['headers'][$header] ?? '');
        }

        function get_transient(string $key): mixed
        {
            return Net::$transients[$key] ?? false;
        }

        function set_transient(string $key, mixed $value, int $ttl = 0): bool
        {
            Net::$transients[$key] = $value;
            return true;
        }

        function current_user_can(string $capability, mixed ...$args): bool
        {
            return in_array($capability . ($args === [] ? '' : ':' . implode(',', array_map('strval', $args))), Net::$caps, true)
                || in_array($capability, Net::$caps, true);
        }

        function get_option(string $option, mixed $default_value = false): mixed
        {
            return array_key_exists($option, Net::$options) ? Net::$options[$option] : $default_value;
        }

        function wp_has_ability(string $name): bool
        {
            return in_array($name, Net::$abilities, true);
        }

        function get_current_user_id(): int
        {
            return Net::$userId;
        }

        function admin_url(string $path = ''): string
        {
            return 'https://example.test/wp-admin/' . $path;
        }

        /** @param array<string, mixed> $args */
        function wp_register_ability(string $name, array $args): mixed
        {
            \WPPilot\Tests\Unit\Kits\Pagespeed\Registrations::$args[$name] = $args;
            return null;
        }
    }
}
