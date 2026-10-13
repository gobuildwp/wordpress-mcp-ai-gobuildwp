<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * delete-comment bypasses the trash, so its ledger row must say it cannot be undone.
 */
final class DeleteCommentLedgerTest extends TestCase
{
    /**
     * Its before-image is a comment-status snapshot (shared with moderate-comment), and
     * the generic status branch used to file it as a reversible status restore. The
     * Changes screen and session undo then offered to undo a comment that no longer
     * existed, and the rollback failed with wppilot_rollback_target_missing.
     */
    public function test_a_permanent_comment_delete_is_recorded_as_irreversible(): void
    {
        $before = [
            'type' => 'comment-status',
            'values' => ['comment_id' => 7, 'status' => 'approved'],
            'fingerprint' => 'x',
        ];

        $payload = \wppilot_build_rollback_payload('wppilot/delete-comment', $before, [
            'comment_id' => 7,
            'result' => 'deleted',
            'reversible' => false,
        ]);

        self::assertFalse($payload['reversible']);
        self::assertSame('The comment was permanently deleted.', $payload['reason']);
    }

    public function test_moderating_a_comment_stays_reversible(): void
    {
        $before = [
            'type' => 'comment-status',
            'values' => ['comment_id' => 7, 'status' => 'approved'],
            'fingerprint' => 'x',
        ];

        $payload = \wppilot_build_rollback_payload('wppilot/moderate-comment', $before, ['comment_id' => 7]);

        self::assertTrue($payload['reversible']);
        self::assertSame('restore-comment-status', $payload['type']);
    }
}
