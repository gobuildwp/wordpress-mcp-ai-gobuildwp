<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\DbWrite;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\DbWrite;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Host;
use WPPilot\Kits\Runtime\Jobs;
use WPPilot\Kits\Runtime\Ledger;

require_once dirname(__DIR__, 3) . '/doubles/sqlite-wpdb.php';

/**
 * The db-write kit against real SQL on SQLite: which tables it refuses, the count guard, the
 * before-images it records, and that undo puts rows back unless they changed since.
 */
final class DbWriteTest extends TestCase
{
    /** @var list<array{ability: string, items: list<array<string, mixed>>}> */
    public static array $recorded = [];

    /** @var array<string, callable> */
    public static array $strategies = [];

    public static int $budget = 1_048_576;

    private mixed $savedWpdb = null;

    private SchemaWpdb $db;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/doubles.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        $kit = require dirname(__DIR__, 4) . '/includes/kits/db-write/bootstrap.php';
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
        Runtime\host(self::host());
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        $this->db = new SchemaWpdb(new \Kit_Test_Sqlite_Wpdb());
        $GLOBALS['wpdb'] = $this->db;
        $this->db->query('CREATE TABLE wp_shop_items (id INTEGER PRIMARY KEY, sku TEXT, status TEXT, qty INTEGER, note TEXT, api_token TEXT)');
        $this->db->query('CREATE TABLE wp_shop_codes (code TEXT PRIMARY KEY, label TEXT)');
        $this->db->query('CREATE TABLE wp_shop_pairs (a INTEGER, b INTEGER, PRIMARY KEY (a, b))');
        $this->db->query('CREATE TABLE wp_shop_log (msg TEXT)');
        $this->db->query('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_title TEXT)');
        $this->db->query('CREATE TABLE wp_usermeta (umeta_id INTEGER PRIMARY KEY, meta_key TEXT)');
        $this->db->query('CREATE TABLE wp_test_state (id INTEGER PRIMARY KEY, v TEXT)');
        $this->db->query('CREATE TABLE wp_host_changes (id INTEGER PRIMARY KEY, v TEXT)');
        $this->db->query('CREATE TABLE other_items (id INTEGER PRIMARY KEY)');
        $this->db->query('CREATE TABLE wp_shop_hosted (id INTEGER PRIMARY KEY)');
        $this->db->query('CREATE VIEW wp_shop_view AS SELECT * FROM wp_shop_items');
        foreach ([[1, 'A1', 'draft', 3], [2, 'A2', 'draft', 5], [3, 'A3', 'live', 7]] as [$id, $sku, $status, $qty]) {
            $this->db->insert('wp_shop_items', ['id' => $id, 'sku' => $sku, 'status' => $status, 'qty' => $qty, 'note' => null, 'api_token' => 'secret' . $id]);
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->savedWpdb;
        remove_all_filters('wppilot_kit_db_protected_tables');
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return array_map(
            static fn(array $row): array => array_map(static fn(mixed $v): ?string => $v === null ? null : (string) $v, $row),
            $this->db->get_results('SELECT id, sku, status, qty, note FROM wp_shop_items ORDER BY id', ARRAY_A) ?? [],
        );
    }

    /** @return array<string, mixed> */
    private function undo(int $recorded, int $item): array|WP_Error
    {
        return (self::$strategies[DbWrite\STRATEGY])(['snapshot' => self::$recorded[$recorded]['items'][$item]['before']]);
    }

    public function testAbilitiesAreRegisteredAsDeclared(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/db-write/kit.json'), true);
        $declared = array_column($manifest['abilities'], null, 'name');
        foreach (['wppilot/db-insert-row' => true, 'wppilot/db-update-rows' => true, 'wppilot/db-delete-rows' => true] as $name => $destructive) {
            self::assertTrue(wp_has_ability($name), $name);
            $args = Registrations::$args[$name];
            self::assertSame([false, $destructive], [$args['meta']['annotations']['readonly'], $args['meta']['annotations']['destructive']], $name);
            self::assertSame($destructive, $declared[$name]['destructive'], "kit.json agrees on {$name}");
            self::assertSame('developer', $args['meta']['safety']['min_profile'], $name);
        }
        self::assertArrayHasKey('confirm', Registrations::$args['wppilot/db-delete-rows']['input_schema']['properties']);
        self::assertContains('expected_count', Registrations::$args['wppilot/db-update-rows']['input_schema']['required']);
        self::assertArrayHasKey(DbWrite\STRATEGY, self::$strategies, 'boot registers the undo');
    }

    public function testProtectedTablesAreRefused(): void
    {
        add_filter('wppilot_kit_db_protected_tables', static fn(array $t): array => [...$t, 'shop_codes']);
        $cases = [
            'wp_posts' => 'kit_db_write_table_refused',
            'wp_usermeta' => 'kit_db_write_table_refused',
            'wp_test_state' => 'kit_db_write_table_refused',
            'wp_host_changes' => 'kit_db_write_table_refused',
            'wp_shop_codes' => 'kit_db_write_table_refused',
            'wp_shop_hosted' => 'kit_db_write_table_refused',
            'wp_shop_view' => 'kit_db_write_table_refused',
            'other_items' => 'kit_db_write_table_refused',
            'wp_shop_pairs' => 'kit_db_write_no_primary_key',
            'wp_shop_log' => 'kit_db_write_no_primary_key',
            'wp_missing' => 'kit_db_write_no_table',
            'wp_shop`x' => 'kit_db_write_bad_table',
        ];
        foreach ($cases as $table => $code) {
            $out = DbWrite\insert_row(['table' => $table, 'values' => ['id' => 9]]);
            self::assertInstanceOf(WP_Error::class, $out, $table);
            self::assertSame($code, $out->get_error_code(), $table);
        }
        self::assertSame([], self::$recorded);
    }

    public function testInsertRecordsTheRowAndUndoRemovesIt(): void
    {
        $out = DbWrite\insert_row(['table' => 'wp_shop_items', 'values' => ['sku' => 'B1', 'status' => 'draft', 'qty' => true]]);

        self::assertIsArray($out);
        self::assertSame(4, $out['id']);
        self::assertSame(['c1'], $out['change_ids']);
        self::assertSame('1', (string) $this->db->get_var('SELECT qty FROM wp_shop_items WHERE id = 4'), 'a boolean is written as 1');
        $before = self::$recorded[0]['items'][0]['before'];
        self::assertSame(['insert', null], [$before['op'], $before['row']]);

        $undone = $this->undo(0, 0);
        self::assertTrue($undone['verified']);
        self::assertNull($this->db->get_var('SELECT id FROM wp_shop_items WHERE id = 4'));
    }

    public function testInsertNeedsAFreeKeyWhenTheDatabaseDoesNotFillIt(): void
    {
        self::assertSame('kit_db_write_pk_required', DbWrite\insert_row(['table' => 'wp_shop_codes', 'values' => ['label' => 'x']])->get_error_code());
        self::assertSame('kit_db_write_pk_taken', DbWrite\insert_row(['table' => 'wp_shop_items', 'values' => ['id' => 1]])->get_error_code());
        self::assertSame('kit_db_write_unknown_column', DbWrite\insert_row(['table' => 'wp_shop_items', 'values' => ['nope' => 1]])->get_error_code());
        self::assertSame('kit_db_write_bad_value', DbWrite\insert_row(['table' => 'wp_shop_items', 'values' => ['sku' => ['a']]])->get_error_code());
        self::assertTrue(DbWrite\insert_row(['table' => 'wp_shop_codes', 'values' => ['code' => 'X1', 'label' => 'x'], 'dry_run' => true])['dry_run']);
        self::assertNull($this->db->get_var("SELECT code FROM wp_shop_codes WHERE code = 'X1'"), 'a dry run writes nothing');
    }

    public function testUpdateNeedsTheExactCount(): void
    {
        $base = ['table' => 'wp_shop_items', 'set' => ['status' => 'live'], 'where' => ['status' => 'draft']];

        foreach ([null, 0, 201, '2'] as $bad) {
            self::assertSame('kit_db_write_bad_expected_count', DbWrite\update_rows($base + ['expected_count' => $bad])->get_error_code());
        }
        $mismatch = DbWrite\update_rows($base + ['expected_count' => 3]);
        self::assertSame('kit_db_write_count_mismatch', $mismatch->get_error_code());
        self::assertSame(2, $mismatch->get_error_data()['matched']);
        self::assertSame(['draft', 'draft', 'live'], array_column($this->rows(), 'status'), 'nothing changed');
        self::assertSame([], self::$recorded);
    }

    public function testUpdateRefusesKeyChangesAndSensitiveFilters(): void
    {
        $out = DbWrite\update_rows(['table' => 'wp_shop_items', 'set' => ['id' => 9], 'where' => ['id' => 1], 'expected_count' => 1]);
        self::assertSame('kit_db_write_pk_in_set', $out->get_error_code());

        $out = DbWrite\update_rows(['table' => 'wp_shop_items', 'set' => ['note' => 'x'], 'where' => ['api_token' => 'secret1'], 'expected_count' => 1]);
        self::assertSame('kit_db_write_sensitive_where', $out->get_error_code(), 'a count would tell whether a token exists');

        $out = DbWrite\update_rows(['table' => 'wp_shop_items', 'set' => ['note' => 'x'], 'where' => [], 'expected_count' => 1]);
        self::assertSame('kit_db_write_bad_values', $out->get_error_code(), 'an empty where would match every row');
    }

    public function testUpdateDryRunNamesRowsAndColumnsWithoutValues(): void
    {
        $out = DbWrite\update_rows(['table' => 'wp_shop_items', 'set' => ['status' => 'live', 'qty' => 5], 'where' => ['status' => 'draft'], 'expected_count' => 2, 'dry_run' => true]);

        self::assertSame(2, $out['matched']);
        self::assertSame(2, $out['would_change']);
        self::assertSame([['qty', 'status'], ['status']], array_column($out['rows'], 'columns_changing'), 'row 2 already has qty 5');
        self::assertStringNotContainsString('draft', (string) wp_json_encode($out));
        self::assertSame([], self::$recorded);
    }

    public function testUpdateWritesRecordsAndUndoesEachRow(): void
    {
        $out = DbWrite\update_rows(['table' => 'wp_shop_items', 'set' => ['status' => 'live', 'note' => null], 'where' => ['status' => 'draft', 'note' => null], 'expected_count' => 2]);

        self::assertSame(2, $out['updated']);
        self::assertSame(['live', 'live', 'live'], array_column($this->rows(), 'status'));
        $items = self::$recorded[0]['items'];
        self::assertCount(2, $items);
        self::assertSame(['status' => 'draft', 'note' => null], $items[0]['before']['row']);
        self::assertArrayNotHasKey('api_token', $items[0]['before']['row'], 'only the columns written are kept');

        self::assertTrue($this->undo(0, 0)['verified']);
        self::assertSame(['draft', 'live', 'live'], array_column($this->rows(), 'status'));
    }

    public function testUndoIsRefusedWhenTheRowChangedSince(): void
    {
        DbWrite\update_rows(['table' => 'wp_shop_items', 'set' => ['qty' => 10], 'where' => ['id' => 1], 'expected_count' => 1]);
        $this->db->query('UPDATE wp_shop_items SET qty = 11 WHERE id = 1');

        $undone = $this->undo(0, 0);
        self::assertInstanceOf(WP_Error::class, $undone);
        self::assertSame('kit_db_write_changed_since', $undone->get_error_code());
        self::assertSame('11', (string) $this->db->get_var('SELECT qty FROM wp_shop_items WHERE id = 1'), 'the later edit stays');
    }

    public function testUndoChecksTheTableAgain(): void
    {
        DbWrite\update_rows(['table' => 'wp_shop_items', 'set' => ['qty' => 10], 'where' => ['id' => 1], 'expected_count' => 1]);
        add_filter('wppilot_kit_db_protected_tables', static fn(array $t): array => [...$t, 'wp_shop_items']);

        self::assertSame('kit_db_write_table_refused', $this->undo(0, 0)->get_error_code());
    }

    public function testAFailedRowStopsTheCallAndKeepsWhatWasWritten(): void
    {
        $this->db->failUpdate = ['2'];
        $out = DbWrite\update_rows(['table' => 'wp_shop_items', 'set' => ['qty' => 0], 'where' => ['status' => 'draft'], 'expected_count' => 2]);

        self::assertSame(1, $out['updated']);
        self::assertSame(1, $out['not_updated']);
        self::assertStringContainsString('id = 2', $out['stopped']);
        self::assertCount(1, self::$recorded[0]['items'], 'the written row is recorded');
    }

    public function testDeleteNeedsConfirmationButADryRunDoesNot(): void
    {
        $base = ['table' => 'wp_shop_items', 'where' => ['status' => 'draft'], 'expected_count' => 2];

        self::assertSame('kit_confirmation_required', DbWrite\delete_rows($base)->get_error_code());
        $dry = DbWrite\delete_rows($base + ['dry_run' => true]);
        self::assertSame([1, 2], array_map('intval', $dry['ids']));
        self::assertCount(3, $this->rows());
        self::assertSame('kit_db_write_bad_expected_count', DbWrite\delete_rows(['table' => 'wp_shop_items', 'where' => ['status' => 'draft'], 'expected_count' => 51, 'confirm' => true])->get_error_code());
    }

    public function testDeleteRecordsWholeRowsAndUndoPutsThemBackWithTheirKeys(): void
    {
        $out = DbWrite\delete_rows(['table' => 'wp_shop_items', 'where' => ['status' => 'draft'], 'expected_count' => 2, 'confirm' => true]);

        self::assertSame(2, $out['deleted']);
        self::assertSame(['3'], array_column($this->rows(), 'id'));
        $before = self::$recorded[0]['items'][1]['before'];
        self::assertSame('secret2', $before['row']['api_token'], 'the whole row is kept so it can come back');

        self::assertTrue($this->undo(0, 1)['verified']);
        self::assertSame(['2', '3'], array_column($this->rows(), 'id'));
        self::assertSame('secret2', $this->db->get_var('SELECT api_token FROM wp_shop_items WHERE id = 2'));

        $this->db->insert('wp_shop_items', ['id' => 1, 'sku' => 'new']);
        self::assertSame('kit_db_write_changed_since', $this->undo(0, 0)->get_error_code(), 'a row that came back meanwhile is not overwritten');
    }

    public function testACallOverTheSnapshotBudgetWritesNothing(): void
    {
        self::$budget = 10;
        $out = DbWrite\delete_rows(['table' => 'wp_shop_items', 'where' => ['status' => 'draft'], 'expected_count' => 2, 'confirm' => true]);

        self::assertSame('kit_db_write_too_large', $out->get_error_code());
        self::assertCount(3, $this->rows());
    }

    private static function host(): Host
    {
        $ledger = new class implements Ledger {
            public function capture_for(string $ability_name, callable $capture): void
            {
            }

            public function record_items(string $ability_name, array $items, ?string $group = null): array
            {
                DbWriteTest::$recorded[] = ['ability' => $ability_name, 'items' => $items];
                return ['group' => 'g', 'change_ids' => array_map(static fn(int $i): string => 'c' . ($i + 1), array_keys($items)), 'without_before_image' => 0];
            }

            public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
            {
                DbWriteTest::$strategies[$type] = $restore;
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
                return DbWriteTest::$budget;
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
                return match ($point) {
                    'protected-table-prefixes' => ['host_'],
                    'protected-tables' => ['wp_shop_hosted'],
                    default => null,
                };
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
