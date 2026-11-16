<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * MCP IP allowlist: optionally limit which addresses may reach GoBuildWP's agent endpoints.
 *
 * Covers every GoBuildWP MCP route, the OAuth REST endpoints (token, register, revoke,
 * introspect), the device sign-in code/token exchange, and the ability REST routes when an agent
 * credential calls them. The browser consent screen in wp-admin is not covered: it is the site
 * owner's browser, not the agent, that opens it.
 *
 * - An empty list allows every address. That is the default.
 * - Entries are single IPv4/IPv6 addresses or CIDR ranges. IPv4-mapped IPv6 addresses
 *   (::ffff:192.0.2.1) match their IPv4 entries.
 * - The client address is REMOTE_ADDR. X-Forwarded-For is only read when REMOTE_ADDR is a proxy the
 *   owner named in GOBUILDWP_TRUSTED_PROXIES (or the `wppilot_trusted_proxies` filter), and then
 *   the rightmost address that is not itself a trusted proxy is used.
 * - `define('WPPILOT_MCP_IP_ALLOWLIST_DISABLED', true);` in wp-config.php switches enforcement off
 *   without touching the stored list, so an owner who locked their agents out can always recover.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_IP_ALLOWLIST_OPTION = 'wppilot_mcp_ip_allowlist';

const WPPILOT_IP_ALLOWLIST_MAX = 200;

const WPPILOT_IP_ALLOWLIST_SAVE_ACTION = 'wppilot_save_ip_allowlist';

/**
 * Parse an address or CIDR range into its packed network bytes and prefix length.
 *
 * IPv4-mapped IPv6 input is reduced to IPv4 so both spellings of one address compare equal.
 *
 * @return array{bytes: string, prefix: int, family: int}|null Null when the entry is not valid.
 */
function wppilot_ip_parse(string $entry): ?array
{
    $entry = trim($entry);
    if ($entry === '') {
        return null;
    }
    $prefix = null;
    if (str_contains($entry, '/')) {
        [$entry, $bits] = explode('/', $entry, 2);
        if (preg_match('/^\d{1,3}$/', $bits) !== 1) {
            return null;
        }
        $prefix = (int) $bits;
    }
    // Brackets are how IPv6 appears in URLs and some proxy headers.
    $entry = trim($entry, '[]');
    $packed = @inet_pton($entry);
    if (!is_string($packed)) {
        return null;
    }
    if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
        $packed = substr($packed, 12);
        if ($prefix !== null) {
            $prefix -= 96;
            if ($prefix < 0) {
                return null;
            }
        }
    }
    $max = strlen($packed) * 8;
    $prefix ??= $max;
    if ($prefix > $max) {
        return null;
    }

    return ['bytes' => $packed, 'prefix' => $prefix, 'family' => strlen($packed) === 4 ? 4 : 6];
}

/**
 * Whether an address falls inside an entry (single address or CIDR range).
 */
function wppilot_ip_matches(string $ip, string $entry): bool
{
    $address = wppilot_ip_parse($ip);
    $range = wppilot_ip_parse($entry);
    if ($address === null || $range === null || $address['family'] !== $range['family'] || $address['prefix'] !== strlen($address['bytes']) * 8) {
        return false;
    }

    $full = intdiv($range['prefix'], 8);
    if (substr($address['bytes'], 0, $full) !== substr($range['bytes'], 0, $full)) {
        return false;
    }
    $rest = $range['prefix'] % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xff << (8 - $rest)) & 0xff;

    return (ord($address['bytes'][$full]) & $mask) === (ord($range['bytes'][$full]) & $mask);
}

/**
 * The proxies whose X-Forwarded-For is believed.
 *
 * @return list<string>
 */
function wppilot_trusted_proxies(): array
{
    $configured = [];
    if (defined('GOBUILDWP_TRUSTED_PROXIES')) {
        /** @var mixed $constant */
        $constant = constant('GOBUILDWP_TRUSTED_PROXIES');
        if (is_string($constant)) {
            $configured = explode(',', $constant);
        } elseif (is_array($constant)) {
            $configured = $constant;
        }
    }

    /**
     * Filter the proxies whose X-Forwarded-For header GoBuildWP believes for the MCP IP allowlist.
     *
     * @param list<string> $proxies Addresses or CIDR ranges.
     */
    $filtered = apply_filters('wppilot_trusted_proxies', $configured);
    if (!is_array($filtered)) {
        $filtered = $configured;
    }

    $proxies = [];
    foreach ($filtered as $proxy) {
        if (is_string($proxy) && wppilot_ip_parse($proxy) !== null) {
            $proxies[] = trim($proxy);
        }
    }

    return $proxies;
}

/**
 * @param list<string> $entries
 */
function wppilot_ip_in_any(string $ip, array $entries): bool
{
    foreach ($entries as $entry) {
        if (wppilot_ip_matches($ip, $entry)) {
            return true;
        }
    }

    return false;
}

/**
 * The address of whoever sent this request, as the allowlist sees it.
 *
 * @param array<string, mixed>|null $server Defaults to $_SERVER.
 */
function wppilot_ip_client_address(?array $server = null): string
{
    $server ??= $_SERVER;
    $remote = isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR']) ? trim($server['REMOTE_ADDR']) : '';
    if (wppilot_ip_parse($remote) === null) {
        return '';
    }
    $proxies = wppilot_trusted_proxies();
    if ($proxies === [] || !wppilot_ip_in_any($remote, $proxies)) {
        return $remote;
    }
    $forwarded = isset($server['HTTP_X_FORWARDED_FOR']) && is_string($server['HTTP_X_FORWARDED_FOR']) ? $server['HTTP_X_FORWARDED_FOR'] : '';
    // Right to left: each trusted proxy appended the address it received from. The first entry that
    // is not a trusted proxy is the client; anything left of it was supplied by that client.
    $hops = array_reverse(array_map('trim', explode(',', $forwarded)));
    foreach ($hops as $hop) {
        if (wppilot_ip_parse($hop) === null) {
            break;
        }
        if (!wppilot_ip_in_any($hop, $proxies)) {
            return $hop;
        }
    }

    return $remote;
}

/**
 * Whether wp-config.php has switched enforcement off.
 */
function wppilot_ip_allowlist_disabled(): bool
{
    return defined('WPPILOT_MCP_IP_ALLOWLIST_DISABLED') && (bool) constant('WPPILOT_MCP_IP_ALLOWLIST_DISABLED') === true;
}

/**
 * The stored allowlist.
 *
 * @return list<string>
 */
function wppilot_ip_allowlist(): array
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_IP_ALLOWLIST_OPTION, default_value: []);
    if (!is_array($stored)) {
        return [];
    }
    $entries = [];
    foreach ($stored as $entry) {
        if (is_string($entry) && wppilot_ip_parse($entry) !== null) {
            $entries[] = $entry;
        }
    }

    return $entries;
}

/**
 * Validate a list of entries. Returns the normalised list, or the entries that are invalid.
 *
 * @param list<string> $lines Raw lines; blank lines and `#` comments are ignored.
 * @return array{entries: list<string>, invalid: list<string>}
 */
function wppilot_ip_allowlist_validate(array $lines): array
{
    $entries = [];
    $invalid = [];
    foreach ($lines as $line) {
        $hash = strpos($line, '#');
        $value = trim($hash === false ? $line : substr($line, 0, $hash));
        if ($value === '') {
            continue;
        }
        if (wppilot_ip_parse($value) === null) {
            $invalid[] = $value;
            continue;
        }
        if (!in_array($value, $entries, true)) {
            $entries[] = $value;
        }
    }
    if (count($entries) > WPPILOT_IP_ALLOWLIST_MAX) {
        $invalid[] = sprintf('(more than %d entries)', WPPILOT_IP_ALLOWLIST_MAX);
    }

    return ['entries' => $entries, 'invalid' => $invalid];
}

/**
 * @param list<string> $entries Already validated.
 */
function wppilot_ip_allowlist_save(array $entries): void
{
    update_option(WPPILOT_IP_ALLOWLIST_OPTION, array_values($entries), autoload: true);
}

/**
 * Whether an address may reach the agent endpoints under the current list.
 */
function wppilot_ip_allowed(string $ip): bool
{
    if (wppilot_ip_allowlist_disabled()) {
        return true;
    }
    $entries = wppilot_ip_allowlist();
    if ($entries === []) {
        return true;
    }

    return $ip !== '' && wppilot_ip_in_any($ip, $entries);
}

/**
 * Whether a REST route is one the allowlist guards.
 */
function wppilot_ip_allowlist_route_is_guarded(string $route): bool
{
    if (function_exists('WPPilot\\OAuth\\Middleware\\is_any_mcp_route') && \WPPilot\OAuth\Middleware\is_any_mcp_route($route)) {
        return true;
    }
    // Every GoBuildWP MCP server lives under /mcp/; checked directly too, because the OAuth
    // middleware is not loaded on a site where OAuth is unavailable.
    if (preg_match('#^/mcp/(?:wppilot|wppilot-oauth|mcp-adapter-default-server)(?:/|$)#', $route) === 1) {
        return true;
    }
    if (str_starts_with($route, '/wppilot/v1/oauth/')) {
        return true;
    }

    return in_array($route, ['/gobuildwp/v1/device/code', '/gobuildwp/v1/device/token'], strict: true);
}

/**
 * Whether a REST route runs abilities, which the allowlist guards for agent credentials only.
 */
function wppilot_ip_allowlist_route_runs_abilities(string $route): bool
{
    return preg_match('#^/(?:wppilot/v1|wp-abilities/v1)/abilities(?:/|$)#', $route) === 1;
}

/**
 * The refusal an agent gets from an address that is not on the list.
 */
function wppilot_ip_allowlist_error(string $ip): WP_Error
{
    return new WP_Error(
        'wppilot_ip_not_allowed',
        sprintf(
            /* translators: %s: IP address */
            __('This site only accepts AI agent connections from listed addresses, and %s is not one of them. A site administrator can add it under GoBuildWP > Connect > IP allowlist or with `wp wppilot ip-allowlist add`.', domain: 'wppilot'),
            $ip !== '' ? $ip : __('this address', domain: 'wppilot'),
        ),
        ['status' => 403],
    );
}

/**
 * Refuse a guarded request from an address not on the list. Hooked on `rest_pre_dispatch` at
 * priority 1, ahead of every other GoBuildWP guard.
 *
 * @param mixed $result Response short-circuit from an earlier filter.
 */
function wppilot_ip_allowlist_rest_guard(mixed $result, mixed $server, mixed $request): mixed
{
    if ($result !== null || !$request instanceof WP_REST_Request) {
        return $result;
    }
    // WordPress matches REST routes case-insensitively and keeps the caller's spelling.
    $route = strtolower($request->get_route());
    $guarded = wppilot_ip_allowlist_route_is_guarded($route);
    if (!$guarded && wppilot_ip_allowlist_route_runs_abilities($route)) {
        $guarded = function_exists('wppilot_read_mode_request_is_agent') && wppilot_read_mode_request_is_agent();
    }
    if (!$guarded) {
        return $result;
    }
    $ip = wppilot_ip_client_address();
    if (wppilot_ip_allowed($ip)) {
        return $result;
    }

    return wppilot_ip_allowlist_error($ip);
}

/**
 * Save the list from the Connect screen.
 */
function wppilot_ip_allowlist_handle_save(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to change the GoBuildWP IP allowlist.', domain: 'wppilot'), status_code: 403);
    }
    check_admin_referer(WPPILOT_IP_ALLOWLIST_SAVE_ACTION);

    $raw = isset($_POST['wppilot_ip_allowlist']) && is_string($_POST['wppilot_ip_allowlist'])
        ? sanitize_textarea_field(wp_unslash($_POST['wppilot_ip_allowlist']))
        : '';
    $checked = wppilot_ip_allowlist_validate(preg_split('/\r\n|\r|\n|,/', $raw) ?: []);
    $current = wppilot_ip_allowlist();
    $confirmed = isset($_POST['wppilot_ip_allowlist_confirm']) && $_POST['wppilot_ip_allowlist_confirm'] === '1';

    $status = 'saved';
    if ($checked['invalid'] !== []) {
        $status = 'invalid';
        set_transient('wppilot_ip_allowlist_invalid_' . get_current_user_id(), $checked['invalid'], 5 * MINUTE_IN_SECONDS);
    } elseif ($checked['entries'] === [] && $current !== [] && !$confirmed) {
        // Clearing the list opens the endpoints to every address: confirm first.
        $status = 'confirm_clear';
    } else {
        wppilot_ip_allowlist_save($checked['entries']);
    }

    wp_safe_redirect(add_query_arg('wppilot_ip_result', $status, admin_url('admin.php?page=' . WPPILOT_SETUP_PAGE)) . '#wppilot-ip-allowlist');
    exit();
}

/**
 * The IP allowlist panel on the Connect screen. Rendered whether or not MCP is ready, so an owner
 * whose agents are locked out can always reach it.
 */
function wppilot_ip_allowlist_render_panel(): void
{
    if (!wppilot_current_user_can_manage()) {
        return;
    }
    $entries = wppilot_ip_allowlist();
    $you = wppilot_ip_client_address();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: picks one of the fixed result messages after a redirect.
    $result = isset($_GET['wppilot_ip_result']) && is_string($_GET['wppilot_ip_result']) ? sanitize_key(wp_unslash($_GET['wppilot_ip_result'])) : '';
    $invalid = [];
    if ($result === 'invalid') {
        /** @var mixed $stored */
        $stored = get_transient('wppilot_ip_allowlist_invalid_' . get_current_user_id());
        $invalid = is_array($stored) ? array_filter($stored, 'is_string') : [];
    }
    ?>
    <div class="wppilot-connect-section" id="wppilot-ip-allowlist">
        <h2><?php esc_html_e('IP allowlist', domain: 'wppilot'); ?></h2>
        <p class="description" style="max-width:70ch;"><?php esc_html_e(
            'Optional. List the addresses your AI clients connect from, one per line (single addresses or CIDR ranges, IPv4 or IPv6). Requests to the MCP endpoints, the OAuth token endpoints and device sign-in from any other address are refused. Leave it empty to allow every address. Hosted clients such as Claude.ai and GoBuildWP Cloud connect from their own servers, so list their published ranges or they are refused too.',
            domain: 'wppilot',
        ); ?></p>
        <?php if ($result === 'saved') { ?>
            <div class="notice notice-success inline"><p><?php esc_html_e('IP allowlist saved.', domain: 'wppilot'); ?></p></div>
        <?php } elseif ($result === 'confirm_clear') { ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e('Not saved. Emptying the list lets every address reach the agent endpoints: tick the confirmation box to do that.', domain: 'wppilot'); ?></p></div>
        <?php } elseif ($result === 'invalid') { ?>
            <div class="notice notice-error inline"><p><?php echo esc_html(sprintf(
                /* translators: %s: comma-separated invalid entries */
                __('Not saved. These entries are not valid addresses or CIDR ranges: %s', domain: 'wppilot'),
                implode(', ', $invalid),
            )); ?></p></div>
        <?php } ?>
        <?php if (wppilot_ip_allowlist_disabled()) { ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e('WPPILOT_MCP_IP_ALLOWLIST_DISABLED is set in wp-config.php, so the list below is not enforced.', domain: 'wppilot'); ?></p></div>
        <?php } ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(WPPILOT_IP_ALLOWLIST_SAVE_ACTION); ?>">
            <?php wp_nonce_field(WPPILOT_IP_ALLOWLIST_SAVE_ACTION); ?>
            <p>
                <label for="wppilot-ip-allowlist-field" class="screen-reader-text"><?php esc_html_e('Allowed addresses', domain: 'wppilot'); ?></label>
                <textarea id="wppilot-ip-allowlist-field" name="wppilot_ip_allowlist" rows="5" cols="50" class="large-text code" placeholder="203.0.113.10&#10;2001:db8::/32"><?php echo esc_textarea(implode("\n", $entries)); ?></textarea>
            </p>
            <?php if ($entries !== []) { ?>
                <p><label><input type="checkbox" name="wppilot_ip_allowlist_confirm" value="1">
                    <?php esc_html_e('I understand that saving an empty list allows every address.', domain: 'wppilot'); ?></label></p>
            <?php } ?>
            <p class="description"><?php echo esc_html(sprintf(
                /* translators: %s: IP address */
                __('Your address, as GoBuildWP sees it: %s. If the site is behind a proxy or CDN, name it in GOBUILDWP_TRUSTED_PROXIES in wp-config.php or every client will appear as the proxy. If you lock your agents out, define WPPILOT_MCP_IP_ALLOWLIST_DISABLED as true in wp-config.php.', domain: 'wppilot'),
                $you !== '' ? $you : __('unknown', domain: 'wppilot'),
            )); ?></p>
            <?php submit_button(__('Save IP allowlist', domain: 'wppilot'), 'secondary', 'submit', false); ?>
        </form>
    </div>
    <?php
}

/**
 * `wp wppilot ip-allowlist list|add|remove|clear|status`.
 *
 * @param list<string>          $args
 * @param array<string, string> $assoc_args
 */
function wppilot_ip_allowlist_cli(array $args, array $assoc_args = []): void
{
    $action = $args[0] ?? 'status';
    $entries = wppilot_ip_allowlist();

    switch ($action) {
        case 'status':
            \WP_CLI::log($entries === [] ? 'off: every address is allowed' : sprintf('on: %d entr%s', count($entries), count($entries) === 1 ? 'y' : 'ies'));
            if (wppilot_ip_allowlist_disabled()) {
                \WP_CLI::log('Not enforced: WPPILOT_MCP_IP_ALLOWLIST_DISABLED is set in wp-config.php.');
            }
            return;
        case 'list':
            foreach ($entries as $entry) {
                \WP_CLI::log($entry);
            }
            return;
        case 'add':
            $values = array_slice($args, 1);
            if ($values === []) {
                \WP_CLI::error('Usage: wp wppilot ip-allowlist add <address-or-cidr>...');
            }
            $checked = wppilot_ip_allowlist_validate(array_merge($entries, $values));
            if ($checked['invalid'] !== []) {
                \WP_CLI::error('Not a valid address or CIDR range: ' . implode(', ', $checked['invalid']));
            }
            wppilot_ip_allowlist_save($checked['entries']);
            \WP_CLI::success(sprintf('The allowlist has %d entr%s.', count($checked['entries']), count($checked['entries']) === 1 ? 'y' : 'ies'));
            return;
        case 'remove':
            $values = array_map('trim', array_slice($args, 1));
            $kept = array_values(array_diff($entries, $values));
            if ($kept === [] && $entries !== []) {
                \WP_CLI::confirm('That empties the list, which allows every address. Continue?', $assoc_args);
            }
            wppilot_ip_allowlist_save($kept);
            \WP_CLI::success(sprintf('The allowlist has %d entr%s.', count($kept), count($kept) === 1 ? 'y' : 'ies'));
            return;
        case 'clear':
            \WP_CLI::confirm('Empty the IP allowlist, allowing every address?', $assoc_args);
            wppilot_ip_allowlist_save([]);
            \WP_CLI::success('The allowlist is empty: every address is allowed.');
            return;
        default:
            \WP_CLI::error('Usage: wp wppilot ip-allowlist status|list|add|remove|clear');
    }
}

function wppilot_ip_allowlist_register_hooks(): void
{
    add_filter('rest_pre_dispatch', callback: 'wppilot_ip_allowlist_rest_guard', priority: 1, accepted_args: 3);
    add_action('admin_post_' . WPPILOT_IP_ALLOWLIST_SAVE_ACTION, callback: 'wppilot_ip_allowlist_handle_save');

    if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
        \WP_CLI::add_command('wppilot ip-allowlist', 'wppilot_ip_allowlist_cli', [
            'shortdesc' => 'Show or change the addresses allowed to reach GoBuildWP\'s MCP and OAuth endpoints.',
            'synopsis' => [
                ['type' => 'positional', 'name' => 'action', 'optional' => true, 'default' => 'status', 'options' => ['status', 'list', 'add', 'remove', 'clear']],
                ['type' => 'positional', 'name' => 'entries', 'optional' => true, 'repeating' => true],
                ['type' => 'flag', 'name' => 'yes', 'optional' => true],
            ],
        ]);
    }
}
