<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\MediaEdit;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/edit-image', [
    'label' => __('Edit Image', domain: 'wppilot'),
    'description' => __(
        'Resizes, crops, rotates or flips a media-library image with WordPress\'s own image editor. operations is a list applied in order: {op:"resize", width?, height?} fits within the box keeping the aspect ratio; {op:"crop", x, y, width, height} in pixels of the image as it is at that step; {op:"rotate", degrees: 90|180|270} clockwise; {op:"flip", direction: "horizontal"|"vertical"}. mode "copy" (default) saves a new attachment and leaves the original untouched; mode "replace" writes a new file for the same attachment the way the Edit Image screen does, keeping the old file and recording it as a backup, so every page using the image changes. Sides are capped at 8000 px and enlarging is refused unless allow_upscale is true. JPEG, PNG, GIF, WebP and AVIF only. Never deletes files. Both modes can be undone from the change log: a copy by removing the new attachment, a replace by pointing the attachment back at its old file (refused if that file is gone). Use copy unless the person asked to change the image everywhere it appears.',
        domain: 'wppilot',
    ),
    'category' => 'media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_id' => ['type' => 'integer', 'minimum' => 1],
            'operations' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => MAX_OPERATIONS,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'op' => ['type' => 'string', 'enum' => ['resize', 'crop', 'rotate', 'flip']],
                        'width' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MAX_DIMENSION],
                        'height' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MAX_DIMENSION],
                        'x' => ['type' => 'integer', 'minimum' => 0],
                        'y' => ['type' => 'integer', 'minimum' => 0],
                        'degrees' => ['type' => 'integer', 'enum' => [90, 180, 270], 'description' => 'Clockwise.'],
                        'direction' => ['type' => 'string', 'enum' => ['horizontal', 'vertical'], 'description' => 'horizontal mirrors left to right; vertical turns it upside down.'],
                    ],
                    'required' => ['op'],
                    'additionalProperties' => false,
                ],
            ],
            'mode' => ['type' => 'string', 'enum' => ['copy', 'replace'], 'default' => 'copy'],
            'allow_upscale' => ['type' => 'boolean', 'default' => false, 'description' => 'Allow a resize to enlarge the image. Only when the person asked for it: enlarging blurs.'],
            'title' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Title for the new attachment (copy mode). Defaults to the original title plus "(edited)".'],
        ],
        'required' => ['attachment_id', 'operations'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'mode' => ['type' => 'string'],
            'attachment_id' => ['type' => 'integer'],
            'source_attachment_id' => ['type' => 'integer'],
            'file' => ['type' => 'string'],
            'previous_file' => ['type' => 'string'],
            'url' => ['type' => 'string'],
            'width' => ['type' => 'integer'],
            'height' => ['type' => 'integer'],
            'mime_type' => ['type' => 'string'],
            'filesize' => ['type' => 'integer'],
            'operations_applied' => ['type' => 'array', 'items' => ['type' => 'object']],
            'alt_copied' => ['type' => 'boolean'],
            'sizes_regenerated' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'execute_callback' => static fn(array $input): array|WP_Error => edit($input),
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('upload_files'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);
