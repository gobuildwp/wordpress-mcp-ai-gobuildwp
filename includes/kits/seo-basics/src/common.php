<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SeoBasics;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\PostPartial;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * What every SEO plugin's module in this kit shares: which plugins are active, the post an
 * ability may act on, and the partial before-image a meta-backed write keeps.
 *
 * Scope is deliberately one post's title, meta description and robots. Focus keywords, canonical
 * URLs, social previews, schema and redirects are what GoBuildWP Pro's copies of these same
 * abilities add; on a licensed Pro site Pro registers first and this kit stands aside.
 */

/**
 * Plugin slug => whether its loader has run, by the constant each plugin defines as it loads.
 *
 * Read at plugins_loaded (the kit loader's hook), when every active plugin's main file has been
 * included. Each module checks its own API again before it reads or writes.
 *
 * @return array<string, bool>
 */
function vendors(): array
{
    return [
        'yoast' => defined('WPSEO_VERSION'),
        'rank-math' => defined('RANK_MATH_VERSION'),
        'aioseo' => defined('AIOSEO_VERSION'),
        'seopress' => defined('SEOPRESS_VERSION'),
        'tsf' => defined('THE_SEO_FRAMEWORK_VERSION'),
        'slim-seo' => defined('SLIM_SEO_VER'),
        // SmartCrawl defines SMARTCRAWL_VERSION only on init (priority 1); its main file declares
        // the SmartCrawl\SmartCrawl class, which is what can be seen at plugins_loaded.
        'smartcrawl' => defined('SMARTCRAWL_VERSION') || class_exists('SmartCrawl\\SmartCrawl'),
    ];
}

/**
 * @return list<string>
 */
function active_vendors(): array
{
    return array_keys(array_filter(vendors()));
}

/**
 * The base permission for every ability here: the host's switch and capability, and editing
 * posts at all. The post itself is checked again in resolve_post().
 */
function can_edit_posts(): bool
{
    return Runtime\can_run() && current_user_can('edit_posts');
}

/**
 * The post id an input names: `post_id`, or its `id` alias where the ability accepts one.
 *
 * @param array<string, mixed> $input
 */
function requested_id(array $input): mixed
{
    return $input['post_id'] ?? $input['id'] ?? null;
}

/**
 * The same id as a positive int, or 0; for before-image captures, which never refuse.
 *
 * @param array<string, mixed> $input
 */
function requested_int(array $input): int
{
    $raw = requested_id($input);
    if (is_int($raw)) {
        return max(0, $raw);
    }
    if ((is_float($raw) && floor($raw) === $raw && $raw > 0 && $raw < 2147483647) || (is_string($raw) && ctype_digit($raw))) {
        return (int) $raw;
    }
    return 0;
}

/**
 * The post, when it is a real post (not a revision or auto-draft) that the current user may edit.
 *
 * @param string $code Error code prefix: the plugin's, so errors read like Pro's copies of these abilities.
 */
function resolve_post(mixed $raw, string $code): \WP_Post|WP_Error
{
    $numeric = is_int($raw)
        || (is_float($raw) && floor($raw) === $raw)
        || (is_string($raw) && ctype_digit($raw));
    if (!$numeric) {
        return new WP_Error($code . '_invalid_post', 'post_id must be a numeric post id', ['status' => 400]);
    }
    $id = requested_int(['post_id' => $raw]);
    // get_post(0) falls back to the global post, so a zero id must never reach it.
    $post = $id > 0 ? get_post($id) : null;
    if (!$post instanceof \WP_Post) {
        return new WP_Error($code . '_post_not_found', sprintf('post not found: %d', $id), ['status' => 404]);
    }
    if ($post->post_type === 'revision' || $post->post_status === 'auto-draft') {
        return new WP_Error($code . '_invalid_post', sprintf('post %d is a revision or auto-draft', $id), ['status' => 400]);
    }
    if (!current_user_can('edit_post', $id)) {
        return new WP_Error($code . '_forbidden', sprintf(
            /* translators: %d: post id. */
            __('You are not allowed to edit post %d.', domain: 'wppilot'),
            $id,
        ), ['status' => 403]);
    }
    return $post;
}

function invalid(string $code, string $message): WP_Error
{
    return new WP_Error($code . '_invalid_input', $message, ['status' => 400]);
}

function no_changes(string $code): WP_Error
{
    return new WP_Error(
        $code . '_no_changes',
        __('Send at least one of the SEO fields this ability sets.', domain: 'wppilot'),
        ['status' => 400],
    );
}

/**
 * The fields whose value differs between two reads, as dotted paths (`robots.index`).
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @return list<string>
 */
function changed(array $before, array $after, string $prefix = ''): array
{
    $changed = [];
    foreach ($after as $key => $value) {
        $path = $prefix . (string) $key;
        $old = $before[$key] ?? null;
        if (is_array($value) && is_array($old) && !array_is_list_compat($value)) {
            $changed = array_merge($changed, changed($old, $value, $path . '.'));
        } elseif ($old !== $value) {
            $changed[] = $path;
        }
    }
    return $changed;
}

/**
 * array_is_list() for PHP 8.0, which does not have it.
 *
 * @param array<array-key, mixed> $value
 */
function array_is_list_compat(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

/**
 * A before-image of exactly these meta keys on the post, which the runtime's post-partial
 * strategy restores and verifies, or null when there is no post or nothing to keep.
 *
 * @param list<string> $meta_keys
 * @return array<string, mixed>|null
 */
function partial_snapshot(int $post_id, array $meta_keys): ?array
{
    if ($post_id <= 0 || $meta_keys === []) {
        return null;
    }
    return PostPartial\capture($post_id, [], array_values(array_unique($meta_keys)));
}
