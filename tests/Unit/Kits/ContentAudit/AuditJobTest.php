<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\ContentAudit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\ContentAudit;
use WPPilot\Kits\Runtime;

/**
 * The two abilities around the auditor: page mode inline, background mode as a job, and the
 * status ability reading that job back.
 */
final class AuditJobTest extends TestCase
{
    private FakeSite $site;

    private static ?FakeJobs $jobs = null;

    private static int $user = 7;

    private static bool $admin = false;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/content-audit/bootstrap.php';
        require_once __DIR__ . '/FakeSite.php';
        require_once __DIR__ . '/FakeJobs.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/content-audit/src/abilities/audit-content.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/content-audit/src/abilities/audit-content-status.php';
    }

    protected function setUp(): void
    {
        self::$user = 7;
        self::$admin = false;
        ContentAudit\viewer(static fn(): array => ['id' => self::$user, 'admin' => self::$admin]);
        delete_option(ContentAudit\JOBS_OPTION);
        $this->site = new FakeSite();
        ContentAudit\source($this->site);
        self::$jobs = new FakeJobs();
        self::$jobs->register(ContentAudit\JOB_KIND, 'WPPilot\Kits\ContentAudit\step');
        Runtime\host(new FakeAuditHost(self::$jobs));
    }

    public function testBothAbilitiesAreRegistered(): void
    {
        self::assertTrue(wp_has_ability('wppilot/audit-content'));
        self::assertTrue(wp_has_ability('wppilot/audit-content-status'));
    }

    public function testWithoutInputTheWholeSiteGoesToABackgroundJob(): void
    {
        $result = ContentAudit\audit([]);

        self::assertSame('background', $result['mode']);
        self::assertSame('queued', $result['status']);
        self::assertSame(ContentAudit\JOB_KIND, self::$jobs->jobs[$result['job_id']]['kind']);
        self::assertSame(ContentAudit\Auditor::DEFAULT_CHECKS, self::$jobs->jobs[$result['job_id']]['payload']['options']['checks']);
    }

    /**
     * Steps run until the cursor passes the last post; the final step decides orphans, which
     * need every post, and the status ability returns the findings.
     */
    public function testTheJobStepsThroughEveryPostAndStatusReadsIt(): void
    {
        for ($id = 1; $id <= 45; $id++) {
            $this->site->add($id, 'p' . $id, $id === 1 ? '<a href="/p2/">x</a>' : '');
        }
        $started = ContentAudit\audit(['checks' => ['orphans']]);
        $id = $started['job_id'];

        $steps = self::$jobs->run($id);

        self::assertSame(4, $steps, '20 + 20 + 5, then the step that finds nothing and finishes');
        $status = ContentAudit\status(['job_id' => $id, 'limit' => 500]);
        self::assertSame('done', $status['status']);
        self::assertTrue($status['stats']['complete']);
        self::assertSame(45, $status['stats']['scanned']);
        self::assertSame(44, $status['total_findings'], 'every post but the one post 1 links to');
        self::assertSame(44, $status['counts']['by_type']['orphan_content']);
    }

    public function testStatusShowsPartialFindingsWhileRunning(): void
    {
        for ($id = 1; $id <= 30; $id++) {
            $this->site->add($id, 'p' . $id, 'thin');
        }
        $id = ContentAudit\audit(['checks' => ['thin_content']])['job_id'];
        self::$jobs->run($id, 1);

        $status = ContentAudit\status(['job_id' => $id]);

        self::assertSame('running', $status['status']);
        self::assertFalse($status['stats']['complete']);
        self::assertSame(20, $status['total_findings']);
        self::assertEqualsWithDelta(20 / 30, $status['progress'], 0.001);
    }

    public function testStatusRefusesOtherUsersJobsAndUnknownIds(): void
    {
        $id = ContentAudit\audit([])['job_id'];

        self::$user = 8;
        $forbidden = ContentAudit\status(['job_id' => $id]);
        self::assertInstanceOf(WP_Error::class, $forbidden);
        self::assertSame('kit_content_audit_job_forbidden', $forbidden->get_error_code());

        self::$admin = true;
        self::assertSame('queued', ContentAudit\status(['job_id' => $id])['status']);

        $missing = ContentAudit\status(['job_id' => 'nope']);
        self::assertInstanceOf(WP_Error::class, $missing);
        self::assertSame(404, $missing->get_error_data()['status']);
    }

    public function testWithoutAJobIdStatusListsTheCallersRecentAudits(): void
    {
        $first = ContentAudit\audit([])['job_id'];
        self::$user = 8;
        ContentAudit\audit([]);
        self::$user = 7;

        $listed = ContentAudit\status([]);

        self::assertSame([$first], array_column($listed['jobs'], 'job_id'));
    }

    public function testPageModeReturnsTheNextCursorAndLeavesOrphansToAFullScan(): void
    {
        for ($id = 1; $id <= 5; $id++) {
            $this->site->add($id, 'p' . $id, '');
        }

        $first = ContentAudit\audit(['mode' => 'page', 'limit' => 3, 'checks' => ['orphans']]);
        self::assertSame(3, $first['next_cursor']);
        self::assertSame([], $first['findings']);
        self::assertSame(5, $first['stats']['total']);

        $last = ContentAudit\audit(['mode' => 'page', 'limit' => 3, 'cursor' => 3, 'checks' => ['orphans']]);
        self::assertNull($last['next_cursor']);
        self::assertSame(2, $last['stats']['scanned']);

        $whole = ContentAudit\audit(['mode' => 'page', 'limit' => 10, 'checks' => ['orphans']]);
        self::assertSame(5, $whole['counts']['by_type']['orphan_content']);
    }

    public function testPageModeSamplesFewPagesUnlessAsked(): void
    {
        for ($id = 1; $id <= 9; $id++) {
            $this->site->add($id, 'p' . $id, '');
        }

        ContentAudit\audit(['mode' => 'page', 'limit' => 9, 'checks' => ['schema']]);

        self::assertCount(3, $this->site->fetched);
    }

    public function testOnePostIsAuditedInlineAndMustBePublished(): void
    {
        $this->site->add(1, 'live', 'few words');
        $this->site->add(2, 'draft', 'few words', 'page', 'draft');

        $result = ContentAudit\audit(['post_id' => 1, 'checks' => ['thin_content']]);
        self::assertSame('page', $result['mode']);
        self::assertSame(['thin_content'], array_column($result['findings'], 'type'));

        $refused = ContentAudit\audit(['post_id' => 2]);
        self::assertInstanceOf(WP_Error::class, $refused);
    }
}

