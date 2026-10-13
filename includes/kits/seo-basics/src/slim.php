<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SeoBasics\Slim;

use WP_Error;
use WPPilot\Kits\SeoBasics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Slim SEO: one post's meta title, description and noindex flag, in Slim SEO's post meta row.
 *
 * Verified against Slim SEO 4.11.0 (src/MetaTags/Settings/Base.php save()):
 * - everything a post overrides is ONE post meta row, `slim_seo`, an array with the keys title,
 *   description, canonical, noindex (1 or absent), facebook_image and twitter_image. A write
 *   here changes title, description and noindex and keeps the rest of the row as it is;
 * - text goes through sanitize_text_field(), empty values are dropped, and the row is deleted
 *   when nothing is left, as Slim SEO's own save does;
 * - Slim SEO cannot force index and keeps no follow flag at all: noindex can only be switched on
 *   per post, "index" is the absence of the flag, and a post type set to noindex in Slim SEO's
 *   settings stays noindexed whatever the post says.
 *
 * The row is written with update_post_meta(), which unslashes, so it is slashed first; Slim SEO's
 * own save path does not, and loses backslashes.
 */
const CODE = 'slim_seo';

const META_KEY = 'slim_seo';

function available(): bool
{
    return defined('SLIM_SEO_VER');
}

function not_active(): WP_Error
{
    return new WP_Error('slim_seo_not_active', __('Slim SEO is not active.', domain: 'wppilot'), ['status' => 409]);
}

/**
 * @return array<string, mixed>
 */
function row(int $post_id): array
{
    /** @var mixed $row */
    $row = get_post_meta($post_id, META_KEY, true);
    return is_array($row) ? $row : [];
}

/**
 * @return array{title: string, description: string, robots_index: string}
 */
function read(int $post_id): array
{
    $row = row($post_id);
    return [
        'title' => is_scalar($row['title'] ?? null) ? (string) $row['title'] : '',
        'description' => is_scalar($row['description'] ?? null) ? (string) $row['description'] : '',
        'robots_index' => !empty($row['noindex']) ? 'noindex' : 'default',
    ];
}

/**
 * @return array<string, string>
 */
function unsupported(): array
{
    return [
        'robots_index' => 'Slim SEO cannot force index: "index" and "default" both clear the post\'s noindex flag, and a post type set to noindex stays noindexed.',
        'robots_follow' => 'Slim SEO has no per-post nofollow.',
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|null
 */
function snapshot(array $input): ?array
{
    return available() ? SeoBasics\partial_snapshot(SeoBasics\requested_int($input), [META_KEY]) : null;
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
    if (array_key_exists('robots_index', $input)) {
        if (!in_array($input['robots_index'], ['default', 'index', 'noindex'], strict: true)) {
            return SeoBasics\invalid(CODE, 'robots_index must be one of: default, index, noindex.');
        }
        $changes['robots_index'] = $input['robots_index'];
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
    return ['post_id' => $post->ID, 'seo' => read($post->ID), 'unsupported' => unsupported()];
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
    $row = row($id);
    foreach (['title', 'description'] as $field) {
        if (array_key_exists($field, $changes)) {
            $row[$field] = sanitize_text_field((string) $changes[$field]);
        }
    }
    if (array_key_exists('robots_index', $changes)) {
        // No "force index" exists: index and default both clear the flag.
        $row['noindex'] = $changes['robots_index'] === 'noindex' ? 1 : 0;
    }
    $row = array_filter($row);
    if ($row === []) {
        delete_post_meta($id, META_KEY);
    } else {
        update_post_meta($id, META_KEY, wp_slash($row));
    }

    $after = read($id);
    return ['post_id' => $id, 'changed' => SeoBasics\changed($before, $after), 'seo' => $after, 'unsupported' => unsupported()];
}
