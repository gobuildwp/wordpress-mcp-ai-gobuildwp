<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\MaintenanceMode {
    use Kit_Test_Site;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WPPilot\Kits\MaintenanceMode;
    use WPPilot\Kits\Runtime;
    use WPPilot\Kits\Runtime\Host;
    use WPPilot\Kits\Runtime\Jobs;
    use WPPilot\Kits\Runtime\Ledger;

    final class State
    {
        /** @var array<string, array<string, mixed>> */
        public static array $registered = [];

        /** @var list<string> */
        public static array $headers = [];

        public static int $nocache = 0;

        /** @var list<array<string, mixed>> */
        public static array $nodes = [];

        /** @var list<array{ability: string, items: list<array<string, mixed>>}> */
        public static array $recorded = [];

        /** @var array<string, callable> */
        public static array $strategies = [];
    }

    /**
     * The maintenance-mode kit: who is turned away, with what, for how long, and the switch's
     * confirmation, ledger record and undo.
     */
    final class MaintenanceModeTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once dirname(__DIR__, 3) . '/doubles/admin.php';
            if (!class_exists('Kit_Test_Site')) {
                require_once dirname(__DIR__, 3) . '/doubles/kit-site.php';
            }
            require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
            $kit = require dirname(__DIR__, 4) . '/includes/kits/maintenance-mode/bootstrap.php';
            Runtime\host(self::host());
            ($kit['boot'])(Runtime\host());
            foreach ($kit['ability_files'] as $file) {
                require_once $file;
            }
        }

        protected function setUp(): void
        {
            Runtime\host(self::host());
            Kit_Test_Site::reset();
            remove_all_filters(MaintenanceMode\BYPASS_FILTER);
            State::$headers = [];
            State::$nocache = 0;
            State::$nodes = [];
            State::$recorded = [];
        }

        protected function tearDown(): void
        {
            remove_all_filters(MaintenanceMode\BYPASS_FILTER);
            Kit_Test_Site::reset();
        }

        public function testTheAbilitiesAreRegisteredAsDeclared(): void
        {
            $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/maintenance-mode/kit.json'), true);
            self::assertSame(['wppilot/maintenance-mode-status', 'wppilot/set-maintenance-mode'], array_column($manifest['abilities'], 'name'));
            self::assertTrue(State::$registered['wppilot/maintenance-mode-status']['meta']['annotations']['readonly']);
            self::assertFalse(State::$registered['wppilot/set-maintenance-mode']['meta']['annotations']['readonly']);
            self::assertArrayHasKey(MaintenanceMode\STRATEGY, State::$strategies);
        }

        public function testSwitchingOnNeedsConfirmAndSaysWhatItWillDo(): void
        {
            $refused = MaintenanceMode\set(['enabled' => true, 'minutes' => 30]);

            self::assertInstanceOf(WP_Error::class, $refused);
            self::assertSame('kit_confirmation_required', $refused->get_error_code());
            self::assertStringContainsString('503', $refused->get_error_message());
            self::assertStringContainsString('30 minutes', $refused->get_error_message());
            self::assertFalse(get_option(MaintenanceMode\OPTION));
            self::assertSame([], State::$recorded);
        }

        public function testAVisitorGetsA503WithRetryAfterAndTheEscapedMessage(): void
        {
            $on = MaintenanceMode\set(['enabled' => true, 'minutes' => 10, 'message' => "Back <b>soon</b>\nPromise", 'confirm' => true]);
            self::assertIsArray($on);
            self::assertTrue($on['active']);

            try {
                MaintenanceMode\on_template_redirect();
                self::fail('the visitor was served the site');
            } catch (\RuntimeException $halt) {
                // The harness's wp_die() double throws, carrying the kind and response status.
                self::assertSame('die', $halt->kind);
                self::assertSame(503, $halt->status);
                self::assertStringContainsString('Back soon<br />', $halt->getMessage());
                self::assertStringNotContainsString('<b>', $halt->getMessage());
            }
            self::assertSame(1, State::$nocache);
            self::assertCount(1, State::$headers);
            self::assertMatchesRegularExpression('/^Retry-After: (59\d|600)$/', State::$headers[0]);
        }

        public function testAnAdministratorIsLetThroughAndSeesTheAdminBarNotice(): void
        {
            MaintenanceMode\set(['enabled' => true, 'minutes' => 5, 'confirm' => true]);
            Kit_Test_Site::as_user(1, 'manage_options');

            MaintenanceMode\on_template_redirect();
            MaintenanceMode\on_admin_bar_menu(self::adminBar());

            self::assertSame([], State::$headers);
            self::assertCount(1, State::$nodes);
            self::assertSame(MaintenanceMode\ADMIN_BAR_NODE, State::$nodes[0]['id']);
            self::assertStringContainsString('5 min', State::$nodes[0]['meta']['title']);
        }

        public function testTheBypassFilterCanLetSomeoneElseThrough(): void
        {
            MaintenanceMode\set(['enabled' => true, 'confirm' => true]);
            add_filter(MaintenanceMode\BYPASS_FILTER, static fn(): bool => true);

            self::assertNull(MaintenanceMode\decision(time()));
        }

        public function testItSwitchesItselfOffWhenTheTimeRunsOut(): void
        {
            update_option(MaintenanceMode\OPTION, ['enabled' => true, 'message' => '', 'until' => time() - 1, 'started' => time() - 600, 'by' => 1]);

            MaintenanceMode\on_template_redirect();
            MaintenanceMode\on_admin_bar_menu(self::adminBar());

            self::assertSame([], State::$headers);
            self::assertSame([], State::$nodes, 'no notice for a mode that has run out');
            $status = MaintenanceMode\status();
            self::assertFalse($status['active']);
            self::assertTrue($status['expired']);
            self::assertSame(0, $status['seconds_left']);
        }

        public function testMinutesAndMessageAreBounded(): void
        {
            $zero = MaintenanceMode\set(['enabled' => true, 'minutes' => 0, 'confirm' => true]);
            $week = MaintenanceMode\set(['enabled' => true, 'minutes' => MaintenanceMode\MAX_MINUTES + 1, 'confirm' => true]);
            $long = MaintenanceMode\set(['enabled' => true, 'message' => str_repeat('a', MaintenanceMode\MAX_MESSAGE_LENGTH + 1), 'confirm' => true]);
            $missing = MaintenanceMode\set(['minutes' => 5]);

            self::assertSame('kit_maintenance_bad_minutes', $zero->get_error_code());
            self::assertSame('kit_maintenance_bad_minutes', $week->get_error_code());
            self::assertSame('kit_maintenance_message_too_long', $long->get_error_code());
            self::assertSame('kit_maintenance_bad_input', $missing->get_error_code());
        }

        public function testSwitchingOffNeedsNoConfirmAndIsRecorded(): void
        {
            MaintenanceMode\set(['enabled' => true, 'minutes' => 15, 'message' => 'Upgrading', 'confirm' => true]);

            $off = MaintenanceMode\set(['enabled' => false]);
            $again = MaintenanceMode\set(['enabled' => false]);

            self::assertIsArray($off);
            self::assertTrue($off['changed']);
            self::assertFalse($off['active']);
            self::assertFalse($again['changed'], 'already off: nothing written or recorded');
            self::assertCount(2, State::$recorded);
            self::assertSame('wppilot/set-maintenance-mode', State::$recorded[1]['ability']);
            self::assertTrue(State::$recorded[1]['items'][0]['before']['value']['enabled']);
            self::assertNull(MaintenanceMode\decision(time()));
        }

        public function testUndoRestoresThePreviousSettingOrRemovesIt(): void
        {
            MaintenanceMode\set(['enabled' => true, 'minutes' => 20, 'message' => 'First', 'confirm' => true]);
            MaintenanceMode\set(['enabled' => false]);
            $restore = State::$strategies[MaintenanceMode\STRATEGY];

            $back_on = $restore(['snapshot' => State::$recorded[1]['items'][0]['before']]);
            self::assertTrue($back_on['verified']);
            self::assertTrue($back_on['active']);
            self::assertSame('First', MaintenanceMode\read()['message']);

            $never = $restore(['snapshot' => State::$recorded[0]['items'][0]['before']]);
            self::assertTrue($never['verified']);
            self::assertFalse(get_option(MaintenanceMode\OPTION));

            $bad = $restore(['snapshot' => ['type' => MaintenanceMode\STRATEGY]]);
            self::assertInstanceOf(WP_Error::class, $bad);
        }

        public function testTheMessageIsKeptWhenExtending(): void
        {
            MaintenanceMode\set(['enabled' => true, 'minutes' => 5, 'message' => 'Keep me', 'confirm' => true]);
            $extended = MaintenanceMode\set(['enabled' => true, 'minutes' => 60, 'confirm' => true]);

            self::assertSame('Keep me', $extended['message']);
            self::assertGreaterThan(3500, $extended['seconds_left']);
        }

        public function testAScheduledWindowOpensLaterAndClosesMinutesAfter(): void
        {
            $start = time() + 3600;
            $set = MaintenanceMode\set(['enabled' => true, 'minutes' => 30, 'starts_at' => $start, 'confirm' => true]);
            self::assertIsArray($set);
            self::assertFalse($set['active']);
            self::assertTrue($set['scheduled']);
            self::assertSame($start, $set['starts_at']);
            self::assertFalse($set['expired']);
            self::assertSame($start + 1800, MaintenanceMode\read()['until']);
            self::assertSame($start, State::$recorded[0]['items'][0]['input']['starts_at'] ?? null);

            MaintenanceMode\on_template_redirect();
            self::assertSame([], State::$headers, 'visitors still see the site before the window');

            $state = MaintenanceMode\read();
            self::assertTrue(MaintenanceMode\is_active($state, $start + 60));
            self::assertFalse(MaintenanceMode\is_active($state, $start + 1800));
        }

        public function testAStartIsBoundedAndAPastStartOpensNow(): void
        {
            $far = MaintenanceMode\set(['enabled' => true, 'starts_at' => time() + MaintenanceMode\MAX_LEAD_SECONDS + 60, 'confirm' => true]);
            $text = MaintenanceMode\set(['enabled' => true, 'starts_at' => 'tomorrow', 'confirm' => true]);
            self::assertSame('kit_maintenance_bad_start', $far->get_error_code());
            self::assertSame('kit_maintenance_bad_start', $text->get_error_code());

            $past = MaintenanceMode\set(['enabled' => true, 'minutes' => 10, 'starts_at' => time() - 120, 'confirm' => true]);
            self::assertTrue($past['active']);
            self::assertFalse($past['scheduled']);
        }

        private static function adminBar(): object
        {
            return new class {
                /** @param array<string, mixed> $node */
                public function add_node(array $node): void
                {
                    State::$nodes[] = $node;
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

namespace WPPilot\Kits\MaintenanceMode {
    use WPPilot\Tests\Unit\Kits\MaintenanceMode\State;

    if (!function_exists(__NAMESPACE__ . '\\wp_register_ability')) {
        /** @param array<string, mixed> $args */
        function wp_register_ability(string $name, array $args): mixed
        {
            State::$registered[$name] = $args;
            return \wp_register_ability($name, $args);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\headers_sent')) {
        function headers_sent(): bool
        {
            return false;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\header')) {
        function header(string $header): void
        {
            State::$headers[] = $header;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\nocache_headers')) {
        function nocache_headers(): void
        {
            State::$nocache++;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_add_inline_style')) {
        function wp_add_inline_style(string $handle, string $css): bool
        {
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\sanitize_textarea_field')) {
        function sanitize_textarea_field(string $value): string
        {
            return trim(strip_tags($value));
        }
    }
}
