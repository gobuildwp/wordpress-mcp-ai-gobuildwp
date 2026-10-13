<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * A short-lived signed-in session for the site's own loopback requests.
 *
 * GoBuildWP checks a page by fetching it the way a visitor gets it. That is the
 * right test for a published page and useless for a draft: an anonymous request
 * is answered with the 404, which reads as a broken page when the real answer is
 * "not published yet". The moment an agent most wants to look at a page is
 * exactly while it is still a draft.
 *
 * So the fetch carries a session belonging to the person who asked. Nothing is
 * escalated - the cookies authenticate the current user and nobody else, they
 * are valid for a minute, they are sent only to this site, and the session token
 * is destroyed as soon as the fetch returns. A caller who cannot see the draft
 * in wp-admin cannot see it here either.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** How long a loopback session lives. Long enough for one fetch and its redirects. */
const WPPILOT_LOOPBACK_SESSION_SECONDS = 60;

/**
 * Open a loopback session for the current user.
 *
 * Returns null when there is no logged-in user, which is the normal case for a
 * request authenticated by access token outside a browser - the caller then
 * falls back to the anonymous fetch and says so, rather than failing.
 *
 * @return array{cookies: array<string, string>, token: string, user_id: int}|null
 */
function wppilot_loopback_session_start(): ?array
{
    $user_id = get_current_user_id();
    if ($user_id <= 0 || !class_exists('WP_Session_Tokens') || !function_exists('wp_generate_auth_cookie')) {
        return null;
    }

    $expiration = time() + WPPILOT_LOOPBACK_SESSION_SECONDS;
    $token = \WP_Session_Tokens::get_instance($user_id)->create($expiration);

    // Both cookies, because wp_validate_auth_cookie() reads the logged-in cookie
    // for identity and the auth cookie for anything that checks the secure
    // scheme. A preview of a draft goes through the second one.
    $cookies = [
        LOGGED_IN_COOKIE => wp_generate_auth_cookie($user_id, $expiration, 'logged_in', $token),
    ];

    if (is_ssl() && defined('SECURE_AUTH_COOKIE')) {
        $cookies[SECURE_AUTH_COOKIE] = wp_generate_auth_cookie($user_id, $expiration, 'secure_auth', $token);
    } elseif (defined('AUTH_COOKIE')) {
        $cookies[AUTH_COOKIE] = wp_generate_auth_cookie($user_id, $expiration, 'auth', $token);
    }

    return ['cookies' => $cookies, 'token' => $token, 'user_id' => $user_id];
}

/**
 * Close a loopback session.
 *
 * The session would expire on its own within the minute; destroying it keeps the
 * user's session list honest, so a person reading "log out everywhere else" is
 * not counting sessions GoBuildWP opened for one HTTP request.
 *
 * @param array{cookies: array<string, string>, token: string, user_id: int}|null $session
 */
function wppilot_loopback_session_end(?array $session): void
{
    if ($session === null || !class_exists('WP_Session_Tokens')) {
        return;
    }

    \WP_Session_Tokens::get_instance($session['user_id'])->destroy($session['token']);
}

/**
 * Whether a URL belongs to this site.
 *
 * The session cookies are only ever attached to a URL that passes this. An
 * agent can hand `verify-rendered-page` any address the site can reach, and
 * sending the caller's session to one of them would be a credential leak with a
 * plausible-looking cause.
 */
function wppilot_url_is_same_site(string $url): bool
{
    $host = wp_parse_url($url, PHP_URL_HOST);
    $home = wp_parse_url(home_url('/'), PHP_URL_HOST);

    return is_string($host) && is_string($home) && strcasecmp($host, $home) === 0;
}
