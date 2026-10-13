<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\ContentAudit;

use WP_Error;
use WPPilot\Kits\ContentAudit\Source;

/**
 * A site in memory: posts with a status and content, permalinks, uploads, HTTP answers, menus
 * and SEO meta. Records every HTTP request, so a test can prove what was not asked over HTTP.
 */
final class FakeSite implements Source
{
    /** @var array<int, array{title: string, type: string, status: string, content: string, builder: string, slug: string}> */
    public array $posts = [];

    /** @var array<string, int|WP_Error> URL => HTTP status, or an error. */
    public array $http = [];

    /** @var array<string, string> URL => Location header. */
    public array $locations = [];

    /** @var list<string> */
    public array $requests = [];

    /** @var list<string> */
    public array $fetched = [];

    /** @var array<string, bool> Uploads URL => file exists. */
    public array $uploads = [];

    /** @var array<string, array{status: int, html: string}|WP_Error> */
    public array $pages = [];

    /** @var array{front: int, posts_page: int, menu: list<int>} */
    public array $structural = ['front' => 0, 'posts_page' => 0, 'menu' => []];

    /** @var array<int, array<string, string>> */
    public array $meta = [];

    /** @var list<string> */
    public array $abilities = [];

    /** @var array<string, string> Active SEO providers in the host's registry, slug => label. */
    public array $providers = [];

    /** @var array<string, array<int, array{title: string, description: string}|null>> slug => post => stored SEO. */
    public array $providerSeo = [];

    public function add(int $id, string $slug, string $content = '', string $type = 'page', string $status = 'publish', string $builder = ''): void
    {
        $this->posts[$id] = ['title' => ucfirst($slug), 'type' => $type, 'status' => $status, 'content' => $content, 'builder' => $builder, 'slug' => $slug];
    }

    public function home_url(): string
    {
        return 'https://example.test/';
    }

    public function published_ids(array $post_types, int $after, int $limit): array
    {
        $ids = [];
        ksort($this->posts);
        foreach ($this->posts as $id => $post) {
            if ($id > $after && $post['status'] === 'publish' && in_array($post['type'], $post_types, true)) {
                $ids[] = $id;
            }
        }
        return array_slice($ids, 0, $limit);
    }

    public function count_published(array $post_types): int
    {
        return count($this->published_ids($post_types, 0, PHP_INT_MAX));
    }

    public function post(int $id): ?array
    {
        $post = $this->posts[$id] ?? null;
        if ($post === null) {
            return null;
        }
        return [
            'id' => $id,
            'title' => $post['title'],
            'type' => $post['type'],
            'url' => 'https://example.test/' . $post['slug'] . '/',
            'content' => $post['content'],
            'builder' => $post['builder'],
        ];
    }

    public function url_to_post_id(string $url): int
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        foreach ($this->posts as $id => $post) {
            if ($post['slug'] === $path) {
                return $id;
            }
        }
        return 0;
    }

    public function post_status(int $id): ?string
    {
        return $this->posts[$id]['status'] ?? null;
    }

    public function upload_file_exists(string $url): ?bool
    {
        if (!str_contains($url, '/wp-content/uploads/')) {
            return null;
        }
        return $this->uploads[$url] ?? false;
    }

    public function head(string $url, int $timeout): array|WP_Error
    {
        $this->requests[] = $url;
        $answer = $this->http[$url] ?? 200;
        if ($answer instanceof WP_Error) {
            return $answer;
        }
        return ['status' => $answer, 'location' => $this->locations[$url] ?? ''];
    }

    public function structural_ids(): array
    {
        return $this->structural;
    }

    public function present_meta_keys(array $keys): array
    {
        $present = [];
        foreach ($this->meta as $values) {
            foreach ($values as $key => $value) {
                if ($value !== '' && in_array($key, $keys, true)) {
                    $present[$key] = true;
                }
            }
        }
        return array_keys($present);
    }

    public function meta(int $id, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->meta[$id][$key] ?? '';
        }
        return $values;
    }

    public function seo_providers(): array
    {
        return $this->providers;
    }

    public function seo_read(string $provider, int $id): ?array
    {
        if (!isset($this->providers[$provider])) {
            return null;
        }
        return array_key_exists($id, $this->providerSeo[$provider] ?? [])
            ? $this->providerSeo[$provider][$id]
            : ['title' => '', 'description' => ''];
    }

    public function fetch(string $url): array|WP_Error
    {
        $this->fetched[] = $url;
        return $this->pages[$url] ?? ['status' => 200, 'html' => '<html><body></body></html>'];
    }

    public function has_ability(string $name): bool
    {
        return in_array($name, $this->abilities, true);
    }

    public function ability_namespace(): string
    {
        return 'test';
    }
}
