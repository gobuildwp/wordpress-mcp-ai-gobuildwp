<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp\ServerCard;

use WP_REST_Request;
use WP_REST_Response;

/**
 * The MCP server card: what a client can learn about this server before connecting.
 *
 * Served at `/.well-known/mcp/server-card.json` (SEP-1649's path), with `?wppilot_mcp_server_card=1`
 * for sites without pretty permalinks (where the web server never routes `/.well-known/...` to
 * WordPress), and at `/wp-json/mcp/wppilot/server-card` beside the endpoint it describes.
 *
 * The card format is not final. SEP-2127, which superseded SEP-1649, was still an open pull request
 * in September 2026 and settles on a document that is a strict subset of the MCP Registry's
 * `server.json`: name, title, description, version, websiteUrl, repository, `remotes` (each with its
 * own transport, URL, headers and protocol versions) and `capabilities`. That is the shape used here.
 * Everything GoBuildWP-specific — the endpoint map, the auth methods, the safety profile names, links —
 * sits under one reverse-DNS `_meta` key, so a stricter validator can ignore it.
 *
 * The card is public, identical for every caller, and cacheable, so it carries nothing that depends on
 * who is asking or that describes the site's configuration beyond what connecting requires: no ability
 * list, no active safety profile, no user, no WordPress version. The per-caller view stays behind
 * authentication in `server/discover` and `tools/list` — the guarantee the 1.13.0 anonymous-discovery
 * fix established (see TransportAccessTest) and ServerCardTest holds this document to.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WELL_KNOWN_PATH = '/.well-known/mcp/server-card.json';

const QUERY_VAR = 'wppilot_mcp_server_card';

/** REST route under the `mcp` namespace, beside `/mcp/wppilot`. */
const REST_ROUTE = '/wppilot/server-card';

const REGISTRY_NAME = 'com.gobuildwp/gobuildwp';

/** A wire key clients may already read; it stays as it shipped. */
const META_KEY = 'co.wppilot/server-card';

const WEBSITE_URL = 'https://gobuildwp.com';

const DOCS_URL = 'https://gobuildwp.com/docs';

const REPOSITORY_URL = 'https://github.com/gobuildwp/wordpress-mcp-ai-gobuildwp';

/** One hour: the card changes only when the plugin is updated or OAuth is switched on or off. */
const MAX_AGE = 3600;

/**
 * Plain-language description shared with the Registry `server.json` generator. Kept under the
 * Registry's 100-character limit.
 */
const DESCRIPTION = 'WordPress MCP server: typed abilities with safety profiles, change evidence and undo.';

/**
 * Assemble the card from resolved facts. Pure: every value it prints arrives in $context, which is what
 * lets the test prove that nothing caller- or configuration-specific can reach the document.
 *
 * @param array{
 *     version: string,
 *     mcp_url: string,
 *     oauth_url: string,
 *     alias_url: string,
 *     card_url: string,
 *     well_known_url: string,
 *     modern_urls: list<string>,
 *     app_passwords: bool,
 *     protected_resource_metadata: string,
 *     authorization_server_metadata: string,
 *     profiles: array<string, string>,
 *     modern_version: string,
 *     legacy_version: string,
 * } $context
 * @return array<string, mixed>
 */
function build_card(array $context): array
{
    $all_versions = [$context['modern_version'], $context['legacy_version']];
    $versions_for = static fn(string $url): array => in_array($url, $context['modern_urls'], strict: true)
        ? $all_versions
        : [$context['legacy_version']];

    $authorization_header = [
        'name' => 'Authorization',
        'description' => 'Basic credentials with a WordPress Application Password, or a GoBuildWP access token as a Bearer token.',
        'isRequired' => true,
        'isSecret' => true,
    ];

    $remotes = [[
        'type' => 'streamable-http',
        'url' => $context['mcp_url'],
        'headers' => [$authorization_header],
        'supportedProtocolVersions' => $versions_for($context['mcp_url']),
    ]];

    $endpoints = ['mcp' => $context['mcp_url']];
    $auth = [
        'applicationPasswords' => [
            'available' => $context['app_passwords'],
            'scheme' => 'Basic',
            'endpoints' => [$context['mcp_url']],
        ],
        'accessTokens' => [
            'scheme' => 'Bearer',
            'endpoints' => array_values(array_filter([$context['mcp_url'], $context['oauth_url']])),
        ],
    ];

    if ($context['oauth_url'] !== '') {
        // No Authorization header here on purpose: an OAuth client learns how to sign in from the
        // 401 challenge and the protected-resource metadata, and must not be asked for a secret.
        $remotes[] = [
            'type' => 'streamable-http',
            'url' => $context['oauth_url'],
            'supportedProtocolVersions' => $versions_for($context['oauth_url']),
        ];
        $endpoints['oauth'] = $context['oauth_url'];
        $auth['oauth'] = [
            'endpoint' => $context['oauth_url'],
            'protectedResourceMetadata' => $context['protected_resource_metadata'],
            'authorizationServerMetadata' => $context['authorization_server_metadata'],
            'scopes' => ['mcp'],
            'codeChallengeMethods' => ['S256'],
        ];
    }

    if ($context['alias_url'] !== '') {
        $endpoints['legacyAlias'] = $context['alias_url'];
    }
    $endpoints['serverCard'] = $context['card_url'];
    $endpoints['wellKnownServerCard'] = $context['well_known_url'];

    $profiles = [];
    foreach ($context['profiles'] as $id => $label) {
        $profiles[] = ['id' => $id, 'label' => $label];
    }

    return [
        'name' => REGISTRY_NAME,
        'title' => 'GoBuildWP',
        'description' => DESCRIPTION,
        'version' => $context['version'],
        'websiteUrl' => WEBSITE_URL,
        'repository' => ['url' => REPOSITORY_URL, 'source' => 'github'],
        'remotes' => $remotes,
        // What the server can serve. Which tools, prompts and resources a given connection reaches
        // depends on its user and the site's safety profile, so that is answered only to an
        // authenticated server/discover. Objects, not arrays: `{}` is what the schema requires.
        'capabilities' => [
            'tools' => new \stdClass(),
            'prompts' => new \stdClass(),
            'resources' => new \stdClass(),
            'extensions' => (object) ['io.modelcontextprotocol/skills' => new \stdClass()],
        ],
        '_meta' => [
            META_KEY => [
                'endpoints' => $endpoints,
                'protocolVersions' => [
                    'modern' => $context['modern_version'],
                    'legacy' => $context['legacy_version'],
                ],
                'auth' => $auth,
                // The names a site can choose between, never which one is active: that is part of the
                // per-caller discovery answer and was withheld from anonymous callers in 1.13.0.
                'safetyProfiles' => $profiles,
                'links' => [
                    'website' => WEBSITE_URL,
                    'documentation' => DOCS_URL,
                    'source' => REPOSITORY_URL,
                ],
            ],
        ],
    ];
}

/**
 * Resolve the card's facts from this WordPress install.
 *
 * @return array{version: string, mcp_url: string, oauth_url: string, alias_url: string, card_url: string, well_known_url: string, modern_urls: list<string>, app_passwords: bool, protected_resource_metadata: string, authorization_server_metadata: string, profiles: array<string, string>, modern_version: string, legacy_version: string}
 */
function runtime_context(): array
{
    $oauth = function_exists('wppilot_oauth_transport_allowed') && \wppilot_oauth_transport_allowed();
    $owns_adapter = function_exists('wppilot_owns_mcp_adapter') && \wppilot_owns_mcp_adapter();

    // The modern dispatcher claims routes by is_mcp_route(); asking it keeps the card from promising
    // 2026-07-28 on an endpoint the dispatcher leaves to the adapter.
    $modern_urls = [];
    foreach (['wppilot', 'wppilot-oauth'] as $route) {
        if (\WPPilot\Mcp\is_mcp_route('/mcp/' . $route)) {
            $modern_urls[] = rest_url('mcp/' . $route);
        }
    }

    $profiles = [];
    if (function_exists('wppilot_safety_profiles')) {
        foreach (\wppilot_safety_profiles() as $id => $profile) {
            $profiles[$id] = $profile['label'];
        }
    }

    return [
        'version' => \WPPilot\Mcp\server_version(),
        'mcp_url' => rest_url('mcp/wppilot'),
        'oauth_url' => $oauth ? rest_url('mcp/wppilot-oauth') : '',
        'alias_url' => $owns_adapter ? rest_url('mcp/mcp-adapter-default-server') : '',
        'card_url' => rest_url('mcp' . REST_ROUTE),
        'well_known_url' => home_url(WELL_KNOWN_PATH),
        'modern_urls' => $modern_urls,
        'app_passwords' => function_exists('wppilot_app_passwords_status') && \wppilot_app_passwords_status()['available'],
        'protected_resource_metadata' => $oauth ? home_url('/.well-known/oauth-protected-resource') : '',
        // The OIDC append form: served by this install even when WordPress lives in a subdirectory,
        // unlike the RFC 8414 insert form, which needs the domain root (see oauth/endpoints/discovery.php).
        'authorization_server_metadata' => $oauth ? home_url('/.well-known/openid-configuration') : '',
        'profiles' => $profiles,
        'modern_version' => \WPPilot\Mcp\VERSION_MODERN,
        'legacy_version' => \WPPilot\Mcp\VERSION_LEGACY,
    ];
}

/**
 * The card as served, after the `wppilot_server_card` filter.
 *
 * @return array<string, mixed>
 */
function card(): array
{
    $card = build_card(runtime_context());

    /**
     * Filter the public MCP server card.
     *
     * Served to anonymous callers and cached publicly: add nothing user-, credential- or
     * configuration-specific.
     *
     * @param array<string, mixed> $card
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_server_card', $card);

    return is_array($filtered) ? $filtered : $card;
}

/**
 * Whether the card is served at all: only while the MCP endpoint it describes is answering.
 */
function is_available(): bool
{
    return function_exists('wppilot_is_enabled') && \wppilot_is_enabled();
}

/**
 * The request paths the well-known card answers on.
 *
 * The append form under the site's own path is the one this install always receives. The root form is
 * the same path at the domain root, which only reaches this install when it owns the root — matching it
 * for a subdirectory install is harmless, because such a request never arrives.
 *
 * @return list<string>
 */
function well_known_paths(string $home_path): array
{
    return array_values(array_unique([rtrim($home_path, '/') . WELL_KNOWN_PATH, WELL_KNOWN_PATH]));
}

/**
 * Whether this request asks for the card, by path or by the no-permalinks query flag.
 *
 * @param array<string, mixed> $query
 */
function is_card_request(string $request_uri, array $query, string $home_path): bool
{
    if (array_key_exists(QUERY_VAR, $query)) {
        return true;
    }
    $path = wp_parse_url($request_uri, PHP_URL_PATH);

    return is_string($path) && in_array($path, well_known_paths($home_path), strict: true);
}

/**
 * A strong validator for the encoded card, so a revalidating client gets a 304 without the body.
 */
function etag(string $json): string
{
    return '"' . substr(hash('sha256', $json), 0, 32) . '"';
}

/**
 * @return array<string, string>
 */
function cache_headers(string $etag): array
{
    return [
        'Cache-Control' => 'public, max-age=' . MAX_AGE,
        'ETag' => $etag,
        // Read by browser-based clients on other origins before they hold any credential.
        'Access-Control-Allow-Origin' => '*',
        'X-Content-Type-Options' => 'nosniff',
    ];
}

function encode(array $card): string
{
    $json = wp_json_encode($card, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return is_string($json) ? $json : '{}';
}

/**
 * Serve the well-known card on `init`, before WordPress would route the request to a 404.
 */
function maybe_serve_well_known(): void
{
    if (!is_available()) {
        return;
    }
    $home_path = rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Public read-only discovery document fetched by MCP clients before they hold any credential, so no nonce can exist. The URI path is only compared exactly with the fixed well-known paths (sanitizing would rewrite its percent-encoding) and the query is only checked for the presence of one key.
    if (!is_card_request(isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '', $_GET, $home_path)) {
        return;
    }

    $json = encode(card());
    $etag = etag($json);
    foreach (cache_headers($etag) as $name => $value) {
        header($name . ': ' . $value);
    }
    header('Content-Type: application/json; charset=UTF-8');

    // wp_magic_quotes() slashes $_SERVER too, so the quoted ETag arrives as \"...\" and never
    // matched: every revalidation was a full 200.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- If-None-Match is only compared byte-for-byte with this card's own ETag; it is never stored or output.
    if (trim((string) wp_unslash($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        status_header(304);
        exit();
    }
    status_header(200);
    $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET';
    if (strtoupper($method) !== 'HEAD') {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document.
        echo $json;
    }
    exit();
}

function register_route(): void
{
    if (!is_available()) {
        return;
    }
    register_rest_route('mcp', REST_ROUTE, [
        'methods' => 'GET',
        'callback' => __NAMESPACE__ . '\\rest_card',
        // Public by design: the card is what a client reads before it has a credential. It is the
        // same document for every caller and holds nothing the connect screen does not print.
        'permission_callback' => '__return_true',
    ]);
}

function rest_card(WP_REST_Request $request): WP_REST_Response
{
    return new WP_REST_Response(card(), 200);
}

/**
 * Make the REST copy cacheable.
 *
 * Every `/mcp/wppilot/...` route is marked `no-store` by the transport hardening at priority 20,
 * which is right for JSON-RPC and wrong for a public document meant to be cached. Only this one
 * route is relaxed, and only after hardening has run, so nothing else on the endpoint changes.
 */
function cacheable_rest_response(mixed $response, mixed $server, mixed $request): mixed
{
    if (!$response instanceof WP_REST_Response || !$request instanceof WP_REST_Request) {
        return $response;
    }
    if ($request->get_route() !== '/mcp' . REST_ROUTE || $response->get_status() !== 200) {
        return $response;
    }

    $json = encode(is_array($response->get_data()) ? $response->get_data() : []);
    $headers = $response->get_headers();
    unset($headers['Pragma'], $headers['Expires']);
    $response->set_headers($headers);
    foreach (cache_headers(etag($json)) as $name => $value) {
        $response->header($name, $value);
    }

    return $response;
}

function register(): void
{
    // Priority 1, like the OAuth discovery documents: before anything renders a theme 404.
    add_action('init', __NAMESPACE__ . '\\maybe_serve_well_known', 1);
    add_action('rest_api_init', __NAMESPACE__ . '\\register_route');
    add_filter('rest_post_dispatch', __NAMESPACE__ . '\\cacheable_rest_response', 25, 3);
}
