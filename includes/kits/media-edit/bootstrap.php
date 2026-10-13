<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\MediaEdit;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/src/editing.php';

/**
 * media-edit: resize, crop, rotate and flip media-library images, as a copy or in place.
 *
 * The undo strategies and the before-image capture are registered at boot, not with the
 * ability, because a rollback can be requested on a later request that never registers
 * abilities (the Changes screen), and the strategy has to be known there too.
 */
return [
    'ability_files' => [__DIR__ . '/src/abilities/edit-image.php'],
    'boot' => static function (Host $host): void {
        register_undo($host->ledger());
    },
];
