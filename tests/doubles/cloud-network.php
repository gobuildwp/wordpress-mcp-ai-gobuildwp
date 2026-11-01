<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Multisite doubles for the Cloud inventory network block.
 *
 * Subsites are $GLOBALS['wppilot_test_sites']: blog_id => [domain, path, deleted,
 * options => [name => value]]. Site options share WPPilot_Test_State::$options with
 * get_site_option() in cloud.php; every write and delete is also logged in
 * $GLOBALS['wppilot_test_site_option_calls'] so a test can tell which API was used.
 * Every double is guarded, so a general one added later simply wins.
 */

if (!function_exists('get_sites')) {
    /** @param array<string, mixed> $args */
    function get_sites(array $args = []): array|int
    {
        $GLOBALS['wppilot_test_get_sites_calls'][] = $args;
        $ids = array_map('intval', array_keys($GLOBALS['wppilot_test_sites'] ?? []));
        sort($ids);
        if (!empty($args['count'])) {
            return count($ids);
        }
        $number = (int) ($args['number'] ?? 100);
        if ($number > 0) {
            $ids = array_slice($ids, 0, $number);
        }

        return $ids;
    }
}

if (!function_exists('get_site')) {
    function get_site(mixed $site = null): ?object
    {
        $row = $GLOBALS['wppilot_test_sites'][(int) $site] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return (object) [
            'blog_id' => (string) (int) $site,
            'domain' => (string) ($row['domain'] ?? ''),
            'path' => (string) ($row['path'] ?? '/'),
            'deleted' => (string) (int) ($row['deleted'] ?? 0),
        ];
    }
}

if (!function_exists('get_blog_option')) {
    function get_blog_option(?int $id, string $option, mixed $default_value = false): mixed
    {
        return $GLOBALS['wppilot_test_sites'][(int) $id]['options'][$option] ?? $default_value;
    }
}

if (!function_exists('update_site_option')) {
    function update_site_option(string $option, mixed $value): bool
    {
        $GLOBALS['wppilot_test_site_option_calls'][] = ['update', $option];
        WPPilot_Test_State::$options[$option] = $value;

        return true;
    }
}

if (!function_exists('delete_site_option')) {
    function delete_site_option(string $option): bool
    {
        $GLOBALS['wppilot_test_site_option_calls'][] = ['delete', $option];
        unset(WPPilot_Test_State::$options[$option]);

        return true;
    }
}
