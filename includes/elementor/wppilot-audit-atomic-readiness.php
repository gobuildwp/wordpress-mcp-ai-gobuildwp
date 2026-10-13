<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Ability: how much of this site an atomic-only tool can actually work on.
 *
 * Elementor 4 introduced atomic elements, and the tooling built on them -
 * Elementor's own MCP included - works on atomic documents. Every site built
 * before that is classic widgets, and the failure people hit is not an error
 * message: the tool connects, reports success, and then cannot edit the page
 * they asked about. Nothing on the site says why.
 *
 * This answers that in one call, for the whole site rather than one page:
 * every Elementor document, every element in it, classified, with the widget
 * types that block a migration named and counted. `elementor-check-setup`
 * already reports whether the atomic *runtime* is available; this reports
 * whether the *content* is, which is the half that decides the answer.
 *
 * Read-only, and it never guesses: a widget whose plugin has been deactivated
 * is reported as unregistered rather than silently counted as classic.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Element classes, worst to best, for the per-document verdict.
 *
 * `classic_mapped` is the interesting one: a classic widget GoBuildWP knows how
 * to turn into an atomic element. It is not atomic today, but it is not a
 * blocker either, and collapsing it into `classic` would report a site as
 * hopeless when it is an afternoon's conversion.
 */
const WPPILOT_ELEMENTOR_AUDIT_CLASSES = [
    'atomic',
    'classic_mapped',
    'classic',
    'third_party',
    'unregistered',
];

/**
 * Seconds a whole-site audit may spend reading documents before it stops and
 * reports `truncated`. A site with thousands of Elementor pages must return a
 * useful partial answer rather than exhaust the request.
 */
const WPPILOT_ELEMENTOR_AUDIT_TIME_BUDGET = 20.0;

/** How long a per-document classification stays cached. Keyed by content hash. */
const WPPILOT_ELEMENTOR_AUDIT_CACHE_TTL = DAY_IN_SECONDS;

wp_register_ability('wppilot/elementor-audit-atomic-readiness', [
    'label' => __('Audit Elementor Atomic Readiness', domain: 'wppilot'),
    'description' => __(
        'Reports how much of this site is built with Elementor v4 atomic elements and how much is classic v3 widgets, across every Elementor document rather than one page. Returns a widget histogram, the share of elements an atomic-only tool can work with, the widget types blocking the rest with their counts, and a per-document verdict. Call this before planning Elementor work, before attempting a v3-to-v4 conversion, and whenever an atomic-only tool - including Elementor\'s own MCP - reports success but cannot edit a page: this says whether the content is atomic, which is the half that decides it. Pass post_id to audit one document. Read-only.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'post_id' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Audit a single Elementor document instead of the whole site.',
            ],
            'post_types' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Restrict the scan to these post types. Defaults to every type that has Elementor documents, including elementor_library.',
            ],
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 500,
                'default' => 200,
                'description' => 'How many documents to read in this call.',
            ],
            'offset' => [
                'type' => 'integer',
                'minimum' => 0,
                'default' => 0,
                'description' => 'Skip this many documents. Use with the returned next_offset to continue a truncated scan.',
            ],
            'include_documents' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Include the per-document breakdown. Set false for the totals only.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input): array|\WP_Error {
        return elementor_audit_atomic_readiness($input);
    },
    // The shared callback, like every other ability: it honours the master
    // switch, which a bare capability check skipped.
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
        // Without these the ability registered but was never listed over MCP.
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
    ],
]);

/**
 * Audit one document or the whole site.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|\WP_Error
 */
function elementor_audit_atomic_readiness(array $input): array|\WP_Error
{
    if (!el_min_runtime_available()) {
        return new \WP_Error(
            'wppilot_elementor_unavailable',
            __('Elementor is not active, or is older than the minimum version GoBuildWP supports.', domain: 'wppilot'),
            ['status' => 400],
        );
    }

    $registry = el_audit_widget_registry();
    $started = microtime(true);

    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id > 0) {
        $document = el_audit_document($post_id, $registry);
        if ($document instanceof \WP_Error) {
            return $document;
        }
        $documents = [$document];
        $truncated = false;
        $scanned_all = true;
    } else {
        $limit = (int) ($input['limit'] ?? 200);
        $offset = max(0, (int) ($input['offset'] ?? 0));
        /** @var list<string> $post_types */
        $post_types = is_array($input['post_types'] ?? null) ? array_values(array_filter(
            $input['post_types'],
            static fn(mixed $type): bool => is_string($type) && $type !== '',
        )) : [];

        $ids = el_audit_document_ids($post_types, $limit, $offset);
        $documents = [];
        $truncated = false;
        foreach ($ids as $index => $id) {
            if ((microtime(true) - $started) > WPPILOT_ELEMENTOR_AUDIT_TIME_BUDGET) {
                $truncated = true;
                $offset += $index;
                break;
            }
            $document = el_audit_document((int) $id, $registry);
            if (!$document instanceof \WP_Error) {
                $documents[] = $document;
            }
        }

        if (!$truncated) {
            $truncated = count($ids) === $limit;
            $offset += count($ids);
        }
        $scanned_all = !$truncated && $offset <= count($ids);
    }

    return el_audit_report(
        $documents,
        $truncated,
        $truncated ? $offset : null,
        (bool) ($input['include_documents'] ?? true),
        $scanned_all,
    );
}

/**
 * Every registered widget, by name, with the two facts the audit classifies on.
 *
 * Read once per call. `get_widget_types()` instantiates every widget class, so
 * calling it per document would dominate the runtime on a large site.
 *
 * @return array<string, array{is_atomic: bool, source: string, title: string}>
 */
function el_audit_widget_registry(): array
{
    /** @var array<string, mixed> $listed */
    $listed = schema_list_filtered([]);
    /** @var mixed $widgets */
    $widgets = $listed['widgets'] ?? $listed;

    $registry = [];
    if (is_array($widgets)) {
        foreach ($widgets as $widget) {
            if (!is_array($widget) || !is_string($widget['name'] ?? null)) {
                continue;
            }
            $registry[$widget['name']] = [
                'is_atomic' => (bool) ($widget['is_atomic'] ?? false),
                'source' => is_string($widget['source'] ?? null) ? $widget['source'] : 'unknown',
                'title' => is_string($widget['title'] ?? null) ? $widget['title'] : $widget['name'],
            ];
        }
    }

    return $registry;
}

/**
 * Elementor document ids, newest first.
 *
 * Selected on `_elementor_edit_mode` rather than on the presence of
 * `_elementor_data`, because a post edited once in the block editor keeps stale
 * Elementor data that Elementor itself no longer renders - counting it would
 * report classic widgets on a page nobody sees.
 *
 * @param list<string> $post_types
 * @return list<int>
 */
function el_audit_document_ids(array $post_types, int $limit, int $offset): array
{
    $query = new \WP_Query([
        'post_type' => $post_types === [] ? 'any' : $post_types,
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page' => $limit,
        'offset' => $offset,
        'orderby' => 'ID',
        'order' => 'DESC',
        'fields' => 'ids',
        'no_found_rows' => true,
        'ignore_sticky_posts' => true,
        'suppress_filters' => false,
        'meta_query' => [
            [
                'key' => '_elementor_edit_mode',
                'value' => 'builder',
                'compare' => '=',
            ],
        ],
    ]);

    /** @var list<int> $ids */
    $ids = array_map('intval', $query->posts);

    return $ids;
}

/**
 * Classify one document.
 *
 * @param array<string, array{is_atomic: bool, source: string, title: string}> $registry
 * @return array<string, mixed>|\WP_Error
 */
function el_audit_document(int $post_id, array $registry): array|\WP_Error
{
    $post = get_post($post_id);
    if (!$post instanceof \WP_Post) {
        return new \WP_Error(
            'wppilot_post_not_found',
            sprintf(__('No post with ID %d.', domain: 'wppilot'), $post_id),
            ['status' => 404],
        );
    }

    $raw = get_post_meta($post_id, '_elementor_data', single: true);
    if (!is_string($raw) || trim($raw) === '') {
        return new \WP_Error(
            'wppilot_not_an_elementor_document',
            sprintf(
                __('Post %d has no Elementor content. Only documents edited with Elementor can be audited.', domain: 'wppilot'),
                $post_id,
            ),
            ['status' => 400],
        );
    }

    $cache_key = 'wppilot_el_audit_' . md5($raw);
    /** @var mixed $cached */
    $cached = get_transient($cache_key);
    if (is_array($cached) && isset($cached['counts'], $cached['types'])) {
        /** @var array{counts: array<string, int>, types: array<string, array{count: int, class: string}>} $cached */
        $counts = $cached['counts'];
        $types = $cached['types'];
    } else {
        // el_read_page() answers [elements, error], not the element list.
        [$elements, $read_error] = el_read_page($post_id);
        if ($elements === null) {
            return new \WP_Error(
                'wppilot_elementor_unreadable',
                sprintf(
                    __('Post %1$d has Elementor data that could not be read: %2$s', domain: 'wppilot'),
                    $post_id,
                    (string) $read_error,
                ),
                ['status' => 422],
            );
        }

        $counts = array_fill_keys(WPPILOT_ELEMENTOR_AUDIT_CLASSES, 0);
        $types = [];
        el_audit_walk($elements, $registry, $counts, $types);
        set_transient($cache_key, ['counts' => $counts, 'types' => $types], WPPILOT_ELEMENTOR_AUDIT_CACHE_TTL);
    }

    $total = array_sum($counts);

    return [
        'id' => $post_id,
        'title' => $post->post_title,
        'post_type' => $post->post_type,
        'status' => $post->post_status,
        'template_type' => (string) get_post_meta($post_id, '_elementor_template_type', single: true),
        'elements' => $total,
        'counts' => $counts,
        'types' => $types,
        'verdict' => el_audit_verdict($counts, $total),
        'edit_url' => get_edit_post_link($post_id, 'raw'),
    ];
}

/**
 * Walk an element tree, counting every element by class and by type.
 *
 * @param list<array<string, mixed>> $elements
 * @param array<string, array{is_atomic: bool, source: string, title: string}> $registry
 * @param array<string, int> $counts
 * @param array<string, array{count: int, class: string}> $types
 */
function el_audit_walk(array $elements, array $registry, array &$counts, array &$types): void
{
    foreach ($elements as $element) {
        if (!is_array($element)) {
            continue;
        }

        $type = el_element_widget_type($element);
        if ($type === '') {
            $type = (string) ($element['elType'] ?? 'unknown');
        }

        $class = el_audit_classify($type, $registry);
        $counts[$class] = ($counts[$class] ?? 0) + 1;

        if (!isset($types[$type])) {
            $types[$type] = ['count' => 0, 'class' => $class];
        }
        ++$types[$type]['count'];

        /** @var mixed $children */
        $children = $element['elements'] ?? null;
        if (is_array($children) && $children !== []) {
            /** @var list<array<string, mixed>> $children */
            el_audit_walk($children, $registry, $counts, $types);
        }
    }
}

/**
 * Which class an element type belongs to.
 *
 * The container pseudo-key that `el_element_widget_type()` returns for a classic
 * container is resolved back to `container` first, because the audit reports
 * what a person would recognise in the editor rather than an internal key.
 *
 * @param array<string, array{is_atomic: bool, source: string, title: string}> $registry
 */
function el_audit_classify(string $type, array $registry): string
{
    if ($type === WPPILOT_COMPACT_SCHEMA_CONTAINER_KEY) {
        $type = 'container';
    }

    if (el_type_is_atomic($type)) {
        return 'atomic';
    }

    $registered = $registry[$type] ?? null;

    // Structural elements are not in the widget registry, and neither is an
    // element whose plugin has gone. Only the second is a finding, so the
    // structural types are resolved before the registry is consulted.
    $structural = array_key_exists($type, el_atomic_equivalents()) && $registered === null;

    if ($registered === null && !$structural) {
        return 'unregistered';
    }

    if ($registered !== null && $registered['is_atomic']) {
        return 'atomic';
    }

    if (el_atomic_equivalent_for($type) !== null) {
        return 'classic_mapped';
    }

    if ($registered !== null && !in_array($registered['source'], ['elementor', 'elementor-pro'], strict: true)) {
        return 'third_party';
    }

    return 'classic';
}

/**
 * One document's verdict.
 *
 * @param array<string, int> $counts
 */
function el_audit_verdict(array $counts, int $total): string
{
    if ($total === 0) {
        return 'empty';
    }

    if ($counts['atomic'] === $total) {
        return 'atomic';
    }

    if ($counts['atomic'] === 0) {
        return 'classic';
    }

    return 'mixed';
}

/**
 * Fold per-document results into the site report.
 *
 * @param list<array<string, mixed>> $documents
 * @return array<string, mixed>
 */
function el_audit_report(
    array $documents,
    bool $truncated,
    ?int $next_offset,
    bool $include_documents,
    bool $scanned_all,
): array {
    $counts = array_fill_keys(WPPILOT_ELEMENTOR_AUDIT_CLASSES, 0);
    $histogram = [];
    $verdicts = ['atomic' => 0, 'mixed' => 0, 'classic' => 0, 'empty' => 0];

    foreach ($documents as $document) {
        /** @var array<string, int> $document_counts */
        $document_counts = is_array($document['counts'] ?? null) ? $document['counts'] : [];
        foreach ($document_counts as $class => $count) {
            $counts[$class] = ($counts[$class] ?? 0) + (int) $count;
        }

        $verdict = (string) ($document['verdict'] ?? 'empty');
        $verdicts[$verdict] = ($verdicts[$verdict] ?? 0) + 1;

        /** @var array<string, array{count: int, class: string}> $types */
        $types = is_array($document['types'] ?? null) ? $document['types'] : [];
        foreach ($types as $type => $entry) {
            if (!isset($histogram[$type])) {
                $histogram[$type] = [
                    'count' => 0,
                    'documents' => 0,
                    'class' => (string) $entry['class'],
                    'atomic_equivalent' => el_atomic_equivalent_for((string) $type),
                ];
            }
            $histogram[$type]['count'] += (int) $entry['count'];
            ++$histogram[$type]['documents'];
        }
    }

    $total = array_sum($counts);
    $workable = $counts['atomic'] + $counts['classic_mapped'];

    uasort(
        $histogram,
        static fn(array $a, array $b): int => $b['count'] <=> $a['count'],
    );

    $blockers = [];
    foreach ($histogram as $type => $entry) {
        if (in_array($entry['class'], ['atomic', 'classic_mapped'], strict: true)) {
            continue;
        }
        $blockers[] = [
            'type' => $type,
            'count' => $entry['count'],
            'documents' => $entry['documents'],
            'class' => $entry['class'],
            'reason' => el_audit_blocker_reason((string) $entry['class']),
        ];
        if (count($blockers) === 10) {
            break;
        }
    }

    $coverage = $total === 0 ? 0.0 : round($workable / $total, 4);

    $report = [
        'install' => elementor_check_setup(),
        'scope' => [
            'documents_read' => count($documents),
            'complete' => $scanned_all && !$truncated,
            'truncated' => $truncated,
            'next_offset' => $next_offset,
        ],
        'totals' => [
            'elements' => $total,
            'by_class' => $counts,
            'documents_by_verdict' => $verdicts,
        ],
        'coverage' => [
            'atomic_or_mappable' => $coverage,
            'atomic_only' => $total === 0 ? 0.0 : round($counts['atomic'] / $total, 4),
            'explanation' => __(
                'atomic_or_mappable is the share of elements that are already atomic or that GoBuildWP can convert to an atomic element. atomic_only is the share an atomic-only tool can work with today, with no conversion.',
                domain: 'wppilot',
            ),
        ],
        'verdict' => el_audit_site_verdict($counts, $total),
        'blockers' => $blockers,
        'histogram' => $histogram,
        'next_step' => el_audit_next_step($counts, $total),
        'not_checked' => [
            'whether-another-tools-widget-support-matches-this-classification',
            'page-settings-theme-parts-and-kit-styles',
            'documents-not-edited-with-elementor',
        ],
    ];

    if ($include_documents) {
        $report['documents'] = array_map(
            static function (array $document): array {
                unset($document['types']);
                return $document;
            },
            $documents,
        );
    }

    return $report;
}

/**
 * Why a widget type blocks an atomic-only tool.
 */
function el_audit_blocker_reason(string $class): string
{
    return match ($class) {
        'third_party' => __(
            'Provided by a third-party addon. It has no atomic equivalent, and the addon decides whether it ever gets one.',
            domain: 'wppilot',
        ),
        'unregistered' => __(
            'Not registered on this site. The plugin that provided it is inactive or gone, so the element renders as nothing and cannot be converted - its settings are all that survive.',
            domain: 'wppilot',
        ),
        default => __(
            'A classic Elementor widget with no atomic equivalent. It has to be rebuilt from atomic elements rather than translated.',
            domain: 'wppilot',
        ),
    };
}

/**
 * The site-level verdict.
 *
 * @param array<string, int> $counts
 */
function el_audit_site_verdict(array $counts, int $total): string
{
    if ($total === 0) {
        return 'no_content';
    }

    if ($counts['atomic'] === $total) {
        return 'ready';
    }

    if (($counts['atomic'] + $counts['classic_mapped']) / $total >= 0.8) {
        return 'partial';
    }

    return 'not_ready';
}

/**
 * What to do about it, in one sentence.
 *
 * @param array<string, int> $counts
 */
function el_audit_next_step(array $counts, int $total): string
{
    if ($total === 0) {
        return __('No Elementor content was found in this scan.', domain: 'wppilot');
    }

    if ($counts['atomic'] === $total) {
        return __(
            'Every element is atomic. Atomic-only tooling can work on this site as it stands.',
            domain: 'wppilot',
        );
    }

    if ($counts['unregistered'] > 0) {
        return __(
            'Some elements belong to a plugin that is no longer active. Reactivate it before converting anything, or those elements are lost rather than converted - see the blockers list.',
            domain: 'wppilot',
        );
    }

    if (function_exists('wp_get_ability') && wp_get_ability('wppilot/convert-document') !== null) {
        return __(
            'Convert the classic documents with wppilot/convert-preview, then wppilot/convert-document. Preview first: it reports per element what converts cleanly and what does not.',
            domain: 'wppilot',
        );
    }

    return __(
        'The classic elements have to be rebuilt as atomic elements. GoBuildWP Pro converts them; otherwise this is manual work in the editor, and the blockers list is the order to do it in.',
        domain: 'wppilot',
    );
}
