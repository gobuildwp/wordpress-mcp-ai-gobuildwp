<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Store;

use WPPilot\Design\Cpt;
use WPPilot\Design\Parser;

if (!defined('ABSPATH')) {
    exit();
}

/** Active design slug, or '' if none. */
function get_active_slug(): string
{
    return (string) get_option(Cpt\OPTION_ACTIVE, default_value: '');
}

function set_active(string $slug): void
{
    update_option(Cpt\OPTION_ACTIVE, $slug);
}

/** Find a user-saved design post by slug. */
function find_user_post(string $slug): ?\WP_Post
{
    if ($slug === '') {
        return null;
    }
    /** @var list<\WP_Post> $posts */
    $posts = get_posts([
        'post_type' => Cpt\POST_TYPE,
        'name' => $slug,
        'post_status' => 'any',
        'numberposts' => 1,
        'no_found_rows' => true,
    ]);
    return $posts[0] ?? null;
}

/**
 * All user-saved designs as records.
 *
 * @return list<array{slug: string, name: string, description: string, content: string}>
 */
function all_user(): array
{
    /** @var list<\WP_Post> $posts */
    $posts = get_posts([
        'post_type' => Cpt\POST_TYPE,
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'no_found_rows' => true,
    ]);

    $records = [];
    foreach ($posts as $post) {
        $content = $post->post_content;
        $parsed = Parser\parse($content);
        $records[] = [
            'slug' => $post->post_name,
            'name' => $parsed['name'] !== '' ? $parsed['name'] : $post->post_title,
            'description' => $parsed['description'],
            'content' => $content,
        ];
    }
    return $records;
}

/**
 * Create or update a user design from raw DESIGN.md. Slug is derived from
 * the explicit $slug, else from the parsed name. Upsert by slug. Records
 * who wrote it ($actor: 'agent' or 'user') as post meta for provenance.
 *
 * @return array{slug: string, name: string}
 */
function save(string $content, ?string $slug = null, string $actor = 'agent'): array
{
    $parsed = Parser\parse($content);
    $name = $parsed['name'];
    $final_slug = Parser\normalize_slug($slug !== null && $slug !== '' ? $slug : $name);

    $existing = find_user_post($final_slug);
    $postarr = [
        'post_type' => Cpt\POST_TYPE,
        'post_title' => $name !== '' ? $name : $final_slug,
        'post_name' => $final_slug,
        'post_content' => $content,
        'post_status' => 'publish',
    ];
    if ($existing instanceof \WP_Post) {
        \WPPilot\Design\Revisions\snapshot_current($existing);
        $postarr['ID'] = $existing->ID;
    }
    // @mago-expect analysis:possibly-invalid-argument
    $result = wp_insert_post(wp_slash($postarr), wp_error: true);
    if (is_wp_error($result)) {
        return ['slug' => '', 'name' => $name];
    }

    update_post_meta((int) $result, Cpt\META_LAST_ACTOR, $actor === 'user' ? 'user' : 'agent');

    /** @var \WP_Post|null $saved_post */
    $saved_post = get_post((int) $result);
    if ($saved_post instanceof \WP_Post) {
        \WPPilot\Design\Revisions\snapshot_current($saved_post);
    }

    return ['slug' => $final_slug, 'name' => $name];
}

/**
 * Delete a saved design by slug. If the deleted design was the active one, the
 * active slug is cleared. Returns whether a design was actually removed and
 * whether it had been active.
 *
 * @return array{deleted: bool, was_active: bool}
 */
function delete(string $slug): array
{
    $normalized = Parser\normalize_slug($slug);
    if ($normalized === '') {
        return ['deleted' => false, 'was_active' => false];
    }

    $post = find_user_post($normalized);
    if (!$post instanceof \WP_Post) {
        return ['deleted' => false, 'was_active' => false];
    }

    $was_active = get_active_slug() === $normalized;
    $result = wp_delete_post($post->ID, force_delete: true);
    if ($result === false || $result === null) {
        return ['deleted' => false, 'was_active' => $was_active];
    }

    if ($was_active) {
        set_active('');
    }

    return ['deleted' => true, 'was_active' => $was_active];
}
