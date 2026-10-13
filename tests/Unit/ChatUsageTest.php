<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Chat token usage (includes/chat/usage.php): counts per user and day, the summary the meter
 * shows, and the limits that stop a call before it is made.
 */
final class ChatUsageTest extends TestCase
{
    private const NOW = 1_791_000_000; // 2026-10-03 UTC

    public static function setUpBeforeClass(): void
    {
        // User meta held in memory; guarded, so a fuller double elsewhere wins.
        if (!function_exists('get_user_meta')) {
            eval('function get_user_meta(int $u, string $k = "", bool $single = false): mixed { return $GLOBALS["chat_usage_test_meta"][$u][$k] ?? ($single ? "" : []); }');
        }
        if (!function_exists('update_user_meta')) {
            eval('function update_user_meta(int $u, string $k, mixed $v): bool { $GLOBALS["chat_usage_test_meta"][$u][$k] = $v; return true; }');
        }
        if (!function_exists('delete_user_meta')) {
            eval('function delete_user_meta(int $u, string $k): bool { unset($GLOBALS["chat_usage_test_meta"][$u][$k]); return true; }');
        }
        if (!function_exists('number_format_i18n')) {
            eval('function number_format_i18n(float $n, int $d = 0): string { return number_format($n, $d); }');
        }
        require_once dirname(__DIR__, 2) . '/includes/chat/usage.php';
    }

    protected function setUp(): void
    {
        delete_user_meta(7, WPPILOT_CHAT_USAGE_META);
        remove_all_filters('wppilot_chat_usage_limits');
    }

    public function test_usage_adds_up_per_day_and_month(): void
    {
        wppilot_chat_record_usage(7, 1000, 200, self::NOW);
        wppilot_chat_record_usage(7, 500, 100, self::NOW);
        wppilot_chat_record_usage(7, 300, 0, self::NOW - 86_400);
        wppilot_chat_record_usage(7, 9999, 1, self::NOW - 40 * 86_400); // last month
        wppilot_chat_record_usage(7, 0, 0, self::NOW); // nothing billed, nothing counted

        $u = wppilot_chat_usage_summary(7, self::NOW);
        self::assertSame(1800, $u['today']);
        self::assertSame(2, $u['calls_today']);
        self::assertSame(2100, $u['month']);
        self::assertSame(['day' => 0, 'month' => 0], $u['limits'], 'Free sets no limits');
        self::assertTrue(wppilot_chat_usage_allows(7, self::NOW));
    }

    public function test_a_limit_stops_the_next_call_with_a_429(): void
    {
        add_filter('wppilot_chat_usage_limits', static fn(): array => ['day' => 1000, 'month' => 0]);
        wppilot_chat_record_usage(7, 900, 50, self::NOW);
        self::assertTrue(wppilot_chat_usage_allows(7, self::NOW), 'under the limit');

        wppilot_chat_record_usage(7, 100, 0, self::NOW);
        $refused = wppilot_chat_usage_allows(7, self::NOW);
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('wppilot_chat_usage_limit', $refused->get_error_code());
        self::assertSame(429, $refused->get_error_data()['status']);
        self::assertTrue(wppilot_chat_usage_allows(7, self::NOW + 86_400), 'a new day starts fresh');
    }

    public function test_tokens_come_from_the_ai_client_result_or_not_at_all(): void
    {
        $usage = new class {
            public function getPromptTokens(): int { return 120; }
            public function getCompletionTokens(): int { return 30; }
        };
        $result = new class($usage) {
            public function __construct(private object $u) {}
            public function getTokenUsage(): object { return $this->u; }
        };
        self::assertSame(['prompt' => 120, 'completion' => 30], wppilot_chat_result_tokens($result));
        self::assertNull(wppilot_chat_result_tokens(new \stdClass()));
    }
}
