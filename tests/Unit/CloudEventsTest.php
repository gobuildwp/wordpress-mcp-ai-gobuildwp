<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot_Test_Sqlite_Wpdb;
use WPPilot_Test_State;

use function WPPilot\OAuth\Middleware\reset_request_context;

require_once dirname(__DIR__) . '/doubles/admin.php';
require_once dirname(__DIR__) . '/doubles/mcp-surface.php';
require_once dirname(__DIR__) . '/doubles/sqlite-wpdb.php';
require_once dirname(__DIR__) . '/doubles/cloud.php';
require_once dirname(__DIR__, 2) . '/includes/capabilities.php';
require_once dirname(__DIR__, 2) . '/includes/clients.php';
require_once dirname(__DIR__, 2) . '/includes/rate-limit.php';
require_once dirname(__DIR__, 2) . '/includes/cloud/bootstrap.php';

/**
 * Event pushes (includes/cloud/events.php): the signed heartbeat sent early
 * with `event`, debounced, and only ever from cron.
 */
final class CloudEventsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    /** @var array<string, int> */
    private array $savedCron = [];

    private mixed $savedWpdb = null;

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedCron = WPPilot_Test_State::$cron;
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;

        foreach ([
            WPPILOT_CLOUD_LINK_OPTION,
            WPPILOT_CLOUD_KEYS_OPTION,
            WPPILOT_CLOUD_SEEN_VERSION_OPTION,
            WPPILOT_CLOUD_EVENT_OPTION,
            WPPILOT_TOKENS_SCHEMA_OPTION,
            WPPILOT_SAFETY_PROFILE_OPTION,
        ] as $option) {
            unset(WPPilot_Test_State::$options[$option]);
        }
        unset(WPPilot_Test_State::$cron[WPPILOT_CLOUD_HEARTBEAT_HOOK]);

        $GLOBALS['wpdb'] = new WPPilot_Test_Sqlite_Wpdb();
        $GLOBALS['wppilot_test_transients'] = [];
        $GLOBALS['wppilot_test_http_responses'] = [];
        $GLOBALS['wppilot_test_option_autoload'] = [];
        $GLOBALS['wppilot_test_single_events'] = [];
        $GLOBALS['wppilot_test_managers'] = [1, 2];
        unset($GLOBALS['wppilot_test_environment_type']);
        WPPilot_Test_State::$http_posts = [];
        WPPilot_Test_State::$current_user_id = 1;
        WPPilot_Test_State::$capabilities = ['manage_options'];
        for ($id = 1; $id <= 10; $id++) {
            wppilot_token_policy_cache($id, forget: true);
        }
        reset_request_context();
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        WPPilot_Test_State::$cron = $this->savedCron;
        WPPilot_Test_State::$http_posts = [];
        WPPilot_Test_State::$current_user_id = 0;
        WPPilot_Test_State::$capabilities = [];
        $GLOBALS['wpdb'] = $this->savedWpdb;
        $GLOBALS['wppilot_test_http_responses'] = [];
        $GLOBALS['wppilot_test_single_events'] = [];
        reset_request_context();
        parent::tearDown();
    }

    /** @param array<string, mixed> $body */
    private static function answer(int $status, array $body): array
    {
        return ['response' => ['code' => $status], 'body' => (string) json_encode($body)];
    }

    /** Pair through §1-§3 against a queued Cloud, as CloudPairingTest does. */
    private function link(): void
    {
        $begun = wppilot_cloud_begin(1);
        self::assertIsArray($begun);
        $GLOBALS['wppilot_test_http_responses'][] = self::answer(200, [
            'account_hint' => 'a***@example.com',
            'workspace' => 'Agency',
            'ceiling' => 'production',
            'scope' => null,
            'label' => 'Example',
        ]);
        self::assertTrue(wppilot_cloud_receive($begun['state'], 'code-1234567890', 1));
        $GLOBALS['wppilot_test_http_responses'][] = self::answer(200, ['site_id' => 'site_abc']);
        self::assertIsArray(wppilot_cloud_complete($begun['state'], 1));
        WPPilot_Test_State::$http_posts = [];
    }

    /** @return list<array{hook: string, timestamp: int}> */
    private static function pushes(): array
    {
        return array_values(array_filter(
            $GLOBALS['wppilot_test_single_events'],
            static fn(array $event): bool => $event['hook'] === WPPILOT_CLOUD_EVENT_HOOK,
        ));
    }

    public function test_an_unlinked_site_records_and_schedules_nothing(): void
    {
        wppilot_cloud_on_extension_changed();
        wppilot_cloud_on_backup_finished();
        wppilot_cloud_send_event_push();

        self::assertSame([], self::pushes());
        self::assertArrayNotHasKey(WPPILOT_CLOUD_EVENT_OPTION, WPPilot_Test_State::$options);
        self::assertSame([], WPPilot_Test_State::$http_posts);
    }

    public function test_events_within_the_window_schedule_exactly_one_push_and_send_nothing(): void
    {
        $this->link();
        $now = 1_760_000_000;

        wppilot_cloud_note_event('extension_changed', $now);
        wppilot_cloud_note_event('backup_failed', $now + 2);
        wppilot_cloud_note_event('update_finished', $now + 5);
        wppilot_cloud_note_event('not_an_event', $now + 6);

        self::assertSame([['hook' => WPPILOT_CLOUD_EVENT_HOOK, 'timestamp' => $now]], self::pushes());
        self::assertSame([], WPPilot_Test_State::$http_posts, 'the request that saw the events talks to nobody');
        self::assertSame('backup_failed', wppilot_cloud_event_state()['event'], 'the most significant event wins');
        self::assertFalse($GLOBALS['wppilot_test_option_autoload'][WPPILOT_CLOUD_EVENT_OPTION] ?? null);
    }

    public function test_the_push_is_the_signed_heartbeat_with_the_event(): void
    {
        $this->link();
        wppilot_cloud_on_site_kit_changed();
        wppilot_cloud_on_update_finished();

        wppilot_cloud_send_event_push();

        self::assertCount(1, WPPilot_Test_State::$http_posts);
        $post = WPPilot_Test_State::$http_posts[0];
        self::assertSame('https://app.gobuildwp.com/api/sites/heartbeat', $post['url']);
        $body = (string) $post['body'];
        $sent = (array) json_decode($body, associative: true);
        self::assertSame('update_finished', $sent['event']);
        self::assertSame('site_abc', $sent['site_id']);
        self::assertSame(['site_id', 'ts', 'nonce', 'versions', 'safety_profile', 'home_url', 'event'], array_keys($sent));

        $keys = wppilot_cloud_keys(create: false);
        self::assertIsArray($keys);
        $signature = base64_decode((string) $post['args']['headers']['X-WPPilot-Signature'], true);
        self::assertIsString($signature);
        self::assertTrue(sodium_crypto_sign_verify_detached($signature, $body, (string) base64_decode($keys['public'], true)));

        wppilot_cloud_send_event_push();
        self::assertCount(1, WPPilot_Test_State::$http_posts, 'a duplicate cron run finds nothing waiting');
    }

    public function test_the_hourly_heartbeat_carries_no_event(): void
    {
        $this->link();
        wppilot_cloud_note_event('backup_finished');

        wppilot_cloud_send_heartbeat();

        $sent = (array) json_decode((string) WPPilot_Test_State::$http_posts[0]['body'], associative: true);
        self::assertArrayNotHasKey('event', $sent);
        self::assertSame('backup_finished', wppilot_cloud_event_state()['event'], 'the event push is still waiting');
    }

    public function test_a_push_is_never_sooner_than_the_debounce_after_the_last(): void
    {
        $this->link();
        $now = 1_760_000_000;

        wppilot_cloud_note_event('extension_changed', $now);
        wppilot_cloud_send_event_push($now);
        wppilot_cloud_note_event('backup_finished', $now + 5);
        wppilot_cloud_note_event('extension_changed', $now + 10);

        self::assertSame([$now, $now + WPPILOT_CLOUD_EVENT_DEBOUNCE], array_column(self::pushes(), 'timestamp'));
        self::assertSame('backup_finished', wppilot_cloud_event_state()['event']);

        wppilot_cloud_send_event_push($now + 30);
        wppilot_cloud_note_event('site_kit_changed', $now + 100);
        self::assertSame($now + 100, self::pushes()[2]['timestamp'] ?? null, 'outside the window it goes at once');
    }

    public function test_a_lost_push_is_scheduled_again(): void
    {
        $this->link();
        $now = 1_760_000_000;
        wppilot_cloud_note_event('extension_changed', $now);
        wppilot_cloud_note_event('extension_changed', $now + WPPILOT_CLOUD_EVENT_STALE - 1);
        self::assertCount(1, self::pushes());

        wppilot_cloud_note_event('extension_changed', $now + WPPILOT_CLOUD_EVENT_STALE + 1);
        self::assertCount(2, self::pushes());
    }

    public function test_a_waiting_push_whose_cron_entry_vanished_is_scheduled_again(): void
    {
        $this->link();
        $now = 1_760_000_000;
        wppilot_cloud_note_event('extension_changed', $now);
        self::assertCount(1, self::pushes());

        // WP-Cron dropped the entry (concurrent writes to its one option).
        unset(WPPilot_Test_State::$cron[WPPILOT_CLOUD_EVENT_HOOK]);
        wppilot_cloud_note_event('update_finished', $now + 40);
        self::assertSame(['hook' => WPPILOT_CLOUD_EVENT_HOOK, 'timestamp' => $now + 40], self::pushes()[1] ?? null);

        wppilot_cloud_note_event('extension_changed', $now + 41);
        self::assertCount(2, self::pushes(), 'while the entry exists, nothing more is scheduled');
    }

        public function test_upgrader_updates_and_installs_map_to_their_events(): void
    {
        $this->link();
        wppilot_cloud_on_upgrader_event(null, ['action' => 'update', 'type' => 'translation']);
        wppilot_cloud_on_upgrader_event(null, 'not-an-array');
        self::assertSame([], self::pushes(), 'translations are not an update the Cloud tracks');

        wppilot_cloud_on_upgrader_event(null, ['action' => 'install', 'type' => 'theme']);
        self::assertSame('extension_changed', wppilot_cloud_event_state()['event']);
        wppilot_cloud_on_upgrader_event(null, ['action' => 'update', 'type' => 'core']);
        self::assertSame('update_finished', wppilot_cloud_event_state()['event']);
        self::assertCount(1, self::pushes());
    }

    public function test_backup_plugin_hooks_report_the_verdict(): void
    {
        $this->link();
        $last = ['backup_time' => 1, 'success' => 1, 'errors' => []];
        self::assertSame($last, wppilot_cloud_on_updraftplus_last_backup($last), 'the filter passes the value on unchanged');
        self::assertSame('backup_finished', wppilot_cloud_event_state()['event']);

        $job = new class {
            public int $errors = 2;
        };
        wppilot_cloud_on_backwpup_end_job([], 'file.zip', $job);
        self::assertSame('backup_failed', wppilot_cloud_event_state()['event']);
        self::assertSame('anything', wppilot_cloud_on_updraftplus_last_backup('anything'));
    }
}
