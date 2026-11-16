<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\LaunchCheck;

use WP_Error;
use WPPilot\Kits\ContentAudit\Auditor;
use WPPilot\Kits\ContentAudit\WpSource;
use WPPilot\Kits\Runtime\Page;

if (!defined('ABSPATH')) {
    exit();
}

/** Posts sampled for broken links by default, and the most a caller may ask for. */
const LINK_SAMPLE_DEFAULT = 10;
const LINK_SAMPLE_MAX = 25;

/** HTTP checks for internal links that are not post permalinks, per run. */
const LINK_HTTP_BUDGET = 10;

const FORMS_MAX = 20;

/** Analytics plugins recognised by an active constant or class, keyed by display name. */
const ANALYTICS_PLUGINS = [
    'Site Kit by Google' => 'GOOGLESITEKIT_VERSION',
    'MonsterInsights' => 'MONSTERINSIGHTS_VERSION',
    'ExactMetrics' => 'EXACTMETRICS_VERSION',
    'GA Google Analytics' => 'GAP_VERSION',
    'Independent Analytics' => 'IAWP_VERSION',
    'Koko Analytics' => 'KOKO_ANALYTICS_VERSION',
    'Burst Statistics' => 'BURST_VERSION',
    'WP Statistics' => 'WP_STATISTICS_VERSION',
    'Matomo for WordPress' => 'MATOMO_ANALYTICS_FILE',
    'Plausible Analytics' => 'PLAUSIBLE_ANALYTICS_VERSION',
];

/**
 * Run every check. One loopback fetch of the home page feeds the noindex, favicon and analytics
 * checks; the broken-link sample costs at most LINK_HTTP_BUDGET more requests.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function checklist(array $input): array
{
    $home_url = (string) home_url('/');
    $home = Page::fetch($home_url, 15);
    $doc = null;
    $html = '';
    $home_error = '';
    if ($home instanceof WP_Error) {
        $home_error = $home->get_error_message();
    } else {
        $html = $home['html'];
        $doc = Page::parse($html);
    }
    $home_fetched = !$home instanceof WP_Error;

    $sample = max(0, min(LINK_SAMPLE_MAX, (int) ($input['link_sample'] ?? LINK_SAMPLE_DEFAULT)));

    $checks = [
        check_search_visibility([
            'blog_public' => (string) get_option('blog_public', '1') !== '0',
            'home_fetched' => $home_fetched,
            'home_status' => $home_fetched ? (int) $home['status'] : 0,
            'home_noindex' => meta_noindex($doc),
            'home_error' => $home_error,
        ]),
        check_ssl(['home_url' => $home_url, 'site_url' => (string) site_url('/')]),
        check_debug_display(debug_facts()),
        check_favicon([
            'site_icon' => function_exists('has_site_icon') && has_site_icon(),
            'icon_link' => has_icon_link($doc),
            'favicon_ico' => favicon_ico_file(),
        ]),
        check_analytics([
            'home_fetched' => $home_fetched,
            'in_html' => analytics_in_html($html),
            'plugins' => analytics_plugins(),
        ]),
        check_privacy_page(privacy_facts()),
        check_broken_links(link_facts($sample)),
    ];
    [$forms, $truncated] = list_forms();
    $checks[] = check_forms($forms, $truncated);

    return [
        'summary' => summarise($checks),
        'checks' => $checks,
        'home' => ['url' => $home_url, 'status' => $home_fetched ? (int) $home['status'] : null, 'error' => $home_error === '' ? null : $home_error],
    ];
}

/**
 * @return array{wp_debug: bool, wp_debug_display: ?bool, wp_debug_log: bool|string, ini_display_errors: bool}
 */
function debug_facts(): array
{
    $display = defined('WP_DEBUG_DISPLAY') ? constant('WP_DEBUG_DISPLAY') : true;
    $log = defined('WP_DEBUG_LOG') ? constant('WP_DEBUG_LOG') : false;
    // get_cfg_var() is php.ini's value. ini_get() would read this REST request's own setting,
    // which WordPress forces off for every REST call.
    $ini = get_cfg_var('display_errors');
    return [
        'wp_debug' => defined('WP_DEBUG') && (bool) constant('WP_DEBUG'),
        'wp_debug_display' => $display === null ? null : (bool) $display,
        // A string log path is the owner's choice of location; only `true` means wp-content/debug.log.
        'wp_debug_log' => is_string($log) ? 'custom path' : (bool) $log,
        'ini_display_errors' => is_string($ini) && in_array(strtolower(trim($ini)), ['1', 'on', 'true', 'yes', 'stdout', 'stderr'], true),
    ];
}

/**
 * A favicon.ico file in the WordPress root. Not fetched over HTTP: without a file WordPress
 * answers /favicon.ico itself with a redirect to its own W logo, so a 200 there proves nothing.
 */
function favicon_ico_file(): bool
{
    return is_file(ABSPATH . 'favicon.ico') && (int) filesize(ABSPATH . 'favicon.ico') > 0;
}

/**
 * @return list<string>
 */
function analytics_plugins(): array
{
    $found = [];
    foreach (ANALYTICS_PLUGINS as $name => $constant) {
        if (defined($constant)) {
            $found[] = $name;
        }
    }
    return $found;
}

/**
 * @return array{page_id: int, status: ?string, title: string, url: string}
 */
function privacy_facts(): array
{
    $id = (int) get_option('wp_page_for_privacy_policy', 0);
    $post = $id > 0 ? get_post($id) : null;
    if (!$post instanceof \WP_Post) {
        return ['page_id' => $id, 'status' => null, 'title' => '', 'url' => ''];
    }
    return [
        'page_id' => $id,
        'status' => (string) $post->post_status,
        'title' => (string) get_the_title($post),
        'url' => (string) get_permalink($post),
    ];
}

/**
 * The content audit's own link resolution over the most recently modified posts and pages.
 *
 * @return array{available: bool, sampled: int, broken: list<array<string, mixed>>, unchecked: int}
 */
function link_facts(int $sample): array
{
    if (!class_exists(Auditor::class) || !class_exists(WpSource::class)) {
        return ['available' => false, 'sampled' => 0, 'broken' => [], 'unchecked' => 0];
    }
    if ($sample === 0) {
        return ['available' => true, 'sampled' => 0, 'broken' => [], 'unchecked' => 0];
    }
    $ids = get_posts([
        'post_type' => ['post', 'page'],
        'post_status' => 'publish',
        'orderby' => 'modified',
        'order' => 'DESC',
        'numberposts' => $sample,
        'fields' => 'ids',
        'no_found_rows' => true,
        'suppress_filters' => true,
    ]);
    $ids = array_map('intval', is_array($ids) ? $ids : []);
    $auditor = new Auditor(new WpSource(), Auditor::options([
        'checks' => ['broken_links'],
        'internal_http_limit' => LINK_HTTP_BUDGET,
    ]));
    $state = $auditor->finish($auditor->scan($ids, $auditor->start(count($ids))), false);
    return broken_from_findings((array) $state['findings'], (int) $state['scanned'], (int) $state['unchecked_internal']);
}

/**
 * Keep the link findings and only the fields a person needs to find the link.
 *
 * @param array<int, array<string, mixed>> $findings
 * @return array{available: bool, sampled: int, broken: list<array<string, mixed>>, unchecked: int}
 */
function broken_from_findings(array $findings, int $scanned, int $unchecked): array
{
    $types = ['broken_internal_link', 'internal_link_to_unpublished', 'broken_file_link', 'internal_link_error'];
    $broken = [];
    foreach ($findings as $finding) {
        if (!in_array($finding['type'] ?? '', $types, true)) {
            continue;
        }
        $broken[] = [
            'type' => (string) $finding['type'],
            'post_id' => (int) ($finding['post']['id'] ?? 0),
            'post_title' => (string) ($finding['post']['title'] ?? ''),
            'link' => (string) ($finding['evidence']['href'] ?? ''),
        ];
    }
    return ['available' => true, 'sampled' => $scanned, 'broken' => $broken, 'unchecked' => $unchecked];
}

/**
 * Forms from the five common form plugins, titles and status only. Nothing is submitted.
 *
 * @return array{0: list<array{plugin: string, id: int, title: string, status: string}>, 1: bool}
 */
function list_forms(): array
{
    $forms = [];
    $post_types = [
        'wpcf7_contact_form' => 'Contact Form 7',
        'wpforms' => 'WPForms',
        'forminator_forms' => 'Forminator',
    ];
    $registered = array_values(array_filter(array_keys($post_types), 'post_type_exists'));
    if ($registered !== []) {
        $posts = get_posts([
            'post_type' => $registered,
            'post_status' => ['publish', 'draft', 'private'],
            'numberposts' => FORMS_MAX + 1,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);
        foreach (is_array($posts) ? $posts : [] as $post) {
            if ($post instanceof \WP_Post) {
                $forms[] = ['plugin' => $post_types[$post->post_type], 'id' => (int) $post->ID, 'title' => (string) $post->post_title, 'status' => (string) $post->post_status];
            }
        }
    }
    if (class_exists('GFAPI') && method_exists('GFAPI', 'get_forms')) {
        $gf = \GFAPI::get_forms(true, false);
        foreach (is_array($gf) ? $gf : [] as $form) {
            $forms[] = ['plugin' => 'Gravity Forms', 'id' => (int) ($form['id'] ?? 0), 'title' => (string) ($form['title'] ?? ''), 'status' => 'active'];
        }
    }
    if (defined('FLUENTFORM_VERSION')) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, status FROM {$wpdb->prefix}fluentform_forms ORDER BY updated_at DESC LIMIT %d",
            FORMS_MAX + 1,
        ), ARRAY_A);
        foreach (is_array($rows) ? $rows : [] as $form) {
            $forms[] = ['plugin' => 'Fluent Forms', 'id' => (int) $form['id'], 'title' => (string) $form['title'], 'status' => (string) $form['status']];
        }
    }
    return [array_slice($forms, 0, FORMS_MAX), count($forms) > FORMS_MAX];
}
