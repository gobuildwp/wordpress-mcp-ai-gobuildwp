<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function WPPilot\Elementor\el_atomic_equivalent_for;
use function WPPilot\Elementor\el_audit_classify;
use function WPPilot\Elementor\el_audit_report;
use function WPPilot\Elementor\el_audit_site_verdict;
use function WPPilot\Elementor\el_audit_verdict;
use function WPPilot\Elementor\el_audit_walk;

// The Elementor module is not part of the suite bootstrap: it registers a
// category and a dozen abilities, and the boot snapshot other tests assert
// against is taken before this file loads. Only the layers the audit's
// classification actually needs are required here, and all three are pure -
// constants, a type resolver, and the equivalents table.
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
foreach ([
    // bootstrap declares el_elementor_version() and its siblings; its file-scope
    // hooks land on the doubles and register nothing.
    'bootstrap.php',
    'wppilot-schema-extractor.php',
    'helpers/wppilot-tree.php',
    'helpers/wppilot-atomic-equivalents.php',
    'wppilot-runtime.php',
    // The report embeds check-setup's output verbatim. Without Elementor it
    // reports active:false, which is the honest answer and enough here.
    'wppilot-check-setup.php',
    'wppilot-audit-atomic-readiness.php',
] as $wppilot_audit_test_file) {
    require_once dirname(__DIR__, 2) . '/includes/elementor/' . $wppilot_audit_test_file;
}
unset($wppilot_audit_test_file);

/**
 * The audit exists to answer one question honestly: how much of this site can an
 * atomic-only tool touch. Every way of getting that wrong is silent on the site
 * that has it - an over-optimistic count sends someone into a migration that
 * strands half their pages, and an over-pessimistic one tells them to rebuild a
 * site that would have converted.
 */
final class ElementorAtomicAuditTest extends TestCase
{
    /** @var array<string, array{is_atomic: bool, source: string, title: string}> */
    private const REGISTRY = [
        'heading' => ['is_atomic' => false, 'source' => 'elementor', 'title' => 'Heading'],
        'text-editor' => ['is_atomic' => false, 'source' => 'elementor', 'title' => 'Text Editor'],
        'icon-list' => ['is_atomic' => false, 'source' => 'elementor', 'title' => 'Icon List'],
        'form' => ['is_atomic' => false, 'source' => 'elementor-pro', 'title' => 'Form'],
        'acme-slider' => ['is_atomic' => false, 'source' => 'acme-addons', 'title' => 'Acme Slider'],
        'e-heading' => ['is_atomic' => true, 'source' => 'elementor', 'title' => 'Heading'],
    ];

    // ------------------------------------------------------------ classification

    public function test_an_atomic_element_is_atomic_on_its_prefix_alone(): void
    {
        // Atomic elements carry their type in elType, and a site can have one
        // whose class is not in the widget registry at all.
        self::assertSame('atomic', el_audit_classify('e-flexbox', []));
        self::assertSame('atomic', el_audit_classify('e-heading', self::REGISTRY));
    }

    public function test_a_classic_widget_with_an_equivalent_is_not_a_blocker(): void
    {
        self::assertSame('classic_mapped', el_audit_classify('heading', self::REGISTRY));
        self::assertSame('classic_mapped', el_audit_classify('text-editor', self::REGISTRY));
    }

    public function test_classic_structure_is_mappable_although_it_is_not_in_the_widget_registry(): void
    {
        // Sections, columns and containers are elements rather than widgets, so
        // the registry never lists them. Treating an absent entry as a missing
        // plugin would report every classic page as full of dead elements.
        self::assertSame('classic_mapped', el_audit_classify('section', self::REGISTRY));
        self::assertSame('classic_mapped', el_audit_classify('column', self::REGISTRY));
        self::assertSame(
            'classic_mapped',
            el_audit_classify(\WPPilot\Elementor\WPPILOT_COMPACT_SCHEMA_CONTAINER_KEY, self::REGISTRY),
        );
    }

    public function test_a_first_party_widget_with_no_equivalent_is_classic(): void
    {
        self::assertSame('classic', el_audit_classify('icon-list', self::REGISTRY));
        self::assertSame('classic', el_audit_classify('form', self::REGISTRY));
    }

    public function test_an_addons_widget_is_reported_as_third_party(): void
    {
        // Worth its own class: whether it ever gets an atomic equivalent is the
        // addon author's decision, not Elementor's and not GoBuildWP's.
        self::assertSame('third_party', el_audit_classify('acme-slider', self::REGISTRY));
    }

    public function test_a_widget_whose_plugin_is_gone_is_unregistered_not_classic(): void
    {
        self::assertSame('unregistered', el_audit_classify('deactivated-widget', self::REGISTRY));
    }

    // ------------------------------------------------------------ walking a tree

    public function test_the_walk_counts_every_element_at_every_depth(): void
    {
        $tree = [
            [
                'elType' => 'section',
                'elements' => [
                    [
                        'elType' => 'column',
                        'elements' => [
                            ['elType' => 'widget', 'widgetType' => 'heading'],
                            ['elType' => 'widget', 'widgetType' => 'icon-list'],
                            ['elType' => 'widget', 'widgetType' => 'acme-slider'],
                        ],
                    ],
                ],
            ],
        ];

        [$counts, $types] = self::walk($tree);

        self::assertSame(5, array_sum($counts));
        // section, column and heading all have atomic equivalents.
        self::assertSame(3, $counts['classic_mapped']);
        self::assertSame(1, $counts['classic']);
        self::assertSame(1, $counts['third_party']);
        self::assertSame(0, $counts['atomic']);
        self::assertSame(1, $types['heading']['count']);
        self::assertSame('classic', $types['icon-list']['class']);
    }

    public function test_the_walk_recognises_an_atomic_tree(): void
    {
        $tree = [
            [
                'elType' => 'e-flexbox',
                'elements' => [
                    ['elType' => 'e-heading'],
                    ['elType' => 'e-paragraph'],
                ],
            ],
        ];

        [$counts] = self::walk($tree);

        self::assertSame(3, $counts['atomic']);
        self::assertSame(0, $counts['classic'] + $counts['classic_mapped']);
    }

    // ------------------------------------------------------------ verdicts

    public function test_document_verdicts(): void
    {
        self::assertSame('empty', el_audit_verdict(self::counts(), 0));
        self::assertSame('atomic', el_audit_verdict(self::counts(atomic: 4), 4));
        self::assertSame('classic', el_audit_verdict(self::counts(classic: 4), 4));
        self::assertSame('mixed', el_audit_verdict(self::counts(atomic: 2, classic: 2), 4));
    }

    /**
     * A mostly-mappable site is `partial` rather than `not_ready`, because the
     * work in front of it is a conversion rather than a rebuild. The threshold
     * counts mappable elements, not atomic ones.
     */
    public function test_site_verdicts_count_mappable_elements_towards_readiness(): void
    {
        self::assertSame('no_content', el_audit_site_verdict(self::counts(), 0));
        self::assertSame('ready', el_audit_site_verdict(self::counts(atomic: 10), 10));
        self::assertSame('partial', el_audit_site_verdict(self::counts(atomic: 1, classic_mapped: 8, classic: 1), 10));
        self::assertSame('not_ready', el_audit_site_verdict(self::counts(atomic: 1, classic: 9), 10));
    }

    // ------------------------------------------------------------ the report

    public function test_the_report_separates_what_works_today_from_what_could(): void
    {
        $report = self::report([
            self::document(['atomic' => 2, 'classic_mapped' => 6, 'classic' => 2], 'mixed', [
                'e-heading' => ['count' => 2, 'class' => 'atomic'],
                'section' => ['count' => 6, 'class' => 'classic_mapped'],
                'icon-list' => ['count' => 2, 'class' => 'classic'],
            ]),
        ]);

        self::assertSame(0.8, $report['coverage']['atomic_or_mappable']);
        self::assertSame(0.2, $report['coverage']['atomic_only']);
        self::assertSame('partial', $report['verdict']);
    }

    public function test_blockers_are_the_unmappable_types_ranked_by_how_much_they_block(): void
    {
        $report = self::report([
            self::document(['classic' => 9, 'third_party' => 1], 'classic', [
                'icon-list' => ['count' => 3, 'class' => 'classic'],
                'toggle' => ['count' => 6, 'class' => 'classic'],
                'acme-slider' => ['count' => 1, 'class' => 'third_party'],
                'heading' => ['count' => 40, 'class' => 'classic_mapped'],
            ]),
        ]);

        $types = array_column($report['blockers'], 'type');

        // Ranked by count, and the mappable type with the largest count of all
        // is absent: it is not blocking anything.
        self::assertSame(['toggle', 'icon-list', 'acme-slider'], $types);
        self::assertNotEmpty($report['blockers'][0]['reason']);
    }

    public function test_a_dead_plugins_elements_are_called_out_before_any_conversion(): void
    {
        $report = self::report([
            self::document(['classic_mapped' => 5, 'unregistered' => 1], 'classic', [
                'heading' => ['count' => 5, 'class' => 'classic_mapped'],
                'ghost' => ['count' => 1, 'class' => 'unregistered'],
            ]),
        ]);

        self::assertStringContainsString('no longer active', $report['next_step']);
    }

    public function test_the_report_drops_the_per_document_type_map_but_keeps_the_histogram(): void
    {
        // The per-document type map is an implementation detail of the fold and
        // would double the payload on a site with hundreds of documents.
        $report = self::report([
            self::document(['atomic' => 1], 'atomic', ['e-heading' => ['count' => 1, 'class' => 'atomic']]),
        ]);

        self::assertArrayNotHasKey('types', $report['documents'][0]);
        self::assertSame(1, $report['histogram']['e-heading']['count']);
        self::assertSame(1, $report['histogram']['e-heading']['documents']);
    }

    public function test_the_histogram_names_the_atomic_equivalent_a_converter_would_use(): void
    {
        $report = self::report([
            self::document(['classic_mapped' => 1], 'classic', ['text-editor' => ['count' => 1, 'class' => 'classic_mapped']]),
        ]);

        self::assertSame('e-paragraph', $report['histogram']['text-editor']['atomic_equivalent']);
        self::assertSame('e-paragraph', el_atomic_equivalent_for('text-editor'));
        self::assertNull(el_atomic_equivalent_for('icon-list'));
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param list<array<string, mixed>> $tree
     * @return array{0: array<string, int>, 1: array<string, array{count: int, class: string}>}
     */
    private static function walk(array $tree): array
    {
        $counts = self::counts();
        $types = [];
        el_audit_walk($tree, self::REGISTRY, $counts, $types);

        return [$counts, $types];
    }

    /** @return array<string, int> */
    private static function counts(
        int $atomic = 0,
        int $classic_mapped = 0,
        int $classic = 0,
        int $third_party = 0,
        int $unregistered = 0,
    ): array {
        return [
            'atomic' => $atomic,
            'classic_mapped' => $classic_mapped,
            'classic' => $classic,
            'third_party' => $third_party,
            'unregistered' => $unregistered,
        ];
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, array{count: int, class: string}> $types
     * @return array<string, mixed>
     */
    private static function document(array $counts, string $verdict, array $types): array
    {
        return [
            'id' => 7,
            'title' => 'Home',
            'post_type' => 'page',
            'counts' => [...self::counts(), ...$counts],
            'types' => $types,
            'verdict' => $verdict,
        ];
    }

    /**
     * @param list<array<string, mixed>> $documents
     * @return array<string, mixed>
     */
    private static function report(array $documents): array
    {
        // elementor_check_setup() reads Elementor's own singletons, which do not
        // exist here; the report's `install` block is its output verbatim and is
        // covered by elementor-check-setup's own surface rather than by this.
        return el_audit_report($documents, truncated: false, next_offset: null, include_documents: true, scanned_all: true);
    }
}
