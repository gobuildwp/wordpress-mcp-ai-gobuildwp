<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Tests\Unit\Kits\SecurityRecovery;

use PHPUnit\Framework\TestCase;
use WPPilot_Test_State;

use function GoBuildWP\Kits\SecurityRecovery\activity_since;

require_once dirname(__DIR__, 4) . '/includes/kits/security-recovery/src/commerce.php';

/**
 * gobuildwp/commerce-activity-since against a scripted $wpdb: which tables and
 * columns each count reads, WooCommerce on and off, HPOS on and off, form
 * plugin tables only when present, and null for a count whose query failed.
 */
final class CommerceActivityTest extends TestCase
{
    private const SINCE = 1_767_225_600; // 2026-01-01 00:00:00 UTC

    private const NOW = 1_767_312_000;

    /** @var array<string, mixed> */
    private array $savedOptions = [];

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        WPPilot_Test_State::$multisite = false;
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        WPPilot_Test_State::$multisite = false;
        parent::tearDown();
    }

    public function testWithoutWooCommerceOrdersAndCustomersAreNull(): void
    {
        $db = new CountingWpdb();
        $result = activity_since(self::SINCE, self::NOW, woo: false, db: $db);

        self::assertSame([
            'since' => self::SINCE,
            'now' => self::NOW,
            'woocommerce' => false,
            'orders' => null,
            'customers' => null,
            'users' => 3,
            'comments' => 4,
            'posts_changed' => 5,
            'form_entries' => [],
        ], $result);
        self::assertSame([], $db->matching('wc_orders'));
        self::assertSame([], $db->matching('capabilities'));

        // Every count is a bounded COUNT(*) from the given time, never a row.
        foreach ($db->queries as $sql) {
            if (str_starts_with($sql, 'SHOW TABLES')) {
                continue;
            }
            self::assertStringStartsWith('SELECT /*+ MAX_EXECUTION_TIME(5000) */ COUNT(*) FROM ', $sql);
        }
        self::assertStringContainsString("user_registered >= '2026-01-01 00:00:00'", $db->matching('wp_users')[0]);
        self::assertStringContainsString("comment_date_gmt >= '2026-01-01 00:00:00'", $db->matching('wp_comments')[0]);
        self::assertStringContainsString("comment_approved IN ('0', '1')", $db->matching('wp_comments')[0]);
        self::assertStringContainsString("NOT IN ('order_note', 'webhook_delivery')", $db->matching('wp_comments')[0]);
        $posts = $db->matching('post_modified_gmt')[0];
        self::assertStringContainsString("post_type IN ('post', 'page', 'product')", $posts);
        self::assertStringContainsString("post_status IN ('publish', 'private', 'draft')", $posts);
    }

    public function testWithHposOrdersComeFromTheOrdersTable(): void
    {
        $db = new CountingWpdb();
        $result = activity_since(self::SINCE, self::NOW, woo: true, hpos: true, db: $db);

        self::assertTrue($result['woocommerce']);
        self::assertSame(1, $result['orders']);
        self::assertSame(2, $result['customers']);
        $orders = $db->matching('wp_wc_orders');
        self::assertCount(1, $orders);
        self::assertStringContainsString("type = 'shop_order' AND date_created_gmt >= '2026-01-01 00:00:00'", $orders[0]);
        self::assertStringContainsString("status NOT IN ('auto-draft', 'checkout-draft', 'wc-checkout-draft')", $orders[0]);
        self::assertSame([], $db->matching('post_date_gmt'));

        $customers = $db->matching('wp_usermeta')[0];
        self::assertStringContainsString("meta_key = 'wp_capabilities'", $customers);
        self::assertStringContainsString("LIKE '%\\\"customer\\\"%'", $customers);
    }

    public function testWithoutHposOrdersComeFromPosts(): void
    {
        $db = new CountingWpdb();
        $result = activity_since(self::SINCE, self::NOW, woo: true, hpos: false, db: $db);

        self::assertSame(1, $result['orders']);
        self::assertSame([], $db->matching('wc_orders'));
        $orders = $db->matching('post_date_gmt');
        self::assertCount(1, $orders);
        self::assertStringContainsString("FROM wp_posts WHERE post_type = 'shop_order' AND post_date_gmt >= '2026-01-01 00:00:00'", $orders[0]);
    }

    public function testHposIsReadFromTheOptionWithoutOrderUtil(): void
    {
        WPPilot_Test_State::$options['woocommerce_custom_orders_table_enabled'] = 'yes';
        $db = new CountingWpdb();
        activity_since(self::SINCE, self::NOW, woo: true, db: $db);
        self::assertCount(1, $db->matching('wp_wc_orders'));

        WPPilot_Test_State::$options['woocommerce_custom_orders_table_enabled'] = 'no';
        $db = new CountingWpdb();
        activity_since(self::SINCE, self::NOW, woo: true, db: $db);
        self::assertSame([], $db->matching('wc_orders'));
    }

    public function testAFailedQueryGivesNullAndTheRestStillAnswer(): void
    {
        $db = new CountingWpdb();
        $db->failing = 'wp_comments';
        $result = activity_since(self::SINCE, self::NOW, woo: true, hpos: true, db: $db);

        self::assertNull($result['comments']);
        self::assertSame(3, $result['users']);
        self::assertSame(1, $result['orders']);
        self::assertSame(5, $result['posts_changed']);
    }

    public function testFormEntriesOnlyForTablesThatExist(): void
    {
        WPPilot_Test_State::$options['gmt_offset'] = 2;
        $db = new CountingWpdb();
        $db->tables = ['wp_wpforms_entries', 'wp_fluentform_submissions'];
        $result = activity_since(self::SINCE, self::NOW, woo: false, db: $db);

        self::assertSame(['wpforms' => 6, 'fluentforms' => 8], $result['form_entries']);
        self::assertStringContainsString("FROM wp_wpforms_entries WHERE date >= '2026-01-01 00:00:00'", $db->matching('wpforms_entries')[0]);
        // Fluent Forms dates are in the site's time zone (UTC+2 here).
        self::assertStringContainsString("FROM wp_fluentform_submissions WHERE created_at >= '2026-01-01 02:00:00'", $db->matching('FROM wp_fluentform')[0]);
        self::assertSame([], $db->matching('FROM wp_gf_entry'));

        // A form table whose count fails is left out rather than reported as zero.
        $db = new CountingWpdb();
        $db->tables = ['wp_gf_entry'];
        $db->failing = 'FROM wp_gf_entry';
        self::assertSame([], activity_since(self::SINCE, self::NOW, woo: false, db: $db)['form_entries']);
    }

    public function testOnANetworkOnlyThisSitesUsersCount(): void
    {
        WPPilot_Test_State::$multisite = true;
        $db = new CountingWpdb();
        $db->blog_prefix = 'wp_3_';
        $result = activity_since(self::SINCE, self::NOW, woo: true, hpos: true, db: $db);

        self::assertSame(2, $result['customers']);
        foreach ($db->matching('wp_usermeta') as $sql) {
            self::assertStringContainsString("meta_key = 'wp_3_capabilities'", $sql);
        }
        self::assertCount(2, $db->matching('wp_usermeta'));
    }
}

/**
 * Answers each COUNT by which table it reads, and records every query.
 */
final class CountingWpdb
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $users = 'wp_users';
    public string $usermeta = 'wp_usermeta';
    public string $comments = 'wp_comments';
    public string $blog_prefix = 'wp_';
    public string $last_error = '';

    /** @var list<string> */
    public array $queries = [];

    /** @var list<string> tables SHOW TABLES finds */
    public array $tables = [];

    /** A fragment of the SQL that should fail, or '' for none. */
    public string $failing = '';

    public function get_blog_prefix(): string
    {
        return $this->blog_prefix;
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $index = 0;
        return (string) preg_replace_callback('/%[ds]/', static function (array $match) use (&$index, $args): string {
            $value = $args[$index++] ?? '';
            return $match[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
        }, $query);
    }

    public function get_var(string $query): mixed
    {
        $this->queries[] = $query;
        $this->last_error = '';
        if ($this->failing !== '' && str_contains($query, $this->failing)) {
            $this->last_error = 'Query execution was interrupted, maximum statement execution time exceeded';
            return null;
        }
        if (str_starts_with($query, 'SHOW TABLES LIKE ')) {
            // Undo prepare() and esc_like(): the pattern names one table exactly.
            $name = str_replace(['\_', '\%'], ['_', '%'], stripslashes(trim(substr($query, strlen('SHOW TABLES LIKE ')), "'")));
            return in_array($name, $this->tables, true) ? $name : null;
        }
        $answers = [
            'wc_orders' => '1',
            'post_date_gmt' => '1',
            'wp_usermeta' => str_contains($query, 'customer') ? '2' : '3',
            'wp_users' => '3',
            'wp_comments' => '4',
            'post_modified_gmt' => '5',
            'wpforms_entries' => '6',
            'gf_entry' => '7',
            'fluentform_submissions' => '8',
        ];
        foreach ($answers as $fragment => $answer) {
            if (str_contains($query, $fragment)) {
                return $answer;
            }
        }
        return '0';
    }

    /** @return list<string> the queries containing $fragment */
    public function matching(string $fragment): array
    {
        return array_values(array_filter($this->queries, static fn(string $sql): bool => str_contains($sql, $fragment)));
    }
}
