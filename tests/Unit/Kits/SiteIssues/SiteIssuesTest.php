<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SiteIssues;

use PHPUnit\Framework\TestCase;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\SiteIssues as S;

/**
 * The site-issues kit: what a recorded fatal keeps and drops (paths, query strings, secrets,
 * stack traces), dedupe and the ring buffer, memory/time fatals not blamed on a plugin, the
 * filters other kits use, the report, and the Cloud summary's shape.
 */
final class SiteIssuesTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $kit = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once __DIR__ . '/doubles.php';
        Runtime\host(new IssuesHost());
        self::$kit = require dirname(__DIR__, 4) . '/includes/kits/site-issues/bootstrap.php';
    }

    protected function setUp(): void
    {
        delete_option(S\ERRORS_OPTION);
        remove_all_filters(S\COLLECT_FILTER);
        \SiteIssuesPaused::$plugins = null;
    }

    public function testTheKitDeclaresOneReadOnlyAbilityAndHooksTheRecorder(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/includes/kits/site-issues/kit.json'), true);
        self::assertSame(['wppilot/site-issues'], array_column($manifest['abilities'], 'name'));
        self::assertTrue($manifest['abilities'][0]['readonly']);
        self::assertCount(1, self::$kit['ability_files']);
        self::assertNotSame([], $GLOBALS['wp_filter']['wp_php_error_message'] ?? [], 'the fatal handler filter is hooked');
        self::assertNotSame([], $GLOBALS['wp_filter'][S\ERRORS_FILTER] ?? [], 'the errors filter is offered to other kits');
    }

    public function testAnUncaughtErrorIsStoredRelativeWithItsPluginAndNoTrace(): void
    {
        $file = ABSPATH . 'wp-content/plugins/foo/bar.php';
        $entry = S\record([
            'type' => E_ERROR,
            'message' => "Uncaught Error: Call to undefined function x() in {$file}:12\nStack trace:\n#0 " . ABSPATH . "wp-includes/class-wp-hook.php(324): foo()\n#1 {main}\n  thrown",
            'file' => $file,
            'line' => 12,
        ], '/contact-us/', 1_000);

        self::assertIsArray($entry);
        self::assertSame('Uncaught Error: Call to undefined function x() in wp-content/plugins/foo/bar.php:12', $entry['message']);
        self::assertSame('wp-content/plugins/foo/bar.php', $entry['file']);
        self::assertSame(12, $entry['line']);
        self::assertSame('foo', $entry['source']);
        self::assertSame('error', $entry['cause']);
        self::assertSame('E_ERROR', $entry['type']);
        self::assertSame('/contact-us/', $entry['url_path']);
        self::assertStringNotContainsString('Stack trace', $entry['message']);
        self::assertStringNotContainsString(ABSPATH, serialize(get_option(S\ERRORS_OPTION)));
        self::assertSame(
            'Uncaught Error: Call to undefined function x() in wp-content/plugins/foo/bar.php:12, plugin foo',
            S\describe($entry),
        );
    }

    public function testPathsOutsideTheRootKeepOnlyTheirNameAndSecretsAreMasked(): void
    {
        $entry = S\normalize_error([
            'type' => E_USER_ERROR,
            'message' => 'Failed with token=abc123SECRET and password: hunter2 in /home/acct/private/lib/thing.php:3',
            'file' => '/home/acct/private/lib/thing.php',
            'line' => 3,
        ]);
        self::assertSame('…/thing.php', $entry['file']);
        self::assertStringNotContainsString('/home/acct', $entry['message']);
        self::assertStringNotContainsString('abc123SECRET', $entry['message']);
        self::assertStringNotContainsString('hunter2', $entry['message']);
        self::assertSame('', $entry['source']);
    }

    public function testThemesMuPluginsAndCoreAreNamed(): void
    {
        self::assertSame('theme:twentytwentyfive', S\source_of('wp-content/themes/twentytwentyfive/functions.php'));
        self::assertSame('mu-plugin:zz-test', S\source_of('wp-content/mu-plugins/zz-test.php'));
        self::assertSame('core', S\source_of('wp-includes/plugin.php'));
        self::assertSame('core', S\source_of('wp-settings.php'));
        self::assertSame('', S\source_of('…/x.php'));
    }

    public function testQuotedCredentialValuesAreMaskedBeforeStorage(): void
    {
        $message = S\clean_message('token="private value" password=\'another secret\' api_key=unquotedSECRET');
        foreach (['private value', 'another secret', 'unquotedSECRET'] as $secret) {
            self::assertStringNotContainsString($secret, $message);
        }
        self::assertSame(3, substr_count($message, '[redacted]'));
    }

    public function testRunningOutOfMemoryBlamesNoPlugin(): void
    {
        $entry = S\normalize_error([
            'type' => E_ERROR,
            'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
            'file' => ABSPATH . 'wp-content/plugins/elementor/includes/autoloader.php',
            'line' => 300,
        ]);
        self::assertSame('memory', $entry['cause']);
        self::assertSame('', $entry['source'], 'the file is only where PHP stopped');
        self::assertStringContainsString('ran out of memory', S\describe($entry));
        self::assertStringNotContainsString('plugin elementor', S\describe($entry));
        self::assertSame('timeout', S\cause('Maximum execution time of 30 seconds exceeded'));
    }

    public function testRepeatsAreCountedAndAStormIsNotRewritten(): void
    {
        $error = ['type' => E_ERROR, 'message' => 'boom', 'file' => ABSPATH . 'wp-content/plugins/a/a.php', 'line' => 1];
        S\record($error, '/', 1_000);
        self::assertNull(S\record($error, '/', 1_002), 'a repeat within WRITE_EVERY seconds is not written');
        $second = S\record($error, '/shop/', 1_010);
        self::assertIsArray($second);
        self::assertSame(2, $second['count']);
        self::assertSame(1_000, $second['first_at']);
        self::assertSame(1_010, $second['last_at']);
        self::assertCount(1, get_option(S\ERRORS_OPTION));
    }

    public function testTheLogKeepsTheNewestFifty(): void
    {
        for ($i = 0; $i < S\MAX_ERRORS + 5; $i++) {
            S\record(['type' => E_ERROR, 'message' => 'error ' . $i, 'file' => '', 'line' => $i], '/', 1_000 + $i);
        }
        $stored = get_option(S\ERRORS_OPTION);
        self::assertCount(S\MAX_ERRORS, $stored);
        $newest = S\errors_since(0, 1);
        self::assertSame('error 54', $newest[0]['message']);
        self::assertSame([], array_filter($stored, static fn(array $e): bool => $e['message'] === 'error 0'));
    }

    public function testOtherKitsReadErrorsSinceATimeThroughTheFilter(): void
    {
        S\record(['type' => E_ERROR, 'message' => 'old', 'file' => '', 'line' => 1], '/', 1_000);
        S\record(['type' => E_ERROR, 'message' => 'new', 'file' => ABSPATH . 'wp-content/plugins/b/b.php', 'line' => 2], '/', 2_000);
        $errors = apply_filters(S\ERRORS_FILTER, [], 1_500);
        self::assertCount(1, $errors);
        self::assertSame('new', $errors[0]['message']);
        self::assertSame('new in wp-content/plugins/b/b.php:2, plugin b', $errors[0]['summary']);
    }

    public function testTheShutdownPathRecordsOnlyFatals(): void
    {
        // error_get_last() is whatever the last error was; a warning is not a fatal.
        @trigger_error('just a warning', E_USER_WARNING);
        S\on_shutdown();
        self::assertSame([], get_option(S\ERRORS_OPTION, []));
    }

    public function testTheReportCombinesErrorsPausedExtensionsAndContributedFailures(): void
    {
        $now = time();
        S\record(['type' => E_ERROR, 'message' => 'Uncaught TypeError: nope', 'file' => ABSPATH . 'wp-content/plugins/c/c.php', 'line' => 9], '/blog/', $now - 60);
        wp_paused_plugins()->paused['broken'] = ['type' => E_ERROR, 'message' => 'Paused because', 'file' => ABSPATH . 'wp-content/plugins/broken/broken.php', 'line' => 4];
        add_filter(S\COLLECT_FILTER, static function (array $items): array {
            $items[] = ['kind' => 'update_rolled_back', 'id' => 'abc123', 'at' => time() - 30, 'summary' => 'CMB2 2.13.4 rolled back: / went from 200 to 500', 'source' => 'cmb2'];
            $items[] = ['kind' => 'update_rolled_back', 'id' => 'old', 'at' => 5, 'summary' => 'too old'];
            $items[] = 'not an item';
            return $items;
        });

        $report = S\report([]);
        self::assertSame(['php_fatal' => 1, 'update_rolled_back' => 1, 'backup_failed' => 0, 'paused_extensions' => 1], $report['counts']);
        self::assertSame('/blog/', $report['php_errors'][0]['url_path']);
        self::assertSame('c', $report['php_errors'][0]['source']);
        self::assertSame('broken', $report['paused_extensions'][0]['slug']);
        self::assertSame('wp-content/plugins/broken/broken.php', $report['paused_extensions'][0]['file']);
        self::assertSame('abc123', $report['update_failures'][0]['id']);

        $summary = S\cloud_summary();
        self::assertSame($report['counts'], $summary['counts']);
        self::assertSame(gmdate('c', $now - 60), $summary['newest_fatal_at']);
        $kinds = array_column($summary['recent'], 'kind');
        sort($kinds);
        self::assertSame(['paused_extension', 'php_fatal', 'update_rolled_back'], $kinds);
        foreach ($summary['recent'] as $item) {
            self::assertSame(['id', 'kind', 'first_at', 'last_at', 'count', 'summary', 'source', 'file', 'line', 'url_path'], array_keys($item));
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', $item['last_at']);
            self::assertLessThanOrEqual(300 + 120, mb_strlen($item['summary']));
            self::assertStringNotContainsString(ABSPATH, json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    public function testTheSummaryOfAQuietSiteHasNoNewestFatal(): void
    {
        $summary = S\cloud_summary();
        self::assertNull($summary['newest_fatal_at']);
        self::assertSame([], $summary['recent']);
    }
}
