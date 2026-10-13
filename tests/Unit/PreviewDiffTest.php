<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function WPPilot\Preview\Diff\compare;

/**
 * The differ decides what a person is shown before they approve a write, so the
 * rules that suppress false positives matter as much as the ones that report a
 * change. A diff that cries wolf on every unrelated save is a diff nobody reads.
 */
final class PreviewDiffTest extends TestCase
{
    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $terms
     * @return array<string, mixed>
     */
    private static function snapshot(array $post = [], array $meta = [], array $terms = []): array
    {
        return [
            'type' => 'post',
            'post' => [...['ID' => 7, 'post_title' => 'About', 'post_content' => 'body'], ...$post],
            'meta' => $meta,
            'terms' => $terms,
            'excluded_meta_keys' => [],
        ];
    }

    /** @param array<string, mixed> $diff */
    private static function labels(array $diff): array
    {
        return array_map(static fn(array $entry): string => (string) $entry['path_label'], $diff['entries']);
    }

    // ------------------------------------------------------------ the basics

    public function testAChangedFieldIsReported(): void
    {
        $diff = compare(self::snapshot(), self::snapshot(['post_title' => 'About us']));

        self::assertSame(1, $diff['changed_count']);
        self::assertSame(['post.post_title'], self::labels($diff));
        self::assertSame('changed', $diff['entries'][0]['op']);
        self::assertSame('About', $diff['entries'][0]['before']);
        self::assertSame('About us', $diff['entries'][0]['after']);
    }

    public function testAnIdenticalWriteReportsNothing(): void
    {
        $diff = compare(self::snapshot(), self::snapshot());

        self::assertSame(0, $diff['changed_count']);
        self::assertSame([], $diff['entries']);
        self::assertFalse($diff['destroys']);
    }

    public function testARemovedFieldMarksTheDiffDestructive(): void
    {
        $diff = compare(self::snapshot(meta: ['color' => ['blue']]), self::snapshot());

        self::assertTrue($diff['destroys']);
        self::assertSame('removed', $diff['entries'][0]['op']);
    }

    // ------------------------------------------------- rule 1: volatile fields

    /**
     * WordPress rewrites these on every save. Reporting them would put a
     * phantom entry in literally every preview.
     */
    public function testVolatilePostColumnsAreNeverReported(): void
    {
        $before = self::snapshot(['post_modified' => '2026-01-01 00:00:00', 'guid' => 'a', 'filter' => 'raw']);
        $after = self::snapshot(['post_modified' => '2026-08-19 12:00:00', 'guid' => 'b', 'filter' => 'display']);

        $diff = compare($before, $after);

        self::assertSame(0, $diff['changed_count'], 'volatile columns must not diff');
    }

    public function testVolatileSuppressionDoesNotHideRealChangesBesideIt(): void
    {
        $before = self::snapshot(['post_modified' => '2026-01-01 00:00:00']);
        $after = self::snapshot(['post_modified' => '2026-08-19 12:00:00', 'post_title' => 'Renamed']);

        self::assertSame(['post.post_title'], self::labels(compare($before, $after)));
    }

    // ----------------------------------------------------- rule 3: meta shape

    /**
     * Input meta is `key => value`; a snapshot reads back `key => [value]`.
     * Comparing the two shapes directly would report every meta write as a
     * change even when the value is identical.
     */
    public function testScalarMetaAndSingleElementListAreTheSameValue(): void
    {
        $diff = compare(self::snapshot(meta: ['size' => ['large']]), self::snapshot(meta: ['size' => 'large']));

        self::assertSame(0, $diff['changed_count']);
    }

    /**
     * update_post_meta(5) reads back '5'. Without coercion every integer meta
     * write reports 5 -> 5 as a change.
     */
    public function testIntegerAndStringMetaAreTheSameStoredValue(): void
    {
        $diff = compare(self::snapshot(meta: ['count' => ['5']]), self::snapshot(meta: ['count' => [5]]));

        self::assertSame(0, $diff['changed_count']);
    }

    public function testAGenuineMetaChangeIsStillReported(): void
    {
        $diff = compare(self::snapshot(meta: ['size' => ['large']]), self::snapshot(meta: ['size' => ['small']]));

        self::assertSame(['meta.size'], self::labels($diff));
        self::assertSame('large', $diff['entries'][0]['before']);
        self::assertSame('small', $diff['entries'][0]['after']);
    }

    // ---------------------------------------------------- rule 4: terms as set

    /**
     * wp_get_object_terms() ordering is not meaningful, so comparing order
     * would report churn across every taxonomy on an unrelated write.
     */
    public function testTermOrderIsNotAChange(): void
    {
        $diff = compare(
            self::snapshot(terms: ['category' => [3, 1, 2]]),
            self::snapshot(terms: ['category' => [1, 2, 3]]),
        );

        self::assertSame(0, $diff['changed_count']);
    }

    public function testAddingATermIsAChange(): void
    {
        $diff = compare(
            self::snapshot(terms: ['category' => [1, 2]]),
            self::snapshot(terms: ['category' => [1, 2, 3]]),
        );

        self::assertSame(['terms.category'], self::labels($diff));
    }

    // ------------------------------------------------------ rule 6: redaction

    /**
     * A projected write to a credential-shaped key must never put the new value
     * into an MCP response or an admin screen.
     */
    public function testSensitiveKeyValuesAreRedactedOnBothSides(): void
    {
        $diff = compare(
            self::snapshot(meta: ['stripe_api_key' => ['old-value']]),
            self::snapshot(meta: ['stripe_api_key' => ['sk-live-should-never-appear']]),
        );

        $encoded = (string) wp_json_encode($diff);
        self::assertStringNotContainsString('sk-live-should-never-appear', $encoded);
        self::assertStringNotContainsString('old-value', $encoded);
        self::assertTrue($diff['entries'][0]['redacted']);
    }

    /**
     * A key the snapshot never captured has no knowable before value, so
     * claiming one would be a fabrication.
     */
    public function testAnUncapturedSensitiveKeyIsReportedAsUnknown(): void
    {
        $diff = compare(
            self::snapshot(),
            self::snapshot(meta: ['token' => ['new']]),
            ['token'],
        );

        self::assertSame('unknown', $diff['entries'][0]['op']);
        self::assertSame('sensitive_key_not_captured', $diff['entries'][0]['reason']);
        self::assertNull($diff['entries'][0]['before']);
    }

    // ------------------------------------------------------------ bounding

    public function testTheEntryListIsCappedAndTheRemainderCounted(): void
    {
        $before = [];
        $after = [];
        for ($i = 0; $i < 250; $i++) {
            $before['field_' . $i] = ['a'];
            $after['field_' . $i] = ['b'];
        }

        $diff = compare(self::snapshot(meta: $before), self::snapshot(meta: $after));

        self::assertCount(200, $diff['entries']);
        self::assertTrue($diff['truncated']);
        self::assertSame(50, $diff['dropped_count']);
        self::assertSame(250, $diff['changed_count'], 'the count reports everything, not just what fits');
    }

    public function testAnOversizeValueIsTruncatedForDisplayButStillFlagged(): void
    {
        $long = str_repeat('x', 70000);
        $diff = compare(self::snapshot(['post_content' => 'short']), self::snapshot(['post_content' => $long]));

        self::assertTrue($diff['entries'][0]['value_truncated']);
        self::assertSame(70000, $diff['entries'][0]['after_bytes'], 'the real size is reported, not the shown size');
        self::assertLessThan(70000, strlen((string) $diff['entries'][0]['after']));
    }

    // ------------------------------------------------------------ settings

    public function testSettingsSnapshotsDiffOnTheirValuesSubtree(): void
    {
        $diff = compare(
            ['type' => 'settings', 'values' => ['blogname' => 'Old', 'blogdescription' => 'Same']],
            ['type' => 'settings', 'values' => ['blogname' => 'New', 'blogdescription' => 'Same']],
        );

        self::assertSame(['values.blogname'], self::labels($diff));
    }

    public function testEntriesAreOrderedByPathSoTheScreenIsStable(): void
    {
        $diff = compare(
            self::snapshot(meta: ['zeta' => ['1'], 'alpha' => ['1']]),
            self::snapshot(meta: ['zeta' => ['2'], 'alpha' => ['2']]),
        );

        self::assertSame(['meta.alpha', 'meta.zeta'], self::labels($diff));
    }
}
