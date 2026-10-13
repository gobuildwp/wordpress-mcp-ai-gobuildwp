<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp;

/**
 * Modern Streamable HTTP header validation.
 *
 * The transport mirrors selected body fields into HTTP headers so gateways can
 * route without parsing the body. That creates a split-brain risk: a proxy may
 * authorize on `Mcp-Name` while the server executes `params.name`. The spec
 * closes it by requiring the server to reject any disagreement, and it is the
 * body that is authoritative — the headers are only ever validated against it,
 * never used as input.
 *
 * @link https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http#server-validation
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Marker prefix for a Base64-encoded header value. */
const BASE64_SENTINEL_PREFIX = '=?base64?';

/** Marker suffix for a Base64-encoded header value. */
const BASE64_SENTINEL_SUFFIX = '?=';

/**
 * Normalize a header map to lowercase, hyphenated keys.
 *
 * Two normalizations, both required:
 *
 * 1. Case. HTTP field names are case-insensitive, so `MCP-Protocol-Version` and
 *    `mcp-protocol-version` are the same header and must not be treated as two.
 * 2. Separator. `WP_REST_Request::get_headers()` returns PHP's `$_SERVER`
 *    spelling, where every hyphen has already become an underscore —
 *    `MCP-Protocol-Version` arrives as `MCP_PROTOCOL_VERSION`. Matching on the
 *    hyphenated name alone would miss every real request while still passing a
 *    test that hand-builds the array.
 *
 * Underscores are therefore folded to hyphens. HTTP does permit an underscore
 * in a field name, but by this point the distinction is already lost — PHP
 * discards it before the request reaches WordPress — so folding matches what
 * the client actually sent.
 *
 * Values are left untouched: method and tool names are case-sensitive.
 *
 * @param array<string, mixed> $headers
 * @return array<string, string>
 */
function normalize_headers(array $headers): array
{
    $normalized = [];

    foreach ($headers as $name => $value) {
        if (is_array($value)) {
            // A repeated header arrives as a list; the first value is the one
            // an intermediary would have routed on.
            $value = $value[0] ?? '';
        }
        $key = str_replace('_', '-', strtolower(trim((string) $name)));
        $normalized[$key] = is_scalar($value) ? (string) $value : '';
    }

    return $normalized;
}

/**
 * Decode a header value that uses the Base64 sentinel format.
 *
 * Values outside the sentinel format are returned unchanged. A sentinel whose
 * payload is not valid Base64, or does not round-trip, is rejected rather than
 * silently passed through, because a decode that quietly fails would compare a
 * mangled value against the body and produce a confusing mismatch.
 */
function decode_header_value(string $value): string|false
{
    if (!str_starts_with($value, BASE64_SENTINEL_PREFIX) || !str_ends_with($value, BASE64_SENTINEL_SUFFIX)) {
        return $value;
    }

    $payload = substr(
        $value,
        strlen(BASE64_SENTINEL_PREFIX),
        -strlen(BASE64_SENTINEL_SUFFIX),
    );

    $decoded = base64_decode($payload, strict: true);
    if ($decoded === false) {
        return false;
    }

    return $decoded;
}

/**
 * Whether a header value is within the printable range HTTP permits.
 *
 * Visible ASCII, space, and horizontal tab. A control character here is an
 * injection attempt or a broken client, and either way must not be compared
 * against the body as if it were ordinary text.
 */
function header_value_is_safe(string $value): bool
{
    return preg_match('/^[\x20\x09\x21-\x7E]*$/', $value) === 1;
}

/**
 * Validate the mirrored headers of a modern request against its body.
 *
 * Returns null when the request is acceptable, or the error response to send.
 * Order matters: the protocol version is checked before anything else, so a
 * client on an unsupported revision is told to change versions rather than
 * being handed a confusing complaint about a header it got right.
 *
 * @param array<string, mixed> $headers Raw header map, any casing.
 * @param array<string, mixed> $body    Decoded JSON-RPC request.
 * @return array{status: int, body: array<string, mixed>}|null
 */
function validate_modern_headers(array $headers, array $body): ?array
{
    $headers = normalize_headers($headers);
    $id = $body['id'] ?? null;
    $method = is_string($body['method'] ?? null) ? (string) $body['method'] : '';

    $header_version = $headers[HEADER_PROTOCOL_VERSION] ?? '';
    if ($header_version === '') {
        return header_mismatch_error(
            sprintf('the %s header is required.', 'MCP-Protocol-Version'),
            $id,
        );
    }

    $body_version = body_protocol_version($body);
    if ($header_version !== $body_version) {
        return header_mismatch_error(
            sprintf(
                'MCP-Protocol-Version header value "%1$s" does not match body value "%2$s".',
                $header_version,
                $body_version,
            ),
            $id,
        );
    }

    if (!is_supported_version($header_version)) {
        return unsupported_protocol_version_error($header_version, $id);
    }

    $header_method = $headers[HEADER_METHOD] ?? '';
    if ($header_method === '') {
        return header_mismatch_error('the Mcp-Method header is required.', $id);
    }
    if ($header_method !== $method) {
        return header_mismatch_error(
            sprintf(
                'Mcp-Method header value "%1$s" does not match body value "%2$s".',
                $header_method,
                $method,
            ),
            $id,
        );
    }

    return validate_name_header($headers, $body, $method, $id);
}

/**
 * Validate `Mcp-Name` for the methods that require it.
 *
 * @param array<string, string> $headers Already lowercased.
 * @param array<string, mixed>  $body
 * @return array{status: int, body: array<string, mixed>}|null
 */
function validate_name_header(array $headers, array $body, string $method, mixed $id): ?array
{
    $field = NAME_HEADER_METHODS[$method] ?? null;
    if ($field === null) {
        return null;
    }

    $raw = $headers[HEADER_NAME] ?? '';
    if ($raw === '') {
        return header_mismatch_error(
            sprintf('the Mcp-Name header is required for %s requests.', $method),
            $id,
        );
    }
    if (!header_value_is_safe($raw)) {
        return header_mismatch_error('the Mcp-Name header contains characters HTTP does not permit.', $id);
    }

    $decoded = decode_header_value($raw);
    if ($decoded === false) {
        return header_mismatch_error('the Mcp-Name header is not valid Base64 for its sentinel format.', $id);
    }

    $params = is_array($body['params'] ?? null) ? $body['params'] : [];
    $expected = $params[$field] ?? null;
    if (!is_string($expected)) {
        return header_mismatch_error(
            sprintf('params.%1$s is required for %2$s and must be a string.', $field, $method),
            $id,
        );
    }

    if ($decoded !== $expected) {
        return header_mismatch_error(
            sprintf(
                'Mcp-Name header value "%1$s" does not match body value "%2$s".',
                $decoded,
                $expected,
            ),
            $id,
        );
    }

    return null;
}

/**
 * Compare a mirrored `Mcp-Param-*` value with the body value it mirrors.
 *
 * Numbers are compared numerically, not as strings, so a client sending `42.0`
 * for an integer argument of `42` is not rejected over formatting. Booleans
 * mirror as lowercase `true`/`false`.
 */
function param_header_matches(string $header_value, mixed $body_value): bool
{
    if (is_bool($body_value)) {
        return $header_value === ($body_value ? 'true' : 'false');
    }

    if (is_int($body_value) || is_float($body_value)) {
        return is_numeric($header_value) && (float) $header_value === (float) $body_value;
    }

    return is_string($body_value) && $header_value === $body_value;
}
