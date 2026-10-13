<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SeoBasics;

use Kit_Test_Site;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WP_Error;

require_once __DIR__ . '/harness.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SmartCrawlTest extends SeoBasicsCase
{
    protected function setUp(): void
    {
        $this->boot(['SMARTCRAWL_VERSION']);
    }

    public function testReadReportsTheStoredFlags(): void
    {
        Kit_Test_Site::set_meta(10, '_wds_meta-robots-index', '1');
        Kit_Test_Site::set_meta(10, '_wds_meta-robots-nofollow', '1');

        $seo = $this->run_ability('wppilot/smartcrawl-get-post-seo', ['post_id' => 10])['seo'];

        self::assertSame(['index', 'nofollow'], [$seo['robots_index'], $seo['robots_follow']]);
    }

    public function testUpdateSetsOneFlagPerPairAndUndoRestoresBoth(): void
    {
        Kit_Test_Site::set_meta(10, '_wds_meta-robots-index', '1');
        Kit_Test_Site::set_meta(10, '_wds_title', 'Old \"t\" %%sitename%%');

        $result = $this->run_ability('wppilot/smartcrawl-update-post-seo', [
            'post_id' => 10,
            'title' => self::SLASHY . ' %%sep%%',
            'robots_index' => 'noindex',
            'robots_follow' => 'follow',
        ]);

        self::assertIsArray($result);
        self::assertSame(['title', 'robots_index', 'robots_follow'], $result['changed']);
        self::assertSame([self::SLASHY . ' %%sep%%'], $this->raw()['_wds_title']);
        self::assertSame(['1'], $this->raw()['_wds_meta-robots-noindex']);
        self::assertArrayNotHasKey('_wds_meta-robots-index', $this->raw(), 'at most one flag of the pair');
        self::assertSame(['1'], $this->raw()['_wds_meta-robots-follow']);

        $this->undo_last();

        self::assertSame(['Old \"t\" %%sitename%%'], $this->raw()['_wds_title']);
        self::assertSame(['1'], $this->raw()['_wds_meta-robots-index']);
        self::assertArrayNotHasKey('_wds_meta-robots-noindex', $this->raw());
        self::assertArrayNotHasKey('_wds_meta-robots-follow', $this->raw());
    }

    public function testDefaultClearsBothFlagsAndAnEmptyTitleDeletesTheRow(): void
    {
        Kit_Test_Site::set_meta(10, '_wds_meta-robots-noindex', '1');
        Kit_Test_Site::set_meta(10, '_wds_title', 'T');

        $this->run_ability('wppilot/smartcrawl-update-post-seo', ['post_id' => 10, 'title' => '', 'robots_index' => 'default']);

        self::assertSame([], $this->raw());
    }

    public function testNothingToChangeIsAnError(): void
    {
        $result = $this->run_ability('wppilot/smartcrawl-update-post-seo', ['post_id' => 10]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('smartcrawl_no_changes', $result->get_error_code());
    }
}
