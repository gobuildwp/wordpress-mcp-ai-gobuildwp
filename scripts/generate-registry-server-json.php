<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

use WPPilot\Mcp\ServerCard;

/**
 * Generate the MCP Registry entry (server.json) for GoBuildWP.
 *
 * The Registry lists a server once, by a reverse-DNS name, with the remotes a client connects to.
 * GoBuildWP is installed on each customer's own site, so there is no single URL: the remotes use the
 * Registry's URL-template variables, and the client asks for the site when it installs the entry.
 * Name, title, description, links and protocol facts come from the same constants the site's
 * public server card uses (includes/mcp/server-card.php), and the version from the plugin header,
 * so the two documents cannot describe different servers.
 *
 * Format: https://static.modelcontextprotocol.io/schemas/2025-12-11/server.schema.json, the schema
 * the Registry's generic server.json reference documented in September 2026.
 *
 * Publishing is not automated here. `com.gobuildwp/gobuildwp` is a reverse-DNS name, and the Registry
 * only accepts it from a publisher that has proved control of gobuildwp.com (DNS or HTTP verification
 * with `mcp-publisher login dns|http`), then `mcp-publisher publish`.
 *
 * Usage:
 *   php scripts/generate-registry-server-json.php           print to stdout
 *   php scripts/generate-registry-server-json.php --write   write server.json at the repository root
 *   php scripts/generate-registry-server-json.php --check   exit 1 when server.json is stale
 *                                                           (run after every version bump)
 */

$root = dirname(__DIR__);

// server-card.php refuses to load outside WordPress; it declares only constants and functions, and
// only the constants are read here.
if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}
require_once $root . '/includes/mcp/protocol.php';
require_once $root . '/includes/mcp/server-card.php';

$header = (string) file_get_contents($root . '/wppilot.php');
if (preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $header, $m) !== 1) {
    fwrite(STDERR, "Could not read the Version header from wppilot.php\n");
    exit(1);
}
$version = $m[1];

$site = [
    'description' => 'Your WordPress site address without https://, including any subdirectory WordPress lives in (e.g. example.com or example.com/blog).',
    'isRequired' => true,
];

$document = [
    '$schema' => 'https://static.modelcontextprotocol.io/schemas/2025-12-11/server.schema.json',
    'name' => ServerCard\REGISTRY_NAME,
    'title' => 'GoBuildWP',
    'description' => ServerCard\DESCRIPTION,
    'version' => $version,
    'websiteUrl' => ServerCard\WEBSITE_URL,
    'repository' => ['url' => ServerCard\REPOSITORY_URL, 'source' => 'github'],
    'remotes' => [
        [
            'type' => 'streamable-http',
            'url' => 'https://{site}/wp-json/mcp/wppilot',
            'variables' => ['site' => $site],
            'headers' => [[
                'name' => 'Authorization',
                'description' => 'Basic credentials with a WordPress Application Password, or a GoBuildWP access token as a Bearer token. Create either under GoBuildWP > Connect.',
                'isRequired' => true,
                'isSecret' => true,
            ]],
        ],
        [
            // OAuth 2.1: the client discovers sign-in from the 401 challenge, so no header is asked for.
            'type' => 'streamable-http',
            'url' => 'https://{site}/wp-json/mcp/wppilot-oauth',
            'variables' => ['site' => $site],
        ],
    ],
    '_meta' => [
        'io.modelcontextprotocol.registry/publisher-provided' => [
            'serverCard' => 'https://{site}/.well-known/mcp/server-card.json',
            'protocolVersions' => [\WPPilot\Mcp\VERSION_MODERN, \WPPilot\Mcp\VERSION_LEGACY],
            'documentation' => ServerCard\DOCS_URL,
        ],
    ],
];

if (strlen(ServerCard\DESCRIPTION) > 100) {
    fwrite(STDERR, "ServerCard\\DESCRIPTION exceeds the Registry's 100-character limit.\n");
    exit(1);
}

$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$target = $root . '/server.json';
$mode = $argv[1] ?? '';

if ($mode === '--write') {
    file_put_contents($target, $json);
    echo "Wrote server.json for GoBuildWP {$version}\n";
    exit(0);
}

if ($mode === '--check') {
    $current = is_file($target) ? str_replace("\r\n", "\n", (string) file_get_contents($target)) : '';
    if ($current !== $json) {
        fwrite(STDERR, "server.json is stale. Run: php scripts/generate-registry-server-json.php --write\n");
        exit(1);
    }
    echo "server.json is current ({$version}).\n";
    exit(0);
}

echo $json;
