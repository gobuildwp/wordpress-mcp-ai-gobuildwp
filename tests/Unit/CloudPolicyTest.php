<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot_Test_State;

require_once dirname(__DIR__) . '/doubles/admin.php';
require_once dirname(__DIR__) . '/doubles/mcp-surface.php';
require_once dirname(__DIR__) . '/doubles/sqlite-wpdb.php';
require_once dirname(__DIR__) . '/doubles/cloud.php';
require_once dirname(__DIR__, 2) . '/includes/capabilities.php';
require_once dirname(__DIR__, 2) . '/includes/clients.php';
require_once dirname(__DIR__, 2) . '/includes/rate-limit.php';
require_once dirname(__DIR__, 2) . '/includes/cloud/bootstrap.php';

/**
 * Safety settings pushed from GoBuildWP Cloud (includes/cloud/policy.php).
 *
 * The rules under test: nothing without the owner's opt-in; a policy is
 * applied whole or not at all; loosening needs its own opt-in; Developer Full
 * Access is never settable; the owner's own ability blocks are never lifted.
 */
final class CloudPolicyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        foreach ([WPPILOT_CLOUD_MANAGE_OPTION, WPPILOT_CLOUD_POLICY_OPTION, WPPILOT_SAFETY_PROFILE_OPTION, WPPILOT_CONFIRMATION_MODE_OPTION, 'wppilot_ability_rules'] as $option) {
            unset(WPPilot_Test_State::$options[$option]);
        }
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'production';
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        parent::tearDown();
    }

    /** @param array<string, mixed> $extra */
    private static function policy(array $extra = []): array
    {
        return array_merge(['id' => 'pol-1', 'name' => 'Agency default'], $extra);
    }

    public function test_nothing_is_applied_without_the_owners_opt_in(): void
    {
        $result = wppilot_cloud_apply_policy(self::policy(['safety_profile' => 'readonly']));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_cloud_policy_not_allowed', $result->get_error_code());
        self::assertSame(403, $result->get_error_data()['status']);
        self::assertSame('production', wppilot_get_safety_profile());
    }

    public function test_tighten_only_applies_stricter_settings(): void
    {
        wppilot_cloud_save_manage_settings(tighten: true, loosen: false);

        $result = wppilot_cloud_apply_policy(self::policy([
            'safety_profile' => 'readonly',
            'confirmation_mode' => 'human',
            'disabled' => ['wppilot/execute-php'],
            'require_confirmation' => ['wppilot/update-post'],
        ]));

        self::assertIsArray($result);
        self::assertTrue($result['applied']);
        self::assertSame(['tighten'], array_values(array_unique(array_column($result['changes'], 'direction'))));
        self::assertSame('readonly', wppilot_get_safety_profile());
        self::assertSame('human', wppilot_confirmation_mode());
        $rules = wppilot_get_ability_rules();
        self::assertTrue($rules['wppilot/execute-php']['disabled']);
        self::assertTrue($rules['wppilot/update-post']['require_confirmation']);
        self::assertSame('pol-1', wppilot_cloud_applied_policy()['id']);
    }

    public function test_a_loosening_policy_is_refused_whole_without_the_loosen_opt_in(): void
    {
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'readonly';
        wppilot_cloud_save_manage_settings(tighten: true, loosen: false);

        $result = wppilot_cloud_apply_policy(self::policy([
            'safety_profile' => 'production',
            'disabled' => ['wppilot/execute-php'],
        ]));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_cloud_policy_loosen_refused', $result->get_error_code());
        self::assertSame('safety_profile', $result->get_error_data()['loosening'][0]['setting']);
        self::assertSame('readonly', wppilot_get_safety_profile(), 'nothing changed');
        self::assertSame([], wppilot_get_ability_rules(), 'not even the tightening half');
    }

    public function test_developer_full_access_can_never_be_set_from_the_cloud(): void
    {
        wppilot_cloud_save_manage_settings(tighten: true, loosen: true);

        $result = wppilot_cloud_apply_policy(self::policy(['safety_profile' => 'developer']));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_cloud_policy_invalid', $result->get_error_code());
        self::assertSame('production', wppilot_get_safety_profile());
    }

    public function test_the_cloud_lifts_only_blocks_it_set_itself(): void
    {
        wppilot_cloud_save_manage_settings(tighten: true, loosen: true);
        wppilot_update_ability_rules(['wppilot/delete-post' => ['disabled' => true]]); // the owner's own block

        wppilot_cloud_apply_policy(self::policy(['disabled' => ['wppilot/execute-php']]));
        $result = wppilot_cloud_apply_policy(self::policy(['id' => 'pol-2', 'disabled' => []]));

        self::assertIsArray($result);
        self::assertSame([['setting' => 'disabled', 'ability' => 'wppilot/execute-php', 'from' => 'on', 'to' => 'off', 'direction' => 'loosen']], $result['changes']);
        $rules = wppilot_get_ability_rules();
        self::assertArrayNotHasKey('wppilot/execute-php', $rules, 'the Cloud lifted its own block');
        self::assertTrue($rules['wppilot/delete-post']['disabled'], "the owner's block stays");
    }

    public function test_a_block_the_owner_touched_is_the_owners_and_is_never_lifted(): void
    {
        wppilot_cloud_save_manage_settings(tighten: true, loosen: true);
        wppilot_cloud_apply_policy(self::policy(['disabled' => ['wppilot/execute-php']]));

        // The owner switches the Cloud's block off and on again in wp-admin: from now on it is theirs.
        wppilot_update_ability_rules([]);
        wppilot_update_ability_rules(['wppilot/execute-php' => ['disabled' => true]]);
        $result = wppilot_cloud_apply_policy(self::policy(['disabled' => []]));

        self::assertIsArray($result);
        self::assertSame([], $result['changes']);
        self::assertTrue(wppilot_get_ability_rules()['wppilot/execute-php']['disabled']);
    }

    public function test_a_block_the_owner_lifted_is_dropped_from_the_clouds_list_on_the_next_apply(): void
    {
        wppilot_cloud_save_manage_settings(tighten: true, loosen: true);
        wppilot_cloud_apply_policy(self::policy(['disabled' => ['wppilot/execute-php']]));
        // Lifted some other way than the Hub (here: the option itself), then the next policy omits it.
        WPPilot_Test_State::$options['wppilot_ability_rules'] = [];
        wppilot_cloud_apply_policy(self::policy(['disabled' => []]));
        self::assertSame([], wppilot_cloud_applied_policy()['disabled']);

        // The owner blocks it later, as their own: a policy without it leaves it alone.
        wppilot_update_ability_rules(['wppilot/execute-php' => ['disabled' => true]]);
        $result = wppilot_cloud_apply_policy(self::policy(['disabled' => []]));
        self::assertSame([], $result['changes']);
        self::assertTrue(wppilot_get_ability_rules()['wppilot/execute-php']['disabled']);
    }

    public function test_lifting_its_own_block_counts_as_loosening(): void
    {
        wppilot_cloud_save_manage_settings(tighten: true, loosen: false);
        wppilot_cloud_apply_policy(self::policy(['disabled' => ['wppilot/execute-php']]));

        $result = wppilot_cloud_apply_policy(self::policy(['disabled' => []]));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertTrue(wppilot_get_ability_rules()['wppilot/execute-php']['disabled']);
    }

    public function test_a_dry_run_lists_changes_and_changes_nothing(): void
    {
        wppilot_cloud_save_manage_settings(tighten: true, loosen: false);

        $result = wppilot_cloud_apply_policy(self::policy(['safety_profile' => 'readonly']), dry_run: true);

        self::assertIsArray($result);
        self::assertFalse($result['applied']);
        self::assertCount(1, $result['changes']);
        self::assertSame('production', wppilot_get_safety_profile());
        self::assertNull(wppilot_cloud_applied_policy());
    }

    public function test_bad_input_is_refused(): void
    {
        wppilot_cloud_save_manage_settings(tighten: true, loosen: true);

        foreach ([
            ['id' => ''],
            ['id' => 'x y'],
            self::policy(['confirmation_mode' => 'never']),
            self::policy(['disabled' => 'wppilot/execute-php']),
            self::policy(['disabled' => ['k' => 'wppilot/execute-php']]),
            self::policy(['disabled' => ['not an ability']]),
            self::policy(['disabled' => array_fill(0, WPPILOT_CLOUD_POLICY_MAX_ABILITIES + 1, 'wppilot/a')]),
            self::policy(['disabled' => ["wppilot/a\n"]]),
            self::policy(['disabled' => ['wppilot/' . str_repeat('a', 130)]]),
        ] as $body) {
            $result = wppilot_cloud_apply_policy($body);
            self::assertInstanceOf(WP_Error::class, $result, (string) wp_json_encode($body));
            self::assertSame('wppilot_cloud_policy_invalid', $result->get_error_code());
        }
    }

    public function test_unticking_tighten_opts_out_and_disconnect_clears_the_opt_in(): void
    {
        wppilot_cloud_save_manage_settings(tighten: false, loosen: true);
        self::assertSame(['tighten' => false, 'loosen' => false], wppilot_cloud_manage_settings(), 'unticking "may tighten" opts out, whatever the loosen box says');
        wppilot_cloud_save_manage_settings(tighten: true, loosen: true);

        wppilot_cloud_apply_policy(self::policy(['safety_profile' => 'readonly']));
        wppilot_cloud_clear_link();

        self::assertSame(['tighten' => false, 'loosen' => false], wppilot_cloud_manage_settings());
        self::assertNull(wppilot_cloud_applied_policy());
        self::assertSame('readonly', wppilot_get_safety_profile(), 'applied settings stay; only the right to change them goes');
    }

    public function test_status_reports_opt_in_applied_policy_and_current_settings(): void
    {
        wppilot_cloud_save_manage_settings(tighten: true, loosen: false);
        wppilot_cloud_apply_policy(self::policy(['require_confirmation' => ['wppilot/update-post']]));

        $status = wppilot_cloud_policy_status();

        self::assertSame(['tighten' => true, 'loosen' => false], $status['manage']);
        self::assertSame('pol-1', $status['applied']['id']);
        self::assertSame(['safety_profile' => 'production', 'confirmation_mode' => 'argument', 'disabled' => [], 'require_confirmation' => ['wppilot/update-post']], $status['current']);
    }
}
