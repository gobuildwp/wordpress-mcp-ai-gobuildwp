<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteIssues;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The PHP error log this kit keeps: every fatal error a request on this site hit, recorded from
 * WordPress's own fatal error handler, so "There has been a critical error" comes with the error
 * that caused it even when the server's PHP log is unreadable.
 *
 * Kept in one non-autoloaded option as a ring buffer of MAX_ERRORS entries, deduplicated by
 * message, file and line, each with a count and the first and last time it was seen. What is
 * stored is reduced on the way in: the message's first line only, trimmed; file paths relative to
 * the WordPress root, and a path outside it reduced to its file name; the request's path without
 * its query string. Cookies, headers, query strings, request bodies and stack traces are never
 * read.
 */

const ERRORS_OPTION = 'wppilot_kit_site_issues_php_errors';

/** How other kits read the log: apply_filters(ERRORS_FILTER, [], $since). */
const ERRORS_FILTER = 'wppilot_kit_site_issues_errors';

const MAX_ERRORS = 50;

const MAX_MESSAGE = 300;

/**
 * Seconds within which a repeat of the same error is counted in memory but not written again. A
 * site-wide fatal hits every request at once; one write per error per this many seconds keeps the
 * log from becoming a write storm on the options table. Counts are therefore "at least".
 */
const WRITE_EVERY = 5;

/** PHP error types WordPress's handler treats as fatal. */
const FATAL_TYPES = [E_ERROR, E_PARSE, E_USER_ERROR, E_COMPILE_ERROR, E_CORE_ERROR, E_RECOVERABLE_ERROR];

/**
 * Hook the recorder into this request: WordPress's fatal error handler passes the error through
 * the wp_php_error_message filter just before it prints the critical-error page, and exits after
 * it, so the filter is where the error is caught. The shutdown function covers the requests where
 * the handler does not print (output already started on the front end, the handler disabled by
 * WP_DISABLE_FATAL_ERROR_HANDLER, or a php-error.php drop-in that dies before any filter).
 *
 * A fatal in a plugin file that loads before this one, while it is being included, ends the
 * request before the recorder exists; those are missed. Fatals inside hooks, templates, REST and
 * AJAX handlers, which is where nearly all of them happen, are recorded.
 */
function register_recorder(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    add_filter('wp_php_error_message', __NAMESPACE__ . '\\on_error_message', 1, 2);
    register_shutdown_function(__NAMESPACE__ . '\\on_shutdown');
}

/**
 * @param mixed $message
 * @param mixed $error
 * @return mixed The message, unchanged.
 */
function on_error_message(mixed $message, mixed $error): mixed
{
    if (is_array($error)) {
        record_once($error);
    }
    return $message;
}

function on_shutdown(): void
{
    $error = error_get_last();
    if (is_array($error) && in_array((int) ($error['type'] ?? 0), FATAL_TYPES, true)) {
        record_once($error);
    }
}

/**
 * Record the request's fatal once, whichever of the two paths sees it first.
 *
 * @param array<string, mixed> $error
 */
function record_once(array $error): void
{
    static $recorded = false;
    if ($recorded) {
        return;
    }
    $recorded = true;
    try {
        make_room($error);
        record($error, current_path(), time());
    } catch (\Throwable $ignored) {
        // Recording is best effort: it must never replace the site's error page with its own.
        unset($ignored);
    }
}

/**
 * A request that ran out of memory has almost none left to record with. Give the recorder a
 * little more, as WordPress itself does for admin screens; where the host forbids raising it the
 * write is skipped rather than risking a second fatal in the handler.
 *
 * @param array<string, mixed> $error
 */
function make_room(array $error): void
{
    if (cause((string) ($error['message'] ?? '')) !== 'memory') {
        return;
    }
    $limit = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
    if ($limit > 0) {
        // ini_set may be disabled; the write below is then attempted with what is left.
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- Only after an out-of-memory fatal, inside the shutdown recorder: 32 MB more so the record can be written, as wp_raise_memory_limit() does for admin screens. Silenced because a host may forbid it.
        @ini_set('memory_limit', (string) ($limit + 32 * MB_IN_BYTES));
    }
}

/**
 * The request's path, without query string or fragment, for "where did it happen".
 */
function current_path(): string
{
    if (defined('WP_CLI') && WP_CLI) {
        return 'wp-cli';
    }
    if (wp_doing_cron()) {
        return 'wp-cron';
    }
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the path component is kept, then reduced to printable ASCII and capped at 200 characters below; sanitize_text_field() would drop its percent-encoded octets and make the recorded path unrecognisable.
    $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    $path = preg_replace('/[^\x20-\x7E]/', '', $path) ?? '';
    return mb_substr($path, 0, 200);
}

/**
 * Add one fatal error to the log.
 *
 * @param array<string, mixed> $error As error_get_last() returns it: type, message, file, line.
 * @return array<string, mixed>|null The stored entry, or null when it was counted but not written.
 */
function record(array $error, string $path, int $now): ?array
{
    $entry = normalize_error($error);
    $entry['url_path'] = $path;
    $key = $entry['id'];

    wp_cache_delete(ERRORS_OPTION, 'options');
    $all = stored_errors();
    $existing = $all[$key] ?? null;
    if (is_array($existing)) {
        if ((int) ($existing['last_at'] ?? 0) > $now - WRITE_EVERY) {
            return null;
        }
        $entry['count'] = (int) ($existing['count'] ?? 0) + 1;
        $entry['first_at'] = (int) ($existing['first_at'] ?? $now);
    } else {
        $entry['count'] = 1;
        $entry['first_at'] = $now;
    }
    $entry['last_at'] = $now;
    $all[$key] = $entry;

    uasort($all, static fn(array $a, array $b): int => (int) $b['last_at'] <=> (int) $a['last_at']);
    $all = array_slice($all, 0, MAX_ERRORS, true);
    update_option(ERRORS_OPTION, $all, false);
    return $entry;
}

/**
 * @return array<string, array<string, mixed>> Id => entry.
 */
function stored_errors(): array
{
    /** @var mixed $stored */
    $stored = get_option(ERRORS_OPTION, []);
    if (!is_array($stored)) {
        return [];
    }
    return array_filter($stored, static fn($e): bool => is_array($e) && isset($e['id'], $e['last_at']));
}

/**
 * Errors seen at or after $since, newest first.
 *
 * @return list<array<string, mixed>>
 */
function errors_since(int $since = 0, int $limit = MAX_ERRORS): array
{
    wp_cache_delete(ERRORS_OPTION, 'options');
    $rows = array_values(array_filter(stored_errors(), static fn(array $e): bool => (int) $e['last_at'] >= $since));
    usort($rows, static fn(array $a, array $b): int => (int) $b['last_at'] <=> (int) $a['last_at']);
    return array_slice($rows, 0, max(1, $limit));
}

/**
 * ERRORS_FILTER: the errors last seen at or after $since, each with its one-line summary.
 *
 * @param mixed $errors What earlier callbacks returned.
 * @param mixed $since Unix time.
 * @return list<array<string, mixed>>
 */
function errors_filter(mixed $errors, mixed $since = 0): array
{
    $mine = array_map(
        static fn(array $e): array => array_merge($e, ['summary' => describe($e)]),
        errors_since(is_numeric($since) ? (int) $since : 0),
    );
    return array_merge(is_array($errors) ? array_values($errors) : [], $mine);
}

/**
 * The error as stored: no absolute paths, one line, at most MAX_MESSAGE characters.
 *
 * @param array<string, mixed> $error
 * @return array{id: string, type: string, message: string, file: string, line: int, source: string, cause: string}
 */
function normalize_error(array $error): array
{
    $file = relative_path((string) ($error['file'] ?? ''));
    $line = max(0, (int) ($error['line'] ?? 0));
    $message = clean_message((string) ($error['message'] ?? ''));
    $cause = cause($message);
    return [
        'id' => substr(hash('sha256', $message . '|' . $file . '|' . $line), 0, 32),
        'type' => type_name((int) ($error['type'] ?? 0)),
        'message' => $message,
        'file' => $file,
        'line' => $line,
        // Out of memory and out of time stop wherever PHP happened to be; the file names no culprit.
        'source' => $cause === 'error' ? source_of($file) : '',
        'cause' => $cause,
    ];
}

/**
 * memory (PHP's memory_limit), timeout (max_execution_time) or error (anything else).
 */
function cause(string $message): string
{
    if (stripos($message, 'Allowed memory size') !== false || stripos($message, 'Out of memory') !== false) {
        return 'memory';
    }
    if (stripos($message, 'Maximum execution time') !== false) {
        return 'timeout';
    }
    return 'error';
}

function type_name(int $type): string
{
    return match ($type) {
        E_ERROR => 'E_ERROR',
        E_PARSE => 'E_PARSE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_USER_ERROR => 'E_USER_ERROR',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        default => 'E_' . $type,
    };
}

/**
 * The first line of the message (PHP appends the stack trace after it), with every absolute path
 * in it made relative and anything that looks like a credential masked.
 */
function clean_message(string $message): string
{
    $message = (string) strtok(str_replace("\r", '', $message), "\n");
    $message = (string) preg_replace('/\s+Stack trace:.*$/s', '', $message);
    // Absolute paths, POSIX or Windows, ending in a PHP-ish file name.
    $message = (string) preg_replace_callback(
        '~(?:[A-Za-z]:)?[\\\\/](?:[^\s\'"():,]+[\\\\/])*[^\s\'"():,\\\\/]+\.(?:php|phtml|inc)\b~',
        static fn(array $m): string => relative_path($m[0]),
        $message,
    );
    $message = (string) preg_replace('/\b(password|passwd|pwd|secret|token|api[_-]?key|auth|key)\s*([=:])\s*(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s,;&\'")]+)/i', '$1$2[redacted]', $message);
    $message = trim((string) preg_replace('/\s+/', ' ', $message));
    return mb_strlen($message) > MAX_MESSAGE ? mb_substr($message, 0, MAX_MESSAGE - 1) . '…' : $message;
}

/**
 * A path relative to the WordPress root; outside it, only the file name.
 */
function relative_path(string $path): string
{
    if ($path === '') {
        return '';
    }
    $normal = str_replace('\\', '/', $path);
    $roots = [
        'wp-content/' => trailingslashit(str_replace('\\', '/', WP_CONTENT_DIR)),
        '' => trailingslashit(str_replace('\\', '/', ABSPATH)),
    ];
    foreach ($roots as $prefix => $root) {
        if ($root !== '/' && str_starts_with($normal, $root)) {
            return $prefix . substr($normal, strlen($root));
        }
    }
    return '…/' . basename($normal);
}

/**
 * Which plugin, theme or part of WordPress a relative file belongs to: "plugin-slug",
 * "theme:slug", "mu-plugin:file", "core", or "".
 */
function source_of(string $file): string
{
    if (preg_match('~^wp-content/plugins/([^/]+)~', $file, $m) === 1) {
        return (string) preg_replace('/\.php$/', '', $m[1]);
    }
    if (preg_match('~^wp-content/mu-plugins/([^/]+)~', $file, $m) === 1) {
        return 'mu-plugin:' . preg_replace('/\.php$/', '', $m[1]);
    }
    if (preg_match('~^wp-content/themes/([^/]+)~', $file, $m) === 1) {
        return 'theme:' . $m[1];
    }
    if (preg_match('~^(?:wp-includes|wp-admin)/|^wp-[a-z-]+\.php$~', $file) === 1) {
        return 'core';
    }
    return '';
}

/**
 * One line a person can read: what failed, where, and whose code it is.
 *
 * @param array<string, mixed> $entry
 */
function describe(array $entry): string
{
    $message = (string) ($entry['message'] ?? '');
    $file = (string) ($entry['file'] ?? '');
    $line = (int) ($entry['line'] ?? 0);
    $where = $file !== '' && !str_contains($message, $file) ? sprintf(' in %s%s', $file, $line > 0 ? ':' . $line : '') : '';
    $text = $message . $where;
    $source = (string) ($entry['source'] ?? '');
    $cause = (string) ($entry['cause'] ?? 'error');
    if ($cause === 'memory') {
        $text .= ' (PHP ran out of memory; the file is only where it stopped, not the cause)';
    } elseif ($cause === 'timeout') {
        $text .= ' (PHP ran out of time; the file is only where it stopped)';
    } elseif ($source !== '') {
        $text .= ', ' . source_label($source);
    }
    return $text;
}

function source_label(string $source): string
{
    if (str_starts_with($source, 'theme:')) {
        return 'theme ' . substr($source, 6);
    }
    if (str_starts_with($source, 'mu-plugin:')) {
        return 'must-use plugin ' . substr($source, 10);
    }
    return $source === 'core' ? 'WordPress core' : 'plugin ' . $source;
}
