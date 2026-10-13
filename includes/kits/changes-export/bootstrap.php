<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ChangesExport;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * changes-export: the change ledger as a file an agent or a report can read.
 *
 * Returned to the kit loader, which boots it and registers its ability files inside
 * wp_abilities_api_init. The kit hooks nothing itself.
 */
return [
    'ability_files' => [__DIR__ . '/src/abilities/export-changes.php'],
];
