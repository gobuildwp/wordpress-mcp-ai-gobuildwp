<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\LaunchCheck;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * launch-check: the things a site is usually launched without fixing. Search engines still
 * discouraged, errors shown to visitors, no HTTPS, no favicon, no analytics, no privacy page,
 * broken links in recent content, and the forms someone should submit once. Read-only.
 */
require_once __DIR__ . '/src/checks.php';
require_once __DIR__ . '/src/gather.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/launch-checklist.php',
    ],
];
