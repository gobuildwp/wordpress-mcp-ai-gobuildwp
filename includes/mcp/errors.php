<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp;

/**
 * Centralized JSON-RPC error construction for both protocol eras.
 *
 * The `2026-07-28` revision partitions the JSON-RPC server-error range:
 * `-32000`..`-32019` stays implementation-defined, and `-32020`..`-32099` is
 * reserved for the specification. The three codes below were renumbered into
 * that reserved block by this revision, so they are defined once here rather
 * than written literally at each call site.
 *
 * Every builder returns both the JSON-RPC payload and the HTTP status the
 * transport must pair it with, because the spec fixes those pairings and
 * getting one wrong breaks a client's era detection: a modern client inspects
 * the body of a 400 to decide whether to fall back to `initialize`.
 *
 * @link https://modelcontextprotocol.io/specification/2026-07-28/basic/index#error-codes
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Headers do not match the body, or a required header is missing/malformed. */
const ERROR_HEADER_MISMATCH = -32020;

/** The request needs a client capability the client did not declare. */
const ERROR_MISSING_CLIENT_CAPABILITY = -32021;

/** The requested protocol version is not implemented. */
const ERROR_UNSUPPORTED_PROTOCOL_VERSION = -32022;

/** Standard JSON-RPC: the method does not exist. */
const ERROR_METHOD_NOT_FOUND = -32601;

/** Standard JSON-RPC: invalid params. The modern revision also uses this for a missing resource. */
const ERROR_INVALID_PARAMS = -32602;

/** Standard JSON-RPC: the body could not be parsed. */
const ERROR_PARSE = -32700;

/** Standard JSON-RPC: the body is not a valid request. */
const ERROR_INVALID_REQUEST = -32600;

/** Standard JSON-RPC: an unexpected server-side failure. */
const ERROR_INTERNAL = -32603;

/**
 * Build a JSON-RPC error response paired with its HTTP status.
 *
 * @param array<string, mixed>|null $data
 * @return array{status: int, body: array<string, mixed>}
 */
function error_response(
    int $code,
    string $message,
    int $status,
    mixed $id = null,
    ?array $data = null,
): array {
    $error = ['code' => $code, 'message' => $message];
    if ($data !== null) {
        $error['data'] = $data;
    }

    return [
        'status' => $status,
        'body' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error],
    ];
}

/**
 * The requested protocol version is not one this server implements.
 *
 * The `supported` list is what lets a client recover: it picks a mutually
 * supported version and retries, instead of falling back to `initialize`.
 *
 * @return array{status: int, body: array<string, mixed>}
 */
function unsupported_protocol_version_error(string $requested, mixed $id = null): array
{
    return error_response(
        ERROR_UNSUPPORTED_PROTOCOL_VERSION,
        'Unsupported protocol version',
        400,
        $id,
        ['supported' => SUPPORTED_VERSIONS, 'requested' => $requested],
    );
}

/**
 * A mirrored header disagrees with the body, or a required header is absent.
 *
 * The message names the offending header and both values, because the client's
 * documented recovery is to re-read the tool definition and retry with correct
 * headers — which it cannot do without knowing which header was wrong.
 *
 * @return array{status: int, body: array<string, mixed>}
 */
function header_mismatch_error(string $message, mixed $id = null): array
{
    return error_response(ERROR_HEADER_MISMATCH, 'Header mismatch: ' . $message, 400, $id);
}

/**
 * The request requires a client capability the client did not declare.
 *
 * @return array{status: int, body: array<string, mixed>}
 */
function missing_client_capability_error(string $capability, mixed $id = null): array
{
    return error_response(
        ERROR_MISSING_CLIENT_CAPABILITY,
        sprintf('The request requires the "%s" client capability, which was not declared.', $capability),
        400,
        $id,
        ['capability' => $capability],
    );
}

/**
 * The method is not implemented under the requested revision.
 *
 * Answered with HTTP 404 per the modern transport binding, which distinguishes
 * an unknown MCP method from a 404 produced by a host that does not serve an
 * MCP endpoint at this path at all.
 *
 * @return array{status: int, body: array<string, mixed>}
 */
function method_not_found_error(string $method, mixed $id = null, string $reason = ''): array
{
    $message = sprintf('Method not found: %s', $method);
    if ($reason !== '') {
        $message .= '. ' . $reason;
    }

    return error_response(ERROR_METHOD_NOT_FOUND, $message, 404, $id);
}

/**
 * A method the modern revision removed.
 *
 * Stating the replacement in the message keeps a half-migrated client from
 * concluding the server is broken.
 *
 * @return array{status: int, body: array<string, mixed>}
 */
function removed_method_error(string $method, mixed $id = null): array
{
    $replacements = [
        'ping' => 'It was removed in 2026-07-28; there is no replacement.',
        'logging/setLevel' => sprintf(
            'It was removed in 2026-07-28; set "%s" in each request\'s _meta instead.',
            META_LOG_LEVEL,
        ),
        'initialize' => sprintf(
            'The modern revision has no handshake; send "%s" in each request\'s _meta instead.',
            META_PROTOCOL_VERSION,
        ),
        'notifications/initialized' => 'The modern revision has no handshake, so there is nothing to acknowledge.',
        'notifications/roots/list_changed' => 'It was removed in 2026-07-28; there is no replacement.',
        'resources/subscribe' => 'It was replaced in 2026-07-28 by subscriptions/listen.',
        'resources/unsubscribe' => 'It was replaced in 2026-07-28 by closing the subscriptions/listen stream.',
    ];

    return method_not_found_error($method, $id, $replacements[$method] ?? '');
}

/**
 * A resource, tool, or prompt the request named does not exist.
 *
 * The modern revision moved resource-not-found from `-32002` to `-32602` to
 * align with JSON-RPC, so the code is version-dependent and the caller passes
 * the era in rather than this helper assuming one.
 *
 * @return array{status: int, body: array<string, mixed>}
 */
function not_found_error(string $message, mixed $id = null, bool $modern = true): array
{
    return error_response($modern ? ERROR_INVALID_PARAMS : -32002, $message, 200, $id);
}
