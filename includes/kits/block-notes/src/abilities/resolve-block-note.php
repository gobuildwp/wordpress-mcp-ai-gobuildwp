<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BlockNotes;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/resolve-block-note', [
    'label' => __('Resolve Block Note', domain: 'wppilot'),
    'description' => __(
        'Marks a block editor Note thread resolved (WordPress 7.1+) the way the editor\'s Resolve button does: the thread becomes resolved and a resolution entry by "<name> (AI agent)" joins its history, with the optional content as its comment. Resolve a note only once what it asked for is done, or the person told you to; an already-resolved note is left as it is. An inline text highlight the note marks stays in place. Undo reopens the thread and removes the resolution entry.',
        domain: 'wppilot',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'note_id' => ['type' => 'integer', 'minimum' => 1],
            'content' => ['type' => 'string', 'maxLength' => MAX_CONTENT, 'description' => 'Optional comment shown with the resolution.'],
        ],
        'required' => ['note_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'note_id' => ['type' => 'integer'],
            'post_id' => ['type' => 'integer'],
            'status' => ['type' => 'string'],
            'changed' => ['type' => 'boolean'],
            'resolution_id' => ['type' => 'integer'],
            'previous_status' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static fn(array $input): mixed => resolve_note($input),
    'permission_callback' => static fn(array $input = []): bool => Runtime\can_run() && can_note($input),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);
