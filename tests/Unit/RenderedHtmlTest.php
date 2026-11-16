<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use Kit_Media_Test_State;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;
use WPPilot_Test_State;

use function WPPilot\Abilities\WordPress\wordpress_get_rendered_html;
use function WPPilot\Abilities\WordPress\wordpress_rendered_html_strip;

/**
 * wppilot/get-rendered-html: same-site only, published posts only, scripts and styles stripped
 * on request before the size cap, and the cap reported rather than silent.
 */
final class RenderedHtmlTest extends TestCase
{
    private const PAGE = '<!doctype html><html><head><link rel="stylesheet" href="/a.css"><style>body{color:red}</style>'
        . '<script>alert(1)</script><script type="application/ld+json">{"@type":"Organization"}</script></head>'
        . '<body><h1>Hello</h1><script src="/b.js"></script></body></html>';

    /** @var list<string> */
    private array $savedCaps = [];

    /** @var array<int, mixed> */
    private array $savedPosts = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Unit/Kits/media-doubles.php';
        require_once dirname(__DIR__) . '/doubles/http-headers.php';
        $this->savedCaps = WPPilot_Test_State::$capabilities;
        $this->savedPosts = WPPilot_Test_State::$posts;
        WPPilot_Test_State::$capabilities = ['edit_posts'];
        Kit_Media_Test_State::$http = ['https://example.test/about/' => ['code' => 200, 'body' => self::PAGE]];
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$capabilities = $this->savedCaps;
        WPPilot_Test_State::$posts = $this->savedPosts;
        Kit_Media_Test_State::$http = [];
    }

    public function test_the_ability_is_registered_read_only(): void
    {
        self::assertContains('wppilot/get-rendered-html', WPPILOT_TEST_BOOT_ABILITIES);
        $registration = array_column(WPPILOT_TEST_BOOT_REGISTRATIONS, 'args', 'name')['wppilot/get-rendered-html'];
        self::assertTrue($registration['meta']['annotations']['readonly']);
    }

    public function test_returns_the_served_html_for_a_path_on_this_site(): void
    {
        $result = wordpress_get_rendered_html(['url' => '/about/']);

        self::assertIsArray($result);
        self::assertSame('https://example.test/about/', $result['url']);
        self::assertSame(200, $result['status']);
        self::assertSame(self::PAGE, $result['html']);
        self::assertFalse($result['truncated']);
        self::assertSame(['scripts' => 0, 'styles' => 0, 'stylesheet_links' => 0], $result['stripped']);
    }

    public function test_strips_scripts_and_styles_but_keeps_json_ld(): void
    {
        $result = wordpress_get_rendered_html(['url' => 'https://example.test/about/', 'strip_scripts' => true, 'strip_styles' => true]);

        self::assertIsArray($result);
        self::assertSame(['scripts' => 2, 'styles' => 1, 'stylesheet_links' => 1], $result['stripped']);
        self::assertStringNotContainsString('alert(1)', $result['html']);
        self::assertStringNotContainsString('/b.js', $result['html']);
        self::assertStringNotContainsString('<style', $result['html']);
        self::assertStringNotContainsString('a.css', $result['html']);
        self::assertStringContainsString('application/ld+json', $result['html']);
        self::assertStringContainsString('<h1>Hello</h1>', $result['html']);
    }

    public function test_the_cap_applies_after_stripping_and_is_reported(): void
    {
        $big = '<html><body><p>' . str_repeat("\u{e9}", 1_500) . '</p></body></html>';
        Kit_Media_Test_State::$http['https://example.test/big/'] = ['code' => 200, 'body' => $big];

        $result = wordpress_get_rendered_html(['url' => '/big/', 'max_bytes' => 1_001]);

        self::assertIsArray($result);
        self::assertTrue($result['truncated']);
        self::assertLessThanOrEqual(1_001, $result['returned_bytes']);
        self::assertSame(strlen($big), $result['html_bytes']);
        self::assertTrue(mb_check_encoding($result['html'], 'UTF-8'), 'no character split');
        self::assertStringContainsString('cut at 1001', $result['note']);
    }

    public function test_refuses_another_host_and_non_http_schemes(): void
    {
        $off = wordpress_get_rendered_html(['url' => 'https://evil.example/']);
        $relative = wordpress_get_rendered_html(['url' => '//evil.example/x']);
        $scheme = wordpress_get_rendered_html(['url' => 'ftp://example.test/x']);

        self::assertInstanceOf(WP_Error::class, $off);
        self::assertSame('wppilot_rendered_html_off_site', $off->get_error_code());
        self::assertInstanceOf(WP_Error::class, $relative);
        self::assertInstanceOf(WP_Error::class, $scheme);
        self::assertSame('wppilot_rendered_html_bad_url', $scheme->get_error_code());
    }

    public function test_a_published_post_is_fetched_and_a_draft_is_refused(): void
    {
        $published = new WP_Post();
        $published->ID = 7;
        $published->post_status = 'publish';
        $draft = new WP_Post();
        $draft->ID = 8;
        $draft->post_status = 'draft';
        WPPilot_Test_State::$posts[7] = $published;
        WPPilot_Test_State::$posts[8] = $draft;
        Kit_Media_Test_State::$http['https://example.test/?p=7'] = ['code' => 200, 'body' => '<p>Seven</p>'];

        $ok = wordpress_get_rendered_html(['post_id' => 7]);
        $refused = wordpress_get_rendered_html(['post_id' => 8]);
        $both = wordpress_get_rendered_html(['post_id' => 7, 'url' => '/about/']);

        self::assertIsArray($ok);
        self::assertSame('<p>Seven</p>', $ok['html']);
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('wppilot_rendered_html_unpublished', $refused->get_error_code());
        self::assertStringContainsString('wppilot/verify-rendered-page', $refused->get_error_message());
        self::assertInstanceOf(WP_Error::class, $both);
        self::assertSame('wppilot_rendered_html_target', $both->get_error_code());
    }

    public function test_an_error_page_is_returned_with_its_status(): void
    {
        Kit_Media_Test_State::$http['https://example.test/gone/'] = ['code' => 404, 'body' => '<h1>Not found</h1>'];

        $result = wordpress_get_rendered_html(['url' => '/gone/']);

        self::assertIsArray($result);
        self::assertSame(404, $result['status']);
        self::assertSame('<h1>Not found</h1>', $result['html']);
        self::assertStringContainsString('HTTP 404', $result['note']);
    }

    public function test_strip_leaves_unrelated_link_tags(): void
    {
        $html = '<link rel="icon" href="/f.ico"><link rel=stylesheet href=/x.css><link rel="preload stylesheet" href="/y.css">';

        $result = wordpress_rendered_html_strip($html, false, true);

        self::assertIsArray($result);
        self::assertSame('<link rel="icon" href="/f.ico">', $result['html']);
        self::assertSame(2, $result['counts']['stylesheet_links']);
    }

    public function test_needs_edit_posts(): void
    {
        WPPilot_Test_State::$capabilities = [];

        $result = wordpress_get_rendered_html(['url' => '/about/']);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_rendered_html_forbidden', $result->get_error_code());
    }
}
