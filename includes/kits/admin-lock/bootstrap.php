<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\AdminLock;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * admin-lock: wp-login.php and wp-admin restricted to a list of IP addresses and ranges.
 *
 * The guards are hooked here, on every request the kits boot in, because they act on the request
 * itself. Boot registers the undo for a saved lock.
 */
require_once __DIR__ . '/src/ip.php';
require_once __DIR__ . '/src/lock.php';

register_hooks();

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/lock.php',
    ],
    'boot' => static function (Host $host): void {
        register($host->ledger());
    },
];
