<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Hosts\WPPilotHost;
use WPPilot\Tests\Unit\Kits\Multisite\RecordingAbility;
use WPPilot_Test_State;

/**
 * GoBuildWP's ability runner: what a kit ability that runs another ability goes through inside
 * GoBuildWP. It is the gate pipeline under the `nested` transport, plus the Abilities Hub switch
 * of the site the request is on at that moment.
 */
final class AbilityRunnerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedFilters = [];

    /** @var array<string, mixed> */
    private array $savedOptions = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/includes/kits/_runtime/runtime.php';
        require_once $root . '/includes/kits/_runtime/hosts/wppilot.php';
        require_once __DIR__ . '/Multisite/RecordingAbility.php';
    }

    protected function setUp(): void
    {
        $this->savedFilters = $GLOBALS['wp_filter'] ?? [];
        $this->savedOptions = WPPilot_Test_State::$options;
        unset($GLOBALS['wp_filter']['wppilot_pre_ability_execute']);
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'production';
        WPPilot_Test_State::$options['wppilot_ability_rules'] = [];
        Runtime\host(new WPPilotHost());
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp_filter'] = $this->savedFilters;
        WPPilot_Test_State::$options = $this->savedOptions;
    }

    public function testTheRunnerIsTheHostsOwnAndNotFilterable(): void
    {
        add_filter('wppilot_kit_extension', static fn(mixed $ext, string $point): string => 'swapped', 10, 2);

        self::assertIsCallable((new WPPilotHost())->extension('ability-runner'));
        self::assertSame('swapped', (new WPPilotHost())->extension('seo-providers'));
    }

    /**
     * A destructive inner ability needs its own confirm: approving the outer call is not a human
     * approval of this one.
     */
    public function testADestructiveInnerAbilityNeedsItsOwnConfirm(): void
    {
        $ability = new RecordingAbility('wppilot/delete-thing', ['annotations' => ['readonly' => false, 'destructive' => true]]);

        $refused = Runtime\run_ability($ability, ['id' => 3]);

        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('wppilot_confirmation_required', $refused->get_error_code());
        self::assertSame([], $ability->calls);

        self::assertSame(['done' => true], Runtime\run_ability($ability, ['id' => 3, 'confirm' => true]));
        self::assertSame([['id' => 3]], $ability->calls, 'confirm is stripped, as for any transport');
    }

    public function testControlsSeeTheNestedTransport(): void
    {
        $seen = [];
        add_filter('wppilot_pre_ability_execute', static function (mixed $input, \WP_Ability $ability, string $transport) use (&$seen): mixed {
            $seen[] = $transport;
            return $input;
        }, 10, 3);

        Runtime\run_ability(new RecordingAbility('wppilot/update-thing', ['annotations' => ['readonly' => false]]), ['a' => 1]);

        self::assertSame(['nested'], $seen);
    }

    public function testTheSafetyProfileOfTheCurrentSiteApplies(): void
    {
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'readonly';
        $ability = new RecordingAbility('wppilot/update-thing', ['annotations' => ['readonly' => false]]);

        $blocked = Runtime\run_ability($ability, []);

        self::assertSame('wppilot_safety_profile_blocked', $blocked->get_error_code());
        self::assertSame([], $ability->calls);
    }

    /**
     * The Hub switch is otherwise enforced only by unregistering on the site the request came
     * to; after a switch to another site, the runner reads that site's rules.
     */
    public function testTheCurrentSitesHubSwitchApplies(): void
    {
        WPPilot_Test_State::$options['wppilot_ability_rules'] = ['wppilot/update-thing' => ['disabled' => true]];
        $ability = new RecordingAbility('wppilot/update-thing', ['annotations' => ['readonly' => false]]);

        $blocked = Runtime\run_ability($ability, []);

        self::assertSame('wppilot_ability_disabled', $blocked->get_error_code());
        self::assertSame([], $ability->calls);
    }

    public function testAnAbilityWithoutAnInputSchemaIsGivenNull(): void
    {
        $ability = new RecordingAbility('wppilot/ping', ['annotations' => ['readonly' => true]]);

        Runtime\run_ability($ability, []);

        self::assertSame([null], $ability->calls);
    }
}
