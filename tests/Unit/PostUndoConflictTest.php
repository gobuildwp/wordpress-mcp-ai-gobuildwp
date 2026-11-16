<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;
use WPPilot_Test_State;

/**
 * Undoing a post change used to put the whole before-image back over any edit made after it.
 */
final class PostUndoConflictTest extends TestCase
{
    private const ROLLBACK = ['type' => 'restore-post', 'reversible' => true, 'snapshot' => ['type' => 'post', 'post' => ['ID' => 4]]];

    protected function setUp(): void
    {
        WPPilot_Test_State::reset();
        $post = new WP_Post();
        $post->ID = 4;
        $post->post_type = 'post';
        $post->post_content = 'Written by the agent';
        WPPilot_Test_State::$posts[4] = $post;
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::reset();
    }

    public function test_a_post_row_outside_a_session_records_the_state_it_left(): void
    {
        $row = \wppilot_change_row_with_session(['kind' => 'change', 'rollback' => self::ROLLBACK]);

        self::assertArrayNotHasKey('session', $row);
        self::assertIsString($row['after']['fingerprint'] ?? null);
    }

    public function test_undo_is_refused_when_the_post_was_edited_since(): void
    {
        $row = \wppilot_change_row_with_session(['kind' => 'change', 'rollback' => self::ROLLBACK]);
        self::assertNull(\wppilot_change_changed_since_error($row, self::ROLLBACK));

        WPPilot_Test_State::$posts[4]->post_content = 'Edited by a person afterwards';
        $error = \wppilot_change_changed_since_error($row, self::ROLLBACK);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('wppilot_change_changed_since', $error->get_error_code());
        self::assertStringContainsString('post.post_content', $error->get_error_message());
        self::assertStringContainsString('force=true', $error->get_error_message());
    }

    public function test_rows_from_before_the_check_and_other_types_are_not_checked(): void
    {
        self::assertNull(\wppilot_change_changed_since_error([], self::ROLLBACK));
        self::assertNull(\wppilot_change_changed_since_error(['after' => ['fingerprint' => 'x', 'parts' => []]], ['type' => 'restore-settings']));
    }
}
