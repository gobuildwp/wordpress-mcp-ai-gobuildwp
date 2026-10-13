<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Contrast;

use WPPilot\Design\Preflight;
use WPPilot\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WCAG contrast, computed from the design's own palette.
 *
 * A palette is a promise about what may be put next to what, and most of the
 * accessibility failures a generated page ships are decided at that level
 * rather than at the page: a brand whose accent cannot carry white text will
 * produce unreadable buttons on every page anybody builds with it, forever.
 * Checking the palette answers it once.
 *
 * Ratios are the WCAG 2.x relative-luminance formula. That standard is what
 * conformance is actually measured against, and while APCA is the better
 * perceptual model and is where WCAG 3 is heading, reporting an APCA number
 * against an AA threshold would be answering a question nobody asked.
 *
 * This is analysis, not a gate. It reports pairs and their ratios; whether a
 * given pair is a problem depends on how the design uses it, which is a
 * judgement for the person reading the report.
 */

/** WCAG AA for normal text. */
const AA_NORMAL = 4.5;

/** WCAG AA for large text (18.66px bold, or 24px), and for UI components. */
const AA_LARGE = 3.0;

/** WCAG AAA for normal text. */
const AAA_NORMAL = 7.0;

/**
 * Relative luminance of a hex colour, per WCAG 2.x.
 *
 * Returns null for anything that is not a six-digit hex, so callers can skip a
 * token rather than treat an unparsable value as black.
 */
function luminance(string $hex): ?float
{
    $value = ltrim(Preflight\normalize_hex($hex), characters: '#');
    if (preg_match('/^[0-9a-f]{6}$/i', $value) !== 1) {
        return null;
    }
    $channels = [];
    foreach ([0, 2, 4] as $offset) {
        $channel = ((int) hexdec(substr($value, offset: $offset, length: 2))) / 255;
        $channels[] = $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
    }

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/** Contrast ratio between two hex colours, or null if either is unreadable. */
function ratio(string $foreground, string $background): ?float
{
    $first = luminance($foreground);
    $second = luminance($background);
    if ($first === null || $second === null) {
        return null;
    }
    $lighter = max($first, $second);
    $darker = min($first, $second);

    return ($lighter + 0.05) / ($darker + 0.05);
}

/** The strictest WCAG level a ratio satisfies for normal-size text. */
function grade(float $ratio): string
{
    if ($ratio >= AAA_NORMAL) {
        return 'AAA';
    }
    if ($ratio >= AA_NORMAL) {
        return 'AA';
    }
    if ($ratio >= AA_LARGE) {
        return 'AA-large';
    }

    return 'fail';
}

/**
 * Every foreground/background pair in a design's palette, with its ratio.
 *
 * Pairs are unordered — contrast is symmetric — so a palette of n colours
 * yields n(n-1)/2 rows rather than n². Identical colours are skipped, because
 * "this colour has a ratio of 1 with itself" is noise in every report.
 *
 * @return array{
 *   pairs: list<array{foreground: string, background: string, foreground_role: string, background_role: string, ratio: float, grade: string}>,
 *   readable_pairs: int,
 *   total_pairs: int,
 *   text_safe: list<string>,
 *   warnings: list<string>
 * }
 */
function analyze_palette(string $design_md): array
{
    $colors = Tokens\extract($design_md)['colors'];
    $entries = [];
    foreach ($colors as $role => $value) {
        $hex = Preflight\normalize_hex((string) $value);
        if ($hex === '' || luminance($hex) === null) {
            continue;
        }
        $entries[] = ['role' => (string) $role, 'hex' => $hex];
    }

    $pairs = [];
    $readable = 0;
    $count = count($entries);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            if ($entries[$i]['hex'] === $entries[$j]['hex']) {
                continue;
            }
            $value = ratio($entries[$i]['hex'], $entries[$j]['hex']);
            if ($value === null) {
                continue;
            }
            $grade = grade($value);
            if ($grade === 'AA' || $grade === 'AAA') {
                $readable++;
            }
            $pairs[] = [
                'foreground' => $entries[$i]['hex'],
                'background' => $entries[$j]['hex'],
                'foreground_role' => $entries[$i]['role'],
                'background_role' => $entries[$j]['role'],
                'ratio' => round($value, precision: 2),
                'grade' => $grade,
            ];
        }
    }

    usort($pairs, static fn(array $a, array $b): int => $b['ratio'] <=> $a['ratio']);

    return [
        'pairs' => $pairs,
        'readable_pairs' => $readable,
        'total_pairs' => count($pairs),
        'text_safe' => text_safe_backgrounds($entries),
        'warnings' => palette_warnings($entries, $pairs),
    ];
}

/**
 * Backgrounds that can carry normal-size body text in at least one palette colour.
 *
 * The practical question a designer asks of a palette, and the one a generated
 * page gets wrong: a surface nothing in the palette reads on will be used
 * anyway, with a colour borrowed from outside the design.
 *
 * @param  list<array{role: string, hex: string}> $entries
 * @return list<string>
 */
function text_safe_backgrounds(array $entries): array
{
    $safe = [];
    foreach ($entries as $background) {
        foreach ($entries as $foreground) {
            if ($foreground['hex'] === $background['hex']) {
                continue;
            }
            $value = ratio($foreground['hex'], $background['hex']);
            if ($value !== null && $value >= AA_NORMAL) {
                $safe[] = $background['role'];
                break;
            }
        }
    }

    return $safe;
}

/**
 * Problems worth naming in plain language rather than leaving in the matrix.
 *
 * @param  list<array{role: string, hex: string}>    $entries
 * @param  list<array<string, mixed>>                $pairs
 * @return list<string>
 */
function palette_warnings(array $entries, array $pairs): array
{
    $warnings = [];
    if ($entries === []) {
        return $warnings;
    }
    if (count($entries) < 2) {
        $warnings[] = __(
            'The palette declares one colour, so there is no pair to check. A design needs at least a text colour and a surface colour.',
            domain: 'wppilot',
        );

        return $warnings;
    }

    $safe = text_safe_backgrounds($entries);
    foreach ($entries as $entry) {
        if (!in_array($entry['role'], $safe, strict: true)) {
            $warnings[] = sprintf(
                /* translators: 1: token name, 2: hex value. */
                __(
                    'Nothing in the palette reaches AA for body text on "%1$s" (%2$s). Anything built on that surface will need a colour from outside the design.',
                    domain: 'wppilot',
                ),
                $entry['role'],
                $entry['hex'],
            );
        }
    }

    $best = $pairs[0]['ratio'] ?? 0.0;
    if ($best < AA_NORMAL) {
        $warnings[] = sprintf(
            /* translators: %s: the best ratio in the palette. */
            __(
                'No pair in this palette reaches AA for normal text; the strongest is %s:1 against a 4.5:1 requirement. This palette cannot produce accessible body text on its own.',
                domain: 'wppilot',
            ),
            (string) $best,
        );
    }

    return $warnings;
}

/**
 * The best foreground in a palette for a given background.
 *
 * Used to answer "what should the text on this be" with the design's own
 * colours rather than defaulting to black or white, which is how a careful
 * palette ends up with #000 on it.
 *
 * @param array<string, string> $colors
 */
function best_foreground(string $background, array $colors): string
{
    $best = '';
    $best_ratio = 0.0;
    foreach ($colors as $value) {
        $hex = Preflight\normalize_hex((string) $value);
        if ($hex === '' || $hex === Preflight\normalize_hex($background)) {
            continue;
        }
        $value_ratio = ratio($hex, $background);
        if ($value_ratio !== null && $value_ratio > $best_ratio) {
            $best_ratio = $value_ratio;
            $best = $hex;
        }
    }

    return $best;
}
