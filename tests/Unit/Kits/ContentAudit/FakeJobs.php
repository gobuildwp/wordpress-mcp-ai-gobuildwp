<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\ContentAudit;

use WP_Error;
use WPPilot\Kits\Runtime\Host;
use WPPilot\Kits\Runtime\Jobs;
use WPPilot\Kits\Runtime\Ledger;

/**
 * Jobs kept in memory, stepped by hand.
 */
final class FakeJobs implements Jobs
{
    /** @var array<string, callable> */
    public array $steps = [];

    /** @var array<string, array<string, mixed>> */
    public array $jobs = [];

    private int $next = 0;

    public function register(string $kind, callable $step): void
    {
        $this->steps[$kind] = $step;
    }

    public function enqueue(string $kind, array $payload): string|WP_Error
    {
        $id = 'job-' . (++$this->next);
        $this->jobs[$id] = [
            'id' => $id, 'kind' => $kind, 'payload' => $payload, 'state' => [], 'status' => 'queued',
            'progress' => 0.0, 'message' => '', 'owner' => \WPPilot\Kits\ContentAudit\viewer()['id'], 'created_at' => 1, 'updated_at' => 1,
        ];
        return $id;
    }

    public function get(string $id): ?array
    {
        return $this->jobs[$id] ?? null;
    }

    public function cancel(string $id): bool
    {
        return false;
    }

    /** Run steps until done or $max; returns how many ran. */
    public function run(string $id, int $max = 100): int
    {
        $step = $this->steps[$this->jobs[$id]['kind']];
        $count = 0;
        while ($count < $max) {
            $outcome = $step($this->jobs[$id]['payload'], $this->jobs[$id]['state']);
            $count++;
            $this->jobs[$id]['state'] = $outcome['state'];
            $this->jobs[$id]['progress'] = $outcome['progress'];
            $this->jobs[$id]['status'] = $outcome['done'] ? 'done' : 'running';
            if ($outcome['done']) {
                break;
            }
        }
        return $count;
    }
}

final class FakeAuditHost implements Host
{
    public function __construct(private Jobs $jobs)
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
        throw new \LogicException('The audit never writes to the ledger.');
    }

    public function jobs(): Jobs
    {
        return $this->jobs;
    }

    public function extension(string $point): mixed
    {
        return null;
    }

    public function admin_parent_slug(): string
    {
        return 'tools.php';
    }

    public function confirm_guard(string $ability_name, array $input): bool|WP_Error
    {
        return true;
    }
}
