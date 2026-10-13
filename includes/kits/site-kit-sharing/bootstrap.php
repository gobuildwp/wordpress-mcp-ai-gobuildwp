<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteKitSharing;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * site-kit-sharing: switch on Site Kit by Google's read-only dashboard sharing for Administrators.
 *
 * Returned to the kit loader. The undo is registered on every request, Site Kit active or not, so
 * a change recorded earlier can still be undone after Site Kit was deactivated (it only puts two
 * option rows back). The ability itself registers only while Site Kit is active.
 */
require_once __DIR__ . '/src/sharing.php';

return [
    'ability_files' => available() ? [__DIR__ . '/src/abilities/site-kit-enable-sharing.php'] : [],
    'boot' => static function (Host $host): void {
        register($host->ledger());
    },
];
