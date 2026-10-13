<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Post storage for kit tests, under names that carry no plugin prefix.
 *
 * Kit tests are exported with their kit (scripts/export-kit.php) and must not name GoBuildWP, so
 * they cannot reach WPPilot_Test_State directly. They set up posts, meta, users and capabilities
 * through Kit_Test_Site instead, and the receiving plugin's harness provides its own
 * Kit_Test_Site over its own doubles. The WordPress functions below are the post and meta writes
 * the shared doubles lack; each is guarded, so a harness that already has one keeps its own.
 *
 * One meta model for every kit test: WPPilot_Test_State::$post_meta holds the raw strings the
 * postmeta table would (arrays serialized by maybe_serialize() on write), raw_meta() reads them
 * the way a $wpdb query does, and wordpress.php's get_post_meta() returns them unserialized, as
 * WordPress does. These are the only definitions of the meta and post writes in the suite.
 */
final class Kit_Test_Site
{
    /** When set, wp_update_post() passes post_content through it, as kses would. */
    public static ?Closure $content_filter = null;

    /** When true, meta writes store nothing, like a filter or read-only meta layer refusing them. */
    public static bool $refuse_meta_writes = false;

    public static function reset(): void
    {
        // Only the state kit tests use: a full reset would also drop the ability registrations
        // the bootstrap made, which later test classes read.
        WPPilot_Test_State::$posts = [];
        WPPilot_Test_State::$post_meta = [];
        WPPilot_Test_State::$post_types = [];
        WPPilot_Test_State::$capabilities = [];
        WPPilot_Test_State::$current_user_id = 0;
        foreach (array_keys(WPPilot_Test_State::$options) as $name) {
            if (str_starts_with((string) $name, 'wppilot_kit_')) {
                unset(WPPilot_Test_State::$options[$name]);
            }
        }
        self::$content_filter = null;
        self::$refuse_meta_writes = false;
    }

    public static function as_user(int $user_id, string ...$capabilities): void
    {
        WPPilot_Test_State::$current_user_id = $user_id;
        WPPilot_Test_State::$capabilities = array_values($capabilities);
    }

    public static function register_post_types(string ...$types): void
    {
        foreach ($types as $type) {
            $object = new WP_Post_Type();
            $object->name = $type;
            WPPilot_Test_State::$post_types[$type] = $object;
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function insert(array $fields): int
    {
        $post = new WP_Post();
        $post->ID = (int) ($fields['ID'] ?? (WPPilot_Test_State::$posts === [] ? 1 : max(array_keys(WPPilot_Test_State::$posts)) + 1));
        foreach ($fields as $name => $value) {
            if ($name !== 'ID' && property_exists($post, (string) $name)) {
                $post->{$name} = $value;
            }
        }
        WPPilot_Test_State::$posts[$post->ID] = $post;
        return $post->ID;
    }

    /** @return list<int> */
    public static function post_ids(): array
    {
        $ids = array_keys(WPPilot_Test_State::$posts);
        sort($ids);
        return $ids;
    }

    /**
     * @param list<string> $values Raw stored strings.
     */
    public static function set_raw_meta(int $post_id, string $key, array $values): void
    {
        WPPilot_Test_State::$post_meta[$post_id][$key] = array_values($values);
    }

    /** @return array<string, list<string>> */
    public static function raw_meta(int $post_id): array
    {
        return WPPilot_Test_State::$post_meta[$post_id] ?? [];
    }

    /** One meta value as update_post_meta() would store it, replacing any others. */
    public static function set_meta(int $post_id, string $key, mixed $value): void
    {
        WPPilot_Test_State::$post_meta[$post_id][$key] = [(string) maybe_serialize($value)];
    }

    /** Remove a post and its meta, as a permanent delete would. */
    public static function remove(int $post_id): void
    {
        unset(WPPilot_Test_State::$posts[$post_id], WPPilot_Test_State::$post_meta[$post_id]);
    }

    /**
     * The arguments an ability was last registered with, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function registration(string $name): ?array
    {
        foreach (array_reverse(WPPilot_Test_State::$registrations) as $registration) {
            if ($registration['name'] === $name) {
                return $registration['args'];
            }
        }
        return null;
    }
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!function_exists('metadata_exists')) {
    function metadata_exists(string $meta_type, int $object_id, string $meta_key): bool
    {
        return isset(WPPilot_Test_State::$post_meta[$object_id][$meta_key]);
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = ''): int|bool
    {
        if (Kit_Test_Site::$refuse_meta_writes) {
            return false;
        }
        $value = (string) maybe_serialize(is_string($meta_value) ? wp_unslash($meta_value) : $meta_value);
        // As core: writing the value already stored changes nothing and reports false.
        if ((WPPilot_Test_State::$post_meta[$post_id][$meta_key] ?? null) === [$value]) {
            return false;
        }
        WPPilot_Test_State::$post_meta[$post_id][$meta_key] = [$value];
        return true;
    }
}

if (!function_exists('add_post_meta')) {
    function add_post_meta(int $post_id, string $meta_key, mixed $meta_value, bool $unique = false): int|false
    {
        if (Kit_Test_Site::$refuse_meta_writes) {
            return false;
        }
        $value = (string) maybe_serialize(is_string($meta_value) ? wp_unslash($meta_value) : $meta_value);
        WPPilot_Test_State::$post_meta[$post_id][$meta_key][] = $value;
        return count(WPPilot_Test_State::$post_meta[$post_id][$meta_key]);
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $post_id, string $meta_key, mixed $meta_value = ''): bool
    {
        $existed = isset(WPPilot_Test_State::$post_meta[$post_id][$meta_key]);
        unset(WPPilot_Test_State::$post_meta[$post_id][$meta_key]);
        return $existed;
    }
}

if (!function_exists('wp_update_post')) {
    /**
     * @param array<string, mixed> $postarr
     */
    function wp_update_post(array $postarr, bool $wp_error = false, bool $fire_after_hooks = true): int|WP_Error
    {
        $post = WPPilot_Test_State::$posts[(int) ($postarr['ID'] ?? 0)] ?? null;
        if (!$post instanceof WP_Post) {
            return $wp_error ? new WP_Error('invalid_post', 'Invalid post ID.') : 0;
        }
        foreach ($postarr as $field => $value) {
            if ($field === 'ID' || !property_exists($post, (string) $field)) {
                continue;
            }
            $value = is_string($value) ? wp_unslash($value) : $value;
            if ($field === 'post_content' && Kit_Test_Site::$content_filter !== null) {
                $value = (Kit_Test_Site::$content_filter)($value);
            }
            $post->{$field} = $value;
        }
        return $post->ID;
    }
}
