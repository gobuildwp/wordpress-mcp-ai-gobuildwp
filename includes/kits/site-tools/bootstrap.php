<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteTools;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * site-tools: WP-Cron, Site Health, transients and the options table.
 *
 * Returned to the kit loader, which boots it and registers its ability files inside
 * wp_abilities_api_init. Boot registers the undo for a deleted cron event, so a change recorded
 * on an earlier request can still be undone on this one.
 */
require_once __DIR__ . '/src/cron.php';
require_once __DIR__ . '/src/health.php';
require_once __DIR__ . '/src/transients.php';
require_once __DIR__ . '/src/options.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/cron.php',
        __DIR__ . '/src/abilities/maintenance.php',
    ],
    'boot' => static function (Host $host): void {
        Cron\register($host->ledger());
    },
];
