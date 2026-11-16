<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot_Test_Sqlite_Wpdb;
use WPPilot_Test_State;

require_once dirname(__DIR__) . '/doubles/admin.php';
require_once dirname(__DIR__) . '/doubles/mcp-surface.php';
require_once dirname(__DIR__) . '/doubles/sqlite-wpdb.php';
require_once dirname(__DIR__) . '/doubles/cloud.php';
require_once dirname(__DIR__, 2) . '/includes/capabilities.php';

/**
 * Device sign-in: short-lived, single-use, hashed codes an administrator approves in wp-admin.
 */
final class DeviceAuthTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    private mixed $savedWpdb = null;

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        foreach (array_keys(WPPilot_Test_State::$options) as $option) {
            if (str_starts_with((string) $option, 'wppilot_device_')) {
                unset(WPPilot_Test_State::$options[$option]);
            }
        }
        unset(WPPilot_Test_State::$options[WPPILOT_DEVICE_INDEX_OPTION], WPPilot_Test_State::$options[WPPILOT_TOKENS_SCHEMA_OPTION]);
        $GLOBALS['wpdb'] = new WPPilot_Test_Sqlite_Wpdb();
        $GLOBALS['wppilot_test_managers'] = [1];
        WPPilot_Test_State::$current_user_id = 1;
        WPPilot_Test_State::$capabilities = ['manage_options'];
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        WPPilot_Test_State::$current_user_id = 0;
        WPPilot_Test_State::$capabilities = [];
        $GLOBALS['wpdb'] = $this->savedWpdb;
        unset($GLOBALS['wppilot_test_managers']);
    }

    public function testApprovedCodeYieldsOneScopedTokenAndNothingIsStoredInPlainText(): void
    {
        $started = wppilot_device_start('Codex CLI', '203.0.113.5');
        self::assertIsArray($started);
        self::assertMatchesRegularExpression('/^[BCDFGHJKLMNPQRSTVWXZ]{4}-[BCDFGHJKLMNPQRSTVWXZ]{4}$/', $started['user_code']);
        self::assertSame(600, $started['expires_in']);
        self::assertSame(5, $started['interval']);
        self::assertStringContainsString('page=' . WPPILOT_DEVICE_ADMIN_PAGE, $started['verification_uri']);
        self::assertStringContainsString('user_code=', $started['verification_uri_complete']);

        $stored = serialize(WPPilot_Test_State::$options);
        self::assertStringNotContainsString($started['device_code'], $stored);
        self::assertStringNotContainsString($started['user_code'], $stored);
        self::assertStringNotContainsString(str_replace('-', '', $started['user_code']), $stored);

        $this->assertPollError('authorization_pending', $started['device_code']);

        // Typed in lower case without the hyphen still matches.
        $pending = wppilot_device_find_by_user_code(strtolower(str_replace('-', ' ', $started['user_code'])));
        self::assertNotNull($pending);
        self::assertSame('Codex CLI', $pending['client']);
        self::assertSame('203.0.113.5', $pending['ip']);

        self::assertTrue(wppilot_device_decide($started['user_code'], true, 1, 'readonly', 7));
        self::assertFalse(wppilot_device_decide($started['user_code'], false, 1), 'A code is decided once.');

        $this->expirePollInterval($started['device_code']);
        $token = wppilot_device_poll($started['device_code']);
        self::assertIsArray($token);
        self::assertStringStartsWith('wpp_', $token['access_token']);
        self::assertSame('Bearer', $token['token_type']);
        self::assertSame(7 * DAY_IN_SECONDS, $token['expires_in']);

        $identity = wppilot_token_authenticate($token['access_token']);
        self::assertIsArray($identity);
        self::assertSame(1, (int) $identity['user_id']);
        self::assertSame('readonly', wppilot_token_policy((int) $identity['id'])['ceiling']);

        // Single use: the code is gone.
        $this->assertPollError('invalid_grant', $started['device_code']);
        self::assertNull(wppilot_device_find_by_user_code($started['user_code']));
        self::assertArrayNotHasKey(WPPILOT_DEVICE_INDEX_OPTION, WPPilot_Test_State::$options);
    }

    public function testDeniedCodeIsRefusedOnce(): void
    {
        $started = wppilot_device_start('', '');
        self::assertIsArray($started);
        self::assertTrue(wppilot_device_decide($started['user_code'], false, 1));

        $this->assertPollError('access_denied', $started['device_code']);
        $this->assertPollError('invalid_grant', $started['device_code']);
    }

    public function testExpiredCodeIsRefusedAndCannotBeApproved(): void
    {
        $started = wppilot_device_start('Agent', '');
        self::assertIsArray($started);
        $option = wppilot_device_record_option(wppilot_device_code_hash($started['device_code']));
        WPPilot_Test_State::$options[$option]['expires'] = time() - 1;

        self::assertFalse(wppilot_device_decide($started['user_code'], true, 1, '', 30));
        $this->assertPollError('expired_token', $started['device_code']);
    }

    public function testPollingTooFastSlowsTheClientDown(): void
    {
        $started = wppilot_device_start('Agent', '');
        self::assertIsArray($started);

        $this->assertPollError('authorization_pending', $started['device_code']);
        $this->assertPollError('slow_down', $started['device_code']);
        $option = wppilot_device_record_option(wppilot_device_code_hash($started['device_code']));
        self::assertSame(10, WPPilot_Test_State::$options[$option]['interval']);
    }

    public function testApprovalNeedsAManagerAndAKnownCeiling(): void
    {
        $started = wppilot_device_start('Agent', '');
        self::assertIsArray($started);

        self::assertFalse(wppilot_device_decide($started['user_code'], true, 1, 'developer', 30));
        self::assertFalse(wppilot_device_decide($started['user_code'], true, 99, '', 30));
        self::assertFalse(wppilot_device_decide('BCDF-GHJK', true, 1, '', 30));
        self::assertNotNull(wppilot_device_find_by_user_code($started['user_code']), 'A refused decision leaves the code pending.');
    }

    public function testPendingCodesAreCapped(): void
    {
        for ($i = 0; $i < WPPILOT_DEVICE_MAX_PENDING_PER_IP; $i++) {
            self::assertIsArray(wppilot_device_start('Agent', '198.51.100.7'));
        }
        $refused = wppilot_device_start('Agent', '198.51.100.7');
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('slow_down', $refused->get_error_code());

        self::assertIsArray(wppilot_device_start('Agent', '198.51.100.8'));
    }

    public function testUnknownDeviceCodeAndBadGrantType(): void
    {
        $this->assertPollError('invalid_grant', 'nope');
        $this->assertPollError('invalid_grant', '');

        $response = wppilot_device_rest_token(new \WP_REST_Request(null, ['grant_type' => 'authorization_code', 'device_code' => 'x']));
        self::assertSame(400, $response->status);
        self::assertSame('unsupported_grant_type', $response->data['error']);
        self::assertSame('no-store', $response->headers['Cache-Control']);

        $response = wppilot_device_rest_token(new \WP_REST_Request(null, ['grant_type' => WPPILOT_DEVICE_GRANT_TYPE, 'device_code' => 'x']));
        self::assertSame('invalid_grant', $response->data['error']);
    }

    private function assertPollError(string $code, string $device_code): void
    {
        $result = wppilot_device_poll($device_code);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame($code, $result->get_error_code());
    }

    private function expirePollInterval(string $device_code): void
    {
        $option = wppilot_device_record_option(wppilot_device_code_hash($device_code));
        WPPilot_Test_State::$options[$option]['last_poll'] = 0;
    }
}
