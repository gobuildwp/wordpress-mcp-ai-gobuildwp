<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

// No declare(strict_types=1): wp eval-file evals this file, where declare is a fatal error.

/**
 * Measure how many free abilities a fresh install registers on the default safety profile, and
 * fail when .github/product-facts.json `free_abilities_default_profile` says otherwise.
 *
 * check-ability-count.php counts what the source can register (`free_abilities`). The documents
 * also say how many a fresh install actually ends up with, which the source alone cannot answer:
 * the WooCommerce, SEO, form, backup, security and Site Kit modules register only while their
 * plugin is active, and the ability policy unregisters whatever the safety profile refuses. That
 * second number was written down once and never measured again, so this measures it on a real
 * WordPress: it runs inside the site, reads the registry after the policy has run, and counts the
 * free plugin's own literal `wppilot/` names that are still registered.
 *
 * It refuses to measure a site that is not a fresh install on the default profile, because a
 * number taken there is not the number the documents state: any other active plugin (Pro adds
 * abilities and the gated modules add theirs), a profile other than Production Safe, or Read Mode.
 *
 * Usage, on a throwaway WordPress with only GoBuildWP active and no settings changed:
 *   wp eval-file wp-content/plugins/wppilot/scripts/measure-default-abilities.php
 * Exit 0 when the measurement matches the fact, 1 when it differs, 2 when the site is not fresh.
 */

namespace WPPilot\Scripts\Kits\DefaultAbilityCount;

use function WPPilot\Scripts\Kits\files_under;
use function WPPilot\Scripts\Kits\registered_ability_literals;

if (!defined('ABSPATH') || !function_exists('wp_has_ability')) {
    fwrite(STDERR, "Run this inside WordPress: wp eval-file <path>/scripts/measure-default-abilities.php\n");
    exit(2);
}

require_once __DIR__ . '/lib/kit-tools.php';

$root = dirname(__DIR__);

$others = array_values(array_filter(
    (array) get_option('active_plugins', []),
    static fn(mixed $plugin): bool => is_string($plugin) && !str_starts_with($plugin, basename($root) . '/'),
));
$profile = function_exists('wppilot_effective_safety_profile') ? wppilot_effective_safety_profile() : '';
$read_mode = function_exists('wppilot_read_mode_enabled') && wppilot_read_mode_enabled();
$refusals = [];
if ($others !== []) {
    $refusals[] = 'other plugins are active: ' . implode(', ', $others);
}
if ($profile !== 'production') {
    $refusals[] = "the safety profile is '{$profile}', not the default 'production'";
}
if ($read_mode) {
    $refusals[] = 'Read Mode is on';
}
if ($refusals !== []) {
    fwrite(STDERR, "Not a fresh install on the default profile, so the count would not be the documented one:\n  - "
        . implode("\n  - ", $refusals) . "\n");
    exit(2);
}

$names = [];
foreach (files_under($root . '/includes') as $file) {
    if (!str_ends_with($file, '.php')) {
        continue;
    }
    foreach (registered_ability_literals((string) file_get_contents($file), ['wp_register_ability', 'register_core_ability']) as $ability) {
        if (str_starts_with($ability['name'], 'wppilot/')) {
            $names[$ability['name']] = true;
        }
    }
}
ksort($names);

// The first lookup initialises the registry, which runs every registration and then the policy.
$registered = array_values(array_filter(array_keys($names), static fn(string $name): bool => wp_has_ability($name)));
$count = count($registered);

$facts = json_decode((string) @file_get_contents($root . '/.github/product-facts.json'), true);
$expected = is_array($facts) && is_int($facts['free_abilities_default_profile'] ?? null) ? $facts['free_abilities_default_profile'] : null;

echo sprintf(
    "WordPress %s, PHP %s: %d of %d free abilities registered on the default profile.\n",
    get_bloginfo('version'),
    PHP_VERSION,
    $count,
    count($names),
);

if (!is_array($facts)) {
    // A shipped zip carries no .github/; the measurement is still useful on its own.
    echo "No .github/product-facts.json beside the plugin; nothing to compare with.\n";
    exit(0);
}
if ($expected === null) {
    fwrite(STDERR, "product-facts.json has no integer free_abilities_default_profile. Add {$count}, then run php scripts/check-doc-consistency.php.\n");
    exit(1);
}
if ($count !== $expected) {
    fwrite(STDERR, "product-facts.json free_abilities_default_profile says {$expected}. Update it, then run php scripts/check-doc-consistency.php.\n");
    exit(1);
}
echo "Matches product-facts.json free_abilities_default_profile.\n";
