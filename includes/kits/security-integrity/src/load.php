<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The scan engine, loaded on demand by a job step or an ability call; see bootstrap.php.
 */
require_once __DIR__ . '/kinds.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/checksums.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/fix.php';
