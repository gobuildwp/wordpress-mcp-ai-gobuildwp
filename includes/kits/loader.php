<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Boot the kit runtime on GoBuildWP's host and load the free kits.
 *
 * Kits live in includes/kits/<slug>/ rather than beside the modules they resemble, because every
 * verifier and the website's product:sync already scan includes/ for literal ability names: a
 * kit's abilities are counted like any other without teaching anything a new path. The runtime
 * sits in includes/kits/_runtime/, which discover() skips.
 *
 * Pro loads its own kits through load_kits() below, on the same host, once this has run.
 */
function boot(): void
{
    if (!function_exists('wp_register_ability') || Runtime\has_host()) {
        return;
    }
    Runtime\host(new Runtime\Hosts\WPPilotHost());
    Runtime\PostPartial\register(Runtime\host()->ledger());
    Runtime\load_kits(__DIR__);
}

/**
 * Load kits from another directory — Pro's includes/kits — on GoBuildWP's host.
 *
 * @param list<string> $only Slugs to load; empty loads every kit found there.
 * @return array{loaded: list<string>, skipped: array<string, string>}
 */
function load_kits(string $kits_dir, array $only = []): array
{
    boot();
    if (!Runtime\has_host()) {
        return ['loaded' => [], 'skipped' => []];
    }
    return Runtime\load_kits($kits_dir, $only);
}

/**
 * Every kit loaded or skipped on this request, and why, for integration health.
 *
 * @return array{loaded: list<string>, skipped: array<string, string>}
 */
function report(): array
{
    return Runtime\registry();
}

require_once __DIR__ . '/_runtime/runtime.php';
require_once __DIR__ . '/_runtime/hosts/wppilot.php';
// The scheduled-audits kit's GoBuildWP side: it answers whether GoBuildWP Pro 1.10.0 still runs this
// site's routines before the kits boot, so it loads here, on every request, not with the screens.
require_once dirname(__DIR__) . '/admin/routines.php';

// After every plugin file is loaded, so a kit's `requires.classes` sees WooCommerce and friends;
// before init, so kit abilities are queued before the Abilities API collects them.
add_action('plugins_loaded', __NAMESPACE__ . '\\boot', priority: 20);
