<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The wp-config.php off switch for every way an agent reaches this site.
 *
 * `define('WPPILOT_DISABLE_MCP', true);` is for an owner who needs agent access gone now, from a
 * file, without wp-admin: a leaked token, a misbehaving client, a site that is being handed over.
 * The settings toggle needs a working wp-admin and a database write; this needs neither, and nothing
 * an agent can call is able to undo it.
 *
 * While it is defined as true:
 *
 * - Every REST route in GoBuildWP's namespaces (`wppilot/v1`, `gobuildwp/v1`) and every GoBuildWP MCP
 *   route (`/mcp/wppilot`, `/mcp/wppilot-oauth`, the server card, and the legacy
 *   `/mcp/mcp-adapter-default-server` alias when GoBuildWP's adapter owns it) answers 503. That covers
 *   the MCP transports, the OAuth token/register/revoke/introspect endpoints, device sign-in, Chat,
 *   the REST ability shim and the Cloud routes.
 * - The `.well-known` OAuth and server-card documents and the legacy `/authorize`, `/register` and
 *   `/token` fallbacks are not served, so clients cannot even discover the server.
 * - Every ability call through GoBuildWP's gate is refused on every transport, and a GoBuildWP ability
 *   reached over REST some other way (core's run endpoint, another plugin's MCP server) is refused
 *   inside execute() on WordPress versions that have `wp_pre_execute_ability`.
 * - `wp wppilot mcp serve` exits with an error.
 * - Administrators see a notice saying so.
 *
 * Nothing is deleted: tokens, OAuth clients and settings are left as they are, and removing the
 * line restores access exactly as it was. Other plugins' REST routes and MCP servers are untouched.
 */

const WPPILOT_MCP_DISABLED_CODE = 'wppilot_mcp_disabled';

function wppilot_mcp_disabled(): bool
{
    return defined('WPPILOT_DISABLE_MCP') && constant('WPPILOT_DISABLE_MCP') === true;
}

function wppilot_mcp_disabled_error(): WP_Error
{
    return new WP_Error(
        WPPILOT_MCP_DISABLED_CODE,
        __('AI agent access to this site is switched off in wp-config.php (WPPILOT_DISABLE_MCP). Ask the site owner to remove that line to turn it back on.', domain: 'wppilot'),
        ['status' => 503],
    );
}

/**
 * Whether a REST route belongs to a GoBuildWP agent surface.
 */
function wppilot_mcp_route_is_ours(string $route): bool
{
    // WordPress matches REST routes case-insensitively and keeps the caller's spelling.
    $route = '/' . ltrim(strtolower($route), '/');
    foreach (['/wppilot/v1', '/gobuildwp/v1', '/mcp/wppilot'] as $prefix) {
        if ($route === $prefix || str_starts_with($route, $prefix . '/') || str_starts_with($route, $prefix . '-')) {
            return true;
        }
    }

    // The legacy alias is GoBuildWP's own only when GoBuildWP's adapter is the one loaded; on a
    // foreign adapter it is that plugin's default server, and its GoBuildWP abilities are refused
    // one by one below instead.
    $alias = '/mcp/mcp-adapter-default-server';
    if (($route === $alias || str_starts_with($route, $alias . '/'))
        && function_exists('wppilot_owns_mcp_adapter')
        && wppilot_owns_mcp_adapter()
    ) {
        return true;
    }

    return false;
}

/**
 * `rest_pre_dispatch`: answer GoBuildWP's routes with 503 before routing, authentication-dependent
 * handlers or any permission callback run.
 *
 * @param mixed $result
 * @return mixed
 */
function wppilot_mcp_kill_switch_rest(mixed $result, mixed $server, mixed $request): mixed
{
    if ($result !== null || !wppilot_mcp_disabled() || !$request instanceof WP_REST_Request) {
        return $result;
    }

    return wppilot_mcp_route_is_ours($request->get_route()) ? wppilot_mcp_disabled_error() : $result;
}

/**
 * `wppilot_pre_ability_execute` at priority 0: the gate every GoBuildWP transport runs.
 *
 * @param mixed $input
 * @return mixed
 */
function wppilot_mcp_kill_switch_gate(mixed $input): mixed
{
    if ($input instanceof WP_Error || !wppilot_mcp_disabled()) {
        return $input;
    }

    return wppilot_mcp_disabled_error();
}

/**
 * `wp_pre_execute_ability`: a GoBuildWP ability run over REST outside GoBuildWP's own routes.
 *
 * Limited to REST requests so that site code running an ability in cron or a hook keeps working;
 * the switch is about agents, which only arrive over HTTP or the stdio command blocked below.
 */
function wppilot_mcp_kill_switch_execute(mixed $pre, string $ability_name): mixed
{
    if (!wppilot_mcp_disabled() || !defined('REST_REQUEST') || !REST_REQUEST) {
        return $pre;
    }
    if (!str_starts_with($ability_name, 'wppilot/') && !str_starts_with($ability_name, 'gobuildwp/')) {
        return $pre;
    }

    return wppilot_mcp_disabled_error();
}

/**
 * `init` at priority 0: unhook the discovery documents and the legacy OAuth fallback before they
 * can answer. They are registered on plugins_loaded, so they are all in place by now.
 */
function wppilot_mcp_kill_switch_unhook(): void
{
    if (!wppilot_mcp_disabled()) {
        return;
    }
    remove_action('init', 'WPPilot\\Mcp\\ServerCard\\maybe_serve_well_known', 1);
    remove_action('init', 'WPPilot\\OAuth\\Endpoints\\Discovery\\maybe_serve', 1);
    remove_action('template_redirect', 'WPPilot\\OAuth\\Endpoints\\LegacyFallback\\maybe_serve', 0);
}

function wppilot_mcp_kill_switch_cli(): void
{
    if (!wppilot_mcp_disabled() || !defined('WP_CLI') || constant('WP_CLI') !== true || !class_exists('WP_CLI')) {
        return;
    }
    \WP_CLI::add_hook('before_invoke:wppilot mcp serve', static function (): void {
        \WP_CLI::error(wppilot_mcp_disabled_error()->get_error_message());
    });
}

function wppilot_mcp_kill_switch_notice(): void
{
    if (!wppilot_mcp_disabled() || !current_user_can('manage_options')) {
        return;
    }
    printf(
        '<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
        esc_html__('GoBuildWP: AI agent access is switched off.', domain: 'wppilot'),
        esc_html__('WPPILOT_DISABLE_MCP is set in wp-config.php, so every MCP, OAuth, Chat and Cloud endpoint is refused and no ability can be called. Tokens and settings are kept; remove the line to turn access back on.', domain: 'wppilot'),
    );
}

/**
 * Site Health > Info, so the state is visible where support looks first.
 *
 * @param array<string, mixed> $info
 * @return array<string, mixed>
 */
function wppilot_mcp_kill_switch_debug_info(array $info): array
{
    $info['wppilot-kill-switch'] = [
        'label' => __('GoBuildWP agent access', domain: 'wppilot'),
        'fields' => [
            'disabled' => [
                'label' => 'WPPILOT_DISABLE_MCP',
                'value' => wppilot_mcp_disabled()
                    ? __('Set: all agent endpoints are refused', domain: 'wppilot')
                    : __('Not set', domain: 'wppilot'),
                'debug' => wppilot_mcp_disabled() ? 'true' : 'false',
            ],
        ],
    ];

    return $info;
}

add_filter('rest_pre_dispatch', 'wppilot_mcp_kill_switch_rest', 0, 3);
add_filter('wppilot_pre_ability_execute', 'wppilot_mcp_kill_switch_gate', 0);
add_filter('wp_pre_execute_ability', 'wppilot_mcp_kill_switch_execute', 0, 2);
add_action('init', 'wppilot_mcp_kill_switch_unhook', 0);
add_action('admin_notices', 'wppilot_mcp_kill_switch_notice');
add_filter('debug_information', 'wppilot_mcp_kill_switch_debug_info');
wppilot_mcp_kill_switch_cli();
