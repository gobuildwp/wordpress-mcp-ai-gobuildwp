<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SeoBasics;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * seo-basics: one post's SEO title, meta description and robots for seven SEO plugins.
 *
 * Returned to the kit loader, which registers the ability files inside wp_abilities_api_init.
 * Only the active plugins' abilities are registered; with none active the kit is skipped and
 * says why. Boot registers the undo for an AIOSEO write on every request, so a change recorded
 * earlier can still be undone; the meta-backed plugins use the runtime's post-partial undo,
 * which the host registers. Each write's before-image capture is attached where its ability
 * registers, and only when this copy registered it.
 */
require_once __DIR__ . '/src/common.php';

$seo_basics_vendors = active_vendors();
if ($seo_basics_vendors === []) {
    return ['skip' => 'none of Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework, Slim SEO or SmartCrawl is active'];
}

$seo_basics_modules = [
    'yoast' => 'yoast.php',
    'rank-math' => 'rank-math.php',
    'aioseo' => 'aioseo.php',
    'seopress' => 'seopress.php',
    'tsf' => 'tsf.php',
    'slim-seo' => 'slim.php',
    'smartcrawl' => 'smartcrawl.php',
];
// Every module is loaded (they only define functions), so the AIOSEO undo is always registered.
foreach ($seo_basics_modules as $seo_basics_module) {
    require_once __DIR__ . '/src/' . $seo_basics_module;
}

$seo_basics_files = [];
foreach ($seo_basics_vendors as $seo_basics_vendor) {
    $seo_basics_files[] = __DIR__ . '/src/abilities/' . $seo_basics_modules[$seo_basics_vendor];
}

return [
    'ability_files' => $seo_basics_files,
    'boot' => static function (Host $host): void {
        Aioseo\register($host->ledger());
    },
];
