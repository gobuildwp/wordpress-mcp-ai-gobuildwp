<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('WPPILOT_SETUP_PAGE')) {
    define('WPPILOT_SETUP_PAGE', 'wppilot-setup');
}

require_once dirname(__DIR__) . '/doubles/admin.php';
require_once dirname(__DIR__) . '/doubles/mcp-surface.php';
require_once dirname(__DIR__) . '/doubles/cloud.php';
require_once dirname(__DIR__, 2) . '/includes/capabilities.php';
require_once dirname(__DIR__, 2) . '/includes/clients.php';
require_once dirname(__DIR__, 2) . '/includes/rate-limit.php';
require_once dirname(__DIR__, 2) . '/includes/cloud/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/admin/notifications.php';

/**
 * The wp-admin AI change notifications: which changes count as unseen for the notice and menu count.
 */
final class AdminNotificationsTest extends TestCase
{
    public function testOnlyChangesAfterTheLastLookAreUnseen(): void
    {
        $changes = [['id' => 'c3', 'at' => 300], ['id' => 'c2', 'at' => 200], ['id' => 'c1', 'at' => 100]];

        self::assertSame(['c3', 'c2'], array_column(wppilot_notifications_unseen($changes, 150), 'id'));
        self::assertSame([], wppilot_notifications_unseen($changes, 300), 'a change at the seen time was seen');
        self::assertCount(3, wppilot_notifications_unseen($changes, 0));
        self::assertSame([], wppilot_notifications_unseen([], 0));
    }

    public function testCountStopsAtTheReadLimit(): void
    {
        self::assertSame('3', wppilot_notifications_count_label(3));
        self::assertSame(WPPILOT_NOTIFICATIONS_LIMIT . '+', wppilot_notifications_count_label(WPPILOT_NOTIFICATIONS_LIMIT));
    }
}
