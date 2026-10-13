<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function WPPilot\Mcp\legacy_image_result;
use function WPPilot\Mcp\tool_result;

use const WPPilot\Mcp\RESULT_CONTENT_KEY;

/**
 * The `_mcp_content` convention: an ability result carrying an image becomes an MCP image block
 * on both MCP paths, and never rides along as base64 text.
 */
final class TransportImageContentTest extends TestCase
{
    private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public function testTheKeyIsTheDocumentedOne(): void
    {
        self::assertSame('_mcp_content', RESULT_CONTENT_KEY);
    }

    public function testAnImageItemBecomesAnImageBlockAfterTheText(): void
    {
        $result = tool_result([
            'attachment_id' => 7,
            '_mcp_content' => [['type' => 'image', 'data' => self::PIXEL, 'mimeType' => 'image/png']],
        ]);

        self::assertCount(2, $result['content']);
        self::assertSame('text', $result['content'][0]['type']);
        self::assertSame(['type' => 'image', 'data' => self::PIXEL, 'mimeType' => 'image/png'], $result['content'][1]);
        self::assertFalse($result['isError']);
    }

    public function testTheImageIsNotRepeatedInTheTextOrTheStructuredResult(): void
    {
        $result = tool_result([
            'attachment_id' => 7,
            '_mcp_content' => [['type' => 'image', 'data' => self::PIXEL, 'mimeType' => 'image/png']],
        ]);

        self::assertSame(['attachment_id' => 7], $result['structuredContent']);
        self::assertStringNotContainsString(self::PIXEL, $result['content'][0]['text']);
        self::assertStringNotContainsString('_mcp_content', $result['content'][0]['text']);
    }

    public function testAResultWithoutTheKeyIsUnchanged(): void
    {
        $result = tool_result(['ok' => true]);

        self::assertSame([['type' => 'text', 'text' => "{\n    \"ok\": true\n}"]], $result['content']);
        self::assertSame(['ok' => true], $result['structuredContent']);
    }

    public function testAStringResultIsUnchanged(): void
    {
        self::assertSame([['type' => 'text', 'text' => 'done']], tool_result('done')['content']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidItems(): array
    {
        return [
            'not an image' => [['type' => 'audio', 'data' => self::PIXEL, 'mimeType' => 'audio/wav']],
            'svg is not accepted' => [['type' => 'image', 'data' => self::PIXEL, 'mimeType' => 'image/svg+xml']],
            'missing mime type' => [['type' => 'image', 'data' => self::PIXEL]],
            'not base64' => [['type' => 'image', 'data' => '<script>', 'mimeType' => 'image/png']],
            'empty data' => [['type' => 'image', 'data' => '', 'mimeType' => 'image/png']],
            'a string' => ['image'],
        ];
    }

    #[DataProvider('invalidItems')]
    public function testAnInvalidItemIsDroppedNotPassedThroughAsText(mixed $item): void
    {
        $result = tool_result(['id' => 1, '_mcp_content' => [$item]]);

        self::assertCount(1, $result['content']);
        self::assertSame(['id' => 1], $result['structuredContent']);
    }

    public function testLegacyExecuteAbilityResultBecomesTheAdapterImageShape(): void
    {
        $converted = legacy_image_result([
            'success' => true,
            'data' => [
                'attachment_id' => 7,
                '_mcp_content' => [['type' => 'image', 'data' => self::PIXEL, 'mimeType' => 'image/png']],
            ],
        ]);

        self::assertIsArray($converted);
        self::assertSame('image', $converted['type']);
        self::assertSame('image/png', $converted['mimeType']);
        self::assertSame(base64_decode(self::PIXEL, true), $converted['results']);
    }

    public function testLegacyDirectToolResultIsConvertedToo(): void
    {
        $converted = legacy_image_result([
            '_mcp_content' => [['type' => 'image', 'data' => self::PIXEL, 'mimeType' => 'image/webp']],
        ]);

        self::assertIsArray($converted);
        self::assertSame('image/webp', $converted['mimeType']);
    }

    public function testLegacyResultWithoutAValidImageDropsTheKeyAndKeepsTheRest(): void
    {
        $converted = legacy_image_result([
            'success' => true,
            'data' => ['id' => 3, '_mcp_content' => [['type' => 'image', 'data' => '!!', 'mimeType' => 'image/png']]],
        ]);

        self::assertSame(['success' => true, 'data' => ['id' => 3]], $converted);
    }

    public function testLegacyResultsWithoutTheKeyAndFailuresAreUntouched(): void
    {
        $plain = ['success' => true, 'data' => ['id' => 3]];
        $failed = ['success' => false, 'error' => 'nope'];

        self::assertSame($plain, legacy_image_result($plain));
        self::assertSame($failed, legacy_image_result($failed));
        self::assertNull(legacy_image_result(null));
    }
}
