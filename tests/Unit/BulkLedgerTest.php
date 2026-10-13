<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot_Test_State;

/**
 * Per-item ledger rows for bulk writes, grouped so a batch undoes as one.
 */
final class BulkLedgerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    private mixed $savedWpdb = null;

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        unset(WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION]);
        $GLOBALS['wppilot_test_restored'] = [];
        wppilot_register_rollback_strategy(
            'tests/bulk-item',
            static function (array $payload): array {
                $GLOBALS['wppilot_test_restored'][] = $payload['snapshot']['target'];
                return ['verified' => $payload['snapshot']['target'] !== 'broken'];
            },
        );
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        $GLOBALS['wpdb'] = $this->savedWpdb;
    }

    public function testEachItemGetsItsOwnRowUnderOneGroupAndTheAggregateIsDropped(): void
    {
        wppilot_change_pending('wppilot/tests-bulk', ['started_at' => 0.0]);

        $recorded = wppilot_ledger_record_items('wppilot/tests-bulk', [
            $this->item('a'),
            $this->item('b'),
        ]);

        self::assertNull(wppilot_change_pending('wppilot/tests-bulk'));
        $log = wppilot_get_change_log();
        self::assertCount(2, $log);
        self::assertSame($recorded['change_ids'], array_column($log, 'id'));
        self::assertSame([$recorded['group'], $recorded['group']], array_column($log, 'group'));
        self::assertSame(['post_id' => 1], $log[0]['bulk_item']);
        self::assertTrue($log[1]['rollback']['reversible']);
        self::assertSame(0, $recorded['without_before_image']);
    }

    public function testItemsPastTheSnapshotBudgetAreRecordedAsIrreversible(): void
    {
        $heavy = str_repeat('x', 700_000);

        $recorded = wppilot_ledger_record_items('wppilot/tests-bulk', [
            $this->item('a', $heavy),
            $this->item('b', $heavy),
            $this->item('c'),
        ]);

        $log = wppilot_get_change_log();
        self::assertTrue($log[0]['rollback']['reversible']);
        self::assertFalse($log[1]['rollback']['reversible']);
        self::assertStringContainsString('snapshot budget', $log[1]['rollback']['reason']);
        self::assertTrue($log[2]['rollback']['reversible'], 'a small item after a refused heavy one still fits');
        self::assertSame(1, $recorded['without_before_image']);
    }

    public function testGroupRollbackUndoesNewestFirstAndReportsEachRow(): void
    {
        $first = wppilot_ledger_record_items('wppilot/tests-bulk', [$this->item('a'), $this->item('broken')]);
        $first_ids = $first['change_ids'];
        wppilot_ledger_record_items('wppilot/tests-bulk', [$this->item('other')]);

        $result = wppilot_rollback_group($first['group']);

        self::assertIsArray($result);
        self::assertSame(['broken', 'a'], $GLOBALS['wppilot_test_restored']);
        self::assertSame(1, $result['rolled_back']);
        self::assertSame(1, $result['failed']);
        self::assertSame(['failed', 'rolled_back'], array_column($result['results'], 'status'));
        self::assertSame([$first_ids[1], $first_ids[0]], array_column($result['results'], 'change_id'));

        $again = wppilot_rollback_group($first['group']);
        self::assertSame(1, $again['skipped'], 'the row already undone is skipped, not undone twice');
    }

    public function testUnknownGroupIsAnError(): void
    {
        $result = wppilot_rollback_group('no-such-group');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_change_group_not_found', $result->get_error_code());
    }

    /**
     * The read and the write happen inside one named lock, released even when
     * the write throws, so a concurrent request cannot slip its row in between.
     */
    public function testLedgerWritesHappenUnderANamedLock(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';

            /** @var list<string> */
            public array $queries = [];

            public function prepare(string $query, mixed ...$args): string
            {
                return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $query), $args);
            }

            public function get_var(string $query): string
            {
                $this->queries[] = $query;
                return '1';
            }
        };
        $GLOBALS['wpdb'] = $wpdb;

        wppilot_store_changes([['id' => 'one'], ['id' => 'two']]);

        self::assertSame(
            ["SELECT GET_LOCK('wp_wppilot_change_log', 5)", "SELECT RELEASE_LOCK('wp_wppilot_change_log')"],
            $wpdb->queries,
        );
        self::assertSame(['one', 'two'], array_column(wppilot_get_change_log(), 'id'));
    }

    /**
     * @return array{input: array<string, mixed>, before: array<string, mixed>, result: array<string, mixed>, item: array<string, mixed>}
     */
    private function item(string $target, string $pad = ''): array
    {
        return [
            'input' => ['target' => $target],
            'before' => ['type' => 'tests/bulk-item', 'target' => $target, 'pad' => $pad],
            'result' => ['ok' => true],
            'item' => ['post_id' => 1],
        ];
    }
}
