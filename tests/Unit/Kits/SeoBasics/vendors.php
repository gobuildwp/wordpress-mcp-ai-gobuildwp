<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Doubles of the SEO plugins' APIs the seo-basics kit calls, and the few WordPress functions the
 * shared doubles lack. Loaded only inside the kit's isolated test processes.
 */

namespace {
    // The runtime's job runner reads these at construction; an isolated process has not had
    // another test define them.
    foreach (['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3_600, 'DAY_IN_SECONDS' => 86_400] as $seo_basics_name => $seo_basics_seconds) {
        if (!defined($seo_basics_name)) {
            define($seo_basics_name, $seo_basics_seconds);
        }
    }

    if (!function_exists('wp_kses')) {
        /** @param array<string, mixed>|string $allowed_html */
        function wp_kses(string $content, array|string $allowed_html, array $allowed_protocols = []): string
        {
            return strip_tags($content);
        }
    }

    if (!function_exists('sanitize_textarea_field')) {
        function sanitize_textarea_field(string $text): string
        {
            return trim(strip_tags($text));
        }
    }

    if (!function_exists('post_password_required')) {
        function post_password_required(mixed $post = null): bool
        {
            return $post instanceof WP_Post && in_array($post->ID, Seo_Basics_Vendors::$password_protected, true);
        }
    }

    final class Seo_Basics_Vendors
    {
        /** @var list<int> */
        public static array $password_protected = [];
    }

    /**
     * Yoast SEO's WPSEO_Meta, as inc/class-wpseo-meta.php behaves for these four keys.
     */
    final class WPSEO_Meta
    {
        public static string $meta_prefix = '_yoast_wpseo_';

        /** @var array<string, string> */
        public static array $defaults = [
            '_yoast_wpseo_title' => '',
            '_yoast_wpseo_metadesc' => '',
            '_yoast_wpseo_meta-robots-noindex' => '0',
            '_yoast_wpseo_meta-robots-nofollow' => '0',
        ];

        public static function set_value(string $key, mixed $meta_value, int $post_id): bool
        {
            // Yoast slashes because update_metadata() unslashes.
            $meta_value = wp_slash($meta_value);
            $full = self::$meta_prefix . $key;
            // Yoast's update_post_metadata filter keeps the table clean: a default is not stored.
            if (wp_unslash($meta_value) === (self::$defaults[$full] ?? null)) {
                delete_post_meta($post_id, $full);
                return true;
            }
            return (bool) update_post_meta($post_id, $full, $meta_value);
        }

        public static function get_value(string $key, int $postid = 0): string
        {
            $full = self::$meta_prefix . $key;
            /** @var list<mixed> $values */
            $values = get_post_meta($postid, $full);
            if (isset($values[0]) && is_string($values[0])) {
                return $values[0];
            }
            return self::$defaults[$full] ?? '';
        }
    }

    /**
     * The SEO Framework's tsf() facade, down to data()->plugin()->post().
     */
    function tsf(): object
    {
        return new class {
            public function data(): object
            {
                return new class {
                    public function plugin(): object
                    {
                        return new class {
                            public function post(): Seo_Basics_Tsf_Post
                            {
                                return new Seo_Basics_Tsf_Post();
                            }
                        };
                    }
                };
            }
        };
    }

    /**
     * TSF 5.1.4's Data\Plugin\Post, reduced to the three methods the kit calls.
     */
    final class Seo_Basics_Tsf_Post
    {
        /** @return array<string, mixed> */
        public function get_default_meta(int $post_id = 0): array
        {
            return [
                '_genesis_title' => '',
                '_genesis_description' => '',
                '_genesis_canonical_uri' => '',
                '_genesis_noindex' => 0,
                '_genesis_nofollow' => 0,
                '_open_graph_title' => '',
            ];
        }

        /** @return array<string, mixed> Raw stored values over the defaults, as TSF reads them. */
        public function get_meta(int $post_id = 0): array
        {
            $defaults = $this->get_default_meta($post_id);
            $meta = [];
            foreach (array_intersect_key(get_post_meta($post_id) ?: [], $defaults) as $key => $values) {
                $meta[$key] = $values[0];
            }
            return array_merge($defaults, $meta);
        }

        /** @param array<string, mixed> $data */
        public function save_meta(int $post_id, array $data): void
        {
            foreach (array_merge($this->get_default_meta($post_id), $data) as $field => $value) {
                if ($value || (is_string($value) && strlen($value))) {
                    update_post_meta($post_id, $field, $value); // kit-lint: slashed
                } else {
                    delete_post_meta($post_id, $field);
                }
            }
        }
    }

    function aioseo(): object
    {
        return new class {
            public object $helpers;

            public function __construct()
            {
                $this->helpers = new class {
                    /** @return list<string> */
                    public function getPublicPostTypes(bool $namesOnly = false): array
                    {
                        return ['post', 'page'];
                    }
                };
            }
        };
    }

    /**
     * AIOSEO 5.0.2's Post model for the columns the kit touches: getPost() fills defaults for a
     * post with no row, save() inserts or updates it, and loaded values come back the way the
     * database returns them (strings), except the booleans AIOSEO casts on load.
     */
    final class Seo_Basics_Aioseo_Post
    {
        /** @var array<int, array<string, mixed>> post id => stored row */
        public static array $rows = [];

        public static string $fail = '';

        public ?int $id = null;

        public int $post_id = 0;

        public ?string $title = null;

        public ?string $description = null;

        public mixed $robots_default = true;

        public mixed $robots_noindex = false;

        public mixed $robots_nofollow = false;

        public mixed $robots_noarchive = false;

        public mixed $robots_noimageindex = false;

        public mixed $robots_nosnippet = false;

        public mixed $robots_noodp = false;

        public mixed $robots_notranslate = false;

        public mixed $robots_max_snippet = null;

        public mixed $robots_max_videopreview = null;

        public mixed $robots_max_imagepreview = 'large';

        public string $lastError = '';

        /** Columns other than the ones the kit writes, which a save must keep. */
        public mixed $og_title = null;

        public static function getPost(int $postId): self
        {
            $post = new self();
            $post->post_id = $postId;
            foreach (self::$rows[$postId] ?? [] as $column => $value) {
                if ($column === 'id' || $column === 'post_id') {
                    $post->{$column} = (int) $value;
                    continue;
                }
                $post->{$column} = str_starts_with($column, 'robots_') && !in_array($column, ['robots_max_snippet', 'robots_max_videopreview', 'robots_max_imagepreview'], true)
                    ? (bool) $value
                    : $value;
            }
            return $post;
        }

        public function save(): void
        {
            if (self::$fail !== '') {
                $this->lastError = self::$fail;
                return;
            }
            $row = [];
            foreach (get_object_vars($this) as $column => $value) {
                if ($column === 'lastError') {
                    continue;
                }
                $row[$column] = is_bool($value) ? ($value ? '1' : '0') : ($value === null ? null : (string) $value);
            }
            $row['id'] = (string) ($this->id ?? $this->post_id);
            self::$rows[$this->post_id] = $row;
            $this->lastError = '';
        }
    }

    // AIOSEO names its model in its own namespace; an alias keeps a foreign namespace block out of
    // a file that is exported with the kit.
    class_alias(Seo_Basics_Aioseo_Post::class, 'AIOSEO\\Plugin\\Common\\Models\\Post');
}
