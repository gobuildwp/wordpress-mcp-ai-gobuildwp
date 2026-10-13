<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom tables created in includes/oauth/schema.php; WordPress has no API for them. Table names come from $wpdb->prefix plus fixed suffixes - never from input - and every value goes through $wpdb->prepare().

namespace WPPilot\Troubleshoot\Checks;

use WPPilot\OAuth\Repositories\ClientRepository;

if (!defined('ABSPATH')) {
    exit();
}

const HTTP_TIMEOUT = 5;

/**
 * Options for the self-probe HTTP requests. TLS verification stays on for public sites; on a
 * local-only HTTPS host (self-signed or local-CA certs, see wppilot_likely_self_signed_https)
 * it is relaxed, otherwise every probe would fail on the certificate instead of testing anything.
 *
 * @return array{timeout: int, sslverify: bool}
 */
function http_options(): array
{
    return ['timeout' => HTTP_TIMEOUT, 'sslverify' => !\wppilot_likely_self_signed_https()];
}

/**
 * Run the diagnostics in dependency order. Failed prerequisites (abilities, transport, schema)
 * mark the checks that depend on them as skipped instead of piling redundant failures onto the
 * report.
 *
 * $method scopes the report to the connection method being troubleshot: 'oauth' drops the
 * Application Passwords check, 'password' drops the OAuth-only checks (and, with them, their
 * outbound probes), and 'token' drops both — an access token needs neither an application password
 * nor OAuth discovery, so either result would be noise in its report. Null runs everything.
 * Abilities, transport, permalinks, and anonymous REST apply to every method.
 *
 * @return list<array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}>
 */
function run_all(?string $method = null): array
{
    // These are connection-method slugs, not credentials — nothing sensitive is compared.
    $include_oauth = $method === null || $method === 'oauth';
    // @mago-expect lint:no-insecure-comparison
    $include_password = $method === null || $method === 'password';

    $results = [];

    $abilities = check_abilities();
    $results[] = $abilities;

    $transport = check_transport();
    $results[] = $transport;

    $results[] = check_permalinks();

    $results[] = check_rest_reachable();

    // Straight after REST reachability, because it answers the question the
    // rest of this screen only circles: does MCP itself respond?
    $results[] = $abilities['status'] === 'fail'
        ? skipped('mcp_handshake', __('MCP endpoint', domain: 'wppilot'), $abilities, $transport, $abilities)
        : check_mcp_handshake();

    $results[] = check_security_edge();

    // Applies to both connection methods: the sandbox is reachable (or not)
    // regardless of how the agent authenticates.
    if (!wppilot_is_wordpress_org_edition()) {
        $results[] = check_sandbox_exposure();
    }

    if ($include_oauth) {
        $schema = check_schema();
        $results[] = $schema;

        // With abilities off the OAuth endpoints are not registered at all (see
        // WPPilot\OAuth\boot), so the probes would only restate that failure.
        $oauth_ok = $abilities['status'] !== 'fail' && $transport['status'] !== 'fail' && $schema['status'] !== 'fail';
        $discovery_headers = [];
        $discovery = $oauth_ok
            ? check_discovery($discovery_headers)
            : skipped('discovery', __('OAuth discovery', domain: 'wppilot'), $abilities, $transport, $schema);
        $results[] = $discovery;

        $results[] = $oauth_ok
            ? check_registration()
            : skipped(
                'registration',
                __('OAuth client registration', domain: 'wppilot'),
                $abilities,
                $transport,
                $schema,
            );

        $results[] = $oauth_ok && $discovery['status'] === 'ok'
            ? check_bot_filter()
            : skipped(
                'bot_filter',
                __('Hosting bot filter', domain: 'wppilot'),
                $abilities,
                $transport,
                $schema,
                $discovery,
            );

        $results[] = $schema['status'] !== 'fail'
            ? check_limits()
            : skipped('limits', __('Registration limits', domain: 'wppilot'), $schema);

        $results[] = check_environment($discovery_headers);
    }

    if ($include_password) {
        $results[] = check_app_passwords();
    }

    $results[] = check_change_ledger();

    return $results;
}

/**
 * Where the change ledger is kept.
 *
 * A site whose database user cannot create tables keeps the ledger in one capped option, where it
 * holds far less history and concurrent writes can lose rows. Nothing else on the site would say
 * so, and the remedy (a privilege) is the host's to grant.
 *
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function check_change_ledger(): array
{
    $label = __('Change ledger', domain: 'wppilot');
    if (!function_exists('wppilot_change_storage_status')) {
        return info('change_ledger', $label, __('The change ledger is not loaded.', domain: 'wppilot'));
    }
    $status = \wppilot_change_storage_status();
    if ($status['storage'] === 'table') {
        return ok('change_ledger', $label, sprintf(
            /* translators: 1: number of recorded changes, 2: database table name */
            __('%1$d changes recorded in the %2$s table.', domain: 'wppilot'),
            $status['rows'],
            $status['table'],
        ));
    }
    if ($status['error'] === null) {
        return info('change_ledger', $label, __(
            'The change ledger is moving from its old option storage to its own table. This finishes on a later page load.',
            domain: 'wppilot',
        ));
    }

    return warn(
        'change_ledger',
        $label,
        sprintf(
            /* translators: 1: database table name, 2: database error message */
            __(
                'Changes are still kept in a single option (at most 500 rows), because the %1$s table could not be used: %2$s',
                domain: 'wppilot',
            ),
            $status['table'],
            $status['error']['message'],
        ),
        __(
            'Ask your host to grant the WordPress database user the CREATE and ALTER privileges. GoBuildWP tries again every hour and moves the existing history across on its own.',
            domain: 'wppilot',
        ),
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_abilities(): array
{
    $label = __('AI Abilities', domain: 'wppilot');
    if (\wppilot_is_enabled()) {
        return ok(
            'abilities',
            $label,
            __('AI Abilities are turned on; the MCP endpoints are registered.', domain: 'wppilot'),
        );
    }
    return fail(
        'abilities',
        $label,
        __(
            'AI Abilities are turned off, so no MCP endpoint exists and every client sees an empty or missing server.',
            domain: 'wppilot',
        ),
        __('Turn on AI Abilities in Step 1 of the Connect page, then run these checks again.', domain: 'wppilot'),
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
// $action names a symptom key the UI can jump to (a report row's "Open the fix below" button),
// so a remedy that lives inside a symptom branch is one click away instead of a scavenger hunt.
// One positional parameter per key of the fixed result shape; an options array would just
// re-implement the shape without the type safety.
// @mago-expect lint:excessive-parameter-list
function result(
    string $id,
    string $status,
    string $label,
    string $message,
    string $remedy = '',
    string $action = '',
    string $copy = '',
): array {
    return [
        'id' => $id,
        'status' => $status,
        'label' => $label,
        'message' => $message,
        'remedy' => $remedy,
        'action' => $action,
        'copy' => $copy,
    ];
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function ok(string $id, string $label, string $message): array
{
    return result($id, status: 'ok', label: $label, message: $message);
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
// Mirrors result(): one positional parameter per key of the fixed result shape.
// @mago-expect lint:excessive-parameter-list
function warn(
    string $id,
    string $label,
    string $message,
    string $remedy = '',
    string $action = '',
    string $copy = '',
): array {
    return result(
        $id,
        status: 'warning',
        label: $label,
        message: $message,
        remedy: $remedy,
        action: $action,
        copy: $copy,
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function fail(string $id, string $label, string $message, string $remedy = ''): array
{
    return result($id, status: 'fail', label: $label, message: $message, remedy: $remedy);
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function info(string $id, string $label, string $message, string $remedy = ''): array
{
    return result($id, status: 'info', label: $label, message: $message, remedy: $remedy);
}

/**
 * A skipped entry naming the failed prerequisite(s), so the report explains the gap instead of
 * silently shortening.
 *
 * @param array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} ...$failed
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function skipped(string $id, string $label, array ...$failed): array
{
    $names = [];
    foreach ($failed as $f) {
        if ($f['status'] !== 'fail') {
            continue;
        }
        $names[] = $f['label'];
    }
    return result(
        $id,
        status: 'skipped',
        label: $label,
        message: sprintf(
            /* translators: %s: comma-separated list of failed prerequisite check names */
            __('Skipped: fix "%s" first.', domain: 'wppilot'),
            implode('", "', $names),
        ),
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_transport(): array
{
    $label = __('Secure transport', domain: 'wppilot');
    if (\wppilot_oauth_transport_allowed()) {
        return ok(
            'transport',
            $label,
            __(
                'The site is served over HTTPS (or is a local dev environment), so both connection methods are available.',
                domain: 'wppilot',
            ),
        );
    }
    return fail(
        'transport',
        $label,
        __(
            'This site is served over plain HTTP on a non-local environment. GoBuildWP does not register any OAuth endpoint here, because authorization codes and tokens would travel in cleartext.',
            domain: 'wppilot',
        ),
        __(
            'Serve the site over HTTPS. Application Passwords are equally unavailable on plain HTTP.',
            domain: 'wppilot',
        ),
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_permalinks(): array
{
    $label = __('Permalink structure', domain: 'wppilot');
    $structure = (string) get_option('permalink_structure', default_value: '');
    if ($structure !== '') {
        return ok(
            'permalinks',
            $label,
            __(
                'Pretty permalinks are enabled; MCP and OAuth URLs use the standard /wp-json/ path form.',
                domain: 'wppilot',
            ),
        );
    }
    return warn(
        'permalinks',
        $label,
        __(
            'Permalinks are set to "Plain", so MCP URLs take the index.php?rest_route= form. Connections can work this way, but strictly spec-compliant clients can fail OAuth discovery on it, and path-based cache/WAF rules on managed hosts do not recognize these URLs as API traffic.',
            domain: 'wppilot',
        ),
        sprintf(
            /* translators: %s: permalink settings URL */
            __(
                'Switch to "Post name" under %s, then reconnect your AI client. A client that refreshes its token on the first 401 recovers by itself; one that does not needs a manual reconnect.',
                domain: 'wppilot',
            ),
            admin_url('options-permalink.php'),
        ),
    );
}

/**
 * Confirm the PHP sandbox is not reachable over HTTP.
 *
 * The sandbox holds agent-authored PHP under wp-content. GoBuildWP writes
 * .htaccess and web.config guards into it, but nginx and Caddy read neither, so
 * on those servers the only way to know is to ask the server itself. A sandbox
 * that answers 200 executes agent PHP outside the WordPress bootstrap, and so
 * outside the safety policy entirely.
 *
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function check_sandbox_exposure(): array
{
    $label = __('PHP sandbox exposure', domain: 'wppilot');

    if (!is_dir(WPPILOT_SANDBOX_DIR)) {
        return ok(
            'sandbox_exposure',
            $label,
            __('The sandbox directory does not exist yet, so there is nothing to serve.', domain: 'wppilot'),
        );
    }

    $missing = [];
    foreach (array_keys(\wppilot_sandbox_guard_files()) as $basename) {
        if (!file_exists(WPPILOT_SANDBOX_DIR . $basename)) {
            $missing[] = $basename;
        }
    }

    // A probe file is not created just to test this: the check reads whatever
    // the guards already deny. index.php always exists once the directory is
    // hardened, and a correctly configured server refuses to serve it.
    $probe_url = content_url('wppilot-sandbox/index.php');
    $response = wp_remote_get($probe_url, http_options());

    if (is_wp_error($response)) {
        return warn(
            'sandbox_exposure',
            $label,
            sprintf(
                /* translators: %s: HTTP error message */
                __('Could not probe the sandbox directory over HTTP: %s', domain: 'wppilot'),
                $response->get_error_message(),
            ),
            sprintf(
                /* translators: %s: sandbox URL */
                __('Open %s in a private browser window. It must not return a page.', domain: 'wppilot'),
                $probe_url,
            ),
        );
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code >= 400) {
        return ok(
            'sandbox_exposure',
            $label,
            sprintf(
                /* translators: %d: HTTP status code */
                __('The web server refuses to serve the sandbox directory (HTTP %d).', domain: 'wppilot'),
                $code,
            ),
        );
    }

    $server_hint = $missing === []
        ? __(
            'The .htaccess and web.config guards are in place, so this server is most likely nginx or Caddy, which read neither.',
            domain: 'wppilot',
        )
        : sprintf(
            /* translators: %s: comma-separated list of missing filenames */
            __('These guard files are missing from the sandbox directory: %s.', domain: 'wppilot'),
            implode(', ', $missing),
        );

    return fail(
        'sandbox_exposure',
        $label,
        sprintf(
            /* translators: 1: HTTP status code, 2: sandbox URL */
            __(
                'The sandbox directory is served over HTTP (HTTP %1$d at %2$s). Any PHP an agent writes there can be executed by anyone who knows the filename, outside WordPress and outside the GoBuildWP safety policy.',
                domain: 'wppilot',
            ),
            $code,
            $probe_url,
        ),
        $server_hint . ' '
            . __(
                'Block the directory in your server configuration. For nginx, add inside the server block: location ^~ /wp-content/wppilot-sandbox/ { deny all; return 404; } — then reload nginx and run these checks again.',
                domain: 'wppilot',
            ),
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_schema(): array
{
    $label = __('OAuth storage', domain: 'wppilot');
    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $wpdb */
    $table = $wpdb->prefix . 'wppilot_oauth_clients';
    // @mago-expect analysis:possibly-invalid-argument
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if (is_string($found) && $found === $table) {
        return ok('schema', $label, __('The OAuth tables are installed.', domain: 'wppilot'));
    }
    return fail(
        'schema',
        $label,
        __('The OAuth tables are missing, so no OAuth client can register or authenticate.', domain: 'wppilot'),
        __(
            'Deactivate and reactivate GoBuildWP to re-run the installer, then run these checks again.',
            domain: 'wppilot',
        ),
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_rest_reachable(): array
{
    $label = __('Anonymous REST API', domain: 'wppilot');
    $response = wp_remote_get(rest_url(), http_options());
    if (is_wp_error($response)) {
        return fail(
            'rest',
            $label,
            sprintf(
                /* translators: %s: HTTP error message */
                __('The site could not fetch its own REST index: %s', domain: 'wppilot'),
                $response->get_error_message(),
            ),
            __(
                'On a public site, ask your host why the server cannot reach its own public URL (loopback requests may be blocked).',
                domain: 'wppilot',
            ),
        );
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code === 401 || $code === 403) {
        return fail(
            'rest',
            $label,
            sprintf(
                /* translators: %d: HTTP status code */
                __(
                    'The REST API answers anonymous requests with HTTP %d. A security plugin is likely restricting REST access to logged-in users, which also blocks the OAuth registration step AI clients perform.',
                    domain: 'wppilot',
                ),
                $code,
            ),
            __(
                'In your security plugin, allow unauthenticated access to the REST API (at minimum the wppilot/v1 and mcp namespaces), then run these checks again.',
                domain: 'wppilot',
            ),
        );
    }
    if ($code !== 200) {
        return warn(
            'rest',
            $label,
            sprintf(
                /* translators: %d: HTTP status code */
                __('The REST index answered HTTP %d instead of 200.', domain: 'wppilot'),
                $code,
            ),
        );
    }
    return ok('rest', $label, __('The REST API answers anonymous requests.', domain: 'wppilot'));
}

/**
 * Normalize a wp_remote_* headers value (array or CaseInsensitiveDictionary) into a flat
 * lowercase-keyed string map.
 *
 * @return array<string, string>
 */
function normalize_headers(mixed $raw): array
{
    if (is_object($raw) && method_exists($raw, 'getAll')) {
        // @mago-expect analysis:mixed-assignment
        $raw = $raw->getAll();
    }
    if (!is_array($raw)) {
        return [];
    }
    $headers = [];
    /** @var mixed $value */
    foreach ($raw as $name => $value) {
        if (is_array($value)) {
            $value = implode(', ', array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $value));
        }
        if (!is_scalar($value)) {
            continue;
        }
        $headers[strtolower((string) $name)] = (string) $value;
    }
    return $headers;
}

/**
 * @param array<string, string> $headers Filled with the last discovery response's headers, for
 *                                       the environment check.
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
/**
 * Speak MCP to our own endpoint and see whether it answers like an MCP server.
 *
 * Every other check here establishes that a route exists, that REST is
 * reachable, that discovery documents parse. None of them ever sent a JSON-RPC
 * frame, so the one question an operator actually asks — "is MCP working?" —
 * was the one thing the diagnostics could not answer. A site could pass every
 * check and still fail the first real client.
 *
 * The probe is deliberately unauthenticated, because the correct answer is a
 * refusal. An MCP endpoint that returns 401 to an anonymous `initialize` is
 * behaving exactly right: the route is mounted, the JSON-RPC layer is running,
 * and authentication is enforced. A 404 means the route never registered; a 200
 * means the endpoint is answering strangers, which is a far worse finding than
 * anything else on this screen.
 *
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function check_mcp_handshake(): array
{
    $label = __('MCP endpoint', domain: 'wppilot');
    $url = rest_url('mcp/wppilot');

    $options = http_options();
    $options['headers'] = array_merge(is_array($options['headers'] ?? null) ? $options['headers'] : [], [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
    ]);
    $options['body'] = (string) wp_json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'wppilot-diagnostics', 'version' => (string) WPPILOT_VERSION],
        ],
    ]);

    $response = wp_remote_post($url, $options);
    if (is_wp_error($response)) {
        // A warning, not a failure. This probe is a loopback request — the site
        // calling its own public URL — and plenty of correctly working setups
        // cannot do that: containers whose published port is not reachable from
        // inside, hosts that block loopback, split-horizon DNS. The endpoint may
        // be serving outside clients perfectly while this probe cannot see it,
        // so reporting "broken" here would send people fixing what is not wrong.
        return warn(
            'mcp_handshake',
            $label,
            sprintf(
                /* translators: %s: HTTP error message */
                __(
                    'This site could not call its own MCP endpoint: %s. That often means loopback requests are blocked rather than that MCP is down.',
                    domain: 'wppilot',
                ),
                $response->get_error_message(),
            ),
            __(
                'Connect from your AI client to find out for certain. If the client works, nothing here needs fixing; if it also fails, ask your host whether the site can reach its own public URL.',
                domain: 'wppilot',
            ),
        );
    }

    $code = (int) wp_remote_retrieve_response_code($response);

    if ($code === 404) {
        return fail(
            'mcp_handshake',
            $label,
            __('The MCP endpoint is not registered, so no AI client can connect.', domain: 'wppilot'),
            __(
                'Turn on AI Abilities on the Overview screen. If it is already on, the MCP Adapter failed to load — reinstall the GoBuildWP release build ZIP.',
                domain: 'wppilot',
            ),
        );
    }

    if ($code === 401 || $code === 403) {
        return ok(
            'mcp_handshake',
            $label,
            __(
                'The MCP endpoint answers JSON-RPC and refuses unauthenticated calls, which is what a client should meet before it signs in.',
                domain: 'wppilot',
            ),
        );
    }

    $body = (string) wp_remote_retrieve_body($response);
    /** @var mixed $decoded */
    $decoded = json_decode($body, associative: true);

    $result = is_array($decoded) && is_array($decoded['result'] ?? null) ? $decoded['result'] : [];

    if ($code === 200 && ($result['serverInfo'] ?? null) !== null) {
        return fail(
            'mcp_handshake',
            $label,
            __(
                'The MCP endpoint completed a handshake for an anonymous caller. Anyone who can reach this URL can drive the site through AI abilities.',
                domain: 'wppilot',
            ),
            __(
                'Something is authenticating requests that should be rejected — check for a plugin that force-authenticates REST calls, then re-run these checks. Turn AI Abilities off until this reads as refused.',
                domain: 'wppilot',
            ),
        );
    }

    return warn(
        'mcp_handshake',
        $label,
        sprintf(
            /* translators: %d: HTTP status code */
            __(
                'The MCP endpoint answered HTTP %d instead of refusing an unauthenticated handshake. It is reachable, but not responding the way a client expects.',
                domain: 'wppilot',
            ),
            $code,
        ),
    );
}

/**
 * @param array<string, string> $headers Filled with the response headers, for check_environment().
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function check_discovery(array &$headers): array
{
    $label = __('OAuth discovery', domain: 'wppilot');
    // Single source for the URL set, shared with the request handler (endpoints/discovery.php). URLs
    // are absolute (origin + path), never home_url($path), so a subdirectory is not prepended twice.
    // The advertised protected-resource URL is required; the authorization-server document is
    // reachable through several interchangeable forms, so its group only fails when they all do.
    $probes = \WPPilot\OAuth\Endpoints\Discovery\discovery_probes(home_url(), \WPPilot\OAuth\resource_identifier());

    // group => whether any member answered; group => a failure to report if none did.
    $group_satisfied = [];
    $group_failure = [];

    foreach ($probes as $probe) {
        $outcome = probe_discovery_document($probe, $label, $headers);
        if ($outcome['ok']) {
            if ($probe['group'] !== '') {
                $group_satisfied[$probe['group']] = true;
            }
            continue;
        }
        if ($probe['requirement'] === 'required') {
            return $outcome['failure'] ?? discovery_generic_failure($label);
        }
        if ($probe['requirement'] === 'any') {
            $group_satisfied[$probe['group']] ??= false;
            if ($outcome['failure'] !== null) {
                $group_failure[$probe['group']] ??= $outcome['failure'];
            }
        }

        // 'optional' probes never fail discovery.
    }

    foreach ($group_satisfied as $group => $satisfied) {
        if (!$satisfied) {
            return $group_failure[$group] ?? discovery_generic_failure($label);
        }
    }

    return ok(
        'discovery',
        $label,
        __(
            'The discovery documents answer with valid JSON on the paths this site advertises. Note: this probe runs from the server itself, so a firewall that blocks only external or datacenter IPs can still affect real AI clients.',
            domain: 'wppilot',
        ),
    );
}

/**
 * Fetch one discovery URL and validate it: HTTP 200, an `application/json` content type (parameters
 * such as charset ignored), a JSON body, and the identifying field carrying this site's exact
 * resource or issuer. On success the response headers are captured for the environment check.
 *
 * @param array{url: string, field: string, expected: string, requirement: string, group: string, label: string} $probe
 * @param array<string, string> $headers
 * @return array{ok: bool, failure: ?array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}}
 */
function probe_discovery_document(array $probe, string $label, array &$headers): array
{
    // Redirects are NOT followed: a redirect on these URLs is itself the finding. Hosting platforms
    // (seen on WP Engine) ship edge rules that 301 the OAuth well-known paths to the homepage;
    // following the redirect would only report "200 but not JSON", while the redirect names the
    // actual problem and its owner.
    $response = wp_remote_get($probe['url'], http_options() + ['redirection' => 0]);
    if (is_wp_error($response)) {
        return [
            'ok' => false,
            'failure' => fail(
                'discovery',
                $label,
                sprintf(
                    /* translators: 1: discovery URL, 2: HTTP error message */
                    __('Fetching %1$s failed: %2$s', domain: 'wppilot'),
                    $probe['url'],
                    $response->get_error_message(),
                ),
            ),
        ];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code >= 300 && $code < 400) {
        $location = wp_remote_retrieve_header($response, header: 'location');
        $location = is_string($location) && $location !== '' ? $location : __('another URL', domain: 'wppilot');
        return [
            'ok' => false,
            'failure' => fail(
                'discovery',
                $label,
                sprintf(
                    /* translators: 1: discovery URL, 2: HTTP status code, 3: redirect target URL */
                    __(
                        '%1$s is redirected by the server (HTTP %2$d to %3$s) instead of being answered. AI clients follow the redirect, receive a web page instead of the OAuth metadata, and sign-in fails with a registration error. This is typically a hosting-level rule on the /.well-known/ paths, not something WordPress controls.',
                        domain: 'wppilot',
                    ),
                    $probe['url'],
                    $code,
                    $location,
                ),
                __(
                    'Ask your hosting support to let this path, including any subpath, pass through to WordPress ("proxy pass as dynamic"), then run these checks again.',
                    domain: 'wppilot',
                ),
            ),
        ];
    }
    $media_type = discovery_media_type(wp_remote_retrieve_header($response, header: 'content-type'));
    // @mago-expect analysis:mixed-assignment
    $body = json_decode(wp_remote_retrieve_body($response), associative: true);
    if ($code !== 200 || $media_type !== 'application/json' || !is_array($body)) {
        return [
            'ok' => false,
            'failure' => fail(
                'discovery',
                $label,
                sprintf(
                    /* translators: 1: discovery URL, 2: HTTP status code */
                    __(
                        '%1$s answered HTTP %2$d, or without an application/json body. AI clients cannot find the sign-in endpoints. A cache or firewall layer may be intercepting the URL.',
                        domain: 'wppilot',
                    ),
                    $probe['url'],
                    $code,
                ),
                __(
                    'Exclude the /.well-known/oauth-* paths from page caching and firewall challenges, then run these checks again.',
                    domain: 'wppilot',
                ),
            ),
        ];
    }
    // The document must be ours: the identifying field present AND carrying the exact resource or
    // issuer this site advertises, so a stray OAuth document from another plugin is caught.
    if (($body[$probe['field']] ?? null) !== $probe['expected']) {
        return [
            'ok' => false,
            'failure' => fail(
                'discovery',
                $label,
                sprintf(
                    /* translators: 1: discovery URL, 2: JSON field name */
                    __(
                        '%1$s returned JSON whose "%2$s" field is missing or does not match this site. Another plugin may be serving its own OAuth metadata on this path.',
                        domain: 'wppilot',
                    ),
                    $probe['url'],
                    $probe['field'],
                ),
            ),
        ];
    }
    $headers = normalize_headers(wp_remote_retrieve_headers($response));
    return ['ok' => true, 'failure' => null];
}

/**
 * The media type of a Content-Type header, lowercased and without parameters, so
 * `application/json; charset=UTF-8` is recognized as `application/json`.
 */
function discovery_media_type(mixed $header): string
{
    if (!is_string($header)) {
        return '';
    }
    $type = strtolower(trim($header));
    $semicolon = strpos($type, needle: ';');
    return $semicolon === false ? $type : rtrim(substr($type, offset: 0, length: $semicolon));
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function discovery_generic_failure(string $label): array
{
    return fail(
        'discovery',
        $label,
        __(
            'The OAuth discovery documents could not be reached. AI clients cannot find the sign-in endpoints.',
            domain: 'wppilot',
        ),
        __(
            'Exclude the /.well-known/oauth-* paths from page caching and firewall challenges, then run these checks again.',
            domain: 'wppilot',
        ),
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_registration(): array
{
    $label = __('OAuth client registration', domain: 'wppilot');
    // Single-use pass so the probe skips the per-IP registration limits: every self-test comes
    // from the server's own address, and counting it would let repeated diagnostics runs trip a
    // rate limit that has nothing to do with the AI clients (they use their own address buckets).
    $token = wp_generate_password(32, special_chars: false, extra_special_chars: false);
    set_transient('wppilot_oauth_selftest_' . hash('sha256', $token), value: '1', expiration: MINUTE_IN_SECONDS);
    $response = wp_remote_post(rest_url('wppilot/v1/oauth/register'), [
        'timeout' => HTTP_TIMEOUT,
        'sslverify' => !\wppilot_likely_self_signed_https(),
        'headers' => ['Content-Type' => 'application/json', 'X-WPPilot-Self-Test' => $token],
        'body' => (string) wp_json_encode([
            'client_name' => 'GoBuildWP Self-Test',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]),
    ]);
    if (is_wp_error($response)) {
        return fail(
            'registration',
            $label,
            sprintf(
                /* translators: %s: HTTP error message */
                __('The registration test request failed: %s', domain: 'wppilot'),
                $response->get_error_message(),
            ),
        );
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    // @mago-expect analysis:mixed-assignment
    $body = json_decode(wp_remote_retrieve_body($response), associative: true);
    if ($code === 201 && is_array($body) && is_string($body['client_id'] ?? null)) {
        (new ClientRepository())->revoke($body['client_id']);
        return ok(
            'registration',
            $label,
            __(
                'A test registration succeeded (the test client was deleted right away). If an AI client still cannot register, the block is between that client and this site — typically a firewall or bot filter on datacenter IPs — or its attempts exhausted the per-address limits; see the registration-error symptom below.',
                domain: 'wppilot',
            ),
        );
    }
    if ($code === 429) {
        return warn(
            'registration',
            $label,
            __(
                'The test registration was rate-limited for the server’s own address. This does not affect AI clients (each connects from its own address with its own budget); it usually means the self-test pass was not honored, and it clears within the hour.',
                domain: 'wppilot',
            ),
            __(
                'Run the diagnostics again in an hour. If an AI client reports "too many attempts", wait an hour or create a manual client ID that skips registration entirely.',
                domain: 'wppilot',
            ),
            action: 'registration',
        );
    }
    return fail(
        'registration',
        $label,
        sprintf(
            /* translators: %d: HTTP status code */
            __(
                'The registration endpoint answered HTTP %d instead of 201. A security plugin or firewall is likely intercepting POST requests to the REST API.',
                domain: 'wppilot',
            ),
            $code,
        ),
        __(
            'Allow anonymous POST requests to /wp-json/wppilot/v1/oauth/register, then run these checks again.',
            domain: 'wppilot',
        ),
    );
}

/**
 * The HTTP-library user agents common AI clients connect with (Claude.ai's OAuth client sends
 * python-httpx). Hosts with edge bot protection often reject these signatures while letting
 * browser and WordPress user agents through, which strands connector sign-in before it reaches
 * PHP — the server-side checks all pass while every real client fails.
 *
 * @return list<string>
 */
function bot_filter_user_agents(): array
{
    /** @var mixed $agents */
    $agents = apply_filters('wppilot_troubleshoot_bot_filter_user_agents', [
        'python-httpx/0.28.1',
        'python-requests/2.32.3',
        'node',
        'Go-http-client/2.0',
    ]);
    if (!is_array($agents)) {
        return [];
    }
    $clean = [];
    /** @var mixed $agent */
    foreach ($agents as $agent) {
        if (!is_string($agent) || $agent === '') {
            continue;
        }
        $clean[] = $agent;
    }
    return $clean;
}

/**
 * Names of the security / edge layers detected in front of the site, from response headers and the
 * active-plugin list. Presence only — this does not prove any of them is blocking.
 *
 * @param array<string, string> $headers        Lower-cased response headers from a self-probe.
 * @param list<string>          $active_plugins  Plugin basenames, e.g. "wordfence/wordfence.php".
 * @return list<string>
 */
function detect_security_edge(array $headers, array $active_plugins): array
{
    $found = [];

    $server = strtolower($headers['server'] ?? '');
    if (array_key_exists('cf-ray', $headers) || str_contains($server, 'cloudflare')) {
        $found[] = 'Cloudflare';
    }
    if (array_key_exists('x-sucuri-id', $headers) || array_key_exists('x-sucuri-cache', $headers)) {
        $found[] = 'Sucuri';
    }
    foreach ($headers as $name => $_value) {
        if (!str_starts_with($name, 'x-wpe-') && !str_starts_with($name, 'x-wpengine')) {
            continue;
        }
        $found[] = 'WP Engine';
        break;
    }
    if (str_contains($server, 'litespeed')) {
        $found[] = 'LiteSpeed';
    }

    $plugin_labels = [
        'wordfence/' => 'Wordfence',
        'better-wp-security/' => 'Solid Security',
        'ithemes-security-pro/' => 'Solid Security',
        'sucuri-scanner/' => 'Sucuri Security',
        'all-in-one-wp-security-and-firewall/' => 'All-In-One Security',
        'wp-cerber/' => 'WP Cerber',
        'wp-simple-firewall/' => 'Shield Security',
        'malcare-security/' => 'MalCare',
    ];
    foreach ($active_plugins as $plugin) {
        foreach ($plugin_labels as $slug => $label) {
            if (!str_starts_with($plugin, $slug)) {
                continue;
            }
            $found[] = $label;
        }
    }

    return array_values(array_unique($found));
}

/**
 * The security / edge layers detected in front of this site right now, cached for the duration of
 * the request so the diagnostics run and the support report share a single self-probe. Combines the
 * response headers of a self-request with the active-plugin list.
 *
 * @return list<string>
 */
function current_security_edge_layers(): array
{
    /** @var list<string>|null $cached */
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $headers = [];
    $response = wp_remote_get(home_url('/'), http_options() + ['redirection' => 0]);
    if (!is_wp_error($response)) {
        $headers = normalize_headers(wp_remote_retrieve_headers($response));
    }

    // @mago-expect analysis:mixed-assignment
    $raw_active = \get_option('active_plugins');
    $active = is_array($raw_active) ? $raw_active : [];
    $plugins = [];
    /** @var mixed $plugin */
    foreach ($active as $plugin) {
        if (!is_string($plugin)) {
            continue;
        }
        $plugins[] = $plugin;
    }

    // @mago-expect lint:inline-variable-return
    $cached = detect_security_edge($headers, $plugins);
    return $cached;
}

/**
 * Report which security / edge layers sit in front of the site. Presence only: this never asserts a
 * layer is blocking (the self-probe runs from the server's own IP, so an external block can be
 * invisible). Runs for both connection methods, since a CDN/WAF blocks App Password MCP traffic too.
 *
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function check_security_edge(): array
{
    $label = __('Security & edge layers', domain: 'wppilot');

    $found = current_security_edge_layers();
    if ($found === []) {
        return ok(
            'security_edge',
            $label,
            __('No CDN, WAF, or security plugin was detected in front of the site.', domain: 'wppilot'),
        );
    }

    return info(
        'security_edge',
        $label,
        sprintf(
            /* translators: %s: comma-separated list of detected security/edge layer names */
            __(
                'Detected in front of your site: %s. These protect your site — keep them. Our checks pass from here, but such layers can still filter AI clients from the outside. If a client cannot connect, ask for the MCP paths to be allowed through — do not turn the security off.',
                domain: 'wppilot',
            ),
            implode(', ', $found),
        ),
        __(
            'Allow these paths through the CDN/WAF/security layer, by path (not by User-Agent): /wp-json/mcp/wppilot and /.well-known/oauth-* . Keep every other protection on.',
            domain: 'wppilot',
        ),
    );
}

/**
 * Probe the public discovery URL with the user agents above. The discovery check has already
 * proven the same URL answers the server's default user agent, so any signature rejected here
 * is the edge layer discriminating by client fingerprint — a warning, not a failure: the site
 * itself is healthy and the fix belongs to the host.
 *
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function check_bot_filter(): array
{
    $label = __('Hosting bot filter', domain: 'wppilot');
    $url = home_url('/.well-known/oauth-authorization-server');
    $blocked = [];
    foreach (bot_filter_user_agents() as $agent) {
        $response = wp_remote_get($url, array_merge(http_options(), ['user-agent' => $agent]));
        if (is_wp_error($response)) {
            $blocked[$agent] = $response->get_error_message();
            continue;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        // 403 is the plain rejection, 503 the challenge page some bot filters serve instead.
        if ($code === 403 || $code === 503) {
            $blocked[$agent] = sprintf('HTTP %d', $code);
        }
    }
    if ($blocked === []) {
        return ok(
            'bot_filter',
            $label,
            __(
                'The tested non-browser user agents all reach the discovery URL. Note: this probe runs from the server\'s own address, so a filter acting only on external or datacenter IPs can still affect real AI clients.',
                domain: 'wppilot',
            ),
        );
    }
    $list = [];
    foreach ($blocked as $agent => $reason) {
        $list[] = sprintf('"%s" (%s)', $agent, $reason);
    }
    $first_agent = array_key_first($blocked);
    return warn(
        'bot_filter',
        $label,
        sprintf(
            /* translators: %s: comma-separated list of blocked user agents with the response each received */
            __(
                'A security layer in front of this site rejects some non-browser user agents on the OAuth discovery URL: %s. AI clients connect with signatures like these, so their sign-in requests are likely blocked before they reach WordPress, even though every server-side check passes.',
                domain: 'wppilot',
            ),
            implode(', ', $list),
        ),
        __(
            'Ask your hosting support to allow this traffic for your site. The message below is ready to copy into a support ticket.',
            domain: 'wppilot',
        ),
        copy: sprintf(
            /* translators: 1: site URL, 2: probed discovery URL, 3: blocked user agent, 4: response it received */
            __(
                'Hello, my WordPress site %1$s runs GoBuildWP, a plugin that lets AI clients (Claude, ChatGPT and similar services) connect to the site through OAuth. Your edge layer is rejecting those clients\' requests before they reach WordPress, so the connection cannot complete. I can reproduce it: a request to %2$s with the user agent "%3$s" is rejected (%4$s), while the same request with a regular user agent goes through. Could you allow this traffic for my site? The affected paths are /.well-known/oauth-* and /wp-json/. Thank you.',
                domain: 'wppilot',
            ),
            home_url(),
            $url,
            $first_agent,
            $blocked[$first_agent],
        ),
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_limits(): array
{
    $label = __('Registration limits', domain: 'wppilot');
    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $wpdb */
    $active = \WPPilot\OAuth\ClientValidation\active_client_count();
    $cap = \WPPilot\OAuth\ClientValidation\max_clients_per_site();
    $pending_cutoff = gmdate('Y-m-d H:i:s', time() - \WPPilot\OAuth\ClientValidation\STALE_UNUSED_CLIENT_TTL);
    // @mago-expect analysis:possibly-invalid-argument
    // @mago-expect analysis:possibly-invalid-argument
    $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wppilot_oauth_clients
             WHERE last_used_at IS NULL AND admin_created = 0 AND created_at >= %s", $pending_cutoff));
    if ($active >= $cap) {
        return fail(
            'limits',
            $label,
            sprintf(
                /* translators: 1: active connection count, 2: connection cap */
                __(
                    'All %2$d connection slots are in use (%1$d active connections). New registrations are refused until one expires or is revoked.',
                    domain: 'wppilot',
                ),
                $active,
                $cap,
            ),
            __('Revoke unused connections from the Connected Apps page.', domain: 'wppilot'),
        );
    }
    if ($pending >= 5) {
        return warn(
            'limits',
            $label,
            sprintf(
                /* translators: %d: count of pending registrations from the last 24 hours */
                __(
                    '%d registrations from the last 24 hours never completed sign-in. Each failed connection attempt leaves one behind, and enough of them trip the per-address limits, so an AI client that keeps retrying can start receiving "too many attempts" errors even after the original problem is fixed. They are cleaned up automatically after 24 hours.',
                    domain: 'wppilot',
                ),
                $pending,
            ),
            __(
                'Wait for the cleanup, or create a manual client ID that skips registration entirely (admin-created IDs bypass these limits).',
                domain: 'wppilot',
            ),
            action: 'registration',
        );
    }
    return ok(
        'limits',
        $label,
        sprintf(
            /* translators: 1: active connection count, 2: connection cap, 3: pending registration count */
            __(
                '%1$d of %2$d connection slots in use, %3$d pending registrations in the last 24 hours.',
                domain: 'wppilot',
            ),
            $active,
            $cap,
            $pending,
        ),
    );
}

/**
 * Informational only: surface the serving stack seen on the discovery response so the
 * "server healthy but AI client blocked upstream" conversation has facts to start from.
 *
 * @param array<string, string> $headers
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function check_environment(array $headers): array
{
    $label = __('Serving stack', domain: 'wppilot');
    if ($headers === []) {
        return result(
            'environment',
            status: 'skipped',
            label: $label,
            message: __('No discovery response headers available (see the discovery check).', domain: 'wppilot'),
        );
    }
    $seen = [];
    foreach (['server', 'x-powered-by', 'cf-ray', 'x-cache', 'x-cacheable'] as $name) {
        if (($headers[$name] ?? '') === '') {
            continue;
        }
        $seen[] = $name . ': ' . $headers[$name];
    }
    $stack = $seen === [] ? __('No identifying headers observed.', domain: 'wppilot') : implode(' · ', $seen);
    $note = '';
    if (($headers['cf-ray'] ?? '') !== '' || stripos($headers['server'] ?? '', needle: 'cloudflare') !== false) {
        $note =
            ' '
            . __(
                'Cloudflare is in front of this site: its bot protection can challenge AI clients connecting from datacenter addresses even though this server-side probe passes. If registration keeps failing for clients only, review the Cloudflare security settings for this zone.',
                domain: 'wppilot',
            );
    }
    return ok('environment', $label, $stack . $note);
}

/**
 * A plain-text diagnostic report for support: site context, detected layers, and every check with
 * its status. No secrets.
 *
 * @param array{site_url: string, wppilot_version: string, wp_version: string, php_version: string, method: string} $meta
 * @param list<string>                                                                                                $detected_layers
 * @param list<array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}> $checks
 */
function build_support_report(array $meta, array $detected_layers, array $checks): string
{
    $lines = [];
    $lines[] = 'GoBuildWP connection diagnostic';
    $lines[] = 'Site: ' . $meta['site_url'];
    $lines[] =
        'GoBuildWP: '
        . $meta['wppilot_version']
        . ' | WordPress: '
        . $meta['wp_version']
        . ' | PHP: '
        . $meta['php_version'];
    $lines[] = 'Connection method: ' . ($meta['method'] === '' ? 'all' : $meta['method']);
    $lines[] = 'Security/edge detected: ' . ($detected_layers === [] ? 'none' : implode(', ', $detected_layers));
    $lines[] = '';
    $lines[] = 'Checks:';

    // Every check, not just the failing ones: the full battery makes the report self-describing, and
    // since different GoBuildWP versions ship different checks, the list itself shows what this
    // version verified.
    if ($checks === []) {
        $lines[] = '- none';
    }
    foreach ($checks as $check) {
        $lines[] = '- [' . strtoupper($check['status']) . '] ' . $check['label'] . ': ' . $check['message'];
    }

    return implode("\n", $lines);
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_app_passwords(): array
{
    $label = __('Application Passwords', domain: 'wppilot');
    $status = \wppilot_app_passwords_status();
    if ($status['available']) {
        return ok(
            'app_passwords',
            $label,
            __('Application Passwords are available for the password connection method.', domain: 'wppilot'),
        );
    }
    if ($status['reason'] === 'filtered') {
        $blocker = $status['blocker'] ?? null;
        if (is_array($blocker)) {
            return fail('app_passwords', $label, $blocker['message'], $blocker['remedy']);
        }
        return fail('app_passwords', $label, $status['message']);
    }
    return warn('app_passwords', $label, $status['message']);
}
