<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/sandbox/guards.php';

/**
 * Which files must stay in the PHP sandbox.
 *
 * Only `.php` used to count, so write-file put agent-authored code into wp-content/uploads as
 * .phtml or .phar - extensions stock Debian and Ubuntu PHP packages execute - where a request
 * runs it outside the WordPress bootstrap and the safety policy.
 */
final class SandboxPhpExtensionTest extends TestCase
{
    #[DataProvider('phpPaths')]
    public function testPhpExecutablePathsNeedTheSandbox(string $path): void
    {
        self::assertTrue(\wppilot_path_requires_php_sandbox($path));
    }

    /** @return array<string, array{0: string}> */
    public static function phpPaths(): array
    {
        return [
            'php' => ['/var/www/wp-content/uploads/a.php'],
            'phtml' => ['/var/www/wp-content/uploads/a.phtml'],
            'phar' => ['/var/www/wp-content/uploads/a.phar'],
            'php3' => ['/var/www/wp-content/uploads/a.php3'],
            'php5' => ['/var/www/wp-content/uploads/a.php5'],
            'php7' => ['/var/www/wp-content/uploads/a.php7'],
            'php8' => ['/var/www/wp-content/uploads/a.php8'],
            'pht' => ['/var/www/wp-content/uploads/a.pht'],
            'phps' => ['/var/www/wp-content/uploads/a.phps'],
            'upper case' => ['/var/www/wp-content/uploads/a.PHP'],
            'mixed case' => ['/var/www/wp-content/uploads/a.PhTmL'],
            'windows trailing dot' => ['C:/inetpub/wp-content/uploads/a.php.'],
            'windows trailing space' => ['C:/inetpub/wp-content/uploads/a.phtml '],
            'htaccess' => ['/var/www/wp-content/uploads/.htaccess'],
            'user.ini' => ['/var/www/wp-content/uploads/.user.ini'],
            'web.config' => ['/var/www/wp-content/uploads/web.config'],
        ];
    }

    #[DataProvider('otherPaths')]
    public function testOtherFilesDoNot(string $path): void
    {
        self::assertFalse(\wppilot_path_requires_php_sandbox($path));
    }

    /** @return array<string, array{0: string}> */
    public static function otherPaths(): array
    {
        return [
            'css' => ['/var/www/wp-content/themes/x/style.css'],
            'json' => ['/var/www/wp-content/uploads/data.json'],
            'html' => ['/var/www/wp-content/uploads/a.html'],
            'phpt-like name, not extension' => ['/var/www/wp-content/uploads/php.txt'],
            'photo' => ['/var/www/wp-content/uploads/photo.png'],
            'phtml in the directory name only' => ['/var/www/wp-content/uploads/a.phtml/readme.txt'],
            'no extension' => ['/var/www/wp-content/uploads/README'],
        ];
    }

    #[DataProvider('extensions')]
    public function testExtensionClassification(string $extension, bool $expected): void
    {
        self::assertSame($expected, \wppilot_is_php_extension($extension));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function extensions(): array
    {
        return [
            'php' => ['php', true],
            'PHAR' => ['PHAR', true],
            'php74' => ['php74', true],
            'phpx' => ['phpx', false],
            'html' => ['html', false],
            'empty' => ['', false],
            'ph' => ['ph', false],
        ];
    }
}
