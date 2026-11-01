<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * security-protection: a request firewall, login protection, IP blocks and enumeration/flood
 * blocking for the site itself.
 *
 * Unlike the other kits, the engine is loaded on every request, because the guard has to look at
 * every request. It stays cheap: with everything off and no blocks it reads one autoloaded option
 * and returns. Kits boot on plugins_loaded, so this runs before WordPress parses the request but
 * after other plugins have loaded; a server-level prepend is out of scope.
 */
return [
    'ability_files' => [
        __DIR__ . '/src/abilities/protection-status.php',
        __DIR__ . '/src/abilities/protection-events.php',
        __DIR__ . '/src/abilities/protection-blocks.php',
        __DIR__ . '/src/abilities/protection-configure.php',
        __DIR__ . '/src/abilities/protection-block-ip.php',
        __DIR__ . '/src/abilities/protection-unblock-ip.php',
        __DIR__ . '/src/abilities/protection-virtual-patches.php',
    ],
    'boot' => static function (Host $host): void {
        require_once __DIR__ . '/src/load.php';
        install_hooks();
    },
];
