<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * In-memory comments for tests that moderate them. Status lives in comment_approved the way
 * WordPress stores it: "1", "0", "spam" or "trash".
 */
final class WPPilot_Test_Comments
{
    /** @var array<int, WP_Comment> */
    public static array $comments = [];

    /** @var list<int> Comment ids whose next write fails. */
    public static array $failing = [];

    public static function reset(): void
    {
        self::$comments = [];
        self::$failing = [];
    }

    public static function add(int $id, string $approved, int $post_id = 1, string $content = 'Hello', string $email = 'a@example.com'): WP_Comment
    {
        $comment = new WP_Comment();
        $comment->comment_ID = (string) $id;
        $comment->comment_post_ID = (string) $post_id;
        $comment->comment_author = 'Author ' . $id;
        $comment->comment_author_email = $email;
        $comment->comment_content = $content;
        $comment->comment_approved = $approved;
        $comment->comment_type = 'comment';
        $comment->comment_date_gmt = '2026-11-01 10:00:00';
        self::$comments[$id] = $comment;
        return $comment;
    }

    public static function write(int $id, string $approved): bool
    {
        if (!isset(self::$comments[$id]) || in_array($id, self::$failing, true)) {
            return false;
        }
        self::$comments[$id]->comment_approved = $approved;
        return true;
    }
}

if (!class_exists('WP_Comment')) {
    class WP_Comment
    {
        public string $comment_ID = '0';
        public string $comment_post_ID = '0';
        public string $comment_author = '';
        public string $comment_author_email = '';
        public string $comment_content = '';
        public string $comment_approved = '0';
        public string $comment_type = 'comment';
        public string $comment_date_gmt = '';
    }
}

if (!function_exists('get_comment')) {
    function get_comment(mixed $comment = null): ?WP_Comment
    {
        $id = $comment instanceof WP_Comment ? (int) $comment->comment_ID : (int) $comment;
        return WPPilot_Test_Comments::$comments[$id] ?? null;
    }
}

if (!function_exists('wp_get_comment_status')) {
    function wp_get_comment_status(mixed $comment): string|false
    {
        $found = get_comment($comment);
        if ($found === null) {
            return false;
        }
        return match ($found->comment_approved) {
            '1' => 'approved',
            '0' => 'unapproved',
            default => $found->comment_approved,
        };
    }
}

if (!function_exists('wp_set_comment_status')) {
    function wp_set_comment_status(mixed $comment_id, string $comment_status, bool $wp_error = false): bool
    {
        $approved = match ($comment_status) {
            'approve', '1' => '1',
            'hold', '0' => '0',
            'spam' => 'spam',
            'trash' => 'trash',
            default => null,
        };
        return $approved !== null && WPPilot_Test_Comments::write((int) $comment_id, $approved);
    }
}

if (!function_exists('wp_spam_comment')) {
    function wp_spam_comment(mixed $comment_id): bool
    {
        return WPPilot_Test_Comments::write((int) $comment_id, 'spam');
    }
}

if (!function_exists('wp_trash_comment')) {
    function wp_trash_comment(mixed $comment_id): bool
    {
        return WPPilot_Test_Comments::write((int) $comment_id, 'trash');
    }
}

if (!function_exists('get_comments')) {
    /**
     * Supports the arguments wppilot/bulk-moderate-comments passes: status (list), post_id,
     * search, type, author_email, count, fields=ids and number. Dates are not filtered.
     *
     * @param array<string, mixed> $args
     * @return int|list<int>|list<WP_Comment>
     */
    function get_comments(array $args = []): int|array
    {
        $map = ['approve' => '1', 'hold' => '0', 'spam' => 'spam', 'trash' => 'trash'];
        $wanted = array_map(static fn(string $s): string => $map[$s] ?? $s, (array) ($args['status'] ?? ['approve', 'hold']));
        $matches = [];
        ksort(WPPilot_Test_Comments::$comments);
        foreach (WPPilot_Test_Comments::$comments as $id => $comment) {
            if (!in_array($comment->comment_approved, $wanted, true)) {
                continue;
            }
            if (isset($args['post_id']) && (int) $comment->comment_post_ID !== (int) $args['post_id']) {
                continue;
            }
            if (isset($args['search']) && !str_contains($comment->comment_content, (string) $args['search'])) {
                continue;
            }
            if (isset($args['type']) && $comment->comment_type !== $args['type']) {
                continue;
            }
            if (isset($args['author_email']) && $comment->comment_author_email !== $args['author_email']) {
                continue;
            }
            $matches[$id] = $comment;
        }
        if (!empty($args['count'])) {
            return count($matches);
        }
        if (isset($args['number'])) {
            $matches = array_slice($matches, 0, (int) $args['number'], true);
        }
        return ($args['fields'] ?? '') === 'ids' ? array_keys($matches) : array_values($matches);
    }
}
