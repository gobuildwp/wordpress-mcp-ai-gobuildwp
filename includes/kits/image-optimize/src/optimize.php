<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\ImageOptimize;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Ledger;

if (!defined('ABSPATH')) {
    exit();
}

const RUN_ABILITY = 'gobuildwp/image-optimize-run';
const RESTORE_ABILITY = 'gobuildwp/image-optimize-restore';
const SERVING_ABILITY = 'gobuildwp/image-optimize-serving';

/** Undo of one optimised image: the kept original goes back and the copies are deleted. */
const STRATEGY_ORIGINAL = 'image-optimize/original';

/** Undo of a serving-mode change. */
const STRATEGY_SERVING = 'image-optimize/serving';

/** Per attachment: what was done, where the original is kept, and which copies exist. */
const META_KEY = '_gobuildwp_kit_image_opt';

/** The last attachment id the bulk runner finished, so the next call carries on after it. */
const CURSOR_OPTION = 'gobuildwp_kit_image_opt_cursor';

const SERVE_OPTION = 'gobuildwp_kit_image_opt_serve';

const SERVE_MODES = ['off', 'picture'];

/** Originals the kit rewrites. GIF is left alone (animation); WebP and AVIF are already modern. */
const SOURCE_MIMES = ['image/jpeg', 'image/png'];

/** Copy formats, in the order a browser should prefer them. */
const COPY_FORMATS = ['avif' => 'image/avif', 'webp' => 'image/webp'];

const DEFAULT_QUALITY = 82;
const MIN_QUALITY = 40;
const MAX_QUALITY = 100;

/** Longest side an original is resized to; 0 turns resizing off. */
const DEFAULT_MAX_DIMENSION = 2560;
const MIN_MAX_DIMENSION = 320;
const MAX_MAX_DIMENSION = 8000;

const DEFAULT_BATCH = 10;
const MAX_BATCH = 25;

/** Images an estimate reads metadata for in one call, and how many it really encodes. */
const MAX_ESTIMATE_SCAN = 500;
const DEFAULT_SAMPLE = 3;
const MAX_SAMPLE = 5;

/** Ready images an estimate lists one by one; the totals still cover every scanned image. */
const MAX_ESTIMATE_LIST = 100;

/** An original larger than this is not decoded: GD would need gigabytes. */
const MAX_SOURCE_PIXELS = 50_000_000;

/** Seconds of encoding one run may spend before it stops and hands back a cursor. */
const TIME_BUDGET = 20.0;

/** The kept original sits next to the file as <name>.optimize-backup.<ext>. */
const BACKUP_INFIX = '.optimize-backup';

/**
 * @param array<string, mixed> $input
 * @return array{quality: int, max_dimension: int, formats: list<string>, unsupported_formats: list<string>, compress_original: bool, limit: int}
 */
function options(array $input): array
{
    $quality = isset($input['quality']) ? max(MIN_QUALITY, min(MAX_QUALITY, (int) $input['quality'])) : DEFAULT_QUALITY;
    $max = isset($input['max_dimension']) ? (int) $input['max_dimension'] : DEFAULT_MAX_DIMENSION;
    $max = $max <= 0 ? 0 : max(MIN_MAX_DIMENSION, min(MAX_MAX_DIMENSION, $max));
    $asked = is_array($input['formats'] ?? null) ? $input['formats'] : ['webp'];
    $formats = [];
    $unsupported = [];
    foreach (array_keys(COPY_FORMATS) as $format) {
        if (!in_array($format, $asked, true)) {
            continue;
        }
        if (wp_image_editor_supports(['mime_type' => COPY_FORMATS[$format]])) {
            $formats[] = $format;
        } else {
            $unsupported[] = $format;
        }
    }
    return [
        'quality' => $quality,
        'max_dimension' => $max,
        'formats' => $formats,
        'unsupported_formats' => $unsupported,
        'compress_original' => !array_key_exists('compress_original', $input) || (bool) $input['compress_original'],
        'limit' => isset($input['limit']) ? max(1, min(MAX_BATCH, (int) $input['limit'])) : DEFAULT_BATCH,
    ];
}

/** The uploads folder, without a trailing slash, or '' when WordPress reports an error. */
function basedir(): string
{
    $uploads = wp_get_upload_dir();
    return empty($uploads['error']) && is_string($uploads['basedir'] ?? null) ? rtrim(str_replace('\\', '/', $uploads['basedir']), '/') : '';
}

/** A path relative to the uploads folder, or null when it is outside it. */
function relative(string $path): ?string
{
    $base = basedir();
    $path = str_replace('\\', '/', $path);
    if ($base === '' || !str_starts_with($path, $base . '/')) {
        return null;
    }
    return substr($path, strlen($base) + 1);
}

/** An absolute path for a stored relative one, refusing anything that climbs out of uploads. */
function absolute(string $relative): ?string
{
    $base = basedir();
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    if ($base === '' || $relative === '' || preg_match('#(^|/)\.\.(/|$)#', $relative) === 1) {
        return null;
    }
    return $base . '/' . $relative;
}

/**
 * Ids of JPEG and PNG attachments after $after, lowest first.
 *
 * @return list<int>
 */
function candidate_ids(int $after, int $limit): array
{
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (%s, %s) AND ID > %d ORDER BY ID ASC LIMIT %d",
        SOURCE_MIMES[0],
        SOURCE_MIMES[1],
        $after,
        $limit,
    ));
    return is_array($ids) ? array_map('intval', $ids) : [];
}

function count_candidates(int $after = 0): int
{
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (%s, %s) AND ID > %d",
        SOURCE_MIMES[0],
        SOURCE_MIMES[1],
        $after,
    ));
}

function count_optimized(): int
{
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", META_KEY));
}

/** @return array<string, mixed>|null */
function record_of(int $id): ?array
{
    $record = get_post_meta($id, META_KEY, true);
    return is_array($record) ? $record : null;
}

/**
 * The full-size file and every generated size that lives next to it, deduplicated.
 *
 * @param array<string, mixed> $meta
 * @return list<string>
 */
function files_of(string $path, array $meta): array
{
    $files = [$path];
    $dir = dirname($path);
    foreach (is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [] as $size) {
        $file = is_array($size) && is_string($size['file'] ?? null) ? wp_basename($size['file']) : '';
        if ($file !== '' && is_file($dir . '/' . $file)) {
            $files[] = $dir . '/' . $file;
        }
    }
    return array_values(array_unique($files));
}

/**
 * What a run would do to one attachment, read from its metadata only.
 *
 * @param array{quality: int, max_dimension: int, formats: list<string>, compress_original: bool} $o
 * @return array<string, mixed>
 */
function plan(int $id, array $o): array
{
    $row = ['attachment_id' => $id, 'status' => 'skip'];
    $mime = (string) get_post_mime_type($id);
    if (!in_array($mime, SOURCE_MIMES, true)) {
        return $row + ['reason' => 'not_jpeg_or_png'];
    }
    if (record_of($id) !== null) {
        return $row + ['reason' => 'already_optimized'];
    }
    $path = get_attached_file($id);
    $meta = wp_get_attachment_metadata($id);
    if (!is_string($path) || !is_file($path)) {
        return $row + ['reason' => 'file_missing'];
    }
    if (!is_array($meta) || relative($path) === null) {
        return $row + ['reason' => 'no_metadata'];
    }
    $width = (int) ($meta['width'] ?? 0);
    $height = (int) ($meta['height'] ?? 0);
    if ($width * $height > MAX_SOURCE_PIXELS) {
        return $row + ['reason' => 'too_large_to_decode'];
    }
    $resize_to = null;
    if ($o['max_dimension'] > 0 && max($width, $height) > $o['max_dimension']) {
        $scale = $o['max_dimension'] / max($width, $height);
        $resize_to = ['width' => max(1, (int) round($width * $scale)), 'height' => max(1, (int) round($height * $scale))];
    }
    if ($resize_to === null && !$o['compress_original'] && $o['formats'] === []) {
        return $row + ['reason' => 'nothing_to_do'];
    }
    return [
        'attachment_id' => $id,
        'status' => 'ready',
        'file' => (string) relative($path),
        'mime_type' => $mime,
        'width' => $width,
        'height' => $height,
        'bytes' => (int) filesize($path),
        'resize_to' => $resize_to,
        'files_for_copies' => count(files_of($path, $meta)),
    ];
}

/**
 * Encode $source with the editor and return the bytes written, deleting nothing.
 *
 * @return array{path: string, bytes: int, width: int, height: int}|WP_Error
 */
function encode(string $source, string $dest, string $mime, int $quality, int $max_dimension = 0): array|WP_Error
{
    $editor = wp_get_image_editor($source);
    if ($editor instanceof WP_Error) {
        return $editor;
    }
    if ($max_dimension > 0) {
        $size = $editor->get_size();
        if (max((int) $size['width'], (int) $size['height']) > $max_dimension) {
            $resized = $editor->resize($max_dimension, $max_dimension, false);
            if ($resized instanceof WP_Error) {
                return $resized;
            }
        }
    }
    $editor->set_quality($quality);
    $saved = $editor->save($dest, $mime);
    if ($saved instanceof WP_Error) {
        return $saved;
    }
    $path = is_array($saved) && is_string($saved['path'] ?? null) ? $saved['path'] : '';
    if ($path === '' || !is_file($path)) {
        return new WP_Error('kit_image_optimize_encode_failed', 'The image editor wrote no file.');
    }
    if ((string) ($saved['mime-type'] ?? $mime) !== $mime) {
        // An editor that fell back to another format would hand back something the copy name lies about.
        wp_delete_file($path);
        return new WP_Error('kit_image_optimize_wrong_format', sprintf('The image editor could not write %s.', $mime));
    }
    clearstatcache(true, $path);
    return ['path' => $path, 'bytes' => (int) filesize($path), 'width' => (int) ($saved['width'] ?? 0), 'height' => (int) ($saved['height'] ?? 0)];
}

/** <dir>/<name><infix>.<ext>, unique in its folder. */
function sibling(string $path, string $infix): string
{
    $dir = dirname($path);
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $name = pathinfo($path, PATHINFO_FILENAME) . $infix . ($ext !== '' ? '.' . $ext : '');
    return $dir . '/' . wp_unique_filename($dir, $name);
}

/**
 * Optimise one attachment: rewrite the original (resized and recompressed, with the old file kept
 * as a backup) when that saves bytes, then write WebP/AVIF copies of every size that come out
 * smaller than their source.
 *
 * @param array{quality: int, max_dimension: int, formats: list<string>, compress_original: bool} $o
 * @return array{row: array<string, mixed>, before: array<string, mixed>|null}
 */
function optimize_one(int $id, array $o): array
{
    $plan = plan($id, $o);
    if ($plan['status'] !== 'ready') {
        return ['row' => ['attachment_id' => $id, 'status' => 'skipped', 'reason' => $plan['reason']], 'before' => null];
    }
    if (!current_user_can('edit_post', $id)) {
        return ['row' => ['attachment_id' => $id, 'status' => 'skipped', 'reason' => 'not_allowed'], 'before' => null];
    }
    $path = (string) get_attached_file($id);
    $meta = (array) wp_get_attachment_metadata($id);
    $mime = (string) $plan['mime_type'];
    $original_bytes = (int) $plan['bytes'];
    $notes = [];
    $backup = null;
    $resized = null;
    $dims_before = ['width' => (int) $plan['width'], 'height' => (int) $plan['height'], 'filesize' => $original_bytes];

    if ($plan['resize_to'] !== null || $o['compress_original']) {
        $tmp = sibling($path, '.optimize-tmp');
        $encoded = encode($path, $tmp, $mime, $o['quality'], $plan['resize_to'] !== null ? $o['max_dimension'] : 0);
        if ($encoded instanceof WP_Error) {
            return ['row' => ['attachment_id' => $id, 'status' => 'failed', 'error' => $encoded->get_error_message()], 'before' => null];
        }
        if ($plan['resize_to'] === null && $encoded['bytes'] >= $original_bytes) {
            wp_delete_file($encoded['path']);
            $notes[] = 'original_kept: recompressing saved nothing';
        } else {
            $backup = sibling($path, BACKUP_INFIX);
            if (!copy($path, $backup)) {
                wp_delete_file($encoded['path']);
                return ['row' => ['attachment_id' => $id, 'status' => 'failed', 'error' => 'The original could not be copied to its backup, so it was left alone.'], 'before' => null];
            }
            if (!rename($encoded['path'], $path)) {
                wp_delete_file($encoded['path']);
                wp_delete_file($backup);
                return ['row' => ['attachment_id' => $id, 'status' => 'failed', 'error' => 'The optimised file could not replace the original, so it was left alone.'], 'before' => null];
            }
            clearstatcache(true, $path);
            if ($encoded['width'] > 0 && $encoded['height'] > 0) {
                $meta['width'] = $encoded['width'];
                $meta['height'] = $encoded['height'];
            }
            $meta['filesize'] = (int) filesize($path);
            wp_update_attachment_metadata($id, $meta);
            if ($plan['resize_to'] !== null) {
                $resized = ['from' => [$dims_before['width'], $dims_before['height']], 'to' => [(int) $meta['width'], (int) $meta['height']]];
            }
        }
    }

    $copies = [];
    foreach (files_of($path, $meta) as $file) {
        foreach ($o['formats'] as $format) {
            $made = encode($file, $file . '.' . $format, COPY_FORMATS[$format], $o['quality']);
            if ($made instanceof WP_Error) {
                $notes[] = sprintf('%s copy of %s failed: %s', $format, wp_basename($file), $made->get_error_message());
                continue;
            }
            clearstatcache(true, $file);
            if ($made['bytes'] >= (int) filesize($file)) {
                // A copy no smaller than its source would only cost the visitor bytes.
                wp_delete_file($made['path']);
                $notes[] = sprintf('%s copy of %s dropped: not smaller', $format, wp_basename($file));
                continue;
            }
            $copies[(string) relative($file)][$format] = $made['bytes'];
        }
    }

    clearstatcache(true, $path);
    $record = [
        'version' => 1,
        'time' => time(),
        'quality' => $o['quality'],
        'max_dimension' => $o['max_dimension'],
        'file' => (string) relative($path),
        'backup' => $backup !== null ? relative($backup) : null,
        'before' => $dims_before,
        'optimized_bytes' => (int) filesize($path),
        'copies' => $copies,
    ];
    update_post_meta($id, META_KEY, wp_slash($record));

    $row = [
        'attachment_id' => $id,
        'status' => 'optimized',
        'original_bytes' => $original_bytes,
        'optimized_bytes' => $record['optimized_bytes'],
        'original_replaced' => $backup !== null,
        'backup' => $record['backup'],
        'resized' => $resized,
        'copies' => $copies,
        'notes' => $notes,
    ];
    $before = [
        'type' => STRATEGY_ORIGINAL,
        'attachment_id' => $id,
        'file' => $record['file'],
        'backup' => $record['backup'],
        'before' => $dims_before,
        'copies' => $copies,
    ];
    return ['row' => $row, 'before' => $before];
}

/**
 * The bulk runner. Works through `attachment_ids`, or from `cursor` (default: where the last call
 * stopped), until `limit` images are done or the time budget is spent, and hands back next_cursor.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function run(array $input): array|WP_Error
{
    $confirmed = Runtime\confirm_guard(RUN_ABILITY, $input);
    if ($confirmed instanceof WP_Error) {
        return $confirmed;
    }
    $o = options($input);
    $by_ids = is_array($input['attachment_ids'] ?? null) && $input['attachment_ids'] !== [];
    if ($by_ids) {
        $ids = array_slice(array_values(array_unique(array_map('intval', $input['attachment_ids']))), 0, MAX_BATCH);
        $cursor = null;
    } else {
        $cursor = isset($input['cursor']) ? max(0, (int) $input['cursor']) : max(0, (int) get_option(CURSOR_OPTION, 0));
        $ids = candidate_ids($cursor, $o['limit']);
    }

    $started = microtime(true);
    $rows = [];
    $items = [];
    $last = $cursor;
    $stopped_for_time = false;
    foreach ($ids as $index => $id) {
        if ($index > 0 && microtime(true) - $started > TIME_BUDGET) {
            $stopped_for_time = true;
            break;
        }
        $done = optimize_one($id, $o);
        $rows[] = $done['row'];
        $last = $id;
        if ($done['before'] !== null) {
            $items[] = [
                'input' => ['attachment_id' => $id],
                'before' => $done['before'],
                'result' => ['optimized' => true],
                'item' => ['attachment_id' => $id],
            ];
        }
    }

    $recorded = $items === [] ? ['change_ids' => []] : Runtime\host()->ledger()->record_items(RUN_ABILITY, $items);

    $remaining = null;
    $next = null;
    if (!$by_ids) {
        $remaining = count_candidates((int) $last);
        $next = $remaining > 0 ? (int) $last : null;
        if ($next === null) {
            delete_option(CURSOR_OPTION);
        } else {
            update_option(CURSOR_OPTION, $next, false);
        }
    }

    $summary = ['optimized' => 0, 'skipped' => 0, 'failed' => 0, 'bytes_saved' => 0];
    foreach ($rows as $row) {
        $summary[$row['status']] = ($summary[$row['status']] ?? 0) + 1;
        if ($row['status'] === 'optimized') {
            $summary['bytes_saved'] += max(0, $row['original_bytes'] - $row['optimized_bytes']);
        }
    }
    return [
        'results' => $rows,
        'summary' => $summary,
        'formats' => $o['formats'],
        'unsupported_formats' => $o['unsupported_formats'],
        'quality' => $o['quality'],
        'max_dimension' => $o['max_dimension'],
        'next_cursor' => $next,
        'remaining' => $remaining,
        'done' => !$by_ids && $next === null,
        'stopped_for_time' => $stopped_for_time,
        'change_ids' => $recorded['change_ids'] ?? [],
    ];
}

/**
 * A dry run: plans every scanned image from its metadata, and really encodes a few of them into
 * the temp folder (then deletes the results) to measure what the settings save on this site.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function estimate(array $input): array
{
    $o = options($input);
    $cursor = isset($input['cursor']) ? max(0, (int) $input['cursor']) : 0;
    $scan = isset($input['scan']) ? max(1, min(MAX_ESTIMATE_SCAN, (int) $input['scan'])) : MAX_ESTIMATE_SCAN;
    $sample_size = isset($input['sample']) ? max(0, min(MAX_SAMPLE, (int) $input['sample'])) : DEFAULT_SAMPLE;
    $ids = candidate_ids($cursor, $scan);

    $ready = [];
    $skipped = [];
    $bytes = 0;
    $resizes = 0;
    foreach ($ids as $id) {
        $row = plan($id, $o);
        if ($row['status'] !== 'ready') {
            $skipped[$row['reason']] = ($skipped[$row['reason']] ?? 0) + 1;
            continue;
        }
        $ready[] = $row;
        $bytes += $row['bytes'];
        $resizes += $row['resize_to'] !== null ? 1 : 0;
    }

    $samples = [];
    $sample_before = 0;
    $sample_after = 0;
    $temp = rtrim(str_replace('\\', '/', get_temp_dir()), '/');
    foreach (array_slice($ready, 0, $sample_size) as $row) {
        $path = (string) get_attached_file($row['attachment_id']);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $made = [];
        $result = ['attachment_id' => $row['attachment_id'], 'original_bytes' => $row['bytes']];
        $main = encode($path, $temp . '/kit-image-optimize-' . wp_generate_uuid4() . '.' . $ext, $row['mime_type'], $o['quality'], $row['resize_to'] !== null ? $o['max_dimension'] : 0);
        if ($main instanceof WP_Error) {
            $samples[] = $result + ['error' => $main->get_error_message()];
            continue;
        }
        $made[] = $main['path'];
        $after = $row['resize_to'] === null && $main['bytes'] >= $row['bytes'] ? $row['bytes'] : $main['bytes'];
        $result['optimized_bytes'] = $after;
        foreach ($o['formats'] as $format) {
            $copy = encode($main['path'], $main['path'] . '.' . $format, COPY_FORMATS[$format], $o['quality']);
            if (!$copy instanceof WP_Error) {
                $made[] = $copy['path'];
                $result[$format . '_bytes'] = $copy['bytes'];
            }
        }
        foreach ($made as $file) {
            wp_delete_file($file);
        }
        $sample_before += $row['bytes'];
        $sample_after += $after;
        $samples[] = $result;
    }
    $ratio = $sample_before > 0 ? round(1 - $sample_after / $sample_before, 3) : null;
    $remaining = $ids === [] ? 0 : count_candidates((int) end($ids));

    return [
        'scanned' => count($ids),
        'ready' => count($ready),
        'skipped' => $skipped,
        'would_resize' => $resizes,
        'original_bytes' => $bytes,
        'sample' => $samples,
        'estimated_saving_ratio' => $ratio,
        'estimated_bytes_saved' => $ratio === null ? null : (int) round($bytes * max(0.0, $ratio)),
        'images' => array_slice($ready, 0, MAX_ESTIMATE_LIST),
        'images_truncated' => count($ready) > MAX_ESTIMATE_LIST,
        'next_cursor' => $remaining > 0 && $ids !== [] ? (int) end($ids) : null,
        'settings' => [
            'quality' => $o['quality'],
            'max_dimension' => $o['max_dimension'],
            'formats' => $o['formats'],
            'unsupported_formats' => $o['unsupported_formats'],
            'compress_original' => $o['compress_original'],
        ],
        'note' => 'Changes nothing. The saving is measured on the sampled originals only; WebP/AVIF copies are extra files, served instead of the original where the browser supports them.',
    ];
}

/**
 * Put the kept original back, delete the copies and drop the record.
 *
 * Refused when the attachment now points at another file (renamed or edited since), because the
 * backup belongs to the old one.
 *
 * @param array<string, array<string, int>> $copies relative file => [format => bytes]
 * @param array{width?: int, height?: int, filesize?: int} $dims_before
 * @return array<string, mixed>|WP_Error
 */
function undo(int $id, string $file, ?string $backup, array $copies, array $dims_before): array|WP_Error
{
    $path = get_attached_file($id);
    if (!is_string($path) || relative($path) !== $file) {
        return new WP_Error('kit_image_optimize_file_changed', sprintf('Attachment %d no longer points at %s, so its kept original was not put back.', $id, $file));
    }
    $restored = false;
    if ($backup !== null) {
        $backup_path = absolute($backup);
        if ($backup_path === null || !is_file($backup_path)) {
            return new WP_Error('kit_image_optimize_backup_missing', sprintf('The kept original %s is gone, so attachment %d was left as it is.', $backup, $id));
        }
        if (!rename($backup_path, $path)) {
            return new WP_Error('kit_image_optimize_restore_failed', sprintf('The kept original %s could not be moved back.', $backup));
        }
        $restored = true;
    }
    $removed = 0;
    foreach ($copies as $relative => $formats) {
        $source = absolute((string) $relative);
        foreach (is_array($formats) ? array_keys($formats) : [] as $format) {
            if ($source === null || !isset(COPY_FORMATS[$format]) || !is_file($source . '.' . $format)) {
                continue;
            }
            wp_delete_file($source . '.' . $format);
            $removed += is_file($source . '.' . $format) ? 0 : 1;
        }
    }
    if ($restored) {
        $meta = wp_get_attachment_metadata($id);
        if (is_array($meta)) {
            foreach (['width', 'height', 'filesize'] as $key) {
                if (isset($dims_before[$key])) {
                    $meta[$key] = (int) $dims_before[$key];
                }
            }
            wp_update_attachment_metadata($id, $meta);
        }
    }
    delete_post_meta($id, META_KEY);
    clearstatcache();
    return [
        'attachment_id' => $id,
        'original_restored' => $restored,
        'copies_removed' => $removed,
        'verified' => record_of($id) === null && ($backup === null || !is_file((string) absolute($backup))),
    ];
}

/**
 * @param array<string, mixed> $payload A ledger rollback payload; the snapshot is under `snapshot`.
 * @return array<string, mixed>|WP_Error
 */
function restore_original(array $payload): array|WP_Error
{
    $s = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $id = (int) ($s['attachment_id'] ?? 0);
    if ($id <= 0 || !is_string($s['file'] ?? null)) {
        return new WP_Error('kit_rollback_bad_snapshot', 'The change record does not name an optimised image.');
    }
    return undo(
        $id,
        $s['file'],
        is_string($s['backup'] ?? null) ? $s['backup'] : null,
        is_array($s['copies'] ?? null) ? $s['copies'] : [],
        is_array($s['before'] ?? null) ? $s['before'] : [],
    );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function restore(array $input): array|WP_Error
{
    $confirmed = Runtime\confirm_guard(RESTORE_ABILITY, $input);
    if ($confirmed instanceof WP_Error) {
        return $confirmed;
    }
    $ids = is_array($input['attachment_ids'] ?? null) ? array_values(array_unique(array_map('intval', $input['attachment_ids']))) : [];
    if ($ids === []) {
        return new WP_Error('kit_image_optimize_no_ids', 'Name the attachment_ids to restore.');
    }
    $rows = [];
    foreach (array_slice($ids, 0, MAX_BATCH * 2) as $id) {
        $record = record_of($id);
        if ($record === null) {
            $rows[] = ['attachment_id' => $id, 'status' => 'skipped', 'reason' => 'not_optimized'];
            continue;
        }
        if (!current_user_can('edit_post', $id)) {
            $rows[] = ['attachment_id' => $id, 'status' => 'skipped', 'reason' => 'not_allowed'];
            continue;
        }
        $done = undo(
            $id,
            (string) ($record['file'] ?? ''),
            is_string($record['backup'] ?? null) ? $record['backup'] : null,
            is_array($record['copies'] ?? null) ? $record['copies'] : [],
            is_array($record['before'] ?? null) ? $record['before'] : [],
        );
        $rows[] = $done instanceof WP_Error
            ? ['attachment_id' => $id, 'status' => 'failed', 'error' => $done->get_error_message()]
            : ['status' => 'restored'] + $done;
    }
    return ['results' => $rows];
}

/**
 * Delete the kept original and the copies when WordPress deletes the attachment; core only knows
 * about the files it made.
 */
function forget_attachment(int $id): void
{
    $record = record_of($id);
    if ($record === null) {
        return;
    }
    if (is_string($record['backup'] ?? null) && ($backup = absolute($record['backup'])) !== null && is_file($backup)) {
        wp_delete_file($backup);
    }
    foreach (is_array($record['copies'] ?? null) ? $record['copies'] : [] as $relative => $formats) {
        $source = absolute((string) $relative);
        foreach (is_array($formats) ? array_keys($formats) : [] as $format) {
            if ($source !== null && isset(COPY_FORMATS[$format]) && is_file($source . '.' . $format)) {
                wp_delete_file($source . '.' . $format);
            }
        }
    }
}

function serve_mode(): string
{
    $mode = get_option(SERVE_OPTION, 'off');
    return is_string($mode) && in_array($mode, SERVE_MODES, true) ? $mode : 'off';
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function set_serving(array $input): array|WP_Error
{
    $mode = (string) ($input['mode'] ?? '');
    if (!in_array($mode, SERVE_MODES, true)) {
        return new WP_Error('kit_image_optimize_bad_mode', 'mode must be off or picture.');
    }
    $previous = serve_mode();
    if ($mode === 'off') {
        delete_option(SERVE_OPTION);
    } else {
        update_option(SERVE_OPTION, $mode, true);
    }
    return ['mode' => serve_mode(), 'previous' => $previous, 'how' => serving_description(serve_mode())];
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|WP_Error
 */
function restore_serving(array $payload): array|WP_Error
{
    $s = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $mode = (string) ($s['mode'] ?? '');
    if (!in_array($mode, SERVE_MODES, true)) {
        return new WP_Error('kit_rollback_bad_snapshot', 'The change record does not hold a serving mode.');
    }
    $done = set_serving(['mode' => $mode]);
    return $done instanceof WP_Error ? $done : ['mode' => $done['mode'], 'verified' => $done['mode'] === $mode];
}

function register_undo(Ledger $ledger): void
{
    $ledger->register_strategy(STRATEGY_ORIGINAL, static fn(array $payload): array|WP_Error => restore_original($payload));
    $ledger->register_strategy(STRATEGY_SERVING, static fn(array $payload): array|WP_Error => restore_serving($payload));
    $ledger->capture_for(SERVING_ABILITY, static fn(array $input): array => ['type' => STRATEGY_SERVING, 'mode' => serve_mode()]);
}
