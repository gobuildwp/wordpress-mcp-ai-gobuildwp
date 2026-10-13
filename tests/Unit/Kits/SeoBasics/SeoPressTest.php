<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SeoBasics;

use Kit_Test_Site;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Seo_Basics_Vendors;
use WP_Error;

require_once __DIR__ . '/harness.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SeoPressTest extends SeoBasicsCase
{
    protected function setUp(): void
    {
        $this->boot(['SEOPRESS_VERSION']);
    }

    public function testAnAbsentRowIsNoOverrideAndEffectiveRobotsApplyTheDefaults(): void
    {
        $seo = $this->run_ability('wppilot/seopress-get-post-seo', ['post_id' => 10])['seo'];
        self::assertSame(['noindex' => false, 'nofollow' => false, 'effective' => ['noindex' => false, 'nofollow' => false]], $seo['robots']);

        update_option('seopress_titles_option_name', [
            'seopress_titles_nofollow' => '1',
            'seopress_titles_single_titles' => ['post' => ['noindex' => '1']],
        ]);
        $seo = $this->run_ability('wppilot/seopress-get-post-seo', ['id' => 10])['seo'];
        self::assertSame(['noindex' => false, 'nofollow' => false, 'effective' => ['noindex' => true, 'nofollow' => true]], $seo['robots']);
    }

    public function testAPasswordForcesNoindex(): void
    {
        Seo_Basics_Vendors::$password_protected = [10];

        self::assertTrue($this->run_ability('wppilot/seopress-get-post-seo', ['post_id' => 10])['seo']['robots']['effective']['noindex']);
    }

    public function testEditStoresYesOrDeletesAndUndoRestoresTheRows(): void
    {
        Kit_Test_Site::set_meta(10, '_seopress_titles_title', 'Old \"t\"');
        Kit_Test_Site::set_meta(10, '_seopress_robots_follow', 'yes');

        $result = $this->run_ability('wppilot/seopress-edit-post-seo', [
            'post_id' => 10,
            'title' => self::SLASHY,
            'description' => "Line one\nline two",
            'robots' => ['noindex' => true, 'nofollow' => false],
        ]);

        self::assertIsArray($result);
        self::assertSame([self::SLASHY], $this->raw()['_seopress_titles_title']);
        self::assertSame(["Line one\nline two"], $this->raw()['_seopress_titles_desc']);
        self::assertSame(['yes'], $this->raw()['_seopress_robots_index']);
        self::assertArrayNotHasKey('_seopress_robots_follow', $this->raw(), 'false deletes the row; SEOPress never stores a blank');
        self::assertSame(['title', 'description', 'robots.noindex', 'robots.nofollow', 'robots.effective.noindex', 'robots.effective.nofollow'], $result['changed']);

        $this->undo_last();

        self::assertSame(['Old \"t\"'], $this->raw()['_seopress_titles_title']);
        self::assertArrayNotHasKey('_seopress_titles_desc', $this->raw());
        self::assertArrayNotHasKey('_seopress_robots_index', $this->raw());
        self::assertSame(['yes'], $this->raw()['_seopress_robots_follow']);
    }

    public function testAnEmptyTitleDeletesTheOverride(): void
    {
        Kit_Test_Site::set_meta(10, '_seopress_titles_title', 'Custom');

        $this->run_ability('wppilot/seopress-edit-post-seo', ['post_id' => 10, 'title' => '']);

        self::assertArrayNotHasKey('_seopress_titles_title', $this->raw());
    }

    public function testANonBooleanRobotsFlagWritesNothing(): void
    {
        $result = $this->run_ability('wppilot/seopress-edit-post-seo', ['post_id' => 10, 'title' => 'T', 'robots' => ['noindex' => 'yes']]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('seopress_invalid_input', $result->get_error_code());
        self::assertSame([], $this->raw());
    }
}
