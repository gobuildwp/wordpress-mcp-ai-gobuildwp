<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * update_post_meta() unslashes what it is given, so the alt text wppilot/upload-media and
 * wppilot/update-media write has to be slashed first or a backslash in it is lost. The media
 * summary these abilities return needs more of WordPress than the doubles provide, so this
 * checks every alt-text write in the file instead of running it.
 */
final class MediaAltSlashTest extends TestCase
{
    public function testEveryAltTextWriteIsSlashed(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/abilities/wordpress/media.php');
        $writes = preg_match_all(
            "/update_post_meta\\([^;]*'_wp_attachment_image_alt',\\s*meta_value:\\s*([^;]*?)\\)\\s*,?\\s*\\)?;/s",
            $source,
            $matches,
        );

        self::assertSame(2, $writes, 'upload-media and update-media each write alt text once.');
        foreach ($matches[1] as $value) {
            self::assertStringStartsWith('wp_slash(', trim($value));
        }
    }
}
