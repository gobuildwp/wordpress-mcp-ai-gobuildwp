<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Who a later write in another session is attributed to.
 *
 * GoBuildWP Cloud reports a machine User-Agent kept for firewall allowlists; it
 * reached owners as "GoBuildWP Cloud WPPilot-Cloud/1 (+https://app.gobuildwp.com)".
 */
#[RunTestsInSeparateProcesses]
final class ChangeActorTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/doubles/connect-environment.php';
        require_once dirname(__DIR__, 2) . '/includes/clients.php';
        require_once dirname(__DIR__, 2) . '/includes/change-sessions.php';
    }

    public function testCloudShowsItsLabelOnly(): void
    {
        $agent = ['label' => 'GoBuildWP Cloud', 'client' => 'WPPilot-Cloud/1 (+https://app.gobuildwp.com)'];
        self::assertSame('GoBuildWP Cloud', wppilot_change_actor_by($agent, ['login' => 'admin']));
    }

    public function testOtherClientKeepsItsReportedName(): void
    {
        self::assertSame('Work laptop (some-new-client)', wppilot_change_actor_by(['label' => 'Work laptop', 'client' => 'some-new-client'], []));
    }

    public function testFallsBackToClientThenLogin(): void
    {
        self::assertSame('some-new-client', wppilot_change_actor_by(['label' => '', 'client' => 'some-new-client'], ['login' => 'admin']));
        self::assertSame('admin', wppilot_change_actor_by([], ['login' => 'admin']));
    }
}
