<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\A11yAudit;

use Kit_Fake_Image_Editor;
use Kit_Media_Test_State;
use Kit_Test_Site;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\A11yAudit;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Hosts\StandaloneHost;
use WPPilot\Kits\Runtime\MiniLedger;
use WPPilot\Kits\Runtime\PostPartial;

/**
 * The media side of a11y-audit: the alt-text scan, bulk alt writes with per-image undo, the
 * preview the model looks at, and which page the audit fetches.
 */
final class MediaAltTest extends TestCase
{
    private MiniLedger $ledger;

    private static ?StandaloneHost $host = null;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/media-doubles.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/hosts/standalone.php';
        foreach (['checks', 'audit', 'media'] as $file) {
            require_once dirname(__DIR__, 4) . '/includes/kits/a11y-audit/src/' . $file . '.php';
        }
        foreach (['audit-accessibility', 'audit-media-alt', 'get-media-image', 'update-image-alt'] as $file) {
            require_once dirname(__DIR__, 4) . '/includes/kits/a11y-audit/src/abilities/' . $file . '.php';
        }
    }

    protected function setUp(): void
    {
        Kit_Test_Site::reset();
        delete_option(MiniLedger::OPTION);
        Kit_Test_Site::as_user(1, 'edit_post', 'read_post', 'upload_files', 'manage_options');
        Kit_Media_Test_State::reset();
        Kit_Fake_Image_Editor::$saved_paths = [];
        // One host for the class: each StandaloneHost hooks the permission filter again.
        self::$host ??= new StandaloneHost('kitprobe');
        $host = self::$host;
        Runtime\host($host);
        $ledger = $host->ledger();
        self::assertInstanceOf(MiniLedger::class, $ledger);
        $this->ledger = $ledger;
        PostPartial\register($this->ledger);
    }

    protected function tearDown(): void
    {
        Kit_Media_Test_State::cleanup();
    }

    public function testAllFourAbilitiesAreRegisteredWithTheRightAnnotations(): void
    {
        $expected = [
            'wppilot/audit-accessibility' => true,
            'wppilot/audit-media-alt' => true,
            'wppilot/get-media-image' => true,
            'wppilot/update-image-alt' => false,
        ];
        foreach ($expected as $name => $readonly) {
            self::assertTrue(wp_has_ability($name), $name);
            $args = self::registration($name);
            self::assertSame($readonly, $args['meta']['annotations']['readonly'], $name);
            self::assertFalse($args['meta']['annotations']['destructive'], $name);
        }
        self::assertSame(100, self::registration('wppilot/update-image-alt')['input_schema']['properties']['items']['maxItems']);
    }

    // --- audit-media-alt ------------------------------------------------------------------------

    public function testScanClassifiesAltTextAndListsOnlyIssuesByDefault(): void
    {
        Kit_Media_Test_State::add_image(11, '2026/09/beach.jpg', 1600, 900);
        Kit_Media_Test_State::add_image(12, '2026/09/IMG_2041.jpg', 1600, 900);
        Kit_Media_Test_State::add_image(13, '2026/09/team.jpg', 1600, 900);
        Kit_Media_Test_State::add_image(14, '2026/09/divider-line.png', 1200, 20, 'image/png');
        Kit_Test_Site::set_meta(12, '_wp_attachment_image_alt', 'IMG_2041');
        Kit_Test_Site::set_meta(13, '_wp_attachment_image_alt', 'The team outside the office');
        Kit_Media_Test_State::$attachment_counts = ['image/jpeg' => 3, 'image/png' => 1, 'trash' => 5];

        $scan = A11yAudit\scan_media_alt([]);

        self::assertSame([14, 12, 11], array_column($scan['images'], 'attachment_id'));
        $by_id = array_column($scan['images'], null, 'attachment_id');
        self::assertSame('missing', $by_id[11]['alt_status']);
        self::assertSame('filename', $by_id[12]['alt_status']);
        self::assertTrue($by_id[14]['decorative_guess']['likely']);
        self::assertFalse($by_id[11]['decorative_guess']['likely']);
        self::assertSame(['missing' => 2, 'filename' => 1, 'ok' => 1, 'likely_decorative' => 1], $scan['page_summary']);
        self::assertSame(4, $scan['total_images']);
        self::assertNull($scan['next_page']);
    }

    public function testScanPagesThroughTheLibrary(): void
    {
        foreach (range(1, 5) as $id) {
            Kit_Media_Test_State::add_image($id, "2026/09/p{$id}.jpg", 800, 600);
        }
        Kit_Media_Test_State::$attachment_counts = ['image/jpeg' => 5];

        $first = A11yAudit\scan_media_alt(['per_page' => 2, 'include' => 'all']);
        $last = A11yAudit\scan_media_alt(['per_page' => 2, 'page' => 3, 'include' => 'all']);

        self::assertSame([5, 4], array_column($first['images'], 'attachment_id'));
        self::assertSame(2, $first['next_page']);
        self::assertSame([1], array_column($last['images'], 'attachment_id'));
        self::assertNull($last['next_page']);
    }

    public function testScanCapsThePageSize(): void
    {
        self::assertSame(A11yAudit\MAX_SCAN_PAGE, A11yAudit\scan_media_alt(['per_page' => 5000])['per_page']);
    }

    public function testDecorativeGuessReasons(): void
    {
        self::assertTrue(A11yAudit\decorative_guess('dot.png', '', 16, 16)['likely']);
        self::assertTrue(A11yAudit\decorative_guess('hero-bg.jpg', '', 1920, 1080)['likely']);
        self::assertTrue(A11yAudit\decorative_guess('rule.png', '', 1000, 4)['likely']);
        self::assertFalse(A11yAudit\decorative_guess('founder-portrait.jpg', 'Our founder', 800, 1000)['likely']);
    }

    // --- update-image-alt -----------------------------------------------------------------------

    public function testBulkUpdateWritesEachImageAsItsOwnRowInOneGroup(): void
    {
        Kit_Media_Test_State::add_image(21, '2026/09/a.jpg', 800, 600);
        Kit_Media_Test_State::add_image(22, '2026/09/b.jpg', 800, 600);
        Kit_Test_Site::set_meta(22, '_wp_attachment_image_alt', 'b.jpg');

        $result = A11yAudit\update_alts(['items' => [
            ['attachment_id' => 21, 'alt' => 'A kayak on a "calm" lake'],
            ['attachment_id' => 22, 'alt' => 'C:\\path is not special'],
        ]]);

        self::assertIsArray($result);
        self::assertSame(2, $result['updated']);
        self::assertSame(['A kayak on a "calm" lake'], get_post_meta(21, '_wp_attachment_image_alt'));
        // wp_slash on the write: WordPress unslashes, and a backslash in alt text must survive.
        self::assertSame(['C:\\path is not special'], get_post_meta(22, '_wp_attachment_image_alt'));

        $rows = $this->ledger->all();
        self::assertCount(2, $rows);
        self::assertSame($result['group'], $rows[0]['group']);
        self::assertSame($rows[0]['group'], $rows[1]['group']);
        self::assertSame(['attachment_id' => 21], $rows[0]['item']);
        self::assertSame([$rows[0]['id'], $rows[1]['id']], array_column($result['results'], 'change_id'));
        foreach ($rows as $row) {
            self::assertTrue($row['rollback']['reversible']);
            self::assertSame(PostPartial\TYPE, $row['rollback']['type']);
            self::assertSame([A11yAudit\ALT_META_KEY], array_keys($row['rollback']['snapshot']['meta']));
            self::assertSame([], $row['rollback']['snapshot']['fields']);
        }
        self::assertNull($rows[0]['rollback']['snapshot']['meta'][A11yAudit\ALT_META_KEY]);
        self::assertSame(['b.jpg'], $rows[1]['rollback']['snapshot']['meta'][A11yAudit\ALT_META_KEY]);
    }

    public function testOneImageCanBeUndoneWithoutTheOthers(): void
    {
        Kit_Media_Test_State::add_image(21, '2026/09/a.jpg', 800, 600);
        Kit_Media_Test_State::add_image(22, '2026/09/b.jpg', 800, 600);
        Kit_Test_Site::set_meta(22, '_wp_attachment_image_alt', 'old b');
        $result = A11yAudit\update_alts(['items' => [['attachment_id' => 21, 'alt' => 'New a'], ['attachment_id' => 22, 'alt' => 'New b']]]);
        self::assertIsArray($result);

        $undone = $this->ledger->rollback((string) $result['results'][1]['change_id']);

        self::assertIsArray($undone);
        self::assertTrue($undone['verified']);
        self::assertSame(['old b'], get_post_meta(22, '_wp_attachment_image_alt'));
        self::assertSame(['New a'], get_post_meta(21, '_wp_attachment_image_alt'));

        // An image that had no alt before goes back to having none, not an empty one.
        $first = $this->ledger->rollback((string) $result['results'][0]['change_id']);
        self::assertIsArray($first);
        self::assertArrayNotHasKey(A11yAudit\ALT_META_KEY, Kit_Test_Site::raw_meta(21));
    }

    public function testTheWholeBatchCanBeUndoneThroughItsGroup(): void
    {
        foreach ([31, 32, 33] as $id) {
            Kit_Media_Test_State::add_image($id, "2026/09/{$id}.jpg", 800, 600);
            Kit_Test_Site::set_meta($id, '_wp_attachment_image_alt', "old {$id}");
        }
        $result = A11yAudit\update_alts(['items' => array_map(static fn(int $id): array => ['attachment_id' => $id, 'alt' => "new {$id}"], [31, 32, 33])]);
        self::assertIsArray($result);

        foreach ($this->ledger->query(['group' => (string) $result['group']]) as $row) {
            $undone = $this->ledger->rollback((string) $row['id']);
            self::assertIsArray($undone);
        }

        foreach ([31, 32, 33] as $id) {
            self::assertSame(["old {$id}"], get_post_meta($id, '_wp_attachment_image_alt'));
        }
    }

    public function testItemsThatCannotBeWrittenAreReportedAndNotRecorded(): void
    {
        Kit_Media_Test_State::add_image(41, '2026/09/a.jpg', 800, 600);
        Kit_Media_Test_State::add_image(42, '2026/09/doc.pdf', 0, 0, 'application/pdf');
        Kit_Media_Test_State::add_image(43, '2026/09/c.jpg', 800, 600);
        Kit_Test_Site::set_meta(43, '_wp_attachment_image_alt', 'Same');

        $result = A11yAudit\update_alts(['items' => [
            ['attachment_id' => 41, 'alt' => 'First'],
            ['attachment_id' => 41, 'alt' => 'Second'],
            ['attachment_id' => 42, 'alt' => 'A PDF'],
            ['attachment_id' => 43, 'alt' => 'Same'],
            ['attachment_id' => 999, 'alt' => 'Nothing'],
            ['attachment_id' => 44],
        ]]);

        self::assertIsArray($result);
        self::assertSame(['updated', 'skipped', 'skipped', 'unchanged', 'skipped', 'skipped'], array_column($result['results'], 'status'));
        self::assertSame(['First'], get_post_meta(41, '_wp_attachment_image_alt'));
        self::assertCount(1, $this->ledger->all());
    }

    public function testNothingWrittenMeansNoGroupAndNoRows(): void
    {
        Kit_Media_Test_State::add_image(43, '2026/09/c.jpg', 800, 600);
        Kit_Test_Site::set_meta(43, '_wp_attachment_image_alt', 'Same');

        $result = A11yAudit\update_alts(['items' => [['attachment_id' => 43, 'alt' => 'Same']]]);

        self::assertIsArray($result);
        self::assertNull($result['group']);
        self::assertSame([], $this->ledger->all());
    }

    public function testNoWriteHappensWithoutABeforeImageWhenTheBudgetRunsOut(): void
    {
        Kit_Media_Test_State::add_image(51, '2026/09/a.jpg', 800, 600);
        Kit_Media_Test_State::add_image(52, '2026/09/b.jpg', 800, 600);
        // The first snapshot fits; the second would pass the budget.
        Kit_Test_Site::set_meta(51, '_wp_attachment_image_alt', 'x');
        Kit_Test_Site::set_meta(52, '_wp_attachment_image_alt', str_repeat('y', MiniLedger::SNAPSHOT_BUDGET));

        $result = A11yAudit\update_alts(['items' => [['attachment_id' => 51, 'alt' => 'A'], ['attachment_id' => 52, 'alt' => 'B']]]);

        self::assertIsArray($result);
        self::assertSame(['updated', 'skipped'], array_column($result['results'], 'status'));
        self::assertSame([str_repeat('y', MiniLedger::SNAPSHOT_BUDGET)], get_post_meta(52, '_wp_attachment_image_alt'));
    }

    public function testAWriteWordPressDidNotKeepIsReportedAsFailed(): void
    {
        Kit_Media_Test_State::add_image(61, '2026/09/a.jpg', 800, 600);
        Kit_Test_Site::$refuse_meta_writes = true;

        $result = A11yAudit\update_alts(['items' => [['attachment_id' => 61, 'alt' => 'A']]]);

        self::assertIsArray($result);
        self::assertSame('failed', $result['results'][0]['status']);
        self::assertSame([], $this->ledger->all());
    }

    public function testRefusesAnEmptyOrOversizedBatch(): void
    {
        self::assertInstanceOf(WP_Error::class, A11yAudit\update_alts(['items' => []]));
        self::assertInstanceOf(WP_Error::class, A11yAudit\update_alts(['items' => array_fill(0, 101, ['attachment_id' => 1, 'alt' => 'x'])]));
    }

    public function testEmptyAltMarksAnImageDecorative(): void
    {
        Kit_Media_Test_State::add_image(71, '2026/09/spacer.gif', 1, 1, 'image/gif');
        Kit_Test_Site::set_meta(71, '_wp_attachment_image_alt', 'spacer.gif');

        $result = A11yAudit\update_alts(['items' => [['attachment_id' => 71, 'alt' => '']]]);

        self::assertIsArray($result);
        self::assertTrue($result['results'][0]['decorative']);
        self::assertSame([''], get_post_meta(71, '_wp_attachment_image_alt'));
    }

    // --- get-media-image ------------------------------------------------------------------------

    public function testPreviewIsDownscaledAndReturnedAsImageContentWithNoTempFileLeft(): void
    {
        Kit_Media_Test_State::add_image(81, '2026/09/photo.jpg', 4000, 3000);
        Kit_Test_Site::set_meta(81, '_wp_attachment_image_alt', 'Old alt');
        $opened = [];
        Kit_Media_Test_State::$editor_factory = static function (string $path) use (&$opened): Kit_Fake_Image_Editor {
            $opened[] = $path;
            return new Kit_Fake_Image_Editor($path, 4000, 3000);
        };

        $result = A11yAudit\media_image(['attachment_id' => 81]);

        self::assertIsArray($result);
        self::assertSame([1024, 768], [$result['width'], $result['height']]);
        self::assertSame('image/webp', $result['mime_type']);
        self::assertSame('Old alt', $result['alt']);
        self::assertCount(1, $result['_mcp_content']);
        self::assertSame('image', $result['_mcp_content'][0]['type']);
        self::assertSame('image/webp', $result['_mcp_content'][0]['mimeType']);
        self::assertSame($result['bytes'], strlen((string) base64_decode($result['_mcp_content'][0]['data'], true)));
        self::assertNotSame([], Kit_Fake_Image_Editor::$saved_paths);
        foreach (Kit_Fake_Image_Editor::$saved_paths as $path) {
            self::assertFileDoesNotExist($path);
        }
        self::assertFileExists(Kit_Media_Test_State::$basedir . '/2026/09/photo.jpg');
    }

    public function testPreviewStartsFromTheSmallestStoredSizeThatIsLargeEnough(): void
    {
        Kit_Media_Test_State::add_image(82, '2026/09/photo.jpg', 4000, 3000, 'image/jpeg', ['sizes' => [
            'medium' => ['file' => 'photo-300x225.jpg', 'width' => 300, 'height' => 225],
            'large' => ['file' => 'photo-1024x768.jpg', 'width' => 1024, 'height' => 768],
            '1536x1536' => ['file' => 'photo-1536x1152.jpg', 'width' => 1536, 'height' => 1152],
        ]]);
        foreach (['photo-300x225.jpg', 'photo-1024x768.jpg', 'photo-1536x1152.jpg'] as $file) {
            file_put_contents(Kit_Media_Test_State::$basedir . '/2026/09/' . $file, 'x');
        }
        $path = Kit_Media_Test_State::$basedir . '/2026/09/photo.jpg';
        $meta = wp_get_attachment_metadata(82);
        self::assertIsArray($meta);

        self::assertSame(Kit_Media_Test_State::$basedir . '/2026/09/photo-1024x768.jpg', A11yAudit\preview_source($meta, $path, 1024));
        self::assertSame(Kit_Media_Test_State::$basedir . '/2026/09/photo-1536x1152.jpg', A11yAudit\preview_source($meta, $path, 1200));
        self::assertSame($path, A11yAudit\preview_source($meta, $path, 1568));
    }

    public function testABusyImageIsShrunkUntilItFitsTheByteCap(): void
    {
        Kit_Media_Test_State::add_image(83, '2026/09/busy.jpg', 3000, 3000);
        Kit_Media_Test_State::$editor_supports = false;
        Kit_Media_Test_State::$editor_factory = static function (string $path): Kit_Fake_Image_Editor {
            $editor = new Kit_Fake_Image_Editor($path, 3000, 3000);
            // 1024x1024 at quality 82 or 60 is over 750 KB at this rate; 768x768 at 60 is not.
            $editor->byte_factor = 1.5;
            return $editor;
        };

        $result = A11yAudit\media_image(['attachment_id' => 83]);

        self::assertIsArray($result);
        self::assertSame('image/jpeg', $result['mime_type']);
        self::assertSame(768, $result['width']);
        self::assertLessThanOrEqual(A11yAudit\PREVIEW_MAX_BYTES, $result['bytes']);
        foreach (Kit_Fake_Image_Editor::$saved_paths as $path) {
            self::assertFileDoesNotExist($path);
        }
    }

    public function testPreviewRefusesWhatItCannotShow(): void
    {
        Kit_Media_Test_State::add_image(84, '2026/09/logo.svg', 100, 100, 'image/svg+xml');
        self::assertSame('kit_a11y_image_type', self::code(A11yAudit\media_image(['attachment_id' => 84])));
        self::assertSame('kit_a11y_image_not_found', self::code(A11yAudit\media_image(['attachment_id' => 999])));
        self::assertSame('kit_a11y_image_id', self::code(A11yAudit\media_image([])));

        Kit_Media_Test_State::add_image(85, '2026/09/p.jpg', 100, 100);
        Kit_Test_Site::as_user(1, 'upload_files');
        self::assertSame('kit_a11y_image_forbidden', self::code(A11yAudit\media_image(['attachment_id' => 85])));
    }

    public function testAPreviewConvertedToAFormatModelsRejectIsRefusedAndCleanedUp(): void
    {
        Kit_Media_Test_State::add_image(86, '2026/09/p.jpg', 2000, 1000);
        Kit_Media_Test_State::$editor_factory = static function (string $path): Kit_Fake_Image_Editor {
            $editor = new Kit_Fake_Image_Editor($path, 2000, 1000);
            $editor->force_output_mime = 'image/avif';
            return $editor;
        };

        self::assertSame('kit_a11y_image_encode', self::code(A11yAudit\media_image(['attachment_id' => 86])));
        foreach (Kit_Fake_Image_Editor::$saved_paths as $path) {
            self::assertFileDoesNotExist($path);
        }
    }

    // --- audit-accessibility: which page, and when not to audit ---------------------------------

    public function testTargetIsAPublishedPostsPermalinkOrASiteUrl(): void
    {
        Kit_Test_Site::insert(['ID' => 5, 'post_type' => 'page', 'post_status' => 'publish']);
        $post = get_post(5);
        self::assertInstanceOf(\WP_Post::class, $post);

        self::assertSame('https://example.test/?p=5', A11yAudit\target_url(['post_id' => 5]));
        self::assertSame('https://example.test/about/', A11yAudit\target_url(['url' => '/about/']));
        self::assertSame('//evil.test/x', A11yAudit\target_url(['url' => '//evil.test/x']));
        self::assertSame('kit_a11y_no_target', self::code(A11yAudit\target_url([])));

        $post->post_status = 'draft';
        Kit_Test_Site::as_user(1);
        self::assertSame('kit_a11y_post_not_public', self::code(A11yAudit\target_url(['post_id' => 5])));
    }

    public function testADraftIsAuditedAsItsPreviewOnlyForSomeoneWhoCanEditIt(): void
    {
        Kit_Test_Site::insert(['ID' => 7, 'post_type' => 'page', 'post_status' => 'draft']);

        Kit_Test_Site::as_user(1);
        self::assertSame('kit_a11y_post_not_public', self::code(A11yAudit\target(['post_id' => 7])));

        Kit_Test_Site::as_user(1, 'edit_post');
        self::assertSame(['url' => 'https://example.test/?page_id=7&preview=true', 'preview' => true], A11yAudit\target(['post_id' => 7]));
        self::assertSame(['url' => 'https://example.test/about/', 'preview' => false], A11yAudit\target(['url' => '/about/']));
    }

    public function testASignedInFetchOnlyGoesToTheSiteOwnOrigin(): void
    {
        self::assertTrue(Runtime\Page::is_same_origin('https://example.test/?page_id=7&preview=true'));
        self::assertFalse(Runtime\Page::is_same_origin('http://example.test/?page_id=7'));
        self::assertFalse(Runtime\Page::is_same_origin('https://example.test:8443/'));
        self::assertFalse(Runtime\Page::is_same_origin('https://elsewhere.test/'));
    }

    public function testAuditFetchesSameSiteOnlyAndRefusesErrorPages(): void
    {
        Kit_Media_Test_State::$http['https://example.test/missing/'] = ['code' => 404, 'body' => '<html></html>'];
        Kit_Media_Test_State::$http['https://example.test/ok/'] = ['code' => 200, 'body' => '<html lang="en"><head><title>OK</title></head><body><main><h1>Hi</h1><img src="/a.jpg"></main></body></html>'];

        self::assertSame('kit_page_bad_url', self::code(A11yAudit\audit_page(['url' => 'https://elsewhere.test/'])));
        self::assertSame('kit_page_bad_url', self::code(A11yAudit\audit_page(['url' => '//evil.test/x'])));
        self::assertSame('kit_a11y_http_status', self::code(A11yAudit\audit_page(['url' => '/missing/'])));

        $audit = A11yAudit\audit_page(['url' => '/ok/']);
        self::assertIsArray($audit);
        self::assertSame(200, $audit['status']);
        self::assertSame(['image-alt-missing'], array_column($audit['findings'], 'rule'));
        self::assertSame(90, $audit['score']);
        self::assertStringContainsString('not instructions', $audit['note']);
    }

    /** @return array<string, mixed> */
    private static function registration(string $name): array
    {
        $args = Kit_Test_Site::registration($name);
        self::assertIsArray($args, "{$name} is not registered");
        return $args;
    }

    private static function code(mixed $result): string
    {
        return $result instanceof WP_Error ? $result->get_error_code() : 'no error';
    }
}
