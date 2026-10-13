<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ScheduledAudits;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

if (Runtime\unclaimed('wppilot/routines-delete')) {
    wp_register_ability('wppilot/routines-delete', [
        'label' => __('Delete a Scheduled Routine', domain: 'wppilot'),
        'description' => __(
            'Deletes a routine: its definition, its scheduled runs and its stored reports; a run in progress stops at its next step. Undoable from the change log, which puts back the definition and the reports and reschedules it. To stop it for a while instead, save it with enabled false. Destructive: requires explicit confirmation.',
            domain: 'wppilot',
        ),
        'category' => 'routines',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'maxLength' => 40, 'description' => 'The routine, from wppilot/routines-list.'],
                'confirm' => ['type' => 'boolean', 'description' => 'true once the user has approved deleting this routine and its reports.'],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'deleted' => ['type' => 'boolean'],
                'routine_id' => ['type' => 'string'],
                'reports_removed' => ['type' => 'integer'],
                'still_scheduled' => ['type' => 'boolean'],
                'change_id' => ['type' => ['string', 'null']],
            ],
        ],
        'execute_callback' => static fn(array $input): array|\WP_Error => delete($input),
        'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_options'),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
        ],
    ]);
}
