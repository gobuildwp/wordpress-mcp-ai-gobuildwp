<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Multisite;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The multisite calls the network abilities make, so the switching rules are tested apart from
 * a real network. WpNetwork answers them from WordPress.
 */
interface Network
{
    /**
     * @param array{search: string, limit: int, offset: int, include_deleted: bool} $args
     * @return list<array<string, mixed>>
     */
    public function sites(array $args): array;

    /** @param array{search: string, limit: int, offset: int, include_deleted: bool} $args */
    public function count(array $args): int;

    /** @return array<string, mixed>|null */
    public function site(int $id): ?array;

    public function current_site_id(): int;

    public function switch_to(int $id): void;

    public function restore(): void;

    public function ability(string $name): ?\WP_Ability;

    public function can(string $capability): bool;
}
