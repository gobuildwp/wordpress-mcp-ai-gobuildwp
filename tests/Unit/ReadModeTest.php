<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Ability;
use WP_Error;
use WP_REST_Request;
use WPPilot_Test_State;

/**
 * Read Mode: one switch that makes every agent connection read-only, failing closed.
 */
final class ReadModeTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    /** @var array<string, mixed> */
    private array $savedFilters = [];

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedFilters = $GLOBALS['wp_filter'] ?? [];
        unset(WPPilot_Test_State::$options[WPPILOT_READ_MODE_OPTION]);
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'developer';
        unset($GLOBALS['wp_rest_application_password_uuid'], $GLOBALS['wp_rest_auth_cookie']);
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        WPPilot_Test_State::$current_user_id = 0;
        $GLOBALS['wp_filter'] = $this->savedFilters;
        unset($GLOBALS['wp_rest_application_password_uuid'], $GLOBALS['wp_rest_auth_cookie']);
    }

    public function testOffUntilSwitchedOn(): void
    {
        self::assertFalse(wppilot_read_mode_enabled());

        self::assertTrue(wppilot_set_read_mode(true));
        self::assertTrue(wppilot_read_mode_enabled());
        self::assertSame('1', WPPilot_Test_State::$options[WPPILOT_READ_MODE_OPTION]);

        self::assertTrue(wppilot_set_read_mode(false));
        self::assertFalse(wppilot_read_mode_enabled());
    }

    public function testAnUnexpectedStoredValueReadsAsOn(): void
    {
        foreach (['yes', '', 'off', [], 2, '1'] as $stored) {
            WPPilot_Test_State::$options[WPPILOT_READ_MODE_OPTION] = $stored;
            self::assertTrue(wppilot_read_mode_enabled(), var_export($stored, true));
        }
    }

    public function testWritesAreRefusedEvenOnDeveloperFullAccess(): void
    {
        wppilot_set_read_mode(true);

        self::assertSame('readonly', wppilot_effective_safety_profile());
        self::assertFalse(wppilot_safety_profile_allows_ability($this->write()));

        $error = wppilot_safety_check_ability($this->write());
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('wppilot_read_mode', $error->get_error_code());
        self::assertStringContainsString('Read Mode', $error->get_error_message());

        $denied = wppilot_safety_filter_ability_permission(true, 'wppilot/update-post', [], $this->write());
        self::assertInstanceOf(WP_Error::class, $denied);
        self::assertSame('wppilot_read_mode', $denied->get_error_code());
    }

    public function testReadsRunAndTheAllowlistCanBeNarrowed(): void
    {
        wppilot_set_read_mode(true);
        $read = new WP_Ability('wppilot/get-post', ['annotations' => ['readonly' => true]]);

        self::assertTrue(wppilot_safety_check_ability($read));

        add_filter(
            'wppilot_read_mode_allows_ability',
            static fn (bool $allowed, WP_Ability $ability): bool => $ability->get_name() !== 'wppilot/get-post',
            10,
            2,
        );
        self::assertInstanceOf(WP_Error::class, wppilot_safety_check_ability($read));
    }

    public function testAFilterCannotLetAWriteThrough(): void
    {
        wppilot_set_read_mode(true);
        add_filter('wppilot_read_mode_allows_ability', static fn (): bool => true);

        self::assertFalse(wppilot_safety_profile_allows_ability($this->write()));
    }

    public function testAgentRestWritesAreRefusedAndPeopleAndReadsAreNot(): void
    {
        wppilot_set_read_mode(true);
        WPPilot_Test_State::$current_user_id = 1;

        $GLOBALS['wp_rest_application_password_uuid'] = 'uuid';
        $refused = wppilot_read_mode_rest_guard(null, null, $this->request('POST', '/wp/v2/posts'));
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('wppilot_read_mode', $refused->get_error_code());

        self::assertNull(wppilot_read_mode_rest_guard(null, null, $this->request('GET', '/wp/v2/posts')));
        // Ability routes are gated per ability instead.
        self::assertNull(wppilot_read_mode_rest_guard(null, null, $this->request('POST', '/mcp/wppilot')));
        self::assertNull(wppilot_read_mode_rest_guard(null, null, $this->request('POST', '/wp-abilities/v1/abilities/wppilot/get-post/run')));
        self::assertInstanceOf(WP_Error::class, wppilot_read_mode_rest_guard(null, null, $this->request('POST', '/batch/v1')));
        self::assertInstanceOf(WP_Error::class, wppilot_read_mode_rest_guard(null, null, $this->request('POST', '/wppilot/v1/cloud/policy')));

        unset($GLOBALS['wp_rest_application_password_uuid']);
        $GLOBALS['wp_rest_auth_cookie'] = true;
        self::assertNull(wppilot_read_mode_rest_guard(null, null, $this->request('POST', '/wp/v2/posts')));

        // A login core did not authenticate by cookie is treated as an agent.
        unset($GLOBALS['wp_rest_auth_cookie']);
        self::assertInstanceOf(WP_Error::class, wppilot_read_mode_rest_guard(null, null, $this->request('POST', '/wp/v2/posts')));
    }

    public function testRestGuardIsInertWhenOff(): void
    {
        WPPilot_Test_State::$current_user_id = 1;
        $GLOBALS['wp_rest_application_password_uuid'] = 'uuid';

        self::assertNull(wppilot_read_mode_rest_guard(null, null, $this->request('POST', '/wp/v2/posts')));
    }

    public function testXmlrpcKeepsOnlyReadMethods(): void
    {
        $methods = [
            'wp.getPosts' => 'a',
            'wp.newPost' => 'b',
            'wp.deletePost' => 'c',
            'metaWeblog.editPost' => 'd',
            'system.listMethods' => 'e',
            'pingback.ping' => 'f',
        ];
        self::assertSame($methods, wppilot_read_mode_xmlrpc_methods($methods));

        wppilot_set_read_mode(true);
        self::assertSame(['wp.getPosts', 'system.listMethods'], array_keys(wppilot_read_mode_xmlrpc_methods($methods)));
    }

    private function write(): WP_Ability
    {
        return new WP_Ability('wppilot/update-post', ['annotations' => ['readonly' => false]]);
    }

    private function request(string $method, string $route): WP_REST_Request
    {
        return new class ($method, $route) extends WP_REST_Request {
            public function __construct(private string $verb, private string $path)
            {
                parent::__construct();
            }

            public function get_method(): string
            {
                return $this->verb;
            }

            public function get_route(): string
            {
                return $this->path;
            }
        };
    }
}
