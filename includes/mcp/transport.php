<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp;

use WP_Ability;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Modern-era request dispatcher.
 *
 * Hooks `rest_pre_dispatch` on the MCP endpoints. A request carrying modern
 * per-request `_meta` is answered here and never reaches the bundled adapter;
 * anything else is returned untouched so the adapter serves it under the legacy
 * revision exactly as before. That split is the whole compatibility strategy:
 * the adapter is a third-party package pinned in composer.json, so patching it
 * would be undone by the next `composer update`.
 *
 * Everything security-relevant is reused rather than reimplemented. Safety
 * profiles, rate limits, capability checks, and the change ledger all run
 * through the same functions the legacy path uses, so a modern client cannot
 * reach a weaker code path than a legacy one.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Priority of the dispatch hook.
 *
 * After the host guard (5) so a spoofed Host is rejected first, and after the
 * troubleshooting recorder (20) so a modern request still shows up on the
 * Diagnostics screen even though it short-circuits the adapter.
 */
const DISPATCH_PRIORITY = 25;

/**
 * Whether a REST route is one of the MCP endpoints.
 *
 * `/mcp/wppilot-oauth` is the OAuth mirror of the same server, and its protected-resource
 * metadata advertises the modern revision. Leaving it out sent every modern OAuth client to the
 * adapter, which answered "Missing Mcp-Session-Id". caller_may_use_mcp() applies the OAuth route
 * and scope boundary before anything is answered there.
 */
function is_mcp_route(string $route): bool
{
    foreach (['/mcp/wppilot', '/mcp/wppilot-oauth', '/mcp/mcp-adapter-default-server'] as $base) {
        if ($route === $base || str_starts_with($route, $base . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * Whether this site actually serves an MCP route right now.
 *
 * The OAuth server is registered only where the OAuth transport is allowed (HTTPS, or a local
 * site). rest_pre_dispatch runs before route matching, so without this a modern request would be
 * answered on an endpoint that legacy clients, correctly, get a 404 from.
 */
function route_is_served(string $route): bool
{
    if ($route === '/mcp/wppilot-oauth' || str_starts_with($route, '/mcp/wppilot-oauth/')) {
        return function_exists('wppilot_oauth_transport_allowed') && \wppilot_oauth_transport_allowed();
    }
    // The adapter's default server is GoBuildWP's only when GoBuildWP's adapter copy is the one
    // loaded. When another plugin's copy (Elementor 4.3+) is, that route is its server: answering
    // there would hijack its clients with GoBuildWP's tools.
    if ($route === '/mcp/mcp-adapter-default-server' || str_starts_with($route, '/mcp/mcp-adapter-default-server/')) {
        return function_exists('wppilot_owns_mcp_adapter') && \wppilot_owns_mcp_adapter();
    }

    return true;
}

/**
 * Answer a modern request, or hand the request back for legacy handling.
 *
 * @return mixed A WP_REST_Response for modern traffic, or the untouched $result.
 */
function pre_dispatch(mixed $result, WP_REST_Server $server, WP_REST_Request $request): mixed
{
    if ($result !== null || !is_mcp_route($request->get_route()) || !route_is_served($request->get_route())) {
        return $result;
    }

    // GET and DELETE are deliberately NOT answered with 405 here. The modern
    // revision drops the GET stream and the session lifecycle, but GoBuildWP is
    // dual-era: legacy clients still open the adapter's GET stream, and
    // refusing it would break every connection already in the field.
    if ($request->get_method() !== 'POST') {
        return $result;
    }

    $body = $request->get_json_params();
    if (!is_array($body) || !is_modern_request($body)) {
        return $result;
    }

    // rest_pre_dispatch runs before route matching, so the route's permission
    // callback and the OAuth route/scope checks have not run yet. Answer the
    // modern protocol only for a caller who would pass them; anyone else falls
    // through to normal routing, which denies with the same 401 challenge an
    // OAuth client needs to discover how to sign in.
    if (!caller_may_use_mcp($request)) {
        return $result;
    }

    return respond(handle_modern($request, $body));
}

/**
 * Whether the current caller passes the checks the legacy route applies:
 * the AI abilities master switch, GoBuildWP's manage capability, and - for an
 * OAuth credential - the route and scope boundary.
 */
function caller_may_use_mcp(WP_REST_Request $request): bool
{
    if (!function_exists('wppilot_permission_callback') || !\wppilot_permission_callback()) {
        return false;
    }

    if (
        function_exists('WPPilot\OAuth\Middleware\request_oauth_identity')
        && \WPPilot\OAuth\Middleware\request_oauth_identity() !== null
        && function_exists('WPPilot\OAuth\Middleware\authorize_routed_request')
    ) {
        $verdict = \WPPilot\OAuth\Middleware\authorize_routed_request(null, null, $request);
        if ($verdict instanceof WP_Error) {
            return false;
        }
    }

    return true;
}

/**
 * Turn a dispatcher outcome into a REST response.
 *
 * `Mcp-Session-Id` is neither read nor minted on this path. The modern
 * transport says to ignore a session header rather than error on it, so a
 * half-migrated client sending one is served normally and simply never
 * receives a session back.
 *
 * @param array{status: int, body: array<string, mixed>} $outcome
 */
function respond(array $outcome): WP_REST_Response
{
    $response = new WP_REST_Response($outcome['body'], $outcome['status']);
    $response->header('Content-Type', 'application/json; charset=utf-8');
    $response->header('MCP-Protocol-Version', VERSION_MODERN);

    return $response;
}

/**
 * Validate and route one modern request.
 *
 * @param array<string, mixed> $body
 * @return array{status: int, body: array<string, mixed>}
 */
function handle_modern(WP_REST_Request $request, array $body): array
{
    $id = $body['id'] ?? null;
    $method = is_string($body['method'] ?? null) ? $body['method'] : '';

    $header_error = validate_modern_headers($request->get_headers(), $body);
    if ($header_error !== null) {
        return $header_error;
    }

    // A method the revision removed must not quietly work, or a client would
    // believe it had set a log level or kept a session alive when it had not.
    if (is_removed_in_modern($method)) {
        return removed_method_error($method, $id);
    }

    // The revision requires both _meta fields on every request. An empty
    // capabilities object is a valid declaration; an absent key is not, and the
    // server must not have to guess what the client can handle.
    if (!declares_client_capabilities($body)) {
        return error_response(
            ERROR_INVALID_PARAMS,
            sprintf('params._meta.%s is required on every request.', META_CLIENT_CAPABILITIES),
            400,
            $id,
        );
    }

    $params = is_array($body['params'] ?? null) ? $body['params'] : [];

    // Tasks and MCP Apps; see extensions.php.
    $extension = function_exists('WPPilot\Mcp\dispatch_extensions') ? dispatch_extensions($method, $params, $id) : null;
    if ($extension !== null) {
        return $extension;
    }

    // Resources and the Skills extension (SEP-2640) are served by their own module.
    $skills = function_exists('WPPilot\Mcp\SkillResources\dispatch')
        ? SkillResources\dispatch($method, $params, $id)
        : null;
    if ($skills !== null) {
        return $skills;
    }

    return match ($method) {
        'server/discover' => success(build_discover_result(
            runtime_capabilities(),
            function_exists('wppilot_get_safety_profile') ? \wppilot_get_safety_profile() : 'unknown',
        ), $id, $method),
        'tools/list' => success(['tools' => list_tools()], $id, $method),
        'tools/call' => call_tool($params, $id),
        'prompts/list' => success(['prompts' => list_prompts()], $id, $method),
        'prompts/get' => get_prompt($params, $id),
        default => method_not_found_error($method, $id),
    };
}

/**
 * Wrap a result in a decorated, successful JSON-RPC response.
 *
 * @param array<string, mixed> $result
 * @return array{status: int, body: array<string, mixed>}
 */
function success(array $result, mixed $id, string $method): array
{
    return [
        'status' => 200,
        'body' => modern_response($result, $id, $method, server_name(), server_version()),
    ];
}

function server_name(): string
{
    return 'GoBuildWP';
}

function server_version(): string
{
    return defined('WPPILOT_VERSION') ? (string) constant('WPPILOT_VERSION') : '0.0.0';
}

/**
 * Build the tool list from the abilities this connection may actually reach.
 *
 * Filtered by the ability's own permission callback and by the active safety
 * profile, so the list never advertises a tool the very next call would refuse.
 * Returned in a deterministic order so clients can cache it.
 *
 * @return list<array<string, mixed>>
 */
function list_tools(): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $tools = [];

    /** @var mixed $ability */
    foreach (wp_get_abilities() as $ability) {
        if (!$ability instanceof WP_Ability) {
            continue;
        }

        $meta = \wppilot_ability_meta($ability);
        if (!\wppilot_ability_is_exposed($meta)) {
            continue;
        }

        // Prompt-type abilities belong to prompts/list. Listing one as a tool
        // would let a client call it through tools/call and receive prompt
        // messages where it expected a tool result.
        if (\wppilot_ability_mcp_type($meta) === 'prompt') {
            continue;
        }

        if (function_exists('wppilot_safety_profile_allows_ability') && !\wppilot_safety_profile_allows_ability(
            $ability,
        )) {
            continue;
        }

        $tool = client_ready_tool([
            'name' => tool_name($ability->get_name()),
            'title' => (string) $ability->get_label(),
            'description' => (string) $ability->get_description(),
            'inputSchema' => normalize_schema(advertise_confirmation($ability, $ability->get_input_schema())),
            'outputSchema' => normalize_schema($ability->get_output_schema()),
        ]);
        if ($tool === null) {
            continue;
        }
        $annotations = tool_safety_annotations($meta);
        if ($annotations !== []) {
            $tool['annotations'] = $annotations;
        }
        $tools[] = function_exists('WPPilot\Mcp\decorate_tool') ? decorate_tool($tool, $meta) : $tool;
    }

    return sort_tools_deterministically($tools);
}

/**
 * Preserve ability safety hints for modern clients, as the legacy adapter does.
 * Unknown or malformed classifications remain absent and therefore fail closed.
 * @param array<string, mixed> $meta
 * @return array<string, bool>
 */
function tool_safety_annotations(array $meta): array
{
    $source = $meta['annotations'] ?? null;
    if (!is_array($source)) {
        return [];
    }
    $result = [];
    foreach (['readonly' => 'readOnlyHint', 'destructive' => 'destructiveHint', 'idempotent' => 'idempotentHint', 'openWorldHint' => 'openWorldHint'] as $key => $hint) {
        if (isset($source[$key]) && is_bool($source[$key])) {
            $result[$hint] = $source[$key];
        }
    }
    return $result;
}

/**
 * Return the prompt-type abilities this connection may reach.
 *
 * GoBuildWP's prompts are its skills. They take no arguments, so `arguments` is
 * always an empty list rather than omitted — a client that treats a missing
 * key as "unknown" would otherwise prompt the user for input that does not
 * exist.
 *
 * @return list<array<string, mixed>>
 */
function list_prompts(): array
{
    $prompts = [];

    foreach (prompt_abilities() as $ability) {
        $prompts[] = [
            'name' => tool_name($ability->get_name()),
            'title' => (string) $ability->get_label(),
            'description' => (string) $ability->get_description(),
            'arguments' => [],
        ];
    }

    return sort_tools_deterministically($prompts);
}

/**
 * Every registered prompt-type ability that passes discovery and safety.
 *
 * @return list<WP_Ability>
 */
function prompt_abilities(): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $prompts = [];

    /** @var mixed $ability */
    foreach (wp_get_abilities() as $ability) {
        if (!$ability instanceof WP_Ability) {
            continue;
        }
        $meta = \wppilot_ability_meta($ability);

        if (!\wppilot_ability_is_exposed($meta) || \wppilot_ability_mcp_type($meta) !== 'prompt') {
            continue;
        }
        if (function_exists('wppilot_safety_profile_allows_ability') && !\wppilot_safety_profile_allows_ability(
            $ability,
        )) {
            continue;
        }

        $prompts[] = $ability;
    }

    return $prompts;
}

/**
 * Render one prompt.
 *
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}
 */
function get_prompt(array $params, mixed $id): array
{
    $name = is_string($params['name'] ?? null) ? $params['name'] : '';

    foreach (prompt_abilities() as $ability) {
        if (tool_name($ability->get_name()) !== $name) {
            continue;
        }

        // execute() runs the permission check itself and returns a WP_Error
        // when the caller may not read this prompt.
        //
        // null rather than [] when the prompt declares no input schema: core
        // refuses any non-null input in that case, so passing an empty array
        // would fail the read rather than perform it. Every prompt ability
        // currently declares one, which is why this has not bitten yet.
        $result = $ability->execute($ability->get_input_schema() === [] ? null : []);
        if ($result instanceof WP_Error) {
            return error_response(ERROR_INVALID_PARAMS, $result->get_error_message(), 200, $id);
        }

        $messages = is_array($result) && is_array($result['messages'] ?? null) ? $result['messages'] : [];

        return success([
            'description' => (string) $ability->get_description(),
            'messages' => $messages,
        ], $id, 'prompts/get');
    }

    // Modern moved not-found onto -32602.
    return error_response(ERROR_INVALID_PARAMS, sprintf('Unknown prompt: %s', $name), 200, $id);
}

/**
 * Map an ability name to an MCP tool name.
 *
 * Ability names are namespaced with a slash, which is not in the character set
 * MCP tool names are constrained to, so the separator becomes an underscore —
 * the same transformation the bundled adapter applies, keeping tool names
 * identical across the two eras.
 */
function tool_name(string $ability_name): string
{
    return str_replace(['/', '-'], '_', $ability_name);
}

/**
 * Resolve an MCP tool name back to its ability.
 */
function ability_for_tool(string $tool): ?WP_Ability
{
    if (!function_exists('wp_get_abilities')) {
        return null;
    }

    /** @var mixed $ability */
    foreach (wp_get_abilities() as $ability) {
        if ($ability instanceof WP_Ability && tool_name($ability->get_name()) === $tool) {
            return $ability;
        }
    }

    return null;
}

/**
 * Coerce an ability schema into something JSON Schema 2020-12 clients accept.
 *
 * The revision loosened `inputSchema` to allow any 2020-12 keyword, so the
 * schema is passed through as authored. Only the outer shape is guaranteed,
 * because a tool whose schema is not an object breaks client-side validation.
 *
 * GoBuildWP's own abilities author client-ready schemas, but the surface is not
 * only GoBuildWP's: every ability on the site is advertised, and a schema written
 * for WordPress rather than for a client carries things no JSON Schema
 * validator understands — a property-level `'required' => true` instead of the
 * parent's `required` array, and the PHP-only `sanitize_callback`,
 * `validate_callback` and `arg_options` keys. A client that validates arguments
 * against the advertised schema then either rejects the schema or drops the
 * field. WordPress 7.1 ships the canonical translation as
 * `wp_prepare_json_schema_for_client()`, so use it where it exists; older
 * installs keep the shape guarantee alone, which is what they had before.
 *
 * The preparation is on the advertised copy only. `WP_Ability::validate_input()`
 * still asks the ability's own schema, so nothing about what a call is allowed
 * to send changes here.
 *
 * @return array<string, mixed>
 */
function normalize_schema(mixed $schema): array
{
    if (!is_array($schema) || $schema === []) {
        return ['type' => 'object'];
    }

    if (function_exists('wp_prepare_json_schema_for_client')) {
        /** @var mixed $prepared */
        $prepared = \wp_prepare_json_schema_for_client($schema);
        if (is_array($prepared) && $prepared !== []) {
            $schema = $prepared;
        }
    }

    return force_schema_objects($schema);
}

/**
 * Give a tool the root schema shapes MCP requires, or drop it.
 *
 * MCP fixes the root of `inputSchema` (and of `outputSchema`, when present) at
 * `"type": "object"`, and strict clients validate the whole tools/list result
 * against that: one tool with another root fails the list, so every tool on the
 * connection disappears, not just the one at fault. Cloudflare MCP Portals
 * reports it as
 *
 *     Invalid result for tools/list: "code": "invalid_value", "values": ["object"]
 *
 * Abilities GoBuildWP does not own declare other roots. ACF's native abilities
 * declare `"type": "null"` for no input and `["object", "null"]` for optional
 * input. So the root is mapped, and nothing below it is touched:
 *
 * - no `type`, or `"object"`: object, as authored;
 * - a type list including `"object"`: object. MCP arguments are always an
 *   object, so the nullable half is never sent;
 * - `"null"`: an object that takes no properties. call_tool() turns the empty
 *   arguments back into null; see empty_input_for().
 *
 * Any other input root (a bare string, a list) has no object form a client
 * could send, so that tool alone is left out of the list, and the
 * `wppilot_mcp_tool_skipped` action names it. An `outputSchema` whose root is
 * not an object is removed instead of the tool: the field is optional, and an
 * advertised one obliges the result to match it.
 *
 * @param array<string, mixed> $tool
 * @return array<string, mixed>|null
 */
function client_ready_tool(array $tool): ?array
{
    $input = $tool['inputSchema'] ?? null;
    if (is_array($input)) {
        $input = object_root_schema($input);
        if ($input === null) {
            $name = is_string($tool['name'] ?? null) ? $tool['name'] : '';
            if (function_exists('do_action')) {
                \do_action('wppilot_mcp_tool_skipped', $name, 'input_schema_root');
            }
            return null;
        }
        $tool['inputSchema'] = force_schema_objects($input);
    }

    $output = $tool['outputSchema'] ?? null;
    if (is_array($output)) {
        $type = $output['type'] ?? 'object';
        if ($type === 'object') {
            $output['type'] = 'object';
            $tool['outputSchema'] = force_schema_objects($output);
        } else {
            unset($tool['outputSchema']);
        }
    }

    return $tool;
}

/**
 * Map an input schema's root onto `"type": "object"`; see client_ready_tool().
 *
 * @param array<string, mixed> $schema
 * @return array<string, mixed>|null Null when the root has no object form.
 */
function object_root_schema(array $schema): ?array
{
    $type = $schema['type'] ?? 'object';

    if ($type === 'null') {
        $empty = ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
        if (is_string($schema['description'] ?? null)) {
            $empty['description'] = $schema['description'];
        }
        return $empty;
    }

    if ($type === 'object' || (is_array($type) && in_array('object', $type, strict: true))) {
        $schema['type'] = 'object';
        return $schema;
    }

    return null;
}

/**
 * Whether an ability's input schema accepts only null at the root.
 *
 * Such an ability takes no input, and core validates null against it: an empty
 * array — what an MCP client's `{}` decodes to — is refused as "not of type
 * null". The REST shim and call_tool() ask this to pass null instead.
 */
function input_schema_is_null(WP_Ability $ability): bool
{
    $type = $ability->get_input_schema()['type'] ?? null;

    return $type === 'null' || $type === ['null'];
}

/**
 * Declare the `confirm` field on the tools that refuse to run without it.
 *
 * call_tool() rejects a destructive or critical ability unless the arguments
 * carry `confirm: true`, but `confirm` is a control field of this transport
 * rather than ability input, so it appears in no ability's schema. Abilities
 * also overwhelmingly declare `additionalProperties: false`, and a client that
 * validates arguments against the advertised schema — Claude among them — drops
 * an undeclared field rather than sending it. The confirmation then never
 * arrives, and the tool answers the same refusal however many times the model
 * retries, with no way to comply:
 *
 *     Ability "wppilot/elementor-build-page" is destructive or critical.
 *     Obtain explicit user approval, then retry with confirm=true.
 *
 * Advertising the field is what makes that instruction followable. It is added
 * to `required` as well, because a call omitting it cannot succeed.
 *
 * The injection is on the advertised copy only. Abilities declaring their own
 * `confirm` keep theirs untouched, and call_tool() still strips the field
 * before execute() for the rest, asking the ability's real schema rather than
 * this one.
 *
 * @param array<string, mixed> $schema
 * @return array<string, mixed>
 */
function advertise_confirmation(WP_Ability $ability, array $schema): array
{
    if (!function_exists('wppilot_ability_requires_confirmation')) {
        return $schema;
    }
    if (!\wppilot_ability_requires_confirmation($ability)) {
        return $schema;
    }
    if (is_array($schema['properties'] ?? null) && array_key_exists('confirm', $schema['properties'])) {
        return $schema;
    }

    $schema['type'] = 'object';
    $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
    $properties['confirm'] = [
        'type' => 'boolean',
        'description' => 'Must be true. This operation is destructive or critical, and GoBuildWP refuses it '
            . 'without an explicit confirmation that the user approved this specific call.',
    ];
    $schema['properties'] = $properties;

    $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
    if (!in_array('confirm', $required, strict: true)) {
        $required[] = 'confirm';
    }
    $schema['required'] = $required;

    return $schema;
}

/**
 * Replace empty schema maps with objects before the schema is encoded.
 *
 * `json_encode()` renders an empty PHP array as `[]`, so an ability declaring
 * `'properties' => []` — which every no-input ability does, through
 * WPPILOT_NO_INPUT_SCHEMA — reaches the client as a JSON array where JSON
 * Schema requires an object. Anthropic's API rejects the whole tools payload
 * for it, naming only the index:
 *
 *     tools.41.custom.input_schema.properties: Input should be an object
 *
 * One such ability therefore breaks every tool call on the connection, not its
 * own. The keyword maps are coerced recursively because a nested object
 * property can declare an empty `properties` of its own.
 *
 * The same trap is already handled for capabilities in discover.php; this is
 * the schema path it never reached.
 *
 * @param array<string, mixed> $schema
 * @return array<string, mixed>
 */
function force_schema_objects(array $schema): array
{
    foreach (['properties', 'patternProperties', 'definitions', '$defs'] as $keyword) {
        $map = $schema[$keyword] ?? null;
        if ($map === []) {
            $schema[$keyword] = new \stdClass();
            continue;
        }
        if (!is_array($map)) {
            continue;
        }
        foreach ($map as $name => $child) {
            if (is_array($child)) {
                $map[$name] = force_schema_objects($child);
            }
        }
        $schema[$keyword] = $map;
    }

    // `items` and `additionalProperties` hold a schema rather than a map of
    // them, so they are descended into but never replaced: an
    // `additionalProperties` of `false` is a meaningful value, not an empty map.
    foreach (['items', 'additionalProperties'] as $keyword) {
        if (is_array($schema[$keyword] ?? null)) {
            $schema[$keyword] = force_schema_objects($schema[$keyword]);
        }
    }

    return $schema;
}

/**
 * Execute a tool call under the modern revision.
 *
 * Every control runs in wppilot_gate_ability_call(), the same pipeline the
 * REST paths, Chat and Pro's approval replay use; then execute() runs the
 * ability's own permission callback. The change ledger needs no wiring here
 * because it hooks `wp_before_execute_ability` / `wp_after_execute_ability`
 * inside execute().
 *
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}
 */
function call_tool(array $params, mixed $id): array
{
    $name = is_string($params['name'] ?? null) ? $params['name'] : '';
    $ability = $name === '' ? null : ability_for_tool($name);

    if ($ability === null) {
        // The modern revision moved not-found onto -32602.
        return error_response(ERROR_INVALID_PARAMS, sprintf('Unknown tool: %s', $name), 200, $id);
    }

    $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

    // Safety profile, confirmation, confirm strip, then every wppilot_pre_ability_execute control:
    // rate limit, design and preview gates, and whatever a companion plugin adds. This transport
    // used to reproduce each of those by hand; see wppilot_gate_ability_call().
    /** @var mixed $gated */
    $gated = \wppilot_gate_ability_call($ability, $arguments, transport: 'mcp', context: confirmation_context($params));
    if ($gated instanceof WP_Error) {
        // A destructive call awaiting the user's approval goes back as input_required; see confirmation.php.
        $input_required = input_required_payload($gated);
        return $input_required !== null ? input_required_response($input_required, $id) : tool_error($gated, $id);
    }
    if (is_array($gated)) {
        $arguments = $gated;
    }

    // No separate permission call: WP_Ability::execute() normalizes the input,
    // validates it against the schema, runs check_permissions(), fires the
    // ledger's before/after hooks, and validates the output. Duplicating the
    // permission check here would run it twice and, worse, would drift from
    // core's contract the moment core changed it.
    // What a no-argument call must pass depends on the ability, so it cannot be
    // decided here without asking it. See empty_input_for().
    $result = $ability->execute($arguments === [] ? empty_input_for($ability) : $arguments);
    if ($result instanceof WP_Error) {
        return tool_error($result, $id);
    }

    return success(tool_result($result), $id, 'tools/call');
}

/**
 * The result key under which an ability hands the client content that is not text.
 *
 * An ability result is JSON, and an image inside JSON is a base64 string the model reads as
 * text: a 1024px preview costs a hundred thousand tokens and shows the model nothing. MCP has
 * an `image` content block for exactly this, but an ability cannot build MCP content itself:
 * the same result also goes out over REST and Chat, which have no content blocks. So an ability
 * that wants the model to see an image returns
 *
 *     '_mcp_content' => [['type' => 'image', 'data' => <base64>, 'mimeType' => 'image/jpeg']]
 *
 * beside its ordinary fields. The MCP transports lift those items out into content blocks and
 * leave the rest as the structured result; REST and Chat return the whole array as JSON, key and
 * all. Only images are recognised, in the four formats every vision model accepts; an item that
 * is not one is dropped rather than passed through as text.
 */
const RESULT_CONTENT_KEY = '_mcp_content';

/** @var list<string> */
const RESULT_IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

/**
 * A successful tools/call result: the JSON text block, the structured result, and any image
 * blocks the ability returned under RESULT_CONTENT_KEY.
 *
 * @return array{content: list<array<string, string>>, structuredContent: mixed, isError: false}
 */
function tool_result(mixed $result): array
{
    $images = [];
    if (is_array($result) && array_key_exists(RESULT_CONTENT_KEY, $result)) {
        $images = image_content($result[RESULT_CONTENT_KEY]);
        // Out of the text block and the structured result alike: the image is already in the
        // content, and a second base64 copy would be read as text at full token cost.
        unset($result[RESULT_CONTENT_KEY]);
    }

    return [
        'content' => array_merge([['type' => 'text', 'text' => encode_result($result)]], $images),
        'structuredContent' => $result,
        'isError' => false,
    ];
}

/**
 * The valid image items of a RESULT_CONTENT_KEY value, as MCP image content blocks.
 *
 * @return list<array{type: string, data: string, mimeType: string}>
 */
function image_content(mixed $items): array
{
    $blocks = [];
    foreach (is_array($items) ? $items : [] as $item) {
        if (
            !is_array($item)
            || ($item['type'] ?? null) !== 'image'
            || !is_string($item['data'] ?? null)
            || $item['data'] === ''
            || !in_array($item['mimeType'] ?? null, RESULT_IMAGE_MIME_TYPES, strict: true)
            // A client that cannot decode the block fails the whole result, not just the image.
            || base64_decode($item['data'], strict: true) === false
        ) {
            continue;
        }
        $blocks[] = ['type' => 'image', 'data' => $item['data'], 'mimeType' => (string) $item['mimeType']];
    }

    return $blocks;
}

/**
 * Hand the legacy adapter an image result it knows how to send.
 *
 * The bundled adapter builds an `image` block only from a result shaped
 * `['type' => 'image', 'results' => <raw bytes>, 'mimeType' => …]`, and then sends that block
 * alone. A legacy client reaches abilities through execute-ability, whose result wraps the
 * ability's as `['success' => true, 'data' => …]`, so without this the preview would arrive as a
 * base64 string inside JSON. The ability's other fields do not survive the adapter's image path;
 * abilities that use RESULT_CONTENT_KEY keep everything an agent needs elsewhere (the attachment
 * id it asked with, the alt text another ability lists), so the image is the part worth keeping.
 *
 * Filters mcp_adapter_tool_call_result. A copy of the adapter without that filter, or without
 * the image branch, returns the JSON unchanged, which is still correct, only expensive.
 *
 * @return mixed The result, or the adapter's image shape when it carried a valid image.
 */
function legacy_image_result(mixed $result): mixed
{
    if (!is_array($result)) {
        return $result;
    }
    $wrapped = ($result['success'] ?? null) === true && is_array($result['data'] ?? null);
    $payload = $wrapped ? $result['data'] : $result;
    if (!array_key_exists(RESULT_CONTENT_KEY, $payload)) {
        return $result;
    }
    $image = image_content($payload[RESULT_CONTENT_KEY])[0] ?? null;
    if ($image === null) {
        // Nothing a client could show; drop the key so it is not sent as text either.
        unset($payload[RESULT_CONTENT_KEY]);
        if (!$wrapped) {
            return $payload;
        }
        $result['data'] = $payload;
        return $result;
    }

    return [
        'type' => 'image',
        'results' => (string) base64_decode($image['data'], strict: true),
        'mimeType' => $image['mimeType'],
    ];
}

/**
 * The input a no-argument tool call must pass to a given ability.
 *
 * Two incompatible contracts meet here. An ability that declares no input
 * schema at all rejects any input, including `[]`, so it has to be called with
 * null. An ability that declares WPPILOT_NO_INPUT_SCHEMA — an object schema
 * with no properties, which is what every no-input GoBuildWP ability declares —
 * validates its input with rest_is_object(), and `rest_is_object(null)` is
 * false, so null comes back as:
 *
 *     Ability "wppilot/system-status" has invalid input.
 *     Reason: input is not of type object.
 *
 * Passing one answer for both therefore breaks half the no-argument tools
 * whichever answer is chosen. The REST shim already resolved this by asking the
 * ability; this reuses that decision so the two transports cannot drift, and
 * falls back to the same rule when the shim is not loaded — it is required only
 * on WordPress versions that support Abilities.
 */
function empty_input_for(WP_Ability $ability): mixed
{
    if (function_exists('wppilot_normalize_empty_ability_input')) {
        return \wppilot_normalize_empty_ability_input($ability, []);
    }

    return $ability->get_input_schema() === [] || input_schema_is_null($ability) ? null : [];
}

/**
 * Render an ability result as the text block MCP clients display.
 */
function encode_result(mixed $result): string
{
    if (is_string($result)) {
        return $result;
    }

    $encoded = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return is_string($encoded) ? $encoded : '';
}

/**
 * Report a failed tool call.
 *
 * Returned as a successful JSON-RPC result with `isError`, not a JSON-RPC
 * error: a tool that refuses is a normal outcome the model should read and act
 * on, whereas a JSON-RPC error means the call itself was malformed. Only the
 * error code and message cross the boundary, never the WP_Error data payload,
 * which can carry internal paths and query details.
 *
 * @return array{status: int, body: array<string, mixed>}
 */
function tool_error(WP_Error $error, mixed $id): array
{
    return success([
        'content' => [['type' => 'text', 'text' => $error->get_error_message()]],
        'structuredContent' => [
            'error' => $error->get_error_code(),
            'message' => $error->get_error_message(),
        ],
        'isError' => true,
    ], $id, 'tools/call');
}

/**
 * The legacy adapter's tool list with each tool made client-ready.
 *
 * Same rules as the modern path (client_ready_tool()): object roots, object
 * maps, and a tool whose input root has no object form left out rather than
 * failing the whole list.
 *
 * @param array<array-key, mixed> $tools
 * @return list<mixed>
 */
function repair_tool_list(array $tools): array
{
    $repaired = [];
    foreach ($tools as $tool) {
        if (!is_array($tool)) {
            $repaired[] = $tool;
            continue;
        }
        $tool = client_ready_tool($tool);
        if ($tool !== null) {
            $repaired[] = $tool;
        }
    }

    return $repaired;
}

/**
 * Repair the legacy adapter's tool schemas on the way out.
 *
 * A request without modern `_meta` is answered by the bundled adapter, which
 * passes each ability's schema through as authored — so the empty
 * `'properties' => []` that `normalize_schema()` fixes on the modern path, and
 * a root other than `"type": "object"` (client_ready_tool()), still reach a
 * legacy client as authored, and strict clients reject the whole payload. The
 * adapter is a pinned composer package, so the correction belongs in the
 * response rather than in its source, where the next `composer update` would
 * undo it.
 *
 * @return mixed The response, with tool schemas corrected where they applied.
 */
function repair_legacy_tool_schemas(mixed $response, WP_REST_Server $server, WP_REST_Request $request): mixed
{
    if (!$response instanceof WP_REST_Response || !is_mcp_route($request->get_route())) {
        return $response;
    }

    $body = $response->get_data();
    if (!is_array($body)) {
        return $response;
    }

    $result = $body['result'] ?? null;
    if (!is_array($result) || !is_array($result['tools'] ?? null)) {
        return $response;
    }

    $result['tools'] = repair_tool_list($result['tools']);
    $body['result'] = $result;
    $response->set_data($body);

    return $response;
}

/**
 * Register the dispatcher.
 */
function register_modern_transport(): void
{
    add_filter('rest_pre_dispatch', __NAMESPACE__ . '\\pre_dispatch', DISPATCH_PRIORITY, 3);
    // Priority 30 so the connection recorder at 20 still sees the response it expects.
    add_filter('rest_post_dispatch', __NAMESPACE__ . '\\repair_legacy_tool_schemas', 30, 3);
    // After GoBuildWP's other result filters (wppilot.php, at 10), which read the `success` /
    // `data` shape this replaces.
    add_filter('mcp_adapter_tool_call_result', __NAMESPACE__ . '\\legacy_image_result', 30, 1);
}
