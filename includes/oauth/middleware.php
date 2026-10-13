<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- OAuth 2.1 protocol endpoint called by external MCP clients; WordPress nonces cannot exist in this flow. Input is validated against the RFC grammar and rejected with protocol errors, and redirects go to client-registered callback URLs as the spec requires.

namespace WPPilot\OAuth\Middleware;

use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use WP_Error;
use WP_REST_Request;
use WPPilot\OAuth\Bridge;
use WPPilot\OAuth\ServerFactory;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    // WordPress cookie and Application Password authenticators run first. GoBuildWP only establishes
    // an identity when they did not, and does so before REST authentication/hardening callbacks.
    add_filter('determine_current_user', __NAMESPACE__ . '\\resolve_bearer_identity', priority: 30);
    add_filter('rest_authentication_errors', __NAMESPACE__ . '\\reject_invalid_bearer', priority: 5);

    // This hook runs only after WP_REST_Server matches a registered route and before its permission
    // callback, so both OAuth authorization and missing-credential challenges use authoritative routing.
    add_filter(
        'rest_request_before_callbacks',
        __NAMESPACE__ . '\\authorize_routed_request',
        priority: 5,
        accepted_args: 3,
    );

    // Denials above are WP_Error, the only value core lets short-circuit a route's permission callback.
    // Their WWW-Authenticate challenge is attached here, once the error has survived to the response.
    add_filter(
        'rest_request_after_callbacks',
        __NAMESPACE__ . '\\attach_www_authenticate_challenge',
        priority: 5,
        accepted_args: 3,
    );
}

/**
 * Establish a WordPress identity without making any route authorization decision.
 *
 * Two kinds of credential arrive as `Authorization: Bearer`: an OAuth access
 * token, and a GoBuildWP access token. They are told apart by the token's fixed
 * prefix before either validator runs, never by trying one and falling back to
 * the other — a failed OAuth validation records an authentication error, which
 * denies the request outright, so a GoBuildWP token would never get a second look.
 *
 * @param mixed $user Existing identity established by WordPress.
 * @return mixed The existing identity, or the subject ID after successful validation.
 */
function resolve_bearer_identity(mixed $user): mixed
{
    // An MCP credential is for the REST API, as core scopes Application
    // Passwords. Accepting it everywhere let a Read Only token post a comment
    // through wp-comments-post.php, or drive admin-ajax, as the administrator -
    // outside every check the REST boundary applies.
    if (!is_rest_request()) {
        return $user;
    }

    // Validating a credential checks the subject's capabilities, and a plugin's
    // map_meta_cap filter may ask for the current user (Yoast SEO does). Before
    // this filter returns, that asks determine_current_user again: without this
    // guard the request recursed until PHP ran out of memory. The inner call
    // leaves the identity alone; the outer one sets it.
    static $resolving = false;
    if ($resolving) {
        return $user;
    }
    $resolving = true;
    try {
        return resolve_bearer_identity_now($user);
    } finally {
        $resolving = false;
    }
}

function resolve_bearer_identity_now(mixed $user): mixed
{
    $auth = get_authorization_header();

    // function_exists guards the load order rather than the feature: this file is
    // reachable from the OAuth bootstrap, and a request that arrived before the
    // token module loaded must fall through to OAuth rather than fatal.
    if (function_exists('wppilot_token_looks_like') && \wppilot_token_looks_like(bearer_secret($auth))) {
        return resolve_token_identity($user, $auth);
    }

    return resolve_bearer_identity_using(
        $user,
        $auth,
        static fn(string $authorization): array => validate_bearer_credential($authorization),
    );
}

/**
 * Whether this request is for the REST API.
 *
 * REST_REQUEST is defined only once WordPress parses the request, and the
 * current user can be resolved before that, so the REST URL prefix and the
 * rest_route query form are recognised too.
 */
function is_rest_request(): bool
{
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return true;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing test only; nothing is read into state.
    if (isset($_GET['rest_route'])) {
        return true;
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    $prefix = '/' . trim(rest_get_url_prefix(), '/') . '/';
    $home_path = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');

    return $path !== '' && str_starts_with($path, $home_path . $prefix);
}

/**
 * Establish an identity from a GoBuildWP access token.
 *
 * Mirrors resolve_bearer_identity_using() rather than reusing it: the OAuth path
 * reports its failures as OAuth failures, and a user debugging a static token
 * should not be told their "OAuth access token" is invalid.
 *
 * @return mixed The existing identity, or the token's subject ID.
 */
function resolve_token_identity(mixed $user, string $auth): mixed
{
    reset_request_context();

    if (has_existing_identity($user)) {
        return $user;
    }

    $identity = \wppilot_token_authenticate(bearer_secret($auth));
    if ($identity === null) {
        $reason = function_exists('wppilot_token_refusal') ? \wppilot_token_refusal() : '';
        record_authentication_error($reason !== '' ? $reason : 'Invalid, expired, or revoked GoBuildWP access token.');
        return $user;
    }

    wp_set_current_user($identity['user_id']);
    record_oauth_identity($identity['user_id'], ['mcp'], 'token-' . $identity['id'], via: 'token');
    \wppilot_token_touch($identity['id']);

    return $identity['user_id'];
}

/**
 * Testable identity-resolution core. The production entry point always supplies the cryptographic
 * League OAuth resource-server validator above; route or query values are deliberately unavailable.
 *
 * @param \Closure(string): array{user_id: int, scopes: list<string>, client_id?: string} $validator
 * @return mixed
 */
function resolve_bearer_identity_using(mixed $user, string $auth, \Closure $validator): mixed
{
    reset_request_context();

    if (has_existing_identity($user)) {
        return $user;
    }
    if (!has_bearer_scheme($auth)) {
        return $user;
    }
    if (!has_bearer_authorization($auth)) {
        record_authentication_error('Malformed OAuth Bearer credential.');
        return $user;
    }

    try {
        $identity = $validator(normalize_bearer_authorization($auth));
        $user_id = $identity['user_id'];
        if ($user_id <= 0) {
            record_authentication_error('Invalid OAuth token subject.');
            return $user;
        }

        wp_set_current_user($user_id);
        record_oauth_identity($user_id, $identity['scopes'], (string) ($identity['client_id'] ?? ''));

        return $user_id;
    } catch (OAuthServerException) {
        record_authentication_error('Invalid, expired, or revoked OAuth access token.');
        return $user;
    } catch (\Throwable) {
        record_authentication_error('OAuth authentication failed.', status: 500);
        return $user;
    }
}

/**
 * Validate signature, validity window, revocation, audience, subject, and granted scopes.
 *
 * The optional server exists for focused tests; production always builds the server from GoBuildWP's
 * own key and access-token repository.
 *
 * @return array{user_id: int, scopes: list<string>, client_id?: string}
 * @throws OAuthServerException
 */
function validate_bearer_credential(string $auth, ?ResourceServer $server = null): array
{
    $production_server = $server === null;
    $server ??= ServerFactory\build_resource_server();
    $validated = $server->validateAuthenticatedRequest(Bridge\psr7_from_globals()->withHeader(
        'Authorization',
        normalize_bearer_authorization($auth),
    ));

    // RFC 8707: a token minted for another resource must never establish a WordPress identity.
    if ($validated->getAttribute('oauth_client_id') !== \WPPilot\OAuth\resource_identifier()) {
        throw OAuthServerException::accessDenied('Token audience does not match this resource.');
    }

    $user_id = (int) $validated->getAttribute('oauth_user_id');
    if ($user_id <= 0) {
        throw OAuthServerException::accessDenied('Invalid token subject.');
    }

    $identity = [
        'user_id' => $user_id,
        'scopes' => normalize_scopes($validated->getAttribute('oauth_scopes')),
    ];

    // The JWT audience is the protected MCP resource rather than the OAuth
    // client, so League correctly exposes the resource under oauth_client_id.
    // Resolve the real registered client from the persisted token identifier
    // for per-client budgets and connection accounting. Tests may inject a
    // standalone ResourceServer with no WordPress repository, hence the
    // production-only lookup.
    if ($production_server) {
        $token_id = (string) $validated->getAttribute('oauth_access_token_id');
        if ($token_id !== '') {
            $client_id = (new \WPPilot\OAuth\Repositories\AccessTokenRepository())->get_client_id_by_token_identifier(
                $token_id,
            );
            if (is_string($client_id) && $client_id !== '') {
                $identity['client_id'] = $client_id;
            }
        }
    }

    return $identity;
}

/**
 * Return a stored validation error before later REST hardening callbacks run.
 */
function reject_invalid_bearer(mixed $result): mixed
{
    $error = request_authentication_error();
    // A plugin that reads the current user during plugins_loaded (Beaver Builder does, through
    // get_user_locale) settles "nobody" before this middleware is registered, and WordPress caches
    // that answer. A valid credential was later rescued by any plugin that re-resolved the user,
    // but a bad one was never looked at: the client got core's generic 401 with no
    // WWW-Authenticate invalid_token, and an expired token never said it had expired. So a Bearer
    // credential nothing has judged yet is judged here, at REST authentication.
    if (
        $error === null
        && request_oauth_identity() === null
        && get_current_user_id() === 0
        && has_bearer_scheme(get_authorization_header())
    ) {
        /** @var mixed $resolved */
        $resolved = resolve_bearer_identity(0);
        if (is_int($resolved) && $resolved > 0 && get_current_user_id() === 0) {
            wp_set_current_user($resolved);
        }
        $error = request_authentication_error();
    }
    if ($error === null) {
        return $result;
    }

    // A different authenticator must not turn a presented invalid Bearer credential into success.
    send_www_authenticate('invalid_token');
    return $error;
}

/**
 * Enforce OAuth's route boundary from the authoritative matched request route and method.
 */
function authorize_routed_request(mixed $result, mixed $handler, WP_REST_Request $request): mixed
{
    $route = $request->get_route();
    $method = strtoupper($request->get_method());
    $identity = request_oauth_identity();

    if ($identity === null) {
        return challenge_unauthenticated($result, $handler, $request);
    }

    $within_boundary = oauth_identity_may_use_route($route, $method);
    if (!$within_boundary && !is_internal_dispatch_of_admitted_request($route)) {
        return new WP_Error(
            'rest_oauth_route_forbidden',
            'This OAuth credential is not accepted on the requested REST route.',
            ['status' => 403],
        );
    }

    if (!scopes_authorize_routed_request($identity['scopes'], $route, $method)) {
        return new WP_Error('rest_oauth_error', 'Token does not grant access to this REST route.', ['status' => 403]);
    }

    if (!current_user_can_access_mcp()) {
        return new WP_Error('rest_oauth_error', 'User is no longer allowed to manage GoBuildWP.', [
            'status' => 403,
        ]);
    }

    if ($within_boundary) {
        note_admitted_http_request($route, $method);
    }

    // Preserve an earlier pre-dispatch response only after proving that OAuth may be used here.
    return $result;
}

/**
 * The REST route of the HTTP request this process is serving, or '' outside one.
 *
 * Read from the URL WordPress parsed, not from any WP_REST_Request: rest_api_loaded() builds the
 * outer request from exactly this value (untrailingslashed, '/' when empty), and nothing an
 * internal dispatch or a batch subrequest carries can change it.
 */
function http_rest_route(): string
{
    // @mago-expect lint:no-global -- $wp is WordPress' parsed main request.
    // @mago-expect analysis:mixed-assignment -- Narrowed by the checks below.
    $wp = $GLOBALS['wp'] ?? null;
    // @mago-expect analysis:mixed-assignment -- Narrowed by the checks below.
    $query_vars = is_object($wp) && property_exists($wp, 'query_vars') ? $wp->query_vars : null;
    // @mago-expect analysis:mixed-assignment -- Narrowed by the checks below.
    $route = is_array($query_vars) ? $query_vars['rest_route'] ?? '' : '';
    if (!is_string($route) || $route === '') {
        return '';
    }
    // untrailingslashit(), spelled out so this stays callable before formatting.php loads.
    $route = rtrim($route, characters: '/\\');

    return $route === '' ? '/' : $route;
}

/**
 * Routes whose handlers run abilities through GoBuildWP's gate (wppilot_gate_ability_call(), or the
 * foreign gate for other MCP servers' abilities): the MCP endpoints and the REST shim's run route.
 */
function route_runs_gated_abilities(string $route, string $method): bool
{
    return is_any_mcp_route($route)
        || ($method === 'POST' && preg_match('#^/wppilot/v1/abilities/[^/]+(?:/[^/]+)+/run$#', $route) === 1);
}

/**
 * Remember that the HTTP request itself was admitted onto an ability-running route.
 *
 * Only the outer request counts: the route being admitted must be the one in the URL. An internal
 * dispatch or a batch subrequest to an MCP route can never stand in for it, because the URL of a
 * batch call is /batch/v1, which no access token may use.
 */
function note_admitted_http_request(string $route, string $method): void
{
    if ($route !== http_rest_route() || !route_runs_gated_abilities($route, $method)) {
        return;
    }
    $context = &request_context();
    $context['admitted_ability_request'] = true;
}

/**
 * Whether a routed request outside the token's boundary is an internal dispatch that an ability
 * made while serving an already-admitted MCP (or ability-run) request.
 *
 * The route boundary exists so a token cannot be pointed at arbitrary WordPress REST routes over
 * HTTP. It was also applied to rest_do_request() calls an ability makes for itself — the block
 * theme kit reading global styles, a backup plugin's export endpoint — which broke every such
 * ability for OAuth and access-token connections while Application Passwords worked. Such a call
 * is part of an ability that has already passed GoBuildWP's gate: the safety profile (which is what
 * a read-only token's limits are), the confirmation contract and the rate limit. The route it
 * dispatches is chosen by the ability's code, not by the client, and the route's own
 * permission_callback still runs as the token's user. The scope and manage-capability checks after
 * the boundary still apply to it.
 *
 * Three things must all hold, and none of them can be supplied by an HTTP client:
 * - the request is not the HTTP request itself (its route differs from the URL's);
 * - the HTTP request's own route is one that runs gated abilities;
 * - that HTTP request was admitted by this boundary for this identity.
 * A REST batch call fails the second: its URL is /batch/v1, so its subrequests stay enforced.
 */
function is_internal_dispatch_of_admitted_request(string $route): bool
{
    $outer = http_rest_route();
    if ($outer === '' || $route === $outer) {
        return false;
    }
    $context = &request_context();

    // Set only by note_admitted_http_request(), which already required the URL's route to run
    // gated abilities, so the outer route needs no second look here.
    return $context['admitted_ability_request'] === true;
}

/**
 * Deny a credential-less request to an OAuth-protected route.
 *
 * The return must be a WP_Error: core only lets a WP_Error short-circuit the route's own
 * permission_callback. A WP_REST_Response is passed over, the route's callback still denies, and the
 * generic rest_forbidden error replaces it — challenge header included.
 */
function challenge_unauthenticated(mixed $result, mixed $handler, WP_REST_Request $request): mixed
{
    if ($result !== null || get_current_user_id() > 0 || has_bearer_scheme(get_authorization_header())) {
        return $result;
    }

    if (route_required_scope($request->get_route(), strtoupper($request->get_method())) === null) {
        return $result;
    }

    return new WP_Error('rest_oauth_required', 'OAuth authentication required.', ['status' => 401]);
}

/**
 * Carry the challenge on the response object rather than emitting it as a raw header.
 *
 * Routed denials are decided during dispatch, which also runs for internal calls (rest_do_request(),
 * REST batch subrequests). A header() there would alter the enclosing HTTP response while the
 * WP_REST_Response actually handed back to the caller carried no challenge at all. Core applies this
 * filter even when $response is already a WP_Error, and converts errors only afterwards, so performing
 * the conversion here is what lets the header survive.
 */
function attach_www_authenticate_challenge(mixed $response, mixed $handler, WP_REST_Request $request): mixed
{
    if (!$response instanceof WP_Error) {
        return $response;
    }

    $challenge = routed_challenge($response, $request);
    if ($challenge === null) {
        return $response;
    }

    $converted = rest_convert_error_to_response($response);
    $converted->header('WWW-Authenticate', $challenge);

    return $converted;
}

/**
 * The RFC 6750 challenge a routed OAuth denial should advertise, or null when the error is not one.
 */
function routed_challenge(WP_Error $error, WP_REST_Request $request): ?string
{
    $code = $error->get_error_code();
    $required_scope = route_required_scope($request->get_route(), strtoupper($request->get_method()));

    if ($code === 'rest_oauth_required') {
        // RFC 6750 §3.1: no `error` parameter when the request carried no credentials at all.
        return $required_scope === null ? null : www_authenticate_header(scope: $required_scope);
    }
    if ($code === 'rest_oauth_route_forbidden') {
        // The route lies outside OAuth's boundary, so it has no route-specific scope to name.
        return www_authenticate_header('insufficient_scope');
    }
    // rest_oauth_error also carries pre-dispatch authentication failures, which never reach this
    // filter; the 403 status is what identifies the routed scope denials among them.
    if ($code === 'rest_oauth_error' && error_status($error) === 403) {
        return www_authenticate_header('insufficient_scope', $required_scope ?? 'mcp');
    }

    return null;
}

function error_status(WP_Error $error): ?int
{
    // @mago-expect analysis:mixed-assignment
    $data = $error->get_error_data();
    // @mago-expect analysis:mixed-assignment
    $status = is_array($data) ? $data['status'] ?? null : null;

    return is_numeric($status) ? (int) $status : null;
}

/**
 * RFC 6750 WWW-Authenticate challenge value.
 */
function www_authenticate_header(?string $error = null, string $scope = 'mcp'): string
{
    $value = 'Bearer resource_metadata="' . \WPPilot\OAuth\Endpoints\Discovery\protected_resource_metadata_url() . '"';
    if ($error !== null) {
        $value .= ', error="' . $error . '"';
    }
    return $value . ', scope="' . $scope . '"';
}

/**
 * Emit the challenge for a rejected Bearer credential.
 *
 * rest_authentication_errors runs in serve_request() before dispatch, so this is a real HTTP response
 * being answered — never an internal dispatch — and no response object exists yet to carry the header.
 */
function send_www_authenticate(string $error): void
{
    if (!headers_sent()) {
        header('WWW-Authenticate: ' . www_authenticate_header($error));
    }
}

/**
 * Existing OAuth protocol endpoint retained for backward compatibility.
 */
function is_mcp_route(string $route): bool
{
    return $route === '/mcp/wppilot-oauth' || str_starts_with($route, '/mcp/wppilot-oauth/');
}

/**
 * Whether a route is any of the MCP endpoints this site serves.
 *
 * Access tokens are accepted on all of them, the canonical `/mcp/wppilot`
 * included. That endpoint is the URL every client config on the Connect screen
 * already points at, and the API-side callers this credential exists for —
 * Anthropic's MCP connector, OpenAI's `mcp` tool — take one URL and one bearer
 * token with no way to express "use the other endpoint for this credential".
 * Sending them to a second URL would be a second thing to get wrong for no
 * security gain: the endpoints are mirrors of the same server.
 */
function is_any_mcp_route(string $route): bool
{
    foreach (['/mcp/wppilot-oauth', '/mcp/wppilot', '/mcp/mcp-adapter-default-server'] as $base) {
        if ($route === $base || str_starts_with($route, $base . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * Exact REST routes on which a GoBuildWP-established identity may survive post-routing checks.
 */
function oauth_identity_may_use_route(string $route, string $method): bool
{
    $method = strtoupper($method);
    if (is_mcp_route($route)) {
        return true;
    }
    // Only for an identity already established by an access token. With no
    // identity this is false, which is what keeps the OAuth challenge off the
    // Application Password endpoint: an unauthenticated request there must go on
    // reaching the adapter's own 401 rather than being answered with a
    // WWW-Authenticate pointing at OAuth.
    // @mago-expect lint:no-insecure-comparison -- credential kind, not a secret.
    if (request_identity_via() === 'token' && is_any_mcp_route($route)) {
        return true;
    }
    // GoBuildWP Cloud's status and unlink routes. Any token identity gets past
    // this boundary; the routes' own permission callback then admits only the
    // token that was paired with the Cloud. See includes/cloud/rest.php.
    // @mago-expect lint:no-insecure-comparison -- credential kind, not a secret.
    if (request_identity_via() === 'token' && str_starts_with($route, '/wppilot/v1/cloud/')) {
        return true;
    }
    if ($route === '/wp-abilities/v1/abilities') {
        return $method === 'GET' || $method === 'HEAD';
    }
    if ($method === 'GET' && preg_match('#^/wp-abilities/v1/abilities/[^/]+(?:/[^/]+)+$#', $route) === 1) {
        return true;
    }

    return $method === 'POST' && preg_match('#^/wppilot/v1/abilities/[^/]+(?:/[^/]+)+/run$#', $route) === 1;
}

/** @param list<string> $scopes */
function scopes_authorize_routed_request(array $scopes, string $route, string $method): bool
{
    // `abilities` and `abilities:read` are accepted only for already-issued CLI tokens. There is
    // no longer a read-only mode: every recognized grant has the same full access as `mcp`.
    return array_intersect($scopes, ['mcp', 'abilities', 'abilities:read']) !== [];
}

function route_required_scope(string $route, string $method): ?string
{
    return oauth_identity_may_use_route($route, $method) ? 'mcp' : null;
}

function get_authorization_header(): string
{
    $auth = trim($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($auth !== '') {
        return $auth;
    }

    return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
}

function has_bearer_scheme(string $auth): bool
{
    return preg_match('/^\s*Bearer(?:\s|$)/i', $auth) === 1;
}

function has_bearer_authorization(string $auth): bool
{
    return preg_match('/^\s*Bearer\s+\S/i', $auth) === 1;
}

function normalize_bearer_authorization(string $auth): string
{
    $matches = [];
    if (preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $auth, $matches) !== 1) {
        return $auth;
    }
    return 'Bearer ' . $matches[1];
}

/**
 * The credential itself, with the scheme and surrounding whitespace removed.
 *
 * Returns an empty string for anything that is not a Bearer credential, so a
 * shape test on the result cannot be fooled by a header of another scheme that
 * happens to contain the token prefix.
 */
function bearer_secret(string $auth): string
{
    $matches = [];
    if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $matches) !== 1) {
        return '';
    }

    return $matches[1];
}

/** @return list<string> */
function normalize_scopes(mixed $scopes): array
{
    if (is_string($scopes)) {
        $parts = preg_split('/\s+/', trim($scopes));
        $scopes = $parts === false ? [] : $parts;
    }
    if (!is_array($scopes)) {
        return [];
    }

    $normalized = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($scopes as $scope) {
        if (!is_string($scope) || $scope === '') {
            continue;
        }
        $normalized[] = $scope;
    }
    return $normalized;
}

function current_user_can_access_mcp(): bool
{
    return \wppilot_current_user_can_manage();
}

function has_existing_identity(mixed $user): bool
{
    return $user !== null && $user !== false && $user !== 0 && $user !== '0';
}

/**
 * Request-local proof and error storage. PHP normally serves one request per process; reset at the
 * start of determine_current_user also keeps persistent test/worker environments isolated.
 *
 * `admitted_ability_request` is set once the HTTP request itself passed the route boundary onto an
 * ability-running route; see is_internal_dispatch_of_admitted_request().
 *
 * @return array{identity: array{user_id: int, scopes: list<string>, via?: string, client_id?: string}|null, error: WP_Error|null, admitted_ability_request: bool}
 */
function &request_context(): array
{
    static $context = ['identity' => null, 'error' => null, 'admitted_ability_request' => false];
    return $context;
}

function reset_request_context(): void
{
    $context = &request_context();
    $context = ['identity' => null, 'error' => null, 'admitted_ability_request' => false];
}

/** @param list<string> $scopes */
function record_oauth_identity(int $user_id, array $scopes, string $client_id = '', string $via = 'oauth'): void
{
    $context = &request_context();
    $context['identity'] = ['user_id' => $user_id, 'scopes' => $scopes, 'via' => $via];
    // An admission belongs to the identity it was decided for.
    $context['admitted_ability_request'] = false;
    if ($client_id !== '') {
        $context['identity']['client_id'] = $client_id;
    }
    $context['error'] = null;
}

/**
 * Which credential established the current identity: 'oauth', 'token', or '' when
 * nothing did.
 */
function request_identity_via(): string
{
    $identity = request_oauth_identity();

    return is_array($identity) ? (string) ($identity['via'] ?? 'oauth') : '';
}

function record_authentication_error(string $message, int $status = 401): void
{
    $context = &request_context();
    $context['identity'] = null;
    $context['admitted_ability_request'] = false;
    $context['error'] = new WP_Error('rest_oauth_error', $message, ['status' => $status]);
}

/** @return array{user_id: int, scopes: list<string>, via?: string, client_id?: string}|null */
function request_oauth_identity(): ?array
{
    $context = &request_context();
    return $context['identity'];
}

function request_authentication_error(): ?WP_Error
{
    $context = &request_context();
    return $context['error'];
}
