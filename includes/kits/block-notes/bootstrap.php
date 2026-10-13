<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BlockNotes;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * block-notes: the review loop over WordPress 7.1's block Notes.
 *
 * The undo strategies are registered on every request, not only when a note is written: an
 * undo arrives later, from the Changes screen or another agent, and must find them.
 */
require_once __DIR__ . '/src/Blocks.php';
require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/WpStore.php';
require_once __DIR__ . '/src/notes.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/list-block-notes.php',
        __DIR__ . '/src/abilities/add-block-note.php',
        __DIR__ . '/src/abilities/reply-block-note.php',
        __DIR__ . '/src/abilities/resolve-block-note.php',
    ],
    'boot' => static function (Host $host): void {
        register_ledger($host->ledger());
    },
];
