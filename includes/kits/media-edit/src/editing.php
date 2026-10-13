<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\MediaEdit;

use WP_Error;
use WPPilot\Kits\Runtime\Ledger;
use WPPilot\Kits\Runtime\PostPartial;

if (!defined('ABSPATH')) {
    exit();
}

const ABILITY = 'wppilot/edit-image';

/** Longest side, in pixels, any step may produce. */
const MAX_DIMENSION = 8000;

/**
 * Largest source the editor is asked to open, in pixels. GD holds about five bytes a pixel, so
 * this is roughly the 256 MB WordPress raises the limit to for image work, and a larger source
 * would fail as a fatal out-of-memory error rather than as an answer.
 */
const MAX_SOURCE_PIXELS = 50_000_000;

const MAX_OPERATIONS = 10;

/** Raster types the core editors write. SVG and PDF previews are not images an editor can crop. */
const MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

const TYPE_CREATED = 'media-edit/delete-created-attachment';

const TYPE_REPLACED = 'media-edit/restore-replaced-file';

/** What a replace changes, exactly as core's image editor changes it. */
const REPLACE_META_KEYS = ['_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes'];

/**
 * Check the operations against the image as it will be at each step, before anything is opened.
 *
 * Returns the steps normalised, each with the size it produces, or the first problem. A resize
 * that would not change the size is dropped rather than refused, so "fit within 1200" on an
 * image already 1200 wide is not an error.
 *
 * @param list<mixed> $operations
 * @return array{steps: list<array<string, mixed>>, width: int, height: int}|WP_Error
 */
function plan(array $operations, int $width, int $height, bool $allow_upscale): array|WP_Error
{
    if ($width <= 0 || $height <= 0) {
        return new WP_Error('kit_media_edit_unknown_size', 'The image size could not be read.');
    }
    if ($operations === [] || count($operations) > MAX_OPERATIONS) {
        return new WP_Error('kit_media_edit_operations', sprintf('Give between 1 and %d operations.', MAX_OPERATIONS));
    }
    $steps = [];
    foreach (array_values($operations) as $index => $operation) {
        $where = sprintf('Operation %d', $index + 1);
        if (!is_array($operation)) {
            return new WP_Error('kit_media_edit_operation', "{$where} is not an object.");
        }
        $op = (string) ($operation['op'] ?? '');
        switch ($op) {
            case 'resize':
                $want_w = (int) ($operation['width'] ?? 0);
                $want_h = (int) ($operation['height'] ?? 0);
                if ($want_w <= 0 && $want_h <= 0) {
                    return new WP_Error('kit_media_edit_resize', "{$where}: resize needs a width, a height, or both.");
                }
                if ($want_w > MAX_DIMENSION || $want_h > MAX_DIMENSION) {
                    return new WP_Error('kit_media_edit_too_large', sprintf('%s: images are limited to %d px a side.', $where, MAX_DIMENSION));
                }
                // Both given means "fit within the box": the aspect ratio is always kept.
                $scale = $want_w > 0 && $want_h > 0
                    ? min($want_w / $width, $want_h / $height)
                    : ($want_w > 0 ? $want_w / $width : $want_h / $height);
                $new_w = max(1, (int) round($width * $scale));
                $new_h = max(1, (int) round($height * $scale));
                if ($new_w === $width && $new_h === $height) {
                    continue 2;
                }
                if ($scale > 1 && !$allow_upscale) {
                    return new WP_Error('kit_media_edit_upscale', sprintf(
                        '%s would enlarge the image from %dx%d to %dx%d, which blurs it. Set allow_upscale only if the person asked for a larger image.',
                        $where,
                        $width,
                        $height,
                        $new_w,
                        $new_h,
                    ));
                }
                if ($new_w > MAX_DIMENSION || $new_h > MAX_DIMENSION) {
                    return new WP_Error('kit_media_edit_too_large', sprintf('%s: images are limited to %d px a side.', $where, MAX_DIMENSION));
                }
                $steps[] = ['op' => 'resize', 'width' => $new_w, 'height' => $new_h, 'upscale' => $scale > 1];
                [$width, $height] = [$new_w, $new_h];
                break;

            case 'crop':
                $x = (int) ($operation['x'] ?? 0);
                $y = (int) ($operation['y'] ?? 0);
                $w = (int) ($operation['width'] ?? 0);
                $h = (int) ($operation['height'] ?? 0);
                if ($x < 0 || $y < 0 || $w < 1 || $h < 1 || $x + $w > $width || $y + $h > $height) {
                    return new WP_Error('kit_media_edit_crop', sprintf(
                        '%s: the crop box %d,%d %dx%d does not fit inside the image, which is %dx%d at this step.',
                        $where,
                        $x,
                        $y,
                        $w,
                        $h,
                        $width,
                        $height,
                    ));
                }
                $steps[] = ['op' => 'crop', 'x' => $x, 'y' => $y, 'width' => $w, 'height' => $h];
                [$width, $height] = [$w, $h];
                break;

            case 'rotate':
                $degrees = (int) ($operation['degrees'] ?? 0);
                if (!in_array($degrees, [90, 180, 270], strict: true)) {
                    return new WP_Error('kit_media_edit_rotate', "{$where}: rotate by 90, 180 or 270 degrees clockwise.");
                }
                $steps[] = ['op' => 'rotate', 'degrees' => $degrees];
                if ($degrees !== 180) {
                    [$width, $height] = [$height, $width];
                }
                break;

            case 'flip':
                $direction = (string) ($operation['direction'] ?? '');
                if (!in_array($direction, ['horizontal', 'vertical'], strict: true)) {
                    return new WP_Error('kit_media_edit_flip', "{$where}: flip direction is horizontal (mirror left to right) or vertical (upside down).");
                }
                $steps[] = ['op' => 'flip', 'direction' => $direction];
                break;

            default:
                return new WP_Error('kit_media_edit_operation', "{$where}: op must be resize, crop, rotate or flip.");
        }
    }

    return ['steps' => $steps, 'width' => $width, 'height' => $height];
}

/**
 * Run the planned steps on an open editor, in order.
 *
 * @param object $editor A WP_Image_Editor.
 * @param list<array<string, mixed>> $steps
 */
function apply(object $editor, array $steps): bool|WP_Error
{
    foreach ($steps as $step) {
        $done = match ($step['op']) {
            'resize' => resize($editor, (int) $step['width'], (int) $step['height'], ($step['upscale'] ?? false) === true),
            'crop' => $editor->crop((int) $step['x'], (int) $step['y'], (int) $step['width'], (int) $step['height']),
            // The editors turn counter-clockwise for a positive angle (imagerotate, and Imagick
            // is given 360 - angle); core's own "rotate right" button sends -90 for the same reason.
            'rotate' => $editor->rotate(-(int) $step['degrees']),
            // flip($horz, $vert) is named for the axis, not the motion: $horz swaps top and bottom,
            // $vert swaps left and right. Core's "flip horizontal" button sends $vert.
            'flip' => $step['direction'] === 'horizontal' ? $editor->flip(false, true) : $editor->flip(true, false),
            default => new WP_Error('kit_media_edit_operation', 'Unknown operation.'),
        };
        if ($done instanceof WP_Error) {
            return $done;
        }
        if ($done !== true) {
            return new WP_Error('kit_media_edit_failed', sprintf('The image editor could not %s the image.', (string) $step['op']));
        }
    }
    return true;
}

/**
 * Resize to an exact size, enlarging only when the plan allowed it.
 *
 * Core computes resize dimensions through image_resize_dimensions(), which refuses to enlarge;
 * its pre-filter is the documented way to supply them. The filter is narrowed to this one call's
 * numbers and removed before returning, so no other resize on the request is affected.
 */
function resize(object $editor, int $width, int $height, bool $upscale): bool|WP_Error
{
    if (!$upscale) {
        return $editor->resize($width, $height, false);
    }
    $exact = static function (mixed $output, mixed $orig_w, mixed $orig_h, mixed $dest_w, mixed $dest_h, mixed $crop) use ($width, $height): mixed {
        return (int) $dest_w === $width && (int) $dest_h === $height && !$crop
            ? [0, 0, 0, 0, $width, $height, (int) $orig_w, (int) $orig_h]
            : $output;
    };
    add_filter('image_resize_dimensions', $exact, 10, 6);
    try {
        return $editor->resize($width, $height, false);
    } finally {
        remove_filter('image_resize_dimensions', $exact, 10);
    }
}

/**
 * Edit an attachment's image.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function edit(array $input): array|WP_Error
{
    $id = (int) ($input['attachment_id'] ?? 0);
    $post = $id > 0 ? get_post($id) : null;
    if (!$post instanceof \WP_Post || $post->post_type !== 'attachment') {
        return new WP_Error('kit_media_edit_not_found', 'No attachment has that ID.', ['status' => 404]);
    }
    if (!current_user_can('edit_post', $id)) {
        return new WP_Error('kit_media_edit_forbidden', 'You cannot edit this attachment.', ['status' => 403]);
    }
    $mime = (string) get_post_mime_type($id);
    if (!in_array($mime, MIME_TYPES, strict: true)) {
        return new WP_Error('kit_media_edit_not_an_image', sprintf('Only JPEG, PNG, GIF, WebP and AVIF images can be edited; this attachment is %s.', $mime !== '' ? $mime : 'of unknown type'));
    }
    if (!wp_image_editor_supports(['mime_type' => $mime])) {
        return new WP_Error('kit_media_edit_unsupported', sprintf('This server has no image editor that can write %s.', $mime));
    }
    $mode = (string) ($input['mode'] ?? 'copy');
    if (!in_array($mode, ['copy', 'replace'], strict: true)) {
        return new WP_Error('kit_media_edit_mode', 'mode is copy or replace.');
    }

    $path = get_attached_file($id);
    if (!is_string($path) || $path === '' || !file_exists($path)) {
        // Offloaded media (S3 and the like) has no local file to open.
        return new WP_Error('kit_media_edit_no_file', 'The image file is not on this server, so it cannot be edited here.');
    }

    $meta = wp_get_attachment_metadata($id);
    $known_w = is_array($meta) ? (int) ($meta['width'] ?? 0) : 0;
    $known_h = is_array($meta) ? (int) ($meta['height'] ?? 0) : 0;
    if ($known_w * $known_h > MAX_SOURCE_PIXELS) {
        return new WP_Error('kit_media_edit_source_too_large', sprintf('The image is %dx%d; editing is limited to %d megapixels.', $known_w, $known_h, (int) (MAX_SOURCE_PIXELS / 1_000_000)));
    }
    // Checked against the recorded size first, so a bad request never opens the file.
    $operations = is_array($input['operations'] ?? null) ? array_values($input['operations']) : [];
    $allow_upscale = ($input['allow_upscale'] ?? false) === true;
    if ($known_w > 0 && $known_h > 0) {
        $early = plan($operations, $known_w, $known_h, $allow_upscale);
        if ($early instanceof WP_Error) {
            return $early;
        }
    }

    $editor = wp_get_image_editor($path);
    if ($editor instanceof WP_Error) {
        return new WP_Error('kit_media_edit_open', 'The image could not be opened: ' . $editor->get_error_message());
    }
    $size = $editor->get_size();
    $width = (int) ($size['width'] ?? 0);
    $height = (int) ($size['height'] ?? 0);
    if ($width * $height > MAX_SOURCE_PIXELS) {
        return new WP_Error('kit_media_edit_source_too_large', sprintf('The image is %dx%d; editing is limited to %d megapixels.', $width, $height, (int) (MAX_SOURCE_PIXELS / 1_000_000)));
    }
    $plan = plan($operations, $width, $height, $allow_upscale);
    if ($plan instanceof WP_Error) {
        return $plan;
    }
    if ($plan['steps'] === []) {
        return new WP_Error('kit_media_edit_nothing', 'These operations leave the image as it is; nothing was saved.');
    }
    $applied = apply($editor, $plan['steps']);
    if ($applied instanceof WP_Error) {
        return $applied;
    }

    return $mode === 'replace'
        ? save_replace($id, $mime, $path, $editor, $plan['steps'])
        : save_copy($post, $mime, $path, $editor, $plan['steps'], (string) ($input['title'] ?? ''));
}

/**
 * Save the edit as a new attachment beside the original, which is left untouched.
 *
 * @param list<array<string, mixed>> $steps
 * @return array<string, mixed>|WP_Error
 */
function save_copy(\WP_Post $source, string $mime, string $path, object $editor, array $steps, string $title): array|WP_Error
{
    $dir = dirname($path);
    $name = pathinfo($path, PATHINFO_FILENAME);
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $filename = wp_unique_filename($dir, $name . '-edited.' . $ext);
    $saved = $editor->save($dir . '/' . $filename, $mime);
    if ($saved instanceof WP_Error || !is_array($saved) || !is_string($saved['path'] ?? null)) {
        return new WP_Error('kit_media_edit_save', 'The edited image could not be saved' . ($saved instanceof WP_Error ? ': ' . $saved->get_error_message() : '.'));
    }
    $new_path = $saved['path'];
    $new_mime = (string) ($saved['mime-type'] ?? $mime);
    $relative = _wp_relative_upload_path($new_path);
    $uploads = wp_get_upload_dir();
    $title = trim(sanitize_text_field($title));

    $attachment_id = wp_insert_attachment(wp_slash([
        'post_mime_type' => $new_mime,
        'post_title' => $title !== '' ? $title : $source->post_title . ' (edited)',
        'post_content' => '',
        'post_excerpt' => '',
        'post_status' => 'inherit',
        'guid' => rtrim((string) ($uploads['baseurl'] ?? ''), '/') . '/' . $relative,
    ]), $new_path, $source->post_parent, true);
    if ($attachment_id instanceof WP_Error || (int) $attachment_id <= 0) {
        // Our own output from a moment ago, not a file anyone has used: leaving it would strand
        // an orphan in uploads/ that no attachment points at.
        wp_delete_file($new_path);
        return new WP_Error('kit_media_edit_insert', 'The edited image was saved but could not be added to the media library' . ($attachment_id instanceof WP_Error ? ': ' . $attachment_id->get_error_message() : '.'));
    }
    $attachment_id = (int) $attachment_id;

    if (!function_exists('wp_generate_attachment_metadata')) {
        // Loaded only in wp-admin; an MCP or REST request is neither.
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $meta = wp_generate_attachment_metadata($attachment_id, $new_path);
    wp_update_attachment_metadata($attachment_id, is_array($meta) ? $meta : []);

    $alt = (string) get_post_meta($source->ID, '_wp_attachment_image_alt', true);
    if ($alt !== '') {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', wp_slash($alt));
    }

    $size = $editor->get_size();
    return [
        'mode' => 'copy',
        'attachment_id' => $attachment_id,
        'source_attachment_id' => $source->ID,
        'file' => $relative,
        'url' => (string) wp_get_attachment_url($attachment_id),
        'width' => (int) ($size['width'] ?? 0),
        'height' => (int) ($size['height'] ?? 0),
        'mime_type' => $new_mime,
        'filesize' => (int) ($saved['filesize'] ?? 0),
        'operations_applied' => $steps,
        'alt_copied' => $alt !== '',
    ];
}

/**
 * Save the edit over the attachment the way core's image editor does.
 *
 * Mirrors wp_save_image() with target "all": a new `-e<time><rand>` file beside the old one,
 * the old full size and every regenerated sub-size recorded in `_wp_attachment_backup_sizes`
 * under `full-orig` / `<size>-orig` the first time and `<name>-<suffix>` after, and the
 * attachment pointed at the new file. The old files stay on disk (this never deletes, and never
 * honours IMAGE_EDIT_OVERWRITE), which is what lets the undo point back at them.
 *
 * @param list<array<string, mixed>> $steps
 * @return array<string, mixed>|WP_Error
 */
function save_replace(int $id, string $mime, string $path, object $editor, array $steps): array|WP_Error
{
    $meta = wp_get_attachment_metadata($id);
    if (!is_array($meta)) {
        return new WP_Error('kit_media_edit_no_metadata', 'The attachment has no image metadata; regenerate it or use mode copy.');
    }
    $backup = get_post_meta($id, '_wp_attachment_backup_sizes', true);
    $backup = is_array($backup) ? $backup : [];
    $meta['sizes'] = is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [];

    $basename = wp_basename($path);
    $dirname = dirname($path);
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $filename = pathinfo($path, PATHINFO_FILENAME);
    $suffix = time() . wp_rand(100, 999);
    while (true) {
        $filename = preg_replace('/-e([0-9]+)$/', '', $filename) . "-e{$suffix}";
        $new_path = "{$dirname}/{$filename}.{$ext}";
        if (!file_exists($new_path)) {
            break;
        }
        ++$suffix;
    }

    $saved = $editor->save($new_path, $mime);
    if ($saved instanceof WP_Error || !is_array($saved) || !is_string($saved['path'] ?? null)) {
        return new WP_Error('kit_media_edit_save', 'The edited image could not be saved' . ($saved instanceof WP_Error ? ': ' . $saved->get_error_message() : '.'));
    }
    if ((string) ($saved['mime-type'] ?? $mime) !== $mime) {
        // The site converts images on save (image_editor_output_format). The attachment's type
        // and every link to it assume the old format, so a replace would break them; a copy
        // becomes a new attachment of the new type instead.
        wp_delete_file($saved['path']);
        return new WP_Error('kit_media_edit_converted', 'This site converts edited images to another format, so the file cannot be replaced in place. Use mode copy.');
    }
    $new_path = $saved['path'];

    $tag = !isset($backup['full-orig'])
        ? 'full-orig'
        : (($backup['full-orig']['file'] ?? '') !== $basename ? "full-{$suffix}" : '');
    if ($tag !== '') {
        $backup[$tag] = [
            'width' => (int) ($meta['width'] ?? 0),
            'height' => (int) ($meta['height'] ?? 0),
            'filesize' => (int) ($meta['filesize'] ?? (int) filesize($path)),
            'file' => $basename,
        ];
    }

    if (!update_attached_file($id, $new_path)) {
        // Nothing points at the new file yet, so it is ours to remove, as core removes it.
        wp_delete_file($new_path);
        return new WP_Error('kit_media_edit_attach', 'The edited image was saved but the attachment could not be pointed at it.');
    }

    $size = $editor->get_size();
    $meta['file'] = _wp_relative_upload_path($new_path);
    $meta['width'] = (int) ($size['width'] ?? 0);
    $meta['height'] = (int) ($size['height'] ?? 0);
    $meta['filesize'] = (int) ($saved['filesize'] ?? 0);

    $subsizes = wp_get_registered_image_subsizes();
    foreach (array_keys($subsizes) as $name) {
        if (!isset($meta['sizes'][$name])) {
            continue;
        }
        $orig = $backup["{$name}-orig"] ?? null;
        $size_tag = $orig === null
            ? "{$name}-orig"
            : (($orig['file'] ?? '') !== ($meta['sizes'][$name]['file'] ?? '') ? "{$name}-{$suffix}" : '');
        if ($size_tag !== '') {
            $backup[$size_tag] = $meta['sizes'][$name];
        }
    }
    $resized = $editor->multi_resize($subsizes);
    $meta['sizes'] = array_merge($meta['sizes'], is_array($resized) ? $resized : []);

    wp_update_attachment_metadata($id, $meta);
    update_post_meta($id, '_wp_attachment_backup_sizes', wp_slash($backup));

    return [
        'mode' => 'replace',
        'attachment_id' => $id,
        'source_attachment_id' => $id,
        'file' => (string) $meta['file'],
        'previous_file' => _wp_relative_upload_path($path),
        'url' => (string) wp_get_attachment_url($id),
        'width' => $meta['width'],
        'height' => $meta['height'],
        'mime_type' => $mime,
        'filesize' => $meta['filesize'],
        'operations_applied' => $steps,
        'sizes_regenerated' => array_keys(is_array($resized) ? $resized : []),
    ];
}

/**
 * The before-image for one call: what its undo will need.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|null
 */
function capture(array $input): ?array
{
    $id = (int) ($input['attachment_id'] ?? 0);
    if (($input['mode'] ?? 'copy') !== 'replace') {
        // A copy changes nothing that exists; the undo only needs the ID the call returns.
        return $id > 0 ? ['type' => TYPE_CREATED, 'source_attachment_id' => $id] : null;
    }
    $snapshot = PostPartial\capture($id, [], REPLACE_META_KEYS);
    if ($snapshot === null) {
        return null;
    }
    $snapshot['type'] = TYPE_REPLACED;
    return $snapshot;
}

/**
 * The stored undo for a copy, built from what the call returned.
 *
 * @param array<string, mixed> $before
 * @return array<string, mixed>
 */
function build_created(array $before, mixed $result): array
{
    $id = is_array($result) ? (int) ($result['attachment_id'] ?? 0) : 0;
    $file = is_array($result) ? (string) ($result['file'] ?? '') : '';
    if ($id <= 0 || $file === '' || !is_array($result) || ($result['mode'] ?? '') !== 'copy') {
        return ['reversible' => false, 'reason' => 'The edit did not report the attachment it created, so there is nothing to remove.'];
    }
    return ['attachment_id' => $id, 'file' => $file, 'source_attachment_id' => (int) ($before['source_attachment_id'] ?? 0)];
}

/**
 * Undo a copy: remove the attachment it created, and its files, then confirm both are gone.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|WP_Error
 */
function restore_created(array $payload): array|WP_Error
{
    $id = (int) ($payload['attachment_id'] ?? 0);
    $file = (string) ($payload['file'] ?? '');
    if ($id <= 0 || $file === '') {
        return new WP_Error('kit_media_edit_undo_payload', 'The change record does not say which attachment was created.');
    }
    $post = get_post($id);
    if (!$post instanceof \WP_Post) {
        // Someone deleted it already: the site is in the state this undo would produce.
        return ['attachment_id' => $id, 'already_deleted' => true, 'verified' => !file_exists(upload_path($file))];
    }
    if ($post->post_type !== 'attachment' || (string) get_post_meta($id, '_wp_attached_file', true) !== $file) {
        // The ID now names something else, or the copy was itself edited in place. Deleting it
        // would remove more than this change made.
        return new WP_Error('kit_media_edit_undo_changed', 'The attachment this edit created has changed since (it points at another file), so undoing would delete more than the edit made. Delete it by hand if that is intended.');
    }
    if (!wp_delete_attachment($id, true)) {
        return new WP_Error('kit_media_edit_undo_failed', 'WordPress refused to delete the attachment the edit created.');
    }
    $gone = !get_post($id) instanceof \WP_Post;
    $file_gone = !file_exists(upload_path($file));
    return ['attachment_id' => $id, 'deleted' => true, 'file_removed' => $file_gone, 'verified' => $gone && $file_gone];
}

/**
 * Undo a replace: point the attachment back at its old file, after checking that file is still
 * there. Restoring metadata that names a missing file would leave a broken image and call it
 * undone.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>|WP_Error
 */
function restore_replaced(array $payload): array|WP_Error
{
    $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $values = $snapshot['meta']['_wp_attached_file'] ?? null;
    $previous = is_array($values) && is_string($values[0] ?? null) ? $values[0] : '';
    if ($previous === '') {
        return new WP_Error('kit_media_edit_undo_payload', 'The change record does not name the file the attachment used before.');
    }
    $absolute = upload_path($previous);
    if (!file_exists($absolute)) {
        return new WP_Error('kit_media_edit_undo_file_missing', sprintf('The original file %s is no longer on the server, so the attachment cannot be pointed back at it.', $previous));
    }
    $restored = PostPartial\restore($snapshot);
    if ($restored instanceof WP_Error) {
        return $restored;
    }
    $exists = file_exists($absolute);
    return array_merge($restored, [
        'file' => $previous,
        'file_exists' => $exists,
        'verified' => ($restored['verified'] ?? false) === true && $exists,
    ]);
}

/** An `_wp_attached_file` value as a path, the way get_attached_file() resolves it. */
function upload_path(string $file): string
{
    if (path_is_absolute($file)) {
        return $file;
    }
    $uploads = wp_get_upload_dir();
    return rtrim((string) ($uploads['basedir'] ?? ''), '/\\') . '/' . ltrim($file, '/');
}

function register_undo(Ledger $ledger): void
{
    $ledger->register_strategy(
        TYPE_CREATED,
        static fn(array $payload): array|WP_Error => restore_created($payload),
        static fn(array $before, mixed $result): array => build_created($before, $result),
    );
    $ledger->register_strategy(TYPE_REPLACED, static fn(array $payload): array|WP_Error => restore_replaced($payload));
    $ledger->capture_for(ABILITY, static fn(array $input): ?array => capture($input));
}
