<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Redirects;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * redirects: the site's own 301, 302 and 410 rules, exact or by prefix, with hit counts; a 301
 * whenever a published URL changes; and suggestions from the 404 log.
 *
 * The serving and slug-change hooks are added here, on every request the kits boot in, because a
 * redirect has to answer the request that hits it. Boot registers the undo.
 */
require_once dirname(__DIR__) . '/not-found-log/src/log.php';
require_once __DIR__ . '/src/redirects.php';

register_hooks();

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/redirects.php',
    ],
    'boot' => static function (Host $host): void {
        register($host->ledger());
    },
];
