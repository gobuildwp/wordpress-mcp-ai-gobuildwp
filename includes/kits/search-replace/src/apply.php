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

const APPLY_ABILITY = 'wppilot/search-replace-apply';

/** Posts one apply call (or one job step) writes at most. */
const MAX_POSTS_PER_CALL = 100;

const CANCEL_ABILITY = 'wppilot/search-replace-cancel';

/** The Runner job kind that applies a plan in the background. */
const JOB_KIND = 'wppilot_kit_search_replace';

/**
 * wppilot/search-replace-apply.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function apply(array $input): array|WP_Error
{
    $confirmed = Runtime\confirm_guard(APPLY_ABILITY, $input);
    if ($confirmed instanceof WP_Error) {
        return $confirmed;
    }
    $plan_id = is_string($input['plan_id'] ?? null) ? $input['plan_id'] : '';
    $subset = [];
    foreach (is_array($input['post_ids'] ?? null) ? $input['post_ids'] : [] as $id) {
        if ((int) $id > 0) {
            $subset[(int) $id] = (int) $id;
        }
    }
    $subset = array_values($subset);

    if (($input['background'] ?? false) === true) {
        return enqueue($plan_id, $subset);
    }
    return run_batch($plan_id, $subset);
}

/**
 * Apply up to MAX_POSTS_PER_CALL pending posts of a plan.
 *
 * Every post is written only after its partial before-image is taken and its fingerprint still
 * matches the preview, and every written post gets its own ledger row in the plan's group — so one
 * post, or the whole plan across all its calls, can be undone. The batch stops, leaving the rest
 * pending, as soon as the next before-image would not fit the ledger's snapshot budget for one
 * call: the ledger would record that post without a before-image, and a write nobody can undo is
 * exactly what this ability must never make.
 *
 * @param list<int> $subset Only these posts of the plan; empty for all.
 * @return array<string, mixed>|WP_Error
 */
function run_batch(string $plan_id, array $subset = []): array|WP_Error
{
    $plan = load_plan($plan_id);
    if ($plan instanceof WP_Error) {
        return $plan;
    }
    if (!lock_plan($plan_id)) {
        return new WP_Error('kit_sr_plan_busy', 'Another call is applying this plan right now; check search-replace-status, then retry.', ['status' => 409]);
    }

    $ledger = Runtime\host()->ledger();
    $records = [];
    $written = [];
    $skipped = [];
    $stopped_by = null;
    try {
        // Re-read under the lock: the plan read above may predate a batch that just finished.
        $plan = load_plan($plan_id);
        if ($plan instanceof WP_Error) {
            return $plan;
        }
        $matcher = matcher($plan);
        if ($matcher instanceof WP_Error) {
            return $matcher;
        }
        $wanted = $subset !== [] ? array_flip($subset) : null;
        $budget = $ledger->snapshot_budget();
        $used = 0;
        $processed = 0;
        $deadline = microtime(true) + TIME_BUDGET;

        try {
            foreach ($plan['items'] as $index => $item) {
                if ($item['status'] !== 'pending' || ($wanted !== null && !isset($wanted[$item['post_id']]))) {
                    continue;
                }
                if ($processed >= MAX_POSTS_PER_CALL) {
                    $stopped_by = 'batch_limit';
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $stopped_by = 'time';
                    break;
                }
                $outcome = apply_item($item, $matcher, $budget, $used, (array) $plan);
                if ($outcome['status'] === 'stop') {
                    $stopped_by = 'snapshot_budget';
                    break;
                }
                $processed++;
                $plan['items'][$index]['status'] = $outcome['status'];
                $plan['items'][$index]['reason'] = $outcome['reason'];
                if (isset($outcome['record'])) {
                    $records[] = $outcome['record'];
                    $written[] = $outcome['report'];
                } else {
                    $skipped[] = ['post_id' => $item['post_id'], 'title' => $item['title'], 'reason' => $outcome['reason'], 'message' => $outcome['message']];
                }
            }
        } finally {
            // Recorded even when a write throws: every post already written has a row, and an
            // empty list still closes the ledger's aggregate row for this call, which would
            // otherwise be filed as a change with no before-image.
            $recorded = $ledger->record_items(APPLY_ABILITY, $records, (string) $plan['group']);
            $plan['expires_at'] = max((int) $plan['expires_at'], time() + PLAN_TTL);
            save_plan($plan);
        }
    } finally {
        unlock_plan($plan_id);
    }

    foreach ($written as $position => $report) {
        $written[$position]['change_id'] = (string) ($recorded['change_ids'][$position] ?? '');
    }
    $remaining = 0;
    foreach ($plan['items'] as $item) {
        if ($item['status'] === 'pending' && ($wanted === null || isset($wanted[$item['post_id']]))) {
            $remaining++;
        }
    }
    $builders = [];
    foreach ($written as $report) {
        foreach ($report['builders'] as $slug) {
            $builders[$slug][] = $report['post_id'];
        }
    }
    $written = array_map(static function (array $report): array {
        unset($report['builders']);
        return $report;
    }, $written);

    return [
        'plan_id' => $plan_id,
        'group' => (string) $plan['group'],
        'applied' => count($written),
        'posts' => $written,
        'skipped' => $skipped,
        'remaining' => $remaining,
        'stopped_by' => $remaining > 0 ? $stopped_by : null,
        'cursor' => $remaining > 0 ? ['plan_id' => $plan_id, 'post_ids' => $subset] : null,
        'total' => $wanted === null ? count($plan['items']) : count(array_filter($plan['items'], static fn(array $item): bool => isset($wanted[$item['post_id']]))),
        'without_before_image' => (int) ($recorded['without_before_image'] ?? 0),
        'caches' => $written === [] ? [] : caches_to_clear($builders, true),
        'expires_at' => gmdate('c', (int) $plan['expires_at']),
    ];
}

/**
 * Write one planned post, or say why not.
 *
 * @param array<string, mixed> $item
 * @param array{pattern: string, replace: string, regex: bool, unicode: bool} $matcher
 * @param array<string, mixed> $plan
 * @return array{status: string, reason: string, message: string, record?: array<string, mixed>, report?: array<string, mixed>}
 */
function apply_item(array $item, array $matcher, int $budget, int &$used, array $plan): array
{
    $id = (int) $item['post_id'];
    $skip = static fn(string $reason, string $message): array => ['status' => 'skipped', 'reason' => $reason, 'message' => $message];

    if (!current_user_can('edit_post', $id)) {
        return $skip('kit_sr_cannot_edit', 'You cannot edit this post.');
    }
    $post = get_post($id);
    $snapshot = $post instanceof \WP_Post ? PostPartial\capture($id, $item['fields'], $item['meta_keys']) : null;
    if ($snapshot === null) {
        return $skip('kit_sr_post_missing', 'The post no longer exists.');
    }
    if (!hash_equals((string) $item['fingerprint'], (string) $snapshot['fingerprint'])) {
        return $skip('kit_sr_changed_since_preview', 'The post changed after the preview, so the reviewed diff no longer describes it. Preview it again.');
    }
    $bytes = snapshot_bytes($snapshot);
    if ($bytes > $budget) {
        return $skip('kit_sr_too_large_to_undo', 'The before-image is larger than one call may keep, so the post was not changed.');
    }
    if ($used + $bytes > $budget) {
        return ['status' => 'stop', 'reason' => '', 'message' => ''];
    }

    $analysis = analyze_post($post, $matcher, ['fields' => $item['fields'], 'meta_keys' => $item['meta_keys']]);
    if ($analysis['changes'] === [] || $analysis['fields'] !== $item['fields'] || $analysis['meta_keys'] !== $item['meta_keys']) {
        return $skip('kit_sr_changed_since_preview', 'The post no longer matches the way the preview found it. Preview it again.');
    }

    $used += $bytes;
    $write = write_post($id, $analysis['writes']);
    if ($write['written'] === []) {
        return ['status' => 'failed', 'reason' => 'kit_sr_write_failed', 'message' => implode(' ', $write['errors'])];
    }

    return [
        'status' => $write['errors'] === [] ? 'applied' : 'partial',
        'reason' => $write['errors'] === [] ? '' : 'kit_sr_write_failed',
        'message' => implode(' ', $write['errors']),
        'record' => [
            'input' => [
                'plan_id' => (string) $plan['id'],
                'post_id' => $id,
                'search' => (string) $plan['search'],
                'replace' => (string) $plan['replace'],
                'regex' => (bool) $plan['regex'],
            ],
            'before' => $snapshot,
            'result' => ['post_id' => $id, 'targets' => $write['written'], 'matches' => $analysis['count'], 'verified' => $write['verified']],
            'item' => ['post_id' => $id, 'title' => (string) $item['title'], 'post_type' => (string) $item['post_type']],
        ],
        'report' => [
            'post_id' => $id,
            'title' => (string) $item['title'],
            'matches' => $analysis['count'],
            'targets' => $write['written'],
            'verified' => $write['verified'],
            'warnings' => array_merge($write['errors'], $write['warnings']),
            'builders' => (array) ($item['builders'] ?? []),
        ],
    ];
}

/**
 * Write meta first, then post fields, and read each back.
 *
 * Meta goes first because wp_update_post() fires save_post, and a builder hooked there should see
 * the data it is about to render, not the old copy.
 *
 * @param array{fields: array<string, string>, meta: array<string, array{value: mixed, stored: string}>} $writes
 * @return array{written: list<string>, errors: list<string>, warnings: list<string>, verified: bool}
 */
function write_post(int $id, array $writes): array
{
    $written = [];
    $errors = [];
    $warnings = [];
    $verified = true;

    foreach ($writes['meta'] as $key => $write) {
        // update_post_meta() unslashes; builder JSON loses every backslash without wp_slash().
        update_post_meta($id, (string) $key, wp_slash($write['value']));
        $stored = raw_meta_value($id, (string) $key);
        if ($stored === $write['stored']) {
            $written[] = 'meta:' . $key;
            continue;
        }
        $verified = false;
        if ($stored === null) {
            $errors[] = sprintf('meta %s could not be written.', $key);
        } else {
            // Something between the call and the table (sanitize_meta, a plugin filter) changed
            // the value; it was written, but not byte for byte as previewed.
            $written[] = 'meta:' . $key;
            $warnings[] = sprintf('meta %s was stored differently from the preview (a filter changed it).', $key);
        }
    }

    if ($writes['fields'] !== []) {
        $postarr = ['ID' => $id];
        foreach ($writes['fields'] as $field => $value) {
            $postarr[$field] = wp_slash($value);
        }
        // kit-lint: slashed — every field value was wp_slash()ed as $postarr was built.
        $result = wp_update_post($postarr, true);
        if ($result instanceof WP_Error || (int) $result === 0) {
            $verified = false;
            $errors[] = 'Post fields could not be saved: ' . ($result instanceof WP_Error ? $result->get_error_message() : 'wp_update_post() returned 0.');
        } else {
            $post = get_post($id);
            foreach ($writes['fields'] as $field => $value) {
                $written[] = $field;
                if (!$post instanceof \WP_Post || (string) $post->{$field} !== $value) {
                    // Most often kses: a user without unfiltered_html saves filtered content,
                    // exactly as they would in the editor.
                    $verified = false;
                    $warnings[] = sprintf('%s was saved, but WordPress filtered it on save, so it differs from the preview.', $field);
                }
            }
        }
    }

    return ['written' => $written, 'errors' => $errors, 'warnings' => $warnings, 'verified' => $verified];
}

/**
 * One meta value exactly as stored, or null when the key is absent.
 */
function raw_meta_value(int $post_id, string $key): ?string
{
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1", $post_id, $key));
    return is_string($value) ? $value : null;
}

/**
 * Hand the rest of a plan to the jobs runner.
 *
 * @param list<int> $subset
 * @return array<string, mixed>|WP_Error
 */
function enqueue(string $plan_id, array $subset): array|WP_Error
{
    $plan = load_plan($plan_id);
    if ($plan instanceof WP_Error) {
        return $plan;
    }
    if (!lock_plan($plan_id)) {
        return new WP_Error('kit_sr_plan_busy', 'Another call is applying this plan right now; check search-replace-status, then retry.', ['status' => 409]);
    }
    try {
        $job_id = Runtime\host()->jobs()->enqueue(JOB_KIND, ['plan_id' => $plan_id, 'post_ids' => $subset]);
        if ($job_id instanceof WP_Error) {
            return $job_id;
        }
        $plan['job_id'] = $job_id;
        $plan['expires_at'] = max((int) $plan['expires_at'], time() + JOB_PLAN_TTL);
        save_plan($plan);
    } finally {
        unlock_plan($plan_id);
    }
    // No rows from this call: the job records one per post as it writes. This closes the ledger's
    // aggregate row for the call, which would otherwise read as an undoable-looking empty change.
    Runtime\host()->ledger()->record_items(APPLY_ABILITY, [], (string) $plan['group']);

    $pending = count(array_filter($plan['items'], static fn(array $item): bool => $item['status'] === 'pending'));
    return [
        'plan_id' => $plan_id,
        'group' => (string) $plan['group'],
        'job_id' => $job_id,
        'status' => 'queued',
        'pending' => $pending,
        'poll' => ['ability' => 'wppilot/search-replace-status', 'input' => ['job_id' => $job_id]],
        'message' => 'Queued. The job applies the plan 100 posts per step in the background; poll search-replace-status with job_id until status is done or failed.',
    ];
}

/**
 * One step of a background apply. The Runner calls it as the user who queued the job.
 *
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $state
 * @return array{state: array<string, mixed>, done: bool, progress: float, message: string}
 */
function job_step(array $payload, array $state): array
{
    $subset = array_values(array_filter(array_map('intval', is_array($payload['post_ids'] ?? null) ? $payload['post_ids'] : []), static fn(int $id): bool => $id > 0));
    $result = run_batch((string) ($payload['plan_id'] ?? ''), $subset);
    if ($result instanceof WP_Error) {
        if ($result->get_error_code() === 'kit_sr_plan_busy') {
            // A foreground apply holds the plan; wait for it rather than fail approved work.
            usleep(500_000);
            return ['state' => $state, 'done' => false, 'progress' => (float) ($state['progress'] ?? 0.0), 'message' => 'Waiting for another call on this plan to finish.'];
        }
        throw new \RuntimeException($result->get_error_message());
    }
    $state['applied'] = (int) ($state['applied'] ?? 0) + (int) $result['applied'];
    $state['skipped'] = (int) ($state['skipped'] ?? 0) + count($result['skipped']);
    $state['remaining'] = (int) $result['remaining'];
    $total = max(1, (int) $result['total']);
    $state['progress'] = 1.0 - ((int) $result['remaining'] / $total);
    $done = (int) $result['remaining'] === 0 || ((int) $result['applied'] === 0 && $result['skipped'] === [] && $result['stopped_by'] === null);

    return [
        'state' => $state,
        'done' => $done,
        'progress' => (float) $state['progress'],
        'message' => sprintf('%d applied, %d skipped, %d remaining.', $state['applied'], $state['skipped'], $state['remaining']),
    ];
}

/**
 * wppilot/search-replace-cancel: stop a background apply the current user queued.
 *
 * The Runner re-reads the job between steps, so a step already running finishes its batch of at
 * most MAX_POSTS_PER_CALL posts; nothing after it starts. Posts already written keep their own
 * undoable rows, and posts not reached stay pending in the plan, so applying the plan again picks
 * up where the job stopped.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function cancel(array $input): array|WP_Error
{
    $job_id = is_string($input['job_id'] ?? null) ? $input['job_id'] : '';
    $jobs = Runtime\host()->jobs();
    $job = $job_id === '' ? null : $jobs->get($job_id);
    // Someone else's job answers exactly like a missing one, so ids cannot be probed.
    if ($job === null || ($job['kind'] ?? '') !== JOB_KIND || (int) ($job['owner'] ?? 0) !== get_current_user_id()) {
        return new WP_Error('kit_sr_job_not_found', 'No search-replace job of yours has that id.', ['status' => 404]);
    }
    $was = (string) $job['status'];
    $cancelled = $jobs->cancel($job_id);
    // The job record is the only thing a cancel changes, and the Runner has no way back from
    // cancelled, so the ledger row says so instead of implying an undo.
    Runtime\host()->ledger()->record_items(CANCEL_ABILITY, [[
        'input' => ['job_id' => $job_id],
        'before' => null,
        'result' => ['cancelled' => $cancelled],
        'irreversible_reason' => 'Cancelling changes only the background job record, and a cancelled job cannot be resumed. '
            . 'Posts it already wrote keep their own undoable rows; apply the plan again to continue.',
    ]]);

    return [
        'job_id' => $job_id,
        'cancelled' => $cancelled,
        'status' => $cancelled ? 'cancelled' : $was,
        'plan_id' => (string) ($job['payload']['plan_id'] ?? ''),
        'message' => $cancelled
            ? 'Cancelled. A step already running finishes its batch first; posts written so far stay written and undoable. Check search-replace-status for the plan state.'
            : sprintf('The job had already finished (%s); nothing to cancel.', $was),
    ];
}

/**
 * wppilot/search-replace-status: a background apply's progress, and a plan's per-post state.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function status(array $input): array|WP_Error
{
    $job_id = is_string($input['job_id'] ?? null) ? $input['job_id'] : '';
    $plan_id = is_string($input['plan_id'] ?? null) ? $input['plan_id'] : '';
    if ($job_id === '' && $plan_id === '') {
        return new WP_Error('kit_sr_status_needs_id', 'Pass job_id, plan_id, or both.', ['status' => 400]);
    }
    $out = [];
    if ($job_id !== '') {
        $job = Runtime\host()->jobs()->get($job_id);
        if ($job === null || ($job['kind'] ?? '') !== JOB_KIND || (int) ($job['owner'] ?? 0) !== get_current_user_id()) {
            return new WP_Error('kit_sr_job_not_found', 'No search-replace job of yours has that id.', ['status' => 404]);
        }
        $state = is_array($job['state'] ?? null) ? $job['state'] : [];
        $out['job'] = [
            'id' => (string) $job['id'],
            'status' => (string) $job['status'],
            'progress' => (float) ($job['progress'] ?? 0.0),
            'message' => (string) ($job['message'] ?? ''),
            'applied' => (int) ($state['applied'] ?? 0),
            'skipped' => (int) ($state['skipped'] ?? 0),
            'updated_at' => gmdate('c', (int) ($job['updated_at'] ?? 0)),
        ];
        if ($plan_id === '') {
            $plan_id = (string) ($job['payload']['plan_id'] ?? '');
        }
    }
    $plan = load_plan($plan_id);
    if ($plan instanceof WP_Error) {
        if ($out === []) {
            return $plan;
        }
        $out['plan'] = null;
        $out['plan_error'] = $plan->get_error_message();
        return $out;
    }
    $counts = ['pending' => 0, 'applied' => 0, 'partial' => 0, 'skipped' => 0, 'failed' => 0];
    $not_applied = [];
    foreach ($plan['items'] as $item) {
        $counts[$item['status']] = ($counts[$item['status']] ?? 0) + 1;
        if (in_array($item['status'], ['skipped', 'failed', 'partial'], strict: true) && count($not_applied) < 100) {
            $not_applied[] = ['post_id' => $item['post_id'], 'title' => $item['title'], 'status' => $item['status'], 'reason' => $item['reason']];
        }
    }
    $out['plan'] = [
        'plan_id' => (string) $plan['id'],
        'group' => (string) $plan['group'],
        'expires_at' => gmdate('c', (int) $plan['expires_at']),
        'posts' => count($plan['items']),
        'counts' => $counts,
        'problems' => $not_applied,
        'job_id' => (string) ($plan['job_id'] ?? ''),
    ];
    return $out;
}

/**
 * Builder caches that hold a rendered copy of what changed.
 *
 * Cleared only through the builder's own API, and only after checking that API is there; a
 * builder whose API was not verified is reported with what the person should do instead.
 *
 * @param array<string, list<int>> $builders slug => post ids.
 * @return list<array<string, mixed>>
 */
function caches_to_clear(array $builders, bool $clear): array
{
    $out = [];
    foreach ($builders as $slug => $ids) {
        $ids = array_values(array_unique(array_map('intval', (array) $ids)));
        $label = (string) (BUILDERS[$slug]['label'] ?? $slug);
        $entry = ['builder' => $slug, 'label' => $label, 'post_ids' => $ids, 'cleared' => false];
        switch ($slug) {
            case 'elementor':
                $entry['action'] = 'Regenerate Elementor CSS and element cache (Elementor > Tools > Regenerate CSS & Data).';
                if ($clear && class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance)) {
                    $files = \Elementor\Plugin::$instance->files_manager ?? null;
                    if (is_object($files) && method_exists($files, 'clear_cache')) {
                        $files->clear_cache();
                        $entry['cleared'] = true;
                    }
                }
                break;
            case 'beaver-builder':
                $entry['action'] = 'Clear the Beaver Builder asset cache for these posts (Settings > Beaver Builder > Tools > Clear Cache).';
                if ($clear && class_exists('\FLBuilderModel') && method_exists('\FLBuilderModel', 'delete_all_asset_cache')) {
                    foreach ($ids as $id) {
                        \FLBuilderModel::delete_all_asset_cache($id);
                    }
                    $entry['cleared'] = true;
                }
                break;
            case 'bricks':
                $entry['action'] = 'If Bricks loads CSS from external files, regenerate them (Bricks > Settings > Performance).';
                break;
            case 'divi':
                $entry['action'] = 'Clear Divi\'s static CSS (Divi > Theme Options > Builder > Advanced > Static CSS File Generation).';
                break;
            case 'wpbakery':
                $entry['action'] = 'Open one changed page in WPBakery to confirm it renders; WPBakery keeps no separate content cache this tool knows of.';
                break;
        }
        $out[] = $entry;
    }
    if ($builders !== [] || $clear) {
        $out[] = [
            'builder' => 'page-cache',
            'label' => 'Page cache',
            'post_ids' => [],
            'cleared' => false,
            'action' => 'Purge any page cache (caching plugin, host or CDN) for the changed pages, or visitors keep seeing the old text.',
        ];
    }
    return $out;
}
