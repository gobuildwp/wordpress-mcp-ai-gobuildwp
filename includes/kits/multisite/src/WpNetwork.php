<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Multisite;

use WP_Site;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The Network, answered from WordPress's multisite API.
 */
final class WpNetwork implements Network
{
    public function sites(array $args): array
    {
        $sites = get_sites($this->query($args) + ['number' => $args['limit'], 'offset' => $args['offset']]);
        $shaped = [];
        foreach (is_array($sites) ? $sites : [] as $site) {
            if ($site instanceof WP_Site) {
                $shaped[] = $this->shape($site);
            }
        }
        return $shaped;
    }

    public function count(array $args): int
    {
        return (int) get_sites($this->query($args) + ['count' => true]);
    }

    public function site(int $id): ?array
    {
        $site = $id > 0 ? get_site($id) : null;
        return $site instanceof WP_Site ? $this->shape($site) : null;
    }

    public function current_site_id(): int
    {
        return get_current_blog_id();
    }

    public function switch_to(int $id): void
    {
        switch_to_blog($id);
    }

    public function restore(): void
    {
        restore_current_blog();
    }

    public function ability(string $name): ?\WP_Ability
    {
        $ability = function_exists('wp_get_ability') ? wp_get_ability($name) : null;
        return $ability instanceof \WP_Ability ? $ability : null;
    }

    public function can(string $capability): bool
    {
        return current_user_can($capability);
    }

    /**
     * @param array{search: string, limit: int, offset: int, include_deleted: bool} $args
     * @return array<string, mixed>
     */
    private function query(array $args): array
    {
        $query = ['orderby' => 'id', 'order' => 'ASC', 'network_id' => get_current_network_id()];
        if ($args['search'] !== '') {
            $query['search'] = $args['search'];
            $query['search_columns'] = ['domain', 'path'];
        }
        if (!$args['include_deleted']) {
            $query['deleted'] = 0;
        }
        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(WP_Site $site): array
    {
        $id = (int) $site->blog_id;
        return [
            'id' => $id,
            // blogname and home come from the site's own options, which WP_Site loads (and
            // caches) on first read.
            'name' => (string) $site->blogname,
            'url' => (string) $site->home,
            'domain' => (string) $site->domain,
            'path' => (string) $site->path,
            'is_main' => is_main_site($id),
            'public' => (string) $site->public === '1',
            'archived' => (string) $site->archived === '1',
            'spam' => (string) $site->spam === '1',
            'deleted' => (string) $site->deleted === '1',
            'mature' => (string) $site->mature === '1',
            'registered' => (string) $site->registered,
            'last_updated' => (string) $site->last_updated,
        ];
    }
}
