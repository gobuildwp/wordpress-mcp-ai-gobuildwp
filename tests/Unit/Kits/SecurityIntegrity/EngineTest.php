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
 * The file scan engine against a throwaway WordPress folder: path guard, rules, core and plugin
 * checksum compare, uploads, budgets and stored results. Checksums come from primed transients
 * or scripted responses; nothing reaches the network.
 */
final class EngineTest extends TestCase
{
    private Fixture $site;

    protected function setUp(): void
    {
        reset_state();
        $this->site = new Fixture();
    }

    protected function tearDown(): void
    {
        $this->site->remove();
        reset_state();
    }

    /** Malicious snippets, assembled so this file does not itself match the rules. */
    private static function evil(string $which): string
    {
        return match ($which) {
            'eval_base64' => '<?php ' . 'ev' . 'al(base' . '64_decode("ZWNobyAxOw=="));',
            'shell_request' => '<?php ' . 'sys' . 'tem($_' . 'GET["c"]);',
            'eval_request' => '<?php ' . 'ev' . 'al($_' . 'POST["x"]);',
            'webshell' => '<?php /* Files' . 'Man */ echo 1;',
            'js_atob' => 'ev' . 'al(at' . 'ob("YWxlcnQoMSk="));',
        };
    }

    public function test_guard_refuses_traversal_outside_and_missing_paths(): void
    {
        $root = $this->site->root;
        $file = $this->site->put('wp-includes/a.php', '<?php');
        $outside = dirname($root) . '/' . basename($root) . '-outside.php';
        file_put_contents($outside, '<?php');
        try {
            self::assertSame('ok', SI\guard($file, $root));
            self::assertSame('traversal', SI\guard($root . '/wp-includes/../wp-config.php', $root));
            self::assertSame('missing', SI\guard($root . '/nope.php', $root));
            self::assertSame('outside', SI\guard($outside, $root . '/wp-includes'));
        } finally {
            @unlink($outside);
        }
    }

    public function test_denied_names_and_php_extensions(): void
    {
        foreach (['wp-config.php', 'WP-CONFIG.PHP', 'wp-config-sample.php', '.env', '.env.local', 'dump.sql', 'site.zip', 'b.tar.gz'] as $name) {
            self::assertTrue(SI\denied_file($name), $name);
        }
        foreach (['index.php', 'config.php', 'readme.txt'] as $name) {
            self::assertFalse(SI\denied_file($name), $name);
        }
        foreach (['.git', 'node_modules', 'cache', 'backwpup-abc'] as $name) {
            self::assertTrue(SI\denied_dir($name), $name);
        }
        self::assertFalse(SI\denied_dir('includes'));
        foreach (['a.php', 'a.PHP5', 'a.phtml', 'a.phar', 'a.pht'] as $name) {
            self::assertTrue(SI\is_php_name($name), $name);
        }
        self::assertFalse(SI\is_php_name('a.jpg'));
        self::assertTrue(SI\has_inner_php_extension('shell.php.jpg'));
        self::assertFalse(SI\has_inner_php_extension('photo.jpg'));
        self::assertFalse(SI\has_inner_php_extension('shell.php'));
    }

    public function test_rules_find_synthetic_malware_and_ignore_clean_code(): void
    {
        $rules = static fn(string $text, bool $php = true): array => array_column(SI\scan_text($text, $php), 'rule_id');
        self::assertSame(['susp.eval_base64'], $rules(self::evil('eval_base64')));
        self::assertSame(['susp.shell_request'], $rules(self::evil('shell_request')));
        self::assertSame(['susp.eval_request'], $rules(self::evil('eval_request')));
        self::assertSame(['susp.webshell_marker'], $rules(self::evil('webshell')));
        self::assertSame(['susp.js_eval_atob'], $rules(self::evil('js_atob'), false));
        self::assertSame(['susp.php_tag_in_non_php'], $rules("GIF89a\n<?php echo 1;", false));
        self::assertSame([], $rules("<?php\nfunction hello() { return base64_decode('aGk='); }\n"));
        self::assertSame([], $rules('<?php echo 1;'), 'a PHP tag is fine in a PHP file');

        $hit = SI\scan_text("<?php\n\n" . substr(self::evil('eval_base64'), 6))[0];
        self::assertSame(3, $hit['line']);
        self::assertSame('high', $hit['severity_hint']);
    }

    public function test_htaccess_handler_lines_skip_hardening(): void
    {
        self::assertSame(2, SI\htaccess_handler_line("# uploads\nAddHandler application/x-httpd-php .jpg\n"));
        self::assertSame(1, SI\htaccess_handler_line('SetHandler application/x-httpd-php'));
        self::assertSame(1, SI\htaccess_handler_line('php_value auto_prepend_file /tmp/x'));
        self::assertNull(SI\htaccess_handler_line("php_flag engine off\nAddHandler cgi-script .php .phtml\nOptions -ExecCGI\n"));
        self::assertNull(SI\htaccess_handler_line("# AddHandler application/x-httpd-php .jpg\n"));
    }

    public function test_plugin_checksums_cache_a_200_but_not_a_network_failure(): void
    {
        $budget = new SI\Budget(SI\budgets(), 30);
        TestNet::$responses[] = new \WP_Error('http_request_failed', 'down');
        self::assertSame([], SI\plugin_checksums('hello-dolly', '1.7.2', $budget));
        self::assertArrayNotHasKey(SI\plugin_transient('hello-dolly', '1.7.2'), TestNet::$transients);

        $sha = hash('sha256', 'x');
        TestNet::$responses[] = TestNet::json(['files' => ['hello.php' => ['sha256' => $sha], 'bad.php' => ['sha256' => 'nothex']]]);
        self::assertSame(['hello.php' => [$sha]], SI\plugin_checksums('hello-dolly', '1.7.2', $budget));
        self::assertSame(['hello.php' => [$sha]], TestNet::$transients[SI\plugin_transient('hello-dolly', '1.7.2')]);
        self::assertSame(['hello.php' => [$sha]], SI\plugin_checksums('hello-dolly', '1.7.2', $budget), 'served from the transient');
        self::assertCount(2, TestNet::$calls);
        self::assertStringStartsWith('https://downloads.wordpress.org/plugin-checksums/hello-dolly/1.7.2.json', TestNet::$calls[1]);

        TestNet::$responses[] = TestNet::json([], 404);
        self::assertSame([], SI\plugin_checksums('gone', '1.0', $budget));
        self::assertSame([], TestNet::$transients[SI\plugin_transient('gone', '1.0')], 'a 404 is cached as "none published"');
    }

    public function test_plugin_checksums_stop_when_the_fetch_budget_is_spent(): void
    {
        $budget = new SI\Budget(SI\budgets(), 30);
        $budget->fetches = $budget->limits['fetches'];
        self::assertFalse(SI\plugin_checksums('hello-dolly', '1.7.2', $budget));
        self::assertSame([], TestNet::$calls);
    }

    public function test_wordpress_org_plugins_are_told_by_the_update_check(): void
    {
        TestNet::$site_transients['update_plugins'] = (object) [
            'response' => ['akismet/akismet.php' => (object) ['id' => 'w.org/plugins/akismet']],
            'no_update' => ['hello-dolly/hello.php' => ['id' => 'w.org/plugins/hello-dolly'], 'paid/paid.php' => (object) ['id' => 'example.com/paid']],
        ];
        self::assertTrue(SI\is_wporg_plugin('akismet/akismet.php'));
        self::assertTrue(SI\is_wporg_plugin('hello-dolly/hello.php'));
        self::assertFalse(SI\is_wporg_plugin('paid/paid.php'));
        self::assertFalse(SI\is_wporg_plugin('hello.php'), 'single-file plugins never are');
        self::assertFalse(SI\is_wporg_plugin('custom/custom.php'));
    }

    public function test_an_empty_update_check_keeps_the_last_answer(): void
    {
        TestNet::$site_transients['update_plugins'] = (object) ['response' => [], 'no_update' => ['akismet/akismet.php' => (object) ['id' => 'w.org/plugins/akismet']]];
        self::assertTrue(SI\is_wporg_plugin('akismet/akismet.php'));

        // WordPress clears the check after an update; a failed check leaves it empty.
        unset(TestNet::$site_transients['update_plugins']);
        self::assertTrue(SI\is_wporg_plugin('akismet/akismet.php'));
        TestNet::$site_transients['update_plugins'] = (object) ['last_checked' => 1, 'response' => [], 'no_update' => []];
        self::assertTrue(SI\is_wporg_plugin('akismet/akismet.php'));
        self::assertFalse(SI\is_wporg_plugin('custom/custom.php'));

        // A check that answers replaces it.
        TestNet::$site_transients['update_plugins'] = (object) ['response' => [], 'no_update' => ['akismet/akismet.php' => (object) ['id' => 'example.com/akismet']]];
        self::assertFalse(SI\is_wporg_plugin('akismet/akismet.php'));
    }

    private function core_tree(): void
    {
        $index = "<?php // core index\n";
        $version = "<?php \$wp_version = '7.0';\n";
        $this->site->put('index.php', $index);
        $this->site->put('wp-admin/index.php', "<?php // changed\n");
        $this->site->put('wp-includes/version.php', $version);
        $this->site->put('wp-includes/evil.php', self::evil('eval_base64'));
        $this->site->put('extra.php', '<?php echo 1;');
        $this->site->put('robots.txt', 'User-agent: *');
        $this->site->put('wp-config.php', '<?php // secrets');
        $this->site->put('wp-includes/cache/x.php', self::evil('webshell'));
        TestNet::$transients[SI\core_transient(SI\wp_version(), 'en_US')] = [
            'index.php' => md5($index),
            'wp-admin/index.php' => md5('<?php // original'),
            'wp-includes/version.php' => md5($version),
            'wp-includes/gone.php' => md5('gone'),
            'wp-content/plugins/hello.php' => md5('ignored'),
        ];
    }

    public function test_core_compare_reports_changed_missing_and_unexpected_files(): void
    {
        $this->core_tree();
        $run = run_files(['scope' => 'core']);
        self::assertSame(1, $run['steps']);
        self::assertSame([
            'core.mismatch wp-admin/index.php',
            'core.missing wp-includes/gone.php',
            'core.unexpected extra.php',
            'core.unexpected wp-includes/evil.php',
            'susp.eval_base64 wp-includes/evil.php',
        ], summary($run['items']));
        $evil = array_values(array_filter($run['items'], static fn(array $i): bool => $i['rule_id'] === 'susp.eval_base64'))[0];
        self::assertSame('core', $evil['area'], 'a rule hit stays in the area of the file it is in');
        self::assertSame('suspicious', $evil['result']);
        self::assertSame(1, $evil['detail']->line);
        self::assertSame('checked', $run['state']['coverage']['core']['state']);
        self::assertSame('skipped', $run['state']['coverage']['plugins']['state']);
        self::assertSame([], TestNet::$calls);
    }

    public function test_large_core_files_are_hashed_not_skipped(): void
    {
        $index = "<?php // core index\n";
        $big = str_repeat('a', 5 * 1024 * 1024);
        $this->site->put('index.php', $index);
        $this->site->put('wp-includes/js/big.min.js', $big);
        $this->site->put('wp-admin/index.php', $index);
        TestNet::$transients[SI\core_transient(SI\wp_version(), 'en_US')] = [
            'index.php' => md5($index),
            'wp-includes/js/big.min.js' => md5($big),
            'wp-admin/index.php' => md5($index),
        ];
        $run = run_files(['scope' => 'core']);
        self::assertSame([], summary($run['items']), 'a large official file matches its checksum');
        self::assertSame('checked', $run['state']['coverage']['core']['state']);
    }

    public function test_verified_core_files_are_not_read_with_the_rules(): void
    {
        $code = self::evil('eval_base64');
        $this->site->dir('wp-admin');
        $this->site->put('wp-includes/compat.php', $code);
        TestNet::$transients[SI\core_transient(SI\wp_version(), 'en_US')] = ['wp-includes/compat.php' => md5($code)];
        $run = run_files(['scope' => 'core']);
        self::assertSame([], summary($run['items']), 'a file matching its official checksum is the official file');
        self::assertSame(0, $run['state']['coverage']['suspicious']['checked']);
    }

    public function test_folders_beside_wordpress_are_read_for_php_malware(): void
    {
        $this->core_tree();
        $this->site->put('forum/index.php', '<?php echo "forum";');
        $this->site->put('forum/lib/db.php', '<?php // db layer');
        $this->site->put('forum/lib/x.php', self::evil('shell_request'));
        $this->site->put('forum/style.css', 'body{}');
        $this->site->put('assets/app.js', 'console.log(1)');
        $this->site->put('blog/wp-load.php', self::evil('shell_request'));
        $this->site->put('old/wp-content/plugins/a/a.php', '<?php');
        $this->site->put('copy/plugins/a.php', '<?php');
        $this->site->dir('copy/themes');
        $this->site->put('node_modules/pkg/evil.php', self::evil('shell_request'));
        $this->site->put('wp-content/plugins/p/p.php', self::evil('shell_request'));
        $run = run_files(['scope' => 'core']);
        $lines = summary($run['items']);
        self::assertContains('susp.shell_request forum/lib/x.php', $lines);
        self::assertContains('core.outside_wordpress forum', $lines);
        self::assertContains('core.other_install blog', $lines);
        self::assertContains('core.other_install copy', $lines);
        self::assertNotContains('core.other_install old', $lines, 'a folder holding a wp-content is walked, not skipped');
        foreach ($lines as $line) {
            self::assertStringNotContainsString('assets', $line, 'a folder with no PHP gets no line');
            self::assertStringNotContainsString('node_modules', $line);
            self::assertStringNotContainsString('wp-content/plugins/p', $line, 'wp-content is not part of the core scope');
            self::assertStringNotContainsString('blog/', $line, 'another install is not read');
        }
        $by_path = [];
        foreach ($run['items'] as $item) {
            $by_path[$item['rule_id'] . ' ' . $item['path']] = $item;
        }
        $hit = $by_path['susp.shell_request forum/lib/x.php'];
        self::assertSame(['core', 'suspicious', 'critical'], [$hit['area'], $hit['result'], $hit['severity_hint']]);
        $note = $by_path['core.outside_wordpress forum'];
        self::assertSame(['no_checksum', 'info'], [$note['result'], $note['severity_hint']]);
        self::assertStringStartsWith('2 PHP files outside', $note['detail']->reason);
        self::assertSame('1 PHP file outside WordPress\'s own folders; none matched a malware rule.', $by_path['core.outside_wordpress old']['detail']->reason);
        self::assertSame('checked', $run['state']['coverage']['core']['state']);

        add_filter('gobuildwp_kit_security_integrity_budgets', static fn(array $b): array => ['files' => 1] + $b);
        $stepped = run_files(['scope' => 'core'], 200);
        self::assertGreaterThan(1, $stepped['steps']);
        self::assertSame($lines, summary($stepped['items']), 'stops and resumes to the same results');
    }

    public function test_folders_beside_wordpress_are_read_without_core_checksums(): void
    {
        $this->site->put('app/x.php', self::evil('shell_request'));
        $run = run_files(['scope' => 'core']);
        self::assertSame('unavailable', $run['state']['coverage']['core']['state']);
        self::assertSame(['susp.shell_request app/x.php'], summary($run['items']));
    }

    public function test_a_symlinked_folder_beside_wordpress_is_not_followed(): void
    {
        $target = $this->site->root . '-elsewhere';
        mkdir($target);
        file_put_contents($target . '/x.php', self::evil('shell_request'));
        try {
            if (!@symlink($target, $this->site->root . '/linked')) {
                self::markTestSkipped('This system cannot make symlinks.');
            }
            $this->site->put('index.php', 'x');
            TestNet::$transients[SI\core_transient(SI\wp_version(), 'en_US')] = ['index.php' => md5('x')];
            $lines = summary(run_files(['scope' => 'core'])['items']);
            self::assertSame(['symlink_skipped linked'], $lines, 'reported once, by the root walk, and not entered');
        } finally {
            @unlink($this->site->root . '/linked');
            @rmdir($this->site->root . '/linked');
            @unlink($target . '/x.php');
            @rmdir($target);
        }
    }

    public function test_deep_scan_reads_uploaded_images_for_code(): void
    {
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x00\x10JFIF", 50);
        $this->site->put('wp-content/uploads/2026/10/clean.jpg', $jpeg . '<?=' . "\x01\x02");
        $this->site->put('wp-content/uploads/2026/10/tag.png', $jpeg . '<?php echo 1; ?>');
        $this->site->put('wp-content/uploads/2026/10/short.gif', $jpeg . "<?= \$x ?>");
        $this->site->put('wp-content/uploads/2026/10/eval.webp', $jpeg . "\n" . 'ev' . 'al ($_POST[1]);');
        $this->site->put('wp-content/uploads/2026/10/b64.JPEG', $jpeg . ' base' . '64_decode("eA==")');
        $this->site->put('wp-content/uploads/2026/10/tail.bmp', $jpeg . str_repeat("\x00", SI\RULE_READ_BYTES + 10) . '<?php x();');
        $this->site->put('wp-content/uploads/2026/10/doc.pdf', '<?php echo 1;');

        self::assertSame([], summary(run_files(['scope' => 'uploads'])['items']), 'images are read only in a deep scan');

        $run = run_files(['scope' => 'uploads', 'deep' => true]);
        self::assertSame([
            'susp.php_tag_in_non_php wp-content/uploads/2026/10/b64.JPEG',
            'susp.php_tag_in_non_php wp-content/uploads/2026/10/eval.webp',
            'susp.php_tag_in_non_php wp-content/uploads/2026/10/short.gif',
            'susp.php_tag_in_non_php wp-content/uploads/2026/10/tag.png',
            'susp.php_tag_in_non_php wp-content/uploads/2026/10/tail.bmp',
        ], summary($run['items']));
        foreach ($run['items'] as $item) {
            self::assertSame(['uploads', 'suspicious', 'high'], [$item['area'], $item['result'], $item['severity_hint']]);
        }
        self::assertGreaterThan(SI\RULE_READ_BYTES, $run['state']['bytes_read']);
        self::assertLessThanOrEqual(6 * SI\RULE_READ_BYTES + 6, $run['state']['bytes_read'], 'at most the read limit per image');
        self::assertSame(7, $run['state']['files_checked'], 'every upload is counted, the PDF too');

        add_filter('gobuildwp_kit_security_integrity_budgets', static fn(array $b): array => ['files' => 1] + $b);
        $stepped = run_files(['scope' => 'uploads', 'deep' => true], 200);
        self::assertSame(summary($run['items']), summary($stepped['items']));
    }

    public function test_core_without_checksums_is_unavailable_not_clean(): void
    {
        $this->core_tree();
        TestNet::$transients = [];
        $run = run_files(['scope' => 'core']);
        self::assertSame('unavailable', $run['state']['coverage']['core']['state']);
        self::assertNotSame('', $run['state']['coverage']['core']['reason']);
    }

    private function plugin_tree(): void
    {
        $hello = "<?php /* Plugin Name: Hello Dolly */\n";
        $this->site->put('wp-content/plugins/hello-dolly/hello.php', $hello);
        $this->site->put('wp-content/plugins/hello-dolly/readme.txt', 'changed readme');
        $this->site->put('wp-content/plugins/hello-dolly/backdoor.php', self::evil('webshell'));
        $this->site->put('wp-content/plugins/custom/custom.php', '<?php /* Plugin Name: Custom */');
        $this->site->put('wp-content/plugins/single.php', '<?php /* Plugin Name: Single */');
        $this->site->put('wp-content/mu-plugins/loader.php', '<?php // loader');
        TestNet::$plugins = [
            'single.php' => ['Version' => '1'],
            'hello-dolly/hello.php' => ['Version' => '1.7.2'],
            'custom/custom.php' => ['Version' => '1.0'],
        ];
        TestNet::$site_transients['update_plugins'] = (object) [
            'no_update' => ['hello-dolly/hello.php' => (object) ['id' => 'w.org/plugins/hello-dolly']],
        ];
        TestNet::$responses[] = TestNet::json(['files' => [
            'hello.php' => ['sha256' => hash('sha256', $hello)],
            'readme.txt' => ['sha256' => hash('sha256', 'original readme')],
            'gone.php' => ['sha256' => hash('sha256', 'gone')],
        ]]);
    }

    public function test_plugins_compare_against_wordpress_org_and_list_the_rest(): void
    {
        $this->plugin_tree();
        $run = run_files(['scope' => 'plugins']);
        self::assertSame(1, $run['steps']);
        self::assertSame([
            'no_checksum wp-content/mu-plugins/loader.php',
            'no_checksum wp-content/plugins/custom',
            'no_checksum wp-content/plugins/single.php',
            'plugin.mismatch wp-content/plugins/hello-dolly/readme.txt',
            'plugin.missing wp-content/plugins/hello-dolly/gone.php',
            'plugin.unexpected wp-content/plugins/hello-dolly/backdoor.php',
            'susp.webshell_marker wp-content/plugins/hello-dolly/backdoor.php',
        ], summary($run['items']));
        $reasons = [];
        foreach ($run['items'] as $item) {
            if ($item['result'] === 'no_checksum') {
                $reasons[$item['path']] = $item['detail']->reason;
            }
        }
        self::assertSame([
            'wp-content/plugins/custom' => 'not_wordpress_org',
            'wp-content/plugins/single.php' => 'single_file',
            'wp-content/mu-plugins/loader.php' => 'must_use_plugin',
        ], $reasons);
        $mismatch = array_values(array_filter($run['items'], static fn(array $i): bool => $i['rule_id'] === 'plugin.mismatch'))[0];
        self::assertSame('medium', $mismatch['severity_hint'], 'a changed text file is not a PHP change');
        self::assertSame(['type' => 'plugin', 'slug' => 'hello-dolly', 'version' => '1.7.2'], $mismatch['component']);
        self::assertSame('partial', $run['state']['coverage']['plugins']['state']);
        self::assertCount(1, TestNet::$calls);
    }

    public function test_uploads_report_php_and_handler_files(): void
    {
        $this->site->put('wp-content/uploads/2026/10/shell.php', self::evil('shell_request'));
        $this->site->put('wp-content/uploads/2026/10/image.php.jpg', 'JPEG');
        $this->site->put('wp-content/uploads/.htaccess', "AddHandler application/x-httpd-php .jpg\n");
        $this->site->put('wp-content/uploads/.user.ini', "auto_prepend_file=/tmp/x.jpg\n");
        $this->site->put('wp-content/uploads/safe/.htaccess', "php_flag engine off\n");
        $this->site->put('wp-content/uploads/icon.ico', "\x00\x00<?php echo 1;");
        $this->site->put('wp-content/uploads/2026/10/photo.jpg', 'JPEG data');
        $run = run_files(['scope' => 'uploads']);
        self::assertSame([
            'susp.php_tag_in_non_php wp-content/uploads/icon.ico',
            'susp.shell_request wp-content/uploads/2026/10/shell.php',
            'uploads.htaccess_handler wp-content/uploads/.htaccess',
            'uploads.php_double_extension wp-content/uploads/2026/10/image.php.jpg',
            'uploads.php_file wp-content/uploads/2026/10/shell.php',
            'uploads.user_ini wp-content/uploads/.user.ini',
        ], summary($run['items']));
        foreach ($run['items'] as $item) {
            if (str_starts_with((string) $item['rule_id'], 'uploads.')) {
                self::assertSame('php_in_uploads', $item['result']);
            }
        }
        self::assertSame('checked', $run['state']['coverage']['uploads']['state']);
        self::assertGreaterThanOrEqual(7, $run['state']['coverage']['uploads']['checked']);
    }

    public function test_upload_folder_index_stubs_are_not_reported(): void
    {
        $this->site->put('wp-content/uploads/edd/index.php', "<?php\n// Silence is golden.\n");
        $this->site->put('wp-content/uploads/forminator/index.php', '');
        $this->site->put('wp-content/uploads/b/index.php', "<?php\nheader( \$_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found' );\nheader( 'Status: 404 Not Found' );\nexit;\n");
        $this->site->put('wp-content/uploads/c/index.php', "<?php eval(\$_POST['x']);");
        $this->site->put('wp-content/uploads/d/index.php', "<?php // Silence\n" . str_repeat(' ', 600));
        $run = run_files(['scope' => 'uploads']);
        $flagged = array_values(array_filter(summary($run['items']), static fn(string $s): bool => str_starts_with($s, 'uploads.php_file')));
        self::assertSame(['uploads.php_file wp-content/uploads/c/index.php', 'uploads.php_file wp-content/uploads/d/index.php'], $flagged);
    }

    public function test_themes_without_checksums_are_listed_without_a_baseline(): void
    {
        $this->site->put('wp-content/themes/t/style.css', '/* Theme Name: T */');
        $this->site->put('wp-content/themes/t/functions.php', self::evil('shell_request'));
        $run = run_files(['scope' => 'themes']);
        self::assertSame(['no_checksum wp-content/themes/t'], summary($run['items']), 'files are read only for a baseline or a deep scan');
        self::assertSame('not_wordpress_org', $run['items'][0]['detail']->reason);
        self::assertSame('partial', $run['state']['coverage']['themes']['state']);
    }

    public function test_a_small_budget_stops_and_resumes_to_the_same_results(): void
    {
        $this->core_tree();
        $this->site->put('wp-content/uploads/a/shell.php', self::evil('shell_request'));
        $this->site->put('wp-content/uploads/b/photo.jpg', 'JPEG');
        $whole = run_files(['scope' => 'all']);
        self::assertSame(1, $whole['steps']);

        add_filter('gobuildwp_kit_security_integrity_budgets', static fn(array $b): array => ['files' => 1] + $b);
        self::assertSame(1, SI\budgets()['files']);
        $stepped = run_files(['scope' => 'all'], 200);
        self::assertGreaterThan(1, $stepped['steps']);
        self::assertSame(summary($whole['items']), summary($stepped['items']));
        self::assertSame($whole['state']['coverage'], $stepped['state']['coverage']);
    }

    public function test_budget_filters_cannot_raise_the_ceilings(): void
    {
        add_filter('gobuildwp_kit_security_integrity_budgets', static fn(array $b): array => ['files' => 1_000_000, 'seconds' => 0] + $b);
        self::assertSame(SI\BUDGETS['files'], SI\budgets()['files']);
        self::assertSame(1, SI\budgets()['seconds']);
    }

    public function test_results_are_stored_in_pages_and_read_back_by_cursor(): void
    {
        $items = [];
        for ($i = 0; $i < 250; $i++) {
            $items[] = ['path' => 'f' . $i];
        }
        SI\store_results('tok', 0, $items);
        self::assertSame(array_slice($items, 0, SI\PAGE_SIZE), SI\read_results('tok', 0, 250));
        self::assertSame(array_slice($items, 200, 50), SI\read_results('tok', 200, 250));
        self::assertSame(array_slice($items, 10, SI\PAGE_SIZE), SI\read_results('tok', 10, 250));

        SI\store_results('tok', 250, [['path' => 'late']]);
        self::assertSame([['path' => 'late']], SI\read_results('tok', 250, 251));

        SI\delete_results('tok');
        self::assertSame([], SI\read_results('tok', 0, 251));
    }
}
