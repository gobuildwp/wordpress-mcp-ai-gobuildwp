<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityTwoFactor;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * security-2fa: two-factor login (authenticator-app codes plus one-time backup codes) on
 * wp-login.php, set up from the user's profile, with an optional policy that requires it for chosen
 * roles. Loaded on every request because the login and the profile page need it; with nobody using
 * it, a login costs one user-meta read.
 */
return [
    'ability_files' => [
        __DIR__ . '/src/abilities/two-factor-status.php',
        __DIR__ . '/src/abilities/two-factor-configure.php',
    ],
    'boot' => static function (Host $host): void {
        require_once __DIR__ . '/src/load.php';
        install_hooks();
    },
];
