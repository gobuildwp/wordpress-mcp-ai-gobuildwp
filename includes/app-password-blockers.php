<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Who switched Application Passwords off.
 *
 * Kept apart from environment.php so the attribution can be unit-tested on its own: the status
 * function there reads WordPress state, while everything below except the entry point works on
 * plain values.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Name whoever switched Application Passwords off through `wp_is_application_passwords_available`.
 *
 * Wordfence is checked by its setting rather than by its callback: it registers `__return_false`,
 * a core function, so the callback alone cannot be traced back to it. Wordfence also ships with
 * the setting on, which makes it the commonest reason a freshly connected client gets 401 on
 * every call. Anything else is attributed by the file its callback was declared in; a callback
 * that resolves to WordPress core (another `__return_false`) cannot be attributed and yields null,
 * so the caller falls back to generic advice rather than blaming the wrong plugin.
 *
 * @return array{source: string, name: string, message: string, remedy: string, url: string}|null
 */
function wppilot_app_passwords_blocker(): ?array
{
    $wordfence_on = false;
    if (class_exists('wfConfig') && is_callable(['wfConfig', 'get'])) {
        $wordfence_on = (bool) \wfConfig::get('loginSec_disableApplicationPasswords');
    }

    $files = [];
    $hook = $GLOBALS['wp_filter']['wp_is_application_passwords_available'] ?? null;
    if ($hook instanceof WP_Hook) {
        foreach ($hook->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                $file = wppilot_callback_file($callback['function'] ?? null);
                if ($file !== '') {
                    $files[] = $file;
                }
            }
        }
    }

    return wppilot_app_passwords_blocker_from(
        $wordfence_on,
        $files,
        defined('WP_PLUGIN_DIR') ? (string) WP_PLUGIN_DIR : '',
        defined('WPMU_PLUGIN_DIR') ? (string) WPMU_PLUGIN_DIR : '',
        function_exists('get_theme_root') ? get_theme_root() : '',
    );
}

/**
 * The attribution itself, over plain values so it can be tested without a WordPress hook table.
 *
 * @param list<string> $callback_files Files the filter's callbacks were declared in.
 * @return array{source: string, name: string, message: string, remedy: string, url: string}|null
 */
function wppilot_app_passwords_blocker_from(
    bool $wordfence_on,
    array $callback_files,
    string $plugin_dir,
    string $mu_plugin_dir,
    string $theme_root,
): ?array {
    $alternative = __('Or connect with OAuth or a GoBuildWP access token, which do not use Application Passwords.', domain: 'wppilot');

    if ($wordfence_on) {
        return [
            'source' => 'wordfence',
            'name' => 'Wordfence',
            'message' => __(
                'Wordfence has switched Application Passwords off (its "Disable WordPress application passwords" setting, which Wordfence turns on by default), so every AI client using the Application Password method gets 401.',
                domain: 'wppilot',
            ),
            'remedy' => __(
                'In wp-admin go to Wordfence > Firewall > All Firewall Options > Brute Force Protection, uncheck "Disable WordPress application passwords" and save.',
                domain: 'wppilot',
            ) . ' ' . $alternative,
            'url' => function_exists('admin_url')
                ? admin_url('admin.php?page=WordfenceWAF&subpage=waf_options#wf-option-loginSec-disableApplicationPasswords-label')
                : '',
        ];
    }

    $culprits = [];
    foreach ($callback_files as $file) {
        $culprit = wppilot_extension_for_file($file, $plugin_dir, $mu_plugin_dir, $theme_root);
        if ($culprit !== null) {
            $culprits[$culprit['label']] = $culprit['label'];
        }
    }
    if ($culprits === []) {
        return null;
    }

    $names = implode(', ', $culprits);
    return [
        'source' => 'filter',
        'name' => $names,
        'message' => sprintf(
            /* translators: %s: plugin, mu-plugin or theme names with their file, comma separated. */
            __(
                'Application Passwords are switched off by a wp_is_application_passwords_available filter from %s, so every AI client using the Application Password method gets 401.',
                domain: 'wppilot',
            ),
            $names,
        ),
        'remedy' => __(
            'Look in that plugin\'s security or login settings for an option that disables Application Passwords and turn it off.',
            domain: 'wppilot',
        ) . ' ' . $alternative,
        'url' => '',
    ];
}

/**
 * The file a hook callback was declared in, or '' when it cannot be reflected.
 */
function wppilot_callback_file(mixed $callback): string
{
    try {
        if ($callback instanceof Closure || (is_string($callback) && !str_contains($callback, '::') && function_exists($callback))) {
            return (string) (new ReflectionFunction($callback))->getFileName();
        }
        if (is_string($callback) && str_contains($callback, '::')) {
            $callback = explode('::', $callback, 2);
        }
        if (is_array($callback) && count($callback) === 2 && is_string($callback[1])) {
            return (string) (new ReflectionMethod($callback[0], $callback[1]))->getFileName();
        }
        if (is_object($callback) && method_exists($callback, '__invoke')) {
            return (string) (new ReflectionMethod($callback, '__invoke'))->getFileName();
        }
    } catch (ReflectionException) {
        return '';
    }
    return '';
}

/**
 * Which plugin, mu-plugin or theme a file belongs to, as "Name (relative/path.php)".
 *
 * Core files (wp-includes, wp-admin) return null: they hold generic callbacks such as
 * `__return_false` that say nothing about who registered them.
 *
 * @return array{label: string}|null
 */
function wppilot_extension_for_file(string $file, string $plugin_dir, string $mu_plugin_dir, string $theme_root): ?array
{
    $normalize = static fn(string $path): string => rtrim(str_replace('\\', '/', $path), '/');
    $file = $normalize($file);
    $roots = [
        'plugin' => $normalize($plugin_dir),
        'mu-plugin' => $normalize($mu_plugin_dir),
        'theme' => $normalize($theme_root),
    ];
    foreach ($roots as $kind => $root) {
        if ($root === '' || !str_starts_with($file, $root . '/')) {
            continue;
        }
        $relative = substr($file, strlen($root) + 1);
        $slug = explode('/', $relative, 2)[0];
        $name = $kind === 'plugin' ? wppilot_plugin_name_for_slug($root, $slug) : '';
        $label = ($name !== '' ? $name : $slug) . ' (' . basename($root) . '/' . $relative . ')';
        return ['label' => $label];
    }
    return null;
}

/**
 * A plugin's human name from its main file header, or '' when none is readable.
 *
 * The main file is found the way WordPress finds it, as the directory's PHP file that carries a
 * "Plugin Name" header; the directory is known, so this reads a handful of headers at most.
 */
function wppilot_plugin_name_for_slug(string $plugin_root, string $slug): string
{
    if (!function_exists('get_file_data') || str_ends_with($slug, '.php')) {
        return '';
    }
    $candidates = glob($plugin_root . '/' . $slug . '/*.php');
    foreach (is_array($candidates) ? $candidates : [] as $candidate) {
        $name = trim((string) (get_file_data($candidate, ['name' => 'Plugin Name'])['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }
    return '';
}
