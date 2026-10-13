<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BlockNotes;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/reply-block-note', [
    'label' => __('Reply to Block Note', domain: 'wppilot'),
    'description' => __(
        'Replies in a block editor Note thread (WordPress 7.1+) as "<name> (AI agent)": say what you changed in answer to a note, or ask a follow-up. note_id is the thread or any reply in it. A reply neither resolves nor reopens the thread; use wppilot/resolve-block-note for that. Undo deletes the reply.',
        domain: 'wppilot',
    ),
    'category' => 'gutenberg',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'note_id' => ['type' => 'integer', 'minimum' => 1],
            'content' => ['type' => 'string', 'minLength' => 1, 'maxLength' => MAX_CONTENT],
        ],
        'required' => ['note_id', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'note_id' => ['type' => 'integer'],
            'thread_id' => ['type' => 'integer'],
            'post_id' => ['type' => 'integer'],
            'thread_status' => ['type' => 'string'],
            'by_agent' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static fn(array $input): mixed => reply_note($input),
    'permission_callback' => static fn(array $input = []): bool => Runtime\can_run() && can_note($input),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);
