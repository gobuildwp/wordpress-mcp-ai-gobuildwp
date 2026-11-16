<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * REST bridge: the wppilot/rest-request ability calls one route of the site's own REST API through
 * rest_do_request(), as the connected user, so the route's own permission checks apply.
 *
 * Every namespace starts closed. An administrator turns reads (GET) and writes (POST, PUT, PATCH,
 * DELETE) on separately per namespace on the Abilities screen. Some namespaces never open, because
 * they would step around the per-namespace switches or GoBuildWP's own gate: batch/v1 runs other
 * requests, wp-abilities/v1 runs abilities, and the MCP and GoBuildWP namespaces hold tokens and
 * approvals. Some routes never take writes whatever the switch says: users, settings, application
 * passwords and plugin installs.
 *
 * A write needs confirm: true (or, when the site requires a person to approve, an approval in
 * wp-admin) and writes one ledger row with the route, a hash of the body and the response code. It
 * cannot be undone from GoBuildWP. Responses are redacted by key and refused above 256 KB or when
 * they are not JSON. A write to a route with no permission check of its own is refused: such a
 * route is guarded in the browser by a nonce the bridge never has, so it fails closed.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_REST_BRIDGE_ABILITY = 'wppilot/rest-request';

/** Per-namespace switches: array<namespace, array{read: bool, write: bool}>. */
const WPPILOT_REST_BRIDGE_OPTION = 'wppilot_rest_bridge';

/** Per-namespace call counts, kept apart so counting never rewrites the switches. */
const WPPILOT_REST_BRIDGE_STATS_OPTION = 'wppilot_rest_bridge_stats';

/** Largest JSON response handed back, in bytes. */
const WPPILOT_REST_BRIDGE_MAX_BYTES = 262144;

/** Ledger name for a change to the switches made on the Abilities screen. */
const WPPILOT_REST_BRIDGE_LEDGER_NAME = 'wppilot/rest-bridge-settings';

const WPPILOT_REST_BRIDGE_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

/** Parameters that would carry a nonce or change how the request is served; refused in query and body. */
const WPPILOT_REST_BRIDGE_RESERVED_PARAMS = ['_wpnonce', '_ajax_nonce', '_method', '_envelope', '_jsonp'];

/**
 * Why a namespace can never be opened, or null when an administrator may open it.
 */
function wppilot_rest_bridge_closed_namespace(string $namespace): ?string
{
    $first = strtolower(explode('/', $namespace)[0]);
    if ($first === 'batch') {
        return __('It runs several other requests in one call, which would step around the per-namespace switches.', domain: 'wppilot');
    }
    if ($first === 'wp-abilities') {
        return __('It runs abilities, which agents already reach through GoBuildWP and its safety gate.', domain: 'wppilot');
    }
    if (str_starts_with($first, 'mcp') || str_starts_with($first, 'wppilot') || str_starts_with($first, 'gobuildwp')) {
        return __('It holds the MCP connection, tokens and approvals that keep agents in check.', domain: 'wppilot');
    }

    return null;
}

/**
 * Why a route never takes writes, or null when the namespace write switch decides.
 */
function wppilot_rest_bridge_never_writable(string $route): ?string
{
    $route = strtolower($route);
    if (str_contains($route, '/application-passwords')) {
        return __('Application passwords are credentials; agents cannot create or change them.', domain: 'wppilot');
    }
    foreach (['/wp/v2/users' => __('Users and their roles are changed by a person in wp-admin, not through the REST bridge.', domain: 'wppilot'), '/wp/v2/settings' => __('Site settings are changed through the settings ability and its allowlist, not through the REST bridge.', domain: 'wppilot'), '/wp/v2/plugins' => __('Installing or switching plugins writes code to the server; that stays behind Developer Full Access.', domain: 'wppilot')] as $prefix => $reason) {
        if ($route === $prefix || str_starts_with($route, $prefix . '/')) {
            return $reason;
        }
    }

    return null;
}

/**
 * A route as the bridge accepts it: one leading slash, no query string, fragment, dot segments or
 * empty segments, and no trailing slash.
 */
function wppilot_rest_bridge_normalize_route(string $route): string|WP_Error
{
    $route = trim($route);
    $invalid = $route === ''
        || $route[0] !== '/'
        || strlen($route) > 2048
        || preg_match('/[\x00-\x20\x7f?#\\\\]/', $route) === 1
        || str_contains($route, '//')
        || preg_match('#(^|/)\.{1,2}(/|$)#', $route) === 1;
    if ($invalid) {
        return new WP_Error(
            'wppilot_rest_invalid_route',
            __('Pass route as a REST path such as /wp/v2/posts/12, without the /wp-json prefix, a query string or dot segments. Put query parameters in query.', domain: 'wppilot'),
            ['status' => 400],
        );
    }
    $route = rtrim($route, '/');

    return $route === '' ? '/' : $route;
}

/**
 * The registered namespace a route belongs to (the longest that matches), or null.
 *
 * @param list<string> $namespaces
 */
function wppilot_rest_bridge_namespace_for(string $route, array $namespaces): ?string
{
    $route = strtolower($route);
    $found = null;
    foreach ($namespaces as $namespace) {
        $prefix = '/' . strtolower(trim($namespace, '/'));
        if ($prefix === '/') {
            continue;
        }
        if (($route === $prefix || str_starts_with($route, $prefix . '/')) && ($found === null || strlen($namespace) > strlen($found))) {
            $found = $namespace;
        }
    }

    return $found;
}

/** @return array<string, array{read: bool, write: bool}> */
function wppilot_rest_bridge_settings(): array
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_REST_BRIDGE_OPTION, []);
    $settings = [];
    if (!is_array($stored)) {
        return $settings;
    }
    foreach ($stored as $namespace => $switches) {
        if (!is_string($namespace) || $namespace === '' || !is_array($switches) || wppilot_rest_bridge_closed_namespace($namespace) !== null) {
            continue;
        }
        $read = ($switches['read'] ?? false) === true;
        $write = ($switches['write'] ?? false) === true;
        if ($read || $write) {
            $settings[$namespace] = ['read' => $read, 'write' => $write];
        }
    }
    ksort($settings);

    return $settings;
}

/** @return array<string, array{reads: int, writes: int, refused: int, last_at: string}> */
function wppilot_rest_bridge_stats(): array
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_REST_BRIDGE_STATS_OPTION, []);
    $stats = [];
    if (!is_array($stored)) {
        return $stats;
    }
    foreach ($stored as $namespace => $row) {
        if (!is_string($namespace) || !is_array($row)) {
            continue;
        }
        $stats[$namespace] = [
            'reads' => max(0, (int) ($row['reads'] ?? 0)),
            'writes' => max(0, (int) ($row['writes'] ?? 0)),
            'refused' => max(0, (int) ($row['refused'] ?? 0)),
            'last_at' => is_string($row['last_at'] ?? null) ? $row['last_at'] : '',
        ];
    }

    return $stats;
}

/**
 * Count one call against a namespace. `$kind` is reads, writes or refused. Concurrent calls can
 * lose an increment; the counts are a guide to which namespaces are used, not an audit trail.
 */
function wppilot_rest_bridge_count(string $namespace, string $kind): void
{
    if (!in_array($kind, ['reads', 'writes', 'refused'], strict: true)) {
        return;
    }
    $stats = wppilot_rest_bridge_stats();
    $row = $stats[$namespace] ?? ['reads' => 0, 'writes' => 0, 'refused' => 0, 'last_at' => ''];
    $row[$kind]++;
    $row['last_at'] = gmdate('c');
    $stats[$namespace] = $row;
    // Bounded by the namespaces the site registers; a stray name cannot grow it past a few hundred.
    if (count($stats) > 500) {
        $stats = array_slice($stats, offset: -500, length: null, preserve_keys: true);
    }
    update_option(WPPILOT_REST_BRIDGE_STATS_OPTION, $stats, false);
}

function wppilot_rest_bridge_settings_url(): string
{
    return admin_url('admin.php?page=wppilot-abilities#wppilot-rest-bridge');
}

/**
 * Check one call against the namespace list and the switches.
 *
 * @param list<string> $namespaces Registered REST namespaces.
 * @param array<string, array{read: bool, write: bool}> $settings
 * @return array{namespace: string, write: bool}|WP_Error
 */
function wppilot_rest_bridge_check(string $method, string $route, array $namespaces, array $settings): array|WP_Error
{
    if (!in_array($method, WPPILOT_REST_BRIDGE_METHODS, strict: true)) {
        return new WP_Error('wppilot_rest_invalid_method', __('method must be GET, POST, PUT, PATCH or DELETE.', domain: 'wppilot'), ['status' => 400]);
    }
    $namespace = wppilot_rest_bridge_namespace_for($route, $namespaces);
    if ($namespace === null) {
        return new WP_Error(
            'wppilot_rest_unknown_namespace',
            __('No REST namespace on this site matches that route. Routes start with their namespace, such as /wp/v2/posts or /wc/v3/orders.', domain: 'wppilot'),
            ['status' => 404, 'namespaces' => array_values(array_filter($namespaces, static fn(string $ns): bool => wppilot_rest_bridge_closed_namespace($ns) === null))],
        );
    }
    $closed = wppilot_rest_bridge_closed_namespace($namespace);
    if ($closed !== null) {
        return new WP_Error(
            'wppilot_rest_namespace_closed',
            sprintf(
                /* translators: 1: REST namespace, 2: the reason */
                __('The REST namespace "%1$s" is never available through the REST bridge. %2$s', domain: 'wppilot'),
                $namespace,
                $closed,
            ),
            ['status' => 403, 'namespace' => $namespace],
        );
    }
    $write = $method !== 'GET';
    if ($write) {
        $never = wppilot_rest_bridge_never_writable($route);
        if ($never !== null) {
            return new WP_Error(
                'wppilot_rest_route_never_writable',
                sprintf(
                    /* translators: %s: the reason */
                    __('This route never takes writes through the REST bridge, whatever the namespace switch says. %s', domain: 'wppilot'),
                    $never,
                ),
                ['status' => 403, 'namespace' => $namespace],
            );
        }
    }
    $switch = $write ? 'write' : 'read';
    if (($settings[$namespace][$switch] ?? false) !== true) {
        return new WP_Error(
            'wppilot_rest_namespace_disabled',
            sprintf(
                /* translators: 1: REST namespace, 2: Read or Write, 3: wp-admin URL */
                __('The REST namespace "%1$s" is not turned on for %2$s. Ask an administrator to tick %2$s for %1$s under REST bridge on the GoBuildWP Abilities screen (%3$s), then retry. Every namespace starts off.', domain: 'wppilot'),
                $namespace,
                $write ? __('Write', domain: 'wppilot') : __('Read', domain: 'wppilot'),
                wppilot_rest_bridge_settings_url(),
            ),
            ['status' => 403, 'namespace' => $namespace, 'switch' => $switch, 'settings_url' => wppilot_rest_bridge_settings_url()],
        );
    }

    return ['namespace' => $namespace, 'write' => $write];
}

/**
 * Refuse reserved parameters, at the top level of query and body.
 *
 * @param array<array-key, mixed> $query
 * @param array<array-key, mixed> $body
 */
function wppilot_rest_bridge_check_params(array $query, array $body): ?WP_Error
{
    foreach ([$query, $body] as $params) {
        foreach (array_keys($params) as $key) {
            if (in_array(strtolower((string) $key), WPPILOT_REST_BRIDGE_RESERVED_PARAMS, strict: true)) {
                return new WP_Error(
                    'wppilot_rest_reserved_param',
                    sprintf(
                        /* translators: %s: parameter name */
                        __('The parameter "%s" is not accepted: the REST bridge never sends a nonce or overrides the method. A route that needs a browser nonce cannot be called through it.', domain: 'wppilot'),
                        (string) $key,
                    ),
                    ['status' => 400],
                );
            }
        }
    }

    return null;
}

/**
 * The registered handler that would serve a method on a route, or null when none does.
 *
 * @param array<string, mixed> $routes rest_get_server()->get_routes(): route pattern => handlers.
 * @return array<string, mixed>|null
 */
function wppilot_rest_bridge_endpoint_for(array $routes, string $route, string $method): ?array
{
    foreach ($routes as $pattern => $handlers) {
        if (!is_string($pattern) || !is_array($handlers) || preg_match('@^' . $pattern . '$@i', $route) !== 1) {
            continue;
        }
        foreach ($handlers as $handler) {
            if (is_array($handler) && is_array($handler['methods'] ?? null) && ($handler['methods'][$method] ?? false) === true) {
                return $handler;
            }
        }
    }

    return null;
}

/**
 * True when a handler leaves access to the browser: no permission callback, or one that lets
 * everybody in. Such a write route is guarded by a nonce, if by anything.
 *
 * @param array<string, mixed> $handler
 */
function wppilot_rest_bridge_endpoint_is_open(array $handler): bool
{
    $callback = $handler['permission_callback'] ?? null;

    return $callback === null || $callback === '__return_true';
}

/**
 * Replace the value of every credential-like key, at any depth, without trimming anything else.
 */
function wppilot_rest_bridge_redact(mixed $value, int $depth = 0): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if ($depth > 32) {
        return '[depth-limited]';
    }
    $result = [];
    foreach ($value as $key => $item) {
        $result[$key] = is_string($key) && wppilot_change_key_is_sensitive($key) ? '[redacted]' : wppilot_rest_bridge_redact($item, $depth + 1);
    }

    return $result;
}

/**
 * Turn a dispatched response into the ability's result, or refuse it.
 *
 * @param array<string, mixed> $headers
 * @return array<string, mixed>|WP_Error
 */
function wppilot_rest_bridge_shape(int $status, array $headers, mixed $data, string $echoed): array|WP_Error
{
    $content_type = '';
    foreach ($headers as $name => $header) {
        if (strtolower((string) $name) === 'content-type' && is_scalar($header)) {
            $content_type = strtolower((string) $header);
        }
    }
    if (trim($echoed) !== '' || ($content_type !== '' && !str_contains($content_type, 'json'))) {
        return new WP_Error(
            'wppilot_rest_not_json',
            __('The route answered with something other than JSON, so the REST bridge discarded it.', domain: 'wppilot'),
            ['status' => 415, 'response_status' => $status],
        );
    }
    $raw = wp_json_encode($data);
    if (!is_string($raw)) {
        return new WP_Error('wppilot_rest_not_json', __('The route answered with data that cannot be encoded as JSON.', domain: 'wppilot'), ['status' => 415, 'response_status' => $status]);
    }
    // A round trip through JSON turns objects into arrays, so redaction reaches every key.
    $data = wppilot_rest_bridge_redact(json_decode($raw, true));
    $json = (string) wp_json_encode($data);
    $result = ['status' => $status, 'ok' => $status < 400, 'bytes' => strlen($json), 'truncated' => false, 'data' => $data];
    if (strlen($json) > WPPILOT_REST_BRIDGE_MAX_BYTES) {
        $result['truncated'] = true;
        $result['data'] = null;
        $result['note'] = __('The response is larger than 256 KB and was not returned. Ask for less: per_page, page, _fields or a narrower route.', domain: 'wppilot');
    }
    foreach (['X-WP-Total' => 'total', 'X-WP-TotalPages' => 'total_pages'] as $header => $key) {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, $header) === 0 && is_numeric($value)) {
                $result[$key] = (int) $value;
            }
        }
    }

    return $result;
}

/**
 * Run one request through WordPress's REST server, as the current user.
 *
 * @param array<array-key, mixed> $query
 * @param array<array-key, mixed> $body
 * @return array{status: int, headers: array<string, mixed>, data: mixed, echoed: string}
 */
function wppilot_rest_bridge_dispatch(string $method, string $route, array $query, array $body): array
{
    $request = new WP_REST_Request($method, $route);
    $request->set_query_params($query);
    if ($method !== 'GET' && $body !== []) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    ob_start();
    try {
        $response = rest_do_request($request);
    } finally {
        $echoed = (string) ob_get_clean();
    }
    $data = rest_get_server()->response_to_data($response, false);

    return ['status' => (int) $response->get_status(), 'headers' => $response->get_headers(), 'data' => $data, 'echoed' => $echoed];
}

/**
 * Confirm a write: already confirmed by the gate, confirm: true in argument mode, or a wp-admin
 * approval of this exact call when the site requires a person to approve.
 *
 * @param array<string, mixed> $input
 */
function wppilot_rest_bridge_confirm(array $input): bool|WP_Error
{
    $note = function_exists('wppilot_confirmation_note') ? wppilot_confirmation_note(WPPILOT_REST_BRIDGE_ABILITY) : null;
    if (is_array($note) && ($note['method'] ?? 'not-required') !== 'not-required') {
        return true;
    }
    if (function_exists('wppilot_confirmation_mode') && wppilot_confirmation_mode() === 'human') {
        $user_id = get_current_user_id();
        $ability = function_exists('wp_get_ability') ? wp_get_ability(WPPILOT_REST_BRIDGE_ABILITY) : null;
        if ($user_id <= 0 || !$ability instanceof WP_Ability) {
            return new WP_Error('wppilot_human_confirmation_unavailable', __('This site requires a person to approve writes, and this call cannot be tied to a signed-in user.', domain: 'wppilot'), ['status' => 403]);
        }
        $hash = wppilot_confirmation_input_hash($input);
        if (wppilot_confirmation_claim_approval(WPPILOT_REST_BRIDGE_ABILITY, $hash, $user_id)) {
            wppilot_confirmation_note(WPPILOT_REST_BRIDGE_ABILITY, 'approval-url');
            return true;
        }
        if (wppilot_confirmation_find_request(WPPILOT_REST_BRIDGE_ABILITY, $hash, $user_id, 'denied') !== null) {
            return new WP_Error('wppilot_confirmation_denied', __('A person denied this exact call in wp-admin. Do not retry it; ask the user what they want instead.', domain: 'wppilot'), ['status' => 403]);
        }

        return wppilot_confirmation_approval_url_error($ability, $input, 'mcp', $hash, $user_id);
    }
    if (($input['confirm'] ?? null) !== true) {
        return new WP_Error(
            'wppilot_confirmation_required',
            __('A write through the REST bridge changes the site and cannot be undone from GoBuildWP. Show the user the method, route and body, get their approval, then retry with confirm: true.', domain: 'wppilot'),
            ['status' => 400, 'ability' => WPPILOT_REST_BRIDGE_ABILITY],
        );
    }
    if (function_exists('wppilot_confirmation_note')) {
        wppilot_confirmation_note(WPPILOT_REST_BRIDGE_ABILITY, 'argument');
    }

    return true;
}

/**
 * The ledger row for one write that reached the route: what was sent, by hash, and what came back.
 *
 * @param array<array-key, mixed> $body
 * @return array<string, mixed>
 */
function wppilot_rest_bridge_ledger_row(string $method, string $route, string $namespace, array $body, int $status, float $started): array
{
    $user = wp_get_current_user();
    $body_json = (string) wp_json_encode($body);
    $note = function_exists('wppilot_confirmation_note') ? wppilot_confirmation_note(WPPILOT_REST_BRIDGE_ABILITY, clear: true) : null;

    return [
        'kind' => 'change',
        'id' => wp_generate_uuid4(),
        'ability' => WPPILOT_REST_BRIDGE_ABILITY,
        'risk' => 'write',
        'recorded_at' => gmdate('c'),
        'duration_ms' => round((microtime(true) - $started) * 1000, 2),
        'user' => ['id' => (int) $user->ID, 'login' => (string) $user->user_login],
        'agent' => function_exists('wppilot_current_agent') ? wppilot_current_agent() : [],
        'input' => [
            'method' => $method,
            'route' => $route,
            'namespace' => $namespace,
            'body_keys' => array_slice(array_map('strval', array_keys($body)), offset: 0, length: 50),
            'body_sha256' => hash('sha256', $body_json),
        ],
        'input_sha256' => hash('sha256', $method . ' ' . $route . "\n" . $body_json),
        'result' => ['status' => $status, 'ok' => $status < 400],
        'rollback' => [
            'reversible' => false,
            'reason' => __('A write made through the REST bridge cannot be undone from GoBuildWP; change it back through the same route or in wp-admin.', domain: 'wppilot'),
        ],
        'rolled_back' => false,
        'design' => [],
        'confirmation' => is_array($note) ? $note : [],
    ];
}

/**
 * Execute wppilot/rest-request.
 *
 * The seams ($dispatch, $namespaces, $routes) are for tests; WordPress supplies them otherwise.
 *
 * @param array<string, mixed> $input
 * @param null|callable(string, string, array, array): array{status: int, headers: array<string, mixed>, data: mixed, echoed: string} $dispatch
 * @param list<string>|null $namespaces
 * @param array<string, mixed>|null $routes
 * @return array<string, mixed>|WP_Error
 */
function wppilot_rest_bridge_execute(array $input, ?callable $dispatch = null, ?array $namespaces = null, ?array $routes = null): array|WP_Error
{
    $started = microtime(true);
    // This ability writes its own row for a write and none for a read, so the generic row the
    // ledger opened before execution is dropped: it would record every GET as a change and keep
    // the whole body.
    if (function_exists('wppilot_change_pending')) {
        wppilot_change_pending(WPPILOT_REST_BRIDGE_ABILITY, value: null, clear: true);
    }

    $method = strtoupper(is_string($input['method'] ?? null) ? $input['method'] : 'GET');
    $route = wppilot_rest_bridge_normalize_route(is_string($input['route'] ?? null) ? $input['route'] : '');
    if ($route instanceof WP_Error) {
        return $route;
    }
    $query = is_array($input['query'] ?? null) ? $input['query'] : [];
    $body = is_array($input['body'] ?? null) ? $input['body'] : [];

    if ($namespaces === null) {
        $namespaces = array_values(array_map('strval', rest_get_server()->get_namespaces()));
    }
    $checked = wppilot_rest_bridge_check($method, $route, $namespaces, wppilot_rest_bridge_settings());
    if ($checked instanceof WP_Error) {
        $data = $checked->get_error_data();
        if (is_array($data) && is_string($data['namespace'] ?? null)) {
            wppilot_rest_bridge_count($data['namespace'], 'refused');
        }
        return $checked;
    }
    $namespace = $checked['namespace'];
    $refuse = static function (WP_Error $error) use ($namespace): WP_Error {
        wppilot_rest_bridge_count($namespace, 'refused');
        return $error;
    };

    // Read Mode lets this ability through for GET only. The permission gate already refuses a write
    // there; this holds on WordPress versions without that gate.
    if ($checked['write'] && function_exists('wppilot_read_mode_enabled') && wppilot_read_mode_enabled()) {
        return $refuse(wppilot_read_mode_ability_error(WPPILOT_REST_BRIDGE_ABILITY));
    }

    $reserved = wppilot_rest_bridge_check_params($query, $body);
    if ($reserved !== null) {
        return $refuse($reserved);
    }
    if (!$checked['write'] && $body !== []) {
        return $refuse(new WP_Error('wppilot_rest_body_on_read', __('A GET request takes no body; put its parameters in query.', domain: 'wppilot'), ['status' => 400]));
    }

    if ($checked['write']) {
        if ($routes === null) {
            $routes = rest_get_server()->get_routes();
        }
        $endpoint = wppilot_rest_bridge_endpoint_for($routes, $route, $method);
        if ($endpoint === null) {
            return $refuse(new WP_Error('wppilot_rest_no_route', sprintf(
                /* translators: 1: HTTP method, 2: route */
                __('No REST route on this site answers %1$s %2$s.', domain: 'wppilot'),
                $method,
                $route,
            ), ['status' => 404]));
        }
        if (wppilot_rest_bridge_endpoint_is_open($endpoint)) {
            return $refuse(new WP_Error(
                'wppilot_rest_route_unguarded',
                __('This route has no permission check of its own; in a browser it relies on a nonce or lets anyone in. The REST bridge never sends a nonce, so it does not write to it.', domain: 'wppilot'),
                ['status' => 403],
            ));
        }
        $confirmed = wppilot_rest_bridge_confirm($input);
        if ($confirmed instanceof WP_Error) {
            return $refuse($confirmed);
        }
    }

    $response = $dispatch !== null ? $dispatch($method, $route, $query, $body) : wppilot_rest_bridge_dispatch($method, $route, $query, $body);
    $status = (int) ($response['status'] ?? 500);
    wppilot_rest_bridge_count($namespace, $checked['write'] ? 'writes' : 'reads');
    if ($checked['write']) {
        // Recorded whatever came back: the route may have changed something before it failed.
        wppilot_store_change(wppilot_rest_bridge_ledger_row($method, $route, $namespace, $body, $status, $started));
    }

    $shaped = wppilot_rest_bridge_shape(
        $status,
        is_array($response['headers'] ?? null) ? $response['headers'] : [],
        $response['data'] ?? null,
        is_string($response['echoed'] ?? null) ? $response['echoed'] : '',
    );
    if ($shaped instanceof WP_Error) {
        return $shaped;
    }

    return ['method' => $method, 'route' => $route, 'namespace' => $namespace] + $shaped;
}

/**
 * Save the switches from the Abilities screen and record the change. False when nothing changed.
 *
 * Namespaces that are not registered right now (their plugin is inactive) keep their switches.
 *
 * @param list<string> $read Namespaces ticked for Read.
 * @param list<string> $write Namespaces ticked for Write.
 * @param list<string> $namespaces Namespaces the form offered.
 */
function wppilot_rest_bridge_save_settings(array $read, array $write, array $namespaces): bool
{
    $before = wppilot_rest_bridge_settings();
    $after = $before;
    foreach ($namespaces as $namespace) {
        unset($after[$namespace]);
        if (wppilot_rest_bridge_closed_namespace($namespace) !== null) {
            continue;
        }
        $on_read = in_array($namespace, $read, strict: true);
        $on_write = in_array($namespace, $write, strict: true);
        if ($on_read || $on_write) {
            $after[$namespace] = ['read' => $on_read, 'write' => $on_write];
        }
    }
    ksort($after);
    if ($after === $before) {
        return false;
    }
    update_option(WPPILOT_REST_BRIDGE_OPTION, $after, false);

    $changes = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $namespace) {
        foreach (['read', 'write'] as $switch) {
            $was = $before[$namespace][$switch] ?? false;
            $now = $after[$namespace][$switch] ?? false;
            if ($was !== $now) {
                $changes[] = ['namespace' => $namespace, 'switch' => $switch, 'on' => $now];
            }
        }
    }
    $user = wp_get_current_user();
    $input = ['before' => $before, 'after' => $after];
    wppilot_store_change([
        'kind' => 'change',
        'id' => wp_generate_uuid4(),
        'ability' => WPPILOT_REST_BRIDGE_LEDGER_NAME,
        'risk' => 'critical',
        'recorded_at' => gmdate('c'),
        'duration_ms' => 0,
        'user' => ['id' => (int) $user->ID, 'login' => (string) $user->user_login],
        'agent' => [],
        'input' => $input,
        'input_sha256' => hash('sha256', (string) wp_json_encode($input)),
        'result' => ['changes' => $changes],
        'rollback' => [
            'reversible' => false,
            'reason' => __('REST bridge switches are changed back on the Abilities screen; this row keeps both states.', domain: 'wppilot'),
        ],
        'rolled_back' => false,
        'design' => [],
        'confirmation' => ['method' => 'admin-form'],
    ]);

    return true;
}

/**
 * Namespaces registered on this site, sorted.
 *
 * @return list<string>
 */
function wppilot_rest_bridge_registered_namespaces(): array
{
    $namespaces = array_values(array_map('strval', rest_get_server()->get_namespaces()));
    sort($namespaces);

    return $namespaces;
}

/**
 * Handle the REST bridge form. Called from wppilot_handle_ability_hub_actions() after its
 * capability and nonce checks.
 */
function wppilot_handle_rest_bridge_action(): void
{
    $base = admin_url('admin.php?page=wppilot-abilities');
    $namespaces = wppilot_rest_bridge_registered_namespaces();
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- Only called from wppilot_handle_ability_hub_actions() after check_admin_referer('wppilot_ability_hub_action').
    $picked = static function (string $field) use ($namespaces): array {
        $raw = $_POST[$field] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $values = array_map(static fn($value): string => is_string($value) ? sanitize_text_field(wp_unslash($value)) : '', $raw);

        return array_values(array_intersect($namespaces, $values));
    };
    $read = $picked('wppilot_rest_bridge_read');
    $write = $picked('wppilot_rest_bridge_write');
    $confirmed = ($_POST['wppilot_rest_bridge_confirm'] ?? '') === '1';
    // phpcs:enable WordPress.Security.NonceVerification.Missing

    $current = wppilot_rest_bridge_settings();
    foreach ($write as $namespace) {
        if (($current[$namespace]['write'] ?? false) !== true && !$confirmed) {
            wp_safe_redirect(add_query_arg('wppilot_result', 'rest_unconfirmed', $base) . '#wppilot-rest-bridge');
            exit();
        }
    }
    $changed = wppilot_rest_bridge_save_settings($read, $write, $namespaces);
    wp_safe_redirect(add_query_arg('wppilot_result', $changed ? 'rest_saved' : 'rest_unchanged', $base) . '#wppilot-rest-bridge');
    exit();
}

function wppilot_render_rest_bridge_panel(): void
{
    if (!wppilot_current_user_can_manage()) {
        return;
    }
    $settings = wppilot_rest_bridge_settings();
    $stats = wppilot_rest_bridge_stats();
    ?>
    <div class="wppilot-card" id="wppilot-rest-bridge">
        <h2><?php esc_html_e('REST bridge', domain: 'wppilot'); ?></h2>
        <p><?php esc_html_e(
            'Which parts of this site\'s REST API the REST Request ability may call, as the connected user. Every namespace starts off. Read allows GET; Write allows POST, PUT, PATCH and DELETE, each confirmed and recorded on the Changes screen, and none can be undone from GoBuildWP. Users, settings, application passwords and plugin installs never take writes, and routes with no permission check of their own are never written to. Every save is recorded on the Changes screen.',
            domain: 'wppilot',
        ); ?></p>
        <form method="post">
            <?php wp_nonce_field('wppilot_ability_hub_action'); ?>
            <input type="hidden" name="wppilot_ability_hub_action" value="rest_bridge" />
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Namespace', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Read', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Write', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Reads', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Writes', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Refused', domain: 'wppilot'); ?></th>
                        <th scope="col"><?php esc_html_e('Last call (UTC)', domain: 'wppilot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (wppilot_rest_bridge_registered_namespaces() as $namespace): ?>
                    <?php $closed = wppilot_rest_bridge_closed_namespace($namespace); ?>
                    <?php $row = $stats[$namespace] ?? ['reads' => 0, 'writes' => 0, 'refused' => 0, 'last_at' => '']; ?>
                    <tr>
                        <td><code><?php echo esc_html($namespace); ?></code><?php if ($closed !== null): ?><br /><span class="description"><?php echo esc_html($closed); ?></span><?php endif; ?></td>
                        <td><input type="checkbox" name="wppilot_rest_bridge_read[]" value="<?php echo esc_attr($namespace); ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: REST namespace */ __('Read %s', domain: 'wppilot'), $namespace)); ?>" <?php checked(($settings[$namespace]['read'] ?? false) === true); ?> <?php disabled($closed !== null); ?> /></td>
                        <td><input type="checkbox" name="wppilot_rest_bridge_write[]" value="<?php echo esc_attr($namespace); ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: REST namespace */ __('Write %s', domain: 'wppilot'), $namespace)); ?>" <?php checked(($settings[$namespace]['write'] ?? false) === true); ?> <?php disabled($closed !== null); ?> /></td>
                        <td><?php echo esc_html((string) $row['reads']); ?></td>
                        <td><?php echo esc_html((string) $row['writes']); ?></td>
                        <td><?php echo esc_html((string) $row['refused']); ?></td>
                        <td><?php echo esc_html($row['last_at'] !== '' ? $row['last_at'] : '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <label>
                    <input type="checkbox" name="wppilot_rest_bridge_confirm" value="1" />
                    <?php esc_html_e('I understand that turning on Write lets agents change data through every route in that namespace that the connected user may use.', domain: 'wppilot'); ?>
                </label>
            </p>
            <p><button type="submit" class="button button-primary"><?php esc_html_e('Save REST bridge', domain: 'wppilot'); ?></button></p>
        </form>
    </div>
    <?php
}
