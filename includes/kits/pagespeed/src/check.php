<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Pagespeed;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Where a PageSpeed result comes from, in order, and the first one that answers wins.
 *
 *   1. site-kit        Site Kit by Google's PageSpeed Insights module, through Site Kit's own
 *                      REST route, when the module is connected and Site Kit lets this user read
 *                      it. Google is called with Site Kit's credentials; GoBuildWP never sees them.
 *   2. cloud           The GoBuildWP Cloud proxy, which holds GoBuildWP's own PageSpeed key. Works
 *                      whether or not the site is paired with the Cloud, unless the host's
 *                      `cloud-unpaired` extension answers false: then only a paired site uses it.
 *   3. google-api-key  Google directly, with a key the site owner saved in GoBuildWP settings.
 *   4. google-keyless  Google directly with no key. Its quota is shared by every keyless caller
 *                      on the internet and answers 429 for much of the day, so it is last.
 *
 * A source that fails for its own reasons (quota, a refused key, a transport error) hands over to
 * the next. A failure that belongs to the page (too slow to load, unreachable) stops the chain:
 * every source tests the same public URL from Google's servers and would take as long to fail the
 * same way.
 */

const ABILITY = 'wppilot/pagespeed-check';

/** The site-kit-sharing kit's write, named in a hint when Site Kit could have answered. */
const SHARING_ABILITY = 'wppilot/site-kit-enable-sharing';

/** Kit-owned option: a PageSpeed Insights API key the site owner chose to add. Never returned. */
const API_KEY_OPTION = 'wppilot_kit_pagespeed_api_key';

const CACHE_PREFIX = 'wppilot_kit_pagespeed_';

/** A result is reused for 15 minutes unless `refresh` is passed; each run costs quota and 10-60 s. */
const CACHE_TTL = 900;

const CLOUD_PATH = '/api/pagespeed/v1/run';

const GOOGLE_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

/** Lighthouse alone can take a minute on a slow page; the proxy adds its own hop. */
const HTTP_TIMEOUT = 90;

/** The Cloud allows Google 90 seconds and asks clients to wait at least 100. */
const CLOUD_TIMEOUT = 110;

/** Once this many seconds have gone, the remaining sources are skipped rather than started. */
const TIME_BUDGET = 150;

const SOURCES = ['site-kit', 'cloud', 'google-api-key', 'google-keyless'];

const STRATEGIES = ['mobile', 'desktop'];

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function check(array $input): array|WP_Error
{
    $url = resolve_url(is_string($input['url'] ?? null) ? $input['url'] : '');
    if ($url instanceof WP_Error) {
        return $url;
    }
    $strategy = is_string($input['strategy'] ?? null) ? $input['strategy'] : 'mobile';
    if ($strategy !== 'both' && !in_array($strategy, STRATEGIES, strict: true)) {
        return new WP_Error('kit_pagespeed_invalid_input', 'strategy must be mobile, desktop or both.', ['status' => 400]);
    }
    $only = is_string($input['source'] ?? null) ? $input['source'] : 'auto';
    if ($only !== 'auto' && !in_array($only, SOURCES, strict: true)) {
        return new WP_Error('kit_pagespeed_invalid_input', 'source must be auto, site-kit, cloud, google-api-key or google-keyless.', ['status' => 400]);
    }
    $refresh = ($input['refresh'] ?? false) === true;

    if ($strategy !== 'both') {
        return run($url, $strategy, $only, $refresh);
    }

    $results = [];
    foreach (STRATEGIES as $one) {
        $result = run($url, $one, $only, $refresh);
        $results[$one] = $result instanceof WP_Error ? error_summary($result) : $result;
    }
    if (isset($results['mobile']['error'], $results['desktop']['error'])) {
        return run_error_from_summary($results['mobile']);
    }

    return ['url' => $url, 'strategy' => 'both', 'results' => $results];
}

/**
 * One strategy through the source chain.
 *
 * @return array<string, mixed>|WP_Error
 */
function run(string $url, string $strategy, string $only, bool $refresh): array|WP_Error
{
    $key = CACHE_PREFIX . md5($url . '|' . $strategy . '|' . $only);
    if (!$refresh) {
        /** @var mixed $hit */
        $hit = get_transient($key);
        if (is_array($hit) && is_array($hit['scores'] ?? null)) {
            $hit['cached'] = true;
            return $hit;
        }
    }

    $started = microtime(true);
    $attempts = [];
    $last_error = null;
    foreach (SOURCES as $source) {
        if ($only !== 'auto' && $source !== $only) {
            continue;
        }
        if (microtime(true) - $started > TIME_BUDGET) {
            $attempts[] = ['source' => $source, 'outcome' => 'skipped', 'reason' => 'time_budget'];
            continue;
        }
        $answer = from_source($source, $url, $strategy, $refresh);
        if (is_string($answer)) {
            $attempts[] = ['source' => $source, 'outcome' => 'skipped', 'reason' => $answer];
            continue;
        }
        if ($answer instanceof WP_Error) {
            $attempts[] = ['source' => $source, 'outcome' => 'failed', 'code' => (string) $answer->get_error_code(), 'message' => (string) $answer->get_error_message()];
            $last_error = $answer;
            $data = $answer->get_error_data();
            if (is_array($data) && ($data['page'] ?? false) === true) {
                break;
            }
            continue;
        }
        $attempts[] = ['source' => $source, 'outcome' => 'answered'];
        $answer['source'] = $source;
        $answer['attempts'] = $attempts;
        if ($source === 'site-kit') {
            $answer['note'] = 'Site Kit asks Google for the performance category only, so the seo, accessibility and best_practices scores are null.';
        }
        if (in_array('site_kit_not_shared_with_user', array_column($attempts, 'reason'), strict: true) && wp_has_ability(SHARING_ABILITY)) {
            $answer['fix'] = [
                'ability' => SHARING_ABILITY,
                'message' => 'Site Kit\'s PageSpeed Insights module is connected but not shared with this user\'s role, so another source answered. Offer once to turn on read-only Site Kit dashboard sharing for Administrators with ' . SHARING_ABILITY . ' (needs confirm=true; Site Kit only accepts it from an administrator signed in to Site Kit with Google).',
            ];
        }
        if (in_array('site_kit_owner_signed_out', array_column($attempts, 'reason'), strict: true)) {
            $answer['fix'] = [
                'action' => 'site_kit_sign_in',
                'url' => admin_url('admin.php?page=googlesitekit-dashboard'),
                'message' => 'This user owns Site Kit\'s PageSpeed Insights module but is no longer signed in to Site Kit with Google, so another source answered. Sign in once at Site Kit > Dashboard as this user'
                    . (wp_has_ability(SHARING_ABILITY) ? '; then it can be shared read-only with Administrators (' . SHARING_ABILITY . ').' : '.'),
            ];
        }
        set_transient($key, $answer, CACHE_TTL);
        return $answer;
    }

    if ($last_error instanceof WP_Error) {
        $data = $last_error->get_error_data();
        return new WP_Error(
            $last_error->get_error_code(),
            $last_error->get_error_message(),
            array_merge(is_array($data) ? $data : [], ['attempts' => $attempts]),
        );
    }

    return new WP_Error(
        'kit_pagespeed_no_source',
        'No PageSpeed source could be used for this request.',
        ['status' => 503, 'attempts' => $attempts],
    );
}

/**
 * Ask one source. A string is the reason it was not asked at all.
 *
 * @return array<string, mixed>|WP_Error|string
 */
function from_source(string $source, string $url, string $strategy, bool $refresh = false): array|WP_Error|string
{
    switch ($source) {
        case 'site-kit':
            return from_site_kit($url, $strategy);
        case 'cloud':
            return from_cloud_proxy($url, $strategy, $refresh);
        case 'google-api-key':
            $api_key = api_key();
            return $api_key === '' ? 'no_api_key_saved' : from_google($url, $strategy, $api_key);
        default:
            return from_google($url, $strategy, '');
    }
}

/**
 * Site Kit's PageSpeed Insights module through its own REST route, in-process.
 *
 * Confirmed against Site Kit 1.189.0: GET google-site-kit/v1/modules/pagespeed-insights/data/pagespeed
 * with url and strategy (mobile|desktop) runs PagespeedInsights::runpagespeed with the requesting
 * user's Google client, or the module owner's when the module is shared with the user's role
 * (Modules::get_module_for_datapoint). The route needs googlesitekit_setup or
 * googlesitekit_view_posts_insights. Site Kit requests the performance category only.
 *
 * @return array<string, mixed>|WP_Error|string
 */
function from_site_kit(string $url, string $strategy): array|WP_Error|string
{
    if (!defined('GOOGLESITEKIT_VERSION')) {
        return 'site_kit_not_active';
    }
    if (!current_user_can('googlesitekit_setup') && !current_user_can('googlesitekit_view_posts_insights')) {
        return 'site_kit_not_readable_by_user';
    }
    $list = site_kit_get('core/modules/data/list', []);
    $module = null;
    if (is_array($list)) {
        foreach ($list as $entry) {
            if (is_array($entry) && ($entry['slug'] ?? '') === 'pagespeed-insights') {
                $module = $entry;
            }
        }
        if (!is_array($module) || empty($module['active']) || empty($module['connected'])) {
            return 'site_kit_module_not_connected';
        }
    }
    // Site Kit lists its PageSpeed module as connected before anyone has signed in to Google, but
    // it cannot answer until setup is finished; sharing would not help either, so it is not offered.
    $connection = site_kit_get('core/site/data/connection', []);
    if (is_array($connection) && array_key_exists('setupCompleted', $connection) && $connection['setupCompleted'] !== true) {
        return 'site_kit_not_set_up';
    }
    $authenticated = site_kit_get('core/user/data/authentication', []);
    $own_google = is_array($authenticated) && !empty($authenticated['authenticated']);
    // The owner always reads with their own token (Module::get_oauth_client_for_datapoint skips
    // the shared path for the owner), and Site Kit serves everyone else's shared reads from that
    // same token, so an owner whose sign-in is gone needs to sign in again; sharing cannot help.
    // Site Kit lists the owner only to users who can list_users.
    $owner_id = is_array($module) && is_array($module['owner'] ?? null) ? (int) ($module['owner']['id'] ?? 0) : 0;
    if (is_array($authenticated) && !$own_google && $owner_id > 0 && $owner_id === get_current_user_id()) {
        return 'site_kit_owner_signed_out';
    }
    if (!$own_google &&!current_user_can('googlesitekit_read_shared_module_data', 'pagespeed-insights')) {
        // Site Kit would answer with this user's own (absent) Google sign-in and fail. Sharing the
        // module with the user's role is what lets it answer with the owner's.
        return 'site_kit_not_shared_with_user';
    }

    $raw = site_kit_get('modules/pagespeed-insights/data/pagespeed', ['url' => $url, 'strategy' => $strategy]);
    if ($raw instanceof WP_Error) {
        $data = $raw->get_error_data();
        $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
        $reason = is_array($data) ? (string) ($data['reason'] ?? '') : '';
        return lighthouse_error((string) $raw->get_error_code() . ' ' . $reason, (string) $raw->get_error_message(), $status);
    }
    if (!is_array($raw)) {
        return new WP_Error('kit_pagespeed_bad_response', 'Site Kit answered the PageSpeed request with no data.');
    }

    return from_psi($raw, $url, $strategy);
}

/**
 * One GET to a Site Kit route, in-process, as plain arrays.
 *
 * The PageSpeed datapoint returns Google API client models inside the response, which only become
 * JSON when WordPress serves them; in-process nothing does, so encode and decode as the server
 * would.
 *
 * @param array<string, mixed> $params
 */
function site_kit_get(string $route, array $params): mixed
{
    /**
     * Short-circuit a Site Kit request: return anything but null to answer it without dispatching,
     * as pre_http_request does for HTTP. For hosts that reach Site Kit some other way, and tests.
     *
     * @param mixed                $answer null to dispatch.
     * @param string               $route  Route under google-site-kit/v1/.
     * @param array<string, mixed> $params Query parameters.
     */
    /** @var mixed $answer */
    $answer = apply_filters('wppilot_kit_pagespeed_pre_site_kit_request', null, $route, $params);
    if ($answer !== null) {
        return $answer;
    }
    if (!class_exists('WP_REST_Request') || !function_exists('rest_do_request')) {
        return new WP_Error('kit_pagespeed_rest_unavailable', 'The WordPress REST API is not loaded.');
    }
    $request = new \WP_REST_Request('GET', '/google-site-kit/v1/' . $route);
    $request->set_query_params($params);
    $response = rest_do_request($request);
    if ($response instanceof WP_Error) {
        return $response;
    }
    if (is_object($response) && method_exists($response, 'is_error') && $response->is_error()) {
        return $response->as_error();
    }
    $data = is_object($response) && method_exists($response, 'get_data') ? $response->get_data() : $response;
    if ($data === null || is_scalar($data)) {
        return $data;
    }
    $json = wp_json_encode($data);

    return is_string($json) ? json_decode($json, true) : null;
}

/**
 * The GoBuildWP Cloud proxy (pairing protocol §8).
 *
 * POST {cloud}/api/pagespeed/v1/run with JSON {url, strategy, site_url}; 200 with the shared shape,
 * cached by the Cloud for an hour per (url, strategy); errors {error: {code, message,
 * retry_after?}}. A paired site signs the call as its heartbeat does (§5: site_id, ts and nonce in
 * the body, X-WPPilot-Signature over it) through the host's `cloud-sign` extension, and gets the
 * site's own daily allowance; an unpaired one sends the plain body under small per-host limits.
 * No Authorization header is ever sent: the Cloud refuses one (401 unsupported_auth), and the site
 * holds no bearer credential for it. When the Cloud no longer knows the paired site (404
 * unknown_site) the call is repeated unsigned; the link itself is the heartbeat's business.
 *
 * With $refresh a signed call also sends `refresh: true`, and the Cloud skips its hour-long cache
 * for it (counted against the site's daily allowance). It honours that only for a signed call, so
 * the unsigned body never carries it.
 *
 * @return array<string, mixed>|WP_Error|string
 */
function from_cloud_proxy(string $url, string $strategy, bool $refresh = false): array|WP_Error|string
{
    $payload = ['url' => $url, 'strategy' => $strategy, 'site_url' => home_url()];

    /** @var mixed $signer */
    $signer = Runtime\host()->extension('cloud-sign');
    /** @var mixed $signed */
    $signed = is_callable($signer) ? $signer($refresh ? array_merge($payload, ['refresh' => true]) : $payload) : null;
    if (is_array($signed) && is_string($signed['body'] ?? null) && is_string($signed['base'] ?? null)) {
        $base = filtered_cloud_url($signed['base']);
        if ($base !== '') {
            $answer = cloud_post($base, $signed['body'], is_array($signed['headers'] ?? null) ? $signed['headers'] : [], $url, $strategy);
            $data = $answer instanceof WP_Error ? $answer->get_error_data() : null;
            if (!is_array($data) || ($data['cloud_code'] ?? '') !== 'unknown_site') {
                return $answer;
            }
        }
    }

    // A host can keep an unpaired site's tests away from the Cloud entirely (GoBuildWP's
    // WordPress.org edition does): then Google is asked directly, as if no Cloud existed.
    if (!unpaired_cloud_allowed()) {
        return 'cloud_not_connected';
    }

    $base = cloud_url();
    if ($base === '') {
        return 'cloud_url_not_usable';
    }

    return cloud_post($base, (string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES), [], $url, $strategy);
}

/**
 * Whether a site that is not paired with the Cloud may still use its proxy, unsigned.
 *
 * The host's `cloud-unpaired` extension decides; a host that does not answer (a kit exported into
 * another plugin) keeps the proxy available, as before this extension point existed.
 */
function unpaired_cloud_allowed(): bool
{
    /** @var mixed $allowed */
    $allowed = Runtime\host()->extension('cloud-unpaired');

    return $allowed !== false;
}

/**
 * @param array<array-key, mixed> $extra_headers
 * @return array<string, mixed>|WP_Error
 */
function cloud_post(string $base, string $body, array $extra_headers, string $url, string $strategy): array|WP_Error
{
    $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
    foreach ($extra_headers as $name => $value) {
        if (is_string($name) && is_string($value) && strtolower($name) !== 'authorization') {
            $headers[$name] = $value;
        }
    }
    $response = wp_remote_post($base . CLOUD_PATH, [
        'timeout' => CLOUD_TIMEOUT,
        'redirection' => 0,
        'headers' => $headers,
        'body' => $body,
    ]);
    if ($response instanceof WP_Error) {
        return transport_error($response, 'The PageSpeed proxy', CLOUD_TIMEOUT);
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    /** @var mixed $decoded */
    $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
    $decoded = is_array($decoded) ? $decoded : [];
    if ($status === 200) {
        return from_cloud($decoded, $url, $strategy);
    }

    $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
    $code = is_string($error['code'] ?? null) ? $error['code'] : 'http_' . $status;
    $retry = (int) ($error['retry_after'] ?? wp_remote_retrieve_header($response, 'retry-after'));
    $mapped = lighthouse_error(
        $code,
        is_string($error['message'] ?? null) ? $error['message'] : sprintf('The PageSpeed proxy answered HTTP %d.', $status),
        $status,
        max(0, $retry),
    );
    $data = $mapped->get_error_data();

    return new WP_Error(
        $mapped->get_error_code(),
        $mapped->get_error_message(),
        array_merge(is_array($data) ? $data : [], ['cloud_code' => $code]),
    );
}

/**
 * The Cloud's base URL: the host's (GoBuildWP's own setting, so WPPILOT_CLOUD_URL points both
 * pairing and this at a local Cloud), else the default, through this kit's filter either way.
 */
function cloud_url(): string
{
    /** @var mixed $from_host */
    $from_host = Runtime\host()->extension('cloud-url');

    return filtered_cloud_url(is_string($from_host) ? $from_host : '');
}

function filtered_cloud_url(string $url): string
{
    /**
     * Filter the base URL of the PageSpeed proxy (no trailing slash). '' skips the proxy.
     *
     * @param string $url
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_kit_pagespeed_proxy_url', $url);
    if (!is_string($filtered) || $filtered === '') {
        return '';
    }
    $scheme = strtolower((string) wp_parse_url($filtered, PHP_URL_SCHEME));
    if ($scheme !== 'https' && $scheme !== 'http') {
        return '';
    }

    return rtrim($filtered, '/');
}

/**
 * Google's PageSpeed Insights API directly, with or without a key.
 *
 * All four categories are asked for: the API scores only performance unless told otherwise.
 *
 * @return array<string, mixed>|WP_Error
 */
function from_google(string $url, string $strategy, string $api_key): array|WP_Error
{
    $query = 'url=' . rawurlencode($url) . '&strategy=' . rawurlencode($strategy);
    foreach (['performance', 'seo', 'accessibility', 'best-practices'] as $category) {
        $query .= '&category=' . $category;
    }
    if ($api_key !== '') {
        $query .= '&key=' . rawurlencode($api_key);
    }

    $response = wp_remote_get(GOOGLE_ENDPOINT . '?' . $query, [
        'timeout' => HTTP_TIMEOUT,
        'redirection' => 0,
        'headers' => ['Accept' => 'application/json'],
    ]);
    if ($response instanceof WP_Error) {
        return transport_error($response, 'Google');
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    /** @var mixed $body */
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    $body = is_array($body) ? $body : [];
    if ($status === 200) {
        return from_psi($body, $url, $strategy);
    }

    // {"error": {"code", "message", "status", "errors": [{"reason"}], "details": [{"reason"}]}}
    $error = is_array($body['error'] ?? null) ? $body['error'] : [];
    $reasons = [(string) ($error['status'] ?? '')];
    foreach (['errors', 'details'] as $list) {
        foreach ((array) ($error[$list] ?? []) as $row) {
            if (is_array($row) && is_string($row['reason'] ?? null)) {
                $reasons[] = $row['reason'];
            }
        }
    }

    return lighthouse_error(
        implode(' ', array_filter($reasons)),
        is_string($error['message'] ?? null) ? $error['message'] : sprintf('Google answered HTTP %d.', $status),
        $status,
        (int) wp_remote_retrieve_header($response, 'retry-after'),
    );
}

function transport_error(WP_Error $error, string $who, int $timeout = HTTP_TIMEOUT): WP_Error
{
    $message = scrub((string) $error->get_error_message());
    if (stripos($message, 'timed out') !== false || stripos($message, 'cURL error 28') !== false) {
        return new WP_Error(
            'kit_pagespeed_timeout',
            sprintf('%s did not answer within %d seconds. Google may still have been loading the page; the uncached page may be too slow.', $who, $timeout),
            ['status' => 504, 'page' => false, 'detail' => $message],
        );
    }

    return new WP_Error(
        'kit_pagespeed_unreachable_source',
        sprintf('Could not reach %s: %s', $who, $message),
        ['status' => 502, 'page' => false],
    );
}

/** The saved key, or what the filter supplies (a key kept in wp-config.php, say). */
function api_key(): string
{
    /** @var mixed $stored */
    $stored = get_option(API_KEY_OPTION, '');
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_kit_pagespeed_api_key', is_string($stored) ? $stored : '');

    return is_string($filtered) ? trim($filtered) : '';
}

/**
 * The page to test: this site's home page by default, or a URL or path on this site.
 *
 * Only this site's own pages: the abilities run with this site's quota and credentials, and
 * should not become a free PageSpeed service for any URL a caller names.
 */
function resolve_url(string $raw): string|WP_Error
{
    $raw = trim($raw);
    if ($raw === '') {
        return home_url('/');
    }
    if (strlen($raw) > 2048) {
        return new WP_Error('kit_pagespeed_invalid_url', 'url is too long.', ['status' => 400]);
    }
    if (str_starts_with($raw, '/') && !str_starts_with($raw, '//')) {
        $raw = home_url($raw);
    }
    $scheme = strtolower((string) wp_parse_url($raw, PHP_URL_SCHEME));
    $host = strtolower((string) wp_parse_url($raw, PHP_URL_HOST));
    $home = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $bare = static fn(string $h): string => str_starts_with($h, 'www.') ? substr($h, 4) : $h;
    if (!in_array($scheme, ['http', 'https'], strict: true) || $host === '' || $bare($host) !== $bare($home)) {
        return new WP_Error(
            'kit_pagespeed_invalid_url',
            'url must be a page on this site: an absolute http(s) URL on the site\'s host, or a path starting with /.',
            ['status' => 400],
        );
    }

    return esc_url_raw($raw);
}

/** @return array{error: array{code: string, message: string, data: mixed}} */
function error_summary(WP_Error $error): array
{
    return ['error' => [
        'code' => (string) $error->get_error_code(),
        'message' => (string) $error->get_error_message(),
        'data' => $error->get_error_data(),
    ]];
}

/** @param array<string, mixed> $summary */
function run_error_from_summary(array $summary): WP_Error
{
    $error = is_array($summary['error'] ?? null) ? $summary['error'] : [];

    return new WP_Error((string) ($error['code'] ?? 'kit_pagespeed_failed'), (string) ($error['message'] ?? ''), $error['data'] ?? null);
}
