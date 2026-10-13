<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\WooBasics;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * woo-basics: WooCommerce catalog, store, order and customer reads, and a basic product editor.
 *
 * Returned to the kit loader, which boots it and registers its ability files inside
 * wp_abilities_api_init. kit.json's `requires` covers WooCommerce being active; the version floor
 * is checked here, since a manifest cannot compare versions. Boot registers the edit undo on every
 * request, so a change recorded on an earlier one can still be undone.
 */
require_once __DIR__ . '/src/support.php';

// WooCommerce defines WC_VERSION as it loads. A class without it is a stand-in (a test harness),
// which still loads the kit; every ability then answers wc_below_minimum_version by itself.
if (defined('WC_VERSION') && !version_ok()) {
    return ['skip' => sprintf('needs WooCommerce %s or newer', MIN_WC_VERSION)];
}

require_once __DIR__ . '/src/products.php';
require_once __DIR__ . '/src/catalog.php';
require_once __DIR__ . '/src/orders.php';
require_once __DIR__ . '/src/edit.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/catalog.php',
        __DIR__ . '/src/abilities/orders.php',
        __DIR__ . '/src/abilities/edit-product.php',
    ],
    'boot' => static function (Host $host): void {
        register_undo($host->ledger());
    },
];
