<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot_Test_Sqlite_Wpdb;
use WPPilot_Test_State;

require_once dirname(__DIR__) . '/doubles/sqlite-wpdb.php';
require_once dirname(__DIR__) . '/doubles/mcp-surface.php';

/**
 * Session undo and redo over the real ledger table (on SQLite), with a key-value "site" whose
 * writes, restores and current state are fully observable.
 *
 * The site is a registered rollback strategy plus the two extension filters a third-party type
 * uses to take part in the conflict check (current state and target), so these tests exercise the
 * same paths a Pro or kit strategy takes, and none of the built-in WordPress ones.
 */
final class SessionUndoTest extends TestCase
{
    private const TYPE = 'tests/kv';

    /** @var array<string, mixed> */
    private array $savedOptions = [];

    private mixed $savedWpdb = null;

    private mixed $savedFilters = null;

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        $this->savedFilters = $GLOBALS['wp_filter'] ?? null;
        foreach ([
            WPPILOT_CHANGE_LOG_OPTION,
            WPPILOT_CHANGES_SCHEMA_OPTION,
            WPPILOT_CHANGES_STORAGE_OPTION,
            WPPILOT_CHANGES_MIGRATION_OPTION,
            WPPILOT_CHANGES_ERROR_OPTION,
        ] as $option) {
            unset(WPPilot_Test_State::$options[$option]);
        }
        $GLOBALS['wpdb'] = new WPPilot_Test_Sqlite_Wpdb();
        $GLOBALS['wppilot_test_kv'] = [];
        $GLOBALS['wppilot_test_kv_fail'] = [];
        $GLOBALS['wppilot_test_transients'] = [];

        wppilot_register_rollback_strategy(self::TYPE, static function (array $payload): array {
            $snapshot = $payload['snapshot'];
            $key = (string) $snapshot['key'];
            if (in_array($key, $GLOBALS['wppilot_test_kv_fail'], true)) {
                // A restore that runs but does not land.
                return ['verified' => false, 'mismatched' => ['value']];
            }
            if (($snapshot['exists'] ?? true) === false) {
                unset($GLOBALS['wppilot_test_kv'][$key]);
            } else {
                $GLOBALS['wppilot_test_kv'][$key] = $snapshot['value'];
            }
            return ['verified' => self::kv($key)['fingerprint'] === $snapshot['fingerprint']];
        });
        add_filter('wppilot_change_current_state', static function (mixed $state, array $rollback): mixed {
            return ($rollback['type'] ?? '') === self::TYPE ? self::kv((string) $rollback['snapshot']['key']) : $state;
        }, 10, 2);
        add_filter('wppilot_change_payload_target', static function (mixed $target, array $rollback): mixed {
            return ($rollback['type'] ?? '') === self::TYPE ? 'kv:' . $rollback['snapshot']['key'] : $target;
        }, 10, 2);

        self::assertSame('table', wppilot_changes_install_and_migrate()['storage']);
        $this->inSession('stdio-session-a');
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        $GLOBALS['wpdb'] = $this->savedWpdb;
        if ($this->savedFilters === null) {
            unset($GLOBALS['wp_filter']);
        } else {
            $GLOBALS['wp_filter'] = $this->savedFilters;
        }
        wppilot_session_basis(['kind' => '', 'key' => '']);
    }

    public function testUndoSessionRestoresEveryWriteNewestFirstAndRedoPutsThemBack(): void
    {
        // A person's edit before the session: recorded, but in no session.
        $this->inSession('');
        $this->write('title', 'Hello');
        $GLOBALS['wppilot_test_kv']['color'] = 'blue';
        $this->inSession('stdio-session-a');
        $this->write('title', 'Agent title');
        $this->write('title', 'Agent title 2');
        $this->write('color', 'red');
        $this->write('footer', 'new footer');

        $report = wppilot_undo_session('stdio-session-a');

        self::assertIsArray($report);
        self::assertSame('completed', $report['status'], $report['message']);
        self::assertCount(4, $report['undone']);
        self::assertSame(['title' => 'Hello', 'color' => 'blue'], $GLOBALS['wppilot_test_kv'], 'back to exactly before the session, the person\'s edit kept');

        $redo = wppilot_redo_session('stdio-session-a');
        self::assertIsArray($redo);
        self::assertSame('completed', $redo['status'], $redo['message']);
        self::assertSame(['title' => 'Agent title 2', 'color' => 'red', 'footer' => 'new footer'], $GLOBALS['wppilot_test_kv']);

        self::assertSame('nothing-to-do', wppilot_redo_session('stdio-session-a')['status']);
        $again = wppilot_undo_session('stdio-session-a');
        self::assertSame('completed', $again['status'], 'a redone session can be undone again');
    }

    public function testOnlyTheNamedSessionIsUndone(): void
    {
        $this->write('a', 'one');
        $this->inSession('stdio-session-b');
        $this->write('b', 'two');

        $report = wppilot_undo_session('stdio-session-a');

        self::assertSame('completed', $report['status']);
        self::assertSame(['b' => 'two'], $GLOBALS['wppilot_test_kv']);
    }

    public function testAnEditMadeAfterTheSessionRefusesTheWholeRunAndNamesIt(): void
    {
        $this->write('title', 'Agent');
        $this->write('color', 'red');
        // Someone else writes the title afterwards, through GoBuildWP, in another session.
        $this->inSession('stdio-session-b');
        $this->write('title', 'Person');

        $report = wppilot_undo_session('stdio-session-a');

        self::assertSame('refused', $report['status']);
        self::assertSame([], $report['undone']);
        self::assertSame(['title' => 'Person', 'color' => 'red'], $GLOBALS['wppilot_test_kv'], 'nothing was touched, not even the unconflicted color');
        self::assertCount(1, $report['conflicts']);
        self::assertSame('kv:title', $report['conflicts'][0]['target']);
        self::assertSame('changed-since', $report['conflicts'][0]['kind']);
        self::assertSame('stdio-session-b', $report['conflicts'][0]['later_changes'][0]['session_id']);
    }

    public function testAnEditOutsideTheLedgerBetweenTwoSessionWritesIsAConflict(): void
    {
        $this->write('title', 'First');
        $GLOBALS['wppilot_test_kv']['title'] = 'Changed by hand'; // No ledger row at all.
        $this->write('title', 'Second');

        $report = wppilot_undo_session('stdio-session-a');

        self::assertSame('refused', $report['status']);
        self::assertSame('changed-between', $report['conflicts'][0]['kind']);
        self::assertSame('Second', $GLOBALS['wppilot_test_kv']['title']);
    }

    public function testAChangeThatCannotBeUndoneIsNeverSkippedSilently(): void
    {
        $this->write('title', 'Agent');
        wppilot_store_change($this->row('irreversible', ['reversible' => false, 'reason' => 'The file was deleted.']));

        $refused = wppilot_undo_session('stdio-session-a');
        self::assertSame('refused', $refused['status']);
        self::assertSame('The file was deleted.', $refused['not_reversible'][0]['reason']);
        self::assertSame('Agent', $GLOBALS['wppilot_test_kv']['title']);

        $partial = wppilot_undo_session('stdio-session-a', allow_partial: true);
        self::assertSame('completed', $partial['status']);
        self::assertCount(1, $partial['undone']);
        self::assertCount(1, $partial['not_reversible'], 'still reported when allowed');
        self::assertStringContainsString('could not be undone', $partial['message']);
    }

    public function testARegisteredTypeWithoutAStrategyIsReportedNotSkipped(): void
    {
        $this->write('title', 'Agent');
        // A row whose plugin is gone: reversible on paper, no restore registered for its type.
        wppilot_store_change($this->row('orphan', ['reversible' => true, 'type' => 'gone/plugin', 'snapshot' => ['x' => 1]]));

        $report = wppilot_undo_session('stdio-session-a');

        self::assertSame('stopped', $report['status']);
        self::assertSame('orphan', $report['failed']['change_id']);
        self::assertSame('wppilot_rollback_unknown', $report['failed']['code']);
        self::assertSame([], $report['undone'], 'the orphan is newest, so it is first and nothing ran before it');
        self::assertCount(1, $report['not_attempted']);
        self::assertSame('Agent', $GLOBALS['wppilot_test_kv']['title']);
        self::assertNotEmpty($report['unchecked'], 'and it is listed as a change no conflict check covered');
    }

    public function testTheRunStopsAtTheFirstChangeThatDoesNotVerify(): void
    {
        $this->write('a', '1');
        $this->write('b', '2');
        $this->write('c', '3');
        $GLOBALS['wppilot_test_kv_fail'] = ['b'];

        $report = wppilot_undo_session('stdio-session-a');

        self::assertSame('stopped', $report['status']);
        self::assertCount(1, $report['undone'], 'c, the newest, was undone');
        self::assertSame('wppilot_rollback_unverified', $report['failed']['code']);
        self::assertCount(1, $report['not_attempted'], 'a was never touched');
        self::assertSame(['a' => '1', 'b' => '2'], $GLOBALS['wppilot_test_kv']);
        self::assertStringContainsString('Stopped at change', $report['message']);

        // Redo puts back what the stopped run undid.
        $GLOBALS['wppilot_test_kv_fail'] = [];
        $redo = wppilot_redo_session('stdio-session-a');
        self::assertSame('completed', $redo['status'], $redo['message']);
        self::assertSame(['a' => '1', 'b' => '2', 'c' => '3'], $GLOBALS['wppilot_test_kv']);
    }

    public function testRedoIsRefusedWhenTheTargetWasEditedAfterTheUndo(): void
    {
        $this->write('title', 'Agent');
        self::assertSame('completed', wppilot_undo_session('stdio-session-a')['status']);
        $GLOBALS['wppilot_test_kv']['title'] = 'Written by a person after the undo';

        $redo = wppilot_redo_session('stdio-session-a');

        self::assertSame('refused', $redo['status']);
        self::assertSame('changed-since-undo', $redo['conflicts'][0]['kind']);
        self::assertSame('Written by a person after the undo', $GLOBALS['wppilot_test_kv']['title']);
    }

    public function testAWriteOutsideAnySessionIsStoredExactlyAsBefore(): void
    {
        $this->inSession('');
        $this->write('title', 'By a person', 'plain');

        $row = wppilot_get_change('plain');
        self::assertIsArray($row);
        self::assertArrayNotHasKey('session', $row);
        self::assertArrayNotHasKey('after', $row);
    }

    public function testSessionsAreListedWithTheirCounts(): void
    {
        $this->write('a', '1');
        $this->write('b', '2');
        $this->inSession('stdio-session-b');
        $this->write('c', '3');
        wppilot_undo_session('stdio-session-b');

        $sessions = wppilot_list_change_sessions(10);

        self::assertSame(['stdio-session-b', 'stdio-session-a'], array_column($sessions, 'session_id'));
        self::assertSame(1, $sessions[0]['undone']);
        self::assertSame(2, $sessions[1]['undoable']);
        self::assertSame(2, wppilot_count_change_log(['session' => 'stdio-session-a']));

        $detail = wppilot_session_detail('stdio-session-a');
        self::assertIsArray($detail);
        self::assertTrue($detail['undo']['ready']);
        self::assertSame(2, $detail['undo']['can_undo']);
    }

    public function testTheLegacyExecuteToolRecordsNoRowOfItsOwn(): void
    {
        // It runs the target ability, which records itself; a row of its own could only say
        // "No supported before-image" and would make every legacy session look irreversible.
        self::assertTrue(wppilot_change_ability_is_meta('mcp-adapter/execute-ability'));
        self::assertTrue(wppilot_change_ability_is_meta('wppilot/undo-session'));
        self::assertTrue(wppilot_change_ability_is_meta('wppilot/redo-session'));
        self::assertFalse(wppilot_change_ability_is_meta('wppilot/update-post'));
    }

    public function testAnUnknownSessionIsAnError(): void
    {
        self::assertInstanceOf(WP_Error::class, wppilot_undo_session('no-such-session'));
        self::assertInstanceOf(WP_Error::class, wppilot_redo_session(''));
    }

    public function testSessionIdsFromTheTransport(): void
    {
        wppilot_session_basis(['kind' => 'mcp', 'key' => 'raw-adapter-session-id']);
        $mcp = wppilot_current_session_id();
        self::assertStringStartsWith('mcp-', $mcp);
        self::assertStringNotContainsString('raw-adapter-session-id', $mcp, 'the adapter\'s handle is never stored');

        wppilot_session_basis(['kind' => 'derived', 'key' => 'token|token-4|cursor']);
        $first = wppilot_current_session_id();
        wppilot_session_basis(['kind' => 'derived', 'key' => 'token|token-4|cursor']);
        self::assertSame($first, wppilot_current_session_id(), 'the same agent within the idle window is one session');
        wppilot_session_basis(['kind' => 'derived', 'key' => 'token|token-4|claude-code']);
        self::assertNotSame($first, wppilot_current_session_id(), 'another client on the same credential is another session');

        $GLOBALS['wppilot_test_transients'] = [];
        wppilot_session_basis(['kind' => 'derived', 'key' => 'token|token-4|cursor']);
        self::assertNotSame($first, wppilot_current_session_id(), 'after the idle window a new session starts');

        wppilot_session_basis(['kind' => '', 'key' => '']);
        self::assertSame('', wppilot_current_session_id());
    }

    public function testConflictDigestsIgnoreVolatilePostMetaButNotContent(): void
    {
        $post = ['type' => 'post', 'post' => ['ID' => 5, 'post_title' => 'A', 'post_modified' => '1'], 'meta' => ['_elementor_data' => ['[]'], '_elementor_css' => ['x']], 'terms' => []];
        $same = $post;
        $same['post']['post_modified'] = '2';
        $same['meta']['_elementor_css'] = ['regenerated on view'];
        $same['meta']['_edit_lock'] = ['123:1'];
        $edited = $post;
        $edited['meta']['_elementor_data'] = ['[{"id":"a"}]'];

        $digest = wppilot_change_state_digest($post);
        self::assertIsArray($digest);
        self::assertSame($digest['fingerprint'], wppilot_change_state_digest($same)['fingerprint']);
        self::assertSame(['meta._elementor_data'], wppilot_change_digest_diff($digest, wppilot_change_state_digest($edited)));
    }

    private function inSession(string $session): void
    {
        wppilot_session_basis($session === '' ? ['kind' => '', 'key' => ''] : ['kind' => 'stdio', 'key' => $session]);
    }

    /**
     * An agent write: capture the before-image, change the site, record the row.
     */
    private function write(string $key, string $value, ?string $id = null): void
    {
        $before = self::kv($key);
        $GLOBALS['wppilot_test_kv'][$key] = $value;
        wppilot_store_change($this->row($id ?? wp_generate_uuid4(), ['reversible' => true, 'type' => self::TYPE, 'snapshot' => $before]));
    }

    /**
     * @param array<string, mixed> $rollback
     * @return array<string, mixed>
     */
    private function row(string $id, array $rollback): array
    {
        return [
            'id' => $id,
            'kind' => 'change',
            'ability' => 'wppilot/tests-kv-write',
            'risk' => 'write',
            'recorded_at' => gmdate('c'),
            'user' => ['id' => 1, 'login' => 'admin'],
            'agent' => [],
            'input' => [],
            'rollback' => $rollback,
            'rolled_back' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function kv(string $key): array
    {
        $exists = array_key_exists($key, $GLOBALS['wppilot_test_kv']);
        $value = $exists ? $GLOBALS['wppilot_test_kv'][$key] : null;

        return [
            'type' => self::TYPE,
            'key' => $key,
            'exists' => $exists,
            'value' => $value,
            'fingerprint' => hash('sha256', $key . '|' . ($exists ? 'y' : 'n') . '|' . (string) $value),
        ];
    }
}
