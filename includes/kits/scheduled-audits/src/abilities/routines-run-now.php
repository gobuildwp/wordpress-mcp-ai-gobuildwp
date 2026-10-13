<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ScheduledAudits;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

if (Runtime\unclaimed('wppilot/routines-run-now')) {
    wp_register_ability('wppilot/routines-run-now', [
        'label' => __('Run a Routine Now', domain: 'wppilot'),
        'description' => __(
            'Queues one run of a routine on WP-Cron now, outside its schedule; the schedule is unchanged. Returns at once: the run takes one or more cron ticks (a content audit runs as a background job), then emails its recipients and stores its report. Poll wppilot/routines-list until running is null and last_run is newer than queued_at, then read wppilot/routines-report. Refused while a run of the routine is queued or in progress, and within 10 minutes of the last run requested by hand. The run only reads the site. Recorded in the change log; there is nothing to undo.',
            domain: 'wppilot',
        ),
        'category' => 'routines',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'maxLength' => 40, 'description' => 'The routine, from wppilot/routines-list.'],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'queued' => ['type' => 'boolean'],
                'routine_id' => ['type' => 'string'],
                'queued_at' => ['type' => 'object'],
                'audits' => ['type' => 'integer'],
                'next' => ['type' => 'string'],
                'change_id' => ['type' => ['string', 'null']],
            ],
        ],
        'execute_callback' => static fn(array $input): array|\WP_Error => run_now($input),
        'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_options'),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
        ],
    ]);
}
