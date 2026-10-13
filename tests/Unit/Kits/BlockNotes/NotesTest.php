<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\BlockNotes;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\BlockNotes;
use WPPilot\Kits\BlockNotes\Blocks;

/**
 * The review loop's rules over an in-memory store: what each write sends to WordPress, what the
 * ledger keeps for its undo, and that each undo restores and verifies.
 */
final class NotesTest extends TestCase
{
    private const CONTENT = "<!-- wp:heading -->\n<h2 id=\"intro\">Intro</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Body</p>\n<!-- /wp:paragraph -->";

    private FakeStore $store;

    private RecordingLedger $ledger;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/block-notes/bootstrap.php';
        require_once __DIR__ . '/FakeStore.php';
        foreach (['list', 'add', 'reply', 'resolve'] as $verb) {
            require_once dirname(__DIR__, 4) . '/includes/kits/block-notes/src/abilities/' . $verb . '-block-note' . ($verb === 'list' ? 's' : '') . '.php';
        }
    }

    protected function setUp(): void
    {
        $this->store = new FakeStore();
        $this->store->add_post(10, self::CONTENT);
        BlockNotes\store($this->store);
        $this->ledger = new RecordingLedger();
        BlockNotes\register_ledger($this->ledger);
    }

    public function testTheFourAbilitiesAreRegistered(): void
    {
        foreach (['wppilot/list-block-notes', 'wppilot/add-block-note', 'wppilot/reply-block-note', 'wppilot/resolve-block-note'] as $name) {
            self::assertTrue(wp_has_ability($name), $name);
        }
    }

    /**
     * An open thread by the agent, anchored in the block's delimiter, then the author notified.
     */
    public function testAddInsertsAnOpenThreadAndAnchorsIt(): void
    {
        $result = BlockNotes\add_note(['post_id' => 10, 'block_path' => '1', 'block_name' => 'core/paragraph', 'content' => '  Is this claim sourced?  ']);

        self::assertSame(['post_id' => 10, 'parent' => 0, 'content' => 'Is this claim sourced?', 'approved' => '0'], $this->store->inserted[0]);
        self::assertSame(101, $result['note_id']);
        self::assertSame('open', $result['status']);
        self::assertTrue($result['by_agent']);
        self::assertSame(['path' => '1', 'name' => 'core/paragraph', 'anchor' => '', 'label' => '', 'excerpt' => 'Body', 'note_ids' => []], $result['block']);
        self::assertStringContainsString('<!-- wp:paragraph {"metadata":{"noteId":[101]}} -->', $this->store->posts[10]['content']);
        self::assertSame(['insert:101', 'content:10'], $this->store->log);
        self::assertSame([101], $this->store->notified);
        self::assertArrayNotHasKey('warning', $result);
    }

    public function testAddByAnchorAcceptsAShortBlockNameAndWarnsAboutAnOpenEditor(): void
    {
        $this->store->lock = 'Grace';

        $result = BlockNotes\add_note(['post_id' => 10, 'block_anchor' => 'intro', 'block_name' => 'heading', 'content' => 'Shorter?']);

        self::assertSame('0', $result['block']['path']);
        self::assertStringContainsString('Grace has this post open', $result['warning']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusals(): array
    {
        return [
            'stale block name' => [['block_path' => '1', 'block_name' => 'core/image'], 'kit_block_notes_block_changed'],
            'no such block' => [['block_path' => '5'], 'kit_block_notes_block_not_found'],
            'neither path nor anchor' => [[], 'kit_block_notes_block_required'],
            'empty note' => [['block_path' => '1', 'content' => '   '], 'kit_block_notes_empty'],
            'unknown post' => [['post_id' => 99, 'block_path' => '1'], 'kit_block_notes_post_not_found'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testAddRefusesWithoutWriting(array $input, string $code): void
    {
        $result = BlockNotes\add_note($input + ['post_id' => 10, 'content' => 'x']);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame($code, $result->get_error_code());
        self::assertSame([], $this->store->log);
    }

    public function testNotesNeedEditAccessASupportingTypeAndWordPress71(): void
    {
        $this->store->editable = [];
        self::assertSame('kit_block_notes_forbidden', BlockNotes\add_note(['post_id' => 10, 'block_path' => '1', 'content' => 'x'])->get_error_code());
        self::assertFalse(BlockNotes\can_note(['post_id' => 10]));

        $this->store->editable = [10];
        $this->store->note_types = ['post'];
        self::assertSame('kit_block_notes_unsupported', BlockNotes\list_notes(['post_id' => 10])->get_error_code());

        $this->store->note_types = ['page'];
        $this->store->writes = false;
        self::assertSame('kit_block_notes_needs_71', BlockNotes\add_note(['post_id' => 10, 'block_path' => '1', 'content' => 'x'])->get_error_code());
        self::assertIsArray(BlockNotes\list_notes(['post_id' => 10]), 'reading still works');
        self::assertSame([], $this->store->log);
    }

    /**
     * If the anchor does not land, the note is deleted: a note shown apart from its block is not
     * what was asked for.
     */
    public function testAnAnchorThatDoesNotLandTakesTheNoteBack(): void
    {
        $this->store->update_error = new WP_Error('db', 'Database error');

        $result = BlockNotes\add_note(['post_id' => 10, 'block_path' => '1', 'content' => 'x']);

        self::assertSame('kit_block_notes_anchor_failed', $result->get_error_code());
        self::assertSame(['insert:101', 'content:10', 'delete:101'], $this->store->log);
        self::assertSame([], $this->store->notes);
        self::assertSame([], $this->store->notified);
    }

    public function testReplyGoesToTheThreadWithItsStatus(): void
    {
        $this->store->add_note(50, 10, 0, '1');
        $this->store->add_note(51, 10, 50, '1');

        $result = BlockNotes\reply_note(['note_id' => 51, 'content' => 'Done: rewrote the intro.']);

        self::assertSame(['post_id' => 10, 'parent' => 50, 'content' => 'Done: rewrote the intro.', 'approved' => '1'], $this->store->inserted[0]);
        self::assertSame(50, $result['thread_id']);
        self::assertSame('resolved', $result['thread_status']);
        self::assertSame('1', $this->store->notes[50]['status'], 'a reply does not reopen');
        self::assertTrue(BlockNotes\can_note(['note_id' => 51]));
    }

    /**
     * Resolve does what the editor's button does: the thread approved, plus an approved child
     * whose _wp_note_status is `resolved`.
     */
    public function testResolveSetsTheStatusAndAddsTheResolutionEntry(): void
    {
        $this->store->add_note(50, 10);

        $result = BlockNotes\resolve_note(['note_id' => 50, 'content' => 'Fixed the typo.']);

        self::assertSame(['status:50=1', 'insert:101'], $this->store->log);
        self::assertSame(['post_id' => 10, 'parent' => 50, 'content' => 'Fixed the typo.', 'approved' => '1', 'note_status' => 'resolved'], $this->store->inserted[0]);
        self::assertTrue($result['changed']);
        self::assertSame(101, $result['resolution_id']);

        $this->store->log = [];
        $again = BlockNotes\resolve_note(['note_id' => 50]);
        self::assertFalse($again['changed']);
        self::assertSame([], $this->store->log);
    }

    public function testAFailedResolutionEntryReopensTheThread(): void
    {
        $this->store->add_note(50, 10);
        $this->store->insert_error = new WP_Error('db', 'no');

        self::assertInstanceOf(WP_Error::class, BlockNotes\resolve_note(['note_id' => 50]));
        self::assertSame('0', $this->store->notes[50]['status']);
    }

    public function testListGroupsThreadsWithBlocksRepliesAndHistory(): void
    {
        $this->store->posts[10]['content'] = Blocks::add_note_id(self::CONTENT, Blocks::outline(self::CONTENT)[1], 50)
            . '<!-- wp:paragraph --><p>x <mark class="wp-note" data-id="60">y</mark></p><!-- /wp:paragraph -->';
        $this->store->add_note(50, 10, 0, '1');
        $this->store->add_note(51, 10, 50, '1', ['content' => 'Agreed', 'author_name' => 'Ada (AI agent)', 'by_agent' => true]);
        $this->store->add_note(52, 10, 50, '1', ['content' => '', 'note_status' => 'resolved']);
        $this->store->add_note(60, 10, 0, '0');
        $this->store->add_note(70, 11, 0, '0');

        $all = BlockNotes\list_notes(['post_id' => 10, 'include_blocks' => true]);

        self::assertSame(['open' => 1, 'resolved' => 1], $all['counts']);
        self::assertSame([50, 60], array_column($all['notes'], 'id'));
        $resolved = $all['notes'][0];
        self::assertSame('resolved', $resolved['status']);
        self::assertSame('1', $resolved['block']['path']);
        self::assertSame([['id' => 51, 'content' => 'Agreed', 'author' => ['id' => 2, 'name' => 'Ada (AI agent)', 'is_agent' => true], 'date_gmt' => $this->store->notes[51]['date_gmt']]], $resolved['replies']);
        self::assertSame('resolved', $resolved['history'][0]['event']);
        self::assertNull($all['notes'][1]['block'], 'no block carries note 60');
        self::assertTrue($all['notes'][1]['inline']);
        self::assertSame(['0', '1', '2'], array_column($all['blocks'], 'path'));

        self::assertSame([60], array_column(BlockNotes\list_notes(['post_id' => 10, 'status' => 'open'])['notes'], 'id'));
        self::assertArrayNotHasKey('blocks', BlockNotes\list_notes(['post_id' => 10]));
    }

    /**
     * The ledger payload for an added note names the note and post from the result; its undo
     * deletes the note and takes the ID off the block, then verifies both.
     */
    public function testAddedNoteUndoDeletesAndUnanchorsVerified(): void
    {
        $input = ['post_id' => 10, 'block_path' => '1', 'content' => 'Check this'];
        $result = BlockNotes\add_note($input);
        $payload = $this->ledger->payload_for('wppilot/add-block-note', $input, $result);

        self::assertSame(['note_id' => 101, 'post_id' => 10, 'reversible' => true, 'type' => 'kits/block-note'], $payload);

        $undone = $this->ledger->undo($payload);

        self::assertTrue($undone['verified']);
        self::assertArrayNotHasKey(101, $this->store->notes);
        self::assertSame(self::CONTENT, $this->store->posts[10]['content'], 'the block is back byte for byte');
    }

    public function testUndoIsRefusedOnceSomeoneReplied(): void
    {
        $result = BlockNotes\add_note(['post_id' => 10, 'block_path' => '1', 'content' => 'Check this']);
        $this->store->add_note(200, 10, 101, '0');

        $undone = $this->ledger->undo($this->ledger->payload_for('wppilot/add-block-note', [], $result));

        self::assertInstanceOf(WP_Error::class, $undone);
        self::assertSame('kit_block_notes_has_replies', $undone->get_error_code());
        self::assertArrayHasKey(101, $this->store->notes);
    }

    /**
     * A note someone already deleted in the editor still has its anchor removed, and the undo
     * reports the state it verified rather than failing.
     */
    public function testUndoOfANoteAlreadyDeletedStillCleansTheAnchor(): void
    {
        $result = BlockNotes\add_note(['post_id' => 10, 'block_path' => '1', 'content' => 'Check this']);
        unset($this->store->notes[101]);

        $undone = $this->ledger->undo($this->ledger->payload_for('wppilot/add-block-note', [], $result));

        self::assertTrue($undone['verified']);
        self::assertSame(self::CONTENT, $this->store->posts[10]['content']);
    }

    public function testReplyUndoDeletesOnlyTheReply(): void
    {
        $this->store->add_note(50, 10);
        $result = BlockNotes\reply_note(['note_id' => 50, 'content' => 'Answer']);
        $payload = $this->ledger->payload_for('wppilot/reply-block-note', [], $result);

        self::assertSame(['note_id' => 101, 'post_id' => 10, 'reversible' => true, 'type' => 'kits/block-note'], $payload);
        self::assertTrue($this->ledger->undo($payload)['verified']);
        self::assertSame([50], array_keys($this->store->notes));
        self::assertSame(self::CONTENT, $this->store->posts[10]['content']);
    }

    public function testResolveUndoReopensAndRemovesTheEntryVerified(): void
    {
        $this->store->add_note(50, 10);
        $result = BlockNotes\resolve_note(['note_id' => 50]);
        $payload = $this->ledger->payload_for('wppilot/resolve-block-note', ['note_id' => 50], $result);

        self::assertSame(['note_id' => 50, 'resolution_id' => 101, 'reversible' => true, 'type' => 'kits/block-note-resolution'], $payload);

        $undone = $this->ledger->undo($payload);

        self::assertTrue($undone['verified']);
        self::assertSame('0', $this->store->notes[50]['status']);
        self::assertArrayNotHasKey(101, $this->store->notes);
    }

    public function testNothingToUndoWhenResolveChangedNothingOrAddFailed(): void
    {
        $this->store->add_note(50, 10, 0, '1');

        $payload = $this->ledger->payload_for('wppilot/resolve-block-note', [], BlockNotes\resolve_note(['note_id' => 50]));
        self::assertFalse($payload['reversible']);
        self::assertStringContainsString('already resolved', $payload['reason']);

        self::assertFalse($this->ledger->payload_for('wppilot/add-block-note', [], ['note_id' => 0])['reversible']);
    }

    public function testResolveUndoFailsWhenTheThreadIsGone(): void
    {
        self::assertSame('kit_rollback_target_missing', BlockNotes\undo_resolved(['note_id' => 404, 'resolution_id' => 0])->get_error_code());
    }
}
