<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Connection survival kit: three ways round the hosts and edge layers that break MCP.
 *
 * 1. `X-GoBuildWP-Authorization` is read as the Authorization header when a host or proxy strips
 *    the real one. Only on the routes agents use (the same set the IP allowlist guards), only over
 *    HTTPS, only when no Authorization header arrived at all, and only in its Bearer form. The value
 *    is a credential: nothing here logs or stores it.
 * 2. A `?rest_route=` fallback URL for sites whose /wp-json/ path is blocked or not routed.
 *    Diagnostics switches the Connect screen to it when the pretty REST index answers 404 or 403
 *    and the query form answers 200, and back once /wp-json/ works again.
 * 3. "Allow GoBuildWP Cloud" on Diagnostics > Repairs: fetches the Cloud's published egress
 *    addresses, shows them, and only on a second confirmed click adds them to the MCP IP
 *    allowlist, recording a ledger row whose undo restores the previous list.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_AUTH_ALIAS_SERVER_KEY = 'HTTP_X_GOBUILDWP_AUTHORIZATION';

const WPPILOT_REST_FALLBACK_OPTION = 'wppilot_mcp_rest_fallback';

const WPPILOT_CLOUD_EGRESS_PATH = '/.well-known/gobuildwp-egress.json';

const WPPILOT_CLOUD_EGRESS_MAX = 64;

const WPPILOT_CLOUD_ALLOW_PREVIEW_ACTION = 'wppilot_cloud_allow_preview';

const WPPILOT_CLOUD_ALLOW_APPLY_ACTION = 'wppilot_cloud_allow_apply';

const WPPILOT_CLOUD_ALLOW_PREVIEW_TRANSIENT = 'wppilot_cloud_allow_preview_';

// ------------------------------------------------------------------ alias header

/**
 * The REST route a request is for, worked out before WordPress has parsed it.
 *
 * The current user is resolved before rest_api_loaded() fills $wp->query_vars, so the route is read
 * from the same two places WordPress reads it: the `rest_route` query argument, else the path after
 * the REST prefix.
 *
 * @param array<array-key, mixed> $server
 * @param array<array-key, mixed> $get
 */
function wppilot_request_rest_route_early(array $server, array $get): string
{
    $query_route = $get['rest_route'] ?? null;
    if (is_string($query_route) && $query_route !== '') {
        return '/' . trim($query_route, '/');
    }

    $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    $home_path = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
    $prefix = $home_path . '/' . trim(rest_get_url_prefix(), '/') . '/';
    if ($path === '' || !str_starts_with($path, $prefix)) {
        return '';
    }

    return '/' . trim(substr($path, strlen($prefix)), '/');
}

/**
 * Whether the alias header may stand in for Authorization on this route.
 *
 * Exactly the routes the IP allowlist guards plus the ability-running REST shim: the places an
 * agent credential is presented. Everywhere else a stripped header stays stripped.
 */
function wppilot_auth_alias_route_allowed(string $route): bool
{
    if ($route === '') {
        return false;
    }

    return wppilot_ip_allowlist_route_is_guarded($route) || wppilot_ip_allowlist_route_runs_abilities($route);
}

/**
 * The alias header's value, or '' when it must not be used for this request.
 *
 * @param array<array-key, mixed> $server
 * @param array<array-key, mixed> $get
 */
function wppilot_auth_alias_header(array $server, array $get, bool $https): string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (is_string($server[$key] ?? null) && trim($server[$key]) !== '') {
            return '';
        }
    }
    if (!$https) {
        return '';
    }
    $alias = $server[WPPILOT_AUTH_ALIAS_SERVER_KEY] ?? null;
    if (!is_string($alias)) {
        return '';
    }
    $alias = trim(wp_unslash($alias));
    // Bearer only: the alias exists for tokens, and must never turn into a Basic login.
    if (preg_match('/^Bearer\s+\S+$/i', $alias) !== 1) {
        return '';
    }
    if (!wppilot_auth_alias_route_allowed(wppilot_request_rest_route_early($server, $get))) {
        return '';
    }

    return $alias;
}

// ------------------------------------------------------------------ rest_route fallback

/**
 * An MCP endpoint addressed through `?rest_route=`, which works where /wp-json/ is not routed.
 */
function wppilot_mcp_fallback_url(string $route = 'mcp/wppilot'): string
{
    return add_query_arg(['rest_route' => '/' . trim($route, '/')], home_url('/'));
}

function wppilot_rest_fallback_active(): bool
{
    return get_option(WPPILOT_REST_FALLBACK_OPTION, '0') === '1';
}

/**
 * The URL the Connect screen hands to clients: the pretty one, or the fallback once Diagnostics
 * has seen /wp-json/ blocked while the query form works.
 */
function wppilot_mcp_endpoint_url(string $route = 'mcp/wppilot'): string
{
    return wppilot_rest_fallback_active() ? wppilot_mcp_fallback_url($route) : rest_url($route);
}

/**
 * Decide between /wp-json/ and the fallback from two status codes, and remember the choice.
 *
 * @return array{status: string, use_fallback: bool}
 */
function wppilot_rest_fallback_decide(int $pretty_code, int $fallback_code): array
{
    if ($pretty_code === 200) {
        delete_option(WPPILOT_REST_FALLBACK_OPTION);
        return ['status' => 'ok', 'use_fallback' => false];
    }
    if (in_array($pretty_code, [403, 404], strict: true) && $fallback_code === 200) {
        update_option(WPPILOT_REST_FALLBACK_OPTION, '1', autoload: true);
        return ['status' => 'fallback', 'use_fallback' => true];
    }

    return ['status' => 'unknown', 'use_fallback' => wppilot_rest_fallback_active()];
}

/**
 * Diagnostics entry: probe /wp-json/ and ?rest_route=/ and pick the URL clients should use.
 *
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function wppilot_rest_fallback_check(): array
{
    $label = __('REST URL form', domain: 'wppilot');
    $options = \WPPilot\Troubleshoot\Checks\http_options();
    $pretty = wp_remote_get(rest_url(), $options);
    $pretty_code = is_wp_error($pretty) ? 0 : (int) wp_remote_retrieve_response_code($pretty);
    if ($pretty_code === 200) {
        wppilot_rest_fallback_decide(200, 0);
        return \WPPilot\Troubleshoot\Checks\ok('rest_fallback', $label, __('Clients use the /wp-json/ URL, which answers.', domain: 'wppilot'));
    }
    $fallback = wp_remote_get(add_query_arg(['rest_route' => '/'], home_url('/')), $options);
    $fallback_code = is_wp_error($fallback) ? 0 : (int) wp_remote_retrieve_response_code($fallback);
    $decision = wppilot_rest_fallback_decide($pretty_code, $fallback_code);
    $url = wppilot_mcp_fallback_url();
    if ($decision['status'] === 'fallback') {
        return \WPPilot\Troubleshoot\Checks\warn(
            'rest_fallback',
            $label,
            sprintf(
                /* translators: %d: HTTP status code */
                __('/wp-json/ answers HTTP %d, but the ?rest_route= form works. The Connect screen now gives clients the fallback URL.', domain: 'wppilot'),
                $pretty_code,
            ),
            __('Reconnect each client with the URL below. To go back to /wp-json/, ask your host to route it to WordPress, then run these checks again.', domain: 'wppilot'),
            copy: $url,
        );
    }

    return \WPPilot\Troubleshoot\Checks\info(
        'rest_fallback',
        $label,
        sprintf(
            /* translators: 1: HTTP status of /wp-json/, 2: HTTP status of ?rest_route=/ */
            __('/wp-json/ answered HTTP %1$d and the ?rest_route= form HTTP %2$d, so neither URL was chosen automatically.', domain: 'wppilot'),
            $pretty_code,
            $fallback_code,
        ),
        sprintf(
            /* translators: %s: fallback MCP URL */
            __('If your client still cannot connect, try %s.', domain: 'wppilot'),
            $url,
        ),
    );
}

// ------------------------------------------------------------------ Allow GoBuildWP Cloud

/**
 * Where the Cloud publishes the addresses its gateway connects from, or '' with no usable Cloud URL.
 */
function wppilot_cloud_egress_url(): string
{
    $base = function_exists('wppilot_cloud_url') ? wppilot_cloud_url() : '';

    return $base === '' ? '' : $base . WPPILOT_CLOUD_EGRESS_PATH;
}

/**
 * Validate the egress document: `{"ips": ["203.0.113.4", "2001:db8::/48"]}`.
 *
 * Every entry must be an address or CIDR range the allowlist itself accepts; one bad entry rejects
 * the whole document, because a half-applied list is harder to reason about than none.
 *
 * @return list<string>|WP_Error
 */
function wppilot_cloud_egress_parse(string $body): array|WP_Error
{
    /** @var mixed $decoded */
    $decoded = json_decode($body, associative: true);
    $ips = is_array($decoded) ? $decoded['ips'] ?? null : null;
    if (!is_array($ips) || $ips === []) {
        return new WP_Error('wppilot_cloud_egress_invalid', __('The GoBuildWP Cloud address list could not be read.', domain: 'wppilot'));
    }
    if (count($ips) > WPPILOT_CLOUD_EGRESS_MAX) {
        return new WP_Error('wppilot_cloud_egress_invalid', __('The GoBuildWP Cloud address list is longer than expected, so it was not used.', domain: 'wppilot'));
    }
    $valid = [];
    /** @var mixed $entry */
    foreach ($ips as $entry) {
        if (!is_string($entry) || wppilot_ip_parse(trim($entry)) === null) {
            return new WP_Error('wppilot_cloud_egress_invalid', __('The GoBuildWP Cloud address list holds an entry that is not an IP address or range.', domain: 'wppilot'));
        }
        $valid[] = trim($entry);
    }

    return array_values(array_unique($valid));
}

/**
 * @return list<string>|WP_Error
 */
function wppilot_cloud_egress_fetch(): array|WP_Error
{
    $url = wppilot_cloud_egress_url();
    if ($url === '') {
        return new WP_Error('wppilot_cloud_egress_unavailable', __('No usable GoBuildWP Cloud URL is configured.', domain: 'wppilot'));
    }
    $response = wp_safe_remote_get($url, ['timeout' => 5, 'redirection' => 0, 'limit_response_size' => 65536]);
    if (is_wp_error($response)) {
        return $response;
    }
    if ((int) wp_remote_retrieve_response_code($response) !== 200) {
        return new WP_Error('wppilot_cloud_egress_unavailable', sprintf(
            /* translators: %d: HTTP status code */
            __('GoBuildWP Cloud answered HTTP %d for its address list.', domain: 'wppilot'),
            (int) wp_remote_retrieve_response_code($response),
        ));
    }

    return wppilot_cloud_egress_parse((string) wp_remote_retrieve_body($response));
}

/**
 * What applying the list would do to the MCP IP allowlist.
 *
 * An empty allowlist already admits every address, and adding the Cloud's addresses to it would
 * shut out everyone else, so nothing is added then.
 *
 * @param list<string> $egress
 * @return array{needed: bool, add: list<string>, entries: list<string>}
 */
function wppilot_cloud_allowlist_plan(array $egress): array
{
    $current = wppilot_ip_allowlist();
    if ($current === []) {
        return ['needed' => false, 'add' => [], 'entries' => []];
    }
    $add = array_values(array_diff($egress, $current));

    return ['needed' => $add !== [], 'add' => $add, 'entries' => array_values(array_unique(array_merge($current, $add)))];
}

/**
 * Security plugins found on the site whose own allowlists GoBuildWP does not write, so the person
 * is told to add the addresses there by hand.
 *
 * @return list<string>
 */
function wppilot_cloud_allowlist_manual_targets(): array
{
    /** @var mixed $active */
    $active = get_option('active_plugins', []);
    $active = is_array($active) ? $active : [];
    $labels = [
        'wordfence/' => 'Wordfence (Firewall > All Firewall Options > Allowlisted IP addresses)',
        'better-wp-security/' => 'Solid Security (Settings > Global Settings > Authorized hosts)',
        'ithemes-security-pro/' => 'Solid Security (Settings > Global Settings > Authorized hosts)',
        'all-in-one-wp-security-and-firewall/' => 'All-In-One Security (Firewall > Allowlist)',
    ];
    $found = [];
    /** @var mixed $plugin */
    foreach ($active as $plugin) {
        foreach ($labels as $slug => $label) {
            if (is_string($plugin) && str_starts_with($plugin, $slug)) {
                $found[] = $label;
            }
        }
    }

    return array_values(array_unique($found));
}

/**
 * Apply a previewed list to the MCP IP allowlist and record a reversible ledger row.
 *
 * @param list<string> $egress
 * @return array{status: string, message: string, details: list<string>}
 */
function wppilot_cloud_allowlist_apply(array $egress): array
{
    $plan = wppilot_cloud_allowlist_plan($egress);
    if (!$plan['needed']) {
        return [
            'status' => 'info',
            'message' => wppilot_ip_allowlist() === []
                ? __('The MCP IP allowlist is empty, so GoBuildWP Cloud is already allowed. Nothing was changed.', domain: 'wppilot')
                : __('Every GoBuildWP Cloud address is already on the MCP IP allowlist. Nothing was changed.', domain: 'wppilot'),
            'details' => [],
        ];
    }
    $validated = wppilot_ip_allowlist_validate($plan['entries']);
    if ($validated['invalid'] !== []) {
        return ['status' => 'error', 'message' => __('The merged allowlist did not validate, so it was not saved.', domain: 'wppilot'), 'details' => $validated['invalid']];
    }

    $before = function_exists('wppilot_ledger_map_snapshot_options') ? wppilot_ledger_map_snapshot_options([WPPILOT_IP_ALLOWLIST_OPTION]) : null;
    wppilot_ip_allowlist_save($validated['entries']);
    if (function_exists('wppilot_ledger_record_items')) {
        wppilot_ledger_record_items('wppilot/troubleshoot', [[
            'input' => ['fix' => 'allow-gobuildwp-cloud', 'add' => $plan['add']],
            'before' => $before,
            'result' => ['added' => $plan['add'], 'entries' => count($validated['entries'])],
        ]]);
    }

    return [
        'status' => 'success',
        'message' => __('GoBuildWP Cloud\'s addresses were added to the MCP IP allowlist. Undo it from the Changes screen.', domain: 'wppilot'),
        'details' => $plan['add'],
    ];
}

function wppilot_cloud_allow_preview_key(): string
{
    return WPPILOT_CLOUD_ALLOW_PREVIEW_TRANSIENT . get_current_user_id();
}

/**
 * Step one: fetch the list and keep it, unapplied, for the person who asked.
 */
function wppilot_cloud_allow_handle_preview(): void
{
    \WPPilot\Troubleshoot\Repairs\authorize(WPPILOT_CLOUD_ALLOW_PREVIEW_ACTION);
    $egress = wppilot_cloud_egress_fetch();
    if ($egress instanceof WP_Error) {
        \WPPilot\Troubleshoot\Repairs\store_result(['status' => 'error', 'message' => $egress->get_error_message(), 'details' => []]);
        \WPPilot\Troubleshoot\Repairs\back();
        return;
    }
    set_transient(wppilot_cloud_allow_preview_key(), ['ips' => $egress, 'sha' => hash('sha256', implode("\n", $egress))], 10 * MINUTE_IN_SECONDS);
    \WPPilot\Troubleshoot\Repairs\back();
}

/**
 * Step two: apply exactly the list that was shown, never a fresh fetch.
 */
function wppilot_cloud_allow_handle_apply(): void
{
    \WPPilot\Troubleshoot\Repairs\authorize(WPPILOT_CLOUD_ALLOW_APPLY_ACTION);
    $key = wppilot_cloud_allow_preview_key();
    /** @var mixed $preview */
    $preview = get_transient($key);
    delete_transient($key);
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() checked the nonce.
    $sha = isset($_POST['sha']) ? sanitize_text_field(wp_unslash((string) $_POST['sha'])) : '';
    if (!is_array($preview) || !is_array($preview['ips'] ?? null) || !hash_equals((string) ($preview['sha'] ?? ''), $sha)) {
        \WPPilot\Troubleshoot\Repairs\store_result(['status' => 'error', 'message' => __('That preview expired. Fetch the GoBuildWP Cloud addresses again.', domain: 'wppilot'), 'details' => []]);
        \WPPilot\Troubleshoot\Repairs\back();
        return;
    }
    \WPPilot\Troubleshoot\Repairs\store_result(wppilot_cloud_allowlist_apply(array_values(array_filter($preview['ips'], 'is_string'))));
    \WPPilot\Troubleshoot\Repairs\back();
}

function wppilot_cloud_allow_render(): void
{
    /** @var mixed $preview */
    $preview = get_transient(wppilot_cloud_allow_preview_key());
    if (!is_array($preview) || !is_array($preview['ips'] ?? null)) {
        \WPPilot\Troubleshoot\Repairs\render_form(
            WPPILOT_CLOUD_ALLOW_PREVIEW_ACTION,
            __('Allow GoBuildWP Cloud', domain: 'wppilot'),
            __('Fetches the addresses GoBuildWP Cloud connects from and shows what would change. Nothing is saved until you confirm.', domain: 'wppilot'),
        );
        return;
    }
    /** @var list<string> $ips */
    $ips = array_values(array_filter($preview['ips'], 'is_string'));
    $plan = wppilot_cloud_allowlist_plan($ips);
    $manual = wppilot_cloud_allowlist_manual_targets();
    ?>
    <div class="notice inline notice-info">
        <p><strong><?php esc_html_e('GoBuildWP Cloud connects from:', domain: 'wppilot'); ?></strong> <code><?php echo esc_html(implode(', ', $ips)); ?></code></p>
        <p><?php echo esc_html(
            $plan['needed']
                ? sprintf(
                    /* translators: %s: addresses to add */
                    __('Confirming adds %s to the MCP IP allowlist.', domain: 'wppilot'),
                    implode(', ', $plan['add']),
                )
                : __('The MCP IP allowlist needs no change.', domain: 'wppilot'),
        ); ?></p>
        <?php if ($manual !== []) { ?>
            <p><?php esc_html_e('Add the same addresses by hand in:', domain: 'wppilot'); ?> <?php echo esc_html(implode('; ', $manual)); ?></p>
        <?php } ?>
    </div>
    <?php
    \WPPilot\Troubleshoot\Repairs\render_form(
        WPPILOT_CLOUD_ALLOW_APPLY_ACTION,
        __('Confirm: allow GoBuildWP Cloud', domain: 'wppilot'),
        __('Saves the change above and records it in the change ledger, where it can be undone.', domain: 'wppilot'),
        ['sha' => (string) ($preview['sha'] ?? '')],
    );
}

function wppilot_connection_survival_register_hooks(): void
{
    add_action('admin_post_' . WPPILOT_CLOUD_ALLOW_PREVIEW_ACTION, 'wppilot_cloud_allow_handle_preview');
    add_action('admin_post_' . WPPILOT_CLOUD_ALLOW_APPLY_ACTION, 'wppilot_cloud_allow_handle_apply');
    add_action('wppilot_troubleshoot_repairs', 'wppilot_cloud_allow_render');
}
