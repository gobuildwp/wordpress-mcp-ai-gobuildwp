<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\OAuth\ClientIdMetadata;

use WP_Error;

/**
 * Client ID Metadata Documents — the modern client-registration mechanism.
 *
 * MCP `2026-07-28` deprecates RFC 7591 Dynamic Client Registration in favour of
 * a client identifying itself with an HTTPS URL that resolves to a JSON
 * document describing it. DCR stays available as a fallback for clients and
 * authorization servers that do not support CIMD.
 *
 * This makes the authorization server fetch a URL chosen by an unauthenticated
 * caller, which is a textbook SSRF sink: WordPress commonly runs inside a
 * network where `169.254.169.254` hands out cloud credentials and where
 * internal services trust anything originating from the app server. Every
 * fetch is therefore pinned to a host that resolves exclusively to public
 * addresses, re-checked after each redirect, and bounded in size and time.
 *
 * @link https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization/client-registration
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Longest document accepted, in bytes. */
const MAX_DOCUMENT_BYTES = 65536;

/** Wall-clock budget for one fetch, in seconds. */
const FETCH_TIMEOUT_SECONDS = 5;

/** Redirects followed before giving up. */
const MAX_REDIRECTS = 3;

/** Deepest JSON nesting accepted. */
const MAX_JSON_DEPTH = 16;

/** How long a validated document is cached, in seconds. */
const CACHE_TTL_SECONDS = 3600;

/** Cache key prefix. */
const CACHE_PREFIX = 'wppilot_cimd_';

/**
 * IPv4 ranges that must never be fetched.
 *
 * Beyond the obvious private ranges this covers carrier-grade NAT, the
 * link-local block that carries every major cloud's instance-metadata service,
 * the IETF protocol-assignment and documentation blocks, benchmarking, and all
 * multicast and reserved space.
 *
 * @var list<array{0: string, 1: int}>
 */
const BLOCKED_IPV4_RANGES = [
    ['0.0.0.0', 8],          // "this network"
    ['10.0.0.0', 8],         // private
    ['100.64.0.0', 10],      // carrier-grade NAT
    ['127.0.0.0', 8],        // loopback
    ['169.254.0.0', 16],     // link-local, incl. 169.254.169.254 metadata
    ['172.16.0.0', 12],      // private
    ['192.0.0.0', 24],       // IETF protocol assignments
    ['192.0.2.0', 24],       // documentation
    ['192.88.99.0', 24],     // 6to4 relay anycast
    ['192.168.0.0', 16],     // private
    ['198.18.0.0', 15],      // benchmarking
    ['198.51.100.0', 24],    // documentation
    ['203.0.113.0', 24],     // documentation
    ['224.0.0.0', 4],        // multicast
    ['240.0.0.0', 4],        // reserved, incl. 255.255.255.255
];

/**
 * IPv6 ranges that must never be fetched.
 *
 * @var list<array{0: string, 1: int}>
 */
const BLOCKED_IPV6_RANGES = [
    ['::', 128],             // unspecified
    ['::1', 128],            // loopback
    ['64:ff9b::', 96],       // NAT64
    ['100::', 64],           // discard-only
    ['2001:db8::', 32],      // documentation
    ['fc00::', 7],           // unique local
    ['fe80::', 10],          // link-local
    ['ff00::', 8],           // multicast
];

/**
 * Whether an IP literal falls inside a blocked range.
 *
 * IPv4-mapped IPv6 addresses (`::ffff:127.0.0.1`) are unwrapped first, because
 * checking them only against the IPv6 table would let loopback through.
 */
function is_blocked_ip(string $ip): bool
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        // Unparseable is not provably public, so it is refused.
        return true;
    }

    if (strlen($packed) === 16) {
        $mapped_prefix = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
        if (str_starts_with($packed, $mapped_prefix)) {
            $packed = substr($packed, 12);
        }
    }

    $ranges = strlen($packed) === 4 ? BLOCKED_IPV4_RANGES : BLOCKED_IPV6_RANGES;
    foreach ($ranges as [$network, $bits]) {
        $network_packed = @inet_pton($network);
        if ($network_packed !== false && ip_in_range($packed, $network_packed, $bits)) {
            return true;
        }
    }

    return false;
}

/**
 * Compare two packed addresses over a prefix length.
 */
function ip_in_range(string $packed, string $network, int $bits): bool
{
    if (strlen($packed) !== strlen($network)) {
        return false;
    }

    $whole_bytes = intdiv($bits, 8);
    $remainder = $bits % 8;

    if ($whole_bytes > 0 && strncmp($packed, $network, $whole_bytes) !== 0) {
        return false;
    }
    if ($remainder === 0) {
        return true;
    }

    $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

    return (ord($packed[$whole_bytes]) & $mask) === (ord($network[$whole_bytes]) & $mask);
}

/**
 * Validate the shape of a client-id URL before anything is resolved or fetched.
 *
 * Structural rules only, so a malformed identifier is rejected without a DNS
 * lookup. Userinfo is refused because `https://evil.test@internal/` reads as
 * the internal host to a fetcher but as `evil.test` to a careless human
 * reviewer, and a fragment is refused because it is never sent to the server
 * and so cannot be part of an identifier that must compare exactly.
 */
function validate_client_id_url(string $client_id): ?WP_Error
{
    $parts = wp_parse_url($client_id);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return new WP_Error('invalid_client_id', 'The client_id must be an absolute HTTPS URL.');
    }
    if (strtolower((string) $parts['scheme']) !== 'https') {
        return new WP_Error('invalid_client_id', 'The client_id must use HTTPS.');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return new WP_Error('invalid_client_id', 'The client_id must not contain userinfo.');
    }
    if (isset($parts['fragment'])) {
        return new WP_Error('invalid_client_id', 'The client_id must not contain a fragment.');
    }
    if (strlen($client_id) > 2048) {
        return new WP_Error('invalid_client_id', 'The client_id URL is too long.');
    }

    return null;
}

/**
 * Whether a hostname resolves exclusively to public addresses.
 *
 * Every A and AAAA record is checked, not just the first: a DNS-rebinding
 * attacker publishes one public and one internal address so that a check of
 * "the" resolved address can pass while the socket connects to the internal
 * one. A host with no usable records is refused rather than deferred to the
 * HTTP layer.
 *
 * @param list<string> $resolved Addresses the host resolves to.
 */
function resolved_addresses_are_public(array $resolved): bool
{
    if ($resolved === []) {
        return false;
    }

    foreach ($resolved as $ip) {
        if (is_blocked_ip($ip)) {
            return false;
        }
    }

    return true;
}

/**
 * Resolve a hostname to every address it advertises.
 *
 * A bare IP literal is returned as-is so it still passes through the range
 * checks rather than being handed to the resolver.
 *
 * @return list<string>
 */
function resolve_host(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return [$host];
    }

    $addresses = [];
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $addresses[] = $record['ip'];
            }
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }
    }

    return array_values(array_unique($addresses));
}

/**
 * Run every pre-fetch check against a URL.
 *
 * Applied to the initial client_id and, unchanged, to each redirect target, so
 * a redirect cannot be the hole the direct request was not.
 */
function validate_fetch_target(string $url): ?WP_Error
{
    $structural = validate_client_id_url($url);
    if ($structural !== null) {
        return $structural;
    }

    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    if (!resolved_addresses_are_public(resolve_host($host))) {
        // Deliberately vague. Naming the address would turn this endpoint into
        // an internal-network scanner for an unauthenticated caller.
        return new WP_Error('client_id_unreachable', 'The client_id URL could not be retrieved.');
    }

    return null;
}

/**
 * Validate a decoded Client ID Metadata Document.
 *
 * The identity check is exact string equality between the URL that was fetched
 * and the document's own `client_id`. Anything looser lets one document claim
 * an identifier hosted elsewhere.
 *
 * @param array<string, mixed> $document
 * @return array<string, mixed>|WP_Error
 */
function validate_document(array $document, string $client_id): array|WP_Error
{
    if (($document['client_id'] ?? null) !== $client_id) {
        return new WP_Error(
            'client_id_mismatch',
            'The metadata document\'s client_id does not exactly match the URL it was fetched from.',
        );
    }

    $name = $document['client_name'] ?? null;
    if (!is_string($name) || trim($name) === '' || strlen($name) > 256) {
        return new WP_Error('invalid_client_name', 'The metadata document must declare a usable client_name.');
    }

    $redirect_uris = $document['redirect_uris'] ?? null;
    if (!is_array($redirect_uris) || $redirect_uris === []) {
        return new WP_Error('invalid_redirect_uris', 'The metadata document must declare at least one redirect URI.');
    }

    $application_type = $document['application_type'] ?? infer_application_type($redirect_uris);
    if (!in_array($application_type, ['web', 'native'], strict: true)) {
        return new WP_Error('invalid_application_type', 'application_type must be either "web" or "native".');
    }

    $validated = [];
    foreach ($redirect_uris as $uri) {
        if (!is_string($uri)) {
            return new WP_Error('invalid_redirect_uris', 'Every redirect URI must be a string.');
        }
        $error = validate_redirect_uri($uri, (string) $application_type);
        if ($error !== null) {
            return $error;
        }
        $validated[] = $application_type === 'native' ? normalize_loopback_uri($uri) : $uri;
    }

    return [
        'client_id' => $client_id,
        'client_name' => trim($name),
        'application_type' => (string) $application_type,
        // Normalisation can collapse two declared URIs onto one (a client listing both
        // the `localhost` and `127.0.0.1` spellings of the same callback), so the list
        // is deduplicated rather than carrying a redundant entry into every comparison.
        'redirect_uris' => array_values(array_unique($validated)),
        // Logo and policy URLs are carried for display only and are never
        // fetched: dereferencing them would reintroduce the SSRF sink this
        // module exists to close.
        'logo_uri' => is_string($document['logo_uri'] ?? null) ? $document['logo_uri'] : '',
    ];
}

/**
 * Infer application_type for a document that does not declare one.
 *
 * OpenID Connect defaults an omitted application_type to "web", and a web
 * client may never redirect to loopback, so the strict default rejects the
 * whole document. Native clients in the wild publish loopback redirect URIs
 * without declaring the field, and refusing them means refusing every desktop
 * client that does this. When every declared URI is one only a native client
 * is allowed to use, "native" is the sole reading that can be correct, so it
 * is the one inferred; any other list keeps the OIDC default and is validated
 * as a web client exactly as before.
 *
 * @param list<mixed> $redirect_uris
 */
function infer_application_type(array $redirect_uris): string
{
    foreach ($redirect_uris as $uri) {
        if (!is_string($uri) || validate_redirect_uri($uri, 'native') !== null) {
            return 'web';
        }
    }

    return 'native';
}

/**
 * Rewrite a loopback redirect URI's host to the IPv4 literal.
 *
 * RFC 8252 SS7.3 lets a native client bind an ephemeral port, so the port it
 * calls back on is not the one it registered. League's RedirectUriValidator
 * applies that port-agnostic comparison only to the `127.0.0.1` and `[::1]`
 * literals, never to the `localhost` name, so a client registering
 * `http://localhost/callback` and returning on `http://localhost:53821/callback`
 * fails an exact-string match. Normalising both sides onto `127.0.0.1` puts
 * them on league's loopback path instead. `localhost` is loopback by
 * definition, so this never changes which machine receives the code.
 *
 * Anything that is not an http loopback URI is returned untouched.
 */
function normalize_loopback_uri(string $uri): string
{
    $parts = wp_parse_url($uri);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'http') {
        return $uri;
    }
    if (strtolower((string) ($parts['host'] ?? '')) !== 'localhost') {
        return $uri;
    }

    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = (string) ($parts['path'] ?? '');
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';

    return 'http://127.0.0.1' . $port . $path . $query;
}

/**
 * Normalise every loopback URI in a registered list, dropping duplicates.
 *
 * The other half of normalize_loopback_uri(). The authorization endpoint
 * rewrites the URI a client *sends* onto `127.0.0.1`, and league's loopback
 * comparison then matches host and path against the URIs the client
 * *registered*. A Client ID Metadata Document goes through validate_document()
 * and is normalised there, but a Dynamic Client Registration row is stored
 * exactly as it was posted, so a client that registered `localhost` was
 * compared as `127.0.0.1` against `localhost` and refused on the host name.
 * Normalising the stored list on the way out of the repository puts both sides
 * in one form, for rows written before this existed as much as after.
 *
 * @param list<mixed> $uris
 * @return list<string>
 */
function normalize_loopback_uris(array $uris): array
{
    $normalized = [];
    foreach ($uris as $uri) {
        if (is_string($uri) && $uri !== '') {
            $normalized[] = normalize_loopback_uri($uri);
        }
    }

    return array_values(array_unique($normalized));
}

/**
 * Enforce the redirect-URI constraints for the declared application type.
 *
 * OpenID Connect's rules, which the revision requires clients to opt into by
 * declaring `application_type`: a web client redirects to HTTPS and never to
 * loopback, while a native client uses loopback or a private-use scheme and
 * never a remote HTTPS URL. Getting this wrong is how an authorization code
 * ends up delivered to the wrong application.
 */
function validate_redirect_uri(string $uri, string $application_type): ?WP_Error
{
    $parts = wp_parse_url($uri);
    if (!is_array($parts) || !isset($parts['scheme'])) {
        return new WP_Error('invalid_redirect_uris', 'Every redirect URI must be an absolute URL.');
    }
    if (isset($parts['fragment'])) {
        return new WP_Error('invalid_redirect_uris', 'A redirect URI must not contain a fragment.');
    }

    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $is_loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], strict: true);

    if ($application_type === 'web') {
        if ($scheme !== 'https') {
            return new WP_Error('invalid_redirect_uris', 'A web client\'s redirect URIs must use HTTPS.');
        }
        if ($is_loopback) {
            return new WP_Error('invalid_redirect_uris', 'A web client must not redirect to loopback.');
        }
        return null;
    }

    // Native: loopback HTTP, or a private-use scheme such as com.example.app:/.
    if ($scheme === 'http' && $is_loopback) {
        return null;
    }
    if ($scheme !== 'http' && $scheme !== 'https' && str_contains($scheme, '.')) {
        return null;
    }

    return new WP_Error(
        'invalid_redirect_uris',
        'A native client\'s redirect URIs must use loopback HTTP or a reverse-domain private-use scheme.',
    );
}

/**
 * Whether a requested redirect URI is one the document actually declared.
 *
 * Exact string comparison. Prefix or host matching would let a client that
 * registered `https://app.test/cb` receive the code at
 * `https://app.test/cb/../../evil`.
 *
 * @param array<string, mixed> $client
 */
function redirect_uri_is_registered(array $client, string $requested): bool
{
    $registered = is_array($client['redirect_uris'] ?? null) ? $client['redirect_uris'] : [];

    foreach ($registered as $uri) {
        if (is_string($uri) && hash_equals($uri, $requested)) {
            return true;
        }
    }

    return false;
}

/**
 * Decode a metadata document, bounding size and nesting.
 *
 * @return array<string, mixed>|WP_Error
 */
function decode_document(string $raw): array|WP_Error
{
    if (strlen($raw) > MAX_DOCUMENT_BYTES) {
        return new WP_Error('client_id_document_too_large', 'The metadata document exceeds the size limit.');
    }

    $decoded = json_decode($raw, associative: true, depth: MAX_JSON_DEPTH);
    if (!is_array($decoded)) {
        return new WP_Error('client_id_document_invalid', 'The metadata document is not a JSON object.');
    }

    return $decoded;
}

/**
 * Fetch and validate a Client ID Metadata Document.
 *
 * Redirects are followed manually rather than by the HTTP client, so each hop
 * is re-validated. `wp_safe_remote_get()` applies WordPress's own external-host
 * protections on top of the checks here.
 *
 * @return array<string, mixed>|WP_Error
 */
function fetch_client_metadata(string $client_id): array|WP_Error
{
    $cached = get_transient(CACHE_PREFIX . hash('sha256', $client_id));
    if (is_array($cached)) {
        return $cached;
    }

    $url = $client_id;

    for ($hop = 0; $hop <= MAX_REDIRECTS; ++$hop) {
        $target_error = validate_fetch_target($url);
        if ($target_error !== null) {
            return $target_error;
        }

        $response = wp_safe_remote_get($url, [
            'timeout' => FETCH_TIMEOUT_SECONDS,
            'redirection' => 0,
            'limit_response_size' => MAX_DOCUMENT_BYTES,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            // The underlying message can name internal hosts and addresses, so
            // it is dropped rather than surfaced to the caller.
            return new WP_Error('client_id_unreachable', 'The client_id URL could not be retrieved.');
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if (in_array($status, [301, 302, 303, 307, 308], strict: true)) {
            $location = wp_remote_retrieve_header($response, 'location');
            if (!is_string($location) || $location === '') {
                return new WP_Error('client_id_unreachable', 'The client_id URL could not be retrieved.');
            }
            // Re-enter the loop so the new target goes through every check again.
            $url = $location;
            continue;
        }

        if ($status !== 200) {
            return new WP_Error('client_id_unreachable', 'The client_id URL could not be retrieved.');
        }

        $document = decode_document((string) wp_remote_retrieve_body($response));
        if ($document instanceof WP_Error) {
            return $document;
        }

        $validated = validate_document($document, $client_id);
        if ($validated instanceof WP_Error) {
            return $validated;
        }

        set_transient(CACHE_PREFIX . hash('sha256', $client_id), $validated, CACHE_TTL_SECONDS);

        return $validated;
    }

    return new WP_Error('client_id_too_many_redirects', 'The client_id URL redirected too many times.');
}

/**
 * Whether a client identifier should be resolved as a metadata document.
 *
 * Anything that is not an HTTPS URL is a Dynamic Client Registration client id
 * and continues through the existing DCR path unchanged.
 */
function is_metadata_document_client_id(string $client_id): bool
{
    return str_starts_with(strtolower($client_id), 'https://');
}
