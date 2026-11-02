<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Tests\Unit\Kits\SecurityIntegrity;

require_once __DIR__ . '/harness.php';

use GoBuildWP\Kits\SecurityIntegrity as SI;
use GoBuildWP\Kits\SecurityIntegrity\TestNet;
use PHPUnit\Framework\TestCase;

/**
 * WordPress.org theme checks (zip hashing, which themes count as WordPress.org ones, the compare)
 * and the deep scan of files without published checksums. Theme zips are built in the test and
 * handed out as scripted responses; nothing reaches the network.
 */
final class ThemeDeepTest extends TestCase
{
    private Fixture $site;

    /** @var list<string> */
    private array $zips = [];

    protected function setUp(): void
    {
        if (!class_exists('ZipArchive')) {
            self::markTestSkipped('ZipArchive is not available.');
        }
        reset_state();
        $this->site = new Fixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->site)) {
            $this->site->remove();
        }
        reset_state();
        unset($GLOBALS['wppilot_test_themes']);
        foreach ($this->zips as $zip) {
            @unlink($zip);
        }
    }

    /**
     * Malicious snippets, assembled so this file does not itself match the rules. Only ones that
     * desktop antivirus leaves alone on disk.
     */
    private static function evil(string $which): string
    {
        return match ($which) {
            'shell_request' => '<?php ' . 'sys' . 'tem($_' . 'GET["c"]);',
            'js_atob' => 'ev' . 'al(at' . 'ob("YWxlcnQoMSk="));',
        };
    }

    /**
     * A zip file built from name => contents (a name ending in / is a folder entry).
     *
     * @param array<string, string> $entries
     */
    private function zip(array $entries): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'kit-si-zip-');
        $this->zips[] = $file;
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $body) {
            if (str_ends_with($name, '/')) {
                $zip->addEmptyDir(rtrim($name, '/'));
            } else {
                $zip->addFromString($name, $body);
            }
        }
        $zip->close();
        return $file;
    }

    /** @param array<string, string> $entries */
    private function zip_response(array $entries, int $code = 200): array
    {
        return ['response' => ['code' => $code], 'body' => (string) file_get_contents($this->zip($entries))];
    }

    /** @param array<string, object|array<string, string>> $no_update */
    private static function update_check(array $no_update): void
    {
        TestNet::$site_transients['update_themes'] = (object) ['response' => [], 'no_update' => $no_update];
    }

    private static function wporg_entry(string $slug): object
    {
        return (object) ['theme' => $slug, 'url' => 'https://wordpress.org/themes/' . $slug . '/', 'package' => 'https://downloads.wordpress.org/theme/' . $slug . '.1.0.zip'];
    }

    public function test_zip_entries_are_hashed_inside_the_theme_folder_only(): void
    {
        $file = $this->zip([
            'twentyx/' => '',
            'twentyx/style.css' => 'body{}',
            'twentyx/inc/' => '',
            'twentyx/inc/a.php' => '<?php // a',
            'twentyx/../escape.php' => '<?php',
            'twentyx/./dot.php' => '<?php',
            'twentyx/a\\b.php' => '<?php',
            'other/x.php' => '<?php',
            '/abs.php' => '<?php',
        ]);
        self::assertSame([
            'inc/a.php' => [hash('sha256', '<?php // a')],
            'style.css' => [hash('sha256', 'body{}')],
        ], SI\zip_checksums($file, 'twentyx'));
        self::assertSame([], SI\zip_checksums($file, 'missing'));
        file_put_contents($file, 'not a zip');
        self::assertNull(SI\zip_checksums($file, 'twentyx'));
    }

    public function test_safe_zip_paths(): void
    {
        foreach (['style.css', 'inc/a.php', 'a/b/c.js'] as $path) {
            self::assertTrue(SI\safe_zip_path($path), $path);
        }
        foreach (['', '../x', 'a/../x', './x', 'a//b', '/abs', 'C:/x', 'a\\b', "a\0b", 'a/', str_repeat('a', 256)] as $path) {
            self::assertFalse(SI\safe_zip_path($path), $path);
        }
    }

    public function test_theme_checksums_cache_a_200_but_not_a_network_failure(): void
    {
        $budget = new SI\Budget(SI\budgets(), 30);
        TestNet::$responses[] = new \WP_Error('http_request_failed', 'down');
        self::assertSame([], SI\theme_checksums('twentyx', '1.0', $budget));
        self::assertArrayNotHasKey(SI\theme_transient('twentyx', '1.0'), TestNet::$transients);

        TestNet::$responses[] = $this->zip_response(['twentyx/style.css' => 'body{}']);
        $sums = ['style.css' => [hash('sha256', 'body{}')]];
        self::assertSame($sums, SI\theme_checksums('twentyx', '1.0', $budget));
        self::assertSame($sums, TestNet::$transients[SI\theme_transient('twentyx', '1.0')]);
        self::assertSame($sums, SI\theme_checksums('twentyx', '1.0', $budget), 'served from the transient');
        self::assertCount(2, TestNet::$calls);
        self::assertSame('https://downloads.wordpress.org/theme/twentyx.1.0.zip', TestNet::$calls[1]);

        TestNet::$responses[] = ['response' => ['code' => 404], 'body' => 'Not found'];
        self::assertSame([], SI\theme_checksums('gone', '1.0', $budget));
        self::assertSame([], TestNet::$transients[SI\theme_transient('gone', '1.0')], 'a 404 is cached as "none published"');

        TestNet::$responses[] = ['response' => ['code' => 200], 'body' => ''];
        self::assertSame([], SI\theme_checksums('empty', '1.0', $budget));
        self::assertArrayNotHasKey(SI\theme_transient('empty', '1.0'), TestNet::$transients, 'an empty download is not cached');

        self::assertSame([], SI\theme_checksums('../bad', '1.0', $budget));
        self::assertSame([], SI\theme_checksums('twentyx', '1.0/../x', $budget));
        self::assertCount(4, TestNet::$calls, 'bad names never reach the network');
        self::assertSame([], glob(sys_get_temp_dir() . '/gbwp-si-*') ?: [], 'downloads are deleted');
    }

    public function test_theme_checksums_stop_when_the_fetch_budget_is_spent(): void
    {
        $budget = new SI\Budget(SI\budgets(), 30);
        $budget->fetches = $budget->limits['fetches'];
        self::assertFalse(SI\theme_checksums('twentyx', '1.0', $budget));
        self::assertSame([], TestNet::$calls);
    }

    public function test_wordpress_org_themes_are_told_by_the_update_check(): void
    {
        self::update_check([
            'twentyx' => self::wporg_entry('twentyx'),
            'listed' => ['theme' => 'listed', 'url' => 'https://wordpress.org/themes/listed/', 'package' => ''],
            'premium' => (object) ['theme' => 'premium', 'url' => 'https://wordpress.org/themes/premium/', 'package' => 'https://vendor.example/premium.zip'],
            'bare' => (object) ['theme' => 'bare'],
        ]);
        self::assertTrue(SI\is_wporg_theme('twentyx'));
        self::assertTrue(SI\is_wporg_theme('listed'));
        self::assertFalse(SI\is_wporg_theme('premium'), 'a package from elsewhere');
        self::assertFalse(SI\is_wporg_theme('bare'), 'nothing says WordPress.org');
        self::assertFalse(SI\is_wporg_theme('custom'));

        // An empty check keeps the last answer; one that answers replaces it.
        unset(TestNet::$site_transients['update_themes']);
        self::assertTrue(SI\is_wporg_theme('twentyx'));
        self::update_check(['twentyx' => (object) ['theme' => 'twentyx', 'package' => 'https://vendor.example/twentyx.zip']]);
        self::assertFalse(SI\is_wporg_theme('twentyx'));
    }

    public function test_wordpress_org_themes_are_compared_and_the_rest_listed(): void
    {
        $style = "/* Theme Name: X */\n";
        $this->site->put('wp-content/themes/twentyx/style.css', $style);
        $this->site->put('wp-content/themes/twentyx/functions.php', '<?php // changed');
        $this->site->put('wp-content/themes/twentyx/backdoor.php', self::evil('shell_request'));
        $this->site->put('wp-content/themes/twentyx/readme.txt', 'local notes');
        $this->site->put('wp-content/themes/agency/style.css', '/* Theme Name: Agency */');
        $this->site->put('wp-content/themes/child/style.css', '/* Theme Name: Child */');
        $this->site->put('wp-content/themes/index.php', "<?php\n// Silence is golden.\n");
        $GLOBALS['wppilot_test_themes'] = [
            'twentyx' => ['Name' => 'X', 'Version' => '1.0'],
            'agency' => ['Name' => 'Agency', 'Version' => '2.0', 'UpdateURI' => 'https://vendor.example/agency'],
            'child' => ['Name' => 'Child', 'Version' => '1.0', 'Template' => 'twentyx'],
        ];
        // A premium theme whose updater adds a WordPress.org-looking entry is still left out by its Update URI.
        self::update_check(['twentyx' => self::wporg_entry('twentyx'), 'agency' => self::wporg_entry('agency')]);
        TestNet::$responses[] = $this->zip_response([
            'twentyx/style.css' => $style,
            'twentyx/functions.php' => '<?php // original',
            'twentyx/gone.php' => '<?php // gone',
        ]);
        $run = run_files(['scope' => 'themes']);
        self::assertSame(1, $run['steps']);
        self::assertSame([
            'no_checksum wp-content/themes/agency',
            'no_checksum wp-content/themes/child',
            'susp.shell_request wp-content/themes/twentyx/backdoor.php',
            'theme.mismatch wp-content/themes/twentyx/functions.php',
            'theme.missing wp-content/themes/twentyx/gone.php',
            'theme.unexpected wp-content/themes/twentyx/backdoor.php',
        ], summary($run['items']));
        $mismatch = array_values(array_filter($run['items'], static fn(array $i): bool => $i['rule_id'] === 'theme.mismatch'))[0];
        self::assertSame(['type' => 'theme', 'slug' => 'twentyx', 'version' => '1.0'], $mismatch['component']);
        $coverage = $run['state']['coverage']['themes'];
        self::assertSame('partial', $coverage['state']);
        self::assertSame(1, $coverage['compared']);
        self::assertSame(2, $coverage['unverified']);
        self::assertSame('skipped', $run['state']['coverage']['deep']['state']);
        self::assertCount(1, TestNet::$calls, 'one download for the one WordPress.org theme');
    }

    public function test_themes_are_complete_when_every_one_is_compared(): void
    {
        $this->site->put('wp-content/themes/twentyx/style.css', 'body{}');
        $GLOBALS['wppilot_test_themes'] = ['twentyx' => ['Name' => 'X', 'Version' => '1.0']];
        self::update_check(['twentyx' => self::wporg_entry('twentyx')]);
        TestNet::$responses[] = $this->zip_response(['twentyx/style.css' => 'body{}']);
        $run = run_files(['scope' => 'themes']);
        self::assertSame([], $run['items']);
        self::assertSame('checked', $run['state']['coverage']['themes']['state']);
    }

    public function test_deep_scan_reads_files_without_published_checksums(): void
    {
        $this->site->put('wp-content/plugins/premium/premium.php', '<?php /* Plugin Name: Premium */');
        $this->site->put('wp-content/plugins/premium/assets/app.js', self::evil('js_atob'));
        $this->site->put('wp-content/plugins/premium/assets/editor.js', 'var tpl = "<?php echo 1; ?>";');
        $this->site->put('wp-content/plugins/premium/assets/big.min.js', self::evil('js_atob') . str_repeat(' ', 70 * 1024));
        $this->site->put('wp-content/plugins/premium/inc/loader.php', self::evil('shell_request'));
        $this->site->put('wp-content/mu-plugins/loader.php', '<?php // loader');
        $this->site->put('wp-content/mu-plugins/vendor/inner.php', self::evil('shell_request'));
        $this->site->put('wp-content/object-cache.php', self::evil('shell_request'));
        $this->site->put('wp-content/index.php', "<?php\n// Silence is golden.\n");
        $this->site->put('wp-content/themes/custom/functions.php', self::evil('shell_request'));
        $this->site->put('wp-content/themes/custom/js/site.js', 'console.log(1);');
        TestNet::$plugins = ['premium/premium.php' => ['Version' => '3.0']];
        $GLOBALS['wppilot_test_themes'] = ['custom' => ['Name' => 'Custom', 'Version' => '1.0']];

        $plain = run_files(['scope' => 'all']);
        self::assertSame('skipped', $plain['state']['coverage']['deep']['state']);
        self::assertSame([
            'no_checksum wp-content/mu-plugins/loader.php',
            'no_checksum wp-content/plugins/premium',
            'no_checksum wp-content/themes/custom',
        ], array_values(array_filter(summary($plain['items']), static fn(string $s): bool => !str_starts_with($s, 'core'))));

        reset_state();
        TestNet::$plugins = ['premium/premium.php' => ['Version' => '3.0']];
        $deep = run_files(['scope' => 'all', 'deep' => true]);
        self::assertSame([
            'no_checksum wp-content/mu-plugins/loader.php',
            'no_checksum wp-content/plugins/premium',
            'no_checksum wp-content/themes/custom',
            'susp.js_eval_atob wp-content/plugins/premium/assets/app.js',
            'susp.shell_request wp-content/mu-plugins/vendor/inner.php',
            'susp.shell_request wp-content/object-cache.php',
            'susp.shell_request wp-content/plugins/premium/inc/loader.php',
            'susp.shell_request wp-content/themes/custom/functions.php',
        ], array_values(array_filter(summary($deep['items']), static fn(string $s): bool => !str_starts_with($s, 'core'))));
        foreach ($deep['items'] as $item) {
            if ($item['result'] === 'suspicious') {
                self::assertSame(['line'], array_keys((array) $item['detail']), 'rule id and line only');
            }
        }
        $coverage = $deep['state']['coverage']['deep'];
        self::assertSame('checked', $coverage['state']);
        self::assertSame(1, $coverage['skipped'], 'the large minified script');
        self::assertSame(9, $coverage['checked']);
    }

    public function test_deep_scan_reads_php_in_wp_content_folders_and_cache_folders(): void
    {
        $this->site->put('wp-content/cache/object/x1.php', self::evil('shell_request'));
        $this->site->put('wp-content/cache/page/index.html', self::evil('shell_request'));
        $this->site->put('wp-content/hidden-dir/a.php', self::evil('shell_request'));
        $this->site->put('wp-content/upgrade/tmp/b.php.jpg', self::evil('shell_request'));
        $this->site->put('wp-content/languages/plugins/x-en_US.l10n.php', "<?php
return ['messages' => []];
");
        $this->site->put('wp-content/.git/hooks/c.php', self::evil('shell_request'));
        $this->site->put('wp-content/node_modules/pkg/d.php', self::evil('shell_request'));
        $this->site->put('wp-content/plugins/premium/premium.php', '<?php /* Plugin Name: Premium */');
        $this->site->put('wp-content/plugins/premium/cache/e.php', self::evil('shell_request'));
        $this->site->put('wp-content/plugins/premium/cache/notes.txt', self::evil('shell_request'));
        TestNet::$plugins = ['premium/premium.php' => ['Version' => '3.0']];

        $deep = run_files(['scope' => 'all', 'deep' => true]);
        $hits = array_values(array_filter(summary($deep['items']), static fn(string $s): bool => str_starts_with($s, 'susp.')));
        self::assertSame([
            'susp.shell_request wp-content/cache/object/x1.php',
            'susp.shell_request wp-content/hidden-dir/a.php',
            'susp.shell_request wp-content/plugins/premium/cache/e.php',
            'susp.shell_request wp-content/upgrade/tmp/b.php.jpg',
        ], $hits, 'PHP in cache, upgrade and unknown folders is read once; html, text, .git and node_modules are not');
    }

    public function test_deep_scan_needs_plugins_or_themes_in_scope(): void
    {
        $run = run_files(['scope' => 'uploads', 'deep' => true]);
        self::assertSame('skipped', $run['state']['coverage']['deep']['state']);
        $run = run_files(['scope' => 'themes', 'deep' => true]);
        self::assertSame('checked', $run['state']['coverage']['deep']['state']);
    }

    public function test_a_small_budget_resumes_a_deep_scan_to_the_same_results(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->site->put('wp-content/plugins/premium/f' . $i . '.php', $i === 3 ? self::evil('shell_request') : '<?php // ' . $i);
            $this->site->put('wp-content/mu-plugins/m/f' . $i . '.php', '<?php // ' . $i);
        }
        $this->site->put('wp-content/mu-plugins/m.php', '<?php // loader');
        TestNet::$plugins = ['premium/f0.php' => ['Version' => '1.0']];
        $whole = run_files(['scope' => 'plugins', 'deep' => true]);

        reset_state();
        TestNet::$plugins = ['premium/f0.php' => ['Version' => '1.0']];
        add_filter('gobuildwp_kit_security_integrity_budgets', static fn(array $b): array => ['files' => 2] + $b);
        $steps = run_files(['scope' => 'plugins', 'deep' => true], 40);
        self::assertGreaterThan(1, $steps['steps']);
        self::assertSame(summary($whole['items']), summary($steps['items']));
        self::assertSame($whole['state']['coverage']['deep']['checked'], $steps['state']['coverage']['deep']['checked']);
    }
}
