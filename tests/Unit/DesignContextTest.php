<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot\Design\Context;

/**
 * What the site says to every agent, before the agent does anything.
 *
 * This block is the only part of the design system that arrives without being
 * asked for. A well-written page brief carries a palette, the ladders, the
 * compositions worth using and the standards a finished page owes — and then
 * works for exactly the one page somebody pasted it into. This rides along with
 * every request, including the small ones nobody writes a brief for.
 *
 * That reach is also the risk, and it is the thing these tests defend. Text
 * here costs on every session on every install, so it earns its place by
 * staying small; and it is read as instruction, so nothing that came out of a
 * design file may appear in it unfenced.
 */
final class DesignContextTest extends TestCase
{
    /**
     * A budget, not a preference.
     *
     * Every line added here is paid for on every session of every install, and
     * the failure mode is invisible: nobody notices instructions growing until
     * they are crowding out the rest of the context. Raise it deliberately or
     * cut something.
     */
    private const STANDARDS_BUDGET_BYTES = 1800;

    public function test_the_standards_stay_inside_their_budget(): void
    {
        $bytes = strlen(implode("\n", Context\standards()));

        self::assertLessThan(
            self::STANDARDS_BUDGET_BYTES,
            $bytes,
            "The build standards are {$bytes} bytes and ride on every request. Cut one before adding another.",
        );
    }

    /**
     * The standards are the site's rules, not the design file's.
     *
     * They are rendered outside the fence that quarantines a DESIGN.md, and an
     * agent told to distrust that block must not have to work out which half of
     * it is safe to act on. A fence marker in here would blur exactly that line.
     */
    public function test_the_standards_carry_no_fence_markers(): void
    {
        foreach (Context\standards() as $line) {
            self::assertStringNotContainsString('```', $line, 'a standard must not open or close the quarantine fence');
        }
    }

    /**
     * Each one has to name something an agent can act on. A standard nobody can
     * check is decoration in a place that charges rent.
     */
    public function test_every_standard_is_actionable(): void
    {
        $bullets = array_values(array_filter(
            Context\standards(),
            static fn(string $line): bool => str_starts_with($line, '- '),
        ));

        self::assertGreaterThanOrEqual(6, count($bullets));
        foreach ($bullets as $bullet) {
            self::assertGreaterThan(40, strlen($bullet), 'a standard too short to say why is a slogan');
        }
    }

    // ------------------------------------------------------------ cold start

    /**
     * With no design saved, saying nothing is the worst option available: the
     * agent builds the average of everything it has seen and nobody finds out
     * until the page exists.
     */
    public function test_the_cold_start_names_the_way_out(): void
    {
        $block = Context\cold_start();

        self::assertStringContainsString('wppilot/generate-design', $block);
        self::assertStringContainsString('wppilot/adopt-design-from-site', $block);
        self::assertStringContainsString('wppilot/save-design', $block);
    }

    public function test_the_cold_start_is_short(): void
    {
        self::assertLessThan(1200, strlen(Context\cold_start()));
    }

    // -------------------------------------------------------------- ladders

    /**
     * A ladder is read by a person as often as by an agent, and 144.00px in a
     * scale reads as machine output rather than as a decision somebody made.
     */
    public function test_a_scale_value_reads_as_a_number(): void
    {
        self::assertSame('144', Context\px(144.0));
        self::assertSame('8', Context\px(8.0));
        self::assertSame('13.5', Context\px(13.5));
        self::assertSame('0.5', Context\px(0.5));
    }
}
