<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function WPPilot\Cli\McpServe\handle_line;

require_once dirname(__DIR__, 2) . '/includes/cli/mcp-serve.php';

/**
 * The message handling of `wp wppilot mcp serve`, against a recording router: what reaches the
 * adapter, what goes back on stdout, and who the ledger is told is on the other end.
 *
 * The loop and the adapter itself are exercised over a real process on a real site; see the
 * release verification. These pin the parts a regression would break silently.
 */
final class StdioServeTest extends TestCase
{
    protected function tearDown(): void
    {
        wppilot_session_basis(['kind' => '', 'key' => '']);
    }

    public function testInitializeNamesTheClientForTheLedger(): void
    {
        $router = $this->router(['protocolVersion' => '2025-06-18', '_session_id' => 'adapter-made-this']);
        $agent = ['method' => 'stdio', 'credential' => 'stdio-user-1', 'label' => 'WP-CLI stdio (admin)', 'client' => '', 'client_version' => ''];

        $line = handle_line($router, (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'claude-code', 'version' => '2.1']],
        ]), $agent, 'stdio-abc');

        $response = json_decode($line, true);
        self::assertSame(1, $response['id']);
        self::assertSame('2025-06-18', $response['result']['protocolVersion']);
        self::assertArrayNotHasKey('_session_id', $response['result'], 'no HTTP session leaks into a stdio answer');
        self::assertSame('claude-code', $agent['client']);
        self::assertSame('stdio-abc', wppilot_current_session_id());
        self::assertSame([['initialize', 'stdio']], array_map(static fn(array $call): array => [$call[0], $call[3]], $router->calls));
    }

    public function testANotificationIsRoutedButNotAnswered(): void
    {
        $router = $this->router([]);
        $agent = ['method' => 'stdio', 'credential' => '', 'label' => '', 'client' => '', 'client_version' => ''];

        self::assertSame('', handle_line($router, '{"jsonrpc":"2.0","method":"notifications/initialized"}', $agent, 's'));
        self::assertCount(1, $router->calls);
    }

    public function testAnEmptyResultIsAnObjectAndAnErrorIsAnError(): void
    {
        $agent = ['method' => 'stdio', 'credential' => '', 'label' => '', 'client' => '', 'client_version' => ''];

        self::assertSame('{"jsonrpc":"2.0","id":7,"result":{}}', handle_line($this->router([]), '{"jsonrpc":"2.0","id":7,"method":"ping"}', $agent, 's'));

        $error = json_decode(handle_line($this->router(['error' => ['code' => -32601, 'message' => 'Method not found']]), '{"jsonrpc":"2.0","id":"x","method":"nope"}', $agent, 's'), true);
        self::assertSame('x', $error['id']);
        self::assertSame(-32601, $error['error']['code']);
    }

    public function testMalformedInputIsAnsweredWithoutReachingTheAdapter(): void
    {
        $router = $this->router([]);
        $agent = ['method' => 'stdio', 'credential' => '', 'label' => '', 'client' => '', 'client_version' => ''];

        self::assertSame(-32700, json_decode(handle_line($router, 'not json', $agent, 's'), true)['error']['code']);
        self::assertSame(-32700, json_decode(handle_line($router, '[{"jsonrpc":"2.0","id":1,"method":"ping"}]', $agent, 's'), true)['error']['code'], 'batches are refused');
        self::assertSame(-32600, json_decode(handle_line($router, '{"jsonrpc":"1.0","id":1,"method":"ping"}', $agent, 's'), true)['error']['code']);
        self::assertSame([], $router->calls);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function router(array $result): object
    {
        return new class ($result) {
            /** @var list<array{0: string, 1: array<string, mixed>, 2: mixed, 3: string}> */
            public array $calls = [];

            /** @param array<string, mixed> $result */
            public function __construct(private array $result)
            {
            }

            /**
             * @param array<string, mixed> $params
             * @return array<string, mixed>
             */
            public function route_request(string $method, array $params, mixed $id, string $transport): array
            {
                $this->calls[] = [$method, $params, $id, $transport];
                return $this->result;
            }
        };
    }
}
