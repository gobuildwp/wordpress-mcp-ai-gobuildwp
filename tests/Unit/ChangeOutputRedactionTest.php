<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPPilot\Kits\Runtime\MiniLedger;

/**
 * What a ledger row looks like when it leaves the site: get-change, the export and the Changes
 * screen. Key-name redaction happened at write time; email addresses in values are masked here,
 * and the stored row (the before-image undo restores) must come through untouched.
 */
final class ChangeOutputRedactionTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function emails(): iterable
    {
        yield 'plain' => ['Write to jane.doe@example.com today', 'Write to j***@e***.com today'];
        yield 'subdomain and plus' => ['<a href="mailto:ops+wp@mail.agency.co.uk">x</a>', '<a href="mailto:o***@m***.uk">x</a>'];
        yield 'two addresses' => ['a@b.io, c@d.org', 'a***@b***.io, c***@d***.org'];
        yield 'retina file name kept' => ['<img src="logo@2x.png" srcset="hero@3x.webp 3x">', '<img src="logo@2x.png" srcset="hero@3x.webp 3x">'];
        yield 'no address' => ['Just text with an @ sign', 'Just text with an @ sign'];
    }

    /** @return iterable<string, array{string, bool}> */
    public static function keyNames(): iterable
    {
        yield 'litespeed object password' => ['litespeed.conf.object-pswd', true];
        yield 'litespeed cloudflare key' => ['litespeed.conf.cdn-cloudflare_key', true];
        yield 'nitropack site id' => ['nitropack-siteId', true];
        yield 'autoptimize ccss key' => ['autoptimize_ccss_key', true];
        yield 'smtp pass' => ['smtp_pass', true];
        yield 'basic auth' => ['basic-auth', true];
        yield 'api key' => ['stripe_api_key', true];
        yield 'cookie settings still match' => ['cache_bypass_cookies_enabled', true];
        yield 'bypass alone' => ['bypass_logged_in', false];
        yield 'author stays visible' => ['post_author', false];
        yield 'meta key stays visible' => ['meta_key', false];
        yield 'keywords stay visible' => ['_yoast_wpseo_focuskw_keywords', false];
    }

    #[DataProvider('keyNames')]
    public function testSensitiveKeyNames(string $key, bool $sensitive): void
    {
        self::assertSame($sensitive, \wppilot_change_key_is_sensitive($key), $key);
    }

    #[DataProvider('emails')]
    public function testEmailsAreMasked(string $input, string $expected): void
    {
        self::assertSame($expected, \wppilot_mask_emails($input));
    }

    public function testGetChangeMasksInputResultAndBeforeImageButNotTheStoredRow(): void
    {
        $entry = [
            'id' => 'c1',
            'ability' => 'wppilot/update-post',
            'user' => ['id' => 1, 'login' => 'admin'],
            'input' => ['post_id' => 7, 'excerpt' => 'Contact jane@example.com'],
            'result' => ['summary' => ['excerpt' => 'Contact jane@example.com']],
            'rollback' => [
                'reversible' => true,
                'type' => 'restore-post',
                'snapshot' => [
                    'post' => ['post_excerpt' => 'Old: bob@example.org'],
                    'meta' => ['_billing_email' => ['bob@example.org'], 'stripe_api_key' => ['sk_live_x']],
                ],
            ],
            'rollback_result' => ['observed' => ['post_excerpt' => 'Old: bob@example.org']],
        ];
        $stored = $entry;

        $out = \wppilot_change_for_output($entry);

        self::assertSame($stored, $entry, 'The row passed in is not changed.');
        self::assertSame('Contact j***@e***.com', $out['input']['excerpt']);
        self::assertSame(7, $out['input']['post_id']);
        self::assertSame('Contact j***@e***.com', $out['result']['summary']['excerpt']);
        self::assertSame('Old: b***@e***.org', $out['rollback']['snapshot']['post']['post_excerpt']);
        self::assertSame(['b***@e***.org'], $out['rollback']['snapshot']['meta']['_billing_email']);
        self::assertSame('[redacted]', $out['rollback']['snapshot']['meta']['stripe_api_key']);
        self::assertSame('Old: b***@e***.org', $out['rollback_result']['observed']['post_excerpt']);
        // Structure the Changes screen reads to decide what it offers stays as stored.
        self::assertTrue($out['rollback']['reversible']);
        self::assertSame('restore-post', $out['rollback']['type']);
        self::assertSame(['id' => 1, 'login' => 'admin'], $out['user']);
        self::assertStringNotContainsString('@example.', (string) wp_json_encode($out));
    }

    public function testExportRowMasksInput(): void
    {
        $row = \wppilot_change_export_row([
            'id' => 'c2',
            'input' => ['excerpt' => 'Reach me at me@site.dev', 'token' => '[redacted]'],
        ]);
        self::assertSame(['excerpt' => 'Reach me at m***@s***.dev', 'token' => '[redacted]'], $row['input']);
    }

    public function testKitRuntimeExportMasksInputToo(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/kits/_runtime/runtime.php';
        self::assertSame(
            ['nested' => ['text' => 'Hi x***@y***.com', 'n' => 3]],
            MiniLedger::mask_emails(['nested' => ['text' => 'Hi x@y.com', 'n' => 3]]),
        );
    }
}
