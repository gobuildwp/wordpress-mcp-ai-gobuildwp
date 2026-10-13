<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SiteTools;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Host;
use WPPilot\Kits\Runtime\Jobs;
use WPPilot\Kits\Runtime\Ledger;
use WPPilot\Kits\Runtime\ProfileGate;
use WPPilot\Kits\SiteTools\Cron;
use WPPilot\Kits\SiteTools\Health;
use WPPilot\Kits\SiteTools\Options;
use WPPilot\Kits\SiteTools\Transients;

/**
 * The site-tools kit against WordPress doubles that keep core's own cron semantics.
 */
final class SiteToolsTest extends TestCase
{
    /** @var list<array{ability: string, items: list<array<string, mixed>>}> */
    public static array $recorded = [];

    /** @var array<string, callable> */
    public static array $strategies = [];

    public static string $profile = 'allow';

    private mixed $savedWpdb = null;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/doubles.php';
        require_once __DIR__ . '/registrations.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        $kit = require dirname(__DIR__, 4) . '/includes/kits/site-tools/bootstrap.php';
        Runtime\host(self::host());
        ($kit['boot'])(Runtime\host());
        foreach ($kit['ability_files'] as $file) {
            require_once $file;
        }
    }

    protected function setUp(): void
    {
        SiteState::reset();
        self::$recorded = [];
        Runtime\host(self::host());
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->savedWpdb;
        remove_all_filters('wppilot_kit_options_explore_denied');
        remove_all_filters('wp_doing_cron');
    }

    // Registration.

    public function testEveryAbilityIsRegisteredWithItsDeclaredShape(): void
    {
        $expected = [
            'wppilot/cron-list' => [true, false],
            'wppilot/cron-run' => [false, false],
            'wppilot/cron-delete' => [false, true],
            'wppilot/site-health-tests' => [true, false],
            'wppilot/transients-flush' => [false, false],
            'wppilot/options-explore' => [true, false],
        ];
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/site-tools/kit.json'), true);
        foreach ($expected as $name => [$readonly, $destructive]) {
            self::assertTrue(wp_has_ability($name), $name);
            $annotations = Registrations::$args[$name]['meta']['annotations'];
            self::assertSame($readonly, $annotations['readonly'], $name);
            self::assertSame($destructive, $annotations['destructive'], $name);
            $declared = array_column($manifest['abilities'], null, 'name')[$name];
            self::assertSame([$readonly, $destructive], [$declared['readonly'], $declared['destructive']], "kit.json agrees on {$name}");
        }
    }

    public function testTheOptionsExplorerIsDeveloperOnlyAndAudited(): void
    {
        $args = Registrations::$args['wppilot/options-explore'];

        self::assertSame(['min_profile' => 'developer', 'audit_reads' => true], $args['meta']['safety']);
        self::$profile = 'deny';
        self::assertInstanceOf(WP_Error::class, ($args['permission_callback'])());
        self::$profile = 'allow';
        self::assertTrue(($args['permission_callback'])());
    }

    public function testCronDeleteDeclaresTheConfirmItChecks(): void
    {
        self::assertArrayHasKey('confirm', Registrations::$args['wppilot/cron-delete']['input_schema']['properties']);
        self::assertArrayHasKey('confirm', Registrations::$args['wppilot/transients-flush']['input_schema']['properties']);
        self::assertArrayHasKey(Cron\STRATEGY, self::$strategies, 'boot registers the undo');
    }

    // cron-list.

    public function testEventsAreListedSoonestFirstWithWhatTheRunAndDeleteNeed(): void
    {
        $now = time();
        SiteState::$listeners['wp_version_check'] = static function (): void {
        };
        SiteState::schedule($now + 3600, 'wp_version_check', [], 'hourly');
        $key = SiteState::schedule($now - 120, 'orphan_hook', ['post' => 7]);

        $list = Cron\list_events([]);

        self::assertSame(['orphan_hook', 'wp_version_check'], array_column($list['events'], 'hook'));
        $orphan = $list['events'][0];
        self::assertSame($key, $orphan['key']);
        self::assertSame(['post' => 7], $orphan['args']);
        self::assertTrue($orphan['due']);
        self::assertGreaterThanOrEqual(120, $orphan['overdue_seconds']);
        self::assertFalse($orphan['has_callbacks']);
        self::assertNull($orphan['schedule']);
        self::assertTrue($list['events'][1]['has_callbacks']);
        self::assertSame('hourly', $list['events'][1]['schedule']);
        self::assertSame(1, $list['due_or_overdue']);
        self::assertContains('daily', array_column($list['schedules'], 'name'));

        self::assertSame(['orphan_hook'], array_column(Cron\list_events(['due_only' => true])['events'], 'hook'));
        self::assertSame(['wp_version_check'], array_column(Cron\list_events(['hook' => 'VERSION'])['events'], 'hook'));
        $capped = Cron\list_events(['limit' => 1]);
        self::assertSame(1, $capped['count']);
        self::assertTrue($capped['truncated']);
    }

    // cron-run.

    /**
     * What wp-cron.php does, in its order: reschedule, unschedule, fire — as no user, with
     * wp_doing_cron() true, and the lock taken and given back.
     */
    public function testADueRecurringEventRunsAsWpCronWouldRunIt(): void
    {
        $due = time() - 90;
        $key = SiteState::schedule($due, 'nightly_cleanup', ['all'], 'daily');
        SiteState::$listeners['nightly_cleanup'] = static function (string $what): void {
            echo "cleaned {$what}";
        };

        $result = Cron\run(['hook' => 'nightly_cleanup', 'timestamp' => $due, 'key' => $key]);

        self::assertIsArray($result);
        self::assertTrue($result['ran']);
        self::assertSame('cleaned all', $result['output']);
        self::assertNull($result['error']);
        self::assertSame([['hook' => 'nightly_cleanup', 'args' => ['all'], 'user' => 0, 'doing_cron' => true]], SiteState::$fired);
        self::assertSame(1, SiteState::$user, 'the caller is restored');
        self::assertFalse(apply_filters('wp_doing_cron', false), 'wp_doing_cron() is false again');
        self::assertArrayNotHasKey(Cron\LOCK, SiteState::$transients, 'the cron lock is released');
        self::assertArrayNotHasKey($due, SiteState::$cron, 'this occurrence is gone');
        self::assertEqualsWithDelta(time() + 86400 - 90, $result['next_run'], 2, 'rescheduled on its own cadence');

        self::assertSame('wppilot/cron-run', self::$recorded[0]['ability']);
        self::assertSame(Cron\RUN_IRREVERSIBLE, self::$recorded[0]['items'][0]['irreversible_reason']);
        self::assertStringContainsString('wp-cron', Cron\RUN_IRREVERSIBLE);
    }

    public function testASingleEventRunsOnceAndIsNotRescheduled(): void
    {
        $due = time() - 5;
        SiteState::schedule($due, 'send_digest', [42]);

        $result = Cron\run(['hook' => 'send_digest', 'timestamp' => $due, 'args' => [42]]);

        self::assertTrue($result['ran']);
        self::assertNull($result['next_run']);
        self::assertSame([], SiteState::$cron);
    }

    public function testAFailingHookIsReportedAndStillCleansUp(): void
    {
        $due = time() - 5;
        $key = SiteState::schedule($due, 'broken_hook');
        SiteState::$listeners['broken_hook'] = static function (): void {
            throw new \RuntimeException('boom');
        };

        $result = Cron\run(['hook' => 'broken_hook', 'timestamp' => $due, 'key' => $key]);

        self::assertSame('RuntimeException: boom', $result['error']);
        self::assertSame(1, SiteState::$user);
        self::assertArrayNotHasKey(Cron\LOCK, SiteState::$transients);
    }

    public function testAFutureEventIsNotRunEarly(): void
    {
        $later = time() + 600;
        $key = SiteState::schedule($later, 'later_hook');

        $result = Cron\run(['hook' => 'later_hook', 'timestamp' => $later, 'key' => $key]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('kit_cron_not_due', $result->get_error_code());
        self::assertSame([], SiteState::$fired);
        self::assertSame([], self::$recorded, 'nothing ran, so nothing is recorded');
    }

    public function testNothingRunsWhileWpCronHoldsTheLock(): void
    {
        $due = time() - 5;
        $key = SiteState::schedule($due, 'busy_hook');
        SiteState::$transients[Cron\LOCK] = sprintf('%.22F', microtime(true) - 10);

        $result = Cron\run(['hook' => 'busy_hook', 'timestamp' => $due, 'key' => $key]);

        self::assertSame('kit_cron_locked', $result->get_error_code());
        self::assertArrayHasKey($due, SiteState::$cron);

        // A lock older than WP_CRON_LOCK_TIMEOUT is a finished run that did not clean up.
        SiteState::$transients[Cron\LOCK] = sprintf('%.22F', microtime(true) - 3600);
        self::assertTrue(Cron\run(['hook' => 'busy_hook', 'timestamp' => $due, 'key' => $key])['ran']);
    }

    public function testAnEventMustBeNamedExactly(): void
    {
        $due = time() - 5;
        $key = SiteState::schedule($due, 'named_hook', ['a']);

        self::assertSame('kit_cron_event_unnamed', Cron\run(['hook' => 'named_hook', 'timestamp' => $due])->get_error_code());
        self::assertSame('kit_cron_event_not_found', Cron\run(['hook' => 'named_hook', 'timestamp' => $due, 'args' => ['b']])->get_error_code());
        self::assertSame('kit_cron_bad_args', Cron\run(['hook' => 'named_hook', 'timestamp' => $due, 'args' => ['a'], 'key' => 'nope'])->get_error_code());
        self::assertSame('kit_cron_event_not_found', Cron\run(['hook' => 'named_hook', 'timestamp' => $due + 1, 'key' => $key])->get_error_code());
    }

    // cron-delete and its undo.

    public function testDeleteNeedsConfirmationWhereNoGateAskedForIt(): void
    {
        $at = time() + 60;
        $key = SiteState::schedule($at, 'stuck_hook');

        $refused = Cron\delete(['hook' => 'stuck_hook', 'timestamp' => $at, 'key' => $key]);

        self::assertSame('kit_confirmation_required', $refused->get_error_code());
        self::assertArrayHasKey($at, SiteState::$cron);
    }

    /**
     * The undo puts the same occurrence back — hook, time, schedule, args — and says verified only
     * after reading it back.
     */
    public function testADeletedRecurringEventIsRestoredExactly(): void
    {
        $at = time() + 300;
        $key = SiteState::schedule($at, 'plugin_sync', ['full' => true], 'hourly');
        SiteState::schedule($at + 10, 'other_hook');

        $result = Cron\delete(['hook' => 'plugin_sync', 'timestamp' => $at, 'key' => $key, 'confirm' => true]);

        self::assertTrue($result['deleted']);
        self::assertFalse(Cron\wp_get_scheduled_event('plugin_sync', ['full' => true], $at));
        self::assertNotFalse(Cron\wp_get_scheduled_event('other_hook', [], $at + 10), 'other events are untouched');
        $before = self::$recorded[0]['items'][0]['before'];
        self::assertSame(Cron\STRATEGY, $before['type']);
        self::assertSame('hourly', $before['schedule']);

        $restored = (self::$strategies[Cron\STRATEGY])(['reversible' => true, 'type' => Cron\STRATEGY, 'snapshot' => $before], []);

        self::assertTrue($restored['verified']);
        $event = Cron\wp_get_scheduled_event('plugin_sync', ['full' => true], $at);
        self::assertSame('hourly', $event->schedule);
        self::assertSame($key, Cron\args_key(['full' => true]));
    }

    public function testADeletedSingleEventIsRestoredAtItsOwnTime(): void
    {
        $at = time() + 7200;
        $key = SiteState::schedule($at, 'publish_future_post', [12]);
        Cron\delete(['hook' => 'publish_future_post', 'timestamp' => $at, 'key' => $key, 'confirm' => true]);

        $restored = Cron\restore(['snapshot' => self::$recorded[0]['items'][0]['before']]);

        self::assertTrue($restored['verified']);
        self::assertFalse($restored['schedule']);
        self::assertNotFalse(Cron\wp_get_scheduled_event('publish_future_post', [12], $at));
    }

    /**
     * The owning plugin usually re-adds a recurring event on its next load; a second copy would
     * run it twice as often, so the undo refuses instead of duplicating it.
     */
    public function testTheUndoRefusesToDuplicateAnEventItsPluginAlreadyReAdded(): void
    {
        $at = time() + 300;
        $key = SiteState::schedule($at, 'plugin_sync', [], 'hourly');
        Cron\delete(['hook' => 'plugin_sync', 'timestamp' => $at, 'key' => $key, 'confirm' => true]);
        SiteState::schedule(time() + 3600, 'plugin_sync', [], 'hourly');

        $restored = Cron\restore(['snapshot' => self::$recorded[0]['items'][0]['before']]);

        self::assertInstanceOf(WP_Error::class, $restored);
        self::assertSame('kit_cron_already_rescheduled', $restored->get_error_code());
        self::assertCount(1, SiteState::$cron);
    }

    public function testTheUndoSaysWhyWhenTheScheduleIsGone(): void
    {
        $at = time() + 300;
        $key = SiteState::schedule($at, 'custom_interval_hook', [], 'hourly');
        Cron\delete(['hook' => 'custom_interval_hook', 'timestamp' => $at, 'key' => $key, 'confirm' => true]);
        unset(SiteState::$schedules['hourly']);

        $restored = Cron\restore(['snapshot' => self::$recorded[0]['items'][0]['before']]);

        self::assertInstanceOf(WP_Error::class, $restored);
        self::assertStringContainsString('"hourly" schedule', $restored->get_error_message());
    }

    // site-health-tests.

    public function testDirectTestsRunAndAsyncTestsAreListedAsNotRun(): void
    {
        SiteState::$healthDirect = [
            'php_version' => ['label' => 'PHP Version', 'test' => 'php_version'],
            'plugin_check' => ['label' => 'A plugin test', 'test' => static fn(): array => ['label' => 'Bad thing', 'status' => 'critical', 'description' => '<b>Fix</b> it']],
            'exploding' => ['label' => 'Explodes', 'test' => static function (): array {
                throw new \RuntimeException('no network');
            }],
            'missing' => ['label' => 'Gone', 'test' => 'no_such_test'],
        ];
        SiteState::$healthAsync = ['loopback_requests' => ['label' => 'Loopback request', 'test' => 'https://example.test/wp-json/x']];

        $result = Health\run_tests([]);

        $byId = array_column($result['results'], null, 'id');
        self::assertSame('good', $byId['php_version']['status']);
        self::assertSame('PHP is one of the programming languages used to build WordPress & its plugins.', $byId['php_version']['description']);
        self::assertSame('Learn more', $byId['php_version']['actions']);
        self::assertSame('Performance', $byId['php_version']['badge']);
        self::assertSame('critical', $byId['plugin_check']['status']);
        self::assertSame('Fix it', $byId['plugin_check']['description']);
        self::assertSame('error', $byId['exploding']['status']);
        self::assertStringContainsString('no network', $byId['exploding']['description']);
        self::assertSame('error', $byId['missing']['status']);
        self::assertSame(['good' => 1, 'recommended' => 0, 'critical' => 1, 'error' => 2], $result['counts']);
        self::assertSame([['id' => 'loopback_requests', 'label' => 'Loopback request', 'status' => 'not_run']], array_map(
            static fn(array $row): array => array_intersect_key($row, ['id' => 1, 'label' => 1, 'status' => 1]),
            $result['async_not_run'],
        ));

        $only = Health\run_tests(['tests' => ['php_version']]);
        self::assertSame(['php_version'], array_column($only['results'], 'id'));
        self::assertSame([], $only['async_not_run']);
    }

    // transients-flush.

    public function testExpiredTransientsGoThroughCoreAndAreCounted(): void
    {
        $GLOBALS['wpdb'] = self::optionsTable(['_transient_a', '_transient_timeout_a'], expired: 3);

        $result = Transients\flush([]);

        self::assertTrue(SiteState::$expiredDeleted, 'forced to the database');
        self::assertSame(6, $result['deleted'], 'three expired transients and three expired site transients');
        self::assertSame(0, $result['remaining_expired']);
        self::assertSame(Transients\IRREVERSIBLE, self::$recorded[0]['items'][0]['irreversible_reason']);
    }

    public function testFlushingEverythingNeedsConfirmation(): void
    {
        $GLOBALS['wpdb'] = self::optionsTable(['_transient_a']);

        $refused = Transients\flush(['scope' => 'all']);

        self::assertSame('kit_confirmation_required', $refused->get_error_code());
        self::assertSame([], SiteState::$deletedTransients);
        self::assertSame([], self::$recorded);
    }

    public function testFlushingEverythingDeletesEachTransientThroughWordPress(): void
    {
        $GLOBALS['wpdb'] = self::optionsTable(['_transient_feed_x', '_transient_timeout_feed_x', '_site_transient_update_core']);

        $result = Transients\flush(['scope' => 'all', 'confirm' => true]);

        self::assertSame(['feed_x', 'site:update_core'], SiteState::$deletedTransients);
        self::assertSame(2, $result['deleted']);
        self::assertSame(0, $result['remaining']);
    }

    public function testWithAnObjectCacheTheStaleRowsAndTheCacheGroupsGo(): void
    {
        SiteState::$objectCache = true;
        SiteState::$flushGroup = true;
        $GLOBALS['wpdb'] = self::optionsTable(['_transient_feed_x']);

        $result = Transients\flush(['scope' => 'all', 'confirm' => true]);

        self::assertSame(['option:_transient_feed_x', 'option:_transient_timeout_feed_x'], SiteState::$deletedTransients);
        self::assertSame(['transient', 'site-transient'], $result['flushed_cache_groups']);
    }

    // options-explore.

    public function testOptionValuesAreShownRawAndSecretsWithheld(): void
    {
        add_filter('wppilot_kit_options_explore_denied', static fn(array $names): array => ['my_plugin_*']);
        $GLOBALS['wpdb'] = self::optionsTable([], rows: [
            ['option_name' => 'blogname', 'autoload' => 'on', 'size' => '8', 'preview' => 'My Blog'],
            ['option_name' => 'widget_text', 'autoload' => 'auto-off', 'size' => '900', 'preview' => 'a:1:{s:1:"x";' . str_repeat('y', 300)],
            ['option_name' => 'stripe_api_key', 'autoload' => 'on', 'size' => '32', 'preview' => 'sk_live_123'],
            ['option_name' => 'auth_salt', 'autoload' => 'on', 'size' => '64', 'preview' => 'salty'],
            ['option_name' => 'test_ledger', 'autoload' => 'off', 'size' => '10', 'preview' => 'mine'],
            ['option_name' => '_transient_test_cache', 'autoload' => 'off', 'size' => '10', 'preview' => 'mine'],
            ['option_name' => 'my_plugin_config', 'autoload' => 'on', 'size' => '5', 'preview' => 'hello'],
        ]);

        $result = Options\explore(['value_length' => 200]);

        $byName = array_column($result['options'], null, 'name');
        self::assertSame('My Blog', $byName['blogname']['value']);
        self::assertTrue($byName['blogname']['autoloaded']);
        self::assertTrue($byName['widget_text']['serialized']);
        self::assertFalse($byName['widget_text']['autoloaded']);
        self::assertTrue($byName['widget_text']['value_truncated']);
        self::assertSame(201, mb_strlen($byName['widget_text']['value']));
        self::assertSame('sensitive name', $byName['stripe_api_key']['redacted']);
        self::assertNull($byName['stripe_api_key']['value']);
        self::assertSame('denied', $byName['auth_salt']['redacted']);
        self::assertSame('this plugin\'s own setting', $byName['test_ledger']['redacted']);
        self::assertSame('this plugin\'s own setting', $byName['_transient_test_cache']['redacted']);
        self::assertSame('denied', $byName['my_plugin_config']['redacted']);
        self::assertSame(7, $result['total']);
        self::assertSame(123456, $result['autoloaded_bytes']);
    }

    public function testTheQueryFiltersByPatternAutoloadAndSize(): void
    {
        $db = self::optionsTable([]);
        $GLOBALS['wpdb'] = $db;

        Options\explore(['pattern' => 'woo*_cache?', 'autoload' => 'on', 'order' => 'size', 'limit' => 10, 'offset' => 20, 'value_length' => 0]);

        $sql = implode("\n", $db->prepared);
        self::assertStringContainsString("option_name LIKE 'woo%\\_cache_'", $sql);
        self::assertStringContainsString("autoload IN ('yes','on','auto-on','auto')", $sql);
        self::assertStringContainsString('ORDER BY size DESC', $sql);
        self::assertStringContainsString('LIMIT 10 OFFSET 20', $sql);
    }

    public function testValueLengthZeroReturnsNamesAndSizesOnly(): void
    {
        $GLOBALS['wpdb'] = self::optionsTable([], rows: [['option_name' => 'blogname', 'autoload' => 'on', 'size' => '8', 'preview' => 'M']]);

        $result = Options\explore(['value_length' => 0]);

        self::assertArrayNotHasKey('value', $result['options'][0]);
        self::assertSame(8, $result['options'][0]['size']);
    }

    /**
     * @param list<string> $names
     * @param list<array<string, string>> $rows
     */
    private static function optionsTable(array $names, int $expired = 0, array $rows = []): object
    {
        return new class ($names, $expired, $rows) {
            public string $options = 'wp_options';

            /** @var list<string> */
            public array $prepared = [];

            /**
             * @param list<string> $names
             * @param list<array<string, string>> $rows
             */
            public function __construct(public array $names, public int $expired, public array $rows)
            {
            }

            public function esc_like(string $text): string
            {
                return addcslashes($text, '_%\\');
            }

            public function prepare(string $sql, mixed ...$args): string
            {
                $args = count($args) === 1 && is_array($args[0]) ? $args[0] : $args;
                $sql = preg_replace_callback('/%[sd]/', static function (array $m) use (&$args): string {
                    $value = array_shift($args);
                    return $m[0] === '%d' ? (string) (int) $value : "'" . $value . "'";
                }, $sql);
                $this->prepared[] = (string) $sql;
                return (string) $sql;
            }

            /** @return list<string> */
            public function get_col(string $sql): array
            {
                preg_match("/LIKE '([^']*)%' AND option_name NOT LIKE '([^']*)%'/", $sql, $m);
                $prefix = stripslashes($m[1]);
                $timeout = stripslashes($m[2]);
                return array_values(array_filter($this->names, static fn(string $name): bool => str_starts_with($name, $prefix) && !str_starts_with($name, $timeout)));
            }

            public function get_var(string $sql): string
            {
                if (str_contains($sql, 'SUM(LENGTH')) {
                    return '123456';
                }
                if (str_contains($sql, 'option_value <')) {
                    return (string) $this->expired;
                }
                return (string) count($this->rows);
            }

            /** @return list<array<string, string>> */
            public function get_results(string $sql, string $output = 'OBJECT'): array
            {
                return $this->rows;
            }
        };
    }

    private static function host(): Host
    {
        $ledger = new class implements Ledger {
            public function capture_for(string $ability_name, callable $capture): void
            {
            }

            public function record_items(string $ability_name, array $items, ?string $group = null): array
            {
                SiteToolsTest::$recorded[] = ['ability' => $ability_name, 'items' => $items];
                return ['group' => 'g', 'change_ids' => ['c1'], 'without_before_image' => 0];
            }

            public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
            {
                SiteToolsTest::$strategies[$type] = $restore;
                return true;
            }

            public function query(array $filters = []): array
            {
                return [];
            }

            public function export_row(array $entry): array
            {
                return $entry;
            }

            public function snapshot_budget(): int
            {
                return 1_048_576;
            }

            public function download_url(): string
            {
                return '';
            }
        };
        // No gate pipeline in front of it, so confirmation is the kit's to check, as standalone.
        return new class ($ledger) implements Host, ProfileGate {
            public function __construct(private Ledger $ledger)
            {
            }

            public function profile_allows(string $ability_name): bool|WP_Error
            {
                return SiteToolsTest::$profile === 'allow' ? true : new WP_Error('kit_safety_profile_blocked', $ability_name);
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
                return $this->ledger;
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
                return ($input['confirm'] ?? null) === true ? true : new WP_Error('kit_confirmation_required', $ability_name);
            }
        };
    }
}
