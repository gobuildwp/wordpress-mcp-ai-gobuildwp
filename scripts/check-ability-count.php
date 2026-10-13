<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Fail the build when the number of free abilities in the source differs from the number the
 * documents are checked against.
 *
 * check-doc-consistency.php holds README, readme.txt and the docs to
 * .github/product-facts.json `free_abilities`, but nothing held that number to the code: a new
 * ability — a kit's, say, which lives in includes/kits/ rather than beside the modules it
 * resembles — made every document one short while every check stayed green. This counts what
 * the website's product:sync and the release verifiers count: distinct literal `wppilot/…` names
 * passed to wp_register_ability() or to the WordPress module's register_core_ability() wrapper,
 * anywhere under includes/. Names built at run time are not counted, deliberately; that is how
 * the standalone kit host keeps its `<id>/kit-*` abilities out of GoBuildWP's total.
 *
 * Usage: php scripts/check-ability-count.php
 */

namespace WPPilot\Scripts\Kits\AbilityCount;

use function WPPilot\Scripts\Kits\files_under;
use function WPPilot\Scripts\Kits\registered_ability_literals;

require_once __DIR__ . '/lib/kit-tools.php';

$root = dirname(__DIR__);
$facts = json_decode((string) file_get_contents($root . '/.github/product-facts.json'), true);
if (!is_array($facts) || !is_int($facts['free_abilities'] ?? null)) {
    fwrite(STDERR, ".github/product-facts.json has no integer free_abilities\n");
    exit(1);
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

$count = count($names);
if ($count !== $facts['free_abilities']) {
    fwrite(STDERR, sprintf(
        "includes/ registers %d distinct literal wppilot/ abilities; .github/product-facts.json free_abilities says %d.\n"
        . "Update free_abilities, then run php scripts/check-doc-consistency.php for the documents that repeat it.\n",
        $count,
        $facts['free_abilities'],
    ));
    exit(1);
}

echo "includes/ registers {$count} free abilities, as product-facts.json says.\n";
