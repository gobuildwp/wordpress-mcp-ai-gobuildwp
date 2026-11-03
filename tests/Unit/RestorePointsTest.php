<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot_Test_Sqlite_Wpdb;
use WPPilot_Test_State;

require_once dirname(__DIR__) . '/doubles/admin.php';
require_once dirname(__DIR__) . '/doubles/mcp-surface.php';
require_once dirname(__DIR__) . '/doubles/sqlite-wpdb.php';
require_once dirname(__DIR__) . '/doubles/cloud.php';
require_once dirname(__DIR__, 2) . '/includes/capabilities.php';
require_once dirname(__DIR__, 2) . '/includes/clients.php';
require_once dirname(__DIR__, 2) . '/includes/rate-limit.php';
require_once dirname(__DIR__, 2) . '/includes/cloud/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/abilities/change-log.php';

/**
 * Restore points over real files in a temporary plugins/themes/site tree, through the real ledger
 * (on SQLite): the gate takes the zip, the ledger row links to it, undo swaps it back in, and
 * redo puts the AI's version back from the second restore point.
 */
final class RestorePointsTest extends TestCase
{
    private string $root = '';

    /** @var array<string, mixed> */
    private array $savedOptions = [];

    private mixed $savedWpdb = null;

    private mixed $savedFilters = null;

    protected function setUp(): void
    {
        if (!class_exists('ZipArchive')) {
            self::markTestSkipped('ZipArchive is not installed.');
        }
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        $this->savedFilters = $GLOBALS['wp_filter'] ?? null;
        foreach ([
            WPPILOT_CHANGE_LOG_OPTION,
            WPPILOT_CHANGES_SCHEMA_OPTION,
            WPPILOT_CHANGES_STORAGE_OPTION,
            WPPILOT_CHANGES_MIGRATION_OPTION,
            WPPILOT_CHANGES_ERROR_OPTION,
            WPPILOT_RESTORE_POINT_INDEX_OPTION,
            WPPILOT_RESTORE_POINT_HOURS_OPTION,
            WPPILOT_CLOUD_LINK_OPTION,
        ] as $option) {
            unset(WPPilot_Test_State::$options[$option]);
        }
        $GLOBALS['wpdb'] = new WPPilot_Test_Sqlite_Wpdb();
        WPPilot_Test_State::$plugins = [];

        $this->root = wp_normalize_path(sys_get_temp_dir()) . '/gobuildwp-rp-' . bin2hex(random_bytes(4));
        foreach (['plugins', 'themes', 'site', 'storage'] as $dir) {
            mkdir($this->root . '/' . $dir, 0755, true);
        }
        $roots = ['plugins' => $this->root . '/plugins', 'themes' => $this->root . '/themes', 'site' => $this->root . '/site'];
        add_filter('wppilot_restore_point_roots', static fn(): array => $roots);
        $storage = $this->root . '/storage/points';
        add_filter('wppilot_restore_point_storage_dir', static fn(): string => $storage);
        wppilot_restore_points_register_hooks();

        self::assertSame('table', wppilot_changes_install_and_migrate()['storage']);
        wppilot_session_basis(['kind' => 'stdio', 'key' => 'restore-points-session']);
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        WPPilot_Test_State::$plugins = [];
        $GLOBALS['wpdb'] = $this->savedWpdb;
        if ($this->savedFilters === null) {
            unset($GLOBALS['wp_filter']);
        } else {
            $GLOBALS['wp_filter'] = $this->savedFilters;
        }
        wppilot_session_basis(['kind' => '', 'key' => '']);
        if ($this->root !== '') {
            wppilot_restore_point_delete_tree($this->root);
        }
    }

    public function testPluginUpdateUndoesFromTheZipAndRedoPutsTheAiVersionBack(): void
    {
        $this->put('plugins/shop/shop.php', "<?php // 1.0\n");
        $this->put('plugins/shop/assets/app.js', 'old();');
        mkdir($this->root . '/plugins/shop/empty');
        $original = $this->tree('plugins/shop');

        $id = $this->run_ability('wppilot/update-plugin', ['file' => 'shop/shop.php'], function (): void {
            $this->put('plugins/shop/shop.php', "<?php // 2.0\n");
            unlink($this->root . '/plugins/shop/assets/app.js');
            $this->put('plugins/shop/assets/new.js', 'new();');
        });
        $updated = $this->tree('plugins/shop');

        $entry = wppilot_get_change($id);
        self::assertSame('files/snapshot', $entry['rollback']['type']);
        self::assertTrue($entry['rollback']['reversible']);
        $summary = wppilot_change_public_summary($entry);
        self::assertTrue($summary['reversible']);
        self::assertSame(1, $summary['undo_window_hours'], 'no Cloud link: one hour');
        self::assertNotSame('', $summary['undo_until']);

        $undo = wppilot_rollback_change($id);
        self::assertIsArray($undo, $undo instanceof WP_Error ? $undo->get_error_message() : '');
        self::assertSame($original, $this->tree('plugins/shop'));
        self::assertTrue(is_dir($this->root . '/plugins/shop/empty'), 'empty folders come back too');
        self::assertSame([], $this->leftovers('plugins'), 'no staging or aside copies are left behind');

        $redo = wppilot_redo_session('restore-points-session');
        self::assertSame('completed', $redo['status'], $redo['message']);
        self::assertSame($updated, $this->tree('plugins/shop'));

        // The record: what was saved, and both attempts with their results. No server path.
        $output = wppilot_change_for_output(wppilot_get_change($id));
        $point = $output['restore_point'];
        self::assertSame('available', $point['status']);
        self::assertSame($id, $point['change_id']);
        self::assertSame(2, $point['files']);
        self::assertSame('undo', $point['attempts'][0]['op']);
        self::assertSame('restored', $point['attempts'][0]['result']);
        self::assertSame(hash('sha256', "<?php // 1.0\n"), $this->fileHash($point['file_list']['listed'], 'plugins:shop/shop.php'));
        $redo_points = array_filter(wppilot_restore_point_index(), static fn(array $r): bool => $r['role'] === 'redo');
        self::assertCount(1, $redo_points);
        $redo_point = array_values($redo_points)[0];
        self::assertSame($point['expires_at'], $redo_point['expires_at'], 'redo expires with the undo');
        self::assertSame('redo', $redo_point['attempts'][0]['op']);
        self::assertStringNotContainsString($this->root, (string) wp_json_encode($output));
    }

    public function testAnExpiredRestorePointIsRefusedWithTheManualPath(): void
    {
        $this->put('themes/brand/style.css', '/* v1 */');
        $id = $this->run_ability('wppilot/update-theme', ['stylesheet' => 'brand'], function (): void {
            $this->put('themes/brand/style.css', '/* v2 */');
        });
        $index = wppilot_restore_point_index();
        foreach ($index as $point_id => $record) {
            $index[$point_id]['expires_ts'] = time() - 1;
        }
        wppilot_restore_point_save_index($index);

        $summary = wppilot_change_public_summary(wppilot_get_change($id));
        self::assertFalse($summary['reversible']);
        self::assertStringContainsString('expired', $summary['rollback_reason']);

        $undo = wppilot_rollback_change($id);
        self::assertInstanceOf(WP_Error::class, $undo);
        self::assertStringContainsString('expired', $undo->get_error_message());
        self::assertStringContainsString('Reinstall the earlier version', $undo->get_error_message());
        self::assertSame('/* v2 */', file_get_contents($this->root . '/themes/brand/style.css'), 'nothing was touched');
        $attempts = wppilot_change_for_output(wppilot_get_change($id))['restore_point']['attempts'];
        self::assertSame('failed', $attempts[0]['result']);
    }

    public function testAChangeOverTheSizeCapIsRefusedBeforeItRunsUnlessAllowed(): void
    {
        $this->put('site/big.txt', str_repeat('x', 64));
        add_filter('wppilot_restore_point_max_bytes', static fn(): int => 16);
        $ability = $this->ability('wppilot/write-file');

        $refused = wppilot_restore_point_pre_execute(['path' => 'big.txt', 'content' => 'y'], $ability);
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('wppilot_restore_point_failed', $refused->get_error_code());
        self::assertStringContainsString('Nothing was changed', $refused->get_error_message());
        self::assertSame([], wppilot_restore_point_index());

        add_filter('wppilot_restore_point_allow_without_snapshot', static fn(): bool => true);
        self::assertSame(['path' => 'big.txt', 'content' => 'y'], wppilot_restore_point_pre_execute(['path' => 'big.txt', 'content' => 'y'], $ability));
    }

    public function testTheSiteCapPrunesExpiredThenOldestRestorePoints(): void
    {
        $this->put('site/a.txt', str_repeat('a', 4000));
        $this->put('site/b.txt', str_repeat('b', 4000));
        $this->put('site/c.txt', str_repeat('c', 4000));
        $a = wppilot_restore_point_create('wppilot/edit-file', [['root' => 'site', 'rel' => 'a.txt']]);
        $b = wppilot_restore_point_create('wppilot/edit-file', [['root' => 'site', 'rel' => 'b.txt']]);
        self::assertIsArray($a);
        self::assertIsArray($b);
        $index = wppilot_restore_point_index();
        $index[$a['id']]['created_ts'] = time() - 100;
        $index[$b['id']]['expires_ts'] = time() - 1; // Newer, but expired: goes first.
        wppilot_restore_point_save_index($index);
        $one = (int) $index[$a['id']]['zip_bytes'];
        add_filter('wppilot_restore_point_total_bytes', static fn(): int => $one + 4000 + 10);

        $c = wppilot_restore_point_create('wppilot/edit-file', [['root' => 'site', 'rel' => 'c.txt']]);

        self::assertIsArray($c);
        self::assertSame([$a['id'], $c['id']], array_keys(wppilot_restore_point_index()));
        self::assertFalse(is_dir($this->root . '/storage/points/' . $b['id']));
    }

    public function testUndoingAnInstallRemovesTheFolder(): void
    {
        $id = $this->run_ability('wppilot/install-plugin', ['slug' => 'fresh'], function (): void {
            $this->put('plugins/fresh/fresh.php', "<?php\n");
            $this->put('plugins/fresh/readme.txt', 'Fresh');
            WPPilot_Test_State::$plugins['fresh/fresh.php'] = ['Name' => 'Fresh'];
        }, ['file' => 'fresh/fresh.php', 'slug' => 'fresh']);

        $entry = wppilot_get_change($id);
        self::assertSame('files/snapshot', $entry['rollback']['type']);
        self::assertSame('absent', $entry['rollback']['snapshot']['targets'][0]['kind']);

        $undo = wppilot_rollback_change($id);
        self::assertIsArray($undo, $undo instanceof WP_Error ? $undo->get_error_message() : '');
        self::assertFalse(file_exists($this->root . '/plugins/fresh'));
        self::assertSame([], $this->leftovers('plugins'));
    }

    public function testUndoingAWriteThatCreatedFoldersRemovesThemAndAnEditRestoresTheFile(): void
    {
        $created = $this->run_ability('wppilot/write-file', ['path' => 'wp-content/new/deep/file.txt'], function (): void {
            $this->put('site/wp-content/new/deep/file.txt', 'hello');
        }, null, ['site/wp-content']);
        $this->put('site/wp-config.txt', 'define one');
        $edited = $this->run_ability('wppilot/edit-file', ['path' => 'wp-config.txt'], function (): void {
            $this->put('site/wp-config.txt', 'define two');
        });

        self::assertIsArray(wppilot_rollback_change($edited));
        self::assertSame('define one', file_get_contents($this->root . '/site/wp-config.txt'));
        self::assertIsArray(wppilot_rollback_change($created));
        self::assertFalse(file_exists($this->root . '/site/wp-content/new'));
        self::assertTrue(is_dir($this->root . '/site/wp-content'), 'only what the write created is removed');
    }

    public function testTargetsThatEscapeTheirRootAreRefused(): void
    {
        self::assertInstanceOf(WP_Error::class, wppilot_restore_point_targets_for('wppilot/write-file', ['path' => '../outside.txt']));
        self::assertInstanceOf(WP_Error::class, wppilot_restore_point_targets_for('wppilot/update-plugin', ['file' => '../evil.php']));
        self::assertInstanceOf(WP_Error::class, wppilot_restore_point_targets_for('wppilot/update-theme', ['stylesheet' => 'a/b']));
        self::assertInstanceOf(WP_Error::class, wppilot_restore_point_targets_for('wppilot/delete-file', ['path' => '/']));
        self::assertNull(wppilot_restore_point_targets_for('wppilot/update-post', ['post_id' => 1]));
        self::assertSame(
            [['root' => 'site', 'rel' => 'x.php'], ['root' => 'site', 'rel' => 'x.php.disabled']],
            wppilot_restore_point_targets_for('wppilot/enable-file', ['path' => 'x.php.disabled']),
        );
    }

    public function testRetentionIsClampedAndNeedsACloudLink(): void
    {
        self::assertSame(168, wppilot_restore_point_set_hours(500));
        self::assertSame(1, wppilot_restore_point_set_hours(0));
        self::assertSame(24, wppilot_restore_point_set_hours(24));
        self::assertSame(1, wppilot_restore_point_clamp_hours('lots'));
        self::assertSame(1, wppilot_restore_point_hours(), 'no Cloud link: one hour whatever is stored');

        update_option(WPPILOT_CLOUD_LINK_OPTION, ['site_id' => 'site-1', 'token_id' => 7]);
        self::assertSame(24, wppilot_restore_point_hours());
        WPPilot_Test_State::$options[WPPILOT_RESTORE_POINT_HOURS_OPTION] = 9999;
        self::assertSame(168, wppilot_restore_point_hours(), 'a stored value out of range is clamped on read');

        $this->put('site/f.txt', 'one');
        $point = wppilot_restore_point_create('wppilot/edit-file', [['root' => 'site', 'rel' => 'f.txt']]);
        self::assertIsArray($point);
        wppilot_restore_point_set_hours(1);
        $record = wppilot_restore_point_get($point['id']);
        self::assertSame(168, $record['window_hours'], 'expiry is fixed when the restore point is taken');
    }

    public function testCleanupRemovesExpiredAndUnclaimedRestorePoints(): void
    {
        $this->put('site/f.txt', 'one');
        $kept = wppilot_restore_point_create('wppilot/edit-file', [['root' => 'site', 'rel' => 'f.txt']], ['change_id' => 'row-1']);
        $expired = wppilot_restore_point_create('wppilot/edit-file', [['root' => 'site', 'rel' => 'f.txt']], ['change_id' => 'row-2', 'expires_ts' => time() - 1]);
        $unclaimed = wppilot_restore_point_create('wppilot/edit-file', [['root' => 'site', 'rel' => 'f.txt']]);
        $index = wppilot_restore_point_index();
        $index[$unclaimed['id']]['created_ts'] = time() - 7200;
        wppilot_restore_point_save_index($index);

        self::assertSame(2, wppilot_restore_points_cleanup()['removed']);
        self::assertSame([$kept['id']], array_keys(wppilot_restore_point_index()));
        self::assertFalse(is_dir($this->root . '/storage/points/' . $expired['id']));
    }

    public function testWpConfigIsNeverCopiedIntoARestorePoint(): void
    {
        $this->put('site/wp-config.php', "<?php define('DB_PASSWORD', 'hunter2');\n");

        $refused = wppilot_restore_point_pre_execute(['path' => 'wp-config.php', 'content' => 'x'], $this->ability('wppilot/edit-file'));
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertStringContainsString('wp-config.php', $refused->get_error_message());
        self::assertInstanceOf(WP_Error::class, wppilot_restore_point_create('wppilot/edit-file', [['root' => 'site', 'rel' => 'WP-Config.php']]));
        self::assertSame([], wppilot_restore_point_index());
        self::assertSame([], (array) glob($this->root . '/storage/points/*', GLOB_ONLYDIR));
    }

    public function testTheStoredSnapshotIsEncryptedAndATamperedOneIsRefused(): void
    {
        $this->put('site/notes.txt', 'plain-marker-before');
        $id = $this->run_ability('wppilot/edit-file', ['path' => 'notes.txt'], function (): void {
            $this->put('site/notes.txt', 'after');
        });
        $point_id = (string) array_key_first(wppilot_restore_point_index());
        $dir = $this->root . '/storage/points/' . $point_id;

        self::assertFalse(file_exists($dir . '/snapshot.zip'), 'no plain zip is kept');
        self::assertSame(['index.php', 'manifest.json', 'snapshot.bin'], array_values(array_diff((array) scandir($dir), ['.', '..'])), 'no temporary plain copy is left');
        $sealed = (string) file_get_contents($dir . '/snapshot.bin');
        self::assertStringStartsNotWith('PK', $sealed);
        self::assertStringNotContainsString('plain-marker-before', $sealed);

        $sealed[strlen($sealed) - 1] = $sealed[strlen($sealed) - 1] === 'a' ? 'b' : 'a';
        file_put_contents($dir . '/snapshot.bin', $sealed);
        $undo = wppilot_rollback_change($id);
        self::assertInstanceOf(WP_Error::class, $undo);
        self::assertSame('wppilot_restore_point_corrupt', $undo->get_error_code());
        self::assertSame('after', file_get_contents($this->root . '/site/notes.txt'), 'nothing was touched');
        self::assertSame(['index.php', 'manifest.json', 'snapshot.bin'], array_values(array_diff((array) scandir($dir), ['.', '..'])), 'the decrypted copy is removed');
    }

    public function testChangeOutputNeverNamesTheStorageId(): void
    {
        $this->put('site/page.txt', 'one');
        $id = $this->run_ability('wppilot/edit-file', ['path' => 'page.txt'], function (): void {
            $this->put('site/page.txt', 'two');
        });
        self::assertIsArray(wppilot_rollback_change($id));
        $ids = array_keys(wppilot_restore_point_index());
        self::assertCount(2, $ids, 'the undo point and the redo point');

        $output = wppilot_change_for_output(wppilot_get_change($id));
        $json = (string) wp_json_encode($output);
        foreach ($ids as $point_id) {
            self::assertStringNotContainsString((string) $point_id, $json);
        }
        self::assertSame('available', $output['restore_point']['status']);
        self::assertSame('page.txt', $output['rollback']['snapshot']['targets'][0]['rel']);
    }

    public function testLookingUpAnExpiredRestorePointDeletesItsFilesButKeepsTheRecord(): void
    {
        $this->put('site/f.txt', 'one');
        $point = wppilot_restore_point_create('wppilot/edit-file', [['root' => 'site', 'rel' => 'f.txt']], ['expires_ts' => time() - 1]);
        self::assertIsArray($point);
        $dir = $this->root . '/storage/points/' . $point['id'];
        self::assertTrue(is_dir($dir));

        $record = wppilot_restore_point_get($point['id']);

        self::assertIsArray($record);
        self::assertFalse(file_exists($dir), 'an expired restore point keeps no copy of the files');
        self::assertArrayHasKey($point['id'], wppilot_restore_point_index(), 'the record stays to explain the expiry');
    }

    public function testDeactivationDropsTheCleanupScheduleAndExpiredRestorePoints(): void
    {
        $saved_cron = WPPilot_Test_State::$cron;
        $this->put('site/f.txt', 'one');
        $expired = wppilot_restore_point_create('wppilot/edit-file', [['root' => 'site', 'rel' => 'f.txt']], ['change_id' => 'row-1', 'expires_ts' => time() - 1]);
        $kept = wppilot_restore_point_create('wppilot/edit-file', [['root' => 'site', 'rel' => 'f.txt']], ['change_id' => 'row-2']);
        wppilot_restore_points_schedule_cleanup();
        self::assertNotFalse(wp_next_scheduled(WPPILOT_RESTORE_POINT_CLEANUP_HOOK));

        try {
            wppilot_deactivate_current_site();
            self::assertFalse(wp_next_scheduled(WPPILOT_RESTORE_POINT_CLEANUP_HOOK));
            self::assertSame([$kept['id']], array_keys(wppilot_restore_point_index()));
            self::assertFalse(is_dir($this->root . '/storage/points/' . $expired['id']));
        } finally {
            WPPilot_Test_State::$cron = $saved_cron;
        }
    }

    public function testUndoRefusesWhenTheFileChangedSinceUnlessForced(): void
    {
        $this->put('site/page.txt', 'ai-before');
        $id = $this->run_ability('wppilot/edit-file', ['path' => 'page.txt'], function (): void {
            $this->put('site/page.txt', 'ai-after');
        });
        $this->put('site/page.txt', 'person-edited-later');

        $undo = wppilot_rollback_change($id);
        self::assertInstanceOf(WP_Error::class, $undo);
        self::assertSame('wppilot_restore_point_changed_since', $undo->get_error_code());
        self::assertStringContainsString('force=true', $undo->get_error_message());
        self::assertSame('person-edited-later', file_get_contents($this->root . '/site/page.txt'));
        self::assertNotTrue(wppilot_get_change($id)['rolled_back'] ?? false);
        self::assertCount(1, wppilot_restore_point_index(), 'the unused redo copy is dropped');

        $forced = wppilot_rollback_change($id, true);
        self::assertIsArray($forced, $forced instanceof WP_Error ? $forced->get_error_message() : '');
        self::assertSame('ai-before', file_get_contents($this->root . '/site/page.txt'));
    }

    public function testUndoingAnInstallKeepsAFolderSomeoneAddedFilesTo(): void
    {
        $id = $this->run_ability('wppilot/install-plugin', ['slug' => 'fresh'], function (): void {
            $this->put('plugins/fresh/fresh.php', "<?php\n");
            WPPilot_Test_State::$plugins['fresh/fresh.php'] = ['Name' => 'Fresh'];
        }, ['file' => 'fresh/fresh.php', 'slug' => 'fresh']);
        $this->put('plugins/fresh/uploads/customer.csv', 'a,b');

        $undo = wppilot_rollback_change($id);

        self::assertInstanceOf(WP_Error::class, $undo);
        self::assertSame('wppilot_restore_point_changed_since', $undo->get_error_code());
        self::assertSame('a,b', file_get_contents($this->root . '/plugins/fresh/uploads/customer.csv'));
    }

    /**
     * Run a covered ability the way the gate pipeline and execute hooks do: the gate's restore
     * point, the before hook, the work itself, the after hook. Returns the ledger row's id.
     *
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $result
     * @param list<string>              $dirs   Folders that exist before the call.
     */
    private function run_ability(string $name, array $input, callable $work, ?array $result = null, array $dirs = []): string
    {
        foreach ($dirs as $dir) {
            if (!is_dir($this->root . '/' . $dir)) {
                mkdir($this->root . '/' . $dir, 0755, true);
            }
        }
        $gated = wppilot_restore_point_pre_execute($input, $this->ability($name));
        self::assertIsArray($gated, $gated instanceof WP_Error ? $gated->get_error_message() : '');
        wppilot_change_before($name, $input);
        $work();
        wppilot_change_after($name, $input, $result ?? ['ok' => true]);
        $rows = wppilot_query_change_log([], 1);

        return (string) $rows[0]['id'];
    }

    private function ability(string $name): object
    {
        return new class ($name) {
            public function __construct(private string $name)
            {
            }

            public function get_name(): string
            {
                return $this->name;
            }
        };
    }

    private function put(string $rel, string $content): void
    {
        $path = $this->root . '/' . $rel;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);
    }

    /** @return array<string, string> relative path => content, '/' for folders */
    private function tree(string $rel): array
    {
        $base = $this->root . '/' . $rel;
        $out = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $path = substr(wp_normalize_path($item->getPathname()), strlen($base) + 1);
            $out[$path] = $item->isDir() ? '/' : (string) file_get_contents($item->getPathname());
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> */
    private function leftovers(string $rel): array
    {
        return array_values(array_filter(
            (array) scandir($this->root . '/' . $rel),
            static fn(mixed $name): bool => str_starts_with((string) $name, '.gobuildwp-'),
        ));
    }

    /** @param list<array<string, mixed>> $files */
    private function fileHash(array $files, string $path): string
    {
        foreach ($files as $file) {
            if ($file['path'] === $path) {
                return (string) $file['sha256'];
            }
        }

        return '';
    }
}
