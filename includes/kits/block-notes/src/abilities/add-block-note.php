<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BlockNotes;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/add-block-note', [
    'label' => __('Add Block Note', domain: 'wppilot'),
    'description' => __(
        'Leaves a block editor Note for a person on one block of a post (WordPress 7.1+), shown in the editor\'s Notes sidebar beside that block with the author shown as "<name> (AI agent)". Name the block by block_path (e.g. "0.2.1": child indexes, from wppilot/list-block-notes with include_blocks=true) or block_anchor (its HTML id), and pass block_name so a stale path is refused instead of noting the wrong block. The note is attached by adding its ID to that block\'s metadata.noteId in post_content, which makes a revision; nothing else in the content changes. The post author gets the site\'s usual new-note email. Use it to ask a person to review or decide something; it does not change the block itself. Undo deletes the note and its anchor, and is refused once someone has replied.',
        domain: 'wppilot',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'minimum' => 1],
            'block_path' => ['type' => 'string', 'pattern' => '^[0-9]+(\\.[0-9]+)*$'],
            'block_anchor' => ['type' => 'string', 'minLength' => 1],
            'block_name' => ['type' => 'string', 'description' => 'The block name you expect there, e.g. core/paragraph; refused when it is another.'],
            'content' => ['type' => 'string', 'minLength' => 1, 'maxLength' => MAX_CONTENT],
        ],
        'required' => ['post_id', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'note_id' => ['type' => 'integer'],
            'post_id' => ['type' => 'integer'],
            'status' => ['type' => 'string'],
            'block' => ['type' => 'object'],
            'by_agent' => ['type' => 'boolean'],
            'warning' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static fn(array $input): mixed => add_note($input),
    'permission_callback' => static fn(array $input = []): bool => Runtime\can_run() && can_note($input),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);
