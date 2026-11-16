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
 * Pause agents: one reversible switch that refuses every agent call, reads included.
 */
final class AgentPauseTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    /** @var array<string, mixed> */
    private array $savedFilters = [];

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedFilters = $GLOBALS['wp_filter'] ?? [];
        unset(
            WPPilot_Test_State::$options[WPPILOT_AGENTS_PAUSED_OPTION],
            WPPilot_Test_State::$options[WPPILOT_AGENTS_PAUSED_META_OPTION],
        );
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

    public function testRunningUntilPausedAndResumeClearsWhoPaused(): void
    {
        self::assertFalse(wppilot_agents_paused());
        WPPilot_Test_State::$current_user_id = 7;

        self::assertTrue(wppilot_set_agents_paused(true));
        $status = wppilot_agents_pause_status();
        self::assertTrue($status['paused']);
        self::assertFalse($status['pinned']);
        self::assertSame(7, $status['by']);
        self::assertGreaterThan(0, $status['at']);

        self::assertTrue(wppilot_set_agents_paused(false));
        self::assertFalse(wppilot_agents_paused());
        self::assertSame(0, wppilot_agents_pause_status()['by']);
    }

    public function testAnUnexpectedStoredValueReadsAsPaused(): void
    {
        foreach (['yes', '', 'off', [], 2, '1'] as $stored) {
            WPPilot_Test_State::$options[WPPILOT_AGENTS_PAUSED_OPTION] = $stored;
            self::assertTrue(wppilot_agents_paused(), var_export($stored, true));
        }
    }

    public function testEveryAbilityIsRefusedReadsIncluded(): void
    {
        $read = new WP_Ability('wppilot/get-post', ['annotations' => ['readonly' => true]]);
        self::assertNull(wppilot_agents_pause_gate_error($read));
        self::assertTrue(wppilot_agents_pause_filter_permission(true, 'wppilot/get-post'));

        wppilot_set_agents_paused(true);

        $gate = wppilot_gate_ability_call($read, [], 'mcp');
        self::assertInstanceOf(WP_Error::class, $gate);
        self::assertSame('agents_paused', $gate->get_error_code());
        self::assertSame(503, $gate->get_error_data()['status']);

        $filtered = wppilot_agents_pause_filter_permission(true, 'wppilot/get-post');
        self::assertInstanceOf(WP_Error::class, $filtered);
        self::assertSame('agents_paused', $filtered->get_error_code());

        $legacy = wppilot_agents_pause_mcp_tool_call(['ability_name' => 'wppilot/get-post'], 'mcp-adapter-execute-ability');
        self::assertInstanceOf(WP_Error::class, $legacy);
        self::assertSame('agents_paused', $legacy->get_error_code());
    }

    public function testAgentRestRequestsAreRefusedExceptCloudStatusAndUnlink(): void
    {
        wppilot_set_agents_paused(true);
        WPPilot_Test_State::$current_user_id = 1;
        $GLOBALS['wp_rest_application_password_uuid'] = 'uuid';

        foreach ([['GET', '/wp/v2/posts'], ['POST', '/mcp/wppilot'], ['POST', '/wppilot/v1/cloud/policy']] as [$method, $route]) {
            $refused = wppilot_agents_pause_rest_guard(null, null, $this->request($method, $route));
            self::assertInstanceOf(WP_Error::class, $refused, $route);
            self::assertSame('agents_paused', $refused->get_error_code());
        }
        self::assertNull(wppilot_agents_pause_rest_guard(null, null, $this->request('GET', '/wppilot/v1/cloud/status')));
        self::assertNull(wppilot_agents_pause_rest_guard(null, null, $this->request('POST', '/wppilot/v1/cloud/unlink')));

        // A person signed in to wp-admin is not an agent.
        unset($GLOBALS['wp_rest_application_password_uuid']);
        $GLOBALS['wp_rest_auth_cookie'] = true;
        self::assertNull(wppilot_agents_pause_rest_guard(null, null, $this->request('POST', '/wp/v2/posts')));
    }

    public function testRestGuardIsInertWhileRunning(): void
    {
        WPPilot_Test_State::$current_user_id = 1;
        $GLOBALS['wp_rest_application_password_uuid'] = 'uuid';

        self::assertNull(wppilot_agents_pause_rest_guard(null, null, $this->request('POST', '/mcp/wppilot')));
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
