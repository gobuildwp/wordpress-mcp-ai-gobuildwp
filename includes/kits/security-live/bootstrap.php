<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityLive;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * security-live: instant checks on the WordPress events that matter (uploads, new
 * administrators, sensitive settings, file editor saves, updates, injected scripts in posts),
 * plus a bounded change watch over code files that looks only at files whose size or time
 * changed. Everything records events; nothing is blocked or changed. High and critical events
 * tell GoBuildWP Cloud right away through the signed heartbeat.
 */
return [
    'ability_files' => [
        __DIR__ . '/src/abilities/security-live-status.php',
        __DIR__ . '/src/abilities/security-live-events.php',
        __DIR__ . '/src/abilities/security-live-run-watch.php',
        __DIR__ . '/src/abilities/security-live-configure.php',
    ],
    'boot' => static function (Host $host): void {
        require_once __DIR__ . '/src/load.php';
        install_hooks();
    },
];
