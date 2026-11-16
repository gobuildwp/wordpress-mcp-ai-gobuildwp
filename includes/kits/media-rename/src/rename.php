<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\MediaRename;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Ledger;
use WPPilot\Kits\Runtime\PostPartial;

if (!defined('ABSPATH')) {
    exit();
}

const ABILITY = 'gobuildwp/rename-media-file';

/** Undo of the attachment item: files back to their old names, attached file, metadata and slug. */
const STRATEGY = 'media-rename/attachment';

/** image-optimize's record: its kept original and WebP/AVIF copies carry the old name. */
const OPTIMIZE_META_KEY = '_gobuildwp_kit_image_opt';

/** Posts whose content links to the file that one call will rewrite; more is a job for search-replace. */
const MAX_POSTS = 50;

/** Posts the reference search reads before giving up. */
const MAX_SCAN = 200;

/** Meta references listed (not changed). */
const MAX_META_REFERENCES = 20;

const MAX_NAME_LENGTH = 120;

/** The uploads folder, without a trailing slash, or '' when WordPress reports an error. */
function basedir(): string
{
    $uploads = wp_get_upload_dir();
    return empty($uploads['error']) && is_string($uploads['basedir'] ?? null) ? rtrim(str_replace('\\', '/', $uploads['basedir']), '/') : '';
}

/** The uploads URL without its scheme ("example.com/wp-content/uploads"), so http and https links both match. */
function base_url_fragment(): string
{
    $uploads = wp_get_upload_dir();
    $url = is_string($uploads['baseurl'] ?? null) ? $uploads['baseurl'] : '';
    return rtrim((string) preg_replace('#^(https?:)?//#i', '', $url), '/');
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
 * The new stem from what the caller typed: sanitised the way uploads are, with the file's own
 * extension dropped if it was typed. The extension itself never changes.
 */
function new_stem(string $name, string $ext): string|WP_Error
{
    // Refuse folders before sanitising, which would quietly turn "../red" into "red".
    if (str_contains($name, '/') || str_contains($name, '\\')) {
        return new WP_Error('kit_media_rename_bad_name', 'Give the new file name without folders, for example "red-bicycle".');
    }
    $name = sanitize_file_name(trim($name));
    if ($ext !== '' && strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) === strtolower($ext)) {
        $name = (string) pathinfo($name, PATHINFO_FILENAME);
    }
    $name = trim($name, '.-_ ');
    if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
        return new WP_Error('kit_media_rename_bad_name', 'Give the new file name without folders, for example "red-bicycle".');
    }
    if (str_contains($name, '.')) {
        return new WP_Error('kit_media_rename_bad_name', 'Give the new name without an extension; the file keeps its own.');
    }
    if (strlen($name) > MAX_NAME_LENGTH) {
        return new WP_Error('kit_media_rename_bad_name', sprintf('Keep the new name under %d characters.', MAX_NAME_LENGTH));
    }
    return $name;
}

/**
 * Every file WordPress knows for the attachment, by basename: the attached file, the original an
 * upload was scaled from, and each generated size.
 *
 * @param array<string, mixed> $meta
 * @return list<string>
 */
function basenames(string $path, array $meta): array
{
    $names = [wp_basename($path)];
    if (is_string($meta['original_image'] ?? null) && $meta['original_image'] !== '') {
        $names[] = wp_basename($meta['original_image']);
    }
    foreach (is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [] as $size) {
        if (is_array($size) && is_string($size['file'] ?? null) && $size['file'] !== '') {
            $names[] = wp_basename($size['file']);
        }
    }
    return array_values(array_unique($names));
}

/** Escape a LIKE fragment, with wpdb's own escaper when it has one. */
function like(string $text): string
{
    global $wpdb;
    return method_exists($wpdb, 'esc_like') ? $wpdb->esc_like($text) : addcslashes($text, '_%\\');
}

/**
 * Replace each old file URL fragment with its new one, only where the match ends the file name
 * (so photo.jpg does not also rewrite photo.jpg.webp or photo.jpgx).
 *
 * @param array<string, string> $pairs old fragment => new fragment
 * @return array{0: string, 1: int}
 */
function replace_in(string $text, array $pairs): array
{
    if ($text === '' || $pairs === []) {
        return [$text, 0];
    }
    $olds = array_keys($pairs);
    usort($olds, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    $pattern = '#(' . implode('|', array_map(static fn(string $o): string => preg_quote($o, '#'), $olds)) . ')(?![A-Za-z0-9_\-]|\.[A-Za-z0-9])#';
    $count = 0;
    $out = preg_replace_callback($pattern, static fn(array $m): string => $pairs[$m[1]], $text, -1, $count);
    return [is_string($out) ? $out : $text, (int) $count];
}

/**
 * Work out the rename without changing anything.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function plan(array $input): array|WP_Error
{
    $id = (int) ($input['attachment_id'] ?? 0);
    $post = $id > 0 ? get_post($id) : null;
    if (!$post instanceof \WP_Post || $post->post_type !== 'attachment') {
        return new WP_Error('kit_media_rename_not_found', 'No attachment has that id.');
    }
    if (!current_user_can('edit_post', $id)) {
        return new WP_Error('kit_media_rename_forbidden', 'You cannot edit this attachment.');
    }
    $optimised = get_post_meta($id, OPTIMIZE_META_KEY, true);
    if (!empty($optimised)) {
        return new WP_Error('kit_media_rename_optimized', 'This image has optimised WebP/AVIF copies and a kept original under its current name. Restore it with gobuildwp/image-optimize-restore first, rename it, then optimise it again.');
    }
    $path = get_attached_file($id);
    if (!is_string($path) || !is_file($path)) {
        return new WP_Error('kit_media_rename_file_missing', 'The attachment\'s file is not on this server.');
    }
    $file = relative($path);
    if ($file === null) {
        return new WP_Error('kit_media_rename_outside_uploads', 'The attachment\'s file is outside the uploads folder, so it is not renamed.');
    }
    $meta = wp_get_attachment_metadata($id);
    $meta = is_array($meta) ? $meta : [];
    $ext = (string) pathinfo($path, PATHINFO_EXTENSION);
    $original = is_string($meta['original_image'] ?? null) && $meta['original_image'] !== '' ? wp_basename($meta['original_image']) : wp_basename($path);
    $old_stem = (string) pathinfo($original, PATHINFO_FILENAME);
    $stem = new_stem((string) ($input['new_name'] ?? ''), $ext);
    if ($stem instanceof WP_Error) {
        return $stem;
    }
    if ($stem === $old_stem) {
        return new WP_Error('kit_media_rename_same_name', 'The file already has that name.');
    }

    $dir = dirname(str_replace('\\', '/', $path));
    $dir_rel = dirname($file) === '.' ? '' : dirname($file) . '/';
    $files = [];
    foreach (basenames($path, $meta) as $old) {
        $rest = substr($old, strlen($old_stem));
        if (!str_starts_with($old, $old_stem) || ($rest !== '' && !in_array($rest[0], ['-', '.'], true))) {
            return new WP_Error('kit_media_rename_unexpected_names', sprintf('%s does not start with %s like the attachment\'s other files, so the rename was not attempted.', $old, $old_stem));
        }
        $new = $stem . $rest;
        $on_disk = is_file($dir . '/' . $old);
        if ($on_disk && file_exists($dir . '/' . $new)) {
            return new WP_Error('kit_media_rename_exists', sprintf('%s%s already exists. Choose another name.', $dir_rel, $new));
        }
        $files[] = ['from' => $dir_rel . $old, 'to' => $dir_rel . $new, 'on_disk' => $on_disk];
    }

    $pairs = [];
    $base = base_url_fragment();
    foreach ($files as $f) {
        $pairs[$base . '/' . $f['from']] = $base . '/' . $f['to'];
    }

    $slug = (bool) ($input['update_slug'] ?? true) ? sanitize_title($stem) : null;
    if ($slug === '' || $slug === (string) $post->post_name) {
        $slug = null;
    }
    $posts = [];
    $not_editable = [];
    if ((bool) ($input['update_content'] ?? true)) {
        global $wpdb;
        $needle = '%' . like($base . '/' . $dir_rel . $old_stem) . '%';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type <> 'revision' AND (post_content LIKE %s OR post_excerpt LIKE %s) ORDER BY ID ASC LIMIT %d",
            $needle,
            $needle,
            MAX_SCAN + 1,
        ));
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        if (count($ids) > MAX_SCAN) {
            return new WP_Error('kit_media_rename_too_many_posts', sprintf('More than %d posts mention this file. Rename it, with update_content false, then update links with the search-replace abilities.', MAX_SCAN));
        }
        foreach ($ids as $post_id) {
            $p = get_post($post_id);
            if (!$p instanceof \WP_Post) {
                continue;
            }
            [, $in_content] = replace_in((string) $p->post_content, $pairs);
            [, $in_excerpt] = replace_in((string) $p->post_excerpt, $pairs);
            if ($in_content + $in_excerpt === 0) {
                continue;
            }
            if (!current_user_can('edit_post', $post_id)) {
                $not_editable[] = $post_id;
                continue;
            }
            $posts[] = ['post_id' => $post_id, 'title' => (string) $p->post_title, 'post_type' => (string) $p->post_type, 'replacements' => $in_content + $in_excerpt];
        }
        if ($not_editable !== []) {
            return new WP_Error('kit_media_rename_cannot_edit_posts', sprintf('Posts %s link to this file and you cannot edit them, so renaming would break their images.', implode(', ', $not_editable)));
        }
        if (count($posts) > MAX_POSTS) {
            return new WP_Error('kit_media_rename_too_many_posts', sprintf('%d posts link to this file; one call updates at most %d. Rename it with update_content false, then update links with the search-replace abilities.', count($posts), MAX_POSTS));
        }
    }

    return [
        'attachment_id' => $id,
        'file' => $file,
        'new_file' => $dir_rel . $stem . substr(wp_basename($path), strlen($old_stem)),
        'old_stem' => $old_stem,
        'stem' => $stem,
        'files' => $files,
        'pairs' => $pairs,
        'slug' => ['from' => (string) $post->post_name, 'to' => $slug],
        'posts' => $posts,
        'meta_references' => meta_references($base . '/' . $dir_rel . $old_stem),
        'meta' => $meta,
    ];
}

/**
 * Post meta that mentions the file (page builders keep image URLs there). Reported, never changed:
 * builder data is often serialized or JSON with escaped slashes, which search-replace handles.
 *
 * @return list<array{post_id: int, meta_key: string}>
 */
function meta_references(string $fragment): array
{
    global $wpdb;
    $plain = '%' . like($fragment) . '%';
    $escaped = '%' . like(str_replace('/', '\\/', $fragment)) . '%';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key NOT IN ('_wp_attached_file', '_wp_attachment_metadata') AND (meta_value LIKE %s OR meta_value LIKE %s) ORDER BY post_id ASC LIMIT %d",
        $plain,
        $escaped,
        MAX_META_REFERENCES,
    ), ARRAY_A);
    $found = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $found[] = ['post_id' => (int) ($row['post_id'] ?? 0), 'meta_key' => (string) ($row['meta_key'] ?? '')];
    }
    return $found;
}

/**
 * The metadata with every file name moved from $old_stem to $stem.
 *
 * @param array<string, mixed> $meta
 * @return array<string, mixed>
 */
function renamed_meta(array $meta, string $old_stem, string $stem, string $new_file): array
{
    $swap = static fn(string $name): string => str_starts_with($name, $old_stem) ? $stem . substr($name, strlen($old_stem)) : $name;
    if (isset($meta['file'])) {
        $meta['file'] = $new_file;
    }
    if (is_string($meta['original_image'] ?? null) && $meta['original_image'] !== '') {
        $meta['original_image'] = $swap(wp_basename($meta['original_image']));
    }
    foreach (is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [] as $name => $size) {
        if (is_array($size) && is_string($size['file'] ?? null)) {
            $meta['sizes'][$name]['file'] = $swap(wp_basename($size['file']));
        }
    }
    return $meta;
}

/**
 * Rename the files in $moves (relative from => to), undoing the ones already moved if one fails.
 *
 * @param list<array{from: string, to: string}> $moves
 */
function move_files(array $moves): bool|WP_Error
{
    $done = [];
    foreach ($moves as $move) {
        $from = absolute($move['from']);
        $to = absolute($move['to']);
        if ($from === null || $to === null || !is_file($from) || file_exists($to) || !rename($from, $to)) {
            foreach (array_reverse($done) as [$back_to, $back_from]) {
                rename($back_from, $back_to);
            }
            return new WP_Error('kit_media_rename_move_failed', sprintf('%s could not be renamed to %s; every file was left with its old name.', $move['from'], $move['to']));
        }
        $done[] = [$from, $to];
    }
    return true;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function rename_media(array $input): array|WP_Error
{
    $dry_run = (bool) ($input['dry_run'] ?? false);
    if (!$dry_run) {
        $confirmed = Runtime\confirm_guard(ABILITY, $input);
        if ($confirmed instanceof WP_Error) {
            return $confirmed;
        }
    }
    $plan = plan($input);
    if ($plan instanceof WP_Error) {
        return $plan;
    }
    $id = (int) $plan['attachment_id'];
    $report = [
        'attachment_id' => $id,
        'file' => ['from' => $plan['file'], 'to' => $plan['new_file']],
        'files' => array_map(static fn(array $f): array => ['from' => $f['from'], 'to' => $f['to'], 'on_disk' => $f['on_disk']], $plan['files']),
        'slug' => $plan['slug'],
        'posts' => $plan['posts'],
        'meta_references' => $plan['meta_references'],
        'notes' => array_values(array_filter([
            $plan['meta_references'] !== [] ? 'Post meta listed in meta_references (often page-builder data) still names the old file and was not changed; update it with the search-replace abilities.' : '',
            'The attachment\'s GUID keeps the old address, as WordPress intends; nothing serves pages from it.',
            'Pages cached by a caching plugin or CDN may show the old address until the cache is cleared.',
        ])),
    ];
    if ($dry_run) {
        return ['dry_run' => true] + $report;
    }

    $snapshot = [
        'type' => STRATEGY,
        'attachment_id' => $id,
        'attached_file' => $plan['file'],
        'new_attached_file' => $plan['new_file'],
        'files' => array_values(array_map(static fn(array $f): array => ['from' => $f['from'], 'to' => $f['to']], array_filter($plan['files'], static fn(array $f): bool => $f['on_disk']))),
        'metadata' => $plan['meta'],
        'post_name' => $plan['slug']['from'],
        'slug_changed' => $plan['slug']['to'] !== null,
    ];
    $post_snapshots = [];
    foreach ($plan['posts'] as $p) {
        $captured = PostPartial\capture((int) $p['post_id'], ['post_content', 'post_excerpt']);
        if ($captured !== null) {
            $post_snapshots[(int) $p['post_id']] = $captured;
        }
    }
    $bytes = strlen((string) wp_json_encode($snapshot));
    foreach ($post_snapshots as $s) {
        $bytes += strlen((string) wp_json_encode($s));
    }
    $ledger = Runtime\host()->ledger();
    if ($bytes > $ledger->snapshot_budget()) {
        return new WP_Error('kit_media_rename_too_large_to_undo', 'The before-images of this attachment and the posts linking to it are larger than one call may keep, so nothing was renamed. Rename with update_content false and update links with search-replace.');
    }

    $moved = move_files($snapshot['files']);
    if ($moved instanceof WP_Error) {
        return $moved;
    }
    update_attached_file($id, (string) $plan['new_file']);
    wp_update_attachment_metadata($id, renamed_meta($plan['meta'], (string) $plan['old_stem'], (string) $plan['stem'], (string) $plan['new_file']));
    if ($plan['slug']['to'] !== null) {
        wp_update_post(wp_slash(['ID' => $id, 'post_name' => (string) $plan['slug']['to']]));
        $report['slug']['to'] = (string) get_post_field('post_name', $id);
    }

    $items = [[
        'input' => ['attachment_id' => $id, 'new_name' => (string) $plan['stem']],
        'before' => $snapshot,
        'result' => ['file' => $plan['new_file']],
        'item' => ['attachment_id' => $id, 'from' => $plan['file'], 'to' => $plan['new_file']],
    ]];
    foreach ($report['posts'] as $index => $p) {
        $post_id = (int) $p['post_id'];
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post || !isset($post_snapshots[$post_id])) {
            $report['posts'][$index]['status'] = 'skipped';
            continue;
        }
        [$content] = replace_in((string) $post->post_content, $plan['pairs']);
        [$excerpt] = replace_in((string) $post->post_excerpt, $plan['pairs']);
        $updated = wp_update_post(wp_slash(['ID' => $post_id, 'post_content' => $content, 'post_excerpt' => $excerpt]), true);
        if ($updated instanceof WP_Error) {
            $report['posts'][$index]['status'] = 'failed';
            $report['posts'][$index]['error'] = $updated->get_error_message();
            continue;
        }
        $report['posts'][$index]['status'] = 'updated';
        $items[] = [
            'input' => ['attachment_id' => $id, 'post_id' => $post_id],
            'before' => $post_snapshots[$post_id],
            'result' => ['post_id' => $post_id, 'replacements' => $p['replacements']],
            'item' => ['post_id' => $post_id, 'title' => $p['title'], 'post_type' => $p['post_type']],
        ];
    }
    $recorded = $ledger->record_items(ABILITY, $items);

    $after = get_attached_file($id);
    $report['verified'] = is_string($after) && relative($after) === $plan['new_file'] && is_file($after);
    $report['group'] = $recorded['group'] ?? null;
    $report['change_ids'] = $recorded['change_ids'] ?? [];
    $report['undo'] = 'Undo every change in this group together: undoing only the file rename leaves the updated posts pointing at names that no longer exist.';
    return $report;
}

/**
 * Undo the attachment item: move every file back, then restore the attached file, the metadata
 * and the slug. Refused when the attachment has been renamed or re-pointed since.
 *
 * @param array<string, mixed> $payload A ledger rollback payload; the snapshot is under `snapshot`.
 * @return array<string, mixed>|WP_Error
 */
function restore_attachment(array $payload): array|WP_Error
{
    $s = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $id = (int) ($s['attachment_id'] ?? 0);
    $old = (string) ($s['attached_file'] ?? '');
    $new = (string) ($s['new_attached_file'] ?? '');
    if ($id <= 0 || $old === '' || $new === '' || !is_array($s['files'] ?? null) || !is_array($s['metadata'] ?? null)) {
        return new WP_Error('kit_rollback_bad_snapshot', 'The change record does not describe a renamed file.');
    }
    $current = get_attached_file($id);
    if (!is_string($current) || relative($current) !== $new) {
        return new WP_Error('kit_media_rename_changed_since', sprintf('Attachment %d no longer points at %s, so its files were not renamed back.', $id, $new));
    }
    $moves = [];
    foreach ($s['files'] as $f) {
        if (is_array($f) && is_string($f['from'] ?? null) && is_string($f['to'] ?? null)) {
            $moves[] = ['from' => $f['to'], 'to' => $f['from']];
        }
    }
    $moved = move_files($moves);
    if ($moved instanceof WP_Error) {
        return $moved;
    }
    update_attached_file($id, $old);
    wp_update_attachment_metadata($id, $s['metadata']);
    if (($s['slug_changed'] ?? false) === true) {
        wp_update_post(wp_slash(['ID' => $id, 'post_name' => (string) ($s['post_name'] ?? '')]));
    }
    $after = get_attached_file($id);
    $files_back = true;
    foreach ($moves as $move) {
        $files_back = $files_back && is_file((string) absolute($move['to']));
    }
    return [
        'attachment_id' => $id,
        'file' => $old,
        'verified' => is_string($after) && relative($after) === $old && $files_back
            && (($s['slug_changed'] ?? false) !== true || (string) get_post_field('post_name', $id) === (string) ($s['post_name'] ?? '')),
    ];
}

function register_undo(Ledger $ledger): void
{
    $ledger->register_strategy(STRATEGY, static fn(array $payload): array|WP_Error => restore_attachment($payload));
}
