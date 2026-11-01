<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityLive {
    // WordPress functions the hooks call, doubled in the kit's namespace.
    if (!function_exists(__NAMESPACE__ . '\get_userdata')) {
        function get_userdata(int $id): object|false
        {
            return isset($GLOBALS['gobuildwp_sl_test_users'][$id]) ? (object) ['ID' => $id, 'user_login' => $GLOBALS['gobuildwp_sl_test_users'][$id]] : false;
        }

        function user_can(int $id, string $capability): bool
        {
            return in_array($id, $GLOBALS['gobuildwp_sl_test_admins'] ?? [], true);
        }

        function get_attached_file(int $id, bool $unfiltered = false): string|false
        {
            return $GLOBALS['gobuildwp_sl_test_attachments'][$id] ?? false;
        }
    }
}

namespace GoBuildWP\Tests\Unit\Kits\SecurityLive {

    require_once dirname(__DIR__) . '/SecurityIntegrity/harness.php';
    require_once dirname(__DIR__, 4) . '/includes/kits/security-live/src/load.php';

    use GoBuildWP\Kits\SecurityIntegrity as SI;
    use GoBuildWP\Kits\SecurityIntegrity\TestNet;
    use GoBuildWP\Kits\SecurityLive as SL;
    use GoBuildWP\Tests\Unit\Kits\SecurityIntegrity\Fixture;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WPPilot_Test_State;

    use function GoBuildWP\Tests\Unit\Kits\SecurityIntegrity\reset_state;

    /**
     * Live security: the event store, settings, the instant hook checks, the stat-only change
     * watch and the Cloud route.
     */
    final class LiveTest extends TestCase
    {
        private Fixture $site;

        private const NOW = 1_790_000_000;

        protected function setUp(): void
        {
            reset_state();
            unset(WPPilot_Test_State::$cron[SL\CRON_HOOK], WPPilot_Test_State::$cron[SL\CRON_HOOK . '_soon']);
            $GLOBALS['gobuildwp_sl_test_users'] = [];
            $GLOBALS['gobuildwp_sl_test_admins'] = [];
            $GLOBALS['gobuildwp_sl_test_attachments'] = [];
            $this->site = new Fixture();
        }

        protected function tearDown(): void
        {
            $this->site->remove();
            reset_state();
            unset(WPPilot_Test_State::$cron[SL\CRON_HOOK], WPPilot_Test_State::$cron[SL\CRON_HOOK . '_soon']);
        }

        /** Snippets assembled so this file does not itself match the rules. */
        private static function evil(): string
        {
            return '<?php ' . 'ev' . 'al(base' . '64_decode("ZWNobyAxOw=="));';
        }

        /** @return list<string> "severity kind rule_id subject" */
        private static function events(): array
        {
            return array_map(
                static fn(array $e): string => $e['severity'] . ' ' . $e['kind'] . ' ' . ($e['detail']['rule_id'] ?? '') . ' ' . $e['subject'],
                SL\events_since(0, 200),
            );
        }

        /** A small core tree with checksums for it. */
        private function core(): void
        {
            $index = "<?php // index\n";
            $load = "<?php // load\n";
            $this->site->put('index.php', $index);
            $this->site->put('wp-includes/load.php', $load);
            $this->site->put('wp-admin/admin.php', $index);
            $this->site->dir('wp-content/plugins');
            $this->site->dir('wp-content/mu-plugins');
            $this->site->dir('wp-content/themes/twenty');
            $this->site->put('wp-content/themes/twenty/functions.php', "<?php // theme\n");
            $this->site->dir('wp-content/uploads/2026/10');
            TestNet::$transients[SI\core_transient(SI\wp_version(), 'en_US')] = [
                'index.php' => md5($index),
                'wp-includes/load.php' => md5($load),
                'wp-admin/admin.php' => md5($index),
            ];
        }

        private function pass(?int $entries = null): array
        {
            $limits = SI\budgets();
            if ($entries !== null) {
                $limits['entries'] = $entries;
            }
            return SL\watch_step(new SI\Budget($limits, 20), self::NOW);
        }

        /** Touch a file so its size or time differs from the index. */
        private function change(string $path, string $content): void
        {
            $file = $this->site->put($path, $content);
            touch($file, time() + 5);
            clearstatcache();
        }

        // ------------------------------------------------------------ store

        public function test_record_dedupes_trims_and_filters(): void
        {
            self::assertSame(1, SL\record('high', 'admin', 'Administrator: eve', ['rule_id' => 'users.new_admin'], self::NOW));
            self::assertSame(0, SL\record('high', 'admin', 'Administrator: eve', ['rule_id' => 'users.new_admin'], self::NOW + 60), 'the same event within six hours is not recorded again');
            self::assertSame(2, SL\record('high', 'admin', 'Administrator: eve', ['rule_id' => 'users.new_admin'], self::NOW + SL\DEDUPE_SECONDS + 1));
            self::assertSame(3, SL\record('bogus', 'x', "multi\nline", ['secret' => 'no', 'note' => 'ok', 'line' => 7], self::NOW));

            $all = SL\events_since(0);
            self::assertCount(3, $all);
            self::assertSame('info', $all[2]['severity'], 'unknown severities are info');
            self::assertSame('multi line', $all[2]['subject']);
            self::assertSame(['note' => 'ok', 'line' => 7], $all[2]['detail'], 'only known detail fields are kept');
            self::assertArrayNotHasKey('key', $all[0]);
            self::assertSame([3], array_column(SL\events_since(2), 'id'));
            self::assertSame([1, 2], array_column(SL\events_since(0, 100, 'high'), 'id'));
            self::assertSame(3, SL\last_event_id());

            for ($i = 0; $i < SL\MAX_EVENTS + 10; $i++) {
                SL\record('low', 'x', 'item ' . $i, [], self::NOW);
            }
            $store = SL\event_store();
            self::assertCount(SL\MAX_EVENTS, $store['items']);
            self::assertSame(SL\MAX_EVENTS + 13, SL\last_event_id(), 'ids keep counting past the ring');
            self::assertFalse($GLOBALS['wppilot_test_option_autoload'][SL\EVENTS_OPTION] ?? null, 'events are not autoloaded');
        }

        public function test_nothing_is_recorded_when_off(): void
        {
            self::assertIsArray(SL\configure(['enabled' => false]));
            self::assertSame(0, SL\record('critical', 'admin', 'x', [], self::NOW));
            self::assertSame([], SL\events_since());
            self::assertSame('off', SL\status()['state']);
            self::assertFalse(SL\run_watch(5, self::NOW)['ran']);
        }

        /** @return array<string, array{0: array<string, mixed>}> */
        public static function bad_settings(): array
        {
            return [
                'unknown key' => [['firewall' => 'on']],
                'enabled not bool' => [['enabled' => 1]],
                'interval too short' => [['interval_minutes' => 4]],
                'interval too long' => [['interval_minutes' => 1441]],
                'interval string' => [['interval_minutes' => '15']],
                'paths not list' => [['excluded_paths' => 'wp-content/cache']],
                'path with dots' => [['excluded_paths' => ['wp-content/../wp-config.php']]],
                'empty path' => [['excluded_paths' => ['/']]],
                'too many paths' => [['excluded_paths' => array_map(static fn(int $i): string => 'p' . $i, range(1, 51))]],
            ];
        }

        /** @param array<string, mixed> $input */
        #[DataProvider('bad_settings')]
        public function test_bad_settings_are_refused(array $input): void
        {
            $result = SL\configure($input);
            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('gobuildwp_live_invalid', $result->get_error_code());
            self::assertSame(15, SL\settings()['interval_minutes'], 'nothing changed');
        }

        public function test_configure_cleans_paths_and_reschedules(): void
        {
            $result = SL\configure(['interval_minutes' => 30, 'excluded_paths' => ['\\wp-content\\cache\\', 'wp-content//cache']]);
            self::assertIsArray($result);
            self::assertSame(['wp-content/cache'], $result['excluded_paths']);
            self::assertSame(30, SL\settings()['interval_minutes']);
            self::assertArrayHasKey(SL\CRON_HOOK, WPPilot_Test_State::$cron);
            self::assertSame(1800, SL\cron_schedules([])[SL\CRON_SCHEDULE]['interval']);

            SL\configure(['enabled' => false]);
            self::assertArrayNotHasKey(SL\CRON_HOOK, WPPilot_Test_State::$cron, 'turning it off removes the scheduled run');
        }

        // ------------------------------------------------------------ change watch

        public function test_first_pass_is_a_silent_baseline_then_changes_are_events(): void
        {
            $this->core();
            $this->site->put('wp-content/uploads/2026/10/old.php', '<?php');
            $first = $this->pass();
            self::assertSame(0, $first['checked'], 'the first pass reads no file');
            self::assertSame([], self::events(), 'the first pass records nothing, even what was already there');
            $status = SL\status();
            self::assertSame('watching', $status['state']);
            self::assertSame(1, $status['passes']);
            self::assertSame(5, $status['files_indexed']);

            $second = $this->pass();
            self::assertSame(0, $second['checked'], 'unchanged files are not read');

            $this->change('wp-includes/load.php', "<?php // load, changed\n");
            $this->change('wp-content/uploads/2026/10/shell.php', '<?php echo 1;');
            $this->site->put('wp-content/uploads/2026/10/photo.jpg', 'not watched');
            $this->change('wp-includes/extra.php', self::evil());
            $third = $this->pass();
            self::assertSame(3, $third['checked']);
            self::assertSame([
                'high file core.mismatch wp-includes/load.php',
                'high file core.unexpected wp-includes/extra.php',
                'high file susp.eval_base64 wp-includes/extra.php',
                'high file uploads.php_file wp-content/uploads/2026/10/shell.php',
            ], self::sorted(self::events()));
            $hit = array_values(array_filter(SL\events_since(), static fn(array $e): bool => $e['detail']['rule_id'] === 'uploads.php_file'))[0];
            self::assertSame(['path' => 'wp-content/uploads/2026/10/shell.php', 'area' => 'uploads', 'result' => 'php_in_uploads', 'rule_id' => 'uploads.php_file'], $hit['detail']);

            self::assertSame(0, $this->pass()['checked'], 'a change is checked once');
        }

        /** @param list<string> $lines @return list<string> */
        private static function sorted(array $lines): array
        {
            sort($lines);
            return $lines;
        }

        public function test_new_mu_plugin_and_dropin_are_high_events(): void
        {
            $this->core();
            $this->pass();
            SL\check_extras(self::NOW, true);
            $this->site->put('wp-content/mu-plugins/loader.php', "<?php // loader\n");
            $this->site->put('wp-content/object-cache.php', "<?php // cache\n");
            $this->pass();
            self::assertSame([
                'high file_added live.dropin_added wp-content/object-cache.php',
                'high file_added live.mu_plugin_added wp-content/mu-plugins/loader.php',
            ], self::sorted(self::events()));
            self::assertSame(0, SL\check_extras(self::NOW + 400, true), 'the quick check does not repeat what the watch found');
        }

        public function test_quick_check_finds_new_mu_plugins_between_passes(): void
        {
            $this->core();
            self::assertSame(0, SL\check_extras(self::NOW), 'the first look only remembers');
            $this->site->put('wp-content/mu-plugins/x.php', '<?php');
            self::assertSame(0, SL\check_extras(self::NOW + 60), 'at most every five minutes');
            self::assertSame(1, SL\check_extras(self::NOW + 301));
            self::assertSame(['high file_added live.mu_plugin_added wp-content/mu-plugins/x.php'], self::events());
        }

        public function test_a_file_a_gobuildwp_plugin_vouches_for_is_info(): void
        {
            add_filter('gobuildwp_live_known_file', static fn($known, string $abs, string $path) => str_ends_with($path, '/gobuildwp-rescue.php') && is_file($abs) ? 'ours' : $known, 10, 3);
            $this->core();
            $this->pass();
            SL\check_extras(self::NOW, true);
            $this->site->put('wp-content/mu-plugins/gobuildwp-rescue.php', "<?php // rescue\n");
            $this->site->put('wp-content/mu-plugins/other.php', "<?php // other\n");
            self::assertSame(2, SL\check_extras(self::NOW + 301, true));
            self::assertSame([
                'high file_added live.mu_plugin_added wp-content/mu-plugins/other.php',
                'info file_added live.mu_plugin_added wp-content/mu-plugins/gobuildwp-rescue.php',
            ], self::sorted(self::events()));
        }

        public function test_watch_resumes_across_runs_with_a_small_budget(): void
        {
            $this->core();
            for ($i = 0; $i < 30; $i++) {
                $this->site->put('wp-content/themes/twenty/inc/f' . $i . '.php', '<?php // ' . $i);
            }
            $runs = 0;
            do {
                $this->pass(8);
                $runs++;
            } while (SL\status()['passes'] === 0 && $runs < 50);
            self::assertGreaterThan(2, $runs, 'the pass was split across runs');
            self::assertSame(1, SL\status()['passes']);
            self::assertSame(34, SL\status()['files_indexed'], 'no file was lost between runs');
        }

        public function test_excluded_paths_links_and_other_files_are_left_out(): void
        {
            $this->core();
            SL\configure(['excluded_paths' => ['wp-content/themes/twenty/cache']]);
            $this->pass();
            $this->site->put('wp-content/themes/twenty/cache/c.php', self::evil());
            $this->site->put('wp-content/themes/twenty/readme.txt', self::evil());
            $outside = dirname($this->site->root) . '/' . basename($this->site->root) . '-outside';
            mkdir($outside);
            file_put_contents($outside . '/evil.php', self::evil());
            $linked = @symlink($outside, $this->site->root . '/wp-content/themes/linked');
            try {
                $this->pass();
                self::assertSame([], self::events());
                if ($linked) {
                    self::assertNotContains('theme:linked', array_column(SL\discover_buckets(), 'id'), 'a linked folder is not watched');
                }
            } finally {
                @unlink($this->site->root . '/wp-content/themes/linked');
                @rmdir($this->site->root . '/wp-content/themes/linked');
                @unlink($outside . '/evil.php');
                @rmdir($outside);
            }
        }

        public function test_new_plugin_folder_after_the_baseline_is_checked(): void
        {
            $this->core();
            $this->pass();
            $this->site->put('wp-content/plugins/dropper/dropper.php', self::evil());
            $this->pass();
            self::assertSame(['high file susp.eval_base64 wp-content/plugins/dropper/dropper.php'], self::events());
        }

        public function test_removed_buckets_drop_their_index(): void
        {
            $this->core();
            $this->site->put('wp-content/plugins/gone/gone.php', '<?php');
            $this->pass();
            $option = SL\index_option('plugin:gone');
            self::assertArrayHasKey($option, WPPilot_Test_State::$options);
            unlink($this->site->root . '/wp-content/plugins/gone/gone.php');
            rmdir($this->site->root . '/wp-content/plugins/gone');
            $this->pass();
            self::assertArrayNotHasKey($option, WPPilot_Test_State::$options);
        }

        public function test_updates_queue_their_folders_first(): void
        {
            $this->core();
            $this->site->put('wp-content/plugins/akismet/akismet.php', '<?php');
            $this->pass();
            SL\on_upgrade(null, ['type' => 'plugin', 'action' => 'update', 'plugins' => ['akismet/akismet.php', 'hello.php']]);
            self::assertSame(['plugin:akismet', 'plugins'], WPPilot_Test_State::$options[SL\QUEUE_OPTION]);
            SL\on_upgrade(null, ['type' => 'theme', 'themes' => ['twenty']]);
            self::assertContains('theme:twenty', WPPilot_Test_State::$options[SL\QUEUE_OPTION]);

            $cursor = SL\take_queued(['buckets' => SL\discover_buckets(), 'pos' => 0, 'walk' => null]);
            self::assertSame(['plugins', 'plugin:akismet', 'theme:twenty'], array_slice(array_column($cursor['buckets'], 'id'), 0, 3));
            self::assertArrayNotHasKey(SL\QUEUE_OPTION, WPPilot_Test_State::$options);
        }

        public function test_a_pass_walks_the_risky_folders_first(): void
        {
            $this->core();
            $this->site->put('wp-content/plugins/akismet/akismet.php', '<?php');
            $ids = array_column(SL\discover_buckets(), 'id');
            self::assertSame(['core:root', 'content', 'mu', 'uploads'], array_values(array_intersect($ids, ['core:root', 'content', 'mu', 'uploads'])));
            self::assertLessThan(array_search('core:wp-includes', $ids, true), array_search('uploads', $ids, true));
            self::assertLessThan(array_search('plugin:akismet', $ids, true), array_search('uploads', $ids, true));
        }

        public function test_a_busy_lock_skips_the_run(): void
        {
            $this->core();
            add_option(SL\LOCK_OPTION, self::NOW);
            self::assertSame('busy', SL\run_watch(5, self::NOW + 10)['reason']);
            self::assertTrue(SL\run_watch(5, self::NOW + SL\LOCK_SECONDS + 1)['ran'], 'a stale lock is taken over');
            self::assertArrayNotHasKey(SL\LOCK_OPTION, WPPilot_Test_State::$options, 'the lock is released');
        }

        // ------------------------------------------------------------ instant checks

        public function test_new_administrators_are_high_events_with_the_fix_fields(): void
        {
            $GLOBALS['gobuildwp_sl_test_users'] = [7 => 'eve', 8 => 'bob'];
            SL\on_set_user_role(7, 'administrator', ['subscriber']);
            SL\on_set_user_role(8, 'editor', ['subscriber']);
            SL\on_set_user_role(8, 'administrator', ['administrator']);
            $GLOBALS['gobuildwp_sl_test_admins'] = [7];
            SL\on_user_register(7);
            SL\on_grant_super_admin(8);

            $events = SL\events_since();
            self::assertCount(2, $events, 'one event per user; a role set and a registration for the same user are one');
            self::assertSame('high', $events[0]['severity']);
            self::assertSame('Administrator: eve', $events[0]['subject']);
            self::assertSame('wp_users', $events[0]['detail']['table']);
            self::assertSame('7', $events[0]['detail']['entity_id']);
            self::assertSame('users.new_admin', $events[0]['detail']['rule_id']);
            self::assertSame('user:eve', $events[0]['detail']['label']);
        }

        public function test_sensitive_settings(): void
        {
            SL\on_updated_option('siteurl', 'https://a.test', 'https://evil.test');
            SL\on_updated_option('blogname', 'a', 'b');
            SL\on_updated_option('active_plugins', ['a/a.php'], ['a/a.php']);
            SL\on_updated_option('active_plugins', ['a/a.php'], ['a/a.php', 'b/b.php']);
            SL\on_updated_option('default_role', 'subscriber', 'administrator');
            SL\on_updated_option('users_can_register', '1', '0');
            update_option('default_role', 'administrator');
            SL\on_updated_option('users_can_register', '0', '1');
            self::assertSame([
                'high option options.siteurl Setting changed: siteurl',
                'low option options.active_plugins Setting changed: active_plugins',
                'critical option options.default_role Setting changed: default_role',
                'critical option options.users_can_register Setting changed: users_can_register',
            ], self::events());
            self::assertSame('Activated: b/b.php', SL\events_since()[1]['detail']['note']);
        }

        public function test_php_uploads(): void
        {
            SL\on_upload_prefilter(['name' => 'shell.php', 'tmp_name' => '']);
            SL\on_upload_prefilter(['name' => 'photo.jpg', 'tmp_name' => $this->site->put('tmp/a', 'GIF89a <?php echo 1;')]);
            SL\on_upload_prefilter(['name' => 'clean.jpg', 'tmp_name' => $this->site->put('tmp/b', 'GIF89a')]);
            self::assertSame('upload', SL\on_upload_prefilter('upload'), 'the filter passes anything through');

            $GLOBALS['gobuildwp_sl_test_attachments'] = [
                5 => $this->site->put('wp-content/uploads/2026/10/x.php.jpg', 'GIF89a'),
                6 => $this->site->put('wp-content/uploads/2026/10/y.gif', 'GIF89a <?php echo 1;'),
                7 => $this->site->put('wp-content/uploads/2026/10/z.png', 'PNG'),
                8 => '/elsewhere/q.php',
            ];
            foreach ([5, 6, 7, 8, 9] as $id) {
                SL\on_add_attachment($id);
            }
            self::assertSame([
                'high upload uploads.php_upload Upload of shell.php',
                'high upload susp.php_tag_in_non_php Upload of photo.jpg',
                'high file uploads.php_double_extension wp-content/uploads/2026/10/x.php.jpg',
                'high file susp.php_tag_in_non_php wp-content/uploads/2026/10/y.gif',
            ], self::events());
            self::assertSame('php_in_uploads', SL\events_since()[2]['detail']['result']);
        }

        public function test_posts_with_new_injected_scripts(): void
        {
            $bad = '<p>Hi</p><script src="https://cdn.evil.test/x.js"></script><iframe src="https://x.test" width="0" height="0"></iframe>';
            $post = (object) ['ID' => 12, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Hello', 'post_content' => $bad];
            $before = (object) ['post_content' => '<script src="https://cdn.evil.test/x.js"></script>'];
            SL\on_after_insert_post(12, $post, true, $before);
            SL\on_after_insert_post(13, (object) ['post_type' => 'revision', 'post_status' => 'inherit', 'post_content' => $bad]);
            SL\on_after_insert_post(14, (object) ['post_type' => 'page', 'post_status' => 'draft', 'post_title' => '', 'post_content' => '<script src="/local.js"></script>']);
            self::assertSame(['high post posts.hidden_iframe Post #12: Hello'], self::events(), 'only what the save added; the external script was already there');
            $detail = SL\events_since()[0]['detail'];
            self::assertSame(['wp_posts', '12'], [$detail['table'], $detail['entity_id']]);
        }

        public function test_file_editor_saves(): void
        {
            $_POST = ['file' => 'functions.php', 'theme' => 'twenty'];
            try {
                SL\on_file_editor_save();
            } finally {
                $_POST = [];
            }
            self::assertSame(['medium file_editor  functions.php'], self::events());
        }

        // ------------------------------------------------------------ Cloud route

        public function test_cloud_route_runs_configures_and_pages_events(): void
        {
            $this->core();
            SL\record('high', 'admin', 'a', [], self::NOW);
            SL\record('low', 'x', 'b', [], self::NOW);
            $out = SL\handle_live(['after' => 0, 'limit' => 1, 'run' => true, 'seconds' => 5, 'configure' => ['interval_minutes' => 60]], self::NOW);
            self::assertIsArray($out);
            self::assertTrue($out['configured']);
            self::assertTrue($out['run']['ran']);
            self::assertSame(60, $out['status']['interval_minutes']);
            self::assertSame([1], array_column($out['events'], 'id'));
            self::assertTrue($out['more']);
            self::assertSame(2, $out['last_event_id']);

            $next = SL\handle_live(['after' => 1]);
            self::assertSame([2], array_column($next['events'], 'id'));
            self::assertFalse($next['more']);
            self::assertNull($next['run']);
        }

        /** @return array<string, array{0: array<string, mixed>}> */
        public static function bad_bodies(): array
        {
            return [
                'after negative' => [['after' => -1]],
                'after string' => [['after' => '3']],
                'limit too big' => [['limit' => 201]],
                'seconds too long' => [['seconds' => 26]],
                'run not bool' => [['run' => 'yes']],
                'configure not object' => [['configure' => 'x']],
                'configure unknown' => [['configure' => ['nope' => true]]],
            ];
        }

        /** @param array<string, mixed> $body */
        #[DataProvider('bad_bodies')]
        public function test_cloud_route_refuses_bad_bodies(array $body): void
        {
            $out = SL\handle_live($body);
            self::assertInstanceOf(WP_Error::class, $out);
            self::assertSame(400, $out->get_error_data()['status']);
        }

        public function test_route_uses_the_cloud_permission(): void
        {
            if (!function_exists('wppilot_cloud_rest_permission')) {
                self::markTestSkipped('The Cloud module is not loaded in this run.');
            }
            $GLOBALS['wppilot_test_rest_routes'] = [];
            SL\register_route();
            self::assertSame('wppilot_cloud_rest_permission', $GLOBALS['wppilot_test_rest_routes']['gobuildwp/v1/cloud/live']['permission_callback'] ?? null);
            self::assertSame('POST', $GLOBALS['wppilot_test_rest_routes']['gobuildwp/v1/cloud/live']['methods'] ?? null);
        }

        public function test_kit_manifest_lists_the_abilities_with_their_annotations(): void
        {
            $kit = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/security-live/kit.json'), true);
            $bootstrap = require dirname(__DIR__, 4) . '/includes/kits/security-live/bootstrap.php';
            self::assertCount(4, $bootstrap['ability_files']);
            foreach ($bootstrap['ability_files'] as $file) {
                $source = (string) file_get_contents($file);
                self::assertStringContainsString("Runtime\\can_run() && current_user_can('manage_options')", $source);
                self::assertMatchesRegularExpression("/wp_register_ability\\('gobuildwp\\/security-live-/", $source);
            }
            $readonly = array_column($kit['abilities'], 'readonly', 'name');
            self::assertSame([
                'gobuildwp/security-live-status' => true,
                'gobuildwp/security-live-events' => true,
                'gobuildwp/security-live-run-watch' => false,
                'gobuildwp/security-live-configure' => false,
            ], $readonly);
        }
    }
}
