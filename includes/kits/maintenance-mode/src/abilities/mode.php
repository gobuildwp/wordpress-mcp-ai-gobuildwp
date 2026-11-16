<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\MaintenanceMode;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/maintenance-mode-status', [
    'label' => __('Get Maintenance Mode', domain: 'wppilot'),
    'description' => __(
        'Says whether maintenance mode is on: active, the message visitors see, when it switches itself off (until, Unix time, and until_iso), seconds_left and who started it. scheduled is true when a window is set for later, with starts_at (Unix time) and starts_at_iso. expired is true when it was switched on and its time has run out, so visitors already see the site again.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input = []): array => status($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

wp_register_ability('wppilot/set-maintenance-mode', [
    'label' => __('Set Maintenance Mode', domain: 'wppilot'),
    'description' => __(
        'Switches maintenance mode on or off. While it is on, every visitor who is not an administrator gets a 503 "down for maintenance" page with a Retry-After header, so search engines come back later instead of dropping pages; administrators see the site as usual, with a red Maintenance mode notice in the admin bar. wp-admin, wp-login.php, the REST API and admin-ajax are never blocked. enabled=true needs minutes (1 to 10080, default 60): it always switches itself off after that, and calling it again while on restarts the clock. starts_at (Unix time, up to 30 days ahead) schedules the window instead: it opens then and closes minutes later; leave it out to start now. message is plain text up to 1000 characters; leave it out to keep the last one. Switching on needs confirm=true after the user approves; switching off does not. The change can be undone from the change log.',
        domain: 'wppilot',
    ),
    'category' => 'wordpress',
    'input_schema' => [
        'type' => 'object',
        'required' => ['enabled'],
        'properties' => [
            'enabled' => ['type' => 'boolean', 'description' => 'true to turn visitors away, false to serve the site again.'],
            'minutes' => ['type' => 'integer', 'minimum' => MIN_MINUTES, 'maximum' => MAX_MINUTES, 'default' => DEFAULT_MINUTES, 'description' => 'How long it stays on before switching itself off.'],
            'starts_at' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Unix time the window opens, up to 30 days ahead. Leave out to start now.'],
            'message' => ['type' => 'string', 'maxLength' => MAX_MESSAGE_LENGTH, 'description' => 'Plain text shown to visitors. HTML is removed.'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required as true to switch it on, after the user approves.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static fn(array $input): array|WP_Error => set($input),
    'permission_callback' => static fn(): bool => Runtime\can_run(),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);
