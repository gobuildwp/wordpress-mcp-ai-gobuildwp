<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SeoBasics\Tsf;

use WP_Error;
use WPPilot\Kits\SeoBasics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The SEO Framework: one post's meta title, description and robots, through TSF's post data API.
 *
 * Verified against The SEO Framework 5.1.4 (inc/classes/data/plugin/post.class.php):
 * - post SEO is post meta listed by tsf()->data()->plugin()->post()->get_default_meta():
 *   `_genesis_title`, `_genesis_description`, `_genesis_noindex`, `_genesis_nofollow` and a dozen
 *   more (canonical, social, redirect, archive exclusions);
 * - save_meta() merges what it is given over the defaults and rewrites EVERY one of those keys,
 *   deleting the empty ones, which is also what TSF's own single-item update does. So the
 *   before-image covers all of them, not only the fields sent: that is what the write touches;
 * - robots are "qubits": 1 forces noindex / nofollow, -1 forces index / follow, 0 follows the
 *   site and post type settings.
 *
 * save_meta() hands each value to update_post_meta(), which unslashes, so every value is slashed
 * first; without that a write here would strip the backslashes from every TSF field of the post,
 * including the ones this call did not change.
 */
const CODE = 'tsf';

const TITLE_KEY = '_genesis_title';

const DESCRIPTION_KEY = '_genesis_description';

/** Ability field => TSF meta key. */
const ROBOTS = [
    'robots_index' => '_genesis_noindex',
    'robots_follow' => '_genesis_nofollow',
];

function available(): bool
{
    return defined('THE_SEO_FRAMEWORK_VERSION') && function_exists('tsf');
}

function not_active(): WP_Error
{
    return new WP_Error('tsf_not_active', __('The SEO Framework is not active.', domain: 'wppilot'), ['status' => 409]);
}

/**
 * TSF's post data object, or null when this TSF version does not expose it, so a release that
 * moves the API reports itself as unsupported instead of fataling.
 */
function post_data(): ?object
{
    if (!available()) {
        return null;
    }
    /** @var mixed $object */
    $object = \tsf();
    foreach (['data', 'plugin', 'post'] as $method) {
        if (!is_object($object) || !method_exists($object, $method)) {
            return null;
        }
        $object = $object->{$method}();
    }
    return is_object($object) ? $object : null;
}

/**
 * Every meta key TSF's save_meta() writes for this post.
 *
 * @return list<string>
 */
function meta_keys(int $post_id): array
{
    $data = post_data();
    /** @var mixed $defaults */
    $defaults = $data !== null && method_exists($data, 'get_default_meta') ? $data->get_default_meta($post_id) : [];
    $keys = is_array($defaults) ? array_map('strval', array_keys($defaults)) : [];
    return array_values(array_unique(array_merge($keys, [TITLE_KEY, DESCRIPTION_KEY], array_values(ROBOTS))));
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|null
 */
function snapshot(array $input): ?array
{
    $post_id = SeoBasics\requested_int($input);
    return available() ? SeoBasics\partial_snapshot($post_id, meta_keys($post_id)) : null;
}

/**
 * @return array<string, mixed>
 */
function stored_meta(int $post_id): array
{
    $data = post_data();
    /** @var mixed $meta */
    $meta = $data !== null && method_exists($data, 'get_meta') ? $data->get_meta($post_id) : [];
    return is_array($meta) ? $meta : [];
}

function index_label(mixed $qubit): string
{
    $value = (int) $qubit;
    return $value > 0 ? 'noindex' : ($value < 0 ? 'index' : 'default');
}

function follow_label(mixed $qubit): string
{
    $value = (int) $qubit;
    return $value > 0 ? 'nofollow' : ($value < 0 ? 'follow' : 'default');
}

/**
 * @return array{title: string, description: string, robots_index: string, robots_follow: string}
 */
function read(int $post_id): array
{
    $meta = stored_meta($post_id);
    return [
        'title' => is_scalar($meta[TITLE_KEY] ?? null) ? (string) $meta[TITLE_KEY] : '',
        'description' => is_scalar($meta[DESCRIPTION_KEY] ?? null) ? (string) $meta[DESCRIPTION_KEY] : '',
        'robots_index' => index_label($meta[ROBOTS['robots_index']] ?? 0),
        'robots_follow' => follow_label($meta[ROBOTS['robots_follow']] ?? 0),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function changes(array $input): array|WP_Error
{
    $changes = [];
    foreach (['title', 'description'] as $field) {
        if (array_key_exists($field, $input)) {
            if (!is_string($input[$field])) {
                return SeoBasics\invalid(CODE, sprintf('%s must be a string.', $field));
            }
            $changes[$field] = $input[$field];
        }
    }
    $allowed = ['robots_index' => ['default', 'index', 'noindex'], 'robots_follow' => ['default', 'follow', 'nofollow']];
    foreach ($allowed as $field => $values) {
        if (array_key_exists($field, $input)) {
            if (!in_array($input[$field], $values, strict: true)) {
                return SeoBasics\invalid(CODE, sprintf('%s must be one of: %s.', $field, implode(', ', $values)));
            }
            $changes[$field] = $input[$field];
        }
    }
    return $changes === [] ? SeoBasics\no_changes(CODE) : $changes;
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
    $post = SeoBasics\resolve_post(SeoBasics\requested_id($input), CODE);
    if ($post instanceof WP_Error) {
        return $post;
    }
    return ['post_id' => $post->ID, 'seo' => read($post->ID)];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function update_post_seo(array $input): array|WP_Error
{
    if (!available()) {
        return not_active();
    }
    $post = SeoBasics\resolve_post(SeoBasics\requested_id($input), CODE);
    if ($post instanceof WP_Error) {
        return $post;
    }
    $changes = changes($input);
    if ($changes instanceof WP_Error) {
        return $changes;
    }
    $data = post_data();
    if ($data === null || !method_exists($data, 'save_meta') || !method_exists($data, 'get_meta')) {
        return new WP_Error('tsf_api_missing', __('This version of The SEO Framework does not expose the post meta API this kit writes through.', domain: 'wppilot'), ['status' => 501]);
    }

    $id = $post->ID;
    $before = read($id);
    $meta = stored_meta($id);
    if (array_key_exists('title', $changes)) {
        $meta[TITLE_KEY] = (string) $changes['title'];
    }
    if (array_key_exists('description', $changes)) {
        $meta[DESCRIPTION_KEY] = (string) $changes['description'];
    }
    $qubits = ['default' => 0, 'noindex' => 1, 'nofollow' => 1, 'index' => -1, 'follow' => -1];
    foreach (ROBOTS as $field => $key) {
        if (array_key_exists($field, $changes)) {
            $meta[$key] = $qubits[(string) $changes[$field]] ?? 0;
        }
    }
    // Each value slashed on its own: save_meta() stores them one by one through update_post_meta().
    $data->save_meta($id, array_map('wp_slash', $meta));

    $after = read($id);
    return ['post_id' => $id, 'changed' => SeoBasics\changed($before, $after), 'seo' => $after];
}
