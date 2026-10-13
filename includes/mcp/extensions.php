<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Mcp;

/**
 * The hook-in points the modern transport and server/discover call for optional extensions:
 * Tasks (tasks.php) and MCP Apps (apps.php).
 *
 * Kept out of transport.php so that file changes by one line per hook, and so each extension can
 * be left unloaded without the transport noticing: every call here checks the module is present.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Answer a request one of the extensions owns, or return null to let the transport route it.
 *
 * Runs before the skill resources, because a resources/list must include the Apps resources and
 * a `ui://` read must not be answered "unknown skill".
 *
 * @param array<string, mixed> $params
 * @return array{status: int, body: array<string, mixed>}|null
 */
function dispatch_extensions(string $method, array $params, mixed $id): ?array
{
    if (function_exists('WPPilot\\Mcp\\Tasks\\dispatch')) {
        $outcome = Tasks\dispatch($method, $params, $id);
        if ($outcome !== null) {
            return $outcome;
        }
    }
    if (function_exists('WPPilot\\Mcp\\Apps\\dispatch')) {
        return Apps\dispatch($method, $params, $id);
    }

    return null;
}

/**
 * Add what the extensions contribute to a tool definition.
 *
 * `_meta` is passed through from the ability's `meta.mcp._meta`, which is what the bundled adapter
 * does on the legacy path; that is how wppilot/preview-ability links its Apps card. `execution`
 * carries the tool's task support.
 *
 * @param array<string, mixed> $tool
 * @param array<string, mixed> $meta The ability's meta.
 * @return array<string, mixed>
 */
function decorate_tool(array $tool, array $meta): array
{
    $mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];
    if (is_array($mcp['_meta'] ?? null) && $mcp['_meta'] !== []) {
        $tool['_meta'] = $mcp['_meta'];
    }
    if (function_exists('WPPilot\\Mcp\\Tasks\\tool_execution')) {
        $execution = Tasks\tool_execution($meta);
        if ($execution !== null) {
            $tool['execution'] = $execution;
        }
    }

    return $tool;
}

/**
 * Resources the extensions serve, for the `resources` capability.
 */
function extension_resource_count(): int
{
    return function_exists('WPPilot\\Mcp\\Apps\\resource_count') ? Apps\resource_count() : 0;
}

/**
 * Declare the extensions' capabilities.
 *
 * @param array<string, mixed> $capabilities
 * @return array<string, mixed>
 */
function with_extension_capabilities(array $capabilities): array
{
    if (function_exists('WPPilot\\Mcp\\Tasks\\with_capability')) {
        $capabilities = Tasks\with_capability($capabilities);
    }

    return $capabilities;
}
