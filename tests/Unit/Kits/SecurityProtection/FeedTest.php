<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection {
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
}

namespace GoBuildWP\Tests\Unit\Kits\SecurityProtection {

    require_once dirname(__DIR__, 4) . '/includes/kits/security-protection/src/load.php';

    use GoBuildWP\Kits\SecurityProtection as SP;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WPPilot_Test_State;

    /**
     * The Cloud feed: the extra rule pack and the fleet reputation blocklist, their validation,
     * and how the firewall and the guard use them.
     */
    final class FeedTest extends TestCase
    {
        protected function setUp(): void
        {
            WPPilot_Test_State::$options = [];
            $GLOBALS['gobuildwp_sp_test_transients'] = [];
        }

        /** @return array<string, mixed> */
        private static function rule(array $over = []): array
        {
            return $over + ['id' => 'cve-2026-1', 'label' => 'Exploit probe', 'in' => ['path', 'args'], 'pattern' => 'wp-content/plugins/bad/.*\.php'];
        }

        // ------------------------------------------------------------ validation

        public function test_empty_feed_is_the_default(): void
        {
            self::assertSame(['rules' => 0, 'blocklist' => 0, 'digest' => null, 'set_at' => null], SP\feed_summary());
            self::assertFalse(SP\feed_has_content());
            self::assertSame([], SP\feed_rules());
            self::assertSame([], SP\feed_blocklist());
        }

        public function test_set_feed_stores_rules_and_blocklist(): void
        {
            $r = SP\set_feed(['rules' => [self::rule()], 'blocklist' => [['ip' => '203.0.113.9', 'reason' => 'fleet'], ['ip' => '198.51.100.0/24']], 'digest' => 'v1:abc'], 1_700_000_000);
            self::assertIsArray($r);
            self::assertSame(1, $r['rules']);
            self::assertSame(2, $r['blocklist']);
            self::assertSame('v1:abc', $r['digest']);
            self::assertSame('2023-11-14T22:13:20Z', $r['set_at']);
            self::assertArrayHasKey('prepend', $r);
            self::assertTrue(SP\feed_has_content());
            $rules = SP\feed_rules();
            self::assertSame('feed:cve-2026-1', $rules[0]['id']);
            self::assertSame(['path', 'args'], $rules[0]['in']);
            self::assertSame(1, preg_match($rules[0]['pattern'], '/wp-content/plugins/bad/shell.php'));
            // The settings event names the counts.
            $events = SP\events(5, 'settings');
            self::assertStringContainsString('1 rules, 2 blocklist', $events[0]['detail']);
        }

        public function test_set_feed_replaces_the_whole_feed(): void
        {
            SP\set_feed(['rules' => [self::rule()], 'blocklist' => [['ip' => '203.0.113.9']]]);
            $r = SP\set_feed(['rules' => [], 'blocklist' => [], 'digest' => null]);
            self::assertIsArray($r);
            self::assertSame(0, $r['rules']);
            self::assertSame(0, $r['blocklist']);
            self::assertFalse(SP\feed_has_content());
        }

        public function test_rules_are_validated(): void
        {
            foreach ([
                self::rule(['id' => 'bad id']),
                self::rule(['id' => str_repeat('a', 65)]),
                self::rule(['label' => '']),
                self::rule(['in' => []]),
                self::rule(['in' => ['body']]),
                self::rule(['pattern' => '']),
                self::rule(['pattern' => str_repeat('a', SP\VP_MAX_PATTERN + 1)]),
                self::rule(['pattern' => '(?:a']),
                'not an array',
            ] as $rule) {
                $r = SP\set_feed(['rules' => [$rule]]);
                self::assertInstanceOf(WP_Error::class, $r, json_encode($rule));
                self::assertSame('gobuildwp_feed_invalid', $r->get_error_code());
            }
            self::assertFalse(SP\feed_has_content());
        }

        public function test_blocklist_entries_are_validated(): void
        {
            foreach ([
                ['ip' => 'junk'],
                ['ip' => '10.0.0.0/8'],
                ['ip' => '2001:db8::/32'],
                ['ip' => '203.0.113.9', 'expires_at' => 'not a date'],
                ['reason' => 'no ip'],
            ] as $entry) {
                $r = SP\set_feed(['blocklist' => [$entry]]);
                self::assertInstanceOf(WP_Error::class, $r, json_encode($entry));
            }
            self::assertInstanceOf(WP_Error::class, SP\set_feed(['rules' => 'nope']));
            self::assertInstanceOf(WP_Error::class, SP\set_feed(['digest' => 'has space']));
            self::assertInstanceOf(WP_Error::class, SP\set_feed(['rules' => array_fill(0, SP\MAX_FEED_RULES + 1, self::rule())]));
        }

        public function test_duplicates_and_expired_entries_are_dropped(): void
        {
            // Real time: the reply's counts come from feed_state(), which looks at the clock.
            $now = time();
            $r = SP\set_feed([
                'rules' => [self::rule()],
                'blocklist' => [
                    ['ip' => '203.0.113.9'],
                    ['ip' => '203.0.113.9', 'reason' => 'twice'],
                    ['ip' => '203.0.113.10', 'expires_at' => gmdate('c', $now - 60)],
                    ['ip' => '203.0.113.11', 'expires_at' => gmdate('c', $now + 3600)],
                ],
            ], $now);
            self::assertIsArray($r);
            self::assertSame(1, $r['rules']);
            self::assertSame(2, $r['blocklist']);
            $list = SP\feed_blocklist($now);
            self::assertSame(['203.0.113.9', '203.0.113.11'], array_column($list, 'ip'));
            self::assertSame($now + 3600, $list[1]['expires_at']);
            // Once the timed entry has expired it is no longer served.
            self::assertSame(['203.0.113.9'], array_column(SP\feed_blocklist($now + 7200), 'ip'));
            // Two rules with one id is a Cloud bug, not something to paper over.
            $r = SP\set_feed(['rules' => [self::rule(), self::rule(['label' => 'Again'])]]);
            self::assertInstanceOf(WP_Error::class, $r);
            self::assertStringContainsString('appears twice', $r->get_error_message());
        }

        // ------------------------------------------------------------ use

        public function test_feed_block_for_matches_addresses_and_ranges(): void
        {
            $list = [['ip' => '203.0.113.9', 'reason' => '', 'expires_at' => null], ['ip' => '198.51.100.0/24', 'reason' => '', 'expires_at' => null]];
            self::assertNotNull(SP\feed_block_for('203.0.113.9', $list));
            self::assertNotNull(SP\feed_block_for('198.51.100.77', $list));
            self::assertNull(SP\feed_block_for('203.0.113.10', $list));
            self::assertNull(SP\feed_block_for('', $list));
        }

        public function test_firewall_match_runs_feed_rules_after_the_built_in_ones(): void
        {
            SP\set_feed(['rules' => [self::rule(['in' => ['ua'], 'pattern' => '^EvilBot/'])]]);
            $parts = SP\request_parts(['REQUEST_URI' => '/', 'HTTP_USER_AGENT' => 'EvilBot/1.0'], [], [], []);
            $hit = SP\firewall_match($parts);
            self::assertNotNull($hit);
            self::assertSame('feed:cve-2026-1', $hit['id']);
            self::assertSame('ua', $hit['in']);
            // Skips work on feed ids too.
            self::assertNull(SP\firewall_match($parts, ['feed:cve-2026-1']));
        }

        public function test_guard_blocks_reputation_addresses_but_not_the_allowlist(): void
        {
            $settings = SP\sanitize_settings(['firewall' => 'off', 'login' => ['enabled' => false], 'block_user_enumeration' => false], SP\default_settings());
            self::assertIsArray($settings);
            $ctx = [
                'ip' => '203.0.113.9', 'path' => '/', 'is_login' => false, 'is_xmlrpc' => false, 'is_rest' => false,
                'is_admin_area' => false, 'has_auth_header' => false, 'author_query' => false, 'signed_in' => false, 'admin' => false,
                'parts' => SP\request_parts(['REQUEST_URI' => '/'], [], [], []),
            ];
            $list = [['ip' => '203.0.113.0/24', 'reason' => '', 'expires_at' => null]];
            $d = SP\decide($ctx, $settings, [], $list);
            self::assertSame(['action' => 'block', 'type' => 'block', 'rule' => 'reputation'], array_intersect_key($d ?? [], ['action' => 1, 'type' => 1, 'rule' => 1]));
            // A REST request with its own authentication is deferred, not refused outright.
            $d = SP\decide(['is_rest' => true, 'has_auth_header' => true] + $ctx, $settings, [], $list);
            self::assertSame('defer', $d['action'] ?? null);
            // The allowlist wins; so does an administrator.
            $allow = SP\sanitize_settings(['allowlist' => ['203.0.113.9']], $settings);
            self::assertIsArray($allow);
            self::assertNull(SP\decide($ctx, $allow, [], $list));
            self::assertNull(SP\decide(['signed_in' => true, 'admin' => true] + $ctx, $settings, [], $list));
            // With no list given, the stored feed is used.
            self::assertNull(SP\decide($ctx, $settings, []));
            SP\set_feed(['blocklist' => [['ip' => '203.0.113.9']]]);
            self::assertSame('reputation', SP\decide($ctx, $settings, [])['rule'] ?? null);
        }
    }
}
