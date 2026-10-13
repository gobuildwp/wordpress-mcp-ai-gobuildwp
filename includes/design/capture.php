<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Capture;

use WP_Error;

/**
 * Screenshots of a page, and the comparison between two of them.
 *
 * Every other check in GoBuildWP reads the page. None of them can see it, and the
 * failures that matter most to whoever owns the site are visual: a headline
 * that is illegible over the photograph behind it, a card that wrapped onto its
 * own row at 1024px, an element that rendered but is invisible. `not_checked`
 * on the rendered check has named `visual-regression` and
 * `responsive-breakpoints` since it was written; this is that gap.
 *
 * Two decisions shape the whole module.
 *
 * GoBuildWP does not bundle a renderer. A screenshot is taken by a browser - the
 * agent's own, or a logged-in wp-admin tab rasterising the page it loaded - and
 * this module stores what comes back and compares it. Bundling headless Chrome
 * in a WordPress plugin is not something a shared host would survive.
 *
 * Captures are attachments, and everything that refers to one refers to it by
 * id. A preview record is capped at 256KB and the whole change ledger at 4MB,
 * so a base64 screenshot in either would evict real history to store a picture.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Marks an attachment as a capture and records what it is a capture of. */
const CAPTURE_META = '_wppilot_capture';

/**
 * The captured post's ID, on its own key so a post's captures are one indexed
 * query. Filtering the site's newest captures in PHP lost a quiet page's
 * captures as soon as busier pages had taken more.
 */
const CAPTURE_POST_META = '_wppilot_capture_post';

/**
 * Set only on files GoBuildWP created itself. Pruning deletes those; an existing
 * media item somebody registered as a capture is unmarked, never deleted - it
 * may be the site's logo.
 */
const CAPTURE_OWNED_META = '_wppilot_capture_owned';

/**
 * How many captures to keep per post.
 *
 * Enough for a before, an after, and a few attempts in between. Captures are
 * full-size PNGs of whole pages, and an unbounded history would fill an uploads
 * directory with pictures of pages nobody is comparing any more.
 */
const CAPTURE_KEEP_PER_POST = 10;

/** The widths a capture set covers, and what each one is for. */
const CAPTURE_VIEWPORTS = [
    ['width' => 1440, 'label' => 'desktop'],
    ['width' => 1024, 'label' => 'tablet'],
    ['width' => 390, 'label' => 'phone'],
];

/**
 * The grid the comparison samples.
 *
 * A pixel-exact diff of two page screenshots reports every page as different:
 * a font renders a hair differently, an image is re-encoded, a carousel is on a
 * different slide. Sampling a grid of average colours answers the question
 * somebody actually has - did this region change - and is stable against all
 * three.
 */
const CAPTURE_DIFF_COLUMNS = 32;

const CAPTURE_DIFF_ROWS = 48;

/**
 * Per-cell colour distance, 0 to 1, above which a cell counts as changed.
 *
 * Low enough to catch a colour change nobody would call subtle, high enough to
 * ignore re-encoding and antialiasing.
 */
const CAPTURE_DIFF_THRESHOLD = 0.06;

/**
 * Record an attachment as a capture.
 *
 * @param array<string, mixed> $context
 */
function record(int $attachment_id, array $context, bool $owned = false): void
{
    update_post_meta($attachment_id, CAPTURE_POST_META, (int) ($context['post_id'] ?? 0));
    if ($owned) {
        update_post_meta($attachment_id, CAPTURE_OWNED_META, 1);
    }
    update_post_meta($attachment_id, CAPTURE_META, [
        'post_id' => (int) ($context['post_id'] ?? 0),
        'url' => (string) ($context['url'] ?? ''),
        'viewport' => (int) ($context['viewport'] ?? 0),
        'label' => (string) ($context['label'] ?? ''),
        'job_id' => (string) ($context['job_id'] ?? ''),
        'captured_at' => gmdate('c'),
    ]);

    prune((int) ($context['post_id'] ?? 0));
}

/**
 * A capture's context, or null when the attachment is not one.
 *
 * @return array<string, mixed>|null
 */
function context(int $attachment_id): ?array
{
    /** @var mixed $meta */
    $meta = get_post_meta($attachment_id, CAPTURE_META, single: true);

    return is_array($meta) ? $meta : null;
}

/**
 * Captures of one post, newest first.
 *
 * @return list<array<string, mixed>>
 */
function listing(int $post_id, int $limit = 50): array
{
    $attachments = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => $limit,
        'orderby' => 'ID',
        'order' => 'DESC',
        'fields' => 'ids',
        'meta_query' => $post_id > 0
            ? [['key' => CAPTURE_POST_META, 'value' => $post_id, 'compare' => '=', 'type' => 'NUMERIC']]
            : [['key' => CAPTURE_META, 'compare' => 'EXISTS']],
    ]);

    $captures = [];
    foreach ($attachments as $attachment_id) {
        $attachment_id = (int) $attachment_id;
        $context = context($attachment_id);
        if ($context === null || ($post_id > 0 && (int) ($context['post_id'] ?? 0) !== $post_id)) {
            continue;
        }
        // array_merge, not a spread: unpacking string keys is a fatal on PHP 8.0.
        $captures[] = array_merge($context, ['attachment_id' => $attachment_id, 'url' => wp_get_attachment_url($attachment_id)]);
    }

    return $captures;
}

/**
 * Delete the oldest captures of a post past the keep limit.
 */
function prune(int $post_id): void
{
    if ($post_id <= 0) {
        return;
    }

    $captures = listing($post_id, limit: -1);
    foreach (array_slice($captures, CAPTURE_KEEP_PER_POST) as $capture) {
        $attachment_id = (int) $capture['attachment_id'];
        if (get_post_meta($attachment_id, CAPTURE_OWNED_META, single: true)) {
            wp_delete_attachment($attachment_id, force_delete: true);
            continue;
        }
        delete_post_meta($attachment_id, CAPTURE_META);
        delete_post_meta($attachment_id, CAPTURE_POST_META);
    }
}

/**
 * Compare two captures.
 *
 * Returns a score from 0 (identical) to 1 (nothing in common) and the regions
 * that changed, in the coordinates of the newer image so they can be pointed at.
 *
 * @return array<string, mixed>|WP_Error
 */
function compare(int $before_id, int $after_id): array|WP_Error
{
    if (!function_exists('imagecreatefromstring')) {
        return new WP_Error(
            'wppilot_capture_no_gd',
            __(
                'This site has no GD image library, so two captures cannot be compared here. The images themselves are still available to look at.',
                domain: 'wppilot',
            ),
            ['status' => 501],
        );
    }

    $before = grid($before_id);
    if ($before instanceof WP_Error) {
        return $before;
    }

    $after = grid($after_id);
    if ($after instanceof WP_Error) {
        return $after;
    }

    $changed = [];
    $total = 0.0;
    $cells = CAPTURE_DIFF_COLUMNS * CAPTURE_DIFF_ROWS;

    for ($row = 0; $row < CAPTURE_DIFF_ROWS; ++$row) {
        for ($column = 0; $column < CAPTURE_DIFF_COLUMNS; ++$column) {
            $distance = cell_distance($before['cells'][$row][$column], $after['cells'][$row][$column]);
            $total += $distance;

            if ($distance > CAPTURE_DIFF_THRESHOLD) {
                $changed[] = [
                    'x' => (int) round(($column / CAPTURE_DIFF_COLUMNS) * $after['width']),
                    'y' => (int) round(($row / CAPTURE_DIFF_ROWS) * $after['height']),
                    'width' => (int) round($after['width'] / CAPTURE_DIFF_COLUMNS),
                    'height' => (int) round($after['height'] / CAPTURE_DIFF_ROWS),
                    'distance' => round($distance, 4),
                ];
            }
        }
    }

    // A page that got longer is a change in itself, and one the grid cannot see:
    // sampling normalises both images to the same grid, so added content shifts
    // every cell rather than showing up as one region.
    $height_ratio = $before['height'] > 0 ? $after['height'] / $before['height'] : 1.0;

    return [
        'before' => capture_summary($before_id),
        'after' => capture_summary($after_id),
        'diff_score' => round($total / $cells, 4),
        'changed_cells' => count($changed),
        'total_cells' => $cells,
        'changed_regions' => array_slice(merge_regions($changed), 0, 20),
        'height_ratio' => round($height_ratio, 3),
        'identical' => $changed === [] && abs($height_ratio - 1.0) < 0.01,
        'not_checked' => [
            'anything-below-the-captured-viewport-height',
            'animation-and-anything-a-script-paints-after-capture',
            'sub-cell-differences-smaller-than-the-sampling-grid',
        ],
    ];
}

/**
 * One capture reduced to a grid of average colours.
 *
 * @return array{cells: list<list<array{0: float, 1: float, 2: float}>>, width: int, height: int}|WP_Error
 */
function grid(int $attachment_id): array|WP_Error
{
    $path = get_attached_file($attachment_id);
    if (!is_string($path) || !is_readable($path)) {
        return new WP_Error(
            'wppilot_capture_missing',
            sprintf(
                /* translators: %d: attachment ID. */
                __('Capture %d has no readable image file.', domain: 'wppilot'),
                $attachment_id,
            ),
            ['status' => 404],
        );
    }

    $data = file_get_contents($path);
    $image = is_string($data) ? @imagecreatefromstring($data) : false;
    if ($image === false) {
        return new WP_Error(
            'wppilot_capture_unreadable',
            sprintf(
                /* translators: %d: attachment ID. */
                __('Capture %d is not an image this site can read.', domain: 'wppilot'),
                $attachment_id,
            ),
            ['status' => 422],
        );
    }

    $width = imagesx($image);
    $height = imagesy($image);

    // Downscale to the sampling grid in one step and let GD average the pixels,
    // which is both faster than sampling in PHP and better at it.
    $small = imagecreatetruecolor(CAPTURE_DIFF_COLUMNS, CAPTURE_DIFF_ROWS);
    imagecopyresampled($small, $image, 0, 0, 0, 0, CAPTURE_DIFF_COLUMNS, CAPTURE_DIFF_ROWS, $width, $height);
    imagedestroy($image);

    $cells = [];
    for ($row = 0; $row < CAPTURE_DIFF_ROWS; ++$row) {
        $line = [];
        for ($column = 0; $column < CAPTURE_DIFF_COLUMNS; ++$column) {
            $rgb = imagecolorat($small, $column, $row);
            $line[] = [
                (($rgb >> 16) & 0xFF) / 255,
                (($rgb >> 8) & 0xFF) / 255,
                ($rgb & 0xFF) / 255,
            ];
        }
        $cells[] = $line;
    }
    imagedestroy($small);

    return ['cells' => $cells, 'width' => $width, 'height' => $height];
}

/**
 * Distance between two cell colours, 0 to 1.
 *
 * @param array{0: float, 1: float, 2: float} $a
 * @param array{0: float, 1: float, 2: float} $b
 */
function cell_distance(array $a, array $b): float
{
    // Euclidean in RGB, normalised by the longest possible distance. Not a
    // perceptual metric: the question is "did this region change", and a
    // perceptual metric would answer a different, harder one less predictably.
    $sum = (($a[0] - $b[0]) ** 2) + (($a[1] - $b[1]) ** 2) + (($a[2] - $b[2]) ** 2);

    return sqrt($sum) / sqrt(3);
}

/**
 * Collapse adjacent changed cells into rectangles.
 *
 * A list of 200 changed cells is not a finding. "The region around the hero
 * headline changed" is, and that is what adjacent cells add up to.
 *
 * @param list<array<string, mixed>> $cells
 * @return list<array<string, mixed>>
 */
function merge_regions(array $cells): array
{
    $regions = [];

    foreach ($cells as $cell) {
        $merged = false;
        foreach ($regions as $index => $region) {
            if (regions_touch($region, $cell)) {
                $right = max($region['x'] + $region['width'], $cell['x'] + $cell['width']);
                $bottom = max($region['y'] + $region['height'], $cell['y'] + $cell['height']);
                $regions[$index]['x'] = min($region['x'], $cell['x']);
                $regions[$index]['y'] = min($region['y'], $cell['y']);
                $regions[$index]['width'] = $right - $regions[$index]['x'];
                $regions[$index]['height'] = $bottom - $regions[$index]['y'];
                $regions[$index]['cells'] = (int) $region['cells'] + 1;
                $regions[$index]['distance'] = max((float) $region['distance'], (float) $cell['distance']);
                $merged = true;
                break;
            }
        }

        if (!$merged) {
            $regions[] = array_merge($cell, ['cells' => 1]);
        }
    }

    usort($regions, static fn(array $a, array $b): int => ((int) $b['cells']) <=> ((int) $a['cells']));

    return $regions;
}

/**
 * Whether a cell belongs to a region already being built.
 *
 * @param array<string, mixed> $region
 * @param array<string, mixed> $cell
 */
function regions_touch(array $region, array $cell): bool
{
    // One cell's slack in each direction, so a changed area broken by a single
    // unchanged cell - a gap between two words - stays one region.
    $slack_x = (int) $cell['width'];
    $slack_y = (int) $cell['height'];

    return (int) $cell['x'] <= (int) $region['x'] + (int) $region['width'] + $slack_x
        && (int) $cell['x'] + (int) $cell['width'] + $slack_x >= (int) $region['x']
        && (int) $cell['y'] <= (int) $region['y'] + (int) $region['height'] + $slack_y
        && (int) $cell['y'] + (int) $cell['height'] + $slack_y >= (int) $region['y'];
}

/**
 * A capture, described.
 *
 * @return array<string, mixed>
 */
function capture_summary(int $attachment_id): array
{
    $context = context($attachment_id) ?? [];

    return [
        'attachment_id' => $attachment_id,
        'url' => wp_get_attachment_url($attachment_id),
        'viewport' => (int) ($context['viewport'] ?? 0),
        'label' => (string) ($context['label'] ?? ''),
        'captured_at' => (string) ($context['captured_at'] ?? ''),
    ];
}
