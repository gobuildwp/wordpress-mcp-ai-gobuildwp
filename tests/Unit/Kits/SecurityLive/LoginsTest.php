<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Tests\Unit\Kits\SecurityLive;

require_once dirname(__DIR__) . '/SecurityIntegrity/harness.php';
require_once dirname(__DIR__, 4) . '/includes/kits/security-live/src/load.php';

use GoBuildWP\Kits\SecurityLive as SL;
use PHPUnit\Framework\TestCase;

use function GoBuildWP\Tests\Unit\Kits\SecurityIntegrity\reset_state;

/** Failed sign-ins are counted per network and rolled up into one event, never one per attempt. */
final class LoginsTest extends TestCase
{
    private const NOW = 1_790_000_000;

    protected function setUp(): void
    {
        reset_state();
        delete_option(SL\LOGINS_OPTION);
        delete_option(SL\EVENTS_OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(SL\LOGINS_OPTION);
        delete_option(SL\EVENTS_OPTION);
        reset_state();
    }

    /** @return list<array<string, mixed>> */
    private static function loginEvents(): array
    {
        return array_values(array_filter(SL\events_since(0, 200), static fn(array $e): bool => str_starts_with((string) $e['kind'], 'login')));
    }

    public function test_network_is_masked_and_invalid_addresses_are_ignored(): void
    {
        self::assertSame('203.0.113.0/24', SL\login_network('203.0.113.77'));
        self::assertSame('2001:db8:abcd::/48', SL\login_network('2001:db8:abcd:12::1'));
        self::assertSame('', SL\login_network('not-an-ip'));
        self::assertSame('', SL\login_network(''));
    }

    public function test_below_threshold_records_nothing(): void
    {
        for ($i = 0; $i < SL\LOGIN_NETWORK_THRESHOLD - 1; $i++) {
            SL\on_login_failed('admin', self::NOW + $i, '203.0.113.5');
        }
        self::assertSame([], self::loginEvents());
    }

    public function test_one_rolled_up_event_per_network_burst(): void
    {
        for ($i = 0; $i < 30; $i++) {
            SL\on_login_failed('admin', self::NOW + $i, '203.0.113.' . ($i % 4 + 1));
        }
        $events = self::loginEvents();
        self::assertCount(1, $events);
        self::assertSame('medium', $events[0]['severity']);
        self::assertSame('login_failed', $events[0]['kind']);
        self::assertSame('logins.failed_burst', $events[0]['detail']['rule_id']);
        self::assertSame('203.0.113.0/24', $events[0]['detail']['network']);
        self::assertSame(SL\LOGIN_NETWORK_THRESHOLD, $events[0]['detail']['count']);
        self::assertStringNotContainsString('203.0.113.5', json_encode($events, JSON_THROW_ON_ERROR));
    }

    public function test_window_expires_counts(): void
    {
        for ($i = 0; $i < SL\LOGIN_NETWORK_THRESHOLD - 1; $i++) {
            SL\on_login_failed('admin', self::NOW, '198.51.100.9');
        }
        SL\on_login_failed('admin', self::NOW + SL\LOGIN_WINDOW + 1, '198.51.100.9');
        self::assertSame([], self::loginEvents());
    }

    public function test_site_wide_threshold_across_many_networks(): void
    {
        for ($i = 0; $i < SL\LOGIN_SITE_THRESHOLD; $i++) {
            SL\on_login_failed('admin', self::NOW + $i, '10.' . $i . '.0.1');
        }
        $events = self::loginEvents();
        self::assertCount(1, $events);
        self::assertSame('high', $events[0]['severity']);
        self::assertSame('logins.failed_many', $events[0]['detail']['rule_id']);
        self::assertSame(SL\LOGIN_SITE_THRESHOLD, $events[0]['detail']['count']);
    }

    public function test_sign_in_after_burst_is_flagged_and_normal_sign_in_is_not(): void
    {
        SL\on_login('editor', null, self::NOW, '192.0.2.10');
        self::assertSame([], self::loginEvents());

        for ($i = 0; $i < SL\LOGIN_NETWORK_THRESHOLD; $i++) {
            SL\on_login_failed('editor', self::NOW + $i, '192.0.2.10');
        }
        SL\on_login('editor', null, self::NOW + 20, '192.0.2.44');
        $events = self::loginEvents();
        self::assertCount(2, $events);
        self::assertSame('high', $events[1]['severity']);
        self::assertSame('login_after_failures', $events[1]['kind']);
        self::assertSame('logins.success_after_failures', $events[1]['detail']['rule_id']);
    }

    public function test_network_list_is_capped(): void
    {
        for ($i = 0; $i < SL\LOGIN_MAX_NETWORKS + 20; $i++) {
            SL\on_login_failed('x', self::NOW, '172.16.' . intdiv($i, 250) . '.' . ($i % 250));
            SL\on_login_failed('x', self::NOW, '100.' . intdiv($i, 250) . '.' . ($i % 250) . '.1');
        }
        $state = get_option(SL\LOGINS_OPTION);
        self::assertLessThanOrEqual(SL\LOGIN_MAX_NETWORKS, count($state['nets']));
    }
}
