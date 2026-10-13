<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SeoBasics;

use Kit_Test_Site;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Seo_Basics_Aioseo_Post as Post;
use WP_Error;
use WPPilot\Kits\SeoBasics\Aioseo;

require_once __DIR__ . '/harness.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AioseoTest extends SeoBasicsCase
{
    protected function setUp(): void
    {
        $this->boot(['AIOSEO_VERSION']);
        Post::$rows = [];
        Post::$fail = '';
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function seed(array $columns): void
    {
        Post::$rows[10] = array_merge(['id' => '3', 'post_id' => '10'], $columns);
    }

    public function testAPostWithNoRowReadsAsInheritingDefaults(): void
    {
        $seo = $this->run_ability('wppilot/aioseo-get-post-seo', ['post_id' => 10])['seo'];

        self::assertSame(['seo_title' => '', 'meta_description' => '', 'robots' => ['index' => 'default', 'follow' => 'follow']], $seo);
    }

    public function testRobotsDefaultHidesThePerPostFlags(): void
    {
        $this->seed(['robots_default' => '1', 'robots_noindex' => '1', 'robots_nofollow' => '1']);
        self::assertSame(['index' => 'default', 'follow' => 'follow'], $this->run_ability('wppilot/aioseo-get-post-seo', ['id' => 10])['seo']['robots']);

        $this->seed(['robots_default' => '0', 'robots_noindex' => '1', 'robots_nofollow' => '0']);
        self::assertSame(['index' => 'noindex', 'follow' => 'follow'], $this->run_ability('wppilot/aioseo-get-post-seo', ['id' => 10])['seo']['robots']);
    }

    public function testEditWritesTheTableAndTheKitsOwnUndoPutsTheColumnsBack(): void
    {
        $this->seed([
            'title' => 'Old \"title\"',
            'description' => null,
            'robots_default' => '0',
            'robots_noindex' => '0',
            'robots_nofollow' => '1',
            'robots_noarchive' => '1',
            'robots_max_snippet' => '50',
            'og_title' => 'Keep me',
        ]);

        $result = $this->run_ability('wppilot/aioseo-edit-post-seo', [
            'post_id' => 10,
            'seo_title' => self::SLASHY,
            'meta_description' => 'Fresh',
            'robots' => ['index' => 'default'],
        ]);

        self::assertIsArray($result);
        self::assertSame(['seo_title', 'meta_description', 'robots.index', 'robots.follow'], $result['changed']);
        $row = Post::$rows[10];
        self::assertSame(self::SLASHY, $row['title'], 'no slashing: AIOSEO writes through esc_sql');
        self::assertSame(['1', '0', '0', '0'], [$row['robots_default'], $row['robots_noindex'], $row['robots_nofollow'], $row['robots_noarchive']]);
        self::assertNull($row['robots_max_snippet'], 'inheriting clears the preview limits too');
        self::assertSame('Keep me', $row['og_title']);

        $ledger_row = $this->ledger->all()[0];
        self::assertSame(Aioseo\STRATEGY, $ledger_row['rollback']['type']);

        $this->undo_last();

        $row = Post::$rows[10];
        self::assertSame('Old \"title\"', $row['title']);
        self::assertNull($row['description']);
        self::assertSame(['0', '0', '1', '1', '50'], [$row['robots_default'], $row['robots_noindex'], $row['robots_nofollow'], $row['robots_noarchive'], $row['robots_max_snippet']]);
        self::assertSame('Keep me', $row['og_title']);
    }

    public function testSessionStateIsTheCapturedColumnsNowAndARedoGoesBackThroughTheUndo(): void
    {
        $this->seed(['title' => 'Old', 'description' => 'Old description']);
        $this->run_ability('wppilot/aioseo-edit-post-seo', ['post_id' => 10, 'seo_title' => self::SLASHY]);
        $snapshot = $this->ledger->all()[0]['rollback']['snapshot'];

        $after = Aioseo\current_state($snapshot);
        self::assertSame(Aioseo\STRATEGY, $after['type']);
        self::assertSame(['title' => self::SLASHY], $after['columns'], 'only the columns the before-image holds');
        self::assertSame('10:title', Aioseo\state_target($snapshot));

        $this->undo_last();
        self::assertSame('Old', Post::$rows[10]['title']);
        self::assertSame($snapshot['fingerprint'], Aioseo\current_state($snapshot)['fingerprint'], 'after the undo the state is the before-image again');

        $redo = Aioseo\restore(['snapshot' => $after]);
        self::assertTrue($redo['verified'], 'a redo is the undo run with the state the undo replaced');
        self::assertSame(self::SLASHY, Post::$rows[10]['title']);
        self::assertSame('Old description', Post::$rows[10]['description']);
    }

    public function testExplicitRobotsKeepTheAdvancedFlagsAndNofollowAlonePromotesAnInheritingPost(): void
    {
        $this->seed(['robots_default' => '0', 'robots_noindex' => '0', 'robots_nosnippet' => '1']);
        $this->run_ability('wppilot/aioseo-edit-post-seo', ['post_id' => 10, 'robots' => ['index' => 'noindex']]);
        self::assertSame(['0', '1', '1'], [Post::$rows[10]['robots_default'], Post::$rows[10]['robots_noindex'], Post::$rows[10]['robots_nosnippet']]);

        $this->seed(['robots_default' => '1']);
        $result = $this->run_ability('wppilot/aioseo-edit-post-seo', ['post_id' => 10, 'robots' => ['follow' => 'nofollow']]);
        self::assertSame(['index' => 'index', 'follow' => 'nofollow'], $result['seo']['robots']);
        self::assertSame('0', Post::$rows[10]['robots_default']);
    }

    public function testAnUndoOfAPostThatHadNoRowWritesItsDefaultsBack(): void
    {
        $this->run_ability('wppilot/aioseo-edit-post-seo', ['post_id' => 10, 'robots' => ['index' => 'noindex']]);
        self::assertSame('noindex', $this->run_ability('wppilot/aioseo-get-post-seo', ['post_id' => 10])['seo']['robots']['index']);

        $this->undo_last();

        self::assertSame('default', $this->run_ability('wppilot/aioseo-get-post-seo', ['post_id' => 10])['seo']['robots']['index']);
    }

    public function testAFailedSaveIsReportedNotClaimed(): void
    {
        Post::$fail = 'Table is read only';

        $result = $this->run_ability('wppilot/aioseo-edit-post-seo', ['post_id' => 10, 'seo_title' => 'T']);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('aioseo_save_failed', $result->get_error_code());
    }

    public function testAnUndoThatDoesNotStickIsNotVerified(): void
    {
        $this->run_ability('wppilot/aioseo-edit-post-seo', ['post_id' => 10, 'seo_title' => 'T']);
        $snapshot = $this->ledger->all()[0]['rollback']['snapshot'];
        Post::$fail = 'gone';

        $result = Aioseo\restore(['snapshot' => $snapshot]);

        self::assertInstanceOf(WP_Error::class, $result);
    }

    public function testAPostTypeAioseoDoesNotManageIsRefused(): void
    {
        Kit_Test_Site::insert(['ID' => 12, 'post_type' => 'wp_block', 'post_status' => 'publish']);

        $result = $this->run_ability('wppilot/aioseo-get-post-seo', ['post_id' => 12]);

        self::assertSame('aioseo_invalid_post', $result->get_error_code());
    }
}
