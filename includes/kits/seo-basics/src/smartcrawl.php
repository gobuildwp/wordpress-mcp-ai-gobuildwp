<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SeoBasics\SmartCrawl;

use WP_Error;
use WPPilot\Kits\SeoBasics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * SmartCrawl: one post's meta title, description and robots, stored the way its editor does.
 *
 * Verified against SmartCrawl 3.16.4 (includes/core/admin/class-metabox.php save_postdata() and
 * save_robots_meta(); includes/core/entities/class-post.php):
 * - `_wds_title` and `_wds_metadesc`, sanitised with smartcrawl_sanitize_preserve_macros(),
 *   which keeps its %%macros%%; an empty value deletes the row;
 * - robots are two pairs of flags stored as "1": `_wds_meta-robots-noindex` / `-index` and
 *   `_wds_meta-robots-nofollow` / `-follow`. Which one counts depends on the post type: on a post
 *   type SmartCrawl noindexes only `-index` is read, otherwise only `-noindex`. A write sets at
 *   most one flag of each pair and "default" clears both.
 *
 * Its editor writes these with update_post_meta() unslashed and loses backslashes; this kit
 * slashes.
 */
const CODE = 'smartcrawl';

/** Ability field => meta key, for the text fields. */
const TEXT = [
    'title' => '_wds_title',
    'description' => '_wds_metadesc',
];

const FLAGS = [
    'noindex' => '_wds_meta-robots-noindex',
    'index' => '_wds_meta-robots-index',
    'nofollow' => '_wds_meta-robots-nofollow',
    'follow' => '_wds_meta-robots-follow',
];

/** Ability field => the pair of flags it chooses between. */
const PAIRS = [
    'robots_index' => ['noindex', 'index'],
    'robots_follow' => ['nofollow', 'follow'],
];

function available(): bool
{
    return defined('SMARTCRAWL_VERSION');
}

function not_active(): WP_Error
{
    return new WP_Error('smartcrawl_not_active', __('SmartCrawl is not active.', domain: 'wppilot'), ['status' => 409]);
}

function meta_string(int $post_id, string $key): string
{
    /** @var mixed $value */
    $value = get_post_meta($post_id, $key, true);
    return is_scalar($value) ? (string) $value : '';
}

function flag(int $post_id, string $which): bool
{
    return (bool) get_post_meta($post_id, FLAGS[$which], true);
}

/**
 * The stored flags. Which of them SmartCrawl obeys depends on the post type's setting.
 *
 * @return array{title: string, description: string, robots_index: string, robots_follow: string}
 */
function read(int $post_id): array
{
    return [
        'title' => meta_string($post_id, TEXT['title']),
        'description' => meta_string($post_id, TEXT['description']),
        'robots_index' => flag($post_id, 'noindex') ? 'noindex' : (flag($post_id, 'index') ? 'index' : 'default'),
        'robots_follow' => flag($post_id, 'nofollow') ? 'nofollow' : (flag($post_id, 'follow') ? 'follow' : 'default'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return list<string>
 */
function touched_keys(array $input): array
{
    $keys = [];
    foreach (TEXT as $field => $key) {
        if (array_key_exists($field, $input)) {
            $keys[] = $key;
        }
    }
    foreach (PAIRS as $field => $pair) {
        if (array_key_exists($field, $input)) {
            foreach ($pair as $which) {
                $keys[] = FLAGS[$which];
            }
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

function sanitize_macro_text(string $value): string
{
    return function_exists('smartcrawl_sanitize_preserve_macros')
        ? (string) \smartcrawl_sanitize_preserve_macros($value)
        : sanitize_text_field($value);
}

/**
 * Set a meta row, or delete it when the value is empty, as SmartCrawl's editor does.
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
function changes(array $input): array|WP_Error
{
    $changes = [];
    foreach (array_keys(TEXT) as $field) {
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

    $id = $post->ID;
    $before = read($id);
    foreach (TEXT as $field => $key) {
        if (array_key_exists($field, $changes)) {
            put($id, $key, sanitize_macro_text((string) $changes[$field]));
        }
    }
    foreach (PAIRS as $field => $pair) {
        if (array_key_exists($field, $changes)) {
            foreach ($pair as $which) {
                put($id, FLAGS[$which], $changes[$field] === $which ? '1' : '');
            }
        }
    }

    $after = read($id);
    return ['post_id' => $id, 'changed' => SeoBasics\changed($before, $after), 'seo' => $after];
}
