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
    use WPPilot_Test_State;

    /**
     * The pre-WordPress firewall: installing it (engine, state file, .user.ini block), keeping
     * its state current, taking it out, and the engine itself run under php-cgi.
     */
    final class PrependTest extends TestCase
    {
        private string $dir = '';

        protected function setUp(): void
        {
            WPPilot_Test_State::$options = [];
            $GLOBALS['gobuildwp_sp_test_transients'] = [];
            $this->dir = str_replace('\\', '/', sys_get_temp_dir()) . '/gobuildwp-sp-' . bin2hex(random_bytes(4));
            mkdir($this->dir . '/site/wp-content', 0755, true);
            $root = $this->dir . '/site/wp-content/gobuildwp-protection';
            $site = $this->dir . '/site';
            add_filter('gobuildwp_protection_prepend_root', static fn(): string => $root);
            add_filter('gobuildwp_protection_prepend_site_root', static fn(): string => $site);
        }

        protected function tearDown(): void
        {
            remove_all_filters('gobuildwp_protection_prepend_root');
            remove_all_filters('gobuildwp_protection_prepend_site_root');
            self::rmtree($this->dir);
        }

        private static function rmtree(string $path): void
        {
            if (!is_dir($path)) {
                return;
            }
            foreach (array_diff((array) scandir($path), ['.', '..']) as $name) {
                $child = $path . '/' . $name;
                is_dir($child) && !is_link($child) ? self::rmtree($child) : @unlink($child);
            }
            @rmdir($path);
        }

        private function ini(): string
        {
            $name = (string) ini_get('user_ini.filename');
            self::assertNotSame('', $name, 'user_ini.filename is needed for this test');
            return $this->dir . '/site/' . $name;
        }

        private function root(): string
        {
            return $this->dir . '/site/wp-content/gobuildwp-protection';
        }

        // ------------------------------------------------------------ install

        public function test_not_installed_by_default(): void
        {
            self::assertSame(['installed' => false, 'method' => null, 'active' => false, 'problem' => null], SP\prepend_status());
            self::assertFalse(SP\sync_prepend_state());
        }

        public function test_install_writes_engine_state_and_ini_block(): void
        {
            $r = SP\set_prepend(true);
            self::assertIsArray($r, is_object($r) ? $r->get_error_message() : '');
            self::assertTrue($r['installed']);
            self::assertSame('user_ini', $r['method']);
            self::assertFalse($r['active'], 'this PHP process was not started through the prepend');
            self::assertStringContainsString('did not pass through it yet', (string) $r['problem']);
            self::assertFileExists($this->root() . '/firewall.php');
            self::assertFileExists($this->root() . '/state.php');
            self::assertFileExists($this->root() . '/.htaccess');
            self::assertFileExists($this->root() . '/index.php');
            self::assertFileEquals(SP\prepend_engine_source(), $this->root() . '/firewall.php');
            $ini = (string) file_get_contents($this->ini());
            self::assertStringContainsString(SP\PREPEND_INI_BEGIN, $ini);
            self::assertStringContainsString('auto_prepend_file = "' . $this->root() . '/firewall.php"', $ini);
            self::assertStringContainsString(SP\PREPEND_INI_END, $ini);
            $state = include $this->root() . '/state.php';
            self::assertIsArray($state);
            self::assertTrue($state['enabled']);
            self::assertSame('log', $state['firewall']);
            self::assertSame([], $state['blocks']);
            self::assertSame([], $state['rules'], 'rules are only shipped in block mode');
            // Installing again is idempotent and keeps the user's own ini lines.
            file_put_contents($this->ini(), "memory_limit = 256M\n" . $ini);
            self::assertIsArray(SP\set_prepend(true));
            $ini = (string) file_get_contents($this->ini());
            self::assertSame(1, substr_count($ini, SP\PREPEND_INI_BEGIN));
            self::assertStringStartsWith("memory_limit = 256M\n", $ini);
            self::assertCount(1, SP\events(10, 'settings'));
        }

        public function test_state_follows_blocks_settings_and_feed(): void
        {
            self::assertIsArray(SP\set_prepend(true));
            $block = SP\add_block('203.0.113.9', 60, 'manual', 'manual');
            self::assertIsArray($block);
            SP\configure(['firewall' => 'block', 'xmlrpc' => 'off', 'allowlist' => ['198.51.100.1']]);
            SP\set_feed(['rules' => [['id' => 'r1', 'label' => 'Feed rule', 'in' => ['ua'], 'pattern' => '^EvilBot/']], 'blocklist' => [['ip' => '192.0.2.0/24']]]);
            $state = include $this->root() . '/state.php';
            self::assertSame('block', $state['firewall']);
            self::assertSame('off', $state['xmlrpc']);
            self::assertSame(['198.51.100.1'], $state['allowlist']);
            self::assertSame([['ip' => '203.0.113.9', 'source' => 'manual', 'expires_at' => $block['expires_at']]], $state['blocks']);
            self::assertGreaterThan(time(), $block['expires_at']);
            self::assertSame([['ip' => '192.0.2.0/24', 'expires_at' => null]], $state['blocklist']);
            self::assertSame('feed:r1', end($state['rules'])['id']);
            self::assertGreaterThan(count(SP\rules()), count($state['rules']));
            SP\remove_block('203.0.113.9');
            $state = include $this->root() . '/state.php';
            self::assertSame([], $state['blocks']);
        }

        public function test_remove_cleans_up(): void
        {
            self::assertIsArray(SP\set_prepend(true));
            file_put_contents($this->ini(), "memory_limit = 256M\n" . file_get_contents($this->ini()));
            $r = SP\set_prepend(false);
            self::assertIsArray($r);
            self::assertFalse($r['installed']);
            self::assertDirectoryDoesNotExist($this->root());
            self::assertSame("memory_limit = 256M\n", file_get_contents($this->ini()));
            self::assertSame(['installed' => false, 'method' => null, 'active' => false, 'problem' => null], SP\prepend_status());
            // The ini file goes when only our block was in it.
            unlink($this->ini());
            self::assertIsArray(SP\set_prepend(true));
            self::assertFileExists($this->ini());
            self::assertIsArray(SP\set_prepend(false));
            self::assertFileDoesNotExist($this->ini());
        }

        public function test_foreign_auto_prepend_file_is_refused(): void
        {
            file_put_contents($this->ini(), "auto_prepend_file = \"/srv/wordfence-waf.php\"\n");
            $r = SP\set_prepend(true);
            self::assertInstanceOf(\WP_Error::class, $r);
            self::assertSame('gobuildwp_prepend_unavailable', $r->get_error_code());
            self::assertStringContainsString('already sets auto_prepend_file', $r->get_error_message());
            self::assertDirectoryDoesNotExist($this->root());
            self::assertFalse(SP\prepend_status()['installed']);
        }

        public function test_engine_events_are_ingested(): void
        {
            self::assertIsArray(SP\set_prepend(true));
            file_put_contents($this->root() . '/events.log', json_encode(['id' => 'pre-abc', 'at' => 1_700_000_000, 'type' => 'block', 'ip' => '203.0.113.9', 'rule' => 'manual', 'path' => '/wp-login.php', 'detail' => 'Address is blocked']) . "\n"
                . "not json\n"
                . json_encode(['type' => 'nonsense', 'ip' => '1.1.1.1']) . "\n");
            self::assertSame(1, SP\ingest_prepend_events(1_700_000_100));
            self::assertFileDoesNotExist($this->root() . '/events.log');
            $events = SP\events(10, 'block');
            self::assertCount(1, $events);
            self::assertSame('203.0.113.9', $events[0]['ip']);
            self::assertSame('manual', $events[0]['rule']);
            self::assertSame('Address is blocked (before WordPress, ref pre-abc)', $events[0]['detail']);
            self::assertSame(0, SP\ingest_prepend_events());
        }

        // ------------------------------------------------------------ engine

        /**
         * Run the engine the way PHP does in production: as auto_prepend_file in front of a script,
         * under php-cgi so it sees a web SAPI.
         *
         * @param array<string, string> $env
         * @return array{status: int, body: string}
         */
        private function request(array $env): array
        {
            $cgi = dirname(PHP_BINARY) . '/php-cgi' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            if (!is_file($cgi)) {
                self::markTestSkipped('php-cgi is not beside the php binary');
            }
            $target = $this->dir . '/site/' . ($env['SCRIPT'] ?? 'index.php');
            file_put_contents($target, "<?php echo 'passed';\n");
            $cmd = escapeshellarg($cgi) . ' -n -d cgi.force_redirect=0 -d auto_prepend_file=' . escapeshellarg($this->root() . '/firewall.php') . ' -d display_errors=1';
            $vars = [
                'REDIRECT_STATUS' => '200',
                'REQUEST_METHOD' => 'GET',
                'SCRIPT_FILENAME' => $target,
                'SCRIPT_NAME' => '/' . ($env['SCRIPT'] ?? 'index.php'),
                'REQUEST_URI' => $env['REQUEST_URI'] ?? '/',
                'QUERY_STRING' => $env['QUERY_STRING'] ?? '',
                'REMOTE_ADDR' => $env['REMOTE_ADDR'] ?? '198.51.100.20',
                'HTTP_USER_AGENT' => $env['HTTP_USER_AGENT'] ?? 'Test',
                'HTTP_COOKIE' => $env['HTTP_COOKIE'] ?? '',
            ];
            $vars += ['SystemRoot' => (string) getenv('SystemRoot'), 'PATH' => (string) getenv('PATH'), 'TEMP' => (string) getenv('TEMP')];
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir, $vars);
            self::assertIsResource($proc);
            fclose($pipes[0]);
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            self::assertSame('', trim($err), $err);
            [$head, $body] = array_pad(explode("\r\n\r\n", $out, 2), 2, '');
            $status = preg_match('/^Status: (\d+)/m', $head, $m) === 1 ? (int) $m[1] : 200;
            return ['status' => $status, 'body' => $body];
        }

        public function test_engine_refuses_blocked_addresses_before_wordpress(): void
        {
            self::assertIsArray(SP\set_prepend(true));
            SP\add_block('203.0.113.9', 60, 'manual', 'manual');
            SP\add_block('203.0.113.50', 60, 'lockout', 'login_lockout');
            SP\set_feed(['blocklist' => [['ip' => '192.0.2.0/24']]]);
            SP\configure(['allowlist' => ['203.0.113.77'], 'xmlrpc' => 'off']);
            SP\add_block('203.0.113.77', 60, 'manual', 'manual');

            $r = $this->request(['REMOTE_ADDR' => '198.51.100.20']);
            self::assertSame(['status' => 200, 'body' => 'passed'], $r);

            $r = $this->request(['REMOTE_ADDR' => '203.0.113.9']);
            self::assertSame(403, $r['status']);
            self::assertStringContainsString('Access blocked', $r['body']);
            self::assertStringContainsString('pre-', $r['body']);

            // Reputation ranges, and XML-RPC when it is off.
            self::assertSame(403, $this->request(['REMOTE_ADDR' => '192.0.2.200'])['status']);
            self::assertSame(403, $this->request(['REMOTE_ADDR' => '198.51.100.20', 'SCRIPT' => 'xmlrpc.php', 'REQUEST_URI' => '/xmlrpc.php'])['status']);

            // A login lockout only guards the login paths; the allowlist beats a block.
            self::assertSame(200, $this->request(['REMOTE_ADDR' => '203.0.113.50'])['status']);
            self::assertSame(403, $this->request(['REMOTE_ADDR' => '203.0.113.50', 'SCRIPT' => 'wp-login.php', 'REQUEST_URI' => '/wp-login.php'])['status']);
            self::assertSame(200, $this->request(['REMOTE_ADDR' => '203.0.113.77'])['status']);

            // Someone with a WordPress session is left to WordPress.
            self::assertSame(200, $this->request(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_COOKIE' => 'wordpress_logged_in_abc=x'])['status']);

            // The blocks were logged and turn into events.
            self::assertFileExists($this->root() . '/events.log');
            self::assertSame(4, SP\ingest_prepend_events());
            $rules = array_column(array_filter(SP\events(20), static fn($e) => str_contains($e['detail'], 'before WordPress')), 'rule');
            sort($rules);
            self::assertSame(['login_lockout', 'manual', 'reputation', 'xmlrpc_off'], $rules);
        }

        public function test_engine_applies_firewall_rules_only_in_block_mode(): void
        {
            self::assertIsArray(SP\set_prepend(true));
            SP\set_feed(['rules' => [['id' => 'r1', 'label' => 'Feed rule', 'in' => ['ua'], 'pattern' => '^EvilBot/']]]);
            $probe = ['REQUEST_URI' => '/?f=../../etc/passwd', 'QUERY_STRING' => 'f=../../etc/passwd'];
            self::assertSame(200, $this->request($probe)['status'], 'log mode: WordPress decides');
            self::assertSame(200, $this->request(['HTTP_USER_AGENT' => 'EvilBot/2'])['status']);
            SP\configure(['firewall' => 'block']);
            self::assertSame(403, $this->request($probe)['status']);
            self::assertSame(403, $this->request(['HTTP_USER_AGENT' => 'EvilBot/2'])['status']);
            self::assertSame(200, $this->request(['HTTP_USER_AGENT' => 'GoodBot/2'])['status']);
            self::assertSame(200, $this->request(['HTTP_USER_AGENT' => 'EvilBot/2', 'HTTP_COOKIE' => 'wordpress_logged_in_abc=x'])['status']);
        }

        public function test_engine_survives_a_broken_state_file(): void
        {
            self::assertIsArray(SP\set_prepend(true));
            SP\add_block('203.0.113.9', 60, 'manual', 'manual');
            file_put_contents($this->root() . '/state.php', "<?php return 'junk';\n");
            self::assertSame(200, $this->request(['REMOTE_ADDR' => '203.0.113.9'])['status']);
            unlink($this->root() . '/state.php');
            self::assertSame(200, $this->request(['REMOTE_ADDR' => '203.0.113.9'])['status']);
        }
    }
}
