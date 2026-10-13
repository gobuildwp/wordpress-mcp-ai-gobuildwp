<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\A11yAudit;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\PostPartial;

if (!defined('ABSPATH')) {
    exit();
}

const ALT_META_KEY = '_wp_attachment_image_alt';

const UPDATE_ABILITY = 'wppilot/update-image-alt';

const MAX_ALT_ITEMS = 100;

const MAX_ALT_LENGTH = 1000;

const MAX_SCAN_PAGE = 100;

/** Longest side of a preview, by default and at most. Vision models downscale past ~1568. */
const PREVIEW_DEFAULT = 1024;

const PREVIEW_MAX = 1568;

/** Largest preview returned, in bytes before base64: about 1 MB once encoded. */
const PREVIEW_MAX_BYTES = 750_000;

/** Words in a file name or title that usually mean the image is decoration. */
const DECORATIVE_WORDS = ['spacer', 'divider', 'separator', 'border', 'background', 'bg', 'pattern', 'texture', 'shadow', 'gradient', 'line', 'dots', 'blank', 'transparent', 'pixel', 'ornament', 'flourish', 'swirl', 'overlay', 'shape', 'wave', 'blob'];

/**
 * One page of the media library's images and the state of their alt text.
 *
 * Paged by the library itself (newest first), not by "images with problems": a filename-as-alt
 * check cannot be done in SQL, so a page is fetched and judged here, and `include` decides
 * whether the fine ones are listed too. `next_page` keeps going until the library is exhausted.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function scan_media_alt(array $input): array
{
    $page = max(1, (int) ($input['page'] ?? 1));
    $per_page = min(MAX_SCAN_PAGE, max(1, (int) ($input['per_page'] ?? 50)));
    $include_all = ($input['include'] ?? 'issues') === 'all';

    $ids = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'post_mime_type' => 'image',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'orderby' => 'ID',
        'order' => 'DESC',
        'fields' => 'ids',
        'no_found_rows' => true,
    ]);
    $ids = is_array($ids) ? array_map('intval', $ids) : [];

    $images = [];
    $counts = ['missing' => 0, 'filename' => 0, 'ok' => 0, 'likely_decorative' => 0];
    foreach ($ids as $id) {
        $row = describe_attachment($id);
        $counts[$row['alt_status']]++;
        if ($row['decorative_guess']['likely']) {
            $counts['likely_decorative']++;
        }
        if ($include_all || $row['alt_status'] !== 'ok') {
            $images[] = $row;
        }
    }
    $total = count_images();

    return [
        'images' => $images,
        'page' => $page,
        'per_page' => $per_page,
        'scanned' => count($ids),
        'page_summary' => $counts,
        'total_images' => $total,
        'next_page' => count($ids) === $per_page && $page * $per_page < $total ? $page + 1 : null,
    ];
}

/**
 * @return array{attachment_id: int, title: string, filename: string, url: string, mime_type: string, width: int, height: int, alt: string, alt_status: string, decorative_guess: array{likely: bool, reason: string}}
 */
function describe_attachment(int $id): array
{
    $post = get_post($id);
    $alt = (string) get_post_meta($id, ALT_META_KEY, true);
    $file = (string) get_post_meta($id, '_wp_attached_file', true);
    $meta = wp_get_attachment_metadata($id);
    $width = is_array($meta) ? (int) ($meta['width'] ?? 0) : 0;
    $height = is_array($meta) ? (int) ($meta['height'] ?? 0) : 0;
    $filename = wp_basename($file);
    $title = $post instanceof \WP_Post ? $post->post_title : '';

    return [
        'attachment_id' => $id,
        'title' => $title,
        'filename' => $filename,
        'url' => (string) wp_get_attachment_url($id),
        'mime_type' => (string) get_post_mime_type($id),
        'width' => $width,
        'height' => $height,
        'alt' => $alt,
        'alt_status' => alt_status($alt, $filename),
        'decorative_guess' => decorative_guess($filename, $title, $width, $height),
    ];
}

/** `missing`, `filename` (the alt is the file name or a camera name), or `ok`. */
function alt_status(string $alt, string $filename): string
{
    if (trim($alt) === '') {
        return 'missing';
    }
    return alt_is_filename($alt, $filename !== '' ? '/' . $filename : '') ? 'filename' : 'ok';
}

/**
 * A guess, never a verdict: an image may be decoration if its name says so or it is tiny. The
 * agent must look (get-media-image) before writing alt="".
 *
 * @return array{likely: bool, reason: string}
 */
function decorative_guess(string $filename, string $title, int $width, int $height): array
{
    if ($width > 0 && $height > 0 && $width <= 32 && $height <= 32) {
        return ['likely' => true, 'reason' => sprintf('It is only %dx%d pixels.', $width, $height)];
    }
    $words = preg_split('/[^a-z0-9]+/', strtolower(pathinfo($filename, PATHINFO_FILENAME) . ' ' . $title)) ?: [];
    $hits = array_values(array_intersect(DECORATIVE_WORDS, $words));
    if ($hits !== []) {
        return ['likely' => true, 'reason' => sprintf('Its name contains "%s".', $hits[0])];
    }
    if ($width > 0 && $height > 0 && ($width / $height >= 8 || $height / $width >= 8)) {
        return ['likely' => true, 'reason' => sprintf('It is a thin strip (%dx%d), like a divider.', $width, $height)];
    }
    return ['likely' => false, 'reason' => ''];
}

function count_images(): int
{
    $counts = wp_count_attachments('image');
    $total = 0;
    foreach (is_object($counts) ? get_object_vars($counts) : [] as $mime => $count) {
        if (str_starts_with((string) $mime, 'image/')) {
            $total += (int) $count;
        }
    }
    return $total;
}

/**
 * Set alt text on up to 100 images, one ledger row per image, all in one group.
 *
 * Every write has its before-image first. When the host's snapshot budget for one call would be
 * exceeded, the remaining items are skipped and reported, never written without one.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function update_alts(array $input): array|WP_Error
{
    $items = is_array($input['items'] ?? null) ? array_values($input['items']) : [];
    if ($items === [] || count($items) > MAX_ALT_ITEMS) {
        return new WP_Error('kit_a11y_alt_items', sprintf('Give between 1 and %d items.', MAX_ALT_ITEMS));
    }

    $ledger = Runtime\host()->ledger();
    $budget = $ledger->snapshot_budget();
    $used = 0;
    $rows = [];
    $records = [];
    $seen = [];
    foreach ($items as $index => $item) {
        $id = is_array($item) ? (int) ($item['attachment_id'] ?? 0) : 0;
        $row = ['attachment_id' => $id, 'status' => 'skipped'];
        if (!is_array($item) || $id <= 0 || !is_string($item['alt'] ?? null)) {
            $rows[] = $row + ['reason' => sprintf('Item %d needs attachment_id and alt.', $index + 1)];
            continue;
        }
        if (isset($seen[$id])) {
            $rows[] = $row + ['reason' => 'This attachment appears earlier in the same call; only the first is applied.'];
            continue;
        }
        $seen[$id] = true;
        $alt = sanitize_text_field($item['alt']);
        if (strlen($alt) > MAX_ALT_LENGTH) {
            $rows[] = $row + ['reason' => sprintf('Alt text is limited to %d characters; describe, do not transcribe.', MAX_ALT_LENGTH)];
            continue;
        }
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== 'attachment' || !wp_attachment_is_image($id)) {
            $rows[] = $row + ['reason' => 'Not an image attachment.'];
            continue;
        }
        if (!current_user_can('edit_post', $id)) {
            $rows[] = $row + ['reason' => 'You cannot edit this attachment.'];
            continue;
        }
        $previous = (string) get_post_meta($id, ALT_META_KEY, true);
        if ($previous === $alt) {
            $rows[] = ['attachment_id' => $id, 'status' => 'unchanged', 'alt' => $alt];
            continue;
        }
        $before = PostPartial\capture($id, [], [ALT_META_KEY]);
        $bytes = strlen((string) wp_json_encode($before));
        if ($before === null || $used + $bytes > $budget) {
            $rows[] = $row + ['reason' => 'The change log\'s snapshot budget for one call is used up; send this item again in another call.'];
            continue;
        }
        $used += $bytes;

        update_post_meta($id, ALT_META_KEY, wp_slash($alt));
        $stored = (string) get_post_meta($id, ALT_META_KEY, true);
        if ($stored === $previous) {
            // Something (a filter, a read-only meta layer) kept the old value; nothing changed.
            $rows[] = ['attachment_id' => $id, 'status' => 'failed', 'reason' => 'WordPress did not store the new alt text.'];
            continue;
        }
        // Recorded as stored, even if a filter rewrote it: whatever changed must be undoable.
        $records[] = [
            'input' => ['attachment_id' => $id, 'alt' => $alt],
            'before' => $before,
            'result' => ['attachment_id' => $id, 'alt' => $stored, 'previous_alt' => $previous],
            'item' => ['attachment_id' => $id],
        ];
        $row = ['attachment_id' => $id, 'status' => 'updated', 'alt' => $stored, 'previous_alt' => $previous, 'decorative' => $stored === ''];
        $rows[] = $stored === $alt ? $row : $row + ['note' => 'The site changed the alt text as it was saved; alt is what was stored.'];
    }

    // Called even when nothing was written: it also discards the one-row record the host opened
    // for this call, which would otherwise say "not reversible" about a call that changed nothing.
    $recorded = $ledger->record_items(UPDATE_ABILITY, $records);
    $group = $records !== [] ? $recorded['group'] : null;
    $next = 0;
    foreach ($rows as $index => $row) {
        if ($row['status'] === 'updated') {
            $rows[$index]['change_id'] = (string) ($recorded['change_ids'][$next++] ?? '');
        }
    }

    $count = static fn(string $status): int => count(array_filter($rows, static fn(array $row): bool => $row['status'] === $status));
    return [
        'updated' => $count('updated'),
        'unchanged' => $count('unchanged'),
        'skipped' => $count('skipped'),
        'failed' => $count('failed'),
        'group' => $group,
        'results' => $rows,
    ];
}

/**
 * A downscaled copy of an attachment's image, for the model to look at.
 *
 * Returned under the `_mcp_content` convention (see includes/mcp/transport.php in GoBuildWP), so
 * an MCP client shows it to the model as an image; over REST it is plain JSON with base64.
 * Written to a temporary file only because the image editors save to a path; every file this
 * creates is deleted before returning, whatever happens.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function media_image(array $input): array|WP_Error
{
    $id = (int) ($input['attachment_id'] ?? 0);
    if ($id <= 0) {
        return new WP_Error('kit_a11y_image_id', 'attachment_id is required.');
    }
    $post = get_post($id);
    if (!$post instanceof \WP_Post || $post->post_type !== 'attachment') {
        return new WP_Error('kit_a11y_image_not_found', 'No attachment has that ID.', ['status' => 404]);
    }
    if (!current_user_can('read_post', $id)) {
        return new WP_Error('kit_a11y_image_forbidden', 'You cannot view this attachment.', ['status' => 403]);
    }
    $mime = (string) get_post_mime_type($id);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/heic'], strict: true)) {
        return new WP_Error('kit_a11y_image_type', sprintf('Only raster images can be previewed; this attachment is %s. For an SVG, read its markup instead.', $mime !== '' ? $mime : 'of unknown type'));
    }
    $path = get_attached_file($id);
    if (!is_string($path) || $path === '' || !file_exists($path)) {
        return new WP_Error('kit_a11y_image_no_file', 'The image file is not on this server (offloaded media cannot be previewed here).');
    }

    $max = min(PREVIEW_MAX, max(128, (int) ($input['max_size'] ?? PREVIEW_DEFAULT)));
    $meta = wp_get_attachment_metadata($id);
    $source = preview_source(is_array($meta) ? $meta : [], $path, $max);
    $editor = wp_get_image_editor($source);
    if ($editor instanceof WP_Error) {
        return new WP_Error('kit_a11y_image_open', 'The image could not be opened: ' . $editor->get_error_message());
    }
    $format = (string) ($input['format'] ?? 'auto');
    $out_mime = match ($format) {
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'png' => 'image/png',
        default => wp_image_editor_supports(['mime_type' => 'image/webp']) ? 'image/webp' : 'image/jpeg',
    };

    $bytes = null;
    $size = [];
    // Lower quality first, then a smaller image: a busy photo can exceed the cap at 1024 px.
    foreach ([[$max, 82], [$max, 60], [(int) round($max * 0.75), 60], [(int) round($max * 0.5), 60]] as [$side, $quality]) {
        $current = $editor->get_size();
        if (max((int) ($current['width'] ?? 0), (int) ($current['height'] ?? 0)) > $side) {
            $resized = $editor->resize($side, $side, false);
            if ($resized instanceof WP_Error) {
                return new WP_Error('kit_a11y_image_resize', 'The image could not be downscaled: ' . $resized->get_error_message());
            }
        }
        $editor->set_quality($quality);
        $encoded = encode_to_bytes($editor, $out_mime);
        if ($encoded instanceof WP_Error) {
            return $encoded;
        }
        [$bytes, $out_mime] = $encoded;
        $size = $editor->get_size();
        if (strlen($bytes) <= PREVIEW_MAX_BYTES) {
            break;
        }
        $bytes = null;
    }
    if ($bytes === null) {
        return new WP_Error('kit_a11y_image_too_large', 'Even a small preview of this image is over the size limit.');
    }

    return [
        'attachment_id' => $id,
        'title' => $post->post_title,
        'filename' => wp_basename($path),
        'alt' => (string) get_post_meta($id, ALT_META_KEY, true),
        'caption' => $post->post_excerpt,
        'width' => (int) ($size['width'] ?? 0),
        'height' => (int) ($size['height'] ?? 0),
        'original_width' => is_array($meta) ? (int) ($meta['width'] ?? 0) : 0,
        'original_height' => is_array($meta) ? (int) ($meta['height'] ?? 0) : 0,
        'mime_type' => $out_mime,
        'bytes' => strlen($bytes),
        '_mcp_content' => [['type' => 'image', 'data' => base64_encode($bytes), 'mimeType' => $out_mime]],
    ];
}

/**
 * The smallest stored size whose longer side still covers the preview, else the file itself:
 * decoding a 6000 px original to make a 1024 px preview costs memory for nothing.
 *
 * @param array<string, mixed> $meta
 */
function preview_source(array $meta, string $path, int $max): string
{
    $best = null;
    $best_side = PHP_INT_MAX;
    foreach (is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [] as $size) {
        if (!is_array($size) || !is_string($size['file'] ?? null)) {
            continue;
        }
        $side = max((int) ($size['width'] ?? 0), (int) ($size['height'] ?? 0));
        $candidate = dirname($path) . '/' . wp_basename($size['file']);
        if ($side >= $max && $side < $best_side && file_exists($candidate)) {
            $best = $candidate;
            $best_side = $side;
        }
    }
    return $best ?? $path;
}

/**
 * Save the editor's current image to a temporary file, read it back, and delete every file the
 * save made.
 *
 * @return array{0: string, 1: string}|WP_Error The bytes and the type actually written.
 */
function encode_to_bytes(object $editor, string $mime): array|WP_Error
{
    $extension = $mime === 'image/jpeg' ? 'jpg' : substr($mime, 6);
    // Unguessable, so another request cannot read or swap the file between save and read.
    $target = get_temp_dir() . 'kit-media-preview-' . bin2hex(random_bytes(12)) . '.' . $extension;
    $written = [$target];
    try {
        $saved = $editor->save($target, $mime);
        if (is_array($saved) && is_string($saved['path'] ?? null)) {
            // The site's output-format filter may have changed the extension.
            $written[] = $saved['path'];
        }
        if ($saved instanceof WP_Error || !is_array($saved) || !is_string($saved['path'] ?? null) || !is_readable($saved['path'])) {
            return new WP_Error('kit_a11y_image_encode', 'The preview could not be written' . ($saved instanceof WP_Error ? ': ' . $saved->get_error_message() : '.'));
        }
        $bytes = file_get_contents($saved['path']);
        if (!is_string($bytes) || $bytes === '') {
            return new WP_Error('kit_a11y_image_encode', 'The preview could not be read back.');
        }
        $written_mime = (string) ($saved['mime-type'] ?? $mime);
        if (!in_array($written_mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], strict: true)) {
            // The site's output-format filter turned it into something MCP clients do not show.
            return new WP_Error('kit_a11y_image_encode', sprintf('This site converts previews to %s, which vision models do not accept. Try another format.', $written_mime));
        }
        return [$bytes, $written_mime];
    } finally {
        foreach (array_unique($written) as $file) {
            if (is_string($file) && $file !== '' && file_exists($file)) {
                wp_delete_file($file);
            }
        }
    }
}
