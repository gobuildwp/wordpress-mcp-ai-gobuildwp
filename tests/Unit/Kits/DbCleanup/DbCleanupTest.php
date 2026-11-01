<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\DbCleanup;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\DbCleanup;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Host;
use WPPilot\Kits\Runtime\Jobs;
use WPPilot\Kits\Runtime\Ledger;

require_once dirname(__DIR__, 3) . '/doubles/sqlite-wpdb.php';

/**
 * The db-cleanup kit against real SQL on SQLite: what it counts, what it removes, that it needs
 * confirmation, and that the undo puts each row back with its own ids.
 */
final class DbCleanupTest extends TestCase
{
    /** @var list<array{ability: string, items: list<array<string, mixed>>}> */
    public static array $recorded = [];

    /** @var array<string, callable> */
    public static array $strategies = [];

    public static int $budget = 1_048_576;

    private mixed $savedWpdb = null;

    private TablesWpdb $db;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/doubles.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        $kit = require dirname(__DIR__, 4) . '/includes/kits/db-cleanup/bootstrap.php';
        Runtime\host(self::host());
        ($kit['boot'])(Runtime\host());
        foreach ($kit['ability_files'] as $file) {
            require_once $file;
        }
    }

    protected function setUp(): void
    {
        self::$recorded = [];
        self::$budget = 1_048_576;
        Calls::$log = [];
        Calls::$failDelete = [];
        Runtime\host(self::host());
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        $this->db = new TablesWpdb(new \WPPilot_Test_Sqlite_Wpdb());
        $GLOBALS['wpdb'] = $this->db;
        $this->seed();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->savedWpdb;
    }

    private function seed(): void
    {
        $old = gmdate('Y-m-d H:i:s', time() - 60 * 86400);
        $new = gmdate('Y-m-d H:i:s', time() - 86400);
        $this->db->insert('wp_posts', ['ID' => 1, 'post_parent' => 0, 'post_type' => 'post', 'post_content' => 'Hello', 'post_modified_gmt' => $old]);
        $this->db->insert('wp_posts', ['ID' => 2, 'post_parent' => 1, 'post_type' => 'revision', 'post_content' => 'Old draft', 'post_modified_gmt' => $old]);
        $this->db->insert('wp_posts', ['ID' => 3, 'post_parent' => 1, 'post_type' => 'revision', 'post_content' => 'Recent draft', 'post_modified_gmt' => $new]);
        $this->db->insert('wp_postmeta', ['meta_id' => 10, 'post_id' => 2, 'meta_key' => '_edit_last', 'meta_value' => '1']);
        $this->db->insert('wp_comments', ['comment_ID' => 20, 'comment_post_ID' => 1, 'comment_content' => 'buy now', 'comment_approved' => 'spam', 'comment_date_gmt' => $old]);
        $this->db->insert('wp_comments', ['comment_ID' => 21, 'comment_post_ID' => 1, 'comment_content' => 'spam today', 'comment_approved' => 'spam', 'comment_date_gmt' => $new]);
        $this->db->insert('wp_comments', ['comment_ID' => 22, 'comment_post_ID' => 1, 'comment_content' => 'binned', 'comment_approved' => 'trash', 'comment_date_gmt' => $old]);
        $this->db->insert('wp_comments', ['comment_ID' => 23, 'comment_post_ID' => 1, 'comment_content' => 'Nice post', 'comment_approved' => '1', 'comment_date_gmt' => $old]);
        $this->db->insert('wp_commentmeta', ['meta_id' => 30, 'comment_id' => 20, 'meta_key' => 'akismet_result', 'meta_value' => 'true']);
    }

    /** @return list<int> */
    private function ids(string $sql): array
    {
        return array_map('intval', $this->db->get_col($sql));
    }

    public function testAbilitiesAreRegisteredAsDeclared(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/db-cleanup/kit.json'), true);
        $declared = array_column($manifest['abilities'], null, 'name');
        foreach (['wppilot/db-cleanup-preview' => [true, false], 'wppilot/db-cleanup' => [false, true]] as $name => [$readonly, $destructive]) {
            self::assertTrue(wp_has_ability($name), $name);
            $annotations = Registrations::$args[$name]['meta']['annotations'];
            self::assertSame([$readonly, $destructive], [$annotations['readonly'], $annotations['destructive']], $name);
            self::assertSame([$readonly, $destructive], [$declared[$name]['readonly'], $declared[$name]['destructive']], "kit.json agrees on {$name}");
        }
        self::assertArrayHasKey('confirm', Registrations::$args['wppilot/db-cleanup']['input_schema']['properties']);
        self::assertArrayHasKey(DbCleanup\STRATEGY, self::$strategies, 'boot registers the undo');
    }

    public function testPreviewCountsOnlyOldRowsAndChangesNothing(): void
    {
        $out = DbCleanup\preview(['older_than_days' => 30]);

        self::assertSame(['spam_comments' => 1, 'trash_comments' => 1, 'revisions' => 1], $out['counts']);
        self::assertSame([20, 21, 22, 23], $this->ids('SELECT comment_ID FROM wp_comments ORDER BY comment_ID'));
        self::assertSame([], Calls::$log);
    }

    public function testPreviewHonoursTheChosenTargets(): void
    {
        self::assertSame(['revisions' => 1], DbCleanup\preview(['targets' => ['revisions', 'bogus']])['counts']);
    }

    public function testCleanupRefusesWithoutConfirmation(): void
    {
        $out = DbCleanup\cleanup(['targets' => ['spam_comments']]);

        self::assertInstanceOf(WP_Error::class, $out);
        self::assertSame('kit_confirmation_required', $out->get_error_code());
        self::assertSame([20, 21, 22, 23], $this->ids('SELECT comment_ID FROM wp_comments ORDER BY comment_ID'));
        self::assertSame([], self::$recorded);
    }

    public function testCleanupRemovesOnlyOldTargetRowsAndRecordsEachOne(): void
    {
        $out = DbCleanup\cleanup(['confirm' => true, 'older_than_days' => 30]);

        self::assertIsArray($out);
        self::assertSame(['spam_comments' => 1, 'trash_comments' => 1, 'revisions' => 1], $out['deleted']);
        self::assertSame(['spam_comments' => 0, 'trash_comments' => 0, 'revisions' => 0], $out['remaining']);
        self::assertSame([21, 23], $this->ids('SELECT comment_ID FROM wp_comments ORDER BY comment_ID'), 'recent spam and approved comments stay');
        self::assertSame([1, 3], $this->ids('SELECT ID FROM wp_posts ORDER BY ID'), 'the post and the recent revision stay');
        self::assertContains('delete_comment:20:force', Calls::$log);
        self::assertCount(1, self::$recorded);
        self::assertSame('wppilot/db-cleanup', self::$recorded[0]['ability']);
        $items = self::$recorded[0]['items'];
        self::assertSame([['spam_comments', 20], ['trash_comments', 22], ['revisions', 2]], array_map(static fn(array $i): array => [$i['item']['target'], $i['item']['id']], $items));
        self::assertSame('akismet_result', $items[0]['before']['meta'][0]['meta_key']);
    }

    public function testCleanupStopsAtTheLimit(): void
    {
        $out = DbCleanup\cleanup(['confirm' => true, 'limit' => 1]);

        self::assertSame(['spam_comments' => 1, 'trash_comments' => 0, 'revisions' => 0], $out['deleted']);
        self::assertSame(1, $out['remaining']['trash_comments']);
    }

    public function testCleanupStopsBeforeTheSnapshotBudgetIsSpent(): void
    {
        self::$budget = 10;

        $out = DbCleanup\cleanup(['confirm' => true]);

        self::assertTrue($out['stopped_at_snapshot_budget']);
        self::assertSame(0, array_sum($out['deleted']));
        self::assertSame([20, 21, 22, 23], $this->ids('SELECT comment_ID FROM wp_comments ORDER BY comment_ID'), 'nothing removed without a before-image');
    }

    public function testAFailedDeleteIsCountedAndNotRecorded(): void
    {
        Calls::$failDelete = [20];

        $out = DbCleanup\cleanup(['confirm' => true, 'targets' => ['spam_comments']]);

        self::assertSame(1, $out['failed']);
        self::assertSame(0, $out['deleted']['spam_comments']);
        self::assertSame([], self::$recorded);
    }

    public function testCleanupWithNoTargetsIsRefused(): void
    {
        $out = DbCleanup\cleanup(['confirm' => true, 'targets' => ['bogus']]);

        self::assertInstanceOf(WP_Error::class, $out);
        self::assertSame('kit_db_cleanup_no_targets', $out->get_error_code());
    }

    public function testUndoPutsEachRowBackWithItsOwnIdsAndMeta(): void
    {
        DbCleanup\cleanup(['confirm' => true]);
        $restore = self::$strategies[DbCleanup\STRATEGY];

        foreach (self::$recorded[0]['items'] as $item) {
            $result = $restore(['snapshot' => $item['before']]);
            self::assertIsArray($result);
            self::assertTrue($result['verified']);
        }

        self::assertSame([20, 21, 22, 23], $this->ids('SELECT comment_ID FROM wp_comments ORDER BY comment_ID'));
        self::assertSame([1, 2, 3], $this->ids('SELECT ID FROM wp_posts ORDER BY ID'));
        self::assertSame('buy now', $this->db->get_var('SELECT comment_content FROM wp_comments WHERE comment_ID = 20'));
        self::assertSame('spam', $this->db->get_var('SELECT comment_approved FROM wp_comments WHERE comment_ID = 20'));
        self::assertSame('30', $this->db->get_var('SELECT meta_id FROM wp_commentmeta WHERE comment_id = 20'));
        self::assertSame('10', $this->db->get_var('SELECT meta_id FROM wp_postmeta WHERE post_id = 2'));
        self::assertContains('count:1', Calls::$log);
        self::assertContains('clean_post:2', Calls::$log);
    }

    public function testUndoRefusesWhenTheIdIsTakenAgain(): void
    {
        DbCleanup\cleanup(['confirm' => true, 'targets' => ['spam_comments']]);
        $before = self::$recorded[0]['items'][0]['before'];
        $this->db->insert('wp_comments', ['comment_ID' => 20, 'comment_post_ID' => 1, 'comment_content' => 'new', 'comment_approved' => '1', 'comment_date_gmt' => gmdate('Y-m-d H:i:s')]);

        $result = (self::$strategies[DbCleanup\STRATEGY])(['snapshot' => $before]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('kit_db_cleanup_id_taken', $result->get_error_code());
        self::assertSame('new', $this->db->get_var('SELECT comment_content FROM wp_comments WHERE comment_ID = 20'));
    }

    public function testUndoRefusesWhenTheParentPostIsGone(): void
    {
        DbCleanup\cleanup(['confirm' => true, 'targets' => ['revisions']]);
        $this->db->delete('wp_posts', ['ID' => 1]);

        $result = (self::$strategies[DbCleanup\STRATEGY])(['snapshot' => self::$recorded[0]['items'][0]['before']]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('kit_db_cleanup_parent_gone', $result->get_error_code());
    }

    public function testUndoRejectsAForeignSnapshot(): void
    {
        $result = (self::$strategies[DbCleanup\STRATEGY])(['snapshot' => ['target' => 'users', 'row' => ['ID' => 1]]]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('kit_rollback_bad_snapshot', $result->get_error_code());
    }

    private static function host(): Host
    {
        $ledger = new class implements Ledger {
            public function capture_for(string $ability_name, callable $capture): void
            {
            }

            public function record_items(string $ability_name, array $items, ?string $group = null): array
            {
                DbCleanupTest::$recorded[] = ['ability' => $ability_name, 'items' => $items];
                return ['group' => 'g', 'change_ids' => ['c1'], 'without_before_image' => 0];
            }

            public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
            {
                DbCleanupTest::$strategies[$type] = $restore;
                return true;
            }

            public function query(array $filters = []): array
            {
                return [];
            }

            public function export_row(array $entry): array
            {
                return $entry;
            }

            public function snapshot_budget(): int
            {
                return DbCleanupTest::$budget;
            }

            public function download_url(): string
            {
                return '';
            }
        };
        return new class ($ledger) implements Host {
            public function __construct(private Ledger $ledger)
            {
            }

            public function id(): string
            {
                return 'test';
            }

            public function can_manage(): bool
            {
                return true;
            }

            public function is_enabled(): bool
            {
                return true;
            }

            public function safety_profile(): string
            {
                return 'developer';
            }

            public function ledger(): Ledger
            {
                return $this->ledger;
            }

            public function jobs(): Jobs
            {
                throw new \LogicException('not used');
            }

            public function extension(string $point): mixed
            {
                return null;
            }

            public function admin_parent_slug(): string
            {
                return 'tools.php';
            }

            public function confirm_guard(string $ability_name, array $input): bool|WP_Error
            {
                return ($input['confirm'] ?? null) === true ? true : new WP_Error('kit_confirmation_required', $ability_name);
            }
        };
    }
}
