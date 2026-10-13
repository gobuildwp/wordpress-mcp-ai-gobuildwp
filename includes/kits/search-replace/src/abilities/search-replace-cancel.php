<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SearchReplace;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/search-replace-cancel', [
    'label' => __('Cancel Search and Replace Job', domain: 'wppilot'),
    'description' => __(
        'Stops a background search-replace job (job_id from search-replace-apply with background=true) that you queued, when the person asks to stop it. A step already running finishes its batch of up to 100 posts first; nothing after it starts. Posts already written stay written and each stays undoable through its own change row; posts not reached stay pending in the plan, so applying the same plan_id again continues from there. A cancelled job cannot be resumed. Only your own jobs can be cancelled. Does not undo anything: to reverse posts already changed, use the change rows of the plan group.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'string', 'minLength' => 1, 'description' => 'From search-replace-apply with background=true.'],
        ],
        'required' => ['job_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'string'],
            'cancelled' => ['type' => 'boolean'],
            'status' => ['type' => 'string'],
            'plan_id' => ['type' => 'string'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback' => static fn(array $input): array|\WP_Error => cancel($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
    ],
]);
