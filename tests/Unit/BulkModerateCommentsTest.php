<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot_Test_Comments;
use WPPilot_Test_State;

use function WPPilot\Abilities\WordPress\wordpress_bulk_moderate_comments;

/**
 * wppilot/bulk-moderate-comments: preview changes nothing, apply needs the previewed count back,
 * spam and trash need confirm, and every changed comment gets its own undoable ledger row.
 */
final class BulkModerateCommentsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    /** @var list<string> */
    private array $savedCaps = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/doubles/comments.php';
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedCaps = WPPilot_Test_State::$capabilities;
        unset(WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION]);
        WPPilot_Test_State::$capabilities = ['moderate_comments'];
        WPPilot_Test_Comments::reset();
        WPPilot_Test_Comments::add(1, '0', content: 'Buy cheap pills');
        WPPilot_Test_Comments::add(2, '0', content: 'Nice post');
        WPPilot_Test_Comments::add(3, '1', content: 'Buy cheap watches');
        WPPilot_Test_Comments::add(4, '0', post_id: 9, content: 'Buy now');
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        WPPilot_Test_State::$capabilities = $this->savedCaps;
        WPPilot_Test_Comments::reset();
    }

    public function test_the_ability_is_registered(): void
    {
        self::assertContains('wppilot/bulk-moderate-comments', WPPILOT_TEST_BOOT_ABILITIES);
    }

    public function test_preview_counts_the_matches_and_changes_nothing(): void
    {
        $result = wordpress_bulk_moderate_comments(['action' => 'approve', 'filter' => ['search' => 'Buy']]);

        self::assertIsArray($result);
        self::assertTrue($result['preview']);
        // Comment 3 is already approved, so it is not counted.
        self::assertSame([1, 4], $result['comment_ids']);
        self::assertSame(2, $result['count']);
        self::assertSame(2, $result['total']);
        self::assertCount(2, $result['samples']);
        self::assertSame('Buy cheap pills', $result['samples'][0]['excerpt']);
        self::assertStringContainsString('expected_count: 2', $result['next_step']);
        self::assertFalse($result['confirm_required']);
        self::assertSame('0', WPPilot_Test_Comments::$comments[1]->comment_approved);
        self::assertSame([], \wppilot_get_change_log());
    }

    public function test_apply_without_the_previewed_count_is_refused(): void
    {
        $missing = wordpress_bulk_moderate_comments(['action' => 'approve', 'filter' => ['status' => ['hold']], 'preview' => false]);
        $stale = wordpress_bulk_moderate_comments(['action' => 'approve', 'filter' => ['status' => ['hold']], 'preview' => false, 'expected_count' => 2]);

        self::assertInstanceOf(WP_Error::class, $missing);
        self::assertSame('wppilot_bulk_comments_unpreviewed', $missing->get_error_code());
        self::assertInstanceOf(WP_Error::class, $stale);
        self::assertSame('wppilot_bulk_comments_count_changed', $stale->get_error_code());
        self::assertStringContainsString('3 comment(s) match now', $stale->get_error_message());
        self::assertSame('0', WPPilot_Test_Comments::$comments[2]->comment_approved);
    }

    public function test_spam_needs_confirm_and_says_what_it_will_do(): void
    {
        $result = wordpress_bulk_moderate_comments([
            'action' => 'spam',
            'comment_ids' => [1, 4],
            'preview' => false,
            'expected_count' => 2,
        ]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_confirmation_required', $result->get_error_code());
        self::assertStringContainsString('Marks 2 comment(s) as spam', $result->get_error_message());
        self::assertSame('0', WPPilot_Test_Comments::$comments[1]->comment_approved);
    }

    public function test_apply_writes_one_undoable_row_per_comment_under_one_group(): void
    {
        $result = wordpress_bulk_moderate_comments([
            'action' => 'spam',
            'comment_ids' => [1, 3, 4, 99],
            'preview' => false,
            'expected_count' => 3,
            'confirm' => true,
        ]);

        self::assertIsArray($result);
        self::assertSame(3, $result['changed']);
        self::assertSame('approved', $result['comments'][1]['previous_status']);
        self::assertSame('spam', WPPilot_Test_Comments::$comments[3]->comment_approved);
        $log = \wppilot_get_change_log();
        self::assertCount(3, $log);
        self::assertSame([$result['group'], $result['group'], $result['group']], array_column($log, 'group'));
        self::assertSame('restore-comment-status', $log[1]['rollback']['type']);
        self::assertTrue($log[1]['rollback']['reversible']);
    }

    public function test_undo_puts_a_previously_approved_comment_back_to_approved(): void
    {
        $result = wordpress_bulk_moderate_comments([
            'action' => 'unapprove',
            'comment_ids' => [3],
            'preview' => false,
            'expected_count' => 1,
        ]);
        self::assertIsArray($result);
        self::assertSame('0', WPPilot_Test_Comments::$comments[3]->comment_approved);

        $log = \wppilot_get_change_log();
        $restored = \wppilot_restore_comment_status($log[0]['rollback']['snapshot']);

        self::assertIsArray($restored);
        self::assertSame('1', WPPilot_Test_Comments::$comments[3]->comment_approved, 'approved, not held');
        self::assertSame('approved', $restored['status']);
    }

    public function test_a_filter_past_the_batch_size_reports_what_remains(): void
    {
        WPPilot_Test_Comments::reset();
        for ($id = 1; $id <= 105; $id++) {
            WPPilot_Test_Comments::add($id, '0');
        }

        $preview = wordpress_bulk_moderate_comments(['action' => 'approve', 'filter' => []]);
        self::assertIsArray($preview);
        self::assertSame(105, $preview['total']);
        self::assertSame(100, $preview['count']);
        self::assertArrayHasKey('note', $preview);

        $applied = wordpress_bulk_moderate_comments(['action' => 'approve', 'filter' => [], 'preview' => false, 'expected_count' => 100]);
        self::assertIsArray($applied);
        self::assertSame(100, $applied['changed']);
        self::assertSame(5, $applied['remaining']);

        $next = wordpress_bulk_moderate_comments(['action' => 'approve', 'filter' => []]);
        self::assertIsArray($next);
        self::assertSame(range(101, 105), $next['comment_ids']);
    }

    public function test_a_failed_write_is_reported_and_not_recorded(): void
    {
        WPPilot_Test_Comments::$failing = [2];

        $result = wordpress_bulk_moderate_comments(['action' => 'approve', 'comment_ids' => [1, 2], 'preview' => false, 'expected_count' => 2]);

        self::assertIsArray($result);
        self::assertSame(1, $result['changed']);
        self::assertSame(2, $result['failed'][0]['comment_id']);
        self::assertCount(1, \wppilot_get_change_log());
    }

    public function test_scope_and_capability_are_checked(): void
    {
        $both = wordpress_bulk_moderate_comments(['action' => 'approve', 'comment_ids' => [1], 'filter' => []]);
        self::assertInstanceOf(WP_Error::class, $both);
        self::assertSame('wppilot_bulk_comments_scope', $both->get_error_code());

        WPPilot_Test_State::$capabilities = [];
        $denied = wordpress_bulk_moderate_comments(['action' => 'approve', 'comment_ids' => [1]]);
        self::assertInstanceOf(WP_Error::class, $denied);
        self::assertSame('cannot_moderate_comments', $denied->get_error_code());
    }
}
