<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Multisite;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/network-run-ability', [
    'label' => __('Run Ability on Network Site', domain: 'wppilot'),
    'description' => __(
        'Runs one other ability on one site of this multisite network, as if called on that site: it switches to the site, runs the ability through the same safety controls a direct call meets there (that site\'s safety profile and agent switch, the ability\'s own permission check for your role on that site, and its confirmation rule), then switches back whatever happens. Pass the ability\'s own arguments in input; a destructive or critical ability still needs confirm: true inside input, because approving this call does not approve the one it carries. The change is recorded in that site\'s change record, and the result lists its change_ids; undo it by running wppilot/rollback-change on the same site through this ability. Needs the manage_network capability. It cannot run itself, and it refuses sites flagged deleted. Get site IDs from wppilot/network-list-sites.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'site_id' => ['type' => 'integer', 'minimum' => 1],
            'ability' => ['type' => 'string', 'pattern' => '^[a-z0-9-]+/[a-z0-9-]+$', 'description' => 'The ability to run there, by its full name.'],
            'input' => ['type' => 'object', 'default' => [], 'additionalProperties' => true, 'description' => 'That ability\'s arguments, including its own confirm when it needs one.'],
        ],
        'required' => ['site_id', 'ability'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'site_id' => ['type' => 'integer'],
            'site_url' => ['type' => 'string'],
            'ability' => ['type' => 'string'],
            'result' => ['type' => ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null']],
            'change_record' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static fn(array $input): mixed => run($input),
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_network'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        // Not destructive itself: the ability it carries brings its own risk class, and the
        // gate applies it on the target site. Marking this one destructive would demand a
        // confirm for every read across the network and still prove nothing about the inner call.
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);
