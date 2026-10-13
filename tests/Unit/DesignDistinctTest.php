<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot\Design\Distinct;

use function WPPilot\Design\Distinct\color_distance;
use function WPPilot\Design\Distinct\compare;
use function WPPilot\Design\Distinct\palette_distance;
use function WPPilot\Design\Preflight\hex_to_hsl;

/**
 * Every other design rule judges a document on its own terms. This one asks
 * whether it is anything new, which is the failure that only shows up in the
 * aggregate: a design can satisfy the whole contract and still be the fourth
 * site this quarter with a navy ground and the same two faces.
 *
 * The numbers here are calibrated rather than chosen, so the tests pin the
 * calibration: what the thresholds sit between, that a comparison means the same
 * thing whichever design was written first, and that the report names the design
 * a person would actually point at.
 */
final class DesignDistinctTest extends TestCase
{
    // Two hex sets for the same decision, and a genuinely different one.
    private const NAVY = ['#0f1b3d', '#f4ecd8', '#c9873a'];
    private const NAVY_AGAIN = ['#101c40', '#f2ebd6', '#c88b3d'];
    private const FOREST = ['#12301f', '#eef3ea', '#7aa35c'];

    /**
     * @param list<string> $colors
     * @param list<string> $fonts
     * @return array{slug: string, name: string, colors: list<string>, fonts: list<string>}
     */
    private static function other(string $slug, array $colors, array $fonts): array
    {
        return ['slug' => $slug, 'name' => ucfirst($slug), 'colors' => $colors, 'fonts' => $fonts];
    }

    // ------------------------------------------------------- colour distance

    public function test_a_colour_is_no_distance_from_itself(): void
    {
        $navy = hex_to_hsl('#0f1b3d');
        self::assertNotNull($navy);
        self::assertSame(0.0, color_distance($navy, $navy));
    }

    /**
     * hex_to_hsl answers a positional [hue, saturation, lightness]. Reading it as
     * if it were keyed silently yields null for every component, which computes
     * as zero distance between every pair of colours — a check that passes
     * everything while appearing to work.
     */
    public function test_hsl_is_positional(): void
    {
        $hsl = hex_to_hsl('#0f1b3d');
        self::assertNotNull($hsl);
        self::assertArrayHasKey(0, $hsl);
        self::assertArrayNotHasKey('h', $hsl);
        self::assertEqualsWithDelta(224.0, $hsl[0], 1.0, 'hue in degrees');
    }

    /**
     * A grey has no hue worth comparing, so the hue term fades out as saturation
     * drops. The weight it gives up moves to saturation rather than evaporating:
     * a monochrome palette and a red one are not near-identical merely because
     * one of them has no hue to compare.
     */
    public function test_grey_and_red_are_far_apart_despite_grey_having_no_hue(): void
    {
        self::assertLessThan(0.05, palette_distance(['#4a4a4a'], ['#525252']), 'two greys');
        self::assertGreaterThan(0.4, palette_distance(['#4a4a4a'], ['#d92b2b']), 'grey against red');
    }

    // ------------------------------------------------------ palette distance

    public function test_the_same_decision_in_different_hex_values_is_near_zero(): void
    {
        self::assertLessThan(Distinct\NEAR_DUPLICATE, palette_distance(self::NAVY, self::NAVY_AGAIN));
    }

    public function test_unrelated_palettes_are_far_apart(): void
    {
        self::assertGreaterThan(Distinct\RELATIVES, palette_distance(self::NAVY, self::FOREST));
    }

    /**
     * Matching each colour to its nearest counterpart is directional, and the two
     * directions genuinely disagree: black/white/acid-green finds a close
     * neighbour for every one of its colours inside black/white/grey, while the
     * grey palette looks back and finds nothing near the acid. "Is this too close
     * to that" cannot have two answers depending on which was saved first.
     */
    public function test_distance_is_symmetric(): void
    {
        $mono = ['#1a1a1a', '#fafafa', '#8c8c8c'];
        $acid = ['#0a0a0a', '#f0f0f0', '#c8f542'];

        self::assertSame(palette_distance($mono, $acid), palette_distance($acid, $mono));

        // And symmetric the strict way: the accent one palette owns alone is
        // enough to keep the two apart, rather than being averaged away.
        self::assertGreaterThan(Distinct\NEAR_DUPLICATE, palette_distance($mono, $acid));
    }

    public function test_an_empty_palette_is_maximally_distant(): void
    {
        self::assertSame(1.0, palette_distance([], self::NAVY));
        self::assertSame(1.0, palette_distance(self::NAVY, []));
    }

    // -------------------------------------------------------------- verdicts

    public function test_nothing_to_compare_against_is_distinct(): void
    {
        $result = compare(self::NAVY, ['fraunces', 'public sans'], []);

        self::assertTrue($result['distinct']);
        self::assertSame(0, $result['compared']);
        self::assertNull($result['nearest']);
        self::assertSame([], $result['findings']);
    }

    public function test_same_palette_and_same_pairing_is_a_near_duplicate(): void
    {
        $result = compare(self::NAVY, ['fraunces', 'public sans'], [
            self::other('harbour', self::NAVY_AGAIN, ['fraunces', 'public sans']),
        ]);

        self::assertFalse($result['distinct']);
        self::assertSame('near-duplicate', $result['nearest']['verdict']);
        self::assertSame('design-near-duplicate', $result['findings'][0]['rule']);
    }

    public function test_same_palette_with_different_faces_reports_only_the_palette(): void
    {
        $result = compare(self::NAVY, ['fraunces', 'public sans'], [
            self::other('beacon', self::NAVY, ['bitter', 'karla']),
        ]);

        self::assertSame('same-palette', $result['nearest']['verdict']);
        self::assertSame('design-palette-repeat', $result['findings'][0]['rule']);
    }

    public function test_a_shared_pairing_over_a_related_palette_is_the_same_voice(): void
    {
        // Far enough apart to be a different palette, close enough to be kin.
        $teal = ['#1d3a34', '#ece7dd', '#d4674a'];
        $result = compare(self::NAVY, ['fraunces', 'public sans'], [
            self::other('estuary', $teal, ['fraunces', 'public sans']),
        ]);

        self::assertGreaterThan(Distinct\NEAR_DUPLICATE, $result['nearest']['palette_distance']);
        self::assertSame('same-voice', $result['nearest']['verdict']);
        self::assertSame('design-pairing-repeat', $result['findings'][0]['rule']);
    }

    public function test_an_unrelated_design_raises_nothing(): void
    {
        $result = compare(self::NAVY, ['fraunces', 'public sans'], [
            self::other('thicket', self::FOREST, ['syne', 'inter tight']),
        ]);

        self::assertTrue($result['distinct']);
        self::assertSame('distinct', $result['nearest']['verdict']);
        self::assertSame([], $result['findings']);
    }

    // --------------------------------------------------------------- ranking

    /**
     * Ranking on colour alone put a design that shared this one's exact hexes but
     * none of its typefaces ahead of the design that shared both, by a thousandth
     * of a point. The report then named that design, asked about *its* fonts, and
     * downgraded a real near-duplicate to a colour coincidence — so the one
     * finding worth raising was the one it could not raise.
     */
    public function test_the_nearest_design_is_the_most_alike_not_merely_the_closest_in_colour(): void
    {
        $result = compare(self::NAVY, ['fraunces', 'public sans'], [
            // Exactly this palette, unrelated faces: nearer in colour alone.
            self::other('beacon', self::NAVY, ['bitter', 'karla']),
            // A hair further in colour, but the same two faces.
            self::other('harbour', self::NAVY_AGAIN, ['fraunces', 'public sans']),
        ]);

        self::assertSame('harbour', $result['nearest']['slug']);
        self::assertSame('near-duplicate', $result['nearest']['verdict']);
    }

    public function test_the_reported_distance_is_the_true_palette_distance(): void
    {
        $result = compare(self::NAVY, ['fraunces', 'public sans'], [
            self::other('harbour', self::NAVY_AGAIN, ['fraunces', 'public sans']),
        ]);

        // Not the halved ranking score that the shared pairing earns.
        self::assertSame(
            round(palette_distance(self::NAVY, self::NAVY_AGAIN), 3),
            $result['nearest']['palette_distance'],
        );
    }

    public function test_partly_shared_fonts_are_not_a_shared_pairing(): void
    {
        $result = compare(self::NAVY, ['fraunces', 'public sans'], [
            // One face in common out of two: a coincidence, not a house style.
            self::other('beacon', self::NAVY, ['fraunces', 'karla']),
        ]);

        self::assertSame('same-palette', $result['nearest']['verdict']);
    }

    public function test_a_design_with_no_colours_is_skipped_rather_than_compared(): void
    {
        $result = compare(self::NAVY, ['fraunces', 'public sans'], [
            self::other('draft', [], ['fraunces', 'public sans']),
        ]);

        self::assertSame(0, $result['compared']);
        self::assertNull($result['nearest']);
    }

    // ----------------------------------------------------------- calibration

    /**
     * The thresholds only mean anything if nothing real falls either side of them
     * by accident. Measured across a spread of genuine palettes, the same
     * decision in different hex values lands at 0.01 and the closest genuinely
     * distinct pair at 0.13, leaving an empty band between. Both lines are
     * asserted against real palettes rather than against their own literals, so
     * changing the distance function moves the test with it.
     */
    public function test_the_thresholds_sit_in_an_empty_band(): void
    {
        self::assertGreaterThan(
            palette_distance(self::NAVY, self::NAVY_AGAIN),
            Distinct\NEAR_DUPLICATE,
            'the same decision must fall below the near-duplicate line',
        );
        self::assertLessThan(
            palette_distance(self::NAVY, self::FOREST),
            Distinct\RELATIVES,
            'genuinely different palettes must fall above the relatives line',
        );
        self::assertLessThan(Distinct\RELATIVES, Distinct\NEAR_DUPLICATE);
    }
}
