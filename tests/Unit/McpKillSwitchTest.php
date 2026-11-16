<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

require_once dirname(__DIR__) . '/doubles/admin.php';
require_once dirname(__DIR__, 2) . '/includes/mcp/adapter-origin.php';
require_once dirname(__DIR__, 2) . '/includes/mcp-kill-switch.php';

/**
 * WPPILOT_DISABLE_MCP: GoBuildWP's routes, gate and abilities refuse; nothing else does.
 *
 * A constant cannot be undefined again, so the switched-on behaviour is tested last in each method
 * that needs it and the route matcher, which does not read the constant, is tested on its own.
 */
final class McpKillSwitchTest extends TestCase
{
    public function testRouteMatcherCoversGoBuildWpSurfacesOnly(): void
    {
        foreach ([
            '/wppilot/v1/chat/status',
            '/wppilot/v1/oauth/token',
            '/gobuildwp/v1/device/token',
            '/mcp/wppilot',
            '/mcp/wppilot-oauth',
            '/mcp/wppilot/server-card',
            'wppilot/v1/abilities/wppilot/get-post/run',
            // WordPress matches routes case-insensitively, so a mixed-case spelling still reaches us.
            '/WPPilot/v1/chat/status',
            '/MCP/WPPILOT',
        ] as $route) {
            self::assertTrue(wppilot_mcp_route_is_ours($route), $route);
        }

        foreach ([
            '/wp/v2/posts',
            '/wp-abilities/v1/abilities',
            '/mcp/elementor-mcp',
            '/wppilotx/v1/thing',
            '/mcp/wppilotish',
        ] as $route) {
            self::assertFalse(wppilot_mcp_route_is_ours($route), $route);
        }
        self::assertSame(wppilot_owns_mcp_adapter(), wppilot_mcp_route_is_ours('/mcp/mcp-adapter-default-server'));
    }

    // Both constants would leak into every later test in the same process.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSwitchedOnRefusesRoutesGateAndAbilities(): void
    {
        if (!defined('WPPILOT_DISABLE_MCP')) {
            define('WPPILOT_DISABLE_MCP', true);
        }
        if (!defined('REST_REQUEST')) {
            define('REST_REQUEST', true);
        }
        self::assertTrue(wppilot_mcp_disabled());

        $ours = new class extends WP_REST_Request {
            public function get_route(): string
            {
                return '/mcp/wppilot';
            }
        };
        $theirs = new class extends WP_REST_Request {
            public function get_route(): string
            {
                return '/wp/v2/posts';
            }
        };

        $refused = wppilot_mcp_kill_switch_rest(null, null, $ours);
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('wppilot_mcp_disabled', $refused->get_error_code());
        self::assertSame(503, $refused->get_error_data()['status']);
        self::assertNull(wppilot_mcp_kill_switch_rest(null, null, $theirs));

        self::assertInstanceOf(WP_Error::class, wppilot_mcp_kill_switch_gate(['post_id' => 1]));

        self::assertInstanceOf(WP_Error::class, wppilot_mcp_kill_switch_execute(null, 'wppilot/update-post'));
        self::assertInstanceOf(WP_Error::class, wppilot_mcp_kill_switch_execute(null, 'gobuildwp/protection-feed'));
        self::assertNull(wppilot_mcp_kill_switch_execute(null, 'other-plugin/do-thing'));
    }
}
