<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/agent-context.php';

/**
 * The page-speed lines of the server instructions: the check is always named, and the Site Kit
 * sharing offer appears only while Site Kit is active and some module is still unshared with
 * Administrators, so an agent is told about it once and stops hearing about it once it is done.
 */
final class AgentContextSpeedTest extends TestCase
{
    protected function setUp(): void
    {
        \WPPilot_Test_State::$options = [];
        if (!defined('GOOGLESITEKIT_VERSION')) {
            define('GOOGLESITEKIT_VERSION', '1.189.0');
        }
    }

    public function testTheOfferIsMadeWhileAModuleIsUnshared(): void
    {
        $shared = ['sharedRoles' => ['administrator'], 'management' => 'owner'];
        \WPPilot_Test_State::$options['googlesitekit_dashboard_sharing'] = ['search-console' => $shared, 'analytics-4' => $shared];

        $text = implode("\n", wppilot_build_speed_context_lines());

        self::assertStringContainsString('`wppilot/pagespeed-check`', $text);
        self::assertStringContainsString('offer once', $text);
        self::assertStringContainsString('`wppilot/site-kit-enable-sharing`', $text);
    }

    public function testTheOfferStopsOnceEverythingIsShared(): void
    {
        $shared = ['sharedRoles' => ['editor', 'administrator'], 'management' => 'owner'];
        \WPPilot_Test_State::$options['googlesitekit_dashboard_sharing'] = ['search-console' => $shared, 'analytics-4' => $shared, 'pagespeed-insights' => $shared];

        $text = implode("\n", wppilot_build_speed_context_lines());

        self::assertStringContainsString('`wppilot/pagespeed-check`', $text);
        self::assertStringNotContainsString('site-kit-enable-sharing', $text);
    }
}
