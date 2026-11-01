<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Response;
use WPPilot_Test_State;

use function WPPilot\OAuth\Middleware\oauth_identity_may_use_route;
use function WPPilot\OAuth\Middleware\record_oauth_identity;
use function WPPilot\OAuth\Middleware\reset_request_context;

require_once dirname(__DIR__) . '/doubles/admin.php';
require_once dirname(__DIR__) . '/doubles/mcp-surface.php';
require_once dirname(__DIR__) . '/doubles/cloud.php';
require_once dirname(__DIR__) . '/doubles/cloud-network.php';
require_once dirname(__DIR__, 2) . '/includes/capabilities.php';
require_once dirname(__DIR__, 2) . '/includes/clients.php';
require_once dirname(__DIR__, 2) . '/includes/rate-limit.php';
require_once dirname(__DIR__, 2) . '/includes/cloud/bootstrap.php';

/**
 * The installed-software inventory the Cloud matches against vulnerability data,
 * and its hash in the status answer.
 */
final class CloudInventoryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    /** @var array<string, array<string, string>> */
    private array $savedPlugins = [];

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedPlugins = WPPilot_Test_State::$plugins;
        unset(
            WPPilot_Test_State::$options[GOBUILDWP_CLOUD_INVENTORY_HASH_OPTION],
            WPPilot_Test_State::$options[WPPILOT_CLOUD_LINK_OPTION],
            WPPilot_Test_State::$options['active_plugins'],
            WPPilot_Test_State::$options['active_sitewide_plugins'],
        );
        WPPilot_Test_State::$plugins = [];
        WPPilot_Test_State::$multisite = false;
        WPPilot_Test_State::$current_user_id = 1;
        $GLOBALS['wppilot_test_option_autoload'] = [];
        $GLOBALS['wppilot_test_rest_routes'] = [];
        reset_request_context();
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        WPPilot_Test_State::$plugins = $this->savedPlugins;
        WPPilot_Test_State::$multisite = false;
        WPPilot_Test_State::$current_user_id = 0;
        unset(
            $GLOBALS['wppilot_test_site_transients'],
            $GLOBALS['wppilot_test_themes'],
            $GLOBALS['wppilot_test_stylesheet'],
            $GLOBALS['wppilot_test_template'],
            $GLOBALS['wppilot_test_mu_plugins'],
            $GLOBALS['wppilot_test_dropins'],
        );
        reset_request_context();
        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> type:slug => item */
    private static function byKey(array $inventory): array
    {
        $out = [];
        foreach ($inventory['items'] as $item) {
            $out[$item['type'] . ':' . $item['slug']] = $item;
        }

        return $out;
    }

    private function link(int $token_id = 7): void
    {
        WPPilot_Test_State::$options[WPPILOT_CLOUD_LINK_OPTION] = [
            'site_id' => 'site_abc',
            'token_id' => $token_id,
            'account_hint' => '',
            'cloud_url' => 'https://app.gobuildwp.com',
            'paired_at' => '2026-10-28T00:00:00Z',
        ];
    }

    public function test_inventory_lists_core_plugins_themes_mu_plugins_and_dropins(): void
    {
        WPPilot_Test_State::$plugins = [
            'akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.3'],
            'hello.php' => ['Name' => 'Hello Dolly', 'Version' => '1.7.2'],
        ];
        WPPilot_Test_State::$options['active_plugins'] = ['akismet/akismet.php'];
        $GLOBALS['wppilot_test_themes'] = [
            'twentytwentyfive' => ['Name' => 'Twenty Twenty-Five', 'Version' => '1.2'],
            'child' => ['Name' => 'Child', 'Version' => '0.1', 'Template' => 'twentytwentyfive'],
            'unused' => ['Name' => 'Unused', 'Version' => '3.0'],
        ];
        $GLOBALS['wppilot_test_stylesheet'] = 'child';
        $GLOBALS['wppilot_test_template'] = 'twentytwentyfive';
        $GLOBALS['wppilot_test_mu_plugins'] = ['loader.php' => ['Name' => 'Loader', 'Version' => '1.0']];
        $GLOBALS['wppilot_test_dropins'] = ['advanced-cache.php' => ['Name' => 'Advanced caching plugin', 'Version' => '']];

        $inventory = gobuildwp_cloud_inventory();
        $items = self::byKey($inventory);

        self::assertSame('7.0', $inventory['wp_version']);
        self::assertSame(PHP_VERSION, $inventory['php_version']);
        self::assertFalse($inventory['multisite']);
        self::assertSame('en_US', $inventory['locale']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', $inventory['generated_at']);
        self::assertFalse($inventory['truncated']);
        self::assertSame('core', $inventory['items'][0]['type'], 'core first');
        self::assertSame(['type' => 'core', 'slug' => 'wordpress', 'file' => '', 'name' => 'WordPress', 'version' => '7.0', 'active' => true, 'network_active' => false, 'parent' => null, 'update_uri' => '', 'new_version' => null, 'wporg' => true], $items['core:wordpress']);
        self::assertTrue($items['plugin:akismet']['active']);
        self::assertSame('akismet/akismet.php', $items['plugin:akismet']['file']);
        self::assertFalse($items['plugin:hello']['active']);
        self::assertTrue($items['theme:child']['active']);
        self::assertSame('twentytwentyfive', $items['theme:child']['parent']);
        self::assertTrue($items['theme:twentytwentyfive']['active'], 'the parent of the active child theme is in use');
        self::assertFalse($items['theme:unused']['active']);
        self::assertSame('', $items['theme:unused']['file']);
        self::assertSame('mu_plugin', $items['mu_plugin:loader']['type']);
        self::assertSame('loader.php', $items['mu_plugin:loader']['file']);
        self::assertSame('dropin', $items['dropin:advanced-cache']['type']);

        $json = (string) json_encode($inventory);
        self::assertStringNotContainsString(ABSPATH, $json, 'no absolute paths');
        self::assertStringNotContainsString(str_replace('/', '\\/', ABSPATH), $json);
    }

    public function test_single_file_plugin_slug_is_its_basename(): void
    {
        self::assertSame('hello', gobuildwp_cloud_inventory_plugin_slug('hello.php'));
        self::assertSame('akismet', gobuildwp_cloud_inventory_plugin_slug('akismet/akismet.php'));
        self::assertSame('my-plugin', gobuildwp_cloud_inventory_plugin_slug('my-plugin/sub/main.php'));
    }

    public function test_hash_does_not_depend_on_order(): void
    {
        $a = gobuildwp_cloud_inventory_item(['type' => 'plugin', 'slug' => 'a', 'file' => 'a/a.php', 'version' => '1.0', 'active' => true]);
        $b = gobuildwp_cloud_inventory_item(['type' => 'plugin', 'slug' => 'b', 'file' => 'b/b.php', 'version' => '2.0']);
        $t = gobuildwp_cloud_inventory_item(['type' => 'theme', 'slug' => 't', 'version' => '1', 'active' => true]);

        self::assertSame(
            gobuildwp_cloud_inventory_hash_of([$a, $b, $t]),
            gobuildwp_cloud_inventory_hash_of([$t, $b, $a]),
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', gobuildwp_cloud_inventory_hash_of([$a]));
    }

    public function test_hash_changes_with_version_activation_and_network_state(): void
    {
        $item = static fn(string $version, bool $active = true, bool $network = false): array => gobuildwp_cloud_inventory_item([
            'type' => 'plugin',
            'slug' => 'a',
            'file' => 'a/a.php',
            'version' => $version,
            'active' => $active,
            'network_active' => $network,
        ]);
        $base = gobuildwp_cloud_inventory_hash_of([$item('1.0')]);

        self::assertNotSame($base, gobuildwp_cloud_inventory_hash_of([$item('1.0.1')]));
        self::assertNotSame($base, gobuildwp_cloud_inventory_hash_of([$item('1.0', active: false)]));
        self::assertNotSame($base, gobuildwp_cloud_inventory_hash_of([$item('1.0', network: true)]));
    }

    public function test_hash_is_cached_unautoloaded_and_forget_deletes_it(): void
    {
        WPPilot_Test_State::$plugins = ['a/a.php' => ['Name' => 'A', 'Version' => '1.0']];
        $first = gobuildwp_cloud_inventory_hash(now: 1_000);

        self::assertSame(['hash' => $first, 'at' => 1_000], WPPilot_Test_State::$options[GOBUILDWP_CLOUD_INVENTORY_HASH_OPTION]);
        self::assertFalse($GLOBALS['wppilot_test_option_autoload'][GOBUILDWP_CLOUD_INVENTORY_HASH_OPTION]);

        // A version change without any hook firing: the cache still answers until it expires.
        WPPilot_Test_State::$plugins = ['a/a.php' => ['Name' => 'A', 'Version' => '1.1']];
        self::assertSame($first, gobuildwp_cloud_inventory_hash(now: 1_100));
        self::assertNotSame($first, gobuildwp_cloud_inventory_hash(now: 1_000 + GOBUILDWP_CLOUD_INVENTORY_HASH_TTL), 'expired');

        gobuildwp_cloud_inventory_forget();
        self::assertArrayNotHasKey(GOBUILDWP_CLOUD_INVENTORY_HASH_OPTION, WPPilot_Test_State::$options);
    }

    public function test_every_change_hook_forgets_the_hash(): void
    {
        foreach ([
            'upgrader_process_complete',
            'activated_plugin',
            'deactivated_plugin',
            'deleted_plugin',
            'switch_theme',
            'deleted_theme',
            '_core_updated_successfully',
            'automatic_updates_complete',
        ] as $hook) {
            self::assertContains($hook, GOBUILDWP_CLOUD_INVENTORY_HOOKS);
        }
    }

    public function test_multisite_marks_network_active_plugins(): void
    {
        WPPilot_Test_State::$multisite = true;
        WPPilot_Test_State::$plugins = [
            'net/net.php' => ['Name' => 'Net', 'Version' => '1.0'],
            'local/local.php' => ['Name' => 'Local', 'Version' => '1.0'],
        ];
        WPPilot_Test_State::$options['active_plugins'] = ['local/local.php'];
        WPPilot_Test_State::$options['active_sitewide_plugins'] = ['net/net.php' => 1_700_000_000];

        $inventory = gobuildwp_cloud_inventory();
        $items = self::byKey($inventory);

        self::assertTrue($inventory['multisite']);
        self::assertTrue($items['plugin:net']['network_active']);
        self::assertTrue($items['plugin:net']['active']);
        self::assertFalse($items['plugin:local']['network_active']);
        self::assertTrue($items['plugin:local']['active']);
    }

    public function test_network_activation_is_ignored_on_a_single_site(): void
    {
        WPPilot_Test_State::$plugins = ['net/net.php' => ['Name' => 'Net', 'Version' => '1.0']];
        WPPilot_Test_State::$options['active_sitewide_plugins'] = ['net/net.php' => 1];

        $items = self::byKey(gobuildwp_cloud_inventory());

        self::assertFalse($items['plugin:net']['network_active']);
        self::assertFalse($items['plugin:net']['active']);
    }

    public function test_update_uri_is_captured_for_plugins_and_themes(): void
    {
        WPPilot_Test_State::$plugins = ['pro/pro.php' => ['Name' => 'Pro', 'Version' => '2.0', 'UpdateURI' => 'https://vendor.example/pro']];
        $GLOBALS['wppilot_test_themes'] = ['agency' => ['Name' => 'Agency', 'Version' => '1.0', 'UpdateURI' => 'https://vendor.example/agency']];

        $items = self::byKey(gobuildwp_cloud_inventory());

        self::assertSame('https://vendor.example/pro', $items['plugin:pro']['update_uri']);
        self::assertSame('https://vendor.example/agency', $items['theme:agency']['update_uri']);
    }

    public function test_wporg_and_new_version_come_from_the_update_transients(): void
    {
        WPPilot_Test_State::$plugins = [
            'akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.3'],
            'hello.php' => ['Name' => 'Hello Dolly', 'Version' => '1.7.2'],
            'premium/premium.php' => ['Name' => 'Premium', 'Version' => '3.0'],
            'custom/custom.php' => ['Name' => 'Custom', 'Version' => '1.0'],
        ];
        $GLOBALS['wppilot_test_themes'] = [
            'twentytwentyfive' => ['Name' => 'Twenty Twenty-Five', 'Version' => '1.2'],
            'vendor' => ['Name' => 'Vendor', 'Version' => '1.0', 'UpdateURI' => 'https://vendor.example/t'],
            'local' => ['Name' => 'Local', 'Version' => '1.0'],
        ];
        $GLOBALS['wppilot_test_site_transients'] = [
            'update_plugins' => (object) [
                'response' => [
                    'akismet/akismet.php' => (object) ['id' => 'w.org/plugins/akismet', 'new_version' => '5.4'],
                    // A commercial updater injecting its own offer.
                    'premium/premium.php' => (object) ['id' => 'premium/premium.php', 'new_version' => '3.1'],
                ],
                'no_update' => [
                    'hello.php' => (object) ['id' => 'w.org/plugins/hello-dolly', 'new_version' => '1.7.2'],
                ],
            ],
            'update_themes' => (object) [
                'response' => ['twentytwentyfive' => ['theme' => 'twentytwentyfive', 'new_version' => '1.3']],
                'no_update' => ['vendor' => ['theme' => 'vendor', 'new_version' => '1.0']],
            ],
            'update_core' => (object) [
                'updates' => [(object) ['response' => 'upgrade', 'current' => '7.1']],
            ],
        ];

        $items = self::byKey(gobuildwp_cloud_inventory());

        self::assertTrue($items['plugin:akismet']['wporg']);
        self::assertSame('5.4', $items['plugin:akismet']['new_version']);
        self::assertTrue($items['plugin:hello']['wporg'], 'no_update counts');
        self::assertNull($items['plugin:hello']['new_version'], 'no_update is not an offer');
        self::assertFalse($items['plugin:premium']['wporg']);
        self::assertSame('3.1', $items['plugin:premium']['new_version']);
        self::assertFalse($items['plugin:custom']['wporg'], 'not listed at all');
        self::assertTrue($items['theme:twentytwentyfive']['wporg']);
        self::assertSame('1.3', $items['theme:twentytwentyfive']['new_version']);
        self::assertFalse($items['theme:vendor']['wporg'], 'its Update URI names another host');
        self::assertFalse($items['theme:local']['wporg']);
        self::assertSame('7.1', $items['core:wordpress']['new_version']);
    }

    public function test_items_are_capped_with_core_and_active_kept_first(): void
    {
        $plugins = [];
        for ($i = 0; $i < GOBUILDWP_CLOUD_INVENTORY_MAX_ITEMS + 50; $i++) {
            $plugins[sprintf('p%04d/p.php', $i)] = ['Name' => 'P' . $i, 'Version' => '1.0'];
        }
        WPPilot_Test_State::$plugins = $plugins;
        // The last one listed is active; it must survive the cap.
        $last = sprintf('p%04d/p.php', GOBUILDWP_CLOUD_INVENTORY_MAX_ITEMS + 49);
        WPPilot_Test_State::$options['active_plugins'] = [$last];

        $inventory = gobuildwp_cloud_inventory();

        self::assertCount(GOBUILDWP_CLOUD_INVENTORY_MAX_ITEMS, $inventory['items']);
        self::assertTrue($inventory['truncated']);
        self::assertSame(GOBUILDWP_CLOUD_INVENTORY_MAX_ITEMS + 51, $inventory['total_items']);
        self::assertSame('core', $inventory['items'][0]['type']);
        self::assertSame($last, $inventory['items'][1]['file']);
    }

    public function test_long_strings_are_truncated(): void
    {
        $item = gobuildwp_cloud_inventory_item([
            'type' => 'plugin',
            'slug' => str_repeat('s', 500),
            'file' => str_repeat('f', 500),
            'name' => str_repeat('n', 500),
            'version' => str_repeat('9', 500),
            'active' => true,
            'update_uri' => str_repeat('u', 500),
            'new_version' => str_repeat('8', 500),
        ]);

        self::assertSame(200, strlen($item['slug']));
        self::assertSame(300, strlen($item['file']));
        self::assertSame(200, strlen($item['name']));
        self::assertSame(64, strlen($item['version']));
        self::assertSame(300, strlen($item['update_uri']));
        self::assertSame(64, strlen((string) $item['new_version']));
    }

    public function test_route_is_registered_under_gobuildwp_with_the_linked_token_check(): void
    {
        wppilot_cloud_register_routes();

        $route = $GLOBALS['wppilot_test_rest_routes']['gobuildwp/v1/cloud/inventory'] ?? null;
        self::assertIsArray($route);
        self::assertSame('GET', $route['methods']);
        self::assertSame('gobuildwp_cloud_rest_inventory', $route['callback']);
        self::assertSame('wppilot_cloud_rest_permission', $route['permission_callback']);
    }

    public function test_route_refuses_a_token_while_the_site_is_not_linked(): void
    {
        record_oauth_identity(1, ['mcp'], 'token-7', via: 'token');
        $denied = wppilot_cloud_rest_permission();
        self::assertInstanceOf(WP_Error::class, $denied);
        self::assertSame(403, $denied->get_error_data()['status']);

        $answer = gobuildwp_cloud_rest_inventory();
        self::assertInstanceOf(WP_Error::class, $answer);
        self::assertSame(403, $answer->get_error_data()['status']);
    }

    public function test_route_admits_only_the_linked_token_and_answers_with_the_hash(): void
    {
        $this->link(7);
        WPPilot_Test_State::$plugins = ['a/a.php' => ['Name' => 'A', 'Version' => '1.0']];

        record_oauth_identity(1, ['mcp'], 'token-8', via: 'token');
        $other = wppilot_cloud_rest_permission();
        self::assertInstanceOf(WP_Error::class, $other);
        self::assertSame(403, $other->get_error_data()['status']);

        record_oauth_identity(1, ['mcp'], 'token-7', via: 'token');
        self::assertTrue(wppilot_cloud_rest_permission());
        self::assertTrue(oauth_identity_may_use_route('/gobuildwp/v1/cloud/inventory', 'GET'));

        $answer = gobuildwp_cloud_rest_inventory();
        self::assertInstanceOf(WP_REST_Response::class, $answer);
        self::assertSame(200, $answer->status);
        self::assertSame(gobuildwp_cloud_inventory_hash_of($answer->data['items'], $answer->data), $answer->data['inventory_hash']);
        self::assertSame($answer->data['inventory_hash'], gobuildwp_cloud_inventory_hash(), 'the status answer carries the same hash');
    }
}
