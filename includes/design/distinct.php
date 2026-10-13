<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Distinct;

use WPPilot\Design\Library;
use WPPilot\Design\Preflight;
use WPPilot\Design\Spec;
use WPPilot\Design\Tokens;

/**
 * Is this design different from the ones already on this install?
 *
 * Every other rule in the design system asks whether a design is any good on
 * its own terms: does the accent carry text, is the type scale declared, is the
 * palette applied in a sane proportion. A design can pass all of them and still
 * be the fourth site this month with a navy ground, a warm grey body face and
 * an asymmetric split in the hero.
 *
 * That is the failure the anti-tell rules do not catch, because nothing about
 * any one page is wrong. It only shows up in the aggregate, and the aggregate
 * is exactly what an agency sees when it lines up the sites it shipped.
 *
 * The comparison is deliberately shallow: palette distance, whether the type
 * pairing is identical, and how much of the grammar set is shared. Deeper
 * similarity measures would be more accurate and far more likely to produce a
 * confident wrong answer, and a warning nobody trusts is worse than no warning.
 * Nothing here refuses a design. Two sites in one franchise are supposed to
 * look alike; the check reports, the human decides.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Palette distance below which two designs are the same colour decision.
 */
const NEAR_DUPLICATE = 0.06;

/**
 * Palette distance below which two designs are close relatives.
 */
const RELATIVES = 0.18;

/**
 * How much apparent distance a design gains by not sharing this one's type
 * pairing, used only for ranking and never reported.
 *
 * Set below NEAR_DUPLICATE so it decides which sibling is named among designs
 * that are already the same colour decision, without reordering designs that
 * are genuinely far apart from each other.
 */
const PAIRING_ADVANTAGE = 0.05;

/**
 * Distance between two colours, 0 (identical) to 1 (opposite).
 *
 * Hue carries most of what people mean by "a different colour", so it is
 * weighted above lightness and saturation. Two navies at different lightness
 * are the same decision; navy and rust are not.
 *
 * hex_to_hsl() answers a positional [hue, saturation, lightness], so these are
 * read by index rather than by name.
 *
 * @param array{0: float, 1: float, 2: float} $a
 * @param array{0: float, 1: float, 2: float} $b
 */
function color_distance(array $a, array $b): float
{
    // Hue is a wheel: 350 and 10 are neighbours, not opposites.
    $hue_gap = abs((float) $a[0] - (float) $b[0]);
    if ($hue_gap > 180.0) {
        $hue_gap = 360.0 - $hue_gap;
    }
    $hue = $hue_gap / 180.0;

    $sat = abs((float) $a[1] - (float) $b[1]);
    $lum = abs((float) $a[2] - (float) $b[2]);

    // A grey has no meaningful hue, so comparing hues of two near-greys reports
    // a difference nobody can see. Fade the hue term out as saturation drops.
    $chroma = min((float) $a[1], (float) $b[1]);
    $hue_weight = 0.6 * min(1.0, $chroma * 3.0);

    // Whatever hue gives up moves to saturation rather than evaporating. When
    // one side is grey, how colourful the two are IS the difference between
    // them: a monochrome palette and a red one are not near-identical just
    // because one of them has no hue to compare. Keeping the weights summing to
    // 1 either way also means a distance means the same thing in both regimes.
    $sat_weight = 0.15 + (0.6 - $hue_weight);

    return min(1.0, ($hue * $hue_weight) + ($sat * $sat_weight) + ($lum * 0.25));
}

/**
 * Hex strings to HSL triples, dropping anything unparseable.
 *
 * @param list<string> $hexes
 * @return list<array{0: float, 1: float, 2: float}>
 */
function to_hsl(array $hexes): array
{
    $out = [];
    foreach ($hexes as $hex) {
        $hsl = Preflight\hex_to_hsl($hex);
        if ($hsl !== null) {
            $out[] = $hsl;
        }
    }
    return $out;
}

/**
 * Mean nearest-neighbour distance from every colour in $left to the closest one
 * in $right. Directional: the answer changes if the arguments swap.
 *
 * @param list<array{0: float, 1: float, 2: float}> $left
 * @param list<array{0: float, 1: float, 2: float}> $right
 */
function directional_distance(array $left, array $right): float
{
    $total = 0.0;
    foreach ($left as $one) {
        $nearest = 1.0;
        foreach ($right as $other) {
            $nearest = min($nearest, color_distance($one, $other));
        }
        $total += $nearest;
    }

    return $total / count($left);
}

/**
 * How far apart two palettes are, 0 (same) to 1 (unrelated).
 *
 * Each colour is matched to its nearest counterpart in the other palette rather
 * than by role name, so a design that calls its accent "brand" is still compared
 * against the other's accent.
 *
 * That match is directional, and the two directions genuinely disagree: a
 * three-colour palette of near-black, near-white and an acid green finds a close
 * neighbour for every one of its colours inside a black/white/grey palette and
 * scores 0.04, while the grey palette looks back and scores 0.24 because nothing
 * on its side is anywhere near the acid. "Is this design too close to that one"
 * cannot have two answers depending on which was written first, so both
 * directions are taken and the larger wins. The larger, specifically, means two
 * palettes are only called similar when each is close to the other — the accent
 * one of them owns alone is enough to keep them apart, which is right, because
 * the accent is the part a client recognises.
 *
 * @param list<string> $a Hex colours.
 * @param list<string> $b Hex colours.
 */
function palette_distance(array $a, array $b): float
{
    $left = to_hsl($a);
    $right = to_hsl($b);
    if ($left === [] || $right === []) {
        return 1.0;
    }

    return max(directional_distance($left, $right), directional_distance($right, $left));
}

/**
 * The font families a design names, lowercased and sorted so two designs
 * declaring the same pair in a different order still compare equal.
 *
 * @param array<string, mixed> $typography
 * @return list<string>
 */
function font_pairing(array $typography): array
{
    $families = Preflight\collect_allowed_fonts($typography);
    $families = array_map(static fn(string $f): string => strtolower(trim($f)), $families);
    $families = array_values(array_unique(array_filter($families)));
    sort($families);
    return $families;
}

/**
 * The hex colours and font families a design declares.
 *
 * @return array{colors: list<string>, fonts: list<string>}
 */
function palette_of(string $design_md): array
{
    $tokens = Tokens\extract($design_md);

    $hexes = [];
    foreach ($tokens['colors'] as $value) {
        $hex = Preflight\normalize_hex($value);
        if ($hex !== '') {
            $hexes[] = $hex;
        }
    }

    return ['colors' => $hexes, 'fonts' => font_pairing($tokens['typography'])];
}

/**
 * Compare one design against a set of others.
 *
 * Kept separate from check() so the ranking and the thresholds can be exercised
 * directly. Everything decided here is decided from the arguments — no store, no
 * options, no post query — which matters because this is where the judgement
 * lives, and the judgement is the part worth pinning down.
 *
 * @param list<string> $mine     The candidate's hex colours.
 * @param list<string> $my_fonts The candidate's font families, lowercased.
 * @param list<array{slug: string, name: string, colors: list<string>, fonts: list<string>}> $others
 * @return array{
 *   distinct: bool,
 *   compared: int,
 *   nearest: array{slug: string, name: string, palette_distance: float, shared_fonts: list<string>, verdict: string}|null,
 *   findings: list<array<string, string>>
 * }
 */
function compare(array $mine, array $my_fonts, array $others): array
{
    $nearest = null;
    $nearest_distance = 1.0;
    $nearest_score = 1.0;
    $compared = 0;

    foreach ($others as $record) {
        $theirs = $record['colors'];
        if ($theirs === []) {
            continue;
        }
        $compared++;

        $distance = palette_distance($mine, $theirs);
        $shared = array_values(array_intersect($my_fonts, $record['fonts']));

        // Rank on how alike two designs are overall, not on colour alone.
        // Ranking by palette put a design that shared this one's exact hexes but
        // none of its typefaces ahead of the design that shared both — so the
        // report named the wrong sibling and, by then asking about that one's
        // fonts, downgraded a real near-duplicate to a colour coincidence.
        //
        // The advantage is added rather than multiplied because the case that
        // matters most is the one where the palette distance is already close to
        // zero, and scaling zero leaves zero: against a design carrying this
        // one's exact hex values, no multiplier could ever let the design that
        // shares both the colour and the type win. Below two palettes that are
        // the same decision anyway, the pairing is the only thing left to
        // separate them by, so it has to be able to. The distance reported to
        // the reader stays the true palette distance.
        $score = $shared !== [] && count($shared) >= count($my_fonts)
            ? $distance
            : $distance + PAIRING_ADVANTAGE;
        if ($score >= $nearest_score) {
            continue;
        }

        $nearest_score = $score;
        $nearest_distance = $distance;
        $nearest = [
            'slug' => $record['slug'],
            'name' => $record['name'],
            'palette_distance' => round($distance, 3),
            'shared_fonts' => $shared,
            'verdict' => '',
        ];
    }

    /** @var list<array<string, string>> $findings */
    $findings = [];

    if ($nearest === null) {
        return ['distinct' => true, 'compared' => $compared, 'nearest' => null, 'findings' => $findings];
    }

    $same_fonts = $nearest['shared_fonts'] !== [] && count($nearest['shared_fonts']) >= count($my_fonts);

    // Thresholds come from measuring the spread across a set of real palettes
    // rather than from taste. Two palettes that are the same decision in
    // different hex values land at 0.01; the closest genuinely distinct pair
    // measured was 0.13, and the median unrelated pair 0.30. That leaves an
    // empty band between roughly 0.02 and 0.13, so NEAR_DUPLICATE sits inside
    // it where nothing real falls either side by accident. RELATIVES is set just
    // above the closest distinct pair, which is the point where a shared type
    // pairing starts to be what makes two sites look like each other.
    //
    // The nearest-match mean compresses these numbers: most palettes contain a
    // near-white that finds a near-white in any other palette and contributes
    // almost nothing, so 0.30 is a typical stranger, not 0.7. The thresholds are
    // calibrated to that scale and would be wrong on a raw colour distance.
    if ($nearest_distance < NEAR_DUPLICATE && $same_fonts) {
        $nearest['verdict'] = 'near-duplicate';
        $findings[] = [
            'rule' => 'design-near-duplicate',
            'severity' => 'warn',
            'message' => sprintf(
                /* translators: 1: other design name, 2: distance as a decimal */
                __(
                    'This is close to "%1$s" already on this site: the palettes differ by %2$s and the type pairing is the same. Two sites built from one system should share a skeleton, not a face. Move the hue, change one of the two faces, or say plainly that these are meant to be siblings.',
                    domain: 'wppilot',
                ),
                $nearest['name'],
                number_format($nearest_distance, 2),
            ),
        ];
    } elseif ($nearest_distance < NEAR_DUPLICATE) {
        $nearest['verdict'] = 'same-palette';
        $findings[] = [
            'rule' => 'design-palette-repeat',
            'severity' => 'warn',
            'message' => sprintf(
                /* translators: 1: other design name, 2: distance as a decimal */
                __(
                    'The palette is nearly the one "%1$s" uses, %2$s apart. The typefaces differ, which carries some of the distance, but colour is what a client recognises first.',
                    domain: 'wppilot',
                ),
                $nearest['name'],
                number_format($nearest_distance, 2),
            ),
        ];
    } elseif ($same_fonts && $nearest_distance < RELATIVES) {
        $nearest['verdict'] = 'same-voice';
        $findings[] = [
            'rule' => 'design-pairing-repeat',
            'severity' => 'warn',
            'message' => sprintf(
                /* translators: %s: other design name */
                __(
                    'The type pairing is the same as "%s" and the palettes are close relatives. A shared pairing is a house style when it is chosen and a rut when it is inherited.',
                    domain: 'wppilot',
                ),
                $nearest['name'],
            ),
        ];
    } else {
        $nearest['verdict'] = 'distinct';
    }

    return [
        'distinct' => $findings === [],
        'compared' => $compared,
        'nearest' => $nearest,
        'findings' => $findings,
    ];
}

/**
 * Compare one design against the others already saved on this install.
 *
 * @return array{
 *   distinct: bool,
 *   compared: int,
 *   nearest: array{slug: string, name: string, palette_distance: float, shared_fonts: list<string>, verdict: string}|null,
 *   findings: list<array<string, string>>
 * }
 */
function check(string $design_md, string $own_slug = ''): array
{
    $me = palette_of($design_md);

    /** @var list<array{slug: string, name: string, colors: list<string>, fonts: list<string>}> $others */
    $others = [];
    foreach (Library\all() as $record) {
        // A design is never compared against itself. Saving over an existing
        // design would otherwise always report a perfect match with the version
        // it just replaced.
        if ($record['slug'] === $own_slug || $record['slug'] === '') {
            continue;
        }
        $theirs = palette_of($record['content']);
        $others[] = [
            'slug' => $record['slug'],
            'name' => $record['name'],
            'colors' => $theirs['colors'],
            'fonts' => $theirs['fonts'],
        ];
    }

    $result = compare($me['colors'], $me['fonts'], $others);

    // Structure, which the colour and pairing comparison above cannot see. A
    // design can change every hex and both faces and keep the identical
    // skeleton, and that skeleton is what a reader recognises before reading a
    // word. Without this a second build of one template is reported as distinct
    // because somebody made it blue.
    if (function_exists('WPPilot\Design\Spec\get')) {
        $mine = Spec\get($own_slug);
        $my_skeleton = is_array($mine) ? Spec\skeleton($mine) : '';
        if ($my_skeleton !== '') {
            foreach ($others as $record) {
                $theirs = Spec\get((string) $record['slug']);
                if (!is_array($theirs)) {
                    continue;
                }
                $distance = Spec\skeleton_distance($my_skeleton, Spec\skeleton($theirs));
                if ($distance > 0.2) {
                    continue;
                }
                $result['distinct'] = false;
                $result['findings'][] = [
                    'rule' => 'design-structure-repeat',
                    'severity' => 'warn',
                    'message' => sprintf(
                        /* translators: 1: the other design name, 2: how much the skeletons differ */
                        __(
                            'This has almost the same page structure as "%1$s": the sections differ by %2$s. Two designs can share no colour and no typeface and still be the same page, and the order of grounds down a page is what somebody recognises before they read anything. Change what a section is, not just what colour it is.',
                            domain: 'wppilot',
                        ),
                        (string) $record['name'],
                        number_format($distance, 2),
                    ),
                ];
                break;
            }
        }
    }

    return $result;
}
