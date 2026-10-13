<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * One session writing one post through two before-image shapes: update-post keeps the whole post,
 * an SEO kit keeps only its own meta keys. The session's own second write must not read as
 * someone else's edit, and an edit that really is someone else's still must.
 */
final class SessionPlanOverlapTest extends TestCase
{
    private const WHOLE = 'post:12';

    private const PARTIAL = 'post-partial:12:m._yoast_wpseo_title';

    /**
     * @param array<string, string> $parts
     * @return array{fingerprint: string, parts: array<string, string>}
     */
    private static function digest(array $parts): array
    {
        ksort($parts);

        return ['fingerprint' => hash('sha256', (string) json_encode($parts)), 'parts' => $parts];
    }

    /**
     * @param array{fingerprint: string, parts: array<string, string>} $after
     * @param array{fingerprint: string, parts: array<string, string>}|null $undone
     * @return array<string, mixed>
     */
    private static function row(string $id, string $target, array $after, string $status = 'undoable', ?array $undone = null): array
    {
        return [
            'id' => $id,
            'ability' => 'wppilot/probe',
            'recorded_at' => '2026-09-30T00:00:00+00:00',
            'status' => $status,
            'reason' => '',
            'target' => $target,
            'after' => $after,
            'before' => null,
            'redo_available' => true,
            'redo_reason' => '',
            'redo' => null,
            'undone' => $undone,
        ];
    }

    public function testTheSessionsOwnMetaWriteAfterAWholePostWriteIsNotAConflict(): void
    {
        // update-post (older) left the title changed and the SEO title empty; the SEO write (newer)
        // then set the SEO title. The post now reads as both left it.
        $whole_after = self::digest(['post.post_title' => 'new', 'meta._yoast_wpseo_title' => 'empty']);
        $partial_after = self::digest(['meta._yoast_wpseo_title' => 'seo']);
        $rows = [self::row('b', self::PARTIAL, $partial_after), self::row('a', self::WHOLE, $whole_after)];
        $current = [
            self::PARTIAL => $partial_after,
            self::WHOLE => self::digest(['post.post_title' => 'new', 'meta._yoast_wpseo_title' => 'seo']),
        ];

        $plan = wppilot_session_plan_undo('s', $rows, $current);

        self::assertSame([], $plan['conflicts']);
        self::assertCount(2, $plan['pending']);
    }

    public function testAnotherEditToTheSamePostIsStillAConflict(): void
    {
        $whole_after = self::digest(['post.post_title' => 'new', 'post.post_content' => 'body', 'meta._yoast_wpseo_title' => 'empty']);
        $partial_after = self::digest(['meta._yoast_wpseo_title' => 'seo']);
        $rows = [self::row('b', self::PARTIAL, $partial_after), self::row('a', self::WHOLE, $whole_after)];
        $current = [
            self::PARTIAL => $partial_after,
            // A person also rewrote the content.
            self::WHOLE => self::digest(['post.post_title' => 'new', 'post.post_content' => 'edited', 'meta._yoast_wpseo_title' => 'seo']),
        ];

        $plan = wppilot_session_plan_undo('s', $rows, $current);

        self::assertCount(1, $plan['conflicts']);
        self::assertSame('a', $plan['conflicts'][0]['change_id']);
        self::assertContains('post.post_content', $plan['conflicts'][0]['changed']);
    }

    public function testAWriteToAnotherPostCoversNothing(): void
    {
        $whole_after = self::digest(['meta._yoast_wpseo_title' => 'empty']);
        $other_partial = 'post-partial:99:m._yoast_wpseo_title';
        $rows = [self::row('b', $other_partial, self::digest(['meta._yoast_wpseo_title' => 'seo'])), self::row('a', self::WHOLE, $whole_after)];
        $current = [
            $other_partial => self::digest(['meta._yoast_wpseo_title' => 'seo']),
            self::WHOLE => self::digest(['meta._yoast_wpseo_title' => 'seo']),
        ];

        self::assertCount(1, wppilot_session_plan_undo('s', $rows, $current)['conflicts']);
    }

    public function testStatesWithoutPartsNeverQualify(): void
    {
        $opaque = ['fingerprint' => 'x', 'parts' => []];
        self::assertFalse(wppilot_session_difference_is_own($opaque, ['fingerprint' => 'y', 'parts' => []], ['meta.a' => true]));
        self::assertSame('post:12', wppilot_change_target_object(self::PARTIAL));
        self::assertSame('kits/woo-basics-product:12:name', wppilot_change_target_object('kits/woo-basics-product:12:name'));
    }

    public function testRedoOfAWholePostWriteAfterTheSessionsOwnOlderMetaWriteIsNotAConflict(): void
    {
        // The SEO write (older) and then update-post (newer), both undone: update-post's undo left
        // the SEO title as the SEO write had set it, and the SEO undo then emptied it.
        $partial_after = self::digest(['meta._yoast_wpseo_title' => 'seo']);
        $partial_undone = self::digest(['meta._yoast_wpseo_title' => 'empty']);
        $whole_undone = self::digest(['post.post_title' => 'old', 'meta._yoast_wpseo_title' => 'seo']);
        $rows = [
            self::row('a', self::WHOLE, self::digest(['post.post_title' => 'new', 'meta._yoast_wpseo_title' => 'seo']), 'rolled-back', $whole_undone),
            self::row('b', self::PARTIAL, $partial_after, 'rolled-back', $partial_undone),
        ];
        $current = [
            self::PARTIAL => $partial_undone,
            self::WHOLE => self::digest(['post.post_title' => 'old', 'meta._yoast_wpseo_title' => 'empty']),
        ];

        $plan = wppilot_session_plan_redo('s', $rows, $current);

        self::assertSame([], $plan['conflicts']);
        self::assertCount(2, $plan['pending']);
    }
}
