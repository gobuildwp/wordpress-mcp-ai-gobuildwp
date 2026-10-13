<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp;

/**
 * Protocol-era vocabulary and request classification.
 *
 * GoBuildWP is a dual-era MCP server. The vendored MCP Adapter implements the
 * legacy, session-based revisions (`2025-11-25` and earlier): `initialize`,
 * `notifications/initialized`, and the `Mcp-Session-Id` header. The modern
 * revision `2026-07-28` removes all of that — there is no handshake and no
 * session, and every request carries its own version and client capabilities
 * in `_meta`.
 *
 * Rather than scatter version checks through the adapter or the abilities,
 * classification happens once, here. Everything downstream — authentication,
 * safety profiles, rate limits, the ledger, and the abilities themselves —
 * stays protocol-independent, and only the serializer differs per era.
 *
 * @link https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning
 */

if (!defined('ABSPATH')) {
    exit();
}

/** The modern, stateless revision. */
const VERSION_MODERN = '2026-07-28';

/** The newest legacy revision the bundled adapter negotiates. */
const VERSION_LEGACY = '2025-11-25';

/**
 * Versions advertised to clients, newest first.
 *
 * Only these two are advertised. The adapter also answers `2025-06-18` and
 * `2024-11-05` for clients that ask for them by name during `initialize`, but
 * GoBuildWP does not advertise revisions it has not verified against this build.
 *
 * @var list<string>
 */
const SUPPORTED_VERSIONS = [VERSION_MODERN, VERSION_LEGACY];

/** Per-request `_meta` key carrying the protocol version. */
const META_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';

/** Per-request `_meta` key carrying the client's capabilities. */
const META_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';

/** Optional per-request `_meta` key identifying the client. */
const META_CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';

/** Optional per-request `_meta` key opting in to log notifications. */
const META_LOG_LEVEL = 'io.modelcontextprotocol/logLevel';

/** Result `_meta` key identifying this server. */
const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

/** Notification `_meta` key tagging a subscription stream. */
const META_SUBSCRIPTION_ID = 'io.modelcontextprotocol/subscriptionId';

/**
 * OpenTelemetry trace-context keys propagated verbatim when a client sends them.
 *
 * @var list<string>
 */
const META_TRACE_KEYS = ['traceparent', 'tracestate', 'baggage'];

/** HTTP header mirroring the protocol version. */
const HEADER_PROTOCOL_VERSION = 'mcp-protocol-version';

/** HTTP header mirroring the JSON-RPC method. */
const HEADER_METHOD = 'mcp-method';

/** HTTP header mirroring `params.name` or `params.uri`. */
const HEADER_NAME = 'mcp-name';

/** Legacy session header, ignored under the modern revision. */
const HEADER_SESSION_ID = 'mcp-session-id';

/**
 * Methods whose `Mcp-Name` header is required, mapped to the body field it mirrors.
 *
 * @var array<string, string>
 */
const NAME_HEADER_METHODS = [
    'tools/call' => 'name',
    'prompts/get' => 'name',
    'resources/read' => 'uri',
];

/**
 * Methods the modern revision removed.
 *
 * Each was either deleted outright (`ping`, `logging/setLevel`) or replaced by
 * per-request metadata. They remain served by the legacy adapter and must
 * answer method-not-found under the modern revision rather than quietly
 * working, which would let a client believe it had set a log level or kept a
 * session alive when it had not.
 *
 * @var list<string>
 */
const REMOVED_IN_MODERN = [
    'ping',
    'logging/setLevel',
    'initialize',
    'notifications/initialized',
    'notifications/roots/list_changed',
    'resources/subscribe',
    'resources/unsubscribe',
];

/**
 * Whether a version string is one this server implements.
 */
function is_supported_version(string $version): bool
{
    return in_array($version, SUPPORTED_VERSIONS, strict: true);
}

/**
 * Read the protocol version a request body declares.
 *
 * Returns an empty string when the body carries no version, which is the
 * signal that the request is not modern.
 *
 * @param array<string, mixed> $body Decoded JSON-RPC request.
 */
function body_protocol_version(array $body): string
{
    $params = $body['params'] ?? null;
    if (!is_array($params)) {
        return '';
    }

    $meta = $params['_meta'] ?? null;
    if (!is_array($meta)) {
        return '';
    }

    $version = $meta[META_PROTOCOL_VERSION] ?? null;

    return is_string($version) ? $version : '';
}

/**
 * Classify a request as modern or legacy.
 *
 * The rule comes straight from the versioning spec: a request carrying modern
 * per-request `_meta` is served statelessly under the modern revision, and an
 * `initialize` request selects legacy semantics.
 *
 * The header alone is deliberately not enough to select the modern era. A
 * legacy `2025-06-18`+ client also sends `MCP-Protocol-Version`, so treating
 * the header as the selector would route legacy traffic into the stateless
 * path and break every existing connection. Header/body disagreement is a
 * validation failure handled separately, not an era signal.
 *
 * @param array<string, mixed> $body Decoded JSON-RPC request.
 */
function is_modern_request(array $body): bool
{
    return body_protocol_version($body) !== '';
}

/**
 * Whether a method was removed by the modern revision.
 */
function is_removed_in_modern(string $method): bool
{
    return in_array($method, REMOVED_IN_MODERN, strict: true);
}

/**
 * Read the `_meta` map of a request body.
 *
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function request_meta(array $body): array
{
    $params = $body['params'] ?? null;
    if (!is_array($params)) {
        return [];
    }

    $meta = $params['_meta'] ?? null;

    return is_array($meta) ? $meta : [];
}

/**
 * Read the client capabilities a modern request declares.
 *
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function client_capabilities(array $body): array
{
    $capabilities = request_meta($body)[META_CLIENT_CAPABILITIES] ?? null;

    return is_array($capabilities) ? $capabilities : [];
}

/**
 * Whether a modern request declared its client capabilities at all.
 *
 * An empty object is a valid declaration ("I support nothing optional"); a
 * missing key is not a declaration and is rejected, because the server would
 * otherwise have to guess whether the client can handle an extension.
 *
 * @param array<string, mixed> $body
 */
function declares_client_capabilities(array $body): bool
{
    return is_array(request_meta($body)[META_CLIENT_CAPABILITIES] ?? null);
}

/**
 * Read the log level a modern request opted in to.
 *
 * The modern revision forbids emitting `notifications/message` for a request
 * that did not supply this field, so its absence is meaningful.
 *
 * @param array<string, mixed> $body
 */
function requested_log_level(array $body): string
{
    $level = request_meta($body)[META_LOG_LEVEL] ?? null;

    return is_string($level) ? $level : '';
}

/**
 * Copy any OpenTelemetry trace context a client supplied.
 *
 * Propagated verbatim and never synthesized: an invented trace id would corrupt
 * the caller's trace rather than extend it.
 *
 * @param array<string, mixed> $body
 * @return array<string, string>
 */
function trace_context(array $body): array
{
    $meta = request_meta($body);
    $context = [];

    foreach (META_TRACE_KEYS as $key) {
        $value = $meta[$key] ?? null;
        if (is_string($value) && $value !== '') {
            $context[$key] = $value;
        }
    }

    return $context;
}
