<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection {
    // Transients live in the kit's namespace so no global double is needed.
    if (!function_exists(__NAMESPACE__ . '\get_transient')) {
        function get_transient(string $key): mixed
        {
            return $GLOBALS['gobuildwp_sp_test_transients'][$key] ?? false;
        }

        function set_transient(string $key, mixed $value, int $expiration = 0): bool
        {
            $GLOBALS['gobuildwp_sp_test_transients'][$key] = $value;
            return true;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\get_user_meta')) {
        function get_user_meta(int $user_id, string $key = '', bool $single = false): mixed
        {
            return $GLOBALS['gobuildwp_sp_test_user_meta'][$user_id][$key] ?? ($single ? '' : []);
        }
    }
}

namespace GoBuildWP\Tests\Unit\Kits\SecurityProtection {

    require_once dirname(__DIR__, 4) . '/includes/kits/security-protection/src/load.php';

    use GoBuildWP\Kits\SecurityProtection as SP;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WPPilot_Test_State;

    /**
     * Website protection: client address and CIDR matching, firewall rules, settings validation,
     * blocks and lockouts, the guard's decision for a request, events and counters.
     */
    final class ProtectionTest extends TestCase
    {
        protected function setUp(): void
        {
            WPPilot_Test_State::$options = [];
            $GLOBALS['gobuildwp_sp_test_transients'] = [];
            $GLOBALS['gobuildwp_sp_test_user_meta'] = [];
            $this->server = $_SERVER;
        }

        /** @var array<string, mixed> */
        private array $server = [];

        protected function tearDown(): void
        {
            $_SERVER = $this->server;
            remove_all_filters('gobuildwp_protection_trusted_proxies');
            remove_all_filters('gobuildwp_protection_cloudflare_ranges');
            WPPilot_Test_State::$current_user_id = 0;
        }

        // ------------------------------------------------------------ addresses

        public function test_client_ip_uses_remote_addr_unless_told_otherwise(): void
        {
            $server = ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8'];
            self::assertSame('203.0.113.5', SP\client_ip($server, 'remote_addr'));
            // Not a Cloudflare address, so its CF-Connecting-IP header is forged.
            self::assertSame('203.0.113.5', SP\client_ip($server, 'cloudflare'));
            self::assertSame('203.0.113.5', SP\client_ip($server, 'x_forwarded_for'));
        }

        public function test_cloudflare_header_is_believed_only_from_cloudflare(): void
        {
            self::assertSame('5.6.7.8', SP\client_ip(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8'], 'cloudflare'));
            self::assertSame('5.6.7.8', SP\client_ip(['REMOTE_ADDR' => '2606:4700::1', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8'], 'cloudflare'));
            self::assertSame('172.70.1.2', SP\client_ip(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => 'junk'], 'cloudflare'));

            add_filter('gobuildwp_protection_cloudflare_ranges', static fn(): array => ['203.0.113.0/24']);
            self::assertSame('5.6.7.8', SP\client_ip(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8'], 'cloudflare'));
            self::assertSame('172.70.1.2', SP\client_ip(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8'], 'cloudflare'));
        }

        public function test_forwarded_for_takes_rightmost_address_from_a_trusted_proxy(): void
        {
            $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7, 10.0.0.2, junk'];
            // No trusted proxy named: the header is ignored.
            self::assertSame('10.0.0.1', SP\client_ip($server, 'x_forwarded_for'));

            add_filter('gobuildwp_protection_trusted_proxies', static fn(): array => ['10.0.0.0/24', 'nonsense']);
            self::assertSame(['10.0.0.0/24'], SP\trusted_proxies());
            // 10.0.0.2 is another hop of the trusted proxy, so the client is the address before it.
            self::assertSame('198.51.100.7', SP\client_ip($server, 'x_forwarded_for'));
            self::assertSame('10.0.0.1', SP\client_ip(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'nope'], 'x_forwarded_for'));
            // A connection from outside the proxy list can't choose its own address.
            self::assertSame('192.0.2.9', SP\client_ip(['REMOTE_ADDR' => '192.0.2.9', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6'], 'x_forwarded_for'));
        }

        public function test_ipv4_mapped_addresses_are_unmapped(): void
        {
            self::assertSame('192.0.2.9', SP\client_ip(['REMOTE_ADDR' => '::ffff:192.0.2.9'], 'remote_addr'));
        }

        public function test_cidr_matching(): void
        {
            self::assertTrue(SP\ip_matches('192.0.2.77', '192.0.2.0/24'));
            self::assertFalse(SP\ip_matches('192.0.3.1', '192.0.2.0/24'));
            self::assertTrue(SP\ip_matches('192.0.2.1', '192.0.2.1'));
            self::assertTrue(SP\ip_matches('2001:db8::1', '2001:db8::/32'));
            self::assertFalse(SP\ip_matches('2001:db9::1', '2001:db8::/32'));
            self::assertFalse(SP\ip_matches('192.0.2.1', '2001:db8::/32'));
            self::assertTrue(SP\ip_in_list('10.1.2.3', ['192.0.2.1', '10.0.0.0/8']));
            self::assertSame('', SP\normalize_cidr('300.1.1.1'));
            self::assertSame('', SP\normalize_cidr('10.0.0.0/40'));
        }

        // ------------------------------------------------------------ firewall rules

        /** @return array<string, array{0: array<string, mixed>, 1: string|null}> */
        public static function requests(): array
        {
            return [
                'traversal' => [['REQUEST_URI' => '/?file=../../../../etc/passwd'], 'traversal'],
                'sqli' => [['REQUEST_URI' => '/?id=1 UNION SELECT user_pass FROM wp_users'], 'sqli'],
                'xss' => [['REQUEST_URI' => '/?q=<script>alert(1)</script>'], 'xss'],
                'php wrapper' => [['REQUEST_URI' => '/?page=php://filter/convert.base64-encode/resource=index'], 'php_wrapper'],
                'sensitive file' => [['REQUEST_URI' => '/wp-config.php.bak'], null],
                'scanner ua' => [['REQUEST_URI' => '/', 'HTTP_USER_AGENT' => 'sqlmap/1.7'], 'scanner'],
                'clean' => [['REQUEST_URI' => '/shop/?s=chocolate+cake&page=2', 'HTTP_USER_AGENT' => 'Mozilla/5.0'], 'none'],
            ];
        }

        /** @param array<string, mixed> $server */
        #[DataProvider('requests')]
        public function test_firewall_rules(array $server, ?string $expected): void
        {
            $uri = (string) $server['REQUEST_URI'];
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $get);
            $hit = SP\firewall_match(SP\request_parts($server, $get, [], []));
            if ($expected === 'none') {
                self::assertNull($hit);
            } elseif ($expected === null) {
                self::assertNotNull($hit);
            } else {
                self::assertSame($expected, $hit['id'] ?? null);
            }
        }

        public function test_php_upload_is_caught(): void
        {
            $files = ['f' => ['name' => 'shell.php', 'type' => 'image/png', 'tmp_name' => '', 'error' => 0, 'size' => 1]];
            $hit = SP\firewall_match(SP\request_parts(['REQUEST_URI' => '/upload'], [], [], $files));
            self::assertSame('php_upload', $hit['id'] ?? null);
        }

        public function test_skip_list_skips_xss(): void
        {
            $parts = SP\request_parts(['REQUEST_URI' => '/'], [], ['content' => '<script>x()</script>'], []);
            self::assertNotNull(SP\firewall_match($parts));
            self::assertNull(SP\firewall_match($parts, ['xss']));
        }

        // ------------------------------------------------------------ settings

        public function test_defaults_are_safe_but_not_blocking(): void
        {
            $s = SP\settings();
            self::assertSame('log', $s['firewall']);
            self::assertTrue($s['login']['enabled']);
            self::assertFalse($s['login']['generic_errors']);
            self::assertSame('on', $s['xmlrpc']);
            self::assertTrue($s['block_user_enumeration']);
            self::assertFalse($s['flood']['enabled']);
            self::assertSame([], $s['allowlist']);
        }

        public function test_stored_settings_from_before_the_safe_defaults_still_win(): void
        {
            WPPilot_Test_State::$options[SP\OPT_SETTINGS] = ['firewall' => 'off', 'login' => ['enabled' => false]];
            $s = SP\settings();
            self::assertSame('off', $s['firewall']);
            self::assertFalse($s['login']['enabled']);
            self::assertTrue($s['block_user_enumeration']);
        }

        /** Every part off: what the kit looked like before 1.1.0, for tests of single parts. */
        private static function all_off(): array
        {
            $s = SP\sanitize_settings(['firewall' => 'off', 'login' => ['enabled' => false], 'block_user_enumeration' => false], SP\default_settings());
            self::assertIsArray($s);
            return $s;
        }

        public function test_configure_merges_and_returns_previous(): void
        {
            $r = SP\configure(['firewall' => 'block', 'login' => ['enabled' => true, 'max_failures' => 4]]);
            self::assertIsArray($r);
            self::assertSame('log', $r['previous']['firewall']);
            self::assertSame('block', $r['settings']['firewall']);
            self::assertTrue($r['settings']['login']['enabled']);
            self::assertSame(4, $r['settings']['login']['max_failures']);
            self::assertSame(15, $r['settings']['login']['window_minutes']);
            self::assertSame('block', SP\settings()['firewall']);
            self::assertSame('settings', SP\events(10)[0]['type']);
        }

        public function test_configure_rejects_bad_values(): void
        {
            foreach ([
                ['firewall' => 'maybe'],
                ['login' => ['max_failures' => 1]],
                ['ip_source' => 'header'],
                ['allowlist' => ['not-an-ip']],
                ['unknown' => true],
            ] as $bad) {
                self::assertInstanceOf(WP_Error::class, SP\configure($bad), json_encode($bad));
            }
            self::assertSame('log', SP\settings()['firewall']);
        }

        public function test_allowlist_is_normalised(): void
        {
            $r = SP\configure(['allowlist' => [' 192.0.2.1 ', '10.0.0.0/8', '10.0.0.0/8']]);
            self::assertSame(['192.0.2.1', '10.0.0.0/8'], $r['settings']['allowlist']);
        }

        public function test_trusted_proxies_setting_feeds_forwarded_for(): void
        {
            $server = ['REMOTE_ADDR' => '10.1.0.5', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7'];
            self::assertSame('10.1.0.5', SP\client_ip($server, 'x_forwarded_for'));

            $r = SP\configure(['ip_source' => 'x_forwarded_for', 'trusted_proxies' => [' 10.1.0.0/16 ', '10.1.0.0/16']]);
            self::assertIsArray($r);
            self::assertSame(['10.1.0.0/16'], $r['settings']['trusted_proxies']);
            self::assertSame(['10.1.0.0/16'], SP\trusted_proxies());
            self::assertSame('198.51.100.7', SP\client_ip($server, 'x_forwarded_for'));

            $status = SP\status($server);
            self::assertSame('198.51.100.7', $status['client_ip']);
            self::assertSame(['10.1.0.0/16'], $status['trusted_proxies']);
            self::assertFalse($status['protection_disabled']);
        }

        public function test_trusted_proxies_refuses_bad_or_huge_ranges(): void
        {
            foreach ([['junk'], ['0.0.0.0/0'], ['10.0.0.0/7'], ['2001::/31'], 'not-a-list', array_fill(0, 21, '10.0.0.1')] as $bad) {
                self::assertInstanceOf(WP_Error::class, SP\configure(['trusted_proxies' => $bad]), json_encode($bad));
            }
            self::assertSame([], SP\settings()['trusted_proxies']);
            self::assertIsArray(SP\configure(['trusted_proxies' => ['10.0.0.0/8', '2001:db8::/32', '::1']]));
        }

        // ------------------------------------------------------------ blocks and lockouts

        public function test_blocks_wider_than_a_16_or_48_are_refused(): void
        {
            foreach (['10.0.0.0/8', '198.51.0.0/15', '2001:db8::/32', '2001:db8::/47'] as $wide) {
                $r = SP\add_block($wide, 0, '', 'manual');
                self::assertInstanceOf(WP_Error::class, $r, $wide);
                self::assertSame('gobuildwp_protection_invalid', $r->get_error_code());
            }
            self::assertIsArray(SP\add_block('198.51.0.0/16', 0, '', 'manual'));
            self::assertIsArray(SP\add_block('2001:db8:1::/48', 0, '', 'manual'));
            self::assertCount(2, SP\block_list());
        }

        public function test_a_manual_block_cannot_cover_the_callers_own_address(): void
        {
            $_SERVER['REMOTE_ADDR'] = '203.0.113.40';
            $r = SP\add_block('203.0.113.0/24', 0, '', 'manual');
            self::assertInstanceOf(WP_Error::class, $r);
            self::assertSame('gobuildwp_protection_self_block', $r->get_error_code());

            // The address the signed-in user logged in from counts too, even on another request.
            WPPilot_Test_State::$current_user_id = 7;
            $GLOBALS['gobuildwp_sp_test_user_meta'][7]['session_tokens'] = ['abc' => ['ip' => '198.51.100.23', 'login' => 1]];
            $r = SP\add_block('198.51.100.23', 0, '', 'manual');
            self::assertInstanceOf(WP_Error::class, $r);
            self::assertSame('gobuildwp_protection_self_block', $r->get_error_code());

            self::assertIsArray(SP\add_block('198.51.101.0/24', 0, '', 'manual'));
            // Automatic blocks name the attacker's address, never the caller's, so they aren't checked.
            self::assertIsArray(SP\add_block('203.0.113.40', 30, '', 'login_lockout'));
        }

        #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
        #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
        public function test_disable_protection_constant_unhooks_the_guard(): void
        {
            SP\configure(['xmlrpc' => 'off']);
            SP\install_hooks();
            self::assertArrayHasKey('xmlrpc_enabled', $GLOBALS['wp_filter'] ?? []);

            $GLOBALS['wp_filter'] = [];
            define('GOBUILDWP_DISABLE_PROTECTION', true);
            SP\install_hooks();
            self::assertSame([], $GLOBALS['wp_filter']);
        }

        public function test_block_ip_runs_without_confirm(): void
        {
            $before = count(WPPilot_Test_State::$registrations);
            require dirname(__DIR__, 4) . '/includes/kits/security-protection/src/abilities/protection-block-ip.php';
            $args = WPPilot_Test_State::$registrations[$before]['args'];
            WPPilot_Test_State::$registrations = array_slice(WPPilot_Test_State::$registrations, 0, $before);
            WPPilot_Test_State::$registered_abilities = array_slice(WPPilot_Test_State::$registered_abilities, 0, $before);

            // GoBuildWP Cloud never sends confirm=true, so a confirm step would refuse every block.
            self::assertFalse($args['meta']['annotations']['destructive']);
            self::assertArrayNotHasKey('confirm', $args['input_schema']['properties']);
            $ability = new \WP_Ability('gobuildwp/protection-block-ip', $args['meta'], $args['input_schema'], $args['category']);
            self::assertFalse(wppilot_ability_requires_confirmation($ability));

            $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/security-protection/kit.json'), true);
            $entry = array_values(array_filter($manifest['abilities'], static fn(array $a): bool => $a['name'] === 'gobuildwp/protection-block-ip'))[0];
            self::assertFalse($entry['destructive']);
        }

        public function test_block_expire_and_unblock(): void
        {
            $now = 1_800_000_000;
            $b = SP\add_block('198.51.100.0/24', 10, 'abuse', 'manual', $now);
            self::assertIsArray($b);
            self::assertSame($now + 600, $b['expires_at']);
            self::assertNotNull(SP\block_for('198.51.100.9', SP\blocks($now + 1), false));
            self::assertSame([], SP\blocks($now + 601));
            self::assertTrue(SP\remove_block('198.51.100.0/24'));
            self::assertFalse(SP\remove_block('198.51.100.0/24'));
        }

        public function test_allowlisted_address_cannot_be_blocked(): void
        {
            SP\configure(['allowlist' => ['192.0.2.0/24']]);
            $r = SP\add_block('192.0.2.5', 0, '', 'manual');
            self::assertInstanceOf(WP_Error::class, $r);
            self::assertSame('gobuildwp_protection_allowlisted', $r->get_error_code());
            self::assertInstanceOf(WP_Error::class, SP\add_block('nonsense', 0, '', 'manual'));
        }

        public function test_lockout_only_covers_login(): void
        {
            SP\add_block('203.0.113.9', 30, '', 'login_lockout');
            $blocks = SP\blocks();
            self::assertNull(SP\block_for('203.0.113.9', $blocks, false));
            self::assertNotNull(SP\block_for('203.0.113.9', $blocks, true));
        }

        public function test_failures_cross_limit_inside_window_only(): void
        {
            $login = ['max_failures' => 3, 'window_minutes' => 15];
            $t = 1_800_000_000;
            self::assertFalse(SP\note_failure('192.0.2.1', $login, $t));
            self::assertFalse(SP\note_failure('192.0.2.1', $login, $t + 10));
            // Window passed: the count starts again.
            self::assertFalse(SP\note_failure('192.0.2.1', $login, $t + 901));
            self::assertFalse(SP\note_failure('192.0.2.1', $login, $t + 902));
            self::assertTrue(SP\note_failure('192.0.2.1', $login, $t + 903));
            SP\note_failure('192.0.2.2', $login, $t);
            SP\clear_failures('192.0.2.2');
            self::assertArrayNotHasKey('192.0.2.2', get_option(SP\OPT_FAILURES));
        }

        public function test_hits_count_in_window(): void
        {
            $t = 1_800_000_000;
            self::assertSame(1, SP\note_hit('404', '192.0.2.1', 60, $t));
            self::assertSame(2, SP\note_hit('404', '192.0.2.1', 60, $t + 5));
            self::assertSame(1, SP\note_hit('404', '192.0.2.1', 60, $t + 61));
            self::assertSame(1, SP\note_hit('404', '192.0.2.2', 60, $t + 61));
        }

        // ------------------------------------------------------------ decisions

        /** @param array<string, mixed> $over */
        private static function ctx(array $over = [], array $server = ['REQUEST_URI' => '/'], array $get = []): array
        {
            return $over + [
                'ip' => '198.51.100.20',
                'path' => '/',
                'is_login' => false,
                'is_xmlrpc' => false,
                'is_rest' => false,
                'is_admin_area' => false,
                'has_auth_header' => false,
                'author_query' => false,
                'signed_in' => false,
                'admin' => false,
                'parts' => SP\request_parts($server, $get, [], []),
            ];
        }

        /** @param array<string, mixed> $change */
        private static function with(array $change): array
        {
            $s = SP\sanitize_settings($change, self::all_off());
            self::assertIsArray($s);
            return $s;
        }

        public function test_nothing_on_lets_everything_through(): void
        {
            $attack = self::ctx([], ['REQUEST_URI' => '/'], ['id' => '1 UNION SELECT 1']);
            self::assertNull(SP\decide($attack, self::all_off(), []));
        }

        public function test_defaults_log_attacks_and_refuse_enumeration(): void
        {
            $attack = self::ctx([], ['REQUEST_URI' => '/'], ['id' => '1 UNION SELECT 1']);
            self::assertSame('log', SP\decide($attack, SP\default_settings(), [])['action'] ?? null);
            self::assertSame('author_query', SP\decide(self::ctx(['author_query' => true]), SP\default_settings(), [])['rule'] ?? null);
        }

        public function test_firewall_block_and_log_modes(): void
        {
            $attack = self::ctx([], ['REQUEST_URI' => '/'], ['id' => '1 UNION SELECT 1']);
            self::assertSame('block', SP\decide($attack, self::with(['firewall' => 'block']), [])['action'] ?? null);
            self::assertSame('log', SP\decide($attack, self::with(['firewall' => 'log']), [])['action'] ?? null);
        }

        public function test_admin_and_allowlist_are_never_stopped(): void
        {
            $s = self::with(['firewall' => 'block', 'allowlist' => ['198.51.100.0/24']]);
            $attack = self::ctx([], ['REQUEST_URI' => '/'], ['id' => '1 UNION SELECT 1']);
            self::assertNull(SP\decide($attack, $s, []));
            self::assertNull(SP\decide(self::ctx(['ip' => '203.0.113.1', 'admin' => true] + $attack), self::with(['firewall' => 'block']), []));
        }

        public function test_rest_call_with_credentials_is_deferred(): void
        {
            $ctx = self::ctx(['is_rest' => true, 'has_auth_header' => true], ['REQUEST_URI' => '/wp-json/x'], ['id' => '1 UNION SELECT 1']);
            self::assertSame('defer', SP\decide($ctx, self::with(['firewall' => 'block']), [])['action'] ?? null);
            $blocks = ['198.51.100.20' => ['ip' => '198.51.100.20', 'reason' => '', 'source' => 'manual', 'created_at' => 1, 'expires_at' => null]];
            self::assertSame('defer', SP\decide(self::ctx(['is_rest' => true, 'has_auth_header' => true]), self::all_off(), $blocks)['action'] ?? null);
        }

        public function test_blocked_address_is_stopped_everywhere(): void
        {
            $blocks = ['198.51.100.20' => ['ip' => '198.51.100.20', 'reason' => '', 'source' => 'manual', 'created_at' => 1, 'expires_at' => null]];
            $d = SP\decide(self::ctx(), self::all_off(), $blocks);
            self::assertSame('block', $d['action'] ?? null);
            self::assertSame('manual', $d['rule'] ?? null);
        }

        public function test_xmlrpc_off_and_enumeration(): void
        {
            self::assertSame('xmlrpc_off', SP\decide(self::ctx(['is_xmlrpc' => true]), self::with(['xmlrpc' => 'off']), [])['rule'] ?? null);
            $s = self::with(['block_user_enumeration' => true]);
            self::assertSame('enumeration', SP\decide(self::ctx(['author_query' => true]), $s, [])['type'] ?? null);
            self::assertNull(SP\decide(self::ctx(['author_query' => true, 'signed_in' => true]), $s, []));
            self::assertNull(SP\decide(self::ctx(['author_query' => true, 'is_admin_area' => true]), $s, []));
        }

        public function test_signed_in_users_skip_xss_only(): void
        {
            $s = self::with(['firewall' => 'block']);
            $xss = self::ctx(['signed_in' => true], ['REQUEST_URI' => '/'], ['c' => '<script>alert(1)</script>']);
            self::assertNull(SP\decide($xss, $s, []));
            $sqli = self::ctx(['signed_in' => true], ['REQUEST_URI' => '/'], ['id' => '1 UNION SELECT 1']);
            self::assertNotNull(SP\decide($sqli, $s, []));
        }

        public function test_signed_in_users_may_name_sensitive_files_in_fields_but_not_in_the_path(): void
        {
            $s = self::with(['firewall' => 'block']);
            $prose = self::ctx(['signed_in' => true], ['REQUEST_URI' => '/wp-admin/post.php'], ['content' => 'Edit wp-config.php and your .env. file']);
            self::assertNull(SP\decide($prose, $s, []), 'an editor writing about wp-config.php is not an attack');
            self::assertNotNull(SP\decide(self::ctx([], ['REQUEST_URI' => '/'], ['content' => 'see wp-config.php']), $s, []), 'anonymous fields are still checked');
            $path = self::ctx(['signed_in' => true], ['REQUEST_URI' => '/wp-content/plugins/x/../../wp-config.php']);
            self::assertNotNull(SP\decide($path, $s, []), 'a sensitive path is blocked for everyone');
            $parts = SP\request_parts(['REQUEST_URI' => '/'], [], ['c' => '/etc/passwd'], []);
            self::assertNotNull(SP\firewall_match($parts, ['sensitive_file:path']));
            self::assertNull(SP\firewall_match($parts, ['sensitive_file:args']));
        }

        // ------------------------------------------------------------ events and counters

        public function test_events_counters_and_status(): void
        {
            SP\record_event('firewall', 'blocked', '192.0.2.1', 'sqli', '/x', 'SQL');
            SP\record_event('login', 'failed', '192.0.2.2', null, '/wp-login.php', 'x');
            SP\record_event('login', 'failed', '192.0.2.2', null, '/wp-login.php', 'x');
            SP\add_block('192.0.2.50', 0, 'manual test', 'manual');
            self::assertCount(1, SP\events(10, 'firewall'));
            $c = SP\counters();
            self::assertSame(1, $c['firewall_blocked_24h']);
            self::assertSame(2, $c['login_failures_24h']);
            self::assertSame(1, $c['blocks_active']);
            $status = SP\status(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1']);
            self::assertSame(1, $status['version']);
            self::assertContains('x_forwarded_for', $status['proxy_headers_seen']);
            self::assertSame('192.0.2.50', SP\block_list()[0]['ip']);
            self::assertNull(SP\block_list()[0]['expires_at']);
        }

        public function test_event_ring_buffer_is_capped(): void
        {
            for ($i = 0; $i < SP\MAX_EVENTS + 20; $i++) {
                SP\record_event('login', 'failed', '192.0.2.1', null, '/wp-login.php', 'x');
            }
            self::assertCount(SP\MAX_EVENTS, SP\events(SP\MAX_EVENTS + 100));
        }
    }
}
