<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The file integrity scan, one budgeted step at a time.
 *
 * Phases, by scope: core (compare with core's checksums, then look for PHP files core does not
 * ship in wp-admin, wp-includes and the root), plugins (WordPress.org plugins compared with their
 * checksums; others reported as having none, and hashed when a baseline is asked for), must-use
 * plugins (listed), themes (WordPress.org themes compared with the files of their zip; others
 * reported as having none, and hashed when a baseline is asked for) and uploads (PHP files, and
 * server settings that would run other files as PHP).
 *
 * A deep scan (payload deep=true) also reads, with the rules, every PHP and JavaScript file that
 * no published checksum covers: premium and custom plugins and themes, must-use plugins, and the
 * drop-ins and other PHP files in the wp-content folder itself (its own 'content' phase).
 *
 * The cursor lives in $state['files'] and is saved after every step, so a step can stop on any
 * file and the next one continues from there.
 */

const SCOPES = ['all', 'core', 'plugins', 'themes', 'uploads'];
const FILE_AREAS = ['core', 'plugins', 'themes', 'uploads', 'suspicious', 'deep'];

/** Uploaded files whose type can carry script; suspicious-indicator rules read these. */
const UPLOAD_SCRIPT_EXTENSIONS = ['ico', 'js', 'svg', 'html', 'htm', 'shtml', 'inc'];

/** Image types a deep scan reads for code hidden inside them. */
const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

/** Of an image larger than RULE_READ_BYTES, this much of its end is read too: payloads are often appended. */
const IMAGE_TAIL_BYTES = 65536;

/** At most this many rule hits are reported per file. */
const MAX_HITS_PER_FILE = 5;

/**
 * A deep scan leaves out JavaScript it would learn little from: minified files over the first
 * size (bundled libraries) and any file over the second.
 */
const DEEP_MINIFIED_JS_MAX = 64 * 1024;
const DEEP_JS_MAX = 512 * 1024;

/** @return list<string> */
function phases_for(string $scope, bool $deep = false): array
{
    return match ($scope) {
        'core' => ['core'],
        'plugins' => $deep ? ['plugins', 'mu_plugins', 'content'] : ['plugins', 'mu_plugins'],
        'themes' => ['themes'],
        'uploads' => ['uploads'],
        default => $deep ? ['core', 'plugins', 'mu_plugins', 'content', 'themes', 'uploads'] : ['core', 'plugins', 'mu_plugins', 'themes', 'uploads'],
    };
}

/** The area a phase reports into: must-use plugins and wp-content's own files count as plugins. */
function phase_area(string $phase): string
{
    return in_array($phase, ['mu_plugins', 'content'], strict: true) ? 'plugins' : $phase;
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $state
 */
function files_init(array $payload, array &$state): void
{
    $scope = (string) ($payload['scope'] ?? 'all');
    $deep = (bool) ($payload['deep'] ?? false);
    $phases = phases_for($scope, $deep);
    $areas = array_map(__NAMESPACE__ . '\phase_area', $phases);
    foreach (FILE_AREAS as $area) {
        $in_scope = $area === 'suspicious' || in_array($area, $areas, strict: true);
        $reason = 'Not in this scan\'s scope.';
        if ($area === 'deep') {
            // Only plugins and themes have files no published checksum covers.
            $in_scope = $deep && array_intersect($areas, ['plugins', 'themes']) !== [];
            $reason = $deep ? 'No plugins or themes in this scan\'s scope.' : 'Deep scan not requested.';
        }
        $state['coverage'][$area] = [
            'state' => $in_scope ? 'pending' : 'skipped',
            'checked' => 0,
            'skipped' => 0,
            'reason' => $in_scope ? '' : $reason,
        ];
    }
    $state['files'] = ['phases' => $phases, 'phase' => 0, 'sub' => '', 'cursor' => 0, 'walk' => null];
}

/**
 * Run the file scan until it finishes or the budget is spent.
 *
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $state
 * @return bool Whether the whole scan is finished.
 */
function files_run(array $payload, array &$state, Budget $budget, Collector $out): bool
{
    $fs = &$state['files'];
    $baseline = (bool) ($payload['baseline'] ?? false);
    $deep = (bool) ($payload['deep'] ?? false);
    while ($fs['phase'] < count($fs['phases'])) {
        $phase = (string) $fs['phases'][$fs['phase']];
        $state['phase'] = $phase;
        $finished = match ($phase) {
            'core' => phase_core($fs, $budget, $out, $state),
            'plugins' => phase_plugins($fs, $budget, $out, $state, $baseline, $deep),
            'mu_plugins' => phase_mu_plugins($fs, $budget, $out, $state, $deep),
            'content' => phase_content($fs, $budget, $out, $state),
            'themes' => phase_themes($fs, $budget, $out, $state, $baseline, $deep),
            'uploads' => phase_uploads($fs, $budget, $out, $state, $deep),
            default => true,
        };
        if (!$finished) {
            return false;
        }
        $out->finish(phase_area($phase));
        $fs['phase']++;
        $fs['sub'] = '';
        $fs['cursor'] = 0;
        $fs['walk'] = null;
    }
    $out->finish('suspicious');
    $out->finish('deep');
    $state['phase'] = 'finished';
    return true;
}

/**
 * How far the file scan is, 0..1: finished phases, plus the share of plugins or themes done in
 * those phases. Other phases have no known size until they are walked.
 *
 * @param array<string, mixed> $state
 */
function files_progress(array $state): float
{
    $fs = $state['files'] ?? null;
    if (!is_array($fs) || $fs['phases'] === []) {
        return 0.0;
    }
    $within = 0.0;
    $phase = $fs['phases'][$fs['phase']] ?? null;
    if ($phase === 'plugins' && isset($fs['plugins']) && $fs['plugins'] !== []) {
        $within = (int) ($fs['plugin'] ?? 0) / count($fs['plugins']);
    } elseif ($phase === 'themes' && isset($fs['themes']) && $fs['themes'] !== []) {
        $within = min((int) ($fs['theme'] ?? 0), count($fs['themes'])) / count($fs['themes']);
    }
    return min(1.0, ((int) $fs['phase'] + $within) / count($fs['phases']));
}

/**
 * @param array{type: string, slug: string, version: string}|null $component
 * @param array<string, mixed> $detail
 * @return array<string, mixed>
 */
function file_item(string $path, string $area, string $result, string $rule_id, string $severity, ?array $component, array $detail = []): array
{
    return [
        'path' => clip($path),
        'area' => $area,
        'result' => $result,
        'rule_id' => $rule_id,
        'severity_hint' => $severity,
        'component' => $component,
        'detail' => (object) $detail,
    ];
}

/**
 * Note a file the scan could not open, as a result and in the area's skipped count.
 *
 * @param array<string, mixed> $state
 * @param array{type: string, slug: string, version: string}|null $component
 */
function skip_file(Collector $out, array &$state, string $path, string $area, string $why, ?array $component, array $detail = []): void
{
    $state['files_skipped'] = (int) ($state['files_skipped'] ?? 0) + 1;
    $out->count($area, 'skipped');
    if ($why === 'denied') {
        return;
    }
    $result = match ($why) {
        'symlink' => 'symlink_skipped',
        'too_large' => 'too_large',
        default => 'unreadable',
    };
    $out->add(file_item($path, $area, $result, '', 'info', $component, $detail));
    $out->partial($area, 'Some files could not be checked.');
}

/**
 * @param array<string, mixed> $state
 */
function checked(Collector $out, array &$state, string $area): void
{
    $state['files_checked'] = (int) ($state['files_checked'] ?? 0) + 1;
    $out->count($area, 'checked');
}

/**
 * Hash a file, or report why it cannot be. Returns null when the step's byte budget cannot take
 * the file (the caller stops and retries it next step), false when the file was reported as
 * skipped, and the hash otherwise.
 *
 * @param array<string, mixed> $state
 * @param array{type: string, slug: string, version: string}|null $component
 */
function hash_of(string $abs, string $path, string $area, string $algo, Budget $budget, Collector $out, array &$state, ?array $component): string|false|null
{
    if (is_link($abs)) {
        skip_file($out, $state, $path, $area, 'symlink', $component);
        return false;
    }
    $size = @filesize($abs);
    if ($size === false || !is_readable($abs)) {
        skip_file($out, $state, $path, $area, 'unreadable', $component);
        return false;
    }
    if ($size > $budget->limits['hash_size']) {
        skip_file($out, $state, $path, $area, 'too_large', $component, ['size' => $size]);
        return false;
    }
    if (!$budget->can_read($size)) {
        return null;
    }
    $hash = $algo === 'md5' ? @md5_file($abs) : @hash_file('sha256', $abs);
    $budget->files++;
    $budget->bytes += $size;
    $state['bytes_read'] = (int) ($state['bytes_read'] ?? 0) + $size;
    if (!is_string($hash)) {
        skip_file($out, $state, $path, $area, 'unreadable', $component);
        return false;
    }
    checked($out, $state, $area);
    return $hash;
}

/**
 * Read the start of a flagged file and report what the suspicious-indicator rules find in it.
 *
 * @param array<string, mixed> $state
 * @param array{type: string, slug: string, version: string}|null $component
 * @param bool $serious report only high and critical hits (files nothing else flagged)
 * @param list<string> $except rule ids not to report for this file
 */
function inspect(string $abs, string $path, string $area, bool $is_php, bool $blobs, Budget $budget, Collector $out, array &$state, ?array $component, bool $serious = false, array $except = []): void
{
    $text = @file_get_contents($abs, false, null, 0, RULE_READ_BYTES);
    if (!is_string($text)) {
        return;
    }
    $budget->bytes += strlen($text);
    $state['bytes_read'] = (int) ($state['bytes_read'] ?? 0) + strlen($text);
    $out->count('suspicious', 'checked');
    $hits = 0;
    foreach (scan_text($text, $is_php) as $hit) {
        if ((!$blobs && in_array($hit['rule_id'], ['susp.base64_blob', 'susp.hex_blob'], strict: true))
            || ($serious && !in_array($hit['severity_hint'], ['high', 'critical'], strict: true))
            || in_array($hit['rule_id'], $except, strict: true)) {
            continue;
        }
        $out->add(file_item($path, $area, 'suspicious', $hit['rule_id'], $hit['severity_hint'], $component, ['line' => $hit['line']]));
        if (++$hits >= MAX_HITS_PER_FILE) {
            break;
        }
    }
}

/** Whether a flagged file is worth reading with the rules: code, or a type that can hide it. */
function rule_worthy(string $name): bool
{
    $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    return is_php_name($name) || has_inner_php_extension($name) || in_array($extension, UPLOAD_SCRIPT_EXTENSIONS, strict: true)
        || in_array(strtolower($name), ['.htaccess', '.user.ini', 'php.ini'], strict: true);
}

/** The rule id of a checksum result: core.*, plugin.* or theme.* by area. */
function area_rule(string $area, string $result): string
{
    return match ($area) {
        'core' => 'core.',
        'themes' => 'theme.',
        default => 'plugin.',
    } . $result;
}

/**
 * Compare one listed file with its published hashes.
 *
 * @param list<string> $expected
 * @param array<string, mixed> $state
 * @param array{type: string, slug: string, version: string} $component
 * @return bool false when the step must stop before this file.
 */
function compare_file(string $abs, string $path, string $area, string $algo, array $expected, Budget $budget, Collector $out, array &$state, array $component): bool
{
    if (!file_exists($abs) && !is_link($abs)) {
        $out->add(file_item($path, $area, 'missing', area_rule($area, 'missing'), 'low', $component));
        checked($out, $state, $area);
        return true;
    }
    $hash = hash_of($abs, $path, $area, $algo, $budget, $out, $state, $component);
    if ($hash === null) {
        return false;
    }
    if ($hash === false || in_array($hash, $expected, strict: true)) {
        return true;
    }
    $name = basename($path);
    $php = is_php_name($name);
    $out->add(file_item($path, $area, 'mismatch', area_rule($area, 'mismatch'), $php ? 'high' : 'medium', $component));
    if (rule_worthy($name)) {
        inspect($abs, $path, $area, $php, true, $budget, $out, $state, $component);
    }
    return true;
}

/**
 * A PHP file (or server settings file) found where the published list has none.
 *
 * @param array<string, mixed> $state
 * @param array{type: string, slug: string, version: string} $component
 */
function report_unexpected(string $abs, string $path, string $area, Budget $budget, Collector $out, array &$state, array $component): bool
{
    $name = basename($path);
    if (!is_php_name($name) && !has_inner_php_extension($name) && !in_array(strtolower($name), ['.htaccess', '.user.ini'], strict: true)) {
        return true;
    }
    $size = @filesize($abs);
    if ($size !== false && $size <= $budget->limits['file_size'] && !$budget->can_read(min($size, RULE_READ_BYTES))) {
        return false;
    }
    $budget->files++;
    checked($out, $state, $area);
    $out->add(file_item($path, $area, 'unexpected', area_rule($area, 'unexpected'), is_php_name($name) ? 'high' : 'medium', $component));
    if ($size !== false && $size <= $budget->limits['file_size'] && is_readable($abs)) {
        inspect($abs, $path, $area, is_php_name($name), true, $budget, $out, $state, $component);
    }
    return true;
}

/**
 * A hash record for a later scan to compare with.
 *
 * @param array<string, mixed> $state
 * @param array{type: string, slug: string, version: string} $component
 */
function record_baseline(string $abs, string $path, string $area, Budget $budget, Collector $out, array &$state, array $component): bool
{
    $hash = hash_of($abs, $path, $area, 'sha256', $budget, $out, $state, $component);
    if ($hash === null) {
        return false;
    }
    if ($hash !== false) {
        $out->add(file_item($path, $area, 'baseline', '', 'info', $component, [
            'sha256' => $hash,
            'size' => (int) @filesize($abs),
            'mtime' => (int) @filemtime($abs),
        ]));
    }
    return true;
}

/**
 * A baseline record for a PHP file nothing can be compared with (no official checksums), plus
 * the rules' high and critical hits in it.
 *
 * @param array<string, mixed> $state
 * @param array{type: string, slug: string, version: string} $component
 */
function baseline_inspect(string $abs, string $path, string $area, Budget $budget, Collector $out, array &$state, array $component): bool
{
    if (!record_baseline($abs, $path, $area, $budget, $out, $state, $component)) {
        return false;
    }
    inspect($abs, $path, $area, true, false, $budget, $out, $state, $component, serious: true);
    return true;
}

/** Whether a deep scan reads this file: PHP (or a name hiding a PHP extension) and JavaScript. */
function deep_worthy(string $name): bool
{
    return is_php_name($name) || has_inner_php_extension($name) || strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) === 'js';
}

/**
 * Read a file no published checksum covers with the rules, reporting only their high and critical
 * hits, as for baseline files. Large and minified JavaScript is counted as skipped by the deep
 * scan and not read. Returns false when the step must stop before this file.
 *
 * @param array<string, mixed> $state
 * @param array{type: string, slug: string, version: string}|null $component
 * @param bool $deep count the file in the deep scan's coverage
 */
function deep_inspect(string $abs, string $path, string $name, string $area, Budget $budget, Collector $out, array &$state, ?array $component, bool $deep = true): bool
{
    if (!deep_worthy($name)) {
        return true;
    }
    $script = !is_php_name($name) && !has_inner_php_extension($name);
    $size = @filesize($abs);
    if ($size === false || !is_readable($abs)) {
        skip_file($out, $state, $path, $area, 'unreadable', $component);
        return true;
    }
    if ($script && ($size > DEEP_JS_MAX || ($size > DEEP_MINIFIED_JS_MAX && str_ends_with(strtolower($name), '.min.js')))) {
        $out->count('deep', 'skipped');
        return true;
    }
    if ($size > $budget->limits['file_size']) {
        skip_file($out, $state, $path, $area, 'too_large', $component, ['size' => $size]);
        return true;
    }
    if (!$budget->can_read(min($size, RULE_READ_BYTES))) {
        return false;
    }
    $budget->files++;
    checked($out, $state, $area);
    if ($deep) {
        $out->count('deep', 'checked');
    }
    // Scripts often carry "<?php" in strings (code editors, syntax highlighters); only PHP's own rules apply.
    inspect($abs, $path, $area, !$script, false, $budget, $out, $state, $component, serious: true, except: $script ? [PHP_TAG_RULE] : []);
    return true;
}

/**
 * A file of a component without published checksums: hashed and read when a baseline is asked
 * for (PHP), and read with the rules in a deep scan (PHP and JavaScript), never both.
 *
 * @param array<string, mixed> $state
 * @param array{type: string, slug: string, version: string} $component
 */
function uncovered_file(string $abs, string $path, string $name, string $area, bool $baseline, bool $deep, Budget $budget, Collector $out, array &$state, array $component): bool
{
    if ($baseline && is_php_name($name)) {
        if (!baseline_inspect($abs, $path, $area, $budget, $out, $state, $component)) {
            return false;
        }
        if ($deep) {
            $out->count('deep', 'checked');
        }
        return true;
    }
    return !$deep || deep_inspect($abs, $path, $name, $area, $budget, $out, $state, $component);
}

/** @return array{type: string, slug: string, version: string} */
function core_component(): array
{
    return ['type' => 'core', 'slug' => 'wordpress', 'version' => wp_version()];
}

function wp_version(): string
{
    global $wp_version;
    return is_string($wp_version ?? null) && $wp_version !== '' ? $wp_version : (string) get_bloginfo('version');
}

function abspath(): string
{
    return rtrim(normalize(roots()['abspath']), '/');
}

/**
 * @param array<string, mixed> $fs
 * @param array<string, mixed> $state
 */
function phase_core(array &$fs, Budget $budget, Collector $out, array &$state): bool
{
    $sums = core_checksums(wp_version(), (string) get_locale(), $budget);
    if ($sums === false) {
        return false;
    }
    if ($sums === null) {
        $out->coverage('core', 'unavailable', 'WordPress.org has no checksums for this WordPress version and language.');
        // The folders beside WordPress need no checksums: read them all the same.
        return phase_others($fs, $budget, $out, $state);
    }
    if ($fs['sub'] === 'others') {
        return phase_others($fs, $budget, $out, $state);
    }
    $component = core_component();
    $root = abspath();
    if ($fs['sub'] === '') {
        $paths = array_keys($sums);
        for ($i = (int) $fs['cursor']; $i < count($paths); $i++) {
            $path = (string) $paths[$i];
            // Bundled themes and plugins are updated on their own and checked as such.
            if (str_starts_with($path, 'wp-content/') || has_parent_segment($path)) {
                continue;
            }
            if ($budget->spent()) {
                $fs['cursor'] = $i;
                return false;
            }
            if (denied_file(basename($path))) {
                skip_file($out, $state, $path, 'core', 'denied', $component);
                continue;
            }
            if (!compare_file($root . '/' . $path, $path, 'core', 'md5', [$sums[$path]], $budget, $out, $state, $component)) {
                $fs['cursor'] = $i;
                return false;
            }
        }
        $fs['sub'] = 'extra';
        $fs['walk'] = [
            'queue' => [[$root . '/wp-admin', 'wp-admin'], [$root . '/wp-includes', 'wp-includes'], [$root, '']],
            'offset' => 0,
            'flat' => [$root],
        ];
    }
    $walk = $fs['walk'];
    $done = walk(
        $walk,
        $budget,
        static function (string $abs, string $path, string $name) use ($sums, $budget, $out, &$state, $component): bool {
            if (isset($sums[$path])) {
                return true;
            }
            // In the root only PHP files are core's business; .htaccess, robots.txt and the like are the site's.
            if (!str_contains($path, '/') && !is_php_name($name)) {
                return true;
            }
            return report_unexpected($abs, $path, 'core', $budget, $out, $state, $component);
        },
        static function (string $path, string $why) use ($out, &$state, $component): void {
            skip_file($out, $state, $path, 'core', $why, $component);
        },
    );
    $fs['walk'] = $walk;
    if (!$done) {
        return false;
    }
    $fs['sub'] = 'others';
    $fs['walk'] = null;
    return phase_others($fs, $budget, $out, $state);
}

/** Folders in the WordPress root that are WordPress's own, by lower-cased name. */
function own_root_folders(): array
{
    $own = ['wp-admin', 'wp-includes', 'wp-content'];
    $roots = roots();
    $dirs = [content_root(), $roots['plugins'], $roots['mu_plugins'], themes_root(), uploads_root()];
    foreach ($dirs as $dir) {
        $name = $dir === '' ? '' : root_name((string) $dir, '');
        if ($name !== '') {
            $own[] = strtolower(explode('/', $name)[0]);
        }
    }
    return array_values(array_unique($own));
}

/**
 * Why a root folder is left alone as another WordPress install or another site's wp-content, or ''.
 */
function other_install(string $dir): string
{
    if (is_file($dir . '/wp-load.php') || is_file($dir . '/wp-includes/version.php')) {
        return 'Another WordPress install; scan that site on its own.';
    }
    if (is_dir($dir . '/plugins') && is_dir($dir . '/themes')) {
        return 'Looks like another site\'s wp-content folder; scan that site on its own.';
    }
    return '';
}

/**
 * The folders in the WordPress root that are not WordPress's own (a forum, a second app, a
 * leftover copy): one folder at a time, every PHP file read with the rules. Hits are reported at
 * the rule's severity; files with none get one info line per folder. Symlinked folders were
 * already reported by the root walk, and another WordPress install is noted and left alone.
 *
 * @param array<string, mixed> $fs
 * @param array<string, mixed> $state
 */
function phase_others(array &$fs, Budget $budget, Collector $out, array &$state): bool
{
    $root = abspath();
    if ($fs['sub'] !== 'others') {
        $fs['sub'] = 'others';
        $fs['walk'] = null;
    }
    if (!isset($fs['others']) || !is_array($fs['others'])) {
        $entries = @scandir($root);
        $own = own_root_folders();
        $tops = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            $abs = $root . '/' . $entry;
            if ($entry === '.' || $entry === '..' || is_link($abs) || !is_dir($abs) || in_array(strtolower($entry), $own, strict: true)) {
                continue;
            }
            if (denied_dir($entry)) {
                skip_file($out, $state, $entry, 'core', 'denied', null);
                continue;
            }
            $why = other_install($abs);
            if ($why !== '') {
                $out->add(file_item($entry, 'core', 'no_checksum', 'core.other_install', 'info', null, ['reason' => $why]));
                continue;
            }
            $tops[] = $entry;
        }
        $fs['others'] = $tops;
        $fs['outside'] = ['php' => 0, 'hit' => 0];
    }
    while ($fs['others'] !== []) {
        $top = (string) $fs['others'][0];
        $walk = is_array($fs['walk']) ? $fs['walk'] : ['queue' => [[$root . '/' . $top, $top]], 'offset' => 0, 'flat' => []];
        $count = &$fs['outside'];
        $done = walk(
            $walk,
            $budget,
            static function (string $abs, string $path, string $name) use ($budget, $out, &$state, &$count): bool {
                if (!is_php_name($name)) {
                    return true;
                }
                $size = @filesize($abs);
                if ($size === false || !is_readable($abs)) {
                    skip_file($out, $state, $path, 'core', 'unreadable', null);
                    return true;
                }
                if ($size > $budget->limits['file_size']) {
                    skip_file($out, $state, $path, 'core', 'too_large', null, ['size' => $size]);
                    return true;
                }
                if (!$budget->can_read(min($size, RULE_READ_BYTES))) {
                    return false;
                }
                $budget->files++;
                checked($out, $state, 'core');
                $before = count($out->items);
                inspect($abs, $path, 'core', true, true, $budget, $out, $state, null);
                $count['php']++;
                if (count($out->items) > $before) {
                    $count['hit']++;
                }
                return true;
            },
            static function (string $path, string $why) use ($out, &$state): void {
                skip_file($out, $state, $path, 'core', $why, null);
            },
        );
        unset($count);
        $fs['walk'] = $walk;
        if (!$done) {
            return false;
        }
        $clean = (int) $fs['outside']['php'] - (int) $fs['outside']['hit'];
        if ($clean > 0) {
            $out->add(file_item($top, 'core', 'no_checksum', 'core.outside_wordpress', 'info', null, [
                'reason' => sprintf('%d PHP %s outside WordPress\'s own folders; none matched a malware rule.', $clean, $clean === 1 ? 'file' : 'files'),
            ]));
        }
        array_shift($fs['others']);
        $fs['walk'] = null;
        $fs['outside'] = ['php' => 0, 'hit' => 0];
    }
    unset($fs['others'], $fs['outside']);
    return true;
}

/**
 * @return list<array{0: string, 1: string, 2: string, 3: bool}> plugin file, folder, version, from WordPress.org
 */
function plugin_list(): array
{
    if (!function_exists('get_plugins') && is_file(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugins = function_exists('get_plugins') ? get_plugins() : [];
    $list = [];
    foreach ($plugins as $file => $data) {
        $file = (string) $file;
        $folder = str_contains($file, '/') ? (string) strstr($file, '/', true) : '';
        $list[] = [$file, $folder, is_array($data) ? (string) ($data['Version'] ?? '') : '', is_wporg_plugin($file)];
    }
    usort($list, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
    return $list;
}

/**
 * @param array<string, mixed> $fs
 * @param array<string, mixed> $state
 */
function phase_plugins(array &$fs, Budget $budget, Collector $out, array &$state, bool $baseline, bool $deep = false): bool
{
    $plugins_dir = roots()['plugins'];
    if ($plugins_dir === '' || !is_dir($plugins_dir)) {
        $out->coverage('plugins', 'unavailable', 'The plugins folder could not be read.');
        return true;
    }
    if (!isset($fs['plugins'])) {
        $fs['plugins'] = plugin_list();
        $fs['plugin'] = 0;
        $fs['sub'] = 'start';
    }
    $dir = normalize($plugins_dir);
    $root_name = root_name($plugins_dir, 'wp-content/plugins');
    while ((int) $fs['plugin'] < count($fs['plugins'])) {
        [$file, $folder, $version, $wporg] = $fs['plugins'][(int) $fs['plugin']];
        $slug = $folder !== '' ? $folder : (string) preg_replace('/\.php$/i', '', $file);
        $component = ['type' => 'plugin', 'slug' => $slug, 'version' => $version];
        $base = join_path($root_name, $folder !== '' ? $folder : $file);
        if ($budget->spent()) {
            return false;
        }
        if ($fs['sub'] === 'start') {
            if ($folder === '' || has_parent_segment($folder) || !is_dir($dir . '/' . $folder) || is_link($dir . '/' . $folder)) {
                $out->add(file_item($base, 'plugins', 'no_checksum', '', 'info', $component, ['reason' => 'single_file']));
                $out->partial('plugins', 'Some plugins have no published checksums.');
                $fs['sub'] = ($baseline || $deep) && $folder === '' ? 'baseline_single' : 'next';
            } elseif ($wporg) {
                $sums = plugin_checksums($folder, $version, $budget);
                if ($sums === false) {
                    return false;
                }
                if ($sums === []) {
                    $out->add(file_item($base, 'plugins', 'no_checksum', '', 'info', $component, ['reason' => 'checksums_unavailable']));
                    $out->partial('plugins', 'Some plugins have no published checksums.');
                    $fs['sub'] = $baseline || $deep ? 'baseline' : 'next';
                } else {
                    $fs['sub'] = 'compare';
                }
            } else {
                $out->add(file_item($base, 'plugins', 'no_checksum', '', 'info', $component, ['reason' => 'not_wordpress_org']));
                $out->partial('plugins', 'Some plugins have no published checksums.');
                $fs['sub'] = $baseline || $deep ? 'baseline' : 'next';
            }
            $fs['cursor'] = 0;
            $fs['walk'] = null;
        }
        if ($fs['sub'] === 'baseline_single') {
            if (!uncovered_file($dir . '/' . $file, $base, $file, 'plugins', $baseline, $deep, $budget, $out, $state, $component)) {
                return false;
            }
            $fs['sub'] = 'next';
        }
        if ($fs['sub'] === 'compare') {
            $sums = plugin_checksums($folder, $version, $budget);
            if ($sums === false) {
                return false;
            }
            $paths = array_keys($sums);
            for ($i = (int) $fs['cursor']; $i < count($paths); $i++) {
                $relative = (string) $paths[$i];
                if (has_parent_segment($relative)) {
                    continue;
                }
                if ($budget->spent()) {
                    $fs['cursor'] = $i;
                    return false;
                }
                $path = $base . '/' . $relative;
                if (denied_file(basename($relative))) {
                    skip_file($out, $state, $path, 'plugins', 'denied', $component);
                    continue;
                }
                $abs = $dir . '/' . $folder . '/' . $relative;
                if (file_exists($abs) && guard($abs, $dir . '/' . $folder) === 'outside') {
                    skip_file($out, $state, $path, 'plugins', 'symlink', $component);
                    continue;
                }
                if (!compare_file($abs, $path, 'plugins', 'sha256', $sums[$relative], $budget, $out, $state, $component)) {
                    $fs['cursor'] = $i;
                    return false;
                }
            }
            $fs['sub'] = 'extra';
        }
        if ($fs['sub'] === 'extra' || $fs['sub'] === 'baseline') {
            $mode = $fs['sub'];
            $sums = $mode === 'extra' ? plugin_checksums($folder, $version, $budget) : [];
            if ($sums === false) {
                return false;
            }
            $walk = is_array($fs['walk']) ? $fs['walk'] : ['queue' => [[$dir . '/' . $folder, $base]], 'offset' => 0];
            $prefix = strlen($base) + 1;
            $done = walk(
                $walk,
                $budget,
                static function (string $abs, string $path, string $name) use ($mode, $sums, $prefix, $baseline, $deep, $budget, $out, &$state, $component): bool {
                    if ($mode === 'baseline') {
                        return uncovered_file($abs, $path, $name, 'plugins', $baseline, $deep, $budget, $out, $state, $component);
                    }
                    if (isset($sums[substr($path, $prefix)])) {
                        return true;
                    }
                    if ($deep && !is_php_name($name) && !has_inner_php_extension($name)) {
                        // A script the published list does not have.
                        return deep_inspect($abs, $path, $name, 'plugins', $budget, $out, $state, $component);
                    }
                    return report_unexpected($abs, $path, 'plugins', $budget, $out, $state, $component);
                },
                static function (string $path, string $why) use ($out, &$state, $component): void {
                    skip_file($out, $state, $path, 'plugins', $why, $component);
                },
            );
            $fs['walk'] = $walk;
            if (!$done) {
                return false;
            }
            $fs['sub'] = 'next';
        }
        $fs['plugin'] = (int) $fs['plugin'] + 1;
        $fs['sub'] = 'start';
        $fs['walk'] = null;
        $fs['cursor'] = 0;
    }
    return true;
}

/**
 * Must-use plugins are listed: they have no published checksums and no version. A deep scan also
 * reads them, and the files in their folders, with the rules.
 *
 * @param array<string, mixed> $fs
 * @param array<string, mixed> $state
 */
function phase_mu_plugins(array &$fs, Budget $budget, Collector $out, array &$state, bool $deep = false): bool
{
    $mu_dir = roots()['mu_plugins'];
    if ($mu_dir === '' || !is_dir($mu_dir) || is_link($mu_dir)) {
        return true;
    }
    $dir = normalize($mu_dir);
    $root_name = root_name($mu_dir, 'wp-content/mu-plugins');
    $prefix = strlen($root_name) + 1;
    $walk = is_array($fs['walk']) ? $fs['walk'] : ['queue' => [[$dir, $root_name]], 'offset' => 0, 'flat' => $deep ? [] : [$dir]];
    $done = walk(
        $walk,
        $budget,
        static function (string $abs, string $path, string $name) use ($deep, $prefix, $budget, $out, &$state): bool {
            $relative = substr($path, $prefix);
            $top = !str_contains($relative, '/');
            $slug = $top ? (string) preg_replace('/\.[^.]+$/', '', $name) : (string) strtok($relative, '/');
            $component = ['type' => 'plugin', 'slug' => $slug, 'version' => ''];
            if ($top && is_php_name($name)) {
                $out->add(file_item($path, 'plugins', 'no_checksum', '', 'info', $component, ['reason' => 'must_use_plugin']));
                if (!$deep) {
                    $state['files_checked'] = (int) ($state['files_checked'] ?? 0) + 1;
                }
            }
            if (!$deep) {
                return true;
            }
            if (!deep_inspect($abs, $path, $name, 'plugins', $budget, $out, $state, $component)) {
                if ($top && is_php_name($name)) {
                    // The listing is made again, with the file, next step.
                    array_pop($out->items);
                }
                return false;
            }
            return true;
        },
        static function (string $path, string $why) use ($out, &$state): void {
            skip_file($out, $state, $path, 'plugins', $why, null);
        },
    );
    $fs['walk'] = $walk;
    return $done;
}

/**
 * The PHP files in the wp-content folder itself, deep scans only: drop-ins (object-cache.php,
 * advanced-cache.php, db.php and the like) and anything else put there. None has a published
 * checksum; they are read with the rules.
 *
 * @param array<string, mixed> $fs
 * @param array<string, mixed> $state
 */
function phase_content(array &$fs, Budget $budget, Collector $out, array &$state): bool
{
    $content = content_root();
    if ($content === '' || !is_dir($content) || is_link($content)) {
        return true;
    }
    $dir = normalize($content);
    $walk = is_array($fs['walk']) ? $fs['walk'] : ['queue' => [[$dir, root_name($content, 'wp-content')]], 'offset' => 0, 'flat' => [$dir]];
    $done = walk(
        $walk,
        $budget,
        static function (string $abs, string $path, string $name) use ($budget, $out, &$state): bool {
            if (!is_php_name($name) && !has_inner_php_extension($name)) {
                return true;
            }
            if (strtolower($name) === 'index.php' && is_stub_index($abs, (int) @filesize($abs))) {
                return true;
            }
            return deep_inspect($abs, $path, $name, 'plugins', $budget, $out, $state, null);
        },
        static function (string $path, string $why) use ($out, &$state): void {
            skip_file($out, $state, $path, 'plugins', $why, null);
        },
    );
    $fs['walk'] = $walk;
    return $done;
}

/**
 * The theme folders in the themes folder, by name, with the installed version and whether
 * WordPress.org serves the theme. A theme whose Update URI header names another updater is never
 * treated as a WordPress.org one, nor is one whose name or version WordPress.org could not have.
 *
 * @return list<array{0: string, 1: string, 2: bool}> folder, version, from WordPress.org
 */
function theme_list(string $dir): array
{
    $installed = function_exists('wp_get_themes') ? wp_get_themes() : [];
    $list = [];
    foreach ((array) @scandir($dir) as $entry) {
        $entry = (string) $entry;
        if ($entry === '.' || $entry === '..' || !is_dir($dir . '/' . $entry) || is_link($dir . '/' . $entry) || denied_dir($entry)) {
            continue;
        }
        $theme = $installed[$entry] ?? null;
        $version = is_object($theme) && method_exists($theme, 'get') ? (string) $theme->get('Version') : '';
        $update_uri = is_object($theme) && method_exists($theme, 'get') ? (string) $theme->get('UpdateURI') : '';
        $own_updater = $update_uri !== '' && !in_array(strtolower($update_uri), ['https://wordpress.org/themes/' . $entry . '/', 'w.org/theme/' . $entry], strict: true);
        $wporg = $theme !== null && !$own_updater
            && preg_match(THEME_SLUG_PATTERN, $entry) === 1 && preg_match(THEME_VERSION_PATTERN, $version) === 1
            && is_wporg_theme($entry);
        $list[] = [$entry, $version, $wporg];
    }
    usort($list, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
    return $list;
}

/**
 * Themes, one at a time: a WordPress.org theme is compared with the files of its zip for the
 * installed version (modified, missing, and PHP files the zip does not have), like a WordPress.org
 * plugin. Other themes (child, custom, premium) are reported as having no checksums, then hashed
 * for a baseline and, in a deep scan, read with the rules. Loose PHP files in the themes folder
 * itself are read with the rules when either is asked for.
 *
 * Coverage counts the themes compared ('compared') and those without checksums ('unverified').
 *
 * @param array<string, mixed> $fs
 * @param array<string, mixed> $state
 */
function phase_themes(array &$fs, Budget $budget, Collector $out, array &$state, bool $baseline, bool $deep = false): bool
{
    $root = themes_root();
    if ($root === '' || !is_dir($root) || is_link($root)) {
        $out->coverage('themes', 'unavailable', 'The themes folder could not be read.');
        return true;
    }
    $dir = normalize($root);
    $root_name = root_name($root, 'wp-content/themes');
    if (!isset($fs['themes'])) {
        $fs['themes'] = theme_list($dir);
        $fs['theme'] = 0;
        $fs['sub'] = 'start';
    }
    while ((int) $fs['theme'] < count($fs['themes'])) {
        [$slug, $version, $wporg] = $fs['themes'][(int) $fs['theme']];
        $component = ['type' => 'theme', 'slug' => $slug, 'version' => $version];
        $base = join_path($root_name, $slug);
        if ($budget->spent()) {
            return false;
        }
        if ($fs['sub'] === 'start') {
            $reason = 'not_wordpress_org';
            if ($wporg) {
                $sums = theme_checksums($slug, $version, $budget);
                if ($sums === false) {
                    return false;
                }
                $reason = $sums === [] ? 'checksums_unavailable' : '';
            }
            if ($reason === '') {
                $out->count('themes', 'compared');
                $fs['sub'] = 'compare';
            } else {
                $out->add(file_item($base, 'themes', 'no_checksum', '', 'info', $component, ['reason' => $reason]));
                $out->partial('themes', 'Some themes have no published checksums.');
                $out->count('themes', 'unverified');
                $fs['sub'] = $baseline || $deep ? 'baseline' : 'next';
            }
            $fs['cursor'] = 0;
            $fs['walk'] = null;
        }
        if ($fs['sub'] === 'compare') {
            $sums = theme_checksums($slug, $version, $budget);
            if ($sums === false) {
                return false;
            }
            $paths = array_keys($sums);
            for ($i = (int) $fs['cursor']; $i < count($paths); $i++) {
                $relative = (string) $paths[$i];
                if ($budget->spent()) {
                    $fs['cursor'] = $i;
                    return false;
                }
                $path = $base . '/' . $relative;
                if (denied_file(basename($relative))) {
                    skip_file($out, $state, $path, 'themes', 'denied', $component);
                    continue;
                }
                $abs = $dir . '/' . $slug . '/' . $relative;
                if (file_exists($abs) && guard($abs, $dir . '/' . $slug) === 'outside') {
                    skip_file($out, $state, $path, 'themes', 'symlink', $component);
                    continue;
                }
                if (!compare_file($abs, $path, 'themes', 'sha256', $sums[$relative], $budget, $out, $state, $component)) {
                    $fs['cursor'] = $i;
                    return false;
                }
            }
            $fs['sub'] = 'extra';
        }
        if ($fs['sub'] === 'extra' || $fs['sub'] === 'baseline') {
            $mode = $fs['sub'];
            $sums = $mode === 'extra' ? theme_checksums($slug, $version, $budget) : [];
            if ($sums === false) {
                return false;
            }
            $walk = is_array($fs['walk']) ? $fs['walk'] : ['queue' => [[$dir . '/' . $slug, $base]], 'offset' => 0];
            $prefix = strlen($base) + 1;
            $done = walk(
                $walk,
                $budget,
                static function (string $abs, string $path, string $name) use ($mode, $sums, $prefix, $baseline, $deep, $budget, $out, &$state, $component): bool {
                    if ($mode === 'baseline') {
                        return uncovered_file($abs, $path, $name, 'themes', $baseline, $deep, $budget, $out, $state, $component);
                    }
                    if (isset($sums[substr($path, $prefix)])) {
                        return true;
                    }
                    if ($deep && !is_php_name($name) && !has_inner_php_extension($name)) {
                        return deep_inspect($abs, $path, $name, 'themes', $budget, $out, $state, $component);
                    }
                    return report_unexpected($abs, $path, 'themes', $budget, $out, $state, $component);
                },
                static function (string $path, string $why) use ($out, &$state, $component): void {
                    skip_file($out, $state, $path, 'themes', $why, $component);
                },
            );
            $fs['walk'] = $walk;
            if (!$done) {
                return false;
            }
        }
        $fs['theme'] = (int) $fs['theme'] + 1;
        $fs['sub'] = 'start';
        $fs['walk'] = null;
        $fs['cursor'] = 0;
    }
    if (!$baseline && !$deep) {
        return true;
    }
    // Loose files in the themes folder itself: nothing ships PHP there but an index.php stub.
    $walk = is_array($fs['walk']) ? $fs['walk'] : ['queue' => [[$dir, $root_name]], 'offset' => 0, 'flat' => [$dir]];
    $done = walk(
        $walk,
        $budget,
        static function (string $abs, string $path, string $name) use ($deep, $budget, $out, &$state): bool {
            if (!is_php_name($name) && !has_inner_php_extension($name)) {
                return true;
            }
            if (strtolower($name) === 'index.php' && is_stub_index($abs, (int) @filesize($abs))) {
                checked($out, $state, 'themes');
                return true;
            }
            return deep_inspect($abs, $path, $name, 'themes', $budget, $out, $state, null, $deep);
        },
        static function (string $path, string $why) use ($out, &$state): void {
            skip_file($out, $state, $path, 'themes', $why, null);
        },
    );
    $fs['walk'] = $walk;
    return $done;
}

/**
 * @param array<string, mixed> $fs
 * @param array<string, mixed> $state
 */
function phase_uploads(array &$fs, Budget $budget, Collector $out, array &$state, bool $deep = false): bool
{
    $base = uploads_root();
    if ($base === '' || !is_dir($base) || is_link($base)) {
        $out->coverage('uploads', 'unavailable', 'The uploads folder could not be read.');
        return true;
    }
    $walk = is_array($fs['walk']) ? $fs['walk'] : ['queue' => [[normalize($base), root_name($base, 'wp-content/uploads')]], 'offset' => 0];
    $done = walk(
        $walk,
        $budget,
        // A closure, not an arrow function: an arrow function takes $state by value and its counts would be lost.
        static function (string $abs, string $path, string $name) use ($budget, $out, &$state, $deep): bool {
            return upload_file($abs, $path, $name, $budget, $out, $state, $deep);
        },
        static function (string $path, string $why) use ($out, &$state): void {
            skip_file($out, $state, $path, 'uploads', $why, null);
        },
    );
    $fs['walk'] = $walk;
    return $done;
}

/**
 * True for the tiny index.php plugins put in upload folders to block listing: only an open tag,
 * comments, and header()/http_response_code()/exit/die with literal or $_SERVER arguments.
 */
function is_stub_index(string $abs, int $size): bool
{
    if ($size > 512) {
        return false;
    }
    if ($size === 0) {
        return true;
    }
    $code = @file_get_contents($abs, false, null, 0, 512);
    if (!is_string($code) || !str_starts_with(ltrim($code), '<?php')) {
        return false;
    }
    $calls = ['header', 'http_response_code', 'exit', 'die'];
    $punct = ['(', ')', ';', '[', ']', '.', ','];
    foreach (token_get_all($code) as $t) {
        if (is_string($t)) {
            if (!in_array($t, $punct, strict: true)) {
                return false;
            }
            continue;
        }
        [$id, $text] = $t;
        if (in_array($id, [T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER], strict: true)) {
            continue;
        }
        if ($id === T_EXIT || ($id === T_STRING && in_array(strtolower($text), $calls, strict: true))) {
            continue;
        }
        if ($id === T_VARIABLE && $text === '$_SERVER') {
            continue;
        }
        return false;
    }
    return true;
}

/**
 * Judge one uploaded file. Most are judged by name alone; PHP files, server settings files and
 * types that can carry script are also read; a deep scan also reads images for hidden code.
 *
 * @param array<string, mixed> $state
 */
function upload_file(string $abs, string $path, string $name, Budget $budget, Collector $out, array &$state, bool $deep = false): bool
{
    $lower = strtolower($name);
    $settings = in_array($lower, ['.htaccess', '.user.ini', 'php.ini'], strict: true);
    if (!rule_worthy($name)) {
        if ($deep && in_array(strtolower((string) pathinfo($name, PATHINFO_EXTENSION)), IMAGE_EXTENSIONS, strict: true)) {
            return image_file($abs, $path, $budget, $out, $state);
        }
        checked($out, $state, 'uploads');
        return true;
    }
    $size = @filesize($abs);
    if ($size === false || !is_readable($abs)) {
        skip_file($out, $state, $path, 'uploads', 'unreadable', null);
        return true;
    }
    $php = is_php_name($name);
    if ($php && $lower === 'index.php' && is_stub_index($abs, (int) $size)) {
        // Empty, "Silence is golden" or 404 index.php that plugins drop to stop folder listing.
        checked($out, $state, 'uploads');
        return true;
    }
    if ($php) {
        $out->add(file_item($path, 'uploads', 'php_in_uploads', 'uploads.php_file', 'high', null, ['size' => $size]));
    } elseif (has_inner_php_extension($name)) {
        $out->add(file_item($path, 'uploads', 'php_in_uploads', 'uploads.php_double_extension', 'high', null, ['size' => $size]));
    }
    if ($size > $budget->limits['file_size']) {
        skip_file($out, $state, $path, 'uploads', 'too_large', null, ['size' => $size]);
        return true;
    }
    $read = min($size, RULE_READ_BYTES);
    if (!$budget->can_read($read)) {
        // Drop what this call added; the file is judged again, whole, next step.
        array_splice($out->items, count($out->items) - (($php || has_inner_php_extension($name)) ? 1 : 0));
        return false;
    }
    $budget->files++;
    checked($out, $state, 'uploads');
    if ($settings) {
        $text = @file_get_contents($abs, false, null, 0, RULE_READ_BYTES);
        if (is_string($text)) {
            $budget->bytes += strlen($text);
            $state['bytes_read'] = (int) ($state['bytes_read'] ?? 0) + strlen($text);
            if ($lower === '.htaccess') {
                $line = htaccess_handler_line($text);
                if ($line !== null) {
                    $out->add(file_item($path, 'uploads', 'php_in_uploads', 'uploads.htaccess_handler', 'high', null, ['line' => $line]));
                }
            } elseif (preg_match('/^\s*auto_(?:prepend|append)_file\s*=/mi', $text, $match, PREG_OFFSET_CAPTURE) === 1) {
                $out->add(file_item($path, 'uploads', 'php_in_uploads', 'uploads.user_ini', 'high', null, ['line' => line_at($text, (int) $match[0][1])]));
            }
        }
        return true;
    }
    inspect($abs, $path, 'uploads', $php || has_inner_php_extension($name), $php, $budget, $out, $state, null);
    return true;
}

/**
 * Read an uploaded image for code hidden in it (deep scan): at most RULE_READ_BYTES, the start
 * and, of a larger image, its last IMAGE_TAIL_BYTES too. A hit is reported once per image, as the
 * PHP-tag-in-a-non-PHP-file rule. Returns false when the step must stop before this file.
 *
 * @param array<string, mixed> $state
 */
function image_file(string $abs, string $path, Budget $budget, Collector $out, array &$state): bool
{
    $size = @filesize($abs);
    if ($size === false || !is_readable($abs)) {
        skip_file($out, $state, $path, 'uploads', 'unreadable', null);
        return true;
    }
    $read = min($size, RULE_READ_BYTES);
    if (!$budget->can_read($read)) {
        return false;
    }
    $budget->files++;
    checked($out, $state, 'uploads');
    if ($size <= RULE_READ_BYTES) {
        $data = @file_get_contents($abs);
    } else {
        $head = @file_get_contents($abs, false, null, 0, RULE_READ_BYTES - IMAGE_TAIL_BYTES);
        $tail = @file_get_contents($abs, false, null, $size - IMAGE_TAIL_BYTES, IMAGE_TAIL_BYTES);
        // A newline keeps a pattern from being made up of the end of one piece and the start of the other.
        $data = is_string($head) && is_string($tail) ? $head . "\n" . $tail : false;
    }
    if (!is_string($data)) {
        skip_file($out, $state, $path, 'uploads', 'unreadable', null);
        return true;
    }
    $budget->bytes += strlen($data);
    $state['bytes_read'] = (int) ($state['bytes_read'] ?? 0) + strlen($data);
    $out->count('suspicious', 'checked');
    if (binary_code_hit($data)) {
        $out->add(file_item($path, 'uploads', 'suspicious', PHP_TAG_RULE, 'high', null, ['size' => $size]));
    }
    return true;
}
