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
