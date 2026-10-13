<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SeoBasics\Yoast;

use WP_Error;
use WPPilot\Kits\SeoBasics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Yoast SEO: one post's SEO title, meta description and robots, through WPSEO_Meta.
 *
 * Verified against Yoast SEO 28.5 (inc/class-wpseo-meta.php, src/integrations/watchers/
 * indexable-post-meta-watcher.php):
 * - fields are post meta under `_yoast_wpseo_`; WPSEO_Meta::get_value() returns Yoast's default
 *   for a key the post never set, and set_value() wp_slash()es the value itself before
 *   update_post_meta(), so a value handed to it must NOT be slashed again;
 * - `meta-robots-noindex` is a trap: '0' follows the post type setting, '1' is noindex and '2'
 *   forces index. `meta-robots-nofollow` is '0' follow, '1' nofollow;
 * - Yoast's indexables table caches what the page prints. Its post meta watcher rebuilds a
 *   post's indexable at shutdown whenever any `_yoast_wpseo_` key is added, updated or deleted,
 *   which covers both these writes and a restore through the post-partial strategy, so nothing
 *   here refreshes it by hand (GoBuildWP Pro's copy does not either).
 */
const CODE = 'yoast';

const PREFIX = '_yoast_wpseo_';

/** Ability field => WPSEO_Meta key, for the text fields. */
const TEXT = [
    'seo_title' => 'title',
    'meta_description' => 'metadesc',
];

/** robots sub-field => WPSEO_Meta key. */
const ROBOTS = [
    'index' => 'meta-robots-noindex',
    'follow' => 'meta-robots-nofollow',
];

function available(): bool
{
    return defined('WPSEO_VERSION')
        && class_exists('WPSEO_Meta')
        && method_exists('WPSEO_Meta', 'get_value')
        && method_exists('WPSEO_Meta', 'set_value');
}

function not_active(): WP_Error
{
    return new WP_Error('yoast_not_active', 'Yoast SEO is not active', ['status' => 400]);
}

/**
 * @return 'default'|'index'|'noindex'
 */
function index_label(string $code): string
{
    return match ($code) {
        '1' => 'noindex',
        '2' => 'index',
        default => 'default',
    };
}

function index_code(string $label): ?string
{
    return match ($label) {
        'default' => '0',
        'index' => '2',
        'noindex' => '1',
        default => null,
    };
}

function follow_code(string $label): ?string
{
    return match ($label) {
        'follow' => '0',
        'nofollow' => '1',
        default => null,
    };
}

function value(string $key, int $post_id): string
{
    /** @var mixed $value */
    $value = \WPSEO_Meta::get_value($key, $post_id);
    return is_scalar($value) ? (string) $value : '';
}

/**
 * @return array{seo_title: string, meta_description: string, robots: array{index: string, follow: string}}
 */
function read(int $post_id): array
{
    return [
        'seo_title' => value('title', $post_id),
        'meta_description' => value('metadesc', $post_id),
        'robots' => [
            'index' => index_label(value('meta-robots-noindex', $post_id)),
            'follow' => value('meta-robots-nofollow', $post_id) === '1' ? 'nofollow' : 'follow',
        ],
    ];
}

/**
 * The meta keys this input would write, so the before-image covers exactly those.
 *
 * @param array<string, mixed> $input
 * @return list<string>
 */
function touched_keys(array $input): array
{
    $keys = [];
    foreach (TEXT as $field => $key) {
        if (array_key_exists($field, $input)) {
            $keys[] = PREFIX . $key;
        }
    }
    $robots = is_array($input['robots'] ?? null) ? $input['robots'] : [];
    foreach (ROBOTS as $field => $key) {
        if (array_key_exists($field, $robots)) {
            $keys[] = PREFIX . $key;
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
 * Every value checked before anything is written, so a bad robots value never leaves the title
 * changed and the robots not.
 *
 * @param array<string, mixed> $input
 * @return array<string, string>|WP_Error WPSEO_Meta key => value to set
 */
function writes(array $input): array|WP_Error
{
    $writes = [];
    foreach (TEXT as $field => $key) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        if (!is_string($input[$field])) {
            return SeoBasics\invalid(CODE, sprintf('%s must be a string', $field));
        }
        // Yoast sanitises its text meta the same way when it saves the editor.
        $writes[$key] = sanitize_text_field($input[$field]);
    }
    if (array_key_exists('robots', $input)) {
        $robots = $input['robots'];
        if (!is_array($robots)) {
            return SeoBasics\invalid(CODE, 'robots must be an object');
        }
        if (array_key_exists('index', $robots)) {
            $code = is_string($robots['index']) ? index_code($robots['index']) : null;
            if ($code === null) {
                return SeoBasics\invalid(CODE, 'robots.index must be default, index, or noindex');
            }
            $writes[ROBOTS['index']] = $code;
        }
        if (array_key_exists('follow', $robots)) {
            $code = is_string($robots['follow']) ? follow_code($robots['follow']) : null;
            if ($code === null) {
                return SeoBasics\invalid(CODE, 'robots.follow must be follow or nofollow');
            }
            $writes[ROBOTS['follow']] = $code;
        }
    }
    return $writes === [] ? SeoBasics\no_changes(CODE) : $writes;
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
    $writes = writes($input);
    if ($writes instanceof WP_Error) {
        return $writes;
    }
    $before = read($post->ID);
    foreach ($writes as $key => $value) {
        // set_value() slashes for update_post_meta() itself; slashing here would double every backslash.
        \WPSEO_Meta::set_value($key, $value, $post->ID);
    }
    $after = read($post->ID);

    return ['post_id' => $post->ID, 'changed' => SeoBasics\changed($before, $after), 'seo' => $after];
}
