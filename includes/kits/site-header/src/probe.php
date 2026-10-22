<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteHeader;

if (!defined('ABSPATH')) {
    exit();
}

/*
 * The in-browser half of the header check. The server cannot lay a page out, so the geometry
 * checks run where a layout exists: a page opened with ?wppilot-kit-header-probe=1 measures its
 * own header at the width it is opened at - menu rows, labels that wrap, overlapping elements,
 * horizontal scroll, a menu with no way to open it, a menu button that does not open, header
 * height, text contrast from computed colours, broken images - and publishes the findings as
 * window.siteHeaderProbe, the data-wppilot-kit-header-probe attribute on <html>, and a console
 * line. It reads the page and changes nothing; it opens and closes the menu once to see it work.
 *
 * Only for someone who may change the header. The browser that opens the URL is often not signed
 * in, so the URL carries a token: the user it was issued to, an expiry 15 minutes out and an HMAC
 * of both under a key of its own. It turns the probe on for that page view and does nothing else:
 * it is not a WordPress nonce and no other check accepts it. Anyone else (an anonymous visitor
 * with ?wppilot-kit-header-probe=1, an expired or altered token) gets the page exactly as
 * everyone does, so a page cache never stores a variant; a page that does print the probe is
 * sent with no-cache headers and DONOTCACHEPAGE, and is never cached.
 */

const PROBE_ARG = 'wppilot-kit-header-probe';

/** How long a probe URL works. */
const PROBE_TTL = 900;

/** The probe URL for a page, signed for the current user. */
function probe_url(string $url): string
{
    $user = get_current_user_id();

    return add_query_arg(PROBE_ARG, $user > 0 ? probe_token($user, time() + PROBE_TTL, probe_key()) : '1', $url);
}

/** The probe's own signing key, derived from the site's salt so it matches no other token's. */
function probe_key(): string
{
    return hash_hmac('sha256', 'wppilot-kit-header-probe', wp_salt('nonce'));
}

/** "{user}.{expires}.{hmac}". */
function probe_token(int $user_id, int $expires, string $key): string
{
    return $user_id . '.' . $expires . '.' . hash_hmac('sha256', 'wppilot-kit-header-probe|' . $user_id . '|' . $expires, $key);
}

/** The user a probe token was issued to, or 0 when it is malformed, altered, expired or too far ahead. */
function probe_token_user(string $token, int $now, string $key): int
{
    if (preg_match('/^([1-9]\d{0,19})\.(\d{1,12})\.([0-9a-f]{64})$/D', $token, $m) !== 1) {
        return 0;
    }
    $user = (int) $m[1];
    $expires = (int) $m[2];
    if ($expires < $now || $expires > $now + PROBE_TTL + 60) {
        return 0;
    }

    return hash_equals(probe_token($user, $expires, $key), $token) ? $user : 0;
}

/** Whether this request may run the probe: a signed-in header editor, or a valid token of one. */
function probe_allowed(): bool
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the value is verified here (an HMAC token, or the signed-in user's capability).
    $raw = isset($_GET[PROBE_ARG]) && is_string($_GET[PROBE_ARG]) ? sanitize_text_field(wp_unslash($_GET[PROBE_ARG])) : null;
    if (!is_string($raw) || $raw === '' || strlen($raw) > 120) {
        return false;
    }
    if (current_user_can('edit_theme_options')) {
        return true;
    }
    $user = probe_token_user($raw, time(), probe_key());

    return $user > 0 && user_can($user, 'edit_theme_options');
}

/** Set once the request is allowed the probe, before any output. */
function probing(?bool $set = null): bool
{
    static $on = false;
    if ($set !== null) {
        $on = $set;
    }

    return $on;
}

/**
 * On template_redirect, before output: decide whether this page view prints the probe, and if it
 * does, keep it out of every cache and keep the token out of Referer headers.
 */
function start_probe(): void
{
    if (is_admin() || !probe_allowed()) {
        return;
    }
    probing(true);
    if (!defined('DONOTCACHEPAGE')) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The shared convention page-cache plugins read; this probe response must never be cached.
        define('DONOTCACHEPAGE', true);
    }
    nocache_headers();
    if (!headers_sent()) {
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
    }
}

/**
 * Print the probe (probe.js, next to this file) on a page view start_probe() allowed.
 *
 * Runs on wp_footer after WordPress has printed the footer scripts, so the handle is printed
 * here directly rather than queued. The probe waits for the load event either way.
 */
function print_probe(): void
{
    if (!probing()) {
        return;
    }
    $file = __DIR__ . '/probe.js';
    wp_register_script(
        'wppilot-kit-header-probe',
        plugins_url('probe.js', __FILE__),
        [],
        (string) (file_exists($file) ? filemtime($file) : '1'),
        ['in_footer' => true],
    );
    wp_print_scripts(['wppilot-kit-header-probe']);
}
