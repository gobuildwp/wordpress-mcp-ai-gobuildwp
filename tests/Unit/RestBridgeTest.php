<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot_Test_State;

require_once dirname(__DIR__) . '/doubles/admin.php';

/**
 * The REST bridge behind wppilot/rest-request: namespace switches, routes that never open,
 * confirmation and ledger rows for writes, and response shaping.
 */
final class RestBridgeTest extends TestCase
{
    private const NAMESPACES = ['wp/v2', 'wc/v3', 'wc', 'batch/v1', 'wp-abilities/v1', 'mcp', 'wppilot/v1', 'gobuildwp/v1'];

    /** @var array<string, mixed> */
    private array $savedOptions = [];

    /** @var list<array{string, string, array, array}> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        unset(WPPilot_Test_State::$options[WPPILOT_REST_BRIDGE_OPTION], WPPilot_Test_State::$options[WPPILOT_REST_BRIDGE_STATS_OPTION]);
        if (defined('WPPILOT_CONFIRMATION_MODE_OPTION')) {
            unset(WPPilot_Test_State::$options[WPPILOT_CONFIRMATION_MODE_OPTION]);
        }
        wppilot_confirmation_note(WPPILOT_REST_BRIDGE_ABILITY, clear: true);
        $this->dispatched = [];
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
    }

    /** @param array<string, mixed> $response */
    private function dispatcher(array $response): callable
    {
        return function (string $method, string $route, array $query, array $body) use ($response): array {
            $this->dispatched[] = [$method, $route, $query, $body];

            return $response + ['status' => 200, 'headers' => [], 'data' => null, 'echoed' => ''];
        };
    }

    /** @return array<string, mixed> */
    private static function routes(): array
    {
        return [
            '/wp/v2/posts/(?P<id>[\d]+)' => [
                ['methods' => ['GET' => true], 'permission_callback' => 'get_item_permissions_check'],
                ['methods' => ['POST' => true, 'PUT' => true, 'PATCH' => true], 'permission_callback' => 'update_item_permissions_check'],
            ],
            '/wc/v3/open' => [['methods' => ['POST' => true], 'permission_callback' => '__return_true']],
            '/wc/v3/nocheck' => [['methods' => ['DELETE' => true]]],
        ];
    }

    private static function enable(string $namespace, bool $read, bool $write): void
    {
        $settings = is_array(WPPilot_Test_State::$options[WPPILOT_REST_BRIDGE_OPTION] ?? null) ? WPPilot_Test_State::$options[WPPILOT_REST_BRIDGE_OPTION] : [];
        $settings[$namespace] = ['read' => $read, 'write' => $write];
        WPPilot_Test_State::$options[WPPILOT_REST_BRIDGE_OPTION] = $settings;
    }

    /** @param array<string, mixed> $input */
    private function callBridge(array $input, array $response = []): array|WP_Error
    {
        return wppilot_rest_bridge_execute($input, $this->dispatcher($response), self::NAMESPACES, self::routes());
    }

    public function testRoutesAreNormalizedAndMatchTheLongestNamespace(): void
    {
        self::assertSame('/wp/v2/posts', wppilot_rest_bridge_normalize_route(' /wp/v2/posts/ '));
        foreach (['wp/v2/posts', '/wp/v2/posts?x=1', '/wp/v2/../v2/users', '/wp//v2', '/wp/v2/posts#a', "/wp/v2/\nposts", '/wp\\v2'] as $bad) {
            self::assertInstanceOf(WP_Error::class, wppilot_rest_bridge_normalize_route($bad), $bad);
        }
        self::assertSame('wc/v3', wppilot_rest_bridge_namespace_for('/wc/v3/orders', self::NAMESPACES));
        self::assertSame('wc', wppilot_rest_bridge_namespace_for('/wc/store', self::NAMESPACES));
        self::assertSame('wp/v2', wppilot_rest_bridge_namespace_for('/WP/V2/posts', self::NAMESPACES));
        self::assertNull(wppilot_rest_bridge_namespace_for('/wp/v20/posts', self::NAMESPACES));
    }

    public function testEveryNamespaceStartsOffAndTheErrorNamesTheSwitch(): void
    {
        $error = wppilot_rest_bridge_check('POST', '/wc/v3/orders', self::NAMESPACES, wppilot_rest_bridge_settings());
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('wppilot_rest_namespace_disabled', $error->get_error_code());
        self::assertSame('write', $error->get_error_data()['switch']);
        self::assertStringContainsString('tick Write for wc/v3', $error->get_error_message());

        self::enable('wc/v3', true, false);
        self::assertSame(['namespace' => 'wc/v3', 'write' => false], wppilot_rest_bridge_check('GET', '/wc/v3/orders', self::NAMESPACES, wppilot_rest_bridge_settings()));
        $error = wppilot_rest_bridge_check('DELETE', '/wc/v3/orders/5', self::NAMESPACES, wppilot_rest_bridge_settings());
        self::assertInstanceOf(WP_Error::class, $error, 'Read alone must not allow a write');
        self::assertSame('write', $error->get_error_data()['switch']);
    }

    public function testClosedNamespacesAndNeverWritableRoutesIgnoreTheSwitches(): void
    {
        foreach (['batch/v1', 'wp-abilities/v1', 'mcp', 'wppilot/v1', 'gobuildwp/v1', 'wppilot-pro/v1'] as $namespace) {
            self::assertNotNull(wppilot_rest_bridge_closed_namespace($namespace), $namespace);
        }
        self::assertNull(wppilot_rest_bridge_closed_namespace('wc/v3'));

        WPPilot_Test_State::$options[WPPILOT_REST_BRIDGE_OPTION] = ['batch/v1' => ['read' => true, 'write' => true], 'wp/v2' => ['read' => true, 'write' => true]];
        self::assertArrayNotHasKey('batch/v1', wppilot_rest_bridge_settings());
        $error = wppilot_rest_bridge_check('POST', '/batch/v1', self::NAMESPACES, ['batch/v1' => ['read' => true, 'write' => true]]);
        self::assertSame('wppilot_rest_namespace_closed', $error instanceof WP_Error ? $error->get_error_code() : null);

        foreach (['/wp/v2/users', '/wp/v2/users/1', '/wp/v2/users/me/application-passwords', '/wp/v2/settings', '/wp/v2/plugins/akismet/akismet'] as $route) {
            $error = wppilot_rest_bridge_check('POST', $route, self::NAMESPACES, wppilot_rest_bridge_settings());
            self::assertSame('wppilot_rest_route_never_writable', $error instanceof WP_Error ? $error->get_error_code() : null, $route);
        }
        self::assertIsArray(wppilot_rest_bridge_check('GET', '/wp/v2/users', self::NAMESPACES, wppilot_rest_bridge_settings()));
        self::assertIsArray(wppilot_rest_bridge_check('POST', '/wp/v2/usersettings', self::NAMESPACES, wppilot_rest_bridge_settings()));
    }

    public function testAReadReturnsRedactedDataAndIsCountedButNotLedgered(): void
    {
        self::enable('wp/v2', true, false);
        $before = count(wppilot_get_change_log());
        $result = $this->callBridge(
            ['method' => 'get', 'route' => '/wp/v2/posts/12', 'query' => ['_fields' => 'id']],
            ['data' => ['id' => 12, 'meta' => ['api_key' => 'sk-live', 'colour' => 'red'], 'items' => [(object) ['password' => 'x']]], 'headers' => ['X-WP-Total' => '40', 'Content-Type' => 'application/json; charset=UTF-8']],
        );

        self::assertIsArray($result);
        self::assertSame(200, $result['status']);
        self::assertSame('[redacted]', $result['data']['meta']['api_key']);
        self::assertSame('red', $result['data']['meta']['colour']);
        self::assertSame('[redacted]', $result['data']['items'][0]['password']);
        self::assertSame(40, $result['total']);
        self::assertSame([['GET', '/wp/v2/posts/12', ['_fields' => 'id'], []]], $this->dispatched);
        self::assertCount($before, wppilot_get_change_log());
        self::assertSame(1, wppilot_rest_bridge_stats()['wp/v2']['reads']);
    }

    public function testAWriteNeedsConfirmAndIsLedgeredAsNotUndoable(): void
    {
        self::enable('wp/v2', false, true);
        $input = ['method' => 'POST', 'route' => '/wp/v2/posts/12', 'body' => ['title' => 'New']];

        $refused = $this->callBridge($input);
        self::assertSame('wppilot_confirmation_required', $refused instanceof WP_Error ? $refused->get_error_code() : null);
        self::assertSame([], $this->dispatched);
        self::assertSame(1, wppilot_rest_bridge_stats()['wp/v2']['refused']);

        $before = count(wppilot_get_change_log());
        $result = $this->callBridge($input + ['confirm' => true], ['status' => 200, 'data' => ['id' => 12]]);
        self::assertIsArray($result);
        self::assertTrue($result['ok']);
        $log = wppilot_get_change_log();
        self::assertCount($before + 1, $log);
        $row = end($log);
        self::assertSame(WPPILOT_REST_BRIDGE_ABILITY, $row['ability']);
        self::assertSame('/wp/v2/posts/12', $row['input']['route']);
        self::assertSame(hash('sha256', (string) wp_json_encode(['title' => 'New'])), $row['input']['body_sha256']);
        self::assertArrayNotHasKey('body', $row['input']);
        self::assertSame(200, $row['result']['status']);
        self::assertFalse($row['rollback']['reversible']);
        self::assertSame('argument', $row['confirmation']['method'] ?? null);
        self::assertSame(1, wppilot_rest_bridge_stats()['wp/v2']['writes']);

        // A failed write is recorded too: the route may have changed something first.
        $this->callBridge($input + ['confirm' => true], ['status' => 500, 'data' => ['code' => 'boom']]);
        $log = wppilot_get_change_log();
        self::assertSame(500, end($log)['result']['status']);
    }

    public function testWritesToRoutesWithoutTheirOwnPermissionCheckFailClosed(): void
    {
        self::enable('wc/v3', true, true);
        foreach ([['POST', '/wc/v3/open'], ['DELETE', '/wc/v3/nocheck']] as [$method, $route]) {
            $error = $this->callBridge(['method' => $method, 'route' => $route, 'confirm' => true]);
            self::assertSame('wppilot_rest_route_unguarded', $error instanceof WP_Error ? $error->get_error_code() : null, $route);
        }
        $error = $this->callBridge(['method' => 'PATCH', 'route' => '/wc/v3/missing', 'confirm' => true]);
        self::assertSame('wppilot_rest_no_route', $error instanceof WP_Error ? $error->get_error_code() : null);
        self::assertSame([], $this->dispatched);
    }

    public function testNoncesMethodOverridesAndBodiesOnReadsAreRefused(): void
    {
        self::enable('wp/v2', true, true);
        foreach ([['query' => ['_wpnonce' => 'abc']], ['body' => ['_method' => 'DELETE'], 'method' => 'POST', 'confirm' => true], ['query' => ['_ENVELOPE' => 1]]] as $extra) {
            $error = $this->callBridge(['route' => '/wp/v2/posts/12'] + $extra);
            self::assertSame('wppilot_rest_reserved_param', $error instanceof WP_Error ? $error->get_error_code() : null);
        }
        $error = $this->callBridge(['route' => '/wp/v2/posts/12', 'body' => ['title' => 'x']]);
        self::assertSame('wppilot_rest_body_on_read', $error instanceof WP_Error ? $error->get_error_code() : null);
        self::assertSame([], $this->dispatched);
    }

    public function testNonJsonIsRefusedAndLargeResponsesAreLeftOut(): void
    {
        self::assertSame('wppilot_rest_not_json', wppilot_rest_bridge_shape(200, ['Content-Type' => 'text/html'], '<p>x</p>', '')->get_error_code());
        self::assertSame('wppilot_rest_not_json', wppilot_rest_bridge_shape(200, [], ['a' => 1], '<html>')->get_error_code());

        $big = wppilot_rest_bridge_shape(200, [], ['blob' => str_repeat('a', WPPILOT_REST_BRIDGE_MAX_BYTES)], '');
        self::assertIsArray($big);
        self::assertTrue($big['truncated']);
        self::assertNull($big['data']);
        self::assertGreaterThan(WPPILOT_REST_BRIDGE_MAX_BYTES, $big['bytes']);

        $error = wppilot_rest_bridge_shape(404, [], ['code' => 'rest_post_invalid_id'], '');
        self::assertIsArray($error);
        self::assertFalse($error['ok']);
    }

    public function testSavingSwitchesIsLedgeredKeepsAbsentNamespacesAndSkipsClosedOnes(): void
    {
        self::enable('legacy/v1', true, false);
        $before = count(wppilot_get_change_log());

        self::assertTrue(wppilot_rest_bridge_save_settings(['wp/v2', 'batch/v1'], ['wc/v3', 'mcp'], self::NAMESPACES));
        self::assertSame(
            ['legacy/v1' => ['read' => true, 'write' => false], 'wc/v3' => ['read' => false, 'write' => true], 'wp/v2' => ['read' => true, 'write' => false]],
            wppilot_rest_bridge_settings(),
        );
        $log = wppilot_get_change_log();
        self::assertCount($before + 1, $log);
        $row = end($log);
        self::assertSame(WPPILOT_REST_BRIDGE_LEDGER_NAME, $row['ability']);
        self::assertFalse($row['rollback']['reversible']);
        self::assertContains(['namespace' => 'wc/v3', 'switch' => 'write', 'on' => true], $row['result']['changes']);

        self::assertFalse(wppilot_rest_bridge_save_settings(['wp/v2'], ['wc/v3'], self::NAMESPACES));
        self::assertCount($before + 1, wppilot_get_change_log());
    }
}
