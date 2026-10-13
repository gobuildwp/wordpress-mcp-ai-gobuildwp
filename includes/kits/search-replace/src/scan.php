<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SearchReplace;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\PostPartial;

if (!defined('ABSPATH')) {
    exit();
}

/** Posts one plan may hold. Apply writes them 100 per call, or in a background job. */
const MAX_PLAN_POSTS = 500;

/** Candidate posts one preview call reads; the rest is reached with after_id. */
const SCAN_LIMIT = 2000;

/** Post ids fetched per query while scanning. */
const SCAN_CHUNK = 200;

/** Seconds a preview or an apply batch works before it hands back a cursor. */
const TIME_BUDGET = 20.0;

/** post_ids a caller may name in one call. */
const MAX_POST_IDS = 500;

/** meta_keys a caller may name in one call. */
const MAX_META_PATTERNS = 20;

const DEFAULT_POST_TYPES = ['post', 'page'];

const STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

/** Post types that are history or plumbing, not content anyone means to rewrite. */
const DENIED_POST_TYPES = ['revision', 'customize_changeset', 'oembed_cache', 'user_request'];

/**
 * Meta that is WordPress or builder bookkeeping. Rewriting a lock, an old slug or a file path
 * changes nothing a visitor sees and can break the thing it tracks (a renamed _wp_attached_file
 * points at a file that is not there); caches are rebuilt from the real data instead.
 */
const PROTECTED_META = [
    '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_attached_file',
    '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_wp_trash_meta_status',
    '_wp_trash_meta_time', '_wp_desired_post_slug', '_encloseme', '_pingme',
    '_elementor_css', '_elementor_element_cache', '_elementor_page_assets',
];

/**
 * Page builders whose output is cached apart from the data search-replace changes, detected by a
 * meta key the builder writes on every page it manages.
 */
const BUILDERS = [
    'elementor' => ['label' => 'Elementor', 'markers' => ['_elementor_edit_mode', '_elementor_data']],
    'beaver-builder' => ['label' => 'Beaver Builder', 'markers' => ['_fl_builder_enabled', '_fl_builder_data']],
    'bricks' => ['label' => 'Bricks', 'markers' => ['_bricks_editor_mode', '_bricks_page_content_2']],
    'divi' => ['label' => 'Divi', 'markers' => ['_et_pb_use_builder']],
    'wpbakery' => ['label' => 'WPBakery', 'markers' => ['_wpb_vc_js_status']],
];

/**
 * Validate and normalise where a search runs.
 *
 * @param array<string, mixed> $input
 * @return array{post_types: list<string>, statuses: list<string>, post_ids: list<int>, fields: list<string>, meta_keys: list<string>, after_id: int}|WP_Error
 */
function scope(array $input): array|WP_Error
{
    $types = strings($input['post_types'] ?? DEFAULT_POST_TYPES);
    foreach ($types as $type) {
        if (in_array($type, DENIED_POST_TYPES, strict: true) || !post_type_exists($type)) {
            return new WP_Error('kit_sr_bad_post_type', sprintf('"%s" is not a post type search-replace can change.', $type), ['status' => 400]);
        }
    }
    $statuses = strings($input['statuses'] ?? STATUSES);
    foreach ($statuses as $status) {
        if (!in_array($status, STATUSES, strict: true)) {
            return new WP_Error('kit_sr_bad_status', sprintf('"%s" is not a status search-replace covers; use %s.', $status, implode(', ', STATUSES)), ['status' => 400]);
        }
    }
    $post_ids = [];
    foreach (is_array($input['post_ids'] ?? null) ? $input['post_ids'] : [] as $id) {
        if ((int) $id > 0) {
            $post_ids[(int) $id] = (int) $id;
        }
    }
    if (count($post_ids) > MAX_POST_IDS) {
        return new WP_Error('kit_sr_too_many_ids', sprintf('Name at most %d post_ids per call.', MAX_POST_IDS), ['status' => 400]);
    }
    $fields = array_key_exists('fields', $input) ? strings($input['fields']) : FIELDS;
    foreach ($fields as $field) {
        if (!in_array($field, FIELDS, strict: true)) {
            return new WP_Error('kit_sr_bad_field', sprintf('"%s" is not a field search-replace changes; use %s.', $field, implode(', ', FIELDS)), ['status' => 400]);
        }
    }
    $meta_keys = strings($input['meta_keys'] ?? []);
    if (count($meta_keys) > MAX_META_PATTERNS) {
        return new WP_Error('kit_sr_too_many_meta_keys', sprintf('Name at most %d meta keys or patterns.', MAX_META_PATTERNS), ['status' => 400]);
    }
    foreach ($meta_keys as $pattern) {
        if (preg_match('/^[A-Za-z0-9_\-:.*]{1,255}$/', $pattern) !== 1) {
            return new WP_Error('kit_sr_bad_meta_key', sprintf('"%s" is not a meta key or pattern; use letters, digits, _ - : . and * as a wildcard.', $pattern), ['status' => 400]);
        }
    }
    if ($types === [] || $statuses === [] || ($fields === [] && $meta_keys === [])) {
        return new WP_Error('kit_sr_empty_scope', 'The scope is empty: name at least one post type, status, and field or meta key.', ['status' => 400]);
    }

    return [
        'post_types' => $types,
        'statuses' => $statuses,
        'post_ids' => array_values($post_ids),
        'fields' => array_values($fields),
        'meta_keys' => $meta_keys,
        'after_id' => max(0, (int) ($input['after_id'] ?? 0)),
    ];
}

/**
 * @return list<string>
 */
function strings(mixed $value): array
{
    $out = [];
    foreach (is_array($value) ? $value : [] as $item) {
        if (is_string($item) && $item !== '') {
            $out[$item] = $item;
        }
    }
    return array_values($out);
}

function meta_key_in_scope(string $key, array $patterns): bool
{
    if (in_array($key, PROTECTED_META, strict: true)) {
        return false;
    }
    foreach ($patterns as $pattern) {
        if (preg_match('/^' . str_replace('\*', '.*', preg_quote((string) $pattern, '/')) . '$/', $key) === 1) {
            return true;
        }
    }
    return false;
}

/**
 * The next posts in scope, by ascending ID, after a cursor.
 *
 * A direct query because WP_Query has no "ID greater than", and paging by offset would skip or
 * repeat posts when any are added or trashed between one preview call and the next.
 *
 * @param array{post_types: list<string>, statuses: list<string>, post_ids: list<int>} $scope
 * @return list<int>
 */
function candidate_ids(array $scope, int $after_id, int $limit): array
{
    global $wpdb;
    $args = array_merge($scope['post_types'], $scope['statuses'], [$after_id]);
    $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN (" . placeholders(count($scope['post_types']), '%s') . ')'
        . ' AND post_status IN (' . placeholders(count($scope['statuses']), '%s') . ')'
        . ' AND ID > %d';
    if ($scope['post_ids'] !== []) {
        $sql .= ' AND ID IN (' . placeholders(count($scope['post_ids']), '%d') . ')';
        $args = array_merge($args, $scope['post_ids']);
    }
    $sql .= ' ORDER BY ID ASC LIMIT %d';
    $args[] = $limit;
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders() builds only %s / %d lists.
    $ids = $wpdb->get_col($wpdb->prepare($sql, $args));
    return array_map('intval', is_array($ids) ? $ids : []);
}

function placeholders(int $count, string $placeholder): string
{
    return implode(',', array_fill(0, max(1, $count), $placeholder));
}

/**
 * Meta values exactly as the database holds them, grouped by key, for keys in scope.
 *
 * Read raw rather than through get_post_meta(), which unserializes with every class allowed:
 * looking at a value must not instantiate whatever object a plugin stored in it.
 *
 * @param list<string> $patterns
 * @return array<string, list<string>>
 */
function raw_meta(int $post_id, array $patterns): array
{
    if ($patterns === []) {
        return [];
    }
    global $wpdb;
    $likes = [];
    $args = [$post_id];
    foreach ($patterns as $pattern) {
        $likes[] = 'meta_key LIKE %s';
        $args[] = implode('%', array_map([$wpdb, 'esc_like'], explode('*', $pattern)));
    }
    $sql = "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND (" . implode(' OR ', $likes) . ') ORDER BY meta_id ASC';
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only LIKE %s clauses are appended.
    $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    $grouped = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $key = (string) ($row['meta_key'] ?? '');
        // LIKE treats _ as a wildcard; the pattern match is the real filter.
        if ($key !== '' && meta_key_in_scope($key, $patterns)) {
            $grouped[$key][] = (string) ($row['meta_value'] ?? '');
        }
    }
    return $grouped;
}

/**
 * What the search changes in one post.
 *
 * `writes` carries the new values for apply; `changes` is the diff a person reviews.
 *
 * @param array{pattern: string, replace: string, regex: bool, unicode: bool} $matcher
 * @param array{fields: list<string>, meta_keys: list<string>} $scope
 * @return array{changes: list<array<string, mixed>>, fields: list<string>, meta_keys: list<string>, count: int, skipped: list<array<string, string>>, writes: array{fields: array<string, string>, meta: array<string, array{value: mixed, stored: string}>}}
 */
function analyze_post(\WP_Post $post, array $matcher, array $scope): array
{
    $out = ['changes' => [], 'fields' => [], 'meta_keys' => [], 'count' => 0, 'skipped' => [], 'writes' => ['fields' => [], 'meta' => []]];

    foreach ($scope['fields'] as $field) {
        $result = replace_string((string) $post->{$field}, $matcher);
        if ($result instanceof WP_Error) {
            if ($result->get_error_code() === 'kit_sr_regex_failed' || matches((string) $post->{$field}, $matcher)) {
                $out['skipped'][] = ['target' => $field, 'reason' => (string) $result->get_error_code(), 'message' => $result->get_error_message()];
            }
            continue;
        }
        if ($result['count'] === 0) {
            continue;
        }
        $out['fields'][] = $field;
        $out['count'] += $result['count'];
        $out['writes']['fields'][$field] = $result['value'];
        $out['changes'][] = [
            'target' => $field,
            'encoding' => 'text',
            'count' => $result['count'],
            'samples' => $result['samples'],
        ];
    }

    foreach (raw_meta((int) $post->ID, $scope['meta_keys']) as $key => $values) {
        $key = (string) $key;
        if (count($values) > 1) {
            foreach ($values as $raw) {
                if (matches($raw, $matcher)) {
                    $out['skipped'][] = [
                        'target' => 'meta:' . $key,
                        'reason' => 'kit_sr_multiple_values',
                        'message' => sprintf('This key holds %d values on the post; only single-value keys are rewritten.', count($values)),
                    ];
                    break;
                }
            }
            continue;
        }
        $result = replace_meta($values[0], $matcher);
        if ($result === null) {
            continue;
        }
        if (isset($result['skip'])) {
            $out['skipped'][] = ['target' => 'meta:' . $key, 'reason' => (string) $result['skip'], 'message' => (string) $result['message']];
            continue;
        }
        $out['meta_keys'][] = $key;
        $out['count'] += (int) $result['count'];
        $out['writes']['meta'][$key] = ['value' => $result['value'], 'stored' => (string) $result['stored']];
        $change = [
            'target' => 'meta:' . $key,
            'encoding' => (string) $result['encoding'],
            'count' => (int) $result['count'],
            'samples' => $result['samples'],
        ];
        if ($result['encoding'] === 'json') {
            $change['escaping_changes'] = (bool) $result['escaping_changes'];
        }
        $out['changes'][] = $change;
    }

    return $out;
}

/**
 * Builders managing a post, by the meta keys they leave on it.
 *
 * @return list<string>
 */
function builders_for(int $post_id): array
{
    $found = [];
    foreach (BUILDERS as $slug => $builder) {
        foreach ($builder['markers'] as $key) {
            if (metadata_exists('post', $post_id, $key)) {
                $found[] = $slug;
                break;
            }
        }
    }
    return $found;
}

/**
 * Size of a before-image as the ledger counts it against its snapshot budget.
 *
 * @param array<string, mixed> $snapshot
 */
function snapshot_bytes(array $snapshot): int
{
    $encoded = wp_json_encode($snapshot);
    return is_string($encoded) ? strlen($encoded) : PHP_INT_MAX;
}

/**
 * wppilot/search-replace-preview: scan, build a plan, store it, return the diff.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function preview(array $input): array|WP_Error
{
    if (is_string($input['plan_id'] ?? null) && $input['plan_id'] !== '') {
        $plan = load_plan($input['plan_id']);
        return $plan instanceof WP_Error ? $plan : present_plan($plan, $input);
    }

    $matcher = matcher($input);
    if ($matcher instanceof WP_Error) {
        return $matcher;
    }
    $scope = scope($input);
    if ($scope instanceof WP_Error) {
        return $scope;
    }
    sweep_plans();

    $budget = Runtime\host()->ledger()->snapshot_budget();
    $deadline = microtime(true) + TIME_BUDGET;
    $after = $scope['after_id'];
    $scanned = 0;
    $not_editable = 0;
    $items = [];
    $skipped = [];
    $builders = [];
    $complete = false;
    $stopped_by = null;

    while (true) {
        $limit = min(SCAN_CHUNK, SCAN_LIMIT - $scanned);
        if ($limit <= 0) {
            $stopped_by = 'scan_limit';
            break;
        }
        $ids = candidate_ids($scope, $after, $limit);
        foreach ($ids as $id) {
            if (count($items) >= MAX_PLAN_POSTS) {
                $stopped_by = 'plan_full';
                break 2;
            }
            if (microtime(true) >= $deadline) {
                $stopped_by = 'time';
                break 2;
            }
            $after = $id;
            $scanned++;
            // Checked before reading: the diff would otherwise show content of posts this user
            // cannot open in the editor.
            if (!current_user_can('edit_post', $id)) {
                $not_editable++;
                continue;
            }
            $post = get_post($id);
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $analysis = analyze_post($post, $matcher, $scope);
            foreach ($analysis['skipped'] as $skip) {
                $skipped[] = ['post_id' => $id] + $skip;
            }
            if ($analysis['changes'] === []) {
                continue;
            }
            $snapshot = PostPartial\capture($id, $analysis['fields'], $analysis['meta_keys']);
            if ($snapshot === null) {
                continue;
            }
            if (snapshot_bytes($snapshot) > $budget) {
                $skipped[] = [
                    'post_id' => $id,
                    'target' => 'post',
                    'reason' => 'kit_sr_too_large_to_undo',
                    'message' => 'The fields this change touches are larger than one call may keep for undo, so the post is left out rather than changed without a way back.',
                ];
                continue;
            }
            $post_builders = builders_for($id);
            foreach ($post_builders as $slug) {
                $builders[$slug][] = $id;
            }
            $items[] = [
                'post_id' => $id,
                'post_type' => (string) $post->post_type,
                'title' => (string) $post->post_title,
                'fields' => $analysis['fields'],
                'meta_keys' => $analysis['meta_keys'],
                'fingerprint' => (string) $snapshot['fingerprint'],
                'count' => $analysis['count'],
                'changes' => $analysis['changes'],
                'builders' => $post_builders,
                'status' => 'pending',
                'reason' => '',
            ];
        }
        if (count($ids) < $limit) {
            $complete = true;
            break;
        }
    }

    $totals = [
        'posts_scanned' => $scanned,
        'posts' => count($items),
        'matches' => array_sum(array_column($items, 'count')),
        'not_editable' => $not_editable,
        'skipped' => count($skipped),
    ];
    $cursor = [
        'complete' => $complete,
        'stopped_by' => $complete ? null : $stopped_by,
        'next_after_id' => $complete ? null : $after,
    ];

    if ($items === []) {
        return [
            'plan_id' => null,
            'totals' => $totals,
            'posts' => [],
            'skipped' => array_slice($skipped, 0, 100),
            'caches' => [],
            'notes' => [],
        ] + $cursor;
    }

    $now = time();
    $plan = [
        'id' => new_plan_id(),
        'owner' => get_current_user_id(),
        'created_at' => $now,
        'expires_at' => $now + PLAN_TTL,
        'group' => wp_generate_uuid4(),
        'search' => $matcher['search'],
        'replace' => $matcher['replace'],
        'regex' => $matcher['regex'],
        'case_sensitive' => $matcher['case_sensitive'],
        'scope' => ['post_types' => $scope['post_types'], 'statuses' => $scope['statuses'], 'fields' => $scope['fields'], 'meta_keys' => $scope['meta_keys']],
        'items' => $items,
        'builders' => $builders,
        'skipped' => array_slice($skipped, 0, 200),
        'totals' => $totals,
        'cursor' => $cursor,
        'job_id' => '',
    ];
    save_plan($plan);

    return present_plan($plan, $input);
}

/**
 * The reviewable view of a stored plan, one page of posts at a time.
 *
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $input `diff_offset`, `diff_limit`.
 * @return array<string, mixed>
 */
function present_plan(array $plan, array $input): array
{
    /** @var list<array<string, mixed>> $items */
    $items = $plan['items'];
    $offset = max(0, (int) ($input['diff_offset'] ?? 0));
    $limit = min(100, max(1, (int) ($input['diff_limit'] ?? 50)));
    $page = [];
    $json = false;
    $escaping = false;
    foreach ($items as $item) {
        foreach ($item['changes'] as $change) {
            if (($change['encoding'] ?? '') === 'json') {
                $json = true;
                $escaping = $escaping || ($change['escaping_changes'] ?? false) === true;
            }
        }
    }
    foreach (array_slice($items, $offset, $limit) as $item) {
        $page[] = [
            'post_id' => $item['post_id'],
            'post_type' => $item['post_type'],
            'title' => $item['title'],
            'fingerprint' => $item['fingerprint'],
            'matches' => $item['count'],
            'status' => $item['status'],
            'changes' => $item['changes'],
        ];
    }
    $next = $offset + count($page);

    $notes = [];
    if ($json) {
        $notes[] = $escaping
            ? 'Some JSON values (such as Elementor data) cannot be re-encoded byte for byte: after the change their escaping of slashes or non-ASCII characters may differ from what is stored now. The data means the same; only its spelling changes.'
            : 'JSON values (such as Elementor data) are decoded, changed and re-encoded with the escaping they already use.';
    }

    $pending = count(array_filter($items, static fn(array $item): bool => $item['status'] === 'pending'));
    return [
        'plan_id' => $plan['id'],
        'expires_at' => gmdate('c', (int) $plan['expires_at']),
        'group' => $plan['group'],
        'search' => $plan['search'],
        'replace' => $plan['replace'],
        'regex' => $plan['regex'],
        'case_sensitive' => $plan['case_sensitive'],
        'totals' => $plan['totals'] + ['pending' => $pending],
        'posts' => $page,
        'diff_offset' => $offset,
        'next_diff_offset' => $next < count($items) ? $next : null,
        'skipped' => array_slice((array) $plan['skipped'], 0, 100),
        'caches' => caches_to_clear((array) $plan['builders'], false),
        'notes' => $notes,
    ] + (array) $plan['cursor'];
}
