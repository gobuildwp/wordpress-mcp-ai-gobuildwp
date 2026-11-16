<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\LaunchCheck;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use WPPilot\Kits\LaunchCheck as L;

/**
 * The launch-check kit's verdicts, from gathered facts to pass, warn, fail or info.
 */
final class LaunchCheckTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $kit = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        self::$kit = require dirname(__DIR__, 4) . '/includes/kits/launch-check/bootstrap.php';
    }

    private static function doc(string $head): DOMDocument
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<!doctype html><html><head>' . $head . '</head><body></body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $doc;
    }

    /**
     * @return array<string, mixed>
     */
    private static function visibility(array $overrides = []): array
    {
        return L\check_search_visibility($overrides + [
            'blog_public' => true,
            'home_fetched' => true,
            'home_status' => 200,
            'home_noindex' => false,
            'home_error' => '',
        ]);
    }

    public function testTheKitDeclaresOneReadOnlyAbility(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/launch-check/kit.json'), true);
        self::assertSame(['wppilot/launch-checklist'], array_column($manifest['abilities'], 'name'));
        self::assertTrue($manifest['abilities'][0]['readonly']);
        self::assertSame(['content-audit'], $manifest['requires']['kits']);
        self::assertCount(1, self::$kit['ability_files']);
    }

    public function testNoindexIsReadFromRobotsMetaOnly(): void
    {
        self::assertTrue(L\meta_noindex(self::doc('<meta name="robots" content="noindex, nofollow">')));
        self::assertTrue(L\meta_noindex(self::doc('<meta name="ROBOTS" content="none">')));
        self::assertTrue(L\meta_noindex(self::doc('<meta name="googlebot" content="max-snippet:-1,noindex">')));
        self::assertFalse(L\meta_noindex(self::doc('<meta name="robots" content="index, follow, max-image-preview:large">')));
        self::assertFalse(L\meta_noindex(self::doc('<meta name="description" content="noindex is a word">')));
        self::assertFalse(L\meta_noindex(null));
    }

    public function testSearchVisibility(): void
    {
        self::assertSame('pass', self::visibility()['status']);
        self::assertSame('fail', self::visibility(['blog_public' => false])['status']);
        self::assertSame('fail', self::visibility(['home_noindex' => true])['status']);
        self::assertSame('fail', self::visibility(['home_status' => 503])['status'], 'a maintenance or coming-soon page');
        $unfetched = self::visibility(['home_fetched' => false, 'home_status' => 0, 'home_error' => 'cURL error 28']);
        self::assertSame('warn', $unfetched['status']);
        self::assertStringContainsString('cURL error 28', $unfetched['detail']);
    }

    public function testSslNeedsBothAddressesOnHttps(): void
    {
        self::assertSame('pass', L\check_ssl(['home_url' => 'https://example.com/', 'site_url' => 'https://example.com/wp/'])['status']);
        $mixed = L\check_ssl(['home_url' => 'https://example.com/', 'site_url' => 'http://example.com/']);
        self::assertSame('fail', $mixed['status']);
        self::assertStringContainsString('WordPress Address', $mixed['detail']);
        self::assertStringNotContainsString('Site Address', $mixed['detail']);
    }

    /**
     * @return array<string, array{0: bool, 1: ?bool, 2: bool|string, 3: bool, 4: string}>
     */
    public static function debugCases(): array
    {
        return [
            'production' => [false, true, false, false, 'pass'],
            'debug on, display default' => [true, true, false, false, 'fail'],
            'debug on, display null, php.ini displays' => [true, null, false, true, 'fail'],
            'debug on, display null, php.ini quiet' => [true, null, false, false, 'pass'],
            'debug on, display off, public log' => [true, false, true, false, 'warn'],
            'debug on, display off, custom log path' => [true, false, 'custom path', false, 'pass'],
            'debug off, php.ini displays' => [false, true, false, true, 'warn'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('debugCases')]
    public function testDebugDisplay(bool $debug, ?bool $display, bool|string $log, bool $ini, string $expected): void
    {
        $row = L\check_debug_display(['wp_debug' => $debug, 'wp_debug_display' => $display, 'wp_debug_log' => $log, 'ini_display_errors' => $ini]);
        self::assertSame($expected, $row['status']);
    }

    public function testFavicon(): void
    {
        self::assertTrue(L\has_icon_link(self::doc('<link rel="shortcut icon" href="/x.ico">')));
        self::assertTrue(L\has_icon_link(self::doc('<link rel="apple-touch-icon" href="/a.png">')));
        self::assertFalse(L\has_icon_link(self::doc('<link rel="stylesheet" href="/icon.css">')));
        self::assertFalse(L\has_icon_link(self::doc('<link rel="icon" href="">')));

        self::assertSame('pass', L\check_favicon(['site_icon' => true, 'icon_link' => false, 'favicon_ico' => false])['status']);
        self::assertSame('pass', L\check_favicon(['site_icon' => false, 'icon_link' => false, 'favicon_ico' => true])['status']);
        self::assertSame('warn', L\check_favicon(['site_icon' => false, 'icon_link' => false, 'favicon_ico' => false])['status']);
    }

    public function testAnalyticsFromSnippetsOrPlugins(): void
    {
        $html = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-X"></script><script defer data-domain="x" src="https://plausible.io/js/script.js"></script>';
        self::assertSame(['Google Analytics', 'Plausible'], L\analytics_in_html($html));
        self::assertSame([], L\analytics_in_html('<p>No tracking here</p>'));

        $both = L\check_analytics(['home_fetched' => true, 'in_html' => ['Google Analytics'], 'plugins' => ['Site Kit by Google']]);
        self::assertSame('pass', $both['status']);
        self::assertSame(['Google Analytics', 'Site Kit by Google'], $both['data']['found']);
        self::assertSame('warn', L\check_analytics(['home_fetched' => true, 'in_html' => [], 'plugins' => []])['status']);
        self::assertSame('info', L\check_analytics(['home_fetched' => false, 'in_html' => [], 'plugins' => []])['status']);
    }

    public function testPrivacyPage(): void
    {
        self::assertSame('warn', L\check_privacy_page(['page_id' => 0, 'status' => null, 'title' => '', 'url' => ''])['status']);
        self::assertSame('warn', L\check_privacy_page(['page_id' => 3, 'status' => 'draft', 'title' => 'Privacy Policy', 'url' => ''])['status']);
        self::assertSame('warn', L\check_privacy_page(['page_id' => 3, 'status' => 'trash', 'title' => 'Privacy Policy', 'url' => ''])['status']);
        self::assertSame('pass', L\check_privacy_page(['page_id' => 3, 'status' => 'publish', 'title' => 'Privacy Policy', 'url' => 'https://example.com/privacy/'])['status']);
    }

    public function testBrokenLinksKeepOnlyLinkFindings(): void
    {
        $facts = L\broken_from_findings([
            ['type' => 'broken_internal_link', 'post' => ['id' => 7, 'title' => 'About'], 'evidence' => ['href' => '/gone/', 'occurrences' => 2]],
            ['type' => 'redirected_internal_link', 'post' => ['id' => 7, 'title' => 'About'], 'evidence' => ['href' => '/moved/']],
            ['type' => 'internal_link_to_unpublished', 'post' => ['id' => 9, 'title' => 'Home'], 'evidence' => ['href' => '/draft/']],
        ], 5, 1);

        self::assertSame(5, $facts['sampled']);
        self::assertSame([
            ['type' => 'broken_internal_link', 'post_id' => 7, 'post_title' => 'About', 'link' => '/gone/'],
            ['type' => 'internal_link_to_unpublished', 'post_id' => 9, 'post_title' => 'Home', 'link' => '/draft/'],
        ], $facts['broken']);
        self::assertSame('warn', L\check_broken_links($facts)['status']);
        self::assertSame('pass', L\check_broken_links(L\broken_from_findings([], 5, 0))['status']);
        self::assertSame('info', L\check_broken_links(['available' => false, 'sampled' => 0, 'broken' => [], 'unchecked' => 0])['status']);
    }

    public function testFormsAreListedAndLeftoversFlagged(): void
    {
        self::assertSame('info', L\check_forms([], false)['status']);

        $row = L\check_forms([
            ['plugin' => 'Contact Form 7', 'id' => 1, 'title' => 'Contact form 1', 'status' => 'publish'],
            ['plugin' => 'WPForms', 'id' => 2, 'title' => 'Test form', 'status' => 'publish'],
            ['plugin' => 'WPForms', 'id' => 3, 'title' => 'Contest entry', 'status' => 'publish'],
        ], false);
        self::assertSame('warn', $row['status']);
        self::assertSame([false, true, false], array_column($row['data']['forms'], 'looks_like_test'), '"Contest" is not "test"');
        self::assertStringContainsString('"Test form"', $row['detail']);

        self::assertSame('info', L\check_forms([['plugin' => 'Fluent Forms', 'id' => 4, 'title' => 'Quote request', 'status' => 'published']], true)['status']);
    }

    public function testSummaryIsReadyOnlyWithoutFailures(): void
    {
        self::assertSame(['ready' => true, 'fail' => 0, 'warn' => 1, 'info' => 0, 'pass' => 1], L\summarise([['id' => 'a', 'status' => 'pass'], ['id' => 'b', 'status' => 'warn']]));
        self::assertFalse(L\summarise([['id' => 'a', 'status' => 'fail']])['ready']);
    }
}
