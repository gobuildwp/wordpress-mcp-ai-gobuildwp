<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\BlockTheme;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\BlockTheme;

/**
 * block-theme: the pure parts - the theme.json merge, block markup validation and the
 * summaries agents read. Reads and writes go through WordPress's REST controllers, so they
 * are checked live over MCP rather than against doubles of those controllers.
 */
final class BlockThemeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!function_exists('parse_blocks')) {
            // Just enough of core's parser: block comments name blocks, anything else is freeform.
            eval('function parse_blocks(string $content): array {
                preg_match_all("#<!--\s+wp:([a-z0-9/-]+)#", $content, $m);
                return $m[1] === [] ? [["blockName" => null]] : array_map(static fn(string $n): array => ["blockName" => str_contains($n, "/") ? $n : "core/" . $n], $m[1]);
            }');
        }
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/block-theme/src/functions.php';
    }

    public function test_merge_replaces_extends_removes_and_replaces_lists_whole(): void
    {
        $base = [
            'color' => ['background' => '#fff', 'text' => '#111', 'palette' => [['slug' => 'a'], ['slug' => 'b']]],
            'typography' => ['fontSize' => '16px'],
        ];
        $patch = [
            'color' => ['text' => '#222', 'palette' => [['slug' => 'c']]],
            'typography' => null,
            'spacing' => ['blockGap' => '1rem'],
        ];

        self::assertSame([
            'color' => ['background' => '#fff', 'text' => '#222', 'palette' => [['slug' => 'c']]],
            'spacing' => ['blockGap' => '1rem'],
        ], BlockTheme\merge($base, $patch));
    }

    public function test_lists_are_told_apart_from_objects(): void
    {
        self::assertTrue(BlockTheme\array_is_list_compat([]));
        self::assertTrue(BlockTheme\array_is_list_compat([1, 2]));
        self::assertFalse(BlockTheme\array_is_list_compat([1 => 'a']));
        self::assertFalse(BlockTheme\array_is_list_compat(['a' => 1]));
    }

    public function test_markup_must_be_blocks_within_the_size_cap(): void
    {
        self::assertSame('<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->', BlockTheme\valid_markup('<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->'));
        foreach ([null, 7, '', '   ', '<p>plain HTML, no blocks</p>', str_repeat('<!-- wp:paragraph /-->', 30_000)] as $bad) {
            self::assertInstanceOf(WP_Error::class, BlockTheme\valid_markup($bad), var_export(is_string($bad) ? substr($bad, 0, 40) : $bad, true));
        }
    }

    public function test_template_summary_keeps_what_an_agent_needs(): void
    {
        $summary = BlockTheme\template_summary([
            'id' => 'tt5//header',
            'slug' => 'header',
            'title' => ['raw' => 'Header', 'rendered' => 'Header'],
            'source' => 'custom',
            'has_theme_file' => true,
            'area' => 'header',
            'wp_id' => 42,
            'content' => ['raw' => '<!-- wp:group /-->'],
        ]);

        self::assertSame(['id' => 'tt5//header', 'slug' => 'header', 'title' => 'Header', 'source' => 'custom', 'customized' => true, 'has_theme_file' => true, 'area' => 'header', 'wp_id' => 42], $summary);
        self::assertSame('x', BlockTheme\raw(['rendered' => 'x']));
        self::assertSame('', BlockTheme\raw(null));
    }

    public function test_template_ids_are_checked_before_any_request(): void
    {
        self::assertNull(BlockTheme\find_template('wp_template', 'no-slashes'));
        self::assertNull(BlockTheme\find_template('wp_template', '../../etc//passwd?x=1'));
        self::assertSame('/wp/v2/template-parts/tt5//footer', BlockTheme\template_route('wp_template_part', 'tt5//footer'));
        self::assertSame('wp_template', BlockTheme\template_type('anything else'));
    }
}
