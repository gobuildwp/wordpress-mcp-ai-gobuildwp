<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Tests\Unit\Kits\SecurityIntegrity;

require_once __DIR__ . '/harness.php';

use GoBuildWP\Kits\SecurityIntegrity as SI;
use GoBuildWP\Kits\SecurityIntegrity\TestNet;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Runtime;
use Kit_Test_Site;

/**
 * File fixes against a throwaway WordPress folder: quarantine and its undo, core repair from a
 * checksum-verified download and its undo, the paths and files that are refused, and a batch
 * where one bad item doesn't stop the rest. Core checksums come from a primed transient and the
 * download from a scripted response; nothing reaches the network.
 */
final class FixTest extends TestCase
{
    private Fixture $site;

    private FakeWpdb $db;

    private mixed $previousWpdb = null;

    protected function setUp(): void
    {
        reset_state();
        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $this->db = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->db;
        Kit_Test_Site::as_user(1, 'manage_options');
        Runtime\host(new FakeHost());
        $this->site = new Fixture();
        SI\roots(SI\roots() + ['content' => $this->site->root . '/wp-content']);
        $this->site->dir('wp-content/uploads');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
        $GLOBALS['wpdb'] = $this->previousWpdb;
        reset_state();
    }

    /** @param array<string, string> $sums */
    private static function primeCore(array $sums): void
    {
        TestNet::$transients[SI\core_transient('7.0', 'en_US')] = $sums;
    }

    /** @return array<string, mixed> */
    private static function apply(array $items): array
    {
        $result = SI\apply_fixes(['items' => $items, 'reason' => 'test', 'confirm' => true]);
        self::assertIsArray($result, $result instanceof WP_Error ? $result->get_error_message() : '');
        return $result;
    }

    public function test_quarantine_moves_the_file_out_and_undo_puts_it_back(): void
    {
        $shell = $this->site->put('wp-content/uploads/2026/10/x.php', '<?php echo 1;');
        $result = self::apply([['action' => 'quarantine_file', 'target' => 'wp-content/uploads/2026/10/x.php']]);

        self::assertSame(1, $result['applied']);
        self::assertFileDoesNotExist($shell);
        $dir = $this->site->root . '/wp-content/' . get_option(SI\QUARANTINE_OPTION);
        self::assertFileExists($dir . '/.htaccess');
        self::assertFileExists($dir . '/index.php');
        $stored = glob($dir . '/*.quarantined') ?: [];
        self::assertCount(1, $stored);
        self::assertSame('<?php echo 1;', file_get_contents($stored[0]));

        $listed = SI\list_fixes();
        self::assertSame('applied', $listed[0]['state']);
        self::assertSame('test', $listed[0]['reason']);

        $undo = SI\undo_fixes(['fix_ids' => [$result['results'][0]['fix_id']], 'confirm' => true]);
        self::assertSame(1, $undo['undone']);
        self::assertSame('<?php echo 1;', file_get_contents($shell));
        self::assertSame([], glob($dir . '/*.quarantined') ?: []);
        self::assertSame('undone', SI\list_fixes()[0]['state']);

        $again = SI\undo_fixes(['fix_ids' => [$result['results'][0]['fix_id']], 'confirm' => true]);
        self::assertSame('gobuildwp_security_fix_already_undone', $again['results'][0]['error']['code']);
    }

    public function test_undo_refuses_a_quarantined_copy_that_changed(): void
    {
        $this->site->put('wp-content/uploads/y.php', '<?php echo 2;');
        $result = self::apply([['action' => 'quarantine_file', 'target' => 'wp-content/uploads/y.php']]);
        $dir = $this->site->root . '/wp-content/' . get_option(SI\QUARANTINE_OPTION);
        file_put_contents((glob($dir . '/*.quarantined') ?: [''])[0], 'tampered');

        $undo = SI\undo_fixes(['fix_ids' => [$result['results'][0]['fix_id']], 'confirm' => true]);
        self::assertSame(0, $undo['undone']);
        self::assertSame('gobuildwp_security_fix_changed', $undo['results'][0]['error']['code']);
        self::assertFileDoesNotExist($this->site->root . '/wp-content/uploads/y.php');
    }

    public function test_refused_targets(): void
    {
        $this->site->put('wp-config.php', '<?php');
        $this->site->put('wp-includes/version.php', '<?php');
        $this->site->put('wp-includes/extra.php', '<?php');
        self::primeCore(['wp-includes/version.php' => md5('<?php')]);
        $outside = dirname($this->site->root) . '/' . basename($this->site->root) . '-out.php';
        file_put_contents($outside, '<?php');
        try {
            $result = self::apply([
                ['action' => 'quarantine_file', 'target' => 'wp-config.php'],
                ['action' => 'quarantine_file', 'target' => 'wp-content/uploads/../../wp-config.php'],
                ['action' => 'quarantine_file', 'target' => '../' . basename($outside)],
                ['action' => 'quarantine_file', 'target' => 'wp-includes/version.php'],
                ['action' => 'quarantine_file', 'target' => 'wp-content/uploads/missing.php'],
                ['action' => 'repair_core_file', 'target' => 'wp-content/uploads/a.php'],
                ['action' => 'quarantine_file', 'target' => 'wp-includes/extra.php'],
            ]);
        } finally {
            @unlink($outside);
        }

        $codes = array_map(static fn(array $r): string => (string) ($r['error']['code'] ?? 'applied'), $result['results']);
        self::assertSame([
            'gobuildwp_security_fix_protected',
            'gobuildwp_security_fix_bad_path',
            'gobuildwp_security_fix_bad_path',
            'gobuildwp_security_fix_core_file',
            'gobuildwp_security_fix_bad_path',
            'gobuildwp_security_fix_not_core',
            'applied',
        ], $codes);
        self::assertSame(1, $result['applied']);
        self::assertSame(6, $result['refused']);
        self::assertFileExists($this->site->root . '/wp-config.php');
        self::assertFileDoesNotExist($this->site->root . '/wp-includes/extra.php');
    }

    public function test_repair_core_file_writes_the_verified_copy_and_undo_restores(): void
    {
        $official = "<?php\n\$wp_version = '7.0';\n";
        $file = $this->site->put('wp-includes/version.php', $official . '/* injected */');
        self::primeCore(['wp-includes/version.php' => md5($official)]);
        TestNet::$responses = [['response' => ['code' => 200], 'body' => $official]];

        $result = self::apply([['action' => 'repair_core_file', 'target' => 'wp-includes/version.php']]);

        self::assertSame(1, $result['applied'], json_encode($result) ?: '');
        self::assertSame('https://core.svn.wordpress.org/tags/7.0/wp-includes/version.php', TestNet::$calls[0]);
        self::assertSame($official, file_get_contents($file));

        $undo = SI\undo_fixes(['fix_ids' => [$result['results'][0]['fix_id']], 'confirm' => true]);
        self::assertSame(1, $undo['undone']);
        self::assertSame($official . '/* injected */', file_get_contents($file));
    }

    public function test_repair_restores_a_missing_core_file_and_refuses_a_bad_download(): void
    {
        $official = "<?php\n// login\n";
        $this->site->dir('wp-includes');
        self::primeCore(['wp-login.php' => md5($official), 'wp-includes/a.php' => md5('<?php')]);
        TestNet::$responses = [
            ['response' => ['code' => 200], 'body' => $official],
            ['response' => ['code' => 200], 'body' => 'not the file'],
        ];

        $result = self::apply([
            ['action' => 'repair_core_file', 'target' => 'wp-login.php'],
            ['action' => 'repair_core_file', 'target' => 'wp-includes/a.php'],
        ]);

        self::assertSame('applied', $result['results'][0]['state']);
        self::assertTrue($result['results'][0]['detail']['was_missing']);
        self::assertSame($official, file_get_contents($this->site->root . '/wp-login.php'));
        self::assertSame('gobuildwp_security_fix_checksum_mismatch', $result['results'][1]['error']['code']);
        self::assertFileDoesNotExist($this->site->root . '/wp-includes/a.php');

        SI\undo_fixes(['fix_ids' => [$result['results'][0]['fix_id']], 'confirm' => true]);
        self::assertFileDoesNotExist($this->site->root . '/wp-login.php');
    }

    public function test_repair_refuses_a_file_that_already_matches(): void
    {
        $this->site->put('wp-includes/version.php', '<?php');
        self::primeCore(['wp-includes/version.php' => md5('<?php')]);
        $result = self::apply([['action' => 'repair_core_file', 'target' => 'wp-includes/version.php']]);
        self::assertSame('gobuildwp_security_fix_unchanged', $result['results'][0]['error']['code']);
        self::assertSame([], TestNet::$calls);
    }

    public function test_bad_batches_are_refused_whole(): void
    {
        self::assertInstanceOf(WP_Error::class, SI\apply_fixes(['items' => []]));
        self::assertInstanceOf(WP_Error::class, SI\apply_fixes(['items' => array_fill(0, 26, ['action' => 'quarantine_file', 'target' => 'a'])]));
        $result = self::apply([['action' => 'delete_everything', 'target' => 'a']]);
        self::assertSame('gobuildwp_security_fix_bad_input', $result['results'][0]['error']['code']);
    }

    /** One injected statement, written in pieces so this file holds no live call. */
    private const INJECTED = 'wp_set_auth' . '_cookie((int) $_G' . 'ET["uid"]);';

    /** @return list<string> the error code of each result, or 'applied' */
    private static function codes(array $result): array
    {
        return array_map(static fn(array $r): string => (string) ($r['error']['code'] ?? 'applied'), $result['results']);
    }

    /** @return array<string, mixed> */
    private static function undo(array $result, int $index = 0): array
    {
        $undo = SI\undo_fixes(['fix_ids' => [$result['results'][$index]['fix_id']], 'confirm' => true]);
        self::assertIsArray($undo);
        return $undo;
    }

    /** A WordPress.org plugin with the given official files, path => content. @param array<string, string> $files */
    private static function primePlugin(string $slug, string $version, array $files): void
    {
        TestNet::$plugins = [$slug . '/' . $slug . '.php' => ['Version' => $version]];
        TestNet::$site_transients['update_plugins'] = (object) ['response' => [], 'no_update' => [$slug . '/' . $slug . '.php' => (object) ['id' => 'w.org/plugins/' . $slug]]];
        TestNet::$transients[SI\plugin_transient($slug, $version)] = array_map(static fn(string $body): array => [hash('sha256', $body)], $files);
    }

    /** @return list<string> */
    private function kept(): array
    {
        return glob($this->site->root . '/wp-content/' . get_option(SI\QUARANTINE_OPTION) . '/*.quarantined') ?: [];
    }

    public function test_repair_plugin_file_writes_the_official_copy_and_undo_restores(): void
    {
        $official = "<?php\n/* Plugin Name: Hello */\n";
        $inc = "<?php\nfunction hello_inc() {}\n";
        self::primePlugin('hello', '1.2', ['hello.php' => $official, 'inc/a.php' => $inc]);
        $file = $this->site->put('wp-content/plugins/hello/hello.php', $official . self::INJECTED);
        $this->site->dir('wp-content/plugins/hello/inc');
        TestNet::$responses = [
            ['response' => ['code' => 200], 'body' => $official],
            ['response' => ['code' => 404], 'body' => ''],
            ['response' => ['code' => 200], 'body' => $inc],
        ];

        $result = self::apply([
            ['action' => 'repair_plugin_file', 'target' => 'wp-content/plugins/hello/hello.php'],
            ['action' => 'repair_plugin_file', 'target' => 'wp-content/plugins/hello/inc/a.php'],
        ]);

        self::assertSame(['applied', 'applied'], self::codes($result), json_encode($result) ?: '');
        self::assertSame([
            'https://plugins.svn.wordpress.org/hello/tags/1.2/hello.php',
            'https://plugins.svn.wordpress.org/hello/tags/1.2/inc/a.php',
            'https://plugins.svn.wordpress.org/hello/trunk/inc/a.php',
        ], TestNet::$calls);
        self::assertSame($official, file_get_contents($file));
        $detail = $result['results'][0]['detail'];
        self::assertSame(['wp-content/plugins/hello/hello.php', 'hello', '1.2', hash('sha256', $official), false], [$detail['path'], $detail['plugin'], $detail['version'], $detail['sha256'], $detail['was_missing']]);
        self::assertTrue($result['results'][1]['detail']['was_missing']);

        self::assertSame(1, self::undo($result)['undone']);
        self::assertSame($official . self::INJECTED, file_get_contents($file));
        self::assertSame(1, self::undo($result, 1)['undone']);
        self::assertFileDoesNotExist($this->site->root . '/wp-content/plugins/hello/inc/a.php');
    }

    public function test_repair_plugin_file_refusals(): void
    {
        $official = "<?php\n/* Plugin Name: Hello */\n";
        self::primePlugin('hello', '1.2', ['hello.php' => $official, 'b.php' => '<?php']);
        TestNet::$plugins['mine/mine.php'] = ['Version' => '1.0'];
        $this->site->put('wp-content/plugins/hello/hello.php', $official);
        $this->site->put('wp-content/plugins/hello/extra.php', '<?php');
        $this->site->put('wp-content/plugins/hello/b.php', '<?php // changed');
        $this->site->put('wp-content/plugins/mine/mine.php', '<?php');
        $this->site->put('wp-content/uploads/u.php', '<?php');
        TestNet::$responses = [['response' => ['code' => 200], 'body' => 'not it'], ['response' => ['code' => 200], 'body' => 'nor this']];

        $result = self::apply([
            ['action' => 'repair_plugin_file', 'target' => 'wp-content/plugins/hello/hello.php'],
            ['action' => 'repair_plugin_file', 'target' => 'wp-content/plugins/hello/extra.php'],
            ['action' => 'repair_plugin_file', 'target' => 'wp-content/plugins/mine/mine.php'],
            ['action' => 'repair_plugin_file', 'target' => 'wp-content/uploads/u.php'],
            ['action' => 'repair_plugin_file', 'target' => 'wp-content/plugins/gone/gone.php'],
            ['action' => 'repair_plugin_file', 'target' => 'wp-content/plugins/hello/b.php'],
        ]);

        self::assertSame([
            'gobuildwp_security_fix_unchanged',
            'gobuildwp_security_fix_not_official',
            'gobuildwp_security_fix_not_official',
            'gobuildwp_security_fix_not_plugin',
            'gobuildwp_security_fix_not_plugin',
            'gobuildwp_security_fix_checksum_mismatch',
        ], self::codes($result));
        self::assertSame('<?php // changed', file_get_contents($this->site->root . '/wp-content/plugins/hello/b.php'));
    }

    public function test_clean_file_strips_the_injected_line_and_undo_restores(): void
    {
        TestNet::$plugins = ['mine/mine.php' => ['Version' => '1.0']];
        $before = "<?php\n/*\n * Plugin Name: Mine\n */\nfunction mine_hello(): string\n{\n    return 'hi';\n}\n" . self::INJECTED . "\nadd_action('init', 'mine_hello');\n";
        $after = "<?php\n/*\n * Plugin Name: Mine\n */\nfunction mine_hello(): string\n{\n    return 'hi';\n}\nadd_action('init', 'mine_hello');\n";
        $file = $this->site->put('wp-content/plugins/mine/mine.php', $before);

        $result = self::apply([['action' => 'clean_file', 'target' => 'wp-content/plugins/mine/mine.php']]);

        self::assertSame(['applied'], self::codes($result), json_encode($result) ?: '');
        self::assertSame($after, file_get_contents($file));
        self::assertSame(['path' => 'wp-content/plugins/mine/mine.php', 'mode' => 'stripped', 'lines_removed' => 1, 'sha256' => hash('sha256', $after)], $result['results'][0]['detail']);
        self::assertSame(1, self::undo($result)['undone']);
        self::assertSame($before, file_get_contents($file));

        // Undo refuses once the file has changed again after the fix.
        $again = self::apply([['action' => 'clean_file', 'target' => 'wp-content/plugins/mine/mine.php']]);
        file_put_contents($file, $after . "// edited\n");
        self::assertSame('gobuildwp_security_fix_changed_since', self::undo($again)['results'][0]['error']['code']);
        self::assertSame($after . "// edited\n", file_get_contents($file));
    }

    public function test_clean_file_quarantines_a_file_that_is_only_the_injection(): void
    {
        $file = $this->site->put('wp-content/uploads/2026/10/cache.php', "<?php\n" . self::INJECTED . "\n");

        $result = self::apply([['action' => 'clean_file', 'target' => 'wp-content/uploads/2026/10/cache.php']]);

        self::assertSame('quarantined', $result['results'][0]['detail']['mode'] ?? null, json_encode($result) ?: '');
        self::assertFileDoesNotExist($file);
        self::assertCount(1, $this->kept());
        self::assertSame(1, self::undo($result)['undone']);
        self::assertSame("<?php\n" . self::INJECTED . "\n", file_get_contents($file));
    }

    public function test_clean_file_quarantines_a_web_shell_but_never_a_plugin_main_file(): void
    {
        TestNet::$plugins = [];
        $shell = "<?php\n// " . 'Files' . "Man\nfunction shell_a() { return 1; }\nfunction shell_b() { return 2; }\necho 1; " . self::INJECTED . " echo 2;\n";
        $file = $this->site->put('wp-content/plugins/premium/inc/cache.php', $shell);
        $main = $this->site->put('wp-content/plugins/premium/premium.php', "<?php\n/* Plugin Name: Premium */\n" . substr($shell, 6));

        $result = self::apply([
            ['action' => 'clean_file', 'target' => 'wp-content/plugins/premium/inc/cache.php'],
            ['action' => 'clean_file', 'target' => 'wp-content/plugins/premium/premium.php'],
        ]);

        self::assertSame('quarantined', $result['results'][0]['detail']['mode'] ?? null, json_encode($result) ?: '');
        self::assertFileDoesNotExist($file);
        self::assertSame(['applied', 'gobuildwp_security_fix_cannot_isolate'], self::codes($result));
        self::assertFileExists($main);
    }

    public function test_clean_file_refusals(): void
    {
        TestNet::$plugins = [];
        self::primeCore(['wp-includes/version.php' => md5('<?php')]);
        $this->site->put('wp-includes/version.php', "<?php\n" . self::INJECTED);
        $mixed = "<?php\nfunction mine_a() {}\nfunction mine_b() {}\nfunction mine_c() {}\necho 1;" . self::INJECTED . " echo 2;\n";
        $this->site->put('wp-content/plugins/mine/mixed.php', $mixed);
        $this->site->put('wp-content/uploads/a.js', 'ev' . 'al(atob("YWxlcnQoMSk="));');
        $this->site->put('wp-content/plugins/mine/clean.php', "<?php\nfunction mine_ok() {}\n");

        $result = self::apply([
            ['action' => 'clean_file', 'target' => 'wp-includes/version.php'],
            ['action' => 'clean_file', 'target' => 'wp-content/plugins/mine/mixed.php'],
            ['action' => 'clean_file', 'target' => 'wp-content/uploads/a.js'],
            ['action' => 'clean_file', 'target' => 'wp-content/plugins/mine/clean.php'],
            ['action' => 'clean_file', 'target' => 'wp-content/uploads/none.php'],
        ]);

        self::assertSame([
            'gobuildwp_security_fix_use_repair_core',
            'gobuildwp_security_fix_cannot_isolate',
            'gobuildwp_security_fix_cannot_isolate',
            'gobuildwp_security_fix_unchanged',
            'gobuildwp_security_fix_bad_path',
        ], self::codes($result));
        self::assertSame($mixed, file_get_contents($this->site->root . '/wp-content/plugins/mine/mixed.php'));
        self::assertSame([], TestNet::$calls);
    }

    public function test_clean_file_replaces_official_plugin_and_theme_files(): void
    {
        $official = "<?php\n/* Plugin Name: Hello */\n";
        self::primePlugin('hello', '1.2', ['hello.php' => $official]);
        $plugin = $this->site->put('wp-content/plugins/hello/hello.php', $official . self::INJECTED);
        $clean = "<?php\nfunction twentyx_setup() {}\n";
        $theme = $this->site->put('wp-content/themes/twentyx/functions.php', $clean . self::INJECTED . "\n");
        TestNet::$site_transients['update_themes'] = (object) ['checked' => ['twentyx' => '1.0'], 'response' => [], 'no_update' => ['twentyx' => ['theme' => 'twentyx']]];
        TestNet::$responses = [['response' => ['code' => 200], 'body' => $official], ['response' => ['code' => 200], 'body' => $clean]];

        $result = self::apply([
            ['action' => 'clean_file', 'target' => 'wp-content/plugins/hello/hello.php'],
            ['action' => 'clean_file', 'target' => 'wp-content/themes/twentyx/functions.php'],
        ]);

        self::assertSame(['applied', 'applied'], self::codes($result), json_encode($result) ?: '');
        self::assertSame('https://themes.svn.wordpress.org/twentyx/1.0/functions.php', TestNet::$calls[1]);
        self::assertSame('replaced_official', $result['results'][0]['detail']['mode']);
        self::assertSame(['path' => 'wp-content/themes/twentyx/functions.php', 'mode' => 'replaced_official', 'theme' => 'twentyx', 'version' => '1.0', 'sha256' => hash('sha256', $clean)], $result['results'][1]['detail']);
        self::assertSame($official, file_get_contents($plugin));
        self::assertSame($clean, file_get_contents($theme));

        $undo = SI\undo_fixes(['fix_ids' => array_column($result['results'], 'fix_id'), 'confirm' => true]);
        self::assertSame(2, $undo['undone']);
        self::assertSame($clean . self::INJECTED . "\n", file_get_contents($theme));
    }

    public function test_clean_option_strips_markup_and_undo_restores(): void
    {
        $raw = serialize(['title' => 'News', 'text' => '<p>Hi</p><script src="https://evil.test/x.js"></script>']);
        $this->db->option('widget_text', $raw, 'on');

        $result = self::apply([['action' => 'clean_option', 'target' => 'widget_text']]);

        self::assertSame(['option' => 'widget_text', 'mode' => 'stripped', 'size' => strlen($raw), 'elements_removed' => 1], $result['results'][0]['detail'] ?? null, json_encode($result) ?: '');
        self::assertSame(serialize(['title' => 'News', 'text' => '<p>Hi</p>']), $this->db->optionRows[0]['value']);
        self::assertCount(1, $this->kept());

        self::assertSame(1, self::undo($result)['undone']);
        self::assertSame([$raw, 'on'], [$this->db->optionRows[0]['value'], $this->db->optionRows[0]['autoload']]);
        self::assertSame([], $this->kept());
    }

    public function test_clean_option_deletes_a_code_payload_and_undo_puts_the_row_back(): void
    {
        $raw = 'ev' . 'al(base64_decode("ZWNobyAxOw=="));';
        $this->db->option('wp_cache_core', $raw, 'yes');

        $result = self::apply([['action' => 'clean_option', 'target' => 'wp_cache_core']]);

        self::assertSame(['option' => 'wp_cache_core', 'mode' => 'deleted', 'size' => strlen($raw)], $result['results'][0]['detail'] ?? null, json_encode($result) ?: '');
        self::assertSame([], $this->db->optionRows);
        self::assertSame(1, self::undo($result)['undone']);
        self::assertSame(['wp_cache_core', $raw, 'yes'], [$this->db->optionRows[0]['name'], $this->db->optionRows[0]['value'], $this->db->optionRows[0]['autoload']]);
    }

    public function test_clean_option_refusals(): void
    {
        $this->db->option('blogdescription', 'Just another site');
        $this->db->option('widget_html', serialize(['<script>alert(1)</script>']));
        $result = self::apply([
            ['action' => 'clean_option', 'target' => 'blogdescription'],
            ['action' => 'clean_option', 'target' => 'siteurl'],
            ['action' => 'clean_option', 'target' => 'wp_user_roles'],
            ['action' => 'clean_option', 'target' => 'gobuildwp_kit_si_fixes'],
            ['action' => 'clean_option', 'target' => 'missing_option'],
            ['action' => 'clean_option', 'target' => "bad\nname"],
        ]);
        self::assertSame([
            'gobuildwp_security_fix_unchanged',
            'gobuildwp_security_fix_protected',
            'gobuildwp_security_fix_protected',
            'gobuildwp_security_fix_protected',
            'gobuildwp_security_fix_not_found',
            'gobuildwp_security_fix_bad_input',
        ], self::codes($result));

        $this->db->failWrites = true;
        $failed = self::apply([['action' => 'clean_option', 'target' => 'widget_html']]);
        self::assertSame(['gobuildwp_security_fix_write_failed'], self::codes($failed));
        self::assertSame([], $this->kept());

        // A stripped option edited after the fix is not rolled back over the edit.
        $this->db->failWrites = false;
        $done = self::apply([['action' => 'clean_option', 'target' => 'widget_html']]);
        $this->db->optionRows[1]['value'] = serialize(['edited']);
        self::assertSame('gobuildwp_security_fix_changed_since', self::undo($done)['results'][0]['error']['code']);
        self::assertSame(serialize(['edited']), $this->db->optionRows[1]['value']);
    }

    public function test_remove_cron_drops_events_with_foreign_urls_and_undo_restores(): void
    {
        $cron = [
            1000 => [
                'seo_ping' => ['k1' => ['schedule' => 'hourly', 'args' => ['https://evil.test/p'], 'interval' => 3600]],
                'wp_version_check' => ['k0' => ['schedule' => 'twicedaily', 'args' => [], 'interval' => 43200]],
            ],
            2000 => ['seo_ping' => ['k2' => ['schedule' => false, 'args' => ['https://example.test/sitemap.xml']]]],
            'version' => 2,
        ];
        TestNet::$cron = $cron;
        TestNet::$actions = ['seo_ping'];

        $result = self::apply([['action' => 'remove_cron', 'target' => 'seo_ping']]);

        self::assertSame(['hook' => 'seo_ping', 'removed' => 1, 'reason' => 'remote_url_args'], $result['results'][0]['detail'] ?? null, json_encode($result) ?: '');
        self::assertIsArray(TestNet::$cron);
        self::assertArrayNotHasKey('seo_ping', TestNet::$cron[1000]);
        self::assertArrayHasKey('k2', TestNet::$cron[2000]['seo_ping']);

        self::assertSame(1, self::undo($result)['undone']);
        self::assertSame($cron[1000]['seo_ping'], TestNet::$cron[1000]['seo_ping']);
        self::assertSame($cron[2000], TestNet::$cron[2000]);
        self::assertSame(2, TestNet::$cron['version']);
    }

    public function test_remove_cron_drops_every_event_of_a_hook_without_a_handler(): void
    {
        TestNet::$cron = [1000 => ['ghost' => ['a' => ['schedule' => false, 'args' => []]]], 3000 => ['ghost' => ['b' => ['schedule' => 'daily', 'args' => [1], 'interval' => 86400]]]];

        $result = self::apply([['action' => 'remove_cron', 'target' => 'ghost']]);

        self::assertSame(['hook' => 'ghost', 'removed' => 2, 'reason' => 'no_callback'], $result['results'][0]['detail'] ?? null);
        self::assertSame([], TestNet::$cron);
        self::assertSame(1, self::undo($result)['undone']);
        self::assertSame([1000 => ['ghost' => ['a' => ['schedule' => false, 'args' => []]]], 3000 => ['ghost' => ['b' => ['schedule' => 'daily', 'args' => [1], 'interval' => 86400]]]], TestNet::$cron);
    }

    public function test_remove_cron_refusals(): void
    {
        TestNet::$cron = [1000 => ['local_job' => ['a' => ['schedule' => false, 'args' => ['https://www.example.test/x']]]]];
        TestNet::$actions = ['local_job'];
        $result = self::apply([
            ['action' => 'remove_cron', 'target' => 'local_job'],
            ['action' => 'remove_cron', 'target' => 'not_scheduled'],
            ['action' => 'remove_cron', 'target' => "bad\x01hook"],
        ]);
        self::assertSame(['gobuildwp_security_fix_unchanged', 'gobuildwp_security_fix_unchanged', 'gobuildwp_security_fix_bad_input'], self::codes($result));

        TestNet::$cron = [2000 => ['orphan_job' => ['a' => ['schedule' => false, 'args' => []]]]];
        TestNet::$cron_fails = true;
        $failed = self::apply([['action' => 'remove_cron', 'target' => 'orphan_job']]);
        self::assertSame(['gobuildwp_security_fix_write_failed'], self::codes($failed));
        self::assertSame([], SI\list_fixes());
    }

    public function test_clean_post_removes_flagged_elements_and_undo_restores(): void
    {
        $content = '<p>Hi</p><script src="https://evil.test/a.js"></script><script>var a = 1;</script><iframe src="https://x.test/" width="0" height="0"></iframe><p>End</p>';
        $this->db->postRows[5] = ['content' => $content, 'status' => 'publish', 'type' => 'post'];

        $result = self::apply([['action' => 'clean_post', 'target' => '5']]);

        self::assertSame(['post_id' => 5, 'elements_removed' => 2], $result['results'][0]['detail'] ?? null, json_encode($result) ?: '');
        self::assertSame('<p>Hi</p><script>var a = 1;</script><p>End</p>', $this->db->postRows[5]['content']);
        self::assertSame(1, self::undo($result)['undone']);
        self::assertSame($content, $this->db->postRows[5]['content']);
        self::assertSame([], $this->kept());
    }

    public function test_clean_post_refusals(): void
    {
        $this->db->postRows[6] = ['content' => '<p>Plain</p><script src="https://example.test/own.js"></script>', 'status' => 'publish', 'type' => 'post'];
        $this->db->postRows[7] = ['content' => '<iframe style="display:none" src="https://x.test/"></iframe>', 'status' => 'publish', 'type' => 'post'];
        $result = self::apply([
            ['action' => 'clean_post', 'target' => '6'],
            ['action' => 'clean_post', 'target' => '99'],
            ['action' => 'clean_post', 'target' => 'abc'],
            ['action' => 'clean_post', 'target' => '7'],
        ]);
        self::assertSame(['gobuildwp_security_fix_unchanged', 'gobuildwp_security_fix_not_found', 'gobuildwp_security_fix_not_found', 'applied'], self::codes($result));

        // Content edited after the fix is not rolled back over the edit.
        $this->db->postRows[7]['content'] = '<p>Rewritten</p>';
        self::assertSame('gobuildwp_security_fix_changed_since', self::undo($result, 3)['results'][0]['error']['code']);
        self::assertSame('<p>Rewritten</p>', $this->db->postRows[7]['content']);
    }
}
