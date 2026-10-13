<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Preview\Diff;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Structural diff between two change-ledger snapshots.
 *
 * Pure: it reads no WordPress state and writes none, so the whole rule set is
 * unit-testable without a bootstrap. The projector layer supplies both sides,
 * this file only compares them — the same split includes/design/preflight.php
 * uses, where the lint is pure and the ability supplies the context.
 *
 * Output is a flat list of path entries rather than a nested mirror of the
 * snapshot. A table renders it without recursion (no admin template in this
 * plugin recurses), an agent can answer "what changes" without walking a tree,
 * and it caps by simply dropping entries.
 *
 * Six sources of false positives are handled here, each of which would otherwise
 * make every preview noisy enough to ignore:
 *
 *  1. Volatile post columns move on their own. Dropped, from the one list
 *     WPPILOT_VOLATILE_POST_FIELDS that the ledger fingerprint also uses.
 *  2. wp_slash() is transport compensation, not a value transform, so projectors
 *     record the raw value and this layer never sees a slashing difference.
 *  3. Post meta reads back as `key => [value]` and coerces scalars to strings,
 *     so `5` and `'5'` are the same stored value and must not diff.
 *  4. Object terms are a set; wp_get_object_terms() ordering is not meaningful.
 *  5. An oversize snapshot has no state to compare and is refused upstream
 *     rather than diffed partially.
 *  6. Sensitive keys never appear in a snapshot, so a projected write to one
 *     would otherwise leak the new value into an admin screen and an MCP
 *     response. Values are replaced with a marker on both sides.
 */

/** Per-value budget before a side is truncated for display. */
const MAX_VALUE_BYTES = 65_536;

/** Whole-diff budget. Entries beyond it are dropped and counted. */
const MAX_ENTRIES = 200;

/** Guard against a pathological nested meta value. */
const MAX_DEPTH = 6;

const REDACTED = '[redacted]';

/**
 * Compare two snapshots of the same type.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @param list<string> $sensitive_keys Keys the snapshot deliberately did not capture.
 * @return array{
 *   changed_count: int,
 *   destroys: bool,
 *   truncated: bool,
 *   dropped_count: int,
 *   entries: list<array<string, mixed>>,
 *   unpredicted: list<array{path_label: string, reason: string}>
 * }
 */
function compare(array $before, array $after, array $sensitive_keys = []): array
{
    $entries = [];
    foreach (payload_keys($before, $after) as $section) {
        $left = is_array($before[$section] ?? null) ? $before[$section] : [];
        $right = is_array($after[$section] ?? null) ? $after[$section] : [];
        walk($left, $right, [$section], $entries, $sensitive_keys);
    }

    usort($entries, static fn(array $a, array $b): int => strcmp((string) $a['path_label'], (string) $b['path_label']));

    $dropped = 0;
    if (count($entries) > MAX_ENTRIES) {
        $dropped = count($entries) - MAX_ENTRIES;
        $entries = array_slice($entries, offset: 0, length: MAX_ENTRIES);
    }

    $destroys = false;
    foreach ($entries as $entry) {
        if (($entry['op'] ?? '') === 'removed') {
            $destroys = true;
            break;
        }
    }

    return [
        'changed_count' => count($entries) + $dropped,
        'destroys' => $destroys,
        'truncated' => $dropped > 0,
        'dropped_count' => $dropped,
        'entries' => array_values($entries),
        'unpredicted' => [],
    ];
}

/**
 * The subtrees worth comparing, in a stable order.
 *
 * Everything else in a snapshot is bookkeeping: `type` is the discriminator,
 * `fingerprint` is derived, and `excluded_meta_keys` is reported separately
 * because a change there is an absence of data rather than a value.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @return list<string>
 */
function payload_keys(array $before, array $after): array
{
    $candidates = ['post', 'meta', 'terms', 'values'];
    $present = [];
    foreach ($candidates as $key) {
        if (array_key_exists($key, $before) || array_key_exists($key, $after)) {
            $present[] = $key;
        }
    }
    return $present;
}

/**
 * @param array<array-key, mixed> $before
 * @param array<array-key, mixed> $after
 * @param list<string> $path
 * @param list<array<string, mixed>> $entries
 * @param list<string> $sensitive_keys
 */
// @mago-expect lint:cyclomatic-complexity -- Each branch is one documented false-positive rule; collapsing them would hide which rule fired.
// @mago-expect lint:halstead -- Same: the rules are the function.
function walk(array $before, array $after, array $path, array &$entries, array $sensitive_keys): void
{
    if (count($path) > MAX_DEPTH) {
        return;
    }

    $keys = array_keys($before + $after);
    sort($keys);

    foreach ($keys as $key) {
        $key_string = (string) $key;
        $child_path = [...$path, $key_string];

        // A post column that WordPress rewrites on its own is not a change.
        if (count($path) === 1 && $path[0] === 'post' && is_volatile_post_field($key_string)) {
            continue;
        }

        $has_before = array_key_exists($key, $before);
        $has_after = array_key_exists($key, $after);
        /** @var mixed $left */
        $left = $before[$key] ?? null;
        /** @var mixed $right */
        $after_value = $after[$key] ?? null;

        // Two nested maps recurse. A list is compared whole, because a partial
        // list diff reports a shift as a rewrite of every index.
        if (is_array($left) && is_array($after_value) && is_map($left) && is_map($after_value)) {
            walk($left, $after_value, $child_path, $entries, $sensitive_keys);
            continue;
        }

        $left_normalized = normalize($left, $child_path);
        $right_normalized = normalize($after_value, $child_path);

        if ($has_before && $has_after && same($left_normalized, $right_normalized)) {
            continue;
        }

        $sensitive = path_is_sensitive($child_path);
        $entry = [
            'path' => $child_path,
            'path_label' => implode('.', $child_path),
            'op' => operation($has_before, $has_after),
            'redacted' => $sensitive,
            'projected' => true,
        ];

        // Checked before the generic redaction below, not after: every key in
        // excluded_meta_keys is by definition one wppilot_change_key_is_sensitive()
        // matched, so the generic branch would always win and this — the more
        // informative of the two — would be unreachable. The distinction matters:
        // "redacted" means the value exists and is being withheld, while this
        // means the before value was never captured, so no comparison happened
        // at all and claiming one would be a fabrication.
        if (in_array($key_string, $sensitive_keys, strict: true)) {
            $entry['op'] = 'unknown';
            $entry['reason'] = 'sensitive_key_not_captured';
            $entry['before'] = null;
            $entry['after'] = $has_after ? REDACTED : null;
            $entry['redacted'] = true;
            $entries[] = $entry;
            continue;
        }

        if ($sensitive) {
            $entry['before'] = $has_before ? REDACTED : null;
            $entry['after'] = $has_after ? REDACTED : null;
            $entries[] = $entry;
            continue;
        }

        $before_display = $has_before ? stringify($left_normalized) : null;
        $after_display = $has_after ? stringify($right_normalized) : null;

        $entry['before_bytes'] = $before_display === null ? 0 : strlen($before_display);
        $entry['after_bytes'] = $after_display === null ? 0 : strlen($after_display);
        $entry['value_truncated'] = false;

        if ($before_display !== null && strlen($before_display) > MAX_VALUE_BYTES) {
            $before_display = substr($before_display, offset: 0, length: MAX_VALUE_BYTES);
            $entry['value_truncated'] = true;
        }
        if ($after_display !== null && strlen($after_display) > MAX_VALUE_BYTES) {
            $after_display = substr($after_display, offset: 0, length: MAX_VALUE_BYTES);
            $entry['value_truncated'] = true;
        }

        $entry['before'] = $before_display;
        $entry['after'] = $after_display;
        $entries[] = $entry;
    }
}

function operation(bool $has_before, bool $has_after): string
{
    if (!$has_before) {
        return 'added';
    }
    if (!$has_after) {
        return 'removed';
    }
    return 'changed';
}

/**
 * Whether a post column is one WordPress moves on its own.
 *
 * Reads the ledger's list when it is loaded and falls back to the same values
 * otherwise, so the differ stays usable in a unit test that boots neither.
 */
function is_volatile_post_field(string $key): bool
{
    $volatile = defined('WPPILOT_VOLATILE_POST_FIELDS')
        ? (array) constant('WPPILOT_VOLATILE_POST_FIELDS')
        : ['post_modified', 'post_modified_gmt', 'guid', 'filter'];

    return in_array($key, array_map('strval', $volatile), strict: true);
}

/**
 * Whether any segment of a path names a credential-shaped field.
 *
 * @param list<string> $path
 */
function path_is_sensitive(array $path): bool
{
    if (!function_exists('wppilot_change_key_is_sensitive')) {
        return false;
    }
    foreach ($path as $segment) {
        if (\wppilot_change_key_is_sensitive($segment)) {
            return true;
        }
    }
    return false;
}

/**
 * True for an associative array — something worth recursing into.
 *
 * A list is a value here: post meta stores `key => [value]`, and object terms
 * are an unordered id set. Both are compared whole.
 *
 * @param array<array-key, mixed> $value
 */
function is_map(array $value): bool
{
    if ($value === []) {
        return false;
    }
    return array_keys($value) !== range(0, count($value) - 1);
}

/**
 * Put both sides into the shape the database would actually return.
 *
 * Post meta coerces scalars to strings on write, so 5 and '5' are one stored
 * value. Object terms are a set, so ordering carries no meaning and is removed
 * before comparison — otherwise an unrelated write reports churn across every
 * taxonomy.
 *
 * @param list<string> $path
 */
function normalize(mixed $value, array $path): mixed
{
    if (($path[0] ?? '') === 'terms' && is_array($value)) {
        $ids = array_map('intval', array_filter($value, 'is_scalar'));
        sort($ids);
        return $ids;
    }

    if (($path[0] ?? '') === 'meta') {
        // A single value and a one-element list are the same stored meta.
        $list = is_array($value) ? $value : [$value];
        $flat = [];
        foreach ($list as $item) {
            $flat[] = is_scalar($item) || $item === null ? scalar_to_string($item) : $item;
        }
        return $flat;
    }

    if (is_scalar($value) || $value === null) {
        return scalar_to_string($value);
    }

    return $value;
}

function scalar_to_string(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '';
    }
    return (string) $value;
}

function same(mixed $left, mixed $right): bool
{
    if (is_array($left) && is_array($right)) {
        return wp_json_encode($left) === wp_json_encode($right);
    }
    return $left === $right;
}

function stringify(mixed $value): string
{
    if (is_array($value)) {
        if (count($value) === 1 && (is_string($value[0] ?? null) || is_int($value[0] ?? null))) {
            return (string) $value[0];
        }
        return (string) wp_json_encode($value);
    }
    return scalar_to_string($value);
}
