<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Ability: Write/create files.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/write-file', [
    'label' => __('Write File', domain: 'wppilot'),
    'description' => __(
        'Writes small UTF-8 text content to a file on the server filesystem. PHP files (*.php, and *.phtml, *.phar, *.php7 or any other extension a server may run as PHP) and PHP execution control files can ONLY be written to the sandbox directory (wp-content/wppilot-sandbox/). Other non-PHP files can intentionally go anywhere under ABSPATH. The sandbox is for loading and crash recovery of generated PHP, not security isolation for all filesystem writes. Does not accept base64 or binary uploads; use wppilot/create-upload-link for ZIPs, plugins, themes, media, binary files, or other large uploads. Automatically creates parent directories when needed.',
        domain: 'wppilot',
    ),
    'category' => 'filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'File path. Relative paths are resolved from the WordPress root (ABSPATH).',
                'minLength' => 1,
            ],
            'content' => [
                'type' => 'string',
                'description' => 'Small UTF-8 text content to write to the file. Do not pass base64 or binary data; use wppilot/create-upload-link for binary or large uploads.',
            ],
            'encoding' => [
                'type' => 'string',
                'description' => 'Content encoding. Only UTF-8 text is supported; base64 and binary uploads are rejected. Use wppilot/create-upload-link for those.',
                'enum' => ['utf-8'],
                'default' => 'utf-8',
            ],
            'mode' => [
                'type' => 'string',
                'description' => 'Write mode.',
                'enum' => ['overwrite', 'append'],
                'default' => 'overwrite',
            ],
            'create_directories' => [
                'type' => 'boolean',
                'description' => 'Whether to create parent directories if they do not exist.',
                'default' => true,
            ],
        ],
        'required' => ['path', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'Absolute path to the written file.'],
            'bytes_written' => ['type' => 'integer', 'description' => 'Number of bytes written.'],
            'created' => [
                'type' => 'boolean',
                'description' => 'Whether a new file was created (vs overwriting existing).',
            ],
            'directories_created' => [
                'type' => 'array',
                'description' => 'List of directories that were created.',
                'items' => ['type' => 'string'],
            ],
            'size' => ['type' => 'integer', 'description' => 'Final file size in bytes.'],
        ],
    ],
    'execute_callback' => 'wppilot_write_file',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'PHP FILE SANDBOX:',
                '- PHP files (*.php, and *.phtml, *.phar, *.php7 or any other extension a server may run as PHP) and PHP execution control files can ONLY be written to: wp-content/wppilot-sandbox/',
                '- Use a path like "wp-content/wppilot-sandbox/my-feature.php"',
                '- Other non-PHP files can intentionally be written anywhere under ABSPATH.',
                '- Use this ability only for small UTF-8 text files. Do NOT use base64 here.',
                '- For ZIPs, plugins, themes, media, binary files, or other large uploads, use wppilot/create-upload-link instead.',
                '- The sandbox is not security isolation for all filesystem writes; it is for generated PHP loading and crash recovery.',
                '- Sandbox plugins are loaded by a mu-plugin loader on every request.',
                '',
                'CRASH RECOVERY:',
                '- If a sandbox plugin causes a fatal error, the loader auto-detects the crash',
                '  and enters safe mode on the next request. All sandbox plugins are skipped.',
                '- In safe mode, MCP still works. You can read, fix, or delete the broken file.',
                '- After fixing, delete the file "wp-content/wppilot-sandbox/.crashed"',
                '  to exit safe mode and resume loading sandbox plugins.',
                '- If MCP suddenly stops responding after you wrote a PHP file, wait — the next',
                '  request will auto-recover into safe mode and MCP will be available again.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Decode write content based on encoding.
 *
 * @param string $content  Raw content string.
 * @param string $encoding Encoding type. Only 'utf-8' is supported.
 * @return string|WP_Error Content or WP_Error on unsupported encoding.
 */
function wppilot_decode_write_content(string $content, string $encoding): string|WP_Error
{
    if ($encoding === 'base64') {
        return new WP_Error(
            'base64_not_supported',
            'wppilot/write-file does not accept base64 or binary content. Use wppilot/create-upload-link for ZIPs, plugins, themes, media, binary files, or other large uploads.',
        );
    }

    if ($encoding !== 'utf-8') {
        return new WP_Error('unsupported_encoding', 'wppilot/write-file only accepts UTF-8 text content.');
    }

    return $content;
}

/**
 * Write content to a file.
 *
 * @param array $input Input with 'path', 'content', optional 'encoding', 'mode', 'create_directories'.
 * @return array|WP_Error
 */
function wppilot_write_file($input)
{
    $resolved = wppilot_resolve_path(path: (string) $input['path'], must_exist: false);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $encoding = (string) ($input['encoding'] ?? 'utf-8');
    $mode = (string) ($input['mode'] ?? 'overwrite');
    $create_directories = ($input['create_directories'] ?? true) !== false;

    $symlink_error = wppilot_reject_final_path_symlink($resolved);
    if (is_wp_error($symlink_error)) {
        return $symlink_error;
    }

    $sandbox_error = wppilot_check_php_execution_sandbox($resolved);
    if (is_wp_error($sandbox_error)) {
        return $sandbox_error;
    }

    $content = wppilot_decode_write_content((string) $input['content'], $encoding);
    if (is_wp_error($content)) {
        return $content;
    }

    $created = !file_exists($resolved);
    $parent_dir = dirname($resolved);

    if (!is_dir($parent_dir) && !$create_directories) {
        return new WP_Error('directory_not_found', sprintf('Parent directory does not exist: %s', $parent_dir));
    }

    $directories_created = wppilot_ensure_parent_dir($parent_dir);
    if (is_wp_error($directories_created)) {
        return $directories_created;
    }

    $flags = LOCK_EX;
    if ($mode === 'append') {
        $flags |= FILE_APPEND;
    }

    $bytes_written = file_put_contents($resolved, $content, $flags);
    if ($bytes_written === false) {
        return new WP_Error('write_failed', sprintf('Failed to write file: %s', $resolved));
    }

    if ($created) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.file_system_operations_readfile,WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem is not usable here: it takes credentials from an interactive admin form, which a REST/MCP request has no way to show. Writing the requested path is the ability itself, bounded by the path guard and gated to Developer Full Access.
        chmod(filename: $resolved, permissions: 0644);
    }

    return [
        'path' => $resolved,
        'bytes_written' => $bytes_written,
        'created' => $created,
        'directories_created' => $directories_created,
        'size' => filesize($resolved),
    ];
}
