<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Cli\McpServe;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `wp wppilot mcp serve`: GoBuildWP over stdio, for clients that run on the same machine as the
 * site (Claude Code, Cursor, a CI job) and would rather launch a process than hold a credential.
 *
 * It serves the same MCP server the HTTP endpoint does — the adapter's `wppilot` server, its
 * request router, its tool handler — so a stdio client gets exactly the HTTP surface: the same
 * abilities, the same `mcp_adapter_pre_tool_call` controls (safety profile, confirmation, rate
 * limit, design and preview gates) and the same per-ability permission checks, run as the
 * WordPress user WP-CLI's global `--user` selects. What is different is only what HTTP gives for
 * free and a process has to do itself:
 *
 * - Identity. There is no credential to name the agent by, so writes are credited to
 *   `stdio` and the user, with the client name from the `initialize` handshake, and every write of
 *   one process is one session for undo-session.
 * - Freshness. One process lives as long as the client, and WordPress caches options and posts in
 *   memory for the life of a request. The runtime cache is dropped before every message, or a
 *   change made in wp-admin while the client is connected would never be seen.
 * - A clean stdout. Anything else written there — a PHP notice, a plugin's stray echo — would be
 *   read as a malformed protocol message and drop the connection. Errors go to stderr and output
 *   produced while handling a message is diverted there too.
 *
 * The adapter's own `wp mcp-adapter serve` exists too; this command differs in picking GoBuildWP's
 * server by default (the adapter picks the first registered one, which is Elementor's when both are
 * installed), printing nothing to stdout, refusing to run without a user, and the three points above.
 */

const DEFAULT_SERVER = 'wppilot';

function register(): void
{
    if (!defined('WP_CLI') || constant('WP_CLI') !== true || !class_exists('WP_CLI')) {
        return;
    }

    \WP_CLI::add_command('wppilot mcp serve', __NAMESPACE__ . '\\serve_command', [
        'shortdesc' => 'Serve GoBuildWP\'s MCP server over stdio, as the WordPress user given with --user.',
        'longdesc' => implode("\n", [
            'Runs until the client closes stdin. Requires the global --user parameter.',
            '',
            '## EXAMPLES',
            '',
            '    # Claude Code, on the machine that runs the site',
            '    claude mcp add gobuildwp-local -- wp --path=/var/www/html wppilot mcp serve --user=admin',
            '',
            '    # One request from a script',
            '    printf \'%s\n\' \'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"ci","version":"1"}}}\' | wp wppilot mcp serve --user=admin',
        ]),
        'synopsis' => [
            [
                'type' => 'assoc',
                'name' => 'server',
                'description' => 'The MCP server to serve.',
                'optional' => true,
                'default' => DEFAULT_SERVER,
            ],
        ],
        'when' => 'after_wp_load',
    ]);
}

/**
 * @param list<string>          $args
 * @param array<string, string> $assoc_args
 */
function serve_command(array $args, array $assoc_args): void
{
    // Before anything can print: a notice on stdout is a corrupt protocol message.
    ini_set('display_errors', 'stderr'); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- stdout is the protocol channel; errors must go to stderr.

    $user = wp_get_current_user();
    if ($user->ID <= 0) {
        \WP_CLI::error('Pass --user=<id|login|email>. The server runs as that WordPress user, and every ability checks that user\'s permissions, exactly as over HTTP.');
    }
    if (!function_exists('wppilot_is_enabled') || !\wppilot_is_enabled()) {
        \WP_CLI::error('GoBuildWP\'s AI abilities are switched off on this site. Switch them on under GoBuildWP > Connect first.');
    }
    /** This filter belongs to the MCP adapter; a site that switched stdio off there has switched it off here too. */
    if (!(bool) apply_filters('mcp_adapter_enable_stdio_transport', true) || !(bool) apply_filters('wppilot_stdio_enabled', true)) {
        \WP_CLI::error('The stdio transport is disabled on this site (mcp_adapter_enable_stdio_transport or wppilot_stdio_enabled).');
    }

    $router = router((string) ($assoc_args['server'] ?? DEFAULT_SERVER));
    if (is_string($router)) {
        \WP_CLI::error($router);
    }
    // There is no REST route over stdio, so say which server this process is; GoBuildWP's servers
    // publish by GoBuildWP's exposure rule (includes/mcp/legacy-access.php).
    if (function_exists('WPPilot\Mcp\Legacy\stdio_server')) {
        \WPPilot\Mcp\Legacy\stdio_server((string) ($assoc_args['server'] ?? DEFAULT_SERVER));
    }

    if (function_exists('wp_raise_memory_limit')) {
        wp_raise_memory_limit('wppilot_stdio');
    }

    $session = 'stdio-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 20);
    $agent = [
        'method' => 'stdio',
        'credential' => 'stdio-user-' . $user->ID,
        'label' => 'WP-CLI stdio (' . $user->user_login . ')',
        'client' => '',
        'client_version' => '',
    ];
    identify($session, $agent);

    log_line(sprintf('GoBuildWP MCP stdio server ready: server %s, user %s (#%d), session %s.', (string) ($assoc_args['server'] ?? DEFAULT_SERVER), $user->user_login, $user->ID, $session));

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- Reading the protocol stream.
    while (($line = fgets(STDIN)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }
        ob_start();
        try {
            $response = handle_line($router, $line, $agent, $session);
        } catch (\Throwable $error) {
            log_line('Error: ' . $error->getMessage());
            $response = encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => 'Internal error']]);
        }
        $stray = (string) ob_get_clean();
        if ($stray !== '') {
            log_line('Output while handling a message (diverted from stdout): ' . substr($stray, 0, 2000));
        }
        if ($response !== '') {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing the protocol stream.
            fwrite(STDOUT, $response . "\n");
            fflush(STDOUT);
        }
    }

    log_line('Client closed the connection; stopping.');
}

/**
 * The request router of one registered MCP server, or why there is none.
 */
function router(string $server_id): object|string
{
    if (!class_exists('WP\\MCP\\Core\\McpAdapter')) {
        return 'The MCP adapter is not loaded, so there is no MCP server to serve. Reinstall the GoBuildWP release build.';
    }
    $server = \WP\MCP\Core\McpAdapter::instance()->get_server($server_id);
    if ($server === null) {
        $ids = array_keys(\WP\MCP\Core\McpAdapter::instance()->get_servers());
        return sprintf('No MCP server "%s" is registered. Registered: %s.', $server_id, $ids === [] ? 'none' : implode(', ', $ids));
    }
    if (!method_exists($server, 'create_transport_context')) {
        return 'The loaded MCP adapter is a version without the transport context this command needs. Deactivate the plugin whose older adapter copy is loaded, or use its own serve command.';
    }
    $context = $server->create_transport_context();
    $router = $context->request_router ?? null;

    return is_object($router) && method_exists($router, 'route_request')
        ? $router
        : 'The loaded MCP adapter has no request router this command can drive.';
}

/**
 * Tell the ledger and the Connections screen who is on the other end.
 *
 * @param array{method: string, credential: string, label: string, client: string, client_version: string} $agent
 */
function identify(string $session, array $agent): void
{
    if (function_exists('wppilot_current_agent')) {
        \wppilot_current_agent($agent);
    }
    if (function_exists('wppilot_session_basis')) {
        \wppilot_session_basis(['kind' => 'stdio', 'key' => $session]);
    }
}

/**
 * Handle one JSON-RPC line and return the line to answer with ('' for a notification).
 *
 * @param array{method: string, credential: string, label: string, client: string, client_version: string} $agent
 */
function handle_line(object $router, string $line, array &$agent, string $session): string
{
    $message = json_decode($line, true);
    if (!is_array($message) || array_is_list_like($message)) {
        // Batches were removed from MCP in 2025-06-18; a list is refused like any malformed message.
        return encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error: send one JSON-RPC object per line.']]);
    }
    $id = $message['id'] ?? null;
    $method = $message['method'] ?? null;
    if (($message['jsonrpc'] ?? null) !== '2.0' || !is_string($method)) {
        if ($id === null && !is_string($method)) {
            // A response or other message with no method needs no answer.
            return '';
        }
        return encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32600, 'message' => 'Invalid Request']]);
    }
    $params = is_array($message['params'] ?? null) ? $message['params'] : [];

    if ($method === 'initialize') {
        $info = is_array($params['clientInfo'] ?? null) ? $params['clientInfo'] : [];
        $agent['client'] = substr(trim((string) ($info['name'] ?? '')), 0, 100);
        $agent['client_version'] = substr(trim((string) ($info['version'] ?? '')), 0, 50);
        identify($session, $agent);
    }
    if (function_exists('wppilot_record_connection')) {
        \wppilot_record_connection('stdio', get_current_user_id(), ['name' => $agent['client'], 'version' => $agent['client_version']]);
    }

    /** @var array<string, mixed> $result */
    $result = $router->route_request($method, $params, $id, 'stdio');
    if ($id === null) {
        return '';
    }
    // The adapter mints an HTTP session on initialize when it has an HTTP context; there is none here.
    unset($result['_session_id']);
    if (isset($result['error']) && is_array($result['error'])) {
        return encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => $result['error']]);
    }

    return encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) $result]);
}

/** @param array<array-key, mixed> $value */
function array_is_list_like(array $value): bool
{
    return $value !== [] && array_keys($value) === range(0, count($value) - 1);
}

/** @param array<string, mixed> $message */
function encode(array $message): string
{
    $json = wp_json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return is_string($json) ? $json : '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}';
}

function log_line(string $message): void
{
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- stderr is the log channel.
    fwrite(STDERR, '[wppilot stdio] ' . $message . "\n");
}
