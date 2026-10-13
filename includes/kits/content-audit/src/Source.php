<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\ContentAudit;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Everything the audit reads from the site.
 *
 * The audit's judgement — what counts as broken, orphaned, thin or ineligible — lives in
 * Auditor and is tested against a fake of this. WpSource answers it from WordPress.
 */
interface Source
{
    /** The site's home URL, with a trailing slash. */
    public function home_url(): string;

    /**
     * Published IDs of the given types above a cursor, ascending.
     *
     * @param list<string> $post_types
     * @return list<int>
     */
    public function published_ids(array $post_types, int $after, int $limit): array;

    /** @param list<string> $post_types */
    public function count_published(array $post_types): int;

    /**
     * @return array{id: int, title: string, type: string, url: string, content: string, builder: string}|null
     *         `builder` is page-builder data stored outside post_content (Elementor's JSON), or ''.
     */
    public function post(int $id): ?array;

    /** url_to_postid(): 0 when the URL is not a post's permalink. */
    public function url_to_post_id(string $url): int;

    /** A post's status, or null when there is no such post. */
    public function post_status(int $id): ?string;

    /** Whether an uploads URL has its file on disk; null when the URL is not under uploads. */
    public function upload_file_exists(string $url): ?bool;

    /**
     * A HEAD request that does not follow redirects, falling back to a small GET where the
     * server refuses HEAD. Only through wp_safe_remote_*().
     *
     * @return array{status: int, location: string}|WP_Error
     */
    public function head(string $url, int $timeout): array|WP_Error;

    /**
     * Posts that are reachable without an inbound content link: the front page, the posts page
     * and every post a navigation menu links to.
     *
     * @return array{front: int, posts_page: int, menu: list<int>}
     */
    public function structural_ids(): array;

    /**
     * Which of these post meta keys exist anywhere on the site. A key written `base[field]` is
     * present when `base` is.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public function present_meta_keys(array $keys): array;

    /**
     * @param list<string> $keys A key written `base[field]` reads that field of the array stored under `base`.
     * @return array<string, string> Key to its single value; '' when absent.
     */
    public function meta(int $id, array $keys): array;

    /**
     * The SEO plugins the host's provider registry reports active on this request, slug to
     * label. Empty when the host offers no registry (a copy of this kit outside GoBuildWP Pro).
     *
     * @return array<string, string>
     */
    public function seo_providers(): array;

    /**
     * One post's stored SEO title and description, read through a registered provider. Null
     * when the provider is gone or its reader fails.
     *
     * @return array{title: string, description: string}|null
     */
    public function seo_read(string $provider, int $id): ?array;

    /**
     * The page as a visitor receives it.
     *
     * @return array{status: int, html: string}|WP_Error
     */
    public function fetch(string $url): array|WP_Error;

    public function has_ability(string $name): bool;

    /** The namespace the host's abilities are registered under (its host id). */
    public function ability_namespace(): string;
}
