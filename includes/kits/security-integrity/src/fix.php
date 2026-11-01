<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Security fixes for what a scan reported, each one undoable: quarantine a file, replace a
 * modified or missing core or plugin file with WordPress.org's copy, cut injected code out of a
 * file, clean or remove an option, remove scheduled events, clean a post's content, demote an
 * administrator account, unpublish a post. Every fix is recorded in FIX_OPTION with what undo
 * needs; nothing is deleted without a copy kept first.
 * Quarantined files keep their bytes in a folder the web server refuses to serve, under a name
 * with no PHP extension.
 */

const FIX_OPTION = 'gobuildwp_kit_si_fixes';
const FIX_KEEP = 200;
const QUARANTINE_OPTION = 'gobuildwp_kit_si_quarantine_dir';
const FIX_MAX_BYTES = 20 * 1024 * 1024;
const FIX_BATCH = 25;
const FIX_ACTIONS = ['quarantine_file', 'repair_core_file', 'demote_user', 'unpublish_post', 'repair_plugin_file', 'clean_file', 'clean_option', 'remove_cron', 'clean_post'];
const CORE_SOURCE_URL = 'https://core.svn.wordpress.org/tags/%s/%s';
/** Slug, then `tags/{version}` or `trunk`, then the path inside the plugin. */
const PLUGIN_SOURCE_URL = 'https://plugins.svn.wordpress.org/%s/%s/%s';
/** Slug, version, path inside the theme. */
const THEME_SOURCE_URL = 'https://themes.svn.wordpress.org/%s/%s/%s';

/** Options clean_option never touches: the site breaks or its access changes without them. */
const FIX_PROTECTED_OPTIONS = ['siteurl', 'home', 'active_plugins', 'cron', 'template', 'stylesheet', 'admin_email', 'users_can_register', 'default_role', 'rewrite_rules', 'wp_user_roles'];
const FIX_PROTECTED_OPTION_PREFIXES = ['gobuildwp_', 'wppilot_'];

/** Files whose loss breaks the site or leaks secrets; never quarantined, whatever a scan said. */
const FIX_PROTECTED = ['wp-config.php', 'wp-settings.php', 'wp-load.php', 'wp-blog-header.php', 'index.php', '.htaccess'];

/** @return list<array<string, mixed>> */
function fixes(): array
{
    /** @var mixed $stored */
    $stored = get_option(FIX_OPTION, []);
    return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
}

/** @param list<array<string, mixed>> $list */
function save_fixes(array $list): void
{
    // Oldest undone records go first; applied ones are kept so they stay undoable.
    while (count($list) > FIX_KEEP) {
        $drop = null;
        foreach ($list as $i => $f) {
            if (($f['state'] ?? '') !== 'applied') {
                $drop = $i;
                break;
            }
        }
        array_splice($list, $drop ?? 0, 1);
    }
    update_option(FIX_OPTION, array_values($list), autoload: false);
}

/** @return array<string, mixed>|null */
function find_fix(string $id): ?array
{
    foreach (fixes() as $f) {
        if (($f['id'] ?? '') === $id) {
            return $f;
        }
    }
    return null;
}

/** @param array<string, mixed> $fix */
function put_fix(array $fix): void
{
    $list = fixes();
    foreach ($list as $i => $f) {
        if (($f['id'] ?? '') === $fix['id']) {
            $list[$i] = $fix;
            save_fixes($list);
            return;
        }
    }
    $list[] = $fix;
    save_fixes($list);
}

function fix_error(string $code, string $message): \WP_Error
{
    return new \WP_Error('gobuildwp_security_fix_' . $code, $message, ['status' => 400]);
}

/**
 * The quarantine folder under wp-content, created on first use with deny rules for Apache,
 * LiteSpeed, IIS and an index file. Its name carries a random part so it can't be guessed.
 */
function quarantine_dir(): string|\WP_Error
{
    /** @var mixed $name */
    $name = get_option(QUARANTINE_OPTION, '');
    if (!is_string($name) || preg_match('/^gobuildwp-quarantine-[a-z0-9]{16}$/', $name) !== 1) {
        $name = 'gobuildwp-quarantine-' . bin2hex(random_bytes(8));
        update_option(QUARANTINE_OPTION, $name, autoload: false);
    }
    $dir = content_root() . '/' . $name;
    if (!make_dir($dir)) {
        return fix_error('quarantine_unavailable', 'The quarantine folder could not be created in wp-content.');
    }
    $guards = [
        '.htaccess' => "Require all denied\nDeny from all\n",
        'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
        'index.php' => "<?php\n// Silence.\n",
    ];
    foreach ($guards as $file => $body) {
        if (!is_file($dir . '/' . $file) && file_put_contents($dir . '/' . $file, $body) === false) {
            return fix_error('quarantine_unavailable', 'The quarantine folder could not be protected.');
        }
    }
    return $dir;
}

/** wp-content: a roots() override named 'content', else WP_CONTENT_DIR. */
function content_root(): string
{
    $set = roots();
    if (isset($set['content'])) {
        return normalize((string) $set['content']);
    }
    return normalize(defined('WP_CONTENT_DIR') ? (string) constant('WP_CONTENT_DIR') : rtrim((string) $set['abspath'], '/') . '/wp-content');
}

function make_dir(string $dir): bool
{
    return is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir);
}

/**
 * A site-relative path checked for a fix: no traversal, no symlink, an existing regular file
 * inside the WordPress folder (wp-content/... resolves to the real wp-content when that lives
 * elsewhere), not in quarantine.
 *
 * @return array{rel: string, abs: string}|\WP_Error
 */
function fix_path(string $path, bool $must_exist = true): array|\WP_Error
{
    $rel = ltrim(str_replace('\\', '/', trim($path)), '/');
    if ($rel === '' || strlen($rel) > 400 || str_contains($rel, "\0") || has_parent_segment($rel)) {
        return fix_error('bad_path', 'path must be a file path relative to the WordPress folder.');
    }
    $root = normalize((string) roots()['abspath']);
    $abs = $root . '/' . $rel;
    if (str_starts_with($rel, 'wp-content/')) {
        $root = content_root();
        $abs = $root . '/' . substr($rel, strlen('wp-content/'));
    }
    // Plugins and themes may live outside wp-content (a custom WP_PLUGIN_DIR, a registered theme root).
    foreach (component_roots() as $prefix => [, $dir]) {
        if (str_starts_with($rel, $prefix . '/')) {
            $root = $dir;
            $abs = $dir . '/' . substr($rel, strlen($prefix) + 1);
            break;
        }
    }
    $quarantine = get_option(QUARANTINE_OPTION, '');
    if (is_string($quarantine) && $quarantine !== '' && str_starts_with($rel, 'wp-content/' . $quarantine . '/')) {
        return fix_error('bad_path', 'That file is in quarantine.');
    }
    if (is_link($abs)) {
        return fix_error('bad_path', 'That path is a symbolic link.');
    }
    if (!$must_exist && !file_exists($abs)) {
        // A missing core file: its folder must still sit inside the site.
        $parent = dirname($abs);
        while (!is_dir($parent) && strlen($parent) > strlen($root)) {
            $parent = dirname($parent);
        }
        return guard($parent, $root) === 'ok' ? ['rel' => $rel, 'abs' => $abs] : fix_error('bad_path', 'That path is outside the site.');
    }
    $guard = guard($abs, $root);
    if ($guard !== 'ok') {
        return fix_error('bad_path', $guard === 'missing' ? 'That file does not exist.' : 'That path is outside the site or a symbolic link.');
    }
    if (!is_file($abs)) {
        return fix_error('bad_path', 'That path is not a file.');
    }
    return ['rel' => $rel, 'abs' => $abs];
}

/**
 * Where plugin and theme paths really live: site-relative prefix => [type, folder]. Both the
 * usual wp-content/plugins spelling and the folder's own name under the site are accepted.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function component_roots(): array
{
    $map = [];
    foreach (['plugins' => (string) roots()['plugins'], 'themes' => themes_root()] as $type => $dir) {
        if ($dir === '') {
            continue;
        }
        $dir = normalize($dir);
        $map['wp-content/' . $type] = [$type, $dir];
        $map[root_name($dir, 'wp-content/' . $type)] = [$type, $dir];
    }
    return $map;
}

/**
 * The plugin or theme a site-relative path belongs to.
 *
 * @return array{0: string, 1: string, 2: string}|null type ('plugins' or 'themes'), folder, path inside the folder
 */
function component_of(string $rel): ?array
{
    foreach (component_roots() as $prefix => [$type]) {
        if (str_starts_with($rel, $prefix . '/')) {
            $rest = substr($rel, strlen($prefix) + 1);
            $cut = strpos($rest, '/');
            return $cut === false || $cut === 0 ? null : [$type, substr($rest, 0, $cut), substr($rest, $cut + 1)];
        }
    }
    return null;
}

/** Whether a relative path is in core's own tree (root files, wp-admin, wp-includes). */
function is_core_area(string $rel): bool
{
    return str_starts_with($rel, 'wp-admin/') || str_starts_with($rel, 'wp-includes/') || !str_contains($rel, '/');
}

/** @return array<string, string>|null Core checksums for this site's version, path => md5. */
function fix_core_sums(): ?array
{
    $limits = budgets();
    $limits['fetches'] = 4;
    $sums = core_checksums((string) get_bloginfo('version'), (string) get_locale(), new Budget($limits, 30));
    return is_array($sums) ? $sums : null;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|\WP_Error
 */
function apply_fix(array $input): array|\WP_Error
{
    $action = (string) ($input['action'] ?? '');
    $target = trim((string) ($input['target'] ?? ''));
    if (!in_array($action, FIX_ACTIONS, strict: true) || $target === '') {
        return fix_error('bad_input', 'action and target are required.');
    }
    $fix = [
        'id' => wp_generate_uuid4(),
        'action' => $action,
        'target' => mb_substr($target, 0, 400),
        'state' => 'applied',
        'applied_at' => time(),
        'applied_by' => get_current_user_id(),
        'reason' => mb_substr(sanitize_text_field((string) ($input['reason'] ?? '')), 0, 200),
    ];
    $done = match ($action) {
        'quarantine_file' => quarantine_file($target),
        'repair_core_file' => repair_core_file($target),
        'demote_user' => demote_user($target),
        'unpublish_post' => unpublish_post($target),
        'repair_plugin_file' => repair_plugin_file($target),
        'clean_file' => clean_file($target),
        'clean_option' => clean_option($target),
        'remove_cron' => remove_cron($target),
        'clean_post' => clean_post($target),
    };
    if (is_wp_error($done)) {
        return $done;
    }
    $fix['undo'] = $done['undo'];
    put_fix($fix);
    return ['fix_id' => $fix['id'], 'action' => $action, 'target' => $fix['target'], 'state' => 'applied', 'detail' => $done['detail']];
}

/** @return array{undo: array<string, mixed>, detail: array<string, mixed>}|\WP_Error */
function quarantine_file(string $path): array|\WP_Error
{
    $p = fix_path($path);
    if (is_wp_error($p)) {
        return $p;
    }
    if (in_array(basename($p['rel']), FIX_PROTECTED, strict: true) && !str_contains($p['rel'], '/uploads/')) {
        return fix_error('protected', 'This file is needed for WordPress to run and can\'t be quarantined. Use repair_core_file for a modified core file.');
    }
    if (is_core_area($p['rel'])) {
        $sums = fix_core_sums();
        if ($sums === null) {
            return fix_error('no_checksums', 'WordPress.org checksums for this version could not be loaded, so a core-area file can\'t be judged.');
        }
        if (isset($sums[$p['rel']])) {
            return fix_error('core_file', 'This is a WordPress core file. Use repair_core_file to replace it with the official copy.');
        }
    }
    $size = (int) filesize($p['abs']);
    if ($size > FIX_MAX_BYTES) {
        return fix_error('too_large', 'The file is larger than 20 MB.');
    }
    $dir = quarantine_dir();
    if (is_wp_error($dir)) {
        return $dir;
    }
    $sha = (string) hash_file('sha256', $p['abs']);
    $stored = wp_generate_uuid4() . '.quarantined';
    $perms = fileperms($p['abs']) & 0777;
    if (!@rename($p['abs'], $dir . '/' . $stored)) {
        return fix_error('move_failed', 'The file could not be moved. Check the folder\'s permissions.');
    }
    @chmod($dir . '/' . $stored, 0600);
    return [
        'undo' => ['path' => $p['rel'], 'stored' => $stored, 'sha256' => $sha, 'size' => $size, 'perms' => $perms],
        'detail' => ['path' => $p['rel'], 'sha256' => $sha, 'size' => $size],
    ];
}

/** @return array{undo: array<string, mixed>, detail: array<string, mixed>}|\WP_Error */
function repair_core_file(string $path): array|\WP_Error
{
    $p = fix_path($path, must_exist: false);
    if (is_wp_error($p)) {
        return $p;
    }
    if (!is_core_area($p['rel']) || $p['rel'] === 'wp-config.php') {
        return fix_error('not_core', 'Only WordPress core files (root, wp-admin, wp-includes) can be repaired.');
    }
    $sums = fix_core_sums();
    if ($sums === null || !isset($sums[$p['rel']])) {
        return fix_error('not_core', 'This path is not in WordPress.org\'s file list for this version.');
    }
    $expected = $sums[$p['rel']];
    $exists = is_file($p['abs']);
    if ($exists && md5_file($p['abs']) === $expected) {
        return fix_error('unchanged', 'The file already matches WordPress.org\'s copy.');
    }
    $version = (string) get_bloginfo('version');
    $url = sprintf(CORE_SOURCE_URL, rawurlencode($version), implode('/', array_map('rawurlencode', explode('/', $p['rel']))));
    $res = wp_remote_get($url, ['timeout' => 20, 'limit_response_size' => FIX_MAX_BYTES, 'redirection' => 2, 'reject_unsafe_urls' => true]);
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
        return fix_error('download_failed', 'The official file could not be downloaded from WordPress.org.');
    }
    $body = (string) wp_remote_retrieve_body($res);
    if (md5($body) !== $expected) {
        return fix_error('checksum_mismatch', 'The downloaded file does not match WordPress.org\'s checksum, so nothing was changed.');
    }
    $undo = replace_file($p, $body);
    if (is_wp_error($undo)) {
        return $undo;
    }
    return ['undo' => $undo, 'detail' => ['path' => $p['rel'], 'md5' => $expected, 'was_missing' => !$exists]];
}

/**
 * Write new bytes over a file, keeping the current copy in quarantine for undo: written to a
 * temporary name beside it and renamed into place, so a failed write never leaves half a file.
 *
 * @param array{rel: string, abs: string} $p
 * @return array<string, mixed>|\WP_Error the undo record
 */
function replace_file(array $p, string $body): array|\WP_Error
{
    $exists = is_file($p['abs']);
    $undo = ['path' => $p['rel'], 'stored' => null, 'existed' => $exists];
    if ($exists) {
        $dir = quarantine_dir();
        if (is_wp_error($dir)) {
            return $dir;
        }
        $stored = wp_generate_uuid4() . '.quarantined';
        if (!@copy($p['abs'], $dir . '/' . $stored)) {
            return fix_error('backup_failed', 'The current file could not be kept for undo, so nothing was changed.');
        }
        @chmod($dir . '/' . $stored, 0600);
        $undo['stored'] = $stored;
        $undo['sha256'] = (string) hash_file('sha256', $p['abs']);
        $undo['perms'] = fileperms($p['abs']) & 0777;
    } elseif (!make_dir(dirname($p['abs']))) {
        return fix_error('write_failed', 'The folder for this file could not be created.');
    }
    $tmp = $p['abs'] . '.gobuildwp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $body) !== strlen($body) || !@rename($tmp, $p['abs'])) {
        @unlink($tmp);
        return fix_error('write_failed', 'The new file could not be written. Check the folder\'s permissions.');
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($p['abs'], true);
    }
    $undo['written_sha256'] = hash('sha256', $body);
    return $undo;
}

/**
 * The WordPress.org file list entry for a file inside a plugin folder.
 *
 * @return array{slug: string, version: string, inner: string, expected: list<string>}|\WP_Error
 */
function official_plugin_file(string $rel): array|\WP_Error
{
    $component = component_of($rel);
    if ($component === null || $component[0] !== 'plugins') {
        return fix_error('not_plugin', 'path must be a file inside a plugin folder (wp-content/plugins/{folder}/...).');
    }
    [, $folder, $inner] = $component;
    foreach (plugin_list() as [, $plugin_folder, $version, $wporg]) {
        if ($plugin_folder !== $folder) {
            continue;
        }
        if (!$wporg || $version === '') {
            return fix_error('not_official', 'This plugin is not from WordPress.org, so there is no official copy to compare with.');
        }
        $limits = budgets();
        $limits['fetches'] = 4;
        $sums = plugin_checksums($folder, $version, new Budget($limits, 30));
        if ($sums === false || $sums === []) {
            return fix_error('no_checksums', 'WordPress.org checksums for this plugin version could not be loaded.');
        }
        if (!isset($sums[$inner]) || $sums[$inner] === []) {
            return fix_error('not_official', 'This file is not in the plugin\'s official file list for this version.');
        }
        return ['slug' => $folder, 'version' => $version, 'inner' => $inner, 'expected' => array_values(array_map('strval', $sums[$inner]))];
    }
    return fix_error('not_plugin', 'No installed plugin uses that folder.');
}

/**
 * The first URL whose body passes $accept. Refused bodies end in checksum_mismatch, failed
 * downloads in download_failed.
 *
 * @param list<string> $urls
 * @param callable(string): bool $accept
 */
function fetch_official(array $urls, callable $accept): string|\WP_Error
{
    $refused = false;
    foreach ($urls as $url) {
        $res = wp_remote_get($url, ['timeout' => 20, 'limit_response_size' => FIX_MAX_BYTES, 'redirection' => 2, 'reject_unsafe_urls' => true]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            continue;
        }
        $body = (string) wp_remote_retrieve_body($res);
        if ($accept($body)) {
            return $body;
        }
        $refused = true;
    }
    return $refused
        ? fix_error('checksum_mismatch', 'The downloaded file does not match WordPress.org\'s copy, so nothing was changed.')
        : fix_error('download_failed', 'The official file could not be downloaded from WordPress.org.');
}

function url_path(string $path): string
{
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

/**
 * Put WordPress.org's copy of a plugin file in place: the plugin's tag for the installed version,
 * else trunk, accepted only when its sha256 is in the official list.
 *
 * @param array{rel: string, abs: string} $p
 * @param array{slug: string, version: string, inner: string, expected: list<string>} $info
 * @return array{undo: array<string, mixed>, detail: array<string, mixed>}|\WP_Error
 */
function replace_from_plugin(array $p, array $info): array|\WP_Error
{
    $exists = is_file($p['abs']);
    if ($exists && in_array(hash_file('sha256', $p['abs']), $info['expected'], strict: true)) {
        return fix_error('unchanged', 'The file already matches WordPress.org\'s copy.');
    }
    if ($exists && (int) filesize($p['abs']) > FIX_MAX_BYTES) {
        return fix_error('too_large', 'The file is larger than 20 MB.');
    }
    $slug = rawurlencode($info['slug']);
    $inner = url_path($info['inner']);
    $body = fetch_official([
        sprintf(PLUGIN_SOURCE_URL, $slug, 'tags/' . rawurlencode($info['version']), $inner),
        sprintf(PLUGIN_SOURCE_URL, $slug, 'trunk', $inner),
    ], static fn(string $body): bool => in_array(hash('sha256', $body), $info['expected'], strict: true));
    if (is_wp_error($body)) {
        return $body;
    }
    $undo = replace_file($p, $body);
    if (is_wp_error($undo)) {
        return $undo;
    }
    return ['undo' => $undo, 'detail' => ['path' => $p['rel'], 'plugin' => $info['slug'], 'version' => $info['version'], 'sha256' => $undo['written_sha256'], 'was_missing' => !$exists]];
}

/** @return array{undo: array<string, mixed>, detail: array<string, mixed>}|\WP_Error */
function repair_plugin_file(string $path): array|\WP_Error
{
    $p = fix_path($path, must_exist: false);
    if (is_wp_error($p)) {
        return $p;
    }
    $info = official_plugin_file($p['rel']);
    return is_wp_error($info) ? $info : replace_from_plugin($p, $info);
}

/**
 * A theme WordPress.org serves updates for (listed in the update_themes transient) and its
 * installed version.
 *
 * @return array{slug: string, version: string}|null
 */
function official_theme(string $stylesheet): ?array
{
    /** @var mixed $updates */
    $updates = get_site_transient('update_themes');
    if (!is_object($updates)) {
        return null;
    }
    $listed = false;
    foreach (['response', 'no_update'] as $list) {
        $entries = isset($updates->{$list}) && is_array($updates->{$list}) ? $updates->{$list} : [];
        $listed = $listed || isset($entries[$stylesheet]);
    }
    if (!$listed) {
        return null;
    }
    $version = isset($updates->checked) && is_array($updates->checked) ? (string) ($updates->checked[$stylesheet] ?? '') : '';
    if ($version === '' && function_exists('wp_get_themes')) {
        $theme = wp_get_themes()[$stylesheet] ?? null;
        $version = is_object($theme) && method_exists($theme, 'get') ? (string) $theme->get('Version') : '';
    }
    return preg_match('/^[0-9A-Za-z._-]{1,40}$/', $version) === 1 ? ['slug' => $stylesheet, 'version' => $version] : null;
}

/**
 * Remove injected code from a file and keep everything else. In order:
 *  (a) a core file is refused (repair_core_file replaces it);
 *  (b) a file of a WordPress.org plugin that is in its official list is replaced with that copy;
 *  (c) a file of a WordPress.org theme is replaced with the theme's copy when that copy is clean;
 *  (d) in PHP, the injected statements and blocks are cut out and the rest checked: no serious hit
 *      may remain and the file must still parse;
 *  (e) when nothing but the injection was there, the file is quarantined instead.
 * Anything else is refused with cannot_isolate.
 *
 * @return array{undo: array<string, mixed>, detail: array<string, mixed>}|\WP_Error
 */
function clean_file(string $path): array|\WP_Error
{
    $p = fix_path($path);
    if (is_wp_error($p)) {
        return $p;
    }
    if (is_core_area($p['rel']) && $p['rel'] !== 'wp-config.php') {
        $sums = fix_core_sums();
        if ($sums === null) {
            return fix_error('no_checksums', 'WordPress.org checksums for this version could not be loaded, so a core-area file can\'t be judged.');
        }
        if (isset($sums[$p['rel']])) {
            return fix_error('use_repair_core', 'This is a WordPress core file. Use repair_core_file to replace it with the official copy.');
        }
    }
    if ((int) filesize($p['abs']) > FIX_MAX_BYTES) {
        return fix_error('too_large', 'The file is larger than 20 MB.');
    }
    $component = component_of($p['rel']);
    if ($component !== null && $component[0] === 'plugins') {
        $info = official_plugin_file($p['rel']);
        if (!is_wp_error($info)) {
            $done = replace_from_plugin($p, $info);
            if (!is_wp_error($done)) {
                $done['detail'] += ['mode' => 'replaced_official'];
            }
            return $done;
        }
    }
    $code = (string) file_get_contents($p['abs']);
    $is_php = is_php_name(basename($p['rel']));
    if (serious_hits($code, $is_php) === []) {
        return fix_error('unchanged', 'No injected code was found in this file.');
    }
    $theme = $component !== null && $component[0] === 'themes' ? official_theme($component[1]) : null;
    if ($theme !== null) {
        $body = fetch_official(
            [sprintf(THEME_SOURCE_URL, rawurlencode($theme['slug']), rawurlencode($theme['version']), url_path($component[2]))],
            static fn(string $body): bool => serious_hits($body, $is_php) === [],
        );
        if (is_string($body)) {
            $undo = replace_file($p, $body);
            if (is_wp_error($undo)) {
                return $undo;
            }
            return ['undo' => $undo, 'detail' => ['path' => $p['rel'], 'mode' => 'replaced_official', 'theme' => $theme['slug'], 'version' => $theme['version'], 'sha256' => $undo['written_sha256']]];
        }
    }
    $stripped = $is_php ? strip_injected($code) : null;
    $isolated = $stripped !== null && meaningful_php($stripped['code']) && serious_hits($stripped['code']) === [] && valid_php($stripped['code']);
    if (!$isolated) {
        // Nothing left but the injection, or a whole file of malware (a dropped web shell): quarantine
        // it, unless the plugin or theme can't load without it.
        if (($stripped !== null && !meaningful_php($stripped['code'])) || (whole_file_malware($code, $is_php) && !component_entry($p['rel'], $code))) {
            $done = quarantine_file($p['rel']);
            if (!is_wp_error($done)) {
                $done['detail'] += ['mode' => 'quarantined'];
            }
            return $done;
        }
        return fix_error('cannot_isolate', 'The injected code could not be cut out without breaking the file. Quarantine it or restore it from a clean copy.');
    }
    $undo = replace_file($p, $stripped['code']);
    if (is_wp_error($undo)) {
        return $undo;
    }
    return ['undo' => $undo, 'detail' => ['path' => $p['rel'], 'mode' => 'stripped', 'lines_removed' => $stripped['lines'], 'sha256' => $undo['written_sha256']]];
}

/**
 * A file that is malware as a whole rather than a good file with something injected: a known web
 * shell, or a dropper of at most three lines with a critical-grade hit.
 */
function whole_file_malware(string $code, bool $is_php): bool
{
    $lines = count(array_filter(preg_split('/
||
/', $code) ?: [], static fn(string $l): bool => trim($l) !== '' && trim($l) !== '<?php'));
    foreach (serious_hits($code, $is_php) as $hit) {
        if ($hit['rule_id'] === 'susp.webshell_marker' || ($hit['severity_hint'] === 'critical' && $lines <= 3)) {
            return true;
        }
    }
    return false;
}

/** Whether the file is one its plugin or theme loads by name: a plugin header, a theme's functions.php or index.php. */
function component_entry(string $rel, string $code): bool
{
    $base = strtolower(basename($rel));
    if ((component_of($rel)[0] ?? '') === 'themes' && in_array($base, ['functions.php', 'index.php'], strict: true)) {
        return true;
    }
    return preg_match('/^[ 	\/*#@]*(Plugin|Theme) Name:/mi', substr($code, 0, 8192)) === 1;
}

/**
 * Cut injected code out of PHP source: whole lines with a serious hit that hold one complete
 * statement, then a leading `<?php … ?>` block with a hit that another `<?php` follows, a trailing
 * block with a hit after the file's other code, and text with a hit after the last `?>`. Whole
 * blocks are only cut when short, so a file's own code is never taken as the injection.
 *
 * @return array{code: string, lines: int}|null null when nothing could be cut
 */
function strip_injected(string $code): ?array
{
    $removed = 0;
    // Pass 1: single-statement lines; a line that opens a PHP block keeps its tag.
    $starts = line_starts($code);
    $lines = preg_split('/(?<=\n)/', $code, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $not_code = [T_INLINE_HTML, T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC, T_END_HEREDOC, T_CLOSE_TAG, T_OPEN_TAG_WITH_ECHO];
    foreach ($lines as $index => $line) {
        $first = $starts[$index + 1] ?? null;
        if ($first === null || in_array($first, $not_code, strict: true)) {
            continue;
        }
        $prefix = '';
        $statement = $line;
        if ($first === T_OPEN_TAG) {
            if (preg_match('/^(\s*<\?php)(\s.*)$/s', $line, $m) !== 1) {
                continue;
            }
            [, $prefix, $statement] = $m;
        }
        if (serious_hits($statement) === [] || !single_statement($statement)) {
            continue;
        }
        $lines[$index] = $prefix === '' ? '' : $prefix . (str_ends_with($line, "\n") ? "\n" : '');
        $removed++;
    }
    $code = implode('', $lines);
    // Pass 2: a short leading block that another block follows.
    $blocks = php_blocks($code);
    if (count($blocks) >= 2 && $blocks[0][0] === 0) {
        $end = $blocks[0][1];
        $text = substr($code, 0, $end);
        if (short_block($text) && serious_hits($text) !== []) {
            $next = $blocks[1][0];
            $cut = trim(substr($code, $end, $next - $end)) === '' ? $next : $end;
            $removed += block_lines(substr($code, 0, $cut));
            $code = substr($code, $cut);
            $blocks = php_blocks($code);
        }
    }
    // Pass 3: text with a hit after the last closing tag.
    $end = $blocks === [] ? 0 : $blocks[count($blocks) - 1][1];
    $tail = substr($code, $end);
    if ($blocks !== [] && trim($tail) !== '' && serious_hits($tail) !== []) {
        $removed += block_lines($tail);
        $code = substr($code, 0, $end);
    }
    // Pass 4: a short last block with a hit, after the file's other code.
    if (count($blocks) >= 2) {
        [$start, $end] = $blocks[count($blocks) - 1];
        $text = substr($code, $start, $end - $start);
        if (short_block($text) && serious_hits($text) !== [] && trim(substr($code, $end)) === '') {
            $removed += block_lines(substr($code, $start));
            $code = substr($code, 0, $start);
        }
    }
    return $removed > 0 ? ['code' => $code, 'lines' => $removed] : null;
}

/**
 * The first token other than whitespace that starts on each line, by line number. Lines inside a
 * token that began earlier (a comment, a string) are absent.
 *
 * @return array<int, int|string>
 */
function line_starts(string $code): array
{
    $first = [];
    $line = 1;
    foreach (token_get_all($code) as $token) {
        $id = is_array($token) ? $token[0] : $token;
        if ($id !== T_WHITESPACE) {
            $first[$line] ??= $id;
        }
        $line += substr_count(is_array($token) ? $token[1] : $token, "\n");
    }
    return $first;
}

/**
 * PHP blocks as byte ranges: each `<?php` to just after its `?>`, or to the end of the file.
 *
 * @return list<array{0: int, 1: int}>
 */
function php_blocks(string $code): array
{
    $blocks = [];
    $offset = 0;
    $open = null;
    foreach (token_get_all($code) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        if (is_array($token) && ($token[0] === T_OPEN_TAG || $token[0] === T_OPEN_TAG_WITH_ECHO)) {
            $open = $offset;
        } elseif (is_array($token) && $token[0] === T_CLOSE_TAG && $open !== null) {
            $blocks[] = [$open, $offset + strlen($text)];
            $open = null;
        }
        $offset += strlen($text);
    }
    if ($open !== null) {
        $blocks[] = [$open, strlen($code)];
    }
    return $blocks;
}

/** A block short enough to be an injection rather than the file's own code: at most 10 non-blank lines. */
function short_block(string $text): bool
{
    return count(array_filter(preg_split('/\r\n|\r|\n/', $text) ?: [], static fn(string $l): bool => trim($l) !== '')) <= 10;
}

function block_lines(string $text): int
{
    return max(1, substr_count(rtrim($text, "\r\n"), "\n") + 1);
}

/** Whether one line of PHP is exactly one complete statement: it parses alone and ends at its only top-level `;`. */
function single_statement(string $line): bool
{
    $line = trim($line);
    if ($line === '' || str_contains($line, '<?') || str_contains($line, '?>')) {
        return false;
    }
    try {
        $tokens = token_get_all('<?php ' . $line, TOKEN_PARSE);
    } catch (\ParseError | \CompileError) {
        return false;
    }
    $depth = 0;
    $ends = 0;
    $last = null;
    foreach ($tokens as $token) {
        $id = is_array($token) ? $token[0] : $token;
        if (in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], strict: true)) {
            continue;
        }
        if (in_array($id, ['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], strict: true)) {
            $depth++;
        } elseif (in_array($id, [')', ']', '}'], strict: true)) {
            $depth--;
        } elseif ($id === ';' && $depth === 0) {
            $ends++;
        }
        $last = $id;
    }
    return $ends === 1 && $last === ';';
}

/** Whether PHP source still parses. */
function valid_php(string $code): bool
{
    try {
        token_get_all($code, TOKEN_PARSE);
        return true;
    } catch (\ParseError | \CompileError) {
        return false;
    }
}

/** Whether anything but tags, comments and whitespace is left. */
function meaningful_php(string $code): bool
{
    foreach (token_get_all($code) as $token) {
        if (!is_array($token)) {
            return true;
        }
        if (in_array($token[0], [T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], strict: true)) {
            continue;
        }
        if ($token[0] === T_INLINE_HTML && trim($token[1]) === '') {
            continue;
        }
        return true;
    }
    return false;
}

/**
 * Keep bytes in the quarantine folder for undo.
 *
 * @return array{stored: string, sha256: string}|\WP_Error
 */
function keep_copy(string $bytes): array|\WP_Error
{
    $dir = quarantine_dir();
    if (is_wp_error($dir)) {
        return $dir;
    }
    $stored = wp_generate_uuid4() . '.quarantined';
    if (file_put_contents($dir . '/' . $stored, $bytes) !== strlen($bytes)) {
        @unlink($dir . '/' . $stored);
        return fix_error('backup_failed', 'The current value could not be kept for undo, so nothing was changed.');
    }
    @chmod($dir . '/' . $stored, 0600);
    return ['stored' => $stored, 'sha256' => hash('sha256', $bytes)];
}

/**
 * Bytes kept by keep_copy(), checked against the sha256 recorded with them.
 *
 * @param array<string, mixed> $u
 */
function kept_copy(array $u): string|\WP_Error
{
    $dir = quarantine_dir();
    if (is_wp_error($dir)) {
        return $dir;
    }
    $stored = (string) ($u['stored'] ?? '');
    if (preg_match('/^[0-9a-f-]{36}\.quarantined$/', $stored) !== 1 || !is_file($dir . '/' . $stored)) {
        return fix_error('missing', 'The kept copy is gone.');
    }
    $bytes = (string) file_get_contents($dir . '/' . $stored);
    if (!is_string($u['sha256'] ?? null) || hash('sha256', $bytes) !== $u['sha256']) {
        return fix_error('changed', 'The kept copy changed since it was stored, so it was not put back.');
    }
    return $bytes;
}

/** @param array<string, mixed> $u */
function drop_copy(array $u): void
{
    $dir = quarantine_dir();
    $stored = (string) ($u['stored'] ?? '');
    if (is_string($dir) && preg_match('/^[0-9a-f-]{36}\.quarantined$/', $stored) === 1) {
        @unlink($dir . '/' . $stored);
    }
}

function option_protected(string $name): bool
{
    global $wpdb;
    $name = strtolower($name);
    if (in_array($name, FIX_PROTECTED_OPTIONS, strict: true) || $name === strtolower($wpdb->prefix . 'user_roles') || str_ends_with($name, '_user_roles')) {
        return true;
    }
    foreach (FIX_PROTECTED_OPTION_PREFIXES as $prefix) {
        if (str_starts_with($name, $prefix)) {
            return true;
        }
    }
    return false;
}

function flush_option_cache(string $name): void
{
    wp_cache_delete($name, 'options');
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
}

/**
 * Script and iframe elements removed from every string inside a value, walking arrays and
 * objects' public properties; the value keeps its type.
 */
function strip_markup(mixed $value, int &$count, int $depth = 0): mixed
{
    if (is_string($value)) {
        $clean = preg_replace(['#<(script|iframe)\b[^>]*>.*?</\1\s*>#is', '#<(?:script|iframe)\b[^>]*>#i'], '', $value, -1, $found);
        if (!is_string($clean)) {
            return $value;
        }
        $count += $found;
        return $clean;
    }
    if ($depth >= 32) {
        return $value;
    }
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = strip_markup($item, $count, $depth + 1);
        }
        return $value;
    }
    if (is_object($value) && !$value instanceof \__PHP_Incomplete_Class) {
        foreach (get_object_vars($value) as $key => $item) {
            $value->{$key} = strip_markup($item, $count, $depth + 1);
        }
    }
    return $value;
}

/**
 * Clean one option: script and iframe elements stripped from its strings, or the whole row
 * deleted when it carries an eval( or base64_decode payload. The raw value and autoload are
 * kept for undo.
 *
 * @return array{undo: array<string, mixed>, detail: array<string, mixed>}|\WP_Error
 */
function clean_option(string $name): array|\WP_Error
{
    global $wpdb;
    if (strlen($name) > 191 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
        return fix_error('bad_input', 'target must be an option name.');
    }
    if (option_protected($name)) {
        return fix_error('protected', 'This option is needed for the site to run or holds its access settings, so it is not changed.');
    }
    /** @var mixed $row */
    $row = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name), ARRAY_A);
    if (!is_array($row)) {
        return fix_error('not_found', 'No option with that name.');
    }
    $raw = (string) ($row['option_value'] ?? '');
    $autoload = (string) ($row['autoload'] ?? 'yes');
    $count = 0;
    $value = strip_markup(safe_unserialize($raw), $count);
    if ($count > 0) {
        $mode = 'stripped';
        $written = (string) maybe_serialize($value);
    } elseif (stripos($raw, 'eval(') !== false || stripos($raw, 'base64_decode') !== false) {
        $mode = 'deleted';
        $written = null;
    } else {
        return fix_error('unchanged', 'No script, iframe or code payload was found in this option.');
    }
    $kept = keep_copy((string) wp_json_encode(['option' => $name, 'value' => base64_encode($raw), 'autoload' => $autoload]));
    if (is_wp_error($kept)) {
        return $kept;
    }
    $ok = $written === null
        ? $wpdb->delete($wpdb->options, ['option_name' => $name])
        : $wpdb->update($wpdb->options, ['option_value' => $written], ['option_name' => $name]);
    if ($ok === false) {
        drop_copy($kept);
        return fix_error('write_failed', 'The option could not be saved.');
    }
    flush_option_cache($name);
    $undo = ['option' => $name, 'mode' => $mode] + $kept;
    if ($written !== null) {
        $undo['written_sha256'] = hash('sha256', $written);
    }
    $detail = ['option' => $name, 'mode' => $mode, 'size' => strlen($raw)];
    if ($written !== null) {
        $detail['elements_removed'] = $count;
    }
    return ['undo' => $undo, 'detail' => $detail];
}

/**
 * Remove a scheduled hook's events: those whose arguments carry a URL on another host, or all of
 * them when nothing handles the hook. The removed events are kept for undo.
 *
 * @return array{undo: array<string, mixed>, detail: array<string, mixed>}|\WP_Error
 */
function remove_cron(string $hook): array|\WP_Error
{
    if (strlen($hook) > 191 || preg_match('/[\x00-\x1F\x7F]/', $hook) === 1) {
        return fix_error('bad_input', 'target must be a scheduled hook name.');
    }
    /** @var mixed $crons */
    $crons = function_exists('_get_cron_array') ? _get_cron_array() : false;
    if (!is_array($crons)) {
        return fix_error('unavailable', 'The scheduled events could not be read.');
    }
    $orphan = !has_action($hook);
    $site_host = url_host((string) home_url());
    $removed = [];
    foreach ($crons as $time => $hooks) {
        if (!is_array($hooks) || !is_array($hooks[$hook] ?? null)) {
            continue;
        }
        foreach ($hooks[$hook] as $key => $event) {
            $event = is_array($event) ? $event : [];
            $args = is_array($event['args'] ?? null) ? $event['args'] : [];
            if (!$orphan && !foreign_url_in((string) wp_json_encode($args), $site_host)) {
                continue;
            }
            $removed[] = [
                'timestamp' => (int) $time,
                'hook' => $hook,
                'key' => (string) $key,
                'schedule' => is_string($event['schedule'] ?? null) ? $event['schedule'] : false,
                'interval' => isset($event['interval']) ? (int) $event['interval'] : null,
                'args' => $args,
            ];
            unset($crons[$time][$hook][$key]);
        }
        if ($crons[$time][$hook] === []) {
            unset($crons[$time][$hook]);
        }
        if ($crons[$time] === []) {
            unset($crons[$time]);
        }
    }
    if ($removed === []) {
        return fix_error('unchanged', $orphan ? 'No event is scheduled for that hook.' : 'That hook has a handler and none of its events carries a URL on another site.');
    }
    $saved = _set_cron_array($crons, true);
    if ($saved === false || is_wp_error($saved)) {
        return fix_error('write_failed', 'The scheduled events could not be saved.');
    }
    return ['undo' => ['hook' => $hook, 'events' => $removed], 'detail' => ['hook' => $hook, 'removed' => count($removed), 'reason' => $orphan ? 'no_callback' : 'remote_url_args']];
}

/**
 * Remove the script and iframe elements in a post's content that the database scan flags. The
 * old content is kept for undo.
 *
 * @return array{undo: array<string, mixed>, detail: array<string, mixed>}|\WP_Error
 */
function clean_post(string $target): array|\WP_Error
{
    global $wpdb;
    $id = ctype_digit($target) ? (int) $target : 0;
    /** @var mixed $content */
    $content = $id > 0 ? $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id)) : null;
    if (!is_string($content)) {
        return fix_error('not_found', 'No post with that id.');
    }
    $site_host = url_host((string) home_url());
    $removed = 0;
    $previous = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1000000');
    try {
        $clean = preg_replace_callback('#<script\b[^>]*>.*?</script\s*>|<iframe\b[^>]*>(?:.*?</iframe\s*>)?#is', static function (array $m) use ($site_host, &$removed): string {
            if (classify_content($m[0], $site_host, links: false) === []) {
                return $m[0];
            }
            $removed++;
            return '';
        }, $content);
    } finally {
        if (is_string($previous)) {
            ini_set('pcre.backtrack_limit', $previous);
        }
    }
    if (!is_string($clean)) {
        return fix_error('cannot_isolate', 'The post content could not be read as markup.');
    }
    if ($removed === 0) {
        return fix_error('unchanged', 'No flagged script or iframe was found in this post.');
    }
    $kept = keep_copy($content);
    if (is_wp_error($kept)) {
        return $kept;
    }
    if ($wpdb->update($wpdb->posts, ['post_content' => $clean], ['ID' => $id]) === false) {
        drop_copy($kept);
        return fix_error('write_failed', 'The post could not be saved.');
    }
    if (function_exists('clean_post_cache')) {
        clean_post_cache($id);
    }
    return [
        'undo' => ['post_id' => $id, 'written_sha256' => hash('sha256', $clean)] + $kept,
        'detail' => ['post_id' => $id, 'elements_removed' => $removed],
    ];
}

/** @return array{undo: array<string, mixed>, detail: array<string, mixed>}|\WP_Error */
function demote_user(string $target): array|\WP_Error
{
    $id = ctype_digit($target) ? (int) $target : 0;
    $user = $id > 0 ? get_userdata($id) : false;
    if (!$user instanceof \WP_User) {
        return fix_error('not_found', 'No user with that id.');
    }
    if ($id === get_current_user_id()) {
        return fix_error('self', 'The account making this call can\'t demote itself.');
    }
    $super = is_multisite() && is_super_admin($id);
    if (!in_array('administrator', (array) $user->roles, strict: true) && !$super && !user_can($user, 'manage_options')) {
        return fix_error('not_admin', 'This user is not an administrator.');
    }
    $admins = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 2, 'exclude' => [$id]]);
    if ($admins === []) {
        return fix_error('last_admin', 'This is the only administrator, so it can\'t be demoted.');
    }
    $roles = array_values(array_map('strval', (array) $user->roles));
    $user->set_role('subscriber');
    if ($super) {
        revoke_super_admin($id);
    }
    // Sign the account out everywhere: a stolen session must not outlive the demotion.
    \WP_Session_Tokens::get_instance($id)->destroy_all();
    return ['undo' => ['user_id' => $id, 'roles' => $roles, 'super' => $super], 'detail' => ['user_id' => $id, 'login' => mb_substr($user->user_login, 0, 60), 'previous_roles' => $roles]];
}

/** @return array{undo: array<string, mixed>, detail: array<string, mixed>}|\WP_Error */
function unpublish_post(string $target): array|\WP_Error
{
    $id = ctype_digit($target) ? (int) $target : 0;
    $post = $id > 0 ? get_post($id) : null;
    if (!$post instanceof \WP_Post) {
        return fix_error('not_found', 'No post with that id.');
    }
    if ($post->post_status === 'draft' || $post->post_status === 'trash') {
        return fix_error('unchanged', 'This post is not public.');
    }
    $previous = $post->post_status;
    $res = wp_update_post(['ID' => $id, 'post_status' => 'draft'], true);
    if (is_wp_error($res)) {
        return fix_error('write_failed', 'The post could not be unpublished.');
    }
    return ['undo' => ['post_id' => $id, 'status' => $previous], 'detail' => ['post_id' => $id, 'title' => mb_substr((string) $post->post_title, 0, 120), 'previous_status' => $previous]];
}

/** @return array<string, mixed>|\WP_Error */
function undo_fix(string $id): array|\WP_Error
{
    $fix = find_fix($id);
    if ($fix === null) {
        return fix_error('not_found', 'No fix with that id.');
    }
    if (($fix['state'] ?? '') !== 'applied') {
        return fix_error('already_undone', 'This fix was already undone.');
    }
    $u = is_array($fix['undo'] ?? null) ? $fix['undo'] : [];
    $result = match ($fix['action']) {
        'quarantine_file', 'repair_core_file', 'repair_plugin_file', 'clean_file' => restore_file($u),
        'demote_user' => restore_user($u),
        'unpublish_post' => restore_post($u),
        'clean_option' => restore_option($u),
        'remove_cron' => restore_cron($u),
        'clean_post' => restore_post_content($u),
        default => fix_error('bad_input', 'Unknown fix.'),
    };
    if (is_wp_error($result)) {
        return $result;
    }
    $fix['state'] = 'undone';
    $fix['undone_at'] = time();
    $fix['undone_by'] = get_current_user_id();
    put_fix($fix);
    return ['fix_id' => $id, 'action' => $fix['action'], 'target' => $fix['target'], 'state' => 'undone'];
}

/** @param array<string, mixed> $u */
function restore_file(array $u): bool|\WP_Error
{
    $p = fix_path((string) ($u['path'] ?? ''), must_exist: false);
    if (is_wp_error($p)) {
        return $p;
    }
    if (is_string($u['written_sha256'] ?? null) && (!is_file($p['abs']) || hash_file('sha256', $p['abs']) !== $u['written_sha256'])) {
        return fix_error('changed_since', 'The file changed after the fix, so undo would overwrite newer content. Nothing was changed.');
    }
    // A quarantine left nothing at the path: a file there now is newer content (a reinstall or update).
    if (!array_key_exists('written_sha256', $u) && file_exists($p['abs'])) {
        return fix_error('changed_since', 'A new file is at this path since the quarantine, so undo would overwrite it. Nothing was changed.');
    }
    $stored = (string) ($u['stored'] ?? '');
    if ($stored === '') {
        // A repaired core file that was missing before: undo removes the official copy again.
        return is_file($p['abs']) && !@unlink($p['abs']) ? fix_error('write_failed', 'The file could not be removed.') : true;
    }
    $dir = quarantine_dir();
    if (is_wp_error($dir)) {
        return $dir;
    }
    if (preg_match('/^[0-9a-f-]{36}\.quarantined$/', $stored) !== 1 || !is_file($dir . '/' . $stored)) {
        return fix_error('missing', 'The quarantined copy is gone.');
    }
    if (isset($u['sha256']) && hash_file('sha256', $dir . '/' . $stored) !== $u['sha256']) {
        return fix_error('changed', 'The quarantined copy changed since it was stored, so it was not put back.');
    }
    if (!make_dir(dirname($p['abs']))) {
        return fix_error('write_failed', 'The folder for this file could not be created.');
    }
    if (!@copy($dir . '/' . $stored, $p['abs'])) {
        return fix_error('write_failed', 'The file could not be put back. Check the folder\'s permissions.');
    }
    @chmod($p['abs'], is_int($u['perms'] ?? null) ? $u['perms'] : 0644);
    @unlink($dir . '/' . $stored);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($p['abs'], true);
    }
    return true;
}

/** @param array<string, mixed> $u */
function restore_user(array $u): bool|\WP_Error
{
    $user = get_userdata((int) ($u['user_id'] ?? 0));
    if (!$user instanceof \WP_User) {
        return fix_error('missing', 'The user no longer exists.');
    }
    $roles = array_values(array_filter((array) ($u['roles'] ?? []), static fn($r): bool => is_string($r) && get_role($r) !== null));
    $user->set_role($roles[0] ?? 'administrator');
    foreach (array_slice($roles, 1) as $role) {
        $user->add_role($role);
    }
    if (!empty($u['super']) && is_multisite()) {
        grant_super_admin($user->ID);
    }
    return true;
}

/** @param array<string, mixed> $u */
function restore_post(array $u): bool|\WP_Error
{
    $id = (int) ($u['post_id'] ?? 0);
    if (!get_post($id) instanceof \WP_Post) {
        return fix_error('missing', 'The post no longer exists.');
    }
    $res = wp_update_post(['ID' => $id, 'post_status' => (string) ($u['status'] ?? 'publish')], true);
    return is_wp_error($res) ? fix_error('write_failed', 'The post could not be republished.') : true;
}

/** @param array<string, mixed> $u */
function restore_option(array $u): bool|\WP_Error
{
    global $wpdb;
    $name = (string) ($u['option'] ?? '');
    $bytes = kept_copy($u);
    if (is_wp_error($bytes)) {
        return $bytes;
    }
    /** @var mixed $kept */
    $kept = json_decode($bytes, true);
    $raw = is_array($kept) && ($kept['option'] ?? null) === $name ? base64_decode((string) ($kept['value'] ?? ''), true) : false;
    if ($name === '' || !is_string($raw)) {
        return fix_error('missing', 'The kept copy of the option could not be read.');
    }
    /** @var mixed $current */
    $current = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
    if (($u['mode'] ?? '') === 'stripped' && (!is_string($current) || hash('sha256', $current) !== ($u['written_sha256'] ?? null))) {
        return fix_error('changed_since', 'The option changed after the fix, so undo would overwrite newer content. Nothing was changed.');
    }
    if (($u['mode'] ?? '') === 'deleted' && $current !== null) {
        return fix_error('changed_since', 'The option was created again after the fix, so undo would overwrite it. Nothing was changed.');
    }
    $autoload = (string) ($kept['autoload'] ?? 'yes');
    $ok = $current === null
        ? $wpdb->insert($wpdb->options, ['option_name' => $name, 'option_value' => $raw, 'autoload' => $autoload])
        : $wpdb->update($wpdb->options, ['option_value' => $raw, 'autoload' => $autoload], ['option_name' => $name]);
    if ($ok === false) {
        return fix_error('write_failed', 'The option could not be put back.');
    }
    flush_option_cache($name);
    drop_copy($u);
    return true;
}

/** @param array<string, mixed> $u */
function restore_cron(array $u): bool|\WP_Error
{
    /** @var mixed $crons */
    $crons = function_exists('_get_cron_array') ? _get_cron_array() : false;
    $crons = is_array($crons) ? $crons : [];
    $hook = (string) ($u['hook'] ?? '');
    foreach (is_array($u['events'] ?? null) ? $u['events'] : [] as $event) {
        if (!is_array($event) || ($event['hook'] ?? null) !== $hook || $hook === '') {
            continue;
        }
        $entry = ['schedule' => is_string($event['schedule'] ?? null) ? $event['schedule'] : false, 'args' => is_array($event['args'] ?? null) ? $event['args'] : []];
        if (is_int($event['interval'] ?? null)) {
            $entry['interval'] = $event['interval'];
        }
        $crons[(int) ($event['timestamp'] ?? 0)][$hook][(string) ($event['key'] ?? md5(serialize($entry['args'])))] = $entry;
    }
    ksort($crons, SORT_NUMERIC);
    $saved = _set_cron_array($crons, true);
    return $saved === false || is_wp_error($saved) ? fix_error('write_failed', 'The scheduled events could not be saved.') : true;
}

/** @param array<string, mixed> $u */
function restore_post_content(array $u): bool|\WP_Error
{
    global $wpdb;
    $id = (int) ($u['post_id'] ?? 0);
    /** @var mixed $current */
    $current = $id > 0 ? $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id)) : null;
    if (!is_string($current)) {
        return fix_error('missing', 'The post no longer exists.');
    }
    if (hash('sha256', $current) !== ($u['written_sha256'] ?? null)) {
        return fix_error('changed_since', 'The post changed after the fix, so undo would overwrite newer content. Nothing was changed.');
    }
    $bytes = kept_copy($u);
    if (is_wp_error($bytes)) {
        return $bytes;
    }
    if ($wpdb->update($wpdb->posts, ['post_content' => $bytes], ['ID' => $id]) === false) {
        return fix_error('write_failed', 'The post could not be put back.');
    }
    if (function_exists('clean_post_cache')) {
        clean_post_cache($id);
    }
    drop_copy($u);
    return true;
}

/**
 * Fixes for listing: newest first, without the stored names of quarantined copies.
 *
 * @return list<array<string, mixed>>
 */
function list_fixes(int $limit = 50): array
{
    $out = [];
    foreach (array_reverse(fixes()) as $f) {
        $out[] = [
            'fix_id' => (string) ($f['id'] ?? ''),
            'action' => (string) ($f['action'] ?? ''),
            'target' => (string) ($f['target'] ?? ''),
            'state' => (string) ($f['state'] ?? ''),
            'applied_at' => gmdate('c', (int) ($f['applied_at'] ?? 0)),
            'undone_at' => isset($f['undone_at']) ? gmdate('c', (int) $f['undone_at']) : null,
            'reason' => (string) ($f['reason'] ?? ''),
        ];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/**
 * Several fixes in one call, each applied or refused on its own: one bad target never stops the
 * rest, and every applied fix gets its own fix_id for undo.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|\WP_Error
 */
function apply_fixes(array $input): array|\WP_Error
{
    $guard = \WPPilot\Kits\Runtime\confirm_guard('gobuildwp/security-fix-apply', $input);
    if ($guard instanceof \WP_Error) {
        return $guard;
    }
    $items = is_array($input['items'] ?? null) ? array_values($input['items']) : [];
    if ($items === [] || count($items) > FIX_BATCH) {
        return fix_error('bad_input', sprintf('items must hold 1 to %d fixes.', FIX_BATCH));
    }
    $results = [];
    $applied = 0;
    foreach ($items as $item) {
        $item = is_array($item) ? $item : [];
        $done = apply_fix($item + ['reason' => $input['reason'] ?? '']);
        if (is_wp_error($done)) {
            $results[] = [
                'action' => (string) ($item['action'] ?? ''),
                'target' => mb_substr((string) ($item['target'] ?? ''), 0, 400),
                'state' => 'refused',
                'error' => ['code' => $done->get_error_code(), 'message' => $done->get_error_message()],
            ];
            continue;
        }
        $applied++;
        $results[] = $done;
    }
    return ['applied' => $applied, 'refused' => count($results) - $applied, 'results' => $results];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|\WP_Error
 */
function undo_fixes(array $input): array|\WP_Error
{
    $guard = \WPPilot\Kits\Runtime\confirm_guard('gobuildwp/security-fix-undo', $input);
    if ($guard instanceof \WP_Error) {
        return $guard;
    }
    $ids = is_array($input['fix_ids'] ?? null) ? array_values(array_unique(array_map('strval', $input['fix_ids']))) : [];
    if ($ids === [] || count($ids) > FIX_BATCH) {
        return fix_error('bad_input', sprintf('fix_ids must hold 1 to %d ids.', FIX_BATCH));
    }
    $results = [];
    $undone = 0;
    foreach ($ids as $id) {
        $done = undo_fix($id);
        if (is_wp_error($done)) {
            $results[] = ['fix_id' => $id, 'state' => 'refused', 'error' => ['code' => $done->get_error_code(), 'message' => $done->get_error_message()]];
            continue;
        }
        $undone++;
        $results[] = $done;
    }
    return ['undone' => $undone, 'refused' => count($results) - $undone, 'results' => $results];
}
