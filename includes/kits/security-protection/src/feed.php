<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The Cloud feed: a request-rule pack and a fleet-wide reputation blocklist that GoBuildWP Cloud
 * pushes to the site over the same signed route as virtual patches.
 *
 * Rules are extra firewall rules in the shape of rules.php's own, and run with the site's firewall
 * mode (log or block). Blocklist entries block an address everywhere, like a manual block, except
 * that they are never shown among the site's own blocks and expire on their own. The allowlist and
 * signed-in administrators win over both.
 */

const OPT_FEED = 'gobuildwp_kit_sp_feed';
const MAX_FEED_RULES = 200;
const MAX_FEED_BLOCKLIST = 2000;
const FEED_MAX_TEXT = 200;
const FEED_IN = ['path', 'args', 'ua', 'files'];

/**
 * @return array{rules: list<array{id: string, label: string, in: list<string>, pattern: string}>, blocklist: list<array{ip: string, reason: string, expires_at: int|null}>, digest: string|null, set_at: string|null}
 */
function feed(): array
{
    $stored = get_option(OPT_FEED, []);
    $stored = is_array($stored) ? $stored : [];
    return [
        'rules' => is_array($stored['rules'] ?? null) ? array_values($stored['rules']) : [],
        'blocklist' => is_array($stored['blocklist'] ?? null) ? array_values($stored['blocklist']) : [],
        'digest' => is_string($stored['digest'] ?? null) ? $stored['digest'] : null,
        'set_at' => is_string($stored['set_at'] ?? null) ? $stored['set_at'] : null,
    ];
}

/**
 * @return array{id: string, label: string, in: list<string>, pattern: string}|WP_Error
 */
function sanitize_feed_rule(mixed $rule): array|WP_Error
{
    if (!is_array($rule)) {
        return new WP_Error('gobuildwp_feed_invalid', 'Invalid feed rule: each rule must be an object.');
    }
    $id = is_string($rule['id'] ?? null) ? trim($rule['id']) : '';
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
        return new WP_Error('gobuildwp_feed_invalid', 'Invalid feed rule: id must be 1–64 letters, digits, - or _.');
    }
    $label = is_string($rule['label'] ?? null) ? substr(sanitize_text(trim($rule['label'])), 0, FEED_MAX_TEXT) : '';
    if ($label === '') {
        return new WP_Error('gobuildwp_feed_invalid', sprintf('Invalid feed rule %s: label is required.', $id));
    }
    $in = $rule['in'] ?? null;
    if (!is_array($in) || $in === [] || !is_list_array($in)) {
        return new WP_Error('gobuildwp_feed_invalid', sprintf('Invalid feed rule %s: in must be a non-empty list.', $id));
    }
    $where = [];
    foreach ($in as $w) {
        if (!is_string($w) || !in_array($w, FEED_IN, true)) {
            return new WP_Error('gobuildwp_feed_invalid', sprintf('Invalid feed rule %s: in may only hold %s.', $id, implode(', ', FEED_IN)));
        }
        $where[$w] = true;
    }
    $pattern = is_string($rule['pattern'] ?? null) ? $rule['pattern'] : '';
    if ($pattern === '' || strlen($pattern) > VP_MAX_PATTERN) {
        return new WP_Error('gobuildwp_feed_invalid', sprintf('Invalid feed rule %s: pattern must be 1–%d characters.', $id, VP_MAX_PATTERN));
    }
    $problem = vp_pattern_problem($pattern);
    if ($problem !== null) {
        return new WP_Error('gobuildwp_feed_invalid', sprintf('Invalid feed rule %s: pattern %s.', $id, $problem));
    }
    return ['id' => $id, 'label' => $label, 'in' => array_keys($where), 'pattern' => $pattern];
}

/**
 * @return array{ip: string, reason: string, expires_at: int|null}|WP_Error
 */
function sanitize_feed_block(mixed $entry): array|WP_Error
{
    if (!is_array($entry)) {
        return new WP_Error('gobuildwp_feed_invalid', 'Invalid blocklist entry: each entry must be an object.');
    }
    $cidr = is_string($entry['ip'] ?? null) ? normalize_cidr($entry['ip']) : '';
    if ($cidr === '') {
        return new WP_Error('gobuildwp_feed_invalid', 'Invalid blocklist entry: ip must be an IP address or CIDR range.');
    }
    if (str_contains($cidr, '/')) {
        $bits = (int) substr($cidr, (int) strpos($cidr, '/') + 1);
        $min = str_contains($cidr, ':') ? MIN_BLOCK_PREFIX_V6 : MIN_BLOCK_PREFIX_V4;
        if ($bits < $min) {
            return new WP_Error('gobuildwp_feed_invalid', sprintf('Invalid blocklist entry %s: a range must be /%d or narrower.', $cidr, $min));
        }
    }
    $expires = $entry['expires_at'] ?? null;
    if ($expires !== null) {
        $at = is_string($expires) ? strtotime($expires) : false;
        if ($at === false) {
            return new WP_Error('gobuildwp_feed_invalid', sprintf('Invalid blocklist entry %s: expires_at must be an ISO 8601 time or null.', $cidr));
        }
        $expires = $at;
    }
    return [
        'ip' => $cidr,
        'reason' => is_string($entry['reason'] ?? null) ? substr(sanitize_text(trim($entry['reason'])), 0, FEED_MAX_TEXT) : '',
        'expires_at' => $expires,
    ];
}

/**
 * Replace the whole feed. Every rule and entry is checked first; one bad item refuses the set.
 * Empty lists clear. Already-expired entries are dropped quietly.
 *
 * @param array<string, mixed> $input rules, blocklist, digest
 * @return array<string, mixed>|WP_Error The state, as feed_state().
 */
function set_feed(array $input, ?int $now = null): array|WP_Error
{
    $now ??= time();
    $rules_in = $input['rules'] ?? [];
    $list_in = $input['blocklist'] ?? [];
    if (!is_array($rules_in) || !is_list_array($rules_in) || count($rules_in) > MAX_FEED_RULES) {
        return new WP_Error('gobuildwp_feed_invalid', 'Invalid feed: rules must be a list of at most ' . MAX_FEED_RULES . ' rules.');
    }
    if (!is_array($list_in) || !is_list_array($list_in) || count($list_in) > MAX_FEED_BLOCKLIST) {
        return new WP_Error('gobuildwp_feed_invalid', 'Invalid feed: blocklist must be a list of at most ' . MAX_FEED_BLOCKLIST . ' entries.');
    }
    $digest = $input['digest'] ?? null;
    if ($digest !== null && (!is_string($digest) || !preg_match('/^[A-Za-z0-9:_-]{1,128}$/', $digest))) {
        return new WP_Error('gobuildwp_feed_invalid', 'Invalid feed: digest must be up to 128 letters, digits, :, - or _.');
    }
    $rules = [];
    foreach ($rules_in as $rule) {
        $one = sanitize_feed_rule($rule);
        if ($one instanceof WP_Error) {
            return $one;
        }
        if (isset($rules[$one['id']])) {
            return new WP_Error('gobuildwp_feed_invalid', sprintf('Invalid feed: rule id %s appears twice.', $one['id']));
        }
        $rules[$one['id']] = $one;
    }
    $blocklist = [];
    foreach ($list_in as $entry) {
        $one = sanitize_feed_block($entry);
        if ($one instanceof WP_Error) {
            return $one;
        }
        if ($one['expires_at'] !== null && $one['expires_at'] <= $now) {
            continue;
        }
        $blocklist[$one['ip']] = $one;
    }
    $previous = feed();
    $next = [
        'rules' => array_values($rules),
        'blocklist' => array_values($blocklist),
        'digest' => $digest,
        'set_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
    ];
    if ($next['rules'] !== $previous['rules'] || $next['blocklist'] !== $previous['blocklist'] || $next['digest'] !== $previous['digest']) {
        update_option(OPT_FEED, $next, true);
        record_event('settings', 'changed', '', null, '', sprintf('Cloud feed: %d rules, %d blocklist entries', count($rules), count($blocklist)), $now);
        sync_prepend_state();
    }
    return feed_state();
}

/**
 * The feed's rules in rules.php's shape, with compiled patterns and `feed:` ids.
 *
 * @return list<array{id: string, in: list<string>, pattern: string, label: string}>
 */
function feed_rules(): array
{
    $out = [];
    foreach (feed()['rules'] as $rule) {
        if (!is_array($rule) || !is_string($rule['id'] ?? null) || !is_string($rule['pattern'] ?? null)) {
            continue;
        }
        $out[] = [
            'id' => 'feed:' . $rule['id'],
            'in' => array_values(array_intersect(is_array($rule['in'] ?? null) ? $rule['in'] : [], FEED_IN)),
            'pattern' => vp_regex($rule['pattern']),
            'label' => is_string($rule['label'] ?? null) ? $rule['label'] : $rule['id'],
        ];
    }
    return $out;
}

/**
 * Unexpired blocklist entries.
 *
 * @return list<array{ip: string, reason: string, expires_at: int|null}>
 */
function feed_blocklist(?int $now = null): array
{
    $now ??= time();
    $out = [];
    foreach (feed()['blocklist'] as $entry) {
        if (!is_array($entry) || !is_string($entry['ip'] ?? null)) {
            continue;
        }
        $expires = is_int($entry['expires_at'] ?? null) ? $entry['expires_at'] : null;
        if ($expires !== null && $expires <= $now) {
            continue;
        }
        $out[] = ['ip' => $entry['ip'], 'reason' => is_string($entry['reason'] ?? null) ? $entry['reason'] : '', 'expires_at' => $expires];
    }
    return $out;
}

/**
 * The blocklist entry that covers $ip, or null. Exact addresses are compared directly; only
 * ranges need the prefix test.
 *
 * @param list<array{ip: string, reason: string, expires_at: int|null}> $blocklist
 * @return array{ip: string, reason: string, expires_at: int|null}|null
 */
function feed_block_for(string $ip, array $blocklist): ?array
{
    if ($ip === '' || $blocklist === []) {
        return null;
    }
    $ip = normalize_ip($ip);
    $ranges = [];
    foreach ($blocklist as $entry) {
        if ($entry['ip'] === $ip) {
            return $entry;
        }
        if (str_contains($entry['ip'], '/')) {
            $ranges[] = $entry;
        }
    }
    foreach ($ranges as $entry) {
        if (ip_matches($ip, $entry['ip'])) {
            return $entry;
        }
    }
    return null;
}

function feed_has_content(): bool
{
    $f = feed();
    return $f['rules'] !== [] || $f['blocklist'] !== [];
}

/**
 * @return array{rules: int, blocklist: int, digest: string|null, set_at: string|null}
 */
function feed_summary(): array
{
    $f = feed();
    return [
        'rules' => count($f['rules']),
        'blocklist' => count(feed_blocklist()),
        'digest' => $f['digest'],
        'set_at' => $f['set_at'],
    ];
}

/**
 * @return array<string, mixed>
 */
function feed_state(): array
{
    return feed_summary() + ['prepend' => prepend_status()];
}

/**
 * array_is_list() for PHP 8.0, the plugin's floor.
 */
function is_list_array(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}
