<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * wppilot/import-media-url checked the host once (IPv4 only) and then let download_url()
 * resolve it again and follow redirects anywhere. These cover the per-hop check and the pin
 * it produces; IP literals keep them off the network.
 */
final class SafeFetchTest extends TestCase
{
    public function test_private_and_reserved_addresses_are_refused(): void
    {
        $blocked = [
            'http://127.0.0.1/a.jpg',
            'http://10.1.2.3/a.jpg',
            'http://169.254.169.254/latest/meta-data/',
            'http://100.64.0.1/a.jpg',
            'http://[::1]/a.jpg',
            'http://[fe80::1]/a.jpg',
            'http://[fd00::1]/a.jpg',
            'http://[::ffff:127.0.0.1]/a.jpg',
        ];
        foreach ($blocked as $url) {
            $result = \wppilot_safe_fetch_target($url);
            self::assertInstanceOf(WP_Error::class, $result, $url);
            self::assertSame('wppilot_media_url_private', $result->get_error_code(), $url);
        }
    }

    public function test_a_public_address_is_pinned_to_the_checked_ip(): void
    {
        $target = \wppilot_safe_fetch_target('https://93.184.215.14/photo.jpg');

        self::assertIsArray($target);
        self::assertSame('93.184.215.14:443:93.184.215.14', \wppilot_safe_fetch_resolve_entry($target));
        self::assertSame('[2606:4700::1]:8080:[2606:4700::1]', \wppilot_safe_fetch_resolve_entry(['host' => '2606:4700::1', 'port' => 8080, 'ip' => '2606:4700::1']));
    }

    public function test_credentials_and_other_schemes_are_refused(): void
    {
        self::assertInstanceOf(WP_Error::class, \wppilot_safe_fetch_target('https://user:pass@93.184.215.14/a.jpg'));
        self::assertInstanceOf(WP_Error::class, \wppilot_safe_fetch_target('file:///etc/passwd'));
        self::assertInstanceOf(WP_Error::class, \wppilot_safe_fetch_target('gopher://93.184.215.14/'));
    }

    public function test_import_media_url_no_longer_calls_download_url(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/abilities/wordpress/media.php');

        self::assertStringNotContainsString('= download_url(', $source);
        self::assertStringContainsString('wppilot_safe_download_url(', $source);
    }
}
