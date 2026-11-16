<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\DbWrite;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * db-write: insert, update and delete rows in a plugin's own table, one primary key at a time,
 * each row recorded with its before-image so the change log can put it back.
 *
 * Shares db-read's table rules (prefix, views, network sites, credential tables), which is why
 * kit.json requires that kit. Boot registers the undo, so a write recorded on an earlier request
 * can be undone on this one.
 */
require_once dirname(__DIR__) . '/db-read/src/sql.php';
require_once dirname(__DIR__) . '/db-read/src/database.php';
require_once __DIR__ . '/src/rows.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/rows.php',
    ],
    'boot' => static function (Host $host): void {
        register($host->ledger());
    },
];
