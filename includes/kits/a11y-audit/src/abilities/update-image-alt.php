<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\A11yAudit;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/update-image-alt', [
    'label' => __('Update Image Alt Text', domain: 'wppilot'),
    'description' => __(
        'Sets the alt text of up to 100 media-library images in one call: items is a list of {attachment_id, alt}. Write alt text only after looking at the image (wppilot/get-media-image): say what it shows and why it is there, usually under 125 characters, without starting "image of". Use alt "" only for purely decorative images. This changes the alt stored on the attachment, which new insertions and most themes use; an image already placed in a post keeps the alt written into its block until that block is edited. Each image gets its own change-log entry, all sharing one group, so one image or the whole batch can be undone. Items that are not images, that you cannot edit, repeated in the call, or already carrying this alt are reported and not written. Returns per-item status and change_id.',
        domain: 'wppilot',
    ),
    'category' => 'accessibility',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'items' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => MAX_ALT_ITEMS,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'attachment_id' => ['type' => 'integer', 'minimum' => 1],
                        'alt' => ['type' => 'string', 'maxLength' => MAX_ALT_LENGTH],
                    ],
                    'required' => ['attachment_id', 'alt'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['items'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'updated' => ['type' => 'integer'],
            'unchanged' => ['type' => 'integer'],
            'skipped' => ['type' => 'integer'],
            'failed' => ['type' => 'integer'],
            'group' => ['type' => ['string', 'null']],
            'results' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
    ],
    'execute_callback' => static fn(array $input): array|WP_Error => update_alts($input),
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('upload_files'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);
