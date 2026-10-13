<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\ScheduledAudits;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\ScheduledAudits as R;

/**
 * Schedules in the site timezone, what a routine may run, a run advanced across cron ticks while
 * the content audit works in the background, the diff between runs, the email, and the undo.
 */
final class RoutinesTest extends TestCase
{
    private static TestHost $host;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 4);
        require_once $root . '/includes/kits/_runtime/runtime.php';
        require_once __DIR__ . '/doubles.php';
        self::$host = new TestHost();
        Runtime\host(self::$host);
        require_once $root . '/includes/kits/scheduled-audits/bootstrap.php';
        // Once per process, as on a real request: the kit guards against registering twice.
        R\register_hooks(self::$host);
    }

    protected function setUp(): void
    {
        Runtime\host(self::$host);
        self::$host->ledger->rows = [];
        Site::reset();
        self::addAudits();
    }

    protected function tearDown(): void
    {
        Site::$active = false;
    }

    private static function at(string $iso): int
    {
        return (int) strtotime($iso);
    }

    /**
     * Accessibility: the front page loses a rule between runs and /page-12/ gains an instance.
     * Content: a background job that is running on the first poll and done on the second.
     * And a write with a read-sounding name, which a routine must refuse.
     */
    private static function addAudits(): void
    {
        $readonly = ['annotations' => ['readonly' => true, 'destructive' => false]];
        Site::$options['test_a11y_round'] = 1;
        Site::$abilities['wppilot/audit-accessibility'] = [
            'meta' => $readonly,
            'schema' => ['type' => 'object', 'properties' => ['url' => [], 'post_id' => []]],
            'run' => static function (array $input): array {
                Site::$calls[] = ['a11y', $input['url'], Site::$user];
                $round = (int) Site::$options['test_a11y_round'];
                $path = (string) parse_url((string) $input['url'], PHP_URL_PATH);
                $findings = [];
                if ($path === '/' && $round === 1) {
                    $findings[] = ['rule' => 'image-alt', 'severity' => 'critical', 'count' => 2, 'examples' => [['html' => '<img src=x>']]];
                }
                if ($path === '/page-12/') {
                    $findings[] = ['rule' => 'link-name', 'severity' => 'serious', 'count' => $round];
                }
                return ['url' => $input['url'], 'status' => 200, 'score' => 90, 'summary' => ['rules_failed' => count($findings), 'instances' => 3], 'findings' => $findings];
            },
        ];
        Site::$options['test_jobs'] = [];
        Site::$options['test_content_findings'] = [];
        Site::$abilities['wppilot/audit-content'] = [
            'meta' => $readonly,
            'schema' => ['type' => 'object', 'properties' => ['mode' => [], 'checks' => [], 'post_id' => [], 'cursor' => [], 'limit' => []]],
            'run' => static function (array $input): array {
                Site::$calls[] = ['content', $input['mode'] ?? '', Site::$user];
                $id = 'job-' . (count(Site::$options['test_jobs']) + 1);
                Site::$options['test_jobs'][$id] = 0;
                return ['mode' => 'background', 'job_id' => $id, 'status' => 'queued'];
            },
        ];
        Site::$abilities['wppilot/audit-content-status'] = [
            'meta' => $readonly,
            'schema' => ['type' => 'object', 'properties' => ['job_id' => [], 'offset' => [], 'limit' => []]],
            'run' => static function (array $input): array {
                $polls = ++Site::$options['test_jobs'][$input['job_id']];
                if ($polls < 2) {
                    return ['job_id' => $input['job_id'], 'status' => 'running', 'progress' => 0.5];
                }
                $findings = Site::$options['test_content_findings'];
                return [
                    'job_id' => $input['job_id'],
                    'status' => 'done',
                    'findings' => $findings,
                    'total_findings' => count($findings),
                    'next_offset' => null,
                    'counts' => ['by_severity' => ['high' => count($findings)], 'by_type' => []],
                    'stats' => ['scanned' => 40, 'complete' => true],
                ];
            },
        ];
        Site::$abilities['wppilot/update-image-alt'] = [
            'meta' => ['annotations' => ['readonly' => false]],
            'schema' => ['type' => 'object', 'properties' => []],
            'run' => static function (): array {
                Site::$calls[] = ['WRITE'];
                return [];
            },
        ];
    }

    /** @return array<string, mixed> */
    private static function base(): array
    {
        return [
            'label' => 'Weekly check',
            'audits' => [
                ['ability' => 'wppilot/audit-accessibility', 'front_page' => true, 'top_pages' => 2],
                ['ability' => 'wppilot/audit-content', 'input' => ['checks' => ['broken_links']]],
            ],
            'schedule' => ['frequency' => 'weekly', 'day' => 'monday', 'hour' => 7],
            'delivery' => ['email_user_ids' => [1, 3]],
        ];
    }

    /** Test options the audit stubs keep; everything else is the kit's. */
    private static function kitOptions(): array
    {
        return array_filter(Site::$options, static fn(string $name): bool => !str_starts_with($name, 'test_'), ARRAY_FILTER_USE_KEY);
    }

    public function testSchedulesAreCalendarTimesInTheSiteTimezone(): void
    {
        $weekly = ['frequency' => 'weekly', 'day' => 'monday', 'hour' => 7];
        // Monday 15:00 in Karachi: this week's 07:00 has passed, so next Monday.
        self::assertSame(self::at('2026-10-05T02:00:00Z'), R\next_run($weekly, self::at('2026-09-28T10:00:00Z')));
        self::assertSame(self::at('2026-09-28T02:00:00Z'), R\next_run($weekly, self::at('2026-09-27T10:00:00Z')));
        self::assertSame(self::at('2026-09-28T18:00:00Z'), R\next_run(['frequency' => 'daily', 'day' => null, 'hour' => 23], self::at('2026-09-28T10:00:00Z')));

        // British Summer Time starts 2026-03-29: 07:00 local is 07:00Z the day before, 06:00Z after.
        Site::$tz = 'Europe/London';
        self::assertSame(self::at('2026-03-29T06:00:00Z'), R\next_run(['frequency' => 'daily', 'hour' => 7], self::at('2026-03-28T08:00:00Z')));
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('refusals')]
    public function testARoutineThatCouldNotRunIsRefusedWhenSaved(array $changes, string $code): void
    {
        $result = R\save(array_merge(self::base(), $changes));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame($code, $result->get_error_code());
        self::assertSame([], self::kitOptions(), 'a refused save stores nothing');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusals(): array
    {
        return [
            'a write ability' => [['audits' => [['ability' => 'wppilot/update-image-alt']]], 'kit_routines_ability_not_allowed'],
            'the runner owns mode' => [['audits' => [['ability' => 'wppilot/audit-content', 'input' => ['mode' => 'page']]]], 'kit_routines_reserved_input'],
            'input outside the ability schema' => [['audits' => [['ability' => 'wppilot/audit-content', 'input' => ['bogus' => 1]]]], 'kit_routines_invalid_input'],
            'a page on another host' => [['audits' => [['ability' => 'wppilot/audit-accessibility', 'urls' => ['https://elsewhere.test/']]]], 'kit_routines_invalid_url'],
            'accessibility without pages' => [['audits' => [['ability' => 'wppilot/audit-accessibility']]], 'kit_routines_no_pages'],
            'a non-administrator recipient' => [['delivery' => ['email_user_ids' => [2]]], 'kit_routines_invalid_recipient'],
            'results going nowhere' => [['delivery' => ['email_user_ids' => [], 'report' => false]], 'kit_routines_no_delivery'],
            'weekly without a day' => [['schedule' => ['frequency' => 'weekly', 'hour' => 7]], 'kit_routines_invalid_schedule'],
            'running as a non-administrator' => [['run_as' => 2], 'kit_routines_invalid_run_as'],
        ];
    }

    /**
     * The whole life of one routine: created, run by hand across ticks, run on schedule with
     * changes, run with a failing audit, edited, deleted, and each write undone.
     */
    public function testARoutineRunsAcrossTicksReportsWhatChangedAndIsUndoable(): void
    {
        $ledger = self::$host->ledger;

        $saved = R\save(self::base());
        self::assertIsArray($saved);
        self::assertTrue($saved['created']);
        self::assertSame('weekly-check', $saved['routine']['id']);
        self::assertSame(1, $saved['routine']['run_as'], 'runs as the caller by default');
        self::assertSame(['email_user_ids' => [1, 3], 'report' => true], $saved['routine']['delivery']);
        self::assertSame([], R\get_routine('weekly-check')['audits'][0]['urls']);
        self::assertSame(2, R\get_routine('weekly-check')['audits'][0]['top_pages']);
        self::assertSame(self::at('2026-10-05T02:00:00Z'), R\wp_next_scheduled(R\HOOK, ['weekly-check']), 'saving schedules the next run');
        self::assertNotFalse(R\wp_next_scheduled(R\RECONCILE_HOOK), 'the hourly repair is scheduled while routines exist');
        $save_row = $ledger->rows[0];
        self::assertSame('wppilot/routines-save', $save_row['ability']);
        self::assertNull($save_row['before']['routine'], 'the create records a before-image of nothing');

        // Run now, advanced across ticks.
        Site::$options['test_content_findings'] = [
            ['type' => 'broken_internal_link', 'severity' => 'high', 'post' => ['id' => 5, 'title' => 'SECRET TITLE', 'type' => 'post', 'url' => 'https://site.test/hello/'], 'evidence' => ['href' => '/missing/']],
        ];
        Site::$user = 3;
        $queued = R\run_now(['id' => 'weekly-check']);
        self::assertIsArray($queued);
        self::assertTrue($queued['queued']);
        self::assertSame(Site::$now, R\wp_next_scheduled(R\HOOK, ['weekly-check']), 'run-now schedules a tick now');
        $again = R\run_now(['id' => 'weekly-check']);
        self::assertInstanceOf(WP_Error::class, $again);
        self::assertSame('kit_routines_already_queued', $again->get_error_code());

        R\tick('weekly-check');
        $state = R\state('weekly-check');
        self::assertSame('done', $state['run']['steps'][0]['status'], 'accessibility finishes in the first tick');
        self::assertSame(['/', '/page-12/', '/page-14/'], $state['run']['steps'][0]['targets'], 'front page, then menu pages, then pages by order; drafts skipped');
        self::assertSame('waiting', $state['run']['steps'][1]['status']);
        self::assertSame('job-1', $state['run']['steps'][1]['job_id'], 'the content audit is started and waited on');
        self::assertSame(3, Site::$user, 'the caller is restored after the tick');
        self::assertSame([1], array_values(array_unique(array_column(Site::$calls, 2))), 'every audit ran as the routine\'s user');
        self::assertSame(Site::$now + R\POLL_DELAY, R\wp_next_scheduled(R\HOOK, ['weekly-check']), 'a waiting run polls again later');
        $running = R\run_now(['id' => 'weekly-check']);
        self::assertInstanceOf(WP_Error::class, $running);
        self::assertSame('kit_routines_running', $running->get_error_code());

        R\tick('weekly-check');
        self::assertIsArray(R\state('weekly-check')['run'], 'still running while the job runs');
        R\tick('weekly-check');
        $state = R\state('weekly-check');
        self::assertNull($state['run']);
        self::assertSame('done', $state['last_run']['status'], 'the run finishes once the job is done');
        self::assertSame(3, $state['last_run']['totals']['issues']);
        self::assertNull($state['last_run']['new'], 'first run: nothing to compare');
        self::assertSame(self::at('2026-10-05T02:00:00Z'), R\wp_next_scheduled(R\HOOK, ['weekly-check']), 'after a manual run the schedule is unchanged');

        self::assertCount(2, Site::$mail, 'one email per recipient');
        self::assertSame('owner@example.test', Site::$mail[0]['to']);
        $mail = Site::$mail[0];
        self::assertStringContainsString('3 issues found (first run)', $mail['subject']);
        self::assertStringContainsString('routine=weekly-check', $mail['body']);
        self::assertStringContainsString('changed nothing', $mail['body']);
        foreach (['SECRET TITLE', '<img', 'second@'] as $private) {
            self::assertStringNotContainsString($private, $mail['body'], 'no titles, markup or other addresses in the email');
        }
        $stored = (string) json_encode(R\reports('weekly-check'));
        self::assertCount(1, R\reports('weekly-check'));
        self::assertStringNotContainsString('SECRET TITLE', $stored, 'the stored report keeps no titles');
        self::assertStringNotContainsString('<img', $stored, 'the stored report keeps no markup');
        self::assertNotContains(['WRITE'], Site::$calls, 'no write ran');

        // Cooldown, then a scheduled run with changes.
        Site::$now += 120;
        $cool = R\run_now(['id' => 'weekly-check']);
        self::assertInstanceOf(WP_Error::class, $cool);
        self::assertSame('kit_routines_cooldown', $cool->get_error_code());

        Site::$options['test_a11y_round'] = 2;
        Site::$options['test_content_findings'][] = ['type' => 'thin_content', 'severity' => 'low', 'post' => ['id' => 9, 'title' => 'x', 'type' => 'post', 'url' => 'https://site.test/new/'], 'evidence' => ['words' => 10]];
        Site::$now = self::at('2026-10-05T02:00:00Z');
        Site::$mail = [];
        R\tick('weekly-check');
        R\tick('weekly-check');
        R\tick('weekly-check');
        $state = R\state('weekly-check');
        self::assertNull($state['run']);
        self::assertSame('schedule', $state['last_run']['trigger'], 'the scheduled run ran');
        self::assertSame(self::at('2026-10-12T02:00:00Z'), (int) $state['next_run'], 'and moved the schedule on a week');
        $diff = R\reports('weekly-check')[0]['diff'];
        self::assertSame(1, $diff['new_count']);
        self::assertSame('thin_content', $diff['new'][0]['what'], 'diff: the new thin page');
        self::assertSame(1, $diff['resolved_count']);
        self::assertSame('image-alt', $diff['resolved'][0]['what'], 'diff: the fixed front-page rule');
        self::assertSame('/', $diff['resolved'][0]['where']);
        self::assertSame(1, $diff['changed_count']);
        self::assertSame(1, $diff['changed'][0]['previous_count'], 'diff: a rule with more instances');
        self::assertSame(2, $diff['changed'][0]['count']);
        self::assertStringContainsString('1 new, 1 resolved', Site::$mail[0]['subject'], 'the email leads with what changed');
        self::assertArrayNotHasKey('issues', R\reports('weekly-check')[1], 'only the newest run keeps its issue list');

        // An audit that fails is not compared: its issues are not "resolved".
        unset(Site::$abilities['wppilot/audit-content-status']);
        Site::$now = self::at('2026-10-12T02:00:00Z');
        R\tick('weekly-check');
        R\tick('weekly-check');
        $latest = R\reports('weekly-check')[0];
        self::assertSame('partial', $latest['status'], 'a failed audit makes a partial run');
        self::assertSame('failed', $latest['steps'][1]['status']);
        self::assertSame(0, $latest['diff']['resolved_count'], 'a failed audit is left out of the diff');
        self::assertSame(['Content audit (broken_links)'], $latest['diff']['not_compared']);

        // Undo.
        $restore = $ledger->strategies[R\STRATEGY];
        R\save(['id' => 'weekly-check', 'label' => 'Renamed']);
        $edit_row = $ledger->rows[count($ledger->rows) - 1];
        self::assertSame('Weekly check', $edit_row['before']['routine']['label'], 'an edit keeps the previous definition');
        // What a session undo compares and a redo puts back: the routine now, in the before-image's shape.
        $redo = R\current_state($edit_row['before']);
        self::assertSame(R\STRATEGY, $redo['type']);
        self::assertSame('Renamed', $redo['routine']['label']);
        self::assertArrayNotHasKey('reports', $redo, 'the state of an edit leaves the runs out, as its before-image does');
        self::assertSame('weekly-check:definition', R\state_target($edit_row['before']));
        $restored = $restore(['snapshot' => $edit_row['before']]);
        self::assertTrue($restored['verified']);
        self::assertSame('Weekly check', R\get_routine('weekly-check')['label'], 'undoing an edit restores the definition');
        self::assertNotSame($redo['fingerprint'], R\current_state($edit_row['before'])['fingerprint'], 'the undo moved the state');
        $redone = $restore(['snapshot' => $redo]);
        self::assertTrue($redone['verified'], 'a redo goes back through the same restore');
        self::assertSame('Renamed', R\get_routine('weekly-check')['label']);
        self::assertTrue($restore(['snapshot' => $edit_row['before']])['verified']);

        $runs_before = count(R\reports('weekly-check'));
        self::assertInstanceOf(WP_Error::class, R\delete(['id' => 'weekly-check']), 'delete needs confirm');
        self::assertNotNull(R\get_routine('weekly-check'));
        $deleted = R\delete(['id' => 'weekly-check', 'confirm' => true]);
        self::assertIsArray($deleted);
        self::assertNull(R\get_routine('weekly-check'));
        self::assertSame([], R\reports('weekly-check'), 'delete removes the routine and its reports');
        self::assertFalse(R\wp_next_scheduled(R\HOOK, ['weekly-check']), 'delete clears its tick');
        self::assertFalse(R\wp_next_scheduled(R\RECONCILE_HOOK), 'and the repair, with no routines left');
        self::assertArrayNotHasKey(R\STATE_PREFIX . 'weekly-check', Site::$options, 'delete clears its state');
        $delete_row = $ledger->rows[count($ledger->rows) - 1];
        self::assertSame('weekly-check:with-reports', R\state_target($delete_row['before']), 'a delete is keyed apart from an edit: its before-image also holds the runs');
        self::assertNull(R\current_state($delete_row['before'])['routine']);
        $restored = $restore(['snapshot' => $delete_row['before']]);
        self::assertTrue($restored['verified']);
        self::assertCount($runs_before, R\reports('weekly-check'), 'undoing a delete brings back the routine and its reports');
        self::assertNotFalse(R\wp_next_scheduled(R\HOOK, ['weekly-check']), 'and reschedules it');

        $restored = $restore(['snapshot' => $save_row['before']]);
        self::assertSame('removed', $restored['restored']);
        self::assertNull(R\get_routine('weekly-check'), 'undoing the create removes the routine');
        self::assertFalse(R\wp_next_scheduled(R\HOOK, ['weekly-check']));
    }

    public function testAnAuditThatIsNoLongerReadOnlyFailsItsStepAndNeverRuns(): void
    {
        $saved = R\save([
            'label' => 'Solo',
            'audits' => [['ability' => 'wppilot/audit-accessibility', 'urls' => ['/about/']]],
            'schedule' => ['frequency' => 'daily', 'day' => null, 'hour' => 23],
        ]);
        self::assertIsArray($saved);
        Site::$abilities['wppilot/audit-accessibility']['meta'] = ['annotations' => ['readonly' => false]];

        R\run_now(['id' => 'solo']);
        R\tick('solo');

        $run = R\reports('solo')[0] ?? [];
        self::assertSame('failed', $run['status'] ?? '');
        self::assertStringContainsString('read-only', (string) $run['steps'][0]['error']);
        self::assertSame([], Site::$calls, 'the audit was never executed');
    }

    public function testATickForARoutineThatNoLongerExistsDropsItsEvent(): void
    {
        R\wp_schedule_single_event(Site::$now, R\HOOK, ['ghost']);

        R\tick('ghost');

        self::assertFalse(R\wp_next_scheduled(R\HOOK, ['ghost']));
    }
}
