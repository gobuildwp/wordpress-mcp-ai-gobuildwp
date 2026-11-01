<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Published checksums: WordPress core's (md5, from api.wordpress.org through core's own
 * get_core_checksums()), WordPress.org plugins' (sha256, from downloads.wordpress.org) and
 * WordPress.org themes' (sha256 of each file in the theme's zip from downloads.wordpress.org,
 * which publishes no checksum list for themes).
 *
 * Each list is cached in a transient for a week per version, so a resumed scan does not fetch it
 * again. A plugin or theme is checked only when WordPress.org itself offers its updates; a premium
 * one that happens to share a folder name with a WordPress.org one must not be compared with the
 * free one's files.
 */

const CHECKSUM_TTL = 7 * DAY_IN_SECONDS;
const PLUGIN_CHECKSUMS_URL = 'https://downloads.wordpress.org/plugin-checksums/%s/%s.json';

const THEME_ZIP_URL = 'https://downloads.wordpress.org/theme/%s.%s.zip';

/** The plugin files WordPress.org offered updates for at the last update check that answered. */
const WPORG_PLUGINS_OPTION = 'gobuildwp_kit_si_wporg';

/** The themes WordPress.org offered updates for at the last update check that answered. */
const WPORG_THEMES_OPTION = 'gobuildwp_kit_si_wporg_themes';

/**
 * Limits on a theme zip: the download, its entries, and the bytes one entry and all of them may
 * unpack to. A zip over any of them is treated as having no checksums.
 */
const THEME_ZIP_LIMITS = ['download' => 32 * 1024 * 1024, 'entries' => 5000, 'entry' => 32 * 1024 * 1024, 'total' => 256 * 1024 * 1024];

/** Theme folder names and versions as WordPress.org has them; anything else is not looked up. */
const THEME_SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,99}$/';
const THEME_VERSION_PATTERN = '/^[0-9A-Za-z._-]{1,40}$/';

function core_transient(string $version, string $locale): string
{
    return 'gobuildwp_kit_si_core_' . md5($version . '|' . $locale);
}

/**
 * Core's checksums for this version, path => md5, or null when none can be had. Tries the site's
 * locale, then en_US, as core's own checksum verification does. A download counts against the
 * step's fetch budget; false is returned (not null) when that budget is spent, so the step waits
 * for the next one instead of recording the checksums as unavailable.
 *
 * @return array<string, string>|null|false
 */
function core_checksums(string $version, string $locale, Budget $budget): array|null|false
{
    foreach (array_unique([$locale, 'en_US']) as $try) {
        /** @var mixed $cached */
        $cached = get_transient(core_transient($version, $try));
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }
    }
    if (!function_exists('get_core_checksums') && is_file(ABSPATH . 'wp-admin/includes/update.php')) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }
    if (!function_exists('get_core_checksums')) {
        return null;
    }
    foreach (array_unique([$locale, 'en_US']) as $try) {
        if (!$budget->can_fetch()) {
            return false;
        }
        $budget->fetches++;
        /** @var mixed $sums */
        $sums = get_core_checksums($version, $try);
        if (is_array($sums) && $sums !== []) {
            $clean = [];
            foreach ($sums as $path => $md5) {
                if (is_string($path) && is_string($md5) && preg_match('/^[a-f0-9]{32}$/', $md5) === 1) {
                    $clean[$path] = $md5;
                }
            }
            ksort($clean, SORT_STRING);
            set_transient(core_transient($version, $try), $clean, CHECKSUM_TTL);
            return $clean;
        }
    }
    return null;
}

function plugin_transient(string $slug, string $version): string
{
    return 'gobuildwp_kit_si_pc_' . md5($slug . '@' . $version);
}

/**
 * A WordPress.org plugin's checksums, path => list of accepted sha256 values; [] when WordPress.org
 * has none for that version; false when the fetch budget is spent.
 *
 * @return array<string, list<string>>|false
 */
function plugin_checksums(string $slug, string $version, Budget $budget): array|false
{
    /** @var mixed $cached */
    $cached = get_transient(plugin_transient($slug, $version));
    if (is_array($cached)) {
        return $cached;
    }
    if (!$budget->can_fetch()) {
        return false;
    }
    $budget->fetches++;
    $response = wp_remote_get(sprintf(PLUGIN_CHECKSUMS_URL, rawurlencode($slug), rawurlencode($version)), [
        'timeout' => 10,
        'redirection' => 2,
        'limit_response_size' => 4 * 1024 * 1024,
    ]);
    if (is_wp_error($response)) {
        // A network failure is not cached: the next scan tries again.
        return [];
    }
    $sums = [];
    if ((int) wp_remote_retrieve_response_code($response) === 200) {
        /** @var mixed $data */
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $files = is_array($data) && is_array($data['files'] ?? null) ? $data['files'] : [];
        foreach ($files as $path => $hashes) {
            if (!is_string($path) || !is_array($hashes)) {
                continue;
            }
            $values = is_array($hashes['sha256'] ?? null) ? $hashes['sha256'] : [$hashes['sha256'] ?? null];
            $values = array_values(array_filter($values, static fn(mixed $v): bool => is_string($v) && preg_match('/^[a-f0-9]{64}$/', $v) === 1));
            if ($values !== []) {
                $sums[$path] = $values;
            }
        }
        ksort($sums, SORT_STRING);
    }
    set_transient(plugin_transient($slug, $version), $sums, CHECKSUM_TTL);
    return $sums;
}

/**
 * Whether WordPress.org offers this plugin's updates, as recorded by the last update check: its
 * entry in the update_plugins transient carries an id starting with `w.org/`. Single-file plugins
 * have no folder to compare and are never treated as WordPress.org ones.
 */
function is_wporg_plugin(string $file): bool
{
    return str_contains($file, '/') && in_array($file, wporg_plugins(), strict: true);
}

/**
 * The plugin files WordPress.org offers updates for. WordPress clears the update check after
 * every update and keeps it empty when the check fails; until it answers again the last answer
 * stands, so a plugin doesn't lose its checksum comparison (and get raw rules on its files) just
 * because it was updated.
 *
 * @return list<string>
 */
function wporg_plugins(): array
{
    /** @var mixed $updates */
    $updates = get_site_transient('update_plugins');
    $answered = false;
    $files = [];
    foreach (['response', 'no_update'] as $list) {
        $entries = is_object($updates) && isset($updates->{$list}) && is_array($updates->{$list}) ? $updates->{$list} : [];
        foreach ($entries as $file => $entry) {
            $answered = true;
            $id = is_object($entry) ? ($entry->id ?? '') : (is_array($entry) ? ($entry['id'] ?? '') : '');
            if (is_string($id) && str_starts_with($id, 'w.org/')) {
                $files[] = (string) $file;
            }
        }
    }
    if (!$answered) {
        /** @var mixed $stored */
        $stored = get_option(WPORG_PLUGINS_OPTION, []);
        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }
    sort($files, SORT_STRING);
    if (get_option(WPORG_PLUGINS_OPTION) !== $files) {
        update_option(WPORG_PLUGINS_OPTION, $files, autoload: false);
    }
    return $files;
}

function theme_transient(string $slug, string $version): string
{
    return 'gobuildwp_kit_si_tc_' . md5($slug . '@' . $version);
}

/**
 * A WordPress.org theme's checksums, path => list of one sha256, read from the theme's zip; []
 * when there are none to be had (no such version, an unusable zip, no ZipArchive); false when the
 * fetch budget is spent. The zip is downloaded to a temporary file, hashed entry by entry through
 * streams and deleted; nothing in it is ever unpacked to disk.
 *
 * @return array<string, list<string>>|false
 */
function theme_checksums(string $slug, string $version, Budget $budget): array|false
{
    if (preg_match(THEME_SLUG_PATTERN, $slug) !== 1 || preg_match(THEME_VERSION_PATTERN, $version) !== 1) {
        return [];
    }
    /** @var mixed $cached */
    $cached = get_transient(theme_transient($slug, $version));
    if (is_array($cached)) {
        return $cached;
    }
    if (!class_exists('ZipArchive')) {
        return [];
    }
    if (!$budget->can_fetch()) {
        return false;
    }
    $zip = temp_file();
    if ($zip === null) {
        return [];
    }
    try {
        $budget->fetches++;
        $response = wp_remote_get(sprintf(THEME_ZIP_URL, rawurlencode($slug), rawurlencode($version)), [
            'timeout' => 15,
            'redirection' => 2,
            'stream' => true,
            'filename' => $zip,
            'limit_response_size' => THEME_ZIP_LIMITS['download'],
        ]);
        if (is_wp_error($response)) {
            // A network failure is not cached: the next scan tries again.
            return [];
        }
        $sums = [];
        if ((int) wp_remote_retrieve_response_code($response) === 200) {
            $size = (int) @filesize($zip);
            $read = $size < THEME_ZIP_LIMITS['download'] ? zip_checksums($zip, $slug) : null;
            if ($read === null && ($size === 0 || $size >= THEME_ZIP_LIMITS['download'])) {
                // An empty or cut-off download is not cached either; a whole but unusable zip is.
                return [];
            }
            $sums = $read ?? [];
        }
        set_transient(theme_transient($slug, $version), $sums, CHECKSUM_TTL);
        return $sums;
    } finally {
        @unlink($zip);
    }
}

/** A new, empty temporary file for a download, or null when none can be made. */
function temp_file(): ?string
{
    $dir = function_exists('get_temp_dir') ? (string) get_temp_dir() : sys_get_temp_dir();
    $file = @tempnam($dir, 'gbwp-si-');
    return is_string($file) && $file !== '' ? $file : null;
}

/**
 * The sha256 of every file under "{$slug}/" in a zip, keyed by its path inside that folder, or
 * null when the zip cannot be read or goes over THEME_ZIP_LIMITS. Entries are only ever read as
 * streams and their names only used as keys, so a name like ../x or /etc/x cannot write anything;
 * such names, and any outside the theme's folder, are left out.
 *
 * @return array<string, list<string>>|null
 */
function zip_checksums(string $file, string $slug): ?array
{
    $zip = new \ZipArchive();
    if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
        return null;
    }
    try {
        if ($zip->numFiles > THEME_ZIP_LIMITS['entries']) {
            return null;
        }
        $prefix = $slug . '/';
        $sums = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                return null;
            }
            $name = (string) $stat['name'];
            if (!str_starts_with($name, $prefix) || str_ends_with($name, '/')) {
                continue;
            }
            $relative = substr($name, strlen($prefix));
            if (!safe_zip_path($relative) || isset($sums[$relative])) {
                continue;
            }
            if ((int) $stat['size'] > THEME_ZIP_LIMITS['entry']) {
                // Left out: the scan then judges the installed file as one the zip does not ship.
                continue;
            }
            $hash = zip_entry_hash($zip, $i, THEME_ZIP_LIMITS['total'] - $total);
            if ($hash === null) {
                return null;
            }
            $total += $hash[1];
            $sums[$relative] = [$hash[0]];
        }
        ksort($sums, SORT_STRING);
        return $sums;
    } finally {
        $zip->close();
    }
}

/** A plainly relative path inside a zip: no absolute, drive, backslash, NUL, empty, . or .. part. */
function safe_zip_path(string $path): bool
{
    if ($path === '' || strlen($path) > 255 || strpbrk($path, "\\\0:") !== false || str_starts_with($path, '/')) {
        return false;
    }
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return false;
        }
    }
    return true;
}

/**
 * Hash one zip entry by streaming it, counting the bytes it really unpacks to (a zip can lie about
 * its sizes). Null when it cannot be read or unpacks to more than $allowed bytes.
 *
 * @return array{0: string, 1: int}|null sha256 and size
 */
function zip_entry_hash(\ZipArchive $zip, int $index, int $allowed): ?array
{
    $name = $zip->getNameIndex($index);
    $stream = is_string($name) ? $zip->getStream($name) : false;
    if (!is_resource($stream)) {
        return null;
    }
    $limit = min($allowed, THEME_ZIP_LIMITS['entry']);
    $context = hash_init('sha256');
    $size = 0;
    try {
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                return null;
            }
            $size += strlen($chunk);
            if ($size > $limit) {
                return null;
            }
            hash_update($context, $chunk);
        }
    } finally {
        fclose($stream);
    }
    return [hash_final($context), $size];
}

/**
 * Whether WordPress.org offers this theme's updates, as recorded by the last update check: its
 * entry in the update_themes transient points at wordpress.org for its page and its package. A
 * theme's own updater that adds an entry for it points elsewhere.
 */
function is_wporg_theme(string $stylesheet): bool
{
    return in_array($stylesheet, wporg_themes(), strict: true);
}

/**
 * The themes WordPress.org offers updates for. As with plugins, the last answer stands while the
 * update check is empty (cleared after an update, or failed).
 *
 * @return list<string>
 */
function wporg_themes(): array
{
    /** @var mixed $updates */
    $updates = get_site_transient('update_themes');
    $answered = false;
    $themes = [];
    foreach (['response', 'no_update'] as $list) {
        $entries = is_object($updates) && isset($updates->{$list}) && is_array($updates->{$list}) ? $updates->{$list} : [];
        foreach ($entries as $stylesheet => $entry) {
            $answered = true;
            $entry = is_object($entry) ? get_object_vars($entry) : (is_array($entry) ? $entry : []);
            $url = is_string($entry['url'] ?? null) ? $entry['url'] : '';
            $package = is_string($entry['package'] ?? null) ? $entry['package'] : '';
            if (($url !== '' || $package !== '')
                && ($url === '' || str_starts_with($url, 'https://wordpress.org/themes/'))
                && ($package === '' || str_starts_with($package, 'https://downloads.wordpress.org/theme/'))) {
                $themes[] = (string) $stylesheet;
            }
        }
    }
    if (!$answered) {
        /** @var mixed $stored */
        $stored = get_option(WPORG_THEMES_OPTION, []);
        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }
    $themes = array_values(array_unique($themes));
    sort($themes, SORT_STRING);
    if (get_option(WPORG_THEMES_OPTION) !== $themes) {
        update_option(WPORG_THEMES_OPTION, $themes, autoload: false);
    }
    return $themes;
}
