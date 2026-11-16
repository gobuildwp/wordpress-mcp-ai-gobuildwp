<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\OAuth\DiscoveryCache;

/**
 * Purge page-cache copies of the OAuth discovery documents.
 *
 * A page cache that stored `/.well-known/oauth-protected-resource` (or the authorization-server and
 * OpenID documents) before GoBuildWP served them, or while the site lived at another URL, keeps
 * handing clients a 404 or a stale issuer, and OAuth sign-in fails with nothing pointing at the
 * cache. This module asks each supported cache plugin to drop exactly those URLs: after the
 * settings that shape them change, on activation, and on demand from the Diagnostics screen.
 *
 * Rules, all deliberate:
 *
 * - A purge API is only called after `function_exists` / `has_action` / `class_exists` shows it is
 *   there. A plugin that is detected but offers no per-URL purge is reported as unsupported with
 *   the manual step, never purged some other way.
 * - Purge by URL only, never purge-all. On LiteSpeed in particular, `litespeed_purge_all` resets
 *   OPcache, and on a small site that reset can take every long request down with it.
 * - Loaded outside the OAuth boot gates on purpose: a site that just switched AI Abilities off, or
 *   moved off HTTPS, still has the stale documents in its cache.
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/endpoints/discovery.php';

const PENDING_OPTION = 'wppilot_discovery_cache_purge_pending';

const LAST_RESULT_OPTION = 'wppilot_discovery_cache_last_purge';

/**
 * Every discovery URL a client may request, absolute and de-duplicated.
 *
 * @return list<string>
 */
function discovery_urls(): array
{
    $resource = function_exists('WPPilot\\OAuth\\resource_identifier')
        ? \WPPilot\OAuth\resource_identifier()
        : rest_url('mcp/wppilot-oauth');
    $urls = [];
    foreach (\WPPilot\OAuth\Endpoints\Discovery\discovery_probes(home_url(), $resource) as $probe) {
        $urls[] = $probe['url'];
    }

    return array_values(array_unique($urls));
}

/**
 * The cache plugins GoBuildWP knows how to talk to.
 *
 * `detected` says the plugin is active. `purge` is null when the plugin has no per-URL purge this
 * module can reach, in which case `manual` says what the owner does instead.
 *
 * @return list<array{id: string, label: string, detected: bool, purge: (callable(list<string>): void)|null, manual: string}>
 */
function providers(): array
{
    $per_url = static function (callable $one): callable {
        return static function (array $urls) use ($one): void {
            foreach ($urls as $url) {
                if (is_string($url) && $url !== '') {
                    $one($url);
                }
            }
        };
    };

    $providers = [
        [
            'id' => 'litespeed',
            'label' => 'LiteSpeed Cache',
            'detected' => defined('LSCWP_V') || has_action('litespeed_purge_url') !== false,
            // By URL only. Never `litespeed_purge_all`: it resets OPcache.
            'purge' => has_action('litespeed_purge_url') !== false
                ? $per_url(static function (string $url): void {
                    do_action('litespeed_purge_url', $url);
                })
                : null,
            'manual' => __('In LiteSpeed Cache > Toolbox > Purge, purge each URL listed here.', domain: 'wppilot'),
        ],
        [
            'id' => 'wp-rocket',
            'label' => 'WP Rocket',
            'detected' => defined('WP_ROCKET_VERSION'),
            'purge' => function_exists('rocket_clean_files')
                ? static function (array $urls): void {
                    \rocket_clean_files($urls);
                }
                : null,
            'manual' => __('In WP Rocket, add the /.well-known/ paths to "Never Cache URL(s)" and clear the cache.', domain: 'wppilot'),
        ],
        [
            'id' => 'w3tc',
            'label' => 'W3 Total Cache',
            'detected' => defined('W3TC'),
            'purge' => function_exists('w3tc_flush_url')
                ? $per_url(static function (string $url): void {
                    \w3tc_flush_url($url);
                })
                : null,
            'manual' => __('In W3 Total Cache > Page Cache, add the /.well-known/ paths to "Never cache the following pages" and empty the page cache.', domain: 'wppilot'),
        ],
        [
            'id' => 'wp-super-cache',
            'label' => 'WP Super Cache',
            'detected' => defined('WPCACHEHOME'),
            'purge' => function_exists('wpsc_delete_url_cache')
                ? $per_url(static function (string $url): void {
                    \wpsc_delete_url_cache($url);
                })
                : null,
            'manual' => __('In WP Super Cache > Advanced, add the /.well-known/ paths to the rejected URL strings and delete the cache.', domain: 'wppilot'),
        ],
        [
            'id' => 'wp-fastest-cache',
            'label' => 'WP Fastest Cache',
            'detected' => class_exists('WpFastestCache'),
            'purge' => function_exists('wpfc_clear_cache_of_url')
                ? $per_url(static function (string $url): void {
                    \wpfc_clear_cache_of_url($url);
                })
                : null,
            'manual' => __('In WP Fastest Cache > Exclude, exclude the /.well-known/ paths, then delete the cache.', domain: 'wppilot'),
        ],
        [
            'id' => 'sg-optimizer',
            'label' => 'SiteGround Speed Optimizer',
            'detected' => function_exists('sg_cachepress_purge_cache') || defined('SiteGround_Optimizer\\VERSION'),
            // Always with a URL: called with none it flushes the whole site.
            'purge' => function_exists('sg_cachepress_purge_cache')
                ? $per_url(static function (string $url): void {
                    \sg_cachepress_purge_cache($url);
                })
                : null,
            'manual' => __('In SiteGround Speed Optimizer > Caching, exclude the /.well-known/ paths and purge the cache.', domain: 'wppilot'),
        ],
        [
            'id' => 'breeze',
            'label' => 'Breeze',
            'detected' => defined('BREEZE_VERSION'),
            // Breeze only offers a site-wide purge, which this module never runs.
            'purge' => null,
            'manual' => __('In Breeze > Advanced Options, add the /.well-known/ paths to "Never Cache URL(s)" and purge the cache.', domain: 'wppilot'),
        ],
        [
            'id' => 'cache-enabler',
            'label' => 'Cache Enabler',
            'detected' => class_exists('Cache_Enabler'),
            'purge' => has_action('cache_enabler_clear_page_cache_by_url') !== false
                ? $per_url(static function (string $url): void {
                    do_action('cache_enabler_clear_page_cache_by_url', $url);
                })
                : null,
            'manual' => __('In Cache Enabler, clear the site cache.', domain: 'wppilot'),
        ],
        [
            'id' => 'hummingbird',
            'label' => 'Hummingbird',
            'detected' => defined('WPHB_VERSION'),
            // Hummingbird purges by post ID or site-wide; the discovery documents are neither a post.
            'purge' => null,
            'manual' => __('In Hummingbird > Caching > Page Caching, add the /.well-known/ paths to the URL exclusions and clear the cache.', domain: 'wppilot'),
        ],
        [
            'id' => 'cloudflare',
            'label' => 'Cloudflare',
            'detected' => defined('CLOUDFLARE_PLUGIN_DIR'),
            // The Cloudflare plugin exposes no stable per-URL purge to other plugins.
            'purge' => null,
            'manual' => __('In the Cloudflare dashboard, use Caching > Configuration > Custom Purge and purge each URL listed here.', domain: 'wppilot'),
        ],
    ];

    /**
     * Filter the cache plugins the discovery purge talks to.
     *
     * Each entry: id, label, detected (bool), purge (callable taking a list of URLs, or null),
     * manual (string). A purge callable must purge those URLs only, never the whole cache.
     *
     * @param list<array<string, mixed>> $providers
     */
    $filtered = apply_filters('wppilot_discovery_cache_purgers', $providers);
    if (!is_array($filtered)) {
        return $providers;
    }

    $clean = [];
    foreach ($filtered as $provider) {
        if (!is_array($provider) || !isset($provider['id'], $provider['label']) || !is_string($provider['id']) || !is_string($provider['label'])) {
            continue;
        }
        $clean[] = [
            'id' => $provider['id'],
            'label' => $provider['label'],
            'detected' => ($provider['detected'] ?? false) === true,
            'purge' => isset($provider['purge']) && is_callable($provider['purge']) ? $provider['purge'] : null,
            'manual' => isset($provider['manual']) && is_string($provider['manual']) ? $provider['manual'] : '',
        ];
    }

    return $clean;
}

/**
 * Purge the discovery URLs from every detected cache that supports it.
 *
 * @return array{at: int, reason: string, urls: list<string>, purged: list<string>, unsupported: list<array{label: string, manual: string}>, errors: list<array{label: string, error: string}>, detected: int}
 */
function purge(string $reason): array
{
    $urls = discovery_urls();
    $result = [
        'at' => time(),
        'reason' => $reason,
        'urls' => $urls,
        'purged' => [],
        'unsupported' => [],
        'errors' => [],
        'detected' => 0,
    ];

    foreach (providers() as $provider) {
        if (!$provider['detected']) {
            continue;
        }
        $result['detected']++;
        if ($provider['purge'] === null) {
            $result['unsupported'][] = ['label' => $provider['label'], 'manual' => $provider['manual']];
            continue;
        }
        try {
            ($provider['purge'])($urls);
            $result['purged'][] = $provider['label'];
        } catch (\Throwable $e) {
            $result['errors'][] = ['label' => $provider['label'], 'error' => $e->getMessage()];
        }
    }

    update_option(LAST_RESULT_OPTION, $result, autoload: false);

    return $result;
}

/**
 * The last purge's result, or null when none has run.
 *
 * @return array{at: int, reason: string, urls: list<string>, purged: list<string>, unsupported: list<array{label: string, manual: string}>, errors: list<array{label: string, error: string}>, detected: int}|null
 */
function last_result(): ?array
{
    /** @var mixed $stored */
    $stored = get_option(LAST_RESULT_OPTION, default_value: null);
    if (!is_array($stored) || !isset($stored['at'], $stored['urls'], $stored['purged'], $stored['unsupported'])) {
        return null;
    }

    /** @var array{at: int, reason: string, urls: list<string>, purged: list<string>, unsupported: list<array{label: string, manual: string}>, errors: list<array{label: string, error: string}>, detected: int} $stored */
    return $stored;
}

/**
 * Ask for a purge once this request has finished loading. Settings hooks fire mid-request, often
 * before a cache plugin has registered its purge hooks, so the purge itself waits for `wp_loaded`
 * (this request or the next).
 */
function mark_pending(): void
{
    update_option(PENDING_OPTION, '1', autoload: true);
}

function run_pending(): void
{
    if (get_option(PENDING_OPTION, default_value: '0') !== '1') {
        return;
    }
    // Cleared first, so a purge that fatals is not retried on every request.
    delete_option(PENDING_OPTION);
    purge('settings');
}

function register(): void
{
    // The settings the discovery documents are built from, and whether they are served at all.
    foreach (['home', 'siteurl', 'permalink_structure', 'wppilot_ai_abilities_enabled', 'wppilot_ai_abilities_domain'] as $option) {
        add_action('update_option_' . $option, callback: __NAMESPACE__ . '\\mark_pending', priority: 10, accepted_args: 0);
        add_action('add_option_' . $option, callback: __NAMESPACE__ . '\\mark_pending', priority: 10, accepted_args: 0);
    }
    add_action('wp_loaded', callback: __NAMESPACE__ . '\\run_pending', priority: 99);
}
