<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\ContentAudit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\ContentAudit\Auditor;

/**
 * The audit's judgement over a site in memory.
 */
final class AuditorTest extends TestCase
{
    private FakeSite $site;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/content-audit/bootstrap.php';
        require_once __DIR__ . '/FakeSite.php';
    }

    protected function setUp(): void
    {
        $this->site = new FakeSite();
    }

    /**
     * Permalinks resolve through WordPress, not HTTP: a published target is fine, a draft is a
     * link visitors cannot follow, a trashed one is broken. Only the archive URL is requested.
     */
    public function testInternalLinksResolveByPostStatusWithoutHttp(): void
    {
        $this->site->add(1, 'home-page', str_repeat('word ', 400) . '<a href="/live/">a</a><a href="/draft/">b</a><a href="https://example.test/gone/">c</a><a href="/category/news/">d</a>');
        $this->site->add(2, 'live', str_repeat('word ', 400));
        $this->site->add(3, 'draft', '', 'page', 'draft');
        $this->site->add(4, 'gone', '', 'page', 'trash');

        $report = $this->audit(['checks' => ['broken_links']]);

        self::assertSame(['https://example.test/category/news/'], $this->site->requests);
        $by_type = $this->byType($report['findings']);
        self::assertSame(3, $by_type['internal_link_to_unpublished'][0]['evidence']['target_id']);
        self::assertSame('draft', $by_type['internal_link_to_unpublished'][0]['evidence']['target_status']);
        self::assertSame('high', $by_type['internal_link_to_unpublished'][0]['severity']);
        self::assertSame(4, $by_type['broken_internal_link'][0]['evidence']['target_id']);
        self::assertSame(1, $by_type['broken_internal_link'][0]['post']['id']);
        self::assertCount(2, $report['findings']);
    }

    public function testHttpAnswersForNonPermalinksBecomeFindings(): void
    {
        $this->site->add(1, 'a', '<a href="/old-route/">1</a><a href="/missing/">2</a><a href="/boom/">3</a><a href="/tag/x/">4</a>');
        $this->site->http = [
            'https://example.test/old-route/' => 301,
            'https://example.test/missing/' => 404,
            'https://example.test/boom/' => 500,
            'https://example.test/tag/x/' => 200,
        ];
        $this->site->locations = ['https://example.test/old-route/' => 'https://example.test/new-route/'];

        $findings = $this->byType($this->audit(['checks' => ['broken_links']])['findings']);

        self::assertSame('low', $findings['redirected_internal_link'][0]['severity']);
        self::assertSame('https://example.test/new-route/', $findings['redirected_internal_link'][0]['evidence']['location']);
        self::assertSame(404, $findings['broken_internal_link'][0]['evidence']['http_status']);
        self::assertSame('medium', $findings['internal_link_error'][0]['severity']);
        self::assertCount(3, array_merge(...array_values($findings)));
    }

    public function testTheHttpBudgetLeavesTheRestUncheckedAndSaysSo(): void
    {
        $this->site->add(1, 'a', '<a href="/x1/">1</a><a href="/x2/">2</a><a href="/x3/">3</a>');
        $this->site->http = ['https://example.test/x1/' => 404, 'https://example.test/x2/' => 404, 'https://example.test/x3/' => 404];

        $report = $this->audit(['checks' => ['broken_links'], 'internal_http_limit' => 1]);

        self::assertCount(1, $this->site->requests);
        self::assertCount(1, $report['findings']);
        self::assertStringContainsString('2 internal links', implode(' ', $report['notes']));
    }

    public function testMissingUploadsAreBrokenFilesAndPresentOnesAreNotRequested(): void
    {
        $present = 'https://example.test/wp-content/uploads/2026/01/a.pdf';
        $this->site->uploads[$present] = true;
        $this->site->add(1, 'a', '<a href="' . $present . '">a</a><a href="/wp-content/uploads/2026/01/b.pdf">b</a>');

        $findings = $this->audit(['checks' => ['broken_links']])['findings'];

        self::assertSame(['broken_file_link'], array_column($findings, 'type'));
        self::assertSame('/wp-content/uploads/2026/01/b.pdf', $findings[0]['evidence']['href']);
        self::assertSame([], $this->site->requests);
    }

    /**
     * The same URL twice in one post is one finding with a count; admin and API URLs are never
     * judged; anchors and mail links are not links to check.
     */
    public function testOccurrencesAreGroupedAndNonPagesSkipped(): void
    {
        $this->site->add(1, 'a', '<a href="/gone/">1</a><a href="/gone/#x">2</a><a href="/wp-admin/post.php">3</a><a href="#top">4</a><a href="mailto:x@y.z">5</a>');
        $this->site->http = ['https://example.test/gone/' => 404];

        $findings = $this->audit(['checks' => ['broken_links']])['findings'];

        self::assertCount(2, $findings, 'the href with a fragment is its own occurrence of the same target');
        self::assertSame(['https://example.test/gone/'], $this->site->requests, 'resolved once, from cache the second time');
    }

    /**
     * Orphans: nothing else links to it, it is not the front or posts page, and no menu has it.
     * A post linking to itself does not rescue it.
     */
    public function testOrphansExcludeLinkedStructuralAndMenuContent(): void
    {
        $this->site->add(1, 'front', '<a href="/linked/">x</a>');
        $this->site->add(2, 'linked', '');
        $this->site->add(3, 'menu-only', '');
        $this->site->add(4, 'blog', '');
        $this->site->add(5, 'lonely', '<a href="/lonely/">me</a>');
        $this->site->add(6, 'lonely-post', '', 'post');
        $this->site->structural = ['front' => 1, 'posts_page' => 4, 'menu' => [3]];

        $findings = $this->audit(['checks' => ['orphans']])['findings'];

        self::assertSame([5, 6], array_map(static fn(array $f): int => $f['post']['id'], $findings));
        self::assertSame(['medium', 'low'], array_column($findings, 'severity'));
        self::assertSame('https://example.test/lonely/', $findings[0]['post']['url']);
    }

    public function testOrphansAreNotJudgedFromAPartialScan(): void
    {
        $this->site->add(1, 'a', '');

        $auditor = new Auditor($this->site, Auditor::options(['checks' => ['orphans']]));
        $state = $auditor->finish($auditor->scan([1], $auditor->start(1)), false);
        $report = $auditor->report($state);

        self::assertSame([], $report['findings']);
        self::assertStringContainsString('Orphans were not checked', $report['notes'][0]);
    }

    public function testThinContentUsesTheThresholdAndGradesBelowHalf(): void
    {
        $this->site->add(1, 'short', str_repeat('word ', 40));
        $this->site->add(2, 'middling', str_repeat('word ', 80));
        $this->site->add(3, 'enough', str_repeat('word ', 100));
        $this->site->add(4, 'builder', '', 'page', 'publish', (string) json_encode([['settings' => ['editor' => str_repeat('word ', 120)]]]));

        $findings = $this->audit(['checks' => ['thin_content'], 'thin_words' => 100])['findings'];

        self::assertSame([1, 2], array_map(static fn(array $f): int => $f['post']['id'], $findings));
        self::assertSame(['medium', 'low'], array_column($findings, 'severity'));
        self::assertSame(40, $findings[0]['evidence']['words']);
        self::assertSame(100, $findings[0]['evidence']['threshold']);
    }

    public function testSeoMetaIsReadFromTheDetectedPluginAndNamesIt(): void
    {
        $this->site->add(1, 'described', '');
        $this->site->add(2, 'bare', '');
        $this->site->meta = [1 => ['rank_math_description' => 'A page.', 'rank_math_title' => 'Title']];
        $this->site->abilities = ['test/rank-math-edit-post-seo'];

        $report = $this->audit(['checks' => ['seo_meta']]);
        $findings = $this->byType($report['findings']);

        self::assertSame(['Rank Math'], $report['stats']['seo_sources']);
        self::assertSame(2, $findings['missing_meta_description'][0]['post']['id']);
        self::assertSame(['Rank Math'], $findings['missing_meta_description'][0]['evidence']['source']);
        self::assertSame(['rank_math_description'], $findings['missing_meta_description'][0]['evidence']['keys_checked']);
        self::assertSame('info', $findings['missing_meta_title'][0]['severity']);
        self::assertSame(['test/rank-math-edit-post-seo'], $findings['missing_meta_description'][0]['suggested_fix']['abilities']);
        self::assertCount(2, $report['findings']);
    }

    public function testTheSeoFrameworkMetaIsReadAndItsAbilitySuggested(): void
    {
        $this->site->add(1, 'described', '');
        $this->site->add(2, 'bare', '');
        $this->site->meta = [1 => ['_genesis_description' => 'A page.']];
        $this->site->abilities = ['test/tsf-update-post-seo'];

        $report = $this->audit(['checks' => ['seo_meta']]);
        $findings = $this->byType($report['findings']);

        self::assertSame(['The SEO Framework'], $report['stats']['seo_sources']);
        self::assertSame('post meta', $report['stats']['seo_read_via']);
        self::assertCount(1, $findings['missing_meta_description']);
        self::assertSame(2, $findings['missing_meta_description'][0]['post']['id']);
        self::assertSame(['_genesis_description'], $findings['missing_meta_description'][0]['evidence']['keys_checked']);
        self::assertSame(['test/tsf-update-post-seo'], $findings['missing_meta_description'][0]['suggested_fix']['abilities']);
    }

    /**
     * With a provider registry (GoBuildWP Pro) the audit reads through it: a deactivated plugin's
     * leftover meta is ignored, an unregistered fix ability is not suggested, and a provider the
     * kit has no meta keys for is still read and named.
     */
    public function testRegistryProvidersAreReadInsteadOfMetaKeys(): void
    {
        $this->site->add(1, 'described', '');
        $this->site->add(2, 'bare', '');
        $this->site->add(3, 'unreadable', '');
        $this->site->meta = [2 => ['_yoast_wpseo_metadesc' => 'Stale, Yoast is off.']];
        $this->site->providers = ['smartcrawl' => 'SmartCrawl', 'acme-seo' => 'Acme SEO'];
        $this->site->providerSeo = [
            'smartcrawl' => [1 => ['title' => '', 'description' => 'Stored.'], 3 => null],
            'acme-seo' => [3 => null],
        ];

        $report = $this->audit(['checks' => ['seo_meta']]);
        $findings = $this->byType($report['findings']);

        self::assertSame(['SmartCrawl', 'Acme SEO'], $report['stats']['seo_sources']);
        self::assertSame('seo provider registry', $report['stats']['seo_read_via']);
        self::assertSame([2], array_map(static fn(array $f): int => $f['post']['id'], $findings['missing_meta_description']));
        self::assertSame(['SmartCrawl', 'Acme SEO'], $findings['missing_meta_description'][0]['evidence']['source']);
        self::assertSame([], $findings['missing_meta_description'][0]['suggested_fix']['abilities'], 'smartcrawl-update-post-seo is not registered here');

        $this->site->abilities = ['test/smartcrawl-update-post-seo'];
        $findings = $this->byType($this->audit(['checks' => ['seo_meta']])['findings']);
        self::assertSame(['test/smartcrawl-update-post-seo'], $findings['missing_meta_description'][0]['suggested_fix']['abilities']);
    }

    public function testSlimSeoArrayMetaKeysAreSplit(): void
    {
        self::assertSame(['slim_seo', 'description'], \WPPilot\Kits\ContentAudit\WpSource::split_key('slim_seo[description]'));
        self::assertSame(['_wds_metadesc', null], \WPPilot\Kits\ContentAudit\WpSource::split_key('_wds_metadesc'));
    }

    public function testNoSeoPluginIsOneSiteFindingNotOnePerPost(): void
    {
        $this->site->add(1, 'a', '');
        $this->site->add(2, 'b', '');

        $findings = $this->audit(['checks' => ['seo_meta']])['findings'];

        self::assertSame(['seo_meta_source_unknown'], array_column($findings, 'type'));
        self::assertNull($findings[0]['post']);
    }

    public function testSchemaIsSampledAcrossTheRunAndPageErrorsAreHigh(): void
    {
        for ($id = 1; $id <= 10; $id++) {
            $this->site->add($id, 'p' . $id, '');
        }
        $this->site->pages['https://example.test/p6/'] = ['status' => 500, 'html' => ''];
        $this->site->pages['https://example.test/p1/'] = ['status' => 200, 'html' => '<script type="application/ld+json">{"@type":"Thing"}</script>'];

        $findings = $this->audit(['checks' => ['schema'], 'schema_sample' => 2])['findings'];

        self::assertSame(['https://example.test/p1/', 'https://example.test/p6/'], $this->site->fetched);
        self::assertSame(['page_error', 'schema_missing_context'], array_column($findings, 'type'));
        self::assertSame(500, $findings[0]['evidence']['http_status']);
    }

    public function testUnfetchablePagesAreNotedNotFailed(): void
    {
        $this->site->add(1, 'a', '');
        $this->site->pages['https://example.test/a/'] = new WP_Error('x', 'loopback blocked');

        $findings = $this->audit(['checks' => ['schema']])['findings'];

        self::assertSame('page_not_fetched', $findings[0]['type']);
        self::assertSame('loopback blocked', $findings[0]['evidence']['error']);
    }

    public function testExternalLinksAreOptInCappedAndBotWallsAreLow(): void
    {
        $this->site->add(1, 'a', '<a href="https://dead.test/">1</a><a href="https://walled.test/">2</a><a href="https://down.test/">3</a><a href="https://fine.test/">4</a>');
        $this->site->http = ['https://dead.test/' => 404, 'https://walled.test/' => 403, 'https://down.test/' => new WP_Error('http', 'timed out')];

        $this->audit(['checks' => ['broken_links']]);
        self::assertSame([], $this->site->requests, 'external links are not checked unless asked');

        $report = $this->audit(['checks' => ['external_links'], 'external_limit' => 3]);
        $findings = $this->byType($report['findings']);

        self::assertCount(3, $this->site->requests);
        self::assertSame('medium', $findings['broken_external_link'][0]['severity']);
        self::assertSame('low', $findings['broken_external_link'][1]['severity']);
        self::assertSame('timed out', $findings['external_link_unreachable'][0]['evidence']['error']);
        self::assertStringContainsString('1 external links were not checked', implode(' ', $report['notes']));
    }

    /**
     * Past the cap the worst findings are kept and the counts stay exact.
     */
    public function testTheFindingCapKeepsTheWorst(): void
    {
        $auditor = new Auditor($this->site, Auditor::options(['checks' => ['seo_meta']]));
        $state = $auditor->start(0);
        $state['findings'] = array_fill(0, Auditor::MAX_FINDINGS, ['type' => 'missing_meta_title', 'severity' => 'info', 'post' => ['id' => 9], 'evidence' => [], 'suggested_fix' => []]);
        $state['counts']['by_severity']['info'] = Auditor::MAX_FINDINGS;
        $this->site->add(1, 'broken', '');
        $this->site->pages['https://example.test/broken/'] = ['status' => 404, 'html' => ''];

        $auditor = new Auditor($this->site, Auditor::options(['checks' => ['schema'], 'schema_sample' => 1]));
        $state = $auditor->scan([1], $state + ['schema_stride' => 1]);
        $report = $auditor->report($state, 0, 1);

        self::assertSame('page_error', $report['findings'][0]['type']);
        self::assertSame(Auditor::MAX_FINDINGS, $report['total_findings']);
        self::assertSame(1, $state['dropped']);
        self::assertSame(1, $report['counts']['by_severity']['high']);
    }

    public function testReportSortsBySeverityPagesAndFilters(): void
    {
        $this->site->add(1, 'a', 'short <a href="/nope/">x</a>');
        $this->site->http = ['https://example.test/nope/' => 404];

        $auditor = new Auditor($this->site, Auditor::options(['checks' => ['broken_links', 'thin_content']]));
        $state = $auditor->finish($auditor->scan([1], $auditor->start(1)), true);

        $all = $auditor->report($state);
        self::assertSame(['broken_internal_link', 'thin_content'], array_column($all['findings'], 'type'));
        $first = $auditor->report($state, 0, 1);
        self::assertSame(1, $first['next_offset']);
        self::assertSame(['thin_content'], array_column($auditor->report($state, 0, 10, ['type' => 'thin_content'])['findings'], 'type'));
        self::assertSame([], $auditor->report($state, 0, 10, ['severity' => 'info'])['findings']);
        self::assertArrayHasKey('broken_internal_link', $all['fixes']);
        self::assertFalse($all['fixes']['broken_internal_link']['abilities'][0]['available']);
    }

    public function testOptionsAreClampedAndUnknownChecksDropped(): void
    {
        $options = Auditor::options(['checks' => ['schema', 'bogus'], 'thin_words' => 1, 'external_limit' => 999, 'post_types' => ['page', 'Post!', ''], 'schema_sample' => 'x']);

        self::assertSame(['schema'], $options['checks']);
        self::assertSame(50, $options['thin_words']);
        self::assertSame(50, $options['external_limit']);
        self::assertSame(['page', 'post'], $options['post_types']);
        self::assertSame(10, $options['schema_sample']);
        self::assertSame(Auditor::DEFAULT_CHECKS, Auditor::options([])['checks']);
        self::assertNotContains('external_links', Auditor::DEFAULT_CHECKS);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function audit(array $input): array
    {
        $options = Auditor::options($input + ['post_types' => ['page', 'post']]);
        $auditor = new Auditor($this->site, $options);
        $ids = $this->site->published_ids($options['post_types'], 0, 1000);
        $state = $auditor->start(count($ids));
        $state = $auditor->scan($ids, $state);
        return $auditor->report($auditor->finish($state, true));
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return array<string, list<array<string, mixed>>>
     */
    private function byType(array $findings): array
    {
        $grouped = [];
        foreach ($findings as $finding) {
            $grouped[$finding['type']][] = $finding;
        }
        return $grouped;
    }
}
