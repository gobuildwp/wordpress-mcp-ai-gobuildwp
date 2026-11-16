<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Read Mode: one site-level switch that makes every agent connection read-only.
 *
 * It is stricter than the Read Only safety profile and sits on top of it. While
 * it is on:
 *
 * - The effective safety profile is Read Only for every request, whatever the
 *   site profile or a token's ceiling says, so MCP, the REST ability routes,
 *   core's `wp-abilities` routes, WP-CLI, Chat and the Cloud all refuse a write
 *   through the same permission filter. Discovery hides writes the same way.
 * - Only read abilities on the allowlist run: annotated read-only, not held to a
 *   higher profile by `meta.safety.min_profile`, and not removed by the
 *   `wppilot_read_mode_allows_ability` filter.
 * - Two abilities read or write depending on their input: `wppilot/rest-request`
 *   and `wppilot/run-wp-cli`. Read Mode lists them only where the site's own
 *   profile already would, and the permission gate then runs only a GET request
 *   or a read WP-CLI command (wppilot_read_mode_input_is_read()). Each callback
 *   checks again, for WordPress versions without that gate.
 * - A REST write made with an application password, an access token or OAuth is
 *   refused before it reaches any route, core's included. Logged-in people in
 *   wp-admin are not agents and keep working.
 * - XML-RPC, which application passwords also open, offers its read methods only.
 *
 * It fails closed: only a stored `'0'` (or no option at all) reads as off.
 * Anything else, or `WPPILOT_READ_MODE` set to true in wp-config.php, is on.
 */

const WPPILOT_READ_MODE_OPTION = 'wppilot_read_mode';

/**
 * Abilities that read or write depending on their input. See the file header.
 */
const WPPILOT_READ_MODE_INPUT_GATED = ['wppilot/rest-request', 'wppilot/run-wp-cli'];

/**
 * Whether Read Mode is on for this site.
 */
function wppilot_read_mode_enabled(): bool
{
    if (wppilot_read_mode_forced()) {
        return true;
    }

    /** @var mixed $stored */
    $stored = get_option(WPPILOT_READ_MODE_OPTION, default_value: '0');

    // Fail closed: a corrupted or unexpected value is treated as on, never off.
    return !($stored === '0' || $stored === 0 || $stored === false);
}

/**
 * Whether wp-config.php pins Read Mode on, so the screens cannot turn it off.
 */
function wppilot_read_mode_forced(): bool
{
    return defined('WPPILOT_READ_MODE') && (bool) constant('WPPILOT_READ_MODE') === true;
}

/**
 * Switch Read Mode on or off. Returns false when wp-config.php pins it on.
 */
function wppilot_set_read_mode(bool $enabled): bool
{
    if (!$enabled && wppilot_read_mode_forced()) {
        return false;
    }

    $was = wppilot_read_mode_enabled();
    update_option(WPPILOT_READ_MODE_OPTION, $enabled ? '1' : '0', autoload: true);
    if ($was === $enabled) {
        return true;
    }

    /**
     * Fires after Read Mode is switched.
     *
     * @param bool $enabled The new state.
     */
    do_action('wppilot_read_mode_changed', $enabled);

    return true;
}

/**
 * Whether Read Mode lets this ability run.
 *
 * Only reached when Read Mode is on and the effective profile is already Read
 * Only, so this narrows the read set; it can never let a write through.
 */
function wppilot_read_mode_allows_ability(WP_Ability $ability): bool
{
    $name = $ability->get_name();

    // The adapter's meta-tools only discover or dispatch; the ability they
    // dispatch to is checked on its own.
    if (str_starts_with($name, 'mcp-adapter/')) {
        return true;
    }

    if (!wppilot_ability_is_readonly($ability) && !wppilot_read_mode_input_gated($name)) {
        return false;
    }

    /**
     * Filter whether a read ability may run while Read Mode is on.
     *
     * Return false to keep a sensitive read off the allowlist. Returning true
     * for a write ability has no effect.
     *
     * @param bool       $allowed Default true for a read-only ability.
     * @param WP_Ability $ability The ability being checked.
     */
    return apply_filters('wppilot_read_mode_allows_ability', true, $ability) === true;
}

/**
 * Whether this ability reads or writes depending on its input (WPPILOT_READ_MODE_INPUT_GATED).
 */
function wppilot_read_mode_input_gated(string $ability_name): bool
{
    return in_array($ability_name, WPPILOT_READ_MODE_INPUT_GATED, strict: true);
}

/**
 * Whether this call to an input-gated ability only reads, so Read Mode lets it run.
 *
 * `wppilot/rest-request` reads when its method is GET, read the way the bridge reads it (no method
 * means GET). `wppilot/run-wp-cli` reads when wppilot_wp_cli_is_read_command() says its command is
 * a read and no refused global flag is passed. Anything unexpected is a write.
 */
function wppilot_read_mode_input_is_read(string $ability_name, mixed $input): bool
{
    $input = is_array($input) ? $input : [];

    if ($ability_name === 'wppilot/rest-request') {
        return strtoupper(is_string($input['method'] ?? null) ? $input['method'] : 'GET') === 'GET';
    }

    if ($ability_name === 'wppilot/run-wp-cli') {
        if (!function_exists('wppilot_wp_cli_is_read_command') || !is_array($input['args'] ?? null)) {
            return false;
        }
        $args = [];
        /** @var mixed $arg */
        foreach ($input['args'] as $arg) {
            if (!is_string($arg)) {
                return false;
            }
            $args[] = $arg;
        }

        return wppilot_wp_cli_refused_global_arg($args) === null
            && wppilot_wp_cli_is_read_command(wppilot_wp_cli_command_words($args));
    }

    return false;
}

/**
 * The refusal an agent gets for an ability Read Mode does not allow.
 */
function wppilot_read_mode_ability_error(string $ability_name): WP_Error
{
    return new WP_Error(
        'wppilot_read_mode',
        sprintf(
            /* translators: %s: ability name */
            __(
                'Ability "%s" is blocked: this site is in Read Mode, so every connection is read-only. A site administrator turns Read Mode off in GoBuildWP Settings or with `wp wppilot read-mode off`; do not retry.',
                domain: 'wppilot',
            ),
            $ability_name,
        ),
        ['status' => 403, 'ability' => $ability_name, 'read_mode' => true],
    );
}

/**
 * The refusal for any non-ability write Read Mode stops (REST, Cloud).
 */
function wppilot_read_mode_write_error(): WP_Error
{
    return new WP_Error(
        'wppilot_read_mode',
        __(
            'This site is in Read Mode, so every connection is read-only and this write was refused. A site administrator turns Read Mode off in GoBuildWP Settings or with `wp wppilot read-mode off`.',
            domain: 'wppilot',
        ),
        ['status' => 403, 'read_mode' => true],
    );
}

/**
 * Whether the current REST request was authenticated by an agent credential
 * rather than a person's wp-admin login.
 *
 * Application passwords, GoBuildWP access tokens and OAuth all count. A request
 * from a logged-in user that core did not authenticate by cookie (some other
 * plugin's Basic Auth or JWT) counts too: Read Mode fails closed.
 */
function wppilot_read_mode_request_is_agent(): bool
{
    if (!empty($GLOBALS['wp_rest_application_password_uuid'])) {
        return true;
    }
    if (function_exists('WPPilot\\OAuth\\Middleware\\request_identity_via') && \WPPilot\OAuth\Middleware\request_identity_via() !== '') {
        return true;
    }
    if (get_current_user_id() <= 0) {
        return false;
    }

    return empty($GLOBALS['wp_rest_auth_cookie']);
}

/**
 * REST routes an agent may still write to while Read Mode is on.
 *
 * Ability-running routes stay open because every ability on them is checked
 * by the permission filter above; the device sign-in token exchange and the
 * Cloud unlink only ever reduce or hand out read-limited access.
 */
function wppilot_read_mode_route_is_exempt(string $route): bool
{
    if (function_exists('WPPilot\\OAuth\\Middleware\\is_any_mcp_route') && \WPPilot\OAuth\Middleware\is_any_mcp_route($route)) {
        return true;
    }
    if (preg_match('#^/(?:wppilot/v1|wp-abilities/v1)/abilities/[^/]+(?:/[^/]+)+/run$#', $route) === 1) {
        return true;
    }

    return in_array($route, [
        '/wppilot/v1/cloud/unlink',
        '/gobuildwp/v1/device/code',
        '/gobuildwp/v1/device/token',
    ], strict: true) || str_starts_with($route, '/wppilot/v1/oauth/');
}

/**
 * Refuse an agent's REST write while Read Mode is on. Hooked on `rest_pre_dispatch`.
 *
 * A batch request is refused as a whole: `/batch/v1` is a POST that is not on
 * the exempt list, and its sub-requests never pass through this filter again.
 *
 * @param mixed $result Response short-circuit from an earlier filter.
 */
function wppilot_read_mode_rest_guard(mixed $result, mixed $server, mixed $request): mixed
{
    if ($result !== null || !$request instanceof WP_REST_Request || !wppilot_read_mode_enabled()) {
        return $result;
    }
    if (in_array(strtoupper($request->get_method()), ['GET', 'HEAD', 'OPTIONS'], strict: true)) {
        return $result;
    }
    if (wppilot_read_mode_route_is_exempt($request->get_route()) || !wppilot_read_mode_request_is_agent()) {
        return $result;
    }

    return wppilot_read_mode_write_error();
}

/**
 * Offer only XML-RPC's read methods while Read Mode is on.
 *
 * An allowlist by verb, so a method a plugin adds is dropped unless its name
 * reads as a read. `system.multicall` dispatches through this same table, so it
 * cannot reach a dropped method.
 *
 * @param array<string, mixed> $methods
 * @return array<string, mixed>
 */
function wppilot_read_mode_xmlrpc_methods(array $methods): array
{
    if (!wppilot_read_mode_enabled()) {
        return $methods;
    }

    $kept = [];
    foreach ($methods as $name => $callback) {
        $verb = (string) substr((string) strrchr('.' . (string) $name, '.'), 1);
        if (preg_match('/^(?:get|list|supported|sayHello|addTwoNumbers|multicall)/', $verb) === 1) {
            $kept[(string) $name] = $callback;
        }
    }

    return $kept;
}

/**
 * Show a Read Mode badge in the admin bar while it is on.
 */
function wppilot_read_mode_admin_bar(\WP_Admin_Bar $wp_admin_bar): void
{
    if (!wppilot_read_mode_enabled() || !wppilot_current_user_can_manage()) {
        return;
    }

    $wp_admin_bar->add_node([
        'id' => 'wppilot-read-mode',
        'title' => esc_html__('Read Mode', domain: 'wppilot')
            . '<span class="screen-reader-text"> ' . esc_html__('(agents can only read)', domain: 'wppilot') . '</span>',
        'href' => admin_url('admin.php?page=wppilot-settings#agent-access'),
        'meta' => [
            'class' => 'wppilot-read-mode-badge',
            'title' => __('GoBuildWP Read Mode is on: every agent connection is read-only.', domain: 'wppilot'),
        ],
    ]);
}

/**
 * `wp wppilot read-mode on|off|status`.
 *
 * @param list<string> $args
 */
function wppilot_read_mode_cli(array $args): void
{
    $action = $args[0] ?? 'status';

    if ($action === 'status') {
        \WP_CLI::log(wppilot_read_mode_enabled() ? 'on' : 'off');
        if (wppilot_read_mode_forced()) {
            \WP_CLI::log('Pinned on by WPPILOT_READ_MODE in wp-config.php.');
        }
        return;
    }
    if ($action !== 'on' && $action !== 'off') {
        \WP_CLI::error('Usage: wp wppilot read-mode on|off|status');
    }
    if (!wppilot_set_read_mode($action === 'on')) {
        \WP_CLI::error('Read Mode is pinned on by WPPILOT_READ_MODE in wp-config.php.');
    }

    \WP_CLI::success($action === 'on' ? 'Read Mode is on: every agent connection is read-only.' : 'Read Mode is off.');
}

function wppilot_read_mode_register_hooks(): void
{
    add_filter('rest_pre_dispatch', callback: 'wppilot_read_mode_rest_guard', priority: 5, accepted_args: 3);
    add_filter('xmlrpc_methods', callback: 'wppilot_read_mode_xmlrpc_methods', priority: 999);
    add_action('admin_bar_menu', callback: 'wppilot_read_mode_admin_bar', priority: 999);

    if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
        \WP_CLI::add_command('wppilot read-mode', 'wppilot_read_mode_cli', [
            'shortdesc' => 'Turn GoBuildWP Read Mode on or off, or show whether it is on.',
            'synopsis' => [
                [
                    'type' => 'positional',
                    'name' => 'action',
                    'optional' => true,
                    'default' => 'status',
                    'options' => ['on', 'off', 'status'],
                ],
            ],
        ]);
    }
}
