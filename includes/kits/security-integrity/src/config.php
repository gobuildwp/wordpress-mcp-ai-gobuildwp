<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Configuration checks, reported as database-scan results in the `config` area. Each names the
 * setting (a constant or option name) and never its value; wp-config.php is only stat()ed.
 */

/** End of security support per PHP branch (php.net/supported-versions). */
const PHP_EOL_DATES = [
    '7.4' => '2022-11-28',
    '8.0' => '2023-11-26',
    '8.1' => '2025-12-31',
    '8.2' => '2026-12-31',
    '8.3' => '2027-12-31',
    '8.4' => '2028-12-31',
    '8.5' => '2029-12-31',
];

/**
 * @return list<array<string, mixed>>
 */
function config_results(?string $php_version = null, ?int $now = null): array
{
    $now ??= time();
    $items = [];
    $add = static function (string $rule, string $severity, string $label, array $detail = []) use (&$items): void {
        $items[] = db_item('config', $rule, $severity, 'config', $label, $label, $detail);
    };

    if (!defined('DISALLOW_FILE_EDIT') || !constant('DISALLOW_FILE_EDIT')) {
        if (!defined('DISALLOW_FILE_MODS') || !constant('DISALLOW_FILE_MODS')) {
            $add('config.file_edit_enabled', 'medium', 'DISALLOW_FILE_EDIT');
        }
    }
    $debug = defined('WP_DEBUG') && constant('WP_DEBUG');
    // WP_DEBUG_DISPLAY defaults to true, so errors show whenever WP_DEBUG is on and it is not set off.
    if ($debug && (!defined('WP_DEBUG_DISPLAY') || constant('WP_DEBUG_DISPLAY') !== false)) {
        $add('config.debug_display', 'medium', 'WP_DEBUG_DISPLAY');
    }
    if ((bool) apply_filters('xmlrpc_enabled', true) && !has_xmlrpc_block()) {
        $add('config.xmlrpc_enabled', 'low', 'xmlrpc_enabled');
    }
    foreach (config_permission_results() as [$rule, $severity]) {
        $add($rule, $severity, 'wp-config.php');
    }
    $php = php_eol_rule($php_version ?? PHP_VERSION, $now);
    if ($php !== null) {
        $add($php[0], $php[1], 'PHP_VERSION', ['reason' => $php[2]]);
    }
    if (!defined('FORCE_SSL_ADMIN') || !constant('FORCE_SSL_ADMIN')) {
        $home = (string) get_option('home', '');
        if (str_starts_with(strtolower($home), 'https://')) {
            $add('config.force_ssl_admin_off', 'low', 'FORCE_SSL_ADMIN');
        }
    }
    if (core_auto_updates_off()) {
        $add('config.core_auto_updates_off', 'low', 'WP_AUTO_UPDATE_CORE');
    }
    return $items;
}

/** Whether something already removes XML-RPC's methods (a common hardening snippet). */
function has_xmlrpc_block(): bool
{
    /** @var mixed $methods */
    $methods = apply_filters('xmlrpc_methods', ['system.listMethods' => 'this:listMethods']);
    return is_array($methods) && $methods === [];
}

/**
 * wp-config.php's permission bits, where the platform has them. Windows reports no useful mode.
 *
 * @return list<array{0: string, 1: string}>
 */
function config_permission_results(?string $file = null, ?int $mode = null): array
{
    if ($mode === null) {
        if (DIRECTORY_SEPARATOR === '\\') {
            return [];
        }
        $file ??= config_file();
        if ($file === null) {
            return [];
        }
        $stat = @stat($file);
        if (!is_array($stat)) {
            return [];
        }
        $mode = (int) $stat['mode'];
    }
    if (($mode & 0002) !== 0) {
        return [['config.wp_config_world_writable', 'high']];
    }
    if (($mode & 0004) !== 0) {
        return [['config.wp_config_world_readable', 'low']];
    }
    return [];
}

/** wp-config.php, in ABSPATH or (as WordPress allows) the folder above it. */
function config_file(): ?string
{
    $here = ABSPATH . 'wp-config.php';
    if (is_file($here) && !is_link($here)) {
        return $here;
    }
    $above = dirname(ABSPATH) . '/wp-config.php';
    if (is_file($above) && !is_link($above) && !is_file(dirname(ABSPATH) . '/wp-settings.php')) {
        return $above;
    }
    return null;
}

/**
 * @return array{0: string, 1: string, 2: string}|null rule, severity, reason
 */
function php_eol_rule(string $version, int $now): ?array
{
    $branch = implode('.', array_slice(explode('.', $version), 0, 2));
    $eol = PHP_EOL_DATES[$branch] ?? null;
    if ($eol === null) {
        if (version_compare($branch, '7.4', '<')) {
            return ['config.php_eol', 'high', 'PHP ' . $branch . ' no longer gets security fixes.'];
        }
        return null;
    }
    $end = (int) strtotime($eol . ' 23:59:59 UTC');
    if ($end < $now) {
        return ['config.php_eol', 'high', 'PHP ' . $branch . ' stopped getting security fixes on ' . $eol . '.'];
    }
    if ($end < $now + 180 * DAY_IN_SECONDS) {
        return ['config.php_eol_soon', 'low', 'PHP ' . $branch . ' stops getting security fixes on ' . $eol . '.'];
    }
    return null;
}

/** Whether automatic core updates are turned off altogether (minor security releases included). */
function core_auto_updates_off(): bool
{
    if (defined('AUTOMATIC_UPDATER_DISABLED') && constant('AUTOMATIC_UPDATER_DISABLED')) {
        return true;
    }
    if (defined('WP_AUTO_UPDATE_CORE') && constant('WP_AUTO_UPDATE_CORE') === false) {
        return true;
    }
    return (bool) apply_filters('automatic_updater_disabled', false) || !(bool) apply_filters('allow_minor_auto_core_updates', true);
}
