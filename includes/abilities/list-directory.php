<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Ability: List directory contents.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/list-directory', [
    'label' => __('List Directory', domain: 'wppilot'),
    'description' => __(
        'Lists files and directories at a given path. Supports glob pattern filtering, recursive listing with configurable depth, and hidden file inclusion. Results are sorted with directories first, then alphabetically. Output is capped at a configurable limit to prevent oversized responses.',
        domain: 'wppilot',
    ),
    'category' => 'filesystem',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Directory path. Defaults to ABSPATH (WordPress root). Relative paths are resolved from ABSPATH.',
                'default' => '',
            ],
            'pattern' => [
                'type' => 'string',
                'description' => 'Glob pattern to filter entries (e.g. "*.php", "wp-*").',
                'default' => '*',
            ],
            'recursive' => [
                'type' => 'boolean',
                'description' => 'Whether to list contents recursively.',
                'default' => false,
            ],
            'max_depth' => [
                'type' => 'integer',
                'description' => 'Maximum recursion depth (only used when recursive=true).',
                'default' => 3,
                'minimum' => 1,
                'maximum' => 10,
            ],
            'include_hidden' => [
                'type' => 'boolean',
                'description' => 'Whether to include hidden files/directories (those starting with a dot).',
                'default' => false,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of entries to return.',
                'default' => 500,
                'minimum' => 1,
                'maximum' => 5000,
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Absolute path of the listed directory.'],
            'entries' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'path' => ['type' => 'string'],
                        'type' => ['type' => 'string', 'description' => '"file" or "directory".'],
                        'size' => ['type' => 'integer', 'description' => 'Size in bytes (files only).'],
                        'permissions' => ['type' => 'string', 'description' => 'Octal permission string.'],
                        'modified' => ['type' => 'string', 'description' => 'Last modified time (ISO 8601).'],
                    ],
                ],
            ],
            'total' => ['type' => 'integer', 'description' => 'Total matching entries found (before limit).'],
            'truncated' => ['type' => 'boolean', 'description' => 'Whether results were truncated due to limit.'],
        ],
    ],
    'execute_callback' => 'wppilot_list_directory',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        // A read, so the risk class alone would let it run on Production Safe and even Read
        // Only, where it could read wp-config.php: the database password and the auth salts.
        // Those profiles promise no server filesystem access, so it is Developer Full Access only.
        'safety' => ['min_profile' => 'developer', 'audit_reads' => true],
        'annotations' => [
            'instructions' => 'TIP: AI-written PHP plugins live in wp-content/wppilot-sandbox/. Check wp-content/wppilot-sandbox/.crashed to see if safe mode is active.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * List directory contents.
 *
 * @param array $input Input with optional 'path', 'pattern', 'recursive', 'max_depth', 'include_hidden', 'limit'.
 * @return array|WP_Error
 */
function wppilot_list_directory(array $input = [])
{
    $path = (string) (($input['path'] ?? '') !== '' ? $input['path'] : ABSPATH);
    $pattern = (string) ($input['pattern'] ?? '*');
    $recursive = ($input['recursive'] ?? false) === true;
    $max_depth = (int) ($input['max_depth'] ?? 3);
    $include_hidden = ($input['include_hidden'] ?? false) === true;
    $limit = max(1, min(5000, (int) ($input['limit'] ?? 500)));

    $resolved = wppilot_resolve_path($path, must_exist: true);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    if (!is_dir($resolved)) {
        return new WP_Error('not_a_directory', sprintf('Path is not a directory: %s', $resolved));
    }

    if (!is_readable($resolved)) {
        return new WP_Error('not_readable', sprintf('Directory is not readable: %s', $resolved));
    }

    $all_entries = $recursive
        ? wppilot_collect_recursive_entries($resolved, $pattern, $include_hidden, $max_depth)
        : wppilot_collect_flat_entries($resolved, $pattern, $include_hidden);

    if (is_wp_error($all_entries)) {
        return $all_entries;
    }

    // Sort: directories first, then alphabetically by name.
    usort($all_entries, static function (array $a, array $b) {
        $a_type = (string) $a['type'];
        $b_type = (string) $b['type'];
        if ($a_type !== $b_type) {
            return $a_type === 'directory' ? -1 : 1;
        }
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    $total = count($all_entries);
    $truncated = $total > $limit;

    return [
        'path' => $resolved,
        'entries' => array_slice($all_entries, offset: 0, length: $limit),
        'total' => $total,
        'truncated' => $truncated,
    ];
}

/**
 * Collect directory entries recursively.
 *
 * @param string $resolved       Absolute path to list.
 * @param string $pattern        Glob pattern to filter by.
 * @param bool   $include_hidden Whether to include hidden entries.
 * @param int    $max_depth      Maximum recursion depth.
 * @return list<array<array-key, mixed>>
 */
function wppilot_collect_recursive_entries($resolved, $pattern, $include_hidden, $max_depth)
{
    $entries = [];
    $iterator = new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS);
    // CATCH_GET_CHILD: descending into a subdirectory the process cannot open — one without read
    // permission, or a symlink whose target has gone — throws UnexpectedValueException from
    // getChildren(). Without this flag that exception escapes the whole listing, so a single
    // unreadable entry anywhere in the tree returned nothing at all instead of everything else.
    $iterator = new RecursiveIteratorIterator(
        $iterator,
        RecursiveIteratorIterator::SELF_FIRST,
        RecursiveIteratorIterator::CATCH_GET_CHILD,
    );
    $iterator->setMaxDepth($max_depth - 1); // setMaxDepth is 0-indexed.

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }
        $entry = wppilot_build_entry($item, $pattern, $include_hidden);
        if ($entry !== null) {
            $entries[] = $entry;
        }
    }

    return $entries;
}

/**
 * Collect directory entries (non-recursive).
 *
 * @param string $resolved       Absolute path to list.
 * @param string $pattern        Glob pattern to filter by.
 * @param bool   $include_hidden Whether to include hidden entries.
 * @return list<array<array-key, mixed>>|WP_Error
 */
function wppilot_collect_flat_entries($resolved, $pattern, $include_hidden)
{
    $dir_handle = opendir($resolved);
    if ($dir_handle === false) {
        return new WP_Error('open_failed', sprintf('Could not open directory: %s', $resolved));
    }

    $entries = [];
    while (($filename = readdir($dir_handle)) !== false) {
        if ($filename === '.' || $filename === '..') {
            continue;
        }

        $info = new SplFileInfo($resolved . DIRECTORY_SEPARATOR . $filename);
        $entry = wppilot_build_entry($info, $pattern, $include_hidden);
        if ($entry !== null) {
            $entries[] = $entry;
        }
    }

    closedir($dir_handle);
    return $entries;
}

/**
 * Build a directory entry array from an SplFileInfo object.
 *
 * @param SplFileInfo $info           The file info object.
 * @param string      $pattern        Glob pattern to match against.
 * @param bool        $include_hidden Whether to include hidden entries.
 * @return array|null Entry array or null if filtered out.
 */
function wppilot_build_entry($info, $pattern, $include_hidden)
{
    $name = $info->getFilename();

    // Filter hidden files.
    if (!$include_hidden && str_starts_with($name, '.')) {
        return null;
    }

    // Filter by glob pattern.
    if ($pattern !== '*' && !fnmatch($pattern, $name)) {
        return null;
    }

    $pathname = $info->getPathname();
    $is_dir = $info->isDir();
    // Broken symlinks and unreadable entries fail stat-dependent calls (getSize,
    // getPerms, getMTime) — in PHP 8+ those throw RuntimeException. Check once
    // via file_exists(), which follows symlinks and returns false for dangling
    // ones, then gate the stat calls so we still list the entry.
    $stat_ok = $is_dir || file_exists($pathname);

    return [
        'name' => $name,
        'path' => $pathname,
        'type' => $is_dir ? 'directory' : 'file',
        'size' => $stat_ok && !$is_dir ? $info->getSize() : 0,
        'permissions' => $stat_ok ? substr(sprintf('%o', $info->getPerms()), -4) : '0000',
        'modified' => $stat_ok ? gmdate('c', (int) $info->getMTime()) : '',
    ];
}
