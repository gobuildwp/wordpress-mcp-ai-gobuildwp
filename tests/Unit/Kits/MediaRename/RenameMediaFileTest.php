<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\MediaRename;

use GoBuildWP\Kits\MediaRename as Rename;
use Kit_Media_Fake_Wpdb;
use Kit_Media_Test_State;
use Kit_Test_Site;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Hosts\StandaloneHost;
use WPPilot\Kits\Runtime\MiniLedger;
use WPPilot\Kits\Runtime\PostPartial;
use WPPilot\Tests\Unit\Kits\RuntimeAbilities;

/**
 * gobuildwp/rename-media-file: the dry run, the confirm gate, renaming the scaled file, its
 * original and every size, the metadata, slug and post links that follow, the refusals, and
 * undoing the whole group through the runtime ledger.
 */
final class RenameMediaFileTest extends TestCase
{
    private const URL = 'https://example.test/wp-content/uploads/2026/09/';

    private const CONTENT = '<p><a href="' . self::URL . 'photo.jpg"><img src="' . self::URL . 'photo-300x200.jpg" srcset="' . self::URL . 'photo-scaled.jpg 2560w"></a></p>'
        . '<picture><source srcset="' . self::URL . 'photo-scaled.jpg.webp"></picture><img src="' . self::URL . 'photo-1.jpg">';

    private const EXCERPT = 'See http://example.test/wp-content/uploads/2026/09/photo.jpg?ver=2';

    private const BUILDER = '[{"settings":{"image":{"url":"https:\\/\\/example.test\\/wp-content\\/uploads\\/2026\\/09\\/photo-300x200.jpg"}}}]';

    private static ?StandaloneHost $host = null;

    private mixed $wpdb = null;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/media-doubles.php';
        require_once dirname(__DIR__) . '/doubles/runtime-abilities.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/hosts/standalone.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/media-rename/src/rename.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/media-rename/src/abilities/rename-media-file.php';
    }

    protected function setUp(): void
    {
        Kit_Test_Site::reset();
        delete_option(MiniLedger::OPTION);
        Kit_Test_Site::as_user(1, 'edit_post', 'upload_files', 'manage_options');
        Kit_Media_Test_State::reset();
        $this->wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new Kit_Media_Fake_Wpdb();
        self::$host ??= new StandaloneHost('kitprobe');
        Runtime\host(self::$host);
        Rename\register_undo(Runtime\host()->ledger());
        PostPartial\register(Runtime\host()->ledger());
        RuntimeAbilities::$abilities = [];
        RuntimeAbilities::add(Rename\ABILITY, ['annotations' => ['readonly' => false, 'destructive' => true]]);

        // A big upload WordPress scaled: photo-scaled.jpg is attached, photo.jpg is its original.
        Kit_Media_Test_State::add_image(10, '2026/09/photo-scaled.jpg', 2560, 1707, 'image/jpeg', [
            'original_image' => 'photo.jpg',
            'sizes' => [
                'medium' => ['file' => 'photo-300x200.jpg', 'width' => 300, 'height' => 200, 'mime-type' => 'image/jpeg'],
                'thumbnail' => ['file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg'],
            ],
        ]);
        file_put_contents($this->path('photo.jpg'), 'original');
        file_put_contents($this->path('photo-300x200.jpg'), 'medium');
        // The thumbnail file is missing on disk: its metadata is still renamed, nothing is moved.
        wp_update_post(['ID' => 10, 'post_name' => 'photo']);
        Kit_Test_Site::insert(['ID' => 20, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Ride', 'post_content' => self::CONTENT, 'post_excerpt' => self::EXCERPT]);
        Kit_Test_Site::insert(['ID' => 21, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Other', 'post_content' => '<img src="' . self::URL . 'photo-1.jpg">']);
        Kit_Test_Site::insert(['ID' => 22, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Built']);
        Kit_Test_Site::set_raw_meta(22, '_elementor_data', [self::BUILDER]);
    }

    protected function tearDown(): void
    {
        Kit_Media_Test_State::cleanup();
        $GLOBALS['wpdb'] = $this->wpdb;
        RuntimeAbilities::$abilities = [];
    }

    public function testTheAbilityIsADestructiveUploadFilesWrite(): void
    {
        $args = Kit_Test_Site::registration(Rename\ABILITY);
        self::assertIsArray($args);
        self::assertTrue($args['meta']['annotations']['destructive']);
        self::assertFalse($args['meta']['annotations']['idempotent']);
        self::assertSame(['attachment_id', 'new_name'], $args['input_schema']['required']);
        self::assertTrue($args['permission_callback']());
        Kit_Test_Site::as_user(1, 'manage_options', 'edit_post');
        self::assertFalse($args['permission_callback']());
    }

    public function testADryRunReportsEveryChangeAndMakesNone(): void
    {
        $report = Rename\rename_media(['attachment_id' => 10, 'new_name' => 'red-bicycle', 'dry_run' => true]);

        self::assertIsArray($report, self::code($report));
        self::assertTrue($report['dry_run']);
        self::assertSame(['from' => '2026/09/photo-scaled.jpg', 'to' => '2026/09/red-bicycle-scaled.jpg'], $report['file']);
        self::assertSame([
            ['from' => '2026/09/photo-scaled.jpg', 'to' => '2026/09/red-bicycle-scaled.jpg', 'on_disk' => true],
            ['from' => '2026/09/photo.jpg', 'to' => '2026/09/red-bicycle.jpg', 'on_disk' => true],
            ['from' => '2026/09/photo-300x200.jpg', 'to' => '2026/09/red-bicycle-300x200.jpg', 'on_disk' => true],
            ['from' => '2026/09/photo-150x150.jpg', 'to' => '2026/09/red-bicycle-150x150.jpg', 'on_disk' => false],
        ], $report['files']);
        self::assertSame(['from' => 'photo', 'to' => 'red-bicycle'], $report['slug']);
        self::assertSame([['post_id' => 20, 'title' => 'Ride', 'post_type' => 'post', 'replacements' => 4]], $report['posts']);
        self::assertSame([['post_id' => 22, 'meta_key' => '_elementor_data']], $report['meta_references']);
        self::assertStringContainsString('meta_references', $report['notes'][0]);

        self::assertFileExists($this->path('photo-scaled.jpg'));
        self::assertFileDoesNotExist($this->path('red-bicycle-scaled.jpg'));
        self::assertSame(self::CONTENT, get_post(20)->post_content);
        self::assertSame('photo', get_post(10)->post_name);
        self::assertSame([], Runtime\host()->ledger()->all());
    }

    public function testARealRenameNeedsConfirm(): void
    {
        $refused = Rename\rename_media(['attachment_id' => 10, 'new_name' => 'red-bicycle']);

        self::assertSame('kit_confirmation_required', self::code($refused));
        self::assertFileExists($this->path('photo-scaled.jpg'));
    }

    public function testRenamingMovesEveryFileAndRewritesMetadataSlugAndLinks(): void
    {
        $report = Rename\rename_media(['attachment_id' => 10, 'new_name' => 'red-bicycle', 'confirm' => true]);

        self::assertIsArray($report, self::code($report));
        self::assertTrue($report['verified']);
        self::assertCount(2, $report['change_ids']);
        self::assertSame('updated', $report['posts'][0]['status']);
        foreach (['red-bicycle-scaled.jpg', 'red-bicycle.jpg', 'red-bicycle-300x200.jpg'] as $file) {
            self::assertFileExists($this->path($file));
        }
        foreach (['photo-scaled.jpg', 'photo.jpg', 'photo-300x200.jpg'] as $file) {
            self::assertFileDoesNotExist($this->path($file));
        }
        self::assertSame('original', file_get_contents($this->path('red-bicycle.jpg')));

        self::assertSame('2026/09/red-bicycle-scaled.jpg', get_post_meta(10, '_wp_attached_file', true));
        $meta = wp_get_attachment_metadata(10);
        self::assertSame('2026/09/red-bicycle-scaled.jpg', $meta['file']);
        self::assertSame('red-bicycle.jpg', $meta['original_image']);
        self::assertSame('red-bicycle-300x200.jpg', $meta['sizes']['medium']['file']);
        self::assertSame('red-bicycle-150x150.jpg', $meta['sizes']['thumbnail']['file']);
        self::assertSame('red-bicycle', get_post(10)->post_name);

        $content = get_post(20)->post_content;
        self::assertStringContainsString('href="' . self::URL . 'red-bicycle.jpg"', $content);
        self::assertStringContainsString('src="' . self::URL . 'red-bicycle-300x200.jpg"', $content);
        self::assertStringContainsString(self::URL . 'red-bicycle-scaled.jpg 2560w', $content);
        self::assertStringContainsString(self::URL . 'photo-scaled.jpg.webp', $content, 'a copy named after the file is not this file');
        self::assertStringContainsString(self::URL . 'photo-1.jpg', $content, 'another attachment is left alone');
        self::assertSame('See http://example.test/wp-content/uploads/2026/09/red-bicycle.jpg?ver=2', get_post(20)->post_excerpt);
        self::assertSame('<img src="' . self::URL . 'photo-1.jpg">', get_post(21)->post_content);
        self::assertSame([self::BUILDER], Kit_Test_Site::raw_meta(22)['_elementor_data'], 'builder meta is reported, never changed');
    }

    public function testUndoingTheGroupPutsFilesMetadataSlugAndLinksBack(): void
    {
        $before = wp_get_attachment_metadata(10);
        $report = Rename\rename_media(['attachment_id' => 10, 'new_name' => 'red-bicycle', 'confirm' => true]);
        self::assertIsArray($report, self::code($report));

        foreach (array_reverse($report['change_ids']) as $id) {
            $undone = Runtime\host()->ledger()->rollback($id);
            self::assertIsArray($undone, self::code($undone));
        }

        foreach (['photo-scaled.jpg', 'photo.jpg', 'photo-300x200.jpg'] as $file) {
            self::assertFileExists($this->path($file));
        }
        self::assertFileDoesNotExist($this->path('red-bicycle.jpg'));
        self::assertSame('2026/09/photo-scaled.jpg', get_post_meta(10, '_wp_attached_file', true));
        self::assertSame($before, wp_get_attachment_metadata(10));
        self::assertSame('photo', get_post(10)->post_name);
        self::assertSame(self::CONTENT, get_post(20)->post_content);
        self::assertSame(self::EXCERPT, get_post(20)->post_excerpt);
    }

    public function testUndoIsRefusedOnceTheAttachmentPointsElsewhere(): void
    {
        $report = Rename\rename_media(['attachment_id' => 10, 'new_name' => 'red-bicycle', 'confirm' => true, 'update_content' => false]);
        self::assertIsArray($report, self::code($report));
        update_attached_file(10, Kit_Media_Test_State::$basedir . '/2026/09/blue.jpg');

        $undone = Runtime\host()->ledger()->rollback($report['change_ids'][0]);

        self::assertSame('kit_media_rename_changed_since', self::code($undone));
        self::assertFileExists($this->path('red-bicycle.jpg'));
    }

    public function testContentAndSlugCanBeLeftAlone(): void
    {
        $report = Rename\rename_media(['attachment_id' => 10, 'new_name' => 'red-bicycle', 'confirm' => true, 'update_content' => false, 'update_slug' => false]);

        self::assertIsArray($report, self::code($report));
        self::assertSame([], $report['posts']);
        self::assertNull($report['slug']['to']);
        self::assertCount(1, $report['change_ids']);
        self::assertSame('photo', get_post(10)->post_name);
        self::assertSame(self::CONTENT, get_post(20)->post_content);
    }

    public function testATypedExtensionIsDroppedAndTheNameSanitised(): void
    {
        self::assertSame('red-bicycle', Rename\new_stem('red-bicycle.JPG', 'jpg'));
        self::assertSame('red-bicycle', Rename\new_stem('  red bicycle ', 'jpg'));
        foreach (['', '...', 'red.bicycle', '../red', str_repeat('a', Rename\MAX_NAME_LENGTH + 1)] as $bad) {
            self::assertSame('kit_media_rename_bad_name', self::code(Rename\new_stem($bad, 'jpg')), $bad);
        }
    }

    public function testRefusalsLeaveEverythingInPlace(): void
    {
        $cases = [
            'kit_media_rename_not_found' => ['attachment_id' => 20, 'new_name' => 'x'],
            'kit_media_rename_same_name' => ['attachment_id' => 10, 'new_name' => 'photo'],
            'kit_media_rename_bad_name' => ['attachment_id' => 10, 'new_name' => 'a/b.c'],
        ];
        foreach ($cases as $code => $input) {
            self::assertSame($code, self::code(Rename\rename_media($input + ['confirm' => true])), $code);
        }

        file_put_contents($this->path('red-bicycle-300x200.jpg'), 'someone else');
        self::assertSame('kit_media_rename_exists', self::code(Rename\rename_media(['attachment_id' => 10, 'new_name' => 'red-bicycle', 'confirm' => true])));
        self::assertSame('someone else', file_get_contents($this->path('red-bicycle-300x200.jpg')));
        self::assertFileExists($this->path('photo-scaled.jpg'));

        Kit_Test_Site::set_meta(10, Rename\OPTIMIZE_META_KEY, ['file' => '2026/09/photo-scaled.jpg']);
        self::assertSame('kit_media_rename_optimized', self::code(Rename\rename_media(['attachment_id' => 10, 'new_name' => 'blue', 'confirm' => true])));

        self::assertSame([], Runtime\host()->ledger()->all());
        self::assertSame(self::CONTENT, get_post(20)->post_content);
    }

    public function testFilesThatDoNotShareTheStemStopTheRename(): void
    {
        $meta = wp_get_attachment_metadata(10);
        $meta['sizes']['medium']['file'] = 'other-300x200.jpg';
        wp_update_attachment_metadata(10, $meta);

        self::assertSame('kit_media_rename_unexpected_names', self::code(Rename\rename_media(['attachment_id' => 10, 'new_name' => 'blue', 'confirm' => true])));
        self::assertFileExists($this->path('photo-scaled.jpg'));
    }

    public function testReplacementStopsAtTheEndOfTheFileName(): void
    {
        $pairs = ['h/photo.jpg' => 'h/new.jpg'];

        self::assertSame(['h/new.jpg h/new.jpg?x h/new.jpg"', 3], Rename\replace_in('h/photo.jpg h/photo.jpg?x h/photo.jpg"', $pairs));
        self::assertSame(['h/photo.jpg.webp h/photo.jpgx h/photo.jpg-1', 0], Rename\replace_in('h/photo.jpg.webp h/photo.jpgx h/photo.jpg-1', $pairs));
    }

    private function path(string $file): string
    {
        clearstatcache();
        return Kit_Media_Test_State::$basedir . '/2026/09/' . $file;
    }

    private static function code(mixed $result): string
    {
        return $result instanceof WP_Error ? $result->get_error_code() : 'no error';
    }
}
