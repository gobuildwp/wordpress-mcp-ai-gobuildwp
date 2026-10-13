<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Self-hosted plugin update checker.
 *
 * Checks https://gobuildwp.com/api/wppilot/info for new versions
 * and injects update data into the WordPress update system.
 */

if (!defined('ABSPATH')) {
    exit();
}

add_filter('site_transient_update_plugins', callback: 'wppilot_check_for_updates');
add_filter('plugins_api', callback: 'wppilot_plugins_api', priority: 10, accepted_args: 3);

/**
 * Inject update data into the plugins update transient.
 *
 * @param mixed $transient The update_plugins transient value.
 * @return mixed
 */
function wppilot_check_for_updates($transient)
{
    if (!is_object($transient)) {
        return $transient;
    }

    /** @var object{response: array<string, object>, no_update: array<string, object>, checked?: array<string, string>} $transient */

    $remote = wppilot_fetch_update_info();
    $plugin_file = plugin_basename(dirname(__DIR__) . '/wppilot.php');

    if ($remote === null || !version_compare(WPPILOT_VERSION, $remote['version'], operator: '<')) {
        // No remote info or up-to-date. Report as no_update so WordPress.org cannot override.
        $transient->no_update[$plugin_file] = (object) [
            'slug' => 'wppilot',
            'plugin' => $plugin_file,
            'new_version' => WPPILOT_VERSION,
            'url' => '',
            'package' => '',
        ];

        return $transient;
    }

    $update_data = [
        'slug' => 'wppilot',
        'plugin' => $plugin_file,
        'new_version' => $remote['version'],
        'url' => $remote['homepage'],
        'package' => $remote['download_url'],
        'tested' => $remote['tested'],
        'requires_php' => $remote['requires_php'],
        'requires' => $remote['requires'],
        'icons' => $remote['icons'],
        'banners' => $remote['banners'],
    ];
    $transient->response[$plugin_file] = (object) $update_data;

    return $transient;
}

/**
 * Supply plugin info for the "View Details" popup.
 *
 * @param false|object|array $result
 * @param string             $action
 * @param object             $args
 * @return false|object|array
 */
function wppilot_plugins_api($result, $action, $args)
{
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'wppilot') {
        return $result;
    }

    $remote = wppilot_fetch_update_info();
    if ($remote === null) {
        return $result;
    }

    return (object) [
        'name' => $remote['name'],
        'slug' => 'wppilot',
        'version' => $remote['version'],
        'author' => $remote['author'],
        'author_profile' => $remote['author_homepage'],
        'homepage' => $remote['homepage'],
        'requires' => $remote['requires'],
        'requires_php' => $remote['requires_php'],
        'tested' => $remote['tested'],
        'last_updated' => $remote['last_updated'],
        'sections' => $remote['sections'],
        'icons' => $remote['icons'],
        'banners' => $remote['banners'],
        'download_link' => $remote['download_url'],
    ];
}

/**
 * Whether a person with update rights asked WordPress to check for updates now.
 */
function wppilot_update_check_forced(): bool
{
    return is_admin()
        && isset($_GET['force-check']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag, the same one core's update screen reads.
        && current_user_can('update_plugins');
}

/**
 * Fetch public plugin release metadata with transient caching.
 *
 * @return array{name: string, version: string, author: string, author_homepage: string, homepage: string, requires: string, requires_php: string, tested: string, last_updated: string, sections: array<string, string>, icons: array<string, string>, banners: array<string, string>, download_url: string}|null
 */
function wppilot_fetch_update_info()
{
    $cache_key = 'wppilot_update_info';
    /** @var array{name: string, version: string, author: string, author_homepage: string, homepage: string, requires: string, requires_php: string, tested: string, last_updated: string, sections: array<string, string>, icons: array<string, string>, banners: array<string, string>, download_url: string}|string|false $cached */
    $cached = get_transient($cache_key);

    // "Check again" on Dashboard > Updates (update-core.php?force-check=1) asks for fresh
    // data; without this the 12-hour cache below hid a new release until it expired.
    if ($cached !== false && wppilot_update_check_forced()) {
        $cached = false;
    }

    if ($cached === 'error') {
        return null;
    }
    if (is_array($cached)) {
        return $cached;
    }

    $raw = wppilot_request_update_info();
    if ($raw === null) {
        set_transient($cache_key, value: 'error', expiration: HOUR_IN_SECONDS);
        return null;
    }

    $data = wppilot_normalize_update_response($raw);
    set_transient($cache_key, value: $data, expiration: 12 * HOUR_IN_SECONDS);
    return $data;
}

/**
 * Make the HTTP request to the public release-metadata endpoint.
 *
 * @return array<string, mixed>|null Raw decoded response, or null on failure.
 */
function wppilot_request_update_info()
{
    // Version-only lookup: this request transmits no site URL, host, user,
    // licence data or credential. Scope note: that is a statement about the
    // update check, not about the plugin as a whole. Anonymous usage
    // reporting in includes/telemetry/ does send the site URL, on its own
    // schedule, with its own switch — see readme.txt, External services.
    // add_query_arg() is variadic; named arguments become associative keys in
    // its internal argument array and break WordPress core's numeric indexing.
    // @mago-expect lint:literal-named-argument -- Variadic WordPress helpers require positional arguments.
    $url = add_query_arg(['v' => WPPILOT_VERSION], 'https://gobuildwp.com/api/wppilot/info');

    $response = wp_remote_get($url, ['timeout' => 10]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }

    /** @var array<string, mixed>|null $raw */
    $raw = json_decode(wp_remote_retrieve_body($response), associative: true);

    if (!is_array($raw) || !is_string($raw['version'] ?? null) || $raw['version'] === '') {
        return null;
    }

    return $raw;
}

/**
 * Normalize the raw API response into a typed array.
 *
 * @param array<string, mixed> $raw Raw decoded API response.
 * @return array{name: string, version: string, author: string, author_homepage: string, homepage: string, requires: string, requires_php: string, tested: string, last_updated: string, sections: array<string, string>, icons: array<string, string>, banners: array<string, string>, download_url: string}
 */
function wppilot_normalize_update_response($raw)
{
    /** @var array<string, string> $sections */
    $sections = is_array($raw['sections'] ?? null) ? $raw['sections'] : [];
    /** @var array<string, string> $icons */
    $icons = is_array($raw['icons'] ?? null) ? $raw['icons'] : [];
    /** @var array<string, string> $banners */
    $banners = is_array($raw['banners'] ?? null) ? $raw['banners'] : [];

    return [
        'name' => (string) ($raw['name'] ?? 'GoBuildWP'),
        'version' => (string) $raw['version'],
        'author' => (string) ($raw['author'] ?? ''),
        'author_homepage' => (string) ($raw['author_homepage'] ?? ''),
        'homepage' => (string) ($raw['homepage'] ?? ''),
        'requires' => (string) ($raw['requires'] ?? ''),
        'requires_php' => (string) ($raw['requires_php'] ?? ''),
        'tested' => (string) ($raw['tested'] ?? ''),
        'last_updated' => (string) ($raw['last_updated'] ?? ''),
        'sections' => $sections,
        'icons' => $icons,
        'banners' => $banners,
        'download_url' => (string) ($raw['download_url'] ?? ''),
    ];
}
