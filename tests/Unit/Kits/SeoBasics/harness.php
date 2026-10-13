<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The seo-basics kit on the standalone host, with the real MiniLedger doing the capture,
 * record and rollback, and small doubles of the seven SEO plugins' APIs.
 *
 * Each test runs in its own process: the kit decides which plugins are active from the constants
 * they define, and a constant defined here would otherwise stay defined for every later test in
 * the suite.
 *
 * The vendor doubles mirror only what the kit relies on, taken from the plugins' source:
 * Yoast's set_value() slashing for update_post_meta() and dropping a value equal to its default,
 * TSF's save_meta() rewriting every key over its defaults and deleting the empty ones, and
 * AIOSEO's Post model loading columns back as database strings.
 */

namespace WPPilot\Tests\Unit\Kits\SeoBasics {
    use Kit_Test_Site;
    use PHPUnit\Framework\TestCase;
    use WPPilot\Kits\Runtime;
    use WPPilot\Kits\Runtime\Hosts\StandaloneHost;
    use WPPilot\Kits\Runtime\MiniLedger;
    use WPPilot\Kits\Runtime\PostPartial;

    abstract class SeoBasicsCase extends TestCase
    {
        protected MiniLedger $ledger;

        protected StandaloneHost $host;

        /** @var array<string, mixed> */
        protected array $kit = [];

        /** A value with the escapes WordPress's unslashing would eat: `\"` and a Windows path. */
        protected const SLASHY = 'Say \"hi\" at C:\x';

        /**
         * Define the plugins' constants, then load the kit the way the kit loader does.
         *
         * @param list<string> $constants
         */
        protected function boot(array $constants): void
        {
            require_once dirname(__DIR__, 3) . '/doubles/kit-site.php';
            require_once __DIR__ . '/vendors.php';
            require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
            require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/hosts/standalone.php';
            foreach ($constants as $constant) {
                if (!defined($constant)) {
                    define($constant, '1.0.0');
                }
            }
            Kit_Test_Site::reset();
            delete_option(MiniLedger::OPTION);
            Kit_Test_Site::register_post_types('post', 'page');
            Kit_Test_Site::as_user(1, 'manage_options', 'edit_posts', 'edit_post');
            Kit_Test_Site::insert(['ID' => 10, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Hello']);

            $this->host = new StandaloneHost('kitprobe');
            Runtime\host($this->host);
            $ledger = $this->host->ledger();
            self::assertInstanceOf(MiniLedger::class, $ledger);
            $this->ledger = $ledger;
            // The host registers the runtime's post-partial undo; the standalone test host does not.
            PostPartial\register($this->ledger);

            /** @var mixed $kit */
            $kit = require dirname(__DIR__, 4) . '/includes/kits/seo-basics/bootstrap.php';
            self::assertIsArray($kit);
            $this->kit = $kit;
            if (isset($kit['skip'])) {
                return;
            }
            ($kit['boot'])($this->host);
            foreach ($kit['ability_files'] as $file) {
                require_once $file;
            }
        }

        /**
         * Run an ability as the ledger sees it: capture, execute, record.
         *
         * @param array<string, mixed> $input
         */
        protected function run_ability(string $name, array $input): mixed
        {
            $args = Kit_Test_Site::registration($name);
            self::assertIsArray($args, "{$name} is registered");
            self::assertTrue(($args['permission_callback'])(), "{$name} permits an editor");
            $this->ledger->before($name, $input);
            /** @var mixed $result */
            $result = ($args['execute_callback'])($input);
            $this->ledger->after($name, $input, $result);
            return $result;
        }

        /**
         * Undo the most recent change and return what the ledger reports.
         *
         * @return array<string, mixed>
         */
        protected function undo_last(): array
        {
            $rows = $this->ledger->all();
            self::assertNotSame([], $rows, 'the write was recorded');
            $row = $rows[count($rows) - 1];
            self::assertTrue($row['rollback']['reversible'] ?? false, 'the write is reversible: ' . (string) ($row['rollback']['reason'] ?? ''));
            $result = $this->ledger->rollback((string) $row['id']);
            self::assertIsArray($result, $result instanceof \WP_Error ? $result->get_error_message() : 'rollback result');
            self::assertTrue($result['verified']);
            return $result;
        }

        /** @return array<string, list<string>> */
        protected function raw(int $post_id = 10): array
        {
            return Kit_Test_Site::raw_meta($post_id);
        }
    }
}

namespace WPPilot\Kits\Runtime {
    // MiniLedger drops the options cache before it writes; the doubles keep no cache to drop.
    if (!function_exists(__NAMESPACE__ . '\\wp_cache_delete') && !function_exists('wp_cache_delete')) {
        function wp_cache_delete(int|string $key, string $group = ''): bool
        {
            return true;
        }
    }
}
