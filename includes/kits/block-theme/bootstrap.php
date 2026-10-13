<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BlockTheme;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/src/functions.php';

/**
 * block-theme: the site editor's objects - global styles, templates and template parts,
 * patterns and block navigation menus - through WordPress's own REST controllers.
 *
 * The undo strategies and before-image captures are registered at boot, not with the
 * abilities: an undo can arrive on a later request that never registers abilities.
 */
return [
    'ability_files' => [
        __DIR__ . '/src/abilities/global-styles.php',
        __DIR__ . '/src/abilities/templates.php',
        __DIR__ . '/src/abilities/patterns.php',
        __DIR__ . '/src/abilities/navigation.php',
    ],
    'boot' => static function (Host $host): void {
        register_ledger($host->ledger());
    },
];
