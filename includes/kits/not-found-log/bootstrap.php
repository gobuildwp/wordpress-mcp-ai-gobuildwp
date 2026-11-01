<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\NotFoundLog;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * not-found-log: a rolled-up, capped log of the paths that returned 404.
 *
 * The recorder is hooked here, on every request the kits boot in, because the 404 has to be
 * caught in the request that hits it.
 */
require_once __DIR__ . '/src/log.php';

register_recorder();

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/log.php',
    ],
];
