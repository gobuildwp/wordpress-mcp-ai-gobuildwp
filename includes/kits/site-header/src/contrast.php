<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteHeader;

if (!defined('ABSPATH')) {
    exit();
}

/*
 * Colour arithmetic for the header checks: WCAG contrast, and a logo's brand colours for the
 * site facts a dry run reports. Plain data in and out, so it is tested on its own.
 */

/** WCAG AA: body-size text, and large text (24px, or 18.66px bold). */
const CONTRAST_TEXT = 4.5;
const CONTRAST_LARGE = 3.0;

function is_hex(string $value): bool
{
    return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) === 1;
}

/** @return array{0: float, 1: float, 2: float} */
function hex_rgb(string $hex): array
{
    $h = ltrim($hex, '#');
    if (strlen($h) === 3) {
        $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }

    return [(float) hexdec(substr($h, 0, 2)), (float) hexdec(substr($h, 2, 2)), (float) hexdec(substr($h, 4, 2))];
}

/** @param array{0: float, 1: float, 2: float} $rgb */
function rgb_hex(array $rgb): string
{
    $c = static fn(float $v): int => max(0, min(255, (int) round($v)));

    return sprintf('#%02x%02x%02x', $c($rgb[0]), $c($rgb[1]), $c($rgb[2]));
}

/** WCAG relative luminance. */
function luminance(string $hex): float
{
    $l = 0.0;
    foreach (hex_rgb($hex) as $i => $v) {
        $s = $v / 255;
        $lin = $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        $l += [0.2126, 0.7152, 0.0722][$i] * $lin;
    }

    return $l;
}

function contrast(string $a, string $b): float
{
    $la = luminance($a);
    $lb = luminance($b);

    return round((max($la, $lb) + 0.05) / (min($la, $lb) + 0.05), 2);
}

/** $a moved $t of the way to $b. */
function mix(string $a, string $b, float $t): string
{
    $x = hex_rgb($a);
    $y = hex_rgb($b);

    return rgb_hex([$x[0] + ($y[0] - $x[0]) * $t, $x[1] + ($y[1] - $x[1]) * $t, $x[2] + ($y[2] - $x[2]) * $t]);
}

/** Whether a colour reads as dark (white text sits on it better than black). */
function is_dark(string $hex): bool
{
    return contrast($hex, '#ffffff') >= contrast($hex, '#000000');
}

/**
 * A logo's brand colours from the colours it is drawn with, weighted by how much of it each
 * covers: the most-used saturated colour is the accent, a near-black one the text.
 *
 * @param array<string, int|float> $weights  {"#rrggbb": weight}
 * @return array<string, string>  accent and/or text
 */
function logo_colors(array $weights): array
{
    arsort($weights);
    $out = [];
    foreach ($weights as $hex => $weight) {
        $hex = strtolower((string) $hex);
        if (!is_hex($hex) || $weight <= 0) {
            continue;
        }
        [$r, $g, $b] = hex_rgb($hex);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $saturation = $max > 0 ? ($max - $min) / $max : 0.0;
        $l = luminance($hex);
        if (!isset($out['accent']) && $saturation >= 0.3 && $l > 0.02 && $l < 0.85) {
            $out['accent'] = $hex;
        } elseif (!isset($out['text']) && $l < 0.03) {
            $out['text'] = $hex;
        }
    }

    return $out;
}
