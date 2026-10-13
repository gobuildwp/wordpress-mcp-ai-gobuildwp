<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function WPPilot\Design\Capture\cell_distance;
use function WPPilot\Design\Capture\merge_regions;

require_once dirname(__DIR__, 2) . '/includes/design/capture.php';

/**
 * The comparison behind visual verification.
 *
 * Two failures decide whether this is usable. If it reports every page as
 * changed - which a pixel-exact comparison does, because fonts rasterise
 * differently and images get re-encoded - it gets switched off within a day. If
 * it reports a changed hero as unchanged, it is worse than nothing, because
 * somebody trusted it.
 *
 * So the assertions are about the middle: noise below the threshold stays
 * quiet, a real change is caught, and a changed area comes back as one region
 * rather than as two hundred cells.
 */
final class CaptureDiffTest extends TestCase
{
    // ------------------------------------------------------------- distance

    public function test_identical_colours_are_no_distance_apart(): void
    {
        self::assertSame(0.0, cell_distance([0.1, 0.2, 0.3], [0.1, 0.2, 0.3]));
    }

    public function test_black_against_white_is_the_whole_range(): void
    {
        self::assertSame(1.0, round(cell_distance([0.0, 0.0, 0.0], [1.0, 1.0, 1.0]), 4));
    }

    /**
     * A re-encoded screenshot differs from its original by a fraction of a
     * channel. Anything that treats that as a change reports every page as
     * changed, every time.
     */
    public function test_re_encoding_noise_stays_under_the_threshold(): void
    {
        $noise = cell_distance([0.50, 0.50, 0.50], [0.512, 0.506, 0.495]);

        self::assertLessThan(\WPPilot\Design\Capture\CAPTURE_DIFF_THRESHOLD, $noise);
    }

    public function test_a_colour_change_anybody_would_notice_clears_the_threshold(): void
    {
        // A heading that went from near-black to mid-grey.
        $real = cell_distance([0.08, 0.10, 0.12], [0.45, 0.45, 0.45]);

        self::assertGreaterThan(\WPPilot\Design\Capture\CAPTURE_DIFF_THRESHOLD, $real);
    }

    // --------------------------------------------------------------- regions

    public function test_adjacent_cells_become_one_region(): void
    {
        $cells = [
            ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'distance' => 0.4],
            ['x' => 10, 'y' => 0, 'width' => 10, 'height' => 10, 'distance' => 0.5],
            ['x' => 20, 'y' => 0, 'width' => 10, 'height' => 10, 'distance' => 0.3],
        ];

        $regions = merge_regions($cells);

        self::assertCount(1, $regions);
        self::assertSame(0, $regions[0]['x']);
        self::assertSame(30, $regions[0]['width']);
        self::assertSame(3, $regions[0]['cells']);
        // The region reports its worst cell: a region is as changed as the most
        // changed thing in it.
        self::assertSame(0.5, $regions[0]['distance']);
    }

    public function test_a_one_cell_gap_does_not_split_a_region(): void
    {
        // The space between two words in a changed headline is an unchanged
        // cell. Splitting there would turn one finding into two.
        $regions = merge_regions([
            ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'distance' => 0.4],
            ['x' => 20, 'y' => 0, 'width' => 10, 'height' => 10, 'distance' => 0.4],
        ]);

        self::assertCount(1, $regions);
    }

    public function test_changes_far_apart_stay_separate(): void
    {
        $regions = merge_regions([
            ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'distance' => 0.4],
            ['x' => 400, 'y' => 900, 'width' => 10, 'height' => 10, 'distance' => 0.4],
        ]);

        self::assertCount(2, $regions);
    }

    public function test_the_biggest_region_is_reported_first(): void
    {
        $regions = merge_regions([
            ['x' => 500, 'y' => 500, 'width' => 10, 'height' => 10, 'distance' => 0.9],
            ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'distance' => 0.2],
            ['x' => 10, 'y' => 0, 'width' => 10, 'height' => 10, 'distance' => 0.2],
            ['x' => 20, 'y' => 0, 'width' => 10, 'height' => 10, 'distance' => 0.2],
        ]);

        // Three cells beat one, even though the single cell changed more: a
        // large area that shifted slightly is the more useful finding.
        self::assertSame(3, $regions[0]['cells']);
    }

    public function test_nothing_changed_is_no_regions(): void
    {
        self::assertSame([], merge_regions([]));
    }
}
