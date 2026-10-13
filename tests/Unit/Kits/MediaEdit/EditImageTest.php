<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\MediaEdit;

use Kit_Fake_Image_Editor;
use Kit_Media_Test_State;
use Kit_Test_Site;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\MediaEdit;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Hosts\StandaloneHost;
use WPPilot\Kits\Runtime\MiniLedger;

/**
 * wppilot/edit-image: input validation and caps, the operations handed to the editor, both save
 * modes, and both undo strategies run end to end through the runtime's MiniLedger.
 */
final class EditImageTest extends TestCase
{
    private ?Kit_Fake_Image_Editor $editor = null;

    private MiniLedger $ledger;

    private static ?StandaloneHost $host = null;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/media-doubles.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/hosts/standalone.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/media-edit/src/editing.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/media-edit/src/abilities/edit-image.php';
    }

    protected function setUp(): void
    {
        Kit_Test_Site::reset();
        delete_option(MiniLedger::OPTION);
        Kit_Test_Site::as_user(1, 'edit_post', 'upload_files', 'manage_options');
        Kit_Media_Test_State::reset();
        Kit_Fake_Image_Editor::$saved_paths = [];
        Kit_Media_Test_State::$editor_factory = function (string $path): Kit_Fake_Image_Editor {
            $meta = wp_get_attachment_metadata(1);
            $this->editor = new Kit_Fake_Image_Editor($path, (int) ($meta['width'] ?? 1200), (int) ($meta['height'] ?? 800));
            return $this->editor;
        };
        // One host for the class: each StandaloneHost hooks the permission filter again.
        self::$host ??= new StandaloneHost('kitprobe');
        Runtime\host(self::$host);
        $this->ledger = new MiniLedger();
        MediaEdit\register_undo($this->ledger);
    }

    protected function tearDown(): void
    {
        Kit_Media_Test_State::cleanup();
        unset($GLOBALS['wp_filter']['image_resize_dimensions']);
    }

    public function testTheAbilityIsRegisteredAsAnUndoableNonDestructiveWrite(): void
    {
        self::assertTrue(wp_has_ability('wppilot/edit-image'));
        $args = self::registration('wppilot/edit-image');
        self::assertFalse($args['meta']['annotations']['readonly']);
        self::assertFalse($args['meta']['annotations']['destructive']);
        self::assertSame(['attachment_id', 'operations'], $args['input_schema']['required']);
        self::assertSame(MediaEdit\MAX_DIMENSION, $args['input_schema']['properties']['operations']['items']['properties']['width']['maximum']);
    }

    public function testPermissionNeedsUploadFiles(): void
    {
        $permission = self::registration('wppilot/edit-image')['permission_callback'];
        self::assertTrue($permission());
        Kit_Test_Site::as_user(1, 'manage_options', 'edit_post');
        self::assertFalse($permission());
    }

    // --- plan(): validation and caps -----------------------------------------------------------

    public function testResizeByWidthKeepsTheAspectRatio(): void
    {
        $plan = MediaEdit\plan([['op' => 'resize', 'width' => 600]], 1200, 800, false);
        self::assertIsArray($plan);
        self::assertSame(['op' => 'resize', 'width' => 600, 'height' => 400, 'upscale' => false], $plan['steps'][0]);
    }

    public function testResizeWithBothSidesFitsWithinTheBox(): void
    {
        $plan = MediaEdit\plan([['op' => 'resize', 'width' => 500, 'height' => 500]], 1200, 800, false);
        self::assertIsArray($plan);
        self::assertSame([500, 333], [$plan['width'], $plan['height']]);
    }

    public function testEnlargingIsRefusedUnlessAllowed(): void
    {
        $refused = MediaEdit\plan([['op' => 'resize', 'width' => 2400]], 1200, 800, false);
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('kit_media_edit_upscale', $refused->get_error_code());

        $allowed = MediaEdit\plan([['op' => 'resize', 'width' => 2400]], 1200, 800, true);
        self::assertIsArray($allowed);
        self::assertTrue($allowed['steps'][0]['upscale']);
    }

    public function testNoSideMayExceedTheCapEvenWhenEnlargingIsAllowed(): void
    {
        $asked = MediaEdit\plan([['op' => 'resize', 'width' => 9000]], 1200, 800, true);
        self::assertSame('kit_media_edit_too_large', $asked instanceof WP_Error ? $asked->get_error_code() : '');

        // Asking for 7000 wide on a tall image makes it 14000 high.
        $derived = MediaEdit\plan([['op' => 'resize', 'width' => 7000]], 1000, 2000, true);
        self::assertSame('kit_media_edit_too_large', $derived instanceof WP_Error ? $derived->get_error_code() : '');
    }

    public function testAResizeThatChangesNothingIsDropped(): void
    {
        $plan = MediaEdit\plan([['op' => 'resize', 'width' => 1200], ['op' => 'flip', 'direction' => 'vertical']], 1200, 800, false);
        self::assertIsArray($plan);
        self::assertSame([['op' => 'flip', 'direction' => 'vertical']], $plan['steps']);
    }

    public function testCropIsCheckedAgainstTheSizeAtItsStep(): void
    {
        // After a quarter turn the 1200x800 image is 800x1200, so a 1000-high crop fits.
        $plan = MediaEdit\plan([['op' => 'rotate', 'degrees' => 90], ['op' => 'crop', 'x' => 0, 'y' => 100, 'width' => 800, 'height' => 1000]], 1200, 800, false);
        self::assertIsArray($plan);
        self::assertSame([800, 1000], [$plan['width'], $plan['height']]);

        $outside = MediaEdit\plan([['op' => 'crop', 'x' => 0, 'y' => 100, 'width' => 800, 'height' => 1000]], 1200, 800, false);
        self::assertSame('kit_media_edit_crop', $outside instanceof WP_Error ? $outside->get_error_code() : '');
    }

    /**
     * @return array<string, array{0: list<mixed>, 1: string}>
     */
    public static function invalidOperations(): array
    {
        return [
            'none' => [[], 'kit_media_edit_operations'],
            // Providers run before setUpBeforeClass() loads the kit, so MAX_OPERATIONS (10) is spelt out.
            'too many' => [array_fill(0, 11, ['op' => 'flip', 'direction' => 'vertical']), 'kit_media_edit_operations'],
            'unknown op' => [[['op' => 'sharpen']], 'kit_media_edit_operation'],
            'not an object' => [['resize'], 'kit_media_edit_operation'],
            'resize without size' => [[['op' => 'resize']], 'kit_media_edit_resize'],
            'rotate by 45' => [[['op' => 'rotate', 'degrees' => 45]], 'kit_media_edit_rotate'],
            'flip diagonal' => [[['op' => 'flip', 'direction' => 'diagonal']], 'kit_media_edit_flip'],
            'negative crop' => [[['op' => 'crop', 'x' => -1, 'y' => 0, 'width' => 10, 'height' => 10]], 'kit_media_edit_crop'],
            'empty crop' => [[['op' => 'crop', 'x' => 0, 'y' => 0, 'width' => 0, 'height' => 10]], 'kit_media_edit_crop'],
        ];
    }

    /**
     * @param list<mixed> $operations
     */
    #[DataProvider('invalidOperations')]
    public function testInvalidOperationsAreRefused(array $operations, string $code): void
    {
        $plan = MediaEdit\plan($operations, 1200, 800, false);
        self::assertInstanceOf(WP_Error::class, $plan);
        self::assertSame($code, $plan->get_error_code());
    }

    // --- apply(): what the editor is asked to do ------------------------------------------------

    public function testRotateIsClockwiseAndFlipIsNamedForTheMotion(): void
    {
        $editor = new Kit_Fake_Image_Editor('/x.jpg', 1200, 800);
        $done = MediaEdit\apply($editor, [
            ['op' => 'rotate', 'degrees' => 90],
            ['op' => 'flip', 'direction' => 'horizontal'],
            ['op' => 'flip', 'direction' => 'vertical'],
        ]);

        self::assertTrue($done);
        // The editors turn counter-clockwise for a positive angle; flip($horz, $vert) swaps
        // top/bottom for $horz, so a left-right mirror is flip(false, true).
        self::assertSame([['rotate', -90], ['flip', false, true], ['flip', true, false]], $editor->calls);
    }

    public function testAnAllowedEnlargementUsesACallScopedFilterThatIsRemoved(): void
    {
        $editor = new Kit_Fake_Image_Editor('/x.jpg', 1200, 800);
        $done = MediaEdit\apply($editor, [['op' => 'resize', 'width' => 2400, 'height' => 1600, 'upscale' => true]]);

        self::assertTrue($done);
        self::assertSame(['width' => 2400, 'height' => 1600], $editor->get_size());
        self::assertArrayNotHasKey('image_resize_dimensions', $GLOBALS['wp_filter'] ?? []);
    }

    public function testAnEditorErrorStopsTheRun(): void
    {
        $editor = new Kit_Fake_Image_Editor('/x.jpg', 100, 100);
        // Without the upscale flag the editor itself refuses to enlarge, as core's does.
        $done = MediaEdit\apply($editor, [['op' => 'resize', 'width' => 200, 'height' => 200], ['op' => 'rotate', 'degrees' => 90]]);

        self::assertInstanceOf(WP_Error::class, $done);
        self::assertCount(1, $editor->calls);
    }

    // --- edit(): refusals before anything is written ---------------------------------------------

    public function testRefusesAnUnknownAttachment(): void
    {
        self::assertSame('kit_media_edit_not_found', self::code(MediaEdit\edit(['attachment_id' => 99, 'operations' => [['op' => 'rotate', 'degrees' => 90]]])));
    }

    public function testRefusesWithoutEditPostOnTheAttachment(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/photo.jpg', 1200, 800);
        Kit_Test_Site::as_user(1, 'upload_files');

        self::assertSame('kit_media_edit_forbidden', self::code(MediaEdit\edit(['attachment_id' => 1, 'operations' => [['op' => 'rotate', 'degrees' => 90]]])));
    }

    public function testRefusesSomethingThatIsNotARasterImage(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/logo.svg', 100, 100, 'image/svg+xml');

        self::assertSame('kit_media_edit_not_an_image', self::code(MediaEdit\edit(['attachment_id' => 1, 'operations' => [['op' => 'rotate', 'degrees' => 90]]])));
    }

    public function testRefusesWhenTheFileIsNotOnThisServer(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/photo.jpg', 1200, 800);
        unlink(Kit_Media_Test_State::$basedir . '/2026/09/photo.jpg');

        self::assertSame('kit_media_edit_no_file', self::code(MediaEdit\edit(['attachment_id' => 1, 'operations' => [['op' => 'rotate', 'degrees' => 90]]])));
    }

    public function testRefusesAnOversizedSourceWithoutOpeningIt(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/huge.jpg', 10000, 10000);

        self::assertSame('kit_media_edit_source_too_large', self::code(MediaEdit\edit(['attachment_id' => 1, 'operations' => [['op' => 'rotate', 'degrees' => 90]]])));
        self::assertNull($this->editor);
    }

    public function testABadPlanIsRefusedBeforeTheImageIsOpened(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/photo.jpg', 1200, 800);

        self::assertSame('kit_media_edit_upscale', self::code(MediaEdit\edit(['attachment_id' => 1, 'operations' => [['op' => 'resize', 'width' => 5000]]])));
        self::assertNull($this->editor);
    }

    public function testRefusesAnUnknownMode(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/photo.jpg', 1200, 800);

        self::assertSame('kit_media_edit_mode', self::code(MediaEdit\edit(['attachment_id' => 1, 'mode' => 'overwrite', 'operations' => [['op' => 'rotate', 'degrees' => 90]]])));
    }

    public function testOperationsThatChangeNothingSaveNothing(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/photo.jpg', 1200, 800);

        self::assertSame('kit_media_edit_nothing', self::code(MediaEdit\edit(['attachment_id' => 1, 'operations' => [['op' => 'resize', 'width' => 1200]]])));
        self::assertSame([], Kit_Fake_Image_Editor::$saved_paths);
    }

    // --- copy mode ----------------------------------------------------------------------------------

    public function testCopyMakesANewAttachmentAndLeavesTheOriginalAlone(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/photo.jpg', 1200, 800);
        Kit_Test_Site::set_meta(1, '_wp_attachment_image_alt', 'A red door');
        $original_meta = Kit_Test_Site::raw_meta(1);

        $result = MediaEdit\edit(['attachment_id' => 1, 'operations' => [['op' => 'resize', 'width' => 600]]]);

        self::assertIsArray($result);
        self::assertSame('copy', $result['mode']);
        $copy = $result['attachment_id'];
        self::assertNotSame(1, $copy);
        self::assertSame('2026/09/photo-edited.jpg', $result['file']);
        self::assertSame([600, 400], [$result['width'], $result['height']]);
        self::assertTrue($result['alt_copied']);
        self::assertSame(['A red door'], get_post_meta($copy, '_wp_attachment_image_alt'));
        self::assertSame('Photo 1 (edited)', get_post($copy)?->post_title);
        self::assertFileExists(Kit_Media_Test_State::$basedir . '/2026/09/photo-edited.jpg');
        self::assertFileExists(Kit_Media_Test_State::$basedir . '/2026/09/photo.jpg');
        self::assertSame($original_meta, Kit_Test_Site::raw_meta(1));
    }

    public function testCopyNeverOverwritesAnEarlierCopy(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/photo.jpg', 1200, 800);
        file_put_contents(Kit_Media_Test_State::$basedir . '/2026/09/photo-edited.jpg', 'earlier');

        $result = MediaEdit\edit(['attachment_id' => 1, 'operations' => [['op' => 'rotate', 'degrees' => 180]]]);

        self::assertIsArray($result);
        self::assertSame('2026/09/photo-edited-1.jpg', $result['file']);
        self::assertSame('earlier', file_get_contents(Kit_Media_Test_State::$basedir . '/2026/09/photo-edited.jpg'));
    }

    public function testCopyIsUndoneByRemovingTheNewAttachmentAndItsFile(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/photo.jpg', 1200, 800);
        $id = $this->run_through_ledger(['attachment_id' => 1, 'operations' => [['op' => 'flip', 'direction' => 'horizontal']]]);

        $row = $this->ledger->all()[0];
        self::assertTrue($row['rollback']['reversible']);
        self::assertSame(MediaEdit\TYPE_CREATED, $row['rollback']['type']);
        $copy = (int) $row['rollback']['attachment_id'];
        self::assertSame('2026/09/photo-edited.jpg', get_post_meta($copy, '_wp_attached_file', true));
        self::assertSame('2026/09/photo-edited.jpg', $row['rollback']['file']);
        self::assertSame(1, $row['rollback']['source_attachment_id']);

        $undone = $this->ledger->rollback($id);
        self::assertIsArray($undone);
        self::assertTrue($undone['verified']);
        self::assertNull(get_post($copy));
        self::assertFileDoesNotExist(Kit_Media_Test_State::$basedir . '/2026/09/photo-edited.jpg');
        self::assertFileExists(Kit_Media_Test_State::$basedir . '/2026/09/photo.jpg');
    }

    public function testCopyUndoRefusesWhenTheCopyNowPointsAtAnotherFile(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/photo.jpg', 1200, 800);
        $id = $this->run_through_ledger(['attachment_id' => 1, 'operations' => [['op' => 'rotate', 'degrees' => 90]]]);
        $copy = Kit_Test_Site::post_ids()[1];
        Kit_Test_Site::set_meta($copy, '_wp_attached_file', '2026/09/photo-edited-e1.jpg');

        $undone = $this->ledger->rollback($id);

        self::assertSame('kit_media_edit_undo_changed', self::code($undone));
        self::assertNotNull(get_post($copy));
    }

    public function testCopyUndoOfAnAlreadyDeletedCopyIsVerified(): void
    {
        $result = MediaEdit\restore_created(['attachment_id' => 4242, 'file' => '2026/09/gone.jpg']);

        self::assertIsArray($result);
        self::assertTrue($result['already_deleted']);
        self::assertTrue($result['verified']);
    }

    public function testCopyWithoutAReportedAttachmentIsRecordedAsNotReversible(): void
    {
        $payload = MediaEdit\build_created(['type' => MediaEdit\TYPE_CREATED, 'source_attachment_id' => 1], ['mode' => 'copy']);

        self::assertFalse($payload['reversible']);
    }

    // --- replace mode -------------------------------------------------------------------------------

    public function testReplaceWritesAnEditFileAndRecordsBackupsTheWayCoreDoes(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/photo.jpg', 1200, 800, 'image/jpeg', [
            'sizes' => ['thumbnail' => ['file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg']],
        ]);

        $result = MediaEdit\edit(['attachment_id' => 1, 'mode' => 'replace', 'operations' => [['op' => 'rotate', 'degrees' => 90]]]);

        self::assertIsArray($result);
        self::assertSame('replace', $result['mode']);
        self::assertMatchesRegularExpression('#^2026/09/photo-e\d+100\.jpg$#', $result['file']);
        self::assertSame('2026/09/photo.jpg', $result['previous_file']);
        self::assertSame([$result['file']], get_post_meta(1, '_wp_attached_file'));
        $meta = get_post_meta(1, '_wp_attachment_metadata', true);
        self::assertSame([800, 1200], [$meta['width'], $meta['height']]);
        self::assertSame($result['file'], $meta['file']);
        self::assertStringStartsWith('photo-e', $meta['sizes']['thumbnail']['file']);
        $backup = get_post_meta(1, '_wp_attachment_backup_sizes', true);
        self::assertSame(['width' => 1200, 'height' => 800, 'filesize' => 64, 'file' => 'photo.jpg'], $backup['full-orig']);
        self::assertSame('photo-150x150.jpg', $backup['thumbnail-orig']['file']);
        // Never deletes: the old file is still there for the undo to point back at.
        self::assertFileExists(Kit_Media_Test_State::$basedir . '/2026/09/photo.jpg');
        self::assertFileExists(Kit_Media_Test_State::$basedir . '/' . $result['file']);
    }

    public function testASecondReplaceKeepsTheFirstOriginalAndTagsTheNextBackupWithItsSuffix(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/photo.jpg', 1200, 800);
        $first = MediaEdit\edit(['attachment_id' => 1, 'mode' => 'replace', 'operations' => [['op' => 'rotate', 'degrees' => 90]]]);
        self::assertIsArray($first);
        get_post_meta(1, '_wp_attachment_metadata', true)['width'] = 800;
        get_post_meta(1, '_wp_attachment_metadata', true)['height'] = 1200;

        $second = MediaEdit\edit(['attachment_id' => 1, 'mode' => 'replace', 'operations' => [['op' => 'flip', 'direction' => 'vertical']]]);

        self::assertIsArray($second);
        $backup = get_post_meta(1, '_wp_attachment_backup_sizes', true);
        self::assertSame('photo.jpg', $backup['full-orig']['file']);
        $later = array_values(array_filter(array_keys($backup), static fn(string $key): bool => preg_match('/^full-\d+$/', $key) === 1));
        self::assertCount(1, $later);
        self::assertSame(basename($first['file']), $backup[$later[0]]['file']);
        // Core strips the previous -e suffix rather than stacking them.
        self::assertSame(1, substr_count($second['file'], '-e'));
    }

    public function testReplaceIsRefusedWhenTheSiteConvertsTheFormatAndTheWrittenFileIsRemoved(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/photo.jpg', 1200, 800);
        Kit_Media_Test_State::$editor_factory = static function (string $path): Kit_Fake_Image_Editor {
            $editor = new Kit_Fake_Image_Editor($path, 1200, 800);
            $editor->force_output_mime = 'image/webp';
            return $editor;
        };

        $result = MediaEdit\edit(['attachment_id' => 1, 'mode' => 'replace', 'operations' => [['op' => 'rotate', 'degrees' => 90]]]);

        self::assertSame('kit_media_edit_converted', self::code($result));
        self::assertSame(['2026/09/photo.jpg'], get_post_meta(1, '_wp_attached_file'));
        foreach (Kit_Fake_Image_Editor::$saved_paths as $path) {
            self::assertFileDoesNotExist($path);
        }
    }

    public function testReplaceIsUndoneByRestoringTheThreeMetaKeys(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/photo.jpg', 1200, 800);
        $before = Kit_Test_Site::raw_meta(1);
        $id = $this->run_through_ledger(['attachment_id' => 1, 'mode' => 'replace', 'operations' => [['op' => 'crop', 'x' => 0, 'y' => 0, 'width' => 600, 'height' => 400]]]);

        $row = $this->ledger->all()[0];
        self::assertSame(MediaEdit\TYPE_REPLACED, $row['rollback']['type']);
        self::assertSame(MediaEdit\REPLACE_META_KEYS, array_keys($row['rollback']['snapshot']['meta']));
        // Absent before the edit, so the undo deletes it rather than leaving an empty backup list.
        self::assertNull($row['rollback']['snapshot']['meta']['_wp_attachment_backup_sizes']);

        $undone = $this->ledger->rollback($id);

        self::assertIsArray($undone);
        self::assertTrue($undone['verified']);
        self::assertSame($before, Kit_Test_Site::raw_meta(1));
    }

    public function testReplaceUndoRefusesWhenTheOldFileIsGone(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/photo.jpg', 1200, 800);
        $id = $this->run_through_ledger(['attachment_id' => 1, 'mode' => 'replace', 'operations' => [['op' => 'rotate', 'degrees' => 270]]]);
        $after = Kit_Test_Site::raw_meta(1);
        unlink(Kit_Media_Test_State::$basedir . '/2026/09/photo.jpg');

        $undone = $this->ledger->rollback($id);

        self::assertSame('kit_media_edit_undo_file_missing', self::code($undone));
        // Nothing was restored: the attachment still points at the edited file, which exists.
        self::assertSame($after, Kit_Test_Site::raw_meta(1));
    }

    public function testCaptureDependsOnTheMode(): void
    {
        Kit_Media_Test_State::add_image(1, '2026/09/photo.jpg', 1200, 800);

        self::assertSame(['type' => MediaEdit\TYPE_CREATED, 'source_attachment_id' => 1], MediaEdit\capture(['attachment_id' => 1]));
        $replace = MediaEdit\capture(['attachment_id' => 1, 'mode' => 'replace']);
        self::assertIsArray($replace);
        self::assertSame(MediaEdit\TYPE_REPLACED, $replace['type']);
        self::assertSame([], $replace['fields']);
        self::assertNull(MediaEdit\capture(['attachment_id' => 77, 'mode' => 'replace']));
    }

    /**
     * Run edit-image the way the host does: before-image, execute, after, then return the row id.
     *
     * @param array<string, mixed> $input
     */
    private function run_through_ledger(array $input): string
    {
        $this->ledger->before(MediaEdit\ABILITY, $input);
        $result = MediaEdit\edit($input);
        self::assertIsArray($result, $result instanceof WP_Error ? $result->get_error_message() : '');
        $this->ledger->after(MediaEdit\ABILITY, $input, $result);
        $rows = $this->ledger->all();
        self::assertCount(1, $rows);
        return (string) $rows[0]['id'];
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
