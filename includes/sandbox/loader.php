<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * Sandbox Loader
 * Loads AI-written PHP plugins from the sandbox directory. Includes automatic crash recovery in dev mode.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * List the sandbox PHP files that should be loaded into the request.
 *
 * The web-server guards written by wppilot_harden_sandbox_dir() live in the
 * same directory; index.php is a silence stub and must not be loaded as if it
 * were an agent-authored plugin.
 *
 * @param string $sandbox_dir Sandbox directory path, with trailing slash.
 * @return list<string> Absolute paths, or an empty list when there is nothing to load.
 */
function wppilot_sandbox_loadable_files(string $sandbox_dir): array
{
    $files = glob($sandbox_dir . '*.php');
    if ($files === false) {
        return [];
    }

    $guards = wppilot_sandbox_guard_files();

    return array_values(array_filter(
        $files,
        static fn(string $file): bool => !array_key_exists(basename($file), $guards),
    ));
}

/**
 * Shutdown handler that creates a .crashed marker when a fatal error occurs while a sandbox file is loading.
 *
 * @param string      $crashed_file        Path to the .crashed marker file.
 * @param string|null $current_sandbox_file The sandbox file currently being loaded, or null if loading is complete.
 */
function wppilot_sandbox_crash_handler(string $crashed_file, ?string $current_sandbox_file): void
{
    if ($current_sandbox_file === null) {
        return;
    }

    $error = error_get_last();
    if ($error === null) {
        return;
    }

    // Only react to fatal error types that kill execution.
    if (!($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        return;
    }

    $error['sandbox_file'] = $current_sandbox_file;
    file_put_contents($crashed_file, (string) wp_json_encode($error), LOCK_EX);
}

(static function () {
    $sandbox_dir = WPPILOT_SANDBOX_DIR;

    // Ensure sandbox directory exists.
    if (!is_dir($sandbox_dir)) {
        return;
    }

    $loading_file = $sandbox_dir . '.loading';
    $crashed_file = $sandbox_dir . '.crashed';
    $abilities_enabled = wppilot_is_enabled();

    // When abilities are disabled, load sandbox files without crash-recovery overhead.
    if (!$abilities_enabled) {
        $files = wppilot_sandbox_loadable_files($sandbox_dir);
        if ($files) {
            foreach ($files as $file) {
                require_once $file;
            }
        }
        return;
    }

    // Clean up legacy .loading marker if present.
    if (file_exists($loading_file)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.file_system_operations_readfile,WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem is not usable here: it takes credentials from an interactive admin form, which a REST/MCP request has no way to show. This loads GoBuildWP's own sandbox guard files from the directory it created.
        unlink($loading_file);
    }

    // Crash recovery: .crashed exists → stay in safe mode.
    $is_safe_mode = file_exists($crashed_file);

    // Manual safe mode via URL parameter.
    if (!$is_safe_mode && ($_GET['wppilot_safe_mode'] ?? null) === '1') {
        $is_safe_mode = true;
    }

    // Dashboard warnings.
    add_action('admin_notices', static function () use ($crashed_file) {
        if (!wppilot_current_user_can_manage()) {
            return;
        }
        if (file_exists($crashed_file)) {
            wp_admin_notice(
                sprintf(
                    '<strong>%s</strong> %s',
                    esc_html__('GoBuildWP Sandbox: Safe mode is active.', domain: 'wppilot'),
                    esc_html__(
                        'A sandbox plugin caused a fatal error. All sandbox plugins are disabled. Fix or delete the broken plugin, then delete wp-content/wppilot-sandbox/.crashed to resume.',
                        domain: 'wppilot',
                    ),
                ),
                [
                    'type' => 'error',
                    'dismissible' => false,
                ],
            );
        }
    });

    // Safe mode: skip loading all sandbox files.
    if ($is_safe_mode) {
        return;
    }

    // Normal load with shutdown-based crash detection.
    $files = wppilot_sandbox_loadable_files($sandbox_dir);
    if (!$files) {
        return;
    }

    // Tracks which sandbox file is currently being loaded. The shutdown handler uses this to
    // detect crashes even when the fatal error is thrown from a core or third-party file in the
    // call chain (e.g. sandbox file → get_header() → wp_head() → fatal in wp-includes/).
    // Set to null after the loop completes, which makes the handler a no-op.
    $current_sandbox_file = null;

    register_shutdown_function(static function () use ($crashed_file, &$current_sandbox_file) {
        wppilot_sandbox_crash_handler($crashed_file, $current_sandbox_file);
    });

    foreach ($files as $file) {
        $current_sandbox_file = $file;
        require_once $file;
    }
    $current_sandbox_file = null;
})();
