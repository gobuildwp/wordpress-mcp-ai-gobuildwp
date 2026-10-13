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
 * The Abilities Hub's per-ability overrides: "always require confirmation" and
 * "minimum profile", for any ability — third-party ones are the reason they exist.
 *
 * Both only tighten. The tests that matter most are the ones proving a rule cannot
 * loosen what the ability declares for itself.
 */
final class AbilityGovernanceRulesTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'production';
        unset(WPPilot_Test_State::$options['wppilot_ability_rules']);
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
    }

    public function testRequireConfirmationGatesAnOrdinaryThirdPartyWrite(): void
    {
        $ability = new WP_Ability('rank-math/set-link-settings');
        self::assertSame(['nofollow' => true], wppilot_gate_ability_call($ability, ['nofollow' => true], 'mcp'));

        $this->rule('rank-math/set-link-settings', require_confirmation: true);

        $refused = wppilot_gate_ability_call($ability, ['nofollow' => true], 'mcp');
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('wppilot_confirmation_required', $refused->get_error_code());
        self::assertSame(['nofollow' => true], wppilot_gate_ability_call($ability, ['nofollow' => true, 'confirm' => true], 'mcp'));
        // Chat's approve button and Pro's queue still count as the confirmation.
        self::assertSame(['nofollow' => true], wppilot_gate_ability_call($ability, ['nofollow' => true], 'chat', ['human_approved' => true]));
    }

    public function testAnUntickedRuleDoesNotSwitchOffADestructiveConfirmation(): void
    {
        $this->rule('wppilot/delete-post', require_confirmation: false);

        self::assertTrue(wppilot_ability_requires_confirmation(new WP_Ability('wppilot/delete-post')));
    }

    public function testMinimumProfileOverrideBlocksBelowIt(): void
    {
        $ability = new WP_Ability('acme/export-orders', ['annotations' => ['readonly' => true]]);
        self::assertTrue(wppilot_safety_profile_allows_ability($ability));

        $this->rule('acme/export-orders', min_profile: 'developer');

        self::assertFalse(wppilot_safety_profile_allows_ability($ability));
        $refused = wppilot_gate_ability_call($ability, [], 'rest');
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('wppilot_safety_profile_blocked', $refused->get_error_code());
        $permission = wppilot_safety_filter_ability_permission(true, 'acme/export-orders', [], $ability);
        self::assertInstanceOf(WP_Error::class, $permission);

        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'developer';
        self::assertTrue(wppilot_safety_profile_allows_ability($ability));
    }

    public function testAnOverrideCannotLowerTheAbilitysOwnMinimum(): void
    {
        $ability = new WP_Ability('wppilot/db-select', [
            'annotations' => ['readonly' => true],
            'safety' => ['min_profile' => 'developer'],
        ]);
        $this->rule('wppilot/db-select', min_profile: 'production');

        self::assertSame('developer', wppilot_ability_safety_policy($ability)['min_profile']);
        self::assertFalse(wppilot_safety_profile_allows_ability($ability));
    }

    public function testRulesRoundTripAndDefaultsLeaveNoRow(): void
    {
        $rules = wppilot_set_ability_governance_rule([], 'acme/save', require_confirmation: true, min_profile: 'production');
        wppilot_update_ability_rules($rules);

        self::assertSame(
            ['acme/save' => ['require_confirmation' => true, 'min_profile' => 'production']],
            WPPilot_Test_State::$options['wppilot_ability_rules'],
        );
        self::assertSame(
            ['disabled' => false, 'require_confirmation' => true, 'min_profile' => 'production'],
            wppilot_get_ability_rules()['acme/save'],
        );

        $rules = wppilot_set_ability_governance_rule(wppilot_get_ability_rules(), 'acme/save', require_confirmation: false, min_profile: '');
        wppilot_update_ability_rules($rules);
        self::assertSame([], WPPilot_Test_State::$options['wppilot_ability_rules']);
    }

    public function testGovernanceRulesKeepAnExistingDisable(): void
    {
        wppilot_update_ability_rules(['acme/save' => ['disabled' => true]]);
        $rules = wppilot_set_ability_governance_rule(wppilot_get_ability_rules(), 'acme/save', require_confirmation: true, min_profile: '');
        wppilot_update_ability_rules($rules);

        self::assertTrue(wppilot_get_ability_rules()['acme/save']['disabled']);
        self::assertTrue(wppilot_get_ability_rules()['acme/save']['require_confirmation']);
    }

    public function testReadOnlyAndUnknownProfilesAreNotStoredAsAMinimum(): void
    {
        self::assertSame('', wppilot_set_ability_governance_rule([], 'acme/save', false, 'readonly')['acme/save']['min_profile']);
        self::assertSame('', wppilot_set_ability_governance_rule([], 'acme/save', false, 'root')['acme/save']['min_profile']);
    }

    public function testHubProtectedAbilitiesTakeNoGovernanceRule(): void
    {
        self::assertSame([], wppilot_set_ability_governance_rule([], 'mcp-adapter/execute-ability', true, 'developer'));
    }

    private function rule(string $name, bool $require_confirmation = false, string $min_profile = ''): void
    {
        wppilot_update_ability_rules(
            wppilot_set_ability_governance_rule(wppilot_get_ability_rules(), $name, $require_confirmation, $min_profile),
        );
    }
}
