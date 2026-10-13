<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Ability;
use WP_Error;
use WPPilot_Test_State;

/**
 * Restore paths registered from outside the ledger's built-in `match`.
 */
final class RollbackStrategyRegistryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    /** @var array<string, mixed> */
    private array $savedFilters = [];

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedFilters = $GLOBALS['wp_filter'] ?? [];
        unset(WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION]);
        $GLOBALS['wppilot_test_doing_it_wrong'] = [];
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        $GLOBALS['wp_filter'] = $this->savedFilters;
    }

    public function testTypeWithoutASlashIsRefused(): void
    {
        self::assertFalse(wppilot_register_rollback_strategy('restore-post', static fn(): array => []));
        self::assertNull(wppilot_get_rollback_strategy('restore-post'));
        self::assertCount(1, $GLOBALS['wppilot_test_doing_it_wrong']);
    }

    public function testRegisteredTypeRecordsAReversibleRowAndRestoresIt(): void
    {
        $restored = [];
        wppilot_register_rollback_strategy(
            'tests/widget-state',
            static function (array $payload, array $entry) use (&$restored): array {
                $restored = [$payload['snapshot']['value'], $entry['ability']];
                return ['verified' => true];
            },
        );
        $id = $this->recordWrite('tests/widget-state');

        $row = wppilot_get_change($id);
        self::assertTrue($row['rollback']['reversible']);
        self::assertSame('tests/widget-state', $row['rollback']['type']);

        $result = wppilot_rollback_change($id);
        self::assertIsArray($result);
        self::assertTrue($result['rolled_back']);
        self::assertSame(['old', 'wppilot/tests-set-widget'], $restored);
        self::assertTrue(wppilot_get_change($id)['rolled_back']);
    }

    public function testUnverifiedRestoreIsNotMarkedRolledBack(): void
    {
        wppilot_register_rollback_strategy('tests/unverified', static fn(): array => ['verified' => false]);
        $id = $this->recordWrite('tests/unverified');

        $result = wppilot_rollback_change($id);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_rollback_unverified', $result->get_error_code());
        self::assertFalse(wppilot_get_change($id)['rolled_back']);
    }

    public function testBuildCallbackCanDeclineAndCannotReroute(): void
    {
        wppilot_register_rollback_strategy(
            'tests/declines',
            static fn(): array => ['verified' => true],
            static fn(array $before, mixed $result): array => ['reversible' => false, 'reason' => 'Nothing was returned.'],
        );
        wppilot_register_rollback_strategy(
            'tests/reroutes',
            static fn(): array => ['verified' => true],
            static fn(array $before, mixed $result): array => ['type' => 'restore-post', 'id' => $result['id']],
        );

        $declined = wppilot_get_change($this->recordWrite('tests/declines'));
        self::assertFalse($declined['rollback']['reversible']);
        self::assertSame('Nothing was returned.', $declined['rollback']['reason']);

        $rerouted = wppilot_get_change($this->recordWrite('tests/reroutes'));
        self::assertSame('tests/reroutes', $rerouted['rollback']['type']);
        self::assertSame(9, $rerouted['rollback']['id']);
    }

    /**
     * A row recorded while its strategy's plugin was active stays undoable in
     * principle, but says why it cannot be undone now.
     */
    public function testUnregisteredTypeAtRollbackTimeExplainsItself(): void
    {
        WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION] = [[
            'id' => 'orphan',
            'ability' => 'wppilot/gone',
            'rollback' => ['reversible' => true, 'type' => 'gone/plugin-state'],
            'rolled_back' => false,
        ]];

        $result = wppilot_rollback_change('orphan');
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_rollback_unknown', $result->get_error_code());
    }

    private function recordWrite(string $type): string
    {
        remove_all_filters('wppilot_capture_before_image');
        add_filter(
            'wppilot_capture_before_image',
            static fn(mixed $before, string $ability_name): array => ['type' => $type, 'value' => 'old'],
            accepted_args: 2,
        );
        $ability = new WP_Ability('wppilot/tests-set-widget');
        wppilot_change_before('wppilot/tests-set-widget', ['value' => 'new'], $ability);
        wppilot_change_after('wppilot/tests-set-widget', ['value' => 'new'], ['id' => 9], $ability);

        $log = wppilot_get_change_log();
        return (string) end($log)['id'];
    }
}
