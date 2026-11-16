<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\MaintenanceMode;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * maintenance-mode: a timed "down for maintenance" page for visitors, with administrators let through.
 *
 * The front-end gate and the admin bar notice are hooked here, on every request the kits boot in,
 * because they act on the request itself. Boot registers the undo for a switch, so a change
 * recorded on an earlier request can still be undone on this one.
 */
require_once __DIR__ . '/src/mode.php';

register_hooks();

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/mode.php',
    ],
    'boot' => static function (Host $host): void {
        register($host->ledger());
    },
];
