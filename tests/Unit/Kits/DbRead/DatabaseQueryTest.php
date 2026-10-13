<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\DbRead;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\DbRead;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Host;
use WPPilot\Kits\Runtime\Jobs;
use WPPilot\Kits\Runtime\Ledger;
use WPPilot\Kits\Runtime\ProfileGate;

/**
 * wppilot/database-tables and wppilot/database-query against a recording database.
 */
final class DatabaseQueryTest extends TestCase
{
    private mixed $savedWpdb = null;

    /** @var object{queries: list<string>, rows: list<array<string, mixed>>, error: string, refuse_transaction: bool, version: string} */
    private object $db;

    public static string $profileAnswer = 'allow';

    public static function setUpBeforeClass(): void
    {
        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
        require_once __DIR__ . '/registrations.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/db-read/bootstrap.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/db-read/src/abilities/database-tables.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/db-read/src/abilities/database-query.php';
    }

    protected function setUp(): void
    {
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        $this->db = self::recordingDatabase();
        $GLOBALS['wpdb'] = $this->db;
        Runtime\host(self::host());
        self::$profileAnswer = 'allow';
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->savedWpdb;
        remove_all_filters('wppilot_kit_db_read_denied_tables');
    }

    public function testTheAbilitiesAreRegisteredWithTheirLiteralNames(): void
    {
        self::assertTrue(wp_has_ability('wppilot/database-tables'));
        self::assertTrue(wp_has_ability('wppilot/database-query'));
    }

    /**
     * Both are Developer-only reads that are audited, and both enforce that themselves.
     */
    public function testBothDeclareAndEnforceTheDeveloperProfile(): void
    {
        foreach (['wppilot/database-tables', 'wppilot/database-query'] as $name) {
            $args = self::registration($name);
            self::assertSame(['min_profile' => 'developer', 'audit_reads' => true], $args['meta']['safety']);
            self::assertTrue($args['meta']['annotations']['readonly']);

            self::$profileAnswer = 'allow';
            self::assertTrue(($args['permission_callback'])());
            self::$profileAnswer = 'deny';
            $denied = ($args['permission_callback'])();
            self::assertInstanceOf(WP_Error::class, $denied);
            self::assertSame($name, $denied->get_error_message(), 'the host was asked about this ability');
        }
    }

    /**
     * The checked SELECT runs inside a read-only transaction that is always rolled back, capped,
     * with the server's own timeout.
     */
    public function testTheQueryRunsReadOnlyCappedAndTimed(): void
    {
        $this->db->rows = [['ID' => '1', 'post_title' => 'Hello']];

        $result = DbRead\query(['sql' => 'SELECT ID, post_title FROM wp_posts;', 'limit' => 500]);

        self::assertIsArray($result);
        self::assertSame([
            'START TRANSACTION READ ONLY',
            'SELECT /*+ MAX_EXECUTION_TIME(5000) */ * FROM (SELECT ID, post_title FROM wp_posts) AS kit_q LIMIT 200',
            'ROLLBACK',
        ], $this->queriesAfterSetup());
        self::assertSame(200, $result['limit']);
        self::assertSame(['wp_posts'], $result['tables']);
        self::assertSame(5000, $result['timeout_ms']);
        self::assertSame([['ID' => '1', 'post_title' => 'Hello']], $result['rows']);
    }

    public function testMariaDbGetsItsOwnTimeout(): void
    {
        $this->db->version = '10.11.6-MariaDB';

        DbRead\query(['sql' => 'SELECT ID FROM wp_posts']);

        self::assertSame(
            'SET STATEMENT max_statement_time=5 FOR SELECT * FROM (SELECT ID FROM wp_posts) AS kit_q LIMIT 50',
            $this->queriesAfterSetup()[1],
        );
    }

    public function testAnOuterOrderKeepsItsOrderThroughTheDerivedTable(): void
    {
        DbRead\query(['sql' => 'SELECT ID FROM wp_posts ORDER BY ID DESC', 'limit' => 10]);

        self::assertStringContainsString('(SELECT ID FROM wp_posts ORDER BY ID DESC LIMIT 10) AS kit_q', $this->queriesAfterSetup()[1]);
    }

    public function testARejectedQueryNeverReachesTheDatabase(): void
    {
        $result = DbRead\query(['sql' => 'SELECT user_pass FROM wp_users']);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame([], $this->queriesAfterSetup());
    }

    public function testADatabaseErrorStillRollsBack(): void
    {
        $this->db->error = "Duplicate column name 'ID'";

        $result = DbRead\query(['sql' => 'SELECT p.ID, m.post_id AS ID FROM wp_posts p JOIN wp_postmeta m ON m.post_id = p.ID']);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertStringContainsString('unique name', $result->get_error_message());
        self::assertSame('ROLLBACK', $this->queriesAfterSetup()[2]);
    }

    public function testNoReadOnlyTransactionMeansNoQuery(): void
    {
        $this->db->refuse_transaction = true;

        $result = DbRead\query(['sql' => 'SELECT ID FROM wp_posts']);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('kit_db_read_transaction', $result->get_error_code());
        self::assertSame(['START TRANSACTION READ ONLY'], $this->queriesAfterSetup());
    }

    public function testSensitiveColumnsAndSecretMetaAreRedactedAndLongCellsCut(): void
    {
        $this->db->rows = [
            ['comment_ID' => '1', 'comment_author_email' => 'a@example.test', 'comment_content' => str_repeat('x', 1500)],
            ['comment_ID' => '2', 'comment_author_email' => null, 'comment_content' => 'short'],
        ];

        $result = DbRead\query(['sql' => 'SELECT * FROM wp_comments']);

        self::assertSame(['comment_author_email'], $result['redacted_columns']);
        self::assertSame('[redacted]', $result['rows'][0]['comment_author_email']);
        self::assertNull($result['rows'][1]['comment_author_email'], 'an empty value says nothing and stays empty');
        self::assertSame(1001, mb_strlen($result['rows'][0]['comment_content']));
        self::assertSame(1, $result['truncated_cells']);

        $this->db->rows = [
            ['meta_key' => '_stripe_secret', 'meta_value' => 'sk_live_x'],
            ['meta_key' => '_thumbnail_id', 'meta_value' => '42'],
        ];
        $meta = DbRead\query(['sql' => 'SELECT meta_key, meta_value FROM wp_postmeta']);
        self::assertSame('[redacted]', $meta['rows'][0]['meta_value']);
        self::assertSame('42', $meta['rows'][1]['meta_value']);
    }

    public function testTheDeniedListGrowsThroughTheFilterButNeverShrinks(): void
    {
        add_filter('wppilot_kit_db_read_denied_tables', static fn(array $names): array => ['wc_customer_lookup']);

        $denied = DbRead\denied_tables();

        self::assertContains('wc_customer_lookup', $denied);
        self::assertContains('users', $denied);
        self::assertInstanceOf(WP_Error::class, DbRead\query(['sql' => 'SELECT * FROM wp_wc_customer_lookup']));
    }

    public function testTablesAreListedWithTheirRefusalsAndColumns(): void
    {
        $result = DbRead\list_tables([]);

        self::assertIsArray($result);
        $byName = array_column($result['tables'], null, 'name');
        self::assertSame(['wp_comments', 'wp_postmeta', 'wp_posts', 'wp_users', 'wp_wc_customer_lookup'], array_keys($byName));
        self::assertTrue($byName['wp_posts']['queryable']);
        self::assertFalse($byName['wp_users']['queryable']);
        self::assertStringContainsString('credentials', $byName['wp_users']['refusal']);
        self::assertSame(1, $result['other_tables_hidden'], 'another plugin\'s table is not described');
        self::assertSame(12345, $byName['wp_posts']['rows_estimate']);
        self::assertSame(['ID', 'post_password'], array_column($byName['wp_posts']['columns'], 'name'));
        self::assertTrue($byName['wp_posts']['columns'][1]['redacted_in_results']);

        $narrow = DbRead\list_tables(['search' => 'post', 'include_columns' => false]);
        self::assertSame(['wp_postmeta', 'wp_posts'], array_column($narrow['tables'], 'name'));
        self::assertArrayNotHasKey('columns', $narrow['tables'][0]);
    }

    /** @return list<string> */
    private function queriesAfterSetup(): array
    {
        return array_values(array_filter(
            $this->db->queries,
            static fn(string $sql): bool => !str_contains($sql, 'information_schema') && $sql !== 'SELECT VERSION()',
        ));
    }

    /** @return array<string, mixed> */
    private static function registration(string $name): array
    {
        return Registrations::$args[$name] ?? self::fail("{$name} was not registered");
    }

    private static function recordingDatabase(): object
    {
        return new class {
            public string $prefix = 'wp_';
            public string $base_prefix = 'wp_';
            public string $last_error = '';
            public string $error = '';
            public bool $refuse_transaction = false;
            public string $version = '8.0.36';
            /** @var list<string> */
            public array $queries = [];
            /** @var list<array<string, mixed>> */
            public array $rows = [];
            private bool $suppress = false;

            public function suppress_errors(bool $suppress = true): bool
            {
                $previous = $this->suppress;
                $this->suppress = $suppress;
                return $previous;
            }

            public function query(string $sql): int|bool
            {
                $this->queries[] = $sql;
                $this->last_error = '';
                if ($this->refuse_transaction && str_starts_with($sql, 'START')) {
                    $this->last_error = 'not supported';
                    return false;
                }
                return 0;
            }

            public function get_var(string $sql): ?string
            {
                $this->queries[] = $sql;
                return $sql === 'SELECT VERSION()' ? $this->version : null;
            }

            /** @param list<string> $args */
            public function prepare(string $sql, array $args): string
            {
                return vsprintf(str_replace('%s', "'%s'", $sql), $args);
            }

            /** @return list<array<string, mixed>> */
            public function get_results(string $sql, string $output = 'OBJECT'): array
            {
                $this->queries[] = $sql;
                $this->last_error = '';
                if (str_contains($sql, 'information_schema.TABLES')) {
                    $table = static fn(string $name, string $type = 'BASE TABLE'): array => [
                        'name' => $name, 'type' => $type, 'engine' => 'InnoDB', 'rows_estimate' => $name === 'wp_posts' ? '12345' : '0',
                        'data_bytes' => '16384', 'index_bytes' => '0', 'collation' => 'utf8mb4_unicode_ci',
                    ];
                    return [$table('other_plugin_log'), $table('wp_comments'), $table('wp_postmeta'), $table('wp_posts'), $table('wp_users'), $table('wp_wc_customer_lookup')];
                }
                if (str_contains($sql, 'information_schema.COLUMNS')) {
                    return [
                        ['table_name' => 'wp_posts', 'name' => 'ID', 'type' => 'bigint unsigned', 'nullable' => 'NO', 'column_key' => 'PRI'],
                        ['table_name' => 'wp_posts', 'name' => 'post_password', 'type' => 'varchar(255)', 'nullable' => 'NO', 'column_key' => ''],
                    ];
                }
                if ($this->error !== '') {
                    $this->last_error = $this->error;
                    return [];
                }
                return $this->rows;
            }
        };
    }

    private static function host(): Host
    {
        return new class implements Host, ProfileGate {
            public function profile_allows(string $ability_name): bool|WP_Error
            {
                return DatabaseQueryTest::$profileAnswer === 'allow' ? true : new WP_Error('kit_safety_profile_blocked', $ability_name);
            }

            public function id(): string
            {
                return 'test';
            }

            public function can_manage(): bool
            {
                return true;
            }

            public function is_enabled(): bool
            {
                return true;
            }

            public function safety_profile(): string
            {
                return 'developer';
            }

            public function ledger(): Ledger
            {
                throw new \LogicException('not used');
            }

            public function jobs(): Jobs
            {
                throw new \LogicException('not used');
            }

            public function extension(string $point): mixed
            {
                return null;
            }

            public function admin_parent_slug(): string
            {
                return 'tools.php';
            }

            public function confirm_guard(string $ability_name, array $input): bool|WP_Error
            {
                return true;
            }
        };
    }
}
