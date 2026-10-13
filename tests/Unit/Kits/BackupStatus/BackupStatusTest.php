<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\BackupStatus;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\BackupStatus as B;
use WPPilot\Kits\Runtime;
use WPPilot\Tests\Unit\Kits\BackupStatus\Duplicator\DupPackage;

/**
 * The backup-status kit against UpdraftPlus, Duplicator 5, BackWPup and All-in-One WP Migration
 * doubles: each adapter's
 * reading, what never leaves the kit, and standing aside when the ability names are taken.
 */
final class BackupStatusTest extends TestCase
{
    private static ReadHost $host;

    /** @var array<string, mixed> */
    private static array $kit = [];

    private mixed $savedWpdb = null;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once __DIR__ . '/doubles.php';
        require_once __DIR__ . '/harness.php';
        self::$host = new ReadHost();
        Runtime\host(self::$host);
        self::$kit = require dirname(__DIR__, 4) . '/includes/kits/backup-status/bootstrap.php';
    }

    protected function setUp(): void
    {
        Caps::$granted = ['manage_options'];
        Registrations::$args = [];
        Vendors::reset();
        self::$host->enabled = true;
        Runtime\host(self::$host);
        $GLOBALS['updraftplus'] = new \UpdraftPlus();
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class () {
            public function prepare(string $query, mixed ...$args): string
            {
                return vsprintf($query, $args);
            }
        };
        Vendors::$bwpLogdir = sys_get_temp_dir() . '/kit-backup-status-' . getmypid();
        @mkdir(Vendors::$bwpLogdir);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->savedWpdb;
        unset($GLOBALS['updraftplus']);
        $this->removeTree(Vendors::$bwpLogdir);
    }

    // Registration.

    public function testBothReadsRegisterWhileTheirNamesAreFree(): void
    {
        $this->registerAbilities();

        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/backup-status/kit.json'), true);
        foreach (['wppilot/backup-status', 'wppilot/backup-list'] as $name) {
            self::assertArrayHasKey($name, Registrations::$args);
            $args = Registrations::$args[$name];
            self::assertTrue($args['meta']['annotations']['readonly'], $name);
            self::assertFalse($args['meta']['annotations']['destructive'], $name);
            self::assertSame('backups', $args['category']);
            $declared = array_column($manifest['abilities'], null, 'name')[$name];
            self::assertSame([true, false, 'self'], [$declared['readonly'], $declared['destructive'], $declared['ledger']]);
        }
        self::assertSame(['wppilot/backup-status', 'wppilot/backup-list'], array_column($manifest['abilities'], 'name'));
    }

    public function testPermissionNeedsTheHostAndManageOptions(): void
    {
        $this->registerAbilities();
        $permission = Registrations::$args['wppilot/backup-status']['permission_callback'];

        self::assertTrue($permission());
        Caps::$granted = [];
        self::assertFalse($permission());
        Caps::$granted = ['manage_options'];
        self::$host->enabled = false;
        self::assertFalse($permission());
    }

    // UpdraftPlus.

    public function testUpdraftPlusSetsAreReadNewestFirstWithOnlyTheLastJobsVerdict(): void
    {
        $recent = $this->seedUpdraftPlus();

        $list = B\updraftplus_list(10);

        self::assertCount(2, $list);
        self::assertSame($recent, $list[0]['timestamp']);
        self::assertSame(['plugins', 'uploads', 'db'], $list[0]['contents'], 'entities with a recorded size, not bookkeeping keys');
        self::assertSame(3050, $list[0]['size_bytes'], 'every part, plugins1-size included');
        self::assertSame(['success', 0, 1], [$list[0]['result'], $list[0]['errors'], $list[0]['warnings']]);
        self::assertSame('unknown', $list[1]['result'], 'an older set has no verdict');
        self::assertSame(['Local (web server)', 'Amazon S3'], $list[0]['storage']);
        self::assertSame(['Local (web server)'], $list[1]['storage']);
        self::assertSame('2026-09-29T03:00:00+05:00', $list[0]['time']);
        self::assertSame('2026-09-28T22:00:00Z', $list[0]['time_utc']);
        $encoded = (string) json_encode($list);
        self::assertStringNotContainsString('backup_x', $encoded, 'no archive names');
        self::assertStringNotContainsString('bbbbbbbbbbbb', $encoded, 'no job nonces');
    }

    public function testAnUpdraftPlusVerdictThatOutlivedItsSetIsNotASuccessfulBackup(): void
    {
        $recent = $this->seedUpdraftPlus();
        self::assertSame($recent, B\updraftplus_status()['last_successful_backup']['timestamp']);

        unset(Vendors::$updraft['updraft_backup_history'][$recent]);
        $status = B\updraftplus_status();

        self::assertSame('deleted', $status['last_backup']['kind']);
        self::assertNull($status['last_successful_backup']);
    }

    public function testRunningAndQueuedUpdraftPlusJobs(): void
    {
        $now = Vendors::$now;
        Vendors::$cron = [$now + 300 => ['updraft_backup_resume' => ['k' => ['args' => [1, 'cccccccccccc']]]]];
        Vendors::$siteOptions['updraft_jobdata_cccccccccccc'] = ['jobstatus' => 'dbcreating1', 'backup_time' => $now - 60];

        $running = B\updraftplus_running();
        self::assertTrue($running['running']);
        self::assertSame('running: dbcreating1', $running['jobs'][0]['state']);

        Vendors::$siteOptions['updraft_jobdata_cccccccccccc']['jobstatus'] = 'finished';
        self::assertFalse(B\updraftplus_running()['running'], 'a finished job is not running');

        Vendors::$cron = [$now => ['updraft_backupnow_backup_database' => ['k' => ['args' => [['nocloud' => false]]]]]];
        self::assertSame('queued', B\updraftplus_running()['jobs'][0]['state']);
    }

    // Duplicator.

    public function testDuplicatorPackagesByStatus(): void
    {
        $this->seedDuplicator();

        $dup = B\duplicator_list(10);

        self::assertSame(['cancelled', 'failed', 'success'], array_column($dup, 'result'));
        self::assertSame(['db', 'plugins', 'others'], $dup[2]['contents']);
        self::assertSame(['db'], $dup[1]['contents'], 'a database-only package');
        self::assertSame(Vendors::$now - 7200, $dup[2]['timestamp'], 'created is UTC');
        self::assertSame(Vendors::$now - 7000, $dup[2]['finished_timestamp'], 'finished from the complete-state timer');
        self::assertSame([4242, null], [$dup[2]['size_bytes'], $dup[1]['size_bytes']], 'size only for a complete archive');
        self::assertSame(['Default (Default Local)'], $dup[2]['storage']);
        self::assertFalse(B\duplicator_status()['trigger']['supported']);
    }

    // BackWPup.

    public function testBackWPupRunsFromTheirLogs(): void
    {
        $this->seedBackWPup();

        $runs = B\backwpup_list(10);

        self::assertSame(['running', 'failed', 'success'], array_column($runs, 'result'));
        self::assertSame(['plugins', 'uploads', 'db', 'plugin-list'], $runs[2]['contents']);
        self::assertSame(['Folder', 'Amazon S3'], $runs[2]['storage']);
        self::assertSame(Vendors::$now - 90000 + 40, $runs[2]['finished_timestamp'], 'log time plus runtime');
        self::assertFalse($runs[2]['archive_verified'], 'stored remotely as well: counted, not verified');
        self::assertCount(1, B\backwpup_list(1));

        $status = B\backwpup_status();
        self::assertSame([2, 3], array_column($status['jobs'], 'job_id'), 'the tempjob scaffolding is hidden');
        self::assertSame(Vendors::$now - 90000, $status['last_successful_backup']['timestamp']);
        self::assertTrue($status['running']['running']);
        self::assertStringNotContainsString(Vendors::$bwpLogdir, (string) json_encode($status), 'no log paths');
    }

    public function testABackWPupLogWhoseLocalArchiveIsGoneIsNotABackup(): void
    {
        $this->seedBackWPup();
        Vendors::$bwpWorking = false;
        $archives = Vendors::$bwpLogdir . '/archives';
        @mkdir($archives);
        Vendors::$bwpPaths = ['uploads/backwpup-archives/' => $archives];
        Vendors::$bwpJobs[4] = ['jobid' => 4, 'name' => 'Local', 'type' => ['DBDUMP'], 'destinations' => ['FOLDER'], 'backupdir' => 'uploads/backwpup-archives/', 'backuptype' => 'archive', 'activetype' => ''];
        $archive = '2026-09-29_10-00-00_ABCDEF04_DBDUMP.zip';
        file_put_contents(Vendors::$bwpLogdir . '/backwpup_log_d_4.html', "<html><body>\n[INFO] Logfile is: backwpup_log_d_4.html<br />\n[INFO] Backup file is located at: .../wp-content/uploads/backwpup-archives/{$archive}<br />\n");
        touch(Vendors::$bwpLogdir . '/backwpup_log_d_4.html', Vendors::$now - 10);
        Vendors::$bwpHeaders['backwpup_log_d_4.html'] = ['logtime' => Vendors::$now - 20, 'errors' => 0, 'warnings' => 0, 'jobid' => 4, 'name' => 'Local', 'type' => 'DBDUMP', 'runtime' => 10, 'backupfilesize' => 4000];
        file_put_contents($archives . '/' . $archive, 'zip');

        $present = B\backwpup_status();
        self::assertSame([4, true, 'job-run'], [$present['last_successful_backup']['job_id'], $present['last_successful_backup']['archive_verified'], $present['last_successful_backup']['kind']]);
        self::assertStringNotContainsString($archive, (string) json_encode($present));

        unlink($archives . '/' . $archive);
        $gone = B\backwpup_status();
        self::assertSame(['deleted', false], [$gone['last_backup']['kind'], $gone['last_backup']['archive_verified']]);
        self::assertSame(Vendors::$now - 90000, $gone['last_successful_backup']['timestamp'], 'the older run is the last good one');
    }

    public function testTheArchiveNameIsFoundByShapeInATranslatedOrCompressedLog(): void
    {
        file_put_contents(Vendors::$bwpLogdir . '/de.html', "[INFO] Die Sicherungsdatei liegt unter: .../backwpup-archives/2026-09-29_x_FILE-DBDUMP.tar.gz<br />\n");
        file_put_contents(Vendors::$bwpLogdir . '/gz.html.gz', (string) gzencode("[INFO] Backup file is located at: .../a/b.tar<br />\n"));
        file_put_contents(Vendors::$bwpLogdir . '/none.html', "[INFO] Nothing here<br />\n");

        self::assertSame('2026-09-29_x_FILE-DBDUMP.tar.gz', B\backwpup_archive_name(Vendors::$bwpLogdir . '/de.html'));
        self::assertSame('b.tar', B\backwpup_archive_name(Vendors::$bwpLogdir . '/gz.html.gz'));
        self::assertNull(B\backwpup_archive_name(Vendors::$bwpLogdir . '/none.html'));
    }

    // The abilities.

    public function testStatusAcrossProvidersNamesTheNewestSuccessfulBackup(): void
    {
        $this->seedUpdraftPlus();
        $this->seedDuplicator();
        $this->seedBackWPup();

        $status = B\status([]);

        self::assertSame(['updraftplus', 'duplicator', 'backwpup', 'ai1wm'], $status['active_providers']);
        self::assertSame(['UpdraftPlus', 'Duplicator', 'BackWPup', 'All-in-One WP Migration'], $status['supported_providers']);
        self::assertSame(['provider' => 'duplicator', 'timestamp' => Vendors::$now - 7000], array_slice($status['newest_successful_backup'], 0, 2), 'Duplicator finished last');
        self::assertSame(['updraftplus'], array_column(B\status(['provider' => 'updraftplus'])['providers'], 'provider'));

        // An All-in-One WP Migration export that finished later is the newest across providers.
        $this->archive('site-20260929-040000-abcdefghijkl.wpress', 2 * self::MB);
        Vendors::$ai1wmFiles = [['path' => '', 'filename' => 'site-20260929-040000-abcdefghijkl.wpress', 'mtime' => Vendors::$now - 60, 'size' => 2 * self::MB]];
        try {
            self::assertSame(['provider' => 'ai1wm', 'timestamp' => Vendors::$now - 60], array_slice(B\status([])['newest_successful_backup'], 0, 2));
        } finally {
            $this->removeTree(AI1WM_BACKUPS_PATH);
        }
    }

    // All-in-One WP Migration.

    private const MB = 1048576;

    /**
     * A file in the doubles' backups folder: a complete archive ends in the plugin's (v1) end-of-
     * archive block of NUL bytes; an incomplete one does not.
     */
    private function archive(string $name, int $bytes, bool $complete = true): void
    {
        @mkdir(dirname(AI1WM_BACKUPS_PATH . '/' . $name), 0777, true);
        $handle = fopen(AI1WM_BACKUPS_PATH . '/' . $name, 'wb');
        ftruncate($handle, $bytes);
        if (!$complete) {
            fseek($handle, $bytes - 1);
            fwrite($handle, 'X');
        }
        fclose($handle);
    }

    public function testAi1wmBackupsAreTheFinishedArchivesInItsFolderWithoutTheirNames(): void
    {
        $now = Vendors::$now;
        $this->archive('example-com-20260928-090000-a1b2c3d4e5f6.wpress', 2 * self::MB);
        $this->archive('example-com-20260928-230000-zyxwvutsrqpo.wpress', 3 * self::MB);
        $this->archive('huge.wpress', 5000);
        Vendors::$ai1wmFiles = [
            ['path' => '', 'filename' => 'example-com-20260928-090000-a1b2c3d4e5f6.wpress', 'mtime' => $now - 86400, 'size' => 2 * self::MB],
            ['path' => '', 'filename' => 'example-com-20260928-230000-zyxwvutsrqpo.wpress', 'mtime' => $now - 3600, 'size' => 3 * self::MB],
            ['path' => 'old', 'filename' => 'old/unreadable.wpress', 'mtime' => null, 'size' => null],
            ['path' => '', 'filename' => 'huge.wpress', 'mtime' => $now - 7200, 'size' => null],
        ];
        Vendors::$ai1wmLabels = ['example-com-20260928-230000-zyxwvutsrqpo.wpress' => 'Before the update'];

        try {
            $list = B\ai1wm_list(10);

            self::assertCount(3, $list, 'a file the plugin could not date is not listed');
            self::assertSame([$now - 3600, $now - 7200, $now - 86400], array_column($list, 'timestamp'), 'newest first, by file time');
            self::assertSame(['success', 3 * self::MB, ['Local (web server)'], 'Before the update', null, 'backup'], [$list[0]['result'], $list[0]['size_bytes'], $list[0]['storage'], $list[0]['label'], $list[0]['contents'], $list[0]['kind']]);
            self::assertSame([null, 'backup'], [$list[1]['size_bytes'], $list[1]['kind']], 'a size too large to measure stays null and still counts');
            self::assertSame(16, strlen($list[0]['id']));
            self::assertNotSame($list[0]['id'], $list[2]['id']);
            self::assertCount(1, B\ai1wm_list(1));

            $status = B\ai1wm_status();
            self::assertSame([$now - 3600, $now - 3600, 3, 0, '7.112'], [$status['last_backup']['timestamp'], $status['last_successful_backup']['timestamp'], $status['backups'], $status['unverified_files'], $status['version']]);
            $encoded = (string) json_encode([$list, $status]);
            foreach (['a1b2c3d4e5f6', 'zyxwvutsrqpo', 'example-com', 'huge', 'unreadable', AI1WM_STORAGE_PATH, AI1WM_BACKUPS_PATH] as $secret) {
                self::assertStringNotContainsString($secret, $encoded, 'no archive names or paths leave the kit');
            }
        } finally {
            $this->removeTree(AI1WM_BACKUPS_PATH);
        }
    }

    public function testAFileDroppedInTheBackupsFolderIsNotABackupUnlessItIsACompleteArchive(): void
    {
        $now = Vendors::$now;
        $this->archive('real.wpress', 2 * self::MB);
        $this->archive('empty.wpress', 0);
        $this->archive('tiny.wpress', 4377);
        $this->archive('cut-short.wpress', 2 * self::MB, false);
        $this->archive('future.wpress', 2 * self::MB);
        $this->archive('nested/inner.wpress', 2 * self::MB);
        Vendors::$ai1wmFiles = [
            ['path' => '', 'filename' => 'empty.wpress', 'mtime' => $now - 10, 'size' => 0],
            ['path' => '', 'filename' => 'tiny.wpress', 'mtime' => $now - 20, 'size' => 4377],
            ['path' => '', 'filename' => 'cut-short.wpress', 'mtime' => $now - 30, 'size' => 2 * self::MB],
            ['path' => '', 'filename' => 'future.wpress', 'mtime' => $now + 3600, 'size' => 2 * self::MB],
            ['path' => 'nested', 'filename' => 'nested/inner.wpress', 'mtime' => $now - 40, 'size' => 2 * self::MB],
            ['path' => '', 'filename' => 'gone.wpress', 'mtime' => $now - 50, 'size' => 2 * self::MB],
            ['path' => '', 'filename' => 'real.wpress', 'mtime' => $now - 7200, 'size' => 2 * self::MB],
        ];

        try {
            $status = B\ai1wm_status();
            self::assertSame($now - 7200, $status['last_successful_backup']['timestamp'], 'only the complete archive counts');
            self::assertSame([1, 6], [$status['backups'], $status['unverified_files']]);
            self::assertSame(['unknown', 'unverified'], [$status['last_backup']['result'], $status['last_backup']['kind']]);
            self::assertSame(['provider' => 'ai1wm', 'timestamp' => $now - 7200], array_slice(B\status(['provider' => 'ai1wm'])['newest_successful_backup'], 0, 2));

            unlink(AI1WM_BACKUPS_PATH . '/real.wpress');
            self::assertNull(B\ai1wm_status()['last_successful_backup'], 'with no complete archive there is no backup');
        } finally {
            $this->removeTree(AI1WM_BACKUPS_PATH);
        }
    }

    public function testAnAi1wmJobFolderCountsAsRunningOnlyWhileItChanges(): void
    {
        $storage = AI1WM_STORAGE_PATH;
        @mkdir($storage);
        try {
            self::assertFalse(B\ai1wm_running()['running'], 'an empty storage folder: nothing running');

            @mkdir($storage . '/6ac7aa9072d44');
            touch($storage . '/6ac7aa9072d44/site.wpress', Vendors::$now - 30);
            @mkdir($storage . '/not-a-job');
            touch($storage . '/not-a-job/x.wpress', Vendors::$now);
            touch($storage . '/error-log-6ac7aa9072d44.log', Vendors::$now);
            $running = B\ai1wm_running();
            self::assertTrue($running['running']);
            self::assertCount(1, $running['jobs'], 'only a uniqid() job folder is a run');
            self::assertSame(['running', 'export or import'], [$running['jobs'][0]['state'], $running['jobs'][0]['what']]);
            self::assertSame(gmdate('Y-m-d\TH:i:s\Z', Vendors::$now - 30), $running['jobs'][0]['last_activity']['time_utc']);
            self::assertTrue(B\ai1wm_status()['running']['running']);

            touch($storage . '/6ac7aa9072d44/site.wpress', Vendors::$now - 16 * 60);
            self::assertFalse(B\ai1wm_running()['running'], 'a folder left by an abandoned run stops counting');
        } finally {
            $this->removeTree($storage);
        }
    }

    public function testAi1wmCanBeStartedWhereverProsRunnerHasWhatItCalls(): void
    {
        $trigger = B\ai1wm_status()['trigger'];
        self::assertFalse($trigger['supported'], 'no ai1wm_export chain is registered');
        self::assertSame(
            "This All-in-One WP Migration install can't be started from here (no export steps are registered). Make the backup from All-in-One WP Migration's Export screen in wp-admin.",
            $trigger['reason'],
        );

        // Any chain the installed release registers; the version is never read.
        Vendors::$ai1wmChain = [5 => [['function' => 'Ai1wm_Export_Init::execute', 'accepted_args' => 1]]];
        $trigger = B\ai1wm_status()['trigger'];
        self::assertTrue($trigger['supported']);
        self::assertSame([], $trigger['scopes']);
        self::assertArrayNotHasKey('reason', $trigger);
        self::assertSame([], B\ai1wm_status()['next_scheduled']);
    }

    public function testAnUnknownProviderIsRefused(): void
    {
        $bad = B\status(['provider' => 'vaultpress']);

        self::assertInstanceOf(WP_Error::class, $bad);
        self::assertSame('kit_backups_provider', $bad->get_error_code());
    }

    public function testListClampsItsLimitAndCoversEveryProvider(): void
    {
        $this->seedUpdraftPlus();
        $this->seedDuplicator();
        $this->seedBackWPup();

        $listed = B\list_backups(['limit' => 1000]);

        self::assertSame(100, $listed['limit']);
        self::assertSame(['updraftplus', 'duplicator', 'backwpup', 'ai1wm'], array_column($listed['providers'], 'provider'));
    }

    public function testAProviderThatThrowsIsReportedUnreadableWithoutItsPaths(): void
    {
        $this->seedDuplicator();
        Vendors::$updraftBroken = true;

        $status = B\status([]);
        $updraft = $status['providers'][0];

        self::assertSame(['updraftplus', false], [$updraft['provider'], $updraft['readable']]);
        self::assertStringNotContainsString('/var/www', $updraft['error']);
        self::assertTrue($status['providers'][1]['readable'], 'Duplicator still answers');
    }

    private function registerAbilities(): void
    {
        foreach (self::$kit['ability_files'] as $file) {
            require $file;
        }
    }

    private function seedUpdraftPlus(): int
    {
        $now = Vendors::$now;
        $old = $now - 3 * 86400;
        $recent = $now - 2 * 3600;
        Vendors::$updraft['updraft_backup_history'] = [
            $old => ['db' => 'backup_old-db.gz', 'db-size' => 1000, 'nonce' => 'aaaaaaaaaaaa', 'service' => 'none'],
            $recent => [
                'plugins' => ['backup_x-plugins.zip', 'backup_x-plugins2.zip'], 'plugins-size' => 500, 'plugins1-size' => 250,
                'uploads' => ['backup_x-uploads.zip'], 'uploads-size' => 2000,
                'db' => 'backup_x-db.gz', 'db-size' => 300,
                'checksums' => ['sha1' => ['db0' => 'abc']], 'files_enumerated_at' => ['plugins' => 1], 'last_saved_by_version' => '1.26.8',
                'nonce' => 'bbbbbbbbbbbb', 'service' => ['s3'], 'label' => 'Before the redesign',
            ],
        ];
        Vendors::$updraft['updraft_last_backup'] = ['backup_time' => $recent, 'success' => 1, 'errors' => [['level' => 'warning', 'message' => 'w']], 'backup_nonce' => 'bbbbbbbbbbbb'];
        Vendors::$updraft['updraft_service'] = ['s3'];
        return $recent;
    }

    private function seedDuplicator(): void
    {
        $now = Vendors::$now;
        Vendors::$dupRows = [
            new DupPackage(7, 100, gmdate('Y-m-d H:i:s', $now - 7200), ['package_component_db', 'package_component_plugins', 'package_component_other'], 4242, false, [100 => ['start' => $now - 7000.0, 'end' => $now - 7000.0]]),
            new DupPackage(8, -1, gmdate('Y-m-d H:i:s', $now - 600), ['package_component_db'], 0, true),
            new DupPackage(9, -2, gmdate('Y-m-d H:i:s', $now - 300), [], 0),
        ];
    }

    private function seedBackWPup(): void
    {
        $now = Vendors::$now;
        Vendors::$bwpJobs = [
            1 => ['jobid' => 1, 'name' => 'First backup', 'tempjob' => true, 'type' => ['DBDUMP'], 'destinations' => ['FOLDER'], 'activetype' => 'wpcron'],
            2 => ['jobid' => 2, 'name' => 'Nightly', 'type' => ['FILE', 'DBDUMP', 'WPPLUGIN'], 'destinations' => ['FOLDER', 'S3'], 'activetype' => 'wpcron', 'cron' => '0 3 * * *', 'backupuploads' => true, 'backupplugins' => true],
            3 => ['jobid' => 3, 'name' => 'Manual', 'type' => ['DBDUMP'], 'destinations' => ['FOLDER'], 'activetype' => ''],
        ];
        foreach (['backwpup_log_a_1.html' => $now - 90000, 'backwpup_log_b_2.html.gz' => $now - 3000, 'backwpup_log_c_3.html' => $now - 60] as $file => $mtime) {
            touch(Vendors::$bwpLogdir . '/' . $file, $mtime);
        }
        Vendors::$bwpHeaders = [
            'backwpup_log_a_1.html' => ['logtime' => $now - 90000, 'errors' => 0, 'warnings' => 0, 'jobid' => 2, 'name' => 'Nightly', 'type' => 'FILE+DBDUMP+WPPLUGIN', 'runtime' => 40, 'backupfilesize' => 9000],
            'backwpup_log_b_2.html.gz' => ['logtime' => $now - 3000, 'errors' => 2, 'warnings' => 1, 'jobid' => 2, 'name' => 'Nightly', 'type' => 'FILE+DBDUMP', 'runtime' => 12, 'backupfilesize' => 0],
            'backwpup_log_c_3.html' => ['logtime' => $now - 60, 'errors' => 0, 'warnings' => 0, 'jobid' => 3, 'name' => 'Manual', 'type' => 'DBDUMP', 'runtime' => 0, 'backupfilesize' => 0],
        ];
        Vendors::$bwpWorking = (object) ['logfile' => Vendors::$bwpLogdir . '/backwpup_log_c_3.html', 'job' => ['jobid' => 3, 'name' => 'Manual'], 'start_time' => $now - 60];
    }

    // Last: it leaves the names claimed in the shared registry, which the tests above need free.
    public function testTheKitStandsAsideWhenAnotherPluginRegisteredTheNamesFirst(): void
    {
        // GoBuildWP Pro 1.10.0 registers both at priority 10, before the kit loader's 20.
        foreach (['wppilot/backup-status', 'wppilot/backup-list'] as $name) {
            if (!\wp_has_ability($name)) {
                \wp_register_ability($name, ['label' => 'Registered first elsewhere']);
            }
        }

        $this->registerAbilities();

        self::assertSame([], Registrations::$args);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $name) {
            if ($name === '.' || $name === '..' || !is_string($name)) {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
