<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityRecovery;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * security-recovery: what a restore would lose, for the Cloud's recovery plans.
 *
 * Nothing runs at load: the counting code is read only when the ability runs.
 */
return [
    'ability_files' => [
        __DIR__ . '/src/abilities/commerce-activity-since.php',
    ],
];
