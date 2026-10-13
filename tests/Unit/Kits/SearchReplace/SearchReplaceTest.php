<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SearchReplace;

use Kit_Test_Site;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\PostPartial;
use WPPilot\Kits\SearchReplace as SR;

require_once __DIR__ . '/harness.php';

/**
 * Preview, apply, jobs and undo, against a fake host and posts held in Kit_Test_Site.
 */
final class SearchReplaceTest extends TestCase
{
    private FakeHost $host;

    private mixed $previousWpdb = null;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/search-replace/bootstrap.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/search-replace/src/abilities/search-replace-preview.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/search-replace/src/abilities/search-replace-apply.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/search-replace/src/abilities/search-replace-status.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/search-replace/src/abilities/search-replace-cancel.php';
    }

    protected function setUp(): void
    {
        Kit_Test_Site::reset();
        Kit_Test_Site::register_post_types('post', 'page');
        Kit_Test_Site::as_user(1, 'edit_post');
        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new FakeWpdb();
        $this->host = new FakeHost();
        Runtime\host($this->host);
        $kit = require dirname(__DIR__, 4) . '/includes/kits/search-replace/bootstrap.php';
        ($kit['boot'])($this->host);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->previousWpdb;
        Kit_Test_Site::reset();
    }

    private static function post(string $title, string $content = '', string $type = 'page', string $status = 'publish'): int
    {
        return Kit_Test_Site::insert(['post_title' => $title, 'post_content' => $content, 'post_type' => $type, 'post_status' => $status]);
    }

    /** @return array<string, mixed> */
    private static function preview(array $input): array
    {
        $result = SR\preview($input);
        self::assertIsArray($result, $result instanceof WP_Error ? $result->get_error_message() : '');
        return $result;
    }

    /** @return array<string, mixed> */
    private static function apply(array $input): array
    {
        $result = SR\apply($input + ['confirm' => true]);
        self::assertIsArray($result, $result instanceof WP_Error ? $result->get_error_message() : '');
        return $result;
    }

    public function testTheAbilitiesAreRegisteredWithTheirLiteralNames(): void
    {
        self::assertTrue(wp_has_ability('wppilot/search-replace-preview'));
        self::assertTrue(wp_has_ability('wppilot/search-replace-apply'));
        self::assertTrue(wp_has_ability('wppilot/search-replace-status'));
        self::assertTrue(wp_has_ability('wppilot/search-replace-cancel'));
    }

    public function testPreviewStoresAPlanAndWritesNothing(): void
    {
        $id = self::post('Welcome to Old Co', 'Old Co makes things. Old Co ships.');
        self::post('Unrelated', 'nothing here');

        $plan = self::preview(['search' => 'Old Co', 'replace' => 'New Co']);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $plan['plan_id']);
        self::assertTrue($plan['complete']);
        self::assertSame(['posts_scanned' => 2, 'posts' => 1, 'matches' => 3, 'not_editable' => 0, 'skipped' => 0, 'pending' => 1], $plan['totals']);
        self::assertSame($id, $plan['posts'][0]['post_id']);
        self::assertSame(['post_title', 'post_content'], array_column($plan['posts'][0]['changes'], 'target'));
        self::assertSame(2, $plan['posts'][0]['changes'][1]['count']);
        self::assertSame('Welcome to Old Co', get_post($id)->post_title);
        self::assertSame([], $this->host->ledger->calls);
    }

    public function testScopeRefusesGuidOptionsAndHistoryPostTypes(): void
    {
        Kit_Test_Site::register_post_types('revision');

        $guid = SR\preview(['search' => 'x', 'fields' => ['guid']]);
        $revision = SR\preview(['search' => 'x', 'post_types' => ['revision']]);

        self::assertInstanceOf(WP_Error::class, $guid);
        self::assertSame('kit_sr_bad_field', $guid->get_error_code());
        self::assertInstanceOf(WP_Error::class, $revision);
        self::assertSame('kit_sr_bad_post_type', $revision->get_error_code());
    }

    public function testPostsTheUserCannotEditAreNeitherShownNorPlanned(): void
    {
        self::post('Old Co');
        Kit_Test_Site::as_user(1);

        $plan = self::preview(['search' => 'Old Co', 'replace' => 'New Co']);

        self::assertNull($plan['plan_id']);
        self::assertSame(1, $plan['totals']['not_editable']);
        self::assertSame([], $plan['posts']);
    }

    public function testMetaSkipsAreReportedAndBookkeepingMetaIsNeverTouched(): void
    {
        $id = self::post('Page');
        Kit_Test_Site::set_raw_meta($id, '_wp_old_slug', ['old-co']);
        Kit_Test_Site::set_raw_meta($id, 'links', ['old-co one', 'old-co two']);
        Kit_Test_Site::set_raw_meta($id, 'widget', ['a:1:{s:1:"o";O:8:"stdClass":1:{s:1:"t";s:6:"old-co";}}']);
        Kit_Test_Site::set_raw_meta($id, 'tagline', ['old-co forever']);

        $plan = self::preview(['search' => 'old-co', 'replace' => 'new-co', 'fields' => [], 'meta_keys' => ['*']]);

        self::assertSame(['meta:tagline'], array_column($plan['posts'][0]['changes'], 'target'));
        $skips = array_column($plan['skipped'], 'reason', 'target');
        self::assertSame(['meta:links' => 'kit_sr_multiple_values', 'meta:widget' => 'kit_sr_serialized_object'], $skips);
    }

    public function testApplyRefusesWithoutConfirmation(): void
    {
        $id = self::post('Old Co');
        $plan = self::preview(['search' => 'Old Co', 'replace' => 'New Co']);

        $result = SR\apply(['plan_id' => $plan['plan_id']]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('kit_confirmation_required', $result->get_error_code());
        self::assertSame('Old Co', get_post($id)->post_title);
    }

    public function testApplyWritesEveryEncodingAndRecordsOneUndoableRowPerPostInThePlanGroup(): void
    {
        $elementor = json_encode([['settings' => ['url' => 'https://old.example/a', 'html' => '<p class=\"x\">old.example</p>'], 'elements' => (object) []]]);
        $first = self::post('Home on old.example', 'See https://old.example/about');
        Kit_Test_Site::set_raw_meta($first, '_elementor_data', [$elementor]);
        Kit_Test_Site::set_raw_meta($first, '_elementor_edit_mode', ['builder']);
        Kit_Test_Site::set_raw_meta($first, 'untouched', ['old.example stays']);
        $second = self::post('Contact');
        Kit_Test_Site::set_raw_meta($second, 'settings', [serialize(['links' => ['old.example', 'other'], 'count' => 2])]);

        $plan = self::preview(['search' => 'old.example', 'replace' => 'new.example', 'meta_keys' => ['_elementor_data', 'settings']]);
        $result = self::apply(['plan_id' => $plan['plan_id']]);

        self::assertSame(2, $result['applied']);
        self::assertSame(0, $result['remaining']);
        self::assertNull($result['cursor']);
        self::assertSame('Home on new.example', get_post($first)->post_title);
        self::assertSame('See https://new.example/about', get_post($first)->post_content);
        self::assertSame(str_replace('old.example', 'new.example', $elementor), Kit_Test_Site::raw_meta($first)['_elementor_data'][0]);
        self::assertSame(['old.example stays'], Kit_Test_Site::raw_meta($first)['untouched']);
        self::assertSame(['links' => ['new.example', 'other'], 'count' => 2], unserialize(Kit_Test_Site::raw_meta($second)['settings'][0]));
        self::assertTrue($result['posts'][0]['verified']);

        self::assertCount(1, $this->host->ledger->calls);
        self::assertSame('wppilot/search-replace-apply', $this->host->ledger->calls[0]['ability']);
        self::assertSame($plan['group'], $this->host->ledger->calls[0]['group']);
        $rows = $this->host->ledger->rows();
        self::assertSame([$first, $second], array_column(array_column($rows, 'before'), 'post_id'));
        self::assertSame(PostPartial\TYPE, $rows[0]['before']['type']);
        // Exactly what the plan touches: no other field, no other meta key.
        self::assertSame(['post_title', 'post_content'], array_keys($rows[0]['before']['fields']));
        self::assertSame(['_elementor_data'], array_keys($rows[0]['before']['meta']));
        self::assertSame([], $rows[1]['before']['fields']);
        self::assertSame(['settings'], array_keys($rows[1]['before']['meta']));
        self::assertSame('change-1-0', $result['posts'][0]['change_id']);

        $elementorCache = array_values(array_filter($result['caches'], static fn(array $c): bool => $c['builder'] === 'elementor'));
        self::assertSame([$first], $elementorCache[0]['post_ids']);
        // Elementor is not loaded here, so nothing claims to have cleared it.
        self::assertFalse($elementorCache[0]['cleared']);
    }

    public function testPostsChangedSincePreviewAreSkippedAndTheRestApplied(): void
    {
        $kept = self::post('Old Co one');
        $edited = self::post('Old Co two');
        $plan = self::preview(['search' => 'Old Co', 'replace' => 'New Co']);
        get_post($edited)->post_title = 'Old Co two, edited by a person';

        $result = self::apply(['plan_id' => $plan['plan_id']]);

        self::assertSame(1, $result['applied']);
        self::assertSame('New Co one', get_post($kept)->post_title);
        self::assertSame('Old Co two, edited by a person', get_post($edited)->post_title);
        self::assertSame([['post_id' => $edited, 'title' => 'Old Co two', 'reason' => 'kit_sr_changed_since_preview']], array_map(
            static fn(array $s): array => ['post_id' => $s['post_id'], 'title' => $s['title'], 'reason' => $s['reason']],
            $result['skipped'],
        ));
        self::assertCount(1, $this->host->ledger->rows());
        // A skipped post is settled: applying again neither retries nor reports it.
        $again = self::apply(['plan_id' => $plan['plan_id']]);
        self::assertSame(0, $again['applied']);
        self::assertSame([], $again['skipped']);
    }

    public function testAtMostOneHundredPostsPerCallThenTheCursorFinishesTheSameGroup(): void
    {
        for ($i = 0; $i < 150; $i++) {
            self::post('Old Co ' . $i);
        }
        $plan = self::preview(['search' => 'Old Co', 'replace' => 'New Co']);

        $first = self::apply(['plan_id' => $plan['plan_id']]);
        self::assertSame(100, $first['applied']);
        self::assertSame(50, $first['remaining']);
        self::assertSame('batch_limit', $first['stopped_by']);
        self::assertSame($plan['plan_id'], $first['cursor']['plan_id']);

        $second = self::apply(['plan_id' => $first['cursor']['plan_id']]);
        self::assertSame(50, $second['applied']);
        self::assertSame(0, $second['remaining']);
        self::assertNull($second['cursor']);

        self::assertSame([$plan['group'], $plan['group']], array_column($this->host->ledger->calls, 'group'));
        self::assertCount(150, $this->host->ledger->rows());
    }

    public function testASubsetAppliesOnlyTheNamedPosts(): void
    {
        $a = self::post('Old Co a');
        $b = self::post('Old Co b');
        $plan = self::preview(['search' => 'Old Co', 'replace' => 'New Co']);

        $result = self::apply(['plan_id' => $plan['plan_id'], 'post_ids' => [$b, 999]]);

        self::assertSame(1, $result['applied']);
        self::assertSame('Old Co a', get_post($a)->post_title);
        self::assertSame('New Co b', get_post($b)->post_title);
    }

    public function testTheSnapshotBudgetStopsTheCallBeforeAnyPostWouldLackABeforeImage(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::post('Old Co ' . $i, str_repeat('Old Co body ', 20));
        }
        $plan = self::preview(['search' => 'Old Co', 'replace' => 'New Co']);
        $one = SR\snapshot_bytes(PostPartial\capture(1, ['post_title', 'post_content']));
        $this->host->ledger->budget = (int) ($one * 2.5);

        $first = self::apply(['plan_id' => $plan['plan_id']]);

        self::assertSame(2, $first['applied']);
        self::assertSame('snapshot_budget', $first['stopped_by']);
        self::assertSame(3, $first['remaining']);
        self::assertSame('Old Co 2', get_post(3)->post_title);
        foreach ($this->host->ledger->rows() as $row) {
            self::assertIsArray($row['before']);
        }

        self::apply(['plan_id' => $plan['plan_id']]);
        $last = self::apply(['plan_id' => $plan['plan_id']]);
        self::assertSame(0, $last['remaining']);
        self::assertCount(5, $this->host->ledger->rows());
    }

    public function testAPostTooLargeToUndoIsLeftOutOfThePlan(): void
    {
        self::post('Title', 'Old Co ' . str_repeat('x', 5000));
        $this->host->ledger->budget = 1000;

        $plan = self::preview(['search' => 'Old Co', 'replace' => 'New Co']);

        self::assertNull($plan['plan_id']);
        self::assertSame('kit_sr_too_large_to_undo', $plan['skipped'][0]['reason']);
    }

    public function testAPlanIsBoundToItsUserAndExpires(): void
    {
        self::post('Old Co');
        $plan = self::preview(['search' => 'Old Co', 'replace' => 'New Co']);

        Kit_Test_Site::as_user(2, 'edit_post');
        $other = SR\apply(['plan_id' => $plan['plan_id'], 'confirm' => true]);
        self::assertInstanceOf(WP_Error::class, $other);
        self::assertSame('kit_sr_plan_not_yours', $other->get_error_code());

        Kit_Test_Site::as_user(1, 'edit_post');
        $stored = get_option(SR\PLAN_OPTION_PREFIX . $plan['plan_id']);
        $stored['expires_at'] = time() - 1;
        update_option(SR\PLAN_OPTION_PREFIX . $plan['plan_id'], $stored);
        $expired = SR\apply(['plan_id' => $plan['plan_id'], 'confirm' => true]);
        self::assertInstanceOf(WP_Error::class, $expired);
        self::assertSame('kit_sr_plan_expired', $expired->get_error_code());
        self::assertFalse(get_option(SR\PLAN_OPTION_PREFIX . $plan['plan_id']));

        $missing = SR\apply(['plan_id' => 'not-a-plan', 'confirm' => true]);
        self::assertSame('kit_sr_plan_not_found', $missing->get_error_code());
    }

    public function testALockedPlanIsRefusedRatherThanAppliedTwice(): void
    {
        self::post('Old Co');
        $plan = self::preview(['search' => 'Old Co', 'replace' => 'New Co']);
        self::assertTrue(SR\lock_plan($plan['plan_id']));

        $result = SR\apply(['plan_id' => $plan['plan_id'], 'confirm' => true]);

        self::assertSame('kit_sr_plan_busy', $result->get_error_code());
        SR\unlock_plan($plan['plan_id']);
    }

    public function testABackgroundJobAppliesThePlanAndStatusReportsIt(): void
    {
        for ($i = 0; $i < 120; $i++) {
            self::post('Old Co ' . $i);
        }
        $plan = self::preview(['search' => 'Old Co', 'replace' => 'New Co']);

        $queued = self::apply(['plan_id' => $plan['plan_id'], 'background' => true]);

        self::assertSame('queued', $queued['status']);
        self::assertSame(['ability' => 'wppilot/search-replace-status', 'input' => ['job_id' => $queued['job_id']]], $queued['poll']);
        self::assertSame([], $this->host->ledger->calls[0]['items']);
        self::assertSame('Old Co 0', get_post(1)->post_title);

        $this->host->jobs->run($queued['job_id']);

        $status = SR\status(['job_id' => $queued['job_id']]);
        self::assertSame('done', $status['job']['status']);
        self::assertSame(120, $status['job']['applied']);
        self::assertSame(120, $status['plan']['counts']['applied']);
        self::assertSame(0, $status['plan']['counts']['pending']);
        self::assertSame('New Co 119', get_post(120)->post_title);
        $groups = array_unique(array_column($this->host->ledger->calls, 'group'));
        self::assertSame([$plan['group']], array_values($groups));

        Kit_Test_Site::as_user(2, 'edit_post');
        self::assertSame('kit_sr_job_not_found', SR\status(['job_id' => $queued['job_id']])->get_error_code());
    }

    /**
     * A cancel stops the job after the step in flight, leaves the rest of the plan pending so it
     * can be applied again, records an honest irreversible row, and only works on your own job.
     */
    public function testCancelStopsABackgroundJobAndLeavesTheRestPending(): void
    {
        for ($i = 0; $i < 150; $i++) {
            self::post('Old Co ' . $i);
        }
        $plan = self::preview(['search' => 'Old Co', 'replace' => 'New Co']);
        $queued = self::apply(['plan_id' => $plan['plan_id'], 'background' => true]);
        $this->host->jobs->run($queued['job_id'], 1);

        Kit_Test_Site::as_user(2, 'edit_post');
        self::assertSame('kit_sr_job_not_found', SR\cancel(['job_id' => $queued['job_id']])->get_error_code());
        Kit_Test_Site::as_user(1, 'edit_post');

        $cancelled = SR\cancel(['job_id' => $queued['job_id']]);
        self::assertTrue($cancelled['cancelled']);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame($plan['plan_id'], $cancelled['plan_id']);

        $this->host->jobs->run($queued['job_id']);
        $status = SR\status(['job_id' => $queued['job_id']]);
        self::assertSame('cancelled', $status['job']['status']);
        self::assertSame(100, $status['plan']['counts']['applied']);
        self::assertSame(50, $status['plan']['counts']['pending']);
        self::assertSame('Old Co 149', get_post(150)->post_title);

        $row = end($this->host->ledger->calls);
        self::assertSame('wppilot/search-replace-cancel', $row['ability']);
        self::assertNull($row['items'][0]['before']);
        self::assertStringContainsString('cannot be resumed', $row['items'][0]['irreversible_reason']);

        $again = SR\cancel(['job_id' => $queued['job_id']]);
        self::assertFalse($again['cancelled']);
        self::assertSame('cancelled', $again['status']);

        $rest = self::apply(['plan_id' => $plan['plan_id']]);
        self::assertSame(50, $rest['applied']);
    }

    public function testUndoRestoresOnlyWhatThePlanTouched(): void
    {
        $id = self::post('Old Co', 'Old Co body');
        Kit_Test_Site::set_raw_meta($id, '_elementor_data', ['[{"t":"Old Co \\/ x"}]']);
        $plan = self::preview(['search' => 'Old Co', 'replace' => 'New Co', 'fields' => ['post_title'], 'meta_keys' => ['_elementor_data']]);
        self::apply(['plan_id' => $plan['plan_id']]);
        get_post($id)->post_content = 'A person edited the body afterwards';

        $restored = PostPartial\restore(['snapshot' => $this->host->ledger->rows()[0]['before']]);

        self::assertTrue($restored['verified']);
        self::assertSame('Old Co', get_post($id)->post_title);
        self::assertSame('A person edited the body afterwards', get_post($id)->post_content);
        self::assertSame(['[{"t":"Old Co \\/ x"}]'], Kit_Test_Site::raw_meta($id)['_elementor_data']);
    }

    public function testContentWordPressFiltersOnSaveIsReportedNotHidden(): void
    {
        self::post('Title', 'Old Co <script>x</script>');
        Kit_Test_Site::$content_filter = static fn(string $html): string => str_replace('<script>x</script>', '', $html);
        $plan = self::preview(['search' => 'Old Co', 'replace' => 'New Co']);

        $result = self::apply(['plan_id' => $plan['plan_id']]);

        self::assertFalse($result['posts'][0]['verified']);
        self::assertStringContainsString('filtered', $result['posts'][0]['warnings'][0]);
    }

    public function testAFullPlanHandsBackWhereTheNextScanStarts(): void
    {
        for ($i = 0; $i < SR\MAX_PLAN_POSTS + 1; $i++) {
            self::post('Old Co ' . $i);
        }

        $first = self::preview(['search' => 'Old Co', 'replace' => 'New Co']);
        self::assertFalse($first['complete']);
        self::assertSame('plan_full', $first['stopped_by']);
        self::assertSame(SR\MAX_PLAN_POSTS, $first['next_after_id']);
        self::assertSame(50, count($first['posts']));
        self::assertSame(50, $first['next_diff_offset']);

        $page = self::preview(['plan_id' => $first['plan_id'], 'diff_offset' => 450, 'diff_limit' => 100]);
        self::assertSame(451, $page['posts'][0]['post_id']);
        self::assertNull($page['next_diff_offset']);

        $rest = self::preview(['search' => 'Old Co', 'replace' => 'New Co', 'after_id' => $first['next_after_id']]);
        self::assertTrue($rest['complete']);
        self::assertSame([SR\MAX_PLAN_POSTS + 1], array_column($rest['posts'], 'post_id'));
    }

    public function testMetaKeyPatternsAreEscapedForLike(): void
    {
        $id = self::post('Page');
        Kit_Test_Site::set_raw_meta($id, '_yoast_wpseo_title', ['Old Co | Home']);
        Kit_Test_Site::set_raw_meta($id, 'xyoastxwpseoxtitle', ['Old Co decoy']);

        $plan = self::preview(['search' => 'Old Co', 'replace' => 'New Co', 'fields' => [], 'meta_keys' => ['_yoast_wpseo_*']]);

        self::assertSame(['meta:_yoast_wpseo_title'], array_column($plan['posts'][0]['changes'], 'target'));
        self::assertStringContainsString("LIKE '\\\\_yoast\\\\_wpseo\\\\_%'", implode("\n", $GLOBALS['wpdb']->queries));
    }
}
