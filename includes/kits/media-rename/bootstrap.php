<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\MediaRename;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * media-rename: rename an attachment's files and slug, and rewrite the links to it in post
 * content, as one undoable change-log group.
 *
 * The posts' undo is the runtime's post-partial strategy, which the host registers; boot adds
 * the attachment's own strategy, so a rename recorded on an earlier request can be undone.
 */
require_once __DIR__ . '/src/rename.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/rename-media-file.php',
    ],
    'boot' => static function (Host $host): void {
        register_undo($host->ledger());
    },
];
