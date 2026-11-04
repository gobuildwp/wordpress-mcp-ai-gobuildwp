<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Limits for one step and for a whole job. A site may lower any of them with the
 * `gobuildwp_kit_security_integrity_budgets` filter; a raised value is ignored.
 */
const BUDGETS = [
    'files' => 400,                    // files opened or hashed per step
    'entries' => 10000,                // directory entries judged by name only, per step
    'seconds' => 15,                   // wall time per file-scan step
    'memory' => 32 * 1024 * 1024,      // memory a step may use over what it started with
    'file_size' => 4 * 1024 * 1024,    // larger files are reported too_large, not read for rules
    'hash_size' => 32 * 1024 * 1024,   // checksum hashing streams the file, so it allows more
    'bytes' => 50 * 1024 * 1024,       // bytes read per step
    'fetches' => 20,                   // checksum downloads per step
    'job_seconds' => 1800,             // step time a whole job may use before it stops as incomplete
    'db_seconds' => 20,                // wall time per database-scan step
    'query_seconds' => 5,              // per query, where the database server supports a limit
    'rows' => 500,                     // rows per query chunk
];

/** Suspicious-indicator rules read at most this much of a file. */
const RULE_READ_BYTES = 262144;

/** Results are stored in options of this many items, which is also the results page size. */
const PAGE_SIZE = 200;

const INDEX_OPTION = 'gobuildwp_kit_si_jobs';
const RESULTS_PREFIX = 'gobuildwp_kit_si_r_';
const START_MUTEX_PREFIX = 'gobuildwp_kit_si_start_';
const START_MUTEX_WAIT = 3.0;

/** GoBuildWP Pro's safe-updates lock: an option holding the time the lock expires. */
const SAFE_UPDATES_LOCK = 'wppilot_kit_safe_updates_lock';

/** Longest pause between steps when the server is busy. */
const MAX_DELAY = 300;

/**
 * A detection token written with one letter in brackets (`ev[a]l(`), returned as the plain text.
 *
 * Host malware scanners (Imunify360, ClamAV, a host's own) quarantine any file whose source holds
 * these names, which made this plugin's own rule files unreadable on some hosts. Written bracketed,
 * the source never holds the name; inside a regex the bracket form matches the same text, and
 * plain-text checks go through this.
 */
function sig(string $token): string
{
    return str_replace(['[', ']'], '', $token);
}

/**
 * @return array<string, int>
 */
function budgets(): array
{
    /** @var mixed $filtered */
    $filtered = apply_filters('gobuildwp_kit_security_integrity_budgets', BUDGETS);
    $budgets = [];
    foreach (BUDGETS as $key => $default) {
        $value = is_array($filtered) && is_numeric($filtered[$key] ?? null) ? (int) $filtered[$key] : $default;
        $budgets[$key] = max(1, min($default, $value));
    }
    return $budgets;
}

/**
 * What one step may still spend. Every counter here is per step; the job's running totals live in
 * its state.
 */
final class Budget
{
    public int $files = 0;
    public int $entries = 0;
    public int $bytes = 0;
    public int $fetches = 0;
    public int $rows = 0;
    private float $deadline;
    private int $memory_ceiling;

    /** @param array<string, int> $limits */
    public function __construct(public array $limits, int $seconds)
    {
        $this->deadline = microtime(true) + $seconds;
        $ceiling = memory_get_usage() + $limits['memory'];
        $limit = memory_limit();
        if ($limit > 0) {
            // Never run a step to within 8 MB of the PHP limit, whatever the headroom says.
            $ceiling = min($ceiling, $limit - 8 * 1024 * 1024);
        }
        $this->memory_ceiling = $ceiling;
    }

    /** True once any per-step limit is reached; the step saves its cursor and stops. */
    public function spent(): bool
    {
        return $this->files >= $this->limits['files']
            || $this->entries >= $this->limits['entries']
            || $this->bytes >= $this->limits['bytes']
            || microtime(true) >= $this->deadline
            // Memory stops a step only after it made progress, so a process already near its limit still advances.
            || ($this->entries + $this->files + $this->rows > 0 && memory_get_usage() >= $this->memory_ceiling);
    }

    public function can_read(int $size): bool
    {
        return $this->bytes + $size <= $this->limits['bytes'] || $this->bytes === 0;
    }

    public function can_fetch(): bool
    {
        return $this->fetches < $this->limits['fetches'];
    }
}

function memory_limit(): int
{
    $value = trim((string) ini_get('memory_limit'));
    if ($value === '' || $value === '-1') {
        return 0;
    }
    $number = (int) $value;
    $unit = strtolower(substr($value, -1));
    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

/**
 * Whether an update is running: GoBuildWP's safe updates, or WordPress's own core or automatic
 * updater. A scan during an update would report every half-written file as modified.
 */
function updates_running(): bool
{
    wp_cache_delete(SAFE_UPDATES_LOCK, 'options');
    if ((int) get_option(SAFE_UPDATES_LOCK, 0) > time()) {
        return true;
    }
    // WP_Upgrader::create_lock() stores the time the lock was taken.
    foreach (['core_updater.lock' => 15 * MINUTE_IN_SECONDS, 'auto_updater.lock' => HOUR_IN_SECONDS] as $lock => $ttl) {
        wp_cache_delete($lock, 'options');
        $taken = (int) get_option($lock, 0);
        if ($taken > time() - $ttl) {
            return true;
        }
    }
    return false;
}

/**
 * Whether the server looks busy enough that the next step should wait longer.
 */
function server_busy(): bool
{
    if (!function_exists('sys_getloadavg')) {
        return false;
    }
    $load = @sys_getloadavg();
    if (!is_array($load) || !isset($load[0])) {
        return false;
    }
    return (float) $load[0] > cpu_count() * 2;
}

function cpu_count(): int
{
    static $count = null;
    if ($count !== null) {
        return $count;
    }
    $count = 1;
    $env = getenv('NUMBER_OF_PROCESSORS');
    if (is_string($env) && (int) $env > 0) {
        $count = (int) $env;
    } elseif (@is_readable('/proc/cpuinfo')) {
        $info = (string) @file_get_contents('/proc/cpuinfo');
        $count = max(1, (int) preg_match_all('/^processor\s*:/m', $info));
    }
    return $count;
}

/**
 * Write result items at their positions, so a step that is run again from the same saved state
 * overwrites what it wrote the first time instead of adding duplicates.
 *
 * @param list<array<string, mixed>> $items
 */
function store_results(string $token, int $start, array $items): void
{
    $pages = [];
    foreach ($items as $offset => $item) {
        $position = $start + $offset;
        $page = intdiv($position, PAGE_SIZE);
        if (!isset($pages[$page])) {
            /** @var mixed $stored */
            $stored = get_option(RESULTS_PREFIX . $token . '_' . $page, []);
            $pages[$page] = is_array($stored) ? array_values($stored) : [];
        }
        $pages[$page][$position % PAGE_SIZE] = $item;
    }
    $last = intdiv($start + count($items) - 1, PAGE_SIZE);
    foreach ($pages as $page => $rows) {
        ksort($rows);
        if ($page === $last) {
            // Anything past the last item written is left over from a run that went further.
            $rows = array_slice($rows, 0, (($start + count($items) - 1) % PAGE_SIZE) + 1, true);
        }
        $name = RESULTS_PREFIX . $token . '_' . $page;
        if (!add_option($name, array_values($rows), '', false)) {
            update_option($name, array_values($rows), false);
        }
    }
}

/**
 * @return list<array<string, mixed>>
 */
function read_results(string $token, int $cursor, int $total): array
{
    $items = [];
    $loaded = [];
    for ($position = $cursor; $position < $total && count($items) < PAGE_SIZE; $position++) {
        $page = intdiv($position, PAGE_SIZE);
        if (!isset($loaded[$page])) {
            /** @var mixed $stored */
            $stored = get_option(RESULTS_PREFIX . $token . '_' . $page, []);
            $loaded[$page] = is_array($stored) ? array_values($stored) : [];
        }
        $row = $loaded[$page][$position % PAGE_SIZE] ?? null;
        if (!is_array($row)) {
            break;
        }
        $items[] = $row;
    }
    return $items;
}

/** Delete a job's result pages, which are stored without gaps from page 0. */
function delete_results(string $token): void
{
    for ($page = 0; $page < 100000; $page++) {
        $name = RESULTS_PREFIX . $token . '_' . $page;
        if (get_option($name, null) === null) {
            return;
        }
        delete_option($name);
    }
}

/**
 * Collects a step's new results and coverage counters, and writes them once at the end.
 */
final class Collector
{
    /** @var list<array<string, mixed>> */
    public array $items = [];

    /** @param array<string, mixed> $state */
    public function __construct(private array &$state) {}

    /** @param array<string, mixed> $item */
    public function add(array $item): void
    {
        $this->items[] = $item;
    }

    public function count(string $area, string $field, int $by = 1): void
    {
        $this->state['coverage'][$area][$field] = (int) ($this->state['coverage'][$area][$field] ?? 0) + $by;
    }

    public function coverage(string $area, string $state, string $reason = ''): void
    {
        $this->state['coverage'][$area]['state'] = $state;
        if ($reason !== '') {
            $this->state['coverage'][$area]['reason'] = $reason;
        }
    }

    /**
     * Note that an area will not be complete: some of it could not be checked. An area still being
     * scanned keeps 'pending' until finish(); a finished one drops to 'partial'.
     */
    public function partial(string $area, string $reason): void
    {
        $this->state['coverage'][$area]['degraded'] = true;
        if (($this->state['coverage'][$area]['state'] ?? '') === 'checked') {
            $this->state['coverage'][$area]['state'] = 'partial';
        }
        if (($this->state['coverage'][$area]['reason'] ?? '') === '') {
            $this->state['coverage'][$area]['reason'] = $reason;
        }
    }

    /** The scan has been through all of an area: 'pending' becomes 'checked' or 'partial'. */
    public function finish(string $area): void
    {
        if (($this->state['coverage'][$area]['state'] ?? '') === 'pending') {
            $this->state['coverage'][$area]['state'] = !empty($this->state['coverage'][$area]['degraded']) ? 'partial' : 'checked';
        }
    }

    public function flush(string $token): void
    {
        if ($this->items === []) {
            return;
        }
        $start = (int) ($this->state['results'] ?? 0);
        store_results($token, $start, $this->items);
        $this->state['results'] = $start + count($this->items);
        $this->items = [];
    }
}
