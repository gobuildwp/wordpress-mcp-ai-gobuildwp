<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Troubleshoot\Doctor;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Connection Doctor: why an MCP client that should connect does not.
 *
 * The other diagnostics establish that GoBuildWP is configured correctly. This module looks at what
 * stands between a client and GoBuildWP: a CDN or WAF answering in WordPress's place, a web server that
 * drops the Authorization header before PHP sees it, a clock far enough off to spoil OAuth grants,
 * and URL settings that make OAuth redirect and issuer checks fail. It sends real requests to the
 * site's own MCP endpoints — with and without credentials — and classifies each answer by who sent it:
 * WordPress (a JSON body), or something in front of it (an HTML block page, a challenge, a 406).
 *
 * Every probe is a loopback request, so it travels from the server to itself. That catches rules keyed
 * on method, path, headers and body, which is how ModSecurity and most host WAFs block MCP traffic, but
 * not rules keyed on the caller's IP or country; the report says so rather than promising a clean
 * bill of health.
 *
 * The classifiers are pure functions of a captured response, so the fixture tests in
 * ConnectionDoctorTest exercise every verdict without a network.
 */

if (!defined('ABSPATH')) {
    exit();
}

const ECHO_ROUTE = '/troubleshoot/doctor-echo';

/** Carries the one-time probe id even when the web server strips Authorization. */
const PROBE_HEADER = 'X-WPPilot-Doctor';

/**
 * A scheme no authenticator claims, so the echo probe tests header delivery without WordPress core,
 * Application Passwords or the Bearer middleware reacting to it (and without counting as a failed login
 * in a security plugin).
 */
const PROBE_SCHEME = 'WPPilot-Doctor';

const PROBE_TTL = 120;

const HTTP_TIMEOUT = 8;

/** Where the clock check reads a trusted Date header: a host WordPress already contacts for updates. */
const CLOCK_URL = 'https://api.wordpress.org/';

const CLOCK_WARN_SECONDS = 60;

const CLOCK_FAIL_SECONDS = 300;

/** Response headers worth quoting as evidence. Anything else (cookies above all) is never repeated. */
const EVIDENCE_HEADERS = [
    'server',
    'cf-ray',
    'cf-mitigated',
    'www-authenticate',
    'content-type',
    'x-sucuri-id',
    'x-sucuri-block',
    'x-litespeed-cache',
    'x-powered-by',
];

/*
 * ------------------------------------------------------------------
 * Pure classifiers
 * ------------------------------------------------------------------
 */

/**
 * Lower-case header names and flatten list values.
 *
 * @param array<array-key, mixed> $headers
 * @return array<string, string>
 */
function normalize_headers(array $headers): array
{
    $normalized = [];
    /** @var mixed $value */
    foreach ($headers as $name => $value) {
        if (is_array($value)) {
            $value = implode(', ', array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $value));
        }
        if (is_scalar($value)) {
            $normalized[strtolower((string) $name)] = (string) $value;
        }
    }

    return $normalized;
}

/**
 * A short, tag-free excerpt of a response body for the evidence list.
 */
function body_excerpt(string $body, int $length = 160): string
{
    $title = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m) === 1) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES));
    }
    $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));
    $text = $title !== '' && !str_starts_with($text, $title) ? $title . ' - ' . $text : $text;

    return strlen($text) > $length ? substr($text, 0, $length) . '...' : $text;
}

/**
 * Whether a decoded body is a WordPress REST or JSON-RPC answer, i.e. PHP ran and WordPress replied.
 *
 * @param mixed $json
 */
function is_wordpress_json(mixed $json): bool
{
    if (!is_array($json)) {
        return false;
    }

    return array_key_exists('jsonrpc', $json)
        || (array_key_exists('code', $json) && array_key_exists('message', $json))
        || array_key_exists('resource', $json)
        || array_key_exists('issuer', $json)
        || array_key_exists('authorization', $json);
}

/**
 * Classify one captured response by who produced it.
 *
 * Order matters: a JSON body in WordPress's shape wins over any header, because Cloudflare adds `cf-ray`
 * to every response it proxies, including WordPress's own refusals. Only a non-WordPress body is matched
 * against the block-page signatures.
 *
 * @param array{code: int, headers: array<array-key, mixed>, body: string, error: string} $response
 * @return array{kind: string, layer: string, code: int, json: array<array-key, mixed>|null, headers: array<string, string>, excerpt: string}
 */
function classify_response(array $response): array
{
    $headers = normalize_headers($response['headers']);
    $code = $response['code'];
    $body = $response['body'];
    /** @var mixed $json */
    $json = json_decode($body, associative: true);
    $excerpt = body_excerpt($body);
    $result = static fn(string $kind, string $layer = ''): array => [
        'kind' => $kind,
        'layer' => $layer,
        'code' => $code,
        'json' => is_array($json) ? $json : null,
        'headers' => $headers,
        'excerpt' => $excerpt,
    ];

    if ($response['error'] !== '') {
        return $result('unreachable');
    }
    if (is_wordpress_json($json)) {
        return $result('wordpress');
    }

    $lower = strtolower($body);
    $server = strtolower($headers['server'] ?? '');
    $cloudflare = array_key_exists('cf-ray', $headers) || str_contains($server, 'cloudflare');

    if (str_contains(strtolower($headers['cf-mitigated'] ?? ''), 'challenge')
        || ($cloudflare && (str_contains($lower, 'challenge-platform') || str_contains($lower, '<title>just a moment')))) {
        return $result('cloudflare_challenge', 'Cloudflare');
    }
    // Body signatures before the Cloudflare verdict: behind Cloudflare every response carries cf-ray,
    // including a block page that Wordfence or ModSecurity on the origin produced.
    if (array_key_exists('x-sucuri-block', $headers) || str_contains($lower, 'sucuri website firewall')) {
        return $result('sucuri', 'Sucuri');
    }
    if (str_contains($lower, 'imunify360')) {
        return $result('imunify360', 'Imunify360');
    }
    if (str_contains($lower, 'wordfence')) {
        return $result('wordfence', 'Wordfence');
    }
    if (str_contains($lower, 'mod_security') || str_contains($lower, 'modsecurity') || str_contains($lower, 'mod security')) {
        return $result('modsecurity', 'ModSecurity');
    }
    if ($cloudflare && in_array($code, [403, 429, 503], strict: true)) {
        return $result('cloudflare_block', 'Cloudflare');
    }
    // 406 Not Acceptable is what stock ModSecurity rule sets on shared hosting answer with; almost
    // nothing else sends it for a POST with a JSON body.
    if ($code === 406) {
        return $result('modsecurity', 'ModSecurity');
    }
    if ($code === 401 && str_starts_with(strtolower($headers['www-authenticate'] ?? ''), 'basic')) {
        return $result('http_basic_auth', 'HTTP authentication');
    }
    if (in_array($code, [400, 403, 405, 418, 429, 503], strict: true)) {
        return $result('host_waf', 'Host firewall');
    }
    if ($code === 404) {
        return $result('not_routed');
    }
    if ($code >= 500) {
        return $result('server_error');
    }

    return $result('unexpected');
}

/**
 * The web server family behind the site, from PHP's own view and a response's Server header.
 *
 * OpenLiteSpeed and LiteSpeed Enterprise both report "LiteSpeed", so they share one id and the fix text
 * covers both: the difference that matters is that OpenLiteSpeed does not apply .htaccess changes.
 */
function detect_stack(string $server_software, string $server_header): string
{
    $haystack = strtolower($server_software . ' ' . $server_header);

    foreach (['litespeed' => 'litespeed', 'apache' => 'apache', 'nginx' => 'nginx', 'openresty' => 'nginx', 'microsoft-iis' => 'iis', 'caddy' => 'caddy'] as $needle => $stack) {
        if (str_contains($haystack, $needle)) {
            return $stack;
        }
    }

    return 'unknown';
}

/**
 * Which server variable carried this request's Authorization header, or '' when none did.
 *
 * @param array<array-key, mixed> $server
 */
function authorization_source(array $server): string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (is_string($server[$key] ?? null) && trim($server[$key]) !== '') {
            return $key;
        }
    }
    if (is_string($server['PHP_AUTH_USER'] ?? null) && $server['PHP_AUTH_USER'] !== '') {
        return 'PHP_AUTH_USER';
    }

    return '';
}

/**
 * Compare what the echo endpoint received with what the probe sent.
 *
 * @param array<array-key, mixed> $server
 * @return array{authorization: string, source: string}
 */
function echo_verdict(array $server, string $expected): array
{
    $source = authorization_source($server);
    if ($source === '' || $source === 'PHP_AUTH_USER') {
        return ['authorization' => 'missing', 'source' => $source];
    }
    $received = trim((string) $server[$source]);

    return ['authorization' => hash_equals($expected, $received) ? 'intact' : 'altered', 'source' => $source];
}

/**
 * The exact change that makes the web server pass Authorization through to PHP.
 */
function authorization_fix(string $stack): string
{
    $htaccess = "# Add above \"# BEGIN WordPress\" in .htaccess\n"
        . "<IfModule mod_setenvif.c>\n"
        . "SetEnvIf Authorization \"(.*)\" HTTP_AUTHORIZATION=\$1\n"
        . "</IfModule>\n"
        . "<IfModule mod_rewrite.c>\n"
        . "RewriteEngine On\n"
        . "RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n"
        . "</IfModule>";
    $cgipassauth = "# Apache 2.4.13+ alternative (virtual host, or .htaccess where AllowOverride permits it)\n"
        . 'CGIPassAuth On';
    $nginx = "# nginx: in the location block that passes .php to PHP-FPM\n"
        . "fastcgi_param HTTP_AUTHORIZATION \$http_authorization;\n"
        . "# ...and if nginx proxies to another server instead:\n"
        . "proxy_set_header Authorization \$http_authorization;\n"
        . '# then: nginx -t && nginx -s reload';
    $openlitespeed = "# OpenLiteSpeed ignores .htaccess edits until they are loaded. In WebAdmin:\n"
        . "# Virtual Hosts > (your site) > Rewrite > Rewrite Rules, add:\n"
        . "RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n"
        . '# then Actions > Graceful Restart.';

    return match ($stack) {
        'apache' => $htaccess . "\n\n" . $cgipassauth,
        'nginx' => $nginx,
        'litespeed' => "# LiteSpeed Enterprise reads .htaccess:\n" . $htaccess . "\n\n" . $openlitespeed,
        default => $htaccess . "\n\n" . $nginx,
    };
}

/**
 * The exact change that lets MCP traffic past a detected layer, scoped to GoBuildWP's paths only.
 *
 * @param string $mcp_path   Path prefix of the MCP routes, e.g. `/wp-json/mcp/`.
 * @param string $home_path  The site's path, '' for a root install.
 */
function layer_fix(string $kind, string $mcp_path, string $home_path): string
{
    $well_known = rtrim($home_path, '/') . '/.well-known/';

    return match ($kind) {
        'cloudflare_block', 'cloudflare_challenge' => "Cloudflare dashboard > Security > WAF > Custom rules > Create rule\n"
            . "Rule name: Allow GoBuildWP MCP\n"
            . 'Expression: (starts_with(http.request.uri.path, "' . $mcp_path . '")) or (starts_with(http.request.uri.path, "' . $well_known . '"))' . "\n"
            . "Action: Skip - All remaining custom rules, Rate limiting rules, All managed rules, Super Bot Fight Mode\n"
            . "Place it first in the rule order.\n"
            . 'Bot Fight Mode on the Free plan cannot be skipped by a rule; if challenges continue, turn it off under Security > Bots.',
        'modsecurity' => "Ask your host to exclude the rule that fired for these paths rather than switching ModSecurity off.\n"
            . "Find the rule id in the ModSecurity audit log (cPanel: Security > ModSecurity; WHM: ModSecurity Tools), then in the server configuration:\n"
            . '<LocationMatch "^' . $mcp_path . '">' . "\n"
            . "    SecRuleRemoveById <rule id>\n"
            . '</LocationMatch>',
        'sucuri' => "Sucuri Firewall dashboard > Access Control > Allowlist URL Paths, add:\n"
            . $mcp_path . "\n" . $well_known,
        'wordfence' => "WordPress admin > Wordfence > Firewall > All Firewall Options > Advanced Firewall Options > Allowlisted URLs.\n"
            . "Allowlist the blocked request from Wordfence > Tools > Live Traffic, or add the path " . $mcp_path . ' for Body and Query parameters.',
        'imunify360' => "Imunify360 > Firewall: ask the host to disable the triggered rule for " . $mcp_path . " (the incident list names the rule id). Do not add the MCP client's IP to a blanket allowlist.",
        'http_basic_auth' => "The site is behind HTTP Basic authentication (a staging password), which uses the same Authorization header MCP credentials travel in. Exempt " . $mcp_path . ' and ' . $well_known . ' from the password, or connect to a copy of the site without one.',
        default => 'Ask your host which firewall rule answered for ' . $mcp_path . ', and to allow that path by path (not by User-Agent or by turning the firewall off).',
    };
}

/**
 * @param list<string> $evidence
 * @return array{id: string, status: string, label: string, finding: string, evidence: list<string>, fix: string}
 */
function check(string $id, string $status, string $label, string $finding, array $evidence = [], string $fix = ''): array
{
    return ['id' => $id, 'status' => $status, 'label' => $label, 'finding' => $finding, 'evidence' => $evidence, 'fix' => $fix];
}

/**
 * One evidence line for a probe: request, status, the telling headers, and the body excerpt.
 *
 * @param array{kind: string, layer: string, code: int, json: array<array-key, mixed>|null, headers: array<string, string>, excerpt: string} $class
 * @return list<string>
 */
function probe_evidence(string $request, array $class, string $error = ''): array
{
    if ($class['kind'] === 'unreachable') {
        return [sprintf('%s -> no response (%s)', $request, $error)];
    }
    $lines = [sprintf('%s -> HTTP %d', $request, $class['code'])];
    foreach (EVIDENCE_HEADERS as $name) {
        if (isset($class['headers'][$name]) && $class['headers'][$name] !== '') {
            $lines[] = $name . ': ' . substr($class['headers'][$name], 0, 200);
        }
    }
    if ($class['json'] !== null) {
        $code = $class['json']['code'] ?? ($class['json']['error']['code'] ?? null);
        if (is_scalar($code)) {
            $lines[] = 'WordPress error code: ' . (string) $code;
        }
    } elseif ($class['excerpt'] !== '') {
        $lines[] = 'body: ' . $class['excerpt'];
    }

    return $lines;
}

/**
 * Turn a classified MCP probe into a check.
 *
 * Both MCP probes expect a refusal from WordPress: the anonymous one because nobody signed in, the
 * credentialed one because its token is deliberately invalid. A WordPress 401/403 therefore proves the
 * request reached PHP with its headers intact enough to be judged; anything else names who answered.
 *
 * @param array{kind: string, layer: string, code: int, json: array<array-key, mixed>|null, headers: array<string, string>, excerpt: string} $class
 * @param list<string> $evidence
 * @return array{id: string, status: string, label: string, finding: string, evidence: list<string>, fix: string}
 */
function check_mcp_probe(string $id, string $label, array $class, array $evidence, bool $anonymous, string $mcp_path, string $home_path): array
{
    $kind = $class['kind'];
    $code = $class['code'];

    if ($kind === 'wordpress') {
        if ($code === 401 || $code === 403) {
            return check($id, 'pass', $label, $anonymous
                ? __('WordPress answered and refused the unauthenticated request, as it should.', domain: 'wppilot')
                : __('A request carrying an Authorization header reached WordPress and was judged by it (the probe credential is deliberately invalid).', domain: 'wppilot'), $evidence);
        }
        if ($code === 404) {
            return check($id, 'fail', $label, __('WordPress answered, but the MCP route is not registered.', domain: 'wppilot'), $evidence, __('Turn on AI Abilities on the Overview screen. If it is on, reinstall the GoBuildWP release ZIP: the MCP Adapter did not load.', domain: 'wppilot'));
        }
        $result = is_array($class['json']['result'] ?? null) ? $class['json']['result'] : [];
        if ($code === 200 && array_key_exists('serverInfo', $result)) {
            return check($id, 'fail', $label, __('The MCP endpoint completed a handshake without valid credentials, so something is authenticating requests WordPress should refuse. Anyone who can reach the endpoint may be able to use it.', domain: 'wppilot'), $evidence, __('Turn AI Abilities off, then look for a plugin that force-authenticates REST requests.', domain: 'wppilot'));
        }

        return check($id, 'warn', $label, sprintf(
            /* translators: %d: HTTP status code */
            __('WordPress answered with HTTP %d instead of a refusal.', domain: 'wppilot'),
            $code,
        ), $evidence);
    }

    if ($kind === 'unreachable') {
        return check($id, 'warn', $label, __('The site could not reach its own MCP endpoint. Loopback requests are often blocked by hosts or containers; that does not mean outside clients are blocked.', domain: 'wppilot'), $evidence, __('Test from the AI client itself. If it also fails, ask the host whether the site can reach its own public URL, and whether a firewall sits in front of it.', domain: 'wppilot'));
    }

    if ($kind === 'not_routed') {
        return check($id, 'fail', $label, __('The web server answered 404 itself: requests to /wp-json/ never reach WordPress.', domain: 'wppilot'), $evidence, __('Settings > Permalinks > Save Changes rewrites the rules. On nginx, the site block needs: try_files $uri $uri/ /index.php?$args;', domain: 'wppilot'));
    }

    if ($kind === 'server_error' || $kind === 'unexpected') {
        return check($id, 'warn', $label, sprintf(
            /* translators: %d: HTTP status code */
            __('Something other than WordPress answered with HTTP %d.', domain: 'wppilot'),
            $code,
        ), $evidence, layer_fix('host_waf', $mcp_path, $home_path));
    }

    $finding = sprintf(
        /* translators: 1: security layer name, e.g. Cloudflare; 2: HTTP status code */
        __('%1$s answered with HTTP %2$d before the request reached WordPress. MCP clients get the same block.', domain: 'wppilot'),
        $class['layer'],
        $code,
    );
    if (!$anonymous) {
        $finding .= ' ' . __('Requests that carry credentials are the ones being blocked, so every signed-in client fails even though the endpoint looks healthy.', domain: 'wppilot');
    }

    return check($id, 'fail', $label, $finding, $evidence, layer_fix($kind, $mcp_path, $home_path));
}

/**
 * Judge the echo probe: did the Authorization header reach PHP unchanged?
 *
 * @param array{kind: string, layer: string, code: int, json: array<array-key, mixed>|null, headers: array<string, string>, excerpt: string} $class
 * @param list<string> $evidence
 * @return array{id: string, status: string, label: string, finding: string, evidence: list<string>, fix: string}
 */
function check_authorization_echo(array $class, array $evidence, string $stack, string $current_source): array
{
    $id = 'authorization_header';
    $label = __('Authorization header reaches PHP', domain: 'wppilot');
    if ($current_source !== '') {
        $evidence[] = sprintf('this request\'s own credentials arrived in %s', $current_source);
    }

    $verdict = $class['kind'] === 'wordpress' && $class['code'] === 200 && is_string($class['json']['authorization'] ?? null)
        ? $class['json']['authorization']
        : '';
    $source = is_string($class['json']['source'] ?? null) ? $class['json']['source'] : '';

    if ($verdict === 'intact') {
        return check($id, 'pass', $label, sprintf(
            /* translators: %s: PHP server variable name */
            __('The header arrived unchanged, in %s.', domain: 'wppilot'),
            $source,
        ), $evidence);
    }
    if ($verdict === 'missing') {
        return check($id, 'fail', $label, __('The web server removed the Authorization header before PHP ran. Application Passwords and access tokens both travel in it, so every client gets 401 however correct its credentials are. This is the classic Apache CGI/FastCGI behaviour.', domain: 'wppilot'), $evidence, authorization_fix($stack));
    }
    if ($verdict === 'altered') {
        return check($id, 'fail', $label, __('The Authorization header arrived changed: something between the client and PHP rewrites it.', domain: 'wppilot'), $evidence, __('Look for a proxy or security layer that rewrites Authorization (often an HTTP Basic password on a staging site) and exempt the MCP paths from it.', domain: 'wppilot'));
    }

    // The echo itself did not come back from WordPress: a firewall answered, or the loopback failed.
    if ($current_source !== '') {
        return check($id, 'pass', $label, __('The echo probe could not run, but this request\'s own credentials reached PHP, so the header is being passed through.', domain: 'wppilot'), $evidence);
    }

    return check($id, 'warn', $label, __('The echo probe did not come back from WordPress, so whether the header survives could not be tested.', domain: 'wppilot'), $evidence, __('Fix the checks above first, then run the doctor again.', domain: 'wppilot'));
}

/**
 * Whether WordPress will accept an Application Password at all.
 *
 * The most common 401 is not a firewall: WordPress switches Application Passwords off on a site
 * that is not served over HTTPS unless its environment type is `local`, and a security plugin can
 * switch them off with a filter. Every probe above can pass while every Basic-auth client is still
 * refused, so this is checked on its own and named plainly. When the filter's owner is known
 * (Wordfence's setting, or a plugin traced through its callback), the finding names it and the fix
 * is that plugin's own switch, because "find the security plugin" leaves the admin guessing.
 *
 * @param array{source: string, name: string, message: string, remedy: string, url: string}|null $blocker
 * @return array<string, mixed>
 */
function check_application_passwords(bool $available, bool $is_ssl, string $environment, bool $tokens_available, ?array $blocker = null): array
{
    $label = __('Application Passwords accepted', domain: 'wppilot');
    if ($available) {
        return check('application_passwords', 'pass', $label, __('WordPress accepts Application Passwords on this site.', domain: 'wppilot'));
    }
    $evidence = ['is_ssl: ' . ($is_ssl ? 'yes' : 'no'), 'WP_ENVIRONMENT_TYPE: ' . $environment];
    $alternative = $tokens_available
        ? ' ' . __('A GoBuildWP access token (Bearer) works without them.', domain: 'wppilot')
        : '';
    if (!$is_ssl && $environment !== 'local') {
        return check('application_passwords', 'fail', $label, __('WordPress disables Application Passwords on a site that is not served over HTTPS, so every client using Basic auth gets 401.', domain: 'wppilot') . $alternative, $evidence, __('Serve the site over HTTPS. On a development site only, set define( "WP_ENVIRONMENT_TYPE", "local" ); in wp-config.php.', domain: 'wppilot'));
    }
    if ($blocker !== null) {
        $evidence[] = 'Switched off by: ' . $blocker['name'];
        $fix = $blocker['remedy'] . ($blocker['url'] !== '' ? ' ' . $blocker['url'] : '');
        return check('application_passwords', 'fail', $label, $blocker['message'], $evidence, $fix);
    }
    return check('application_passwords', 'fail', $label, __('Application Passwords are switched off by a plugin or by code (the wp_is_application_passwords_available filter), so every client using Basic auth gets 401.', domain: 'wppilot') . $alternative, $evidence, __('Find the security plugin or snippet that disables Application Passwords and allow them, or connect with a GoBuildWP access token.', domain: 'wppilot'));
}

/**
 * Judge the OAuth endpoint's anonymous answer: a 401 carrying a Bearer challenge that points at the
 * protected-resource metadata is how a client learns where to sign in.
 *
 * @param array{kind: string, layer: string, code: int, json: array<array-key, mixed>|null, headers: array<string, string>, excerpt: string} $class
 * @param list<string> $evidence
 * @return array{id: string, status: string, label: string, finding: string, evidence: list<string>, fix: string}
 */
function check_oauth_challenge(array $class, array $evidence, string $mcp_path, string $home_path): array
{
    $id = 'oauth_challenge';
    $label = __('OAuth sign-in challenge', domain: 'wppilot');
    if ($class['kind'] !== 'wordpress') {
        return check_mcp_probe($id, $label, $class, $evidence, true, $mcp_path, $home_path);
    }
    $challenge = strtolower($class['headers']['www-authenticate'] ?? '');
    if ($class['code'] === 401 && str_starts_with($challenge, 'bearer') && str_contains($challenge, 'resource_metadata=')) {
        return check($id, 'pass', $label, __('The OAuth endpoint answers 401 with a Bearer challenge naming its metadata, so clients can find the sign-in flow.', domain: 'wppilot'), $evidence);
    }
    if ($class['code'] === 401) {
        return check($id, 'fail', $label, __('The OAuth endpoint answered 401 without the WWW-Authenticate challenge, so a client cannot discover where to sign in. A proxy or CDN may be removing the header.', domain: 'wppilot'), $evidence, sprintf(
            /* translators: %s: URL path prefix of the MCP routes */
            __('Make sure the proxy or CDN passes the WWW-Authenticate response header through unchanged for %s.', domain: 'wppilot'),
            $mcp_path,
        ));
    }

    return check($id, 'warn', $label, sprintf(
        /* translators: %d: HTTP status code */
        __('The OAuth endpoint answered HTTP %d to an anonymous request instead of 401.', domain: 'wppilot'),
        $class['code'],
    ), $evidence);
}

/**
 * Judge the protected-resource metadata fetch: the first document an OAuth client reads.
 *
 * @param array{kind: string, layer: string, code: int, json: array<array-key, mixed>|null, headers: array<string, string>, excerpt: string} $class
 * @param list<string> $evidence
 * @return array{id: string, status: string, label: string, finding: string, evidence: list<string>, fix: string}
 */
function check_oauth_metadata(array $class, array $evidence, string $mcp_path, string $home_path): array
{
    $id = 'oauth_metadata';
    $label = __('OAuth metadata reachable', domain: 'wppilot');
    if ($class['kind'] === 'wordpress' && $class['code'] === 200) {
        return check($id, 'pass', $label, __('The protected-resource metadata is served as JSON.', domain: 'wppilot'), $evidence);
    }
    if ($class['kind'] === 'not_routed') {
        return check($id, 'fail', $label, __('The web server answered 404 for /.well-known/ itself, so OAuth clients cannot discover the authorization server.', domain: 'wppilot'), $evidence, __("Route /.well-known/oauth-* to WordPress. nginx: location ^~ /.well-known/oauth- { try_files \$uri /index.php?\$args; }", domain: 'wppilot'));
    }
    if ($class['kind'] === 'wordpress') {
        return check($id, 'warn', $label, sprintf(
            /* translators: %d: HTTP status code */
            __('WordPress answered the metadata URL with HTTP %d.', domain: 'wppilot'),
            $class['code'],
        ), $evidence);
    }

    return check_mcp_probe($id, $label, $class, $evidence, true, $mcp_path, $home_path);
}

/**
 * Compare the server clock with a trusted Date header.
 *
 * Access tokens, authorization codes and refresh tokens all carry expiry times checked against this
 * clock. A few minutes of drift turns fresh grants into `invalid_grant` and tokens into "expired" the
 * moment they are issued.
 */
function check_clock(?int $remote, int $local, string $evidence_line): array
{
    $id = 'clock_skew';
    $label = __('Server clock', domain: 'wppilot');
    if ($remote === null) {
        return check($id, 'info', $label, __('No trusted time source could be reached, so the clock was not compared.', domain: 'wppilot'), [$evidence_line]);
    }
    $skew = $local - $remote;
    $evidence = [$evidence_line, sprintf('server clock %s, reference %s, difference %+d s', gmdate('c', $local), gmdate('c', $remote), $skew)];
    if (abs($skew) >= CLOCK_FAIL_SECONDS) {
        return check($id, 'fail', $label, sprintf(
            /* translators: %d: seconds */
            __('The server clock is %d seconds off. OAuth codes and tokens are judged against it, so sign-in fails with invalid_grant or tokens expire on arrival.', domain: 'wppilot'),
            abs($skew),
        ), $evidence, __('Ask the host to enable time synchronisation (NTP). On a server you run: timedatectl set-ntp true', domain: 'wppilot'));
    }
    if (abs($skew) >= CLOCK_WARN_SECONDS) {
        return check($id, 'warn', $label, sprintf(
            /* translators: %d: seconds */
            __('The server clock is %d seconds off. Short-lived OAuth codes can expire early.', domain: 'wppilot'),
            abs($skew),
        ), $evidence, __('Ask the host to enable time synchronisation (NTP). On a server you run: timedatectl set-ntp true', domain: 'wppilot'));
    }

    return check($id, 'pass', $label, __('The server clock is accurate.', domain: 'wppilot'), $evidence);
}

/**
 * Look for the URL mismatches behind OAuth `invalid_grant` and redirect failures.
 *
 * The issuer is the home URL, the authorize endpoint lives under the admin (site) URL, and every
 * redirect is compared exactly. A home/site URL that differs in scheme or host, or a site whose proxy
 * hides HTTPS from PHP, produces URLs that no longer match the ones a client registered.
 */
function check_oauth_urls(string $home, string $siteurl, bool $is_ssl): array
{
    $id = 'oauth_urls';
    $label = __('OAuth URLs and invalid_grant', domain: 'wppilot');
    $home_parts = wp_parse_url($home);
    $site_parts = wp_parse_url($siteurl);
    $home_scheme = is_array($home_parts) ? strtolower((string) ($home_parts['scheme'] ?? '')) : '';
    $site_scheme = is_array($site_parts) ? strtolower((string) ($site_parts['scheme'] ?? '')) : '';
    $home_host = is_array($home_parts) ? strtolower((string) ($home_parts['host'] ?? '')) : '';
    $site_host = is_array($site_parts) ? strtolower((string) ($site_parts['host'] ?? '')) : '';
    $evidence = ['home: ' . $home, 'siteurl: ' . $siteurl, 'this request over HTTPS: ' . ($is_ssl ? 'yes' : 'no')];

    $causes = __('If a client still reports invalid_grant: the authorization code was used twice or after 10 minutes (start sign-in again); the redirect_uri at the token step differs from the one at authorize (scheme, www, port or trailing slash); the PKCE verifier does not match; or the refresh token was already rotated or revoked (remove and re-add the connector).', domain: 'wppilot');

    if ($home_host !== $site_host) {
        return check($id, 'warn', $label, __('The WordPress Address and Site Address use different hosts. The issuer and the sign-in page then live on different hosts, and clients that compare them reject the grant.', domain: 'wppilot') . ' ' . $causes, $evidence, __('Settings > General: use the same host for WordPress Address (URL) and Site Address (URL).', domain: 'wppilot'));
    }
    if ($home_scheme !== $site_scheme) {
        return check($id, 'warn', $label, __('The WordPress Address and Site Address differ in http/https, so OAuth URLs mix schemes and redirect checks fail.', domain: 'wppilot') . ' ' . $causes, $evidence, __('Settings > General: make both addresses https://.', domain: 'wppilot'));
    }
    if ($home_scheme === 'https' && !$is_ssl) {
        return check($id, 'warn', $label, __('The site is https:// but PHP sees this request as plain HTTP, which happens behind a proxy or load balancer that does not pass the original scheme. URLs built during sign-in can come out as http:// and no longer match.', domain: 'wppilot') . ' ' . $causes, $evidence, "// wp-config.php, above \"That's all, stop editing!\"\nif (isset(\$_SERVER['HTTP_X_FORWARDED_PROTO']) && \$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {\n    \$_SERVER['HTTPS'] = 'on';\n}");
    }

    return check($id, 'pass', $label, __('Home and site URLs agree and HTTPS is detected correctly.', domain: 'wppilot') . ' ' . $causes, $evidence);
}

/**
 * Summarise the checks: counts per status and the worst one.
 *
 * @param list<array{id: string, status: string, label: string, finding: string, evidence: list<string>, fix: string}> $checks
 * @return array{status: string, counts: array<string, int>}
 */
function summarize(array $checks): array
{
    $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0, 'skip' => 0];
    foreach ($checks as $check) {
        if (isset($counts[$check['status']])) {
            ++$counts[$check['status']];
        }
    }
    $status = $counts['fail'] > 0 ? 'fail' : ($counts['warn'] > 0 ? 'warn' : 'pass');

    return ['status' => $status, 'counts' => $counts];
}

/*
 * ------------------------------------------------------------------
 * Probes
 * ------------------------------------------------------------------
 */

/**
 * Send one request and capture it in the classifier's shape.
 *
 * @param array<string, string> $headers
 * @return array{code: int, headers: array<array-key, mixed>, body: string, error: string}
 */
function http_request(string $method, string $url, array $headers = [], string $body = ''): array
{
    $options = [
        'method' => $method,
        'timeout' => HTTP_TIMEOUT,
        'redirection' => 0,
        'headers' => $headers,
        // Relaxed only for the site's own local certificate; the clock reference is always verified.
        'sslverify' => wp_parse_url($url, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)
            || !function_exists('wppilot_likely_self_signed_https')
            || !\wppilot_likely_self_signed_https(),
    ];
    if ($body !== '') {
        $options['body'] = $body;
    }

    $response = wp_remote_request($url, $options);
    if (is_wp_error($response)) {
        return ['code' => 0, 'headers' => [], 'body' => '', 'error' => $response->get_error_message()];
    }

    $raw = wp_remote_retrieve_headers($response);
    if (is_object($raw) && method_exists($raw, 'getAll')) {
        $raw = $raw->getAll();
    }

    return [
        'code' => (int) wp_remote_retrieve_response_code($response),
        'headers' => is_array($raw) ? $raw : [],
        'body' => (string) wp_remote_retrieve_body($response),
        'error' => '',
    ];
}

/**
 * The JSON-RPC frame the MCP probes send: the legacy handshake every GoBuildWP version answers.
 */
function handshake_body(): string
{
    return (string) wp_json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => \WPPilot\Mcp\VERSION_LEGACY,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'wppilot-connection-doctor', 'version' => defined('WPPILOT_VERSION') ? (string) WPPILOT_VERSION : '0'],
        ],
    ]);
}

/**
 * Mint a one-time probe id for the echo endpoint. Only its hash is stored, for two minutes.
 */
function mint_probe_id(): string
{
    $id = wp_generate_password(32, false);
    set_transient('wppilot_doctor_' . hash('sha256', $id), 1, PROBE_TTL);

    return $id;
}

/**
 * Run every check in order.
 *
 * $request is injectable so the orchestration can be tested with fixture responses; it takes the same
 * arguments as http_request().
 *
 * @param callable(string, string, array<string, string>, string): array{code: int, headers: array<array-key, mixed>, body: string, error: string}|null $request
 * @return array{generated_at: string, summary: array{status: string, counts: array<string, int>}, checks: list<array{id: string, status: string, label: string, finding: string, evidence: list<string>, fix: string}>, note: string}
 */
function run(?callable $request = null): array
{
    $request ??= __NAMESPACE__ . '\\http_request';
    $home_path = rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
    $mcp_url = rest_url('mcp/wppilot');
    $mcp_path = preg_replace('#wppilot/?$#', '', (string) wp_parse_url($mcp_url, PHP_URL_PATH)) ?? '/wp-json/mcp/';
    $oauth = function_exists('wppilot_oauth_transport_allowed') && \wppilot_oauth_transport_allowed();
    $json_headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];
    $all_headers = [];
    $checks = [];

    // 1. Anonymous MCP request.
    $anonymous = $request('POST', $mcp_url, $json_headers, handshake_body());
    $anonymous_class = classify_response($anonymous);
    $all_headers[] = $anonymous_class['headers'];

    // Stack first in the report, but it reads the Server header the first probe brought back.
    $server_software = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '';
    $stack = detect_stack($server_software, $anonymous_class['headers']['server'] ?? '');
    $checks[] = check('server_stack', 'info', __('Web server', domain: 'wppilot'), sprintf(
        /* translators: 1: web server family, 2: PHP SAPI name */
        __('Web server: %1$s. PHP runs as: %2$s.', domain: 'wppilot'),
        $stack,
        PHP_SAPI,
    ), array_values(array_filter([
        'SERVER_SOFTWARE: ' . $server_software,
        isset($anonymous_class['headers']['server']) ? 'Server header: ' . $anonymous_class['headers']['server'] : '',
    ])), $stack === 'litespeed' ? __('OpenLiteSpeed does not apply .htaccess changes by itself: rules must be added in WebAdmin and loaded with a graceful restart.', domain: 'wppilot') : '');

    $checks[] = check_mcp_probe('mcp_anonymous', __('MCP endpoint, no credentials', domain: 'wppilot'), $anonymous_class, probe_evidence('POST ' . $mcp_url, $anonymous_class, $anonymous['error']), true, $mcp_path, $home_path);

    // 2. The same request carrying a Bearer credential. Deliberately invalid: the question is whether a
    //    request with Authorization reaches WordPress at all, and WordPress's own 401 proves it did.
    $authed = $request('POST', $mcp_url, $json_headers + ['Authorization' => 'Bearer wppilot-doctor-probe-not-a-credential'], handshake_body());
    $authed_class = classify_response($authed);
    $all_headers[] = $authed_class['headers'];
    $checks[] = check_mcp_probe('mcp_with_credentials', __('MCP endpoint, with credentials', domain: 'wppilot'), $authed_class, probe_evidence('POST ' . $mcp_url . ' (Authorization: Bearer <invalid probe>)', $authed_class, $authed['error']), false, $mcp_path, $home_path);

    $checks[] = check_application_passwords(
        function_exists('wp_is_application_passwords_available') && wp_is_application_passwords_available(),
        is_ssl(),
        function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
        function_exists('wppilot_token_hash'),
        function_exists('wppilot_app_passwords_blocker') ? \wppilot_app_passwords_blocker() : null,
    );

    // 3. Does Authorization reach PHP intact?
    $probe_id = mint_probe_id();
    $echo_url = rest_url('wppilot/v1' . ECHO_ROUTE);
    $echo = $request('POST', $echo_url, $json_headers + ['Authorization' => PROBE_SCHEME . ' ' . $probe_id, PROBE_HEADER => $probe_id], '{}');
    $echo_class = classify_response($echo);
    $checks[] = check_authorization_echo($echo_class, probe_evidence('POST ' . $echo_url, $echo_class, $echo['error']), $stack, authorization_source($_SERVER));

    // 4. OAuth: the challenge and the metadata document.
    if ($oauth) {
        $oauth_url = rest_url('mcp/wppilot-oauth');
        $challenge = $request('POST', $oauth_url, $json_headers, handshake_body());
        $challenge_class = classify_response($challenge);
        $all_headers[] = $challenge_class['headers'];
        $checks[] = check_oauth_challenge($challenge_class, probe_evidence('POST ' . $oauth_url, $challenge_class, $challenge['error']), $mcp_path, $home_path);

        $metadata_url = home_url('/.well-known/oauth-protected-resource');
        $metadata = $request('GET', $metadata_url, ['Accept' => 'application/json'], '');
        $metadata_class = classify_response($metadata);
        $all_headers[] = $metadata_class['headers'];
        $checks[] = check_oauth_metadata($metadata_class, probe_evidence('GET ' . $metadata_url, $metadata_class, $metadata['error']), $mcp_path, $home_path);
    } else {
        $checks[] = check('oauth_challenge', 'skip', __('OAuth sign-in challenge', domain: 'wppilot'), __('OAuth is not available on this site (it needs HTTPS), so there is nothing to test.', domain: 'wppilot'));
    }

    // 5. Layers seen on any probe, plus security plugins. Presence only.
    $checks[] = check_layers($all_headers, $mcp_path, $home_path);

    // 6. Clock.
    /** @var mixed $clock_url */
    $clock_url = apply_filters('wppilot_connection_doctor_clock_url', CLOCK_URL);
    $clock_url = is_string($clock_url) && $clock_url !== '' ? $clock_url : CLOCK_URL;
    $clock = $request('HEAD', $clock_url, [], '');
    $date = normalize_headers($clock['headers'])['date'] ?? '';
    $remote = $date !== '' ? strtotime($date) : false;
    $checks[] = check_clock(
        is_int($remote) ? $remote : null,
        time(),
        $clock['error'] !== '' ? sprintf('HEAD %s -> no response (%s)', $clock_url, $clock['error']) : sprintf('HEAD %s -> Date: %s', $clock_url, $date !== '' ? $date : '(none)'),
    );

    // 7. URL consistency behind invalid_grant.
    if ($oauth) {
        $checks[] = check_oauth_urls(home_url(), site_url(), is_ssl());
    }

    return [
        'generated_at' => gmdate('c'),
        'summary' => summarize($checks),
        'checks' => $checks,
        'note' => __('Every probe is sent by this server to itself. Rules that block by visitor IP or country, or that only challenge browsers from outside, cannot be seen from here: if every check passes and a client still fails, the block is keyed on where the client connects from.', domain: 'wppilot'),
    ];
}

/**
 * Report the security and edge layers seen on the probes or installed as plugins, with the path-scoped
 * fix for the one that most often blocks MCP (Cloudflare) when it is present.
 *
 * @param list<array<string, string>> $header_sets
 */
function check_layers(array $header_sets, string $mcp_path, string $home_path): array
{
    $label = __('Security and edge layers', domain: 'wppilot');
    $merged = [];
    foreach ($header_sets as $headers) {
        $merged += $headers;
    }

    $plugins = [];
    /** @var mixed $active */
    $active = get_option('active_plugins', []);
    foreach (is_array($active) ? $active : [] as $plugin) {
        if (is_string($plugin)) {
            $plugins[] = $plugin;
        }
    }
    $layers = function_exists('WPPilot\\Troubleshoot\\Checks\\detect_security_edge')
        ? \WPPilot\Troubleshoot\Checks\detect_security_edge($merged, $plugins)
        : [];

    if ($layers === []) {
        return check('edge_layers', 'pass', $label, __('No CDN, WAF or security plugin was detected.', domain: 'wppilot'));
    }

    return check('edge_layers', 'info', $label, sprintf(
        /* translators: %s: comma-separated names of security layers */
        __('Detected: %s. Keep them on. If a client is blocked while the probes above pass, the rule is keyed on the client\'s address; allow the MCP paths by path.', domain: 'wppilot'),
        implode(', ', $layers),
    ), [], in_array('Cloudflare', $layers, strict: true) ? layer_fix('cloudflare_block', $mcp_path, $home_path) : '');
}

/*
 * ------------------------------------------------------------------
 * Echo endpoint
 * ------------------------------------------------------------------
 */

function register_echo_route(): void
{
    register_rest_route('wppilot/v1', ECHO_ROUTE, [
        'methods' => 'POST',
        'callback' => __NAMESPACE__ . '\\echo_callback',
        // Answers only a probe this server minted in the last two minutes, once, and reports nothing but
        // whether the header arrived. The probe cannot authenticate itself any other way: the header it
        // tests is the one that may be missing.
        'permission_callback' => __NAMESPACE__ . '\\echo_permission',
    ]);
}

function echo_permission(WP_REST_Request $request): bool
{
    $id = (string) $request->get_header('x_wppilot_doctor');
    if ($id === '' || strlen($id) > 64) {
        return false;
    }
    $key = 'wppilot_doctor_' . hash('sha256', $id);
    if (get_transient($key) === false) {
        return false;
    }
    delete_transient($key);

    return true;
}

function echo_callback(WP_REST_Request $request): WP_REST_Response
{
    $expected = PROBE_SCHEME . ' ' . (string) $request->get_header('x_wppilot_doctor');

    return new WP_REST_Response(echo_verdict($_SERVER, $expected), 200);
}

function register(): void
{
    add_action('rest_api_init', __NAMESPACE__ . '\\register_echo_route');
}
