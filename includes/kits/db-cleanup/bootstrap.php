<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\DbCleanup;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * db-cleanup: removes spam comments, trashed comments and old post revisions, each one
 * recorded with its full row and meta so the change log can put it back.
 *
 * Boot registers the undo, so a cleanup recorded on an earlier request can be undone on this one.
 */
require_once __DIR__ . '/src/cleanup.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/cleanup.php',
    ],
    'boot' => static function (Host $host): void {
        register($host->ledger());
    },
];
