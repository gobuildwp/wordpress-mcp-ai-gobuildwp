<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\BlockNotes;

use PHPUnit\Framework\TestCase;
use stdClass;
use WP_Error;
use WPPilot\Kits\BlockNotes\Blocks;

/**
 * Addressing blocks in stored content and writing the note anchor into exactly one delimiter.
 */
final class BlocksTest extends TestCase
{
    private const CONTENT = "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\" id=\"intro\">Intro</h2>\n<!-- /wp:heading -->\n\n"
        . "Some freeform text\n\n"
        . "<!-- wp:group {\"metadata\":{\"name\":\"Pricing\",\"noteId\":7},\"style\":{}} -->\n<div class=\"wp-block-group\">"
        . "<!-- wp:paragraph -->\n<p>First &amp; best</p>\n<!-- /wp:paragraph -->\n"
        . "<!-- wp:acme/card {\"metadata\":{\"noteId\":[8,9]},\"anchor\":\"card\"} /-->\n"
        . "<!-- wp:paragraph -->\n<p>Second</p>\n<!-- /wp:paragraph -->"
        . "</div>\n<!-- /wp:group -->";

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/block-notes/bootstrap.php';
    }

    /**
     * Paths count real blocks only, parents before children, as parse_blocks() nests them;
     * freeform text between blocks takes no index.
     */
    public function testOutlineAddressesEveryBlockByPath(): void
    {
        $outline = Blocks::outline(self::CONTENT);

        self::assertSame(['0', '1', '1.0', '1.1', '1.2'], array_column($outline, 'path'));
        self::assertSame(['core/heading', 'core/group', 'core/paragraph', 'acme/card', 'core/paragraph'], array_column($outline, 'name'));
        self::assertSame(['intro', '', '', 'card', ''], array_column($outline, 'anchor'), 'from the id attribute, or the anchor attribute');
        self::assertSame('Pricing', $outline[1]['label']);
        self::assertSame([[], [7], [], [8, 9], []], array_column($outline, 'note_ids'), 'a single ID (before 7.1) and a list both read');
        self::assertSame('First & best Second', $outline[1]['excerpt']);
        self::assertSame('First & best', $outline[2]['excerpt']);
    }

    public function testFindByPathOrAnchorAndRefuseAnythingElse(): void
    {
        $outline = Blocks::outline(self::CONTENT);

        self::assertSame('core/paragraph', Blocks::find($outline, '1.2', '')['name']);
        self::assertSame('1.1', Blocks::find($outline, '', 'card')['path']);
        foreach ([['', ''], ['1', 'card'], ['4', ''], ['', 'nope']] as [$path, $anchor]) {
            self::assertInstanceOf(WP_Error::class, Blocks::find($outline, $path, $anchor));
        }
        $twice = Blocks::outline('<!-- wp:a {"anchor":"x"} /--><!-- wp:b {"anchor":"x"} /-->');
        self::assertSame('kit_block_notes_block_ambiguous', Blocks::find($twice, '', 'x')->get_error_code());
    }

    /**
     * Only the target's delimiter changes; everything before and after it is byte-identical.
     */
    public function testAddingAnAnchorRewritesOnlyThatDelimiter(): void
    {
        $outline = Blocks::outline(self::CONTENT);
        $updated = Blocks::add_note_id(self::CONTENT, $outline[2], 42);

        $before = substr(self::CONTENT, 0, $outline[2]['start']);
        $after = substr(self::CONTENT, $outline[2]['start'] + $outline[2]['length']);
        self::assertSame($before . '<!-- wp:paragraph {"metadata":{"noteId":[42]}} -->' . $after, $updated);
        self::assertSame([42], Blocks::outline($updated)[2]['note_ids']);
    }

    public function testAnExistingSingleIdBecomesAListAndEmptyObjectsSurvive(): void
    {
        $outline = Blocks::outline(self::CONTENT);
        $updated = Blocks::add_note_id(self::CONTENT, $outline[1], 42);

        self::assertStringContainsString('<!-- wp:group {"metadata":{"name":"Pricing","noteId":[7,42]},"style":{}} -->', $updated);
    }

    public function testAVoidBlockStaysVoidAndAnIdIsNotAddedTwice(): void
    {
        $outline = Blocks::outline(self::CONTENT);
        $updated = Blocks::add_note_id(self::CONTENT, $outline[3], 9);

        self::assertSame(self::CONTENT, $updated);
        $added = Blocks::add_note_id(self::CONTENT, $outline[3], 10);
        self::assertStringContainsString('<!-- wp:acme/card {"metadata":{"noteId":[8,9,10]},"anchor":"card"} /-->', $added);
    }

    /**
     * Removing the anchor puts a block that had no metadata back exactly as it was, and keeps
     * whatever else a block's metadata holds.
     */
    public function testRemovingTheAnchorRestoresTheOriginalBytes(): void
    {
        $once = Blocks::add_note_id(self::CONTENT, Blocks::outline(self::CONTENT)[2], 42);
        $twice = Blocks::add_note_id($once, Blocks::outline($once)[1], 42);

        self::assertCount(2, Blocks::referencing($twice, 42));
        $removed = Blocks::remove_note_id($twice, 42);

        self::assertSame([], Blocks::referencing($removed, 42));
        self::assertSame(self::CONTENT, Blocks::remove_note_id($once, 42));
        self::assertStringContainsString('<!-- wp:group {"metadata":{"name":"Pricing","noteId":[7]},"style":{}} -->', $removed);
        self::assertStringContainsString('{"metadata":{"name":"Pricing"}', Blocks::remove_note_id(self::CONTENT, 7));
        self::assertStringContainsString('<!-- wp:acme/card {"metadata":{"noteId":[9]},"anchor":"card"} /-->', Blocks::remove_note_id(self::CONTENT, 8));
    }

    public function testAttributesAreEncodedAsCoreSerialisesThem(): void
    {
        $attrs = new stdClass();
        $attrs->text = 'a<b>--"c"&d\\e';

        self::assertSame('{"text":"a\\u003cb\\u003e\\u002d\\u002d\\u0022c\\u0022\\u0026d\\u005ce"}', Blocks::encode_attrs($attrs));
    }

    public function testInlineMarkersAreFoundByExactClassAndId(): void
    {
        $content = '<p>A <mark class="wp-note" data-id="12">phrase</mark> and <mark class="wp-note-foo" data-id="13">x</mark></p>';

        self::assertTrue(Blocks::has_inline_marker($content, 12));
        self::assertFalse(Blocks::has_inline_marker($content, 13));
        self::assertFalse(Blocks::has_inline_marker($content, 1));
    }
}
