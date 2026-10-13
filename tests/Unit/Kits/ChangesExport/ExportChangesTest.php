<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\ChangesExport;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\ChangesExport;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Host;
use WPPilot\Kits\Runtime\Jobs;
use WPPilot\Kits\Runtime\Ledger;
use WPPilot\Kits\Runtime\PagedLedger;

/**
 * wppilot/export-changes against a host ledger, as the kit sees it.
 */
final class ExportChangesTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    public static array $rows = [];

    /** @var array<string, mixed> */
    public static array $lastFilters = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/changes-export/src/abilities/export-changes.php';
    }

    protected function setUp(): void
    {
        Runtime\host(self::fakeHost());
        self::$rows = [];
        for ($i = 0; $i < 12; $i++) {
            self::$rows[] = ['id' => 'r' . $i, 'input' => ['text' => str_repeat('x', 100)]];
        }
    }

    public function testTheAbilityIsRegisteredWithItsLiteralName(): void
    {
        // Asked through wp_has_ability() rather than this suite's doubles, so the test still runs
        // when scripts/export-kit.php carries it into another plugin's harness.
        self::assertTrue(wp_has_ability('wppilot/export-changes'));
    }

    public function testFiltersAreForwardedAndEmptyOnesDropped(): void
    {
        ChangesExport\export(['since' => '2026-09-01', 'agent' => 'Claude', 'ability' => '', 'user_id' => 0]);

        self::assertSame(['since' => '2026-09-01', 'agent' => 'Claude'], self::$lastFilters);
    }

    public function testPagingReportsTheNextOffsetAndTheDownloadScreen(): void
    {
        $first = ChangesExport\export(['limit' => 5]);
        self::assertSame(['r0', 'r1', 'r2', 'r3', 'r4'], array_column($first['changes'], 'id'));
        self::assertSame(5, $first['next_offset']);
        self::assertTrue($first['truncated']);
        self::assertSame('https://example.test/changes', $first['download_url']);

        $last = ChangesExport\export(['limit' => 5, 'offset' => 10]);
        self::assertSame(['r10', 'r11'], array_column($last['changes'], 'id'));
        self::assertNull($last['next_offset']);
        self::assertFalse($last['truncated']);
    }

    /**
     * Wide rows stop the page at the byte budget before the row limit, but a
     * single oversized row is still returned so paging always advances.
     */
    public function testByteBudgetEndsThePageEarly(): void
    {
        foreach (self::$rows as $index => $row) {
            self::$rows[$index]['input'] = ['text' => str_repeat('y', 100_000)];
        }

        $page = ChangesExport\export([]);

        self::assertSame(2, $page['count']);
        self::assertSame(2, $page['next_offset']);
    }

    /**
     * A host that pages in its own storage is asked for one page and a count, never for every
     * row: GoBuildWP's ledger is a table whose full rows carry before-images.
     */
    public function testAPagedLedgerIsAskedForOnePage(): void
    {
        Runtime\host(self::fakeHost(paged: true));

        $page = ChangesExport\export(['limit' => 5, 'offset' => 10, 'agent' => 'Claude']);

        self::assertSame(['r10', 'r11'], array_column($page['changes'], 'id'));
        self::assertSame(12, $page['total']);
        self::assertNull($page['next_offset']);
        self::assertSame(['agent' => 'Claude'], self::$lastFilters);
    }

    private static function fakeHost(bool $paged = false): Host
    {
        $ledger = $paged ? new class implements Ledger, PagedLedger {
            public function capture_for(string $ability_name, callable $capture): void
            {
            }

            public function record_items(string $ability_name, array $items, ?string $group = null): array
            {
                return ['group' => '', 'change_ids' => [], 'without_before_image' => 0];
            }

            public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
            {
                return true;
            }

            public function query(array $filters = []): array
            {
                throw new \LogicException('A paged ledger must not be loaded whole.');
            }

            public function query_page(array $filters, int $limit, int $offset): array
            {
                ExportChangesTest::$lastFilters = $filters;
                return array_slice(ExportChangesTest::$rows, $offset, $limit);
            }

            public function count(array $filters = []): int
            {
                return count(ExportChangesTest::$rows);
            }

            public function export_row(array $entry): array
            {
                return $entry;
            }

            public function snapshot_budget(): int
            {
                return 1;
            }

            public function download_url(): string
            {
                return 'https://example.test/changes';
            }
        } : new class implements Ledger {
            public function capture_for(string $ability_name, callable $capture): void
            {
            }

            public function record_items(string $ability_name, array $items, ?string $group = null): array
            {
                return ['group' => '', 'change_ids' => [], 'without_before_image' => 0];
            }

            public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
            {
                return true;
            }

            public function query(array $filters = []): array
            {
                ExportChangesTest::$lastFilters = $filters;
                return ExportChangesTest::$rows;
            }

            public function export_row(array $entry): array
            {
                return $entry;
            }

            public function snapshot_budget(): int
            {
                return 1;
            }

            public function download_url(): string
            {
                return 'https://example.test/changes';
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
                return 'production';
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
                return true;
            }
        };
    }
}
