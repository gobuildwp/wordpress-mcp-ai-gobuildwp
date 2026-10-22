<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use WPPilot_Test_Sqlite_Wpdb;
use WPPilot_Test_State;

require_once dirname(__DIR__) . '/doubles/sqlite-wpdb.php';

/**
 * The change ledger in its own table: storage, SQL filters, migration from the option, the
 * fallback when the table cannot be used, retention, and the paths code written for the option
 * still takes.
 *
 * Runs against a $wpdb double that executes the SQL on SQLite, so a filter is proven by the rows
 * it selects and not only by the string it builds.
 */
final class ChangeLogTableTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    /** @var array<string, int> */
    private array $savedCron = [];

    private mixed $savedWpdb = null;

    private mixed $savedFilters = null;

    private WPPilot_Test_Sqlite_Wpdb $db;

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedCron = WPPilot_Test_State::$cron;
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        $this->savedFilters = $GLOBALS['wp_filter'] ?? null;
        foreach ([
            WPPILOT_CHANGE_LOG_OPTION,
            WPPILOT_CHANGES_SCHEMA_OPTION,
            WPPILOT_CHANGES_STORAGE_OPTION,
            WPPILOT_CHANGES_MIGRATION_OPTION,
            WPPILOT_CHANGES_ERROR_OPTION,
        ] as $option) {
            unset(WPPilot_Test_State::$options[$option]);
        }
        $this->db = new WPPilot_Test_Sqlite_Wpdb();
        $GLOBALS['wpdb'] = $this->db;
        $GLOBALS['wppilot_test_restored'] = [];
        wppilot_register_rollback_strategy(
            'tests/table-item',
            static function (array $payload): array {
                $GLOBALS['wppilot_test_restored'][] = $payload['snapshot']['target'];
                return ['verified' => true];
            },
        );
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        WPPilot_Test_State::$cron = $this->savedCron;
        $GLOBALS['wpdb'] = $this->savedWpdb;
        if ($this->savedFilters === null) {
            unset($GLOBALS['wp_filter']);
        } else {
            $GLOBALS['wp_filter'] = $this->savedFilters;
        }
    }

    public function testARowReadsBackExactlyAsItWasStored(): void
    {
        $this->activate();
        $meta_object = new stdClass();
        $meta_object->layout = ['columns' => 2];
        $row = [
            'kind' => 'change',
            'id' => 'row-1',
            'ability' => 'wppilot/update-post',
            'risk' => 'write',
            'recorded_at' => '2026-09-27T10:00:00+00:00',
            'duration_ms' => 12.0,
            'user' => ['id' => 3, 'login' => 'editor'],
            'agent' => ['credential' => 'ap:9', 'label' => 'Ünïcode agent', 'client' => 'cursor'],
            'input' => ['post_id' => 7, 'title' => "quote ' and backslash \\"],
            'input_sha256' => str_repeat('a', 64),
            'result' => null,
            'rollback' => [
                'reversible' => true,
                'type' => 'restore-post',
                'snapshot' => ['meta' => ['_layout' => [$meta_object]], 'post' => ['post_title' => 'Before']],
            ],
            'rolled_back' => false,
            'design' => [],
            'item_change_ids' => ['x', 'y'],
        ];

        wppilot_store_change($row);

        $stored = wppilot_get_change('row-1');
        self::assertSame(array_keys($row), array_keys((array) $stored), 'keys come back in the order they were written');
        self::assertEquals($row, $stored);
        self::assertInstanceOf(stdClass::class, $stored['rollback']['snapshot']['meta']['_layout'][0], 'an object in meta stays an object');
        self::assertSame(12.0, $stored['duration_ms']);
        self::assertEquals([$row], wppilot_get_change_log());
        self::assertArrayNotHasKey(WPPILOT_CHANGE_LOG_OPTION, WPPilot_Test_State::$options);
    }

    /**
     * A binary meta value in a before-image, or an emoji where the table is utf8, would make the
     * database refuse the whole INSERT.
     */
    public function testBinaryAndFourByteTextRoundTrip(): void
    {
        $this->activate();
        $binary = "\xFF\xFE\x00png-bytes\x80";
        $row = $this->row('binary', rollback: [
            'reversible' => true,
            'type' => 'tests/table-item',
            'snapshot' => ['target' => 'x', 'meta' => ['_thumb' => $binary]],
        ]);
        $row['agent'] = ['label' => "Agent \u{1F680} \xC3", 'client' => 'cli'];

        wppilot_store_change($row);

        self::assertSame($row, wppilot_get_change('binary'));
        self::assertStringStartsWith('b64:', (string) $this->db->get_var("SELECT rollback_data FROM wp_wppilot_changes WHERE change_id = 'binary'"));
        self::assertTrue(wppilot_change_table_active(), 'nothing fell back');
        self::assertSame(['binary'], array_column(wppilot_query_change_log(['agent' => 'agent']), 'id'));
    }

    public function testARowWithoutAnIdIsGivenOne(): void
    {
        $this->activate();
        wppilot_store_changes([['ability' => 'wppilot/one'], ['ability' => 'wppilot/one']]);

        $ids = array_column(wppilot_query_change_log(), 'id');
        self::assertCount(2, $ids);
        self::assertNotSame($ids[0], $ids[1]);
    }

    /**
     * Every filter selects, in SQL, exactly the rows the option reader selects.
     *
     * @param array<string, string|int> $filters
     */
    #[DataProvider('filters')]
    public function testFiltersInSqlMatchTheOptionReader(array $filters): void
    {
        WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION] = $this->queryRows();
        $expected = array_column(wppilot_query_change_log($filters), 'id');
        self::assertNotSame([], $expected, 'the data set exercises this filter');

        $this->activate();
        $this->db->queries = [];
        $actual = array_column(wppilot_query_change_log($filters), 'id');

        self::assertSame($expected, $actual);
        self::assertSame(count($expected), wppilot_count_change_log($filters));
        $select = $this->db->queries[0];
        self::assertStringContainsString(' WHERE ', $select, 'the filter runs in the database');
        self::assertStringEndsWith('ORDER BY seq DESC', $select);
    }

    /** @return array<string, array{0: array<string, string|int>}> */
    public static function filters(): array
    {
        return [
            'ability prefix' => [['ability' => 'wppilot/update-']],
            'agent label, case-insensitive' => [['agent' => 'claude desktop']],
            'agent credential exact' => [['agent' => 'AP:1']],
            'agent client name' => [['agent' => 'claude-code']],
            'kind' => [['kind' => 'audit-read']],
            'group' => [['group' => 'g1']],
            'status undoable' => [['status' => 'undoable']],
            'status rolled back' => [['status' => 'rolled-back']],
            'status not reversible' => [['status' => 'not-reversible']],
            'bare until date includes that evening' => [['until' => '2026-09-26']],
            'since date' => [['since' => '2026-09-27']],
            'since and until with offsets' => [['since' => '2026-09-26T20:00:00-05:00', 'until' => '2026-09-27T08:30:00Z']],
            'user' => [['user_id' => 2]],
            'combined' => [['agent' => 'claude', 'status' => 'undoable', 'kind' => 'change']],
        ];
    }

    public function testFilterSqlIsPlaceholdersOnly(): void
    {
        [$where, $args] = wppilot_change_filter_sql([
            'ability' => "wppilot/x' OR 1=1 --",
            'agent' => ' Claude%_ ',
            'user_id' => '4',
            'since' => '2026-09-27',
            'bogus' => 'ignored',
        ]);

        self::assertSame(
            ' WHERE INSTR(ability, %s) = 1 AND user_id = %d'
            . ' AND (LOWER(agent_credential) = %s OR INSTR(LOWER(agent_label), %s) > 0 OR INSTR(LOWER(agent_client), %s) > 0)'
            . ' AND recorded_at >= %s',
            $where,
        );
        self::assertSame(["wppilot/x' OR 1=1 --", 4, 'claude%_', 'claude%_', 'claude%_', '2026-09-27 00:00:00'], $args);
        self::assertSame(['', []], wppilot_change_filter_sql([]));
    }

    public function testWildcardCharactersInAFilterAreLiteral(): void
    {
        WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION] = $this->queryRows();
        $this->activate();

        self::assertSame([], wppilot_query_change_log(['agent' => '%']));
        self::assertSame([], wppilot_query_change_log(['ability' => '_ppilot']));
    }

    public function testQueryPagesNewestFirstInSql(): void
    {
        $this->activate();
        foreach (range(1, 7) as $n) {
            wppilot_store_change($this->row("r{$n}"));
        }

        self::assertSame(['r4', 'r3', 'r2'], array_column(wppilot_query_change_log([], 3, 3), 'id'));
        self::assertSame(['r1'], array_column(wppilot_query_change_log([], 3, 6), 'id'));
        self::assertStringContainsString('LIMIT 3 OFFSET 6', (string) end($this->db->queries));
        self::assertSame(7, wppilot_count_change_log());
    }

    public function testMigrationCopiesInBatchesResumesAndSwitchesOnlyWhenVerified(): void
    {
        $rows = [];
        foreach (range(1, 120) as $n) {
            $rows[] = $this->row("m{$n}", recorded_at: gmdate('c', 1_790_000_000 + $n));
        }
        $rows[] = ['ability' => 'wppilot/no-id', 'recorded_at' => '2026-09-01T00:00:00+00:00'];
        WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION] = $rows;

        $first = wppilot_changes_install_and_migrate(max_batches: 1);
        self::assertSame(['storage' => 'option', 'copied' => 50, 'done' => false, 'error' => ''], $first);
        self::assertSame('m50', WPPilot_Test_State::$options[WPPILOT_CHANGES_MIGRATION_OPTION]['cursor']);
        self::assertFalse(wppilot_change_table_active());
        self::assertCount(121, wppilot_get_change_log(), 'reads stay on the option until the copy is verified');

        // A request that died after copying a batch but before saving its place repeats that batch.
        WPPilot_Test_State::$options[WPPILOT_CHANGES_MIGRATION_OPTION]['cursor'] = 'm30';
        $second = wppilot_changes_install_and_migrate(max_batches: 1);
        self::assertSame(80, $second['copied'], 'the 20 rows already copied are not copied again');

        $done = wppilot_changes_install_and_migrate();
        self::assertTrue($done['done']);
        self::assertTrue(wppilot_change_table_active());
        self::assertSame('121', $this->db->get_var('SELECT COUNT(*) FROM wp_wppilot_changes'));
        self::assertArrayNotHasKey(WPPILOT_CHANGE_LOG_OPTION, WPPilot_Test_State::$options);
        self::assertArrayNotHasKey(WPPILOT_CHANGES_MIGRATION_OPTION, WPPilot_Test_State::$options);
        self::assertArrayHasKey(WPPILOT_CHANGES_PRUNE_HOOK, WPPilot_Test_State::$cron);

        $log = wppilot_get_change_log();
        self::assertSame(array_merge(array_map(static fn(int $n): string => "m{$n}", range(1, 120))), array_slice(array_column($log, 'id'), 0, 120), 'order is kept');
        self::assertStringStartsWith('legacy-', $log[120]['id'], 'a row without an id is given a stable one');

        $again = wppilot_changes_install_and_migrate();
        self::assertSame(['storage' => 'table', 'copied' => 0, 'done' => true, 'error' => ''], $again);

        $read_back = array_values(array_filter($this->db->queries, static fn(string $q): bool => str_ends_with($q, ' LIMIT 1') && str_starts_with($q, 'SELECT seq, change_id,')));
        self::assertNotSame([], $read_back, 'the schema is proven by reading every column back');
        self::assertStringEndsWith(' FROM `wp_wppilot_changes` LIMIT 1', $read_back[0], 'the table name is bound as a %i identifier');
    }

    public function testAMigrationThatLostItsPlaceStartsOverWithoutDuplicates(): void
    {
        WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION] = [$this->row('a'), $this->row('b'), $this->row('c')];
        wppilot_changes_install_and_migrate(max_batches: 0);
        wppilot_change_table_insert([$this->row('a'), $this->row('b')]);
        WPPilot_Test_State::$options[WPPILOT_CHANGES_MIGRATION_OPTION] = ['cursor' => 'evicted-long-ago', 'copied' => 2];

        wppilot_changes_install_and_migrate();

        self::assertTrue(wppilot_change_table_active());
        self::assertSame(['a', 'b', 'c'], array_column(wppilot_get_change_log(), 'id'));
    }

    public function testNoCreatePrivilegeKeepsTheOptionAndSaysWhy(): void
    {
        $this->db->deny_create = true;
        WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION] = [$this->row('old')];

        $result = wppilot_changes_install_and_migrate();

        self::assertSame('option', $result['storage']);
        self::assertStringContainsString('CREATE command denied', $result['error']);
        wppilot_store_change($this->row('new'));
        self::assertSame(['old', 'new'], array_column(WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION], 'id'));
        self::assertSame(['new', 'old'], array_column(wppilot_query_change_log(), 'id'));

        $status = wppilot_change_storage_status();
        self::assertSame('option', $status['storage']);
        self::assertSame('create', $status['error']['stage']);
        self::assertSame(2, $status['rows']);

        // Not retried on every request...
        $this->db->queries = [];
        wppilot_changes_maybe_upgrade();
        self::assertSame([], $this->db->queries);

        // ...but an hour later, and with the privilege granted the history moves across.
        $this->db->deny_create = false;
        WPPilot_Test_State::$options[WPPILOT_CHANGES_ERROR_OPTION]['at'] = time() - WPPILOT_CHANGES_RETRY_SECONDS - 1;
        wppilot_changes_maybe_upgrade();
        self::assertTrue(wppilot_change_table_active());
        self::assertSame(['new', 'old'], array_column(wppilot_query_change_log(), 'id'));
        self::assertNull(wppilot_change_storage_status()['error']);
    }

    public function testARefusedInsertKeepsTheRowAndTheNextMigrationMergesIt(): void
    {
        $this->activate();
        wppilot_store_change($this->row('before'));
        $this->db->fail_inserts = true;

        wppilot_store_change($this->row('during'));

        self::assertFalse(wppilot_change_table_active(), 'storage falls back');
        self::assertSame(['during'], array_column(WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION], 'id'));
        self::assertSame('write', wppilot_change_storage_status()['error']['stage']);

        $this->db->fail_inserts = false;
        WPPilot_Test_State::$options[WPPILOT_CHANGES_ERROR_OPTION]['at'] = 0;
        wppilot_changes_maybe_upgrade();

        self::assertTrue(wppilot_change_table_active());
        self::assertSame(['during', 'before'], array_column(wppilot_query_change_log(), 'id'));
    }

    /**
     * Two requests recording at once each used to read the log, append and write it back, and the
     * slower write erased the faster one's row. A row is now an insert that reads nothing.
     */
    public function testARowWrittenByAnotherRequestMidWriteIsKept(): void
    {
        $this->activate();
        add_filter('wppilot_change_rows_before_store', static function (array $rows): array {
            static $once = false;
            if (!$once) {
                $once = true;
                // Another request records its row between this one's start and its write.
                wppilot_store_change(['id' => 'other-request', 'ability' => 'wppilot/other', 'recorded_at' => gmdate('c')]);
            }
            return $rows;
        });

        wppilot_store_changes([$this->row('this-request')]);

        self::assertSame(['this-request', 'other-request'], array_column(wppilot_query_change_log(), 'id'));
    }

    public function testGroupRollbackMarksRowsInTheTable(): void
    {
        $this->activate();
        $recorded = wppilot_ledger_record_items('wppilot/tests-bulk', [$this->item('a'), $this->item('b')]);
        wppilot_ledger_record_items('wppilot/tests-bulk', [$this->item('other')]);

        $result = wppilot_rollback_group($recorded['group']);

        self::assertIsArray($result);
        self::assertSame(2, $result['rolled_back']);
        self::assertSame(['b', 'a'], $GLOBALS['wppilot_test_restored'], 'newest first');
        self::assertSame(
            array_reverse($recorded['change_ids']),
            array_column(wppilot_query_change_log(['status' => 'rolled-back']), 'id'),
        );
        self::assertSame(['wppilot/tests-bulk' => 1], array_count_values(array_column(
            wppilot_query_change_log(['status' => 'undoable']),
            'ability',
        )));
        self::assertSame([$recorded['group'] => 2], wppilot_change_group_sizes([$recorded['group'], 'none']));
        self::assertTrue(wppilot_get_change($recorded['change_ids'][0])['rolled_back']);
        self::assertSame(2, wppilot_rollback_group($recorded['group'])['skipped']);
    }

    public function testReplaceReportsFoundEvenWhenNothingChanged(): void
    {
        $this->activate();
        wppilot_store_change($this->row('same'));

        self::assertTrue(wppilot_replace_change('same', (array) wppilot_get_change('same')));
        self::assertFalse(wppilot_replace_change('missing', $this->row('missing')));
    }

    public function testTheRecentLogKeepsTheOptionsOldBounds(): void
    {
        $this->activate();
        $rows = [];
        foreach (range(1, WPPILOT_CHANGE_LOG_MAX + 10) as $n) {
            $rows[] = $this->row("n{$n}");
        }
        wppilot_store_changes($rows);

        $log = wppilot_get_change_log();
        self::assertCount(WPPILOT_CHANGE_LOG_MAX, $log);
        self::assertSame('n' . (WPPILOT_CHANGE_LOG_MAX + 10), $log[count($log) - 1]['id'], 'the newest row is last');
        self::assertSame(WPPILOT_CHANGE_LOG_MAX + 10, wppilot_count_change_log(), 'nothing was evicted');

        $heavy = str_repeat('x', 1_600_000);
        foreach (['h1', 'h2', 'h3'] as $id) {
            wppilot_store_change($this->row($id, input: ['pad' => $heavy]));
        }
        self::assertSame(['h2', 'h3'], array_column(wppilot_get_change_log(), 'id'), 'at most 4 MB is loaded');
    }

    public function testAWriteToTheOldOptionIsTakenIntoTheTable(): void
    {
        $this->activate();
        wppilot_store_change($this->row('free-row'));

        // What GoBuildWP Pro's WooCommerce bulk ledger does: read the log, append, save the option.
        $log = wppilot_get_change_log();
        $log[] = $this->row('pro-row');
        $written = wppilot_change_intercept_option_write($log, false);

        self::assertFalse($written, 'the option write is cancelled');
        self::assertSame(['pro-row', 'free-row'], array_column(wppilot_query_change_log(), 'id'));
    }

    /**
     * GoBuildWP Pro annotates the row being stored from `pre_update_option_wppilot_change_log`.
     */
    public function testTheOptionsPreUpdateFilterStillSeesNewRows(): void
    {
        $this->activate();
        $seen = [];
        add_filter(
            'pre_update_option_' . WPPILOT_CHANGE_LOG_OPTION,
            static function (mixed $value, mixed $old_value) use (&$seen): mixed {
                $seen[] = [array_column($value, 'id'), $old_value];
                $index = array_key_last($value);
                $value[$index]['rollback'] = ['reversible' => false, 'reason' => 'Pro knows why.'];
                return $value;
            },
            10,
            2,
        );
        add_filter('pre_update_option_' . WPPILOT_CHANGE_LOG_OPTION, 'wppilot_change_intercept_option_write', PHP_INT_MAX, 2);

        wppilot_store_change($this->row('annotated'));

        self::assertSame([[['annotated'], []]], $seen);
        self::assertSame('Pro knows why.', wppilot_get_change('annotated')['rollback']['reason']);
        self::assertSame(['annotated'], array_column(wppilot_query_change_log(['status' => 'not-reversible']), 'id'), 'the status column follows the annotation');
    }

    public function testPruneDropsOldImagesFirstThenOldAndSurplusRows(): void
    {
        $this->activate();
        $now = strtotime('2026-09-28T00:00:00Z');
        $day = DAY_IN_SECONDS;
        $big = ['reversible' => true, 'type' => 'tests/table-item', 'snapshot' => ['target' => str_repeat('s', 5000)]];
        wppilot_store_changes([
            $this->row('ancient', recorded_at: gmdate('c', $now - 100 * $day), rollback: $big),
            $this->row('old-image', recorded_at: gmdate('c', $now - 40 * $day), rollback: $big),
            $this->row('old-small', recorded_at: gmdate('c', $now - 40 * $day), rollback: ['reversible' => true, 'type' => 'delete-created-post', 'post_id' => 4]),
            $this->row('recent', recorded_at: gmdate('c', $now - 2 * $day), rollback: $big),
        ]);

        $summary = wppilot_changes_prune($now);

        self::assertSame(['snapshots_pruned' => 2, 'deleted' => 1], $summary, 'ancient loses its image, then the row');
        self::assertSame(['recent', 'old-small', 'old-image'], array_column(wppilot_query_change_log(), 'id'));
        $pruned = wppilot_get_change('old-image');
        self::assertFalse($pruned['rollback']['reversible']);
        self::assertStringContainsString('removed 30 days after the change', $pruned['rollback']['reason']);
        self::assertSame('not-reversible', wppilot_change_status($pruned));
        self::assertSame(['old-image'], array_column(wppilot_query_change_log(['status' => 'not-reversible']), 'id'));
        self::assertTrue(wppilot_get_change('old-small')['rollback']['reversible'], 'a payload without an image stays undoable');
        self::assertTrue(wppilot_get_change('recent')['rollback']['reversible']);

        add_filter('wppilot_change_retention', static fn(array $policy): array => ['max_rows' => 1] + $policy);
        self::assertSame(2, wppilot_changes_prune($now)['deleted']);
        self::assertSame(['recent'], array_column(wppilot_query_change_log(), 'id'));
    }

    public function testPruneFinishesAMigrationThatDidNotDeleteTheOption(): void
    {
        $this->activate();
        wppilot_store_change($this->row('in-table'));
        WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION] = [$this->row('in-table'), $this->row('only-in-option')];

        wppilot_changes_prune();

        self::assertArrayNotHasKey(WPPILOT_CHANGE_LOG_OPTION, WPPilot_Test_State::$options);
        self::assertSame(['only-in-option', 'in-table'], array_column(wppilot_query_change_log(), 'id'));
    }

    private function activate(): void
    {
        $result = wppilot_changes_install_and_migrate();
        self::assertSame('table', $result['storage'], $result['error']);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $rollback
     * @return array<string, mixed>
     */
    private function row(string $id, ?string $recorded_at = null, array $input = [], ?array $rollback = null): array
    {
        return [
            'id' => $id,
            'kind' => 'change',
            'ability' => 'wppilot/tests-write',
            'risk' => 'write',
            'recorded_at' => $recorded_at ?? gmdate('c'),
            'user' => ['id' => 1, 'login' => 'admin'],
            'agent' => [],
            'input' => $input,
            'rollback' => $rollback ?? ['reversible' => false, 'reason' => 'Test row.'],
            'rolled_back' => false,
        ];
    }

    /**
     * @return array{input: array<string, mixed>, before: array<string, mixed>, result: array<string, mixed>, item: array<string, mixed>}
     */
    private function item(string $target): array
    {
        return [
            'input' => ['target' => $target],
            'before' => ['type' => 'tests/table-item', 'target' => $target],
            'result' => ['ok' => true],
            'item' => ['post_id' => 1],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function queryRows(): array
    {
        $row = static fn(
            string $id,
            string $at,
            string $ability,
            array $agent,
            bool $rolled_back = false,
            string $kind = 'change',
            string $group = '',
            bool $reversible = true,
        ): array => [
            'id' => $id,
            'kind' => $kind,
            'group' => $group,
            'ability' => $ability,
            'risk' => 'write',
            'recorded_at' => $at,
            'user' => ['id' => $rolled_back ? 2 : 1, 'login' => 'admin'],
            'agent' => $agent,
            'input' => ['post_id' => 1],
            'rollback' => $reversible
                ? ['reversible' => true, 'type' => 'restore-post', 'snapshot' => ['content' => 'SECRET-CONTENT']]
                : ['reversible' => false, 'reason' => 'No supported before-image.'],
            'rolled_back' => $rolled_back,
        ];

        return [
            $row('a', '2026-09-25T10:00:00+00:00', 'wppilot/update-post', ['label' => 'Claude Desktop', 'credential' => 'ap:1']),
            $row('b', '2026-09-26T23:30:00+00:00', 'wppilot/delete-post', ['label' => 'Cursor'], rolled_back: true),
            $row('c', '2026-09-27T08:00:00+00:00', 'wppilot/database-query', ['label' => 'Claude Desktop'], kind: 'audit-read'),
            $row('d', '2026-09-27T09:00:00+00:00', 'wppilot/update-media', ['client' => 'claude-code'], group: 'g1', reversible: false),
            $row('e', 'not a date', 'wppilot/update-post', ['label' => 'Claude Desktop']),
        ];
    }
}
