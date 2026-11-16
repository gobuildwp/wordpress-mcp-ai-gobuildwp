<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteTools\Options;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

const MAX_ROWS = 200;

const MAX_VALUE = 500;

/**
 * Options whose values are never shown, whatever their name looks like.
 *
 * WordPress keeps its keys and salts here when wp-config.php does not define them, the Freemius
 * SDK many plugins bundle keeps its secret keys in fs_accounts, and recovery_keys holds
 * recovery-mode login keys.
 */
const DENIED = [
    'auth_key', 'auth_salt', 'secure_auth_key', 'secure_auth_salt', 'logged_in_key', 'logged_in_salt',
    'nonce_key', 'nonce_salt', 'recovery_keys', 'mailserver_pass', 'mailserver_login', 'fs_accounts',
];

/**
 * Whether an option's name says its value is a secret.
 *
 * Broader than the column rule the database kit uses: an option name is free text chosen by a
 * plugin author, and a false positive here hides one value, not the option.
 */
function is_secret_name(string $name): bool
{
    return preg_match(
        '/pass(word|wd)?|pwd|secret|token|key|salt|nonce|hash|auth|credential|licen[cs]e|private|cookie|session|api|smtp|oauth|bearer|webhook|signature|e_?mail/i',
        $name,
    ) === 1;
}

/**
 * Why an option's value is withheld, or '' when it may be shown.
 *
 * @param list<string> $denied Exact names, or prefixes ending in `*`.
 */
function withheld(string $name, string $host_id, array $denied): string
{
    $lower = strtolower($name);
    // The plugin carrying this kit keeps its own credentials in options named after it.
    if ($host_id !== '' && preg_match('/(^|_)' . preg_quote(strtolower($host_id), '/') . '_/', $lower) === 1) {
        return 'this plugin\'s own setting';
    }
    foreach (array_merge(DENIED, $denied) as $rule) {
        $rule = strtolower($rule);
        if ($rule === $lower || (str_ends_with($rule, '*') && str_starts_with($lower, substr($rule, 0, -1)))) {
            return 'denied';
        }
    }
    return is_secret_name($name) ? 'sensitive name' : '';
}

const REDACTED = '[redacted]';

/**
 * Redact values stored under secret-looking keys inside a serialized or JSON option value.
 *
 * A complete value is decoded and walked; a cut preview (or one that does not decode) is
 * scanned token by token, which may over-redact but never leaves a secret key's value in clear.
 *
 * @return array{0: string, 1: int} The safe text and how many values were redacted.
 */
function redact_value(string $text, bool $complete): array
{
    $trimmed = ltrim($text);
    if ($trimmed === '') {
        return [$text, 0];
    }
    if ($complete) {
        if (is_serialized($text)) {
            /** @var mixed $data */
            $data = @unserialize($text, ['allowed_classes' => false]);
            if (is_array($data)) {
                $count = 0;
                $clean = redact_tree($data, $count);
                return $count > 0 ? [serialize($clean), $count] : [$text, 0];
            }
        } elseif ($trimmed[0] === '{' || $trimmed[0] === '[') {
            /** @var mixed $data */
            $data = json_decode($text, true);
            if (is_array($data)) {
                $count = 0;
                $clean = redact_tree($data, $count);
                if ($count === 0) {
                    return [$text, 0];
                }
                $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($json)) {
                    return [$json, $count];
                }
            }
        }
    }
    $count = 0;
    $text = redact_serialized_text($text, $count);
    $text = redact_json_text($text, $count);
    return [$text, $count];
}

/**
 * @param array<mixed> $data
 * @return array<mixed>
 */
function redact_tree(array $data, int &$count, int $depth = 0): array
{
    foreach ($data as $key => $value) {
        if (is_string($key) && is_secret_name($key) && $value !== null && $value !== '' && !is_array($value)) {
            $data[$key] = REDACTED;
            $count++;
        } elseif (is_array($value) && $depth < 32) {
            $data[$key] = redact_tree($value, $count, $depth + 1);
        } elseif (is_string($value) && $value !== '' && (is_serialized($value) || in_array(ltrim($value)[0] ?? '', ['{', '['], true))) {
            [$clean, $inner] = redact_value($value, true);
            if ($inner > 0) {
                $data[$key] = $clean;
                $count += $inner;
            }
        }
    }
    return $data;
}

/**
 * Token scan of PHP-serialized text: a value that follows a secret-looking string key is redacted.
 */
function redact_serialized_text(string $text, int &$count): string
{
    $out = '';
    $pos = 0;
    $len = strlen($text);
    $secret_key = false;
    while ($pos < $len && preg_match('/s:(\d+):"/', $text, $m, PREG_OFFSET_CAPTURE, $pos) === 1) {
        $start = (int) $m[0][1];
        $n = (int) $m[1][0];
        $body = $start + strlen($m[0][0]);
        // A scalar value (i:, d:, b:) directly after a secret key.
        if ($secret_key && preg_match('/\G[idb]:[^;]*;?/', $text, $s, 0, $pos) === 1 && $s[0] !== '') {
            $out .= 's:' . strlen(REDACTED) . ':"' . REDACTED . '";';
            $count++;
            $pos += strlen($s[0]);
            $secret_key = false;
            continue;
        }
        $out .= substr($text, $pos, $start - $pos);
        $adjacent = $start === $pos;
        if ($body + $n > $len) {
            // Cut off inside this string.
            if ($secret_key && $adjacent) {
                $out .= 's:' . strlen(REDACTED) . ':"' . REDACTED . '"';
                $count++;
            } else {
                $out .= substr($text, $start);
            }
            return $out;
        }
        $value = substr($text, $body, $n);
        $end = $body + $n + 2; // closing `";`
        if ($secret_key && $adjacent) {
            $out .= 's:' . strlen(REDACTED) . ':"' . REDACTED . '";';
            if ($value !== '') {
                $count++;
            }
            $secret_key = false;
        } else {
            $out .= substr($text, $start, min($end, $len) - $start);
            $secret_key = is_secret_name($value);
        }
        $pos = min($end, $len);
    }
    // A secret key's scalar value at the very end of the text.
    if ($secret_key && $pos < $len && preg_match('/\G[idb]:[^;]*(;?)/', $text, $s, 0, $pos) === 1) {
        $count++;
        return $out . 's:' . strlen(REDACTED) . ':"' . REDACTED . '"' . $s[1] . substr($text, $pos + strlen($s[0]));
    }
    return $out . substr($text, $pos);
}

/**
 * Regex pass over JSON text (complete or cut): `"secret_key": value` loses its value.
 */
function redact_json_text(string $text, int &$count): string
{
    $result = preg_replace_callback(
        '/"((?:[^"\\\\]|\\\\.)*)"(\s*:\s*)("(?:[^"\\\\]|\\\\.)*(?:"|$)|-?[0-9][0-9.eE+-]*|true|false)/',
        static function (array $m) use (&$count): string {
            if (!is_secret_name(stripcslashes($m[1])) || $m[3] === '""') {
                return $m[0];
            }
            $count++;
            return '"' . $m[1] . '"' . $m[2] . '"' . REDACTED . '"';
        },
        $text,
    );
    return is_string($result) ? $result : $text;
}

/**
 * @return list<string>
 */
function denied(): array
{
    /** @var mixed $extra */
    $extra = apply_filters('wppilot_kit_options_explore_denied', []);
    return array_values(array_filter(is_array($extra) ? $extra : [], static fn(mixed $name): bool => is_string($name) && $name !== ''));
}

/**
 * A LIKE pattern from a name pattern where `*` is any run of characters and `?` one character.
 */
function like(object $wpdb, string $pattern): string
{
    return strtr($wpdb->esc_like($pattern), ['*' => '%', '?' => '_']);
}

/**
 * wppilot/options-explore.
 *
 * Values are read raw, as stored, and never unserialized: maybe_unserialize() on an option a
 * plugin wrote can instantiate its objects, and a read has no business running their code.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function explore(array $input): array|WP_Error
{
    global $wpdb;
    if (!is_object($wpdb)) {
        return new WP_Error('kit_options_unavailable', 'The WordPress database connection is not available.');
    }
    $limit = min(MAX_ROWS, max(1, (int) ($input['limit'] ?? 50)));
    $offset = max(0, (int) ($input['offset'] ?? 0));
    $value_length = min(MAX_VALUE, max(0, (int) ($input['value_length'] ?? 200)));
    // 'yes' and 'no' before WordPress 6.6, 'on', 'off' and the 'auto' family since.
    $autoload_values = array_values(wp_autoload_values_to_autoload());

    $where = ['1=1'];
    $params = [];
    $search = trim((string) ($input['search'] ?? ''));
    if ($search !== '') {
        $where[] = 'option_name LIKE %s';
        $params[] = '%' . $wpdb->esc_like($search) . '%';
    }
    $pattern = trim((string) ($input['pattern'] ?? ''));
    if ($pattern !== '') {
        $where[] = 'option_name LIKE %s';
        $params[] = like($wpdb, $pattern);
    }
    $autoload = (string) ($input['autoload'] ?? 'any');
    if ($autoload === 'on' || $autoload === 'off') {
        $placeholders = implode(',', array_fill(0, count($autoload_values), '%s'));
        $where[] = 'autoload ' . ($autoload === 'off' ? 'NOT ' : '') . "IN ({$placeholders})";
        $params = array_merge($params, $autoload_values);
    }
    $where_sql = implode(' AND ', $where);
    $order = ($input['order'] ?? 'name') === 'size' ? 'size DESC, option_name ASC' : 'option_name ASC';

    $total = (int) $wpdb->get_var($params === []
        ? "SELECT COUNT(*) FROM {$wpdb->options} WHERE {$where_sql}"
        : $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE {$where_sql}", $params));
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, autoload, LENGTH(option_value) AS size, SUBSTRING(option_value, 1, %d) AS preview FROM {$wpdb->options} WHERE {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d",
            array_merge([$value_length + 1], $params, [$limit, $offset]),
        ),
        ARRAY_A,
    );
    $autoload_placeholders = implode(',', array_fill(0, count($autoload_values), '%s'));
    $autoloaded = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE autoload IN ({$autoload_placeholders})",
        $autoload_values,
    ));

    $host_id = Runtime\host()->id();
    $denied = denied();
    $options = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $name = (string) ($row['option_name'] ?? '');
        $preview = (string) ($row['preview'] ?? '');
        $size = (int) ($row['size'] ?? 0);
        $reason = withheld($name, $host_id, $denied);
        $option = [
            'name' => $name,
            'autoload' => (string) ($row['autoload'] ?? ''),
            'autoloaded' => in_array((string) ($row['autoload'] ?? ''), $autoload_values, true),
            'size' => $size,
            // Not strict: the preview may stop before the value's closing brace.
            'serialized' => is_serialized($preview, false),
        ];
        if ($reason !== '') {
            $option['value'] = null;
            $option['redacted'] = $reason;
        } elseif ($value_length > 0) {
            // Secrets nested under ordinary-named options (serialized or JSON settings arrays).
            [$preview, $nested] = redact_value($preview, strlen($preview) >= $size);
            if ($nested > 0) {
                $option['redacted_keys'] = $nested;
            }
            $option['value'] = strlen($preview) > $value_length ? substr($preview, 0, $value_length) . '…' : $preview;
            $option['value_truncated'] = $size > $value_length;
        }
        $options[] = $option;
    }

    $next = $offset + count($options);
    return [
        'options' => $options,
        'count' => count($options),
        'total' => $total,
        'offset' => $offset,
        'next_offset' => $next < $total ? $next : null,
        'autoloaded_bytes' => $autoloaded,
    ];
}
