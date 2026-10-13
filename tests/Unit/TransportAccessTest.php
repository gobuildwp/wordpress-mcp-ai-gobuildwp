<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WPPilot_Test_State;

use function WPPilot\Mcp\caller_may_use_mcp;

require_once dirname(__DIR__, 2) . '/includes/capabilities.php';

/**
 * Who the modern transport will answer.
 *
 * It runs on rest_pre_dispatch, before WordPress matches the route and runs its
 * permission callback, so it has to apply that check itself. Until 1.13.0 it did
 * not, and an anonymous tools/list returned every ability's schema.
 */
final class TransportAccessTest extends TestCase
{
    protected function setUp(): void
    {
        WPPilot_Test_State::reset();
    }

    public function testAnonymousCallerIsNotAnswered(): void
    {
        WPPilot_Test_State::$current_user_id = 0;
        WPPilot_Test_State::$logged_in = false;
        WPPilot_Test_State::$capabilities = [];

        self::assertFalse(caller_may_use_mcp(new WP_REST_Request([])));
    }

    public function testCallerWithoutManageCapabilityIsNotAnswered(): void
    {
        WPPilot_Test_State::$current_user_id = 7;
        WPPilot_Test_State::$logged_in = true;
        WPPilot_Test_State::$capabilities = ['edit_posts'];

        self::assertFalse(caller_may_use_mcp(new WP_REST_Request([])));
    }

    public function testMasterSwitchOffRefusesEvenAnAdministrator(): void
    {
        WPPilot_Test_State::$current_user_id = 1;
        WPPilot_Test_State::$logged_in = true;
        WPPilot_Test_State::$capabilities = ['manage_options'];
        WPPilot_Test_State::$wppilot_enabled = false;

        self::assertFalse(caller_may_use_mcp(new WP_REST_Request([])));
    }

    public function testAdministratorIsAnswered(): void
    {
        WPPilot_Test_State::$current_user_id = 1;
        WPPilot_Test_State::$logged_in = true;
        WPPilot_Test_State::$capabilities = ['manage_options'];
        WPPilot_Test_State::$wppilot_enabled = true;

        self::assertTrue(caller_may_use_mcp(new WP_REST_Request([])));
    }
}
