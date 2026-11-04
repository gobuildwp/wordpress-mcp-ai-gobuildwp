<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SecurityIntegrity;

require_once __DIR__ . '/harness.php';

use PHPUnit\Framework\TestCase;

use function GoBuildWP\Kits\SecurityIntegrity\builtin_rules;

/**
 * Host malware scanners (Imunify360, ClamAV, a host's own) quarantine any file whose source holds a
 * known malware signature. A quarantined plugin file can no longer be read, and the site fails with
 * "Failed to open stream: Permission denied". So no shipped file may match our own serious rules or
 * the host patterns below. Detection code writes these names bracketed (`e[v]al`) for that reason.
 */
final class ScannerSignaturesTest extends TestCase
{
    /** Files that hold a signature on purpose, with the reason. */
    private const ALLOWED = [
        // The execute-php ability runs PHP the site owner sends; it is not in the wp.org build.
        'includes/abilities/execute-php.php' => 'host.eval_call',
    ];

    /** What host scanners match on, bracketed so this file is not flagged itself. */
    private const HOST = [
        'host.decoder_call' => '/(?<![\w>:$\\\\])(?:g[z]inflate|g[z]uncompress|s[t]r_rot13|c[o]nvert_uudecode)\s*\(/i',
        'host.eval_call' => '/(?<![\w>:$\\\\])(?:e[v]al|c[r]eate_function)\s*\(/i',
        'host.assert_input' => '/(?<![\w>:$\\\\])a[s]sert\s*\(\s*\$_/i',
        'host.webshell_name' => '/\b(?:F[i]lesMan|c[9]9shell|r[5]7shell|b[3]74k|I[n]doXploit|A[n]onymousFox|a[l]fa\s*shell|0[b]yt3m1n1)\b/i',
    ];

    public function testNoShippedFileCarriesAMalwareSignature(): void
    {
        $root = dirname(__DIR__, 4);
        $patterns = self::HOST;
        foreach (builtin_rules() as $id => [$pattern, $severity]) {
            if (in_array($severity, ['high', 'critical'], true)) {
                $patterns[$id] = $pattern;
            }
        }

        $found = [];
        $scanned = 0;
        foreach ($this->shipped_files($root) as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            $source = (string) file_get_contents($path);
            $scanned++;
            foreach ($patterns as $id => $pattern) {
                if (preg_match($pattern, $source) === 1 && (self::ALLOWED[$relative] ?? null) !== $id) {
                    $found[] = $relative . ': ' . $id;
                }
            }
        }

        self::assertGreaterThan(100, $scanned);
        self::assertSame([], $found, 'Files a host malware scanner would quarantine.');
    }

    /** @return iterable<string> */
    private function shipped_files(string $root): iterable
    {
        yield $root . '/wppilot.php';
        yield $root . '/uninstall.php';
        foreach (['includes', 'assets', 'vendor'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && preg_match('/\.(?:php|js|mjs|inc|phtml)$/', $file->getFilename()) === 1 && $file->getSize() < 4_000_000) {
                    yield $file->getPathname();
                }
            }
        }
    }
}
