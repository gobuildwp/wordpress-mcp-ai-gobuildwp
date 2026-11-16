<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\LaunchCheck;

use DOMDocument;
use DOMElement;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The pure half of the launch checklist: every function here turns facts already gathered into
 * a check row, so the verdicts can be tested without WordPress.
 *
 * A row is {id, label, status, detail, data?}. `fail` is a launch blocker (the site is hidden
 * from search, errors are shown to visitors, no HTTPS), `warn` should be fixed or confirmed,
 * `pass` is fine, and `info` means the check could not decide or only lists things for a person
 * to try.
 */
const STATUS_ORDER = ['fail' => 0, 'warn' => 1, 'info' => 2, 'pass' => 3];

/** Inline analytics and tag-manager signatures, matched against the home page HTML. */
const ANALYTICS_SIGNATURES = [
    'Google Analytics' => ['googletagmanager.com/gtag/js', 'google-analytics.com/analytics.js', "gtag('config'", 'gtag("config"'],
    'Google Tag Manager' => ['googletagmanager.com/gtm.js', 'googletagmanager.com/ns.html'],
    'Plausible' => ['plausible.io/js/'],
    'Matomo' => ['matomo.js', 'piwik.js', '_paq.push'],
    'Fathom' => ['cdn.usefathom.com'],
    'Microsoft Clarity' => ['clarity.ms/tag'],
    'Hotjar' => ['static.hotjar.com'],
    'Umami' => ['data-website-id', 'umami.is/script.js'],
    'Cloudflare Web Analytics' => ['static.cloudflareinsights.com/beacon'],
    'Meta Pixel' => ['connect.facebook.net/en_US/fbevents.js', "fbq('init'", 'fbq("init"'],
    'Jetpack Stats' => ['stats.wp.com/e-'],
];

/** Form titles that read as a leftover from building the site. */
const TEST_FORM_PATTERN = '/\b(test|testing|demo|sample|example|untitled|copy|dummy|lorem)\b/i';

/**
 * @param array<string, mixed> $data
 * @return array{id: string, label: string, status: string, detail: string, data?: array<string, mixed>}
 */
function row(string $id, string $label, string $status, string $detail, array $data = []): array
{
    $row = ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail];
    if ($data !== []) {
        $row['data'] = $data;
    }
    return $row;
}

/**
 * True when the page's own `<meta name="robots">` (or googlebot) carries noindex or none.
 */
function meta_noindex(?DOMDocument $doc): bool
{
    if ($doc === null) {
        return false;
    }
    foreach ($doc->getElementsByTagName('meta') as $meta) {
        if (!$meta instanceof DOMElement) {
            continue;
        }
        $name = strtolower(trim($meta->getAttribute('name')));
        if ($name !== 'robots' && $name !== 'googlebot' && $name !== 'bingbot') {
            continue;
        }
        $content = strtolower($meta->getAttribute('content'));
        if (preg_match('/(^|[\s,])(noindex|none)([\s,]|$)/', $content) === 1) {
            return true;
        }
    }
    return false;
}

function has_icon_link(?DOMDocument $doc): bool
{
    if ($doc === null) {
        return false;
    }
    foreach ($doc->getElementsByTagName('link') as $link) {
        if (!$link instanceof DOMElement) {
            continue;
        }
        $rel = ' ' . strtolower(trim($link->getAttribute('rel'))) . ' ';
        if ((str_contains($rel, ' icon ') || str_contains($rel, ' apple-touch-icon ')) && trim($link->getAttribute('href')) !== '') {
            return true;
        }
    }
    return false;
}

/**
 * @return list<string> The analytics products whose snippet is in the HTML.
 */
function analytics_in_html(string $html): array
{
    $found = [];
    foreach (ANALYTICS_SIGNATURES as $product => $needles) {
        foreach ($needles as $needle) {
            if (stripos($html, $needle) !== false) {
                $found[] = $product;
                break;
            }
        }
    }
    return $found;
}

/**
 * @param array{blog_public: bool, home_fetched: bool, home_status: int, home_noindex: bool, home_error: string} $facts
 */
function check_search_visibility(array $facts): array
{
    $label = 'Search engines can index the site';
    if (!$facts['blog_public']) {
        return row('search_visibility', $label, 'fail', 'Settings > Reading has "Discourage search engines from indexing this site" ticked, so WordPress adds noindex to every page. Untick it at launch.', ['blog_public' => false]);
    }
    if ($facts['home_noindex']) {
        return row('search_visibility', $label, 'fail', 'The home page carries a robots noindex meta tag although Settings > Reading allows indexing. An SEO plugin or the theme adds it; check the home page\'s SEO settings.', ['home_noindex' => true]);
    }
    if (!$facts['home_fetched']) {
        return row('search_visibility', $label, 'warn', 'Settings > Reading allows indexing, but the home page could not be fetched to check for a noindex tag: ' . $facts['home_error'], ['blog_public' => true]);
    }
    if ($facts['home_status'] >= 400) {
        return row('search_visibility', $label, 'fail', sprintf('The home page answers HTTP %d. A coming-soon or maintenance mode, or a broken front page, hides the site from visitors and search engines.', $facts['home_status']), ['home_status' => $facts['home_status']]);
    }
    return row('search_visibility', $label, 'pass', 'Settings > Reading allows indexing and the home page has no noindex tag.');
}

/**
 * @param array{home_url: string, site_url: string} $facts
 */
function check_ssl(array $facts): array
{
    $label = 'HTTPS';
    $insecure = [];
    foreach (['home_url' => 'Site Address', 'site_url' => 'WordPress Address'] as $key => $name) {
        if (strtolower((string) parse_url($facts[$key], PHP_URL_SCHEME)) !== 'https') {
            $insecure[] = $name;
        }
    }
    if ($insecure !== []) {
        return row('ssl', $label, 'fail', implode(' and ', $insecure) . ' in Settings > General ' . (count($insecure) > 1 ? 'do' : 'does') . ' not start with https://. Install a certificate and switch both addresses; browsers mark plain-HTTP sites "Not secure".', ['home_url' => $facts['home_url'], 'site_url' => $facts['site_url']]);
    }
    return row('ssl', $label, 'pass', 'Both site addresses use https://.');
}

/**
 * How WordPress will treat errors on a normal front-end request. The MCP request itself is a
 * REST request, which WordPress always runs with display_errors off, so the constants and the
 * php.ini value are judged rather than the current runtime setting.
 *
 * @param array{wp_debug: bool, wp_debug_display: ?bool, wp_debug_log: bool|string, ini_display_errors: bool} $facts
 */
function check_debug_display(array $facts): array
{
    $label = 'Errors are hidden from visitors';
    $data = [
        'WP_DEBUG' => $facts['wp_debug'],
        'WP_DEBUG_DISPLAY' => $facts['wp_debug_display'],
        'WP_DEBUG_LOG' => $facts['wp_debug_log'],
        'php_display_errors' => $facts['ini_display_errors'],
    ];
    if ($facts['wp_debug'] && $facts['wp_debug_display'] !== false && ($facts['wp_debug_display'] === true || $facts['ini_display_errors'])) {
        return row('debug_display', $label, 'fail', 'WP_DEBUG is on and WP_DEBUG_DISPLAY is not false, so PHP notices and errors, with file paths, are printed into pages. Set WP_DEBUG to false in wp-config.php, or WP_DEBUG_DISPLAY to false.', $data);
    }
    if ($facts['wp_debug'] && $facts['wp_debug_log'] === true) {
        return row('debug_display', $label, 'warn', 'Errors are not shown, but WP_DEBUG_LOG writes them to wp-content/debug.log, which most servers serve to anyone who asks. Turn WP_DEBUG off for launch or point WP_DEBUG_LOG at a path outside the web root.', $data);
    }
    if (!$facts['wp_debug'] && $facts['ini_display_errors']) {
        return row('debug_display', $label, 'warn', 'WP_DEBUG is off, but php.ini has display_errors on, so a fatal error would be printed to visitors. Ask the host to turn display_errors off.', $data);
    }
    return row('debug_display', $label, 'pass', $facts['wp_debug'] ? 'WP_DEBUG is on but nothing is displayed or written to the public log.' : 'WP_DEBUG is off.', $data);
}

/**
 * @param array{site_icon: bool, icon_link: bool, favicon_ico: bool} $facts
 */
function check_favicon(array $facts): array
{
    $label = 'Favicon';
    if ($facts['site_icon']) {
        return row('favicon', $label, 'pass', 'A Site Icon is set in Appearance > Customize > Site Identity.');
    }
    if ($facts['icon_link']) {
        return row('favicon', $label, 'pass', 'The home page links an icon (the theme or a plugin supplies it).');
    }
    if ($facts['favicon_ico']) {
        return row('favicon', $label, 'pass', 'A favicon.ico file is in the WordPress root.');
    }
    return row('favicon', $label, 'warn', 'No Site Icon, no icon link on the home page and no favicon.ico file. Browsers and search results show a generic globe. Set one in Appearance > Customize > Site Identity.');
}

/**
 * @param array{home_fetched: bool, in_html: list<string>, plugins: list<string>} $facts
 */
function check_analytics(array $facts): array
{
    $label = 'Analytics';
    $found = array_values(array_unique(array_merge($facts['in_html'], $facts['plugins'])));
    if ($found !== []) {
        return row('analytics', $label, 'pass', 'Found: ' . implode(', ', $found) . '. Confirm a visit shows up in its dashboard.', ['found' => $found]);
    }
    if (!$facts['home_fetched']) {
        return row('analytics', $label, 'info', 'No analytics plugin is active and the home page could not be fetched to look for a tracking snippet.');
    }
    return row('analytics', $label, 'warn', 'No analytics plugin is active and the home page has no known tracking snippet (Google Analytics, Tag Manager, Plausible, Matomo, Fathom, Clarity and others). Skip this only if the site deliberately runs without analytics.');
}

/**
 * @param array{page_id: int, status: ?string, title: string, url: string} $facts
 */
function check_privacy_page(array $facts): array
{
    $label = 'Privacy policy page';
    if ($facts['page_id'] <= 0 || $facts['status'] === null || $facts['status'] === 'trash') {
        return row('privacy_page', $label, 'warn', 'No privacy policy page is set in Settings > Privacy. Most sites that use cookies, analytics or forms need one.');
    }
    if ($facts['status'] !== 'publish') {
        return row('privacy_page', $label, 'warn', sprintf('The privacy policy page "%s" is %s, so visitors get a 404. Publish it.', $facts['title'], $facts['status']), ['page_id' => $facts['page_id'], 'status' => $facts['status']]);
    }
    return row('privacy_page', $label, 'pass', sprintf('"%s" is published.', $facts['title']), ['page_id' => $facts['page_id'], 'url' => $facts['url']]);
}

/**
 * @param array{available: bool, sampled: int, broken: list<array<string, mixed>>, unchecked: int} $facts
 */
function check_broken_links(array $facts): array
{
    $label = 'Internal links (sample)';
    if (!$facts['available']) {
        return row('broken_links', $label, 'info', 'The content audit is not available on this site, so links were not checked.');
    }
    if ($facts['sampled'] === 0) {
        return row('broken_links', $label, 'info', 'No published posts or pages to check.');
    }
    $scope = sprintf('the %d most recently modified posts and pages', $facts['sampled']);
    if ($facts['broken'] !== []) {
        return row('broken_links', $label, 'warn', sprintf('%d broken or unpublished internal link(s) in %s. Run wppilot/audit-content for the whole site.', count($facts['broken']), $scope), ['links' => $facts['broken'], 'unchecked' => $facts['unchecked']]);
    }
    $detail = sprintf('No broken internal links in %s.', $scope);
    if ($facts['unchecked'] > 0) {
        $detail .= sprintf(' %d link(s) were not checked (HTTP budget).', $facts['unchecked']);
    }
    return row('broken_links', $label, 'pass', $detail . ' This is a sample; wppilot/audit-content checks the whole site.', ['unchecked' => $facts['unchecked']]);
}

/**
 * @param list<array{plugin: string, id: int, title: string, status: string}> $forms
 */
function check_forms(array $forms, bool $truncated): array
{
    $label = 'Forms to test';
    if ($forms === []) {
        return row('test_forms', $label, 'info', 'No forms found from Contact Form 7, WPForms, Gravity Forms, Forminator or Fluent Forms.');
    }
    $leftovers = [];
    foreach ($forms as $index => $form) {
        $forms[$index]['looks_like_test'] = preg_match(TEST_FORM_PATTERN, $form['title']) === 1;
        if ($forms[$index]['looks_like_test']) {
            $leftovers[] = $form['title'];
        }
    }
    $detail = sprintf('%d form(s)%s. Submit each one once and confirm the notification email arrives.', count($forms), $truncated ? ' (first 20 listed)' : '');
    if ($leftovers !== []) {
        return row('test_forms', $label, 'warn', $detail . ' These look like leftovers from building the site: ' . implode(', ', array_map(static fn(string $title): string => '"' . $title . '"', $leftovers)) . '.', ['forms' => $forms]);
    }
    return row('test_forms', $label, 'info', $detail, ['forms' => $forms]);
}

/**
 * @param list<array{id: string, status: string}> $checks
 * @return array{ready: bool, fail: int, warn: int, info: int, pass: int}
 */
function summarise(array $checks): array
{
    $counts = ['fail' => 0, 'warn' => 0, 'info' => 0, 'pass' => 0];
    foreach ($checks as $check) {
        $counts[$check['status']] = ($counts[$check['status']] ?? 0) + 1;
    }
    return ['ready' => $counts['fail'] === 0] + $counts;
}
