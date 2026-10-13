<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- OAuth 2.1 protocol endpoint called by external MCP clients; WordPress nonces cannot exist in this flow. Input is validated against the RFC grammar and rejected with protocol errors, and redirects go to client-registered callback URLs as the spec requires.

namespace WPPilot\OAuth\Endpoints\Authorize;

use League\OAuth2\Server\RedirectUriValidators\RedirectUriValidator;
use WPPilot\OAuth\Repositories\ClientRepository;

if (!defined('ABSPATH')) {
    exit();
}

const PENDING_PREFIX = 'wppilot_oauth_pending_';

const PAGE_SLUG = 'wppilot-oauth-authorize';

/**
 * The authorization endpoint is the one OAuth step that must identify the logged-in
 * WordPress user, so it runs inside the wp-admin cookie context instead of on a REST
 * route. Cookie authentication for the WordPress REST API is disabled or nonce-gated on
 * many hardened sites (security plugins, custom auth, cloned staging sites), which would
 * trap the browser in an endless wp-login redirect loop if `authorize` lived under
 * /wp-json/. admin.php authenticates with the admin cookie and runs auth_redirect() for
 * us, so the flow works uniformly across sites. The remaining endpoints (token, register,
 * revoke, introspect) stay on REST because they authenticate with PKCE/client credentials,
 * not the WordPress user session.
 */
function register(): void
{
    $hook = add_submenu_page(
        parent_slug: '',
        page_title: 'Authorize Application',
        menu_title: '',
        capability: \wppilot_manage_capability(),
        menu_slug: PAGE_SLUG,
        callback: __NAMESPACE__ . '\\render',
    );
    if (is_string($hook) && $hook !== '') {
        add_action('load-' . $hook, __NAMESPACE__ . '\\handle');
    }
}

/**
 * Runs on load-{hook}, before any admin output. admin.php has already enforced login
 * (auth_redirect) and the GoBuildWP management capability, so the current user is
 * authenticated and authorized here.
 */
function handle(): void
{
    if (!\wppilot_current_user_can_manage()) {
        wp_die(
            esc_html__('You are not allowed to authorize GoBuildWP applications.', domain: 'wppilot'),
            title: '',
            args: [
                'response' => 403,
            ],
        );
        return;
    }

    $response_type = get_param('response_type');
    if ($response_type !== 'code') {
        wp_die(esc_html__('response_type must be "code".', domain: 'wppilot'), title: '', args: ['response' => 400]);
        return;
    }

    $client_id = get_param('client_id');
    if ($client_id === '') {
        wp_die(esc_html__('client_id is required.', domain: 'wppilot'), title: '', args: ['response' => 400]);
        return;
    }

    $redirect_uri = get_param('redirect_uri');
    if ($redirect_uri === '') {
        wp_die(esc_html__('redirect_uri is required.', domain: 'wppilot'), title: '', args: ['response' => 400]);
        return;
    }

    // A `localhost` callback is normalised to `127.0.0.1` before anything compares it, so
    // the ephemeral-port match below and league's identical check at the consent step both
    // see a host they recognise as loopback. The client's registered URIs are normalised
    // the same way when the metadata document is validated.
    require_once dirname(__DIR__) . '/client-id-metadata.php';
    $redirect_uri = \WPPilot\OAuth\ClientIdMetadata\normalize_loopback_uri($redirect_uri);

    $code_challenge = get_param('code_challenge');
    if ($code_challenge === '') {
        wp_die(esc_html__('code_challenge is required (PKCE mandatory).', domain: 'wppilot'), title: '', args: [
            'response' => 400,
        ]);
        return;
    }

    $code_challenge_method = get_param('code_challenge_method');
    if ($code_challenge_method !== 'S256') {
        wp_die(esc_html__('code_challenge_method must be "S256".', domain: 'wppilot'), title: '', args: [
            'response' => 400,
        ]);
        return;
    }

    $scope = \WPPilot\OAuth\normalize_authorization_scope(get_param('scope'));
    if ($scope === null) {
        wp_die(esc_html__('The requested OAuth scope is invalid.', domain: 'wppilot'), title: '', args: [
            'response' => 400,
        ]);
        return;
    }

    $resource = get_param('resource');
    if (!\WPPilot\OAuth\resource_request_allowed($resource, \WPPilot\OAuth\resource_identifier())) {
        wp_die(
            esc_html__('The requested resource is not served by this authorization server.', domain: 'wppilot'),
            title: '',
            args: ['response' => 400],
        );
        return;
    }

    $state = get_param('state');

    $client = (new ClientRepository())->getClientEntity($client_id);
    if ($client === null) {
        wp_die(esc_html__('Unknown client_id.', domain: 'wppilot'), title: '', args: ['response' => 400]);
        return;
    }

    // RFC 8252 §7.3: a native/desktop client binds an ephemeral loopback port that can differ from
    // the one it registered, so league's validator matches 127.0.0.1/[::1] ignoring the port (and
    // requires an exact match otherwise). This is the same check league runs at the consent step, so
    // validating it the same way here keeps the two consistent.
    $uri_validator = new RedirectUriValidator($client->getRedirectUri());
    if (!$uri_validator->validateRedirectUri($redirect_uri)) {
        wp_die(esc_html__('redirect_uri not registered for this client.', domain: 'wppilot'), title: '', args: [
            'response' => 400,
        ]);
        return;
    }

    $token = bin2hex(random_bytes(16));
    set_transient(
        PENDING_PREFIX . $token,
        [
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'code_challenge' => $code_challenge,
            'code_challenge_method' => $code_challenge_method,
            'scope' => $scope,
            'state' => $state,
            'user_id' => get_current_user_id(),
        ],
        expiration: 600,
    );

    wp_safe_redirect(admin_url('admin.php?page=wppilot-oauth-consent&token=' . rawurlencode($token)));
    exit();
}

/**
 * Page-body callback. handle() always redirects or wp_die()s on load-{hook} before the
 * admin body renders, so reaching here means the request was not a valid authorization
 * request (e.g. the page was opened without OAuth parameters).
 */
function render(): void
{
    wp_die(esc_html__('Invalid authorization request.', domain: 'wppilot'), title: '', args: ['response' => 400]);
}

/**
 * Read a query parameter as a sanitized string. The authorization endpoint is entered
 * cross-site by the OAuth client without a WordPress nonce (by design), so nonce
 * verification does not apply; the request is validated by PKCE, the registered
 * redirect_uri allowlist, and the nonce-protected consent step.
 */
function get_param(string $key): string
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $raw = $_GET[$key] ?? '';
    return is_string($raw) ? sanitize_text_field($raw) : '';
}

/**
 * Some OAuth clients build the authorization URL by appending "?<params>" to the advertised
 * authorization_endpoint without noticing it already carries "?page=<slug>", folding their
 * parameters onto the page slug (page=<slug>?response_type=...). WordPress then routes to a
 * non-existent admin page and denies the request ("Sorry, you are not allowed to access this
 * page."). Runs on plugins_loaded, before admin.php reads $_GET['page']: split the stray query
 * back out and restore the folded parameters as real request parameters. RFC 6749 §3.1.2 requires
 * clients to append with "&"; this repairs the ones that use "?" instead.
 */
function repair_folded_request(): void
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $page = $_GET['page'] ?? null;
    if (!is_admin() || !is_string($page) || !str_starts_with($page, PAGE_SLUG . '?')) {
        return;
    }

    $parts = explode('?', $page, limit: 2);
    $_GET['page'] = $parts[0];

    $recovered = [];
    parse_str($parts[1] ?? '', $recovered);
    foreach ($recovered as $key => $value) {
        if (array_key_exists($key, $_GET)) {
            continue;
        }
        $_GET[$key] = $value;
    }

    // Keep the raw request line consistent so auth_redirect()'s post-login return URL is clean too.
    foreach (['REQUEST_URI', 'QUERY_STRING'] as $server_key) {
        $raw = $_SERVER[$server_key] ?? '';
        if ($raw !== '') {
            $_SERVER[$server_key] = str_replace(PAGE_SLUG . '?', PAGE_SLUG . '&', $raw);
        }
    }
}
