<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits;

use PHPUnit\Framework\TestCase;
use WPPilot\Kits\Runtime\Hosts\WPPilotLedger;
use WPPilot\Kits\Runtime\SessionLedger;

/**
 * A kit's own restore type takes part in session undo and redo through GoBuildWP's ledger: the
 * reader it registers answers the change log's current-state and target questions for that type,
 * and only for that type.
 */
final class SessionLedgerTest extends TestCase
{
    private mixed $savedFilters = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/includes/kits/_runtime/runtime.php';
        require_once $root . '/includes/kits/_runtime/hosts/wppilot.php';
    }

    protected function setUp(): void
    {
        $this->savedFilters = $GLOBALS['wp_filter'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp_filter'] = $this->savedFilters;
    }

    public function testAKitTypeIsReadAndNamedThroughItsRegisteredReader(): void
    {
        $ledger = new WPPilotLedger();
        self::assertInstanceOf(SessionLedger::class, $ledger);
        $ledger->register_state(
            'kits/probe',
            static fn(array $snapshot): ?array => ['type' => 'kits/probe', 'id' => $snapshot['id'], 'value' => 'now'],
            static fn(array $snapshot): string => (int) $snapshot['id'] > 0 ? (string) $snapshot['id'] : '',
        );

        $rollback = ['reversible' => true, 'type' => 'kits/probe', 'snapshot' => ['type' => 'kits/probe', 'id' => 7, 'value' => 'before']];
        self::assertSame(['type' => 'kits/probe', 'id' => 7, 'value' => 'now'], wppilot_change_current_state($rollback));
        self::assertSame('kits/probe:7', wppilot_change_payload_target($rollback), 'the key is prefixed with the type, so two kits cannot collide');

        $unnamed = ['reversible' => true, 'type' => 'kits/probe', 'snapshot' => ['type' => 'kits/probe', 'id' => 0]];
        self::assertSame('', wppilot_change_payload_target($unnamed), 'a target the kit cannot name is left out of the check');

        $other = ['reversible' => true, 'type' => 'kits/other', 'snapshot' => ['id' => 7]];
        self::assertNull(wppilot_change_current_state($other), 'another type is not answered');
        self::assertSame('', wppilot_change_payload_target($other));
    }

    public function testTheFirstReaderForATypeWins(): void
    {
        $ledger = new WPPilotLedger();
        $ledger->register_state('kits/twice', static fn(array $s): ?array => ['type' => 'kits/twice', 'from' => 'first'], static fn(array $s): string => 'a');
        $ledger->register_state('kits/twice', static fn(array $s): ?array => ['type' => 'kits/twice', 'from' => 'second'], static fn(array $s): string => 'b');

        $rollback = ['reversible' => true, 'type' => 'kits/twice', 'snapshot' => []];
        self::assertSame('first', wppilot_change_current_state($rollback)['from']);
        self::assertSame('kits/twice:a', wppilot_change_payload_target($rollback));
    }
}
