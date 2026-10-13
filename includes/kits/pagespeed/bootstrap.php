<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Pagespeed;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * pagespeed: Google PageSpeed Insights for a page of this site, with no key needed from anyone.
 *
 * Returned to the kit loader, which registers the ability file inside wp_abilities_api_init. No
 * vendor gate: Site Kit is one source among four and is detected per call.
 */
require_once __DIR__ . '/src/normalize.php';
require_once __DIR__ . '/src/check.php';

return [
    'ability_files' => [
        __DIR__ . '/src/abilities/pagespeed-check.php',
    ],
];
