<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPPilot_Test_State;

use function WPPilot\Admin\Changes\csv_cell;
use function WPPilot\Admin\Changes\csv_document;
use function WPPilot\Admin\Changes\read_filters;

/**
 * The one ledger reader behind the Changes screen, its download and the export ability.
 */
final class ChangeLogQueryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/admin/changes/changes.php';
    }

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION] = [
            $this->row('a', '2026-09-25T10:00:00+00:00', 'wppilot/update-post', ['label' => 'Claude Desktop', 'credential' => 'ap:1']),
            $this->row('b', '2026-09-26T23:30:00+00:00', 'wppilot/delete-post', ['label' => 'Cursor'], rolled_back: true),
            $this->row('c', '2026-09-27T08:00:00+00:00', 'wppilot/database-query', ['label' => 'Claude Desktop'], kind: 'audit-read'),
            $this->row('d', '2026-09-27T09:00:00+00:00', 'wppilot/update-media', ['client' => 'claude-code'], group: 'g1', reversible: false),
        ];
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
    }

    /**
     * @param array<string, string|int> $filters
     * @param list<string> $expected
     */
    #[DataProvider('filters')]
    public function testFilters(array $filters, array $expected): void
    {
        self::assertSame($expected, array_column(wppilot_query_change_log($filters), 'id'));
    }

    /** @return array<string, array{0: array<string, string|int>, 1: list<string>}> */
    public static function filters(): array
    {
        return [
            'none, newest first' => [[], ['d', 'c', 'b', 'a']],
            'ability prefix' => [['ability' => 'wppilot/update-'], ['d', 'a']],
            'agent label, case-insensitive' => [['agent' => 'claude desktop'], ['c', 'a']],
            'agent credential exact' => [['agent' => 'ap:1'], ['a']],
            'agent client name' => [['agent' => 'claude-code'], ['d']],
            'kind' => [['kind' => 'audit-read'], ['c']],
            'group' => [['group' => 'g1'], ['d']],
            'status undoable' => [['status' => 'undoable'], ['c', 'a']],
            'status rolled back' => [['status' => 'rolled-back'], ['b']],
            'bare until date includes that evening' => [['until' => '2026-09-26'], ['b', 'a']],
            'since date' => [['since' => '2026-09-27'], ['d', 'c']],
            'user' => [['user_id' => 2], ['b']],
        ];
    }

    public function testExportRowCarriesNoSnapshot(): void
    {
        $row = wppilot_change_export_row(WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION][0]);

        self::assertSame('undoable', $row['status']);
        self::assertSame('Claude Desktop', $row['agent_label']);
        self::assertStringNotContainsString('SECRET-CONTENT', (string) json_encode($row));
    }

    /**
     * Agent-written input must not become a live formula in a spreadsheet.
     */
    #[DataProvider('formulaCells')]
    public function testCsvCellDefusesFormulas(string $value, string $expected): void
    {
        self::assertSame($expected, csv_cell($value));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function formulaCells(): array
    {
        return [
            'equals' => ['=HYPERLINK("http://x")', '"\'=HYPERLINK(""http://x"")"'],
            'plus' => ['+1', '"\'+1"'],
            'minus' => ['-2+3', '"\'-2+3"'],
            'at' => ['@SUM(A1)', '"\'@SUM(A1)"'],
            'tab' => ["\t=1", "\"'\t=1\""],
            'plain' => ['wppilot/update-post', '"wppilot/update-post"'],
            'empty' => ['', '""'],
        ];
    }

    public function testCsvDocumentHasAHeaderAndOneLinePerRow(): void
    {
        $rows = array_map('wppilot_change_export_row', wppilot_query_change_log());
        $lines = explode("\r\n", trim(csv_document($rows)));

        self::assertCount(5, $lines);
        self::assertStringStartsWith("\xEF\xBB\xBF\"id\",", $lines[0]);
    }

    public function testReadFiltersDropsUnknownKeysAndValues(): void
    {
        self::assertSame(
            ['ability' => 'wppilot/update-', 'user_id' => 3],
            read_filters(['ability' => 'wppilot/update-', 'user_id' => '3', 'kind' => 'bogus', 'status' => 'x', 'page' => 'y']),
        );
    }

    /**
     * @param array<string, string> $agent
     * @return array<string, mixed>
     */
    private function row(
        string $id,
        string $at,
        string $ability,
        array $agent,
        bool $rolled_back = false,
        string $kind = 'change',
        string $group = '',
        bool $reversible = true,
    ): array {
        return [
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
    }
}
