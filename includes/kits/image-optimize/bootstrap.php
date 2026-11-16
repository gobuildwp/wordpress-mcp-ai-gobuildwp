<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\ImageOptimize;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * image-optimize: recompress and resize JPEG/PNG originals with WordPress's own image editor
 * (GD or Imagick, nothing sent off the site), keep the old original as a backup, and write
 * WebP/AVIF copies that the front end serves through <picture> markup.
 *
 * Boot registers the undo strategies, so a run recorded on an earlier request can be undone on
 * this one, and the serving filters, which do nothing until serving is switched on.
 */
require_once __DIR__ . '/src/optimize.php';
require_once __DIR__ . '/src/serving.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/image-optimize.php',
    ],
    'boot' => static function (Host $host): void {
        register_undo($host->ledger());
        register_serving();
    },
];
