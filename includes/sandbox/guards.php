<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The PHP sandbox boundary.
 *
 * Two jobs: keep agent-authored PHP confined to the sandbox directory,
 * and keep that directory unreachable from the web. The guard files are
 * written on activation and cannot be modified through the abilities.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Get the sandbox directory path for AI-written PHP plugins.
 *
 * The sandbox is an operational boundary for generated PHP: it gives GoBuildWP
 * one place to load AI-written PHP files from, disable them, and recover from
 * crashes. It is not a security isolation boundary for all filesystem writes.
 * Authenticated GoBuildWP administrators may intentionally read, write, edit,
 * upload, and delete non-PHP files elsewhere under the configured filesystem
 * base directory.
 *
 * @param bool $ensure_exists Whether to create the directory if it doesn't exist.
 * @return string Absolute path to the sandbox directory (with trailing slash).
 */
function wppilot_get_sandbox_dir($ensure_exists = false)
{
    if ($ensure_exists && !is_dir(WPPILOT_SANDBOX_DIR)) {
        wp_mkdir_p(WPPILOT_SANDBOX_DIR);
        wppilot_harden_sandbox_dir();
    }

    return WPPILOT_SANDBOX_DIR;
}

/**
 * Web-server guard files written into the sandbox directory.
 *
 * The sandbox holds agent-authored PHP. Without these, the directory sits under
 * wp-content and every file in it is reachable — and executable — over HTTP,
 * which would run agent PHP outside the WordPress bootstrap and therefore
 * outside the policy in includes/safety.php entirely.
 *
 * @return array<string, string> Basename mapped to file contents.
 */
function wppilot_sandbox_guard_files(): array
{
    return [
        'index.php' => "<?php\n// Silence is golden.\n",
        '.htaccess' =>
            "# GoBuildWP sandbox: agent-authored PHP. Never serve this directory over HTTP.\n"
                . "<IfModule mod_authz_core.c>\n"
                . "    Require all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "    Order allow,deny\n"
                . "    Deny from all\n"
                . "</IfModule>\n",
        'web.config' =>
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<configuration>\n"
                . "    <system.webServer>\n"
                . "        <authorization>\n"
                . "            <deny users=\"*\" />\n"
                . "        </authorization>\n"
                . "    </system.webServer>\n"
                . "</configuration>\n",
    ];
}

/**
 * Write the sandbox web-server guards, creating only what is missing.
 *
 * Idempotent, so it is safe to call from activation, from lazy directory
 * creation, and from the repair path in the troubleshooter. An existing guard
 * is never overwritten: a site owner may have tightened it further.
 *
 * Note that .htaccess is ignored by nginx and Caddy. The troubleshooter probes
 * the directory over HTTP to catch that case; see includes/troubleshoot/checks.php.
 */
function wppilot_harden_sandbox_dir(): void
{
    if (!is_dir(WPPILOT_SANDBOX_DIR)) {
        return;
    }

    foreach (wppilot_sandbox_guard_files() as $basename => $contents) {
        $path = WPPILOT_SANDBOX_DIR . $basename;
        if (file_exists($path)) {
            continue;
        }
        file_put_contents($path, $contents, LOCK_EX);
    }
}

/**
 * Whether a resolved path is one of the sandbox web-server guards.
 *
 * The file abilities may write and delete freely inside the sandbox, which
 * would otherwise let an agent remove its own execution guard.
 */
function wppilot_is_sandbox_guard_file(string $resolved): bool
{
    $real_sandbox = realpath(WPPILOT_SANDBOX_DIR);
    if ($real_sandbox === false) {
        return false;
    }

    $parent = realpath(dirname($resolved));
    if ($parent === false || rtrim($parent, characters: '/\\') !== rtrim($real_sandbox, characters: '/\\')) {
        return false;
    }

    return array_key_exists(basename($resolved), wppilot_sandbox_guard_files());
}

/**
 * Validate that a resolved path is inside the sandbox directory.
 *
 * @param string $resolved The resolved absolute path to check.
 * @return true|WP_Error True if inside the sandbox, WP_Error otherwise.
 */
function wppilot_validate_sandbox_path($resolved)
{
    $sandbox_dir = wppilot_get_sandbox_dir();
    $real_sandbox = realpath($sandbox_dir);
    if ($real_sandbox === false) {
        return new WP_Error('sandbox_not_found', __('The sandbox directory does not exist.', domain: 'wppilot'));
    }

    $real_resolved = realpath($resolved);
    if ($real_resolved === false) {
        $real_resolved = $resolved;
    }

    if (!wppilot_path_is_child_of_directory($real_resolved, $real_sandbox)) {
        return new WP_Error('outside_sandbox', sprintf(
            /* translators: %s: sandbox directory path */
            __('Only files inside the sandbox (%s) can be modified.', domain: 'wppilot'),
            $sandbox_dir,
        ));
    }

    return true;
}

/**
 * Check that a resolved PHP-execution path is inside the sandbox directory.
 *
 * This is deliberately scoped to files that GoBuildWP may execute as PHP or
 * files that can alter PHP execution. Non-PHP filesystem access outside the
 * sandbox is expected behavior, not a sandbox bypass.
 *
 * @param string $resolved Absolute resolved path to the PHP file.
 * @return bool|WP_Error True if valid, WP_Error if outside sandbox.
 */
function wppilot_check_php_sandbox(string $resolved): bool|WP_Error
{
    $sandbox_dir = wppilot_get_sandbox_dir(ensure_exists: false);
    $real_sandbox = realpath($sandbox_dir);
    $parent_dir = realpath(dirname($resolved));

    // If sandbox doesn't exist yet, compare normalized paths.
    if ($real_sandbox === false) {
        $real_sandbox = rtrim(string: $sandbox_dir, characters: '/\\');
    }
    if ($parent_dir === false) {
        $parent_dir = dirname($resolved);
    }

    if (!wppilot_path_is_within_directory($parent_dir, $real_sandbox)) {
        return new WP_Error('php_sandbox_required', sprintf(
            'PHP files and PHP execution control files can only be written to the sandbox directory: %s. Use a path like "wp-content/wppilot-sandbox/my-feature.php".',
            $sandbox_dir,
        ));
    }

    // Writing inside the sandbox is allowed, but not over the guards that keep
    // the sandbox unreachable from the web.
    if (wppilot_is_sandbox_guard_file($resolved)) {
        return new WP_Error('sandbox_guard_file', sprintf(
            'Cannot modify %s: it keeps the sandbox directory from being served over HTTP. Write your PHP to a different filename in %s.',
            basename($resolved),
            $sandbox_dir,
        ));
    }

    return true;
}

/**
 * Check whether a path can directly affect PHP execution and must stay in the sandbox.
 *
 * Do not broaden this to every writable file path unless the product model
 * changes. The sandbox is not intended to isolate all filesystem operations.
 */
function wppilot_path_requires_php_sandbox(string $resolved): bool
{
    // Windows drops trailing dots and spaces from a filename, so "x.php." is saved as x.php.
    $filename = rtrim(strtolower(basename($resolved)), characters: '. ');
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (wppilot_is_php_extension($extension)) {
        return true;
    }

    return in_array(
        $filename,
        [
            '.htaccess',
            '.php.ini',
            '.user.ini',
            'php.ini',
            'web.config',
        ],
        strict: true,
    );
}

/**
 * Whether a file extension is one a web server may hand to PHP.
 *
 * Not only `php`: stock Debian and Ubuntu PHP packages execute .phtml and .phar, and hosts
 * commonly still map .php3-.php8, .pht and .phps. Treating only .php as PHP let agent-written
 * code land in uploads/ as .phtml, where a request could run it outside the WordPress bootstrap
 * and therefore outside the safety policy entirely. Compared case-insensitively, because
 * Windows and macOS filesystems and some handler configurations are.
 */
function wppilot_is_php_extension(string $extension): bool
{
    return preg_match('/^(?:php\d*|phtml|pht|phar|phps)$/i', $extension) === 1;
}

/**
 * Enforce the sandbox boundary for files that can affect PHP execution.
 *
 * Returning true for ordinary non-PHP files is intentional.
 */
function wppilot_check_php_execution_sandbox(string $resolved): bool|WP_Error
{
    if (!wppilot_path_requires_php_sandbox($resolved)) {
        return true;
    }

    return wppilot_check_php_sandbox($resolved);
}
