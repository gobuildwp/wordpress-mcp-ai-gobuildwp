<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SearchReplace;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/search-replace-apply', [
    'label' => __('Apply Search and Replace', domain: 'wppilot'),
    'description' => __(
        'Writes a plan made by search-replace-preview, after the person has seen its diff and approved it. Only posts in the plan are touched, and only if they are unchanged since the preview; a post edited since is skipped with reason kit_sr_changed_since_preview (preview again to include it). Pass post_ids to apply only part of the plan. Writes at most 100 posts per call: when remaining is above 0, call again with the same plan_id (the cursor), or pass background=true to queue a job and poll search-replace-status with the returned job_id (search-replace-cancel stops it). Every post gets its own undoable change row sharing the plan group, so one post or the whole plan can be undone, and a call stops early rather than write a post it could not undo. caches says which builder caches were cleared and what the person should clear by hand. Destructive: needs confirm=true.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'plan_id' => ['type' => 'string', 'description' => 'From search-replace-preview.'],
            'post_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'maxItems' => MAX_PLAN_POSTS, 'description' => 'Apply only these posts of the plan.'],
            'background' => ['type' => 'boolean', 'default' => false, 'description' => 'Queue the rest of the plan as a background job instead of writing now.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Must be true: the person approved this plan and its diff.'],
        ],
        'required' => ['plan_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'plan_id' => ['type' => 'string'],
            'group' => ['type' => 'string'],
            'applied' => ['type' => 'integer'],
            'posts' => ['type' => 'array', 'items' => ['type' => 'object']],
            'skipped' => ['type' => 'array', 'items' => ['type' => 'object']],
            'remaining' => ['type' => 'integer'],
            'stopped_by' => ['type' => ['string', 'null']],
            'cursor' => ['type' => ['object', 'null']],
            'caches' => ['type' => 'array', 'items' => ['type' => 'object']],
            'job_id' => ['type' => 'string'],
            'poll' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static fn(array $input): array|\WP_Error => apply($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => [
            'public' => true,
            // An MCP client can run this as a task: the call is made with background=true, the task
            // follows the queued job, and the finished task's result is search-replace-status for it.
            // Hosts without MCP Tasks ignore these keys.
            'task_support' => 'optional',
            'task_input' => ['background' => true],
            'task_result_ability' => 'wppilot/search-replace-status',
        ],
        'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);
