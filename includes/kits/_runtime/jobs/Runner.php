<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\Runtime;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Background work that outlives one request: leased, time-boxed steps driven by WP-Cron.
 *
 * A search-replace over two thousand posts or an audit of every page cannot finish inside one
 * ability call. A job is registered by kind with a step callback; each tick claims the job with
 * a lease, runs steps until the time budget is spent or the step says it is done, saves the
 * step's state (its cursor) and schedules the next tick. A tick that dies mid-step loses only
 * that step: the lease expires and the next tick resumes from the last saved state.
 *
 * Steps run as the user who enqueued the job, because WP-Cron runs as nobody, and a kit's
 * permission checks and ledger attribution both need the person who asked.
 *
 * Nothing here is visible to an agent until a kit exposes it; the Tasks extension will.
 *
 * A step may return `'yield' => true` to save its state and stop for this tick (it is waiting on
 * something, a lock or a backoff); advance() runs a single step for a caller that polls.
 */
final class Runner implements SteppableJobs
{
    public const OPTION_PREFIX = 'wppilot_kit_job_';
    public const INDEX_OPTION = 'wppilot_kit_jobs';
    public const CRON_HOOK = 'wppilot_kit_jobs_tick';

    /** Seconds a tick holds a job; longer than STEP_BUDGET so a slow step is not double-run. */
    public const LEASE = 90;

    /** Seconds of steps one tick runs before handing over to the next. */
    public const STEP_BUDGET = 20;

    /** Finished jobs are kept this long so a caller can still read the result. */
    public const RETENTION = 7 * DAY_IN_SECONDS;

    /** @var array<string, callable> */
    private array $steps = [];

    public function __construct()
    {
        add_action(self::CRON_HOOK, [$this, 'tick']);
    }

    public function register(string $kind, callable $step): void
    {
        $this->steps[$kind] = $step;
    }

    public function enqueue(string $kind, array $payload): string|WP_Error
    {
        if (!isset($this->steps[$kind])) {
            return new WP_Error('kit_job_unknown_kind', sprintf('No job kind "%s" is registered.', $kind));
        }
        $id = wp_generate_uuid4();
        $now = time();
        $this->save([
            'id' => $id,
            'kind' => $kind,
            'payload' => $payload,
            'state' => [],
            'status' => 'queued',
            'progress' => 0.0,
            'message' => '',
            'owner' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->update_index(static fn(array $index): array => [...$index, $id]);
        $this->schedule(0);
        return $id;
    }

    public function get(string $id): ?array
    {
        /** @var mixed $job */
        $job = get_option(self::OPTION_PREFIX . $id, null);
        return is_array($job) ? $job : null;
    }

    public function cancel(string $id): bool
    {
        $job = $this->get($id);
        if ($job === null || in_array($job['status'], ['done', 'failed', 'cancelled'], strict: true)) {
            return false;
        }
        $job['status'] = 'cancelled';
        $job['updated_at'] = time();
        $this->save($job);
        return true;
    }

    public function advance(string $id): ?array
    {
        $job = $this->get($id);
        if ($job === null || !in_array($job['status'], ['queued', 'running'], strict: true)) {
            return $job;
        }
        if (!$this->claim($id)) {
            // A tick or another poll is stepping it; report the job as it stands.
            return $job;
        }
        try {
            $job = $this->get($id) ?? $job;
            if (in_array($job['status'], ['queued', 'running'], strict: true)) {
                $job = $this->run($job, microtime(true) + self::STEP_BUDGET, 1);
            }
        } finally {
            $this->release($id);
        }
        if ($job['status'] === 'running' || $job['status'] === 'queued') {
            $this->schedule(5);
        }
        return $job;
    }

    public function resume(string $id, ?array $state = null): bool
    {
        $job = $this->get($id);
        if ($job === null || $job['status'] !== 'done') {
            return false;
        }
        $job['status'] = 'running';
        if ($state !== null) {
            $job['state'] = $state;
        }
        $job['updated_at'] = time();
        $this->save($job);
        $this->schedule(0);
        return true;
    }

    /**
     * Run whatever is due. Hooked on the cron event; safe to call directly.
     */
    public function tick(): void
    {
        $deadline = microtime(true) + self::STEP_BUDGET;
        $more = false;
        foreach ($this->index() as $id) {
            $job = $this->get($id);
            if ($job === null) {
                continue;
            }
            if (in_array($job['status'], ['done', 'failed', 'cancelled'], strict: true)) {
                if ((int) $job['updated_at'] < time() - self::RETENTION) {
                    $this->forget($id);
                }
                continue;
            }
            if (microtime(true) >= $deadline || !$this->claim($id)) {
                $more = true;
                continue;
            }
            try {
                $job = $this->run($job, $deadline);
            } finally {
                $this->release($id);
            }
            if ($job['status'] === 'running' || $job['status'] === 'queued') {
                $more = true;
            }
        }
        if ($more) {
            $this->schedule(5);
        }
    }

    /**
     * @param array<string, mixed> $job
     * @param int $max_steps 0 for as many as the deadline allows.
     * @return array<string, mixed>
     */
    private function run(array $job, float $deadline, int $max_steps = 0): array
    {
        $step = $this->steps[(string) $job['kind']] ?? null;
        if ($step === null) {
            // The kit that registered this kind is not loaded on this request; leave the job for
            // one where it is, rather than failing work that is merely waiting.
            return $job;
        }
        $previous_user = get_current_user_id();
        wp_set_current_user((int) $job['owner']);
        $job['status'] = 'running';
        $steps = 0;
        try {
            while (microtime(true) < $deadline && ($max_steps === 0 || $steps < $max_steps)) {
                $steps++;
                // Re-read between steps so a cancel from another request stops the job promptly.
                $fresh = $this->get((string) $job['id']);
                if ($fresh !== null && $fresh['status'] === 'cancelled') {
                    return $fresh;
                }
                /** @var mixed $outcome */
                $outcome = $step(
                    is_array($job['payload']) ? $job['payload'] : [],
                    is_array($job['state']) ? $job['state'] : [],
                );
                if (!is_array($outcome)) {
                    throw new \UnexpectedValueException('A job step must return an array.');
                }
                $job['state'] = is_array($outcome['state'] ?? null) ? $outcome['state'] : [];
                $job['progress'] = min(1.0, max(0.0, (float) ($outcome['progress'] ?? $job['progress'])));
                $job['message'] = (string) ($outcome['message'] ?? '');
                $job['updated_at'] = time();
                if (($outcome['done'] ?? false) === true) {
                    $job['status'] = 'done';
                    $job['progress'] = 1.0;
                    break;
                }
                if (($outcome['yield'] ?? false) === true) {
                    // Waiting on something outside the job; try again next tick, not in a loop.
                    break;
                }
                $this->save($job);
            }
        } catch (\Throwable $error) {
            $job['status'] = 'failed';
            $job['message'] = mb_substr($error->getMessage(), 0, 500);
            $job['updated_at'] = time();
        } finally {
            wp_set_current_user($previous_user);
        }
        // A cancel that landed during the last step wins over that step's result.
        $fresh = $this->get((string) $job['id']);
        if ($fresh !== null && $fresh['status'] === 'cancelled') {
            return $fresh;
        }
        $this->save($job);
        return $job;
    }

    /**
     * Take the job's lease. add_option() is an INSERT on a unique key, so exactly one request
     * wins; an expired lease is removed and contested again.
     */
    private function claim(string $id): bool
    {
        $key = self::OPTION_PREFIX . 'lease_' . $id;
        if (add_option($key, time() + self::LEASE, '', false)) {
            return true;
        }
        wp_cache_delete($key, 'options');
        if ((int) get_option($key, 0) > time()) {
            return false;
        }
        delete_option($key);
        return add_option($key, time() + self::LEASE, '', false);
    }

    private function release(string $id): void
    {
        delete_option(self::OPTION_PREFIX . 'lease_' . $id);
    }

    /** @param array<string, mixed> $job */
    private function save(array $job): void
    {
        update_option(self::OPTION_PREFIX . $job['id'], $job, false);
    }

    private function forget(string $id): void
    {
        delete_option(self::OPTION_PREFIX . $id);
        $this->update_index(static fn(array $index): array => array_diff($index, [$id]));
    }

    /**
     * Read-modify-write of the shared index under a short lock, so two requests enqueueing at
     * once don't drop each other's job. The lock is an add_option() INSERT on a unique key; a
     * stale one from a crashed request expires after 10 s. If the lock can't be had in 2 s the
     * write goes ahead anyway: a lost index entry only delays cleanup, a lost job would not.
     *
     * @param callable(list<string>): array<int, string> $change
     */
    private function update_index(callable $change): void
    {
        $lock = self::INDEX_OPTION . '_lock';
        $give_up = microtime(true) + 2;
        $locked = false;
        while (!($locked = add_option($lock, time() + 10, '', false)) && microtime(true) < $give_up) {
            wp_cache_delete($lock, 'options');
            if ((int) get_option($lock, 0) <= time()) {
                delete_option($lock);
                continue;
            }
            usleep(50_000);
        }
        try {
            update_option(self::INDEX_OPTION, array_values(array_unique($change($this->index()))), false);
        } finally {
            if ($locked) {
                delete_option($lock);
            }
        }
    }

    /** @return list<string> */
    private function index(): array
    {
        wp_cache_delete(self::INDEX_OPTION, 'options');
        /** @var mixed $index */
        $index = get_option(self::INDEX_OPTION, []);
        return is_array($index) ? array_values(array_filter($index, 'is_string')) : [];
    }

    private function schedule(int $delay): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + $delay, self::CRON_HOOK);
        }
        // Without a visitor, WP-Cron only runs on the next page load; this starts it now.
        if ($delay === 0 && function_exists('spawn_cron')) {
            spawn_cron();
        }
    }
}
