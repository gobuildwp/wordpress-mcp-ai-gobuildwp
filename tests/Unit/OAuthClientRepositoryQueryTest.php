<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot\OAuth\Repositories\ClientRepository;

require_once dirname(__DIR__, 2) . '/includes/oauth/repositories/client-repository.php';

/**
 * The OAuth clients table is bound as a %i identifier in every read, and the
 * values still travel as %s/%d placeholders. The double quotes %i the way
 * wpdb::prepare() does on WordPress 6.2+ (backticks, inner backticks doubled).
 */
final class OAuthClientRepositoryQueryTest extends TestCase
{
    private mixed $savedWpdb = null;

    /** @var object{prefix: string, queries: list<string>, row: array<string, mixed>|null} */
    private object $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        $this->db = new class {
            public string $prefix = 'wp_';

            /** @var list<string> */
            public array $queries = [];

            /** @var array<string, mixed>|null */
            public ?array $row = null;

            public function prepare(string $query, mixed ...$args): string
            {
                if (count($args) === 1 && is_array($args[0])) {
                    $args = $args[0];
                }
                $args = array_values($args);
                $index = 0;
                return (string) preg_replace_callback('/%(%|i|d|s)/', static function (array $m) use (&$index, $args): string {
                    if ($m[1] === '%') {
                        return '%';
                    }
                    $value = $args[$index++] ?? '';
                    return match ($m[1]) {
                        'i' => '`' . str_replace('`', '``', (string) $value) . '`',
                        'd' => (string) (int) $value,
                        default => "'" . addslashes((string) $value) . "'",
                    };
                }, $query);
            }

            /** @return list<array<string, mixed>> */
            public function get_results(string $query, mixed $output = null): array
            {
                $this->queries[] = $query;
                return [];
            }

            public function get_var(string $query): ?string
            {
                $this->queries[] = $query;
                return 'abc';
            }

            /** @return array<string, mixed>|null */
            public function get_row(string $query, mixed $output = null): ?array
            {
                $this->queries[] = $query;
                return $this->row;
            }
        };
        $GLOBALS['wpdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->savedWpdb;
        parent::tearDown();
    }

    private static function squash(string $sql): string
    {
        return (string) preg_replace('/\s+/', ' ', $sql);
    }

    public function testEveryReadBindsTheClientsTableAsAnIdentifier(): void
    {
        $repository = new ClientRepository();

        self::assertSame([], $repository->list_admin_clients());
        self::assertSame([], $repository->list_recent(7));
        self::assertSame('abc', $repository->find_admin_client_id("Claude's desktop"));

        self::assertSame([
            'SELECT client_id, client_name, created_at, last_used_at FROM `wp_wppilot_oauth_clients` WHERE admin_created = 1 ORDER BY created_at DESC',
            'SELECT client_id, client_name, created_at, last_used_at FROM `wp_wppilot_oauth_clients` ORDER BY created_at DESC LIMIT 7',
            "SELECT client_id FROM `wp_wppilot_oauth_clients` WHERE admin_created = 1 AND client_name = 'Claude\\'s desktop' ORDER BY created_at DESC LIMIT 1",
        ], array_map(static fn(string $q): string => self::squash($q), $this->db->queries));
    }

    public function testAClientLookupBindsTheTableAndTheIdentifierSeparately(): void
    {
        $this->db->row = [
            'client_id' => 'c0ffee',
            'client_name' => 'Inspector',
            'redirect_uris' => '["http://localhost:6274/cb"]',
            'is_confidential' => '0',
        ];

        $client = (new ClientRepository())->getClientEntity('c0ffee');

        self::assertNotNull($client);
        self::assertSame('c0ffee', $client->getIdentifier());
        self::assertSame(
            "SELECT client_id, client_name, redirect_uris, is_confidential FROM `wp_wppilot_oauth_clients` WHERE client_id = 'c0ffee'",
            self::squash($this->db->queries[0]),
        );
    }
}
