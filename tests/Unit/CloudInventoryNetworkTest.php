<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot_Test_State;

require_once dirname(__DIR__) . '/doubles/admin.php';
require_once dirname(__DIR__) . '/doubles/mcp-surface.php';
require_once dirname(__DIR__) . '/doubles/cloud.php';
require_once dirname(__DIR__) . '/doubles/cloud-network.php';
require_once dirname(__DIR__, 2) . '/includes/capabilities.php';
require_once dirname(__DIR__, 2) . '/includes/clients.php';
require_once dirname(__DIR__, 2) . '/includes/rate-limit.php';
require_once dirname(__DIR__, 2) . '/includes/cloud/bootstrap.php';

/**
 * The inventory's environment block (staging detection) and multisite network block,
 * and how both feed the inventory hash.
 */
final class CloudInventoryNetworkTest extends TestCase
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
            WPPilot_Test_State::$options['active_plugins'],
            WPPilot_Test_State::$options['active_sitewide_plugins'],
        );
        WPPilot_Test_State::$plugins = [];
        WPPilot_Test_State::$multisite = false;
        $GLOBALS['wppilot_test_option_autoload'] = [];
        $GLOBALS['wppilot_test_sites'] = [];
        $GLOBALS['wppilot_test_site_option_calls'] = [];
        $GLOBALS['wppilot_test_get_sites_calls'] = [];
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        WPPilot_Test_State::$plugins = $this->savedPlugins;
        WPPilot_Test_State::$multisite = false;
        unset(
            $GLOBALS['wppilot_test_sites'],
            $GLOBALS['wppilot_test_site_option_calls'],
            $GLOBALS['wppilot_test_get_sites_calls'],
            $GLOBALS['wppilot_test_environment_type'],
            $GLOBALS['wppilot_test_stylesheet'],
            $GLOBALS['wppilot_test_template'],
        );
        parent::tearDown();
    }

    /** @param array<string, mixed> $options */
    private static function site(int $id, string $domain, array $options = [], string $path = '/', bool $deleted = false): void
    {
        $GLOBALS['wppilot_test_sites'][$id] = ['domain' => $domain, 'path' => $path, 'deleted' => $deleted ? 1 : 0, 'options' => $options];
    }

    // --- Environment -------------------------------------------------------------

    public function test_environment_without_host_constants_has_null_host(): void
    {
        $GLOBALS['wppilot_test_environment_type'] = 'staging';
        WPPilot_Test_State::$plugins = [
            'wp-staging/wp-staging.php' => ['Name' => 'WP Staging', 'Version' => '3.0'],
            'akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.3'],
            'instawp-connect/instawp-connect.php' => ['Name' => 'InstaWP Connect', 'Version' => '0.1'],
        ];
        // Installed, not active: still listed.
        WPPilot_Test_State::$options['active_plugins'] = ['akismet/akismet.php'];

        $inventory = gobuildwp_cloud_inventory();

        self::assertArrayNotHasKey('network', $inventory, 'no network block on a single site');
        self::assertSame([
            'type' => 'staging',
            'staging_plugins' => ['wp-staging', 'instawp-connect'],
            'host' => null,
            'host_env' => null,
        ], $inventory['environment']);
    }

    public function test_environment_type_defaults_to_production(): void
    {
        self::assertSame('production', gobuildwp_cloud_inventory()['environment']['type']);
    }

    public function test_host_comes_from_the_presence_of_known_constants_only(): void
    {
        $asked = [];
        $read = [];
        $defined = static function (string $name) use (&$asked): bool {
            $asked[] = $name;

            return in_array($name, ['KINSTA_CACHE_ZONE', 'WPCOMSH_VERSION'], true);
        };
        $constant = static function (string $name) use (&$read): mixed {
            $read[] = $name;

            return 'secret';
        };

        $env = gobuildwp_cloud_inventory_environment_of('production', [], $defined, $constant);

        self::assertSame('kinsta', $env['host'], 'first match in the listed order');
        self::assertNull($env['host_env']);
        self::assertSame([], $read, 'no constant value is read unless PANTHEON_ENVIRONMENT is defined');
        foreach ($asked as $name) {
            self::assertContains($name, ['IS_WPE', 'KINSTA_CACHE_ZONE', 'FLYWHEEL_PLUGIN_DIR', 'WPCOMSH_VERSION', 'PANTHEON_ENVIRONMENT']);
        }

        foreach (['IS_WPE' => 'wpengine', 'FLYWHEEL_PLUGIN_DIR' => 'flywheel', 'WPCOMSH_VERSION' => 'wpcom'] as $const => $host) {
            $only = static fn(string $name): bool => $name === $const;
            self::assertSame($host, gobuildwp_cloud_inventory_environment_of('production', [], $only, $constant)['host']);
        }
    }

    public function test_pantheon_environment_value_is_read_and_validated(): void
    {
        $pantheon = static fn(string $name): bool => $name === 'PANTHEON_ENVIRONMENT';
        $value = static fn(mixed $v): \Closure => static function (string $name) use ($v): mixed {
            self::assertSame('PANTHEON_ENVIRONMENT', $name, 'the only constant whose value is read');

            return $v;
        };

        $env = gobuildwp_cloud_inventory_environment_of('production', [], $pantheon, $value('live'));
        self::assertNull($env['host'], 'host_env does not imply a host label');
        self::assertSame('live', $env['host_env']);
        self::assertSame('pr-12_x', gobuildwp_cloud_inventory_environment_of('production', [], $pantheon, $value('pr-12_x'))['host_env']);
        self::assertNull(gobuildwp_cloud_inventory_environment_of('production', [], $pantheon, $value(str_repeat('a', 33)))['host_env'], 'over 32 chars');
        self::assertNull(gobuildwp_cloud_inventory_environment_of('production', [], $pantheon, $value('Live'))['host_env'], 'upper case');
        self::assertNull(gobuildwp_cloud_inventory_environment_of('production', [], $pantheon, $value('a b'))['host_env']);
        self::assertNull(gobuildwp_cloud_inventory_environment_of('production', [], $pantheon, $value(''))['host_env']);
        self::assertNull(gobuildwp_cloud_inventory_environment_of('production', [], $pantheon, $value(7))['host_env'], 'not a string');
    }

    public function test_staging_plugins_are_matched_by_slug(): void
    {
        $none = static fn(string $name): bool => false;
        $never = static fn(string $name): mixed => null;

        $env = gobuildwp_cloud_inventory_environment_of('local', ['wp-stagecoach', 'akismet', 'wp-staging-pro', 'wp-staging-extra'], $none, $never);

        self::assertSame('local', $env['type']);
        self::assertSame(['wp-staging-pro', 'wp-stagecoach'], $env['staging_plugins']);
    }

    // --- Network -----------------------------------------------------------------

    public function test_network_block_lists_subsites_without_switching(): void
    {
        WPPilot_Test_State::$multisite = true;
        self::site(1, 'Example.com', ['active_plugins' => ['akismet/akismet.php', 'hello.php'], 'stylesheet' => 'child', 'template' => 'parent']);
        self::site(2, 'shop.example.com', ['active_plugins' => ['woocommerce/woocommerce.php', 'akismet/akismet.php', 'akismet/akismet.php'], 'stylesheet' => 'storefront', 'template' => 'storefront'], path: '/shop/');
        self::site(5, 'old.example.com', [], deleted: true);

        $inventory = gobuildwp_cloud_inventory();

        self::assertTrue($inventory['multisite']);
        self::assertSame(3, $inventory['network']['sites_total']);
        self::assertFalse($inventory['network']['truncated']);
        self::assertSame([
            'blog_id' => 1,
            'url_host' => 'example.com',
            'path' => '/',
            'active_plugins' => ['akismet', 'hello'],
            'stylesheet' => 'child',
            'template' => 'parent',
            'deleted' => false,
        ], $inventory['network']['sites'][0]);
        self::assertSame(['akismet', 'woocommerce'], $inventory['network']['sites'][1]['active_plugins'], 'slugs, deduplicated and sorted');
        self::assertSame('/shop/', $inventory['network']['sites'][1]['path']);
        self::assertSame(['blog_id' => 5, 'url_host' => 'old.example.com', 'path' => '/', 'active_plugins' => [], 'stylesheet' => '', 'template' => '', 'deleted' => true], $inventory['network']['sites'][2]);

        self::assertContains(['number' => 200, 'fields' => 'ids'], $GLOBALS['wppilot_test_get_sites_calls']);
        self::assertContains(['count' => true], $GLOBALS['wppilot_test_get_sites_calls']);
    }

    public function test_network_block_is_capped_at_200_subsites(): void
    {
        WPPilot_Test_State::$multisite = true;
        for ($id = 1; $id <= 230; $id++) {
            self::site($id, "s{$id}.example.com", ['active_plugins' => ['a/a.php']]);
        }

        $network = gobuildwp_cloud_inventory()['network'];

        self::assertCount(GOBUILDWP_CLOUD_INVENTORY_MAX_SITES, $network['sites']);
        self::assertSame(200, GOBUILDWP_CLOUD_INVENTORY_MAX_SITES);
        self::assertSame(230, $network['sites_total']);
        self::assertTrue($network['truncated']);
        self::assertSame(200, $network['sites'][199]['blog_id']);
    }

    // --- Hash --------------------------------------------------------------------

    public function test_hash_changes_when_a_subsite_activates_a_plugin(): void
    {
        WPPilot_Test_State::$multisite = true;
        self::site(1, 'example.com', ['active_plugins' => [], 'stylesheet' => 't']);
        self::site(2, 'b.example.com', ['active_plugins' => ['a/a.php'], 'stylesheet' => 't']);
        $before = gobuildwp_cloud_inventory_hash(now: 1_000);

        // A subsite admin activates a plugin; the activated_plugin hook forgets the network-wide cache.
        $GLOBALS['wppilot_test_sites'][1]['options']['active_plugins'] = ['b/b.php'];
        gobuildwp_cloud_inventory_forget();

        self::assertNotSame($before, gobuildwp_cloud_inventory_hash(now: 1_001));
    }

    public function test_hash_changes_with_subsite_theme_and_environment_type(): void
    {
        $inventory = [
            'environment' => ['type' => 'production', 'staging_plugins' => [], 'host' => null, 'host_env' => null],
            'network' => ['sites_total' => 1, 'truncated' => false, 'sites' => [
                ['blog_id' => 1, 'url_host' => 'example.com', 'path' => '/', 'active_plugins' => ['a'], 'stylesheet' => 't', 'template' => 't', 'deleted' => false],
            ]],
        ];
        $base = gobuildwp_cloud_inventory_hash_of([], $inventory);

        $theme = $inventory;
        $theme['network']['sites'][0]['stylesheet'] = 'u';
        self::assertNotSame($base, gobuildwp_cloud_inventory_hash_of([], $theme));

        $staging = $inventory;
        $staging['environment']['type'] = 'staging';
        self::assertNotSame($base, gobuildwp_cloud_inventory_hash_of([], $staging));

        self::assertNotSame($base, gobuildwp_cloud_inventory_hash_of([]), 'items alone hash differently');
    }

    public function test_network_hash_does_not_depend_on_order(): void
    {
        $site = static fn(int $id, array $plugins): array => ['blog_id' => $id, 'url_host' => "s{$id}.example.com", 'path' => '/', 'active_plugins' => $plugins, 'stylesheet' => 't', 'template' => 't', 'deleted' => false];
        $env = ['type' => 'production', 'staging_plugins' => [], 'host' => null, 'host_env' => null];

        $a = gobuildwp_cloud_inventory_hash_of([], ['environment' => $env, 'network' => ['sites_total' => 2, 'truncated' => false, 'sites' => [$site(1, ['a', 'b']), $site(2, ['c'])]]]);
        $b = gobuildwp_cloud_inventory_hash_of([], ['environment' => $env, 'network' => ['sites_total' => 2, 'truncated' => false, 'sites' => [$site(2, ['c']), $site(1, ['b', 'a'])]]]);

        self::assertSame($a, $b);
    }

    public function test_multisite_hash_cache_is_a_site_option(): void
    {
        WPPilot_Test_State::$multisite = true;
        self::site(1, 'example.com');

        $hash = gobuildwp_cloud_inventory_hash(now: 1_000);
        self::assertContains(['update', GOBUILDWP_CLOUD_INVENTORY_HASH_OPTION], $GLOBALS['wppilot_test_site_option_calls']);
        self::assertSame(['hash' => $hash, 'at' => 1_000], WPPilot_Test_State::$options[GOBUILDWP_CLOUD_INVENTORY_HASH_OPTION]);
        self::assertSame($hash, gobuildwp_cloud_inventory_hash(now: 1_100), 'cached');

        gobuildwp_cloud_inventory_forget();
        self::assertContains(['delete', GOBUILDWP_CLOUD_INVENTORY_HASH_OPTION], $GLOBALS['wppilot_test_site_option_calls']);
        self::assertArrayNotHasKey(GOBUILDWP_CLOUD_INVENTORY_HASH_OPTION, WPPilot_Test_State::$options);
    }

    public function test_subsite_lifecycle_hooks_forget_the_hash(): void
    {
        foreach (['wp_initialize_site', 'wp_delete_site', 'activate_blog', 'deactivate_blog', 'activated_plugin', 'update_option_active_plugins'] as $hook) {
            self::assertContains($hook, GOBUILDWP_CLOUD_INVENTORY_HOOKS);
        }
    }
}
