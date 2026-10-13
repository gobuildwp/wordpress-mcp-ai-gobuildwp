<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WP_Ability;
use WP_Error;
use WPPilot_Test_State;

/**
 * GoBuildWP's approvals for writes through other plugins' MCP servers (includes/foreign-gate.php).
 *
 * REST_REQUEST is a constant, so the tests that need it run in their own process.
 */
final class ForeignGateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists('WP_Filter_Sentinel')) {
            eval('final class WP_Filter_Sentinel {}');
        }
        require_once dirname(__DIR__, 2) . '/includes/foreign-gate.php';
    }

    protected function setUp(): void
    {
        unset(WPPilot_Test_State::$options[WPPILOT_FOREIGN_GATE_OPTION], $GLOBALS['wppilot_gate_passes']);
        WPPilot_Test_State::$current_user_id = 0;
    }

    private static function write(string $name = 'elementor/manage-elements', bool $destructive = true): WP_Ability
    {
        return new WP_Ability($name, ['annotations' => ['readonly' => false, 'destructive' => $destructive]]);
    }

    public function test_off_by_default_and_outside_rest_it_changes_nothing(): void
    {
        $sentinel = new \WP_Filter_Sentinel();
        self::assertFalse(wppilot_foreign_gate_enabled());
        self::assertSame($sentinel, wppilot_foreign_gate($sentinel, 'elementor/manage-elements', [], self::write()));

        wppilot_foreign_gate_save_setting([WPPILOT_FOREIGN_GATE_OPTION => 'on']);
        self::assertTrue(wppilot_foreign_gate_enabled());
        // No REST_REQUEST here: a plugin's own code running an ability is left alone.
        self::assertSame($sentinel, wppilot_foreign_gate($sentinel, 'elementor/manage-elements', [], self::write()));
    }

    public function test_another_filters_short_circuit_is_respected(): void
    {
        self::assertSame('cached', wppilot_foreign_gate('cached', 'elementor/manage-elements', [], self::write()));
    }

    public function test_each_pass_from_wppilots_gate_is_used_once(): void
    {
        wppilot_gate_mark_passed('woocommerce/product-delete');
        self::assertTrue(wppilot_gate_take_pass('woocommerce/product-delete'));
        self::assertFalse(wppilot_gate_take_pass('woocommerce/product-delete'));
    }

    public function test_the_setting_only_stores_on_or_off(): void
    {
        wppilot_foreign_gate_save_setting([WPPILOT_FOREIGN_GATE_OPTION => 'sure']);
        self::assertSame('off', WPPilot_Test_State::$options[WPPILOT_FOREIGN_GATE_OPTION]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_over_rest_a_destructive_write_needs_a_signed_in_person_and_reads_pass(): void
    {
        define('REST_REQUEST', true);
        wppilot_foreign_gate_save_setting([WPPILOT_FOREIGN_GATE_OPTION => 'on']);
        $sentinel = new \WP_Filter_Sentinel();

        $read = new WP_Ability('woocommerce/products-query', ['annotations' => ['readonly' => true]]);
        self::assertSame($sentinel, wppilot_foreign_gate($sentinel, 'woocommerce/products-query', [], $read), 'reads are never held');

        $refused = wppilot_foreign_gate($sentinel, 'woocommerce/product-delete', ['id' => 5], self::write('woocommerce/product-delete'));
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('wppilot_human_confirmation_unavailable', $refused->get_error_code());

        // The same call after GoBuildWP's own gate passed it is not held a second time.
        wppilot_gate_mark_passed('woocommerce/product-delete');
        self::assertSame($sentinel, wppilot_foreign_gate($sentinel, 'woocommerce/product-delete', ['id' => 5], self::write('woocommerce/product-delete')));
    }
}
