<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Fail the build when two files in the shipped tree declare the same function.
 *
 * A duplicate declaration is a fatal on activation, and it is invisible to everything else in
 * this repository's gates. `php -l` parses one file at a time and sees nothing wrong with
 * either. The unit suite loads the modules it exercises, so a function moved into a shared
 * file while its original copy stayed behind in an admin file only collides on a real install
 * — where the failure is a white screen during activation rather than a message anyone can
 * act on.
 *
 * Functions are keyed by namespace, because two identically named functions in different
 * namespaces are two different functions. Conditional declarations are excluded: a body
 * guarded by function_exists() is how a plugin polyfills, and both copies are meant to exist.
 *
 * Usage: php scripts/check-duplicate-declarations.php
 */

$root = dirname(__DIR__);

$skip = ['/vendor/', '/build/', '/tests/', '/scripts/', '/node_modules/', '/freemius/'];

/** @var array<string, list<string>> $seen */
$seen = [];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

/** @var SplFileInfo $file */
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    foreach ($skip as $fragment) {
        if (str_contains($path, $fragment)) {
            continue 2;
        }
    }

    $source = (string) file_get_contents($path);
    $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');

    $namespace = preg_match('/^namespace\s+([^;{]+)/m', $source, $m) === 1 ? trim($m[1]) : '';

    // File-scope declarations only: an indented `function` is a method or a
    // closure, and neither collides with anything.
    preg_match_all('/^function\s+&?\s*([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)\s*\(/m', $source, $matches);

    foreach ($matches[1] as $name) {
        // A declaration inside a function_exists() guard is a deliberate
        // polyfill. Detecting the guard exactly needs a parser; the file
        // mentioning its own function name in a function_exists() call is
        // close enough and errs towards silence rather than a false failure.
        if (str_contains($source, "function_exists('{$name}')") || str_contains($source, "function_exists(\"{$name}\")")) {
            continue;
        }

        $key = $namespace === '' ? $name : $namespace . '\\' . $name;
        $seen[$key][] = $relative;
    }
}

$failures = [];

foreach ($seen as $key => $paths) {
    $paths = array_values(array_unique($paths));
    if (count($paths) > 1) {
        $failures[] = sprintf('%s() is declared in %s', $key, implode(' and ', $paths));
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Duplicate function declarations in the shipped tree:\n\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    fwrite(STDERR, "\nEach of these is a fatal error on plugin activation.\n");
    exit(1);
}

echo 'No duplicate function declarations in the shipped tree.', PHP_EOL;
