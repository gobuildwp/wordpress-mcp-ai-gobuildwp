<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\Redirects {
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WPPilot\Kits\NotFoundLog;
    use WPPilot\Kits\Redirects;
    use WPPilot\Kits\Runtime;
    use WPPilot\Kits\Runtime\Host;
    use WPPilot\Kits\Runtime\Jobs;
    use WPPilot\Kits\Runtime\Ledger;

    final class State
    {
        /** @var list<array{ability: string, items: list<array<string, mixed>>}> */
        public static array $recorded = [];

        /** @var array<string, callable> */
        public static array $strategies = [];

        /** @var array<string, array<string, mixed>> */
        public static array $registered = [];

        /** @var array<int, string> post id => permalink */
        public static array $links = [];

        /** @var array<string, int> "reason:slug" => post id */
        public static array $slugs = [];

        public static bool $elsewhere = false;

        public static bool $confirmed = true;
    }

    /**
     * The redirects kit: what a source and target may be, matching, loops and chains, serving,
     * the ledger rows and their undo, automatic redirects on a moved URL, and 404 suggestions.
     */
    final class RedirectsTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
            $kit = require dirname(__DIR__, 4) . '/includes/kits/redirects/bootstrap.php';
            Runtime\host(self::host());
            ($kit['boot'])(Runtime\host());
            foreach ($kit['ability_files'] as $file) {
                require_once $file;
            }
        }

        protected function setUp(): void
        {
            Runtime\host(self::host());
            unset(\Kit_Test_State::$options[Redirects\OPTION], \Kit_Test_State::$options[Redirects\HITS_OPTION], \Kit_Test_State::$options[NotFoundLog\OPTION]);
            State::$recorded = [];
            State::$links = [];
            State::$slugs = [];
            State::$elsewhere = false;
            State::$confirmed = true;
            remove_all_filters(Redirects\AUTO_FILTER);
            remove_all_filters(Redirects\MATCH_TYPES_FILTER);
            remove_all_filters(Redirects\MATCH_FILTER);
        }

        /** @param array<string, mixed> $input */
        private function create(array $input): array
        {
            $out = Redirects\create_rule($input);
            self::assertIsArray($out, $out instanceof WP_Error ? $out->get_error_code() . ': ' . $out->get_error_message() : '');
            return $out;
        }

        private function errorCode(mixed $out): string
        {
            self::assertInstanceOf(WP_Error::class, $out);
            return $out->get_error_code();
        }

        public function testTheManifestMatchesWhatIsRegistered(): void
        {
            $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/redirects/kit.json'), true);
            $names = array_column($manifest['abilities'], 'name');
            self::assertSame(['wppilot/redirect-list', 'wppilot/redirect-create', 'wppilot/redirect-delete', 'wppilot/redirect-suggest', 'wppilot/redirect-test'], $names);
            foreach ($manifest['abilities'] as $ability) {
                $annotations = State::$registered[$ability['name']]['meta']['annotations'];
                self::assertSame($ability['readonly'], $annotations['readonly'], $ability['name']);
                self::assertSame($ability['destructive'], $annotations['destructive'], $ability['name']);
            }
        }

        public function testWordPressEndpointsCannotBeRedirected(): void
        {
            foreach (['/wp-admin/', '/wp-admin/options.php', '/WP-LOGIN.PHP', '/wp-json/wp/v2/posts', 'https://example.test/xmlrpc.php', '/wp-cron.php'] as $source) {
                self::assertSame('kit_redirect_protected', $this->errorCode(Redirects\create_rule(['source' => $source, 'target' => '/x/'])), $source);
            }
            self::assertSame('kit_redirect_protected', $this->errorCode(Redirects\create_rule(['source' => '/', 'target' => '/x/', 'match' => 'prefix'])));
            // A path that only starts with the same letters is not an endpoint.
            $this->create(['source' => '/wp-administrator-guide/', 'target' => '/guide/']);
        }

        public function testSourcesMustBeLocalPathsWithoutAQuery(): void
        {
            self::assertSame('kit_redirect_bad_source', $this->errorCode(Redirects\create_rule(['source' => 'https://elsewhere.example/a', 'target' => '/b/'])));
            self::assertSame('kit_redirect_bad_source', $this->errorCode(Redirects\create_rule(['source' => '/a/?x=1', 'target' => '/b/'])));
            self::assertSame('kit_redirect_bad_source', $this->errorCode(Redirects\create_rule(['source' => '//evil.example/a', 'target' => '/b/'])));
            self::assertSame('kit_redirect_bad_source', $this->errorCode(Redirects\create_rule(['source' => '/a/../wp-admin', 'target' => '/b/'])));
            $out = $this->create(['source' => 'https://example.test/old/', 'target' => '/new/']);
            self::assertSame('/old/', $out['redirect']['source']);
        }

        public function testTargetsRefuseIpAddressesCredentialsAndOtherSchemes(): void
        {
            foreach (['http://192.168.1.10/x', 'https://[::1]/x', 'https://user:pass@example.org/', 'javascript:alert(1)', 'ftp://example.org/', '//example.org/x', '/a b'] as $target) {
                self::assertSame('kit_redirect_bad_target', $this->errorCode(Redirects\create_rule(['source' => '/s/', 'target' => $target])), $target);
            }
            self::assertSame('kit_redirect_bad_target', $this->errorCode(Redirects\create_rule(['source' => '/s/', 'type' => 301])));
            self::assertSame('kit_redirect_bad_target', $this->errorCode(Redirects\create_rule(['source' => '/s/', 'type' => 410, 'target' => '/x/'])));
            self::assertSame('kit_redirect_bad_type', $this->errorCode(Redirects\create_rule(['source' => '/s/', 'target' => '/x/', 'type' => 307])));
            $this->create(['source' => '/s/', 'target' => 'https://partner.example.org/page?ref=1']);
            $this->create(['source' => '/gone/', 'type' => 410]);
        }

        public function testADuplicateSourceIsRefusedWhateverItsCaseOrTrailingSlash(): void
        {
            $first = $this->create(['source' => '/Old-Page/', 'target' => '/new/']);
            $out = Redirects\create_rule(['source' => '/old-page', 'target' => '/other/']);
            self::assertSame('kit_redirect_exists', $this->errorCode($out));
            self::assertSame($first['redirect']['id'], $out->get_error_data()['redirect']);
        }

        public function testExactBeatsPrefixAndTheLongestPrefixWins(): void
        {
            $this->create(['source' => '/shop/', 'target' => '/store/', 'match' => 'prefix']);
            $this->create(['source' => '/shop/sale', 'target' => '/offers/', 'match' => 'prefix']);
            $this->create(['source' => '/shop/sale/today', 'target' => '/today/']);
            $rules = Redirects\read();

            self::assertSame('/store/hats/red', Redirects\find('/shop/hats/red', $rules)['location']);
            self::assertSame('/store/', Redirects\find('/shop', $rules)['location']);
            self::assertSame('/offers/winter', Redirects\find('/SHOP/sale/winter/', $rules)['location']);
            self::assertSame('/today/', Redirects\find('/shop/sale/today/', $rules)['location']);
            self::assertNull(Redirects\find('/shopping/', $rules));
        }

        public function testLoopsAreRefused(): void
        {
            $this->create(['source' => '/a/', 'target' => '/b/']);
            self::assertSame('kit_redirect_loop', $this->errorCode(Redirects\create_rule(['source' => '/b/', 'target' => '/a/'])));
            self::assertSame('kit_redirect_loop', $this->errorCode(Redirects\create_rule(['source' => '/self/', 'target' => '/self'])));
            self::assertSame('kit_redirect_loop', $this->errorCode(Redirects\create_rule(['source' => '/c/', 'target' => 'https://example.test/c/'])));
            self::assertCount(1, Redirects\read());
        }

        public function testAChainOfMoreThanThreeRedirectsIsRefused(): void
        {
            $this->create(['source' => '/1/', 'target' => '/2/']);
            $this->create(['source' => '/2/', 'target' => '/3/']);
            $this->create(['source' => '/3/', 'target' => '/4/']);
            // /0 -> /1 -> /2 -> /3 -> /4 is four redirects.
            self::assertSame('kit_redirect_chain', $this->errorCode(Redirects\create_rule(['source' => '/0/', 'target' => '/1/'])));
            self::assertSame('kit_redirect_chain', $this->errorCode(Redirects\create_rule(['source' => '/4/', 'target' => '/5/'])));
            // A prefix rule whose target sits under its own source never lands.
            self::assertSame('kit_redirect_chain', $this->errorCode(Redirects\create_rule(['source' => '/docs', 'target' => '/docs/v2', 'match' => 'prefix'])));
            $this->create(['source' => '/0/', 'target' => '/4/']);
        }

        public function testTheTraceShowsEveryHopAndWhereItEnds(): void
        {
            $this->create(['source' => '/a/', 'target' => '/b/']);
            $this->create(['source' => '/b/', 'target' => 'https://partner.example.org/x']);
            $this->create(['source' => '/old/', 'type' => 410]);

            $out = Redirects\test_path(['path' => 'https://example.test/a/?utm=1']);
            self::assertSame(['/a/', '/b/'], array_column($out['hops'], 'from'));
            self::assertSame('https://partner.example.org/x', $out['final']);
            self::assertTrue($out['leaves_site']);
            self::assertFalse($out['loop']);

            $gone = Redirects\test_path(['path' => '/old']);
            self::assertTrue($gone['gone']);
            self::assertSame('', $gone['final']);

            self::assertSame([], Redirects\test_path(['path' => '/nothing/'])['hops']);
            self::assertTrue(Redirects\test_path(['path' => '/wp-admin/'])['protected']);
            self::assertSame('kit_redirect_bad_path', $this->errorCode(Redirects\test_path(['path' => 'https://elsewhere.example/'])));
        }

        public function testARequestGetsItsRedirectWithItsQueryStringButEndpointsNever(): void
        {
            $this->create(['source' => '/old/', 'target' => '/new/', 'type' => 302]);
            $this->create(['source' => '/campaign/', 'target' => '/landing/?src=mail']);
            $this->create(['source' => '/retired/', 'type' => 410]);
            $rules = Redirects\read();

            self::assertSame(['status' => 302, 'location' => '/new/?a=1&b=2'], array_diff_key(Redirects\resolve_request('/old?a=1&b=2', $rules), ['id' => 1]));
            self::assertSame('/landing/?src=mail', Redirects\resolve_request('/campaign/?x=1', $rules)['location']);
            self::assertSame(410, Redirects\resolve_request('/retired/', $rules)['status']);
            self::assertNull(Redirects\resolve_request('/somewhere/', $rules));

            // A rule smuggled into the store for an endpoint is still never served.
            $rules['rx'] = ['id' => 'rx', 'source' => '/wp-login.php', 'match' => 'exact', 'target' => '/x/', 'type' => 301, 'origin' => 'manual', 'post_id' => 0, 'created' => 0];
            self::assertNull(Redirects\resolve_request('/wp-login.php?action=lostpassword', $rules));
        }

        public function testHitsAreCountedAtMostOncePerSecond(): void
        {
            $out = $this->create(['source' => '/a/', 'target' => '/b/']);
            $id = $out['redirect']['id'];

            Redirects\count_hit($id, 100);
            Redirects\count_hit($id, 100);
            Redirects\count_hit($id, 101);

            self::assertSame(['hits' => 2, 'last_hit' => 101], Redirects\hits()[$id]);
            self::assertSame(2, Redirects\list_rules([])['redirects'][0]['hits']);
            self::assertFalse(\Kit_Test_State::option_autoload(Redirects\HITS_OPTION));
            self::assertTrue(\Kit_Test_State::option_autoload(Redirects\OPTION));
        }

        public function testCreateIsLedgeredAndItsUndoRemovesTheRule(): void
        {
            $dry = $this->create(['source' => '/a/', 'target' => '/b/', 'dry_run' => true]);
            self::assertTrue($dry['dry_run']);
            self::assertSame([], Redirects\read());
            self::assertSame([], State::$recorded);

            $out = $this->create(['source' => '/a/', 'target' => '/b/']);
            self::assertSame('wppilot/redirect-create', State::$recorded[0]['ability']);
            $before = State::$recorded[0]['items'][0]['before'];
            self::assertSame(Redirects\STRATEGY, $before['type']);
            self::assertNull($before['rule']);

            $restore = State::$strategies[Redirects\STRATEGY];
            self::assertTrue($restore(['snapshot' => $before])['verified']);
            self::assertSame([], Redirects\read());
            self::assertSame(['c1'], $out['change_ids']);
        }

        public function testUndoIsRefusedWhenTheRuleChangedSince(): void
        {
            $this->create(['source' => '/a/', 'target' => '/b/']);
            $before = State::$recorded[0]['items'][0]['before'];
            $rules = Redirects\read();
            $rules[$before['id']]['target'] = '/elsewhere/';
            Redirects\write($rules);

            self::assertSame('kit_redirect_changed_since', $this->errorCode((State::$strategies[Redirects\STRATEGY])(['snapshot' => $before])));
            self::assertCount(1, Redirects\read());
        }

        public function testDeleteNeedsEveryIdIsLedgeredAndUndoPutsItBack(): void
        {
            $a = $this->create(['source' => '/a/', 'target' => '/b/'])['redirect'];
            $c = $this->create(['source' => '/c/', 'target' => '/d/'])['redirect'];
            State::$recorded = [];

            self::assertSame('kit_redirect_not_found', $this->errorCode(Redirects\delete_rules(['ids' => [$a['id'], 'r' . str_repeat('0', 32)]])));
            self::assertCount(2, Redirects\read());

            $dry = Redirects\delete_rules(['ids' => [$a['id']], 'dry_run' => true]);
            self::assertSame([$a], $dry['would_delete']);
            self::assertCount(2, Redirects\read());

            $out = Redirects\delete_rules(['ids' => [$a['id'], $c['id']]]);
            self::assertCount(2, $out['deleted']);
            self::assertSame([], Redirects\read());
            self::assertSame('wppilot/redirect-delete', State::$recorded[0]['ability']);
            self::assertCount(2, State::$recorded[0]['items']);

            $restore = State::$strategies[Redirects\STRATEGY];
            self::assertTrue($restore(['snapshot' => State::$recorded[0]['items'][0]['before']])['verified']);
            self::assertSame([$a['id']], array_keys(Redirects\read()));
        }

        public function testUndoingADeleteIsRefusedWhenItWouldNowLoop(): void
        {
            $a = $this->create(['source' => '/a/', 'target' => '/b/'])['redirect'];
            Redirects\delete_rules(['ids' => [$a['id']]]);
            $before = State::$recorded[1]['items'][0]['before'];
            $this->create(['source' => '/b/', 'target' => '/a/']);

            self::assertSame('kit_redirect_loop', $this->errorCode((State::$strategies[Redirects\STRATEGY])(['snapshot' => $before])));
        }

        public function testDeleteStopsWhenNotConfirmed(): void
        {
            $a = $this->create(['source' => '/a/', 'target' => '/b/'])['redirect'];
            State::$confirmed = false;

            self::assertSame('kit_test_unconfirmed', $this->errorCode(Redirects\delete_rules(['ids' => [$a['id']]])));
            self::assertCount(1, Redirects\read());
            // A dry run needs no confirmation.
            self::assertTrue(Redirects\delete_rules(['ids' => [$a['id']], 'dry_run' => true])['dry_run']);
        }

        public function testAMovedUrlGetsAnAutomaticRedirectThatIsLedgered(): void
        {
            $out = Redirects\auto_redirect([['post_id' => 7, 'old' => 'https://example.test/about/', 'new' => 'https://example.test/about-us/']]);

            self::assertSame('created', $out[0]['action']);
            $rule = array_values(Redirects\read())[0];
            self::assertSame(['/about/', '/about-us/', 301, 'auto', 7], [$rule['source'], $rule['target'], $rule['type'], $rule['origin'], $rule['post_id']]);
            self::assertSame('wppilot/redirect-create', State::$recorded[0]['ability']);
            self::assertSame('auto', State::$recorded[0]['items'][0]['input']['origin']);
        }

        public function testMovingBackRemovesTheLoopAndEarlierAutoRedirectsArePointedStraightAtTheNewUrl(): void
        {
            Redirects\auto_redirect([['post_id' => 7, 'old' => 'https://example.test/a/', 'new' => 'https://example.test/b/']]);
            Redirects\auto_redirect([['post_id' => 7, 'old' => 'https://example.test/b/', 'new' => 'https://example.test/c/']]);
            $targets = array_column(array_values(Redirects\read()), 'target', 'source');
            self::assertSame(['/a/' => '/c/', '/b/' => '/c/'], $targets);

            Redirects\auto_redirect([['post_id' => 7, 'old' => 'https://example.test/c/', 'new' => 'https://example.test/a/']]);
            $targets = array_column(array_values(Redirects\read()), 'target', 'source');
            ksort($targets);
            self::assertSame(['/b/' => '/a/', '/c/' => '/a/'], $targets);
            self::assertSame('/a/', Redirects\test_path(['path' => '/b/'])['final']);
        }

        public function testARedirectAPersonMadeIsNeverChangedByAMove(): void
        {
            $manual = $this->create(['source' => '/a/', 'target' => '/special/'])['redirect'];
            $out = Redirects\auto_redirect([['post_id' => 7, 'old' => 'https://example.test/a/', 'new' => 'https://example.test/b/']]);

            self::assertSame('conflict', $out[0]['action']);
            self::assertSame($manual, Redirects\read()[$manual['id']]);
        }

        public function testAutomaticRedirectsStayOffWhenFilteredOrMadeElsewhere(): void
        {
            add_filter(Redirects\AUTO_FILTER, static fn(): bool => false);
            self::assertSame('off', Redirects\auto_status());
            remove_all_filters(Redirects\AUTO_FILTER);

            State::$elsewhere = true;
            self::assertSame('elsewhere', Redirects\auto_status());
            self::assertSame('elsewhere', Redirects\list_rules([])['auto_redirects']);

            $before = new \WP_Post();
            $before->ID = 7;
            $before->post_status = 'publish';
            $before->post_name = 'a';
            $after = clone $before;
            $after->post_name = 'b';
            State::$links[7] = 'https://example.test/b/';
            Redirects\on_post_updated(7, $after, $before);
            self::assertSame([], Redirects\read());
        }

        public function testASavedPostThatMovedIsRedirectedFromTheUrlRememberedBeforeTheSave(): void
        {
            $post = new \WP_Post();
            $post->ID = 9;
            $post->post_type = 'page';
            $post->post_status = 'publish';
            $post->post_name = 'team';
            \Kit_Test_State::$posts[9] = $post;
            State::$links[9] = 'https://example.test/about/team/';

            Redirects\remember_old_urls(9, ['post_name' => 'people']);
            $after = clone $post;
            $after->post_name = 'people';
            State::$links[9] = 'https://example.test/about/people/';
            Redirects\on_post_updated(9, $after, $post);

            $rule = array_values(Redirects\read())[0];
            self::assertSame(['/about/team/', '/about/people/'], [$rule['source'], $rule['target']]);
            unset(\Kit_Test_State::$posts[9]);
        }

        public function testSuggestionsComeFromThe404LogAndSkipCoveredPaths(): void
        {
            NotFoundLog\record('/old-pricing/', '', 10);
            NotFoundLog\record('/blog/hello-world/', '', 20);
            NotFoundLog\record('/covered/', '', 30);
            NotFoundLog\record('/nonsense-xyz/', '', 40);
            $this->create(['source' => '/covered/', 'target' => '/x/']);
            State::$slugs['old_slug:old-pricing'] = 11;
            State::$slugs['same_slug:hello-world'] = 12;
            State::$links[11] = 'https://example.test/pricing/';
            State::$links[12] = 'https://example.test/2026/hello-world/';
            State::$recorded = [];

            $out = Redirects\suggest([]);
            $by_source = array_column($out['suggestions'], null, 'source');
            self::assertSame(['/pricing/', 'old_slug', 11], [$by_source['/old-pricing/']['target'], $by_source['/old-pricing/']['reason'], $by_source['/old-pricing/']['post_id']]);
            self::assertSame(['/2026/hello-world/', 'same_slug'], [$by_source['/blog/hello-world/']['target'], $by_source['/blog/hello-world/']['reason']]);
            self::assertArrayNotHasKey('/covered/', $by_source);
            self::assertSame(['/nonsense-xyz/'], array_column($out['unmatched'], 'path'));
            self::assertSame([], State::$recorded);
        }

        public function testAnExtensionCanAddAMatchKindToTheSameStore(): void
        {
            add_filter(Redirects\MATCH_TYPES_FILTER, static fn(array $kinds): array => array_merge($kinds, ['regex']));
            add_filter(Redirects\MATCH_FILTER, static function (?string $location, array $rule, string $path): ?string {
                return $rule['match'] === 'regex' && preg_match('#' . $rule['source'] . '#', $path) === 1 ? $rule['target'] : $location;
            }, 10, 3);

            $this->create(['source' => '/p/[0-9]+', 'target' => '/products/', 'match' => 'regex']);
            self::assertSame('/products/', Redirects\find('/p/123', Redirects\read())['location']);

            remove_all_filters(Redirects\MATCH_TYPES_FILTER);
            self::assertSame('kit_redirect_bad_match', $this->errorCode(Redirects\create_rule(['source' => '/q/', 'target' => '/x/', 'match' => 'regex'])));
        }

        public function testTheStoreIsCapped(): void
        {
            $rules = [];
            for ($i = 0; $i < Redirects\MAX_RULES; $i++) {
                $id = 'r' . str_pad((string) $i, 32, '0', STR_PAD_LEFT);
                $rules[$id] = ['id' => $id, 'source' => '/p' . $i, 'match' => 'exact', 'target' => '/t/', 'type' => 301, 'origin' => 'manual', 'post_id' => 0, 'created' => 0];
            }
            Redirects\write($rules);

            self::assertSame('kit_redirect_full', $this->errorCode(Redirects\create_rule(['source' => '/new/', 'target' => '/t/'])));
            self::assertSame('full', Redirects\auto_redirect([['post_id' => 1, 'old' => 'https://example.test/x/', 'new' => 'https://example.test/y/']])[0]['action']);
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
                    return ['group' => 'g', 'change_ids' => array_map(static fn(int $i): string => 'c' . ($i + 1), array_keys($items)), 'without_before_image' => 0];
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
                    return 'standard';
                }

                public function ledger(): Ledger
                {
                    return $this->ledger;
                }

                public function jobs(): Jobs
                {
                    throw new \LogicException('No jobs in these tests.');
                }

                public function extension(string $point): mixed
                {
                    return $point === 'auto-redirects-elsewhere' ? State::$elsewhere : null;
                }

                public function admin_parent_slug(): string
                {
                    return 'tools.php';
                }

                public function confirm_guard(string $ability_name, array $input): bool|WP_Error
                {
                    return State::$confirmed ? true : new WP_Error('kit_test_unconfirmed', 'Not confirmed.');
                }
            };
        }
    }
}

namespace WPPilot\Kits\Redirects {
    use WPPilot\Tests\Unit\Kits\Redirects\State;

    if (!function_exists(__NAMESPACE__ . '\\wp_register_ability')) {
        /** @param array<string, mixed> $args */
        function wp_register_ability(string $name, array $args): mixed
        {
            State::$registered[$name] = $args;
            return \wp_register_ability($name, $args);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\get_permalink')) {
        function get_permalink(mixed $post): string|false
        {
            $id = $post instanceof \WP_Post ? $post->ID : (int) $post;
            return State::$links[$id] ?? false;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\get_the_title')) {
        function get_the_title(mixed $post): string
        {
            return 'Post ' . (int) $post;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\get_posts')) {
        /**
         * @param array<string, mixed> $args
         * @return list<int>
         */
        function get_posts(array $args): array
        {
            $reason = isset($args['meta_key']) ? 'old_slug' : 'same_slug';
            $slug = (string) ($args['meta_value'] ?? $args['name'] ?? '');
            return isset(State::$slugs[$reason . ':' . $slug]) ? [State::$slugs[$reason . ':' . $slug]] : [];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\get_post_status')) {
        function get_post_status(int $post_id): string|false
        {
            $post = \Kit_Test_State::$posts[$post_id] ?? null;
            return $post instanceof \WP_Post ? $post->post_status : false;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\is_post_type_viewable')) {
        function is_post_type_viewable(string $post_type): bool
        {
            return in_array($post_type, ['post', 'page'], true);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\is_post_type_hierarchical')) {
        function is_post_type_hierarchical(string $post_type): bool
        {
            return $post_type === 'page';
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\get_pages')) {
        /**
         * @param array<string, mixed> $args
         * @return list<\WP_Post>
         */
        function get_pages(array $args): array
        {
            return [];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_is_post_revision')) {
        function wp_is_post_revision(int $post_id): bool
        {
            return false;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_is_post_autosave')) {
        function wp_is_post_autosave(int $post_id): bool
        {
            return false;
        }
    }
}
