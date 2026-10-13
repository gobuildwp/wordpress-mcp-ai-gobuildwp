<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ContentAudit;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

/** The job kind this kit registers with the host's jobs runner. */
const JOB_KIND = 'content-audit';

/** Recent audit jobs, so the status ability can list them without an ID. */
const JOBS_OPTION = 'wppilot_kit_content_audit_jobs';

/** Posts one background step audits before saving its state. */
const JOB_BATCH = 20;

/** Posts one synchronous page audits at most. */
const PAGE_LIMIT = 100;

/**
 * The site the audit reads. Replaceable so tests can hand in a fake.
 */
function source(?Source $set = null): Source
{
    /** @var Source|null $source */
    static $source = null;
    if ($set !== null) {
        $source = $set;
    }
    return $source ??= new WpSource();
}

/**
 * Who is asking: their user ID, and whether they administer the site. Replaceable in tests.
 *
 * @param (callable(): array{id: int, admin: bool})|null $set
 * @return array{id: int, admin: bool}
 */
function viewer(?callable $set = null): array
{
    /** @var callable|null $override */
    static $override = null;
    if ($set !== null) {
        $override = $set;
    }
    if ($override !== null) {
        return $override();
    }
    return ['id' => get_current_user_id(), 'admin' => current_user_can('manage_options')];
}

/**
 * wppilot/audit-content.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function audit(array $input): array|WP_Error
{
    $mode = (string) ($input['mode'] ?? '');
    if ($mode === '') {
        // One post is quick enough to answer inline; a whole site is not.
        $mode = (int) ($input['post_id'] ?? 0) > 0 ? 'page' : 'background';
    }
    return $mode === 'page' ? audit_page($input) : audit_background($input);
}

/**
 * One page of posts, or one post, audited inline.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function audit_page(array $input): array|WP_Error
{
    if (!isset($input['schema_sample'])) {
        // Each sampled page is an HTTP round trip inside this one call.
        $input['schema_sample'] = 3;
    }
    $options = Auditor::options($input);
    $site = source();
    $auditor = new Auditor($site, $options);
    $post_id = (int) ($input['post_id'] ?? 0);

    if ($post_id > 0) {
        $post = $site->post($post_id);
        if ($post === null || $site->post_status($post_id) !== 'publish') {
            return new WP_Error('kit_content_audit_not_published', 'The audit reads published content only; that post is not published.', ['status' => 404]);
        }
        $state = $auditor->finish($auditor->scan([$post_id], $auditor->start(1)), false);
        return ['mode' => 'page', 'post_id' => $post_id, 'cursor' => null, 'next_cursor' => null] + $auditor->report($state, 0, Auditor::MAX_FINDINGS);
    }

    $cursor = max(0, (int) ($input['cursor'] ?? 0));
    $limit = max(1, min(PAGE_LIMIT, (int) ($input['limit'] ?? 25)));
    $ids = $site->published_ids($options['post_types'], $cursor, $limit);
    $last = $ids === [] ? $cursor : (int) end($ids);
    $more = $ids !== [] && $site->published_ids($options['post_types'], $last, 1) !== [];

    // The schema sample is spread over this page, not the whole site.
    $state = $auditor->start(count($ids));
    $state['total'] = $site->count_published($options['post_types']);
    $state = $auditor->scan($ids, $state);
    // Orphans need every post: only a first page that holds the whole site can decide them.
    $state = $auditor->finish($state, $cursor === 0 && !$more);

    return [
        'mode' => 'page',
        'cursor' => $cursor,
        'next_cursor' => $more ? $last : null,
    ] + $auditor->report($state, 0, Auditor::MAX_FINDINGS);
}

/**
 * The whole site, as a background job.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function audit_background(array $input): array|WP_Error
{
    $options = Auditor::options($input);
    $id = Runtime\host()->jobs()->enqueue(JOB_KIND, ['options' => $options]);
    if ($id instanceof WP_Error) {
        return $id;
    }
    remember_job($id);
    return [
        'mode' => 'background',
        'job_id' => $id,
        'status' => 'queued',
        'options' => $options,
        'next' => 'Call wppilot/audit-content-status with this job_id until status is "done"; it returns findings so far on every call.',
    ];
}

/**
 * One step of the background audit; registered with the host's jobs runner.
 *
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $state
 * @return array{state: array<string, mixed>, done: bool, progress: float, message: string}
 */
function step(array $payload, array $state): array
{
    $options = Auditor::options(is_array($payload['options'] ?? null) ? $payload['options'] : []);
    $site = source();
    $auditor = new Auditor($site, $options);
    if ($state === []) {
        $state = $auditor->start($site->count_published($options['post_types']));
    }
    $ids = $site->published_ids($options['post_types'], (int) $state['cursor'], JOB_BATCH);
    if ($ids === []) {
        $state = $auditor->finish($state, true);
        $counts = is_array($state['counts']['by_severity'] ?? null) ? $state['counts']['by_severity'] : [];
        return [
            'state' => $state,
            'done' => true,
            'progress' => 1.0,
            'message' => sprintf('Scanned %d posts: %d high, %d medium, %d low, %d info.', (int) $state['scanned'], (int) ($counts['high'] ?? 0), (int) ($counts['medium'] ?? 0), (int) ($counts['low'] ?? 0), (int) ($counts['info'] ?? 0)),
        ];
    }
    $state = $auditor->scan($ids, $state);
    $total = max(1, (int) $state['total']);
    return [
        'state' => $state,
        'done' => false,
        'progress' => min(0.99, (int) $state['scanned'] / $total),
        'message' => sprintf('Scanned %d of about %d posts.', (int) $state['scanned'], $total),
    ];
}

/**
 * wppilot/audit-content-status.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function status(array $input): array|WP_Error
{
    $job_id = (string) ($input['job_id'] ?? '');
    if ($job_id === '') {
        return ['jobs' => recent_jobs()];
    }
    $job = Runtime\host()->jobs()->get($job_id);
    if ($job === null || ($job['kind'] ?? '') !== JOB_KIND) {
        return new WP_Error('kit_content_audit_job_not_found', 'No content audit job has that ID. Finished jobs are kept for 7 days.', ['status' => 404]);
    }
    if (!may_read_job($job)) {
        return new WP_Error('kit_content_audit_job_forbidden', 'That audit was started by another user.', ['status' => 403]);
    }
    $base = [
        'job_id' => $job_id,
        'status' => (string) ($job['status'] ?? ''),
        'progress' => (float) ($job['progress'] ?? 0),
        'message' => (string) ($job['message'] ?? ''),
        'created_at' => gmdate('c', (int) ($job['created_at'] ?? 0)),
        'updated_at' => gmdate('c', (int) ($job['updated_at'] ?? 0)),
    ];
    $state = is_array($job['state'] ?? null) ? $job['state'] : [];
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    if ($state === []) {
        return $base + ['findings' => [], 'total_findings' => 0, 'next_offset' => null];
    }
    $auditor = new Auditor(source(), Auditor::options(is_array($payload['options'] ?? null) ? $payload['options'] : []));
    $filters = [
        'severity' => (string) ($input['severity'] ?? ''),
        'type' => (string) ($input['type'] ?? ''),
    ];
    return $base + $auditor->report(
        $state,
        max(0, (int) ($input['offset'] ?? 0)),
        max(1, min(500, (int) ($input['limit'] ?? 200))),
        $filters,
    );
}

/**
 * @param array<string, mixed> $job
 */
function may_read_job(array $job): bool
{
    $viewer = viewer();
    return (int) ($job['owner'] ?? 0) === $viewer['id'] || $viewer['admin'];
}

function remember_job(string $id): void
{
    /** @var mixed $stored */
    $stored = get_option(JOBS_OPTION, []);
    $jobs = is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
    $jobs[] = ['id' => $id, 'owner' => viewer()['id'], 'created_at' => time()];
    update_option(JOBS_OPTION, array_slice($jobs, -20), false);
}

/**
 * The current user's recent audits, newest first.
 *
 * @return list<array{job_id: string, status: string, progress: float, message: string, created_at: string}>
 */
function recent_jobs(): array
{
    /** @var mixed $stored */
    $stored = get_option(JOBS_OPTION, []);
    $listed = [];
    foreach (array_reverse(is_array($stored) ? $stored : []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $job = Runtime\host()->jobs()->get((string) ($entry['id'] ?? ''));
        if ($job === null || !may_read_job($job)) {
            continue;
        }
        $listed[] = [
            'job_id' => (string) $entry['id'],
            'status' => (string) ($job['status'] ?? ''),
            'progress' => (float) ($job['progress'] ?? 0),
            'message' => (string) ($job['message'] ?? ''),
            'created_at' => gmdate('c', (int) ($job['created_at'] ?? 0)),
        ];
    }
    return $listed;
}
