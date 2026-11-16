<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Ability;
use WP_Error;
use WP_Post;
use WPPilot_Test_State;

require_once dirname(__DIR__) . '/doubles/admin.php';
require_once dirname(__DIR__) . '/doubles/cloud.php';
require_once dirname(__DIR__, 2) . '/includes/file-write-guard.php';

/**
 * Write receipts: a wrapped write names the ledger row that records it and how to undo it, and
 * the row is the one the receipt names.
 */
final class WriteReceiptsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    /** @var array<int, mixed> */
    private array $savedPosts = [];

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedPosts = WPPilot_Test_State::$posts;
        unset(WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION]);
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        WPPilot_Test_State::$posts = $this->savedPosts;
    }

    public function testOnlyWppilotWritesAreWrapped(): void
    {
        $callback = static fn(array $input): array => ['ok' => true];
        $write = ['execute_callback' => $callback, 'input_schema' => ['type' => 'object', 'properties' => []]];
        $read = $write + ['meta' => ['annotations' => ['readonly' => true]]];

        self::assertNotSame($callback, wppilot_write_envelope_args($write, 'wppilot/update-option')['execute_callback']);
        self::assertSame($callback, wppilot_write_envelope_args($read, 'wppilot/get-option')['execute_callback']);
        self::assertSame($callback, wppilot_write_envelope_args($write, 'acme/update-thing')['execute_callback']);
        self::assertSame($callback, wppilot_write_envelope_args($write, 'wppilot/rollback-change')['execute_callback']);
    }

    public function testRenderKeyIsDeclaredOnPostWritesOnly(): void
    {
        $write = ['execute_callback' => static fn(): array => [], 'input_schema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]]];

        $post = wppilot_write_envelope_args($write, 'wppilot/update-post');
        self::assertSame('boolean', $post['input_schema']['properties']['verify_render']['type']);
        self::assertArrayHasKey('title', $post['input_schema']['properties']);

        $option = wppilot_write_envelope_args($write, 'wppilot/update-option');
        self::assertArrayNotHasKey('verify_render', $option['input_schema']['properties']);
    }

    public function testReceiptNamesTheRowTheLedgerWrites(): void
    {
        $name = 'wppilot/create-post';
        $seen = null;
        $args = wppilot_write_envelope_args([
            'execute_callback' => static function (array $input) use (&$seen): array {
                $seen = $input;
                return ['post_id' => 42, 'status' => 'draft'];
            },
            'input_schema' => ['type' => 'object', 'properties' => []],
        ], $name);

        $input = ['title' => 'Hello', 'status' => 'draft', 'verify_render' => true];
        $ability = new WP_Ability($name);
        wppilot_change_before($name, $input, $ability);
        $result = $args['execute_callback']($input);
        wppilot_change_after($name, $input, $result, $ability);

        self::assertSame(['title' => 'Hello', 'status' => 'draft'], $seen, 'The ability never sees the control key.');
        $receipt = $result['receipt'];
        self::assertSame(['post_id' => 42], $receipt['changed']['target']);
        self::assertSame(['status', 'title'], $receipt['changed']['fields']);
        self::assertTrue($receipt['reversible']);
        self::assertSame('wppilot/rollback-change', $receipt['undo']['ability']);
        self::assertSame(['checked' => false, 'reason' => 'no_post'], $receipt['render_check']);

        $row = wppilot_get_change((string) $receipt['change_id']);
        self::assertNotNull($row, 'The receipt names a row that exists.');
        self::assertSame('delete-created-post', $row['rollback']['type']);
        self::assertArrayNotHasKey('receipt', $row['result']);
    }

    public function testUnrecordedWriteSaysItCannotBeUndone(): void
    {
        $receipt = wppilot_write_receipt('wppilot/tests-unrecorded', [], ['ok' => true], verify_render: false);

        self::assertNull($receipt['change_id']);
        self::assertFalse($receipt['reversible']);
        self::assertArrayHasKey('undo_reason', $receipt);
        self::assertArrayNotHasKey('render_check', $receipt);
    }

    public function testBulkWriteReceiptNamesItsItemRows(): void
    {
        $receipt = wppilot_write_receipt('wppilot/tests-bulk', [], ['change_ids' => ['c1', 'c2', 7]], verify_render: false);

        self::assertArrayNotHasKey('change_id', $receipt);
        self::assertSame(['c1', 'c2'], $receipt['change_ids']);
        self::assertNull($receipt['reversible'], 'Each item is undone, or refused, on its own.');
        self::assertSame(['change_id' => 'c1'], $receipt['undo']['input']);
        self::assertArrayNotHasKey('undo_reason', $receipt);
    }

    public function testListResultsAndErrorsPassThroughUntouched(): void
    {
        $list = wppilot_write_envelope_args(['execute_callback' => static fn(): array => [1, 2]], 'wppilot/tests-list');
        self::assertSame([1, 2], $list['execute_callback']());

        $strict = wppilot_write_envelope_args([
            'execute_callback' => static fn(): array => ['ok' => true],
            'output_schema' => ['type' => 'object', 'additionalProperties' => false],
        ], 'wppilot/tests-strict');
        self::assertSame(['ok' => true], $strict['execute_callback'](), 'A closed result schema gets no receipt.');

        $error = new WP_Error('nope', 'No.');
        $failing = wppilot_write_envelope_args(['execute_callback' => static fn(): WP_Error => $error], 'wppilot/tests-error');
        self::assertSame($error, $failing['execute_callback']());
    }

    public function testRenderCheckSkipsPostsVisitorsCannotSee(): void
    {
        $draft = new WP_Post();
        $draft->ID = 7;
        $draft->post_status = 'draft';
        WPPilot_Test_State::$posts[7] = $draft;

        self::assertSame(['checked' => false, 'reason' => 'not_published', 'post_status' => 'draft'], wppilot_write_render_check(7));
        self::assertSame(['checked' => false, 'reason' => 'no_post'], wppilot_write_render_check(0));
    }

    public function testRenderVerdictReadsStatusAndCriticalError(): void
    {
        $url = 'https://example.test/hello/';
        $healthy = wppilot_write_render_verdict($url, ['response' => ['code' => 200], 'body' => '<p>Hello</p>']);
        self::assertTrue($healthy['ok']);
        self::assertFalse($healthy['critical_error']);
        self::assertSame(200, $healthy['status']);

        $broken = wppilot_write_render_verdict($url, ['response' => ['code' => 200], 'body' => 'There has been a critical error on this website.']);
        self::assertFalse($broken['ok']);
        self::assertTrue($broken['critical_error']);

        $server = wppilot_write_render_verdict($url, ['response' => ['code' => 500], 'body' => '']);
        self::assertFalse($server['ok']);
        self::assertFalse($server['critical_error']);
        self::assertSame('http_500', $server['reason']);

        $missing = wppilot_write_render_verdict($url, ['response' => ['code' => 404], 'body' => '']);
        self::assertFalse($missing['ok']);

        $down = wppilot_write_render_verdict($url, new WP_Error('http_request_failed', 'Timed out.'));
        self::assertFalse($down['reachable']);
    }
}
