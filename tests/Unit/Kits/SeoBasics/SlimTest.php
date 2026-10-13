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
final class SlimTest extends SeoBasicsCase
{
    protected function setUp(): void
    {
        $this->boot(['SLIM_SEO_VER']);
    }

    public function testReadSaysWhatSlimSeoCannotStore(): void
    {
        Kit_Test_Site::set_meta(10, 'slim_seo', ['title' => 'T', 'noindex' => 1]);

        $result = $this->run_ability('wppilot/slim-seo-get-post-seo', ['post_id' => 10]);

        self::assertSame(['title' => 'T', 'description' => '', 'robots_index' => 'noindex'], $result['seo']);
        self::assertArrayHasKey('robots_follow', $result['unsupported']);
    }

    public function testUpdateKeepsTheRestOfTheRowAndUndoRestoresIt(): void
    {
        $original = ['title' => 'Old \"t\"', 'canonical' => 'https://example.com/c', 'facebook_image' => 'https://example.com/i.png'];
        Kit_Test_Site::set_meta(10, 'slim_seo', $original);

        $result = $this->run_ability('wppilot/slim-seo-update-post-seo', [
            'post_id' => 10,
            'title' => self::SLASHY,
            'description' => 'D',
            'robots_index' => 'noindex',
        ]);

        self::assertIsArray($result);
        self::assertSame(['title', 'description', 'robots_index'], $result['changed']);
        self::assertSame(
            ['title' => self::SLASHY, 'canonical' => 'https://example.com/c', 'facebook_image' => 'https://example.com/i.png', 'description' => 'D', 'noindex' => 1],
            get_post_meta(10, 'slim_seo', true),
        );

        $this->undo_last();

        self::assertSame($original, get_post_meta(10, 'slim_seo', true));
    }

    public function testIndexOnlyClearsTheFlagAndAnEmptyRowIsDeleted(): void
    {
        Kit_Test_Site::set_meta(10, 'slim_seo', ['noindex' => 1]);

        $result = $this->run_ability('wppilot/slim-seo-update-post-seo', ['post_id' => 10, 'robots_index' => 'index']);

        self::assertSame('default', $result['seo']['robots_index'], 'Slim SEO cannot force index');
        self::assertArrayNotHasKey('slim_seo', $this->raw());

        $this->undo_last();
        self::assertSame(['noindex' => 1], get_post_meta(10, 'slim_seo', true));
    }

    public function testSlimSeoTakesNoFollowField(): void
    {
        self::assertArrayNotHasKey('robots_follow', Kit_Test_Site::registration('wppilot/slim-seo-update-post-seo')['input_schema']['properties']);
        $result = $this->run_ability('wppilot/slim-seo-update-post-seo', ['post_id' => 10, 'robots_index' => 'nofollow']);
        self::assertInstanceOf(WP_Error::class, $result);
    }
}
