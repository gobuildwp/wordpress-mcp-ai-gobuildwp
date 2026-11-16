<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\MediaRename;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/rename-media-file', [
    'label' => __('Rename Media File', domain: 'wppilot'),
    'description' => __(
        'Renames a media-library file for SEO or tidiness: the file, the original it was scaled from and every generated size (photo-300x200.jpg becomes new-name-300x200.jpg), the attachment\'s stored path and metadata, and by default its slug. With update_content (default true) it also rewrites links to those files in post content and excerpts, up to 50 posts. The extension never changes. Refused when a file with the new name exists, when the image has image-optimize copies, or when a linking post is one you cannot edit. Post meta that mentions the file (page-builder data) is listed in meta_references but not changed. Every change is one change-log group: undo the whole group. Use dry_run first to see every file and post it would touch. Destructive: renames files on disk, requires explicit confirmation unless dry_run.',
        domain: 'wppilot',
    ),
    'category' => 'media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer', 'minimum' => 1],
            'new_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => MAX_NAME_LENGTH, 'description' => 'New file name without extension, e.g. "red-bicycle". Sanitised like an upload.'],
            'update_slug' => ['type' => 'boolean', 'default' => true, 'description' => 'Also set the attachment page slug from the new name.'],
            'update_content' => ['type' => 'boolean', 'default' => true, 'description' => 'Rewrite links to the files in post content and excerpts.'],
            'dry_run' => ['type' => 'boolean', 'default' => false, 'description' => 'Report what would change and change nothing.'],
            'confirm' => ['type' => 'boolean', 'description' => 'true once the user has approved the rename.'],
        ],
        'required' => ['attachment_id', 'new_name'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input = []): array|WP_Error => rename_media($input),
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('upload_files'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
