<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, levels: 2) . '/includes/filesystem.php';
require_once dirname(__DIR__, levels: 2) . '/includes/abilities/read-file.php';

/**
 * A partial read that ends inside a multi-byte character used to come back as base64, so
 * GoBuildWP Cloud's AI cleanup, which reads files in 12 KB pages, dropped those files. The
 * incomplete character is now left for the next page and bytes_read says where it starts.
 */
final class ReadFileUtf8BoundaryTest extends TestCase
{
    private string $name = '';
    private string $file = '';

    protected function setUp(): void
    {
        // Relative to ABSPATH (the tests folder), so the path resolves on every OS without a filter.
        $this->name = 'gbw-read-file-' . bin2hex(random_bytes(6)) . '.txt';
        $this->file = ABSPATH . $this->name;
        // "aé" is 3 bytes: a limit of 2 ends between the two bytes of é.
        file_put_contents($this->file, str_repeat("plain text\n", 5) . "aé b\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testPageEndingInsideACharacterStaysUtf8AndPagesJoinUp(): void
    {
        $split = strlen(str_repeat("plain text\n", 5)) + 2;

        $first = wppilot_read_file(['path' => $this->name, 'offset' => 0, 'limit' => $split]);
        self::assertIsArray($first);
        self::assertSame('utf-8', $first['encoding']);
        self::assertTrue($first['truncated']);
        self::assertSame($split - 1, $first['bytes_read']);

        $rest = wppilot_read_file(['path' => $this->name, 'offset' => $first['bytes_read'], 'limit' => -1]);
        self::assertIsArray($rest);
        self::assertSame('utf-8', $rest['encoding']);
        self::assertSame((string) file_get_contents($this->file), $first['content'] . $rest['content']);
    }

    public function testInvalidUtf8ThatIsNotATruncatedTailIsStillBase64(): void
    {
        file_put_contents($this->file, "ok \xC3\x28 bad\n");

        $result = wppilot_read_file(['path' => $this->name, 'offset' => 0, 'limit' => -1]);
        self::assertIsArray($result);
        self::assertSame('base64', $result['encoding']);
    }
}
