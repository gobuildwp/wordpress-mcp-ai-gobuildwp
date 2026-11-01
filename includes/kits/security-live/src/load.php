<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityLive;

if (!defined('ABSPATH')) {
    exit();
}

// The change watch reuses the integrity scan's rules, checksums and walker, so a file found here
// carries the same rule id, result and fingerprint as the same file found by a scan.
require_once dirname(__DIR__, 2) . '/security-integrity/src/load.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/watch.php';
require_once __DIR__ . '/hooks.php';
require_once __DIR__ . '/logins.php';
