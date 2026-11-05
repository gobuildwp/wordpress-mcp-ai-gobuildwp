<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Restore points: a zip of exactly what an AI change is about to touch on disk.
 *
 * Plugin and theme updates, deletes and installs, and the file abilities, used to be recorded in
 * the ledger as "not reversible": the bytes they replaced were gone. Before each of them runs, the
 * one plugin or theme folder, or the one file, the call names is zipped, encrypted with a key
 * derived from the site's auth salt, and kept in protected storage under uploads, with an expiry
 * set by the site's Cloud plan. Storage under uploads is web-reachable on servers that ignore its
 * .htaccess and web.config (nginx), so the stored file must be useless to whoever fetches it. The ledger row then carries a
 * `files/snapshot` payload, and undo puts the zip back: extracted beside the target, verified
 * against the hashes taken at capture, and swapped in with a rename, so a failure part-way leaves
 * the site as it was. Undo can be redone, from a second restore point of the AI's version taken
 * just before the undo, which expires with the first.
 *
 * Every restore point keeps a record that outlives a failed restore: what was captured (file
 * count, bytes, a sha256 per file), who made the change and through which agent, the ledger row
 * it belongs to, when it expires, and each restore attempt with its result. That record is what
 * get-change and Cloud show; neither the storage id nor the path leaves the server.
 *
 * Undo refuses when a target changed after the AI's change (changed_since), so it never wipes a
 * later edit or a file someone added since; `force` overrides that.
 *
 * Fail closed: when a restore point cannot be taken (no ZipArchive, over the size cap, storage not
 * writable), the call is refused before it runs, unless `wppilot_restore_point_allow_without_snapshot`
 * lets it through, in which case the ledger row says it cannot be undone, as before.
 *
 * Nothing registers at file scope; wppilot_restore_points_register_hooks() does.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_RESTORE_POINT_TYPE = 'files/snapshot';
/** Hours of undo the Cloud plan pays for. Written by the signed cloud/retention route. */
const WPPILOT_RESTORE_POINT_HOURS_OPTION = 'gobuildwp_restore_point_hours';
/** Index of every restore point: id => record. Not autoloaded. */
const WPPILOT_RESTORE_POINT_INDEX_OPTION = 'gobuildwp_restore_points';
const WPPILOT_RESTORE_POINT_DIR = 'gobuildwp-restore-points';
const WPPILOT_RESTORE_POINT_CLEANUP_HOOK = 'gobuildwp_restore_points_cleanup';
const WPPILOT_RESTORE_POINT_MIN_HOURS = 1;
const WPPILOT_RESTORE_POINT_MAX_HOURS = 168;
const WPPILOT_RESTORE_POINT_MAX_BYTES = 200 * 1024 * 1024;
const WPPILOT_RESTORE_POINT_TOTAL_BYTES = 1024 * 1024 * 1024;
/** Attempts kept per restore point; the oldest go first. */
const WPPILOT_RESTORE_POINT_MAX_ATTEMPTS = 20;
/** A restore point no ledger row claimed within this long belongs to a call that failed. */
const WPPILOT_RESTORE_POINT_ORPHAN_SECONDS = 3600;
/** The encrypted zip, named so no server hands it out as a zip. */
const WPPILOT_RESTORE_POINT_SEALED = 'snapshot.bin';
/** Plaintext bytes per encrypted chunk: the zip is streamed, never held in memory whole. */
const WPPILOT_RESTORE_POINT_CHUNK = 1048576;

/** The abilities a restore point covers, and how each names its target. */
const WPPILOT_RESTORE_POINT_ABILITIES = [
    'wppilot/update-plugin' => 'plugin',
    'wppilot/delete-plugin' => 'plugin',
    'wppilot/update-theme' => 'theme',
    'wppilot/delete-theme' => 'theme',
    'wppilot/install-plugin' => 'install-plugin',
    'wppilot/install-theme' => 'install-theme',
    'wppilot/write-file' => 'file',
    'wppilot/edit-file' => 'file',
    'wppilot/delete-file' => 'file',
    'wppilot/disable-file' => 'toggle-file',
    'wppilot/enable-file' => 'toggle-file',
];

function wppilot_restore_points_register_hooks(): void
{
    // After the rate limit (6), design (7), preview and backup gates (8) and the approval queue
    // (9): a call any of them holds or refuses never pays for a zip.
    add_filter('wppilot_pre_ability_execute', 'wppilot_restore_point_pre_execute', 20, 2);
    add_filter('wppilot_change_current_state', 'wppilot_restore_point_filter_current_state', 10, 2);
    add_filter('wppilot_change_payload_target', 'wppilot_restore_point_filter_payload_target', 10, 2);
    add_action('init', 'wppilot_restore_points_schedule_cleanup');
    add_action(WPPILOT_RESTORE_POINT_CLEANUP_HOOK, 'wppilot_restore_points_cleanup');
    wppilot_register_rollback_strategy(
        WPPILOT_RESTORE_POINT_TYPE,
        'wppilot_restore_point_strategy_restore',
        'wppilot_restore_point_strategy_build',
    );
}

function wppilot_restore_points_schedule_cleanup(): void
{
    if (function_exists('wp_next_scheduled') && wp_next_scheduled(WPPILOT_RESTORE_POINT_CLEANUP_HOOK) === false) {
        wp_schedule_event(time() + 600, 'daily', WPPILOT_RESTORE_POINT_CLEANUP_HOOK);
    }
}

// ---------------------------------------------------------------- retention

/**
 * How many hours a new restore point is kept. A site with no Cloud link keeps one hour; a linked
 * site keeps what its plan pays for, as last sent by Cloud, clamped to 1-168.
 */
function wppilot_restore_point_hours(): int
{
    if (!function_exists('wppilot_cloud_link') || wppilot_cloud_link() === null) {
        return WPPILOT_RESTORE_POINT_MIN_HOURS;
    }

    return wppilot_restore_point_clamp_hours(get_option(WPPILOT_RESTORE_POINT_HOURS_OPTION, WPPILOT_RESTORE_POINT_MIN_HOURS));
}

function wppilot_restore_point_clamp_hours(mixed $hours): int
{
    $value = is_numeric($hours) ? (int) $hours : WPPILOT_RESTORE_POINT_MIN_HOURS;

    return max(WPPILOT_RESTORE_POINT_MIN_HOURS, min(WPPILOT_RESTORE_POINT_MAX_HOURS, $value));
}

/** Store the plan's window. Restore points already taken keep the expiry they were given. */
function wppilot_restore_point_set_hours(mixed $hours): int
{
    $clamped = wppilot_restore_point_clamp_hours($hours);
    update_option(WPPILOT_RESTORE_POINT_HOURS_OPTION, $clamped, false);

    return $clamped;
}

// ---------------------------------------------------------------- storage

function wppilot_restore_point_storage_dir(): string
{
    $dir = '';
    if (function_exists('wp_upload_dir')) {
        $uploads = wp_upload_dir(null, false);
        $base = is_array($uploads) ? (string) ($uploads['basedir'] ?? '') : '';
        if ($base !== '') {
            $dir = rtrim(wp_normalize_path($base), '/') . '/' . WPPILOT_RESTORE_POINT_DIR;
        }
    }
    /**
     * Where restore points are kept. Outside the web root is better where the host allows it.
     *
     * @param string $dir Default: uploads/gobuildwp-restore-points.
     */
    $filtered = apply_filters('wppilot_restore_point_storage_dir', $dir);

    return is_string($filtered) ? rtrim(wp_normalize_path($filtered), '/') : '';
}

/**
 * Create the storage directory with the deny files a web server honours, so a guessed URL serves
 * nothing. The per-point directories are 128-bit random names on top of that, for servers (nginx)
 * that ignore .htaccess.
 */
function wppilot_restore_point_protect_storage(string $dir): bool
{
    if ($dir === '' || (!is_dir($dir) && !@mkdir($dir, 0755, true))) {
        return false;
    }
    $files = [
        'index.php' => "<?php\n// Silence is golden.\n",
        '.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
        'web.config' => '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' . "\n",
    ];
    foreach ($files as $name => $content) {
        if (!is_file($dir . '/' . $name) && @file_put_contents($dir . '/' . $name, $content) === false) {
            return false;
        }
    }

    return is_writable($dir);
}

/**
 * Run an index read-modify-write under a MySQL named lock, as the ledger does.
 *
 * Two changes finishing at once (a parallel MCP call, a cron cleanup during an undo) each read
 * the index, add or drop their own record and save, and the second save erased the first one's
 * row: its undo then said the restore point was no longer kept and the next cleanup deleted the
 * orphaned zip. GET_LOCK is re-entrant per connection, so a writer may call another.
 *
 * @template T
 * @param callable(): T $write
 * @return T
 */
function wppilot_with_restore_point_lock(callable $write): mixed
{
    global $wpdb;

    $lock = null;
    if (is_object($wpdb) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare')) {
        $lock = $wpdb->prefix . 'wppilot_restore_points';
        $acquired = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 5)) === '1';
        if (!$acquired) {
            $lock = null;
        }
    }
    // The option is autoload=no but cached once read, and a persistent object cache shares that
    // copy across requests; re-read it from the table or the lock protects a stale index.
    if (function_exists('wp_cache_delete')) {
        wp_cache_delete(WPPILOT_RESTORE_POINT_INDEX_OPTION, 'options');
    }

    try {
        return $write();
    } finally {
        if ($lock !== null) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
}

/** @return array<string, array<string, mixed>> */
function wppilot_restore_point_index(): array
{
    $stored = get_option(WPPILOT_RESTORE_POINT_INDEX_OPTION, []);
    if (!is_array($stored)) {
        return [];
    }
    $index = [];
    foreach ($stored as $id => $record) {
        if (is_string($id) && preg_match('/^[a-f0-9]{32}$/', $id) === 1 && is_array($record)) {
            $index[$id] = wppilot_string_keyed_array($record);
        }
    }

    return $index;
}

/** @param array<string, array<string, mixed>> $index */
function wppilot_restore_point_save_index(array $index): void
{
    if ($index === []) {
        delete_option(WPPILOT_RESTORE_POINT_INDEX_OPTION);
        return;
    }
    update_option(WPPILOT_RESTORE_POINT_INDEX_OPTION, $index, false);
}

/**
 * A restore point's record. An expired one loses its files here rather than at the next cleanup
 * run; the record stays, so undo can still say when it expired and what to do instead.
 *
 * @return array<string, mixed>|null
 */
function wppilot_restore_point_get(string $id): ?array
{
    $record = preg_match('/^[a-f0-9]{32}$/', $id) === 1 ? (wppilot_restore_point_index()[$id] ?? null) : null;
    if (is_array($record) && (int) ($record['expires_ts'] ?? 0) <= time()) {
        $dir = wppilot_restore_point_dir_for($id);
        if ($dir !== '' && is_dir($dir)) {
            wppilot_restore_point_delete_tree($dir);
        }
    }

    return $record;
}

/** @param array<string, mixed> $record */
function wppilot_restore_point_put(string $id, array $record): void
{
    wppilot_with_restore_point_lock(static function () use ($id, $record): void {
        $index = wppilot_restore_point_index();
        $index[$id] = $record;
        wppilot_restore_point_save_index($index);
    });
}

function wppilot_restore_point_dir_for(string $id): string
{
    $root = wppilot_restore_point_storage_dir();

    return $root === '' || preg_match('/^[a-f0-9]{32}$/', $id) !== 1 ? '' : $root . '/' . $id;
}

/** Remove a restore point's files and its index record. */
function wppilot_restore_point_forget(string $id): void
{
    $dir = wppilot_restore_point_dir_for($id);
    if ($dir !== '' && is_dir($dir)) {
        wppilot_restore_point_delete_tree($dir);
    }
    wppilot_with_restore_point_lock(static function () use ($id): void {
        $index = wppilot_restore_point_index();
        if (isset($index[$id])) {
            unset($index[$id]);
            wppilot_restore_point_save_index($index);
        }
    });
}

// ---------------------------------------------------------------- targets

/**
 * The directories a restore point may capture or replace something inside.
 *
 * @return array<string, string> root key => real, normalized path
 */
function wppilot_restore_point_roots(): array
{
    $roots = [];
    if (defined('WP_PLUGIN_DIR')) {
        $roots['plugins'] = (string) WP_PLUGIN_DIR;
    }
    if (function_exists('get_theme_root')) {
        $roots['themes'] = (string) get_theme_root();
    }
    // The folder the file abilities are confined to (wppilot_resolve_path()). A site that lifts
    // that limit (the filter returns false) still gets restore points only inside ABSPATH.
    $base = apply_filters('wppilot_filesystem_base_dir', ABSPATH);
    $roots['site'] = is_string($base) && $base !== '' ? $base : (string) ABSPATH;
    /**
     * @param array<string, string> $roots plugins, themes and site (the file abilities' base folder).
     */
    $filtered = apply_filters('wppilot_restore_point_roots', $roots);
    $out = [];
    foreach (is_array($filtered) ? $filtered : [] as $key => $path) {
        if (!is_string($key) || !is_string($path) || $path === '') {
            continue;
        }
        $real = realpath($path);
        if ($real !== false && is_dir($real)) {
            $out[$key] = rtrim(wp_normalize_path($real), '/');
        }
    }

    return $out;
}

function wppilot_restore_point_path_within(string $path, string $root): bool
{
    $path = rtrim(wp_normalize_path($path), '/');
    $root = rtrim(wp_normalize_path($root), '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }

    return $path === $root || str_starts_with($path, $root . '/');
}

/** A single plugin or theme directory name: no separators, no traversal, no leading dot. */
function wppilot_restore_point_segment(string $value): string
{
    return preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$#', $value) === 1 && !str_contains($value, '..') ? $value : '';
}

/**
 * Resolve a target to an absolute path inside its root, refusing symlinks and anything that
 * escapes the root or lands in restore-point storage.
 *
 * @return array{root: string, rel: string, path: string, kind: string}|WP_Error
 */
function wppilot_restore_point_resolve(string $root_key, string $rel): array|WP_Error
{
    $roots = wppilot_restore_point_roots();
    $root = $roots[$root_key] ?? '';
    $rel = trim(wp_normalize_path($rel), '/');
    $segments = $rel === '' ? [] : explode('/', $rel);
    $valid = $root !== '' && $segments !== [] && ($root_key === 'site' || count($segments) === 1);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
            $valid = false;
        }
    }
    if (!$valid) {
        return wppilot_restore_point_error('wppilot_restore_point_target', 'The file or folder this change touches could not be named safely.');
    }

    $path = $root . '/' . $rel;
    $parent = realpath(dirname($path));
    if ($parent === false || !wppilot_restore_point_path_within(wp_normalize_path($parent), $root)) {
        return wppilot_restore_point_error('wppilot_restore_point_target', 'The file or folder this change touches is outside the folder it belongs to.');
    }
    $path = rtrim(wp_normalize_path($parent), '/') . '/' . basename($path);
    if (wppilot_restore_point_is_wp_config($path)) {
        return wppilot_restore_point_error('wppilot_restore_point_target', 'wp-config.php holds the database password and the site\'s secret keys, so it is never copied into a restore point.');
    }
    $storage = wppilot_restore_point_storage_dir();
    if ($storage !== '' && (wppilot_restore_point_path_within($path, $storage) || wppilot_restore_point_path_within($storage, $path))) {
        return wppilot_restore_point_error('wppilot_restore_point_target', 'Restore-point storage cannot itself be captured or replaced.');
    }
    if (is_link($path)) {
        return wppilot_restore_point_error('wppilot_restore_point_symlink', 'The target is a symbolic link; GoBuildWP does not follow links when taking a restore point.');
    }
    $kind = is_dir($path) ? 'dir' : (is_file($path) ? 'file' : 'absent');

    return ['root' => $root_key, 'rel' => $rel, 'path' => $path, 'kind' => $kind];
}

/**
 * Is this the site's wp-config.php: in the site root, or the folder above it, where WordPress also
 * looks for it?
 */
function wppilot_restore_point_is_wp_config(string $path): bool
{
    if (strtolower(basename($path)) !== 'wp-config.php') {
        return false;
    }
    $site = strtolower(rtrim((string) (wppilot_restore_point_roots()['site'] ?? ''), '/'));
    $folder = strtolower(rtrim(wp_normalize_path(dirname($path)), '/'));

    return $site !== '' && ($folder === $site || $folder === rtrim(wp_normalize_path(dirname($site)), '/'));
}

/**
 * The site-relative target for a file ability's `path`. A path whose parents do not exist yet
 * names the first missing directory, so undoing a write that created folders removes them too.
 *
 * @return array{root: string, rel: string}|WP_Error
 */
function wppilot_restore_point_site_target(string $path): array|WP_Error
{
    $roots = wppilot_restore_point_roots();
    $root = $roots['site'] ?? '';
    $path = wp_normalize_path(trim($path));
    if ($root === '' || $path === '' || str_contains($path, "\0")) {
        return wppilot_restore_point_error('wppilot_restore_point_target', 'The file this change touches could not be named safely.');
    }
    // Relative paths are relative to ABSPATH in the file abilities, which is the site root here.
    $absolute = str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1;
    if (!$absolute) {
        $path = $root . '/' . ltrim($path, '/');
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '..') {
            return wppilot_restore_point_error('wppilot_restore_point_target', 'The file path may not contain "..".');
        }
    }

    // Walk up to the nearest directory that exists; the target is the first segment below it.
    $missing = [];
    $cursor = rtrim($path, '/');
    while ($cursor !== '' && realpath($cursor) === false) {
        $missing[] = basename($cursor);
        $parent = dirname($cursor);
        if ($parent === $cursor) {
            break;
        }
        $cursor = $parent;
    }
    $real = realpath($cursor);
    if ($real === false) {
        return wppilot_restore_point_error('wppilot_restore_point_target', 'The file this change touches is outside the site.');
    }
    if ($missing !== []) {
        $base = rtrim(wp_normalize_path($real), '/') . '/' . end($missing);
    } else {
        // The path exists. Resolve its parent, never the target itself, so a link is seen as a link.
        $parent_real = realpath(dirname($cursor));
        $base = $parent_real === false ? '' : rtrim(wp_normalize_path($parent_real), '/') . '/' . basename($cursor);
    }
    if ($base === '' || !wppilot_restore_point_path_within($base, $root) || rtrim($base, '/') === $root) {
        return wppilot_restore_point_error('wppilot_restore_point_target', 'The file this change touches is outside the site.');
    }

    return ['root' => 'site', 'rel' => ltrim(substr($base, strlen($root)), '/')];
}

/**
 * What an ability call will touch, from its input. Null for an ability restore points do not
 * cover; an empty list for an install, whose folder is only known from its result.
 *
 * @param array<string, mixed> $input
 * @return list<array{root: string, rel: string}>|WP_Error|null
 */
function wppilot_restore_point_targets_for(string $ability_name, array $input): array|WP_Error|null
{
    $shape = WPPILOT_RESTORE_POINT_ABILITIES[$ability_name] ?? null;
    if ($shape === null) {
        return null;
    }
    switch ($shape) {
        case 'install-plugin':
        case 'install-theme':
            return [];
        case 'plugin':
            $target = wppilot_restore_point_plugin_target((string) ($input['file'] ?? ''));
            return $target instanceof WP_Error ? $target : [$target];
        case 'theme':
            $slug = wppilot_restore_point_segment(trim((string) ($input['stylesheet'] ?? '')));
            return $slug === ''
                ? wppilot_restore_point_error('wppilot_restore_point_target', 'The theme could not be named safely.')
                : [['root' => 'themes', 'rel' => $slug]];
        case 'file':
            $target = wppilot_restore_point_site_target((string) ($input['path'] ?? ''));
            return $target instanceof WP_Error ? $target : [$target];
        case 'toggle-file':
            $path = (string) ($input['path'] ?? '');
            $original = str_ends_with($path, '.disabled') ? substr($path, 0, -strlen('.disabled')) : $path;
            $targets = [];
            foreach ([$original, $original . '.disabled'] as $candidate) {
                $target = wppilot_restore_point_site_target($candidate);
                if ($target instanceof WP_Error) {
                    return $target;
                }
                $targets[] = $target;
            }
            return $targets;
    }

    return null;
}

/** @return array{root: string, rel: string}|WP_Error */
function wppilot_restore_point_plugin_target(string $file): array|WP_Error
{
    $file = trim(wp_normalize_path($file), '/');
    $segments = $file === '' ? [] : explode('/', $file);
    $first = wppilot_restore_point_segment($segments[0] ?? '');
    if ($first === '' || !str_ends_with(strtolower($file), '.php') || str_contains($file, '..')) {
        return wppilot_restore_point_error('wppilot_restore_point_target', 'The plugin could not be named safely.');
    }

    // "folder/main.php" captures the folder; a single-file plugin ("hello.php") captures the file.
    return ['root' => 'plugins', 'rel' => $first];
}

// ---------------------------------------------------------------- capture

/**
 * The gate filter: take the restore point before the call runs, and refuse the call if it cannot
 * be taken.
 */
function wppilot_restore_point_pre_execute(mixed $input, mixed $ability = null): mixed
{
    if ($input instanceof WP_Error || !is_object($ability) || !method_exists($ability, 'get_name')) {
        return $input;
    }
    $name = (string) $ability->get_name();
    if (!isset(WPPILOT_RESTORE_POINT_ABILITIES[$name])) {
        return $input;
    }
    $targets = wppilot_restore_point_targets_for($name, is_array($input) ? wppilot_string_keyed_array($input) : []);
    if ($targets === []) {
        return $input;
    }
    $snapshot = $targets instanceof WP_Error ? $targets : wppilot_restore_point_create($name, (array) $targets);
    if ($snapshot instanceof WP_Error) {
        /**
         * Let a change run when its restore point cannot be taken. Off by default: the change would
         * then be recorded as impossible to undo, which is exactly what a restore point prevents.
         *
         * @param bool     $allow        False.
         * @param string   $ability_name The ability about to run.
         * @param WP_Error $error        Why no restore point could be taken.
         */
        if (apply_filters('wppilot_restore_point_allow_without_snapshot', false, $name, $snapshot) === true) {
            return $input;
        }

        return new WP_Error(
            'wppilot_restore_point_failed',
            sprintf(
                'GoBuildWP did not run %1$s because it could not first save a restore point to undo it with: %2$s Nothing was changed.',
                $name,
                $snapshot->get_error_message(),
            ),
            ['status' => 507],
        );
    }
    wppilot_restore_point_stash($name, $snapshot);

    return $input;
}

/**
 * The restore point the gate took for the call now running, handed to the ledger's before hook.
 *
 * @param array<string, mixed>|null $set
 * @return array<string, mixed>|null
 */
function wppilot_restore_point_stash(string $ability_name, ?array $set = null, bool $take = false): ?array
{
    /** @var array<string, array<string, mixed>> $stash */
    static $stash = [];
    if ($set !== null) {
        $previous = $stash[$ability_name] ?? null;
        if ($previous !== null) {
            // A call the gate passed but that never reached execute(): its restore point is unused.
            wppilot_restore_point_forget((string) ($previous['id'] ?? ''));
        }
        $stash[$ability_name] = $set;
        return $set;
    }
    $value = $stash[$ability_name] ?? null;
    if ($take) {
        unset($stash[$ability_name]);
    }

    return $value;
}

/**
 * The ledger before-image for a covered ability, or null when there is none.
 *
 * Takes the gate's restore point when it names the same targets. A call that reached execute()
 * without the gate (an internal call, the legacy adapter) gets a best-effort one here; a failure
 * then cannot refuse the call, so the row is recorded as not reversible.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|null
 */
function wppilot_restore_point_before_image(string $ability_name, array $input): ?array
{
    if (!isset(WPPILOT_RESTORE_POINT_ABILITIES[$ability_name])) {
        return null;
    }
    $targets = wppilot_restore_point_targets_for($ability_name, $input);
    $stashed = wppilot_restore_point_stash($ability_name, take: true);
    if ($targets instanceof WP_Error || $targets === null) {
        if ($stashed !== null) {
            wppilot_restore_point_forget((string) ($stashed['id'] ?? ''));
        }
        return null;
    }
    if ($targets === []) {
        return [
            'type' => WPPILOT_RESTORE_POINT_TYPE,
            'pending_install' => true,
            'ability' => $ability_name,
        ];
    }
    if ($stashed !== null) {
        if (wppilot_restore_point_target_keys(wppilot_restore_point_list($stashed['targets'] ?? null)) === wppilot_restore_point_target_keys($targets)) {
            return $stashed;
        }
        wppilot_restore_point_forget((string) ($stashed['id'] ?? ''));
    }
    $snapshot = wppilot_restore_point_create($ability_name, $targets);

    return $snapshot instanceof WP_Error ? null : $snapshot;
}

/**
 * @param list<array<string, mixed>> $targets
 * @return list<string>
 */
function wppilot_restore_point_target_keys(array $targets): array
{
    $keys = [];
    foreach ($targets as $target) {
        $keys[] = (string) ($target['root'] ?? '') . ':' . (string) ($target['rel'] ?? '');
    }
    sort($keys, SORT_STRING);

    return $keys;
}

/** @return list<array<string, mixed>> */
function wppilot_restore_point_list(mixed $value): array
{
    $out = [];
    foreach (is_array($value) ? $value : [] as $item) {
        if (is_array($item)) {
            $out[] = wppilot_string_keyed_array($item);
        }
    }

    return $out;
}

/**
 * Zip the targets into a new restore point.
 *
 * @param list<array{root: string, rel: string}> $targets
 * @param array{role?: string, expires_ts?: int, parent?: string, change_id?: string, as_absent?: bool} $options
 *        as_absent records the targets as not there (an install, captured after it ran), so undo removes them.
 * @return array<string, mixed>|WP_Error The ledger before-image.
 */
function wppilot_restore_point_create(string $ability_name, array $targets, array $options = []): array|WP_Error
{
    $resolved = [];
    foreach ($targets as $target) {
        $item = wppilot_restore_point_resolve((string) $target['root'], (string) $target['rel']);
        if ($item instanceof WP_Error) {
            return $item;
        }
        if (($options['as_absent'] ?? false) === true) {
            $item['kind'] = 'absent';
        }
        $resolved[] = $item;
    }
    if ($resolved === []) {
        return wppilot_restore_point_error('wppilot_restore_point_target', 'There was nothing to capture.');
    }

    $needs_zip = false;
    $scans = [];
    $bytes = 0;
    foreach ($resolved as $i => $item) {
        $scan = wppilot_restore_point_scan($item);
        $scans[$i] = $scan;
        $bytes += $scan['bytes'];
        $needs_zip = $needs_zip || $item['kind'] !== 'absent';
    }
    $max = (int) apply_filters('wppilot_restore_point_max_bytes', WPPILOT_RESTORE_POINT_MAX_BYTES);
    if ($bytes > $max) {
        return wppilot_restore_point_error('wppilot_restore_point_too_large', sprintf(
            'The files this change touches total %1$s, over the %2$s a single restore point may hold.',
            wppilot_restore_point_size($bytes),
            wppilot_restore_point_size($max),
        ));
    }
    if ($needs_zip && !class_exists('ZipArchive')) {
        return wppilot_restore_point_error('wppilot_restore_point_no_zip', 'The PHP zip extension (ZipArchive) is not installed on this server.');
    }
    $room = wppilot_restore_point_make_room($bytes);
    if ($room instanceof WP_Error) {
        return $room;
    }

    $storage = wppilot_restore_point_storage_dir();
    if (!wppilot_restore_point_protect_storage($storage)) {
        return wppilot_restore_point_error('wppilot_restore_point_storage', 'The restore-point folder under uploads could not be created or is not writable.');
    }
    $id = bin2hex(random_bytes(16));
    $dir = $storage . '/' . $id;
    if (!@mkdir($dir, 0755)) {
        return wppilot_restore_point_error('wppilot_restore_point_storage', 'The restore-point folder could not be created.');
    }
    @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");

    $zip_bytes = 0;
    if ($needs_zip) {
        // The plain zip lives only until it is sealed, and is never named .zip.
        $plain = $dir . '/.snapshot-' . bin2hex(random_bytes(8)) . '.tmp';
        $written = wppilot_restore_point_write_zip($plain, $resolved, $scans);
        if (!$written instanceof WP_Error) {
            $written = wppilot_restore_point_seal($plain, $dir . '/' . WPPILOT_RESTORE_POINT_SEALED);
        }
        @unlink($plain);
        if ($written instanceof WP_Error) {
            wppilot_restore_point_delete_tree($dir);
            return $written;
        }
        $zip_bytes = (int) @filesize($dir . '/' . WPPILOT_RESTORE_POINT_SEALED);
    }

    $now = time();
    $hours = wppilot_restore_point_hours();
    $expires_ts = (int) ($options['expires_ts'] ?? ($now + $hours * 3600));
    $manifest_targets = [];
    $summary_targets = [];
    $fingerprints = [];
    $files = 0;
    foreach ($resolved as $i => $item) {
        $scan = $scans[$i];
        $fingerprints[$item['root'] . ':' . $item['rel']] = wppilot_restore_point_scan_fingerprint($item['kind'], $scan);
        $files += count($scan['files']);
        $summary_targets[] = [
            'root' => $item['root'],
            'rel' => $item['rel'],
            'kind' => $item['kind'],
            'files' => count($scan['files']),
            'bytes' => $scan['bytes'],
            'skipped_links' => count($scan['links']),
        ];
        $manifest_targets[] = [
            'root' => $item['root'],
            'rel' => $item['rel'],
            'kind' => $item['kind'],
            'files' => $scan['files'],
            'dirs' => $scan['dirs'],
            'links' => $scan['links'],
        ];
    }
    ksort($fingerprints, SORT_STRING);
    $fingerprint = hash('sha256', (string) wp_json_encode($fingerprints));
    @file_put_contents($dir . '/manifest.json', (string) wp_json_encode(['id' => $id, 'targets' => $manifest_targets]));

    $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    $record = [
        'id' => $id,
        'role' => (string) ($options['role'] ?? 'before'),
        'parent' => (string) ($options['parent'] ?? ''),
        'change_id' => (string) ($options['change_id'] ?? ''),
        'ability' => $ability_name,
        'created_ts' => $now,
        'created_at' => gmdate('c', $now),
        'expires_ts' => $expires_ts,
        'expires_at' => gmdate('c', $expires_ts),
        'window_hours' => $hours,
        'user' => is_object($user) ? ['id' => (int) ($user->ID ?? 0), 'login' => (string) ($user->user_login ?? '')] : [],
        'agent' => function_exists('wppilot_current_agent') ? wppilot_current_agent() : [],
        'targets' => $summary_targets,
        'target_fingerprints' => $fingerprints,
        'fingerprint' => $fingerprint,
        'files' => $files,
        'bytes' => $bytes,
        'zip_bytes' => $zip_bytes,
        'has_zip' => $needs_zip,
        'active_plugins' => wppilot_restore_point_active_plugins($resolved),
        'attempts' => [],
    ];
    wppilot_restore_point_put($id, $record);

    return wppilot_restore_point_before_from_record($record);
}

/**
 * The ledger before-image: enough to find the restore point and compare states, no paths.
 *
 * @param array<string, mixed> $record
 * @return array<string, mixed>
 */
function wppilot_restore_point_before_from_record(array $record): array
{
    return [
        'type' => WPPILOT_RESTORE_POINT_TYPE,
        'id' => (string) ($record['id'] ?? ''),
        'role' => (string) ($record['role'] ?? 'before'),
        'ability' => (string) ($record['ability'] ?? ''),
        'targets' => wppilot_restore_point_list($record['targets'] ?? null),
        'fingerprint' => (string) ($record['fingerprint'] ?? ''),
        'created_at' => (string) ($record['created_at'] ?? ''),
        'expires_at' => (string) ($record['expires_at'] ?? ''),
    ];
}

/**
 * Every file under a target, with its size and sha256. Links are listed, never followed.
 *
 * @param array{root: string, rel: string, path: string, kind: string} $item
 * @return array{files: array<string, array{size: int, sha256: string}>, dirs: list<string>, links: array<string, string>, bytes: int}
 */
function wppilot_restore_point_scan(array $item): array
{
    $scan = ['files' => [], 'dirs' => [], 'links' => [], 'bytes' => 0];
    if ($item['kind'] === 'file') {
        $size = (int) filesize($item['path']);
        $scan['files'][''] = ['size' => $size, 'sha256' => (string) hash_file('sha256', $item['path'])];
        $scan['bytes'] = $size;
        return $scan;
    }
    if ($item['kind'] !== 'dir') {
        return $scan;
    }
    $storage = wppilot_restore_point_storage_dir();
    $stack = [''];
    while ($stack !== []) {
        $sub = array_pop($stack);
        $abs = $item['path'] . ($sub === '' ? '' : '/' . $sub);
        $names = @scandir($abs);
        if ($names === false) {
            continue;
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $rel = $sub === '' ? $name : $sub . '/' . $name;
            $path = $abs . '/' . $name;
            if ($storage !== '' && wppilot_restore_point_path_within($path, $storage)) {
                continue;
            }
            if (is_link($path)) {
                $scan['links'][$rel] = (string) @readlink($path);
                continue;
            }
            if (is_dir($path)) {
                $scan['dirs'][] = $rel;
                $stack[] = $rel;
                continue;
            }
            if (is_file($path)) {
                $size = (int) filesize($path);
                $scan['files'][$rel] = ['size' => $size, 'sha256' => (string) hash_file('sha256', $path)];
                $scan['bytes'] += $size;
            }
        }
    }
    ksort($scan['files'], SORT_STRING);
    sort($scan['dirs'], SORT_STRING);
    ksort($scan['links'], SORT_STRING);

    return $scan;
}

/**
 * A target's state as one hash: its kind and every file's path and content. Not mtimes, which a
 * restore cannot put back.
 *
 * @param array{files: array<string, array{size: int, sha256: string}>, dirs: list<string>, links: array<string, string>, bytes: int} $scan
 */
function wppilot_restore_point_scan_fingerprint(string $kind, array $scan): string
{
    if ($kind === 'absent') {
        return 'absent';
    }
    $files = [];
    foreach ($scan['files'] as $rel => $meta) {
        $files[] = [$rel, $meta['size'], $meta['sha256']];
    }

    return hash('sha256', (string) wp_json_encode([$kind, $files]));
}

/**
 * @param list<array{root: string, rel: string, path: string, kind: string}> $resolved
 * @param array<int, array{files: array<string, array{size: int, sha256: string}>, dirs: list<string>, links: array<string, string>, bytes: int}> $scans
 */
function wppilot_restore_point_write_zip(string $zip_path, array $resolved, array $scans): bool|WP_Error
{
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return wppilot_restore_point_error('wppilot_restore_point_zip', 'The restore-point zip could not be created.');
    }
    $expected = 0;
    foreach ($resolved as $i => $item) {
        if ($item['kind'] === 'absent') {
            continue;
        }
        $prefix = 't' . $i;
        if ($item['kind'] === 'file') {
            $zip->addFile($item['path'], $prefix . '/file');
            $expected++;
            continue;
        }
        $zip->addEmptyDir($prefix);
        $expected++;
        foreach ($scans[$i]['dirs'] as $dir) {
            $zip->addEmptyDir($prefix . '/' . $dir);
            $expected++;
        }
        foreach (array_keys($scans[$i]['files']) as $rel) {
            $zip->addFile($item['path'] . '/' . $rel, $prefix . '/' . $rel);
            $expected++;
        }
    }
    if (!$zip->close()) {
        return wppilot_restore_point_error('wppilot_restore_point_zip', 'The restore-point zip could not be written. The disk may be full.');
    }
    // Read it back: a zip that closed but lost entries would restore a partial folder.
    $check = new ZipArchive();
    if ($check->open($zip_path, ZipArchive::RDONLY) !== true) {
        return wppilot_restore_point_error('wppilot_restore_point_zip', 'The restore-point zip could not be read back.');
    }
    $count = $check->numFiles;
    $check->close();
    if ($count !== $expected) {
        return wppilot_restore_point_error('wppilot_restore_point_zip', 'The restore-point zip is incomplete.');
    }

    return true;
}

/**
 * Plugins under the targets that are active now, so a restore can switch them back on — and only
 * them.
 *
 * @param list<array{root: string, rel: string, path: string, kind: string}> $resolved
 * @return list<string>
 */
function wppilot_restore_point_active_plugins(array $resolved): array
{
    $active = [];
    foreach ($resolved as $item) {
        if ($item['root'] !== 'plugins' || $item['kind'] === 'absent') {
            continue;
        }
        foreach (wppilot_restore_point_plugin_files($item['rel']) as $file) {
            if (function_exists('is_plugin_active') && is_plugin_active($file)) {
                $active[] = $file;
            }
        }
    }

    return $active;
}

/** @return list<string> Installed plugin files inside a plugins-root target. */
function wppilot_restore_point_plugin_files(string $rel): array
{
    if (!function_exists('get_plugins') && is_file(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!function_exists('get_plugins')) {
        return [];
    }
    $files = [];
    foreach (array_keys((array) get_plugins()) as $file) {
        $file = (string) $file;
        if ($file === $rel || str_starts_with($file, $rel . '/')) {
            $files[] = $file;
        }
    }

    return $files;
}

/**
 * Keep restore-point storage under the site cap: expired points go first, then the oldest.
 */
function wppilot_restore_point_make_room(int $incoming): bool|WP_Error
{
    $cap = (int) apply_filters('wppilot_restore_point_total_bytes', WPPILOT_RESTORE_POINT_TOTAL_BYTES);
    if ($incoming > $cap) {
        return wppilot_restore_point_error('wppilot_restore_point_too_large', sprintf(
            'The files this change touches total %1$s, over the %2$s this site keeps for restore points.',
            wppilot_restore_point_size($incoming),
            wppilot_restore_point_size($cap),
        ));
    }
    $index = wppilot_restore_point_index();
    $used = 0;
    foreach ($index as $record) {
        $used += (int) ($record['zip_bytes'] ?? 0);
    }
    if ($used + $incoming <= $cap) {
        return true;
    }
    $now = time();
    uasort($index, static function (array $a, array $b) use ($now): int {
        $a_expired = (int) ($a['expires_ts'] ?? 0) <= $now ? 0 : 1;
        $b_expired = (int) ($b['expires_ts'] ?? 0) <= $now ? 0 : 1;

        return [$a_expired, (int) ($a['created_ts'] ?? 0)] <=> [$b_expired, (int) ($b['created_ts'] ?? 0)];
    });
    foreach ($index as $id => $record) {
        if ($used + $incoming <= $cap) {
            break;
        }
        $used -= (int) ($record['zip_bytes'] ?? 0);
        wppilot_restore_point_forget($id);
    }

    return true;
}

// ---------------------------------------------------------------- ledger strategy

/**
 * Build the ledger payload once the call succeeded. An install's folder is only known now, from
 * its result; its restore point records the folder as absent, so undo removes it.
 *
 * @param array<string, mixed> $before
 * @return array<string, mixed>
 */
function wppilot_restore_point_strategy_build(array $before, mixed $result, string $ability_name): array
{
    if (($before['pending_install'] ?? false) === true) {
        $values = is_array($result) ? wppilot_string_keyed_array($result) : [];
        $target = $ability_name === 'wppilot/install-plugin'
            ? wppilot_restore_point_plugin_target((string) ($values['file'] ?? ''))
            : ['root' => 'themes', 'rel' => wppilot_restore_point_segment((string) ($values['stylesheet'] ?? ''))];
        if ($target instanceof WP_Error || $target['rel'] === '') {
            return ['reversible' => false, 'reason' => wppilot_extension_files_rollback_reason($ability_name)];
        }
        $snapshot = wppilot_restore_point_create($ability_name, [$target], ['as_absent' => true]);
        if ($snapshot instanceof WP_Error) {
            return ['reversible' => false, 'reason' => wppilot_extension_files_rollback_reason($ability_name) . ' ' . $snapshot->get_error_message()];
        }
        $before = $snapshot;
    }
    $id = (string) ($before['id'] ?? '');
    $record = wppilot_restore_point_get($id);
    if ($record === null) {
        return ['reversible' => false, 'reason' => 'The restore point for this change was not kept.'];
    }
    // The state the change left: undo refuses once the targets no longer look like this.
    $record['after_fingerprints'] = wppilot_restore_point_current_fingerprints($record);
    wppilot_restore_point_put($id, $record);

    return ['snapshot' => wppilot_restore_point_before_from_record($record), 'expires_at' => (string) ($record['expires_at'] ?? '')];
}

/**
 * Undo (or redo) a ledger row from its restore point.
 *
 * @param array<string, mixed> $rollback
 * @param array<string, mixed> $entry
 * @return array<string, mixed>|WP_Error
 */
function wppilot_restore_point_strategy_restore(array $rollback, array $entry): array|WP_Error
{
    $snapshot = wppilot_string_keyed_array($rollback['snapshot'] ?? null);
    $op = ($snapshot['role'] ?? 'before') === 'redo' ? 'redo' : 'undo';

    return wppilot_restore_point_restore((string) ($snapshot['id'] ?? ''), $op, (string) ($entry['ability'] ?? ''), ($entry['force'] ?? false) === true);
}

/**
 * Put a restore point back.
 *
 * Every target is staged first, beside itself, and checked against the hashes taken at capture.
 * Only then are the live copies renamed aside and the staged ones renamed in; a rename that fails
 * puts back the ones already swapped. The aside copies are deleted last.
 *
 * @return array<string, mixed>|WP_Error
 */
function wppilot_restore_point_restore(string $id, string $op = 'undo', string $ability_name = '', bool $force = false): array|WP_Error
{
    $record = wppilot_restore_point_get($id);
    $manual = wppilot_restore_point_manual_path($ability_name !== '' ? $ability_name : (string) ($record['ability'] ?? ''));
    if ($record === null) {
        return wppilot_restore_point_error('wppilot_restore_point_missing', 'The restore point for this change is no longer kept, so it cannot be undone automatically. ' . $manual);
    }
    if ((int) ($record['expires_ts'] ?? 0) <= time()) {
        $error = wppilot_restore_point_error('wppilot_restore_point_expired', sprintf(
            'The restore point for this change expired at %1$s, so it cannot be undone automatically. %2$s',
            (string) ($record['expires_at'] ?? ''),
            $manual,
        ));
        wppilot_restore_point_record_attempt($id, $op, $error);
        return $error;
    }
    // Putting the old files back would also wipe whatever changed after the AI's change: a later
    // edit, or files someone added to a folder the change created. Refused unless forced.
    $after = wppilot_string_keyed_array($record['after_fingerprints'] ?? null);
    if (!$force && $after !== []) {
        $now = wppilot_restore_point_current_fingerprints($record);
        $changed = [];
        foreach ($after as $key => $fingerprint) {
            if (($now[$key] ?? null) !== $fingerprint) {
                $changed[] = (string) $key;
            }
        }
        if ($changed !== []) {
            $error = wppilot_restore_point_error('wppilot_restore_point_changed_since', sprintf(
                '%s changed after this change was made, so undoing it would overwrite those later changes. Nothing was changed. Undo with force=true to put the earlier version back anyway.',
                implode(', ', $changed),
            ));
            wppilot_restore_point_record_attempt($id, $op, $error);
            return $error;
        }
    }
    $result = wppilot_restore_point_apply($id, $record);
    wppilot_restore_point_record_attempt($id, $op, $result);
    if ($result instanceof WP_Error) {
        return new WP_Error($result->get_error_code(), $result->get_error_message() . ' ' . $manual, $result->get_error_data());
    }

    return $result;
}

/**
 * @param array<string, mixed> $record
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity -- Stage, swap, roll back and verify are one transaction.
function wppilot_restore_point_apply(string $id, array $record): array|WP_Error
{
    $dir = wppilot_restore_point_dir_for($id);
    $manifest = $dir !== '' && is_file($dir . '/manifest.json') ? json_decode((string) file_get_contents($dir . '/manifest.json'), true) : null;
    if (!is_array($manifest) || !is_array($manifest['targets'] ?? null)) {
        return wppilot_restore_point_error('wppilot_restore_point_missing', 'The restore point\'s file list is missing.');
    }
    $zip = null;
    $opened = '';
    if (($record['has_zip'] ?? false) === true) {
        if (!class_exists('ZipArchive') || !is_file($dir . '/' . WPPILOT_RESTORE_POINT_SEALED)) {
            return wppilot_restore_point_error('wppilot_restore_point_missing', 'The restore point\'s zip is missing or ZipArchive is not installed.');
        }
        $opened = $dir . '/.open-' . bin2hex(random_bytes(8)) . '.tmp';
        $unsealed = wppilot_restore_point_unseal($dir . '/' . WPPILOT_RESTORE_POINT_SEALED, $opened);
        if ($unsealed instanceof WP_Error) {
            @unlink($opened);
            return $unsealed;
        }
        $zip = new ZipArchive();
        if ($zip->open($opened, ZipArchive::RDONLY) !== true) {
            @unlink($opened);
            return wppilot_restore_point_error('wppilot_restore_point_zip', 'The restore point\'s zip could not be opened.');
        }
    }
    $expected = wppilot_string_keyed_array($record['target_fingerprints'] ?? null);
    $token = bin2hex(random_bytes(6));
    $plan = [];
    $error = null;

    // 1. Resolve and stage every target.
    foreach (array_values($manifest['targets']) as $i => $target) {
        $target = wppilot_string_keyed_array($target);
        $item = wppilot_restore_point_resolve((string) ($target['root'] ?? ''), (string) ($target['rel'] ?? ''));
        if ($item instanceof WP_Error) {
            $error = $item;
            break;
        }
        $want = (string) ($target['kind'] ?? 'absent');
        $staged = '';
        if ($want !== 'absent') {
            $staged = dirname($item['path']) . '/.gobuildwp-restore-' . $token . '-' . $i;
            $extracted = $zip instanceof ZipArchive
                ? wppilot_restore_point_extract($zip, 't' . $i, $want, $staged, wppilot_string_keyed_array($target['links'] ?? null))
                : wppilot_restore_point_error('wppilot_restore_point_zip', 'The restore point has no zip.');
            if ($extracted instanceof WP_Error) {
                $error = $extracted;
                wppilot_restore_point_delete_tree($staged);
                break;
            }
            $staged_scan = wppilot_restore_point_scan(['root' => $item['root'], 'rel' => $item['rel'], 'path' => $staged, 'kind' => $want]);
            $key = $item['root'] . ':' . $item['rel'];
            if (wppilot_restore_point_scan_fingerprint($want, $staged_scan) !== (string) ($expected[$key] ?? '')) {
                $error = wppilot_restore_point_error('wppilot_restore_point_corrupt', 'The restore point\'s files do not match the hashes taken when it was saved. Nothing was changed.');
                wppilot_restore_point_delete_tree($staged);
                break;
            }
        }
        $plan[] = ['item' => $item, 'want' => $want, 'staged' => $staged, 'aside' => ''];
    }
    if ($zip instanceof ZipArchive) {
        $zip->close();
        @unlink($opened);
    }
    if ($error === null) {
        $error = wppilot_restore_point_preflight($plan);
    }
    if ($error !== null) {
        foreach ($plan as $step) {
            wppilot_restore_point_delete_tree($step['staged']);
        }
        return $error;
    }

    // 2. Swap. Nothing live has been touched until here.
    $done = [];
    foreach ($plan as $n => $step) {
        $path = $step['item']['path'];
        if (file_exists($path) || is_link($path)) {
            $aside = dirname($path) . '/.gobuildwp-aside-' . $token . '-' . $n;
            if (!@rename($path, $aside)) {
                $error = wppilot_restore_point_error('wppilot_restore_point_swap', 'A file or folder could not be moved aside; it may be in use or not writable.');
                break;
            }
            $plan[$n]['aside'] = $aside;
            $step['aside'] = $aside;
        }
        if ($step['staged'] !== '' && !@rename($step['staged'], $path)) {
            if ($step['aside'] !== '') {
                @rename($step['aside'], $path);
                $plan[$n]['aside'] = '';
            }
            $error = wppilot_restore_point_error('wppilot_restore_point_swap', 'The restored copy could not be moved into place.');
            break;
        }
        $plan[$n]['staged'] = '';
        $done[] = $n;
    }
    if ($error !== null) {
        foreach (array_reverse($done) as $n) {
            $path = $plan[$n]['item']['path'];
            $put_back = dirname($path) . '/.gobuildwp-undo-' . $token . '-' . $n;
            if (file_exists($path)) {
                @rename($path, $put_back);
            }
            if ($plan[$n]['aside'] !== '') {
                @rename($plan[$n]['aside'], $path);
            }
            wppilot_restore_point_delete_tree($put_back);
        }
        foreach ($plan as $step) {
            wppilot_restore_point_delete_tree($step['staged']);
        }
        return $error;
    }

    // 3. Clean up and switch back on what was on.
    foreach ($plan as $step) {
        if ($step['aside'] !== '') {
            wppilot_restore_point_delete_tree($step['aside']);
        }
        if ($step['want'] === 'dir' && function_exists('wp_opcache_invalidate_directory')) {
            wp_opcache_invalidate_directory($step['item']['path']);
        } elseif ($step['want'] === 'file' && function_exists('opcache_invalidate')) {
            @opcache_invalidate($step['item']['path'], true);
        }
    }
    if (function_exists('wp_clean_plugins_cache')) {
        wp_clean_plugins_cache();
    }
    if (function_exists('wp_clean_themes_cache')) {
        wp_clean_themes_cache();
    }
    $reactivated = wppilot_restore_point_reactivate(wppilot_string_list($record['active_plugins'] ?? null));

    // 4. Read back.
    $now = wppilot_restore_point_current_fingerprints($record);
    $mismatched = [];
    foreach ($expected as $key => $fingerprint) {
        if (($now[$key] ?? null) !== $fingerprint) {
            $mismatched[] = $key;
        }
    }

    return [
        'result' => 'restored',
        'targets' => count($plan),
        'files' => (int) ($record['files'] ?? 0),
        'reactivated' => $reactivated,
        'verified' => $mismatched === [],
        'mismatched' => $mismatched,
    ];
}

/**
 * What must be true before anything live moves: an installed plugin being removed is switched
 * off first; the active theme is never removed.
 *
 * @param list<array<string, mixed>> $plan
 */
function wppilot_restore_point_preflight(array $plan): ?WP_Error
{
    foreach ($plan as $step) {
        /** @var array{root: string, rel: string, path: string, kind: string} $item */
        $item = $step['item'];
        if ($item['kind'] === 'absent') {
            continue;
        }
        if ($item['root'] === 'themes' && $step['want'] === 'absent' && function_exists('get_stylesheet')
            && in_array($item['rel'], [get_stylesheet(), get_template()], strict: true)) {
            return wppilot_restore_point_error('wppilot_restore_point_active_theme', 'This theme is the active theme (or its parent). Switch to another theme first, then undo again.');
        }
        if ($item['root'] === 'plugins' && $step['want'] === 'absent') {
            $files = wppilot_restore_point_plugin_files($item['rel']);
            if ($files !== [] && function_exists('deactivate_plugins')) {
                deactivate_plugins($files, true);
            }
        }
    }

    return null;
}

/**
 * @param list<string> $files Plugins that were active when the restore point was taken.
 * @return list<string>
 */
function wppilot_restore_point_reactivate(array $files): array
{
    $done = [];
    foreach ($files as $file) {
        if (!function_exists('is_plugin_active') || !function_exists('activate_plugin') || is_plugin_active($file)) {
            continue;
        }
        $activated = activate_plugin($file, '', false, true);
        if (!is_wp_error($activated)) {
            $done[] = $file;
        }
    }

    return $done;
}

/**
 * Extract one target from the zip into a staging path, refusing any entry name that could land
 * outside it.
 *
 * @param array<string, mixed> $links
 */
function wppilot_restore_point_extract(ZipArchive $zip, string $prefix, string $kind, string $staged, array $links): bool|WP_Error
{
    if (file_exists($staged) || is_link($staged)) {
        return wppilot_restore_point_error('wppilot_restore_point_stage', 'A staging path already exists.');
    }
    if ($kind === 'file') {
        return wppilot_restore_point_extract_entry($zip, $prefix . '/file', $staged)
            ? true
            : wppilot_restore_point_error('wppilot_restore_point_stage', 'The file could not be extracted from the restore point.');
    }
    if (!@mkdir($staged, 0755)) {
        return wppilot_restore_point_error('wppilot_restore_point_stage', 'A staging folder could not be created beside the target.');
    }
    $lead = $prefix . '/';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (!str_starts_with($name, $lead)) {
            continue;
        }
        $rel = substr($name, strlen($lead));
        if ($rel === '' || $rel === false) {
            continue;
        }
        $is_dir = str_ends_with($rel, '/');
        $rel = rtrim($rel, '/');
        if (!wppilot_restore_point_safe_entry($rel)) {
            return wppilot_restore_point_error('wppilot_restore_point_stage', 'The restore point contains an unsafe file name.');
        }
        $dest = $staged . '/' . $rel;
        if ($is_dir) {
            if (!is_dir($dest) && !@mkdir($dest, 0755, true)) {
                return wppilot_restore_point_error('wppilot_restore_point_stage', 'A folder could not be recreated.');
            }
            continue;
        }
        if ((!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) || !wppilot_restore_point_extract_entry($zip, $name, $dest)) {
            return wppilot_restore_point_error('wppilot_restore_point_stage', 'A file could not be extracted from the restore point.');
        }
    }
    // Links inside the folder were recorded, not followed or zipped; put them back as they were.
    foreach ($links as $rel => $target) {
        if (is_string($rel) && wppilot_restore_point_safe_entry($rel) && is_string($target) && $target !== '' && function_exists('symlink')) {
            @symlink($target, $staged . '/' . $rel);
        }
    }

    return true;
}

function wppilot_restore_point_safe_entry(string $rel): bool
{
    if ($rel === '' || str_contains($rel, '\\') || str_contains($rel, "\0") || str_starts_with($rel, '/') || preg_match('#^[A-Za-z]:#', $rel) === 1) {
        return false;
    }
    foreach (explode('/', $rel) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }
    }

    return true;
}

/** Stream one entry to disk, so a large file never sits in memory whole. */
function wppilot_restore_point_extract_entry(ZipArchive $zip, string $name, string $dest): bool
{
    $in = $zip->getStream($name);
    if ($in === false) {
        return false;
    }
    $out = @fopen($dest, 'xb');
    if ($out === false) {
        fclose($in);
        return false;
    }
    $copied = stream_copy_to_stream($in, $out);
    fclose($in);

    return fclose($out) && $copied !== false;
}

/**
 * Each target's current fingerprint, keyed like `target_fingerprints`.
 *
 * @param array<string, mixed> $record
 * @return array<string, string>
 */
function wppilot_restore_point_current_fingerprints(array $record): array
{
    $out = [];
    foreach (wppilot_restore_point_list($record['targets'] ?? null) as $target) {
        $key = (string) ($target['root'] ?? '') . ':' . (string) ($target['rel'] ?? '');
        $item = wppilot_restore_point_resolve((string) ($target['root'] ?? ''), (string) ($target['rel'] ?? ''));
        $out[$key] = $item instanceof WP_Error ? 'unreadable' : wppilot_restore_point_scan_fingerprint($item['kind'], wppilot_restore_point_scan($item));
    }
    ksort($out, SORT_STRING);

    return $out;
}

/**
 * The targets' current state as one fingerprint, comparable with a restore point's.
 *
 * @param array<string, mixed> $record
 */
function wppilot_restore_point_current_fingerprint(array $record): string
{
    return hash('sha256', (string) wp_json_encode(wppilot_restore_point_current_fingerprints($record)));
}

/** @param array<string, mixed>|WP_Error $result */
function wppilot_restore_point_record_attempt(string $id, string $op, array|WP_Error $result): void
{
    $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    $attempt = [
        'at' => gmdate('c'),
        'op' => $op,
        'result' => $result instanceof WP_Error ? 'failed' : (($result['verified'] ?? false) === true ? 'restored' : 'unverified'),
        'error' => $result instanceof WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '',
        'mismatched' => $result instanceof WP_Error ? [] : wppilot_string_list($result['mismatched'] ?? null),
        'user' => is_object($user) ? (string) ($user->user_login ?? '') : '',
    ];
    wppilot_with_restore_point_lock(static function () use ($id, $attempt): void {
        $index = wppilot_restore_point_index();
        if (!isset($index[$id])) {
            return;
        }
        $attempts = wppilot_restore_point_list($index[$id]['attempts'] ?? null);
        $attempts[] = $attempt;
        $index[$id]['attempts'] = array_slice($attempts, -WPPILOT_RESTORE_POINT_MAX_ATTEMPTS);
        wppilot_restore_point_save_index($index);
    });
}

/** Tie a restore point to the ledger row it undoes, so the record names its change. */
function wppilot_restore_point_link(string $id, string $change_id): void
{
    wppilot_with_restore_point_lock(static function () use ($id, $change_id): void {
        $index = wppilot_restore_point_index();
        if (isset($index[$id]) && ($index[$id]['change_id'] ?? '') === '') {
            $index[$id]['change_id'] = $change_id;
            wppilot_restore_point_save_index($index);
        }
    });
}

// ---------------------------------------------------------------- redo and sessions

/**
 * The redo image for an undo about to run: a second restore point of the AI's version, with the
 * first one's expiry, so redo is available exactly as long as undo was.
 *
 * @param array<string, mixed> $rollback
 * @return array{available: bool, reason?: string, snapshot?: array<string, mixed>}
 */
function wppilot_restore_point_redo_image(array $rollback): array
{
    $snapshot = wppilot_string_keyed_array($rollback['snapshot'] ?? null);
    $record = wppilot_restore_point_get((string) ($snapshot['id'] ?? ''));
    if ($record === null || (int) ($record['expires_ts'] ?? 0) <= time()) {
        return ['available' => false, 'reason' => 'The restore point has expired or is gone, so this undo cannot be redone.'];
    }
    $targets = [];
    foreach (wppilot_restore_point_list($record['targets'] ?? null) as $target) {
        $targets[] = ['root' => (string) ($target['root'] ?? ''), 'rel' => (string) ($target['rel'] ?? '')];
    }
    $redo = wppilot_restore_point_create((string) ($record['ability'] ?? ''), $targets, [
        'role' => 'redo',
        'parent' => (string) $record['id'],
        'change_id' => (string) ($record['change_id'] ?? ''),
        'expires_ts' => (int) $record['expires_ts'],
    ]);
    if ($redo instanceof WP_Error) {
        return ['available' => false, 'reason' => 'No copy of the changed files could be kept, so this undo cannot be redone: ' . $redo->get_error_message()];
    }

    return ['available' => true, 'snapshot' => $redo];
}

/**
 * @param array<string, mixed>|null $state
 * @param array<string, mixed>       $rollback
 * @return array<string, mixed>|null
 */
function wppilot_restore_point_filter_current_state(mixed $state, mixed $rollback): mixed
{
    if (!is_array($rollback) || ($rollback['type'] ?? '') !== WPPILOT_RESTORE_POINT_TYPE) {
        return $state;
    }
    $snapshot = wppilot_string_keyed_array($rollback['snapshot'] ?? null);
    $record = wppilot_restore_point_get((string) ($snapshot['id'] ?? ''));
    if ($record === null) {
        return $state;
    }

    return ['type' => WPPILOT_RESTORE_POINT_TYPE, 'fingerprint' => wppilot_restore_point_current_fingerprint($record)];
}

function wppilot_restore_point_filter_payload_target(mixed $target, mixed $rollback): mixed
{
    if (!is_array($rollback) || ($rollback['type'] ?? '') !== WPPILOT_RESTORE_POINT_TYPE) {
        return $target;
    }
    $snapshot = wppilot_string_keyed_array($rollback['snapshot'] ?? null);
    $keys = wppilot_restore_point_target_keys(wppilot_restore_point_list($snapshot['targets'] ?? null));

    return $keys === [] ? $target : 'files:' . wppilot_change_key_list($keys);
}

// ---------------------------------------------------------------- reporting

/**
 * What get-change and Cloud show about a row's restore point. Never a server path or the storage
 * id: undo finds the restore point from the ledger row, so nobody outside needs either.
 *
 * @param array<string, mixed> $rollback
 * @return array<string, mixed>|null
 */
function wppilot_restore_point_public(array $rollback, bool $with_files = false): ?array
{
    if (($rollback['type'] ?? '') !== WPPILOT_RESTORE_POINT_TYPE) {
        return null;
    }
    $snapshot = wppilot_string_keyed_array($rollback['snapshot'] ?? null);
    $id = (string) ($snapshot['id'] ?? '');
    $record = wppilot_restore_point_get($id);
    if ($record === null) {
        return [
            'status' => 'removed',
            'expires_at' => (string) ($snapshot['expires_at'] ?? ''),
            'targets' => wppilot_restore_point_list($snapshot['targets'] ?? null),
        ];
    }
    $expired = (int) ($record['expires_ts'] ?? 0) <= time();
    $out = [
        'status' => $expired ? 'expired' : 'available',
        'role' => (string) ($record['role'] ?? 'before'),
        'change_id' => (string) ($record['change_id'] ?? ''),
        'ability' => (string) ($record['ability'] ?? ''),
        'created_at' => (string) ($record['created_at'] ?? ''),
        'expires_at' => (string) ($record['expires_at'] ?? ''),
        'window_hours' => (int) ($record['window_hours'] ?? 0),
        'user' => $record['user'] ?? [],
        'agent' => $record['agent'] ?? [],
        'targets' => wppilot_restore_point_list($record['targets'] ?? null),
        'files' => (int) ($record['files'] ?? 0),
        'bytes' => (int) ($record['bytes'] ?? 0),
        'zip_bytes' => (int) ($record['zip_bytes'] ?? 0),
        'fingerprint' => (string) ($record['fingerprint'] ?? ''),
        'active_plugins' => wppilot_string_list($record['active_plugins'] ?? null),
        'attempts' => wppilot_restore_point_list($record['attempts'] ?? null),
    ];
    if ($with_files) {
        $out['file_list'] = wppilot_restore_point_file_list($id, 200);
    }

    return $out;
}

/**
 * The captured files with their sizes and hashes, for debugging a restore. Paths are relative
 * to the target.
 *
 * @return array{listed: list<array<string, mixed>>, truncated: bool}
 */
function wppilot_restore_point_file_list(string $id, int $limit): array
{
    $dir = wppilot_restore_point_dir_for($id);
    $manifest = $dir !== '' && is_file($dir . '/manifest.json') ? json_decode((string) file_get_contents($dir . '/manifest.json'), true) : null;
    $listed = [];
    $total = 0;
    foreach (is_array($manifest) && is_array($manifest['targets'] ?? null) ? $manifest['targets'] : [] as $target) {
        $target = wppilot_string_keyed_array($target);
        $prefix = (string) ($target['root'] ?? '') . ':' . (string) ($target['rel'] ?? '');
        foreach (wppilot_string_keyed_array($target['files'] ?? null) as $rel => $meta) {
            $total++;
            if (count($listed) < $limit) {
                $meta = wppilot_string_keyed_array($meta);
                $listed[] = [
                    'path' => $prefix . ($rel === '' ? '' : '/' . $rel),
                    'size' => (int) ($meta['size'] ?? 0),
                    'sha256' => (string) ($meta['sha256'] ?? ''),
                ];
            }
        }
    }

    return ['listed' => $listed, 'truncated' => $total > count($listed)];
}

/**
 * Undo-window fields for the change list: until when the row can be undone, and the window the
 * plan gave it. After expiry the row reads as not reversible, with the manual path.
 *
 * @param array<string, mixed> $entry
 * @return array<string, mixed>
 */
function wppilot_restore_point_summary_fields(array $entry): array
{
    $rollback = is_array($entry['rollback'] ?? null) ? wppilot_string_keyed_array($entry['rollback']) : [];
    if (($rollback['type'] ?? '') !== WPPILOT_RESTORE_POINT_TYPE || ($rollback['reversible'] ?? false) !== true) {
        return [];
    }
    $snapshot = wppilot_string_keyed_array($rollback['snapshot'] ?? null);
    $record = wppilot_restore_point_get((string) ($snapshot['id'] ?? ''));
    $fields = [
        'undo_until' => (string) ($record['expires_at'] ?? $snapshot['expires_at'] ?? ''),
        'undo_window_hours' => (int) ($record['window_hours'] ?? 0),
    ];
    if (($entry['rolled_back'] ?? false) === true) {
        return $fields;
    }
    if ($record === null || (int) ($record['expires_ts'] ?? 0) <= time()) {
        $fields['reversible'] = false;
        $fields['rollback_reason'] = 'The restore point has expired. ' . wppilot_restore_point_manual_path((string) ($entry['ability'] ?? ''));
    }

    return $fields;
}

/** What a person can do by hand once the restore point is gone. */
function wppilot_restore_point_manual_path(string $ability_name): string
{
    return match ($ability_name) {
        'wppilot/install-plugin' => 'Remove the plugin with wppilot/delete-plugin or from Plugins in wp-admin.',
        'wppilot/install-theme' => 'Remove the theme with wppilot/delete-theme or from Appearance > Themes.',
        'wppilot/update-plugin', 'wppilot/delete-plugin' => 'Reinstall the earlier version from its own source (WordPress.org "Advanced View" lists past releases) or restore it from a site backup.',
        'wppilot/update-theme', 'wppilot/delete-theme' => 'Reinstall the earlier version of the theme from its own source or restore it from a site backup.',
        default => 'Restore the file from a site backup or your own copy.',
    };
}

// ---------------------------------------------------------------- cleanup

/**
 * Daily: remove expired restore points, ones no ledger row claimed (their call failed), folders
 * the index no longer names, and staging leftovers of an interrupted restore.
 *
 * @return array{removed: int}
 */
function wppilot_restore_points_cleanup(?int $now = null): array
{
    $now ??= time();
    $removed = 0;
    foreach (wppilot_restore_point_index() as $id => $record) {
        $expired = (int) ($record['expires_ts'] ?? 0) <= $now;
        $orphan = ($record['change_id'] ?? '') === '' && (int) ($record['created_ts'] ?? 0) < $now - WPPILOT_RESTORE_POINT_ORPHAN_SECONDS;
        if ($expired || $orphan) {
            wppilot_restore_point_forget($id);
            $removed++;
        }
    }
    $storage = wppilot_restore_point_storage_dir();
    $known = wppilot_restore_point_index();
    foreach ($storage !== '' && is_dir($storage) ? (array) scandir($storage) : [] as $name) {
        $name = (string) $name;
        $path = $storage . '/' . $name;
        if (preg_match('/^[a-f0-9]{32}$/', $name) === 1 && !isset($known[$name]) && is_dir($path) && (int) @filemtime($path) < $now - 86400) {
            wppilot_restore_point_delete_tree($path);
            $removed++;
        }
    }
    foreach (array_intersect_key(wppilot_restore_point_roots(), ['plugins' => true, 'themes' => true]) as $root) {
        foreach ((array) scandir($root) as $name) {
            $name = (string) $name;
            $path = $root . '/' . $name;
            if (preg_match('/^\.gobuildwp-(restore|aside|undo)-[a-f0-9]{12}-\d+$/', $name) === 1 && (int) @filemtime($path) < $now - 86400) {
                wppilot_restore_point_delete_tree($path);
                $removed++;
            }
        }
    }

    return ['removed' => $removed];
}

/** Delete a file or folder without following links out of it. */
function wppilot_restore_point_delete_tree(string $path): void
{
    if ($path === '') {
        return;
    }
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        // A link to a directory on Windows is removed with rmdir.
        if (is_link($path)) {
            @rmdir($path);
        }
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach ((array) scandir($path) as $name) {
        $name = (string) $name;
        if ($name !== '.' && $name !== '..') {
            wppilot_restore_point_delete_tree($path . '/' . $name);
        }
    }
    @rmdir($path);
}

/** Every restore point and its storage, for uninstall. */
function wppilot_restore_points_delete_all(): void
{
    $storage = wppilot_restore_point_storage_dir();
    if ($storage !== '' && is_dir($storage)) {
        wppilot_restore_point_delete_tree($storage);
    }
    delete_option(WPPILOT_RESTORE_POINT_INDEX_OPTION);
    delete_option(WPPILOT_RESTORE_POINT_HOURS_OPTION);
}

function wppilot_restore_point_size(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) ceil($bytes / 1024)) . ' KB';
}

// ---------------------------------------------------------------- encryption

/**
 * The key restore points are encrypted with, derived from the auth salt in wp-config.php: whoever
 * can read that can already read the site's files. Changing the salt makes existing restore
 * points unreadable, and their undo fails closed.
 */
function wppilot_restore_point_key(): string|WP_Error
{
    if (!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push') || !function_exists('wp_salt')) {
        return wppilot_restore_point_error('wppilot_restore_point_no_sodium', 'The PHP sodium extension is not available, so restore points cannot be encrypted.');
    }

    return sodium_crypto_generichash('gobuildwp-restore-point|' . wp_salt('auth'), '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
}

/**
 * Encrypt $plain into $sealed in chunks: the stream header, then each chunk's length and
 * ciphertext. The last chunk carries the final tag, so a truncated file never decrypts.
 */
function wppilot_restore_point_seal(string $plain, string $sealed): bool|WP_Error
{
    $key = wppilot_restore_point_key();
    if ($key instanceof WP_Error) {
        return $key;
    }
    $in = @fopen($plain, 'rb');
    $out = @fopen($sealed, 'wb');
    if ($in === false || $out === false) {
        if ($in !== false) {
            fclose($in);
        }
        if ($out !== false) {
            fclose($out);
        }
        return wppilot_restore_point_error('wppilot_restore_point_storage', 'The restore point could not be encrypted.');
    }
    [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
    $ok = fwrite($out, $header) === strlen($header);
    $left = (int) @filesize($plain);
    do {
        $chunk = (string) fread($in, WPPILOT_RESTORE_POINT_CHUNK);
        $left -= strlen($chunk);
        $final = $left <= 0 || $chunk === '';
        $tag = $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
        $ok = $ok && fwrite($out, pack('N', strlen($cipher)) . $cipher) === 4 + strlen($cipher);
    } while ($ok && !$final);
    fclose($in);
    $ok = fclose($out) && $ok;
    sodium_memzero($key);

    return $ok ? true : wppilot_restore_point_error('wppilot_restore_point_storage', 'The encrypted restore point could not be written.');
}

/** Decrypt $sealed into $plain; refuses a file that was changed, cut short or sealed with another key. */
function wppilot_restore_point_unseal(string $sealed, string $plain): bool|WP_Error
{
    $key = wppilot_restore_point_key();
    if ($key instanceof WP_Error) {
        return $key;
    }
    $in = @fopen($sealed, 'rb');
    $out = @fopen($plain, 'wb');
    if ($in === false || $out === false) {
        if ($in !== false) {
            fclose($in);
        }
        if ($out !== false) {
            fclose($out);
        }
        return wppilot_restore_point_error('wppilot_restore_point_missing', 'The restore point could not be read.');
    }
    $done = false;
    $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
    if (strlen($header) === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        while (true) {
            $length = (string) fread($in, 4);
            $size = strlen($length) === 4 ? (int) unpack('N', $length)[1] : -1;
            if ($size < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $size > WPPILOT_RESTORE_POINT_CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                break;
            }
            $cipher = (string) fread($in, $size);
            $opened = strlen($cipher) === $size ? sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher) : false;
            if (!is_array($opened) || fwrite($out, $opened[0]) !== strlen($opened[0])) {
                break;
            }
            if ($opened[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                // Anything after the final chunk was not written by seal().
                $done = fread($in, 1) === '';
                break;
            }
        }
    }
    fclose($in);
    fclose($out);
    sodium_memzero($key);

    return $done ? true : wppilot_restore_point_error('wppilot_restore_point_corrupt', 'The restore point could not be decrypted: it was changed or cut short, or the site\'s auth salt changed since it was saved. Nothing was changed.');
}

function wppilot_restore_point_error(string $code, string $message): WP_Error
{
    return new WP_Error($code, $message, ['status' => 409]);
}
