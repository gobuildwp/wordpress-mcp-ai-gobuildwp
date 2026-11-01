<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityTwoFactor;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * A small QR code encoder (ISO/IEC 18004), so the setup screen can show the otpauth:// URI as a
 * scannable code without sending the secret to an outside QR service.
 *
 * It does only what that needs: byte mode, error-correction level M, the smallest version (1-40)
 * the data fits, and the mask with the lowest penalty score. The result is a square matrix of
 * booleans (true = dark), drawn as one SVG path.
 */

/** Error-correction codewords per block, level M, by version (index 0 unused). ISO/IEC 18004 table 9. */
const QR_ECC_PER_BLOCK = [
    -1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26,
    26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28,
];

/** Error-correction blocks, level M, by version (index 0 unused). ISO/IEC 18004 table 9. */
const QR_BLOCKS = [
    -1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16,
    17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49,
];

/** Level M's two format bits. */
const QR_LEVEL_M = 0;

/**
 * @return list<list<bool>>|null Null when the data is longer than version 40 holds.
 */
function qr_matrix(string $data, ?int $force_mask = null): ?array
{
    $length = strlen($data);
    $version = 0;
    for ($v = 1; $v <= 40; $v++) {
        $count_bits = $v < 10 ? 8 : 16;
        if (4 + $count_bits + 8 * $length <= 8 * qr_data_codewords($v)) {
            $version = $v;
            break;
        }
    }
    if ($version === 0) {
        return null;
    }

    $codewords = qr_add_ecc(qr_data_bytes($data, $version), $version);

    $size = $version * 4 + 17;
    $modules = array_fill(0, $size, array_fill(0, $size, false));
    $function = $modules;
    qr_draw_function_patterns($modules, $function, $version);
    qr_draw_codewords($modules, $function, $codewords);

    $best = null;
    $best_score = PHP_INT_MAX;
    foreach ($force_mask === null ? range(0, 7) : [$force_mask] as $mask) {
        $candidate = $modules;
        qr_apply_mask($candidate, $function, $mask);
        qr_draw_format_bits($candidate, $function, $mask);
        $score = $force_mask === null ? qr_penalty($candidate) : 0;
        if ($score < $best_score) {
            $best = $candidate;
            $best_score = $score;
        }
    }
    return $best;
}

/**
 * The matrix as an SVG with the standard four-module quiet zone. Only numbers go into the markup.
 */
function qr_svg(string $data, int $module_px = 4): string
{
    $matrix = qr_matrix($data);
    if ($matrix === null) {
        return '';
    }
    $size = count($matrix);
    $full = $size + 8;
    $path = '';
    foreach ($matrix as $y => $row) {
        foreach ($row as $x => $dark) {
            if ($dark) {
                $path .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
            }
        }
    }
    $px = $full * $module_px;
    return '<svg xmlns="http://www.w3.org/2000/svg" role="img" width="' . $px . '" height="' . $px
        . '" viewBox="0 0 ' . $full . ' ' . $full . '" shape-rendering="crispEdges">'
        . '<rect width="' . $full . '" height="' . $full . '" fill="#fff"/>'
        . '<path fill="#000" d="' . $path . '"/></svg>';
}

function qr_raw_modules(int $version): int
{
    $result = (16 * $version + 128) * $version + 64;
    if ($version >= 2) {
        $align = intdiv($version, 7) + 2;
        $result -= (25 * $align - 10) * $align - 55;
        if ($version >= 7) {
            $result -= 36;
        }
    }
    return $result;
}

function qr_data_codewords(int $version): int
{
    return intdiv(qr_raw_modules($version), 8) - QR_ECC_PER_BLOCK[$version] * QR_BLOCKS[$version];
}

/**
 * Mode indicator, length, the bytes, terminator and padding, as codewords.
 *
 * @return list<int>
 */
function qr_data_bytes(string $data, int $version): array
{
    $capacity = qr_data_codewords($version) * 8;
    $bits = '0100' . str_pad(decbin(strlen($data)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    foreach (str_split($data) as $byte) {
        if ($byte !== '') {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
    }
    $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
    $bytes = [];
    foreach (str_split($bits, 8) as $chunk) {
        $bytes[] = (int) bindec($chunk);
    }
    for ($pad = 0xEC; count($bytes) < $capacity / 8; $pad ^= 0xEC ^ 0x11) {
        $bytes[] = $pad;
    }
    return $bytes;
}

/**
 * Split into blocks, add each block's Reed-Solomon codewords, and interleave.
 *
 * @param list<int> $data
 * @return list<int>
 */
function qr_add_ecc(array $data, int $version): array
{
    $blocks_total = QR_BLOCKS[$version];
    $ecc_len = QR_ECC_PER_BLOCK[$version];
    $raw = intdiv(qr_raw_modules($version), 8);
    $short_blocks = $blocks_total - $raw % $blocks_total;
    $short_len = intdiv($raw, $blocks_total);
    $divisor = qr_rs_divisor($ecc_len);

    $blocks = [];
    $k = 0;
    for ($i = 0; $i < $blocks_total; $i++) {
        $len = $short_len - $ecc_len + ($i < $short_blocks ? 0 : 1);
        $block = array_slice($data, $k, $len);
        $k += $len;
        $ecc = qr_rs_remainder($block, $divisor);
        if ($i < $short_blocks) {
            $block[] = 0;
        }
        $blocks[] = array_merge($block, $ecc);
    }

    $out = [];
    $width = count($blocks[0]);
    for ($i = 0; $i < $width; $i++) {
        foreach ($blocks as $j => $block) {
            // The placeholder byte the short blocks carry is not part of the stream.
            if ($i !== $short_len - $ecc_len || $j >= $short_blocks) {
                $out[] = $block[$i];
            }
        }
    }
    return $out;
}

function qr_gf_multiply(int $x, int $y): int
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = ($z << 1) ^ (($z >> 7) * 0x11D);
        $z ^= (($y >> $i) & 1) * $x;
    }
    return $z;
}

/** @return list<int> */
function qr_rs_divisor(int $degree): array
{
    $result = array_fill(0, $degree, 0);
    $result[$degree - 1] = 1;
    $root = 1;
    for ($i = 0; $i < $degree; $i++) {
        for ($j = 0; $j < $degree; $j++) {
            $result[$j] = qr_gf_multiply($result[$j], $root);
            if ($j + 1 < $degree) {
                $result[$j] ^= $result[$j + 1];
            }
        }
        $root = qr_gf_multiply($root, 0x02);
    }
    return $result;
}

/**
 * @param list<int> $data
 * @param list<int> $divisor
 * @return list<int>
 */
function qr_rs_remainder(array $data, array $divisor): array
{
    $result = array_fill(0, count($divisor), 0);
    foreach ($data as $byte) {
        $factor = $byte ^ array_shift($result);
        $result[] = 0;
        foreach ($divisor as $i => $coef) {
            $result[$i] ^= qr_gf_multiply($coef, $factor);
        }
    }
    return $result;
}

/**
 * @param list<list<bool>> $modules
 * @param list<list<bool>> $function
 */
function qr_set(array &$modules, array &$function, int $x, int $y, bool $dark): void
{
    $modules[$y][$x] = $dark;
    $function[$y][$x] = true;
}

/** @return list<int> */
function qr_alignment_positions(int $version): array
{
    if ($version === 1) {
        return [];
    }
    $count = intdiv($version, 7) + 2;
    $step = intdiv($version * 8 + $count * 3 + 5, $count * 4 - 4) * 2;
    $positions = [];
    for ($i = 0, $pos = $version * 4 + 10; $i < $count - 1; $i++, $pos -= $step) {
        array_unshift($positions, $pos);
    }
    array_unshift($positions, 6);
    return $positions;
}

/**
 * Timing, finder and alignment patterns, and reserved format and version areas.
 *
 * @param list<list<bool>> $modules
 * @param list<list<bool>> $function
 */
// @mago-expect lint:cyclomatic-complexity -- The fixed patterns of the QR specification, drawn in the order it lists them.
function qr_draw_function_patterns(array &$modules, array &$function, int $version): void
{
    $size = count($modules);
    for ($i = 0; $i < $size; $i++) {
        qr_set($modules, $function, 6, $i, $i % 2 === 0);
        qr_set($modules, $function, $i, 6, $i % 2 === 0);
    }
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                    $dist = max(abs($dx), abs($dy));
                    qr_set($modules, $function, $x, $y, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }
    $align = qr_alignment_positions($version);
    $last = count($align) - 1;
    foreach ($align as $i => $ax) {
        foreach ($align as $j => $ay) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                continue;
            }
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    qr_set($modules, $function, $ax + $dx, $ay + $dy, max(abs($dx), abs($dy)) !== 1);
                }
            }
        }
    }
    // Reserve the format areas now; the real bits go in once the mask is chosen.
    qr_draw_format_bits($modules, $function, 0);
    if ($version >= 7) {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            qr_set($modules, $function, $a, $b, $dark);
            qr_set($modules, $function, $b, $a, $dark);
        }
    }
}

/**
 * @param list<list<bool>> $modules
 * @param list<list<bool>> $function
 */
function qr_draw_format_bits(array &$modules, array &$function, int $mask): void
{
    $size = count($modules);
    $data = (QR_LEVEL_M << 3) | $mask;
    $rem = $data;
    for ($i = 0; $i < 10; $i++) {
        $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
    }
    $bits = (($data << 10) | $rem) ^ 0x5412;
    $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;

    for ($i = 0; $i <= 5; $i++) {
        qr_set($modules, $function, 8, $i, $bit($i));
    }
    qr_set($modules, $function, 8, 7, $bit(6));
    qr_set($modules, $function, 8, 8, $bit(7));
    qr_set($modules, $function, 7, 8, $bit(8));
    for ($i = 9; $i < 15; $i++) {
        qr_set($modules, $function, 14 - $i, 8, $bit($i));
    }
    for ($i = 0; $i < 8; $i++) {
        qr_set($modules, $function, $size - 1 - $i, 8, $bit($i));
    }
    for ($i = 8; $i < 15; $i++) {
        qr_set($modules, $function, 8, $size - 15 + $i, $bit($i));
    }
    qr_set($modules, $function, 8, $size - 8, true);
}

/**
 * Place the codewords in the zigzag order, two columns at a time from the right.
 *
 * @param list<list<bool>> $modules
 * @param list<list<bool>> $function
 * @param list<int> $codewords
 */
function qr_draw_codewords(array &$modules, array $function, array $codewords): void
{
    $size = count($modules);
    $total = count($codewords) * 8;
    $i = 0;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right = 5;
        }
        $upward = (($right + 1) & 2) === 0;
        for ($vert = 0; $vert < $size; $vert++) {
            $y = $upward ? $size - 1 - $vert : $vert;
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                if (!$function[$y][$x] && $i < $total) {
                    $modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                    $i++;
                }
            }
        }
    }
}

/**
 * @param list<list<bool>> $modules
 * @param list<list<bool>> $function
 */
function qr_apply_mask(array &$modules, array $function, int $mask): void
{
    $size = count($modules);
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($function[$y][$x]) {
                continue;
            }
            $invert = match ($mask) {
                0 => ($x + $y) % 2 === 0,
                1 => $y % 2 === 0,
                2 => $x % 3 === 0,
                3 => ($x + $y) % 3 === 0,
                4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                default => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
            };
            if ($invert) {
                $modules[$y][$x] = !$modules[$y][$x];
            }
        }
    }
}

/**
 * The standard's mask penalty: runs of five or more, 2x2 blocks, finder-like 1:1:3:1:1 patterns
 * with four light modules on one side, and dark/light imbalance. Lower is easier to scan.
 *
 * @param list<list<bool>> $m
 */
function qr_penalty(array $m): int
{
    $size = count($m);
    $score = 0;
    $lines = [];
    for ($i = 0; $i < $size; $i++) {
        $lines[] = $m[$i];
        $lines[] = array_column($m, $i);
    }
    foreach ($lines as $line) {
        $run = 1;
        for ($i = 1; $i <= $size; $i++) {
            if ($i < $size && $line[$i] === $line[$i - 1]) {
                $run++;
                continue;
            }
            if ($run >= 5) {
                $score += $run - 2;
            }
            $run = 1;
        }
        // Light padding outside the symbol counts as light, as the quiet zone is.
        $text = '0000' . implode('', array_map(static fn(bool $d): string => $d ? '1' : '0', $line)) . '0000';
        $score += 40 * (int) preg_match_all('/(?=00001011101|10111010000)/', $text);
    }
    $dark = 0;
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($m[$y][$x]) {
                $dark++;
            }
            if ($x + 1 < $size && $y + 1 < $size) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
    }
    $total = $size * $size;
    $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
    return $score + max(0, $k) * 10;
}
