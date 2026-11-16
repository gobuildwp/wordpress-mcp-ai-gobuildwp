<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\AdminLock;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Ledger;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The lock: {enabled, allow, ip_source, trusted_proxies, updated, by}. Autoloaded, because every
 * sign-in and dashboard request reads it.
 */
const OPTION = 'wppilot_kit_admin_lock';

const STRATEGY = 'kits/admin-lock';

const MAX_ENTRIES = 100;

const IP_SOURCES = ['remote_addr', 'cloudflare', 'x_forwarded_for'];

/**
 * wp-login.php actions a visitor without an account uses: the password form of a protected post,
 * a privacy-request confirmation link, and signing out. They stay open.
 */
const OPEN_LOGIN_ACTIONS = ['postpass', 'confirmaction', 'logout'];

/** The CLI command that switches the lock off when nobody can reach the dashboard. */
const CLI_COMMAND = 'wppilot-kit admin-lock';

/**
 * The wp-config.php constant that switches the lock off without touching the database, derived
 * from the option name so an exported copy of the kit gets its own.
 */
function escape_constant(): string
{
    return strtoupper(OPTION) . '_DISABLED';
}

function disabled_by_constant(): bool
{
    $name = escape_constant();
    return defined($name) && (bool) constant($name);
}

function register_hooks(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    add_action('login_init', __NAMESPACE__ . '\\on_login_init', 0);
    add_action('admin_init', __NAMESPACE__ . '\\on_admin_init', 0);
    if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
        \WP_CLI::add_command(CLI_COMMAND, __NAMESPACE__ . '\\cli', [
            'shortdesc' => 'Show the admin IP lock, or switch it off when you are locked out.',
            'synopsis' => [
                [
                    'type' => 'positional',
                    'name' => 'action',
                    'optional' => true,
                    'default' => 'status',
                    'options' => ['status', 'disable'],
                ],
            ],
        ]);
    }
}

/**
 * The stored lock, normalised. An absent or damaged option reads as off.
 *
 * @return array{enabled: bool, allow: list<string>, ip_source: string, trusted_proxies: list<string>, updated: int, by: int}
 */
function read(): array
{
    $stored = get_option(OPTION, []);
    $stored = is_array($stored) ? $stored : [];
    $source = is_string($stored['ip_source'] ?? null) && in_array($stored['ip_source'], IP_SOURCES, true) ? $stored['ip_source'] : 'remote_addr';
    return [
        'enabled' => ($stored['enabled'] ?? false) === true,
        'allow' => cidr_list($stored['allow'] ?? []),
        'ip_source' => $source,
        'trusted_proxies' => cidr_list($stored['trusted_proxies'] ?? []),
        'updated' => max(0, (int) ($stored['updated'] ?? 0)),
        'by' => max(0, (int) ($stored['by'] ?? 0)),
    ];
}

/**
 * @param array{enabled: bool, allow: list<string>} $lock
 */
function is_enforced(array $lock): bool
{
    // An empty list would lock everyone out; it is refused on save and treated as off here.
    return $lock['enabled'] && $lock['allow'] !== [] && !disabled_by_constant();
}

/**
 * @param array{ip_source: string, trusted_proxies: list<string>} $lock
 * @param array<string, mixed> $server
 */
function request_ip(array $lock, array $server): string
{
    return client_ip($server, $lock['ip_source'], $lock['trusted_proxies']);
}

/**
 * Whether this request is turned away. Fails open on an address it cannot read, which only
 * happens off the web (CLI, a broken server variable), never for a real visitor.
 *
 * @param array<string, mixed> $server
 */
function blocks(array $lock, array $server): bool
{
    if (!is_enforced($lock)) {
        return false;
    }
    $ip = request_ip($lock, $server);
    return $ip !== '' && !ip_in_list($ip, $lock['allow']);
}

function on_login_init(): void
{
    $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : 'login';
    if (in_array($action, OPEN_LOGIN_ACTIONS, true)) {
        return;
    }
    refuse_if_blocked();
}

/**
 * The dashboard. admin-ajax.php and admin-post.php also fire admin_init, but they serve the front
 * end too (themes, forms, carts), so they are left alone, as are the REST API and WP-Cron.
 */
function on_admin_init(): void
{
    if (wp_doing_ajax() || wp_doing_cron() || is_admin_post()) {
        return;
    }
    refuse_if_blocked();
}

function is_admin_post(): bool
{
    $script = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
    $pagenow = $GLOBALS['pagenow'] ?? '';
    return $pagenow === 'admin-post.php' || str_ends_with($script, '/admin-post.php');
}

function refuse_if_blocked(): void
{
    $lock = read();
    if (!blocks($lock, $_SERVER)) {
        return;
    }
    if (!headers_sent()) {
        nocache_headers();
    }
    $title = __('Access restricted', domain: 'wppilot');
    wp_die(
        '<h1>' . esc_html($title) . '</h1><p>'
        . esc_html(sprintf(
            /* translators: %s: the visitor's IP address. */
            __('Sign-in and the dashboard of this site are only open to approved addresses, and %s is not one of them.', domain: 'wppilot'),
            request_ip($lock, $_SERVER),
        ))
        . '</p>',
        esc_html($title),
        ['response' => 403],
    );
}

/**
 * @param array<string, mixed> $server
 * @return array<string, mixed>
 */
function view(array $server): array
{
    $lock = read();
    $ip = request_ip($lock, $server);
    return [
        'enabled' => $lock['enabled'],
        'enforced' => is_enforced($lock),
        'allow' => $lock['allow'],
        'ip_source' => $lock['ip_source'],
        'trusted_proxies' => $lock['trusted_proxies'],
        'your_ip' => $ip !== '' ? $ip : null,
        'your_ip_allowed' => $ip !== '' && ip_in_list($ip, $lock['allow']),
        'proxy_headers_seen' => proxy_headers_seen($server),
        'disabled_by_constant' => disabled_by_constant(),
        'escape_hatches' => [
            'constant' => sprintf("define('%s', true); in wp-config.php", escape_constant()),
            'cli' => 'wp ' . CLI_COMMAND . ' disable',
        ],
        'protects' => ['wp-login.php', '/wp-admin/'],
        'not_protected' => ['admin-ajax.php', 'admin-post.php', 'REST API', 'WP-Cron', 'wp-login.php actions: ' . implode(', ', OPEN_LOGIN_ACTIONS)],
        'updated' => $lock['updated'] > 0 ? $lock['updated'] : null,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function status(array $input = []): array
{
    unset($input);
    return view($_SERVER);
}

/**
 * Validate a list of addresses and ranges.
 *
 * @return list<string>|WP_Error
 */
function parse_list(mixed $values, string $field): array|WP_Error
{
    if (!is_array($values)) {
        return new WP_Error('kit_admin_lock_bad_input', sprintf('%s is a list of IP addresses or CIDR ranges.', $field), ['status' => 400]);
    }
    if (count($values) > MAX_ENTRIES) {
        return new WP_Error('kit_admin_lock_too_many', sprintf('%s holds at most %d entries.', $field, MAX_ENTRIES), ['status' => 400]);
    }
    $bad = [];
    foreach ($values as $value) {
        if (!is_string($value) || normalize_cidr($value) === '') {
            $bad[] = is_scalar($value) ? substr((string) $value, 0, 60) : gettype($value);
        }
    }
    if ($bad !== []) {
        return new WP_Error('kit_admin_lock_bad_entry', sprintf('Not an IP address or CIDR range in %s: %s', $field, implode(', ', $bad)), ['status' => 400]);
    }
    return cidr_list($values);
}

/**
 * Save the lock. Refused when it would shut out the request saving it.
 *
 * @param array<string, mixed> $input
 * @param array<string, mixed> $server
 * @return array<string, mixed>|WP_Error
 */
function set(array $input, array $server): array|WP_Error
{
    $before_raw = get_option(OPTION, null);
    $before = read();
    $after = $before;

    if (array_key_exists('enabled', $input)) {
        if (!is_bool($input['enabled'])) {
            return new WP_Error('kit_admin_lock_bad_input', 'enabled is true or false.', ['status' => 400]);
        }
        $after['enabled'] = $input['enabled'];
    }
    foreach (['allow', 'trusted_proxies'] as $field) {
        if (array_key_exists($field, $input)) {
            $list = parse_list($input[$field], $field);
            if ($list instanceof WP_Error) {
                return $list;
            }
            $after[$field] = $list;
        }
    }
    if (array_key_exists('ip_source', $input)) {
        if (!is_string($input['ip_source']) || !in_array($input['ip_source'], IP_SOURCES, true)) {
            return new WP_Error('kit_admin_lock_bad_input', 'ip_source is remote_addr, cloudflare or x_forwarded_for.', ['status' => 400]);
        }
        $after['ip_source'] = $input['ip_source'];
    }
    if ($after['ip_source'] === 'x_forwarded_for' && $after['trusted_proxies'] === [] && $after['enabled']) {
        return new WP_Error('kit_admin_lock_no_proxies', 'ip_source x_forwarded_for needs trusted_proxies: the addresses of your own proxy. Without them the header could be forged.', ['status' => 400]);
    }

    $compare = static fn(array $lock): array => [$lock['enabled'], $lock['allow'], $lock['ip_source'], $lock['trusted_proxies']];
    if ($compare($after) === $compare($before)) {
        return ['changed' => false, 'change_id' => null] + view($server);
    }

    if ($after['enabled']) {
        if ($after['allow'] === []) {
            return new WP_Error('kit_admin_lock_empty', 'An empty allow list would lock everyone out. Add at least your own address.', ['status' => 400]);
        }
        $ip = request_ip($after, $server);
        if ($ip === '') {
            return new WP_Error('kit_admin_lock_no_address', 'This request has no IP address to check the list against (it is not a web request), so the lock cannot be switched on from here. Use the site over HTTP.', ['status' => 409]);
        }
        if (!ip_in_list($ip, $after['allow'])) {
            return new WP_Error('kit_admin_lock_self_lockout', sprintf(
                'Refused: this request comes from %s, which the new list does not include, so saving it would lock you out of wp-admin and wp-login.php. Add %s (or a range holding it) to allow.%s',
                $ip,
                $ip,
                proxy_headers_seen($server) !== [] && $after['ip_source'] === 'remote_addr'
                    ? ' This request passed through a proxy; if that is your CDN or load balancer, set ip_source so the real address is read.'
                    : '',
            ), ['status' => 409]);
        }
        if (($input['confirm'] ?? null) !== true) {
            return new WP_Error('kit_confirmation_required', sprintf(
                'This closes wp-login.php and wp-admin to every address except: %s. Anyone else, including other users who sign in through wp-login.php, gets a 403 page. Your address %s is on the list. If you are locked out later, add define(\'%s\', true); to wp-config.php or run wp %s disable. Obtain explicit user approval, then retry with confirm=true.',
                implode(', ', $after['allow']),
                $ip,
                escape_constant(),
                CLI_COMMAND,
            ), ['status' => 409]);
        }
    }

    $after['updated'] = time();
    $after['by'] = get_current_user_id();
    update_option(OPTION, $after, true);
    if ($compare(read()) !== $compare($after)) {
        return new WP_Error('kit_admin_lock_not_saved', 'The setting was not stored; the options table refused the write.', ['status' => 500]);
    }

    $recorded = Runtime\host()->ledger()->record_items('wppilot/set-admin-lock', [[
        'input' => ['enabled' => $after['enabled'], 'allow' => $after['allow'], 'ip_source' => $after['ip_source']],
        'before' => ['type' => STRATEGY, 'existed' => $before_raw !== null, 'value' => $before_raw],
        'result' => ['enabled' => $after['enabled'], 'entries' => count($after['allow'])],
        'item' => ['option' => OPTION],
    ]]);

    return ['changed' => true, 'change_id' => $recorded['change_ids'][0] ?? null] + view($server);
}

/**
 * Put the lock back as it was before a change. Refused when the old lock would shut out the
 * request undoing it, for the same reason a save is.
 *
 * @param array<string, mixed> $payload A ledger rollback payload; the snapshot is under `snapshot`.
 * @return array<string, mixed>|WP_Error
 */
function restore(array $payload): array|WP_Error
{
    $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    if (!array_key_exists('existed', $snapshot)) {
        return new WP_Error('kit_rollback_bad_snapshot', 'The change record does not hold the admin lock setting.');
    }
    $value = $snapshot['existed'] === true ? ($snapshot['value'] ?? null) : null;
    if (is_array($value) && ($value['enabled'] ?? false) === true) {
        $old = $value;
        $source = is_string($old['ip_source'] ?? null) && in_array($old['ip_source'], IP_SOURCES, true) ? $old['ip_source'] : 'remote_addr';
        $ip = client_ip($_SERVER, $source, cidr_list($old['trusted_proxies'] ?? []));
        if ($ip !== '' && !ip_in_list($ip, cidr_list($old['allow'] ?? []))) {
            return new WP_Error('kit_admin_lock_self_lockout', sprintf('Refused: the earlier lock does not include %s, the address of this request, so restoring it would lock you out.', $ip), ['status' => 409]);
        }
    }
    if ($snapshot['existed'] === true) {
        update_option(OPTION, $value, true);
        $verified = get_option(OPTION, null) == $value;
    } else {
        delete_option(OPTION);
        $verified = get_option(OPTION, null) === null;
    }
    return ['option' => OPTION, 'enforced' => is_enforced(read()), 'verified' => $verified];
}

function register(Ledger $ledger): void
{
    $ledger->register_strategy(STRATEGY, static fn(array $payload): array|WP_Error => restore($payload));
}

/**
 * `wp wppilot-kit admin-lock status|disable`: the way back in when nobody can reach the dashboard.
 *
 * @param list<string> $args
 */
function cli(array $args): void
{
    $action = $args[0] ?? 'status';
    $lock = read();
    if ($action === 'disable') {
        if (!$lock['enabled']) {
            \WP_CLI::success('The admin IP lock is already off.');
            return;
        }
        $lock['enabled'] = false;
        $lock['updated'] = time();
        $lock['by'] = 0;
        update_option(OPTION, $lock, true);
        if (read()['enabled']) {
            \WP_CLI::error('The options table refused the write; the lock is still on. Add the wp-config.php constant instead: ' . escape_constant());
        }
        \WP_CLI::success('The admin IP lock is off. wp-login.php and wp-admin are open to every address again.');
        return;
    }
    \WP_CLI::log(is_enforced($lock) ? 'on' : 'off');
    if ($lock['enabled'] && disabled_by_constant()) {
        \WP_CLI::log(sprintf('Switched off by %s in wp-config.php.', escape_constant()));
    }
    if ($lock['allow'] !== []) {
        \WP_CLI::log('Allowed: ' . implode(', ', $lock['allow']));
    }
}
