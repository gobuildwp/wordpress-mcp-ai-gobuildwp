<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\AdminLock {
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WPPilot\Kits\AdminLock;
    use WPPilot\Kits\Runtime;
    use WPPilot\Kits\Runtime\Host;
    use WPPilot\Kits\Runtime\Jobs;
    use WPPilot\Kits\Runtime\Ledger;

    final class State
    {
        /** @var array<string, array<string, mixed>> */
        public static array $registered = [];

        public static bool $ajax = false;

        /** @var list<array{ability: string, items: list<array<string, mixed>>}> */
        public static array $recorded = [];

        /** @var array<string, callable> */
        public static array $strategies = [];
    }

    /**
     * The admin-lock kit: which requests it turns away, which it never touches, the self-lockout
     * refusal, confirmation, undo and the wp-config.php escape hatch.
     */
    final class AdminLockTest extends TestCase
    {
        private const ME = '203.0.113.7';

        /** @var array<string, mixed> */
        private array $savedServer = [];

        /** @var array<string, mixed> */
        private array $savedRequest = [];

        public static function setUpBeforeClass(): void
        {
            require_once dirname(__DIR__, 3) . '/doubles/admin.php';
            require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
            $kit = require dirname(__DIR__, 4) . '/includes/kits/admin-lock/bootstrap.php';
            Runtime\host(self::host());
            ($kit['boot'])(Runtime\host());
            foreach ($kit['ability_files'] as $file) {
                require_once $file;
            }
        }

        protected function setUp(): void
        {
            Runtime\host(self::host());
            delete_option(AdminLock\OPTION);
            $this->savedServer = $_SERVER;
            $this->savedRequest = $_REQUEST;
            $_SERVER['REMOTE_ADDR'] = self::ME;
            unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
            $_SERVER['SCRIPT_NAME'] = '/wp-admin/index.php';
            $_REQUEST = [];
            State::$ajax = false;
            State::$recorded = [];
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->savedServer;
            $_REQUEST = $this->savedRequest;
            delete_option(AdminLock\OPTION);
        }

        public function testTheAbilitiesAreRegisteredAsDeclared(): void
        {
            $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/admin-lock/kit.json'), true);
            self::assertSame(['wppilot/admin-lock-status', 'wppilot/set-admin-lock'], array_column($manifest['abilities'], 'name'));
            self::assertTrue(State::$registered['wppilot/admin-lock-status']['meta']['annotations']['readonly']);
            self::assertFalse(State::$registered['wppilot/set-admin-lock']['meta']['annotations']['readonly']);
            self::assertArrayHasKey(AdminLock\STRATEGY, State::$strategies);
        }

        public function testAListWithoutTheSaversAddressIsRefused(): void
        {
            $result = AdminLock\set(['enabled' => true, 'allow' => ['198.51.100.0/24'], 'confirm' => true], $_SERVER);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('kit_admin_lock_self_lockout', $result->get_error_code());
            self::assertStringContainsString(self::ME, $result->get_error_message());
            self::assertFalse(get_option(AdminLock\OPTION));
        }

        public function testSwitchingOnNeedsConfirmAndNamesTheEscapeHatches(): void
        {
            $result = AdminLock\set(['enabled' => true, 'allow' => ['203.0.113.0/24']], $_SERVER);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('kit_confirmation_required', $result->get_error_code());
            self::assertStringContainsString('203.0.113.0/24', $result->get_error_message());
            self::assertStringContainsString(AdminLock\escape_constant(), $result->get_error_message());
            self::assertStringContainsString('wp wppilot-kit admin-lock disable', $result->get_error_message());
            self::assertSame([], State::$recorded);
        }

        public function testAnEmptyListAndBadEntriesAreRefused(): void
        {
            $empty = AdminLock\set(['enabled' => true, 'allow' => [], 'confirm' => true], $_SERVER);
            $bad = AdminLock\set(['enabled' => true, 'allow' => [self::ME, '10.0.0.0/33', 'example.com'], 'confirm' => true], $_SERVER);
            $xff = AdminLock\set(['enabled' => true, 'allow' => [self::ME], 'ip_source' => 'x_forwarded_for', 'confirm' => true], $_SERVER);

            self::assertSame('kit_admin_lock_empty', $empty->get_error_code());
            self::assertSame('kit_admin_lock_bad_entry', $bad->get_error_code());
            self::assertStringContainsString('10.0.0.0/33, example.com', $bad->get_error_message());
            self::assertSame('kit_admin_lock_no_proxies', $xff->get_error_code());
        }

        public function testAnotherAddressIsTurnedAwayFromLoginAndTheDashboard(): void
        {
            $this->lockTo([self::ME, '2001:db8::/32']);

            $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
            self::assertSame(403, $this->haltStatus(static fn() => AdminLock\on_login_init()));
            self::assertSame(403, $this->haltStatus(static fn() => AdminLock\on_admin_init()));

            $_SERVER['REMOTE_ADDR'] = '2001:db8::5';
            self::assertNull($this->haltStatus(static fn() => AdminLock\on_admin_init()), 'an address inside an allowed range');
            $_SERVER['REMOTE_ADDR'] = '::ffff:' . self::ME;
            self::assertNull($this->haltStatus(static fn() => AdminLock\on_login_init()), 'an IPv4-mapped address is the IPv4 address');
        }

        public function testAjaxAdminPostAndVisitorLoginActionsAreNeverTouched(): void
        {
            $this->lockTo([self::ME]);
            $_SERVER['REMOTE_ADDR'] = '198.51.100.9';

            State::$ajax = true;
            self::assertNull($this->haltStatus(static fn() => AdminLock\on_admin_init()), 'admin-ajax.php');
            State::$ajax = false;

            $_SERVER['SCRIPT_NAME'] = '/wp-admin/admin-post.php';
            self::assertNull($this->haltStatus(static fn() => AdminLock\on_admin_init()), 'admin-post.php');

            foreach (AdminLock\OPEN_LOGIN_ACTIONS as $action) {
                $_REQUEST['action'] = $action;
                self::assertNull($this->haltStatus(static fn() => AdminLock\on_login_init()), $action);
            }
            $_REQUEST['action'] = 'lostpassword';
            self::assertSame(403, $this->haltStatus(static fn() => AdminLock\on_login_init()));
        }

        public function testAForgedCloudflareHeaderIsIgnored(): void
        {
            $_SERVER['REMOTE_ADDR'] = '173.245.48.10';
            $_SERVER['HTTP_CF_CONNECTING_IP'] = self::ME;
            $this->lockTo([self::ME], 'cloudflare');

            self::assertNull($this->haltStatus(static fn() => AdminLock\on_login_init()), 'real Cloudflare edge');

            $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
            self::assertSame(403, $this->haltStatus(static fn() => AdminLock\on_login_init()), 'header sent straight to the server');
        }

        public function testSwitchingOffNeedsNoConfirmAndUndoPutsTheLockBack(): void
        {
            $this->lockTo([self::ME]);

            $off = AdminLock\set(['enabled' => false], $_SERVER);
            self::assertIsArray($off);
            self::assertTrue($off['changed']);
            self::assertFalse($off['enforced']);

            $restore = State::$strategies[AdminLock\STRATEGY];
            $back = $restore(['snapshot' => State::$recorded[1]['items'][0]['before']]);
            self::assertTrue($back['verified']);
            self::assertTrue($back['enforced']);

            $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
            AdminLock\set(['enabled' => false], ['REMOTE_ADDR' => self::ME]);
            $refused = $restore(['snapshot' => State::$recorded[2]['items'][0]['before']]);
            self::assertInstanceOf(WP_Error::class, $refused, 'an undo that would lock out the request undoing it');
            self::assertSame('kit_admin_lock_self_lockout', $refused->get_error_code());

            $_SERVER['REMOTE_ADDR'] = self::ME;
            $never = $restore(['snapshot' => State::$recorded[0]['items'][0]['before']]);
            self::assertTrue($never['verified']);
            self::assertFalse(get_option(AdminLock\OPTION));
        }

        public function testStatusReportsTheRequestAddressAndProxyHints(): void
        {
            $this->lockTo([self::ME]);
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.1';

            $status = AdminLock\status();

            self::assertTrue($status['enforced']);
            self::assertSame(self::ME, $status['your_ip']);
            self::assertTrue($status['your_ip_allowed']);
            self::assertSame(['x_forwarded_for'], $status['proxy_headers_seen']);
            self::assertSame('wp wppilot-kit admin-lock disable', $status['escape_hatches']['cli']);
        }

        public function testTheWpConfigConstantSwitchesItOff(): void
        {
            $this->lockTo([self::ME]);
            $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
            self::assertSame(403, $this->haltStatus(static fn() => AdminLock\on_login_init()));

            define(AdminLock\escape_constant(), true);

            self::assertNull($this->haltStatus(static fn() => AdminLock\on_login_init()));
            self::assertTrue(AdminLock\status()['disabled_by_constant']);
            self::assertFalse(AdminLock\status()['enforced']);
        }

        /** @param list<string> $allow */
        private function lockTo(array $allow, string $source = 'remote_addr'): void
        {
            $result = AdminLock\set(['enabled' => true, 'allow' => $allow, 'ip_source' => $source, 'confirm' => true], $_SERVER);
            self::assertIsArray($result);
            self::assertTrue($result['enforced']);
        }

        private function haltStatus(callable $call): ?int
        {
            try {
                $call();
            } catch (\RuntimeException $halt) {
                // The harness's wp_die() double throws, carrying the response status.
                return $halt->status;
            }
            return null;
        }

        private static function host(): Host
        {
            $ledger = new class implements Ledger {
                public function capture_for(string $ability_name, callable $capture): void
                {
                }

                public function record_items(string $ability_name, array $items, ?string $group = null): array
                {
                    State::$recorded[] = ['ability' => $ability_name, 'items' => $items];
                    return ['group' => 'g', 'change_ids' => ['c' . count(State::$recorded)], 'without_before_image' => 0];
                }

                public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
                {
                    State::$strategies[$type] = $restore;
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
            return new class ($ledger) implements Host {
                public function __construct(private Ledger $ledger)
                {
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
                    return true;
                }
            };
        }
    }
}

namespace WPPilot\Kits\AdminLock {
    use WPPilot\Tests\Unit\Kits\AdminLock\State;

    if (!function_exists(__NAMESPACE__ . '\\wp_register_ability')) {
        /** @param array<string, mixed> $args */
        function wp_register_ability(string $name, array $args): mixed
        {
            State::$registered[$name] = $args;
            return \wp_register_ability($name, $args);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_doing_ajax')) {
        function wp_doing_ajax(): bool
        {
            return State::$ajax;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_doing_cron')) {
        function wp_doing_cron(): bool
        {
            return false;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\headers_sent')) {
        function headers_sent(): bool
        {
            return false;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\nocache_headers')) {
        function nocache_headers(): void
        {
        }
    }
}
