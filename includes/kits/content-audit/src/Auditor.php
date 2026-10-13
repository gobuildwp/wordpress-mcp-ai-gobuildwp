<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ContentAudit;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The content audit: one scan state, fed batches of published posts, finished once.
 *
 * The same state drives a single synchronous page and a background job, so a job is just the
 * page loop run by the jobs runner with its state saved between steps. Everything the scan
 * accumulates across batches — which posts were seen, which were linked to, which URLs were
 * already resolved or checked — is in the state, so a job resumed by another request carries on
 * where the last step stopped.
 */
final class Auditor
{
    public const CHECKS = ['broken_links', 'orphans', 'thin_content', 'seo_meta', 'schema', 'external_links'];

    /** External links are opt-in: every one is a request to someone else's server. */
    public const DEFAULT_CHECKS = ['broken_links', 'orphans', 'thin_content', 'seo_meta', 'schema'];

    /** Findings kept in the state; counts stay exact beyond it. */
    public const MAX_FINDINGS = 1000;

    /** Resolved internal URLs remembered across batches. */
    private const MAX_CACHED_URLS = 5000;

    private const SEVERITY_ORDER = ['high' => 0, 'medium' => 1, 'low' => 2, 'info' => 3];

    private Fixes $fixes;

    /**
     * @param array{checks: list<string>, post_types: list<string>, thin_words: int, external_limit: int, internal_http_limit: int, schema_sample: int} $options
     */
    public function __construct(private Source $source, private array $options)
    {
        $this->fixes = new Fixes($source);
    }

    /**
     * Normalise ability input into options, clamping every limit.
     *
     * @param array<string, mixed> $input
     * @return array{checks: list<string>, post_types: list<string>, thin_words: int, external_limit: int, internal_http_limit: int, schema_sample: int}
     */
    public static function options(array $input): array
    {
        $checks = is_array($input['checks'] ?? null)
            ? array_values(array_intersect(self::CHECKS, array_map('strval', $input['checks'])))
            : self::DEFAULT_CHECKS;
        $types = is_array($input['post_types'] ?? null) ? $input['post_types'] : ['post', 'page'];
        $types = array_values(array_unique(array_filter(
            array_map(static fn(mixed $type): string => strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', (string) $type)), $types),
            static fn(string $type): bool => $type !== '',
        )));
        $clamp = static fn(mixed $value, int $default, int $min, int $max): int => max($min, min($max, is_numeric($value) ? (int) $value : $default));
        return [
            'checks' => $checks === [] ? self::DEFAULT_CHECKS : $checks,
            'post_types' => $types === [] ? ['post', 'page'] : array_slice($types, 0, 10),
            'thin_words' => $clamp($input['thin_words'] ?? null, 300, 50, 5000),
            'external_limit' => $clamp($input['external_limit'] ?? null, 20, 1, 50),
            'internal_http_limit' => $clamp($input['internal_http_limit'] ?? null, 50, 0, 200),
            'schema_sample' => $clamp($input['schema_sample'] ?? null, 10, 0, 50),
        ];
    }

    /**
     * A fresh scan over `$total` posts.
     *
     * @return array<string, mixed>
     */
    public function start(int $total): array
    {
        $sources = [];
        // Where the host has an SEO provider registry (GoBuildWP Pro), the plugins it reports active
        // are read through their own readers, which know storage the meta keys below do not
        // (AIOSEO's table, Slim SEO's array) and ignore the leftovers of a deactivated plugin.
        $providers = $this->enabled('seo_meta') ? $this->source->seo_providers() : [];
        if ($providers !== []) {
            $sources = array_keys($providers);
        } elseif ($this->enabled('seo_meta')) {
            $keys = [];
            foreach (Fixes::SEO_SOURCES as $source) {
                $keys[] = $source['title'];
                $keys[] = $source['description'];
            }
            $present = $this->source->present_meta_keys($keys);
            foreach (Fixes::SEO_SOURCES as $slug => $source) {
                if (in_array($source['title'], $present, true) || in_array($source['description'], $present, true)) {
                    $sources[] = $slug;
                }
            }
        }
        $sample = $this->options['schema_sample'];
        return [
            'total' => $total,
            'scanned' => 0,
            'cursor' => 0,
            'findings' => [],
            'counts' => ['by_type' => [], 'by_severity' => []],
            'dropped' => 0,
            'seen' => [],
            'linked' => [],
            'resolved' => [],
            'external' => [],
            'http_used' => 0,
            'unchecked_internal' => 0,
            'external_checked' => 0,
            'external_skipped' => 0,
            'schema_sampled' => 0,
            // Spread the sample over the whole run rather than taking the first N posts, which
            // are the oldest and least representative of the current templates.
            'schema_stride' => $sample > 0 ? max(1, (int) ceil($total / $sample)) : 0,
            'seo_sources' => $sources,
            // 'registry' or 'meta'; the labels are kept because a registry slug need not be one
            // of Fixes::SEO_SOURCES, and a background job reports after the request that saw it.
            'seo_via' => $providers !== [] ? 'registry' : 'meta',
            'seo_labels' => $providers,
            'complete' => false,
            'notes' => [],
        ];
    }

    /**
     * Audit a batch of post IDs.
     *
     * @param list<int> $ids
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function scan(array $ids, array $state): array
    {
        foreach ($ids as $id) {
            $state['cursor'] = max((int) $state['cursor'], $id);
            $post = $this->source->post($id);
            if ($post === null) {
                continue;
            }
            $position = (int) $state['scanned'];
            $state['scanned'] = $position + 1;
            $state['seen'][$id] = $post['type'];

            if ($this->enabled('broken_links') || $this->enabled('orphans') || $this->enabled('external_links')) {
                $state = $this->scan_links($post, $state);
            }
            if ($this->enabled('thin_content')) {
                $state = $this->scan_thin($post, $state);
            }
            if ($this->enabled('seo_meta') && $state['seo_sources'] !== []) {
                $state = $this->scan_seo($post, $state);
            }
            $stride = (int) $state['schema_stride'];
            if (
                $this->enabled('schema')
                && $stride > 0
                && (int) $state['schema_sampled'] < $this->options['schema_sample']
                && $position % $stride === 0
            ) {
                $state = $this->scan_schema($post, $state);
            }
        }
        return $state;
    }

    /**
     * Close the scan: orphans (only when every post was seen) and the site-level notes.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function finish(array $state, bool $complete): array
    {
        $state['complete'] = $complete;
        $notes = [];
        if ($this->enabled('orphans')) {
            if ($complete) {
                $state = $this->find_orphans($state);
            } else {
                $notes[] = 'Orphans were not checked: that needs every published post scanned. Run with mode "background" for the whole site.';
            }
        }
        if ($this->enabled('seo_meta') && $state['seo_sources'] === []) {
            $state = $this->add($state, 'seo_meta_source_unknown', 'info', null, [
                'checked_keys' => array_values(array_merge(
                    array_column(Fixes::SEO_SOURCES, 'title'),
                    array_column(Fixes::SEO_SOURCES, 'description'),
                )),
            ]);
        }
        if ((int) $state['unchecked_internal'] > 0) {
            $notes[] = sprintf(
                '%d internal links that are not post permalinks (archives, custom routes) were not checked: the limit of %d HTTP checks was reached. Raise internal_http_limit to check them.',
                (int) $state['unchecked_internal'],
                $this->options['internal_http_limit'],
            );
        }
        if ((int) $state['external_skipped'] > 0) {
            $notes[] = sprintf('%d external links were not checked: the limit of %d was reached.', (int) $state['external_skipped'], $this->options['external_limit']);
        }
        if ((int) $state['dropped'] > 0) {
            $notes[] = sprintf('%d findings beyond the first %d were counted but not kept.', (int) $state['dropped'], self::MAX_FINDINGS);
        }
        $state['notes'] = $notes;
        return $state;
    }

    /**
     * The result an agent reads: findings worst first, a page at a time.
     *
     * @param array<string, mixed> $state
     * @param array{severity?: string, type?: string} $filters
     * @return array<string, mixed>
     */
    public function report(array $state, int $offset = 0, int $limit = 200, array $filters = []): array
    {
        $findings = is_array($state['findings'] ?? null) ? $state['findings'] : [];
        $findings = array_values(array_filter($findings, static function (array $finding) use ($filters): bool {
            return (($filters['severity'] ?? '') === '' || $finding['severity'] === $filters['severity'])
                && (($filters['type'] ?? '') === '' || $finding['type'] === $filters['type']);
        }));
        usort($findings, static function (array $a, array $b): int {
            $order = self::SEVERITY_ORDER[$a['severity']] <=> self::SEVERITY_ORDER[$b['severity']];
            return $order !== 0 ? $order : ((int) ($a['post']['id'] ?? 0) <=> (int) ($b['post']['id'] ?? 0));
        });
        $total = count($findings);
        $page = array_slice($findings, $offset, $limit);
        $next = $offset + count($page);
        $counts = is_array($state['counts'] ?? null) ? $state['counts'] : ['by_type' => [], 'by_severity' => []];
        return [
            'findings' => $page,
            'total_findings' => $total,
            'offset' => $offset,
            'next_offset' => $next < $total ? $next : null,
            'counts' => $counts,
            'fixes' => $this->fixes->describe(array_map('strval', array_keys($counts['by_type']))),
            'stats' => [
                'scanned' => (int) ($state['scanned'] ?? 0),
                'total' => (int) ($state['total'] ?? 0),
                'complete' => ($state['complete'] ?? false) === true,
                'seo_sources' => array_map(
                    fn(string $slug): string => $this->seo_label($slug, $state),
                    is_array($state['seo_sources'] ?? null) ? $state['seo_sources'] : [],
                ),
                'seo_read_via' => ($state['seo_via'] ?? 'meta') === 'registry' ? 'seo provider registry' : 'post meta',
                'internal_http_checks' => (int) ($state['http_used'] ?? 0),
                'external_checked' => (int) ($state['external_checked'] ?? 0),
                'schema_pages_fetched' => (int) ($state['schema_sampled'] ?? 0),
            ],
            'notes' => is_array($state['notes'] ?? null) ? $state['notes'] : [],
        ];
    }

    /**
     * Resolve one internal URL (absolute, no fragment) to what a visitor would reach.
     *
     * Without HTTP wherever WordPress can answer: a permalink maps to a post and its status, an
     * uploads URL to a file on disk. Only what neither covers — archives, feeds, custom routes —
     * is asked over HTTP, within a budget, without following redirects.
     *
     * @param array<string, mixed> $state
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} The resolution and the state.
     */
    public function resolve(string $url, array $state): array
    {
        if (isset($state['resolved'][$url]) && is_array($state['resolved'][$url])) {
            return [$state['resolved'][$url], $state];
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $resolution = ['result' => 'ok'];
        if (preg_match('#^/(wp-admin|wp-login\.php|wp-json|xmlrpc\.php|wp-cron\.php)(/|$)#', $path) === 1) {
            $resolution = ['result' => 'skipped'];
        } else {
            $file = $this->source->upload_file_exists($url);
            if ($file === false) {
                $resolution = ['result' => 'broken_file_link'];
            } elseif ($file === null) {
                $resolution = $this->resolve_post($url, $path, $state);
                if (($resolution['result'] ?? '') === 'needs_http') {
                    [$resolution, $state] = $this->resolve_http($url, $state);
                }
            }
        }
        if (($resolution['result'] ?? '') !== 'unchecked' && count($state['resolved']) < self::MAX_CACHED_URLS) {
            $state['resolved'][$url] = $resolution;
        }
        return [$resolution, $state];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function resolve_post(string $url, string $path, array $state): array
    {
        $id = $this->source->url_to_post_id($url);
        if ($id > 0) {
            $status = $this->source->post_status($id);
            if ($status === null || $status === 'trash') {
                return ['result' => 'broken_internal_link', 'target_id' => $id, 'target_status' => $status ?? 'missing'];
            }
            if (in_array($status, ['draft', 'pending', 'private', 'future', 'auto-draft'], true)) {
                return ['result' => 'internal_link_to_unpublished', 'target_id' => $id, 'target_status' => $status];
            }
            return ['result' => 'ok', 'target_id' => $id];
        }
        $home_path = rtrim((string) (parse_url($this->source->home_url(), PHP_URL_PATH) ?? ''), '/');
        if (rtrim($path, '/') === $home_path && (string) parse_url($url, PHP_URL_QUERY) === '') {
            return ['result' => 'ok', 'target' => 'home'];
        }
        return ['result' => 'needs_http'];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function resolve_http(string $url, array $state): array
    {
        if ((int) $state['http_used'] >= $this->options['internal_http_limit']) {
            $state['unchecked_internal'] = (int) $state['unchecked_internal'] + 1;
            return [['result' => 'unchecked'], $state];
        }
        $state['http_used'] = (int) $state['http_used'] + 1;
        $response = $this->source->head($url, 5);
        if ($response instanceof WP_Error) {
            $state['unchecked_internal'] = (int) $state['unchecked_internal'] + 1;
            return [['result' => 'unchecked', 'error' => $response->get_error_message()], $state];
        }
        $status = $response['status'];
        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            return [['result' => 'redirected_internal_link', 'http_status' => $status, 'location' => $response['location']], $state];
        }
        if (in_array($status, [404, 410], true)) {
            return [['result' => 'broken_internal_link', 'http_status' => $status], $state];
        }
        if ($status >= 400) {
            return [['result' => 'internal_link_error', 'http_status' => $status], $state];
        }
        return [['result' => 'ok', 'http_status' => $status], $state];
    }

    /**
     * @param array{id: int, title: string, type: string, url: string, content: string, builder: string} $post
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function scan_links(array $post, array $state): array
    {
        $home = $this->source->home_url();
        $occurrences = [];
        foreach (['content' => Links::from_html($post['content']), 'builder' => Links::from_builder($post['builder'])] as $where => $links) {
            foreach ($links as $link) {
                $occurrences[$link][$where] = ($occurrences[$link][$where] ?? 0) + 1;
            }
        }
        foreach ($occurrences as $link => $where) {
            $link = (string) $link;
            $kind = Links::classify($link, $home);
            $evidence = ['href' => $link, 'occurrences' => array_sum($where), 'found_in' => array_keys($where)];
            if ($kind === 'internal') {
                [$resolution, $state] = $this->resolve(Links::absolute($link, $home), $state);
                $target = (int) ($resolution['target_id'] ?? 0);
                if ($resolution['result'] === 'ok' && $target > 0 && $target !== $post['id']) {
                    $state['linked'][$target] = true;
                }
                if (
                    $this->enabled('broken_links')
                    && !in_array($resolution['result'], ['ok', 'skipped', 'unchecked'], true)
                ) {
                    $severity = match ($resolution['result']) {
                        'redirected_internal_link' => 'low',
                        'internal_link_error' => 'medium',
                        default => 'high',
                    };
                    $details = $resolution;
                    unset($details['result']);
                    $state = $this->add($state, (string) $resolution['result'], $severity, $post, $evidence + $details);
                }
            } elseif ($kind === 'external' && $this->enabled('external_links')) {
                $state = $this->check_external($link, $post, $evidence, $state);
            }
        }
        return $state;
    }

    /**
     * @param array{id: int, title: string, type: string, url: string, content: string, builder: string} $post
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function check_external(string $link, array $post, array $evidence, array $state): array
    {
        $key = Links::external_key($link);
        $result = $state['external'][$key] ?? null;
        if (!is_array($result)) {
            if ((int) $state['external_checked'] >= $this->options['external_limit']) {
                $state['external_skipped'] = (int) $state['external_skipped'] + 1;
                return $state;
            }
            $state['external_checked'] = (int) $state['external_checked'] + 1;
            $response = $this->source->head($key, 5);
            $result = $response instanceof WP_Error
                ? ['error' => $response->get_error_message()]
                : ['http_status' => $response['status']];
            $state['external'][$key] = $result;
        }
        if (isset($result['error'])) {
            return $this->add($state, 'external_link_unreachable', 'low', $post, $evidence + ['error' => (string) $result['error']]);
        }
        $status = (int) ($result['http_status'] ?? 0);
        if ($status >= 400) {
            // 401/403/429 are as often a bot wall as a dead page; say so rather than call it broken.
            $walled = in_array($status, [401, 403, 429], true);
            return $this->add($state, 'broken_external_link', $walled ? 'low' : 'medium', $post, $evidence + [
                'http_status' => $status,
                'note' => $walled ? 'The server refused an automated check; the page may still work in a browser.' : '',
            ]);
        }
        return $state;
    }

    /**
     * @param array{id: int, title: string, type: string, url: string, content: string, builder: string} $post
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function scan_thin(array $post, array $state): array
    {
        $words = Links::word_count(Links::text($post['content'], $post['builder']));
        $threshold = $this->options['thin_words'];
        if ($words >= $threshold) {
            return $state;
        }
        return $this->add($state, 'thin_content', $words < intdiv($threshold, 2) ? 'medium' : 'low', $post, [
            'words' => $words,
            'threshold' => $threshold,
            'counted_from' => $post['builder'] !== '' ? ['post_content', 'page builder data'] : ['post_content'],
        ]);
    }

    /**
     * @param array{id: int, title: string, type: string, url: string, content: string, builder: string} $post
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function scan_seo(array $post, array $state): array
    {
        $slugs = array_values(array_map('strval', (array) $state['seo_sources']));
        $sources = array_values(array_intersect_key(Fixes::SEO_SOURCES, array_flip($slugs)));
        $labels = array_map(fn(string $slug): string => $this->seo_label($slug, $state), $slugs);
        $abilities = array_column($sources, 'ability');
        $values = ['title' => [], 'description' => []];
        if (($state['seo_via'] ?? 'meta') === 'registry') {
            foreach ($slugs as $slug) {
                $seo = $this->source->seo_read($slug, $post['id']);
                if ($seo !== null) {
                    $values['title'][] = $seo['title'];
                    $values['description'][] = $seo['description'];
                }
            }
            // No provider could be read: that is not evidence that anything is missing.
            if ($values['description'] === []) {
                return $state;
            }
        } else {
            $keys = [];
            foreach ($sources as $source) {
                $keys[] = $source['title'];
                $keys[] = $source['description'];
            }
            $meta = $this->source->meta($post['id'], $keys);
            foreach ($sources as $source) {
                $values['title'][] = $meta[$source['title']] ?? '';
                $values['description'][] = $meta[$source['description']] ?? '';
            }
        }
        foreach (['description' => 'missing_meta_description', 'title' => 'missing_meta_title'] as $field => $type) {
            if (array_filter($values[$field], static fn(string $value): bool => $value !== '') !== []) {
                continue;
            }
            $state = $this->add($state, $type, $field === 'description' ? 'low' : 'info', $post, [
                'source' => $labels,
                'keys_checked' => array_column($sources, $field),
                'note' => $field === 'description'
                    ? 'No post-specific description is stored. Unless a post-type template in the SEO plugin supplies one, the page has none.'
                    : 'No post-specific SEO title is stored; the SEO plugin\'s title template applies.',
            ], $abilities);
        }
        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function seo_label(string $slug, array $state): string
    {
        $labels = is_array($state['seo_labels'] ?? null) ? $state['seo_labels'] : [];
        return (string) ($labels[$slug] ?? Fixes::SEO_SOURCES[$slug]['label'] ?? $slug);
    }

    /**
     * @param array{id: int, title: string, type: string, url: string, content: string, builder: string} $post
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function scan_schema(array $post, array $state): array
    {
        if ($post['url'] === '') {
            return $state;
        }
        $state['schema_sampled'] = (int) $state['schema_sampled'] + 1;
        $page = $this->source->fetch($post['url']);
        if ($page instanceof WP_Error) {
            return $this->add($state, 'page_not_fetched', 'info', $post, ['error' => $page->get_error_message()]);
        }
        if ($page['status'] >= 400) {
            return $this->add($state, 'page_error', 'high', $post, ['http_status' => $page['status']]);
        }
        $inspection = Schema::inspect($page['html']);
        foreach ($inspection['problems'] as $problem) {
            $state = $this->add($state, $problem['type'], $problem['severity'], $post, $problem['evidence'] + [
                'json_ld_blocks' => $inspection['blocks'],
                'types_found' => $inspection['types'],
            ]);
        }
        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function find_orphans(array $state): array
    {
        $structural = $this->source->structural_ids();
        $reachable = array_flip(array_merge([$structural['front'], $structural['posts_page']], $structural['menu']));
        foreach ((array) $state['seen'] as $id => $type) {
            $id = (int) $id;
            if (isset($state['linked'][$id]) || isset($reachable[$id])) {
                continue;
            }
            $post = count((array) $state['findings']) < self::MAX_FINDINGS ? $this->source->post($id) : null;
            $state = $this->add(
                $state,
                'orphan_content',
                $type === 'page' ? 'medium' : 'low',
                $post ?? ['id' => $id, 'title' => '', 'type' => (string) $type, 'url' => '', 'content' => '', 'builder' => ''],
                ['inbound_content_links' => 0, 'in_menu' => false, 'note' => 'No other published post or page in this scan links here, and no menu does.'],
            );
        }
        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @param array{id: int, title: string, type: string, url: string, content?: string, builder?: string}|null $post
     * @param array<string, mixed> $evidence
     * @param list<string> $abilities
     * @return array<string, mixed>
     */
    private function add(array $state, string $type, string $severity, ?array $post, array $evidence, array $abilities = []): array
    {
        $state['counts']['by_type'][$type] = (int) ($state['counts']['by_type'][$type] ?? 0) + 1;
        $state['counts']['by_severity'][$severity] = (int) ($state['counts']['by_severity'][$severity] ?? 0) + 1;
        $slot = count((array) $state['findings']);
        if ($slot >= self::MAX_FINDINGS) {
            // Full: keep the worst. A site with a thousand missing titles (info) must still show
            // the one page that returns a 500.
            $slot = null;
            $weakest = self::SEVERITY_ORDER[$severity];
            foreach ((array) $state['findings'] as $index => $kept) {
                if (self::SEVERITY_ORDER[$kept['severity']] > $weakest) {
                    $weakest = self::SEVERITY_ORDER[$kept['severity']];
                    $slot = $index;
                }
            }
            $state['dropped'] = (int) $state['dropped'] + 1;
            if ($slot === null) {
                return $state;
            }
        }
        $state['findings'][$slot] = [
            'type' => $type,
            'severity' => $severity,
            'post' => $post === null ? null : [
                'id' => $post['id'],
                'title' => $post['title'],
                'type' => $post['type'],
                'url' => $post['url'],
            ],
            'evidence' => $evidence,
            'suggested_fix' => $this->fixes->suggest($type, $abilities),
        ];
        return $state;
    }

    private function enabled(string $check): bool
    {
        return in_array($check, $this->options['checks'], true);
    }
}
