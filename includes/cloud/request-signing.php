<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Signed Cloud-to-site requests (Security Guardian R3 contract §1).
 *
 * The Cloud's access token alone used to be enough to change this site. Now the
 * Cloud also signs each tools/call and each write to its REST routes with its own
 * Ed25519 key, so a token copied out of the Cloud's database is not enough to act
 * on its own. The public half reaches this site over TLS from the paired
 * `cloud_url` only: in the /api/pair/complete answer, and in every heartbeat
 * answer for sites paired before it existed. The first key seen is trusted; a
 * different one is accepted only as a rotation that names the stored key as its
 * `previous`, which then stays valid for seven days.
 *
 * Verification happens at one choke point, `rest_pre_dispatch` before the MCP
 * transport answers anything, and only for the linked Cloud token: every other
 * credential is unaffected. Once a key is stored a write needs a valid
 * signature; a read-only call may go unsigned, but a signature it does carry
 * must verify. After this the request still meets the site's own safety
 * profile, confirmations and approval mode like any other call.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** First line of every signed message. */
const GOBUILDWP_CLOUD_SIGNING_VERSION = 'gobuildwp-cloud-request-v1';

/**
 * First line of the site-bound message. The v1 message names no site, so a signature
 * captured from one site would verify on any other site the same Cloud key signs for.
 * The Cloud also sends `X-GoBuildWP-Site-Sig` over the same lines plus this site's Cloud
 * site id. Once one has verified here, the site-bound form is required from then on.
 */
const GOBUILDWP_CLOUD_SITE_SIGNING_VERSION = 'gobuildwp-cloud-request-v2';

/** How far the Cloud's clock may be from this site's, in seconds. */
const GOBUILDWP_CLOUD_SIGNING_SKEW = 300;

/** How long a nonce is remembered. Longer than twice the skew, so a replay cannot outlive it. */
const GOBUILDWP_CLOUD_SIGNING_NONCE_TTL = 900;

/** How long the key before a rotation still verifies. */
const GOBUILDWP_CLOUD_SIGNING_GRACE = 7 * 86400;

const GOBUILDWP_CLOUD_SIGNING_NONCE_PREFIX = 'gobuildwp_cloud_nonce_';

/** Set when the Cloud offered a key that is neither the stored one nor a rotation from it. */
const GOBUILDWP_CLOUD_SIGNING_MISMATCH_TRANSIENT = 'wppilot_cloud_signing_key_mismatch';

/** After the host guard (5), the request recorder (20) and token attribution (21); before the MCP transport (25). */
const GOBUILDWP_CLOUD_SIGNING_PRIORITY = 22;

/** JSON-RPC error code for a refused signature. */
const GOBUILDWP_CLOUD_SIGNING_RPC_ERROR = -32001;

/**
 * Whether this PHP can verify an Ed25519 signature.
 */
function gobuildwp_cloud_signing_supported(): bool
{
    $native = wppilot_cloud_sodium_available() && function_exists('sodium_crypto_sign_verify_detached');

    /**
     * Filters whether this site can verify Cloud signatures. It can only say no:
     * then no key is stored, status reports "unavailable", and a key stored earlier
     * refuses every signed call rather than letting it through unchecked.
     *
     * @param bool $native Whether sodium (or WordPress's sodium_compat) is there.
     */
    return $native && (bool) apply_filters('gobuildwp_cloud_signing_supported', $native);
}

/**
 * A `{kid, public}` pair as the Cloud sends it, or null when it is not one.
 *
 * @return array{kid: string, public: string}|null
 */
function gobuildwp_cloud_signing_parse_key(mixed $value): ?array
{
    if (!is_array($value) || !is_string($value['kid'] ?? null) || !is_string($value['public'] ?? null)) {
        return null;
    }
    if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $value['kid']) !== 1) {
        return null;
    }
    $raw = base64_decode($value['public'], strict: true);
    if (!is_string($raw) || strlen($raw) !== 32) {
        return null;
    }

    return ['kid' => $value['kid'], 'public' => $value['public']];
}

/**
 * The Cloud key stored in the link, or null when there is none (or no link).
 *
 * Read from the raw option: wppilot_cloud_parse_link() keeps only the §3.4 fields.
 *
 * @return array{kid: string, public: string, previous: array{kid: string, public: string}|null, previous_until: int, since: int, site_id: string, site_bound: int}|null
 */
function gobuildwp_cloud_signing_stored(): ?array
{
    /** @var mixed $raw */
    $raw = get_option(WPPILOT_CLOUD_LINK_OPTION, default_value: null);
    $link = wppilot_cloud_parse_link($raw);
    if ($link === null || !is_array($raw)) {
        return null;
    }
    $stored = is_array($raw['cloud_signing'] ?? null) ? $raw['cloud_signing'] : null;
    $current = gobuildwp_cloud_signing_parse_key($stored);
    if ($stored === null || $current === null) {
        return null;
    }

    return [
        'kid' => $current['kid'],
        'public' => $current['public'],
        'previous' => gobuildwp_cloud_signing_parse_key($stored['previous'] ?? null),
        'previous_until' => is_int($stored['previous_until'] ?? null) ? $stored['previous_until'] : 0,
        'since' => is_int($stored['since'] ?? null) ? $stored['since'] : 0,
        'site_id' => (string) $link['site_id'],
        'site_bound' => is_int($stored['site_bound'] ?? null) ? $stored['site_bound'] : 0,
    ];
}

/**
 * Write the key into the link option, keeping every other field as it is.
 *
 * @param array<string, mixed> $signing
 */
function gobuildwp_cloud_signing_save(array $signing): void
{
    /** @var mixed $raw */
    $raw = get_option(WPPILOT_CLOUD_LINK_OPTION, default_value: null);
    if (!is_array($raw)) {
        return;
    }
    // A key change never forgets that the site-bound form is required.
    $bound = is_array($raw['cloud_signing'] ?? null) && is_int($raw['cloud_signing']['site_bound'] ?? null) ? $raw['cloud_signing']['site_bound'] : 0;
    if ($bound > 0 && !isset($signing['site_bound'])) {
        $signing['site_bound'] = $bound;
    }
    $raw['cloud_signing'] = $signing;
    update_option(WPPILOT_CLOUD_LINK_OPTION, $raw, autoload: false);
}

/**
 * Remember that a site-bound signature has verified: from now on the v1 form alone is refused.
 */
function gobuildwp_cloud_signing_bind_site(int $now): void
{
    /** @var mixed $raw */
    $raw = get_option(WPPILOT_CLOUD_LINK_OPTION, default_value: null);
    if (!is_array($raw) || !is_array($raw['cloud_signing'] ?? null)) {
        return;
    }
    if (is_int($raw['cloud_signing']['site_bound'] ?? null) && $raw['cloud_signing']['site_bound'] > 0) {
        return;
    }
    $raw['cloud_signing']['site_bound'] = $now;
    update_option(WPPILOT_CLOUD_LINK_OPTION, $raw, autoload: false);
}

/**
 * Take the `cloud_signing` object from a pair-complete or heartbeat answer.
 *
 * Trust on first use, then only a rotation whose `previous` is the stored key.
 * Anything else is ignored and flagged for the owner, who has to disconnect and
 * reconnect to accept a key the site cannot link to the one it trusts. An
 * answer without the object changes nothing: a key once stored stays enforced.
 *
 * @return string What happened: stored, rotated, unchanged, mismatch, absent or unavailable.
 */
function gobuildwp_cloud_signing_accept(mixed $offered, string|int|null $site_id = null, ?int $now = null): string
{
    $now ??= time();
    $link = wppilot_cloud_link();
    // A heartbeat in flight across a disconnect and re-pair speaks for the old link.
    if ($link === null || ($site_id !== null && $link['site_id'] !== $site_id)) {
        return 'absent';
    }
    if (!is_array($offered)) {
        return 'absent';
    }
    if (!gobuildwp_cloud_signing_supported()) {
        // Never stored: a key this site cannot verify with would refuse every write.
        return 'unavailable';
    }

    $current = gobuildwp_cloud_signing_parse_key($offered);
    if ($current === null) {
        return 'absent';
    }
    $previous = gobuildwp_cloud_signing_parse_key($offered['previous'] ?? null);
    $stored = gobuildwp_cloud_signing_stored();

    if ($stored === null) {
        gobuildwp_cloud_signing_save(['kid' => $current['kid'], 'public' => $current['public'], 'since' => $now]);
        return 'stored';
    }

    if ($stored['kid'] === $current['kid'] && hash_equals($stored['public'], $current['public'])) {
        // Drop a previous key once its grace is over, so the stored record does not grow stale.
        if ($stored['previous'] !== null && $stored['previous_until'] < $now) {
            gobuildwp_cloud_signing_save(['kid' => $stored['kid'], 'public' => $stored['public'], 'since' => $stored['since']]);
        }
        return 'unchanged';
    }

    if ($previous !== null && $previous['kid'] === $stored['kid'] && hash_equals($stored['public'], $previous['public'])) {
        gobuildwp_cloud_signing_save([
            'kid' => $current['kid'],
            'public' => $current['public'],
            'previous' => ['kid' => $stored['kid'], 'public' => $stored['public']],
            'previous_until' => $now + GOBUILDWP_CLOUD_SIGNING_GRACE,
            'since' => $now,
        ]);
        return 'rotated';
    }

    set_transient(GOBUILDWP_CLOUD_SIGNING_MISMATCH_TRANSIENT, ['kid' => $current['kid'], 'at' => $now], 86400);
    if (function_exists('error_log')) {
        // Only the key id: it is public, and enough to tell which key was refused.
        error_log('GoBuildWP Cloud offered a signing key (' . $current['kid'] . ') that does not follow from the stored one; it was ignored. Disconnect and reconnect to accept it.');
    }

    return 'mismatch';
}

/**
 * `cloud_signing` in /cloud/status: whether this site enforces signed writes.
 */
function gobuildwp_cloud_signing_status(): string
{
    if (!gobuildwp_cloud_signing_supported()) {
        return 'unavailable';
    }

    return gobuildwp_cloud_signing_stored() !== null ? 'enforced' : 'none';
}

/**
 * The signed path: `/` + the REST route, without a trailing slash.
 *
 * WordPress has already reduced both `/wp-json/<route>` and `?rest_route=/<route>`
 * to the route, so only the slashes are left to settle.
 */
function gobuildwp_cloud_signing_path(string $route): string
{
    $path = '/' . ltrim($route, '/');

    return strlen($path) > 1 ? rtrim($path, '/') : $path;
}

/**
 * The exact bytes the Cloud signs: `\n`-joined, no trailing newline.
 */
function gobuildwp_cloud_signing_message(
    string $method,
    string $path,
    string $body,
    string $ts,
    string $nonce,
    string $request_id,
    string $op,
    string $approval,
): string {
    return implode("\n", [
        GOBUILDWP_CLOUD_SIGNING_VERSION,
        strtoupper($method),
        gobuildwp_cloud_signing_path($path),
        hash('sha256', $body),
        $ts,
        $nonce,
        $request_id,
        $op,
        $approval,
    ]);
}

/**
 * The site-bound message: the v1 lines under the v2 version line, then the Cloud site id.
 */
function gobuildwp_cloud_signing_site_message(
    string $method,
    string $path,
    string $body,
    string $ts,
    string $nonce,
    string $request_id,
    string $op,
    string $approval,
    string $site_id,
): string {
    $lines = explode("\n", gobuildwp_cloud_signing_message($method, $path, $body, $ts, $nonce, $request_id, $op, $approval));
    $lines[0] = GOBUILDWP_CLOUD_SITE_SIGNING_VERSION;
    $lines[] = $site_id;

    return implode("\n", $lines);
}

/**
 * Whether a REST route is an MCP endpoint, where tools/call arrives.
 */
function gobuildwp_cloud_signing_is_mcp_route(string $route): bool
{
    if (function_exists('WPPilot\OAuth\Middleware\is_any_mcp_route')) {
        return \WPPilot\OAuth\Middleware\is_any_mcp_route($route);
    }

    return $route === '/mcp/wppilot' || str_starts_with($route, '/mcp/');
}

/**
 * The op a REST request is signed for: its route inside the namespace
 * (`/wppilot/v1/cloud/policy` is `cloud/policy`).
 */
function gobuildwp_cloud_signing_rest_op(string $route): string
{
    $parts = explode('/', trim($route, '/'), 3);

    return $parts[2] ?? trim($route, '/');
}

/**
 * Whether the MCP tool a call names is read-only. An unknown tool counts as a write.
 */
function gobuildwp_cloud_signing_tool_is_readonly(string $tool): bool
{
    if (!function_exists('WPPilot\Mcp\ability_for_tool') || !function_exists('wppilot_ability_is_readonly')) {
        return false;
    }
    $ability = \WPPilot\Mcp\ability_for_tool($tool);

    return $ability !== null && wppilot_ability_is_readonly($ability);
}

/**
 * What a Cloud request must show: whether a signature is required, the ops it
 * may be signed for, and how a refusal is answered. Null when nothing is asked
 * of it (an MCP request without tools/call, e.g. tools/list).
 *
 * On an MCP endpoint only tools/call counts; a JSON-RPC batch is held to the
 * strictest of its calls, and a signature on it must name every tool in it.
 * Every other route is a REST route: GET and HEAD are reads, anything else is
 * a write - cloud/policy and cloud/unlink, and also any route the token could
 * reach that the Cloud never calls, so a stolen token finds no unsigned way round.
 *
 * @return array{required: bool, ops: list<string>, mcp: bool, id: mixed}|null
 */
function gobuildwp_cloud_signing_requirement(WP_REST_Request $request): ?array
{
    $route = $request->get_route();
    $method = strtoupper($request->get_method());

    if (!gobuildwp_cloud_signing_is_mcp_route($route)) {
        return [
            'required' => !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true),
            'ops' => [gobuildwp_cloud_signing_rest_op($route)],
            'mcp' => false,
            'id' => null,
        ];
    }

    if ($method !== 'POST') {
        return null;
    }
    /** @var mixed $body */
    $body = json_decode($request->get_body(), associative: true);
    if (!is_array($body)) {
        return null;
    }
    // A JSON-RPC batch is a list; array_is_list() is PHP 8.1 and this plugin still runs on 8.0.
    $batch = $body !== [] && array_keys($body) === range(0, count($body) - 1);
    $messages = $batch ? $body : [$body];

    $ops = [];
    $required = false;
    foreach ($messages as $message) {
        if (!is_array($message) || ($message['method'] ?? null) !== 'tools/call') {
            continue;
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $ops[] = $name;
        if (!gobuildwp_cloud_signing_tool_is_readonly($name)) {
            $required = true;
        }
    }
    if ($ops === []) {
        return null;
    }

    return [
        'required' => $required,
        'ops' => array_values(array_unique($ops)),
        'mcp' => true,
        'id' => $batch ? null : ($body['id'] ?? null),
    ];
}

/**
 * Verify one request against the stored key.
 *
 * Errors carry only the code: nothing about the value that was expected.
 *
 * @param array{kid: string, public: string, previous: array{kid: string, public: string}|null, previous_until: int, since: int} $signing
 * @param list<string> $ops
 */
function gobuildwp_cloud_signing_verify(WP_REST_Request $request, array $signing, bool $required, array $ops, ?int $now = null): bool|WP_Error
{
    $now ??= time();
    $header = static function (string $name) use ($request): ?string {
        $value = $request->get_header($name);
        return is_string($value) ? trim($value) : null;
    };

    $ts = $header('X-GoBuildWP-Ts');
    $nonce = $header('X-GoBuildWP-Nonce');
    $request_id = $header('X-GoBuildWP-Request-Id');
    $op = $header('X-GoBuildWP-Op');
    $kid = $header('X-GoBuildWP-Key-Id');
    $sig = $header('X-GoBuildWP-Sig');
    $site_sig = $header('X-GoBuildWP-Site-Sig');
    // An empty header may not survive every proxy; it signs as "" either way.
    $approval = $header('X-GoBuildWP-Approval') ?? '';

    if ($sig === null && $kid === null && $ts === null && $nonce === null) {
        return $required ? gobuildwp_cloud_signing_error('gobuildwp_signature_required') : true;
    }
    if ($ts === null || $nonce === null || $request_id === null || $op === null || $kid === null || $sig === null) {
        return gobuildwp_cloud_signing_error($required ? 'gobuildwp_signature_required' : 'gobuildwp_signature_invalid');
    }

    if (
        preg_match('/^\d{1,12}$/', $ts) !== 1
        || preg_match('/^[A-Za-z0-9_-]{22,64}$/', $nonce) !== 1
        || preg_match('/^[A-Za-z0-9-]{1,64}$/', $request_id) !== 1
        || strlen($approval) > 64
    ) {
        return gobuildwp_cloud_signing_error('gobuildwp_signature_invalid');
    }

    $public = null;
    if ($kid === $signing['kid']) {
        $public = $signing['public'];
    } elseif ($signing['previous'] !== null && $kid === $signing['previous']['kid'] && $now <= $signing['previous_until']) {
        $public = $signing['previous']['public'];
    }
    if ($public === null) {
        return gobuildwp_cloud_signing_error('gobuildwp_signature_invalid');
    }

    if (abs($now - (int) $ts) > GOBUILDWP_CLOUD_SIGNING_SKEW) {
        return gobuildwp_cloud_signing_error('gobuildwp_stale');
    }

    foreach ($ops as $expected) {
        if (!hash_equals($expected, $op)) {
            return gobuildwp_cloud_signing_error('gobuildwp_signature_invalid');
        }
    }

    if (!gobuildwp_cloud_signing_supported()) {
        return gobuildwp_cloud_signing_error('gobuildwp_signature_invalid');
    }
    $signature = base64_decode($sig, strict: true);
    $key = base64_decode($public, strict: true);
    if (!is_string($signature) || strlen($signature) !== 64 || !is_string($key) || strlen($key) !== 32) {
        return gobuildwp_cloud_signing_error('gobuildwp_signature_invalid');
    }
    $bound = ($signing['site_bound'] ?? 0) > 0;
    if ($site_sig === null && $bound) {
        // This site has seen the site-bound form: a signature that names no site could have been captured elsewhere.
        return gobuildwp_cloud_signing_error('gobuildwp_site_signature_required');
    }
    if ($site_sig !== null) {
        $signature = base64_decode($site_sig, strict: true);
        if (!is_string($signature) || strlen($signature) !== 64) {
            return gobuildwp_cloud_signing_error('gobuildwp_signature_invalid');
        }
        $message = gobuildwp_cloud_signing_site_message(
            $request->get_method(),
            $request->get_route(),
            $request->get_body(),
            $ts,
            $nonce,
            $request_id,
            $op,
            $approval,
            (string) ($signing['site_id'] ?? ''),
        );
    } else {
        $message = gobuildwp_cloud_signing_message(
            $request->get_method(),
            $request->get_route(),
            $request->get_body(),
            $ts,
            $nonce,
            $request_id,
            $op,
            $approval,
        );
    }
    try {
        $valid = sodium_crypto_sign_verify_detached($signature, $message, $key);
    } catch (Throwable) {
        $valid = false;
    }
    if (!$valid) {
        return gobuildwp_cloud_signing_error('gobuildwp_signature_invalid');
    }
    if ($site_sig !== null && !$bound) {
        gobuildwp_cloud_signing_bind_site($now);
    }

    // Only after the signature holds, so a forged request cannot use up a nonce.
    if (!gobuildwp_cloud_signing_claim_nonce($nonce)) {
        return gobuildwp_cloud_signing_error('gobuildwp_replay');
    }

    return true;
}

/**
 * Record a nonce; false when it was seen in the last fifteen minutes.
 *
 * Both stores claim atomically, so two requests carrying the same nonce at the
 * same moment cannot both pass. With a persistent object cache, wp_cache_add()
 * only creates. Without one, a plain INSERT of an option row named after the
 * nonce fails on the unique option_name for every request but the first; the
 * row holds its expiry, and expired rows are deleted as new ones are claimed.
 */
function gobuildwp_cloud_signing_claim_nonce(string $nonce, ?int $now = null): bool
{
    $key = GOBUILDWP_CLOUD_SIGNING_NONCE_PREFIX . sha1($nonce);

    if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_add')) {
        return wp_cache_add($key, 1, 'transient', GOBUILDWP_CLOUD_SIGNING_NONCE_TTL);
    }

    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    $now ??= time();
    $expires = (string) ($now + GOBUILDWP_CLOUD_SIGNING_NONCE_TTL);

    $suppress = $wpdb->suppress_errors(true);
    // @mago-expect analysis:possibly-invalid-argument -- $wpdb->options is a trusted table name.
    $claimed = $wpdb->query(
        // @mago-expect analysis:possibly-invalid-argument
        $wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $key, $expires),
    );
    $wpdb->suppress_errors($suppress);

    if ($claimed === false) {
        // The row exists. Only one that has expired may be taken over, and the
        // conditional UPDATE lets exactly one request do that.
        // @mago-expect analysis:possibly-invalid-argument
        $claimed = $wpdb->query(
            // @mago-expect analysis:possibly-invalid-argument
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS SIGNED) <= %d",
                $expires,
                $key,
                $now,
            ),
        );

        return (int) $claimed === 1;
    }

    // LIKE narrows to the prefix through the option_name index; SUBSTR makes the
    // match exact, since `_` in the pattern is a wildcard.
    // @mago-expect analysis:possibly-invalid-argument
    $wpdb->query(
        // @mago-expect analysis:possibly-invalid-argument
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND SUBSTR(option_name, 1, %d) = %s AND CAST(option_value AS SIGNED) <= %d",
            GOBUILDWP_CLOUD_SIGNING_NONCE_PREFIX . '%',
            strlen(GOBUILDWP_CLOUD_SIGNING_NONCE_PREFIX),
            GOBUILDWP_CLOUD_SIGNING_NONCE_PREFIX,
            $now,
        ),
    );

    return true;
}

function gobuildwp_cloud_signing_error(string $code): WP_Error
{
    $messages = [
        'gobuildwp_signature_required' => __('This GoBuildWP Cloud request must be signed.', domain: 'wppilot'),
        'gobuildwp_site_signature_required' => __('This GoBuildWP Cloud request must carry a signature bound to this site.', domain: 'wppilot'),
        'gobuildwp_replay' => __('This GoBuildWP Cloud request was already used.', domain: 'wppilot'),
        'gobuildwp_stale' => __('This GoBuildWP Cloud request is too old or too far in the future.', domain: 'wppilot'),
    ];

    return new WP_Error($code, $messages[$code] ?? __('The GoBuildWP Cloud request signature is not valid.', domain: 'wppilot'), [
        'status' => 401,
    ]);
}

/**
 * rest_pre_dispatch: refuse a Cloud request whose signature is missing where
 * required, or wrong wherever it is present.
 *
 * Before the MCP transport answers (and before the bundled adapter, which the
 * request reaches only through dispatch after this), and before route matching
 * runs any REST callback, so no Cloud call reaches a tool or a route unchecked.
 *
 * @return mixed The untouched $result, or the refusal.
 */
function gobuildwp_cloud_signing_pre_dispatch(mixed $result, mixed $server, mixed $request): mixed
{
    unset($server);
    if ($result !== null || !$request instanceof WP_REST_Request) {
        return $result;
    }

    $presented = function_exists('wppilot_current_token_id') ? wppilot_current_token_id() : 0;
    if (!wppilot_cloud_token_is_linked($presented, wppilot_cloud_link())) {
        return $result;
    }
    $signing = gobuildwp_cloud_signing_stored();
    if ($signing === null) {
        return $result;
    }

    $requirement = gobuildwp_cloud_signing_requirement($request);
    if ($requirement === null) {
        return $result;
    }

    $verdict = gobuildwp_cloud_signing_verify($request, $signing, $requirement['required'], $requirement['ops']);
    if ($verdict === true) {
        // verify() also answers true for an unsigned read; only a request that carried a
        // signature, and so passed every check above, counts as a verified Cloud request.
        if ($request->get_header('X-GoBuildWP-Sig') !== null) {
            gobuildwp_cloud_request_set_verified(true);
        }
        return $result;
    }

    return $requirement['mcp'] ? gobuildwp_cloud_signing_rpc_error($verdict, $requirement['id']) : $verdict;
}

/**
 * Whether the current request is a GoBuildWP Cloud request whose signature this site verified:
 * the linked Cloud token, a stored Cloud key, and a signature that passed
 * gobuildwp_cloud_signing_verify() in gobuildwp_cloud_signing_pre_dispatch().
 *
 * False for an unsigned request (an unsigned read included), for every other credential, and in
 * WP-CLI and WP-Cron, which never pass through that check. Paid Cloud features (Security
 * Guardian's write abilities) require it, so a local administrator or another MCP client cannot
 * run them directly.
 *
 * Request-scoped: it lives in this PHP process only, which serves one request. A nested
 * rest_do_request() leaves it as it is, so an ability that dispatches internally keeps it.
 */
function gobuildwp_cloud_request_verified(): bool
{
    return gobuildwp_cloud_request_set_verified(null);
}

/**
 * Record (or, with null, only read) whether the current request was verified.
 *
 * @internal Set by gobuildwp_cloud_signing_pre_dispatch(); tests set and reset it.
 */
function gobuildwp_cloud_request_set_verified(?bool $verified): bool
{
    static $state = false;
    if ($verified !== null) {
        $state = $verified;
    }

    return $state;
}

/**
 * A refusal on an MCP endpoint, as JSON-RPC: code -32001, the error code as the
 * message and in `data`. HTTP 200 like every other JSON-RPC error here, so the
 * Cloud reads the code rather than taking it for a revoked credential.
 */
function gobuildwp_cloud_signing_rpc_error(WP_Error $error, mixed $id): WP_REST_Response
{
    return new WP_REST_Response([
        'jsonrpc' => '2.0',
        'id' => is_string($id) || is_int($id) ? $id : null,
        'error' => [
            'code' => GOBUILDWP_CLOUD_SIGNING_RPC_ERROR,
            'message' => $error->get_error_code(),
            'data' => ['code' => $error->get_error_code()],
        ],
    ], 200);
}
