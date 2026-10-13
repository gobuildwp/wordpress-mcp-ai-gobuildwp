<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SeoBasics\Aioseo;

use WP_Error;
use WPPilot\Kits\Runtime\Ledger;
use WPPilot\Kits\Runtime\SessionLedger;
use WPPilot\Kits\SeoBasics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * All in One SEO: one post's SEO title, meta description and robots, in AIOSEO's own table.
 *
 * Verified against AIOSEO 5.0.2 (app/Common/Models/Post.php, Model.php, Utils/Database.php):
 * - per-post SEO is a row in `{prefix}aioseo_posts`, read with Post::getPost() (which fills in
 *   defaults when the post has no row yet) and written with the model's save(), which inserts
 *   or updates the row and records a failed write in the model's `lastError`. Values go to SQL
 *   through esc_sql(), not through WordPress's unslashing meta API, so nothing is slashed here;
 * - robots are all-or-nothing: while `robots_default` is true AIOSEO ignores every per-post
 *   robots column and uses the global / post type robots. index "default" therefore sets it and
 *   clears the flags (and the SERP preview limits, so a stale limit cannot resurface); any
 *   explicit index or follow clears it, keeping the post's advanced flags;
 * - a write through the model's save() changes only the table. AIOSEO's own savePost() also
 *   mirrors the title and description into `_aioseo_*` post meta for multilingual plugins; this
 *   kit writes the way GoBuildWP Pro's copy does, through save(), and leaves that meta alone.
 *
 * Undo is this kit's own: the before-image is the columns a write changes, and the restore puts
 * them back through the same model and re-reads them to verify. GoBuildWP's generic post restore
 * cannot, since none of this is post meta.
 */
const CODE = 'aioseo';

const STRATEGY = 'kits/seo-basics-aioseo';

const MODEL = 'AIOSEO\\Plugin\\Common\\Models\\Post';

/** Boolean robots columns; robots_default gates all the others. */
const ROBOT_FLAGS = [
    'robots_default',
    'robots_noindex',
    'robots_nofollow',
    'robots_noarchive',
    'robots_noimageindex',
    'robots_nosnippet',
    'robots_noodp',
    'robots_notranslate',
];

/** SERP preview limit columns: an int, or null for "unset". */
const ROBOT_LIMITS = ['robots_max_snippet', 'robots_max_videopreview'];

function available(): bool
{
    return defined('AIOSEO_VERSION')
        && function_exists('aioseo')
        && class_exists(MODEL)
        && method_exists(MODEL, 'getPost')
        && method_exists(MODEL, 'save');
}

function not_active(): WP_Error
{
    return new WP_Error('aioseo_not_active', 'All in One SEO is not active', ['status' => 400]);
}

function model(int $post_id): object
{
    /** @var object $model */
    $model = call_user_func([MODEL, 'getPost'], $post_id);
    return $model;
}

function flag(mixed $raw): bool
{
    if (is_bool($raw)) {
        return $raw;
    }
    if (is_int($raw) || is_float($raw)) {
        return (float) $raw !== 0.0;
    }
    return is_string($raw) && in_array(strtolower($raw), ['1', 'on', 'true', 'yes'], strict: true);
}

function limit(mixed $raw): ?int
{
    return is_numeric($raw) ? (int) $raw : null;
}

/**
 * The post types AIOSEO manages SEO for, or [] when it cannot say (then nothing is refused).
 *
 * @return list<string>
 */
function public_post_types(): array
{
    $helpers = function_exists('aioseo') ? (\aioseo()->helpers ?? null) : null;
    if (!is_object($helpers) || !method_exists($helpers, 'getPublicPostTypes')) {
        return [];
    }
    /** @var mixed $names */
    $names = $helpers->getPublicPostTypes(true);
    return is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
}

function resolve(mixed $raw): \WP_Post|WP_Error
{
    $post = SeoBasics\resolve_post($raw, CODE);
    if ($post instanceof WP_Error) {
        return $post;
    }
    $public = public_post_types();
    if ($public !== [] && !in_array($post->post_type, $public, strict: true)) {
        return new WP_Error(
            'aioseo_invalid_post',
            sprintf('AIOSEO does not manage SEO for the \'%s\' post type', $post->post_type),
            ['status' => 400],
        );
    }
    return $post;
}

/**
 * A stored title or description as AIOSEO renders it: entities decoded, tags stripped, through
 * AIOSEO's own sanitiser when it has one. Smart tags (#post_title) are left as they are.
 */
function display(mixed $raw): string
{
    $value = is_string($raw) ? $raw : '';
    $helpers = 'AIOSEO\\Plugin\\Common\\Meta\\Helpers';
    if ($value !== '' && class_exists($helpers) && method_exists($helpers, 'sanitize')) {
        try {
            return (string) (new $helpers('title'))->sanitize($value, false, true);
        } catch (\Throwable) {
            // Fall through to the plain decode below.
        }
    }
    $stripped = wp_strip_all_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML401));
    $collapsed = preg_replace('/\s+/u', ' ', $stripped);
    return trim(is_string($collapsed) ? $collapsed : $stripped);
}

/**
 * @return array{index: string, follow: string}
 */
function robots(object $model): array
{
    if (flag($model->robots_default ?? true)) {
        return ['index' => 'default', 'follow' => 'follow'];
    }
    return [
        'index' => flag($model->robots_noindex ?? false) ? 'noindex' : 'index',
        'follow' => flag($model->robots_nofollow ?? false) ? 'nofollow' : 'follow',
    ];
}

/**
 * @return array{seo_title: string, meta_description: string, robots: array{index: string, follow: string}}
 */
function read(int $post_id): array
{
    $model = model($post_id);
    return [
        'seo_title' => display($model->title ?? null),
        'meta_description' => display($model->description ?? null),
        'robots' => robots($model),
    ];
}

/**
 * The columns an input would change, in the order they are captured.
 *
 * @param array<string, mixed> $input
 * @return list<string>
 */
function touched_columns(array $input): array
{
    $columns = [];
    if (array_key_exists('seo_title', $input)) {
        $columns[] = 'title';
    }
    if (array_key_exists('meta_description', $input)) {
        $columns[] = 'description';
    }
    if (array_key_exists('robots', $input)) {
        // A robots change can move robots_default, which resets every flag and limit with it.
        $columns = array_merge($columns, ROBOT_FLAGS, ROBOT_LIMITS, ['robots_max_imagepreview']);
    }
    return $columns;
}

/**
 * One column's value, normalised so a capture and a later re-read compare equal.
 */
function column(object $model, string $column): mixed
{
    /** @var mixed $raw */
    $raw = $model->{$column} ?? null;
    if (in_array($column, ROBOT_FLAGS, strict: true)) {
        return flag($raw);
    }
    if (in_array($column, ROBOT_LIMITS, strict: true)) {
        return limit($raw);
    }
    return is_scalar($raw) ? (string) $raw : null;
}

/**
 * @param list<string> $columns
 * @return array<string, mixed>
 */
function columns(int $post_id, array $columns): array
{
    $model = model($post_id);
    $values = [];
    foreach ($columns as $name) {
        $values[$name] = column($model, $name);
    }
    return $values;
}

/**
 * @param array<string, mixed> $columns
 */
function fingerprint(int $post_id, array $columns): string
{
    ksort($columns);
    return hash('sha256', (string) wp_json_encode(['post_id' => $post_id, 'columns' => $columns]));
}

/**
 * The before-image of the columns this input changes.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|null
 */
function snapshot(array $input): ?array
{
    $post_id = SeoBasics\requested_int($input);
    $names = touched_columns($input);
    if (!available() || $post_id <= 0 || $names === [] || !get_post($post_id) instanceof \WP_Post) {
        return null;
    }
    $values = columns($post_id, $names);
    return [
        'type' => STRATEGY,
        'post_id' => $post_id,
        'columns' => $values,
        'fingerprint' => fingerprint($post_id, $values),
    ];
}

/**
 * Save the model, turning AIOSEO's recorded write error into a WP_Error.
 */
function save(object $model): ?WP_Error
{
    $model->save();
    $error = $model->lastError ?? '';
    if (is_string($error) && $error !== '') {
        return new WP_Error('aioseo_save_failed', sprintf('AIOSEO could not save the post\'s SEO row: %s', $error), ['status' => 500]);
    }
    return null;
}

/**
 * Put the captured columns back through AIOSEO's model, then re-read and compare.
 *
 * @param array<string, mixed> $payload A ledger rollback payload; the snapshot is under `snapshot`.
 * @return array<string, mixed>|WP_Error
 */
function restore(array $payload): array|WP_Error
{
    $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $post_id = (int) ($snapshot['post_id'] ?? 0);
    $values = is_array($snapshot['columns'] ?? null) ? $snapshot['columns'] : [];
    if (!available()) {
        return new WP_Error('aioseo_not_active', 'All in One SEO is not active, so its SEO row cannot be restored.');
    }
    if ($post_id <= 0 || !get_post($post_id) instanceof \WP_Post) {
        return new WP_Error('kit_rollback_target_missing', 'The post this change touched no longer exists.');
    }
    $known = array_merge(['title', 'description'], ROBOT_FLAGS, ROBOT_LIMITS, ['robots_max_imagepreview']);
    $values = array_intersect_key($values, array_flip($known));
    if ($values === []) {
        return new WP_Error('kit_rollback_invalid', 'This change has no AIOSEO columns to restore.');
    }

    $model = model($post_id);
    foreach ($values as $name => $value) {
        $model->{$name} = $value;
    }
    $error = save($model);
    if ($error instanceof WP_Error) {
        return $error;
    }

    $observed = columns($post_id, array_map('strval', array_keys($values)));
    $expected = (string) ($snapshot['fingerprint'] ?? '');
    $actual = fingerprint($post_id, $observed);

    return [
        'post_id' => $post_id,
        'expected_fingerprint' => $expected,
        'observed_fingerprint' => $actual,
        'verified' => $expected !== '' && hash_equals($expected, $actual),
    ];
}

/**
 * The columns a before-image names, as they are now, in the before-image's shape: what a session
 * undo checks nothing else has changed, and what a redo hands back to restore().
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|null
 */
function current_state(array $snapshot): ?array
{
    if (!available()) {
        return null;
    }
    $post_id = (int) ($snapshot['post_id'] ?? 0);
    $known = array_merge(['title', 'description'], ROBOT_FLAGS, ROBOT_LIMITS, ['robots_max_imagepreview']);
    $names = is_array($snapshot['columns'] ?? null)
        ? array_values(array_intersect(array_map('strval', array_keys($snapshot['columns'])), $known))
        : [];
    if ($post_id <= 0 || $names === []) {
        return null;
    }
    if (!get_post($post_id) instanceof \WP_Post) {
        return ['type' => 'absent'];
    }
    $values = columns($post_id, $names);
    return [
        'type' => STRATEGY,
        'post_id' => $post_id,
        'columns' => $values,
        'fingerprint' => fingerprint($post_id, $values),
    ];
}

/**
 * @param array<string, mixed> $snapshot
 */
function state_target(array $snapshot): string
{
    $names = is_array($snapshot['columns'] ?? null) ? array_map('strval', array_keys($snapshot['columns'])) : [];
    sort($names, SORT_STRING);
    $post_id = (int) ($snapshot['post_id'] ?? 0);
    return $post_id > 0 && $names !== [] ? $post_id . ':' . implode(',', $names) : '';
}

function register(Ledger $ledger): void
{
    $ledger->register_strategy(STRATEGY, static fn(array $payload): array|WP_Error => restore($payload));
    if ($ledger instanceof SessionLedger) {
        $ledger->register_state(
            STRATEGY,
            static fn(array $snapshot): ?array => current_state($snapshot),
            static fn(array $snapshot): string => state_target($snapshot),
        );
    }
}

/**
 * @param array<string, mixed> $input
 */
function validate(array $input): ?WP_Error
{
    foreach (['seo_title', 'meta_description'] as $field) {
        if (array_key_exists($field, $input) && !is_string($input[$field])) {
            return SeoBasics\invalid(CODE, sprintf('%s must be a string', $field));
        }
    }
    if (array_key_exists('robots', $input)) {
        $robots = $input['robots'];
        if (!is_array($robots)) {
            return SeoBasics\invalid(CODE, 'robots must be an object');
        }
        if (array_key_exists('index', $robots) && !in_array($robots['index'], ['default', 'index', 'noindex'], strict: true)) {
            return SeoBasics\invalid(CODE, 'robots.index must be default, index, or noindex');
        }
        if (array_key_exists('follow', $robots) && !in_array($robots['follow'], ['follow', 'nofollow'], strict: true)) {
            return SeoBasics\invalid(CODE, 'robots.follow must be follow or nofollow');
        }
    }
    return touched_columns($input) === [] ? SeoBasics\no_changes(CODE) : null;
}

/**
 * Apply index/follow to the model under AIOSEO's all-or-nothing robots_default rule.
 *
 * @param array<array-key, mixed> $robots
 */
function apply_robots(object $model, array $robots): void
{
    $current = robots($model);
    $index_sent = is_string($robots['index'] ?? null);
    $index = $index_sent ? (string) $robots['index'] : $current['index'];
    $follow = is_string($robots['follow'] ?? null) ? (string) $robots['follow'] : $current['follow'];

    // Only follow was sent, as nofollow, on a post that inherits: robots_default would reset it,
    // so the post moves to explicit robots with index kept, which is what inheriting meant.
    if (!$index_sent && $index === 'default' && $follow === 'nofollow') {
        $index = 'index';
    }

    if ($index === 'default') {
        foreach (ROBOT_FLAGS as $name) {
            $model->{$name} = $name === 'robots_default';
        }
        $model->robots_max_snippet = null;
        $model->robots_max_videopreview = null;
        $model->robots_max_imagepreview = 'large';
        return;
    }
    // Explicit robots: the advanced flags and limits the post already has are left as they are.
    $model->robots_default = false;
    $model->robots_noindex = $index === 'noindex';
    $model->robots_nofollow = $follow === 'nofollow';
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function get_post_seo(array $input): array|WP_Error
{
    if (!available()) {
        return not_active();
    }
    $post = resolve(SeoBasics\requested_id($input));
    if ($post instanceof WP_Error) {
        return $post;
    }
    return ['post_id' => $post->ID, 'seo' => read($post->ID)];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function edit_post_seo(array $input): array|WP_Error
{
    if (!available()) {
        return not_active();
    }
    $post = resolve(SeoBasics\requested_id($input));
    if ($post instanceof WP_Error) {
        return $post;
    }
    $invalid = validate($input);
    if ($invalid instanceof WP_Error) {
        return $invalid;
    }
    $before = read($post->ID);

    $model = model($post->ID);
    if (array_key_exists('seo_title', $input)) {
        $model->title = sanitize_text_field((string) $input['seo_title']);
    }
    if (array_key_exists('meta_description', $input)) {
        $model->description = sanitize_text_field((string) $input['meta_description']);
    }
    if (array_key_exists('robots', $input) && is_array($input['robots'])) {
        apply_robots($model, $input['robots']);
    }
    $error = save($model);
    if ($error instanceof WP_Error) {
        return $error;
    }

    $after = read($post->ID);
    return ['post_id' => $post->ID, 'changed' => SeoBasics\changed($before, $after), 'seo' => $after];
}
