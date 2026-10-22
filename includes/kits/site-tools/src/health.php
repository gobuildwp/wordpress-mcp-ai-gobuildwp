<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteTools\Health;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/** Longest description or action text kept per test. */
const MAX_TEXT = 1200;

/**
 * wppilot/site-health-tests: run WordPress's direct Site Health tests, as its weekly cron check
 * does, and list the asynchronous ones.
 *
 * Direct tests run in this request exactly as WP_Site_Health::wp_cron_scheduled_check() runs
 * them: the class's own get_test_<id>() method for a string test, the callback otherwise, and
 * the result through `site_status_test_result`. Asynchronous tests (loopback, HTTPS, page cache,
 * dotorg communication, background updates) make HTTP requests back to this site or out to
 * WordPress.org and are listed as not run; the Site Health screen runs them.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function run_tests(array $input): array|WP_Error
{
    // Outside wp-admin none of this is loaded. The tests call get_core_updates(),
    // get_plugin_updates() and friends, which is why WordPress's own cron check loads all of
    // wp-admin/includes/admin.php first.
    if (!function_exists('get_core_updates') && is_file(ABSPATH . 'wp-admin/includes/admin.php')) {
        require_once ABSPATH . 'wp-admin/includes/admin.php';
    }
    if (!class_exists('WP_Site_Health') && is_file(ABSPATH . 'wp-admin/includes/class-wp-site-health.php')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
    }
    if (!class_exists('WP_Site_Health')) {
        return new WP_Error('kit_site_health_unavailable', 'WordPress\'s Site Health class is not available on this site.');
    }

    $only = array_values(array_filter(is_array($input['tests'] ?? null) ? $input['tests'] : [], 'is_string'));
    $health = \WP_Site_Health::get_instance();
    $tests = \WP_Site_Health::get_tests();

    $results = [];
    $counts = ['good' => 0, 'recommended' => 0, 'critical' => 0, 'error' => 0];
    foreach (is_array($tests['direct'] ?? null) ? $tests['direct'] : [] as $id => $test) {
        $id = (string) $id;
        if ($only !== [] && !in_array($id, $only, true)) {
            continue;
        }
        $callback = callback($health, is_array($test) ? ($test['test'] ?? null) : null);
        if ($callback === null) {
            $results[] = ['id' => $id, 'label' => label($test, $id), 'status' => 'error', 'description' => 'The test is not callable.'];
            $counts['error']++;
            continue;
        }
        try {
            /** @var mixed $result */
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's Site Health filter, applied exactly as core applies it to a test result.
            $result = apply_filters('site_status_test_result', call_user_func($callback));
        } catch (\Throwable $thrown) {
            $results[] = ['id' => $id, 'label' => label($test, $id), 'status' => 'error', 'description' => 'The test failed: ' . $thrown->getMessage()];
            $counts['error']++;
            continue;
        }
        $row = shape($id, $test, $result);
        $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        $results[] = $row;
    }

    $async = [];
    foreach (is_array($tests['async'] ?? null) ? $tests['async'] : [] as $id => $test) {
        $id = (string) $id;
        if ($only !== [] && !in_array($id, $only, true)) {
            continue;
        }
        $async[] = [
            'id' => $id,
            'label' => label($test, $id),
            'status' => 'not_run',
            'reason' => 'Asynchronous: it makes an HTTP request (to this site or to WordPress.org), so the Site Health screen runs it rather than this call.',
        ];
    }

    return [
        'results' => $results,
        'async_not_run' => $async,
        'counts' => $counts,
        'ran' => count($results),
    ];
}

/**
 * @return callable|null
 */
function callback(object $health, mixed $test): mixed
{
    if (is_string($test)) {
        $method = 'get_test_' . $test;
        if (method_exists($health, $method) && is_callable([$health, $method])) {
            return [$health, $method];
        }
    }
    return is_callable($test) ? $test : null;
}

function label(mixed $test, string $id): string
{
    return is_array($test) && is_string($test['label'] ?? null) ? $test['label'] : $id;
}

/**
 * A test result as plain text: descriptions and actions are HTML written for the admin screen.
 *
 * @return array<string, mixed>
 */
function shape(string $id, mixed $test, mixed $result): array
{
    $result = is_array($result) ? $result : [];
    $status = in_array($result['status'] ?? null, ['good', 'recommended', 'critical'], true) ? (string) $result['status'] : 'error';
    return [
        'id' => $id,
        'label' => is_string($result['label'] ?? null) ? text($result['label']) : label($test, $id),
        'status' => $status,
        'badge' => is_array($result['badge'] ?? null) ? text((string) ($result['badge']['label'] ?? '')) : '',
        'description' => text((string) ($result['description'] ?? '')),
        'actions' => text((string) ($result['actions'] ?? '')),
    ];
}

function text(string $html): string
{
    $plain = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return strlen($plain) > MAX_TEXT ? substr($plain, 0, MAX_TEXT) . '…' : $plain;
}
