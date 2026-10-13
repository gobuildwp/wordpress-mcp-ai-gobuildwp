<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SeoBasics\SeoPress;

use WP_Error;
use WPPilot\Kits\SeoBasics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * SEOPress: one post's meta title, meta description and robots, in SEOPress's post meta.
 *
 * Verified against SEOPress 10.2:
 * - `_seopress_titles_title` and `_seopress_titles_desc` hold the per-post templates, which may
 *   contain %%dynamic_variables%%;
 * - "no override" is an ABSENT row, never a blank one: an empty title or description deletes
 *   the row so the post inherits the global / post type template;
 * - robots are checkbox rows that store the literal 'yes' and are DELETED when unchecked:
 *   `_seopress_robots_index` = 'yes' means noindex, `_seopress_robots_follow` = 'yes' means
 *   nofollow. There is no per-post "force index": false clears the override, and a global or
 *   post type noindex still applies, which `robots.effective` reports.
 */
const CODE = 'seopress';

const TITLE_KEY = '_seopress_titles_title';

const DESCRIPTION_KEY = '_seopress_titles_desc';

/** robots flag => meta key. */
const ROBOTS = [
    'noindex' => '_seopress_robots_index',
    'nofollow' => '_seopress_robots_follow',
];

function available(): bool
{
    return defined('SEOPRESS_VERSION');
}

function not_active(): WP_Error
{
    return new WP_Error('seopress_not_active', 'SeoPress is not active', ['status' => 400]);
}

function meta(int $post_id, string $key): mixed
{
    return get_post_meta($post_id, $key, true);
}

function meta_string(int $post_id, string $key): string
{
    /** @var mixed $value */
    $value = meta($post_id, $key);
    return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
}

/**
 * Whether a global titles-option toggle ('1' when on) or a post type default is set.
 */
function option_on(mixed $raw): bool
{
    return !($raw === null || $raw === '' || $raw === '0' || $raw === false || $raw === 0);
}

/**
 * The robots SEOPress actually prints for the post: the post's own override, a global toggle,
 * the post type's default, or (for noindex) a password on the post, any of which forces it.
 *
 * @param array{noindex: bool, nofollow: bool} $own
 * @return array{noindex: bool, nofollow: bool}
 */
function effective_robots(\WP_Post $post, array $own): array
{
    /** @var mixed $option */
    $option = get_option('seopress_titles_option_name');
    $titles = is_array($option) ? $option : [];
    $types = is_array($titles['seopress_titles_single_titles'] ?? null) ? $titles['seopress_titles_single_titles'] : [];
    $type = is_array($types[$post->post_type] ?? null) ? $types[$post->post_type] : [];

    return [
        'noindex' => $own['noindex'] || post_password_required($post) || option_on($titles['seopress_titles_noindex'] ?? null) || option_on($type['noindex'] ?? null),
        'nofollow' => $own['nofollow'] || option_on($titles['seopress_titles_nofollow'] ?? null) || option_on($type['nofollow'] ?? null),
    ];
}

/**
 * @return array{title: string, description: string, robots: array{noindex: bool, nofollow: bool, effective: array{noindex: bool, nofollow: bool}}}
 */
function read(\WP_Post $post): array
{
    $own = [
        'noindex' => meta($post->ID, ROBOTS['noindex']) === 'yes',
        'nofollow' => meta($post->ID, ROBOTS['nofollow']) === 'yes',
    ];
    return [
        'title' => sanitize_text_field(meta_string($post->ID, TITLE_KEY)),
        'description' => sanitize_text_field(meta_string($post->ID, DESCRIPTION_KEY)),
        'robots' => array_merge($own, ['effective' => effective_robots($post, $own)]),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return list<string>
 */
function touched_keys(array $input): array
{
    $keys = [];
    if (array_key_exists('title', $input)) {
        $keys[] = TITLE_KEY;
    }
    if (array_key_exists('description', $input)) {
        $keys[] = DESCRIPTION_KEY;
    }
    $robots = is_array($input['robots'] ?? null) ? $input['robots'] : [];
    foreach (ROBOTS as $flag => $key) {
        if (array_key_exists($flag, $robots)) {
            $keys[] = $key;
        }
    }
    return $keys;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|null
 */
function snapshot(array $input): ?array
{
    return available() ? SeoBasics\partial_snapshot(SeoBasics\requested_int($input), touched_keys($input)) : null;
}

/**
 * @param array<string, mixed> $input
 */
function validate(array $input): ?WP_Error
{
    foreach (['title', 'description'] as $field) {
        if (array_key_exists($field, $input) && !is_string($input[$field])) {
            return SeoBasics\invalid(CODE, sprintf('%s must be a string', $field));
        }
    }
    if (array_key_exists('robots', $input)) {
        if (!is_array($input['robots'])) {
            return SeoBasics\invalid(CODE, 'robots must be an object');
        }
        foreach (array_keys(ROBOTS) as $flag) {
            if (array_key_exists($flag, $input['robots']) && !is_bool($input['robots'][$flag])) {
                return SeoBasics\invalid(CODE, sprintf('robots.%s must be a boolean', $flag));
            }
        }
    }
    return touched_keys($input) === [] ? SeoBasics\no_changes(CODE) : null;
}

/**
 * Store a sanitised value, or delete the row when it is empty: SEOPress's "no override".
 */
function put(int $post_id, string $key, string $value): void
{
    if ($value === '') {
        delete_post_meta($post_id, $key);
        return;
    }
    update_post_meta($post_id, $key, wp_slash($value));
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
    return ['post_id' => $post->ID, 'seo' => read($post)];
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
    $post = SeoBasics\resolve_post(SeoBasics\requested_id($input), CODE);
    if ($post instanceof WP_Error) {
        return $post;
    }
    $invalid = validate($input);
    if ($invalid instanceof WP_Error) {
        return $invalid;
    }
    $id = $post->ID;
    $before = read($post);

    if (array_key_exists('title', $input)) {
        put($id, TITLE_KEY, sanitize_text_field((string) $input['title']));
    }
    if (array_key_exists('description', $input)) {
        // As SEOPress's metabox saves it.
        put($id, DESCRIPTION_KEY, sanitize_textarea_field((string) $input['description']));
    }
    $robots = is_array($input['robots'] ?? null) ? $input['robots'] : [];
    foreach (ROBOTS as $flag => $key) {
        if (array_key_exists($flag, $robots)) {
            put($id, $key, $robots[$flag] === true ? 'yes' : '');
        }
    }

    $after = read($post);
    return ['post_id' => $id, 'changed' => SeoBasics\changed($before, $after), 'seo' => $after];
}
