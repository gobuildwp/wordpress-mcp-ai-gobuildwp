<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityLive;

use GoBuildWP\Kits\SecurityIntegrity as SI;

if (!defined('ABSPATH')) {
    exit();
}

/*
 * The change watch: a stat-only index of the files that can run (PHP, JS, .htaccess, .user.ini)
 * in core, plugins, themes, must-use plugins and wp-content's own folder, plus PHP and server
 * settings files in uploads. Each run walks part of the tree within a time budget and compares
 * size, mtime and inode with the index. Only new or changed files are read: hashed against the
 * WordPress.org checksums where they exist, and run through the scan's malware rules. The first
 * full pass only builds the index (the baseline) and reports nothing.
 *
 * Storage: one non-autoloaded option per bucket (core folders, each plugin, each theme,
 * must-use plugins, wp-content, uploads), holding path => "size:mtime:inode". A bucket is read
 * and written whole once per pass, a site with 80,000 such files holds a few MB in total, and
 * nothing needs a schema: the kit is free and portable, and uninstall removes every option by
 * its prefix. A custom table would only pay off for per-file queries, which the watch never makes.
 *
 * The walk is the scan's own: it never follows a symlink, skips the scan's denied names and
 * folders, and every bucket's folder must resolve inside the WordPress folder or wp-content.
 */

const CURSOR_OPTION = 'gobuildwp_kit_sl_cursor';
const INDEX_PREFIX = 'gobuildwp_kit_sl_ix_';
const QUEUE_OPTION = 'gobuildwp_kit_sl_queue';
const LOCK_OPTION = 'gobuildwp_kit_sl_lock';
const LOCK_SECONDS = 120;
const CRON_HOOK = 'gobuildwp_kit_sl_tick';
const CRON_SCHEDULE = 'gobuildwp_kit_sl_watch';

/** Files one bucket may index; a bigger bucket is indexed up to this and noted. */
const MAX_BUCKET_FILES = 30000;

/** Longest a single run may take, in seconds. */
const MAX_RUN_SECONDS = 25;

/** Drop-ins WordPress loads from wp-content by name. */
const DROPINS = ['advanced-cache.php', 'db.php', 'db-error.php', 'install.php', 'maintenance.php', 'object-cache.php', 'php-error.php', 'fatal-error-handler.php', 'sunrise.php', 'blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php'];

/** Checksum lists and the plugin list, read once per run. */
final class RunCache
{
    /** @var array<string, string>|null|false */
    public static array|null|false $core = null;
    public static bool $core_loaded = false;
    /** @var array<string, array{0: string, 1: bool}>|null */
    public static ?array $plugins = null;
    /** @var array<string, array<string, list<string>>|false> */
    public static array $plugin_sums = [];

    public static function reset(): void
    {
        self::$core = null;
        self::$core_loaded = false;
        self::$plugins = null;
        self::$plugin_sums = [];
    }
}

/** The per-run limits:the scan's, with room for many more stat calls (they read no bytes). */
function run_budget(int $seconds): SI\Budget
{
    $limits = SI\budgets();
    /** @var mixed $entries */
    $entries = apply_filters('gobuildwp_kit_security_live_entries', 60000);
    $limits['entries'] = max(1000, min(200000, is_numeric($entries) ? (int) $entries : 60000));
    return new SI\Budget($limits, max(1, min(MAX_RUN_SECONDS, $seconds)));
}

/** @return array{abspath: string, content: string, plugins: string, mu_plugins: string, themes: string, uploads: string} */
function watch_roots(): array
{
    $roots = SI\roots();
    $plugins = rtrim((string) ($roots['plugins'] ?? ''), '/\\');
    return [
        'abspath' => rtrim((string) $roots['abspath'], '/\\'),
        'content' => $plugins !== '' ? dirname($plugins) : (defined('WP_CONTENT_DIR') ? (string) constant('WP_CONTENT_DIR') : ''),
        'plugins' => $plugins,
        'mu_plugins' => rtrim((string) ($roots['mu_plugins'] ?? ''), '/\\'),
        'themes' => rtrim(SI\themes_root(), '/\\'),
        'uploads' => rtrim(SI\uploads_root(), '/\\'),
    ];
}

/** Whether a folder may be watched: a real folder (not a link) inside the WordPress folder or wp-content. */
function allowed_dir(string $dir, array $roots): bool
{
    if ($dir === '' || is_link($dir) || !is_dir($dir)) {
        return false;
    }
    $real = realpath($dir);
    if ($real === false) {
        return false;
    }
    foreach ([$roots['abspath'], $roots['content']] as $base) {
        $real_base = $base !== '' ? realpath($base) : false;
        if ($real_base !== false && SI\within($real, $real_base)) {
            return true;
        }
    }
    return false;
}

/**
 * Every bucket, in the order a pass walks them.
 *
 * @return list<array{id: string, area: string, dir: string, name: string, flat: bool}>
 */
function discover_buckets(): array
{
    $roots = watch_roots();
    $abs = $roots['abspath'];
    $buckets = [];
    $add = static function (string $id, string $area, string $dir, bool $flat, string $fallback) use (&$buckets, $roots): void {
        if (allowed_dir($dir, $roots)) {
            $buckets[] = ['id' => $id, 'area' => $area, 'dir' => $dir, 'name' => SI\root_name($dir, $fallback), 'flat' => $flat];
        }
    };
    // Places attackers drop files come first, so a pass that spans several runs sees them soonest.
    $add('core:root', 'core', $abs, true, '');
    $add('content', 'content', $roots['content'], true, 'wp-content');
    $add('mu', 'mu_plugins', $roots['mu_plugins'], false, 'wp-content/mu-plugins');
    $add('uploads', 'uploads', $roots['uploads'], false, 'wp-content/uploads');
    $add('plugins', 'plugins', $roots['plugins'], true, 'wp-content/plugins');
    $add('core:wp-admin', 'core', $abs . '/wp-admin', false, 'wp-admin');
    $add('core:wp-includes', 'core', $abs . '/wp-includes', false, 'wp-includes');
    foreach (['plugins' => 'plugin', 'themes' => 'theme'] as $root => $prefix) {
        $dir = $roots[$root];
        $entries = $dir !== '' && is_dir($dir) ? @scandir($dir) : false;
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.') || SI\denied_dir($entry)) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (!is_link($path) && is_dir($path)) {
                $add($prefix . ':' . $entry, $root, $path, false, 'wp-content/' . $root . '/' . $entry);
            }
        }
    }
    return $buckets;
}

/** Whether the watch indexes this file in this area. */
function watched(string $name, string $area): bool
{
    $lower = strtolower($name);
    $settings = in_array($lower, ['.htaccess', '.user.ini', 'php.ini'], strict: true);
    $php = SI\is_php_name($name) || SI\has_inner_php_extension($name);
    if ($area === 'uploads') {
        return $php || $settings;
    }
    return $php || $settings || str_ends_with($lower, '.js');
}

/** @param list<string> $excluded */
function excluded(string $path, array $excluded): bool
{
    foreach ($excluded as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            return true;
        }
    }
    return false;
}

function index_option(string $bucket): string
{
    return INDEX_PREFIX . md5($bucket);
}

/** @return array<string, string> path => "size:mtime:inode" */
function load_index(string $bucket): array
{
    /** @var mixed $stored */
    $stored = get_option(index_option($bucket), []);
    return is_array($stored) ? $stored : [];
}

/** @param array<string, string> $index */
function save_index(string $bucket, array $index): void
{
    if ($index === []) {
        delete_option(index_option($bucket));
        return;
    }
    update_option(index_option($bucket), $index, false);
}

/** @return array<string, mixed> */
function cursor(): array
{
    /** @var mixed $stored */
    $stored = get_option(CURSOR_OPTION, []);
    return is_array($stored) ? $stored : [];
}

/**
 * Ask for a quick look at some buckets (after an update, say): the next run starts with them.
 *
 * @param list<string> $buckets
 */
function queue_buckets(array $buckets): void
{
    /** @var mixed $queued */
    $queued = get_option(QUEUE_OPTION, []);
    $queued = is_array($queued) ? $queued : [];
    $queued = array_slice(array_values(array_unique([...$queued, ...$buckets])), 0, 50);
    update_option(QUEUE_OPTION, $queued, false);
    if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')) {
        $at = time() + 60;
        if (!wp_next_scheduled(CRON_HOOK . '_soon')) {
            wp_schedule_single_event($at, CRON_HOOK . '_soon');
        }
    }
}

function take_lock(int $now): bool
{
    if (add_option(LOCK_OPTION, $now, '', false)) {
        return true;
    }
    $held = (int) get_option(LOCK_OPTION, 0);
    if ($held < $now - LOCK_SECONDS) {
        update_option(LOCK_OPTION, $now, false);
        return true;
    }
    return false;
}

function release_lock(): void
{
    delete_option(LOCK_OPTION);
}

/**
 * One budgeted run of the change watch. Returns the watch status plus what this run did.
 *
 * @return array<string, mixed>
 */
function run_watch(int $seconds = 10, ?int $now = null): array
{
    $now ??= time();
    if (!settings()['enabled']) {
        return status() + ['ran' => false, 'reason' => 'off'];
    }
    if (!take_lock($now)) {
        return status() + ['ran' => false, 'reason' => 'busy'];
    }
    try {
        $did = watch_step(run_budget($seconds), $now);
    } finally {
        release_lock();
    }
    check_extras($now, true);
    return status() + ['ran' => true] + $did;
}

/**
 * @return array{checked: int, changed: int, events: int}
 */
function watch_step(SI\Budget $budget, int $now): array
{
    RunCache::reset();
    $cursor = cursor();
    $did = ['checked' => 0, 'changed' => 0, 'events' => 0];
    if (!is_array($cursor['buckets'] ?? null) || (int) ($cursor['pos'] ?? 0) >= count($cursor['buckets'])) {
        $cursor = [
            'buckets' => discover_buckets(),
            'pos' => 0,
            'walk' => null,
            'partial' => [],
            'baseline' => !isset($cursor['cycles']) || (int) $cursor['cycles'] === 0 ? true : (bool) ($cursor['baseline'] ?? false),
            'cycles' => (int) ($cursor['cycles'] ?? 0),
            'last_cycle_at' => $cursor['last_cycle_at'] ?? null,
            'cycle_started_at' => $now,
            'files' => (int) ($cursor['files'] ?? 0),
            'truncated' => [],
        ];
    }
    $cursor = take_queued($cursor);
    $excluded = settings()['excluded_paths'];
    $buckets = $cursor['buckets'];

    while ((int) $cursor['pos'] < count($buckets) && !$budget->spent()) {
        $bucket = $buckets[(int) $cursor['pos']];
        if (!is_array($cursor['walk'])) {
            $cursor['walk'] = ['queue' => [[$bucket['dir'], $bucket['name']]], 'offset' => 0, 'flat' => $bucket['flat'] ? [$bucket['dir']] : []];
            $cursor['partial'] = [];
        }
        $old = load_index($bucket['id']);
        // A bucket never indexed before is new (a plugin just installed): its files are checked, not trusted.
        $baseline = (bool) $cursor['baseline'];
        $partial = $cursor['partial'];
        $walk = $cursor['walk'];
        $done = SI\walk(
            $walk,
            $budget,
            static function (string $abs, string $path, string $name) use ($bucket, $old, $baseline, $excluded, $budget, &$partial, &$did, &$cursor): bool {
                if (!watched($name, $bucket['area']) || excluded($path, $excluded)) {
                    return true;
                }
                if (count($partial) >= MAX_BUCKET_FILES) {
                    $cursor['truncated'][$bucket['id']] = true;
                    return true;
                }
                $stat = @lstat($abs);
                if (!is_array($stat)) {
                    return true;
                }
                $sig = $stat['size'] . ':' . $stat['mtime'] . ':' . $stat['ino'];
                if (($old[$path] ?? null) === $sig || $baseline) {
                    $partial[$path] = $sig;
                    return true;
                }
                $events = check_file($abs, $path, $name, $bucket, !isset($old[$path]), $budget);
                if ($events === null) {
                    return false;
                }
                $did['checked']++;
                $did['changed']++;
                $did['events'] += $events;
                $partial[$path] = $sig;
                return true;
            },
            static function (string $path, string $why): void {
            },
        );
        $cursor['walk'] = $walk;
        $cursor['partial'] = $partial;
        if (!$done) {
            break;
        }
        save_index($bucket['id'], $partial);
        $cursor['pos'] = (int) $cursor['pos'] + 1;
        $cursor['walk'] = null;
        $cursor['partial'] = [];
    }

    if ((int) $cursor['pos'] >= count($buckets)) {
        finish_cycle($cursor, $now);
    }
    update_option(CURSOR_OPTION, $cursor, false);
    return $did;
}

/**
 * Buckets queued by an update move to just after the current position, once per pass.
 *
 * @param array<string, mixed> $cursor
 * @return array<string, mixed>
 */
function take_queued(array $cursor): array
{
    /** @var mixed $queued */
    $queued = get_option(QUEUE_OPTION, []);
    if (!is_array($queued) || $queued === []) {
        return $cursor;
    }
    delete_option(QUEUE_OPTION);
    $pos = (int) $cursor['pos'];
    // The bucket being walked keeps its place; queued ones come next.
    $start = is_array($cursor['walk']) ? $pos + 1 : $pos;
    $head = array_slice($cursor['buckets'], 0, $start);
    $tail = array_slice($cursor['buckets'], $start);
    $front = [];
    foreach ($tail as $i => $bucket) {
        if (in_array($bucket['id'], $queued, strict: true)) {
            $front[] = $bucket;
            unset($tail[$i]);
        }
    }
    $cursor['buckets'] = [...$head, ...$front, ...array_values($tail)];
    return $cursor;
}

/** @param array<string, mixed> $cursor */
function finish_cycle(array &$cursor, int $now): void
{
    $keep = [];
    $files = 0;
    foreach ($cursor['buckets'] as $bucket) {
        $keep[index_option($bucket['id'])] = true;
        $files += count(load_index($bucket['id']));
    }
    /** @var mixed $known */
    $known = get_option(CURSOR_OPTION . '_known', []);
    foreach (is_array($known) ? $known : [] as $option) {
        if (is_string($option) && !isset($keep[$option])) {
            delete_option($option);
        }
    }
    update_option(CURSOR_OPTION . '_known', array_keys($keep), false);
    $cursor['baseline'] = false;
    $cursor['cycles'] = (int) $cursor['cycles'] + 1;
    $cursor['last_cycle_at'] = $now;
    $cursor['files'] = $files;
    $cursor['last_truncated'] = array_keys((array) ($cursor['truncated'] ?? []));
}

/**
 * Record a new must-use plugin or wp-content PHP file: high, unless a GoBuildWP plugin
 * vouches for it. The `gobuildwp_live_known_file` filter returns a note when the file is
 * one it wrote, byte for byte (GoBuildWP Pro's Site Rescue); that is logged as info.
 */
function record_added(string $abs, string $path, string $area, string $rule, ?int $now = null): bool
{
    $known = apply_filters('gobuildwp_live_known_file', null, $abs, $path);
    if (is_string($known) && $known !== '') {
        return record('info', 'file_added', $path, ['path' => $path, 'area' => $area, 'result' => 'added', 'rule_id' => $rule, 'note' => $known], $now) > 0;
    }
    return record('high', 'file_added', $path, ['path' => $path, 'area' => $area, 'result' => 'added', 'rule_id' => $rule], $now) > 0;
}

/**
 * Read one new or changed file with the scan's own checks and record what they find. Null when
 * the run's budget can't take the file (it is checked first thing next run).
 *
 * @param array{id: string, area: string, dir: string, name: string, flat: bool} $bucket
 */
function check_file(string $abs, string $path, string $name, array $bucket, bool $is_new, SI\Budget $budget): ?int
{
    $state = [];
    $out = new SI\Collector($state);
    $area = $bucket['area'];
    $ok = true;
    if ($area === 'uploads') {
        $ok = SI\upload_file($abs, $path, $name, $budget, $out, $state);
    } elseif ($area === 'core') {
        $ok = check_core($abs, $path, $name, $budget, $out, $state);
    } elseif ($area === 'plugins' && str_starts_with($bucket['id'], 'plugin:')) {
        $ok = check_plugin($abs, $path, substr($bucket['id'], 7), $budget, $out, $state);
    } elseif (SI\rule_worthy($name)) {
        $size = @filesize($abs);
        if ($size !== false && $size <= $budget->limits['file_size']) {
            if (!$budget->can_read(min($size, SI\RULE_READ_BYTES))) {
                return null;
            }
            $budget->files++;
            SI\inspect($abs, $path, $area === 'content' ? 'core' : ($area === 'mu_plugins' ? 'plugins' : $area), SI\is_php_name($name), true, $budget, $out, $state, null);
        }
    }
    if (!$ok) {
        return null;
    }
    $count = 0;
    if ($is_new && SI\is_php_name($name) && ($area === 'mu_plugins' || $area === 'content')) {
        $rule = $area === 'mu_plugins' ? 'live.mu_plugin_added' : (in_array(strtolower($name), DROPINS, strict: true) ? 'live.dropin_added' : 'live.content_php_added');
        $count += record_added($abs, $path, $area, $rule) ? 1 : 0;
    }
    foreach ($out->items as $item) {
        $count += record_item($item) ? 1 : 0;
    }
    return $count;
}

/** A scan result item as an event; skipped, baseline and informational results are not events. */
function record_item(array $item): bool
{
    $severity = (string) ($item['severity_hint'] ?? 'info');
    if (rank($severity) < rank('medium') || in_array($item['result'] ?? '', ['symlink_skipped', 'too_large', 'unreadable', 'baseline', 'missing'], strict: true)) {
        return false;
    }
    $detail = (array) ($item['detail'] ?? []);
    $component = is_array($item['component'] ?? null) ? $item['component']['type'] . ':' . $item['component']['slug'] : null;
    return record($severity, 'file', (string) $item['path'], [
        'path' => (string) $item['path'],
        'area' => (string) $item['area'],
        'result' => (string) $item['result'],
        'rule_id' => (string) $item['rule_id'],
        'line' => isset($detail['line']) ? (int) $detail['line'] : 0,
        'component' => $component,
    ]) > 0;
}

/** @param array<string, mixed> $state */
function check_core(string $abs, string $path, string $name, SI\Budget $budget, SI\Collector $out, array &$state): bool
{
    if (!RunCache::$core_loaded) {
        RunCache::$core = SI\core_checksums(SI\wp_version(), (string) get_locale(), $budget);
        RunCache::$core_loaded = RunCache::$core !== false;
    }
    $list = RunCache::$core;
    if ($list === false) {
        return false;
    }
    $component = SI\core_component();
    if (is_array($list) && isset($list[$path])) {
        return SI\compare_file($abs, $path, 'core', 'md5', [$list[$path]], $budget, $out, $state, $component);
    }
    if (is_array($list) && (str_contains($path, '/') || SI\is_php_name($name))) {
        return SI\report_unexpected($abs, $path, 'core', $budget, $out, $state, $component);
    }
    if (SI\rule_worthy($name)) {
        $size = @filesize($abs);
        if ($size !== false && $size <= $budget->limits['file_size']) {
            if (!$budget->can_read(min($size, SI\RULE_READ_BYTES))) {
                return false;
            }
            $budget->files++;
            SI\inspect($abs, $path, 'core', SI\is_php_name($name), true, $budget, $out, $state, $component);
        }
    }
    return true;
}

/** @param array<string, mixed> $state */
function check_plugin(string $abs, string $path, string $folder, SI\Budget $budget, SI\Collector $out, array &$state): bool
{
    if (RunCache::$plugins === null) {
        RunCache::$plugins = [];
        foreach (SI\plugin_list() as [$file, $dir, $version, $wporg]) {
            if ($dir !== '') {
                RunCache::$plugins[$dir] = [$version, $wporg];
            }
        }
    }
    [$version, $wporg] = RunCache::$plugins[$folder] ?? ['', false];
    $sums = &RunCache::$plugin_sums;
    $component = ['type' => 'plugin', 'slug' => $folder, 'version' => $version];
    $name = basename($path);
    $inner = substr($path, strpos($path, '/' . $folder . '/') !== false ? (int) strpos($path, '/' . $folder . '/') + strlen($folder) + 2 : 0);
    $list = [];
    if ($wporg && $version !== '') {
        $sums[$folder] ??= SI\plugin_checksums($folder, $version, $budget);
        if ($sums[$folder] === false) {
            unset($sums[$folder]);
            return false;
        }
        $list = $sums[$folder];
    }
    if ($list !== [] && isset($list[$inner])) {
        return SI\compare_file($abs, $path, 'plugins', 'sha256', $list[$inner], $budget, $out, $state, $component);
    }
    if ($list !== []) {
        return SI\report_unexpected($abs, $path, 'plugins', $budget, $out, $state, $component);
    }
    if (!SI\rule_worthy($name)) {
        return true;
    }
    $size = @filesize($abs);
    if ($size === false || $size > $budget->limits['file_size']) {
        return true;
    }
    if (!$budget->can_read(min($size, SI\RULE_READ_BYTES))) {
        return false;
    }
    $budget->files++;
    SI\inspect($abs, $path, 'plugins', SI\is_php_name($name), false, $budget, $out, $state, $component);
    return true;
}

/**
 * The quick check for new must-use plugins and drop-ins, between watch passes: a directory listing
 * of two folders, at most every five minutes. The first look only remembers what is there.
 */
function check_extras(int $now, bool $force = false): int
{
    /** @var mixed $stored */
    $stored = get_option('gobuildwp_kit_sl_extras', null);
    $stored = is_array($stored) ? $stored : null;
    if (!$force && $stored !== null && (int) ($stored['at'] ?? 0) > $now - 300) {
        return 0;
    }
    $roots = watch_roots();
    $seen = [];
    foreach (['mu_plugins' => $roots['mu_plugins'], 'content' => $roots['content']] as $area => $dir) {
        if (!allowed_dir($dir, $roots)) {
            continue;
        }
        $entries = @scandir($dir);
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (!SI\is_php_name($entry) || SI\denied_file($entry) || is_link($dir . '/' . $entry) || !is_file($dir . '/' . $entry)) {
                continue;
            }
            $seen[] = SI\join_path(SI\root_name($dir, $area === 'content' ? 'wp-content' : 'wp-content/mu-plugins'), $entry) . '|' . $area;
        }
    }
    sort($seen);
    $count = 0;
    if ($stored !== null) {
        foreach (array_diff($seen, (array) ($stored['files'] ?? [])) as $new) {
            [$path, $area] = explode('|', $new, 2);
            $name = basename($path);
            $rule = $area === 'mu_plugins' ? 'live.mu_plugin_added' : (in_array(strtolower($name), DROPINS, strict: true) ? 'live.dropin_added' : 'live.content_php_added');
            $count += record_added(rtrim($area === 'content' ? $roots['content'] : $roots['mu_plugins'], '/\\') . '/' . $name,$path, $area, $rule, $now) ? 1 : 0;
        }
    }
    update_option('gobuildwp_kit_sl_extras', ['at' => $now, 'files' => array_slice($seen, 0, 500)], false);
    return $count;
}

/** @return array<string, mixed> */
function status(): array
{
    $cursor = cursor();
    $settings = settings();
    $buckets = is_array($cursor['buckets'] ?? null) ? count($cursor['buckets']) : 0;
    $cycles = (int) ($cursor['cycles'] ?? 0);
    return [
        'version' => 1,
        'enabled' => $settings['enabled'],
        'interval_minutes' => $settings['interval_minutes'],
        'excluded_paths' => $settings['excluded_paths'],
        'state' => !$settings['enabled'] ? 'off' : ($cycles === 0 ? 'baseline' : 'watching'),
        'passes' => $cycles,
        'last_pass_at' => isset($cursor['last_cycle_at']) ? (int) $cursor['last_cycle_at'] : null,
        'files_indexed' => (int) ($cursor['files'] ?? 0),
        'pass_progress' => $buckets > 0 ? round(min(1, (int) ($cursor['pos'] ?? 0) / $buckets), 3) : 0.0,
        'too_many_files' => array_values(array_map('strval', (array) ($cursor['last_truncated'] ?? []))),
        'last_event_id' => last_event_id(),
    ];
}

/** Keep the WP-Cron run in step with the interval (or remove it when live security is off). */
function reschedule(?array $settings = null): void
{
    if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
        return;
    }
    $settings ??= settings();
    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook(CRON_HOOK);
    }
    if ($settings['enabled']) {
        wp_schedule_event(time() + 60, CRON_SCHEDULE, CRON_HOOK);
    }
}
