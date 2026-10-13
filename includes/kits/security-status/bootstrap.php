<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SecurityStatus;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * security-status: what Wordfence or Solid Security reports about itself, its latest scan and who
 * it is blocking. Read-only.
 *
 * Returned to the kit loader, which registers the ability files inside wp_abilities_api_init.
 * Skipped when neither vendor is active, with the same test GoBuildWP Pro's security kit is gated
 * on; which of them answers is decided per call (active_providers()), so a site running both gets
 * both.
 */
if (!class_exists('wordfence') && !defined('WORDFENCE_VERSION') && !class_exists('ITSEC_Core')) {
    return ['skip' => 'neither Wordfence nor Solid Security is active'];
}

require_once __DIR__ . '/src/security.php';
require_once __DIR__ . '/src/wordfence.php';
require_once __DIR__ . '/src/solid.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/plugin-status.php',
        __DIR__ . '/src/abilities/scan-findings.php',
        __DIR__ . '/src/abilities/lockouts.php',
    ],
];
