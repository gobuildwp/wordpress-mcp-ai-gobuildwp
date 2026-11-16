<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Ability;
use WP_Error;
use WPPilot_Test_Sqlite_Wpdb;
use WPPilot_Test_State;

require_once dirname(__DIR__) . '/doubles/sqlite-wpdb.php';

/**
 * Idempotency keys: a retried write with the same key replays the first result and is not
 * recorded twice; different arguments, a running write and a bad key are refused.
 */
final class IdempotencyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    private mixed $savedWpdb = null;

    private WPPilot_Test_Sqlite_Wpdb $db;

    private int $runs = 0;

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        unset(WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION]);
        $this->db = new WPPilot_Test_Sqlite_Wpdb();
        $this->db->query('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name VARCHAR(191) NOT NULL UNIQUE, option_value TEXT NOT NULL, autoload VARCHAR(20) NOT NULL)');
        $GLOBALS['wpdb'] = $this->db;
        $this->runs = 0;
        wppilot_idempotency_claims('all');
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        $GLOBALS['wpdb'] = $this->savedWpdb;
        wppilot_idempotency_claims('all');
    }

    public function testKeyIsDeclaredOnWrites(): void
    {
        $args = wppilot_write_envelope_args(['execute_callback' => static fn(): array => [], 'input_schema' => ['type' => 'object', 'properties' => []]], 'wppilot/update-option');

        self::assertSame('string', $args['input_schema']['properties']['idempotency_key']['type']);
    }

    public function testRetryWithTheSameKeyReplaysTheFirstResultAndRecordsOnce(): void
    {
        $first = $this->call(['title' => 'Hello', 'idempotency_key' => 'abc-1']);
        $again = $this->call(['idempotency_key' => 'abc-1', 'title' => 'Hello']);

        self::assertSame(1, $this->runs, 'The write ran once.');
        self::assertArrayNotHasKey('replayed', $first);
        self::assertTrue($again['replayed']);
        self::assertSame($first['post_id'], $again['post_id']);
        self::assertSame($first['receipt']['change_id'], $again['receipt']['change_id']);
        self::assertCount(1, wppilot_get_change_log(), 'The replay is not a second ledger row.');
    }

    public function testSameKeyWithDifferentArgumentsIsAConflict(): void
    {
        $this->call(['title' => 'Hello', 'idempotency_key' => 'abc-2']);
        $other = $this->call(['title' => 'Other', 'idempotency_key' => 'abc-2']);

        self::assertInstanceOf(WP_Error::class, $other);
        self::assertSame('wppilot_idempotency_conflict', $other->get_error_code());
        self::assertSame(409, $other->get_error_data()['status']);
        self::assertSame(1, $this->runs);
    }

    public function testAKeyStillRunningIsRefusedAndAnUnfinishedRequestFreesIt(): void
    {
        self::assertNull(wppilot_idempotency_begin('wppilot/create-post', ['idempotency_key' => 'abc-3'], ['title' => 'x']));
        $second = wppilot_idempotency_begin('wppilot/create-post', ['idempotency_key' => 'abc-3'], ['title' => 'x']);
        self::assertSame('wppilot_idempotency_in_progress', $second->get_error_code());

        wppilot_idempotency_release_unfinished();
        self::assertNull(wppilot_idempotency_begin('wppilot/create-post', ['idempotency_key' => 'abc-3'], ['title' => 'x']));
    }

    public function testFailedWriteFreesTheKey(): void
    {
        $failing = wppilot_write_envelope_args([
            'execute_callback' => static fn(): WP_Error => new WP_Error('nope', 'No.'),
            'input_schema' => ['type' => 'object', 'properties' => []],
        ], 'wppilot/create-post');

        self::assertInstanceOf(WP_Error::class, $failing['execute_callback'](['idempotency_key' => 'abc-4']));
        self::assertNull(wppilot_idempotency_begin('wppilot/create-post', ['idempotency_key' => 'abc-4'], []));
    }

    public function testKeysAreScopedToTheAbilityAndExpire(): void
    {
        $this->call(['title' => 'Hello', 'idempotency_key' => 'abc-5']);
        self::assertNull(wppilot_idempotency_begin('wppilot/update-post', ['idempotency_key' => 'abc-5'], ['title' => 'Hello']));
        wppilot_idempotency_claims('all');

        $slot = wppilot_idempotency_slot('wppilot/create-post', 'abc-5');
        $record = wppilot_idempotency_read($slot);
        $record['exp'] = time() - 1;
        wppilot_idempotency_write($slot, $record);
        $this->call(['title' => 'Hello', 'idempotency_key' => 'abc-5']);
        self::assertSame(2, $this->runs, 'An expired key runs the write again.');
    }

    public function testBadKeyIsRefused(): void
    {
        $refused = wppilot_idempotency_begin('wppilot/create-post', ['idempotency_key' => 'has space'], []);

        self::assertSame('wppilot_idempotency_key_invalid', $refused->get_error_code());
    }

    public function testSweepRemovesOnlyExpiredRecords(): void
    {
        wppilot_idempotency_insert(WPPILOT_IDEMPOTENCY_PREFIX . 'old', ['exp' => time() - 10, 'state' => 'done']);
        wppilot_idempotency_insert(WPPILOT_IDEMPOTENCY_PREFIX . 'new', ['exp' => time() + 10, 'state' => 'done']);
        wppilot_idempotency_insert('_wppilot_idemX', ['exp' => 1]);

        wppilot_idempotency_sweep(again: true);

        self::assertSame(['_wppilot_idemX', WPPILOT_IDEMPOTENCY_PREFIX . 'new'], $this->db->get_col('SELECT option_name FROM wp_options ORDER BY option_name'));
    }

    /**
     * Drive one wrapped create-post the way WordPress does: before hook, execute, after hook.
     *
     * @param array<string, mixed> $input
     */
    private function call(array $input): mixed
    {
        $name = 'wppilot/create-post';
        $args = wppilot_write_envelope_args([
            'execute_callback' => function (array $input): array {
                $this->runs++;
                return ['post_id' => 100 + $this->runs, 'title' => (string) ($input['title'] ?? '')];
            },
            'input_schema' => ['type' => 'object', 'properties' => []],
        ], $name);
        $ability = new WP_Ability($name);
        wppilot_change_before($name, $input, $ability);
        $result = $args['execute_callback']($input);
        if (!$result instanceof WP_Error) {
            wppilot_change_after($name, $input, $result, $ability);
        }

        return $result;
    }
}
