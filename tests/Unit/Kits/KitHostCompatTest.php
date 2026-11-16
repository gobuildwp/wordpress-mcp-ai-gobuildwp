<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Hosts\WPPilotHost;
use WPPilot_Test_State;

use function WPPilot\Kits\Runtime\Hosts\bridge_legacy_filters;

/**
 * The security kits spell their codes, wp-config constants, folders, markers and hooks in the kit
 * form, which the exporter renames for another plugin. GoBuildWP shipped them first under its own
 * names, which sites, wp-config files, snippets and the Cloud already use: this checks WPPilotHost
 * keeps every one of those working. Not exported: it is about this host, not the kits.
 */
final class KitHostCompatTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/includes/kits/_runtime/runtime.php';
        require_once $root . '/includes/kits/_runtime/hosts/wppilot.php';
    }

    protected function setUp(): void
    {
        // setUp runs inside the separate process too, and a host once set cannot be unset.
        if ($this->name() !== 'test_without_a_host_a_name_is_its_own') {
            Runtime\host(new WPPilotHost());
        }
        $GLOBALS['wp_filter'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp_filter'] = [];
    }

    public function test_kit_names_map_back_to_the_names_sites_already_use(): void
    {
        $names = [
            // Result codes Cloud and clients match.
            'wppilot_kit_security_fix_unchanged' => 'gobuildwp_security_fix_unchanged',
            'wppilot_kit_cloud_pro_required' => 'gobuildwp_cloud_pro_required',
            'wppilot_kit_rules_unsigned' => 'gobuildwp_rules_unsigned',
            // wp-config.php switches.
            'WPPILOT_KIT_DISABLE_2FA' => 'GOBUILDWP_DISABLE_2FA',
            'WPPILOT_KIT_DISABLE_PROTECTION' => 'GOBUILDWP_DISABLE_PROTECTION',
            'WPPILOT_KIT_TRUSTED_PROXIES' => 'GOBUILDWP_TRUSTED_PROXIES',
            // Stored data and files already on disk.
            'wppilot_kit_live_login_failures' => 'gobuildwp_live_login_failures',
            'wppilot-kit-protection' => 'gobuildwp-protection',
            'wppilot-kit pre-WordPress firewall' => 'GoBuildWP pre-WordPress firewall',
            '; BEGIN wppilot-kit protection' => '; BEGIN GoBuildWP protection',
            '; END wppilot-kit protection' => '; END GoBuildWP protection',
            '# BEGIN wppilot-kit protection' => '# BEGIN GoBuildWP protection',
            '# END wppilot-kit protection' => '# END GoBuildWP protection',
        ];
        foreach ($names as $kit => $public) {
            self::assertSame($public, Runtime\public_name($kit), $kit);
        }
        // Anything not in a kit form passes through, including options already named gobuildwp_kit_*.
        foreach (['gobuildwp_kit_security_integrity_pack', 'blogname', 'wppilot_preview_x', 'kit_wppilot_kit_x'] as $name) {
            self::assertSame($name, Runtime\public_name($name), $name);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_without_a_host_a_name_is_its_own(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/kits/_runtime/runtime.php';
        self::assertFalse(Runtime\has_host());
        self::assertSame('wppilot_kit_cloud_pro_required', Runtime\public_name('wppilot_kit_cloud_pro_required'));
        self::assertFalse(Runtime\flag('WPPILOT_KIT_DISABLE_2FA'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_old_wp_config_switches_still_turn_the_kits_off(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 3) . '/includes/kits/_runtime/hosts/wppilot.php';
        Runtime\host(new WPPilotHost());

        self::assertFalse(Runtime\flag('WPPILOT_KIT_DISABLE_2FA'));
        self::assertFalse(Runtime\flag('WPPILOT_KIT_DISABLE_PROTECTION'));
        self::assertNull(Runtime\constant_value('WPPILOT_KIT_TRUSTED_PROXIES'));

        define('GOBUILDWP_DISABLE_2FA', true);
        define('GOBUILDWP_DISABLE_PROTECTION', 'yes');
        define('GOBUILDWP_TRUSTED_PROXIES', '10.0.0.0/8');
        self::assertTrue(Runtime\flag('WPPILOT_KIT_DISABLE_2FA'));
        self::assertFalse(Runtime\flag('WPPILOT_KIT_DISABLE_PROTECTION'), 'a switch is on only when it is true');
        self::assertSame('10.0.0.0/8', Runtime\constant_value('WPPILOT_KIT_TRUSTED_PROXIES'));

        define('WPPILOT_KIT_DISABLE_PROTECTION', true);
        define('WPPILOT_KIT_TRUSTED_PROXIES', '192.0.2.1');
        self::assertTrue(Runtime\flag('WPPILOT_KIT_DISABLE_PROTECTION'));
        self::assertSame('192.0.2.1', Runtime\constant_value('WPPILOT_KIT_TRUSTED_PROXIES'), 'the kit name wins');
    }

    public function test_old_filter_names_still_run(): void
    {
        bridge_legacy_filters();
        add_filter('gobuildwp_live_known_file', static fn($known, string $abs, string $path) => $path === 'wp-content/mu-plugins/r.php' && $abs === '/abs/r.php' ? 'ours' : $known, 10, 3);
        add_filter('gobuildwp_protection_trusted_proxies', static fn(array $proxies): array => [...$proxies, '203.0.113.9']);

        self::assertSame('ours', apply_filters('wppilot_kit_live_known_file', null, '/abs/r.php', 'wp-content/mu-plugins/r.php'));
        self::assertNull(apply_filters('wppilot_kit_live_known_file', null, '/abs/o.php', 'wp-content/mu-plugins/o.php'));
        self::assertSame(['198.51.100.1', '203.0.113.9'], apply_filters('wppilot_kit_protection_trusted_proxies', ['198.51.100.1']));
        // With nothing on the old name the bridge hands the value through.
        self::assertSame('/x', apply_filters('wppilot_kit_protection_prepend_root', '/x'));
    }

    public function test_a_cloud_only_action_refused_under_the_old_code_and_cloud_name(): void
    {
        if (function_exists('gobuildwp_cloud_request_verified') && gobuildwp_cloud_request_verified()) {
            self::markTestSkipped('A verified Cloud request is left over from another test.');
        }
        $refused = Runtime\require_cloud_request();
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('gobuildwp_cloud_pro_required', $refused->get_error_code());
        self::assertStringContainsString('GoBuildWP Cloud Pro', $refused->get_error_message());
        self::assertSame(403, $refused->get_error_data()['status']);
    }

    public function test_host_extension_points_the_kits_read(): void
    {
        $host = new WPPilotHost();
        self::assertContains('wppilot_preview_', $host->extension('unscanned-option-prefixes'));
        self::assertSame(['gobuildwp_', 'wppilot_'], $host->extension('protected-option-prefixes'));
        self::assertSame('gobuildwp/v1', $host->extension('cloud-rest-namespace'));
        self::assertSame(function_exists('wppilot_cloud_rest_permission') ? 'wppilot_cloud_rest_permission' : null, $host->extension('cloud-rest-permission'));
        self::assertSame('GoBuildWP Cloud', $host->extension('cloud-name'));
    }

    /** Cloud never sends confirm=true, so GoBuildWP's confirm gate must not demand it of a block. */
    public function test_block_ip_needs_no_confirmation_from_the_host(): void
    {
        $before = count(WPPilot_Test_State::$registrations);
        require dirname(__DIR__, 3) . '/includes/kits/security-protection/src/abilities/protection-block-ip.php';
        $args = WPPilot_Test_State::$registrations[$before]['args'];
        WPPilot_Test_State::$registrations = array_slice(WPPilot_Test_State::$registrations, 0, $before);
        WPPilot_Test_State::$registered_abilities = array_slice(WPPilot_Test_State::$registered_abilities, 0, $before);

        $ability = new \WP_Ability('gobuildwp/protection-block-ip', $args['meta'], $args['input_schema'], $args['category']);
        self::assertFalse(wppilot_ability_requires_confirmation($ability));
    }
}
