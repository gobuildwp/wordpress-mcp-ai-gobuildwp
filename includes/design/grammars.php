<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Grammars;

/**
 * Section grammars: the compositions a page is built from.
 *
 * Every other token group describes how a page looks. This one describes how it
 * is arranged, and arrangement is what people actually recognise. A palette can
 * be perfect and the page still reads as machine-made, because generated pages
 * share one shape: a vertical stack of full-width bands, each a centred heading
 * over two or three equal columns. That shape is not a taste failure. It is the
 * only shape available when the vocabulary is nested flex containers, so it is
 * the shape that gets produced every time.
 *
 * A grammar is a named composition with its responsive behaviour attached. It
 * carries no colour, no typeface and no copy — only structure — so the same
 * grammar in two different designs produces two pages that share a skeleton and
 * nothing else, which is exactly the relationship between two well-designed
 * sites by the same studio.
 *
 * They are declared as data rather than built as code because every property
 * used here exists in Elementor 4's own style schema — `position` with its
 * inset-block/inset-inline offsets, `z-index`, `display: grid` with
 * `grid-template-columns`, and per-breakpoint style variants. So a grammar
 * compiles to an ordinary global class: the client can still edit the page
 * afterwards without collapsing it, the write gate can check a named grammar
 * instead of forty inline values, and changing one grammar re-skins every
 * section that uses it.
 *
 * Mobile is defined inside the grammar, never left to the caller. Overlap and
 * absolute positioning are where responsive layouts die, and a grammar that
 * only works at one width is worse than no grammar at all.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The shipped grammar set.
 *
 * Deliberately small. Six compositions that differ structurally beat twenty
 * that differ decoratively, and a short list is one an author can hold in mind
 * while writing a design.
 *
 * Each entry carries:
 *   label       human name, shown wherever a design is described
 *   intent      what this composition is for, in one line
 *   variance    how far from a plain stack it sits, 0-1, used by the chooser
 *   styles      base (desktop) style-schema properties for the section
 *   parts       the named children a section of this grammar expects
 *   responsive  tablet and mobile overrides, applied as style variants
 *
 * @return array<string, array<string, mixed>>
 */
function all(): array
{
    return [
        'stacked-band' => [
            'label' => 'Stacked band',
            'intent' => 'A full-width band with centred content. The baseline every other grammar departs from, kept because a page of nothing but departures is as monotonous as a page of none.',
            'variance' => 0.0,
            'styles' => [
                'display' => 'flex',
                'flex-direction' => 'column',
                'align-items' => 'center',
            ],
            'parts' => ['content'],
            'responsive' => [],
        ],

        'editorial-split' => [
            'label' => 'Editorial split',
            'intent' => 'An asymmetric two-column grid, seven parts to five. The uneven ratio is the whole point: equal halves read as a template, an off-balance split reads as a decision.',
            'variance' => 0.35,
            'styles' => [
                'display' => 'grid',
                'grid-template-columns' => '7fr 5fr',
                'align-items' => 'center',
            ],
            'parts' => ['lead', 'support'],
            'responsive' => [
                // One column below the tablet breakpoint. A 7/5 split at 480px
                // is two unreadable columns, not a composition.
                'mobile' => ['grid-template-columns' => '1fr'],
            ],
        ],

        'offset-pair' => [
            'label' => 'Offset pair',
            'intent' => 'Two columns where the second sits lower than the first. The vertical offset does what a straight row cannot: it gives the eye an order to read in.',
            'variance' => 0.45,
            'styles' => [
                'display' => 'grid',
                'grid-template-columns' => '1fr 1fr',
                'align-items' => 'start',
            ],
            'parts' => ['first', 'second'],
            // The offset itself. Without it this grammar described a drop and
            // produced a plain 1fr/1fr row: two columns level with each other,
            // which is the generic two-column shape the whole set exists to get
            // away from. It went unnoticed because the section still looked
            // fine, and the pages built on it quietly hand-tuned a top padding
            // onto the second column to get the effect back, which is the
            // hand-tuning a grammar is supposed to remove.
            'part_styles' => [
                'second' => [
                    'margin' => ['block-start' => '5rem'],
                ],
            ],
            'responsive' => [
                'mobile' => ['grid-template-columns' => '1fr'],
                'part_styles' => [
                    // In one column the drop is not an offset, it is a gap that
                    // does not match any other gap on the page.
                    'second' => ['mobile' => ['margin' => ['block-start' => '0rem']]],
                ],
            ],
        ],

        'bleed-left' => [
            'label' => 'Bleed left',
            'intent' => 'Content held in the grid while one element escapes past the left edge of the page. Asymmetric bleed is the cheapest way to stop a layout feeling boxed, and it only works on one side at a time.',
            'variance' => 0.6,
            'styles' => [
                'display' => 'grid',
                'grid-template-columns' => '5fr 7fr',
                'align-items' => 'center',
                'overflow' => 'hidden',
            ],
            'parts' => ['bleed', 'body'],
            'responsive' => [
                // The bleed is abandoned rather than scaled: a bled image on a
                // phone is an image with a piece missing.
                'mobile' => ['grid-template-columns' => '1fr', 'overflow' => 'visible'],
            ],
        ],

        'overlap-card' => [
            'label' => 'Overlap card',
            'intent' => 'A panel that sits across the boundary between two sections. The single most recognisable sign that a human composed a page, and the one thing a stack of bands can never do.',
            'variance' => 0.7,
            'styles' => [
                'position' => 'relative',
                'display' => 'flex',
                'flex-direction' => 'column',
            ],
            // The overlapping child is pulled up out of its own section and
            // raised above the one it crosses into.
            'parts' => ['ground', 'card'],
            'part_styles' => [
                'card' => [
                    'position' => 'relative',
                    'z-index' => 2,
                    // margin is a dimensions prop in Elementor's schema, not a
                    // set of logical longhands: margin-block-start is refused.
                    'margin' => ['block-start' => '-6rem'],
                ],
            ],
            'responsive' => [
                // A negative pull that is fine at 1440px swallows the section
                // above it at 390px.
                'mobile' => [],
                'part_styles' => [
                    'card' => ['mobile' => ['margin' => ['block-start' => '-1.5rem']]],
                ],
            ],
        ],

        'poster-stack' => [
            'label' => 'Poster stack',
            'intent' => 'Layered elements placed absolutely against a common ground — an oversized numeral behind a heading, a rotated label down an edge. The most expressive grammar and the one that needs the most care.',
            'variance' => 0.9,
            'styles' => [
                'position' => 'relative',
                'min-height' => '32rem',
            ],
            'parts' => ['ground', 'layer'],
            'part_styles' => [
                'layer' => [
                    'position' => 'absolute',
                    'inset-block-start' => '2rem',
                    'inset-inline-start' => '2rem',
                    'z-index' => 1,
                ],
            ],
            'responsive' => [
                // Absolute layers become flow content on a phone. Keeping them
                // absolute is how a poster layout turns into overlapping text.
                'mobile' => ['min-height' => 'auto'],
                'part_styles' => [
                    'layer' => ['mobile' => ['position' => 'static']],
                ],
            ],
        ],

        'index-detail' => [
            'label' => 'Index and detail',
            'intent' => 'A column that stays put while the one beside it scrolls. Changes the reading rhythm of a long page without changing anything about how it looks.',
            'variance' => 0.5,
            'styles' => [
                'display' => 'grid',
                'grid-template-columns' => '4fr 8fr',
                'align-items' => 'start',
            ],
            'parts' => ['index', 'detail'],
            'part_styles' => [
                'index' => [
                    'position' => 'sticky',
                    'inset-block-start' => '6rem',
                ],
            ],
            'responsive' => [
                'mobile' => ['grid-template-columns' => '1fr'],
                'part_styles' => [
                    // Sticky inside a single column pins the heading over its
                    // own list, which reads as a bug.
                    'index' => ['mobile' => ['position' => 'static']],
                ],
            ],
        ],
    ];
}

/**
 * One grammar by name, or null when it is not a shipped grammar.
 *
 * @return array<string, mixed>|null
 */
function get(string $name): ?array
{
    $all = all();
    return $all[$name] ?? null;
}

/**
 * The grammar names this install ships.
 *
 * @return list<string>
 */
function names(): array
{
    return array_keys(all());
}

/**
 * Choose a grammar for a section.
 *
 * The obvious implementation damps variance as the page goes on, so the opener
 * is loud and everything after it calms down. It produces one interesting
 * section followed by five identical bands, which is the monotony this whole
 * file exists to prevent — the failure just moves down the page.
 *
 * What a designed page actually has is rhythm: a strong opening, somewhere
 * quiet to recover, a medium passage, another strong moment. So sections
 * alternate between feature and rest. A feature section draws from the upper
 * half of what the design's variance affords, a rest section from the lower
 * half, and neither repeats whatever the section before it used.
 *
 * The seed makes all of it reproducible. The same site rebuilt lands on the
 * same composition; a different site with the same brief lands elsewhere.
 *
 * @param  list<string> $allowed Grammar names the design permits, or [] for all.
 * @param  string       $previous The grammar used by the section before this one.
 */
function choose(float $variance, string $seed, int $index, array $allowed = [], string $previous = ''): string
{
    $pool = $allowed === [] ? names() : array_values(array_intersect(names(), $allowed));
    if ($pool === []) {
        return 'stacked-band';
    }

    $variance = max(0.0, min(1.0, $variance));

    /** @var list<array{name: string, variance: float}> $affordable */
    $affordable = [];
    foreach ($pool as $name) {
        $grammar = get($name);
        if ($grammar === null) {
            continue;
        }
        $cost = (float) ($grammar['variance'] ?? 0.0);
        if ($cost <= $variance + 0.001) {
            $affordable[] = ['name' => $name, 'variance' => $cost];
        }
    }
    if ($affordable === []) {
        return 'stacked-band';
    }

    usort($affordable, static fn(array $a, array $b): int => $a['variance'] <=> $b['variance']);

    // Odd sections rest, even sections carry the page. Starting on a feature
    // means the opener is the strong one, which is where a reader decides
    // whether the site was designed.
    $is_feature = $index % 2 === 0;
    $midpoint = (int) floor(count($affordable) / 2);

    $band = $is_feature
        ? array_slice($affordable, $midpoint)
        : array_slice($affordable, 0, max(1, $midpoint));
    if ($band === []) {
        $band = $affordable;
    }

    // Never twice in a row. Two identical compositions back to back read as a
    // repeated template even when each one is interesting on its own.
    $names = array_map(static fn(array $g): string => $g['name'], $band);
    if ($previous !== '' && count($names) > 1) {
        $names = array_values(array_filter($names, static fn(string $n): bool => $n !== $previous));
    }

    // The seed alone can land two feature sections on the same grammar by
    // coincidence, which reads as a repeat even though each choice was
    // independent. Stepping by the section's own position walks the band
    // instead, so successive features move through it rather than colliding.
    $step = intdiv($index, 2);
    $hash = crc32($seed);
    return $names[(int) (($hash + $step) % count($names))];
}
