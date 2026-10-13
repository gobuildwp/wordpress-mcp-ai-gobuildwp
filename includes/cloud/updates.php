<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Pending updates for the Cloud dashboard, §4.
 *
 * Read from the update transients WordPress's own twice-daily check fills, so
 * a status call never reaches wordpress.org or any other update source: the
 * Cloud polls every connected site, and those calls must stay cheap. The
 * answer is as fresh as WordPress's last check, which checked_at reports.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** Most items of one kind a status answer carries. */
const WPPILOT_CLOUD_UPDATES_MAX_ITEMS = 300;

/**
 * @return array{
 *     checked_at: int|null,
 *     core: array{current: string, new_version: string}|null,
 *     plugins: list<array{file: string, name: string, version: string, new_version: string, auto_update: bool}>,
 *     themes: list<array{stylesheet: string, name: string, version: string, new_version: string, auto_update: bool}>,
 *     translations: int
 * }
 */
function wppilot_cloud_pending_updates(): array
{
    $plugins_t = get_site_transient('update_plugins');
    $themes_t = get_site_transient('update_themes');
    $core_t = get_site_transient('update_core');

    $checked = [];
    foreach ([$plugins_t, $themes_t, $core_t] as $transient) {
        if (is_object($transient) && isset($transient->last_checked) && is_numeric($transient->last_checked)) {
            $checked[] = (int) $transient->last_checked;
        }
    }

    return [
        // The oldest of the three: every list is at least this fresh.
        'checked_at' => $checked === [] ? null : min($checked),
        'core' => wppilot_cloud_core_update($core_t),
        'plugins' => wppilot_cloud_plugin_updates($plugins_t),
        'themes' => wppilot_cloud_theme_updates($themes_t),
        'translations' => wppilot_cloud_count_translations([$plugins_t, $themes_t, $core_t]),
    ];
}

/**
 * @return array{current: string, new_version: string}|null
 */
function wppilot_cloud_core_update(mixed $transient): ?array
{
    if (!is_object($transient) || !isset($transient->updates) || !is_array($transient->updates)) {
        return null;
    }

    $current = (string) get_bloginfo('version');
    foreach ($transient->updates as $offer) {
        if (!is_object($offer) || ($offer->response ?? '') !== 'upgrade' || !isset($offer->current)) {
            continue;
        }
        $version = wppilot_cloud_short((string) $offer->current, 40);
        if ($version !== '' && version_compare($version, $current, '>')) {
            return ['current' => $current, 'new_version' => $version];
        }
    }

    return null;
}

/**
 * @return list<array{file: string, name: string, version: string, new_version: string, auto_update: bool}>
 */
function wppilot_cloud_plugin_updates(mixed $transient): array
{
    if (!is_object($transient) || !isset($transient->response) || !is_array($transient->response) || $transient->response === []) {
        return [];
    }

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $installed = get_plugins();
    $auto = (array) get_site_option('auto_update_plugins', []);

    $items = [];
    foreach ($transient->response as $file => $offer) {
        $file = (string) $file;
        $new = is_object($offer) ? (string) ($offer->new_version ?? '') : '';
        // An offer for a plugin that is no longer installed is a stale transient, not an update.
        if ($new === '' || !isset($installed[$file])) {
            continue;
        }
        $items[] = [
            'file' => wppilot_cloud_short($file, 200),
            'name' => wppilot_cloud_short((string) ($installed[$file]['Name'] ?? $file), 120),
            'version' => wppilot_cloud_short((string) ($installed[$file]['Version'] ?? ''), 40),
            'new_version' => wppilot_cloud_short($new, 40),
            'auto_update' => in_array($file, $auto, true),
        ];
        if (count($items) >= WPPILOT_CLOUD_UPDATES_MAX_ITEMS) {
            break;
        }
    }

    return $items;
}

/**
 * @return list<array{stylesheet: string, name: string, version: string, new_version: string, auto_update: bool}>
 */
function wppilot_cloud_theme_updates(mixed $transient): array
{
    if (!is_object($transient) || !isset($transient->response) || !is_array($transient->response) || $transient->response === []) {
        return [];
    }

    $auto = (array) get_site_option('auto_update_themes', []);

    $items = [];
    foreach ($transient->response as $stylesheet => $offer) {
        $stylesheet = (string) $stylesheet;
        $new = is_array($offer) ? (string) ($offer['new_version'] ?? '') : '';
        $theme = wp_get_theme($stylesheet);
        if ($new === '' || !$theme->exists()) {
            continue;
        }
        $items[] = [
            'stylesheet' => wppilot_cloud_short($stylesheet, 200),
            'name' => wppilot_cloud_short((string) $theme->get('Name'), 120),
            'version' => wppilot_cloud_short((string) $theme->get('Version'), 40),
            'new_version' => wppilot_cloud_short($new, 40),
            'auto_update' => in_array($stylesheet, $auto, true),
        ];
        if (count($items) >= WPPILOT_CLOUD_UPDATES_MAX_ITEMS) {
            break;
        }
    }

    return $items;
}

/**
 * @param list<mixed> $transients
 */
function wppilot_cloud_count_translations(array $transients): int
{
    $count = 0;
    foreach ($transients as $transient) {
        if (is_object($transient) && isset($transient->translations) && is_array($transient->translations)) {
            $count += count($transient->translations);
        }
    }

    return $count;
}

function wppilot_cloud_short(string $value, int $max): string
{
    $value = trim(wp_strip_all_tags($value));

    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}
