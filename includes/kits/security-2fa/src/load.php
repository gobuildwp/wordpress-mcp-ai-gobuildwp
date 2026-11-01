<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityTwoFactor;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/login.php';
require_once __DIR__ . '/profile.php';
