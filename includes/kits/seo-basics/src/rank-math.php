<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SeoBasics\RankMath;

use WP_Error;
use WPPilot\Kits\SeoBasics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Rank Math: one post's SEO title, meta description and robots, in Rank Math's post meta.
 *
 * Verified against Rank Math 1.0.279:
 * - `rank_math_title` and `rank_math_description` are plain meta strings that may hold Rank
 *   Math variables (%title%, %sep%). WordPress's sanitize_text_field() strips %XX octets and would
 *   turn '%category%' into 'tegory%', so text is cleaned with wp_kses() and no tags, which is
 *   what Rank Math's own field sanitiser does;
 * - robots are ONE meta row, `rank_math_robots`, an array of tokens: index or noindex (neither
 *   means "use the post type default"), nofollow (its absence is follow), and the advanced
 *   noarchive / noimageindex / nosnippet. A robots write here changes only index and follow and
 *   keeps whatever advanced tokens the post already has.
 */
const CODE = 'rank_math';

const TITLE_KEY = 'rank_math_title';

const DESCRIPTION_KEY = 'rank_math_description';

const ROBOTS_KEY = 'rank_math_robots';

const ADVANCED = ['noarchive', 'noimageindex', 'nosnippet'];

function available(): bool
{
    return defined('RANK_MATH_VERSION');
}

function not_active(): WP_Error
{
    return new WP_Error('rank_math_not_active', 'Rank Math is not active', ['status' => 400]);
}

function meta_string(int $post_id, string $key): string
{
    /** @var mixed $value */
    $value = get_post_meta($post_id, $key, true);
    return is_string($value) ? $value : '';
}

/**
 * The stored robots tokens, only the ones Rank Math recognises.
 *
 * @return list<string>
 */
function tokens(int $post_id): array
{
    /** @var mixed $raw */
    $raw = get_post_meta($post_id, ROBOTS_KEY, true);
    if (!is_array($raw)) {
        return [];
    }
    $allowed = array_merge(['index', 'noindex', 'nofollow'], ADVANCED);
    return array_values(array_intersect(array_filter($raw, 'is_string'), $allowed));
}

/**
 * @param list<string> $tokens
 * @return 'default'|'index'|'noindex'
 */
function index_label(array $tokens): string
{
    if (in_array('noindex', $tokens, strict: true)) {
        return 'noindex';
    }
    return in_array('index', $tokens, strict: true) ? 'index' : 'default';
}

/**
 * The token array for an index and follow choice, keeping the advanced tokens in Rank Math's
 * order. index and noindex are mutually exclusive; 'default' writes neither.
 *
 * @param list<string> $advanced
 * @return list<string>
 */
function build_tokens(string $index, string $follow, array $advanced): array
{
    $tokens = [];
    if ($index === 'index' || $index === 'noindex') {
        $tokens[] = $index;
    }
    if ($follow === 'nofollow') {
        $tokens[] = 'nofollow';
    }
    foreach (ADVANCED as $token) {
        if (in_array($token, $advanced, strict: true)) {
            $tokens[] = $token;
        }
    }
    return $tokens;
}

/**
 * Strip markup but keep Rank Math's %variables%.
 */
function clean_text(string $value): string
{
    return trim(wp_kses($value, []));
}

/**
 * @return array{seo_title: string, meta_description: string, robots: array{index: string, follow: string}}
 */
function read(int $post_id): array
{
    $tokens = tokens($post_id);
    return [
        'seo_title' => meta_string($post_id, TITLE_KEY),
        'meta_description' => meta_string($post_id, DESCRIPTION_KEY),
        'robots' => [
            'index' => index_label($tokens),
            'follow' => in_array('nofollow', $tokens, strict: true) ? 'nofollow' : 'follow',
        ],
    ];
}

/**
 * @param array<string, mixed> $input
 * @return list<string>
 */
function touched_keys(array $input): array
{
    $keys = [];
    if (array_key_exists('seo_title', $input)) {
        $keys[] = TITLE_KEY;
    }
    if (array_key_exists('meta_description', $input)) {
        $keys[] = DESCRIPTION_KEY;
    }
    if (array_key_exists('robots', $input)) {
        $keys[] = ROBOTS_KEY;
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
    return touched_keys($input) === [] ? SeoBasics\no_changes(CODE) : null;
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
    $before = read($id);

    if (array_key_exists('seo_title', $input)) {
        update_post_meta($id, TITLE_KEY, wp_slash(clean_text((string) $input['seo_title'])));
    }
    if (array_key_exists('meta_description', $input)) {
        update_post_meta($id, DESCRIPTION_KEY, wp_slash(clean_text((string) $input['meta_description'])));
    }
    if (array_key_exists('robots', $input) && is_array($input['robots'])) {
        $robots = $input['robots'];
        $current = tokens($id);
        $index = is_string($robots['index'] ?? null) ? $robots['index'] : index_label($current);
        $follow = is_string($robots['follow'] ?? null)
            ? $robots['follow']
            : (in_array('nofollow', $current, strict: true) ? 'nofollow' : 'follow');
        update_post_meta($id, ROBOTS_KEY, wp_slash(build_tokens($index, $follow, array_values(array_intersect($current, ADVANCED)))));
    }

    $after = read($id);
    return ['post_id' => $id, 'changed' => SeoBasics\changed($before, $after), 'seo' => $after];
}
