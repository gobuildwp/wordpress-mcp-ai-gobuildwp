<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * A $wpdb with the comment and post tables on in-memory SQLite, and the WordPress delete and
 * cache functions the kit calls, defined in the kit's namespace only.
 */

namespace WPPilot\Tests\Unit\Kits\DbCleanup {
    final class Registrations
    {
        /** @var array<string, array<string, mixed>> */
        public static array $args = [];
    }

    /** Table names on top of the shared SQLite double, which is final. */
    final class TablesWpdb
    {
        public string $comments = 'wp_comments';

        public string $commentmeta = 'wp_commentmeta';

        public string $posts = 'wp_posts';

        public string $postmeta = 'wp_postmeta';

        public string $last_error = '';

        public function __construct(public \WPPilot_Test_Sqlite_Wpdb $db)
        {
            $db->query('CREATE TABLE wp_comments (comment_ID INTEGER PRIMARY KEY, comment_post_ID INTEGER, comment_content TEXT, comment_approved TEXT, comment_date_gmt TEXT)');
            $db->query('CREATE TABLE wp_commentmeta (meta_id INTEGER PRIMARY KEY, comment_id INTEGER, meta_key TEXT, meta_value TEXT)');
            $db->query('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_parent INTEGER, post_type TEXT, post_content TEXT, post_modified_gmt TEXT)');
            $db->query('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT)');
        }

        /** @param list<mixed> $args */
        public function __call(string $name, array $args): mixed
        {
            $result = $this->db->{$name}(...$args);
            $this->last_error = $this->db->last_error;
            return $result;
        }
    }

    final class Calls
    {
        /** @var list<string> */
        public static array $log = [];

        /** @var list<int> ids whose delete reports failure */
        public static array $failDelete = [];
    }
}

namespace WPPilot\Kits\DbCleanup {
    use WPPilot\Tests\Unit\Kits\DbCleanup\Calls;

    if (!function_exists(__NAMESPACE__ . '\\wp_register_ability')) {
        /** @param array<string, mixed> $args */
        function wp_register_ability(string $name, array $args): mixed
        {
            \WPPilot\Tests\Unit\Kits\DbCleanup\Registrations::$args[$name] = $args;
            return \wp_register_ability($name, $args);
        }
    }

    function wp_delete_comment(int $id, bool $force = false): bool
    {
        global $wpdb;
        Calls::$log[] = "delete_comment:{$id}:" . ($force ? 'force' : 'trash');
        if (in_array($id, Calls::$failDelete, true)) {
            return false;
        }
        $wpdb->delete($wpdb->commentmeta, ['comment_id' => $id]);
        return $wpdb->delete($wpdb->comments, ['comment_ID' => $id]) === 1;
    }

    function wp_delete_post_revision(int $id): mixed
    {
        global $wpdb;
        Calls::$log[] = "delete_revision:{$id}";
        if (in_array($id, Calls::$failDelete, true)) {
            return false;
        }
        $wpdb->delete($wpdb->postmeta, ['post_id' => $id]);
        return $wpdb->delete($wpdb->posts, ['ID' => $id]) === 1 ? (object) ['ID' => $id] : false;
    }

    function clean_post_cache(int $id): void
    {
        Calls::$log[] = "clean_post:{$id}";
    }

    function clean_comment_cache(int $id): void
    {
        Calls::$log[] = "clean_comment:{$id}";
    }

    function wp_update_comment_count(int $post_id): bool
    {
        Calls::$log[] = "count:{$post_id}";
        return true;
    }
}
