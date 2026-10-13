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
final class RankMathTest extends SeoBasicsCase
{
    protected function setUp(): void
    {
        $this->boot(['RANK_MATH_VERSION']);
    }

    public function testReadMapsTheRobotsTokenArray(): void
    {
        Kit_Test_Site::set_meta(10, 'rank_math_title', '%title% %sep% Shop');
        Kit_Test_Site::set_meta(10, 'rank_math_robots', ['noindex', 'nofollow', 'noarchive']);

        $seo = $this->run_ability('wppilot/rank-math-get-post-seo', ['post_id' => 10])['seo'];

        self::assertSame('%title% %sep% Shop', $seo['seo_title']);
        self::assertSame(['index' => 'noindex', 'follow' => 'nofollow'], $seo['robots']);

        Kit_Test_Site::set_meta(10, 'rank_math_robots', []);
        self::assertSame(['index' => 'default', 'follow' => 'follow'], $this->run_ability('wppilot/rank-math-get-post-seo', ['id' => 10])['seo']['robots']);
    }

    public function testEditKeepsVariablesAndBackslashesAndUndoRestoresTheRow(): void
    {
        Kit_Test_Site::set_meta(10, 'rank_math_description', 'Old \"desc\"');
        Kit_Test_Site::set_meta(10, 'rank_math_robots', ['index', 'nosnippet']);

        $result = $this->run_ability('wppilot/rank-math-edit-post-seo', [
            'post_id' => 10,
            'seo_title' => '%category% ' . self::SLASHY,
            'meta_description' => '<script>x</script>Plain',
            'robots' => ['index' => 'noindex', 'follow' => 'nofollow'],
        ]);

        self::assertIsArray($result);
        self::assertSame(['%category% ' . self::SLASHY], $this->raw()['rank_math_title'], 'the %variable% and the backslashes survive');
        self::assertSame('xPlain', $result['seo']['meta_description']);
        self::assertSame(['noindex', 'nofollow', 'nosnippet'], get_post_meta(10, 'rank_math_robots', true), 'the advanced token is kept');

        $this->undo_last();

        self::assertArrayNotHasKey('rank_math_title', $this->raw());
        self::assertSame(['Old \"desc\"'], $this->raw()['rank_math_description']);
        self::assertSame(['index', 'nosnippet'], get_post_meta(10, 'rank_math_robots', true));
    }

    public function testDefaultIndexDropsBothIndexTokensAndFollowAloneKeepsIndex(): void
    {
        Kit_Test_Site::set_meta(10, 'rank_math_robots', ['index', 'noimageindex']);

        $this->run_ability('wppilot/rank-math-edit-post-seo', ['post_id' => 10, 'robots' => ['follow' => 'nofollow']]);
        self::assertSame(['index', 'nofollow', 'noimageindex'], get_post_meta(10, 'rank_math_robots', true));

        $this->run_ability('wppilot/rank-math-edit-post-seo', ['post_id' => 10, 'robots' => ['index' => 'default']]);
        self::assertSame(['nofollow', 'noimageindex'], get_post_meta(10, 'rank_math_robots', true));
    }

    public function testAnInvalidValueWritesNothing(): void
    {
        $result = $this->run_ability('wppilot/rank-math-edit-post-seo', ['post_id' => 10, 'seo_title' => 'T', 'robots' => ['follow' => 'dofollow']]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('rank_math_invalid_input', $result->get_error_code());
        self::assertSame([], $this->raw());
    }
}
