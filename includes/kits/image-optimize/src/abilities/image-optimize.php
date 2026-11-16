<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\ImageOptimize;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

const SETTINGS_PROPERTIES = [
    'quality' => ['type' => 'integer', 'minimum' => MIN_QUALITY, 'maximum' => MAX_QUALITY, 'default' => DEFAULT_QUALITY, 'description' => 'Encoder quality for the rewritten original and the copies.'],
    'max_dimension' => ['type' => 'integer', 'minimum' => 0, 'maximum' => MAX_MAX_DIMENSION, 'default' => DEFAULT_MAX_DIMENSION, 'description' => 'Originals whose longest side is larger are resized down to it; 0 never resizes. Values below 320 are raised to 320.'],
    'formats' => [
        'type' => 'array',
        'items' => ['type' => 'string', 'enum' => ['webp', 'avif']],
        'default' => ['webp'],
        'description' => 'Copies to write next to each size. A format the image editor cannot write is skipped and reported.',
    ],
    'compress_original' => ['type' => 'boolean', 'default' => true, 'description' => 'Recompress the original even when it is not resized. It is replaced only when the result is smaller.'],
];

wp_register_ability('gobuildwp/image-optimize-status', [
    'label' => __('Image Optimisation Status', domain: 'wppilot'),
    'description' => __(
        'Reports the web server (and whether rewrite rules could be trusted on it: OpenLiteSpeed ignores .htaccess by default), which formats the image editor can write (WebP, AVIF), how optimised copies are served, how many JPEG/PNG images there are and how many are optimised, and where the bulk run stopped. Changes nothing.',
        domain: 'wppilot',
    ),
    'category' => 'media',
    'input_schema' => ['type' => 'object', 'default' => [], 'properties' => new \stdClass(), 'additionalProperties' => false],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input = []): array => status(),
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('upload_files'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('gobuildwp/image-optimize-estimate', [
    'label' => __('Estimate Image Optimisation', domain: 'wppilot'),
    'description' => __(
        'Dry run of gobuildwp/image-optimize-run with the same settings. Plans up to scan JPEG/PNG images from their metadata (which would be resized, which are skipped and why, total bytes) and really encodes up to sample of them in the temp folder, deleting the results, to measure the saving on this site. Page through a large library with next_cursor. Changes nothing.',
        domain: 'wppilot',
    ),
    'category' => 'media',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => SETTINGS_PROPERTIES + [
            'cursor' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Start after this attachment id (next_cursor from the previous estimate).'],
            'scan' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MAX_ESTIMATE_SCAN, 'default' => MAX_ESTIMATE_SCAN],
            'sample' => ['type' => 'integer', 'minimum' => 0, 'maximum' => MAX_SAMPLE, 'default' => DEFAULT_SAMPLE, 'description' => 'Images to really encode for the measured saving.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input = []): array => estimate($input),
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('upload_files'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('gobuildwp/image-optimize-run', [
    'label' => __('Optimise Images', domain: 'wppilot'),
    'description' => __(
        'Optimises JPEG/PNG images with the site\'s own image editor (GD or Imagick; nothing leaves the site). For each image: resizes an original larger than max_dimension and recompresses it at quality, replacing the file only when the result is smaller (or was resized) and keeping the old original next to it as <name>.optimize-backup.<ext>; then writes WebP/AVIF copies of every size, keeping only copies smaller than their source. Already optimised images are skipped. Give attachment_ids, or let it work through the library: it does limit images per call (stopping early after about 20 seconds), remembers where it stopped, and returns next_cursor until done is true. Each image is recorded so the change log can put the original back; gobuildwp/image-optimize-restore does the same by id. Run gobuildwp/image-optimize-estimate first. Destructive: replaces originals, requires explicit confirmation.',
        domain: 'wppilot',
    ),
    'category' => 'media',
    'input_schema' => [
        'type' => 'object',
        'properties' => SETTINGS_PROPERTIES + [
            'attachment_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'maxItems' => MAX_BATCH, 'description' => 'Optimise exactly these. Leave out to run through the library.'],
            'cursor' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Start after this attachment id; defaults to where the last run stopped. 0 starts over.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MAX_BATCH, 'default' => DEFAULT_BATCH, 'description' => 'Most images in this call.'],
            'confirm' => ['type' => 'boolean', 'description' => 'true once the user has approved optimising (and replacing) these originals.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input = []): array|WP_Error => run($input),
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('upload_files'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

wp_register_ability('gobuildwp/image-optimize-restore', [
    'label' => __('Restore Optimised Images', domain: 'wppilot'),
    'description' => __(
        'Undoes gobuildwp/image-optimize-run for the given attachment_ids: moves the kept original back over the optimised file, restores its recorded width, height and size, and deletes the WebP/AVIF copies. Refused for an image whose file was renamed or edited since. Running gobuildwp/image-optimize-run again redoes it. Destructive: deletes the optimised files, requires explicit confirmation.',
        domain: 'wppilot',
    ),
    'category' => 'media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'attachment_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'minItems' => 1, 'maxItems' => MAX_BATCH * 2],
            'confirm' => ['type' => 'boolean', 'description' => 'true once the user has approved restoring these originals.'],
        ],
        'required' => ['attachment_ids'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input = []): array|WP_Error {
        $result = restore($input);
        if (!is_array($result) || !is_array($result['results'] ?? null)) {
            return $result;
        }
        // An id that is not an attachment at all is not_found, so a wrong id reads differently
        // from an image that was never optimised.
        foreach ($result['results'] as $i => $row) {
            $id = (int) ($row['attachment_id'] ?? 0);
            if (($row['reason'] ?? '') === 'not_optimized' && get_post_type($id) !== 'attachment') {
                $result['results'][$i] = ['attachment_id' => $id, 'status' => 'not_found', 'reason' => 'not_found'];
            }
        }
        return $result;
    },
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('upload_files'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
    ],
]);

wp_register_ability('gobuildwp/image-optimize-serving', [
    'label' => __('Set How Optimised Images Are Served', domain: 'wppilot'),
    'description' => __(
        'Turns serving of the WebP/AVIF copies on (mode picture) or off. With picture, front-end images of optimised attachments, in post content and wherever wp_get_attachment_image() renders them, are wrapped in <picture> with AVIF/WebP <source> elements; an image is wrapped only when every file in its srcset has a copy. This is markup, so it works on OpenLiteSpeed and nginx, where .htaccess rewrite rules are ignored; the kit never writes server rules. Check the front end afterwards: a theme that styles img as a direct child (figure > img) may need a CSS tweak.',
        domain: 'wppilot',
    ),
    'category' => 'media',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'mode' => ['type' => 'string', 'enum' => SERVE_MODES],
        ],
        'required' => ['mode'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input = []): array|WP_Error => set_serving($input),
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_options'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);
