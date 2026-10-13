<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Seed;

/**
 * Decisions derived from the site rather than from the model's taste.
 *
 * Every AI-built site looks like every other one for a reason that is not a
 * shortage of options. Aligned models regress toward the statistical mode of
 * what they were trained on, so asked for a typeface they answer Inter, asked
 * for a layout they answer three equal cards, and asked again they answer the
 * same thing. Shipping forty typefaces and seven compositions does not fix
 * that: a strong prior picks the same three faces out of forty every time.
 * Vocabulary was never the constraint.
 *
 * The fix is to stop asking. When a choice has several defensible answers, the
 * answer is derived from the site — its address and the design's name — rather
 * than chosen. Two sites with identical briefs then diverge because their seeds
 * differ, and one site rebuilt lands on the same design because its seed does
 * not. Reproducible and unlike its neighbours are usually treated as opposing
 * goals; deriving gets both, and asking gets neither.
 *
 * The grammar chooser has worked this way from the start and it is the only
 * place that did. Everything a design decides between — a palette's hue, a type
 * pairing, a corner radius, how dense or adventurous the page is — can come
 * from here instead.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The seed for one design on this site.
 *
 * The home URL and the design name together, so a second design on the same
 * site is a different seed and the same design on a staging copy is the same
 * one. Note that this means a domain change re-rolls every derived value, which
 * is the right trade: a site that moves house rarely wants to keep a palette
 * that was derived rather than chosen, and anything actually chosen is written
 * down in the design document and never derived at all.
 */
function of(string $name = ''): string
{
    return get_home_url() . '|' . $name;
}

/**
 * A stable number in [0, 1) for one seed and one dimension.
 *
 * Dimensions are named so that two decisions taken from the same seed do not
 * move together. Without them a site whose hue landed high would also land high
 * on radius, density and every other axis, and the whole set would swing as one
 * — which reads as a theme rather than as a set of decisions.
 */
function unit(string $seed, string $dimension): float
{
    // The hash rather than crc32: crc32 of two similar strings correlates
    // strongly in its low bits, and adjacent dimension names are similar by
    // construction.
    $digest = substr(hash('sha256', $seed . '::' . $dimension), offset: 0, length: 8);

    return hexdec($digest) / 0xFFFFFFFF;
}

/**
 * One item from a list, chosen by seed.
 *
 * @template T
 * @param  list<T> $items
 * @return T|null
 */
function pick(string $seed, string $dimension, array $items): mixed
{
    if ($items === []) {
        return null;
    }

    return $items[(int) floor(unit($seed, $dimension) * count($items)) % count($items)];
}

/**
 * A value inside a range, quantized so the result is a round number somebody
 * could have chosen on purpose.
 *
 * Quantizing matters more than it looks. A derived radius of 13.4791px reads as
 * machine output the moment anyone opens the panel, and the whole point is a
 * design that could have been decided by a person.
 */
function between(string $seed, string $dimension, float $min, float $max, float $step = 1.0): float
{
    if ($max <= $min) {
        return $min;
    }
    $span = $max - $min;
    $raw = $min + (unit($seed, $dimension) * $span);
    if ($step <= 0.0) {
        return $raw;
    }

    return min($max, $min + (round(($raw - $min) / $step) * $step));
}

/**
 * A shuffled copy of a list, stable for the seed.
 *
 * Used where several things are taken and their order matters — which grammars
 * a design permits, which of a set of accents leads.
 *
 * @template T
 * @param  list<T> $items
 * @return list<T>
 */
function shuffle_list(string $seed, string $dimension, array $items): array
{
    $keyed = [];
    foreach (array_values($items) as $index => $item) {
        $keyed[] = ['sort' => unit($seed, $dimension . ':' . $index), 'item' => $item];
    }
    usort($keyed, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);

    return array_map(static fn(array $entry): mixed => $entry['item'], $keyed);
}

/**
 * Whether a coin lands, at a given bias.
 */
function chance(string $seed, string $dimension, float $probability = 0.5): bool
{
    return unit($seed, $dimension) < $probability;
}
