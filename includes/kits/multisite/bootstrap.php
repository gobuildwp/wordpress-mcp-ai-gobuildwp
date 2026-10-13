<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Multisite;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * multisite: list a network's sites and run an ability on one of them.
 *
 * kit.json cannot say "only on a network": is_multisite() exists on every install and answers
 * false on a single site. The bootstrap says it instead, and the loader reports the kit skipped
 * with this reason, so integration health explains the missing abilities.
 */
if (!is_multisite()) {
    return ['skip' => 'This site is not a multisite network.', 'ability_files' => []];
}

require_once __DIR__ . '/src/Network.php';
require_once __DIR__ . '/src/WpNetwork.php';
require_once __DIR__ . '/src/functions.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/network-list-sites.php',
        __DIR__ . '/src/abilities/network-run-ability.php',
    ],
    'boot' => static function (Host $host): void {
        register_ledger($host->ledger());
    },
];
