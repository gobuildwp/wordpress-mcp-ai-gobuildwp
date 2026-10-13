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
 * The one sequence every execution path runs before WP_Ability::execute().
 *
 * Before 1.14.0 each transport carried its own copy and Chat carried none, so
 * these are the promises the copies used to make separately.
 */
final class GatePipelineTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedFilters = [];

    /** @var array<string, mixed> */
    private array $savedOptions = [];

    protected function setUp(): void
    {
        $this->savedFilters = $GLOBALS['wp_filter'] ?? [];
        $this->savedOptions = WPPilot_Test_State::$options;
        unset($GLOBALS['wp_filter']['wppilot_pre_ability_execute']);
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'production';
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp_filter'] = $this->savedFilters;
        WPPilot_Test_State::$options = $this->savedOptions;
    }

    public function testDestructiveCallWithoutConfirmIsRefused(): void
    {
        $result = wppilot_gate_ability_call($this->destructive(), ['post_id' => 7], 'rest');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_confirmation_required', $result->get_error_code());
    }

    public function testConfirmIsStrippedWhenTheAbilityDoesNotDeclareIt(): void
    {
        $result = wppilot_gate_ability_call($this->destructive(), ['post_id' => 7, 'confirm' => true], 'mcp');

        self::assertSame(['post_id' => 7], $result);
    }

    public function testConfirmIsKeptWhenTheAbilityDeclaresIt(): void
    {
        $ability = new WP_Ability('wppilot/delete-post', [], [
            'type' => 'object',
            'properties' => ['post_id' => ['type' => 'integer'], 'confirm' => ['type' => 'boolean']],
        ]);

        self::assertSame(
            ['post_id' => 7, 'confirm' => true],
            wppilot_gate_ability_call($ability, ['post_id' => 7, 'confirm' => true], 'rest'),
        );
    }

    /**
     * Chat's approve button and Pro's queue are the confirmation; the model
     * cannot set this context, only the server-side caller can.
     */
    public function testHumanApprovalStandsInForConfirm(): void
    {
        $result = wppilot_gate_ability_call(
            $this->destructive(),
            ['post_id' => 7],
            'chat',
            ['human_approved' => true],
        );

        self::assertSame(['post_id' => 7], $result);
    }

    public function testHumanApprovalDoesNotOverrideTheSafetyProfile(): void
    {
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'readonly';

        $result = wppilot_gate_ability_call(
            $this->destructive(),
            ['post_id' => 7],
            'approval',
            ['human_approved' => true],
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_safety_profile_blocked', $result->get_error_code());
    }

    /**
     * The profile refuses before any filter runs, so a forbidden call never
     * spends a rate-limit token or lands in an approval queue.
     */
    public function testProfileRefusalRunsNoFilter(): void
    {
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'readonly';
        $ran = false;
        add_filter('wppilot_pre_ability_execute', static function (mixed $input) use (&$ran): mixed {
            $ran = true;
            return $input;
        });

        wppilot_gate_ability_call(new WP_Ability('wppilot/update-post'), ['post_id' => 7], 'rest');

        self::assertFalse($ran);
    }

    public function testFiltersReceiveTransportAndContextAndCanRefuse(): void
    {
        $seen = [];
        add_filter(
            'wppilot_pre_ability_execute',
            static function (mixed $input, WP_Ability $ability, string $transport, array $context) use (&$seen): mixed {
                $seen = [$ability->get_name(), $transport, $context];
                return new WP_Error('held', 'Held for review.');
            },
            priority: 9,
            accepted_args: 4,
        );

        $result = wppilot_gate_ability_call(
            new WP_Ability('wppilot/update-post'),
            ['post_id' => 7],
            'chat',
            ['human_approved' => true],
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('held', $result->get_error_code());
        self::assertSame(['wppilot/update-post', 'chat', ['human_approved' => true]], $seen);
    }

    public function testFiltersRegisteredWithThreeArgumentsStillWork(): void
    {
        add_filter(
            'wppilot_pre_ability_execute',
            static fn(mixed $input, WP_Ability $ability, string $transport): mixed => ['via' => $transport],
            priority: 6,
            accepted_args: 3,
        );

        self::assertSame(
            ['via' => 'mcp'],
            wppilot_gate_ability_call(new WP_Ability('wppilot/update-post'), ['post_id' => 7], 'mcp'),
        );
    }

    public function testReadOnlyCallPassesUntouchedOnReadonlyProfile(): void
    {
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'readonly';
        $ability = new WP_Ability('wppilot/get-post', ['annotations' => ['readonly' => true]]);

        self::assertSame(['post_id' => 7], wppilot_gate_ability_call($ability, ['post_id' => 7], 'rest'));
    }

    private function destructive(): WP_Ability
    {
        return new WP_Ability('wppilot/delete-post', [], [
            'type' => 'object',
            'properties' => ['post_id' => ['type' => 'integer']],
        ]);
    }
}
