<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\BotAccess;

use PHPUnit\Framework\TestCase;
use WPPilot\Kits\BotAccess as B;

/**
 * The bot-access kit: robots.txt read the way RFC 9309 reads it (group merging, the `*`
 * fallback, longest match with Allow winning a tie, wildcards), Cloudflare's managed block, and
 * access-log scans that keep only crawler names, counts and times.
 */
final class BotAccessTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $kit = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        self::$kit = require dirname(__DIR__, 4) . '/includes/kits/bot-access/bootstrap.php';
    }

    public function testTheKitDeclaresOneReadOnlyAbility(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/bot-access/kit.json'), true);
        self::assertSame(['wppilot/bot-access-report'], array_column($manifest['abilities'], 'name'));
        self::assertTrue($manifest['abilities'][0]['readonly']);
        self::assertFalse($manifest['abilities'][0]['destructive']);
        self::assertCount(1, self::$kit['ability_files']);
    }

    public function testANamedGroupOverridesTheStarGroup(): void
    {
        $groups = B\parse_robots("User-agent: *\nDisallow: /wp-admin/\n\nUser-agent: GPTBot\nDisallow: /\n");

        self::assertSame('blocked', B\evaluate($groups, 'GPTBot')['access']);
        self::assertSame('gptbot', B\evaluate($groups, 'GPTBot')['group']);
        self::assertSame('partial', B\evaluate($groups, 'ClaudeBot')['access']);
        self::assertSame('*', B\evaluate($groups, 'ClaudeBot')['group']);
    }

    public function testConsecutiveUserAgentsShareOneGroupAndCaseIsIgnored(): void
    {
        $groups = B\parse_robots("user-agent: ClaudeBot\nUSER-AGENT: perplexitybot # both\ndisallow: /\nSitemap: https://example.com/sitemap.xml\n");

        self::assertCount(1, $groups);
        self::assertSame('blocked', B\evaluate($groups, 'ClaudeBot')['access']);
        self::assertSame('blocked', B\evaluate($groups, 'PerplexityBot')['access']);
        self::assertSame('allowed', B\evaluate($groups, 'Googlebot')['access'], 'no * group: everything else is allowed');
    }

    public function testLongestMatchWinsAndAllowWinsATie(): void
    {
        self::assertSame('partial', B\evaluate(B\parse_robots("User-agent: *\nDisallow: /\nAllow: /\n"), 'Bingbot')['access']);
        self::assertSame('partial', B\evaluate(B\parse_robots("User-agent: *\nDisallow: /\nAllow: /$\n"), 'Bingbot')['access'], 'Allow: /$ is longer and opens the home page');
        self::assertSame('blocked', B\evaluate(B\parse_robots("User-agent: *\nDisallow: /*\n"), 'Bingbot')['access']);
        self::assertSame('allowed', B\evaluate(B\parse_robots("User-agent: *\nDisallow:\n"), 'Bingbot')['access'], 'an empty Disallow allows everything');
    }

    public function testRepeatedGroupsForOneAgentAreMerged(): void
    {
        $groups = B\parse_robots("User-agent: Googlebot\nDisallow: /private/\n\nUser-agent: Bingbot\nAllow: /\n\nUser-agent: Googlebot\nDisallow: /\n");

        self::assertSame('blocked', B\evaluate($groups, 'Googlebot')['access']);
        self::assertSame(2, B\evaluate($groups, 'Googlebot')['disallow_rules']);
    }

    public function testWordPressDefaultRobotsLeaveEveryCrawlerIn(): void
    {
        $groups = B\parse_robots("User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n");

        foreach (B\crawlers() as $crawler) {
            self::assertNotSame('blocked', B\evaluate($groups, $crawler['token'])['access'], $crawler['token']);
        }
    }

    public function testCloudflareManagedBlockIsRecognised(): void
    {
        self::assertTrue(B\cloudflare_managed("# BEGIN Cloudflare Managed content\nUser-agent: GPTBot\nDisallow: /\n# END Cloudflare Managed Content\n"));
        self::assertFalse(B\cloudflare_managed("User-agent: *\nDisallow:\n"));
    }

    public function testLogScanKeepsOnlyNamesCountsAndTimes(): void
    {
        $seen = B\scan_log_lines([
            '203.0.113.9 - - [10/Oct/2026:13:55:36 +0000] "GET /secret-page/ HTTP/1.1" 200 512 "-" "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)"',
            '203.0.113.9 - - [11/Oct/2026:09:00:00 +0200] "GET / HTTP/1.1" 200 512 "-" "Mozilla/5.0 (compatible; GPTBot/1.2)"',
            '198.51.100.4 - - [11/Oct/2026:10:00:00 +0000] "GET / HTTP/1.1" 200 512 "-" "Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)"',
            '198.51.100.5 - - [11/Oct/2026:10:00:01 +0000] "GET / HTTP/1.1" 200 512 "-" "Mozilla/5.0 (Windows NT 10.0)"',
        ]);

        self::assertSame(['hits' => 2, 'last_seen' => '2026-10-11T07:00:00Z'], $seen['GPTBot']);
        self::assertSame(1, $seen['ClaudeBot']['hits']);
        self::assertCount(2, $seen);
        self::assertStringNotContainsString('203.0.113.9', (string) json_encode($seen));
        self::assertStringNotContainsString('secret-page', (string) json_encode($seen));
    }

    public function testTailDropsTheCutFirstLine(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bot');
        file_put_contents($file, "first line that gets cut\nsecond\nthird\n");
        try {
            self::assertSame(['second', 'third'], B\tail_lines($file, 16));
            self::assertSame(['first line that gets cut', 'second', 'third'], B\tail_lines($file, 1000));
        } finally {
            unlink($file);
        }
    }

    public function testLogPathsComeFromFixedLocationsAndTheFilterOnly(): void
    {
        add_filter(B\LOG_PATHS_FILTER, static fn(array $paths): array => array_merge($paths, ['/srv/custom/access.log', 42]));
        try {
            $paths = B\log_candidates();
        } finally {
            remove_all_filters(B\LOG_PATHS_FILTER);
        }

        self::assertContains('/srv/custom/access.log', $paths);
        self::assertContains('/var/log/nginx/access.log', $paths);
        foreach ($paths as $path) {
            self::assertIsString($path);
        }
    }
}
