<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp\Legacy;

use WP_Ability;
use WP_Error;

/**
 * Who GoBuildWP's servers on the bundled MCP Adapter admit, and what they publish.
 *
 * The modern transport (transport.php) is GoBuildWP's own code and applies GoBuildWP's rules
 * directly. The legacy transport is the adapter, a pinned composer package that other plugins
 * bundle too - Elementor 4.3 ships it - and whose defaults are looser than GoBuildWP's: any user
 * with `read` may open a session, and only `mcp.public` publishes an ability. Patching the package
 * would be undone by the next `composer update`, and a site-wide adapter filter would change
 * servers GoBuildWP does not own, so every decision here is scoped to GoBuildWP's own servers.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Ids of the adapter servers that are GoBuildWP's. Each is served at `/mcp/<id>`.
 *
 * The pre-rename alias slug is GoBuildWP's only when the loaded adapter copy is: otherwise it is
 * the other plugin's own default server.
 *
 * @return list<string>
 */
function own_server_ids(): array
{
    $ids = ['wppilot', 'wppilot-oauth'];
    if (function_exists('wppilot_owns_mcp_adapter') && \wppilot_owns_mcp_adapter()) {
        $ids[] = 'mcp-adapter-default-server';
    }

    return $ids;
}

/**
 * Whether a REST route belongs to one of GoBuildWP's servers. Exact slug or slash suffix, so
 * `/mcp/wppilot-oauth` is never read as a `/mcp/wppilot` prefix match.
 */
function is_own_route(string $route): bool
{
    foreach (own_server_ids() as $id) {
        $base = '/mcp/' . $id;
        if ($route === $base || str_starts_with($route, $base . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * Transport permission for GoBuildWP's servers: the rule the modern transport, the OAuth
 * middleware and the REST shim already apply - the AI abilities switch and GoBuildWP's manage
 * capability (wppilot_permission_callback()).
 *
 * Without it the adapter's default applied, and any logged-in user - a subscriber with an
 * Application Password - could open a session, list the tools, run discover-abilities and read
 * every skill resource, built-in and site-authored. Ability execution was still refused by each
 * ability's own check; this closes the transport itself.
 *
 * @param mixed $request The adapter passes the WP_REST_Request; the decision does not depend on it.
 */
function transport_permission(mixed $request = null): bool
{
    return function_exists('wppilot_permission_callback') && (bool) \wppilot_permission_callback();
}

/**
 * Filters mcp_adapter_default_transport_permission_user_capability for GoBuildWP's routes only.
 *
 * The adapter's default server, renamed to `wppilot` when the adapter copy is GoBuildWP's, is the
 * one GoBuildWP server that cannot carry transport_permission(): DefaultServerFactory does not
 * pass a transport callback through from mcp_adapter_default_server_config. This capability filter
 * is the adapter's only other seam, and it runs for every server without a callback - so a route
 * that is not GoBuildWP's gets the capability it came with.
 *
 * @param mixed $capability The capability the adapter will check, 'read' by default.
 * @param mixed $context    The adapter's HttpRequestContext, carrying the REST request.
 * @return mixed
 */
function default_transport_capability(mixed $capability, mixed $context = null): mixed
{
    /** @var mixed $request */
    $request = is_object($context) && property_exists($context, 'request') ? $context->request : null;
    $route = is_object($request) && method_exists($request, 'get_route') ? (string) $request->get_route() : '';
    if (!is_own_route($route)) {
        return $capability;
    }

    // 'do_not_allow' is the capability WordPress refuses to everyone, administrators included.
    return transport_permission() ? $capability : 'do_not_allow';
}

/**
 * The server `wp wppilot mcp serve` is serving in this process, or '' over HTTP.
 *
 * Pass a server id to record it; the stdio command does that once, before the first message.
 */
function stdio_server(?string $server_id = null): string
{
    static $serving = '';
    if ($server_id !== null) {
        $serving = $server_id;
    }

    return $serving;
}

/**
 * The REST route of the HTTP request being served, or '' outside one.
 */
function current_rest_route(): string
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
    $route = rtrim($route, characters: '/\\');

    return $route === '' ? '/' : $route;
}

/**
 * Whether this request is being answered by one of GoBuildWP's servers.
 */
function serving_own_server(): bool
{
    $stdio = stdio_server();
    if ($stdio !== '') {
        return in_array($stdio, own_server_ids(), strict: true);
    }

    return is_own_route(current_rest_route());
}

/**
 * Whether a legacy GoBuildWP server publishes an ability, by its metadata.
 *
 * On GoBuildWP's servers this is wppilot_ability_is_exposed() - `mcp.public`, then WordPress 7.1's
 * channel-independent `public` - which is what the modern transport lists, so a client sees one
 * set of abilities whichever revision it speaks. Anywhere else it is the adapter's own rule, so
 * another plugin's server does not start listing abilities its execute-ability would refuse.
 *
 * @param array<string, mixed> $meta
 */
function publishes(array $meta): bool
{
    if (serving_own_server()) {
        return \wppilot_ability_is_exposed($meta);
    }

    /** @var mixed $mcp */
    $mcp = $meta['mcp'] ?? null;

    return is_array($mcp) && (bool) ($mcp['public'] ?? false);
}

/**
 * Whether an ability is published by GoBuildWP's rule but not by the adapter's: public through
 * core's `public` flag, with no `mcp.public => true` of its own. WordPress 7.1's
 * core/get-site-info, core/get-user-info and core/get-environment-info are the shipped examples.
 *
 * @param array<string, mixed> $meta
 */
function exposed_only_by_core_flag(array $meta): bool
{
    /** @var mixed $mcp */
    $mcp = $meta['mcp'] ?? null;
    if (is_array($mcp) && ($mcp['public'] ?? null) === true) {
        return false;
    }

    return \wppilot_ability_is_exposed($meta);
}

/**
 * wp_register_ability_args: let the adapter's get-ability-info and execute-ability reach the
 * abilities GoBuildWP publishes when they are called on a GoBuildWP server.
 *
 * Both meta-tools refuse anything without `mcp.public`, so an ability public only through core's
 * flag was listed and callable on the modern transport and "not exposed via MCP" on the legacy
 * one. Only that one refusal is reconsidered, and only on GoBuildWP's servers; every other answer
 * of the adapter's own check stands.
 *
 * @param array<string, mixed> $args
 * @return array<string, mixed>
 */
function bridge_meta_tool_permission(array $args, string $name): array
{
    if (!in_array($name, ['mcp-adapter/execute-ability', 'mcp-adapter/get-ability-info'], strict: true)) {
        return $args;
    }
    /** @var mixed $inner */
    $inner = $args['permission_callback'] ?? null;
    if (!is_callable($inner)) {
        return $args;
    }

    $args['permission_callback'] = static function (mixed $input = null) use ($inner, $name): mixed {
        /** @var mixed $verdict */
        $verdict = $inner($input);
        if (!$verdict instanceof WP_Error || $verdict->get_error_code() !== 'ability_not_public_mcp') {
            return $verdict;
        }

        /** @var mixed $ability_name */
        $ability_name = is_array($input) ? $input['ability_name'] ?? '' : '';
        $ability = is_string($ability_name) && $ability_name !== '' && function_exists('wp_get_ability')
            ? wp_get_ability($ability_name)
            : null;

        return admit_core_public_ability($name, $input, $verdict, $ability);
    };

    return $args;
}

/**
 * Decide a meta-tool call the adapter refused only because the target lacks `mcp.public`.
 *
 * The adapter checks exposure after authentication and its own capability filter, so a refusal on
 * exposure means both passed. get-ability-info has nothing after that check. execute-ability goes
 * on to the target ability's own permission check with the normalized parameters, and that is run
 * here exactly as the adapter runs it after a passing exposure check.
 *
 * @param mixed $ability The target ability the call names, as wp_get_ability() resolved it.
 */
function admit_core_public_ability(string $tool, mixed $input, WP_Error $refusal, mixed $ability): mixed
{
    if (!serving_own_server()) {
        return $refusal;
    }

    if (!$ability instanceof WP_Ability || !exposed_only_by_core_flag(\wppilot_ability_meta($ability))) {
        return $refusal;
    }

    if ($tool === 'mcp-adapter/get-ability-info') {
        return true;
    }

    /** @var mixed $parameters */
    $parameters = is_array($input) ? $input['parameters'] ?? null : null;
    if (class_exists(\WP\MCP\Domain\Utils\AbilityArgumentNormalizer::class)) {
        $parameters = \WP\MCP\Domain\Utils\AbilityArgumentNormalizer::normalize($ability, $parameters);
    }
    /** @var mixed $permitted */
    $permitted = $ability->check_permissions($parameters);

    return $permitted instanceof WP_Error ? $permitted : (bool) $permitted;
}
