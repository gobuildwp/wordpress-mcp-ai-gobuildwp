<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Which copy of the MCP Adapter this request is actually running.
 *
 * GoBuildWP bundles the adapter, and so does every other plugin that speaks MCP -
 * Elementor 4.3 ships it inside `elementor/elementor-mcp-composer`. The classes
 * are global and unprefixed (`WP\MCP\…`), so only one copy can ever be loaded,
 * and which one wins is decided by the Jetpack autoloader's version arbitration
 * rather than by load order: the highest version tag in the merged classmap is
 * served to everybody. A plugin bundling a newer adapter therefore replaces
 * GoBuildWP's copy site-wide, silently.
 *
 * `class_exists()` cannot see this. It answers true whoever provided the class,
 * which is exactly the case where GoBuildWP most needs to behave differently: the
 * adapter's default server belongs to whoever bundled it, so renaming that
 * server and aliasing the adapter's own default slug - both correct when the
 * copy is ours - would contest routes another plugin legitimately owns.
 *
 * This file answers "whose adapter is this" once, from the class's own file
 * path, so the rest of the plugin can branch on the answer instead of guessing.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Where the loaded MCP Adapter came from.
 *
 * Resolved once per request. `ours` is decided by path rather than by version,
 * because a foreign copy at the same version is still foreign - it owns its own
 * default server either way.
 *
 * @return array{loaded: bool, ours: bool, file: string, version: string|null, owner: string|null}
 */
function wppilot_mcp_adapter_origin(): array
{
    /** @var array{loaded: bool, ours: bool, file: string, version: string|null, owner: string|null}|null $origin */
    static $origin = null;

    if ($origin !== null) {
        return $origin;
    }

    $unknown = ['loaded' => false, 'ours' => false, 'file' => '', 'version' => null, 'owner' => null];

    if (!class_exists(WPPILOT_MCP_ADAPTER_CLASS, autoload: false)) {
        $origin = $unknown;

        return $origin;
    }

    try {
        $reflection = new \ReflectionClass(WPPILOT_MCP_ADAPTER_CLASS);
        $file = (string) $reflection->getFileName();
    } catch (\ReflectionException) {
        $origin = $unknown;

        return $origin;
    }

    if ($file === '') {
        $origin = $unknown;

        return $origin;
    }

    $file = wp_normalize_path($file);
    $ours = str_starts_with($file, wppilot_mcp_vendor_dir());

    $origin = [
        'loaded' => true,
        'ours' => $ours,
        'file' => $file,
        'version' => wppilot_mcp_adapter_version($file),
        'owner' => $ours ? 'wppilot' : wppilot_mcp_adapter_owner_plugin($file),
    ];

    return $origin;
}

/**
 * This plugin's own vendor directory, resolved.
 *
 * Built from the plugin file rather than from `__DIR__ . '/../../vendor/'`,
 * because `wp_normalize_path()` converts separators and does not resolve `..` -
 * so the literal path never matches a resolved one and the check quietly
 * answers "not ours" on every site, including the ones where it is.
 */
function wppilot_mcp_vendor_dir(): string
{
    $base = defined('WPPILOT_PLUGIN_FILE')
        ? dirname((string) WPPILOT_PLUGIN_FILE)
        : dirname(__DIR__, 2);

    $resolved = realpath($base);

    return wp_normalize_path(($resolved === false ? $base : $resolved) . '/vendor/');
}

/**
 * The plugin directory name a file belongs to, or null when it is not in one.
 *
 * Reported so a notice can name the plugin rather than print a path, and so a
 * support report says "Elementor" instead of "some other copy".
 */
function wppilot_mcp_adapter_owner_plugin(string $file): ?string
{
    $plugins_dir = '';
    if (defined('WP_PLUGIN_DIR')) {
        $plugins_dir = wp_normalize_path((string) WP_PLUGIN_DIR);
    } elseif (defined('WP_CONTENT_DIR')) {
        $plugins_dir = wp_normalize_path((string) WP_CONTENT_DIR) . '/plugins';
    }

    if ($plugins_dir !== '' && str_starts_with($file, $plugins_dir . '/')) {
        $slug = strtok(substr($file, strlen($plugins_dir) + 1), '/');

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    // A site can move wp-content, and the constants are absent outside a WordPress
    // request entirely. Fall back to the path's own shape rather than reporting
    // nothing, since the whole value of this field is naming the other plugin.
    if (preg_match('#/(?:mu-)?plugins/([^/]+)/#', $file, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

/**
 * The adapter's version, read from the owner's own Jetpack classmap.
 *
 * The adapter carries no version constant, and the arbitration that chose this
 * copy read the classmap - so the classmap is the honest source. A copy loaded
 * some other way has none, and reports null rather than a guess.
 */
function wppilot_mcp_adapter_version(string $file): ?string
{
    $marker = '/vendor/wordpress/mcp-adapter/';
    $position = strpos($file, $marker);
    if ($position === false) {
        return null;
    }

    $classmap = substr($file, 0, $position) . '/vendor/composer/jetpack_autoload_classmap.php';
    if (!is_readable($classmap)) {
        return null;
    }

    /** @var mixed $map */
    $map = include $classmap;
    if (!is_array($map)) {
        return null;
    }

    /** @var mixed $entry */
    $entry = $map[WPPILOT_MCP_ADAPTER_CLASS] ?? null;
    if (!is_array($entry) || !is_string($entry['version'] ?? null)) {
        return null;
    }

    return $entry['version'];
}

/**
 * Whether GoBuildWP may take over the adapter's default server.
 *
 * True only when the loaded adapter is the copy GoBuildWP shipped. On a site where
 * another plugin's copy won, its default server is that plugin's, and GoBuildWP
 * registers its own server beside it instead of renaming theirs.
 */
function wppilot_owns_mcp_adapter(): bool
{
    $origin = wppilot_mcp_adapter_origin();

    return $origin['loaded'] && $origin['ours'];
}

/**
 * A one-line description of the adapter in use, for status output and notices.
 */
function wppilot_mcp_adapter_origin_summary(): string
{
    $origin = wppilot_mcp_adapter_origin();

    if (!$origin['loaded']) {
        return __('The MCP Adapter is not loaded.', domain: 'wppilot');
    }

    if ($origin['ours']) {
        return sprintf(
            /* translators: %s: the MCP Adapter version */
            __('Serving MCP with the adapter bundled in GoBuildWP (%s).', domain: 'wppilot'),
            $origin['version'] ?? __('version unknown', domain: 'wppilot'),
        );
    }

    return sprintf(
        /* translators: 1: plugin directory name, 2: the MCP Adapter version */
        __(
            'Serving MCP with the adapter bundled in another plugin (%1$s, %2$s). Only one copy of the adapter can load, and this one won. GoBuildWP keeps its own endpoint at /wp-json/mcp/wppilot; the adapter\'s default server belongs to that plugin.',
            domain: 'wppilot',
        ),
        $origin['owner'] ?? __('unknown plugin', domain: 'wppilot'),
        $origin['version'] ?? __('version unknown', domain: 'wppilot'),
    );
}
