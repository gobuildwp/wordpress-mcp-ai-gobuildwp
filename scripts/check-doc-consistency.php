<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Fail the build when a document contradicts .github/product-facts.json.
 *
 * Every number in this repository that appears in more than one place has, at
 * some point, appeared in two versions of itself: the ability table said one
 * thing and the prose beside it said another, and the wrong one was the one
 * people read. This script is the gate for that class of mistake. It does not
 * check prose quality and it does not invent facts — it reads the recorded ones
 * and asserts that the documents still agree with them.
 *
 * Usage: php scripts/check-doc-consistency.php
 */

$root = dirname(__DIR__);
$factsFile = $root . '/.github/product-facts.json';

if (!is_readable($factsFile)) {
    fwrite(STDERR, "Missing .github/product-facts.json\n");
    exit(1);
}

$facts = json_decode((string) file_get_contents($factsFile), true);

if (!is_array($facts)) {
    fwrite(STDERR, "product-facts.json is not valid JSON\n");
    exit(1);
}

/** @var list<string> $failures */
$failures = [];

$fail = static function (string $message) use (&$failures): void {
    $failures[] = $message;
};

$read = static function (string $path): string {
    return is_readable($path) ? (string) file_get_contents($path) : '';
};

$readme = $read($root . '/README.md');
$pluginFile = $read($root . '/wppilot.php');
$pluginReadme = $read($root . '/readme.txt');

// ---------------------------------------------------------------------------
// 1. The plugin header is the release. Everything else follows it.
// ---------------------------------------------------------------------------

$header = static function (string $field) use ($pluginFile): ?string {
    return preg_match('/^ \* ' . preg_quote($field, '/') . ':\s*(.+)$/m', $pluginFile, $m) === 1
        ? trim($m[1])
        : null;
};

foreach (
    [
        'Version' => 'version',
        'Requires at least' => 'requires_wordpress',
        'Requires PHP' => 'requires_php',
    ] as $field => $key
) {
    $actual = $header($field);

    if ($actual === null) {
        $fail("wppilot.php header has no {$field} line");
    } elseif ($actual !== (string) $facts[$key]) {
        $fail("wppilot.php {$field} is {$actual}, product-facts.json says {$facts[$key]}");
    }
}

if (preg_match('/^Stable tag:\s*(.+)$/m', $pluginReadme, $m) === 1) {
    if (trim($m[1]) !== (string) $facts['version']) {
        $fail("readme.txt Stable tag is {$m[1]}, product-facts.json says {$facts['version']}");
    }
} else {
    $fail('readme.txt has no Stable tag line');
}

// The WordPress this release was actually exercised against. It is a claim made
// in four places — readme.txt, the README badge, the requirements list and the
// constant the compatibility block publishes to clients — and a stale one is
// worse than none, because a client reads it as a statement of testing.
$compatibility = $read($root . '/includes/compatibility.php');
$tested = (string) $facts['tested_wordpress'];

if (preg_match('/^Tested up to:\s*(.+)$/m', $pluginReadme, $m) === 1) {
    if (trim($m[1]) !== $tested) {
        $fail("readme.txt Tested up to is {$m[1]}, product-facts.json says {$tested}");
    }
} else {
    $fail('readme.txt has no Tested up to line');
}

if (!str_contains($compatibility, "'WPPILOT_TESTED_WORDPRESS_VERSION', value: '{$tested}'")) {
    $fail("includes/compatibility.php does not define WPPILOT_TESTED_WORDPRESS_VERSION as {$tested}");
}

if (!str_contains($readme, "tested%20up%20to-WordPress%20{$tested}")) {
    $fail("README tested-up-to badge does not say WordPress {$tested}");
}

if (!str_contains($readme, "tested up to WordPress {$tested}")) {
    $fail("README requirements do not state 'tested up to WordPress {$tested}'");
}

// ---------------------------------------------------------------------------
// 2. The free ability table has to add up to the number the prose claims.
// ---------------------------------------------------------------------------

if (preg_match('/## What the free plugin can do(.*?)\n## /s', $readme, $m) === 1) {
    $section = $m[1];
    preg_match_all('/^\| \*\*[^|]+\*\* \| `(\d+)`/m', $section, $rows);
    $sum = array_sum(array_map('intval', $rows[1]));

    if ($sum !== (int) $facts['free_abilities']) {
        $fail("README free ability table sums to {$sum}, product-facts.json says {$facts['free_abilities']}");
    }

    if (!str_contains($section, $facts['free_abilities'] . ' registered abilities')) {
        $fail("README free section does not state '{$facts['free_abilities']} registered abilities'");
    }
} else {
    $fail('README has no "What the free plugin can do" section');
}

// ---------------------------------------------------------------------------
// 3. Elementor is free, and says so in the same numbers everywhere.
// ---------------------------------------------------------------------------

$elementorFree = (int) $facts['free_elementor_abilities'];

if (!str_contains($readme, "**{$elementorFree} free**")) {
    $fail("README builder matrix does not mark Elementor as {$elementorFree} free");
}

if (!str_contains($readme, "Elementor {$facts['elementor_minimum']} or newer")) {
    $fail("README does not state the Elementor {$facts['elementor_minimum']} minimum");
}

if (!str_contains($readme, $facts['elementor_atomic_style_properties'] . ' style')) {
    $fail("README does not state the {$facts['elementor_atomic_style_properties']} atomic style properties");
}

// ---------------------------------------------------------------------------
// 4. A builder's ability count is one number, not one per table.
// ---------------------------------------------------------------------------

foreach ($facts['builder_abilities'] as $builder => $count) {
    $name = preg_quote((string) $builder, '/');

    // The Pro integration table: [Builder](url) `N`
    if (preg_match('/\[' . $name . '\]\([^)]+\) `(\d+)`/', $readme, $m) === 1 && (int) $m[1] !== (int) $count) {
        $fail("README integration table says {$builder} {$m[1]}, product-facts.json says {$count}");
    }

    // The builder matrix: | Builder | N |
    if (preg_match('/^\| \*?\*?' . $name . '\*?\*? \| \*?\*?(\d+)\*?\*? \|/m', $readme, $m) === 1 && (int) $m[1] !== (int) $count) {
        $fail("README builder matrix says {$builder} {$m[1]}, product-facts.json says {$count}");
    }

    // Prose of the shape "Builder (N abilities)" or "Builder (N)".
    if (preg_match('/' . $name . ' \((\d+)(?: abilities)?\)/', $readme, $m) === 1 && (int) $m[1] !== (int) $count) {
        $fail("README prose says {$builder} ({$m[1]}), product-facts.json says {$count}");
    }
}

// ---------------------------------------------------------------------------
// 5. Claims that were true once and are not any more.
// ---------------------------------------------------------------------------

$documents = array_merge(
    [$root . '/README.md', $root . '/CONTRIBUTING.md', $root . '/SECURITY.md', $root . '/readme.txt'],
    glob($root . '/docs/*.md') ?: [],
    glob($root . '/.github/ISSUE_TEMPLATE/*.yml') ?: [],
);

$relative = static function (string $path) use ($root): string {
    return ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
};

foreach ($documents as $document) {
    $body = $read($document);

    if ($body === '') {
        continue;
    }

    foreach ($facts['stale_claims'] as $claim) {
        if (stripos($body, (string) $claim) !== false) {
            $fail($relative($document) . " repeats a retired claim: \"{$claim}\"");
        }
    }
}

// ---------------------------------------------------------------------------
// 6. A relative link that does not resolve is a broken document.
// ---------------------------------------------------------------------------

foreach ($documents as $document) {
    if (!str_ends_with($document, '.md')) {
        continue;
    }

    $body = $read($document);

    if ($body === '') {
        continue;
    }

    preg_match_all('/\]\(([^)\s]+)\)/', $body, $links);

    foreach (array_unique($links[1]) as $link) {
        if (str_starts_with($link, 'http') || str_starts_with($link, 'mailto:') || str_starts_with($link, '#')) {
            continue;
        }

        $target = explode('#', $link)[0];

        if ($target === '') {
            continue;
        }

        if (!file_exists(dirname($document) . '/' . $target)) {
            $fail($relative($document) . " links to {$target}, which does not exist");
        }
    }
}

// ---------------------------------------------------------------------------

if ($failures !== []) {
    fwrite(STDERR, "Documentation is inconsistent with .github/product-facts.json:\n\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }

    fwrite(STDERR, "\nFix the document, or update product-facts.json if the fact itself changed.\n");
    exit(1);
}

echo "Documentation is consistent with .github/product-facts.json\n";
