<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace {
    if (!defined('WPPILOT_SANDBOX_DIR')) {
        define('WPPILOT_SANDBOX_DIR', sys_get_temp_dir() . '/wppilot-guard-sandbox-' . getmypid() . '/');
    }

    if (!function_exists('wp_remote_get')) {
        /** @param array<string, mixed> $args */
        function wp_remote_get(string $url, array $args = []): array|WP_Error
        {
            \WPPilot\Tests\Unit\GuardNet::$calls[] = $url;
            return array_shift(\WPPilot\Tests\Unit\GuardNet::$responses)
                ?? new WP_Error('http_request_failed', 'No network in tests.');
        }
    }
}

namespace WPPilot\Tests\Unit {
    use PHPUnit\Framework\TestCase;
    use WP_Error;

    require_once dirname(__DIR__) . '/doubles/admin.php';
    require_once dirname(__DIR__) . '/doubles/cloud.php';
    require_once dirname(__DIR__, 2) . '/includes/filesystem.php';
    require_once dirname(__DIR__, 2) . '/includes/sandbox/guards.php';
    require_once dirname(__DIR__, 2) . '/includes/file-write-guard.php';

    final class GuardNet
    {
        /** @var list<array<string, mixed>|WP_Error> */
        public static array $responses = [];

        /** @var list<string> */
        public static array $calls = [];

        /** @return array<string, mixed> */
        public static function ok(): array
        {
            return ['response' => ['code' => 200], 'body' => '<html>fine</html>'];
        }

        /** @return array<string, mixed> */
        public static function fatal(): array
        {
            return [
                'response' => ['code' => 500],
                'body' => '<p>There has been a critical error on this website.</p>',
            ];
        }
    }

    /**
     * The guarded write behind write-file, edit-file and enable-file: lint first, temp file and
     * rename, then a loopback check that puts the old bytes back when the site breaks.
     */
    final class FileWriteGuardTest extends TestCase
    {
        private string $dir = '';

        protected function setUp(): void
        {
            if (!function_exists('token_get_all')) {
                self::markTestSkipped('The tokenizer extension is not installed.');
            }
            $this->dir = rtrim(WPPILOT_SANDBOX_DIR, '/\\');
            if (!is_dir($this->dir)) {
                mkdir($this->dir, 0755, true);
            }
            GuardNet::$responses = [];
            GuardNet::$calls = [];
        }

        protected function tearDown(): void
        {
            foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        public function testSyntaxErrorIsReportedWithoutRunningTheCode(): void
        {
            self::assertNull(wppilot_php_syntax_error("<?php\necho 'ok';\n"));

            $error = wppilot_php_syntax_error("<?php\nfunction broken( {\n");
            self::assertIsArray($error);
            self::assertSame(2, $error['line']);
        }

        public function testUnparseablePhpIsRefusedAndTheFileIsLeftAlone(): void
        {
            $path = $this->dir . '/feature.php';
            file_put_contents($path, "<?php\n// original\n");

            $result = wppilot_guarded_write_file($path, "<?php\nif (true {\n");

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('php_syntax_error', $result->get_error_code());
            self::assertSame("<?php\n// original\n", file_get_contents($path));
            self::assertSame([], GuardNet::$calls, 'Nothing is probed for a write that never happened.');
        }

        public function testHealthyWriteIsKeptAndLeavesNoTemporaryFile(): void
        {
            $path = $this->dir . '/feature.php';
            GuardNet::$responses = [GuardNet::ok(), GuardNet::ok(), GuardNet::ok(), GuardNet::ok()];

            $result = wppilot_guarded_write_file($path, "<?php\necho 'new';\n");

            self::assertIsArray($result);
            self::assertTrue($result['lint']['checked']);
            self::assertTrue($result['verification']['checked']);
            self::assertTrue($result['verification']['site_ok']);
            self::assertSame("<?php\necho 'new';\n", file_get_contents($path));
            self::assertSame([], glob($this->dir . '/.*.tmp') ?: []);
            self::assertCount(4, GuardNet::$calls, 'Home and wp-admin, before and after.');
        }

        public function testFatalAfterWriteRestoresThePreviousBytes(): void
        {
            $path = $this->dir . '/feature.php';
            file_put_contents($path, "<?php\n// v1\n");
            GuardNet::$responses = [GuardNet::ok(), GuardNet::ok(), GuardNet::fatal(), GuardNet::ok()];

            $result = wppilot_guarded_write_file($path, "<?php\nundefined_function_call();\n");

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('write_rolled_back', $result->get_error_code());
            self::assertTrue($result->get_error_data()['restored']);
            self::assertSame("<?php\n// v1\n", file_get_contents($path));
        }

        public function testFatalAfterCreatingAFileRemovesIt(): void
        {
            $path = $this->dir . '/new-feature.php';
            GuardNet::$responses = [GuardNet::ok(), GuardNet::ok(), GuardNet::ok(), GuardNet::fatal()];

            $result = wppilot_guarded_write_file($path, "<?php
function twice() {}
");

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('write_rolled_back', $result->get_error_code());
            self::assertFileDoesNotExist($path);
        }

        public function testCrashMarkerTheBrokenFileCausedIsRemovedWithIt(): void
        {
            $path = $this->dir . '/feature.php';
            $marker = wppilot_file_guard_crash_marker();
            file_put_contents($path, "<?php
// v1
");

            $undo = static function () use ($path): bool {
                file_put_contents($path, "<?php
// v1
");
                return true;
            };
            // The sandbox loader writes its marker while the broken file loads during the probe.
            GuardNet::$responses = [GuardNet::fatal(), GuardNet::ok()];
            add_filter('https_local_ssl_verify', static function (bool $verify) use ($marker): bool {
                if (count(GuardNet::$calls) === 1) {
                    file_put_contents($marker, '{}');
                }
                return $verify;
            });

            $result = wppilot_file_guard_verify($path, $undo, null);

            unset($GLOBALS['wp_filter']['https_local_ssl_verify']);
            self::assertInstanceOf(WP_Error::class, $result);
            self::assertFileDoesNotExist($marker);
        }

        public function testUnreachableLoopbackKeepsTheWriteAndSaysSo(): void
        {
            $path = $this->dir . '/feature.php';

            $result = wppilot_guarded_write_file($path, "<?php\necho 1;\n");

            self::assertIsArray($result);
            self::assertFalse($result['verification']['checked']);
            self::assertSame('loopback_unreachable', $result['verification']['reason']);
            self::assertFileExists($path);
        }

        public function testSiteThatWasAlreadyFailingIsNotBlamedOnTheWrite(): void
        {
            $path = $this->dir . '/feature.php';
            GuardNet::$responses = [GuardNet::fatal(), GuardNet::fatal()];

            $result = wppilot_guarded_write_file($path, "<?php\necho 1;\n");

            self::assertIsArray($result);
            self::assertSame('site_already_failing', $result['verification']['reason']);
            self::assertSame("<?php\necho 1;\n", file_get_contents($path));
        }

        public function testPlainFilesAreWrittenAtomicallyWithoutAProbe(): void
        {
            $path = $this->dir . '/notes.txt';

            $result = wppilot_guarded_write_file($path, 'hello');

            self::assertIsArray($result);
            self::assertFalse($result['lint']['checked']);
            self::assertSame('not_applicable', $result['verification']['reason']);
            self::assertSame('hello', file_get_contents($path));
            self::assertSame([], GuardNet::$calls);
        }

        public function testFailureReasons(): void
        {
            self::assertSame('http_503', wppilot_file_guard_failure_reason(503, ''));
            self::assertSame('php_fatal', wppilot_file_guard_failure_reason(200, '<b>Fatal error</b>: Uncaught Error'));
            self::assertSame('', wppilot_file_guard_failure_reason(302, ''));
        }
    }
}
