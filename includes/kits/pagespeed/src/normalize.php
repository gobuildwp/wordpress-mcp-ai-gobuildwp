<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Pagespeed;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * One shape for every source.
 *
 * The Cloud proxy already answers in it; Site Kit and Google answer with the raw PageSpeed
 * Insights v5 response (runPagespeed), which from_psi() reduces to it. Keeping the agent-facing
 * shape identical whichever source answered is what lets the fix loop compare a run Site Kit made
 * with one the Cloud made.
 *
 *   url, strategy, fetched_at, cached,
 *   scores {performance, seo, accessibility, best_practices}   0-100 or null when not measured,
 *   metrics {fcp_ms, lcp_ms, tbt_ms, cls, si_ms, ttfb_ms}      lab values, null when absent,
 *   field_data                                                 CrUX, null when Google has none,
 *   opportunities [{id, title, savings_ms, savings_bytes, items_count}]  largest saving first,
 *   diagnostics [{id, title, display}],
 *   lighthouse_version
 */

/** Lighthouse metric audits, by the key they are reported under. */
const METRIC_AUDITS = [
    'fcp_ms' => 'first-contentful-paint',
    'lcp_ms' => 'largest-contentful-paint',
    'tbt_ms' => 'total-blocking-time',
    'cls' => 'cumulative-layout-shift',
    'si_ms' => 'speed-index',
];

/** Score keys => Lighthouse category ids. */
const SCORE_CATEGORIES = [
    'performance' => 'performance',
    'seo' => 'seo',
    'accessibility' => 'accessibility',
    'best_practices' => 'best-practices',
];

/** CrUX metric names (loadingExperience.metrics) => the keys they are reported under. */
const FIELD_METRICS = [
    'LARGEST_CONTENTFUL_PAINT_MS' => 'lcp_ms',
    'INTERACTION_TO_NEXT_PAINT' => 'inp_ms',
    'CUMULATIVE_LAYOUT_SHIFT_SCORE' => 'cls',
    'FIRST_CONTENTFUL_PAINT_MS' => 'fcp_ms',
    'EXPERIMENTAL_TIME_TO_FIRST_BYTE' => 'ttfb_ms',
];

const MAX_OPPORTUNITIES = 15;

const MAX_DIAGNOSTICS = 15;

/** Lighthouse marks an audit passed from 0.9; below that it is something to look at. */
const PASS_SCORE = 0.9;

/**
 * Reduce a PageSpeed Insights v5 response to the shared shape.
 *
 * Opportunities come from two generations of Lighthouse. Up to 12 they are audits whose
 * details.type is "opportunity", carrying overallSavingsMs/Bytes. From 12.x the performance
 * insights (ids ending "-insight") carry their savings in metricSavings instead, and Lighthouse 13
 * removed the old audits. Both are read, so the answer does not depend on which Lighthouse the
 * source happened to run.
 *
 * @param array<array-key, mixed> $raw
 * @return array<string, mixed>|WP_Error
 */
function from_psi(array $raw, string $url, string $strategy): array|WP_Error
{
    $lighthouse = $raw['lighthouseResult'] ?? null;
    if (!is_array($lighthouse)) {
        return new WP_Error('kit_pagespeed_bad_response', 'PageSpeed Insights answered without a Lighthouse result.');
    }
    $runtime = $lighthouse['runtimeError'] ?? null;
    if (is_array($runtime) && is_string($runtime['code'] ?? null) && $runtime['code'] !== '' && $runtime['code'] !== 'NO_ERROR') {
        return lighthouse_error((string) $runtime['code'], (string) ($runtime['message'] ?? ''), 200);
    }

    $categories = is_array($lighthouse['categories'] ?? null) ? $lighthouse['categories'] : [];
    $audits = is_array($lighthouse['audits'] ?? null) ? $lighthouse['audits'] : [];

    $scores = [];
    foreach (SCORE_CATEGORIES as $key => $category) {
        $score = $categories[$category]['score'] ?? null;
        $scores[$key] = is_numeric($score) ? (int) round((float) $score * 100) : null;
    }

    $metrics = [];
    foreach (METRIC_AUDITS as $key => $audit) {
        $metrics[$key] = metric_value($audits[$audit]['numericValue'] ?? null, $key);
    }
    // Lighthouse up to 12 reports the document's response time as its own audit; every version
    // also puts it in the `metrics` summary, which is the only place Lighthouse 13 keeps it.
    $ttfb = $audits['server-response-time']['numericValue'] ?? ($audits['metrics']['details']['items'][0]['timeToFirstByte'] ?? null);
    $metrics['ttfb_ms'] = metric_value($ttfb, 'ttfb_ms');

    // Only the performance category's audits: the SEO and accessibility audits are scored too,
    // and a missing alt text is not a speed opportunity.
    $performance_ids = [];
    $metric_group = [];
    foreach ((array) ($categories['performance']['auditRefs'] ?? []) as $ref) {
        if (is_array($ref) && is_string($ref['id'] ?? null)) {
            $performance_ids[$ref['id']] = true;
            if (($ref['group'] ?? '') === 'metrics') {
                $metric_group[$ref['id']] = true;
            }
        }
    }

    $opportunities = [];
    $diagnostics = [];
    foreach ($audits as $id => $audit) {
        if (!is_string($id) || !is_array($audit)) {
            continue;
        }
        if ($performance_ids !== [] && !isset($performance_ids[$id])) {
            continue;
        }
        if (isset($metric_group[$id]) || in_array($id, METRIC_AUDITS, strict: true) || $id === 'metrics') {
            continue;
        }
        $opportunity = opportunity_from_audit($id, $audit);
        if ($opportunity !== null) {
            $opportunities[] = $opportunity;
            continue;
        }
        $score = $audit['score'] ?? null;
        $mode = (string) ($audit['scoreDisplayMode'] ?? '');
        if (is_numeric($score) && (float) $score < PASS_SCORE && !in_array($mode, ['notApplicable', 'manual', 'error'], strict: true)) {
            $diagnostics[] = [
                'id' => $id,
                'title' => plain((string) ($audit['title'] ?? $id)),
                'display' => plain((string) ($audit['displayValue'] ?? '')),
            ];
        }
    }

    return shape([
        'url' => $url,
        'strategy' => $strategy,
        'fetched_at' => (string) ($lighthouse['fetchTime'] ?? ''),
        'cached' => false,
        'scores' => $scores,
        'metrics' => $metrics,
        'field_data' => field_data($raw['loadingExperience'] ?? null),
        'opportunities' => $opportunities,
        'diagnostics' => $diagnostics,
        'lighthouse_version' => (string) ($lighthouse['lighthouseVersion'] ?? ''),
    ]);
}

/**
 * One audit as an opportunity, or null when it is not one or is already passing.
 *
 * @param array<array-key, mixed> $audit
 * @return array{id: string, title: string, savings_ms: int|null, savings_bytes: int|null, items_count: int}|null
 */
function opportunity_from_audit(string $id, array $audit): ?array
{
    $details = is_array($audit['details'] ?? null) ? $audit['details'] : [];
    $legacy = ($details['type'] ?? '') === 'opportunity';
    $insight = str_ends_with($id, '-insight');
    if (!$legacy && !$insight) {
        return null;
    }

    $savings_ms = is_numeric($details['overallSavingsMs'] ?? null) ? (float) $details['overallSavingsMs'] : null;
    if ($savings_ms === null && is_array($audit['metricSavings'] ?? null)) {
        // Layout shift is a unitless score, not milliseconds; the time metrics are comparable.
        foreach (['LCP', 'FCP', 'TBT', 'INP'] as $metric) {
            $value = $audit['metricSavings'][$metric] ?? null;
            if (is_numeric($value) && ($savings_ms === null || (float) $value > $savings_ms)) {
                $savings_ms = (float) $value;
            }
        }
    }
    $savings_bytes = is_numeric($details['overallSavingsBytes'] ?? null) ? (float) $details['overallSavingsBytes'] : null;

    $score = $audit['score'] ?? null;
    $failing = is_numeric($score) && (float) $score < PASS_SCORE;
    if (!$failing && ($savings_ms ?? 0.0) <= 0.0 && ($savings_bytes ?? 0.0) <= 0.0) {
        return null;
    }

    $items = $details['items'] ?? [];

    return [
        'id' => $id,
        'title' => plain((string) ($audit['title'] ?? $id)),
        'savings_ms' => $savings_ms === null ? null : (int) round($savings_ms),
        'savings_bytes' => $savings_bytes === null ? null : (int) round($savings_bytes),
        'items_count' => is_array($items) ? count($items) : 0,
    ];
}

/**
 * CrUX field data at the 75th percentile, in the Cloud proxy's shape, or null when Google has
 * none (most low-traffic pages). `scope` is "origin" when Google fell back to the whole site.
 *
 * @return array{scope: string, overall: string|null, lcp_ms: int|null, cls: float|null, inp_ms: int|null, fcp_ms: int|null, ttfb_ms: int|null}|null
 */
function field_data(mixed $experience): ?array
{
    if (!is_array($experience) || !is_array($experience['metrics'] ?? null) || $experience['metrics'] === []) {
        return null;
    }
    $out = [
        'scope' => ($experience['origin_fallback'] ?? false) === true ? 'origin' : 'url',
        'overall' => is_string($experience['overall_category'] ?? null) ? $experience['overall_category'] : null,
    ];
    $any = false;
    foreach (FIELD_METRICS as $name => $key) {
        $percentile = $experience['metrics'][$name]['percentile'] ?? null;
        if (!is_numeric($percentile)) {
            $out[$key] = null;
            continue;
        }
        $any = true;
        // CrUX reports layout shift multiplied by 100 so it fits an integer.
        $out[$key] = $name === 'CUMULATIVE_LAYOUT_SHIFT_SCORE' ? round((float) $percentile / 100, 2) : (int) $percentile;
    }

    return $any ? $out : null;
}

/**
 * The Cloud proxy's answer, checked against the contract and put through the same shape.
 *
 * The proxy is ours, but its answer still reaches an agent, so it is held to the shape rather
 * than passed through: a field the contract does not name is dropped, a wrong type is nulled.
 *
 * @param array<array-key, mixed> $body
 * @return array<string, mixed>|WP_Error
 */
function from_cloud(array $body, string $url, string $strategy): array|WP_Error
{
    if (!is_array($body['scores'] ?? null) || !is_array($body['metrics'] ?? null)) {
        return new WP_Error('kit_pagespeed_bad_response', 'The PageSpeed proxy answered without scores or metrics.');
    }

    $scores = [];
    foreach (array_keys(SCORE_CATEGORIES) as $key) {
        $value = $body['scores'][$key] ?? null;
        $scores[$key] = is_numeric($value) ? (int) round((float) $value) : null;
    }
    $metrics = [];
    foreach (['fcp_ms', 'lcp_ms', 'tbt_ms', 'cls', 'si_ms', 'ttfb_ms'] as $key) {
        $metrics[$key] = metric_value($body['metrics'][$key] ?? null, $key);
    }

    $opportunities = [];
    foreach ((array) ($body['opportunities'] ?? []) as $row) {
        if (!is_array($row) || !is_string($row['id'] ?? null) || $row['id'] === '') {
            continue;
        }
        $opportunities[] = [
            'id' => sanitize_audit_id($row['id']),
            'title' => plain((string) ($row['title'] ?? $row['id'])),
            'savings_ms' => is_numeric($row['savings_ms'] ?? null) ? (int) round((float) $row['savings_ms']) : null,
            'savings_bytes' => is_numeric($row['savings_bytes'] ?? null) ? (int) round((float) $row['savings_bytes']) : null,
            'items_count' => is_numeric($row['items_count'] ?? null) ? (int) $row['items_count'] : 0,
        ];
    }
    $diagnostics = [];
    foreach ((array) ($body['diagnostics'] ?? []) as $row) {
        if (!is_array($row) || !is_string($row['id'] ?? null) || $row['id'] === '') {
            continue;
        }
        $diagnostics[] = [
            'id' => sanitize_audit_id($row['id']),
            'title' => plain((string) ($row['title'] ?? $row['id'])),
            'display' => plain(is_scalar($row['display'] ?? null) ? (string) $row['display'] : ''),
        ];
    }

    $field = $body['field_data'] ?? null;

    return shape([
        'url' => $url,
        'strategy' => $strategy,
        'fetched_at' => is_string($body['fetched_at'] ?? null) ? $body['fetched_at'] : '',
        'cached' => ($body['cached'] ?? false) === true,
        'scores' => $scores,
        'metrics' => $metrics,
        'field_data' => is_array($field) && $field !== [] ? $field : null,
        'opportunities' => $opportunities,
        'diagnostics' => $diagnostics,
        'lighthouse_version' => is_string($body['lighthouse_version'] ?? null) ? $body['lighthouse_version'] : '',
    ]);
}

/**
 * Order and cap the lists. Largest time saving first, then bytes: the opportunity worth a person's
 * attention is the one that costs visitors the most time.
 *
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function shape(array $result): array
{
    /** @var list<array{id: string, title: string, savings_ms: int|null, savings_bytes: int|null, items_count: int}> $opportunities */
    $opportunities = $result['opportunities'];
    usort(
        $opportunities,
        static fn(array $a, array $b): int => [(int) ($b['savings_ms'] ?? 0), (int) ($b['savings_bytes'] ?? 0), $a['id']]
            <=> [(int) ($a['savings_ms'] ?? 0), (int) ($a['savings_bytes'] ?? 0), $b['id']],
    );
    $result['opportunities'] = array_slice($opportunities, 0, MAX_OPPORTUNITIES);
    /** @var list<array<string, string>> $diagnostics */
    $diagnostics = $result['diagnostics'];
    $result['diagnostics'] = array_slice($diagnostics, 0, MAX_DIAGNOSTICS);

    return $result;
}

function metric_value(mixed $value, string $key): int|float|null
{
    if (!is_numeric($value)) {
        return null;
    }

    return $key === 'cls' ? round((float) $value, 3) : (int) round((float) $value);
}

/** Audit titles are Markdown with links; the agent needs the words. */
function plain(string $text): string
{
    $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);

    return trim(substr(wp_strip_all_tags($text), 0, 300));
}

function sanitize_audit_id(string $id): string
{
    return substr((string) preg_replace('/[^a-z0-9-]/', '', strtolower($id)), 0, 80);
}

/**
 * A Lighthouse or PageSpeed failure as an error an agent can act on.
 *
 * `page` in the data says whether the failure was the page's (too slow, unreachable): asking
 * another source would take as long and fail the same way, so the source chain stops on those.
 * Quota and transport failures belong to one source, and the next one is tried.
 */
function lighthouse_error(string $code, string $message, int $status, int $retry_after = 0): WP_Error
{
    $haystack = strtoupper($code . ' ' . $message);

    foreach (['NO_FCP', 'NO_LCP', 'PAGE_HUNG', 'PROTOCOL_TIMEOUT', 'PAGE_TOO_SLOW', 'PAGE_TIMEOUT', 'TARGET_TIMEOUT'] as $slow) {
        if (str_contains($haystack, $slow)) {
            return new WP_Error(
                'kit_pagespeed_page_too_slow',
                'Google could not load the page in time; the uncached page may be too slow. Warm the page cache for this URL and try again, or measure the server response with a cache measurement first.',
                ['status' => $status, 'page' => true, 'lighthouse_code' => $code, 'detail' => scrub($message)],
            );
        }
    }

    foreach (['FAILED_DOCUMENT_REQUEST', 'ERRORED_DOCUMENT_REQUEST', 'DNS_FAILURE', 'NOT_HTML', 'INSECURE_DOCUMENT_REQUEST', 'PAGE_UNREACHABLE'] as $unreachable) {
        if (str_contains($haystack, $unreachable)) {
            return new WP_Error(
                'kit_pagespeed_page_unreachable',
                'Google could not fetch the page. PageSpeed Insights tests the public URL from Google\'s servers, so the page must be reachable from the internet (not a local, password-protected or maintenance-mode site) and return HTML.',
                ['status' => $status, 'page' => true, 'lighthouse_code' => $code, 'detail' => scrub($message)],
            );
        }
    }

    foreach (['RATELIMITEXCEEDED', 'RATE_LIMIT', 'QUOTA', 'DAILYLIMITEXCEEDED', 'RESOURCE_EXHAUSTED'] as $quota) {
        if (str_contains($haystack, $quota)) {
            return quota_error($status, $retry_after, $message);
        }
    }
    if ($status === 429) {
        return quota_error($status, $retry_after, $message);
    }

    if (str_contains($haystack, 'API_KEY_INVALID') || str_contains($haystack, 'API KEY NOT VALID') || str_contains($haystack, 'KEYINVALID')) {
        return new WP_Error(
            'kit_pagespeed_bad_key',
            'Google rejected the PageSpeed API key saved in the plugin settings. Check it in Google Cloud console (APIs & Services > Credentials) and that the PageSpeed Insights API is enabled for its project.',
            ['status' => $status, 'page' => false],
        );
    }

    return new WP_Error(
        'kit_pagespeed_failed',
        sprintf('PageSpeed Insights failed: %s', scrub($message) !== '' ? scrub($message) : $code),
        ['status' => $status, 'page' => false, 'lighthouse_code' => $code],
    );
}

function quota_error(int $status, int $retry_after, string $message): WP_Error
{
    $said = scrub($message);

    return new WP_Error(
        'kit_pagespeed_quota',
        ($said !== '' ? rtrim($said, '. ') . '. ' : '')
            . 'The PageSpeed limit is used up for now. Do not retry in a loop: wait and try again'
            . ($retry_after > 0 ? sprintf(' in about %d seconds', $retry_after) : ' later')
            . '; a site owner who runs many tests can add their own PageSpeed API key in the plugin settings.',
        ['status' => $status, 'page' => false, 'retry_after' => $retry_after, 'detail' => scrub($message)],
    );
}

/** Never let an API key travel inside an error message. */
function scrub(string $message): string
{
    $message = (string) preg_replace('/([?&]key=)[^&\s"]+/i', '$1[redacted]', $message);
    $message = (string) preg_replace('/AIza[0-9A-Za-z_\-]{20,}/', '[redacted]', $message);

    return trim(substr(wp_strip_all_tags($message), 0, 400));
}
