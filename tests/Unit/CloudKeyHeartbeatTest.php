<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
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
 * A site connected before Cloud had a signing key asks for it from the status
 * check (heartbeat.php gobuildwp_cloud_heartbeat_for_key) instead of waiting out the hour.
 */
final class CloudKeyHeartbeatTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    /** @var array<string, int> */
    private array $savedCron = [];

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedCron = WPPilot_Test_State::$cron;
        WPPilot_Test_State::$cron = [];
        $GLOBALS['wppilot_test_single_events'] = [];
        unset(WPPilot_Test_State::$options[GOBUILDWP_CLOUD_KEY_HEARTBEAT_OPTION]);
        remove_all_filters('gobuildwp_cloud_signing_supported');
        WPPilot_Test_State::$options[WPPILOT_CLOUD_LINK_OPTION] = [
            'site_id' => 'site-1',
            'token_id' => 7,
            'account_hint' => 'a***@example.com',
            'cloud_url' => 'https://app.gobuildwp.com',
            'paired_at' => '2026-10-01T00:00:00Z',
        ];
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        WPPilot_Test_State::$cron = $this->savedCron;
        $GLOBALS['wppilot_test_single_events'] = [];
        remove_all_filters('gobuildwp_cloud_signing_supported');
        parent::tearDown();
    }

    /** @return list<string> */
    private static function queued(): array
    {
        return array_column($GLOBALS['wppilot_test_single_events'] ?? [], 'hook');
    }

    public function testAMissingKeyQueuesOneHeartbeatPerFifteenMinutes(): void
    {
        if (gobuildwp_cloud_signing_status() !== 'none') {
            self::markTestSkipped('This PHP cannot verify Ed25519.');
        }
        $now = 1_800_000_000;

        self::assertTrue(gobuildwp_cloud_heartbeat_for_key($now));
        self::assertSame([WPPILOT_CLOUD_HEARTBEAT_HOOK], self::queued());

        self::assertFalse(gobuildwp_cloud_heartbeat_for_key($now + 600));
        self::assertCount(1, self::queued());

        self::assertTrue(gobuildwp_cloud_heartbeat_for_key($now + 900));
        self::assertCount(2, self::queued());
    }

    public function testNothingIsQueuedOnceTheKeyIsStoredOrCannotBeUsed(): void
    {
        add_filter('gobuildwp_cloud_signing_supported', static fn (): bool => false);
        self::assertFalse(gobuildwp_cloud_heartbeat_for_key(1_800_000_000));
        self::assertSame([], self::queued());
    }

    public function testNothingIsQueuedWithoutACloudLink(): void
    {
        unset(WPPilot_Test_State::$options[WPPILOT_CLOUD_LINK_OPTION]);
        self::assertFalse(gobuildwp_cloud_heartbeat_for_key(1_800_000_000));
        self::assertSame([], self::queued());
    }
}
