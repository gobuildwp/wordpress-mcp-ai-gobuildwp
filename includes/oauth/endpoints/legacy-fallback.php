<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- OAuth 2.1 protocol endpoint called by external MCP clients; WordPress nonces cannot exist in this flow. Input is validated against the RFC grammar and rejected with protocol errors, and redirects go to client-registered callback URLs as the spec requires.

namespace WPPilot\OAuth\Endpoints\LegacyFallback;

if (!defined('ABSPATH')) {
    exit();
}

const AUTHORIZE_PATH = '/authorize';

const REGISTER_PATH = '/register';

const TOKEN_PATH = '/token';

/** @var list<string> */
const AUTHORIZATION_QUERY_PARAMETERS = [
    'response_type',
    'client_id',
    'redirect_uri',
    'code_challenge',
    'code_challenge_method',
    'scope',
    'state',
    'resource',
];

function register(): void
{
    // Run before canonical redirects and the template loader, but after WordPress has resolved the
    // request. is_404() lets real pages and custom rewrite routes keep these generic path names.
    add_action('template_redirect', __NAMESPACE__ . '\\maybe_serve', priority: 0);
}

/**
 * Return the legacy MCP fallback endpoint named by an HTTP request.
 */
function endpoint_for_request(string $request_uri, string $method): ?string
{
    $path = wp_parse_url($request_uri, PHP_URL_PATH);
    if (!is_string($path)) {
        return null;
    }

    $method = strtoupper($method);
    return match ([$path, $method]) {
        [AUTHORIZE_PATH, 'GET'] => 'authorize',
        [REGISTER_PATH, 'POST'] => 'register',
        [TOKEN_PATH, 'POST'] => 'token',
        default => null,
    };
}

function maybe_serve(): void
{
    // Do not take these deliberately generic slugs away from a page, plugin, theme, or custom
    // rewrite rule. The fallback exists only for paths WordPress could not otherwise resolve.
    if (!is_404()) {
        return;
    }

    $endpoint = endpoint_for_request($_SERVER['REQUEST_URI'] ?? '', $_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($endpoint === null) {
        return;
    }

    if ($endpoint === 'authorize') {
        redirect_to_authorization_page();
    }

    serve_rest_endpoint('/wppilot/v1/oauth/' . $endpoint);
}

/**
 * Move the browser into wp-admin so WordPress can authenticate the user with its normal admin
 * cookie. Only parameters consumed by the authorization endpoint are forwarded.
 */
function redirect_to_authorization_page(): void
{
    $parameters = [];
    foreach (AUTHORIZATION_QUERY_PARAMETERS as $name) {
        // OAuth authorization requests are cross-site GETs and intentionally carry no WP nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $value = $_GET[$name] ?? null;
        if (is_string($value)) {
            $parameters[$name] = wp_unslash($value);
        }
    }

    $url = add_query_arg($parameters, admin_url('admin.php?page=wppilot-oauth-authorize'));
    nocache_headers();
    if (!wp_safe_redirect($url)) {
        wp_die(esc_html__('Could not open the GoBuildWP authorization page.', domain: 'wppilot'), title: '', args: [
            'response' => 500,
        ]);
    }
    exit();
}

/**
 * Dispatch a fallback POST through WordPress's REST server. This preserves the original headers
 * and body while reusing the canonical endpoint's validation, rate limits and response handling.
 */
function serve_rest_endpoint(string $route): void
{
    if (!defined('REST_REQUEST')) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- REST_REQUEST is WordPress core's own constant; this fallback dispatches through the REST server and must set it exactly as core would.
        define('REST_REQUEST', value: true);
    }

    rest_get_server()->serve_request($route);
    exit();
}
