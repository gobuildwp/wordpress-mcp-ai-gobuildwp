<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\NotFoundLog {
    use PHPUnit\Framework\TestCase;
    use WPPilot\Kits\NotFoundLog;

    final class State
    {
        public static bool $is404 = true;

        /** @var array<string, array<string, mixed>> */
        public static array $registered = [];
    }

    /**
     * The not-found-log kit: what a 404 records, what it never keeps, the cap, and the ability's output.
     */
    final class NotFoundLogTest extends TestCase
    {
        /** @var array<string, mixed> */
        private array $savedServer = [];

        public static function setUpBeforeClass(): void
        {
            require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
            $kit = require dirname(__DIR__, 4) . '/includes/kits/not-found-log/bootstrap.php';
            foreach ($kit['ability_files'] as $file) {
                require_once $file;
            }
        }

        protected function setUp(): void
        {
            unset(\WPPilot_Test_State::$options[NotFoundLog\OPTION]);
            State::$is404 = true;
            $this->savedServer = $_SERVER;
            remove_all_filters(NotFoundLog\ENABLED_FILTER);
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->savedServer;
            remove_all_filters(NotFoundLog\ENABLED_FILTER);
        }

        public function testTheAbilityIsRegisteredReadOnlyAsDeclared(): void
        {
            $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/not-found-log/kit.json'), true);
            self::assertSame('wppilot/not-found-log', $manifest['abilities'][0]['name']);
            self::assertTrue($manifest['abilities'][0]['readonly']);
            $annotations = State::$registered['wppilot/not-found-log']['meta']['annotations'];
            self::assertTrue($annotations['readonly']);
            self::assertFalse($annotations['destructive']);
        }

        public function testA404RecordsThePathWithoutQueryAndOnlyTheReferrerHost(): void
        {
            $_SERVER['REQUEST_URI'] = '/old-page/?email=someone%40example.com#x';
            $_SERVER['HTTP_REFERER'] = 'https://News.Example.org/story?id=7';

            NotFoundLog\on_template_redirect();

            $out = NotFoundLog\entries([]);
            self::assertSame([['path' => '/old-page/', 'hits' => 1, 'referrer' => 'news.example.org', 'last_seen' => $out['entries'][0]['last_seen']]], $out['entries']);
            self::assertStringNotContainsString('someone', serialize(get_option(NotFoundLog\OPTION)));
        }

        public function testAPageThatExistsIsNotRecorded(): void
        {
            State::$is404 = false;
            $_SERVER['REQUEST_URI'] = '/about/';

            NotFoundLog\on_template_redirect();

            self::assertSame([], NotFoundLog\entries([])['entries']);
        }

        public function testTheFilterTurnsRecordingOff(): void
        {
            add_filter(NotFoundLog\ENABLED_FILTER, static fn(): bool => false);
            $_SERVER['REQUEST_URI'] = '/missing/';

            NotFoundLog\on_template_redirect();

            self::assertSame([], NotFoundLog\entries([])['entries']);
            self::assertFalse(NotFoundLog\entries([])['recording']);
        }

        public function testRepeatHitsRollUpAndTheSameSecondIsNotWrittenTwice(): void
        {
            NotFoundLog\record('/gone', '', 1000);
            NotFoundLog\record('/gone', '', 1000);
            NotFoundLog\record('/gone', 'https://a.example/', 1001);
            NotFoundLog\record('/other', '', 1002);

            $entries = NotFoundLog\entries([])['entries'];
            self::assertSame(['/gone', '/other'], array_column($entries, 'path'), 'most-hit first');
            self::assertSame(2, $entries[0]['hits']);
            self::assertSame('a.example', $entries[0]['referrer']);
            self::assertSame(1001, $entries[0]['last_seen']);
        }

        public function testAScanOfNewPathsWritesTheLogAtMostOncePerSecond(): void
        {
            for ($i = 0; $i < 50; $i++) {
                NotFoundLog\record("/scan{$i}", '', 2000);
            }
            NotFoundLog\record('/scan0', '', 2000);
            NotFoundLog\record('/next-second', '', 2001);

            // Fifty new paths in one second: only the first was written.
            self::assertSame(['/scan0', '/next-second'], array_keys(NotFoundLog\read()));
            self::assertSame(1, NotFoundLog\read()['/scan0']['hits']);
        }

        public function testAClockThatWentBackwardsDoesNotStopTheLog(): void
        {
            NotFoundLog\record('/later', '', 5000);
            NotFoundLog\record('/earlier', '', 4000);

            self::assertArrayHasKey('/earlier', NotFoundLog\read());
        }

        public function testTheLogIsCappedByReplacingTheLeastHitPath(): void
        {
            NotFoundLog\record('/popular', '', 1);
            NotFoundLog\record('/popular', '', 2);
            for ($i = 0; $i < NotFoundLog\MAX_PATHS; $i++) {
                NotFoundLog\record("/p{$i}", '', 10 + $i);
            }

            $log = NotFoundLog\read();
            self::assertCount(NotFoundLog\MAX_PATHS, $log);
            self::assertArrayHasKey('/popular', $log, 'a path with more hits survives');
            self::assertArrayNotHasKey('/p0', $log, 'the oldest single-hit path goes');
            self::assertArrayHasKey('/p' . (NotFoundLog\MAX_PATHS - 1), $log);
        }

        public function testControlCharactersAndLongPathsAreCleaned(): void
        {
            NotFoundLog\record("/a\x00b\nc", 'javascript:alert(1)', 5);
            NotFoundLog\record('/' . str_repeat('x', 500), '', 6);
            NotFoundLog\record('not-a-path', '', 7);

            $log = NotFoundLog\read();
            self::assertArrayHasKey('/abc', $log);
            self::assertNull($log['/abc']['referrer']);
            self::assertArrayHasKey('/' . str_repeat('x', NotFoundLog\MAX_PATH_LENGTH - 1), $log);
            self::assertCount(2, $log);
        }

        public function testTheAbilityHonoursItsLimit(): void
        {
            NotFoundLog\record('/a', '', 1);
            NotFoundLog\record('/b', '', 2);

            $out = NotFoundLog\entries(['limit' => 1]);
            self::assertCount(1, $out['entries']);
            self::assertSame(2, $out['tracked_paths']);
            self::assertSame(NotFoundLog\MAX_PATHS, $out['max_paths']);
        }

        public function testTheOptionIsNotAutoloaded(): void
        {
            NotFoundLog\record('/a', '', 1);

            self::assertArrayHasKey(NotFoundLog\OPTION, \WPPilot_Test_State::$options);
            self::assertFalse($GLOBALS['wppilot_test_option_autoload'][NotFoundLog\OPTION] ?? null);
        }
    }
}

namespace WPPilot\Kits\NotFoundLog {
    use WPPilot\Tests\Unit\Kits\NotFoundLog\State;

    function is_404(): bool
    {
        return State::$is404;
    }

    /** @param array<string, mixed> $args */
    function wp_register_ability(string $name, array $args): mixed
    {
        State::$registered[$name] = $args;
        return \wp_register_ability($name, $args);
    }
}
