<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\DbRead;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\DbRead\Sql;

/**
 * The gate every raw SELECT passes: what it lets through, what it refuses, and the evasions it
 * has to see through.
 */
final class SqlValidatorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/db-read/src/sql.php';
    }

    /**
     * A main site on a network with the usual prefix, a view, another plugin's table and a
     * second site's table.
     *
     * @return array{known: array<string, true>, allowed: array<string, string>, refused: array<string, string>}
     */
    private static function tables(string $prefix = 'wp_', bool $multisite = true): array
    {
        $denied = ['users', 'usermeta', 'options', 'sitemeta', 'signups', 'woocommerce_api_keys', 'woocommerce_payment_tokens', 'woocommerce_payment_tokenmeta'];
        $all = [
            'wp_posts' => 'BASE TABLE', 'wp_postmeta' => 'BASE TABLE', 'wp_comments' => 'BASE TABLE',
            'wp_users' => 'BASE TABLE', 'wp_usermeta' => 'BASE TABLE', 'wp_options' => 'BASE TABLE',
            'wp_users_backup' => 'BASE TABLE', 'wp_woocommerce_api_keys' => 'BASE TABLE',
            'wp_email_log' => 'BASE TABLE', 'wp_leakview' => 'VIEW', 'other_users' => 'BASE TABLE',
            'wp_2_posts' => 'BASE TABLE', 'wp_2_options' => 'BASE TABLE', 'wp_2fa_codes' => 'BASE TABLE',
        ];
        $tables = ['known' => [], 'allowed' => [], 'refused' => []];
        foreach ($all as $name => $type) {
            $reason = Sql\table_refusal($name, $type, $prefix, 'wp_', $multisite, $denied);
            $tables['known'][strtolower($name)] = true;
            if ($reason === '') {
                $tables['allowed'][strtolower($name)] = $name;
            } else {
                $tables['refused'][strtolower($name)] = $reason;
            }
        }
        return $tables;
    }

    /** @return array<string, array{0: string, 1?: list<string>}> */
    public static function accepted(): array
    {
        return [
            'plain select' => ["SELECT ID, post_title FROM wp_posts WHERE post_status = 'publish'", ['wp_posts']],
            'keywords in lower case, table in upper case' => ['select id from WP_POSTS', ['wp_posts']],
            'backtick names' => ['SELECT `ID` FROM `wp_posts`', ['wp_posts']],
            'doubled backtick inside a name' => ['SELECT 1 AS `a``b` FROM wp_posts', ['wp_posts']],
            'comment markers inside a backtick name' => ['SELECT `a--b#c/*d*/` FROM wp_posts', ['wp_posts']],
            'keywords and a refused table inside a string' => [
                "SELECT ID FROM wp_posts WHERE post_title = 'DROP TABLE wp_users; -- INTO OUTFILE /*! x */ # SLEEP(9)'",
                ['wp_posts'],
            ],
            'a quote written twice inside a string' => ["SELECT ID FROM wp_posts WHERE post_title = 'it''s'", ['wp_posts']],
            'a backtick inside a string' => ["SELECT '`wp_users`' AS s FROM wp_posts", ['wp_posts']],
            'join with aliases' => [
                "SELECT p.ID, m.meta_value FROM wp_posts p JOIN wp_postmeta m ON m.post_id = p.ID WHERE m.meta_key = '_thumbnail_id'",
                ['wp_posts', 'wp_postmeta'],
            ],
            'left outer join with AS' => [
                'SELECT p.ID, c.comment_ID FROM wp_posts AS p LEFT OUTER JOIN wp_comments AS c ON c.comment_post_ID = p.ID',
                ['wp_posts', 'wp_comments'],
            ],
            'comma join' => ['SELECT p.ID FROM wp_posts p, wp_postmeta m WHERE p.ID = m.post_id', ['wp_posts', 'wp_postmeta']],
            'subquery in WHERE' => [
                "SELECT ID FROM wp_posts WHERE ID IN (SELECT post_id FROM wp_postmeta WHERE meta_key = 'x')",
                ['wp_posts', 'wp_postmeta'],
            ],
            'derived table' => ['SELECT t.c FROM (SELECT COUNT(*) AS c FROM wp_posts) t', ['wp_posts']],
            'parenthesized join group' => ['SELECT 1 FROM (wp_posts p JOIN wp_postmeta m ON m.post_id = p.ID)', ['wp_posts', 'wp_postmeta']],
            'LATERAL derived table' => [
                'SELECT p.ID, x.n FROM wp_posts p, LATERAL (SELECT COUNT(*) AS n FROM wp_postmeta m WHERE m.post_id = p.ID) x',
                ['wp_posts', 'wp_postmeta'],
            ],
            'FROM inside EXTRACT is not a table' => [
                'SELECT EXTRACT(YEAR FROM post_date) AS y, COUNT(*) AS n FROM wp_posts GROUP BY y ORDER BY y LIMIT 10',
                ['wp_posts'],
            ],
            'FROM and FOR inside SUBSTRING' => ['SELECT SUBSTRING(post_title FROM 1 FOR 5) AS s FROM wp_posts', ['wp_posts']],
            'union of allowed tables' => ['SELECT ID FROM wp_posts UNION ALL SELECT comment_ID FROM wp_comments', ['wp_posts', 'wp_comments']],
            'WITH ROLLUP is not a CTE' => ['SELECT post_type, COUNT(*) AS n FROM wp_posts GROUP BY post_type WITH ROLLUP', ['wp_posts']],
            'one trailing semicolon' => ['SELECT 1;', []],
            'FROM DUAL' => ['SELECT 1 FROM DUAL', []],
            'JSON_TABLE' => ["SELECT j.a FROM JSON_TABLE('[1,2]', '$[*]' COLUMNS (a INT PATH '$')) AS j", []],
            'a plugin table that starts with a digit after the prefix' => ['SELECT * FROM wp_2fa_codes', ['wp_2fa_codes']],
            'sensitive column selected by its own name' => ['SELECT comment_author_email FROM wp_comments', ['wp_comments']],
            'sensitive column, qualified' => ['SELECT c.comment_author_email FROM wp_comments c', ['wp_comments']],
            'sensitive column after DISTINCT' => ['SELECT DISTINCT comment_author_email FROM wp_comments', ['wp_comments']],
            'star over a table with sensitive columns' => ['SELECT * FROM wp_comments', ['wp_comments']],
            'sensitive column in a derived table of the result' => ['SELECT * FROM (SELECT comment_author_email FROM wp_comments) d', ['wp_comments']],
            'a table whose name looks sensitive, as table and qualifier' => ['SELECT wp_email_log.id FROM wp_email_log', ['wp_email_log']],
            'window function with PARTITION BY' => [
                'SELECT ID, ROW_NUMBER() OVER (PARTITION BY post_type ORDER BY post_date) AS n FROM wp_posts',
                ['wp_posts'],
            ],
            'partition selection narrows an allowed table' => ['SELECT ID FROM wp_posts PARTITION (p0)', ['wp_posts']],
            'counting over a derived table' =>['SELECT COUNT(*) AS n FROM (SELECT comment_author_email FROM wp_comments) d', ['wp_comments']],
        ];
    }

    /**
     * @param list<string> $tables
     */
    #[DataProvider('accepted')]
    public function testAccepted(string $sql, array $tables = []): void
    {
        $result = Sql\validate($sql, self::tables());

        self::assertIsArray($result, $result instanceof WP_Error ? $result->get_error_message() : '');
        self::assertEqualsCanonicalizing($tables, $result['tables']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function rejected(): array
    {
        return [
            // Not one SELECT.
            'empty' => ['  ', 'empty'],
            'a write' => ['DELETE FROM wp_posts', 'single SELECT'],
            'a CTE' => ['WITH x AS (SELECT 1) SELECT * FROM x', 'single SELECT'],
            'a CTE in a subquery' => ['SELECT * FROM (WITH x AS (SELECT 1) SELECT * FROM x) y', 'WITH'],
            'two statements' => ['SELECT 1; SELECT 2', 'one statement'],
            'two semicolons' => ['SELECT 1;;', 'one statement'],
            'parenthesized first' => ['(SELECT 1)', 'single SELECT'],
            'TABLE statement' => ['TABLE wp_posts', 'single SELECT'],
            'TABLE inside a subquery' => ['SELECT * FROM (TABLE wp_posts) t', 'TABLE'],
            'SHOW' => ['SHOW TABLES', 'single SELECT'],

            // Comments of every kind.
            'double-dash comment' => ['SELECT 1 -- c', 'Comments'],
            'double dash without a space' => ['SELECT 1 --1', 'Comments'],
            'hash comment' => ['SELECT 1 #c', 'Comments'],
            'block comment' => ['SELECT 1/**/FROM wp_posts', 'Comments'],
            'executable comment' => ['SELECT /*!50000 1 */', 'Comments'],
            'MariaDB executable comment' => ['SELECT /*M!100000 1 */', 'Comments'],
            'optimizer hint' => ['SELECT /*+ MAX_EXECUTION_TIME(1) */ 1', 'Comments'],
            'comment after a backtick name' => ['SELECT `x` /* */ FROM wp_posts', 'Comments'],

            // Lexing that depends on the SQL mode.
            'backslash in a string' => ["SELECT 'a\\' , 1 FROM wp_posts", 'Backslashes'],
            'backslash escape of a quote' => ["SELECT ID FROM wp_posts WHERE post_title = 'x\\' UNION SELECT user_pass FROM wp_users -- '", 'Backslashes'],
            'double-quoted string' => ['SELECT "x"', 'Double quotes'],
            'double-quoted name' => ['SELECT * FROM "wp_users"', 'Double quotes'],
            'unterminated string' => ["SELECT 'abc", 'not closed'],
            'unterminated backtick' => ['SELECT `abc', 'not closed'],
            'empty backtick name' => ['SELECT `` FROM wp_posts', 'empty backtick'],
            'control character as whitespace' => ["SELECT 1\x0bFROM wp_users", 'Control characters'],
            'NUL byte' => ["SELECT 1\0", 'Control characters'],

            // Variables and escapes.
            'system variable' => ['SELECT @@version', 'Variables'],
            'user variable assignment' => ['SELECT @x := 1', 'Variables'],
            'placeholder' => ['SELECT ? FROM wp_posts', 'not allowed'],
            'ODBC escape' => ['SELECT {fn NOW()}', 'not allowed'],

            // Side effects, waits and locks.
            'INTO OUTFILE' => ["SELECT ID FROM wp_posts INTO OUTFILE '/tmp/x'", 'INTO'],
            'INTO a variable' => ['SELECT ID FROM wp_posts LIMIT 1 INTO x', 'INTO'],
            'LOAD_FILE' => ["SELECT LOAD_FILE('/etc/passwd')", 'LOAD_FILE'],
            'SLEEP' => ['SELECT SLEEP(10)', 'SLEEP'],
            'sleep in lower case' => ['select sleep(1)', 'SLEEP'],
            'SLEEP inside a subquery' => ['SELECT ID FROM wp_posts WHERE ID = (SELECT SLEEP(5))', 'SLEEP'],
            'BENCHMARK' => ["SELECT BENCHMARK(1000000, MD5('a'))", 'BENCHMARK'],
            'GET_LOCK' => ["SELECT GET_LOCK('a', 1)", 'GET_LOCK'],
            'FOR UPDATE' => ['SELECT ID FROM wp_posts FOR UPDATE', 'UPDATE'],
            'FOR SHARE' => ['SELECT ID FROM wp_posts FOR SHARE', 'Locking'],
            'LOCK IN SHARE MODE' => ['SELECT ID FROM wp_posts LOCK IN SHARE MODE', 'LOCK'],
            'PROCEDURE ANALYSE' => ['SELECT ID FROM wp_posts PROCEDURE ANALYSE()', 'PROCEDURE'],
            'NEXT VALUE FOR' => ['SELECT NEXT VALUE FOR s', 'Sequence'],
            'NEXTVAL' => ['SELECT NEXTVAL(s)', 'NEXTVAL'],
            'index hint' => ['SELECT ID FROM wp_posts USE INDEX (PRIMARY)', 'USE'],
            'FORCE INDEX' => ['SELECT ID FROM wp_posts FORCE INDEX (PRIMARY)', 'FORCE'],
            'NATURAL JOIN' => ['SELECT * FROM wp_posts NATURAL JOIN wp_postmeta', 'NATURAL'],

            // Refused and foreign tables, however they are named.
            'a denied table' => ['SELECT * FROM wp_users', 'refused'],
            'a denied table in backticks' => ['SELECT * FROM `wp_users`', 'refused'],
            'a denied table in upper case' => ['SELECT * FROM WP_USERS', 'refused'],
            'a copy of a denied table' => ['SELECT * FROM wp_users_backup', 'refused'],
            'a denied table in a subquery' => ['SELECT * FROM wp_posts WHERE post_author IN (SELECT ID FROM wp_users)', 'refused'],
            'a denied table in a scalar subquery' => ['SELECT (SELECT user_login FROM wp_users LIMIT 1) AS u', 'refused'],
            'a denied table through UNION' => ['SELECT ID FROM wp_posts UNION SELECT user_login FROM wp_users', 'refused'],
            'a denied table through UNION in backticks' => ['SELECT * FROM wp_posts WHERE 1 = 1 UNION SELECT * FROM `wp_users`', 'refused'],
            'a denied table in a comma join' => ['SELECT * FROM wp_posts, wp_users', 'refused'],
            'a denied table in a join group' => ['SELECT * FROM (wp_posts, wp_usermeta)', 'refused'],
            'a denied table in STRAIGHT_JOIN' => ['SELECT * FROM wp_posts AS p STRAIGHT_JOIN wp_users u ON 1', 'refused'],
            'a denied table as a column qualifier' => ['SELECT wp_users.ID FROM wp_posts', 'refused'],
            'options' => ["SELECT option_value FROM wp_options WHERE option_name = 'siteurl'", 'refused'],
            'WooCommerce API keys' => ['SELECT * FROM wp_woocommerce_api_keys', 'refused'],
            'a view' => ['SELECT * FROM wp_leakview', 'view'],
            'a table without the prefix' => ['SELECT * FROM other_users', 'prefix'],
            'another network site' => ['SELECT * FROM wp_2_posts', 'another site'],
            'another network site\'s options' => ['SELECT * FROM wp_2_options', 'another site'],
            'an unknown table' => ['SELECT * FROM wp_nonexistent', 'not one of this site'],
            'a table name with a trailing non-breaking space' => ["SELECT * FROM wp_users\u{00A0}", 'not one of this site'],
            'another database' => ['SELECT * FROM otherdb.wp_posts', 'without a database'],
            'this database, qualified' => ['SELECT * FROM `wordpress`.`wp_posts`', 'without a database'],
            'a table function' => ['SELECT * FROM foo(1)', 'not a table'],
            'FROM without a table' => ['SELECT 1 FROM', 'not followed by a table'],
            'JOIN without a table' => ['SELECT 1 FROM wp_posts JOIN', 'not followed by a table'],

            // System schemas.
            'information_schema' => ['SELECT * FROM information_schema.tables', 'system schema'],
            'information_schema in backticks and upper case' => ['SELECT * FROM `INFORMATION_SCHEMA`.`TABLES`', 'system schema'],
            'mysql.user' => ['SELECT * FROM mysql.user', 'system schema'],
            'performance_schema' => ['SELECT * FROM performance_schema.threads', 'system schema'],
            'sys' => ['SELECT * FROM sys.version', 'system schema'],
            'system schema through UNION' => ['SELECT ID FROM wp_posts UNION SELECT table_name FROM information_schema.tables', 'system schema'],

            // Structure.
            'unbalanced open' => ['SELECT * FROM (SELECT 1', 'never closed'],
            'unbalanced close' => ['SELECT 1)', 'never opened'],
            'UNION followed by VALUES' => ['SELECT 1 UNION VALUES ROW(1)', 'followed by SELECT'],

            // Sensitive columns under another name, or used rather than selected.
            'renamed with AS' => ['SELECT comment_author_email AS e FROM wp_comments', 'looks sensitive'],
            'renamed without AS' => ['SELECT comment_author_email e FROM wp_comments', 'looks sensitive'],
            'inside a function' => ['SELECT CONCAT(comment_author_email) FROM wp_comments', 'looks sensitive'],
            'hashed' => ['SELECT MD5(post_password) FROM wp_posts', 'looks sensitive'],
            'filtered on' => ["SELECT comment_ID FROM wp_comments WHERE comment_author_email LIKE 'a%'", 'looks sensitive'],
            'sorted on' => ['SELECT comment_ID FROM wp_comments ORDER BY comment_author_email', 'looks sensitive'],
            'grouped on' => ['SELECT COUNT(*) AS n FROM wp_comments GROUP BY comment_author_IP', 'looks sensitive'],
            'joined on' => ['SELECT c.comment_ID FROM wp_comments c JOIN wp_comments d ON c.comment_author_IP = d.comment_author_IP', 'looks sensitive'],
            'scalar subquery' => ['SELECT (SELECT comment_author_email FROM wp_comments LIMIT 1) AS x', 'looks sensitive'],
            'renamed inside a derived table' => ['SELECT e FROM (SELECT comment_author_email AS e FROM wp_comments) d', 'looks sensitive'],
            'renamed outside a derived table' => ['SELECT d.comment_author_email AS x FROM (SELECT comment_author_email FROM wp_comments) d', 'looks sensitive'],
            'second UNION branch' => ['SELECT ID FROM wp_posts UNION SELECT comment_author_email FROM wp_comments', 'looks sensitive'],
            'first UNION branch' => ['SELECT comment_author_email FROM wp_comments UNION SELECT post_title FROM wp_posts', 'looks sensitive'],
            'parenthesized UNION branch' => ['SELECT ID FROM wp_posts UNION (SELECT comment_author_email FROM wp_comments)', 'looks sensitive'],
            'derived table inside a scalar subquery' => [
                'SELECT (SELECT * FROM (SELECT comment_author_email FROM wp_comments) d LIMIT 1) AS x',
                'looks sensitive',
            ],
            'set operation inside IN' => [
                'SELECT ID FROM wp_posts WHERE post_title IN ((SELECT post_title FROM wp_posts) UNION SELECT comment_author_email FROM wp_comments)',
                'looks sensitive',
            ],
            'USING a sensitive column' => ['SELECT c.comment_ID FROM wp_comments c JOIN wp_comments d USING (comment_author_email)', 'looks sensitive'],
            'the PASSWORD function' => ["SELECT PASSWORD('x')", 'looks sensitive'],
        ];
    }

    #[DataProvider('rejected')]
    public function testRejected(string $sql, string $because): void
    {
        $result = Sql\validate($sql, self::tables());

        self::assertInstanceOf(WP_Error::class, $result, 'accepted: ' . $sql);
        self::assertSame('kit_sql_rejected', $result->get_error_code());
        self::assertStringContainsStringIgnoringCase($because, $result->get_error_message());
    }

    /**
     * On a network subsite the prefix is wp_2_, so the main site's tables — and the global users
     * table, which carries only the base prefix — are someone else's.
     */
    public function testASubsiteReadsOnlyItsOwnPrefix(): void
    {
        $tables = self::tables('wp_2_');

        self::assertIsArray(Sql\validate('SELECT * FROM wp_2_posts', $tables));
        self::assertInstanceOf(WP_Error::class, Sql\validate('SELECT * FROM wp_posts', $tables));
        self::assertInstanceOf(WP_Error::class, Sql\validate('SELECT * FROM wp_users', $tables));
        self::assertInstanceOf(WP_Error::class, Sql\validate('SELECT * FROM wp_2_options', $tables));
    }

    public function testASingleSiteMayReadNumberedTables(): void
    {
        self::assertIsArray(Sql\validate('SELECT * FROM wp_2_posts', self::tables('wp_', false)));
    }

    public function testTheStatementSentIsTheOneChecked(): void
    {
        $result = Sql\validate("  SELECT ID FROM wp_posts ORDER BY ID DESC ;\n", self::tables());

        self::assertIsArray($result);
        self::assertSame('SELECT ID FROM wp_posts ORDER BY ID DESC', $result['sql']);
        self::assertTrue($result['order_without_limit']);
        self::assertFalse(Sql\validate('SELECT ID FROM wp_posts ORDER BY ID LIMIT 5', self::tables())['order_without_limit']);
        self::assertFalse(
            Sql\validate('SELECT * FROM (SELECT ID FROM wp_posts ORDER BY ID) t', self::tables())['order_without_limit'],
            'only the outer ORDER BY is the caller\'s order',
        );
    }

    public function testLongStatementsAreRefused(): void
    {
        $result = Sql\validate('SELECT ' . str_repeat('1+', 6000) . '1', self::tables());

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertStringContainsString('longer than', $result->get_error_message());
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function names(): array
    {
        return [
            'user_pass' => ['user_pass', true],
            'post_password' => ['post_password', true],
            'user_activation_key' => ['user_activation_key', true],
            'comment_author_email' => ['comment_author_email', true],
            'comment_author_IP' => ['comment_author_IP', true],
            'session_token' => ['session_token', true],
            'consumer_secret' => ['consumer_secret', true],
            'truncated_key hash' => ['password_hash', true],
            'api_key' => ['apiKey', true],
            'meta_key is ordinary' => ['meta_key', false],
            'meta_value is ordinary' => ['meta_value', false],
            'post_author is ordinary' => ['post_author', false],
            'option_name is ordinary' => ['option_name', false],
            'ID is ordinary' => ['ID', false],
            'zip is not ip' => ['zip', false],
            'description is ordinary' => ['description', false],
        ];
    }

    #[DataProvider('names')]
    public function testSensitiveNames(string $name, bool $sensitive): void
    {
        self::assertSame($sensitive, Sql\is_sensitive_name($name));
    }

    public function testTheTimeoutFollowsTheServer(): void
    {
        $mysql = Sql\wrap('SELECT 1', 50, 5000, Sql\parse_server_version('8.0.36'));
        self::assertSame('SELECT /*+ MAX_EXECUTION_TIME(5000) */ * FROM (SELECT 1) AS kit_q LIMIT 50', $mysql['sql']);
        self::assertSame(5000, $mysql['timeout_ms']);

        $maria = Sql\wrap('SELECT 1', 50, 5000, Sql\parse_server_version('10.11.6-MariaDB-1:10.11.6+maria~ubu2204'));
        self::assertSame('SET STATEMENT max_statement_time=5 FOR SELECT * FROM (SELECT 1) AS kit_q LIMIT 50', $maria['sql']);

        $replication = Sql\parse_server_version('5.5.5-10.6.12-MariaDB');
        self::assertSame(['flavor' => 'mariadb', 'version' => '10.6.12'], $replication);

        $old = Sql\wrap('SELECT 1', 50, 5000, Sql\parse_server_version('5.6.51'));
        self::assertSame('SELECT * FROM (SELECT 1) AS kit_q LIMIT 50', $old['sql']);
        self::assertNull($old['timeout_ms']);

        self::assertSame('SET STATEMENT max_statement_time=2.5 FOR SELECT * FROM (SELECT 1) AS kit_q LIMIT 1', Sql\wrap('SELECT 1', 1, 2500, ['flavor' => 'mariadb', 'version' => '10.5.0'])['sql']);
    }
}
