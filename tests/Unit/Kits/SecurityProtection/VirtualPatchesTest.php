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
    // wp_get_theme(), for theme rules.
    require_once dirname(__DIR__, 3) . '/doubles/cloud.php';

    use GoBuildWP\Kits\SecurityProtection as SP;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WPPilot_Test_State;

    /**
     * Virtual patches: rule validation, the site's own version recheck, request matching, who a
     * rule applies to, and the limits on patterns. Every rule here is a made-up example, not a
     * real advisory.
     */
    final class VirtualPatchesTest extends TestCase
    {
        protected function setUp(): void
        {
            WPPilot_Test_State::$options = [];
            WPPilot_Test_State::$plugins = [];
            $GLOBALS['wppilot_test_themes'] = [];
            $GLOBALS['gobuildwp_sp_test_transients'] = [];
        }

        /**
         * @param array<string, mixed> $match
         * @param array<string, mixed> $component
         * @return array<string, mixed>
         */
        private static function example(string $id = 'example-1', array $match = [], array $component = []): array
        {
            return [
                'id' => $id,
                'title' => 'Example: unauthenticated file read',
                'component' => $component + ['type' => 'plugin', 'slug' => 'example-plugin', 'from' => '*', 'to' => '2.0.0', 'to_inclusive' => false],
                'match' => $match + [
                    'endpoint' => ['kind' => 'ajax', 'value' => 'example_download'],
                    'methods' => ['get', 'POST'],
                    'param' => ['name' => 'file', 'test' => 'contains', 'value' => '../'],
                    'who' => 'anonymous',
                ],
            ];
        }

        /**
         * @param array<string, mixed> $server
         * @param array<mixed> $get
         * @param array<mixed> $post
         * @return array<string, mixed>
         */
        private static function req(string $uri, array $get = [], array $post = [], string $method = 'GET'): array
        {
            return SP\vp_request(['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $method], $get, $post);
        }

        /** @return array<string, mixed> */
        private static function clean(array $rule): array
        {
            $clean = SP\sanitize_virtual_patch($rule);
            self::assertIsArray($clean, $clean instanceof WP_Error ? $clean->get_error_message() : '');
            return $clean;
        }

        // ------------------------------------------------------------ validation

        public function test_a_valid_rule_comes_back_canonical(): void
        {
            $clean = self::clean(self::example());
            self::assertSame(['GET', 'POST'], $clean['match']['methods']);
            self::assertSame(['file'], $clean['match']['param']['name']);
            self::assertSame('any', $clean['match']['param']['in']);
            self::assertTrue($clean['component']['from_inclusive']);
            self::assertSame(1, $clean['revision']);
        }

        public function test_invalid_rules_are_refused(): void
        {
            $cases = [
                'id' => ['id' => 'bad id!'] + self::example(),
                'open upper bound' => self::example(component: ['to' => '*']),
                'bad version' => self::example(component: ['to' => '1.0; drop']),
                'bad slug' => self::example(component: ['slug' => '../etc']),
                'unknown endpoint' => self::example(match: ['endpoint' => ['kind' => 'regex', 'value' => '.*']]),
                'root path' => self::example(match: ['endpoint' => ['kind' => 'path', 'value' => '/']]),
                'path with query chars' => self::example(match: ['endpoint' => ['kind' => 'path', 'value' => '/x.php?a=1']]),
                'any endpoint without field' => self::example(match: ['endpoint' => ['kind' => 'any'], 'param' => null]),
                'bad method' => self::example(match: ['methods' => ['TRACE']]),
                'bad who' => self::example(match: ['who' => 'everyone']),
                'capability missing' => self::example(match: ['who' => 'without_capability']),
                'bad param name' => self::example(match: ['param' => ['name' => 'a[b][c][d][e][f]']]),
                'empty contains' => self::example(match: ['param' => ['name' => 'file', 'test' => 'contains', 'value' => '']]),
                'length as text' => self::example(match: ['param' => ['name' => 'file', 'test' => 'longer_than', 'value' => '10']]),
            ];
            foreach ($cases as $label => $rule) {
                self::assertInstanceOf(WP_Error::class, SP\sanitize_virtual_patch($rule), $label);
            }
        }

        public function test_patterns_are_limited(): void
        {
            self::assertNull(SP\vp_pattern_problem('^[a-z0-9_-]+\.(php|phtml)$'));
            self::assertNotNull(SP\vp_pattern_problem('(?<=a)b'), 'look-behind');
            self::assertNotNull(SP\vp_pattern_problem('(?i)x'), 'inline option');
            self::assertNotNull(SP\vp_pattern_problem('(a)\1'), 'back-reference');
            self::assertNotNull(SP\vp_pattern_problem('(*LIMIT_MATCH=99999999)a'), 'verb');
            self::assertNotNull(SP\vp_pattern_problem('\R'), 'recursion escape');
            self::assertNotNull(SP\vp_pattern_problem('[unclosed'), 'does not compile');
            $long = self::example(match: ['param' => ['name' => 'file', 'test' => 'pattern', 'value' => str_repeat('a', SP\VP_MAX_PATTERN + 1)]]);
            self::assertInstanceOf(WP_Error::class, SP\sanitize_virtual_patch($long));
            // A tilde in the pattern can't end the expression early.
            self::assertNull(SP\vp_pattern_problem('a~b'));
            self::assertTrue(SP\vp_pattern_matches('a~b', 'xa~by'));
        }

        public function test_a_runaway_pattern_counts_as_a_match_and_restores_the_limit(): void
        {
            $before = ini_get('pcre.backtrack_limit');
            // Catastrophic backtracking on a long non-matching subject hits the low limit.
            self::assertTrue(SP\vp_pattern_matches('(a+)+$', str_repeat('a', 3000) . 'b'));
            self::assertSame($before, ini_get('pcre.backtrack_limit'));
            self::assertFalse(SP\vp_pattern_matches('^[0-9]+$', 'abc'));
        }

        public function test_the_set_is_replaced_whole_and_one_bad_rule_refuses_it(): void
        {
            WPPilot_Test_State::$plugins = ['example-plugin/example-plugin.php' => ['Version' => '1.9.0']];
            $state = SP\set_virtual_patches([self::example('a'), self::example('b')]);
            self::assertIsArray($state);
            self::assertSame(2, $state['stored']);
            self::assertSame(2, $state['active']);

            $refused = SP\set_virtual_patches([self::example('c'), ['id' => 'broken']]);
            self::assertInstanceOf(WP_Error::class, $refused);
            self::assertSame(2, SP\virtual_patch_state()['stored'], 'the old set stays');

            self::assertInstanceOf(WP_Error::class, SP\set_virtual_patches([self::example('d'), self::example('d')]), 'duplicate ids');
            self::assertInstanceOf(WP_Error::class, SP\set_virtual_patches(array_fill(0, SP\MAX_VPATCHES + 1, self::example())));

            $state = SP\set_virtual_patches([]);
            self::assertIsArray($state);
            self::assertSame(0, $state['stored']);
            self::assertSame([], SP\active_virtual_patches());
            self::assertFalse(SP\has_virtual_patches());
        }

        public function test_the_full_set_is_not_autoloaded_but_the_active_list_is(): void
        {
            WPPilot_Test_State::$plugins = ['example-plugin/example-plugin.php' => ['Version' => '1.0']];
            SP\set_virtual_patches([self::example()]);
            self::assertFalse($GLOBALS['wppilot_test_option_autoload'][SP\OPT_VPATCHES]);
            self::assertTrue($GLOBALS['wppilot_test_option_autoload'][SP\OPT_VPATCHES_ACTIVE]);
        }

        // ------------------------------------------------------------ version recheck

        public function test_version_ranges_follow_version_compare(): void
        {
            $c = ['from' => '1.2', 'from_inclusive' => true, 'to' => '2.0.0', 'to_inclusive' => false];
            self::assertTrue(SP\version_in_range('1.2', $c));
            self::assertTrue(SP\version_in_range('1.10', $c));
            self::assertTrue(SP\version_in_range('2.0.0-beta1', $c));
            self::assertFalse(SP\version_in_range('2.0.0', $c));
            self::assertFalse(SP\version_in_range('1.1.9', $c));
            self::assertTrue(SP\version_in_range('2.0.0', ['to_inclusive' => true] + $c));
            self::assertFalse(SP\version_in_range('1.2', ['from_inclusive' => false] + $c));
            self::assertTrue(SP\version_in_range('0.1', ['from' => '*'] + $c));
            self::assertTrue(SP\version_in_range('', $c), 'an unknown version may be old');
        }

        public function test_the_site_drops_a_rule_once_the_component_is_updated_or_removed(): void
        {
            WPPilot_Test_State::$plugins = ['example-plugin/example-plugin.php' => ['Version' => '1.5.0']];
            $GLOBALS['wppilot_test_themes'] = ['example-theme' => ['Name' => 'Example', 'Version' => '3.0']];
            SP\set_virtual_patches([
                self::example('plugin-rule'),
                self::example('theme-rule', component: ['type' => 'theme', 'slug' => 'example-theme', 'to' => '3.1']),
                self::example('not-installed', component: ['slug' => 'other-plugin']),
            ]);
            self::assertSame(['plugin-rule', 'theme-rule'], array_column(SP\active_virtual_patches(), 'id'));
            self::assertTrue(SP\has_virtual_patches());

            WPPilot_Test_State::$plugins = ['example-plugin/example-plugin.php' => ['Version' => '2.0.0']];
            SP\recheck_virtual_patches();
            self::assertSame(['theme-rule'], array_column(SP\active_virtual_patches(), 'id'));

            unset($GLOBALS['wppilot_test_themes']['example-theme']);
            SP\recheck_virtual_patches();
            self::assertSame([], SP\active_virtual_patches());
            self::assertTrue(SP\has_virtual_patches(), 'stored rules still need rechecking on the next update');

            $state = SP\virtual_patch_state();
            self::assertSame(3, $state['stored']);
            self::assertSame(0, $state['active']);
            self::assertSame('2.0.0', $state['rules'][0]['installed_version']);
            self::assertNull($state['rules'][2]['installed_version']);
        }

        public function test_single_file_plugins_are_found_by_file_name(): void
        {
            WPPilot_Test_State::$plugins = ['example-plugin.php' => ['Version' => '1.0']];
            self::assertSame('1.0', SP\installed_version('plugin', 'example-plugin'));
            self::assertNull(SP\installed_version('plugin', 'example'));
        }

        // ------------------------------------------------------------ matching

        public function test_ajax_rule_matches_the_action_and_field(): void
        {
            $rule = self::clean(self::example());
            self::assertTrue(SP\vp_matches($rule, self::req('/wp-admin/admin-ajax.php', ['action' => 'example_download', 'file' => '../../wp-config.php'])));
            self::assertTrue(SP\vp_matches($rule, self::req('/blog/wp-admin/admin-ajax.php', [], ['action' => 'example_download', 'file' => '..%2F..%2Fwp-config.php'], 'POST')), 'double-encoded, subdirectory');
            self::assertTrue(SP\vp_matches($rule, self::req('/wp-admin//./admin-ajax.php', ['action' => 'example_download', 'file' => '../x'])), 'path tricks');
            self::assertFalse(SP\vp_matches($rule, self::req('/wp-admin/admin-ajax.php', ['action' => 'example_download', 'file' => 'report.pdf'])), 'harmless value');
            self::assertFalse(SP\vp_matches($rule, self::req('/wp-admin/admin-ajax.php', ['action' => 'other', 'file' => '../x'])), 'other action');
            self::assertFalse(SP\vp_matches($rule, self::req('/wp-admin/admin-post.php', ['action' => 'example_download', 'file' => '../x'])), 'other script');
            self::assertFalse(SP\vp_matches($rule, self::req('/wp-admin/admin-ajax.php', ['action' => 'example_download', 'file' => '../x'], [], 'PUT')), 'other method');
        }

        public function test_rest_routes_match_on_segment_boundaries_either_way_they_are_sent(): void
        {
            $rule = self::clean(self::example(match: ['endpoint' => ['kind' => 'rest', 'value' => '/example/v1/upload'], 'param' => null, 'methods' => []]));
            self::assertTrue(SP\vp_matches($rule, self::req('/wp-json/example/v1/upload')));
            self::assertTrue(SP\vp_matches($rule, self::req('/wp-json/Example/v1/upload/7/')));
            self::assertTrue(SP\vp_matches($rule, self::req('/index.php', ['rest_route' => '/example/v1/upload'])));
            self::assertTrue(SP\vp_matches($rule, self::req('/?rest_route=%2Fexample%2Fv1%2Fupload', ['rest_route' => '/example//v1/upload'])));
            self::assertFalse(SP\vp_matches($rule, self::req('/wp-json/example/v1/uploads')));
            self::assertFalse(SP\vp_matches($rule, self::req('/wp-json/example/v1')));
        }

        public function test_only_the_front_controller_counts_as_rest(): void
        {
            // admin-ajax.php with ?rest_route= or a /wp-json/ path suffix is not REST, so a request
            // with an Authorization header can't use the REST deferral to slip past an ajax rule.
            $ajax = SP\vp_request(['REQUEST_URI' => '/wp-admin/admin-ajax.php?rest_route=/x', 'SCRIPT_NAME' => '/wp-admin/admin-ajax.php'], ['rest_route' => '/x', 'action' => 'example_download'], []);
            self::assertNull($ajax['rest_route']);
            $suffix = SP\vp_request(['REQUEST_URI' => '/wp-admin/admin-ajax.php/wp-json/x', 'SCRIPT_NAME' => '/wp-admin/admin-ajax.php'], [], []);
            self::assertNull($suffix['rest_route']);
            $rest = SP\vp_request(['REQUEST_URI' => '/wp-json/example/v1/upload', 'SCRIPT_NAME' => '/index.php'], [], []);
            self::assertSame('/example/v1/upload', $rest['rest_route']);
        }

        public function test_a_json_body_cannot_pick_the_ajax_action_or_the_rest_route(): void
        {
            // admin-ajax.php dispatches on $_REQUEST['action'], which a JSON body never reaches: the
            // handler WordPress runs is the one in the query string, so that is the one the rule sees.
            $rule = self::clean(self::example());
            $server = ['REQUEST_URI' => '/wp-admin/admin-ajax.php?action=example_download', 'REQUEST_METHOD' => 'POST', 'SCRIPT_NAME' => '/wp-admin/admin-ajax.php'];
            $json = SP\vp_request($server, ['action' => 'example_download'], ['action' => 'heartbeat', 'file' => '../../wp-config.php'], '/wp-json/', true);
            self::assertSame('example_download', $json['action']);
            self::assertTrue(SP\vp_matches($rule, $json), 'JSON action must not hide the real handler');
            // A form body is what $_REQUEST is built from, so there it does win over the query string.
            $form = SP\vp_request($server, ['action' => 'example_download'], ['action' => 'heartbeat']);
            self::assertSame('heartbeat', $form['action']);
            // The same holds for rest_route: a JSON body cannot set the query var.
            $rest = SP\vp_request(['REQUEST_URI' => '/wp-json/example/v1/upload', 'REQUEST_METHOD' => 'POST'], [], ['rest_route' => '/other/v1/x'], '/wp-json/', true);
            self::assertSame('/example/v1/upload', $rest['rest_route']);
        }

        public function test_rest_route_query_var_overrides_the_wp_json_path(): void
        {
            // parse_request takes rest_route from the form body, then the query string, before the
            // permalink, so the route WordPress serves is the query var's, not the path's.
            $rule = self::clean(self::example(match: ['endpoint' => ['kind' => 'rest', 'value' => '/example/v1/upload'], 'param' => null, 'methods' => []]));
            $get = SP\vp_request(['REQUEST_URI' => '/wp-json/wp/v2/types?rest_route=/example/v1/upload'], ['rest_route' => '/example/v1/upload'], []);
            self::assertSame('/example/v1/upload', $get['rest_route']);
            self::assertTrue(SP\vp_matches($rule, $get), 'query var route is the served route');
            $hidden = SP\vp_request(['REQUEST_URI' => '/wp-json/example/v1/upload?rest_route=/wp/v2/types'], ['rest_route' => '/wp/v2/types'], []);
            self::assertSame('/wp/v2/types', $hidden['rest_route']);
            self::assertFalse(SP\vp_matches($rule, $hidden), 'the path alone is not the served route');
            $post = SP\vp_request(['REQUEST_URI' => '/index.php', 'REQUEST_METHOD' => 'POST'], [], ['rest_route' => '/example/v1/upload']);
            self::assertSame('/example/v1/upload', $post['rest_route']);
            self::assertTrue(SP\vp_matches($rule, $post), 'form-body rest_route is REST');
            $both = SP\vp_request(['REQUEST_URI' => '/?rest_route=/wp/v2/types', 'REQUEST_METHOD' => 'POST'], ['rest_route' => '/wp/v2/types'], ['rest_route' => '/example/v1/upload']);
            self::assertSame('/example/v1/upload', $both['rest_route'], 'form body wins over query string');
            self::assertNull(SP\vp_request(['REQUEST_URI' => '/wp-admin/admin-ajax.php', 'SCRIPT_NAME' => '/wp-admin/admin-ajax.php', 'REQUEST_METHOD' => 'POST'], [], ['rest_route' => '/x'])['rest_route']);
        }

        public function test_json_bodies_are_read_as_parameters(): void
        {
            self::assertTrue(SP\vp_is_json(['CONTENT_TYPE' => 'application/json; charset=utf-8']));
            self::assertFalse(SP\vp_is_json(['CONTENT_TYPE' => 'application/x-www-form-urlencoded']));
            self::assertSame(['file' => '../x'], SP\vp_json_body('{"file":"../x"}'));
            self::assertSame([], SP\vp_json_body('"text"'));
            self::assertSame([], SP\vp_json_body('{bad'));
            self::assertSame([], SP\vp_json_body(str_repeat(' ', SP\VP_MAX_JSON_BYTES + 1)));
            $rule = self::clean(self::example(match: ['endpoint' => ['kind' => 'rest', 'value' => '/example/v1/upload'], 'methods' => [], 'param' => ['name' => 'file', 'in' => 'body', 'test' => 'contains', 'value' => '../']]));
            $req = SP\vp_request(['REQUEST_URI' => '/wp-json/example/v1/upload', 'REQUEST_METHOD' => 'POST'], [], SP\vp_json_body('{"file":"../../wp-config.php"}'));
            self::assertTrue(SP\vp_matches($rule, $req));
        }

        public function test_a_custom_rest_prefix_is_recognised(): void
        {
            $rule = self::clean(self::example(match: ['endpoint' => ['kind' => 'rest', 'value' => '/example/v1/upload'], 'param' => null, 'methods' => []]));
            $req = SP\vp_request(['REQUEST_URI' => '/blog/api/example/v1/upload', 'SCRIPT_NAME' => '/blog/index.php'], [], [], '/blog/api/');
            self::assertTrue(SP\vp_matches($rule, $req));
            self::assertNull(SP\vp_request(['REQUEST_URI' => '/blog/wp-json/example/v1/upload'], [], [], '/blog/api/')['rest_route']);
        }

        public function test_path_rule_matches_the_end_of_the_path(): void
        {
            $rule = self::clean(self::example(match: ['endpoint' => ['kind' => 'path', 'value' => '/wp-content/plugins/example-plugin/lib/Download.php'], 'param' => null, 'methods' => []]));
            self::assertSame('/wp-content/plugins/example-plugin/lib/download.php', $rule['match']['endpoint']['value']);
            self::assertTrue(SP\vp_matches($rule, self::req('/site/wp-content/plugins/example-plugin/lib/download.php?x=1')));
            self::assertTrue(SP\vp_matches($rule, self::req('/wp-content/plugins/example-plugin/x/../lib/DOWNLOAD.php')));
            self::assertFalse(SP\vp_matches($rule, self::req('/wp-content/plugins/example-plugin/lib/download.php.bak')));
        }

        public function test_field_tests(): void
        {
            $with = static fn(array $param): array => self::clean(self::example(match: ['endpoint' => ['kind' => 'any'], 'param' => $param, 'methods' => []]));
            $req = static fn(array $get, array $post = []): array => self::req('/', $get, $post, $post === [] ? 'GET' : 'POST');

            self::assertTrue(SP\vp_matches($with(['name' => 'data[file]', 'test' => 'present']), $req(['data' => ['file' => '']])));
            self::assertFalse(SP\vp_matches($with(['name' => 'data[file]', 'test' => 'present']), $req(['data' => 'file'])));
            self::assertTrue(SP\vp_matches($with(['name' => 'id', 'test' => 'not_integer']), $req(['id' => '1 OR 1=1'])));
            self::assertFalse(SP\vp_matches($with(['name' => 'id', 'test' => 'not_integer']), $req(['id' => '42'])));
            self::assertTrue(SP\vp_matches($with(['name' => 'id', 'test' => 'not_integer']), $req(['id' => ['42', 'x']])), 'every value of a list is tested');
            self::assertTrue(SP\vp_matches($with(['name' => 'name', 'test' => 'longer_than', 'value' => 5]), $req(['name' => 'abcdef'])));
            self::assertTrue(SP\vp_matches($with(['name' => 'mode', 'test' => 'equals', 'value' => 'raw']), $req(['mode' => 'raw'])));
            self::assertFalse(SP\vp_matches($with(['name' => 'mode', 'test' => 'equals', 'value' => 'raw']), $req(['mode' => 'RAW'])));
            self::assertTrue(SP\vp_matches($with(['name' => 'f', 'test' => 'pattern', 'value' => '\.ph(p|tml)$']), $req([], ['f' => 'shell.PHP'])));
            self::assertFalse(SP\vp_matches($with(['name' => 'f', 'in' => 'query', 'test' => 'pattern', 'value' => '\.php$']), $req([], ['f' => 'shell.php'])), 'only the query string');
        }

        public function test_who_a_rule_applies_to(): void
        {
            $nobody = static fn(string $cap): bool => false;
            $editor = static fn(string $cap): bool => $cap === 'edit_posts';
            $admin = static fn(string $cap): bool => true;
            $anonymous = self::clean(self::example());
            $cap = self::clean(self::example(match: ['who' => 'without_capability', 'capability' => 'edit_posts']));
            $anyone = self::clean(self::example(match: ['who' => 'anyone']));

            self::assertTrue(SP\vp_applies_to($anonymous, false, $nobody));
            self::assertFalse(SP\vp_applies_to($anonymous, true, $nobody));
            self::assertTrue(SP\vp_applies_to($cap, true, $nobody), 'subscriber');
            self::assertFalse(SP\vp_applies_to($cap, true, $editor));
            self::assertTrue(SP\vp_applies_to($cap, false, $nobody));
            self::assertTrue(SP\vp_applies_to($anyone, true, $editor));
            self::assertFalse(SP\vp_applies_to($anyone, true, $admin), 'administrators are never blocked');
        }

        public function test_block_decision_is_a_firewall_event_named_after_the_rule(): void
        {
            $decision = SP\vp_decision(self::clean(self::example('example-7')));
            self::assertSame('firewall', $decision['type']);
            self::assertSame('vpatch:example-7', $decision['rule']);
            self::assertSame('block', $decision['action']);
            $event = SP\record_event($decision['type'], 'blocked', '203.0.113.9', $decision['rule'], '/wp-admin/admin-ajax.php', $decision['detail']);
            self::assertSame('vpatch:example-7', $event['rule']);
            self::assertSame(1, SP\counters()['firewall_blocked_24h']);
        }
    }
}
