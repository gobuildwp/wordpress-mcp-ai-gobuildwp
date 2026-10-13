<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\DbRead;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * db-read: this site's tables, and one checked, read-only SELECT over them.
 *
 * Returned to the kit loader, which boots it and registers its ability files inside
 * wp_abilities_api_init. The kit hooks nothing itself.
 */
require_once __DIR__ . '/src/sql.php';
require_once __DIR__ . '/src/database.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/database-tables.php',
        __DIR__ . '/src/abilities/database-query.php',
    ],
];
