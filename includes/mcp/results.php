<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp;

/**
 * Modern result decoration: `resultType`, and the cache hints on list reads.
 *
 * Legacy results are returned exactly as the adapter built them. Adding modern
 * fields to a legacy response risks breaking strictly-validating clients, and
 * the legacy revision has no `resultType` to add.
 *
 * @link https://modelcontextprotocol.io/specification/2026-07-28/server/utilities/caching
 */

if (!defined('ABSPATH')) {
    exit();
}

/** An ordinary, finished result. */
const RESULT_COMPLETE = 'complete';

/**
 * An interim result awaiting client input.
 *
 * Returned only when the site's confirmation mode is `human` and a client that
 * declared elicitation calls a destructive or critical tool; see confirmation.php.
 */
const RESULT_INPUT_REQUIRED = 'input_required';

/**
 * Methods whose results carry cache hints under the modern revision.
 *
 * @var list<string>
 */
const CACHEABLE_METHODS = [
    'tools/list',
    'prompts/list',
    'resources/list',
    'resources/read',
    'resources/templates/list',
    // SEP-2640: in 2026-07-28 and later, skills/list carries the same list-caching attributes.
    'skills/list',
    'server/discover',
];

/**
 * Freshness hint for list and read results, in milliseconds.
 *
 * Deliberately short. A GoBuildWP tool list changes whenever an administrator
 * toggles an ability, switches the safety profile, or activates a plugin that
 * registers new ones — none of which produce a notification a cache could
 * observe. A minute bounds how long a client can act on a stale surface.
 */
const TTL_LIST_MS = 60000;

/**
 * Freshness hint for `server/discover`, in milliseconds.
 *
 * Longer than a list because it carries identity and version support, which
 * change only across plugin updates.
 */
const TTL_DISCOVER_MS = 300000;

/**
 * Cache visibility for every GoBuildWP result.
 *
 * Always private, and not a placeholder. `public` is only permissible when a
 * result is provably identical for every requester and carries nothing
 * sensitive. GoBuildWP's surface fails that test in three independent ways: the
 * ability list is filtered by the connected account's WordPress capabilities,
 * it is further filtered by the active safety profile, and individual
 * abilities can be switched off per site. A shared intermediary caching one
 * user's tool list and serving it to another would leak the shape of a more
 * privileged account's access.
 */
const CACHE_SCOPE = 'private';

/**
 * Whether a method's result carries `ttlMs` and `cacheScope`.
 */
function is_cacheable_method(string $method): bool
{
    return in_array($method, CACHEABLE_METHODS, strict: true);
}

/**
 * Decorate a result for a modern client.
 *
 * `resultType` is required on every modern result. Cache hints are added only
 * to the methods the spec lists, so an ordinary `tools/call` result stays
 * exactly what the ability returned plus its type.
 *
 * A `_meta` already present on the result is preserved and merged into, since
 * an ability may have attached its own keys.
 *
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function decorate_modern_result(array $result, string $method, string $server_name, string $server_version): array
{
    $result['resultType'] = RESULT_COMPLETE;

    if (is_cacheable_method($method)) {
        $result['ttlMs'] = $method === 'server/discover' ? TTL_DISCOVER_MS : TTL_LIST_MS;
        $result['cacheScope'] = CACHE_SCOPE;
    }

    $meta = is_array($result['_meta'] ?? null) ? $result['_meta'] : [];
    $meta[META_SERVER_INFO] = ['name' => $server_name, 'version' => $server_version];
    $result['_meta'] = $meta;

    return $result;
}

/**
 * Wrap a decorated result in its JSON-RPC envelope.
 *
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function modern_response(array $result, mixed $id, string $method, string $server_name, string $server_version): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => decorate_modern_result($result, $method, $server_name, $server_version),
    ];
}

/**
 * Return tools in a deterministic order.
 *
 * The modern revision asks for a stable order so clients can cache the list and
 * so an LLM prompt built from it stays byte-identical between calls, which is
 * what makes provider-side prompt caching hit. Sorted by name because the
 * registry's iteration order follows plugin load order and shifts whenever a
 * site activates or deactivates anything.
 *
 * @param list<array<string, mixed>> $tools
 * @return list<array<string, mixed>>
 */
function sort_tools_deterministically(array $tools): array
{
    usort($tools, static fn(array $a, array $b): int => strcmp(
        (string) ($a['name'] ?? ''),
        (string) ($b['name'] ?? ''),
    ));

    return $tools;
}
