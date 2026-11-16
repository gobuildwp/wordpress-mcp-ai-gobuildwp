<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * update-post and gutenberg-write-content replace the whole body, so a short reply from the
 * agent used to wipe the page. The guard measures what the write keeps.
 */
final class ContentGuardTest extends TestCase
{
    private const LONG = '<p>Words that people wrote and want to keep on the page. Words that people wrote and want to keep on the page. Words that people wrote and want to keep.</p><img src="a.jpg"><img src="b.jpg"><script>var ignored = "script text is not visible";</script>';

    public function test_measure_counts_visible_text_and_media_only(): void
    {
        $measure = \wppilot_content_measure(self::LONG);

        self::assertSame(2, $measure['media']);
        self::assertStringNotContainsString('script', (string) $measure['text']);
        self::assertGreaterThan(100, $measure['text']);
        self::assertLessThan(200, $measure['text']);
    }

    public function test_a_write_dropping_most_of_the_page_is_refused_with_the_numbers(): void
    {
        $error = \wppilot_content_loss_error(
            5,
            \wppilot_content_measure(self::LONG),
            \wppilot_content_measure('<p>Words that people wrote.</p>'),
        );

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('wppilot_content_loss', $error->get_error_code());
        self::assertStringContainsString('media items from 2 to 0', $error->get_error_message());
        self::assertStringContainsString('confirm_content_loss', $error->get_error_message());
    }

    public function test_an_ordinary_edit_and_a_tiny_stub_pass(): void
    {
        self::assertNull(\wppilot_content_loss_error(5, ['text' => 400, 'media' => 2], ['text' => 250, 'media' => 1]));
        self::assertNull(\wppilot_content_loss_error(5, ['text' => 40, 'media' => 0], ['text' => 3, 'media' => 0]));
    }

    public function test_removing_the_only_image_is_refused(): void
    {
        self::assertInstanceOf(
            WP_Error::class,
            \wppilot_content_loss_error(5, ['text' => 400, 'media' => 1], ['text' => 400, 'media' => 0]),
        );
    }

    public function test_dynamic_block_attributes_count_as_text_and_media(): void
    {
        $parts = \wppilot_content_measure_blocks([[
            'blockName' => 'wppilot/hero',
            'attrs' => ['heading' => 'A big headline here', 'imageId' => 12, 'color' => 'red'],
            'innerHTML' => '',
            'innerBlocks' => [],
        ]]);

        self::assertSame(['A big headline here', 'media'], $parts);
    }
}
