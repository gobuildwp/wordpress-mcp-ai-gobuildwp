<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Child themes: the files wppilot/create-child-theme writes, and the ledger undo that removes them.
 *
 * The undo lives here rather than beside the ability because a rollback can run on a request
 * that never initialises the abilities registry (the Changes screen), and a strategy that is not
 * registered there would leave the row reporting "unknown rollback strategy".
 *
 * The files are fixed templates. Nothing the agent sends ends up as PHP: the slug is a plain
 * directory name and the header values are flattened to one line with comment terminators
 * removed, so they cannot close the style.css comment block.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_CHILD_THEME_TYPE = 'themes/child-created';
/** The screenshot names WordPress looks for, in its own order. */
const WPPILOT_CHILD_THEME_SCREENSHOTS = ['png', 'gif', 'jpg', 'jpeg', 'webp'];

/** One line of a theme header: no line breaks, no comment terminator, at most 200 characters. */
function wppilot_child_theme_header(string $value): string
{
    $value = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
    $value = str_replace('*/', '', $value);

    return trim(mb_substr(trim($value), 0, 200));
}

/**
 * The style.css and functions.php of a new child theme.
 *
 * functions.php loads the parent's stylesheet before the child's, unless the parent (or a
 * plugin) already queued it: many parents enqueue their own style.css with
 * get_template_directory_uri(), and queuing it twice under another handle would load it twice.
 *
 * @param array{slug: string, name: string, parent: string, parent_name: string, description: string} $spec
 * @return array{'style.css': string, 'functions.php': string}
 */
function wppilot_child_theme_files(array $spec): array
{
    $slug = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $spec['slug']);
    $handle = strtolower((string) preg_replace('/[^A-Za-z0-9_-]/', '-', $slug));
    $name = wppilot_child_theme_header($spec['name']);
    $description = wppilot_child_theme_header($spec['description']);
    if ($description === '') {
        $description = wppilot_child_theme_header('Child theme of ' . $spec['parent_name'] . '.');
    }

    $style = "/*\n"
        . "Theme Name: {$name}\n"
        . 'Template: ' . wppilot_child_theme_header($spec['parent']) . "\n"
        . "Description: {$description}\n"
        . "Version: 1.0.0\n"
        . "Text Domain: {$handle}\n"
        . "*/\n\n"
        . "/* Add your own styles below. They load after the parent theme's stylesheet. */\n";

    $functions = <<<PHP
<?php
/**
 * Functions for the {$handle} child theme. Add your own code below.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', static function () {
    \$styles = wp_styles();
    \$parent = get_template_directory_uri() . '/style.css';
    \$child = get_stylesheet_uri();
    \$queued = array();
    foreach (\$styles->queue as \$queued_handle) {
        \$src = isset(\$styles->registered[\$queued_handle]) ? (string) \$styles->registered[\$queued_handle]->src : '';
        \$queued[\$queued_handle] = strtok(\$src, '?');
    }
    \$deps = array();
    if (!in_array(\$parent, \$queued, true)) {
        wp_enqueue_style('{$handle}-parent', \$parent, array(), wp_get_theme(get_template())->get('Version'));
        \$deps[] = '{$handle}-parent';
    }
    \$child_handle = array_search(\$child, \$queued, true);
    if (\$child_handle === false) {
        wp_enqueue_style('{$handle}', \$child, \$deps, wp_get_theme()->get('Version'));
    } elseif (\$deps !== array()) {
        // The parent already queues this stylesheet (get_stylesheet_uri()); make it load after the parent's.
        \$styles->registered[\$child_handle]->deps = array_merge(\$styles->registered[\$child_handle]->deps, \$deps);
    }
}, 20);

PHP;

    return ['style.css' => $style, 'functions.php' => $functions];
}

/** The parent's screenshot file, or null when it has none. */
function wppilot_child_theme_screenshot(string $parent_dir): ?string
{
    foreach (WPPILOT_CHILD_THEME_SCREENSHOTS as $extension) {
        $path = rtrim($parent_dir, '/\\') . '/screenshot.' . $extension;
        if (is_file($path) && !is_link($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * Create the child theme's folder and files. Refuses a folder that already exists, and leaves
 * nothing behind when a write fails part way.
 *
 * @param array<string, string> $files file name => contents
 * @return array{dir: string, files: array<string, string>}|WP_Error the files' sha256 hashes
 */
function wppilot_child_theme_create(string $root, string $slug, array $files, ?string $screenshot = null): array|WP_Error
{
    if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*$#', $slug) !== 1 || str_contains($slug, '..')) {
        return new WP_Error('wppilot_invalid_extension_slug', 'The child theme slug must be a plain directory name.', ['status' => 422]);
    }
    $dir = rtrim(str_replace('\\', '/', $root), '/') . '/' . $slug;
    if (file_exists($dir) || is_link($dir)) {
        return new WP_Error('wppilot_child_theme_exists', sprintf(
            'A folder named "%s" already exists in the themes directory. Nothing was written. Pick another slug.',
            $slug,
        ), ['status' => 409]);
    }
    // mkdir() fails when another request created the folder first, so two calls cannot share it.
    if (!@mkdir($dir, 0755)) {
        return new WP_Error('wppilot_child_theme_write_failed', sprintf(
            'Could not create the folder "%s" in the themes directory. Check that WordPress can write to wp-content/themes.',
            $slug,
        ), ['status' => 500]);
    }

    if ($screenshot !== null) {
        $bytes = @file_get_contents($screenshot);
        if (is_string($bytes)) {
            $files['screenshot.' . strtolower(pathinfo($screenshot, PATHINFO_EXTENSION))] = $bytes;
        }
    }

    $hashes = [];
    foreach ($files as $name => $contents) {
        if (@file_put_contents($dir . '/' . $name, $contents, LOCK_EX) !== strlen($contents)) {
            wppilot_child_theme_delete_files($dir, array_keys($hashes + [$name => '']));
            return new WP_Error('wppilot_child_theme_write_failed', sprintf(
                'Could not write %s, so the child theme was not created and its folder was removed.',
                $name,
            ), ['status' => 500]);
        }
        $hashes[$name] = hash('sha256', $contents);
    }

    return ['dir' => $dir, 'files' => $hashes];
}

/** Delete the named files and then the folder, which only goes when nothing else is in it. */
function wppilot_child_theme_delete_files(string $dir, array $names): void
{
    foreach ($names as $name) {
        $path = $dir . '/' . basename((string) $name);
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** Delete a folder and everything under it, without following links out of it. */
function wppilot_child_theme_delete_tree(string $dir): void
{
    $entries = @scandir($dir);
    foreach (is_array($entries) ? $entries : [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            wppilot_child_theme_delete_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * What is in the folder now that the change did not write, or wrote differently.
 *
 * @param array<string, string> $hashes
 * @return list<string>
 */
function wppilot_child_theme_changed_files(string $dir, array $hashes): array
{
    $changed = [];
    $entries = @scandir($dir);
    foreach (is_array($entries) ? $entries : [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (!isset($hashes[$entry]) || is_dir($path) || is_link($path)) {
            $changed[] = $entry . ' (added)';
        } elseif (!hash_equals($hashes[$entry], (string) hash_file('sha256', $path))) {
            $changed[] = $entry . ' (edited)';
        }
    }

    return $changed;
}

/**
 * Undo a create-child-theme change: switch back to the theme that was active before, when this
 * change activated the child, then delete the folder.
 *
 * Refused, unless forced, when the folder holds files this change did not write or edits to the
 * ones it did (someone built on the child theme since), and when the child is the active theme
 * but this change did not activate it (someone chose it since).
 *
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $entry
 * @return array<string, mixed>|WP_Error
 */
function wppilot_child_theme_rollback(array $payload, array $entry): array|WP_Error
{
    $snapshot = wppilot_string_keyed_array($payload['snapshot'] ?? null);
    $slug = (string) ($snapshot['slug'] ?? '');
    $dir = (string) ($snapshot['dir'] ?? '');
    $hashes = array_map('strval', wppilot_string_keyed_array($snapshot['files'] ?? null));
    $previous = wppilot_string_keyed_array($snapshot['previous'] ?? null);
    $force = ($entry['force'] ?? false) === true;

    if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*$#', $slug) !== 1 || str_contains($slug, '..') || basename($dir) !== $slug) {
        return new WP_Error('wppilot_rollback_invalid_theme', 'The change record does not name a child theme folder.');
    }
    if (function_exists('get_theme_root') && rtrim(str_replace('\\', '/', get_theme_root()), '/') !== dirname($dir)) {
        return new WP_Error('wppilot_rollback_invalid_theme', 'The recorded folder is not in this site\'s themes directory, so it was left alone.');
    }

    if (is_dir($dir) && !is_link($dir) && !$force) {
        $changed = wppilot_child_theme_changed_files($dir, $hashes);
        if ($changed !== []) {
            return new WP_Error('wppilot_change_changed_since', sprintf(
                'The child theme "%s" changed after it was created (%s), so deleting it would lose that work. Nothing was changed. Undo with force=true to delete the whole folder anyway.',
                $slug,
                implode(', ', array_slice($changed, 0, 12)),
            ), ['status' => 409, 'changed' => $changed]);
        }
    }

    $switched_to = null;
    if (get_stylesheet() === $slug) {
        $activated = ($snapshot['activated'] ?? false) === true;
        $back = (string) ($previous['stylesheet'] ?? '');
        if (!$activated && !$force) {
            return new WP_Error('wppilot_child_theme_active', sprintf(
                'The child theme "%s" is the active theme, and it was switched to after this change. Nothing was changed. Switch to another theme first, or undo with force=true to switch back to "%s" and delete it.',
                $slug,
                $back,
            ), ['status' => 409]);
        }
        if ($back === '' || $back === $slug || !wp_get_theme($back)->exists()) {
            return new WP_Error('wppilot_rollback_target_missing', sprintf(
                'The child theme "%s" is active and the theme that was active before it is no longer installed. Switch to another theme with wppilot/switch-theme, then undo again.',
                $slug,
            ), ['status' => 409]);
        }
        switch_theme($back);
        $switched_to = $back;
    }

    if (is_dir($dir) && !is_link($dir)) {
        if ($force) {
            wppilot_child_theme_delete_tree($dir);
        } else {
            wppilot_child_theme_delete_files($dir, array_keys($hashes));
        }
    }
    if (function_exists('wp_clean_themes_cache')) {
        wp_clean_themes_cache();
    }

    return [
        'removed' => $dir,
        'switched_to' => $switched_to,
        'verified' => !file_exists($dir) && get_stylesheet() !== $slug,
    ];
}

if (function_exists('wppilot_register_rollback_strategy')) {
    wppilot_register_rollback_strategy(WPPILOT_CHILD_THEME_TYPE, 'wppilot_child_theme_rollback');
}
