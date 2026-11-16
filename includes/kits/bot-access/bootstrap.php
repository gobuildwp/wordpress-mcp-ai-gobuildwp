<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BotAccess;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * bot-access: which search and AI crawlers robots.txt lets in, what ai.txt says, and which of
 * them the server's access logs show visiting. Read-only and monitor-only: it never blocks a
 * crawler or edits robots.txt.
 */
require_once __DIR__ . '/src/robots.php';
require_once __DIR__ . '/src/report.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/bot-access-report.php',
    ],
];
