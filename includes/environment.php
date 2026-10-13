<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * What kind of site is this, and are abilities on?
 *
 * Production detection drives the warnings shown before enabling AI
 * Abilities and the default safety profile. It is deliberately
 * conservative: anything that does not look clearly like staging or
 * local development is treated as production.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Whether this site has asked for debug logging.
 *
 * GoBuildWP writes to the PHP error log only behind this. A plugin that logs
 * unconditionally fills a production log with noise its owner never asked for,
 * and the directory guidelines treat that as a defect.
 *
 * A function rather than a bare `WP_DEBUG` test at each site, because the
 * constant is untyped: every inline `defined('WP_DEBUG') && WP_DEBUG` reads as a
 * mixed operand to static analysis, and one cast in one place is better than the
 * same cast repeated at every call.
 */
function wppilot_debug_logging_enabled(): bool
{
    return defined('WP_DEBUG') && (bool) constant('WP_DEBUG');
}

/**
 * Check whether the AI abilities are enabled via the settings option.
 *
 * @return bool
 */
function wppilot_is_enabled()
{
    /** @var mixed $value */
    $value = get_option('wppilot_ai_abilities_enabled', default_value: false);
    if ($value !== '1' && $value !== true) {
        return false;
    }

    // Abilities are locked to the domain they were enabled on.
    /** @var string $locked_domain */
    $locked_domain = get_option('wppilot_ai_abilities_domain', default_value: '');
    $current_domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);

    return $locked_domain === $current_domain;
}

/**
 * Heuristic: does this site look like a production environment?
 *
 * Default to production when in doubt — the warning's job is to prompt the user to think
 * twice before enabling AI Abilities on something live. Hostnames and `wp_get_environment_type()`
 * results that strongly suggest staging/dev/local short-circuit to `false`.
 *
 * @return bool
 */
function wppilot_looks_like_production(): bool
{
    $host = wppilot_normalized_home_host();
    if ($host === '') {
        return true;
    }

    if (!str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return false;
    }

    return (
        !wppilot_host_has_non_production_tld($host)
        && !wppilot_host_has_non_production_subdomain_segment($host)
        && !wppilot_host_has_non_production_keyword($host)
        && !wppilot_host_has_non_production_suffix($host)
        && !wppilot_wp_environment_is_non_production()
    );
}

function wppilot_normalized_home_host(): string
{
    $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

    // Strip an eventual port suffix.
    $colon_pos = strpos(haystack: $host, needle: ':');
    if ($colon_pos === false) {
        return $host;
    }

    return substr($host, offset: 0, length: $colon_pos);
}

function wppilot_host_has_non_production_tld(string $host): bool
{
    $segments = explode('.', $host);
    $tld = end($segments);

    /** @var array<int, string> $non_prod_tlds */
    $non_prod_tlds = apply_filters('wppilot_non_production_tlds', [
        'dev',
        'local',
        'staging',
        'test',
        'example',
        'invalid',
        'backup',
    ]);

    return in_array($tld, $non_prod_tlds, strict: true);
}

function wppilot_host_has_non_production_subdomain_segment(string $host): bool
{
    /** @var array<int, string> $non_prod_subdomain_segments */
    $non_prod_subdomain_segments = apply_filters('wppilot_non_production_subdomain_segments', [
        'dev',
        'local',
        'test',
        'staging',
        'stage',
        'stg',
        'wp-staging',
        'wpstaging',
        'development',
        'wptest',
        'backup',
        'preview',
        'preprod',
        'qa',
        'uat',
        'sandbox',
        'demo',
        'beta',
        'mirror',
    ]);

    foreach (explode('.', $host) as $segment) {
        if (in_array($segment, $non_prod_subdomain_segments, strict: true)) {
            return true;
        }
    }

    return false;
}

function wppilot_host_has_non_production_keyword(string $host): bool
{
    /** @var array<int, string> $non_prod_keyword_regex_words */
    $non_prod_keyword_regex_words = apply_filters('wppilot_non_production_keyword_words', [
        'test',
        'dev',
        'staging',
        'stage',
        'stg',
        'local',
        'wp-staging',
        'development',
        'wptest',
        'backup',
        'preview',
        'preprod',
        'sandbox',
        'demo',
        'beta',
    ]);

    $alternation = implode('|', array_map(static fn(string $w): string => preg_quote(
        str: $w,
        delimiter: '/',
    ), $non_prod_keyword_regex_words));

    return $alternation !== '' && preg_match('/\b(?:' . $alternation . ')[0-9]*\b/i', $host) === 1;
}

function wppilot_host_has_non_production_suffix(string $host): bool
{
    /** @var array<int, string> $non_prod_host_suffixes */
    $non_prod_host_suffixes = apply_filters('wppilot_production_host_patterns', [
        'wpengine.com',
        'wpenginepowered.com',
        'sg-host.com',
        'cloudwaysapps.com',
        'closte.com',
        'runcloud.link',
        'kinsta.cloud',
        'pantheonsite.io',
        'onrocket.site',
        'pressdns.com',
        'bigscoots-staging.com',
        'flywheelstaging.com',
        'wpstage.net',
        'wpserveur.net',
        'myftpupload.com',
        'myraidbox.de',
        'elementor.cloud',
        'lndo.site',
        'ddev.site',
        'instawp.co',
        'instawp.link',
        'instawp.xyz',
        'tastewp.com',
        'mystagingwebsite.com',
        'wpcomstaging.com',
        'convesio.cloud',
        '10web.io',
        'plesk.page',
    ]);

    foreach ($non_prod_host_suffixes as $suffix) {
        if ($suffix !== '' && str_ends_with($host, $suffix)) {
            return true;
        }
    }

    return false;
}

function wppilot_wp_environment_is_non_production(): bool
{
    if (!function_exists('wp_get_environment_type')) {
        return false;
    }

    return in_array(wp_get_environment_type(), ['staging', 'development', 'local'], strict: true);
}

/**
 * Heuristic: is this site likely served over plain HTTP on a local hostname?
 *
 * WordPress core blocks Application Passwords on HTTP unless `WP_ENVIRONMENT_TYPE` is set to
 * 'local'. Detecting this lets us surface the exact wp-config snippet the user needs.
 */
function wppilot_likely_local_http(): bool
{
    $home = home_url();
    if (!str_starts_with(strtolower($home), 'http://')) {
        return false;
    }

    $host = strtolower((string) wp_parse_url($home, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    /** @var array<int, string> $local_substrings */
    $local_substrings = apply_filters('wppilot_self_signed_host_patterns', [
        '.local',
        '.test',
        'localhost',
        '.lndo.site',
        '.ddev.site',
    ]);

    foreach ($local_substrings as $needle) {
        if ($needle !== '' && str_contains($host, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * Heuristic: is this site likely served over HTTPS with a certificate the mcp-remote bridge will
 * not trust (a self-signed cert, or a local CA like mkcert that Node ignores by default)?
 *
 * LocalWP, DDEV, Lando and similar dev tools commonly serve local hostnames over HTTPS with such
 * certs, which the bridge rejects unless `NODE_TLS_REJECT_UNAUTHORIZED=0` is passed in the env.
 * Any HTTPS host that is only reachable locally is treated this way, mirroring the bridge decision
 * in wppilot_build_oauth_configs(): this covers single-label hosts (e.g. "site") and private-IP
 * literals too, not only the `.local` / `.test`-style suffixes, so no local HTTPS site is left
 * without the bypass it needs.
 */
function wppilot_likely_self_signed_https(): bool
{
    return str_starts_with(strtolower(home_url()), 'https://') && wppilot_host_unreachable_from_cloud();
}

/**
 * Has the current user dismissed the production warning?
 */
function wppilot_production_warning_dismissed(): bool
{
    /** @var mixed $value */
    $value = get_user_meta(get_current_user_id(), key: 'wppilot_production_warning_dismissed', single: true);
    return $value === '1' || $value === 1 || $value === true;
}

/**
 * Handle the dismiss-production-warning form submission. Called from admin_init.
 */
function wppilot_handle_dismiss_production_warning(): void
{
    if (($_POST['wppilot_dismiss_production_warning'] ?? null) === null) {
        return;
    }

    if (!wppilot_current_user_can_manage()) {
        return;
    }

    check_admin_referer('wppilot_dismiss_production_warning');

    update_user_meta(get_current_user_id(), meta_key: 'wppilot_production_warning_dismissed', meta_value: '1');

    wp_safe_redirect(admin_url('admin.php?page=wppilot-connect'));
    exit();
}

/**
 * Check whether abilities are nominally enabled but inactive due to a domain mismatch.
 *
 * @return bool
 */
function wppilot_is_domain_mismatch()
{
    /** @var mixed $value */
    $value = get_option('wppilot_ai_abilities_enabled', default_value: false);
    if ($value !== '1' && $value !== true) {
        return false;
    }

    /** @var string $locked_domain */
    $locked_domain = get_option('wppilot_ai_abilities_domain', default_value: '');
    $current_domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);

    return $locked_domain !== $current_domain;
}

/**
 * Report whether WordPress Application Passwords are available, and why not if not.
 *
 * Distinguishes between the HTTPS/local-env requirement (`wp_is_application_passwords_supported()`)
 * and a filter-based override (typical of security plugins hooking `wp_is_application_passwords_available`).
 *
 * When a filter is the cause, `blocker` names who installed it where that can be told (see
 * wppilot_app_passwords_blocker()), and `message` then carries that plugin's exact fix instead of
 * the generic "check your security plugin" advice.
 *
 * @return array{available: bool, reason: 'available'|'unsupported'|'filtered', message: string, blocker: array{source: string, name: string, message: string, remedy: string, url: string}|null}
 */
function wppilot_app_passwords_status(): array
{
    if (wp_is_application_passwords_available()) {
        return ['available' => true, 'reason' => 'available', 'message' => '', 'blocker' => null];
    }

    if (!wp_is_application_passwords_supported()) {
        return [
            'available' => false,
            'reason' => 'unsupported',
            'message' => __(
                'Application Passwords require HTTPS or WP_ENVIRONMENT_TYPE set to "local".',
                domain: 'wppilot',
            ),
            'blocker' => null,
        ];
    }

    $blocker = wppilot_app_passwords_blocker();
    if ($blocker !== null) {
        return [
            'available' => false,
            'reason' => 'filtered',
            'message' => $blocker['message'] . ' ' . $blocker['remedy'],
            'blocker' => $blocker,
        ];
    }

    return [
        'available' => false,
        'reason' => 'filtered',
        'message' => __(
            'Application Passwords have been disabled on this site, likely by a security plugin. Check your security plugin settings (e.g. Solid Security, Wordfence, All In One WP Security) and re-enable Application Passwords, or connect with OAuth or a GoBuildWP access token, which do not use them.',
            domain: 'wppilot',
        ),
        'blocker' => null,
    ];
}
