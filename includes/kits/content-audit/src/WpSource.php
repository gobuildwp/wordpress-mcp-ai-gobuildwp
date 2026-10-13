<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ContentAudit;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Page;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The audit's Source, answered from WordPress.
 */
final class WpSource implements Source
{
    public function home_url(): string
    {
        return home_url('/');
    }

    public function published_ids(array $post_types, int $after, int $limit): array
    {
        global $wpdb;
        if ($post_types === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        // A keyset cursor rather than an offset: posts published between two pages would shift
        // an offset and skip or repeat content.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders}) AND ID > %d ORDER BY ID ASC LIMIT %d",
            ...array_values(array_merge($post_types, [$after, $limit])),
        ));
        return array_map('intval', is_array($ids) ? $ids : []);
    }

    public function count_published(array $post_types): int
    {
        global $wpdb;
        if ($post_types === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
            ...$post_types,
        ));
    }

    public function post(int $id): ?array
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post) {
            return null;
        }
        /** @var mixed $builder */
        $builder = get_post_meta($id, '_elementor_data', true);
        $url = get_permalink($post);
        return [
            'id' => $id,
            'title' => (string) $post->post_title,
            'type' => (string) $post->post_type,
            'url' => is_string($url) ? $url : '',
            'content' => (string) $post->post_content,
            'builder' => is_string($builder) ? $builder : '',
        ];
    }

    public function url_to_post_id(string $url): int
    {
        return (int) url_to_postid($url);
    }

    public function post_status(int $id): ?string
    {
        $status = get_post_status($id);
        return is_string($status) ? $status : null;
    }

    public function upload_file_exists(string $url): ?bool
    {
        $uploads = wp_get_upload_dir();
        $base = preg_replace('#^https?:#i', '', (string) ($uploads['baseurl'] ?? ''));
        $target = preg_replace('#^https?:#i', '', strtok($url, '?#') ?: '');
        if (!is_string($base) || $base === '' || !is_string($target) || !str_starts_with($target, rtrim($base, '/') . '/')) {
            return null;
        }
        $relative = rawurldecode(substr($target, strlen(rtrim($base, '/'))));
        if (str_contains($relative, '..') || str_contains($relative, "\0")) {
            // Never let a link in content walk the check out of the uploads directory.
            return false;
        }
        return file_exists(rtrim((string) $uploads['basedir'], '/\\') . $relative);
    }

    public function head(string $url, int $timeout): array|WP_Error
    {
        $args = [
            'timeout' => max(2, min(10, $timeout)),
            'redirection' => 0,
            'user-agent' => 'WordPress content audit (' . home_url('/') . ')',
        ];
        $response = wp_safe_remote_head($url, $args);
        if ($response instanceof WP_Error) {
            return $response;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if (in_array($status, [405, 501], true)) {
            // Some servers refuse HEAD outright; a GET capped at a few bytes asks the same question.
            $response = wp_safe_remote_get($url, $args + ['limit_response_size' => 2048]);
            if ($response instanceof WP_Error) {
                return $response;
            }
            $status = (int) wp_remote_retrieve_response_code($response);
        }
        return ['status' => $status, 'location' => (string) wp_remote_retrieve_header($response, 'location')];
    }

    public function structural_ids(): array
    {
        $front = get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0;
        $posts_page = get_option('show_on_front') === 'page' ? (int) get_option('page_for_posts') : 0;
        $menu = [];
        $menus = wp_get_nav_menus();
        foreach (is_array($menus) ? $menus : [] as $nav) {
            $items = wp_get_nav_menu_items($nav->term_id);
            foreach (is_array($items) ? $items : [] as $item) {
                if (($item->type ?? '') === 'post_type') {
                    $menu[] = (int) $item->object_id;
                } elseif (($item->type ?? '') === 'custom' && is_string($item->url ?? null)) {
                    $id = (int) url_to_postid($item->url);
                    if ($id > 0) {
                        $menu[] = $id;
                    }
                }
            }
        }
        return ['front' => $front, 'posts_page' => $posts_page, 'menu' => array_values(array_unique($menu))];
    }

    public function present_meta_keys(array $keys): array
    {
        global $wpdb;
        if ($keys === []) {
            return [];
        }
        $bases = array_values(array_unique(array_map(static fn(string $key): string => self::split_key($key)[0], $keys)));
        $placeholders = implode(',', array_fill(0, count($bases), '%s'));
        $found = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND meta_value <> ''",
            ...$bases,
        ));
        $found = array_map('strval', is_array($found) ? $found : []);
        return array_values(array_filter($keys, static fn(string $key): bool => in_array(self::split_key($key)[0], $found, true)));
    }

    public function meta(int $id, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            [$base, $field] = self::split_key($key);
            /** @var mixed $value */
            $value = get_post_meta($id, $base, true);
            if ($field !== null) {
                $value = is_array($value) ? ($value[$field] ?? '') : '';
            }
            $values[$key] = is_scalar($value) ? trim((string) $value) : '';
        }
        return $values;
    }

    public function seo_providers(): array
    {
        $labels = [];
        foreach ($this->registry() as $slug => $provider) {
            try {
                if (is_callable($provider['active'] ?? null) && ($provider['active'])() === true) {
                    $labels[$slug] = is_string($provider['label'] ?? null) ? $provider['label'] : $slug;
                }
            } catch (\Throwable) {
                // A provider that cannot say whether it is active is not one.
                continue;
            }
        }
        return $labels;
    }

    public function seo_read(string $provider, int $id): ?array
    {
        $read = $this->registry()[$provider]['read'] ?? null;
        if (!is_callable($read)) {
            return null;
        }
        try {
            /** @var mixed $seo */
            $seo = $read($id);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($seo)) {
            return null;
        }
        $text = static fn(mixed $value): string => is_scalar($value) ? trim((string) $value) : '';
        return ['title' => $text($seo['title'] ?? ''), 'description' => $text($seo['description'] ?? '')];
    }

    /**
     * Every provider the host's `seo-provider-registry` extension point lists (GoBuildWP Pro offers
     * one; anywhere else it is null and the audit falls back to meta keys).
     *
     * @return array<string, array<string, mixed>>
     */
    private function registry(): array
    {
        // Providers register when the host's integrations load, inside wp_abilities_api_init.
        // WordPress fires that lazily, on the first touch of the ability registry, and a
        // background step under WP-Cron may not have touched it yet: the registry then reads
        // empty and the audit falls back to meta keys, including a deactivated plugin's.
        if (function_exists('wp_get_abilities')) {
            wp_get_abilities();
        }
        /** @var mixed $all */
        $all = Runtime\host()->extension('seo-provider-registry');
        if (!is_callable($all)) {
            return [];
        }
        try {
            /** @var mixed $providers */
            $providers = $all();
        } catch (\Throwable) {
            return [];
        }
        $valid = [];
        foreach (is_array($providers) ? $providers : [] as $slug => $provider) {
            if (is_string($slug) && is_array($provider)) {
                $valid[$slug] = $provider;
            }
        }
        return $valid;
    }

    /**
     * `slim_seo[description]` to `['slim_seo', 'description']`; a plain key to `[key, null]`.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function split_key(string $key): array
    {
        return preg_match('/^([^\[\]]+)\[([^\[\]]+)\]$/', $key, $match) === 1 ? [$match[1], $match[2]] : [$key, null];
    }

    public function fetch(string $url): array|WP_Error
    {
        $page = Page::fetch($url, 15);
        if ($page instanceof WP_Error) {
            return $page;
        }
        return ['status' => $page['status'], 'html' => $page['html']];
    }

    public function has_ability(string $name): bool
    {
        return function_exists('wp_has_ability') && wp_has_ability($name);
    }

    public function ability_namespace(): string
    {
        return Runtime\host()->id();
    }
}
