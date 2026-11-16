<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot_Test_Sqlite_Wpdb;
use WPPilot_Test_State;

require_once dirname(__DIR__) . '/doubles/sqlite-wpdb.php';

/**
 * The ledger's hash chain (includes/ledger-chain.php) in table storage: rows link on insert, and
 * editing, deleting or truncating rows is found at the row where it happened, while undo, redo and
 * the prune leave the chain intact. Option storage is covered by tests/connection-safety-smoke.php.
 */
final class ChangeLogChainTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    private mixed $savedWpdb = null;

    private mixed $savedFilters = null;

    private WPPilot_Test_Sqlite_Wpdb $db;

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        $this->savedFilters = $GLOBALS['wp_filter'] ?? null;
        foreach ([
            WPPILOT_CHANGE_LOG_OPTION,
            WPPILOT_CHANGES_SCHEMA_OPTION,
            WPPILOT_CHANGES_STORAGE_OPTION,
            WPPILOT_CHANGES_MIGRATION_OPTION,
            WPPILOT_CHANGES_ERROR_OPTION,
            WPPILOT_LEDGER_CHAIN_HEAD_OPTION,
        ] as $option) {
            unset(WPPilot_Test_State::$options[$option]);
        }
        $this->db = new WPPilot_Test_Sqlite_Wpdb();
        $GLOBALS['wpdb'] = $this->db;
        $result = wppilot_changes_install_and_migrate();
        self::assertSame('table', $result['storage'], $result['error']);
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        $GLOBALS['wpdb'] = $this->savedWpdb;
        if ($this->savedFilters === null) {
            unset($GLOBALS['wp_filter']);
        } else {
            $GLOBALS['wp_filter'] = $this->savedFilters;
        }
    }

    public function testRowsLinkOnInsertAndVerify(): void
    {
        $this->store(['a', 'b']);
        $this->store(['c']);

        $rows = array_map(static fn(string $id): array => (array) wppilot_get_change($id), ['a', 'b', 'c']);
        self::assertSame(WPPILOT_LEDGER_CHAIN_GENESIS, $rows[0]['prev_hash']);
        self::assertSame($rows[0]['row_hash'], $rows[1]['prev_hash']);
        self::assertSame($rows[1]['row_hash'], $rows[2]['prev_hash']);

        $report = wppilot_ledger_chain_verify();
        self::assertTrue($report['intact'], wppilot_ledger_chain_summary($report));
        self::assertSame(3, $report['sealed']);
        self::assertSame($rows[2]['row_hash'], $report['head']);
        self::assertSame($rows[2]['row_hash'], wppilot_change_storage_status()['chain_head']);
    }

    public function testEditedRowIsReportedAtThatRow(): void
    {
        $this->store(['a', 'b', 'c']);
        $this->db->query("UPDATE wp_wppilot_changes SET input_data = 'a:0:{}' WHERE change_id = 'b'");

        $report = wppilot_ledger_chain_verify();
        self::assertFalse($report['intact']);
        self::assertSame('b', $report['breaks'][0]['id']);
        self::assertStringContainsString('changed after', $report['breaks'][0]['reason']);
    }

    public function testDeletedMiddleRowIsReportedAtTheNextRow(): void
    {
        $this->store(['a', 'b', 'c']);
        $this->db->query("DELETE FROM wp_wppilot_changes WHERE change_id = 'b'");

        $report = wppilot_ledger_chain_verify();
        self::assertFalse($report['intact']);
        self::assertSame('c', $report['breaks'][0]['id']);
        self::assertStringContainsString('deleted', $report['breaks'][0]['reason']);
    }

    public function testRemovedNewestRowIsCaughtByTheHead(): void
    {
        $this->store(['a', 'b', 'c']);
        $this->db->query("DELETE FROM wp_wppilot_changes WHERE change_id = 'c'");

        $report = wppilot_ledger_chain_verify();
        self::assertFalse($report['intact']);
        self::assertStringContainsString('newest rows were deleted', $report['breaks'][0]['reason']);
    }

    public function testRowInsertedWithoutAHashIsReported(): void
    {
        $this->store(['a']);
        $this->db->query("INSERT INTO wp_wppilot_changes (change_id, entry_data) VALUES ('forged', 'a:0:{}')");

        $report = wppilot_ledger_chain_verify();
        self::assertFalse($report['intact']);
        self::assertStringContainsString('no hash', $report['breaks'][0]['reason']);
    }

    public function testUndoStateAndPrunedOldestRowsKeepTheChainValid(): void
    {
        $this->store(['a', 'b', 'c']);
        $entry = (array) wppilot_get_change('b');
        $entry['rolled_back'] = true;
        $entry['rolled_back_at'] = gmdate('c');
        $entry['rollback_result'] = ['ok' => true];
        self::assertTrue(wppilot_replace_change('b', $entry));

        // The prune removes the oldest rows; the first survivor's link is taken on trust.
        $this->db->query("DELETE FROM wp_wppilot_changes WHERE change_id = 'a'");

        $report = wppilot_ledger_chain_verify();
        self::assertTrue($report['intact'], wppilot_ledger_chain_summary($report));
        self::assertSame(2, $report['sealed']);
    }

    public function testRowsFromBeforeTheChainAreCountedNotJudged(): void
    {
        $this->db->query("INSERT INTO wp_wppilot_changes (change_id, entry_data) VALUES ('old', 'a:0:{}')");
        $this->store(['new']);

        $report = wppilot_ledger_chain_verify();
        self::assertTrue($report['intact'], wppilot_ledger_chain_summary($report));
        self::assertSame(1, $report['unsealed']);
        self::assertSame(1, $report['sealed']);
    }

    public function testAgePruneRemovesOnlyTheLeadingRunOfOldRows(): void
    {
        $old = gmdate('c', time() - 400 * DAY_IN_SECONDS);
        $this->store(['a'], $old);
        $this->store(['b'], gmdate('c'));
        $this->store(['c'], $old);
        add_filter('wppilot_change_retention', static fn(): array => ['max_days' => 30, 'max_rows' => 0, 'snapshot_days' => 0]);

        wppilot_changes_prune();

        $ids = $this->db->get_col('SELECT change_id FROM wp_wppilot_changes ORDER BY seq');
        self::assertSame(['b', 'c'], $ids);
        self::assertTrue(wppilot_ledger_chain_verify()['intact']);
    }

    /**
     * @param list<string> $ids
     */
    private function store(array $ids, ?string $recorded_at = null): void
    {
        wppilot_store_changes(array_map(static fn(string $id): array => [
            'id' => $id,
            'kind' => 'change',
            'ability' => 'wppilot/tests-write',
            'risk' => 'write',
            'recorded_at' => $recorded_at ?? gmdate('c'),
            'user' => ['id' => 1, 'login' => 'admin'],
            'agent' => [],
            'input' => ['n' => $id],
            'result' => ['ok' => true],
            'rollback' => ['reversible' => false, 'reason' => 'Test row.'],
            'rolled_back' => false,
        ], $ids));
    }
}
