<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!function_exists('wp_remote_retrieve_header')) {
    /**
     * Reads a header from a response double, case-insensitively as WordPress does. Doubles that
     * carry no headers answer ''.
     *
     * @param array<string, mixed>|WP_Error $response
     */
    function wp_remote_retrieve_header(array|WP_Error $response, string $header): string
    {
        if (!is_array($response) || !is_array($response['headers'] ?? null)) {
            return '';
        }
        $headers = array_change_key_case($response['headers'], CASE_LOWER);
        return (string) ($headers[strtolower($header)] ?? '');
    }
}
