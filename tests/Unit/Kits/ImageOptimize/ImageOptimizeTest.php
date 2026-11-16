<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\ImageOptimize;

use GoBuildWP\Kits\ImageOptimize as Opt;
use Kit_Fake_Image_Editor;
use Kit_Media_Fake_Wpdb;
use Kit_Media_Test_State;
use Kit_Test_Site;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Hosts\StandaloneHost;
use WPPilot\Kits\Runtime\MiniLedger;
use WPPilot\Tests\Unit\Kits\RuntimeAbilities;

/**
 * gobuildwp/image-optimize-*: the confirm gate, resize + recompress with a kept original, WebP/AVIF
 * copies, the bulk cursor, the dry-run estimate, undo through the runtime ledger, the restore
 * ability, and <picture> serving that only wraps an image when every file it names has a copy.
 */
final class ImageOptimizeTest extends TestCase
{
    /** Bytes of every fake original; the fake editor compresses only files of exactly this size harder. */
    private const ORIGINAL_BYTES = 4096;

    private const SIZE_BYTES = 1024;

    private static ?StandaloneHost $host = null;

    private mixed $wpdb = null;

    /** @var array<string, array{0: int, 1: int}> basename => [width, height] */
    private array $dims = [];

    /** @var array<string, mixed> */
    private array $server = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/media-doubles.php';
        require_once dirname(__DIR__) . '/doubles/runtime-abilities.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/hosts/standalone.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/image-optimize/src/optimize.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/image-optimize/src/serving.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/image-optimize/src/abilities/image-optimize.php';
    }

    protected function setUp(): void
    {
        Kit_Test_Site::reset();
        delete_option(MiniLedger::OPTION);
        Kit_Test_Site::as_user(1, 'edit_post', 'upload_files', 'manage_options');
        Kit_Media_Test_State::reset();
        Kit_Fake_Image_Editor::$saved_paths = [];
        $this->dims = [];
        $this->server = $_SERVER;
        $this->wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new Kit_Media_Fake_Wpdb();
        Kit_Media_Test_State::$editor_factory = function (string $path): Kit_Fake_Image_Editor {
            [$width, $height] = $this->dims[basename($path)] ?? [100, 100];
            $editor = new Kit_Fake_Image_Editor($path, $width, $height);
            // Untouched originals compress to a fraction; already-small files compress further, so copies come out smaller.
            $editor->byte_factor = filesize($path) === self::ORIGINAL_BYTES ? 0.0002 : 0.0001;
            return $editor;
        };
        self::$host ??= new StandaloneHost('kitprobe');
        Runtime\host(self::$host);
        Opt\register_undo(Runtime\host()->ledger());
        RuntimeAbilities::$abilities = [];
        RuntimeAbilities::add(Opt\RUN_ABILITY, ['annotations' => ['readonly' => false, 'destructive' => true]]);
        RuntimeAbilities::add(Opt\RESTORE_ABILITY, ['annotations' => ['readonly' => false, 'destructive' => true]]);
    }

    protected function tearDown(): void
    {
        Kit_Media_Test_State::cleanup();
        $GLOBALS['wpdb'] = $this->wpdb;
        $_SERVER = $this->server;
        RuntimeAbilities::$abilities = [];
    }

    // --- registration ---------------------------------------------------------------------------

    public function testTheFiveAbilitiesAreRegisteredWithHonestAnnotations(): void
    {
        $expect = [
            'gobuildwp/image-optimize-status' => [true, false],
            'gobuildwp/image-optimize-estimate' => [true, false],
            'gobuildwp/image-optimize-run' => [false, true],
            'gobuildwp/image-optimize-restore' => [false, true],
            'gobuildwp/image-optimize-serving' => [false, false],
        ];
        foreach ($expect as $name => [$readonly, $destructive]) {
            $args = self::registration($name);
            self::assertSame($readonly, $args['meta']['annotations']['readonly'], $name);
            self::assertSame($destructive, $args['meta']['annotations']['destructive'], $name);
        }
        self::assertArrayHasKey('confirm', self::registration(Opt\RUN_ABILITY)['input_schema']['properties']);
        self::assertSame(Opt\MAX_BATCH, self::registration(Opt\RUN_ABILITY)['input_schema']['properties']['limit']['maximum']);
    }

    public function testRunNeedsUploadFilesAndServingNeedsManageOptions(): void
    {
        $run = self::registration(Opt\RUN_ABILITY)['permission_callback'];
        $serving = self::registration(Opt\SERVING_ABILITY)['permission_callback'];
        self::assertTrue($run());
        self::assertTrue($serving());
        Kit_Test_Site::as_user(1, 'manage_options');
        self::assertFalse($run());
        Kit_Test_Site::as_user(1, 'upload_files', 'manage_options');
        self::assertTrue($run());
        self::assertTrue($serving());
    }

    // --- run ------------------------------------------------------------------------------------

    public function testRunRefusesWithoutConfirmAndChangesNothing(): void
    {
        $this->image(1, '2026/09/photo.jpg', 3000, 2000);

        $result = Opt\run(['attachment_ids' => [1]]);

        self::assertSame('kit_confirmation_required', self::code($result));
        self::assertSame(self::ORIGINAL_BYTES, filesize($this->path('2026/09/photo.jpg')));
        self::assertNull(Opt\record_of(1));
        self::assertSame([], Kit_Fake_Image_Editor::$saved_paths);
    }

    public function testRunResizesKeepsTheOriginalAndWritesCopiesOfEverySize(): void
    {
        $this->image(1, '2026/09/photo.jpg', 3000, 2000, ['medium' => [300, 200]]);

        $result = Opt\run(['attachment_ids' => [1], 'formats' => ['webp', 'avif'], 'confirm' => true]);

        self::assertIsArray($result);
        $row = $result['results'][0];
        self::assertSame('optimized', $row['status']);
        self::assertTrue($row['original_replaced']);
        self::assertSame(['from' => [3000, 2000], 'to' => [2560, 1707]], $row['resized']);
        self::assertSame('2026/09/photo.optimize-backup.jpg', $row['backup']);
        self::assertSame(self::ORIGINAL_BYTES, filesize($this->path('2026/09/photo.optimize-backup.jpg')));
        self::assertLessThan(self::ORIGINAL_BYTES, filesize($this->path('2026/09/photo.jpg')));

        $meta = wp_get_attachment_metadata(1);
        self::assertSame([2560, 1707], [$meta['width'], $meta['height']]);
        self::assertSame(filesize($this->path('2026/09/photo.jpg')), $meta['filesize']);

        self::assertSame(['2026/09/photo.jpg', '2026/09/photo-300x200.jpg'], array_keys($row['copies']));
        foreach (array_keys($row['copies']) as $relative) {
            self::assertSame(['avif', 'webp'], array_keys($row['copies'][$relative]));
            self::assertFileExists($this->path($relative . '.webp'));
            self::assertFileExists($this->path($relative . '.avif'));
        }
        self::assertSame(['optimized' => 1, 'skipped' => 0, 'failed' => 0, 'bytes_saved' => self::ORIGINAL_BYTES - $row['optimized_bytes']], $result['summary']);
        self::assertCount(1, $result['change_ids']);
        self::assertSame($row['backup'], Opt\record_of(1)['backup']);
    }

    public function testFormatsTheEditorCannotWriteAreReportedNotFaked(): void
    {
        $this->image(1, '2026/09/photo.jpg', 1200, 800);
        Kit_Media_Test_State::$editor_supports = false;

        $result = Opt\run(['attachment_ids' => [1], 'formats' => ['webp', 'avif'], 'confirm' => true]);

        self::assertIsArray($result);
        self::assertSame([], $result['formats']);
        self::assertSame(['avif', 'webp'], $result['unsupported_formats']);
        self::assertSame([], $result['results'][0]['copies']);
        self::assertNull($result['results'][0]['resized']);
        self::assertFileDoesNotExist($this->path('2026/09/photo.jpg.webp'));
    }

    public function testTheBulkRunnerWorksThroughJpegAndPngWithAStoredCursor(): void
    {
        $this->image(1, '2026/09/one.jpg', 1200, 800);
        $this->image(2, '2026/09/two.png', 1200, 800, [], 'image/png');
        $this->image(3, '2026/09/anim.gif', 1200, 800, [], 'image/gif');
        $this->image(4, '2026/09/four.jpg', 1200, 800);

        $first = Opt\run(['limit' => 2, 'confirm' => true]);

        self::assertIsArray($first);
        self::assertSame([1, 2], array_column($first['results'], 'attachment_id'));
        self::assertSame(2, $first['next_cursor']);
        self::assertSame(1, $first['remaining']);
        self::assertFalse($first['done']);
        self::assertSame(2, get_option(Opt\CURSOR_OPTION));

        $second = Opt\run(['confirm' => true]);

        self::assertIsArray($second);
        self::assertSame([4], array_column($second['results'], 'attachment_id'));
        self::assertNull($second['next_cursor']);
        self::assertTrue($second['done']);
        self::assertFalse(get_option(Opt\CURSOR_OPTION, false));
        self::assertNull(Opt\record_of(3), 'GIFs are never touched');
    }

    public function testAnImageAlreadyOptimisedIsSkippedAndNotRecordedAgain(): void
    {
        $this->image(1, '2026/09/photo.jpg', 1200, 800);
        Opt\run(['attachment_ids' => [1], 'confirm' => true]);

        $again = Opt\run(['attachment_ids' => [1], 'confirm' => true]);

        self::assertIsArray($again);
        self::assertSame(['attachment_id' => 1, 'status' => 'skipped', 'reason' => 'already_optimized'], $again['results'][0]);
        self::assertSame([], $again['change_ids']);
        self::assertCount(1, Runtime\host()->ledger()->all());
    }

    // --- estimate -------------------------------------------------------------------------------

    public function testTheEstimateMeasuresASampleAndLeavesNothingBehind(): void
    {
        $this->image(1, '2026/09/photo.jpg', 3000, 2000);
        $this->image(2, '2026/09/small.jpg', 1200, 800);

        $estimate = Opt\estimate(['formats' => ['webp'], 'sample' => 1]);

        self::assertSame(2, $estimate['scanned']);
        self::assertSame(2, $estimate['ready']);
        self::assertSame(1, $estimate['would_resize']);
        self::assertSame(2 * self::ORIGINAL_BYTES, $estimate['original_bytes']);
        self::assertCount(1, $estimate['sample']);
        self::assertArrayHasKey('webp_bytes', $estimate['sample'][0]);
        self::assertGreaterThan(0.5, $estimate['estimated_saving_ratio']);
        self::assertNull($estimate['next_cursor']);

        self::assertSame(self::ORIGINAL_BYTES, filesize($this->path('2026/09/photo.jpg')));
        self::assertNull(Opt\record_of(1));
        self::assertSame([], glob(Kit_Media_Test_State::$basedir . '/kit-image-optimize-*'));
        self::assertNotSame([], Kit_Fake_Image_Editor::$saved_paths, 'the sample really encoded');
        foreach (Kit_Fake_Image_Editor::$saved_paths as $made) {
            self::assertFileDoesNotExist($made);
        }
        self::assertSame([], Runtime\host()->ledger()->all());
    }

    // --- undo and restore -----------------------------------------------------------------------

    public function testUndoThroughTheLedgerPutsTheOriginalBackAndDeletesTheCopies(): void
    {
        $this->image(1, '2026/09/photo.jpg', 3000, 2000, ['medium' => [300, 200]]);
        $result = Opt\run(['attachment_ids' => [1], 'confirm' => true]);
        self::assertIsArray($result);

        $undone = Runtime\host()->ledger()->rollback($result['change_ids'][0]);

        self::assertIsArray($undone, self::code($undone));
        self::assertTrue($undone['verified']);
        self::assertSame(self::ORIGINAL_BYTES, filesize($this->path('2026/09/photo.jpg')));
        self::assertFileDoesNotExist($this->path('2026/09/photo.optimize-backup.jpg'));
        self::assertFileDoesNotExist($this->path('2026/09/photo.jpg.webp'));
        self::assertFileDoesNotExist($this->path('2026/09/photo-300x200.jpg.webp'));
        $meta = wp_get_attachment_metadata(1);
        self::assertSame([3000, 2000, self::ORIGINAL_BYTES], [$meta['width'], $meta['height'], $meta['filesize']]);
        self::assertNull(Opt\record_of(1));
    }

    public function testUndoIsRefusedWhenTheAttachmentNowPointsAtAnotherFile(): void
    {
        $this->image(1, '2026/09/photo.jpg', 3000, 2000);
        $result = Opt\run(['attachment_ids' => [1], 'confirm' => true]);
        self::assertIsArray($result);
        update_attached_file(1, Kit_Media_Test_State::$basedir . '/2026/09/renamed.jpg');

        $undone = Runtime\host()->ledger()->rollback($result['change_ids'][0]);

        self::assertSame('kit_image_optimize_file_changed', self::code($undone));
        self::assertFileExists($this->path('2026/09/photo.optimize-backup.jpg'));
    }

    public function testTheRestoreAbilityNeedsConfirmAndReportsEachImage(): void
    {
        $this->image(1, '2026/09/photo.jpg', 1200, 800, ['medium' => [300, 200]]);
        $this->image(2, '2026/09/other.jpg', 1200, 800);
        Opt\run(['attachment_ids' => [1], 'confirm' => true]);

        self::assertSame('kit_confirmation_required', self::code(Opt\restore(['attachment_ids' => [1]])));
        self::assertNotNull(Opt\record_of(1));

        $restored = Opt\restore(['attachment_ids' => [1, 2], 'confirm' => true]);

        self::assertIsArray($restored);
        self::assertSame('restored', $restored['results'][0]['status']);
        self::assertTrue($restored['results'][0]['original_restored']);
        self::assertSame(2, $restored['results'][0]['copies_removed']);
        self::assertTrue($restored['results'][0]['verified']);
        self::assertSame(['attachment_id' => 2, 'status' => 'skipped', 'reason' => 'not_optimized'], $restored['results'][1]);
        self::assertSame(self::ORIGINAL_BYTES, filesize($this->path('2026/09/photo.jpg')));
        self::assertSame('kit_image_optimize_no_ids', self::code(Opt\restore(['confirm' => true])));
    }

    public function testDeletingTheAttachmentRemovesTheKeptOriginalAndCopies(): void
    {
        $this->image(1, '2026/09/photo.jpg', 1200, 800);
        Opt\run(['attachment_ids' => [1], 'confirm' => true]);
        self::assertFileExists($this->path('2026/09/photo.jpg.webp'));

        Opt\forget_attachment(1);

        self::assertFileDoesNotExist($this->path('2026/09/photo.optimize-backup.jpg'));
        self::assertFileDoesNotExist($this->path('2026/09/photo.jpg.webp'));
        self::assertFileExists($this->path('2026/09/photo.jpg'));
    }

    // --- serving --------------------------------------------------------------------------------

    public function testAnImageIsWrappedOnlyWhenEveryFileItNamesHasACopy(): void
    {
        $this->image(1, '2026/09/photo.jpg', 1200, 800, ['medium' => [300, 200]]);
        Opt\run(['attachment_ids' => [1], 'confirm' => true]);
        $base = 'https://example.test/wp-content/uploads/2026/09/';
        $img = '<img class="wp-image-1" src="' . $base . 'photo.jpg" srcset="' . $base . 'photo.jpg 1200w, ' . $base . 'photo-300x200.jpg 300w" sizes="(max-width: 1200px) 100vw">';

        self::assertSame(
            '<picture><source type="image/webp" srcset="' . $base . 'photo.jpg.webp 1200w, ' . $base . 'photo-300x200.jpg.webp 300w" sizes="(max-width: 1200px) 100vw">' . $img . '</picture>',
            Opt\wrap_img($img),
        );

        $missing = '<img class="wp-image-1" src="' . $base . 'photo.jpg" srcset="' . $base . 'photo.jpg 1200w, ' . $base . 'photo-768x512.jpg 768w">';
        self::assertSame($missing, Opt\wrap_img($missing));
        $unknown = '<img class="wp-image-9" src="' . $base . 'photo.jpg">';
        self::assertSame($unknown, Opt\wrap_img($unknown));
    }

    public function testContentIsRewrittenOnlyOnTheFrontEndWithPictureMode(): void
    {
        $this->image(1, '2026/09/photo.jpg', 1200, 800);
        Opt\run(['attachment_ids' => [1], 'confirm' => true]);
        $html = '<p><img class="wp-image-1" src="https://example.test/wp-content/uploads/2026/09/photo.jpg"></p>';

        self::assertSame($html, Opt\filter_content($html), 'serving is off by default');

        Opt\set_serving(['mode' => 'picture']);
        self::assertStringStartsWith('<p><picture><source type="image/webp"', (string) Opt\filter_content($html));
        $wrapped = (string) Opt\filter_content($html);
        self::assertSame($wrapped, Opt\filter_content($wrapped), 'an image already in <picture> is left alone');

        Kit_Media_Test_State::$is_admin = true;
        self::assertSame($html, Opt\filter_content($html));
    }

    public function testAServingChangeIsUndoneThroughTheLedger(): void
    {
        $ledger = Runtime\host()->ledger();
        $input = ['mode' => 'picture'];
        $ledger->before(Opt\SERVING_ABILITY, $input);
        $result = Opt\set_serving($input);
        $ledger->after(Opt\SERVING_ABILITY, $input, $result);
        self::assertSame(['mode' => 'picture', 'previous' => 'off'], array_slice($result, 0, 2));

        $rows = $ledger->all();
        $undone = $ledger->rollback((string) end($rows)['id']);

        self::assertIsArray($undone, self::code($undone));
        self::assertSame('off', Opt\serve_mode());
        self::assertSame('kit_image_optimize_bad_mode', self::code(Opt\set_serving(['mode' => 'htaccess'])));
    }

    public function testStatusNamesOpenLiteSpeedAndNeverOffersRewriteRules(): void
    {
        $this->image(1, '2026/09/photo.jpg', 1200, 800);
        $this->image(2, '2026/09/other.jpg', 1200, 800);
        Opt\run(['attachment_ids' => [1], 'confirm' => true]);
        $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
        $_SERVER['LSWS_EDITION'] = 'Openlitespeed 1.7';

        $status = Opt\status();

        self::assertSame('openlitespeed', $status['server']['kind']);
        self::assertStringStartsWith('not used', $status['server']['rewrite_rules']);
        self::assertSame('none', $status['serving']['method']);
        self::assertSame(['jpeg_png_attachments' => 2, 'optimized' => 1, 'after_cursor' => 2], $status['images']);
        self::assertTrue($status['editor_supports']['webp']);
    }

    // --- helpers --------------------------------------------------------------------------------

    /**
     * An attachment whose original is ORIGINAL_BYTES on disk, with generated sizes next to it.
     *
     * @param array<string, array{0: int, 1: int}> $sizes
     */
    private function image(int $id, string $relative, int $width, int $height, array $sizes = [], string $mime = 'image/jpeg'): void
    {
        $meta_sizes = [];
        $stem = pathinfo($relative, PATHINFO_FILENAME);
        $ext = pathinfo($relative, PATHINFO_EXTENSION);
        foreach ($sizes as $name => [$w, $h]) {
            $meta_sizes[$name] = ['file' => "{$stem}-{$w}x{$h}.{$ext}", 'width' => $w, 'height' => $h, 'mime-type' => $mime];
        }
        Kit_Media_Test_State::add_image($id, $relative, $width, $height, $mime, ['sizes' => $meta_sizes, 'filesize' => self::ORIGINAL_BYTES]);
        file_put_contents($this->path($relative), str_repeat('o', self::ORIGINAL_BYTES));
        $this->dims[basename($relative)] = [$width, $height];
        foreach ($meta_sizes as $size) {
            file_put_contents($this->path(dirname($relative) . '/' . $size['file']), str_repeat('s', self::SIZE_BYTES));
            $this->dims[$size['file']] = [$size['width'], $size['height']];
        }
    }

    private function path(string $relative): string
    {
        clearstatcache();
        return Kit_Media_Test_State::$basedir . '/' . $relative;
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
