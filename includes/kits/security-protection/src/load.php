<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/ip.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/virtual-patches.php';
