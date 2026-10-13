<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BuilderQuality;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/src/audit.php';

/**
 * builder-quality: how editable a builder page is, with the fix for each finding.
 * Read-only, so it needs nothing from the host's ledger.
 */
return [
    'ability_files' => [__DIR__ . '/src/abilities/elementor-audit-output.php'],
    'boot' => static function (Host $host): void {
    },
];
