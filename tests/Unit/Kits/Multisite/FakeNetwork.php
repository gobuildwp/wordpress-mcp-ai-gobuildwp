<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\Multisite;

use WP_Error;
use WPPilot\Kits\Multisite\Network;
use WPPilot\Kits\Runtime\Host;
use WPPilot\Kits\Runtime\Jobs;
use WPPilot\Kits\Runtime\Ledger;

/**
 * A network in memory. It keeps a switch stack the way switch_to_blog() does and logs every
 * switch and restore, so a test can assert the order and that the request came home.
 */
final class FakeNetwork implements Network
{
    /** @var array<int, array<string, mixed>> */
    public array $sites = [];

    /** @var array<string, \WP_Ability> */
    public array $abilities = [];

    /** @var list<string> */
    public array $caps = ['manage_network', 'manage_sites'];

    /** @var list<string> */
    public array $log = [];

    /** @var list<array<string, mixed>> */
    public array $queries = [];

    /** When true, restore() forgets to switch back: the failure the ability must catch. */
    public bool $broken_restore = false;

    public int $current = 1;

    /** @var list<int> */
    private array $stack = [];

    public function add_site(int $id, bool $deleted = false): void
    {
        $this->sites[$id] = [
            'id' => $id, 'name' => 'Site ' . $id, 'url' => 'https://s' . $id . '.example.test', 'domain' => 's' . $id . '.example.test',
            'path' => '/', 'is_main' => $id === 1, 'public' => true, 'archived' => false, 'spam' => false, 'deleted' => $deleted,
            'mature' => false, 'registered' => '2026-01-01 00:00:00', 'last_updated' => '2026-09-01 00:00:00',
        ];
    }

    public function sites(array $args): array
    {
        $this->queries[] = $args;
        return array_slice(array_values($this->visible($args)), $args['offset'], $args['limit']);
    }

    public function count(array $args): int
    {
        return count($this->visible($args));
    }

    public function site(int $id): ?array
    {
        return $this->sites[$id] ?? null;
    }

    public function current_site_id(): int
    {
        return $this->current;
    }

    public function switch_to(int $id): void
    {
        $this->log[] = 'switch:' . $id;
        $this->stack[] = $this->current;
        $this->current = $id;
    }

    public function restore(): void
    {
        $this->log[] = 'restore';
        $previous = array_pop($this->stack);
        if (!$this->broken_restore && $previous !== null) {
            $this->current = $previous;
        }
    }

    public function ability(string $name): ?\WP_Ability
    {
        return $this->abilities[$name] ?? null;
    }

    public function can(string $capability): bool
    {
        return in_array($capability, $this->caps, true);
    }

    /**
     * @param array{search: string, limit: int, offset: int, include_deleted: bool} $args
     * @return array<int, array<string, mixed>>
     */
    private function visible(array $args): array
    {
        return array_filter($this->sites, static function (array $site) use ($args): bool {
            return ($args['include_deleted'] || $site['deleted'] !== true)
                && ($args['search'] === '' || str_contains((string) $site['domain'], $args['search']));
        });
    }
}

/**
 * One change ledger per site, answering for whichever site the network is on.
 */
final class PerSiteLedger implements Ledger
{
    /** @var array<int, list<array<string, mixed>>> */
    public array $rows = [];

    /** @var array<string, callable> */
    public array $captures = [];

    /** @var array<string, array{restore: callable, build: callable|null}> */
    public array $strategies = [];

    public function __construct(private FakeNetwork $network)
    {
    }

    public function record(string $ability): string
    {
        $this->rows[$this->network->current] ??= [];
        $id = 'row-' . $this->network->current . '-' . (count($this->rows[$this->network->current]) + 1);
        array_unshift($this->rows[$this->network->current], ['id' => $id, 'ability' => $ability]);
        return $id;
    }

    public function capture_for(string $ability_name, callable $capture): void
    {
        $this->captures[$ability_name] = $capture;
    }

    public function record_items(string $ability_name, array $items, ?string $group = null): array
    {
        return ['group' => '', 'change_ids' => [], 'without_before_image' => 0];
    }

    public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
    {
        $this->strategies[$type] = ['restore' => $restore, 'build' => $build];
        return true;
    }

    public function query(array $filters = []): array
    {
        $this->network->log[] = 'ledger@' . $this->network->current;
        return array_values(array_filter(
            $this->rows[$this->network->current] ?? [],
            static fn(array $row): bool => ($filters['ability'] ?? '') === '' || $row['ability'] === $filters['ability'],
        ));
    }

    public function export_row(array $entry): array
    {
        return $entry;
    }

    public function snapshot_budget(): int
    {
        return 0;
    }

    public function download_url(): string
    {
        return '';
    }
}

/**
 * A host whose ability runner (or its absence) the test chooses.
 */
final class NetworkHost implements Host
{
    /** @var callable|null */
    public $runner = null;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $guarded = [];

    /** @var list<string> Ability names the confirm guard refuses without confirm. */
    public array $destructive = [];

    public function __construct(private Ledger $ledger)
    {
    }

    public function id(): string
    {
        return 'test';
    }

    public function can_manage(): bool
    {
        return true;
    }

    public function is_enabled(): bool
    {
        return true;
    }

    public function safety_profile(): string
    {
        return 'production';
    }

    public function ledger(): Ledger
    {
        return $this->ledger;
    }

    public function jobs(): Jobs
    {
        throw new \LogicException('not used');
    }

    public function extension(string $point): mixed
    {
        return $point === 'ability-runner' ? $this->runner : null;
    }

    public function admin_parent_slug(): string
    {
        return 'tools.php';
    }

    public function confirm_guard(string $ability_name, array $input): bool|WP_Error
    {
        $this->guarded[] = [$ability_name, $input];
        if (in_array($ability_name, $this->destructive, true) && ($input['confirm'] ?? null) !== true) {
            return new WP_Error('kit_confirmation_required', 'confirm');
        }
        return true;
    }
}
