<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Wire the guard into this request. Only the parts that are on are hooked; with everything off
 * and no blocks, this reads the settings and returns.
 *
 * GOBUILDWP_DISABLE_PROTECTION (true in wp-config.php) switches all of it off, for an owner whose
 * own address got blocked and who can no longer reach the dashboard to lift the block.
 */
function install_hooks(): void
{
    if (defined('GOBUILDWP_DISABLE_PROTECTION') && constant('GOBUILDWP_DISABLE_PROTECTION') === true) {
        return;
    }
    $settings = settings();
    $has_blocks = get_option(OPT_BLOCKS, []) !== [];
    if (has_virtual_patches()) {
        install_virtual_patch_hooks();
    }
    $patches = active_virtual_patches();
    $login = $settings['login'];
    $any = $has_blocks
        || $patches !== []
        || $settings['firewall'] !== 'off'
        || $login['enabled']
        || $login['generic_errors']
        || $settings['xmlrpc'] === 'off'
        || $settings['block_user_enumeration']
        || $settings['flood']['enabled'];
    if (!$any || (defined('WP_CLI') && WP_CLI) || (function_exists('wp_doing_cron') && wp_doing_cron())) {
        return;
    }

    if ($patches !== []) {
        guard_virtual_patches($patches, $settings);
    }
    guard_request($settings);

    if ($login['enabled'] || $login['generic_errors'] || $has_blocks) {
        add_filter('authenticate', __NAMESPACE__ . '\\filter_authenticate', 99, 3);
    }
    if ($login['enabled']) {
        add_action('wp_login_failed', __NAMESPACE__ . '\\on_login_failed', 10, 1);
        add_action('wp_login', __NAMESPACE__ . '\\on_login', 10, 0);
    }
    if ($settings['xmlrpc'] === 'off') {
        add_filter('xmlrpc_enabled', '__return_false');
    }
    if ($settings['block_user_enumeration']) {
        add_filter('rest_pre_dispatch', __NAMESPACE__ . '\\filter_rest_users', 10, 3);
        add_filter('wp_sitemaps_add_provider', static fn($provider, $name) => $name === 'users' ? false : $provider, 10, 2);
    }
    if ($settings['flood']['enabled']) {
        add_action('template_redirect', __NAMESPACE__ . '\\on_template_redirect', 1, 0);
    }
    add_filter('rest_authentication_errors', __NAMESPACE__ . '\\filter_rest_auth', 99, 1);
    // A deferred decision on a request WordPress doesn't serve as REST (WordPress serves REST on
    // parse_request and exits) is enforced here instead of being dropped.
    if (isset($GLOBALS['gobuildwp_sp_deferred'])) {
        add_action('parse_request', __NAMESPACE__ . '\\enforce_deferred', PHP_INT_MAX, 0);
        add_action('admin_init', __NAMESPACE__ . '\\enforce_deferred', 0, 0);
        add_action('login_init', __NAMESPACE__ . '\\enforce_deferred', 0, 0);
    }
}

/** The site's REST path prefix, e.g. "/wp-json/" or "/blog/wp-json/". */
function rest_path_prefix(): string
{
    $home = function_exists('home_url') ? (string) wp_parse_url(home_url('/'), PHP_URL_PATH) : '/';
    $base = function_exists('rest_get_url_prefix') ? trim((string) rest_get_url_prefix(), '/') : 'wp-json';
    return rtrim($home, '/') . '/' . $base . '/';
}

/** Blocks a request whose decision waited for REST authentication that never came. */
function enforce_deferred(): void
{
    $d = $GLOBALS['gobuildwp_sp_deferred'] ?? null;
    if (!is_array($d)) {
        return;
    }
    $GLOBALS['gobuildwp_sp_deferred'] = null;
    act(['action' => 'block'] + $d, $d['ip'], $d['path']);
}

/**
 * What the guard knows about this request before WordPress parses it.
 *
 * @param array<string, mixed> $settings
 * @return array<string, mixed>
 */
function request_context(array $settings): array
{
    $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
    $path = rawurldecode((string) strtok($uri, '?'));
    $script = basename(is_string($_SERVER['SCRIPT_NAME'] ?? null) ? $_SERVER['SCRIPT_NAME'] : '');
    return [
        'ip' => client_ip($_SERVER, $settings['ip_source']),
        'path' => $path,
        'is_login' => $script === 'wp-login.php',
        'is_xmlrpc' => (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || $script === 'xmlrpc.php',
        // Only the front controller serves REST; ?rest_route= on xmlrpc.php or wp-comments-post.php is not REST.
        // parse_request also reads rest_route from the form body, so a POST that carries it there is REST too.
        'is_rest' => $script === 'index.php' && (str_starts_with($path, rest_path_prefix()) || isset($_GET['rest_route']) || isset($_POST['rest_route'])),
        'is_admin_area' => str_contains($path, '/wp-admin/'),
        'has_auth_header' => !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) || !empty($_SERVER['PHP_AUTH_USER']),
        'author_query' => isset($_GET['author']) && is_scalar($_GET['author']) && preg_match('/^\s*-?\d+/', (string) $_GET['author']) === 1,
        'signed_in' => false,
        'admin' => false,
        'parts' => request_parts($_SERVER, $_GET, $_POST, $_FILES),
    ];
}

/**
 * The guard's decision for one request, or null to let it through.
 *
 * `defer` is for a REST call that carries credentials: WordPress hasn't checked them yet, so the
 * decision waits for rest_authentication_errors, where an administrator's application password
 * (GoBuildWP Cloud, an MCP client) passes and anyone else is stopped.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $settings
 * @param array<string, array{ip: string, reason: string, source: string, created_at: int, expires_at: int|null}> $blocks
 * @return array{action: string, type: string, rule: string, detail: string}|null
 */
function decide(array $ctx, array $settings, array $blocks): ?array
{
    $ip = (string) $ctx['ip'];
    if ($ip === '' || $ctx['admin'] || ip_in_list($ip, $settings['allowlist'])) {
        return null;
    }
    $defer = $ctx['is_rest'] && $ctx['has_auth_header'];
    $block = block_for($ip, $blocks, $ctx['is_login'] || $ctx['is_xmlrpc']);
    if ($block !== null) {
        $detail = $block['source'] === 'login_lockout' ? 'Too many failed logins' : 'Address is blocked';
        return ['action' => $defer ? 'defer' : 'block', 'type' => 'block', 'rule' => $block['source'], 'detail' => $detail];
    }
    if ($settings['xmlrpc'] === 'off' && $ctx['is_xmlrpc']) {
        return ['action' => 'block', 'type' => 'firewall', 'rule' => 'xmlrpc_off', 'detail' => 'XML-RPC is turned off'];
    }
    if ($settings['block_user_enumeration'] && $ctx['author_query'] && !$ctx['signed_in'] && !$ctx['is_admin_area']) {
        return ['action' => 'block', 'type' => 'enumeration', 'rule' => 'author_query', 'detail' => 'User enumeration'];
    }
    if ($settings['firewall'] !== 'off') {
        // Signed-in users write posts and comments with markup; WordPress filters that itself. Their
        // prose also legitimately names wp-config.php or .env, so the sensitive-file rule only
        // watches the path for them, not form fields.
        $hit = firewall_match($ctx['parts'], $ctx['signed_in'] ? ['xss', 'sensitive_file:args'] : []);
        if ($hit !== null) {
            $action = $settings['firewall'] === 'log' ? 'log' : ($defer ? 'defer' : 'block');
            return ['action' => $action, 'type' => 'firewall', 'rule' => $hit['id'], 'detail' => $hit['label'] . ' in ' . $hit['in']];
        }
    }
    return null;
}

/** @var array{action: string, type: string, rule: string, detail: string, ip: string, path: string}|null */
$GLOBALS['gobuildwp_sp_deferred'] = null;

/**
 * @param array<string, mixed> $settings
 */
function guard_request(array $settings): void
{
    $ctx = request_context($settings);
    $blocks = blocks();
    $decision = decide($ctx, $settings, $blocks);
    if ($decision === null) {
        return;
    }
    // Only now look at the session cookie: most requests never get this far.
    [$signed_in, $admin] = cookie_user();
    if ($signed_in) {
        $ctx['signed_in'] = true;
        $ctx['admin'] = $admin;
        $decision = decide($ctx, $settings, $blocks);
        if ($decision === null) {
            return;
        }
    }
    act($decision, $ctx['ip'], $ctx['path']);
}

/**
 * @param array{action: string, type: string, rule: string, detail: string} $decision
 */
function act(array $decision, string $ip, string $path): void
{
    if ($decision['action'] === 'defer') {
        $GLOBALS['gobuildwp_sp_deferred'] = $decision + ['ip' => $ip, 'path' => $path];
        return;
    }
    $action = $decision['action'] === 'log' ? 'logged' : 'blocked';
    $event = record_event($decision['type'], $action, $ip, $decision['rule'], $path, $decision['detail']);
    if ($action === 'logged') {
        return;
    }
    if ($decision['type'] === 'firewall' && $decision['rule'] !== 'xmlrpc_off') {
        escalate($ip);
    }
    respond_blocked($event['id'], $decision['rule'] === 'login_lockout' ? 'Too many failed logins from your address. Try again later.' : '');
}

/**
 * Ten firewall blocks from one address in ten minutes block the address for an hour.
 */
function escalate(string $ip): void
{
    if (note_hit('fw', $ip, 600) >= 10 && block_for($ip, blocks(), false) === null) {
        add_block($ip, 60, 'Repeated firewall blocks', 'firewall');
    }
}

/**
 * @return array{0: bool, 1: bool} Signed in, and an administrator.
 */
function cookie_user(): array
{
    if (!defined('LOGGED_IN_COOKIE') || empty($_COOKIE[LOGGED_IN_COOKIE]) || !function_exists('wp_validate_auth_cookie')) {
        return [false, false];
    }
    // Validates the cookie without setting the current user, which would be too early for
    // application passwords on REST requests.
    $user_id = wp_validate_auth_cookie('', 'logged_in');
    if (!$user_id) {
        return [false, false];
    }
    return [true, user_can((int) $user_id, 'manage_options')];
}

/** Whether this request carries the access token paired with GoBuildWP Cloud. */
function cloud_token_call(): bool
{
    if (!function_exists('wppilot_current_token_id') || !function_exists('wppilot_cloud_token_is_linked') || !function_exists('wppilot_cloud_link')) {
        return false;
    }
    $token = wppilot_current_token_id();
    return $token > 0 && wppilot_cloud_token_is_linked($token, wppilot_cloud_link());
}

/**
 * The deferred decision, once WordPress knows who is calling the REST API.
 */
function filter_rest_auth(mixed $result): mixed
{
    $d = $GLOBALS['gobuildwp_sp_deferred'] ?? null;
    if (!is_array($d) || is_wp_error($result)) {
        return $result;
    }
    $GLOBALS['gobuildwp_sp_deferred'] = null;
    if (current_user_can('manage_options') || cloud_token_call()) {
        return $result;
    }
    $event = record_event($d['type'], 'blocked', $d['ip'], $d['rule'], $d['path'], $d['detail']);
    if ($d['type'] === 'firewall') {
        escalate($d['ip']);
    }
    return new WP_Error('gobuildwp_blocked', 'Blocked by website protection. Reference: ' . $event['id'], ['status' => 403]);
}

function respond_blocked(string $ref, string $message): void
{
    if (!headers_sent()) {
        status_header(403);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
    }
    $text = $message !== '' ? $message : 'This request was blocked by the site\'s website protection.';
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Blocked</title></head>'
        . '<body style="font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">'
        . '<h1>Access blocked</h1><p>' . esc_html($text) . '</p><p>If you think this is a mistake, contact the site owner and give this reference: <code>'
        . esc_html($ref) . '</code></p></body></html>';
    exit();
}

// ---------------------------------------------------------------- login

function current_ip(): string
{
    return client_ip($_SERVER, settings()['ip_source']);
}

/**
 * Refuse logins from a locked-out address, and replace "unknown user" / "wrong password" with one
 * message so the login form doesn't confirm which usernames exist.
 */
function filter_authenticate(mixed $user, mixed $username = '', mixed $password = ''): mixed
{
    $settings = settings();
    $ip = current_ip();
    if ($ip !== '' && !ip_in_list($ip, $settings['allowlist']) && block_for($ip, blocks(), true) !== null) {
        return new WP_Error('gobuildwp_locked', '<strong>Error:</strong> Too many failed logins from your address. Try again later.');
    }
    if ($settings['login']['generic_errors'] && is_wp_error($user)) {
        $code = $user->get_error_code();
        if (in_array($code, ['invalid_username', 'invalid_email', 'incorrect_password'], true)) {
            return new WP_Error('gobuildwp_login_failed', '<strong>Error:</strong> The username or password is incorrect.');
        }
    }
    return $user;
}

function on_login_failed(mixed $username = ''): void
{
    $settings = settings();
    $ip = current_ip();
    if ($ip === '' || ip_in_list($ip, $settings['allowlist']) || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    $name = is_string($username) ? substr($username, 0, 60) : '';
    record_event('login', 'failed', $ip, 'login_failed', '', $name !== '' ? 'User: ' . $name : '');
    if (note_failure($ip, $settings['login'])) {
        $minutes = (int) $settings['login']['lockout_minutes'];
        add_block($ip, $minutes, 'Too many failed logins', 'login_lockout');
        record_event('login', 'locked', $ip, 'login_lockout', '', sprintf('Locked out for %d minutes', $minutes));
    }
}

function on_login(): void
{
    $ip = current_ip();
    if ($ip !== '') {
        clear_failures($ip);
    }
}

// ---------------------------------------------------------------- enumeration and floods

/**
 * Anonymous visitors don't get the REST user list, which hands out every author's username.
 */
function filter_rest_users(mixed $result, mixed $server, mixed $request): mixed
{
    if ($result !== null || !is_object($request) || !method_exists($request, 'get_route')) {
        return $result;
    }
    if (!preg_match('#^/wp/v2/users(?:/|$)#', (string) $request->get_route()) || is_user_logged_in()) {
        return $result;
    }
    $ip = current_ip();
    if (ip_in_list($ip, settings()['allowlist'])) {
        return $result;
    }
    record_event('enumeration', 'blocked', $ip, 'rest_users', (string) $request->get_route(), 'User enumeration');
    return new WP_Error('rest_user_cannot_view', 'Sorry, you are not allowed to list users.', ['status' => 401]);
}

/**
 * A visitor who requests too many missing pages in a minute is probing for files: block the
 * address for ten minutes.
 */
function on_template_redirect(): void
{
    if (!function_exists('is_404') || !is_404() || is_user_logged_in()) {
        return;
    }
    $settings = settings();
    $ip = current_ip();
    if ($ip === '' || ip_in_list($ip, $settings['allowlist'])) {
        return;
    }
    if (note_hit('404', $ip, 60) <= (int) $settings['flood']['max_404_per_minute']) {
        return;
    }
    add_block($ip, 10, 'Too many missing pages', 'flood');
    $path = is_string($_SERVER['REQUEST_URI'] ?? null) ? (string) strtok($_SERVER['REQUEST_URI'], '?') : '';
    $event = record_event('flood', 'blocked', $ip, '404_flood', $path, 'Too many missing pages in a minute');
    respond_blocked($event['id'], '');
}
