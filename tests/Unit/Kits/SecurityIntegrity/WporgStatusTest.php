<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Tests\Unit\Kits\SecurityIntegrity;

require_once __DIR__ . '/harness.php';

use GoBuildWP\Kits\SecurityIntegrity as SI;
use GoBuildWP\Kits\SecurityIntegrity\TestNet;
use Kit_Test_Site;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;

/**
 * gobuildwp/wporg-status-check: closed, abandoned, ok and unknown verdicts from scripted
 * WordPress.org answers (the shapes GoBuildWP Cloud's check was written against), which components
 * may be looked up at all, the cache, paging and the time budget. Nothing reaches the network.
 */
final class WporgStatusTest extends TestCase
{
    /** 2026-11-03 12:00 UTC. */
    private const NOW = 1793707200;

    private const CLOSED = ['error' => 'closed', 'name' => 'Display Widgets', 'slug' => 'display-widgets', 'closed' => true, 'closed_date' => '2021-01-30', 'reason' => 'security-issue', 'reason_text' => 'Security Issue'];

    protected function setUp(): void
    {
        reset_state();
        delete_option(SI\WPORG_PLUGINS_OPTION);
        delete_option(SI\WPORG_THEMES_OPTION);
        unset($GLOBALS['wppilot_test_themes']);
        TestNet::$plugins = [];
    }

    protected function tearDown(): void
    {
        delete_option(SI\WPORG_PLUGINS_OPTION);
        delete_option(SI\WPORG_THEMES_OPTION);
        unset($GLOBALS['wppilot_test_themes']);
        reset_state();
    }

    /** @param array<string, string> $files plugin file => update-check id */
    private static function update_check(array $files): void
    {
        $no_update = [];
        foreach ($files as $file => $id) {
            $no_update[$file] = (object) ['id' => $id, 'plugin' => $file];
        }
        TestNet::$site_transients['update_plugins'] = (object) ['response' => [], 'no_update' => $no_update];
    }

    private static function plugin(string $file, string $name, string $version = '1.0', string $update_uri = ''): void
    {
        TestNet::$plugins[$file] = ['Name' => $name, 'Version' => $version, 'UpdateURI' => $update_uri];
    }

    /** @return array<string, mixed> */
    private static function check(array $input = [], float $seconds = 20.0): array
    {
        return SI\wporg_status_check($input, self::NOW, $seconds);
    }

    /** @return array<string, array<string, mixed>> */
    private static function by_slug(array $result): array
    {
        return array_column($result['items'], null, 'slug');
    }

    public function test_parse_follows_the_cloud_rules(): void
    {
        $closed = SI\wporg_parse(404, (string) json_encode(self::CLOSED));
        self::assertSame('closed', $closed['state']);
        self::assertSame('security-issue', $closed['reason']);
        self::assertSame('Security Issue', $closed['reason_text']);
        self::assertSame('2021-01-30', $closed['closed_date']);
        self::assertSame('Display Widgets', $closed['name']);

        $live = SI\wporg_parse(200, (string) json_encode(['name' => 'Akismet', 'last_updated' => '2025-10-24 4:13am GMT']));
        self::assertSame(['live', '2025-10-24'], [$live['state'], $live['last_updated']]);
        self::assertSame('2026-08-19', SI\wporg_parse(200, '{"name":"T","last_updated":"2026-08-19"}')['last_updated']);

        $missing = SI\wporg_parse(404, '{"error":"Plugin not found."}');
        self::assertSame(['unknown', 'Not listed on WordPress.org.'], [$missing['state'], $missing['error']]);
        self::assertSame('unknown', SI\wporg_parse(500, '<html>')['state']);
        self::assertSame('unknown', SI\wporg_parse(503, '{"error":"busy"}')['state']);
        self::assertSame('unknown', SI\wporg_parse(200, '[1,2]')['state']);
        self::assertSame('WordPress.org gave no release date.', SI\wporg_parse(200, '{"name":"X","last_updated":"2025-02-30"}')['error']);
    }

    public function test_remote_text_is_cleaned_and_cut(): void
    {
        $closed = SI\wporg_parse(404, (string) json_encode([
            'closed' => true,
            'reason' => 'Bad Reason!<script>',
            'reason_text' => "<b>Security</b>\x07 Issue " . str_repeat('x', 400),
            'closed_date' => 'yesterday',
        ]));
        self::assertNull($closed['reason']);
        self::assertStringStartsWith('Security  Issue', (string) $closed['reason_text']);
        self::assertStringNotContainsString('<', (string) $closed['reason_text']);
        self::assertLessThanOrEqual(200, mb_strlen((string) $closed['reason_text']));
        self::assertNull($closed['closed_date']);
    }

    public function test_abandoned_counts_whole_days(): void
    {
        // 2024-11-02 is 731 whole days before NOW; 2024-11-03 is 730.
        self::assertTrue(SI\wporg_abandoned('2024-11-02', self::NOW));
        self::assertFalse(SI\wporg_abandoned('2024-11-03', self::NOW));
        self::assertFalse(SI\wporg_abandoned(null, self::NOW));
    }

    public function test_closed_abandoned_ok_and_unknown_per_component(): void
    {
        self::plugin('akismet/akismet.php', 'Akismet Anti-spam', '5.0');
        self::plugin('display-widgets/display-widgets.php', 'Display Widgets', '2.6');
        self::plugin('old-thing/old-thing.php', 'Old Thing');
        self::plugin('gone/gone.php', 'Gone');
        self::plugin('hello.php', 'Hello Dolly');
        self::plugin('premium/premium.php', 'Premium', '3.0', 'https://vendor.example/premium');
        // The update check lists live WordPress.org plugins and leaves closed ones out.
        self::update_check([
            'akismet/akismet.php' => 'w.org/plugins/akismet',
            'old-thing/old-thing.php' => 'w.org/plugins/old-thing',
            'gone/gone.php' => 'w.org/plugins/gone',
        ]);
        TestNet::$responses = [
            TestNet::json(['name' => 'Akismet', 'last_updated' => '2026-10-01 1:00pm GMT']),
            TestNet::json(self::CLOSED, 404),
            TestNet::json(['error' => 'Plugin not found.'], 404),
            TestNet::json(['name' => 'Old Thing', 'last_updated' => '2023-01-05 9:00am GMT']),
        ];

        $result = self::check(['type' => 'plugin']);
        $items = self::by_slug($result);

        self::assertSame(['akismet', 'display-widgets', 'gone', 'hello', 'old-thing', 'premium'], array_column($result['items'], 'slug'));
        self::assertSame(['closed' => 1, 'abandoned' => 1, 'ok' => 1, 'unknown' => 3], $result['summary']);
        self::assertSame(6, $result['total']);
        self::assertNull($result['next_cursor']);

        self::assertSame(['ok', 'update_check', '2026-10-01'], [$items['akismet']['status'], $items['akismet']['source'], $items['akismet']['last_updated']]);
        self::assertSame('closed', $items['display-widgets']['status']);
        self::assertSame('slug', $items['display-widgets']['source']);
        self::assertSame(['2021-01-30', 'security-issue', 'Security Issue'], [$items['display-widgets']['closed_date'], $items['display-widgets']['reason'], $items['display-widgets']['reason_text']]);
        self::assertSame('2.6', $items['display-widgets']['version']);
        self::assertSame(['unknown', 'Not listed on WordPress.org.'], [$items['gone']['status'], $items['gone']['note']]);
        self::assertSame('abandoned', $items['old-thing']['status']);
        self::assertSame(['unknown', 'none'], [$items['hello']['status'], $items['hello']['source']]);
        self::assertSame(['unknown', 'none'], [$items['premium']['status'], $items['premium']['source']]);
        self::assertStringContainsString('Update URI', $items['premium']['note']);

        // Four lookups: nothing for the single-file plugin or the premium one.
        self::assertCount(4, TestNet::$calls);
        self::assertStringStartsWith('https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=akismet&', TestNet::$calls[0]);
    }

    public function test_a_slug_only_match_needs_the_same_name(): void
    {
        // A custom plugin in a folder WordPress.org once used for a closed plugin.
        self::plugin('display-widgets/display-widgets.php', 'Acme Sidebar Tools');
        self::update_check([]);
        TestNet::$responses = [TestNet::json(self::CLOSED, 404)];

        $item = self::check()['items'][0];
        self::assertSame('unknown', $item['status']);
        self::assertStringContainsString('may be a different plugin', $item['note']);
    }

    public function test_another_updater_keeps_the_plugin_off_wporg(): void
    {
        self::plugin('display-widgets/display-widgets.php', 'Display Widgets');
        self::update_check(['display-widgets/display-widgets.php' => 'vendor.example/display-widgets']);

        $item = self::check()['items'][0];
        self::assertSame(['unknown', 'none'], [$item['status'], $item['source']]);
        self::assertSame([], TestNet::$calls);
    }

    public function test_themes_use_the_themes_api(): void
    {
        $GLOBALS['wppilot_test_themes'] = [
            'twentyx' => ['Name' => 'Twenty X', 'Version' => '1.2'],
            'agency' => ['Name' => 'Agency', 'Version' => '2.0', 'UpdateURI' => 'https://vendor.example/agency'],
        ];
        TestNet::$site_transients['update_themes'] = (object) ['response' => [], 'no_update' => [
            'twentyx' => (object) ['theme' => 'twentyx', 'url' => 'https://wordpress.org/themes/twentyx/', 'package' => 'https://downloads.wordpress.org/theme/twentyx.1.2.zip'],
        ]];
        TestNet::$responses = [TestNet::json(['name' => 'Twenty X', 'last_updated' => '2026-08-19'])];

        $result = self::check(['type' => 'theme']);
        $items = self::by_slug($result);
        self::assertSame(['agency', 'twentyx'], array_column($result['items'], 'slug'));
        self::assertSame(['ok', 'theme', '2026-08-19'], [$items['twentyx']['status'], $items['twentyx']['type'], $items['twentyx']['last_updated']]);
        self::assertSame('unknown', $items['agency']['status']);
        self::assertCount(1, TestNet::$calls);
        self::assertStringStartsWith('https://api.wordpress.org/themes/info/1.2/?action=theme_information&request%5Bslug%5D=twentyx&', TestNet::$calls[0]);
    }

    public function test_answers_are_cached_and_failures_are_unknown(): void
    {
        self::plugin('akismet/akismet.php', 'Akismet');
        self::update_check(['akismet/akismet.php' => 'w.org/plugins/akismet']);
        TestNet::$responses = [new WP_Error('http_request_failed', 'cURL error 28: timed out')];

        $first = self::check()['items'][0];
        self::assertSame('unknown', $first['status']);
        self::assertStringContainsString('Could not reach WordPress.org', $first['note']);
        // The failure is cached briefly too, so a second call does not ask again.
        self::check();
        self::assertCount(1, TestNet::$calls);

        TestNet::$transients = [];
        TestNet::$responses = [TestNet::json(['name' => 'Akismet', 'last_updated' => '2026-10-01'])];
        self::assertSame('ok', self::check()['items'][0]['status']);
        self::assertSame('ok', self::check()['items'][0]['status']);
        self::assertCount(2, TestNet::$calls);
    }

    public function test_paging_and_the_time_budget_hand_back_a_cursor(): void
    {
        $files = [];
        foreach (['aa', 'bb', 'cc'] as $slug) {
            self::plugin($slug . '/' . $slug . '.php', strtoupper($slug));
            $files[$slug . '/' . $slug . '.php'] = 'w.org/plugins/' . $slug;
        }
        self::update_check($files);
        TestNet::$responses = array_fill(0, 3, TestNet::json(['name' => 'X', 'last_updated' => '2026-10-01']));

        $page = self::check(['limit' => 2]);
        self::assertSame(['aa', 'bb'], array_column($page['items'], 'slug'));
        self::assertSame(2, $page['next_cursor']);
        $page = self::check(['limit' => 2, 'cursor' => 2]);
        self::assertSame(['cc'], array_column($page['items'], 'slug'));
        self::assertNull($page['next_cursor']);

        // With the time spent, one lookup still happens and the cursor points at the next one.
        TestNet::$transients = [];
        TestNet::$calls = [];
        TestNet::$responses = array_fill(0, 3, TestNet::json(['name' => 'X', 'last_updated' => '2026-10-01']));
        $page = self::check([], seconds: 0.0);
        self::assertSame(['aa'], array_column($page['items'], 'slug'));
        self::assertSame(1, $page['next_cursor']);
        self::assertCount(1, TestNet::$calls);

        // slugs narrows the list.
        $page = self::check(['slugs' => ['CC']]);
        self::assertSame(['cc'], array_column($page['items'], 'slug'));
        self::assertSame(1, $page['total']);
    }

    public function test_the_ability_is_public_readonly_and_needs_manage_options(): void
    {
        Kit_Test_Site::as_user(1, 'manage_options');
        $host = new FakeHost();
        Runtime\host($host);
        $kit = require dirname(__DIR__, 4) . '/includes/kits/security-integrity/bootstrap.php';
        self::assertContains('wporg-status-check.php', array_map('basename', $kit['ability_files']));
        foreach ($kit['ability_files'] as $file) {
            require $file;
        }
        $args = Kit_Test_Site::registration('gobuildwp/wporg-status-check');
        self::assertIsArray($args);
        self::assertSame('security-integrity', $args['category']);
        self::assertTrue($args['meta']['annotations']['readonly']);
        self::assertFalse($args['meta']['annotations']['destructive']);
        self::assertTrue($args['meta']['mcp']['public']);
        self::assertTrue(($args['permission_callback'])());
        Kit_Test_Site::as_user(1, 'edit_posts');
        self::assertFalse(($args['permission_callback'])());

        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/security-integrity/kit.json'), true);
        $entry = array_values(array_filter($manifest['abilities'], static fn(array $a): bool => $a['name'] === 'gobuildwp/wporg-status-check'));
        self::assertSame([['name' => 'gobuildwp/wporg-status-check', 'readonly' => true, 'destructive' => false, 'ledger' => 'self']], $entry);

        self::plugin('akismet/akismet.php', 'Akismet');
        self::update_check(['akismet/akismet.php' => 'w.org/plugins/akismet']);
        TestNet::$responses = [TestNet::json(['name' => 'Akismet', 'last_updated' => gmdate('Y-m-d')])];
        $result = ($args['execute_callback'])([]);
        self::assertSame(['closed' => 0, 'abandoned' => 0, 'ok' => 1, 'unknown' => 0], $result['summary']);
    }
}
