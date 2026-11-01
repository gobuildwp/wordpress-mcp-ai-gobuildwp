<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Which files a scan may open, and how they are named in results.
 *
 * The scan walks fixed roots only (core, plugins, must-use plugins, themes, uploads), never
 * follows a symbolic link, never opens a denied file and never descends into a denied folder.
 * Results name files relative to ABSPATH with '/' separators, so nothing outside the site's own
 * folder is ever spelled out.
 */

/** Files never opened, by lower-cased name. */
function denied_file(string $name): bool
{
    $name = strtolower($name);
    return $name === 'wp-config.php'
        || preg_match('/^wp-config-.*\.php$/', $name) === 1
        || str_starts_with($name, '.env')
        || preg_match('/\.(?:sql|zip|tar|gz|tgz)$/', $name) === 1;
}

/** Folders never entered, by lower-cased name. */
function denied_dir(string $name): bool
{
    $name = strtolower($name);
    return in_array($name, ['.git', 'node_modules', 'cache', 'upgrade', 'updraft', 'ai1wm-backups', 'wppilot-kit-safe-updates'], strict: true)
        || str_starts_with($name, 'backwpup-');
}

/** Extensions a web server may hand to PHP. */
function is_php_extension(string $extension): bool
{
    return preg_match('/^(?:php\d*|phtml|pht|phar|phps)$/i', $extension) === 1;
}

function is_php_name(string $name): bool
{
    $dot = strrpos($name, '.');
    return $dot !== false && is_php_extension(substr($name, $dot + 1));
}

/** A PHP extension before the last one (`shell.php.jpg`), which some server setups still run. */
function has_inner_php_extension(string $name): bool
{
    $parts = explode('.', $name);
    array_shift($parts);
    array_pop($parts);
    foreach ($parts as $part) {
        if (is_php_extension($part)) {
            return true;
        }
    }
    return false;
}

/** Separators turned to '/', trailing ones dropped; the filesystem root stays '/'. */
function normalize(string $path): string
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    return $path === '' ? '/' : $path;
}

/** Whether $path is $dir or inside it, compared as strings after normalize(). */
function within(string $path, string $dir): bool
{
    $path = normalize($path);
    $dir = normalize($dir);
    if ($dir === '/') {
        return str_starts_with($path, '/');
    }
    return $path === $dir || str_starts_with($path, $dir . '/');
}

/** Whether a relative path climbs out with a `..` segment. */
function has_parent_segment(string $path): bool
{
    return in_array('..', explode('/', str_replace('\\', '/', $path)), strict: true);
}

/**
 * Decide whether a path may be opened: 'ok', or why not ('traversal', 'symlink', 'missing',
 * 'outside'). The link test comes before realpath(), which would resolve the link.
 */
function guard(string $path, string $root): string
{
    if (has_parent_segment($path)) {
        return 'traversal';
    }
    if (is_link($path)) {
        return 'symlink';
    }
    $real = realpath($path);
    $real_root = realpath($root);
    if ($real === false || $real_root === false) {
        return 'missing';
    }
    return within($real, $real_root) ? 'ok' : 'outside';
}

/**
 * Where WordPress, its plugins and its must-use plugins live. On a site these are always
 * WordPress's own; tests point them at a fixture tree. An override replaces the keys it names;
 * roots([]) restores the defaults.
 *
 * @param array{abspath?: string, plugins?: string, mu_plugins?: string, themes?: string, uploads?: string, content?: string}|null $override
 * @return array{abspath: string, plugins: string, mu_plugins: string, themes?: string, uploads?: string, content?: string}
 */
function roots(?array $override = null): array
{
    static $set = [];
    if ($override !== null) {
        $set = $override;
    }
    return $set + [
        'abspath' => (string) ABSPATH,
        'plugins' => defined('WP_PLUGIN_DIR') ? (string) constant('WP_PLUGIN_DIR') : '',
        'mu_plugins' => defined('WPMU_PLUGIN_DIR') ? (string) constant('WPMU_PLUGIN_DIR') : '',
    ];
}

/** The themes folder: a roots() override named 'themes', else WordPress's theme root. */
function themes_root(): string
{
    $set = roots();
    if (isset($set['themes'])) {
        return (string) $set['themes'];
    }
    return function_exists('get_theme_root') ? (string) get_theme_root() : '';
}

/** The uploads base folder: a roots() override named 'uploads', else wp_upload_dir()'s basedir. */
function uploads_root(): string
{
    $set = roots();
    if (isset($set['uploads'])) {
        return (string) $set['uploads'];
    }
    $uploads = function_exists('wp_upload_dir') ? wp_upload_dir(null, false) : [];
    return is_array($uploads) && empty($uploads['error']) ? (string) ($uploads['basedir'] ?? '') : '';
}

/**
 * A root's name relative to the WordPress folder, or $fallback when the root lives elsewhere (a
 * plugins folder moved out of the web root, say).
 */
function root_name(string $dir, string $fallback): string
{
    $abs = realpath(roots()['abspath']);
    $real = realpath($dir);
    if ($abs !== false && $real !== false && within($real, $abs) && normalize($real) !== normalize($abs)) {
        return ltrim(substr(normalize($real), strlen(normalize($abs))), '/');
    }
    return $fallback;
}

function join_path(string $base, string $name): string
{
    return $base === '' ? $name : $base . '/' . $name;
}

/** Results paths are at most 300 characters. */
function clip(string $path): string
{
    return strlen($path) > 300 ? substr($path, 0, 300) : $path;
}

/**
 * One step of a resumable walk over a folder tree.
 *
 * $walk holds a queue of [absolute dir, result name] pairs and an offset into the first one's
 * sorted listing, so a step that stops part-way resumes at the next entry. $file is called for
 * each regular file and returns false when the step's budget ran out before it could handle it;
 * the walk then stops on that entry and returns false. Returns true once the queue is empty.
 *
 * @param array{queue: list<array{0: string, 1: string}>, offset: int, flat?: list<string>} $walk
 *        `flat` lists dirs whose subfolders are not entered.
 * @param callable(string, string, string): bool $file absolute path, result path, file name
 * @param callable(string, string): void $skipped result path, reason ('symlink' or 'denied')
 */
function walk(array &$walk, Budget $budget, callable $file, callable $skipped): bool
{
    while ($walk['queue'] !== []) {
        [$dir, $name] = $walk['queue'][0];
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            $skipped($name, 'unreadable');
            array_shift($walk['queue']);
            $walk['offset'] = 0;
            continue;
        }
        $entries = array_values(array_diff($entries, ['.', '..']));
        $flat = in_array($dir, $walk['flat'] ?? [], strict: true);
        for ($i = $walk['offset']; $i < count($entries); $i++) {
            if ($budget->spent()) {
                $walk['offset'] = $i;
                return false;
            }
            $entry = $entries[$i];
            $path = $dir . '/' . $entry;
            $result_name = join_path($name, $entry);
            $budget->entries++;
            if (is_link($path)) {
                $skipped($result_name, 'symlink');
                continue;
            }
            if (is_dir($path)) {
                if ($flat) {
                    continue;
                }
                if (denied_dir($entry)) {
                    $skipped($result_name, 'denied');
                    continue;
                }
                $walk['queue'][] = [$path, $result_name];
                continue;
            }
            if (!is_file($path)) {
                continue;
            }
            if (denied_file($entry)) {
                $skipped($result_name, 'denied');
                continue;
            }
            if (!$file($path, $result_name, $entry)) {
                $walk['offset'] = $i;
                return false;
            }
        }
        array_shift($walk['queue']);
        $walk['offset'] = 0;
    }
    return true;
}
