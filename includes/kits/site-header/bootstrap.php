<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SiteHeader;

use WPPilot\Kits\Runtime\Host;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/src/functions.php';

/**
 * site-header: the site-wide header the caller designs, with the plumbing (templates, display
 * conditions, a header per language, the language switcher, undo) and the checks done here.
 *
 * The undo strategy is registered at boot (an undo can arrive on a request that never registers
 * abilities), and so is the swap to a translated header, which runs on every front-end page, and
 * the in-browser probe, printed only on a page opened with a probe URL by someone who may change
 * the header (src/probe.php).
 */
return [
    'ability_files' => [__DIR__ . '/src/abilities/build-site-header.php'],
    'boot' => static function (Host $host): void {
        register_ledger($host->ledger());
        add_filter('elementor/theme/get_location_templates/template_id', __NAMESPACE__ . '\\translated_template');
        add_action('template_redirect', __NAMESPACE__ . '\\start_probe', 0);
        add_action('wp_footer', __NAMESPACE__ . '\\print_probe', 100);
    },
];
