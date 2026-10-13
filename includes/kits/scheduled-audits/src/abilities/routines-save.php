<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ScheduledAudits;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

if (Runtime\unclaimed('wppilot/routines-save')) {
    wp_register_ability('wppilot/routines-save', [
        'label' => __('Save a Scheduled Routine', domain: 'wppilot'),
        'description' => __(
            'Creates or updates a routine: read-only audits the server runs by itself on a schedule, with no agent connected. audits (1-5) each name an ability — wppilot/audit-accessibility (give urls on this site, front_page and/or top_pages: pages in the site\'s menus, then published pages by menu order; at most 10 pages), wppilot/audit-content (the whole site in the background; input may set checks, post_types, thin_words and the other options wppilot/audit-content takes, e.g. checks ["seo_meta","schema"] for SEO) or wppilot/audit-media-alt (the most recently uploaded images, 10 pages of per_page per run: 500 at the default per_page of 50, at most 1000; a larger library is only partly covered) — and an optional input for that ability, checked against its own input schema. schedule: frequency daily or weekly, day (weekly: monday-sunday) and hour 0-23 in the site timezone. delivery: email_user_ids, administrators by user id from admins in wppilot/routines-list (addresses are never accepted), and report (keep the last 20 runs; default true). run_as: the administrator the audits run as (default you); they pass the same gates and permission checks as your own calls. enabled false pauses the schedule. Pass id to update that routine; fields left out keep their values. Without id a new routine is created (label, audits and schedule required). A routine can only run these read-only audits, so it never changes the site and is never held for approval or a fresh backup. Undoable from the change log.',
            domain: 'wppilot',
        ),
        'category' => 'routines',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{0,39}$', 'description' => 'Update this routine; omit to create one (its id is made from the label).'],
                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                'enabled' => ['type' => 'boolean', 'description' => 'false pauses the schedule; run-now still works.'],
                'audits' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => MAX_AUDITS,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'ability' => ['type' => 'string', 'enum' => array_keys(AUDITS)],
                            'input' => ['type' => 'object', 'description' => 'Input for the ability, validated against its schema. The runner sets url/post_id, mode/post_id/cursor/limit and page/include itself.'],
                            'urls' => ['type' => 'array', 'maxItems' => MAX_URLS, 'items' => ['type' => 'string', 'maxLength' => 2000], 'description' => 'Accessibility only: paths or URLs on this site.'],
                            'front_page' => ['type' => 'boolean', 'description' => 'Accessibility only: include the front page.'],
                            'top_pages' => ['type' => 'integer', 'minimum' => 0, 'maximum' => MAX_URLS, 'description' => 'Accessibility only: this many top pages.'],
                        ],
                        'required' => ['ability'],
                        'additionalProperties' => false,
                    ],
                ],
                'schedule' => [
                    'type' => 'object',
                    'properties' => [
                        'frequency' => ['type' => 'string', 'enum' => ['daily', 'weekly']],
                        'day' => ['type' => 'string', 'enum' => DAYS],
                        'hour' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 23],
                    ],
                    'required' => ['frequency', 'hour'],
                    'additionalProperties' => false,
                ],
                'delivery' => [
                    'type' => 'object',
                    'properties' => [
                        'email_user_ids' => ['type' => 'array', 'maxItems' => MAX_RECIPIENTS, 'items' => ['type' => 'integer', 'minimum' => 1]],
                        'report' => ['type' => 'boolean'],
                    ],
                    'additionalProperties' => false,
                ],
                'run_as' => ['type' => 'integer', 'minimum' => 1, 'description' => 'An administrator\'s user id.'],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'saved' => ['type' => 'boolean'],
                'created' => ['type' => 'boolean'],
                'routine' => ['type' => 'object'],
                'change_id' => ['type' => ['string', 'null']],
            ],
        ],
        'execute_callback' => static fn(array $input): array|\WP_Error => save($input),
        'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_options'),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'instructions' => 'Read wppilot/routines-list first for the admins to pick run_as and email_user_ids from, and for existing routines to update rather than duplicate.',
                'readonly' => false,
                'destructive' => false,
                'idempotent' => false,
            ],
        ],
    ]);
}
