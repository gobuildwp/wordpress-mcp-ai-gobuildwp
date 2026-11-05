<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The pre-WordPress firewall: a copy of ../prepend/engine.php under wp-content, loaded through
 * auto_prepend_file from .user.ini (and .htaccess under mod_php), reading a state file the kit
 * rewrites whenever a block, the feed or a setting changes. It is installed by the owner or
 * GoBuildWP Cloud through the protection-prepend ability and removed on deactivation; nothing is
 * written to the site's files unless that was asked for.
 */

const OPT_PREPEND = 'gobuildwp_kit_sp_prepend';
const PREPEND_DIR = 'gobuildwp-protection';
const PREPEND_MARKER = 'GoBuildWP pre-WordPress firewall';
const PREPEND_INI_BEGIN = '; BEGIN GoBuildWP protection';
const PREPEND_INI_END = '; END GoBuildWP protection';
const PREPEND_HT_BEGIN = '# BEGIN GoBuildWP protection';
const PREPEND_HT_END = '# END GoBuildWP protection';
const PREPEND_MAX_INGEST = 200;

/** The protected folder that holds the engine, its state and its event log. */
function prepend_root(): string
{
    $content = defined('WP_CONTENT_DIR') ? (string) constant('WP_CONTENT_DIR') : rtrim(ABSPATH, '/\\') . '/wp-content';
    $root = rtrim(str_replace('\\', '/', $content), '/') . '/' . PREPEND_DIR;
    return (string) apply_filters('gobuildwp_protection_prepend_root', $root);
}

/** The folder whose .user.ini and .htaccess PHP reads for the site: ABSPATH. */
function prepend_site_root(): string
{
    return rtrim((string) apply_filters('gobuildwp_protection_prepend_site_root', str_replace('\\', '/', ABSPATH)), '/');
}

function prepend_engine_source(): string
{
    return dirname(__DIR__) . '/prepend/engine.php';
}

function prepend_engine_path(): string
{
    return prepend_root() . '/firewall.php';
}

function prepend_user_ini_path(): ?string
{
    $name = (string) ini_get('user_ini.filename');
    if ($name === '' || $name === '0') {
        return null;
    }
    return prepend_site_root() . '/' . $name;
}

/** Is the file at $path one of ours? Links and anything else are left alone. */
function prepend_is_ours(string $path): bool
{
    if (is_link($path) || !is_file($path)) {
        return false;
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $head = (string) fread($handle, 2048);
    fclose($handle);
    return str_contains($head, PREPEND_MARKER);
}

/**
 * @return array{method: string, installed_at: int}|null
 */
function prepend_record(): ?array
{
    $r = get_option(OPT_PREPEND, null);
    if (!is_array($r) || !is_string($r['method'] ?? null)) {
        return null;
    }
    return ['method' => $r['method'], 'installed_at' => (int) ($r['installed_at'] ?? 0)];
}

/**
 * @return array{installed: bool, method: string|null, active: bool, problem: string|null}
 */
function prepend_status(): array
{
    $record = prepend_record();
    $installed = $record !== null && prepend_is_ours(prepend_engine_path()) && is_file(prepend_root() . '/state.php');
    $problem = null;
    if ($record !== null && !$installed) {
        $problem = 'The firewall files are missing; install it again.';
    } elseif ($record !== null && !defined('GOBUILDWP_FIREWALL_RAN')) {
        $problem = 'Installed, but this request did not pass through it yet: PHP re-reads .user.ini every few minutes, and some hosts ignore it.';
    }
    return [
        'installed' => $installed,
        'method' => $installed ? $record['method'] : null,
        'active' => defined('GOBUILDWP_FIREWALL_RAN'),
        'problem' => $problem,
    ];
}

/**
 * Install or remove the pre-WordPress firewall.
 *
 * @return array{installed: bool, method: string|null, active: bool, problem: string|null}|WP_Error
 */
function set_prepend(bool $enabled): array|WP_Error
{
    if (!$enabled) {
        $was = prepend_record() !== null;
        remove_prepend();
        if ($was) {
            record_event('settings', 'changed', '', null, '', 'Pre-WordPress firewall removed');
        }
        return prepend_status();
    }
    $problem = install_prepend();
    if ($problem !== null) {
        return new WP_Error('gobuildwp_prepend_unavailable', 'The pre-WordPress firewall cannot be installed: ' . $problem);
    }
    return prepend_status();
}

/**
 * @return string|null What stopped the install, or null when it is in place.
 */
function install_prepend(): ?string
{
    if (defined('DISALLOW_FILE_MODS') && constant('DISALLOW_FILE_MODS')) {
        return 'DISALLOW_FILE_MODS forbids writing files from WordPress.';
    }
    if (function_exists('is_multisite') && is_multisite() && function_exists('is_main_site') && !is_main_site()) {
        return 'on a network it is installed from the main site.';
    }
    $engine = prepend_engine_path();
    $current = (string) ini_get('auto_prepend_file');
    if ($current !== '' && str_replace('\\', '/', $current) !== $engine && !prepend_is_ours($current)) {
        return sprintf('another auto_prepend_file is already in effect (%s).', basename($current));
    }
    $ini = prepend_user_ini_path();
    $apache = PHP_SAPI === 'apache2handler';
    if ($ini === null && !$apache) {
        return 'this server does not read .user.ini files.';
    }
    if ($ini !== null && is_file($ini) && !is_link($ini)) {
        $outside = prepend_strip_block((string) file_get_contents($ini), PREPEND_INI_BEGIN, PREPEND_INI_END);
        if (preg_match('/^\s*auto_prepend_file\s*=/m', $outside) === 1) {
            return 'the site\'s ' . basename($ini) . ' already sets auto_prepend_file.';
        }
    }
    $source = @file_get_contents(prepend_engine_source());
    if (!is_string($source) || !str_contains($source, PREPEND_MARKER)) {
        return 'the engine file is missing from the plugin.';
    }
    $root = prepend_root();
    if (is_link($root) || (!is_dir($root) && !@mkdir($root, 0755, true)) || !is_writable($root)) {
        return 'the folder ' . PREPEND_DIR . ' under wp-content cannot be created.';
    }
    if (!prepend_write($root . '/.htaccess', "# GoBuildWP: nothing in this folder is served.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n")
        || !prepend_write($root . '/index.php', "<?php\n// Silence is golden.\n")) {
        return 'the folder ' . PREPEND_DIR . ' is not writable.';
    }
    if ((file_exists($engine) || is_link($engine)) && !prepend_is_ours($engine)) {
        return 'firewall.php under ' . PREPEND_DIR . ' is not ours.';
    }
    if (!is_file($engine) || hash_file('sha256', $engine) !== hash('sha256', $source)) {
        if (!prepend_write($engine, $source)) {
            return 'the engine could not be written.';
        }
    }
    $method = 'user_ini';
    if ($ini !== null) {
        $body = 'auto_prepend_file = "' . str_replace('"', '', $engine) . '"';
        if (!prepend_replace_block($ini, PREPEND_INI_BEGIN, PREPEND_INI_END, $body)) {
            return basename($ini) . ' in the site root could not be written.';
        }
    }
    if ($apache) {
        $body = "<IfModule mod_php.c>\n    php_value auto_prepend_file \"" . str_replace('"', '', $engine) . "\"\n</IfModule>\n<IfModule mod_php7.c>\n    php_value auto_prepend_file \"" . str_replace('"', '', $engine) . "\"\n</IfModule>";
        if (prepend_replace_block(prepend_site_root() . '/.htaccess', PREPEND_HT_BEGIN, PREPEND_HT_END, $body)) {
            $method = 'htaccess';
        } elseif ($ini === null) {
            return '.htaccess in the site root could not be written.';
        }
    }
    $previous = prepend_record();
    update_option(OPT_PREPEND, ['method' => $method, 'installed_at' => $previous['installed_at'] ?? time()], true);
    if (!sync_prepend_state()) {
        return 'the state file could not be written.';
    }
    if ($previous === null) {
        record_event('settings', 'changed', '', null, '', 'Pre-WordPress firewall installed (' . $method . ')');
    }
    return null;
}

/**
 * Take the firewall out again: the ini and .htaccess blocks, then the folder. Safe to call when
 * nothing is installed. Only files we know are removed; a stranger's file keeps the folder.
 */
function remove_prepend(): bool
{
    $ok = true;
    $ini = prepend_user_ini_path();
    if ($ini !== null && is_file($ini) && !is_link($ini)) {
        $ok = prepend_replace_block($ini, PREPEND_INI_BEGIN, PREPEND_INI_END, null) && $ok;
    }
    $ht = prepend_site_root() . '/.htaccess';
    if (is_file($ht) && !is_link($ht)) {
        $ok = prepend_replace_block($ht, PREPEND_HT_BEGIN, PREPEND_HT_END, null) && $ok;
    }
    $root = prepend_root();
    if (is_dir($root) && !is_link($root)) {
        foreach (['firewall.php', 'state.php', 'state.php.tmp', 'firewall.php.tmp', 'events.log', 'events.log.ingest', '.htaccess', 'index.php', '.htaccess.tmp', 'index.php.tmp'] as $name) {
            $file = $root . '/' . $name;
            if (is_link($file) || is_file($file)) {
                $ok = @unlink($file) && $ok;
            }
        }
        @rmdir($root);
    }
    delete_option(OPT_PREPEND);
    return $ok;
}

/**
 * Rewrite the state file from the current settings, blocks and feed. Nothing happens unless the
 * firewall is installed, so the kit's write paths can call this freely.
 */
function sync_prepend_state(): bool
{
    if (prepend_record() === null) {
        return false;
    }
    $root = prepend_root();
    if (is_link($root) || !is_dir($root)) {
        return false;
    }
    $engine = prepend_engine_path();
    $source = @file_get_contents(prepend_engine_source());
    // A plugin update ships a newer engine; carry it over the next time the state is written.
    if (is_string($source) && str_contains($source, PREPEND_MARKER) && (!is_file($engine) || hash_file('sha256', $engine) !== hash('sha256', $source)) && (!file_exists($engine) || prepend_is_ours($engine))) {
        prepend_write($engine, $source);
    }
    $state = prepend_state();
    $php = "<?php\n// " . PREPEND_MARKER . ": state written by the plugin. Do not edit.\nreturn " . var_export($state, true) . ";\n";
    return prepend_write($root . '/state.php', $php);
}

/**
 * Everything the engine needs, as plain values.
 *
 * @return array<string, mixed>
 */
function prepend_state(?int $now = null): array
{
    $now ??= time();
    $settings = settings();
    $blocks = [];
    foreach (blocks($now) as $b) {
        $blocks[] = ['ip' => $b['ip'], 'source' => $b['source'], 'expires_at' => $b['expires_at']];
    }
    $blocklist = [];
    foreach (feed_blocklist($now) as $e) {
        $blocklist[] = ['ip' => $e['ip'], 'expires_at' => $e['expires_at']];
    }
    $rules = [];
    if ($settings['firewall'] === 'block') {
        foreach (array_merge(rules(), feed_rules()) as $r) {
            $rules[] = ['id' => $r['id'], 'in' => $r['in'], 'pattern' => $r['pattern'], 'label' => $r['label']];
        }
    }
    return [
        'enabled' => !(defined('GOBUILDWP_DISABLE_PROTECTION') && constant('GOBUILDWP_DISABLE_PROTECTION') === true),
        'written_at' => $now,
        'ip_source' => $settings['ip_source'],
        'trusted_proxies' => trusted_proxies(),
        'cloudflare_ranges' => cloudflare_ranges(),
        'allowlist' => array_values($settings['allowlist']),
        'firewall' => $settings['firewall'],
        'xmlrpc' => $settings['xmlrpc'],
        'blocks' => $blocks,
        'blocklist' => $blocklist,
        'rules' => $rules,
    ];
}

/**
 * Turn the engine's log lines into the kit's events, so blocks made before WordPress show up in
 * the protection events and counters. The log is renamed first, so a line the engine appends
 * meanwhile lands in a fresh file.
 */
function ingest_prepend_events(?int $now = null): int
{
    $root = prepend_root();
    $log = $root . '/events.log';
    if (is_link($log) || !is_file($log)) {
        return 0;
    }
    $batch = $root . '/events.log.ingest';
    if (is_link($batch) || !@rename($log, $batch)) {
        return 0;
    }
    $lines = @file($batch, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    @unlink($batch);
    if (!is_array($lines)) {
        return 0;
    }
    $count = 0;
    foreach (array_slice($lines, -PREPEND_MAX_INGEST) as $line) {
        $e = json_decode($line, true);
        if (!is_array($e) || !is_string($e['type'] ?? null) || !in_array($e['type'], EVENT_TYPES, true)) {
            continue;
        }
        $at = is_int($e['at'] ?? null) ? $e['at'] : ($now ?? time());
        $ref = is_string($e['id'] ?? null) ? $e['id'] : '';
        record_event(
            $e['type'],
            'blocked',
            is_string($e['ip'] ?? null) ? $e['ip'] : '',
            is_string($e['rule'] ?? null) ? $e['rule'] : null,
            is_string($e['path'] ?? null) ? $e['path'] : '',
            (is_string($e['detail'] ?? null) ? $e['detail'] : '') . ' (before WordPress' . ($ref !== '' ? ', ref ' . $ref : '') . ')',
            $at,
        );
        $count++;
    }
    return $count;
}

// ---------------------------------------------------------------- file helpers

/** Write beside the target and rename over it, so a reader never sees half a file. */
function prepend_write(string $path, string $content): bool
{
    if (is_link($path)) {
        return false;
    }
    $tmp = $path . '.tmp';
    if (is_link($tmp)) {
        return false;
    }
    if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content) || !@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }
    return true;
}

/** The file's content without our marked block. */
function prepend_strip_block(string $content, string $begin, string $end): string
{
    $pattern = '/[ \t]*' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '[ \t]*(?:\r?\n|$)/s';
    return (string) preg_replace($pattern, '', $content);
}

/**
 * Put our marked block into $file (creating it), replace the one already there, or with a null
 * body take it out. The rest of the file is kept as it was.
 */
function prepend_replace_block(string $file, string $begin, string $end, ?string $body): bool
{
    if (is_link($file)) {
        return false;
    }
    $content = is_file($file) ? (string) file_get_contents($file) : '';
    $kept = prepend_strip_block($content, $begin, $end);
    if ($body === null) {
        if ($kept === $content) {
            return true;
        }
        if (trim($kept) === '') {
            return @unlink($file);
        }
        return @file_put_contents($file, $kept, LOCK_EX) !== false;
    }
    $block = $begin . "\n" . $body . "\n" . $end . "\n";
    $next = $kept === '' ? $block : rtrim($kept, "\r\n") . "\n\n" . $block;
    if ($next === $content) {
        return true;
    }
    return @file_put_contents($file, $next, LOCK_EX) !== false;
}
