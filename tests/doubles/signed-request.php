<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * A REST request with a raw body and headers, for the Cloud request-signing tests.
 *
 * Header names are folded the way WordPress folds them (lower case, `-` to `_`),
 * so `X-GoBuildWP-Sig` and `x_gobuildwp_sig` find the same value.
 */

require_once __DIR__ . '/wordpress.php';

if (!class_exists('WPPilot_Test_Signed_Request')) {
    final class WPPilot_Test_Signed_Request extends WP_REST_Request
    {
        /** @var array<string, string> */
        private array $test_headers = [];

        /** @param array<string, string> $headers */
        public function __construct(
            private string $test_route,
            private string $test_method = 'POST',
            private string $test_body = '',
            array $headers = [],
        ) {
            parent::__construct(json_decode($test_body, associative: true));
            foreach ($headers as $name => $value) {
                $this->test_headers[self::fold($name)] = $value;
            }
        }

        private static function fold(string $name): string
        {
            return strtolower(str_replace('-', '_', $name));
        }

        public function get_route(): string
        {
            return $this->test_route;
        }

        public function get_method(): string
        {
            return $this->test_method;
        }

        public function get_body(): string
        {
            return $this->test_body;
        }

        public function get_header(string $key): ?string
        {
            return $this->test_headers[self::fold($key)] ?? null;
        }
    }
}
