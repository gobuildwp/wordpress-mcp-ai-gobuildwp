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
 * A change ledger for a kit running outside GoBuildWP.
 *
 * Same promises as GoBuildWP's, on a smaller scale: a row for every write a kit ability makes, a
 * before-image where the kit supplies one, an undo that re-reads the target and refuses to call
 * itself done unless it matches, and one row per item for bulk writes under a shared group.
 * Rows live in one option, capped by count and bytes, written under a database lock so two
 * requests recording at once do not erase each other's row.
 *
 * It records only the kit's own abilities — those a kit named through capture_for() or
 * record_items() — never the host plugin's other writes, which are not the kit's to undo.
 *
 * Given the host id, it also keeps an `audit-read` row for each call of that host's read-only
 * abilities that declare `meta.safety.audit_reads` (a raw SQL SELECT), as GoBuildWP's ledger does:
 * the result never, the redacted input always, so the site owner can see who read what.
 */
final class MiniLedger implements Ledger
{
    public const OPTION = 'wppilot_kit_ledger';
    public const MAX_ROWS = 500;
    public const MAX_BYTES = 4_194_304;
    public const SNAPSHOT_BUDGET = 1_048_576;

    /** @var array<string, callable> */
    private array $captures = [];

    /** @var array<string, array{restore: callable, build: callable|null}> */
    private array $strategies = [];

    /** @var array<string, array<string, mixed>> */
    private array $pending = [];

    /**
     * @param string $audit_scope The host id; abilities under `<id>/` that declare audit_reads
     *                            get an audit row. Empty records no reads.
     */
    public function __construct(private string $audit_scope = '')
    {
        add_action('wp_before_execute_ability', [$this, 'before'], 10, 2);
        add_action('wp_after_execute_ability', [$this, 'after'], 10, 3);
    }

    public function capture_for(string $ability_name, callable $capture): void
    {
        $this->captures[$ability_name] = $capture;
    }

    public function before(string $ability_name, mixed $input): void
    {
        $values = is_array($input) ? $input : [];
        if (!isset($this->captures[$ability_name])) {
            if ($this->audits($ability_name)) {
                $this->pending[$ability_name] = ['input' => self::redact($values), 'audit' => true];
            }
            return;
        }
        /** @var mixed $before */
        $before = ($this->captures[$ability_name])($values);
        $this->pending[$ability_name] = [
            'input' => self::redact($values),
            'before' => is_array($before) && is_string($before['type'] ?? null) ? $before : null,
        ];
    }

    public function after(string $ability_name, mixed $input, mixed $result): void
    {
        $pending = $this->pending[$ability_name] ?? null;
        if ($pending === null) {
            return;
        }
        unset($this->pending[$ability_name]);
        if (($pending['audit'] ?? false) === true) {
            $row = $this->row(
                $ability_name,
                $pending['input'],
                null,
                $result,
                'A read-only call, recorded for audit. Nothing changed, so there is nothing to undo.',
                '',
                [],
            );
            $row['kind'] = 'audit-read';
            $this->store([$row]);
            return;
        }
        $this->store([$this->row($ability_name, $pending['input'], $pending['before'], $result, null, '', [])]);
    }

    /**
     * Whether a call is a read the host's own ability asked to have audited.
     */
    private function audits(string $ability_name): bool
    {
        if ($this->audit_scope === '' || !str_starts_with($ability_name, $this->audit_scope . '/')) {
            return false;
        }
        $ability = wp_get_ability($ability_name);
        if (!$ability instanceof \WP_Ability) {
            return false;
        }
        $meta = $ability->get_meta();
        return ($meta['annotations']['readonly'] ?? false) === true && ($meta['safety']['audit_reads'] ?? false) === true;
    }

    public function record_items(string $ability_name, array $items, ?string $group = null): array
    {
        unset($this->pending[$ability_name]);
        $group = $group !== null && $group !== '' ? $group : wp_generate_uuid4();
        $budget = self::SNAPSHOT_BUDGET;
        $rows = [];
        $without = 0;
        foreach ($items as $item) {
            $before = is_array($item['before'] ?? null) ? $item['before'] : null;
            $reason = is_string($item['irreversible_reason'] ?? null) ? $item['irreversible_reason'] : null;
            if ($reason === null && $before !== null) {
                $bytes = strlen((string) wp_json_encode($before));
                if ($bytes > $budget) {
                    $before = null;
                    $reason = 'No before-image was kept: this batch reached the 1 MB snapshot budget for one call.';
                } else {
                    $budget -= $bytes;
                }
            }
            if ($reason !== null || $before === null) {
                $without++;
            }
            $input = is_array($item['input'] ?? null) ? $item['input'] : [];
            $rows[] = $this->row(
                $ability_name,
                self::redact($input),
                $before,
                $item['result'] ?? null,
                $reason,
                $group,
                is_array($item['item'] ?? null) ? $item['item'] : [],
            );
        }
        $this->store($rows);
        return [
            'group' => $group,
            'change_ids' => array_map(static fn(array $row): string => (string) $row['id'], $rows),
            'without_before_image' => $without,
        ];
    }

    public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
    {
        if (preg_match('#^[a-z0-9-]+(?:/[a-z0-9-]+)+$#', $type) !== 1) {
            return false;
        }
        $this->strategies[$type] = ['restore' => $restore, 'build' => $build];
        return true;
    }

    public function query(array $filters = []): array
    {
        $rows = [];
        foreach (array_reverse($this->all()) as $entry) {
            if (($filters['ability'] ?? '') !== '' && !str_starts_with((string) $entry['ability'], (string) $filters['ability'])) {
                continue;
            }
            if (($filters['group'] ?? '') !== '' && ($entry['group'] ?? '') !== $filters['group']) {
                continue;
            }
            if (($filters['status'] ?? '') !== '' && self::status($entry) !== $filters['status']) {
                continue;
            }
            if (($filters['kind'] ?? '') !== '' && ($entry['kind'] ?? 'change') !== $filters['kind']) {
                continue;
            }
            $recorded = strtotime((string) ($entry['recorded_at'] ?? ''));
            $since = ($filters['since'] ?? '') !== '' ? strtotime((string) $filters['since'] . ' UTC') : false;
            $until = ($filters['until'] ?? '') !== '' ? strtotime((string) $filters['until'] . ' UTC') : false;
            if (($since !== false && $recorded < $since) || ($until !== false && $recorded > $until)) {
                continue;
            }
            $rows[] = $entry;
        }
        return $rows;
    }

    public function export_row(array $entry): array
    {
        $user = is_array($entry['user'] ?? null) ? $entry['user'] : [];
        $rollback = is_array($entry['rollback'] ?? null) ? $entry['rollback'] : [];
        return [
            'id' => (string) ($entry['id'] ?? ''),
            'recorded_at' => (string) ($entry['recorded_at'] ?? ''),
            'kind' => (string) ($entry['kind'] ?? 'change'),
            'ability' => (string) ($entry['ability'] ?? ''),
            'user_id' => (int) ($user['id'] ?? 0),
            'user_login' => (string) ($user['login'] ?? ''),
            'group' => (string) ($entry['group'] ?? ''),
            'status' => self::status($entry),
            'rollback_reason' => (string) ($rollback['reason'] ?? ''),
            'rolled_back_at' => (string) ($entry['rolled_back_at'] ?? ''),
            'input' => is_array($entry['input'] ?? null) ? self::mask_emails($entry['input']) : [],
        ];
    }

    /**
     * Mask email addresses in stored input on the way out, as `j***@e***.com`.
     *
     * Write-time redaction goes by key name, so an address typed into a text field is stored in
     * full; masking here rather than at write time keeps what was recorded exact. Same pattern as
     * the GoBuildWP ledger, including leaving retina names such as `logo@2x.png` alone.
     */
    public static function mask_emails(mixed $value, int $depth = 0): mixed
    {
        if (is_array($value)) {
            if ($depth > 32) {
                return '[depth-limited]';
            }
            foreach ($value as $key => $item) {
                $value[$key] = self::mask_emails($item, $depth + 1);
            }
            return $value;
        }
        if (!is_string($value) || !str_contains($value, '@')) {
            return $value;
        }
        $masked = preg_replace_callback(
            '/([A-Za-z0-9._%+\-]+)@((?:[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?\.)+)([A-Za-z]{2,24})\b(?<!\.png|\.jpg|\.jpeg|\.gif|\.webp|\.svg|\.avif)/',
            static function (array $match): string {
                $domain = rtrim($match[2], '.');
                return substr($match[1], 0, 1) . '***@' . substr($domain, 0, 1) . '***.' . $match[3];
            },
            $value
        );
        return is_string($masked) ? $masked : $value;
    }

    public function snapshot_budget(): int
    {
        return self::SNAPSHOT_BUDGET;
    }

    public function download_url(): string
    {
        return '';
    }

    /**
     * Undo one row: run its strategy, and mark it undone only on a verified result.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function rollback(string $id): array|WP_Error
    {
        $entry = null;
        foreach ($this->all() as $row) {
            if (($row['id'] ?? null) === $id) {
                $entry = $row;
            }
        }
        if ($entry === null) {
            return new WP_Error('kit_change_not_found', 'Change record not found.');
        }
        if (($entry['rolled_back'] ?? false) === true) {
            return new WP_Error('kit_change_already_rolled_back', 'This change was already rolled back.');
        }
        $rollback = is_array($entry['rollback'] ?? null) ? $entry['rollback'] : [];
        if (($rollback['reversible'] ?? false) !== true) {
            return new WP_Error('kit_change_not_reversible', (string) ($rollback['reason'] ?? 'This change is not reversible.'));
        }
        $strategy = $this->strategies[(string) ($rollback['type'] ?? '')] ?? null;
        if ($strategy === null) {
            return new WP_Error('kit_rollback_unknown', 'Unknown rollback strategy.');
        }
        try {
            /** @var mixed $result */
            $result = ($strategy['restore'])($rollback, $entry);
        } catch (\Throwable $error) {
            return new WP_Error('kit_rollback_failed', $error->getMessage());
        }
        if ($result instanceof WP_Error) {
            return $result;
        }
        if (!is_array($result) || ($result['verified'] ?? false) !== true) {
            return new WP_Error(
                'kit_rollback_unverified',
                'Rollback ran but the observed state did not match the before-image.',
                is_array($result) ? $result : [],
            );
        }
        $this->update($id, static function (array $row) use ($result): array {
            $row['rolled_back'] = true;
            $row['rolled_back_at'] = gmdate('c');
            $row['rollback_result'] = $result;
            return $row;
        });
        return ['change_id' => $id, 'rolled_back' => true, 'verified' => true, 'details' => $result];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        /** @var mixed $stored */
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $before
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function row(
        string $ability_name,
        array $input,
        ?array $before,
        mixed $result,
        ?string $reason,
        string $group,
        array $item,
    ): array {
        $user = wp_get_current_user();
        return [
            'id' => wp_generate_uuid4(),
            'kind' => 'change',
            'group' => $group,
            'ability' => $ability_name,
            'recorded_at' => gmdate('c'),
            'user' => ['id' => (int) $user->ID, 'login' => (string) $user->user_login],
            'input' => $input,
            'result' => is_array($result) ? array_slice(array_map('strval', array_keys($result)), 0, 50) : gettype($result),
            'rollback' => $reason !== null ? ['reversible' => false, 'reason' => $reason] : $this->payload($ability_name, $before, $result),
            'rolled_back' => false,
            'item' => $item,
        ];
    }

    /**
     * @param array<string, mixed>|null $before
     * @return array<string, mixed>
     */
    private function payload(string $ability_name, ?array $before, mixed $result): array
    {
        if ($before === null) {
            return ['reversible' => false, 'reason' => 'No supported before-image.'];
        }
        $type = (string) $before['type'];
        $strategy = $this->strategies[$type] ?? null;
        if ($strategy === null) {
            return ['reversible' => false, 'reason' => 'No rollback strategy is registered for this change.'];
        }
        if ($strategy['build'] === null) {
            return ['reversible' => true, 'type' => $type, 'snapshot' => $before];
        }
        /** @var mixed $built */
        $built = ($strategy['build'])($before, $result, $ability_name);
        if (!is_array($built) || ($built['reversible'] ?? true) === false) {
            return ['reversible' => false, 'reason' => is_array($built) ? (string) ($built['reason'] ?? '') : 'Not reversible.'];
        }
        return array_merge($built, ['reversible' => true, 'type' => $type]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function store(array $rows): void
    {
        $this->locked(function () use ($rows): void {
            $log = array_merge($this->all(), $rows);
            if (count($log) > self::MAX_ROWS) {
                $log = array_slice($log, -self::MAX_ROWS);
            }
            while (count($log) > 1 && strlen((string) wp_json_encode($log)) > self::MAX_BYTES) {
                array_shift($log);
            }
            update_option(self::OPTION, $log, false);
        });
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change */
    private function update(string $id, callable $change): void
    {
        $this->locked(function () use ($id, $change): void {
            $log = $this->all();
            foreach ($log as $index => $row) {
                if (($row['id'] ?? null) === $id) {
                    $log[$index] = $change($row);
                }
            }
            update_option(self::OPTION, $log, false);
        });
    }

    private function locked(callable $write): void
    {
        global $wpdb;
        $lock = null;
        if (is_object($wpdb) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare')) {
            $lock = $wpdb->prefix . self::OPTION;
            if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 5)) !== '1') {
                $lock = null;
            }
        }
        wp_cache_delete(self::OPTION, 'options');
        try {
            $write();
        } finally {
            if ($lock !== null) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }

    /** @param array<string, mixed> $entry */
    public static function status(array $entry): string
    {
        if (($entry['rolled_back'] ?? false) === true) {
            return 'rolled-back';
        }
        $rollback = is_array($entry['rollback'] ?? null) ? $entry['rollback'] : [];
        return ($rollback['reversible'] ?? false) === true ? 'undoable' : 'not-reversible';
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    public static function redact(array $values, int $depth = 0): array
    {
        $safe = [];
        foreach (array_slice($values, 0, 100, true) as $key => $value) {
            if (preg_match('/password|passwd|secret|token|authorization|api[_-]?key|private[_-]?key|license|cookie|credential/i', (string) $key) === 1) {
                $safe[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $safe[$key] = $depth >= 4 ? '[depth-limited]' : self::redact($value, $depth + 1);
            } else {
                $safe[$key] = is_string($value) && strlen($value) > 500 ? substr($value, 0, 500) . '...' : $value;
            }
        }
        return $safe;
    }
}
