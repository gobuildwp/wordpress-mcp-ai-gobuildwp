<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Abilities\Gutenberg as G;
use WPPilot\Abilities\Gutenberg\Markdown as M;

// Loaded here, not in the bootstrap, so the queue's ability registrations stay out of the
// boot snapshot the surface tests assert against.
require_once dirname(__DIR__, levels: 2) . '/includes/abilities/gutenberg/bootstrap.php';
require_once dirname(__DIR__, levels: 2) . '/includes/abilities/gutenberg/block-path.php';
require_once dirname(__DIR__, levels: 2) . '/includes/abilities/gutenberg/markdown.php';

/**
 * gutenberg-edit-block's addressing and edit rules, the raw specs it stores for the Block
 * Editor Queue, and markdown-to-blocks' conversion.
 */
final class GutenbergBlockEditTest extends TestCase
{
    /**
     * parse_blocks() output for: paragraph, whitespace, group(columns(column(paragraph), column(heading))).
     *
     * @return list<array<string, mixed>>
     */
    private static function tree(): array
    {
        $leaf = static fn(string $name, string $html, array $attrs = []): array => [
            'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => $html, 'innerContent' => [$html],
        ];
        $wrap = static fn(string $name, array $inner): array => [
            'blockName' => $name, 'attrs' => [], 'innerBlocks' => $inner, 'innerHTML' => '<div></div>',
            'innerContent' => array_merge(['<div>'], array_fill(0, count($inner), null), ['</div>']),
        ];

        return [
            $leaf('core/paragraph', '<p>Intro</p>'),
            ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "\n\n", 'innerContent' => ["\n\n"]],
            $wrap('core/group', [$wrap('core/columns', [
                $wrap('core/column', [$leaf('core/paragraph', '<p>Left</p>')]),
                $wrap('core/column', [$leaf('core/heading', '<h2>Right</h2>', ['level' => 2])]),
            ])]),
        ];
    }

    public function testPathsAreListsOfIndexesWithinTheDepthLimit(): void
    {
        self::assertTrue(G\block_path_is_valid([2, 0, 1]));
        self::assertFalse(G\block_path_is_valid([]));
        self::assertFalse(G\block_path_is_valid([1, -1]));
        self::assertFalse(G\block_path_is_valid(['1']));
        self::assertFalse(G\block_path_is_valid([1 => 0]));
        self::assertFalse(G\block_path_is_valid(array_fill(0, G\BLOCK_PATH_MAX_DEPTH + 1, 0)));
    }

    public function testBlockAtPathWalksInnerBlocks(): void
    {
        $tree = self::tree();
        self::assertSame('<h2>Right</h2>', G\block_at_path($tree, [2, 0, 1, 0])['innerHTML']);
        self::assertSame('core/column', G\block_at_path($tree, [2, 0, 0])['blockName']);
        self::assertNull(G\block_at_path($tree, [2, 0, 5]));
        self::assertNull(G\block_at_path($tree, [0, 0]), 'a leaf has no children');
        self::assertTrue(G\is_whitespace_freeform(G\block_at_path($tree, [1])));
    }

    public function testReplaceTouchesOnlyTheAddressedBlock(): void
    {
        $tree = self::tree();
        $edited = G\edit_parsed_block(G\block_at_path($tree, [2, 0, 1, 0]), ['level' => 3, 'textAlign' => 'center'], null);
        $after = G\replace_block_at_path($tree, [2, 0, 1, 0], $edited);

        self::assertSame(['level' => 3, 'textAlign' => 'center'], G\block_at_path($after, [2, 0, 1, 0])['attrs']);
        self::assertSame($tree[0], $after[0]);
        self::assertSame(G\block_at_path($tree, [2, 0, 0]), G\block_at_path($after, [2, 0, 0]));
        self::assertSame($tree, G\replace_block_at_path($tree, [9], $edited), 'a missing path changes nothing');
    }

    public function testNullAttributeRemovesItAndInnerHtmlOnlyReplacesLeaves(): void
    {
        $tree = self::tree();
        $heading = G\edit_parsed_block(G\block_at_path($tree, [2, 0, 1, 0]), ['level' => null], '<h2>New</h2>');
        self::assertSame([], $heading['attrs']);
        self::assertSame(['<h2>New</h2>'], $heading['innerContent']);

        $refused = G\edit_parsed_block($tree[2], null, '<div>flattened</div>');
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('gutenberg_edit_container_html', $refused->get_error_code());
    }

    public function testEditorPathSkipsTopLevelWhitespace(): void
    {
        $tree = self::tree();
        self::assertSame([1, 0, 1, 0], G\editor_block_path($tree, [2, 0, 1, 0]));
        self::assertSame([0], G\editor_block_path($tree, [0]));
    }

    public function testOnlyRegisteredDynamicBlocksWithoutMarkupAreWrittenDirectly(): void
    {
        $dynamic = ['blockName' => 'core/latest-posts', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
        self::assertTrue(G\block_saves_no_markup($dynamic, true));
        self::assertFalse(G\block_saves_no_markup($dynamic, false));
        self::assertFalse(G\block_saves_no_markup(self::tree()[0], true), 'saved markup needs the editor to regenerate');
    }

    public function testRawSpecsAreStoredOnlyWhenTheQueueAllowsThem(): void
    {
        $raw = [['raw' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', 'patch' => ['path' => [0], 'name' => 'core/paragraph', 'attributes' => ['dropCap' => true]]]];

        $agent = G\normalize_blocks($raw);
        self::assertInstanceOf(WP_Error::class, $agent, 'agent block_spec may not carry raw markup');

        $stored = G\normalize_blocks($raw, allow_raw: true);
        self::assertSame($raw, $stored);

        $plain = G\normalize_blocks([['raw' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'], ['name' => 'core/separator']], allow_raw: true);
        self::assertSame(['raw' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'], $plain[0]);
        self::assertSame('core/separator', $plain[1]['name']);

        self::assertInstanceOf(WP_Error::class, G\normalize_blocks([['raw' => '  ']], allow_raw: true));
        self::assertInstanceOf(WP_Error::class, G\normalize_blocks([['raw' => '<p>x</p>', 'patch' => ['path' => []]]], allow_raw: true));
    }

    public function testMarkdownBlockStructure(): void
    {
        $md = "# Title\n\nFirst line\nsame paragraph.\n\n- one\n- two\n  - nested\n\n3. three\n4. four\n\n> quoted\n\n---\n\n```php\necho '<b>';\n```\n\n![Alt](https://example.com/a.png)\n";
        $blocks = M\convert($md)['blocks'];

        self::assertSame(['core/heading', 'core/paragraph', 'core/list', 'core/list', 'core/quote', 'core/separator', 'core/code', 'core/image'], array_column($blocks, 'name'));
        self::assertSame(['level' => 1, 'content' => 'Title'], $blocks[0]['attributes']);
        self::assertSame('First line same paragraph.', $blocks[1]['attributes']['content']);

        $bullets = $blocks[2];
        self::assertSame([], $bullets['attributes']);
        self::assertSame(['one', 'two'], array_map(static fn(array $item): string => $item['attributes']['content'], $bullets['innerBlocks']));
        self::assertSame('nested', $bullets['innerBlocks'][1]['innerBlocks'][0]['innerBlocks'][0]['attributes']['content']);

        self::assertSame(['ordered' => true, 'start' => 3], $blocks[3]['attributes']);
        self::assertSame('quoted', $blocks[4]['innerBlocks'][0]['attributes']['content']);
        self::assertSame('echo &#039;&lt;b&gt;&#039;;', $blocks[6]['attributes']['content']);
        self::assertSame(['url' => 'https://example.com/a.png', 'alt' => 'Alt'], $blocks[7]['attributes']);
    }

    public function testMarkdownTables(): void
    {
        $blocks = M\convert("| Plan | Price |\n|:--|--:|\n| Free | 0 |\n| Pro \\| Team | **9** |\n")['blocks'];
        self::assertSame('core/table', $blocks[0]['name']);
        self::assertSame(['Plan', 'Price'], array_column($blocks[0]['attributes']['head'][0]['cells'], 'content'));
        self::assertSame(['Pro | Team', '<strong>9</strong>'], array_column($blocks[0]['attributes']['body'][1]['cells'], 'content'));
    }

    public function testInlineMarkdownIsEscapedAndOnlySafeUrlsLink(): void
    {
        self::assertSame(
            '<strong>Bold</strong>, <em>it</em>, <s>gone</s>, <code>a &lt; b</code> and <a href="https://example.com/?a=1&amp;b=2">a link</a>',
            M\inline('**Bold**, *it*, ~~gone~~, `a < b` and [a link](https://example.com/?a=1&b=2)'),
        );
        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', M\inline('<script>alert(1)</script>'));
        self::assertSame('[x](javascript:alert(1))', M\inline('[x](javascript:alert(1))'));
        self::assertSame('<a href="/about/">About</a> <a href="#top">top</a>', M\inline('[About](/about/) [top](#top)'));
        self::assertSame('snake_case_name and 2*3*4', M\inline('snake_case_name and 2*3*4'));
        self::assertSame('*literal*', M\inline('\\*literal\\*'));
        self::assertNotEmpty(M\convert('<div>hi</div>')['warnings']);
    }
}
