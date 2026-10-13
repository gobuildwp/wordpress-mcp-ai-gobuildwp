<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot\Design\Seed;

/**
 * Every site looking alike is not a shortage of options: an aligned model
 * regresses to the most familiar answer, so more typefaces change nothing about
 * which three get picked. The fix is to stop asking, and derive the decision
 * from the site instead.
 *
 * That only works if derivation has two properties that pull against each
 * other. It must be stable, or a rebuild silently redesigns a live site. And it
 * must decorrelate, or every axis swings together and the whole set reads as one
 * theme rather than as a set of decisions. These pin both.
 */
final class DesignSeedTest extends TestCase
{
    private const A = 'https://harbour.example|Harbour Dental';
    private const B = 'https://kestrel.example|Kestrel Legal';

    public function test_the_same_seed_and_dimension_always_answer_the_same(): void
    {
        self::assertSame(Seed\unit(self::A, 'hue'), Seed\unit(self::A, 'hue'));
    }

    public function test_two_sites_diverge(): void
    {
        self::assertNotSame(Seed\unit(self::A, 'hue'), Seed\unit(self::B, 'hue'));
    }

    /**
     * Without named dimensions a site whose hue landed high would land high on
     * radius, density and everything else, and the design would swing as one
     * block. Adjacent dimension names are similar by construction, so the hash
     * has to decorrelate strings that differ by one character.
     */
    public function test_dimensions_do_not_move_together(): void
    {
        $hue = Seed\unit(self::A, 'hue');
        $near = Seed\unit(self::A, 'hue2');
        $other = Seed\unit(self::A, 'radius');

        self::assertGreaterThan(0.05, abs($hue - $near));
        self::assertGreaterThan(0.05, abs($hue - $other));
    }

    public function test_a_unit_is_within_range(): void
    {
        foreach (['hue', 'radius', 'density', 'scale', 'ground'] as $dimension) {
            $value = Seed\unit(self::A, $dimension);
            self::assertGreaterThanOrEqual(0.0, $value);
            self::assertLessThan(1.0, $value);
        }
    }

    // ------------------------------------------------------------- between

    public function test_between_stays_inside_its_range(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $value = Seed\between(self::A, 'band' . $i, 42.0, 74.0, 4.0);
            self::assertGreaterThanOrEqual(42.0, $value);
            self::assertLessThanOrEqual(74.0, $value);
        }
    }

    /**
     * A derived radius of 13.4791px reads as machine output the moment somebody
     * opens the panel, and the whole point is a design that could have been
     * decided by a person.
     */
    public function test_between_quantizes_to_the_step(): void
    {
        $value = Seed\between(self::A, 'corner', 16.0, 32.0, 4.0);

        self::assertSame(0.0, fmod($value - 16.0, 4.0));
    }

    public function test_an_empty_range_answers_its_only_value(): void
    {
        self::assertSame(12.0, Seed\between(self::A, 'fixed', 12.0, 12.0, 1.0));
    }

    // ---------------------------------------------------------------- pick

    public function test_pick_is_stable_and_in_the_list(): void
    {
        $items = ['paper', 'ink', 'tinted'];
        $first = Seed\pick(self::A, 'ground', $items);

        self::assertContains($first, $items);
        self::assertSame($first, Seed\pick(self::A, 'ground', $items));
    }

    public function test_picking_from_nothing_answers_nothing(): void
    {
        self::assertNull(Seed\pick(self::A, 'ground', []));
    }

    /**
     * The index is taken modulo the list length, so a unit that rounds to 1.0
     * cannot select past the end.
     */
    public function test_pick_never_runs_off_the_end(): void
    {
        for ($i = 0; $i < 200; $i++) {
            self::assertNotNull(Seed\pick('seed-' . $i, 'dim', ['a', 'b']));
        }
    }

    // ------------------------------------------------------------- shuffle

    public function test_shuffle_keeps_every_item_and_is_stable(): void
    {
        $items = ['stacked-band', 'editorial-split', 'offset-pair', 'bleed-left', 'poster-stack'];
        $once = Seed\shuffle_list(self::A, 'grammars', $items);

        self::assertSame($once, Seed\shuffle_list(self::A, 'grammars', $items));
        sort($once);
        $sorted = $items;
        sort($sorted);
        self::assertSame($sorted, $once);
    }

    public function test_two_sites_shuffle_differently(): void
    {
        $items = range(1, 12);

        self::assertNotSame(
            Seed\shuffle_list(self::A, 'grammars', $items),
            Seed\shuffle_list(self::B, 'grammars', $items),
        );
    }

    // -------------------------------------------------------------- chance

    public function test_chance_is_stable_and_respects_its_bias(): void
    {
        self::assertSame(Seed\chance(self::A, 'coin'), Seed\chance(self::A, 'coin'));
        self::assertFalse(Seed\chance(self::A, 'coin', 0.0));
        self::assertTrue(Seed\chance(self::A, 'coin', 1.0));
    }

    /**
     * Not a distribution test, a sanity one: a bias that never fires or always
     * fires means the derivation has collapsed and every site takes the same
     * branch, which is the failure this whole module exists to prevent.
     */
    public function test_a_fair_coin_lands_both_ways_across_sites(): void
    {
        $heads = 0;
        for ($i = 0; $i < 100; $i++) {
            if (Seed\chance('site-' . $i, 'ground')) {
                $heads++;
            }
        }

        self::assertGreaterThan(20, $heads);
        self::assertLessThan(80, $heads);
    }
}
