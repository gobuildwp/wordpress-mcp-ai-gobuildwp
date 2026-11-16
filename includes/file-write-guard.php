<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Guarded writes for files PHP may execute.
 *
 * write-file, edit-file and enable-file used to put bytes straight onto the live path. A PHP
 * file with a syntax error, or one that fatals when the sandbox loader includes it, took the
 * whole site down on the next request; the loader's crash marker only recovered the request
 * after that, and only for sandbox PHP, not for an .htaccess or .user.ini that broke the server.
 * A half-written file (a full disk, a killed PHP worker) was served half-written.
 *
 * So a write to a file that affects PHP execution now:
 *
 * 1. is syntax-checked with the tokenizer (`token_get_all(..., TOKEN_PARSE)`), which parses
 *    without running anything. `php -l` is not used: it needs a shell and a known PHP binary,
 *    neither of which a hosted site can be trusted to have;
 * 2. is written to a temporary file in the same directory and renamed over the target, so a
 *    reader sees the old file or the new one, never a partial one. The encrypted restore point
 *    the gate takes before every file ability (includes/restore-points.php) is the durable
 *    backup; this file keeps the previous bytes in memory only for the automatic restore;
 * 3. invalidates OPcache for that one file. Never opcache_reset(): on LiteSpeed it stays off
 *    until the request ends and long requests elsewhere on the site fail;
 * 4. is checked with a logged-out loopback request to the home page and to wp-admin. A 5xx,
 *    WordPress's critical-error page, PHP fatal output or a new sandbox crash marker restores the
 *    previous bytes (or removes a file that did not exist), and the write is reported as failed.
 *
 * A probe that cannot reach the site (many hosts block loopback) proves nothing either way, so
 * the write is kept and the result says the check could not run. When the site was already
 * failing before the write, the write is kept too: restoring would blame the wrong change.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Bytes of a failing probe response kept in the error, enough to show the fatal message. */
const WPPILOT_FILE_GUARD_EXCERPT_BYTES = 600;

/**
 * Whether a resolved path is a file PHP can execute or that controls how PHP runs.
 */
function wppilot_file_guard_applies(string $resolved): bool
{
    return wppilot_path_requires_php_sandbox($resolved);
}

/**
 * Whether a resolved path holds PHP source the tokenizer can check.
 */
function wppilot_file_guard_is_php_source(string $resolved): bool
{
    $filename = rtrim(strtolower(basename($resolved)), characters: '. ');

    return wppilot_is_php_extension(strtolower(pathinfo($filename, PATHINFO_EXTENSION)));
}

/**
 * The first syntax error in PHP source, or null when it parses.
 *
 * Parses only; nothing in $code runs. Returns null without checking when the tokenizer extension
 * is missing, which wppilot_file_guard_lint() reports as `unavailable`.
 *
 * @return array{message: string, line: int}|null
 */
function wppilot_php_syntax_error(string $code): ?array
{
    if (!function_exists('token_get_all')) {
        return null;
    }

    try {
        token_get_all($code, TOKEN_PARSE);
    } catch (ParseError $error) {
        return ['message' => $error->getMessage(), 'line' => $error->getLine()];
    } catch (Throwable $error) {
        // A CompileError (e.g. a misplaced declare) is a syntax failure for this purpose too.
        return ['message' => $error->getMessage(), 'line' => $error->getLine()];
    }

    return null;
}

/**
 * Syntax-check content bound for a resolved path.
 *
 * @return array{checked: bool, reason?: string}|WP_Error A WP_Error when the content does not parse.
 */
function wppilot_file_guard_lint(string $resolved, string $content): array|WP_Error
{
    if (!wppilot_file_guard_is_php_source($resolved)) {
        return ['checked' => false, 'reason' => 'not_php'];
    }
    if (!function_exists('token_get_all')) {
        return ['checked' => false, 'reason' => 'unavailable'];
    }

    $error = wppilot_php_syntax_error($content);
    if ($error !== null) {
        return new WP_Error(
            'php_syntax_error',
            sprintf(
                'Refused: the PHP does not parse (line %d: %s). Nothing was written.',
                $error['line'],
                $error['message'],
            ),
            ['status' => 400, 'line' => $error['line'], 'parse_error' => $error['message']],
        );
    }

    return ['checked' => true];
}

/**
 * Write a whole file atomically: a temporary file in the same directory, renamed over the target.
 *
 * Keeps an existing file's permission bits; a new file gets 0644. Falls back to an in-place write
 * when the rename is refused (some Windows and network filesystems refuse to replace an open
 * file), so a guarded write never fails where the old unguarded one succeeded.
 *
 * @return int|WP_Error Bytes written.
 */
function wppilot_atomic_write_file(string $resolved, string $content): int|WP_Error
{
    $dir = dirname($resolved);
    $existed = is_file($resolved);
    $mode = $existed ? (fileperms($resolved) & 0777) : 0644;
    // Not a PHP extension, so neither the web server nor the sandbox loader runs it.
    $temp = $dir . DIRECTORY_SEPARATOR . '.' . basename($resolved) . '.' . bin2hex(random_bytes(6)) . '.tmp';

    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.file_system_operations_readfile,WordPress.WP.AlternativeFunctions.file_system_operations_is_writable,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem is not usable here: it takes credentials from an interactive admin form, which a REST/MCP request has no way to show. Writing the requested path is the calling ability itself, bounded by the path guard and gated to Developer Full Access.
    $bytes = file_put_contents($temp, $content, LOCK_EX);
    if ($bytes === false || $bytes !== strlen($content)) {
        if (file_exists($temp)) {
            unlink($temp);
        }
        return new WP_Error('write_failed', sprintf('Failed to write file: %s', $resolved));
    }
    chmod(filename: $temp, permissions: $mode);

    if (!rename($temp, $resolved)) {
        unlink($temp);
        $bytes = file_put_contents($resolved, $content, LOCK_EX);
        if ($bytes === false) {
            return new WP_Error('write_failed', sprintf('Failed to write file: %s', $resolved));
        }
        if (!$existed) {
            chmod(filename: $resolved, permissions: 0644);
        }
    }

    wppilot_file_guard_invalidate_opcache($resolved);
    clearstatcache(true, $resolved);

    return $bytes;
}

/**
 * Drop one file from OPcache so the next request compiles the new bytes. One file only.
 */
function wppilot_file_guard_invalidate_opcache(string $resolved): void
{
    if (function_exists('opcache_invalidate') && wppilot_file_guard_is_php_source($resolved)) {
        // Suppressed: opcache.restrict_api makes this warn on some hosts, and the write must not fail for it.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @opcache_invalidate($resolved, true);
    }
}

/**
 * The sandbox loader's crash marker path.
 */
function wppilot_file_guard_crash_marker(): string
{
    return rtrim(WPPILOT_SANDBOX_DIR, characters: '/\\') . DIRECTORY_SEPARATOR . '.crashed';
}

/**
 * Whether the post-write loopback check should run.
 */
function wppilot_file_guard_probe_enabled(string $resolved): bool
{
    /**
     * Switch the post-write loopback check off, e.g. on a host whose loopback is known to hang.
     *
     * @param bool   $enabled  Default true.
     * @param string $resolved The file just written.
     */
    return (bool) apply_filters('wppilot_file_write_probe_enabled', true, $resolved);
}

/**
 * Fetch the home page and wp-admin logged out, and say whether either shows a fatal.
 *
 * Logged out on purpose: a cookie would pull in the agent's session, and visitors are who a
 * broken file hurts first. A random query string gets past most page caches, which would
 * otherwise serve a healthy cached copy over a broken site.
 *
 * @return array{reachable: bool, failed: bool, checks: list<array{url: string, status: int, failed: bool, reason: string}>, excerpt?: string}
 */
function wppilot_file_guard_probe(): array
{
    $marker = wppilot_file_guard_crash_marker();
    $marker_before = file_exists($marker);
    $nonce = bin2hex(random_bytes(6));
    $checks = [];
    $reachable = false;
    $failed = false;
    $excerpt = '';

    foreach ([home_url('/'), admin_url('/')] as $url) {
        $response = wp_remote_get(add_query_arg(['wppilot_write_check' => $nonce], $url), [
            'timeout' => 10,
            'redirection' => 0,
            'cookies' => [],
            'headers' => ['Cache-Control' => 'no-cache'],
            // Core's own loopback checks use this filter for the site's own certificate.
            'sslverify' => (bool) apply_filters('https_local_ssl_verify', false),
        ]);
        if (is_wp_error($response)) {
            $checks[] = ['url' => $url, 'status' => 0, 'failed' => false, 'reason' => 'unreachable'];
            continue;
        }
        $reachable = true;
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $reason = wppilot_file_guard_failure_reason($status, $body);
        $checks[] = ['url' => $url, 'status' => $status, 'failed' => $reason !== '', 'reason' => $reason];
        if ($reason !== '') {
            $failed = true;
            if ($excerpt === '') {
                $excerpt = substr(trim(wp_strip_all_tags($body)), 0, WPPILOT_FILE_GUARD_EXCERPT_BYTES);
            }
        }
    }

    clearstatcache(true, $marker);
    if (!$marker_before && file_exists($marker)) {
        $failed = true;
        $checks[] = ['url' => '', 'status' => 0, 'failed' => true, 'reason' => 'sandbox_crash_marker'];
    }

    $result = ['reachable' => $reachable, 'failed' => $failed, 'checks' => $checks];
    if ($excerpt !== '') {
        $result['excerpt'] = $excerpt;
    }

    return $result;
}

/**
 * Why one probe response counts as broken, or '' when it does not.
 */
function wppilot_file_guard_failure_reason(int $status, string $body): string
{
    if ($status >= 500) {
        return 'http_' . $status;
    }
    if (str_contains($body, 'There has been a critical error on this website')) {
        return 'critical_error';
    }
    if (preg_match('/(?:<b>)?(?:Fatal|Parse) error(?:<\/b>)?:\s/i', $body) === 1) {
        return 'php_fatal';
    }

    return '';
}

/**
 * Put a file back the way it was before a guarded write: the previous bytes, or gone.
 */
function wppilot_file_guard_restore(string $resolved, ?string $previous): bool
{
    if ($previous === null) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing the file this same call just created, bounded by the path guard.
        $ok = !file_exists($resolved) || unlink($resolved);
        wppilot_file_guard_invalidate_opcache($resolved);
        return $ok;
    }

    return !is_wp_error(wppilot_atomic_write_file($resolved, $previous));
}

/**
 * Check the site after a guarded change and undo the change when the site broke.
 *
 * $undo puts the file back and returns whether it managed to; it is a callable rather than
 * previous bytes because enable-file undoes a rename, not a write.
 *
 * @param callable(): bool $undo
 * @param array{reachable: bool, failed: bool}|null $baseline A probe from before the change, or null to take none.
 * @return array<string, mixed>|WP_Error The verification summary, or a WP_Error when the change was rolled back.
 */
function wppilot_file_guard_verify(string $resolved, callable $undo, ?array $baseline): array|WP_Error
{
    if (!wppilot_file_guard_applies($resolved) || !wppilot_file_guard_probe_enabled($resolved)) {
        return ['checked' => false, 'reason' => 'not_applicable'];
    }
    if ($baseline !== null && $baseline['reachable'] && $baseline['failed']) {
        return ['checked' => false, 'reason' => 'site_already_failing'];
    }

    $marker = wppilot_file_guard_crash_marker();
    $marker_before = file_exists($marker);
    $probe = wppilot_file_guard_probe();
    if (!$probe['reachable'] && !$probe['failed']) {
        return ['checked' => false, 'reason' => 'loopback_unreachable', 'checks' => $probe['checks']];
    }
    if (!$probe['failed']) {
        return ['checked' => true, 'site_ok' => true, 'checks' => $probe['checks']];
    }

    $restored = $undo();
    // The marker this change caused would keep every sandbox file off until someone removed it.
    clearstatcache(true, $marker);
    if ($restored && !$marker_before && file_exists($marker)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- GoBuildWP's own sandbox crash marker, created by the change being undone.
        unlink($marker);
    }

    return new WP_Error(
        $restored ? 'write_rolled_back' : 'write_broke_site',
        $restored
            ? 'The change broke the site (see checks), so the previous version was put back automatically. Fix the code and try again.'
            : 'The change broke the site (see checks) and could not be put back automatically. Restore it from the change log now.',
        [
            'status' => 422,
            'path' => $resolved,
            'restored' => $restored,
            'checks' => $probe['checks'],
            'excerpt' => $probe['excerpt'] ?? '',
        ],
    );
}

/**
 * A pre-change probe, taken only when the post-change probe will run.
 *
 * @return array{reachable: bool, failed: bool}|null
 */
function wppilot_file_guard_baseline(string $resolved): ?array
{
    if (!wppilot_file_guard_applies($resolved) || !wppilot_file_guard_probe_enabled($resolved)) {
        return null;
    }
    $probe = wppilot_file_guard_probe();

    return ['reachable' => $probe['reachable'], 'failed' => $probe['failed']];
}

/**
 * Lint, write atomically, probe, and restore on a fatal: the whole guarded write for a file.
 *
 * Files that do not affect PHP execution are still written atomically, and not probed.
 *
 * @return array{bytes: int, verification: array<string, mixed>, lint: array<string, mixed>}|WP_Error
 */
function wppilot_guarded_write_file(string $resolved, string $content): array|WP_Error
{
    $lint = wppilot_file_guard_lint($resolved, $content);
    if ($lint instanceof WP_Error) {
        return $lint;
    }

    $previous = null;
    if (is_file($resolved)) {
        $read = file_get_contents($resolved);
        if ($read === false) {
            return new WP_Error('read_failed', sprintf('Could not read the current file to keep a copy: %s', $resolved));
        }
        $previous = $read;
    }

    $baseline = wppilot_file_guard_baseline($resolved);
    $bytes = wppilot_atomic_write_file($resolved, $content);
    if ($bytes instanceof WP_Error) {
        return $bytes;
    }

    $verification = wppilot_file_guard_verify(
        $resolved,
        static fn(): bool => wppilot_file_guard_restore($resolved, $previous),
        $baseline,
    );
    if ($verification instanceof WP_Error) {
        return $verification;
    }

    return ['bytes' => $bytes, 'verification' => $verification, 'lint' => $lint];
}
