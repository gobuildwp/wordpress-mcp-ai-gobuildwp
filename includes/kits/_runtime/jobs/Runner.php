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
 */
final class Runner implements Jobs
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
        $index = $this->index();
        $index[] = $id;
        update_option(self::INDEX_OPTION, array_values(array_unique($index)), false);
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
     * @return array<string, mixed>
     */
    private function run(array $job, float $deadline): array
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
        try {
            while (microtime(true) < $deadline) {
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
                $this->save($job);
            }
        } catch (\Throwable $error) {
            $job['status'] = 'failed';
            $job['message'] = mb_substr($error->getMessage(), 0, 500);
            $job['updated_at'] = time();
        } finally {
            wp_set_current_user($previous_user);
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
        update_option(self::INDEX_OPTION, array_values(array_diff($this->index(), [$id])), false);
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
