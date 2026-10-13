<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Ability: Disable a sandbox file by renaming it to .disabled.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/disable-file', [
    'label' => __('Disable File', domain: 'wppilot'),
    'description' => __(
        'Disables a file in the sandbox (wp-content/wppilot-sandbox/) by appending ".disabled" to its filename. The file is preserved on disk but no longer loaded by the sandbox loader. Use the same path with ".disabled" removed to re-enable. Only operates on files inside the sandbox directory. Idempotent: disabling an already-disabled file succeeds with disabled=false.',
        domain: 'wppilot',
    ),
    'category' => 'filesystem',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'path' => [
                'type' => 'string',
                'description' => 'Path to the sandbox file to disable. Relative paths are resolved from ABSPATH. Must be inside wp-content/wppilot-sandbox/.',
                'minLength' => 1,
            ],
        ],
        'required' => ['path'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'original_path' => ['type' => 'string', 'description' => 'Absolute path of the original file.'],
            'disabled_path' => ['type' => 'string', 'description' => 'Absolute path after renaming.'],
            'disabled' => ['type' => 'boolean', 'description' => 'Whether the file was actually renamed.'],
        ],
    ],
    'execute_callback' => 'wppilot_disable_file',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'SANDBOX NOTES:',
                '- Only files inside wp-content/wppilot-sandbox/ (the PHP sandbox) can be disabled.',
                '- Disabling appends ".disabled" to the filename so the loader skips it.',
                '- To re-enable, rename the file back (remove the .disabled suffix).',
                '- Safer than deleting: the file stays on disk for later re-use.',
            ]),
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * Disable a sandbox file by renaming it with a .disabled suffix.
 *
 * @param array $input Input with 'path'.
 * @return array|WP_Error
 */
function wppilot_disable_file($input)
{
    $resolved = wppilot_resolve_path((string) $input['path'], must_exist: true);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $sandbox_check = wppilot_validate_sandbox_path($resolved);
    if (is_wp_error($sandbox_check)) {
        return $sandbox_check;
    }

    if (!is_file($resolved)) {
        return new WP_Error('not_a_file', sprintf('Path is not a file: %s', $resolved));
    }

    // Renaming a guard away would expose the sandbox over HTTP just as deleting it would.
    if (wppilot_is_sandbox_guard_file($resolved)) {
        return new WP_Error('sandbox_guard_file', sprintf(
            'Cannot disable %s: it keeps the sandbox directory from being served over HTTP.',
            basename($resolved),
        ));
    }

    // Idempotent: already disabled.
    if (wppilot_is_disabled_file($resolved)) {
        return [
            'original_path' => $resolved,
            'disabled_path' => $resolved,
            'disabled' => false,
        ];
    }

    $disabled_path = $resolved . '.disabled';

    if (file_exists($disabled_path)) {
        return new WP_Error('disabled_file_exists', sprintf('A disabled version already exists: %s', $disabled_path));
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.file_system_operations_readfile,WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem is not usable here: it takes credentials from an interactive admin form, which a REST/MCP request has no way to show. Renaming a file aside is the ability itself, bounded by the path guard and gated to Developer Full Access.
    if (!rename($resolved, $disabled_path)) {
        return new WP_Error('rename_failed', sprintf('Failed to rename file: %s', $resolved));
    }

    return [
        'original_path' => $resolved,
        'disabled_path' => $disabled_path,
        'disabled' => true,
    ];
}
