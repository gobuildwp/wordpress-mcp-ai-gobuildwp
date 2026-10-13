<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Path resolution and containment checks.
 *
 * Every filesystem ability resolves its input through this file. The
 * containment helpers are the boundary that keeps a resolved path inside
 * the configured base directory, so they must stay free of WordPress
 * state and easy to reason about in isolation.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolve a filesystem path, ensuring it stays within the allowed base directory.
 *
 * @param string $path       The path to resolve. Relative paths are prepended with ABSPATH.
 * @param bool   $must_exist Whether the path must already exist.
 * @return string|WP_Error   The resolved absolute path, or WP_Error on failure.
 */
function wppilot_resolve_path($path, $must_exist = false)
{
    // Prepend ABSPATH to relative paths.
    if (!str_starts_with($path, '/') && !str_starts_with($path, '\\')) {
        $path = ABSPATH . $path;
    }

    /**
     * Filter the base directory for filesystem operations.
     * Return false to disable the base directory restriction entirely.
     *
     * @param string $base_dir The base directory. Defaults to ABSPATH.
     */
    /** @var string|false $base_dir */
    $base_dir = apply_filters('wppilot_filesystem_base_dir', ABSPATH);

    // Resolve path that may not exist yet via parent directory.
    $resolved = wppilot_resolve_candidate_path($path);

    // For paths that must exist, override with realpath.
    if ($must_exist) {
        $resolved = realpath($path);
        if ($resolved === false) {
            /* translators: %s: the path that was not found */
            return new WP_Error('path_not_found', sprintf(__('Path does not exist: %s', domain: 'wppilot'), $path));
        }
    }

    // Enforce base directory restriction.
    if ($base_dir !== false) {
        $real_base = realpath($base_dir);
        if ($real_base === false) {
            $real_base = rtrim($base_dir, characters: '/\\');
        }

        if (!wppilot_path_is_within_directory($resolved, $real_base)) {
            return new WP_Error('path_outside_base', sprintf(
                /* translators: 1: the resolved path, 2: the allowed base directory */
                __('Path "%1$s" is outside the allowed base directory "%2$s".', domain: 'wppilot'),
                $resolved,
                $real_base,
            ));
        }
    }

    return $resolved;
}

/**
 * Resolve an absolute candidate path while preserving a non-existing final path.
 */
function wppilot_resolve_candidate_path(string $path): string
{
    $resolved_parent = realpath(dirname($path));
    if ($resolved_parent !== false) {
        return wppilot_normalize_absolute_path($resolved_parent . DIRECTORY_SEPARATOR . basename($path));
    }

    return wppilot_normalize_missing_path($path);
}

/**
 * Normalize a path with missing parents from the nearest existing ancestor.
 */
function wppilot_normalize_missing_path(string $path): string
{
    /** @var list<string> $tail */
    $tail = [basename($path)];
    $cursor = dirname($path);
    $found_existing_ancestor = false;

    while ($cursor !== '' && $cursor !== '.' && $cursor !== dirname($cursor)) {
        $real_cursor = realpath($cursor);
        if ($real_cursor !== false) {
            $tail[] = $real_cursor;
            $found_existing_ancestor = true;
            break;
        }

        $tail[] = basename($cursor);
        $cursor = dirname($cursor);
    }

    if (!$found_existing_ancestor) {
        $real_cursor = realpath($cursor);
        if ($real_cursor !== false) {
            $tail[] = $real_cursor;
        }
        if ($real_cursor === false && str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $tail[] = DIRECTORY_SEPARATOR;
        }
    }

    return wppilot_normalize_absolute_path(implode(DIRECTORY_SEPARATOR, array_reverse($tail)));
}

/**
 * Collapse "." and ".." path segments without requiring the path to exist.
 */
function wppilot_normalize_absolute_path(string $path): string
{
    $path = str_replace(search: '\\', replace: DIRECTORY_SEPARATOR, subject: $path);
    $is_absolute = str_starts_with($path, DIRECTORY_SEPARATOR);
    /** @var list<string> $parts */
    $parts = [];

    foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..') {
            array_pop($parts);
            continue;
        }

        $parts[] = $segment;
    }

    $normalized = implode(DIRECTORY_SEPARATOR, $parts);
    if ($is_absolute) {
        return DIRECTORY_SEPARATOR . $normalized;
    }

    return $normalized === '' ? '.' : $normalized;
}

/**
 * Reject writes through a final path symlink.
 */
function wppilot_reject_final_path_symlink(string $resolved): bool|WP_Error
{
    if (!is_link($resolved)) {
        return true;
    }

    return new WP_Error('symlink_write_rejected', sprintf('Refusing to write through symlink path: %s', $resolved));
}

/**
 * Check whether a path is equal to or contained by a directory boundary.
 */
function wppilot_path_is_within_directory(string $path, string $directory): bool
{
    $normalized_path = wppilot_normalize_boundary_path($path);
    $normalized_directory = wppilot_normalize_boundary_path($directory);

    if ($normalized_path === $normalized_directory) {
        return true;
    }

    return wppilot_path_is_child_of_normalized_directory($normalized_path, $normalized_directory);
}

/**
 * Check whether a path is contained by a directory boundary, excluding the directory itself.
 */
function wppilot_path_is_child_of_directory(string $path, string $directory): bool
{
    return wppilot_path_is_child_of_normalized_directory(
        wppilot_normalize_boundary_path($path),
        wppilot_normalize_boundary_path($directory),
    );
}

/**
 * Normalize path separators for directory-boundary comparisons.
 */
function wppilot_normalize_boundary_path(string $path): string
{
    $normalized = rtrim(string: str_replace(search: '\\', replace: '/', subject: $path), characters: '/');

    return $normalized === '' ? '/' : $normalized;
}

/**
 * Check whether a normalized path is contained by a normalized directory.
 */
function wppilot_path_is_child_of_normalized_directory(string $normalized_path, string $normalized_directory): bool
{
    if ($normalized_directory === '/') {
        return str_starts_with($normalized_path, '/');
    }

    return str_starts_with($normalized_path, $normalized_directory . '/');
}

/**
 * Create a parent directory and return the list of directories that were created.
 *
 * @param string $parent_dir Absolute path to the parent directory.
 * @return array|WP_Error List of directories created, or WP_Error on failure.
 */
function wppilot_ensure_parent_dir(string $parent_dir): array|WP_Error
{
    if (is_dir($parent_dir)) {
        return [];
    }

    // Collect which directories will be created.
    $dir_to_check = $parent_dir;
    $dirs_to_create = [];
    while (!is_dir($dir_to_check)) {
        $dirs_to_create[] = $dir_to_check;
        $dir_to_check = dirname($dir_to_check);
    }
    $directories_created = array_reverse($dirs_to_create);

    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.file_system_operations_readfile,WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem is not usable here: it takes credentials from an interactive admin form, which a REST/MCP request has no way to show. This is the path-guard helper the file abilities are bounded by, so it must inspect the real filesystem.
    if (!mkdir(directory: $parent_dir, permissions: 0755, recursive: true)) {
        return new WP_Error('mkdir_failed', sprintf('Failed to create directory: %s', $parent_dir));
    }

    return $directories_created;
}

/**
 * Check whether a filename ends with the ".disabled" suffix.
 *
 * @param string $path File path to check.
 * @return bool
 */
function wppilot_is_disabled_file($path)
{
    return str_ends_with($path, '.disabled');
}
