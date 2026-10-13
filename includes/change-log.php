<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_CHANGE_LOG_OPTION = 'wppilot_change_log';

const WPPILOT_CHANGE_LOG_MAX = 500;

const WPPILOT_CHANGE_LOG_MAX_BYTES = 4_194_304;

const WPPILOT_CHANGE_SNAPSHOT_MAX_BYTES = 524_288;

/**
 * The ledger's recent rows, oldest first.
 *
 * In table storage this is the newest WPPILOT_CHANGE_LOG_MAX rows within
 * WPPILOT_CHANGE_LOG_MAX_BYTES — the most the option ever held — so code written when this was
 * the whole log still reads a bounded list whose last row is the newest. Anything that searches
 * the ledger uses wppilot_query_change_log(), which reaches every row.
 *
 * @return list<array<string, mixed>>
 */
function wppilot_get_change_log(): array
{
    if (wppilot_change_table_active()) {
        return wppilot_change_table_recent();
    }

    return wppilot_change_log_option_rows();
}

/**
 * The rows held in the option: the whole ledger before the table, and after a fallback.
 *
 * @return list<array<string, mixed>>
 */
function wppilot_change_log_option_rows(): array
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_CHANGE_LOG_OPTION, default_value: []);
    if (!is_array($stored)) {
        return [];
    }
    $log = [];
    // @mago-expect analysis:mixed-assignment -- Stored option rows are normalized below.
    foreach ($stored as $entry) {
        if (is_array($entry)) {
            $log[] = wppilot_string_keyed_array($entry);
        }
    }
    return $log;
}

/** Snapshot bytes one bulk call may add to the ledger: a quarter of its cap. */
const WPPILOT_CHANGE_BULK_SNAPSHOT_BUDGET_BYTES = 1_048_576;

/** @param array<string, mixed> $entry */
function wppilot_store_change(array $entry): void
{
    wppilot_store_changes([$entry]);
}

/**
 * Record rows in the ledger, in order, newest last.
 *
 * In table storage each row is one INSERT and nothing else is read or rewritten, so concurrent
 * requests cannot erase each other's rows. Before the migration, and on a site that cannot create
 * the table, the option path below is used.
 *
 * @param list<array<string, mixed>> $entries
 */
function wppilot_store_changes(array $entries): void
{
    if ($entries === []) {
        return;
    }
    if (wppilot_change_table_active()) {
        wppilot_change_table_store(wppilot_change_rows_before_store($entries, legacy_filter: true));
        return;
    }
    // The option write below runs the option's own pre-update filter, so only the new one here.
    wppilot_change_option_store(wppilot_change_rows_before_store($entries, legacy_filter: false));
}

/**
 * Append rows to the option ledger in one read-modify-write, under a lock.
 *
 * The option is rewritten whole on every row. Two requests recording at once
 * each read the same log, append their own row and write it back, and the slower one's write
 * erases the faster one's row. A bulk write recording a hundred items one call at a time also
 * paid for a hundred full rewrites of up to 4 MB. Both are fixed here: the rows go in together,
 * and the read and write happen under a database lock with the option cache dropped first, so
 * a row another request wrote a moment ago is seen.
 *
 * When the lock cannot be had within a few seconds the rows are still written: losing a
 * concurrent row is the old behaviour, and refusing to record a write that already happened
 * would be worse.
 *
 * @param list<array<string, mixed>> $entries
 */
function wppilot_change_option_store(array $entries): void
{
    if ($entries === []) {
        return;
    }
    wppilot_with_change_log_lock(static function () use ($entries): void {
        $log = array_merge(wppilot_change_log_option_rows(), $entries);
        if (count($log) > WPPILOT_CHANGE_LOG_MAX) {
            $log = array_slice(array: $log, offset: -WPPILOT_CHANGE_LOG_MAX);
        }
        while (count($log) > 1) {
            $encoded = wp_json_encode($log);
            if (is_string($encoded) && strlen($encoded) <= WPPILOT_CHANGE_LOG_MAX_BYTES) {
                break;
            }
            array_shift($log);
        }
        update_option(WPPILOT_CHANGE_LOG_OPTION, $log, autoload: false);
    });
}

/**
 * Run a read-modify-write of the ledger under a MySQL named lock.
 *
 * Table storage needs no lock to record a row; the lock still guards the option (before the
 * migration, and after a fallback) and the migration takes it too, so callers that wrap their own
 * read-modify-write in it keep working either way.
 *
 * @template T
 * @param callable(): T $write
 * @return T
 */
function wppilot_with_change_log_lock(callable $write): mixed
{
    global $wpdb;

    $lock = null;
    if (is_object($wpdb) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare')) {
        $lock = $wpdb->prefix . 'wppilot_change_log';
        $acquired = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 5)) === '1';
        if (!$acquired) {
            $lock = null;
        }
    }
    // An autoload=no option is still cached after its first read, and a persistent object cache
    // shares that copy across requests; reading it would re-introduce the lost-row race.
    if (function_exists('wp_cache_delete')) {
        wp_cache_delete(WPPILOT_CHANGE_LOG_OPTION, 'options');
    }

    try {
        return $write();
    } finally {
        if ($lock !== null) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
}

/**
 * Ledger rows matching a filter, newest first.
 *
 * One reader for every place the ledger is searched — the Changes screen, its download and the
 * export ability — so a filter cannot mean one thing on screen and another in the file.
 *
 * @param array{
 *     kind?: string,
 *     ability?: string,
 *     user_id?: int,
 *     agent?: string,
 *     group?: string,
 *     session?: string,
 *     status?: string,
 *     since?: string,
 *     until?: string,
 * } $filters `kind` is `change` or `audit-read`; `ability` matches a prefix; `agent` matches the
 *            credential key exactly or the label or client name as a substring; `status` is
 *            `undoable`, `rolled-back` or `not-reversible`; `since`/`until` are anything
 *            strtotime() reads, compared in UTC and inclusive.
 * @param int $limit  Most rows to return; 0 for all. A page of the result, with $offset.
 * @param int $offset Rows to skip from the newest.
 * @return list<array<string, mixed>>
 */
function wppilot_query_change_log(array $filters = [], int $limit = 0, int $offset = 0): array
{
    if (wppilot_change_table_active()) {
        return wppilot_change_table_query($filters, $limit, $offset);
    }
    $rows = wppilot_query_change_log_option($filters);
    if ($limit > 0 || $offset > 0) {
        $rows = array_slice($rows, max(0, $offset), $limit > 0 ? $limit : null);
    }

    return $rows;
}

/**
 * How many rows wppilot_query_change_log() would return for these filters, without loading them.
 *
 * @param array<string, mixed> $filters
 */
function wppilot_count_change_log(array $filters = []): int
{
    if (wppilot_change_table_active()) {
        return wppilot_change_table_count($filters);
    }

    return count(wppilot_query_change_log_option($filters));
}

/**
 * How many rows each of these groups holds in the whole ledger.
 *
 * @param list<string> $groups
 * @return array<string, int>
 */
function wppilot_change_group_sizes(array $groups): array
{
    if (wppilot_change_table_active()) {
        return wppilot_change_table_group_sizes($groups);
    }
    $wanted = array_fill_keys(array_filter($groups, static fn(string $group): bool => $group !== ''), true);
    $sizes = [];
    foreach (wppilot_change_log_option_rows() as $row) {
        $group = $row['group'] ?? null;
        if (is_string($group) && isset($wanted[$group])) {
            $sizes[$group] = ($sizes[$group] ?? 0) + 1;
        }
    }

    return $sizes;
}

/**
 * The option-storage reader behind wppilot_query_change_log().
 *
 * @param array<string, mixed> $filters
 * @return list<array<string, mixed>>
 */
function wppilot_query_change_log_option(array $filters): array
{
    $kind = (string) ($filters['kind'] ?? '');
    $ability = (string) ($filters['ability'] ?? '');
    $user_id = (int) ($filters['user_id'] ?? 0);
    $agent = strtolower(trim((string) ($filters['agent'] ?? '')));
    $group = (string) ($filters['group'] ?? '');
    $session = (string) ($filters['session'] ?? '');
    $status = (string) ($filters['status'] ?? '');
    $since = wppilot_change_filter_time((string) ($filters['since'] ?? ''), end_of_day: false);
    $until = wppilot_change_filter_time((string) ($filters['until'] ?? ''), end_of_day: true);

    $rows = [];
    foreach (array_reverse(wppilot_change_log_option_rows()) as $entry) {
        if ($kind !== '' && (string) ($entry['kind'] ?? 'change') !== $kind) {
            continue;
        }
        if ($ability !== '' && !str_starts_with((string) ($entry['ability'] ?? ''), $ability)) {
            continue;
        }
        $user = is_array($entry['user'] ?? null) ? $entry['user'] : [];
        if ($user_id > 0 && (int) ($user['id'] ?? 0) !== $user_id) {
            continue;
        }
        if ($agent !== '' && !wppilot_change_agent_matches($entry, $agent)) {
            continue;
        }
        if ($group !== '' && ($entry['group'] ?? null) !== $group) {
            continue;
        }
        if ($session !== '' && ($entry['session'] ?? null) !== $session) {
            continue;
        }
        if ($status !== '' && wppilot_change_status($entry) !== $status) {
            continue;
        }
        $recorded = strtotime((string) ($entry['recorded_at'] ?? ''));
        if (($since !== null || $until !== null) && $recorded === false) {
            continue;
        }
        if (($since !== null && $recorded < $since) || ($until !== null && $recorded > $until)) {
            continue;
        }
        $rows[] = $entry;
    }

    return $rows;
}

/**
 * `undoable`, `rolled-back` or `not-reversible`.
 *
 * @param array<string, mixed> $entry
 */
function wppilot_change_status(array $entry): string
{
    if (($entry['rolled_back'] ?? false) === true) {
        return 'rolled-back';
    }
    $rollback = is_array($entry['rollback'] ?? null) ? $entry['rollback'] : [];
    return ($rollback['reversible'] ?? false) === true ? 'undoable' : 'not-reversible';
}

/** @param array<string, mixed> $entry */
function wppilot_change_agent_matches(array $entry, string $needle): bool
{
    $agent = is_array($entry['agent'] ?? null) ? $entry['agent'] : [];
    if ($needle === strtolower((string) ($agent['credential'] ?? ''))) {
        return true;
    }
    foreach (['label', 'client'] as $field) {
        $value = strtolower((string) ($agent[$field] ?? ''));
        if ($value !== '' && str_contains($value, $needle)) {
            return true;
        }
    }
    return false;
}

function wppilot_change_filter_time(string $value, bool $end_of_day): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    // A bare date means the whole day, so "until 2026-09-27" includes that afternoon.
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        $value .= $end_of_day ? ' 23:59:59' : ' 00:00:00';
    }
    $time = strtotime($value . (preg_match('/(Z|[+-]\d{2}:?\d{2}|UTC)$/i', $value) === 1 ? '' : ' UTC'));
    return $time === false ? null : $time;
}

/**
 * One ledger row as it leaves the site in an export.
 *
 * Flat, and without the rollback snapshot: a before-image is a full copy of a post, its meta and
 * its terms, which is the site's content rather than a record of what happened to it. Input was
 * redacted by key name at write time; email addresses in its values are masked here.
 *
 * @param array<string, mixed> $entry
 * @return array<string, mixed>
 */
function wppilot_change_export_row(array $entry): array
{
    $user = is_array($entry['user'] ?? null) ? $entry['user'] : [];
    $agent = is_array($entry['agent'] ?? null) ? $entry['agent'] : [];
    $rollback = is_array($entry['rollback'] ?? null) ? $entry['rollback'] : [];

    return [
        'id' => (string) ($entry['id'] ?? ''),
        'recorded_at' => (string) ($entry['recorded_at'] ?? ''),
        'kind' => (string) ($entry['kind'] ?? 'change'),
        'ability' => (string) ($entry['ability'] ?? ''),
        'risk' => (string) ($entry['risk'] ?? ''),
        'user_id' => (int) ($user['id'] ?? 0),
        'user_login' => (string) ($user['login'] ?? ''),
        'agent_method' => (string) ($agent['method'] ?? ''),
        'agent_label' => (string) ($agent['label'] ?? ''),
        'agent_client' => (string) ($agent['client'] ?? ''),
        'group' => (string) ($entry['group'] ?? ''),
        'status' => wppilot_change_status($entry),
        'rollback_reason' => (string) ($rollback['reason'] ?? ''),
        'rolled_back_at' => (string) ($entry['rolled_back_at'] ?? ''),
        'confirmation' => is_array($entry['confirmation'] ?? null) ? (string) ($entry['confirmation']['method'] ?? '') : '',
        'input' => is_array($entry['input'] ?? null) ? wppilot_redact_for_output($entry['input']) : [],
    ];
}

/**
 * One ledger row as get-change and the Changes screen show it.
 *
 * Write-time redaction works on key names only, so an email address typed into an excerpt, a
 * comment or an option value was stored and shown in full. Values are masked here, on the way
 * out, and never in storage: the before-image under `rollback` is what undo restores and verifies
 * against, and a masked copy there would make undo write the mask back into the site. The key-name
 * redaction is applied again too, because before-images were never redacted at all.
 *
 * @param array<string, mixed> $entry
 * @return array<string, mixed>
 */
function wppilot_change_for_output(array $entry): array
{
    foreach (['input', 'result', 'rollback', 'rollback_result', 'design'] as $key) {
        if (array_key_exists($key, $entry)) {
            // @mago-expect analysis:mixed-assignment -- Redaction preserves each value's shape.
            $entry[$key] = wppilot_redact_for_output($entry[$key]);
        }
    }
    return $entry;
}

/**
 * Key-name redaction plus email masking, without the length cuts of wppilot_redact_for_log():
 * this is for reading a record, where a shortened before-image would be a wrong one.
 */
function wppilot_redact_for_output(mixed $value, int $depth = 0): mixed
{
    if (is_string($value)) {
        return wppilot_mask_emails($value);
    }
    if (!is_array($value)) {
        return $value;
    }
    if ($depth > 32) {
        return '[depth-limited]';
    }
    $result = [];
    foreach ($value as $key => $item) {
        if (is_string($key) && wppilot_change_key_is_sensitive($key)) {
            $result[$key] = '[redacted]';
            continue;
        }
        // @mago-expect analysis:mixed-assignment -- Redaction preserves each value's shape.
        $result[$key] = wppilot_redact_for_output($item, $depth + 1);
    }
    return $result;
}

/**
 * Mask every email address in a string as `j***@e***.com`.
 *
 * The first letter and the top-level domain survive so a reader can still tell two addresses
 * apart and see that the field held an address; the rest does not. Retina file names such as
 * `logo@2x.png` look like addresses to a plain pattern, so an image extension in place of a
 * top-level domain is left alone.
 */
function wppilot_mask_emails(string $value): string
{
    if (!str_contains($value, '@')) {
        return $value;
    }
    $masked = preg_replace_callback(
        '/([A-Za-z0-9._%+\-]+)@((?:[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?\.)+)([A-Za-z]{2,24})\b(?<!\.png|\.jpg|\.jpeg|\.gif|\.webp|\.svg|\.avif)/',
        static function (array $match): string {
            $domain = rtrim($match[2], '.');
            return substr($match[1], 0, 1) . '***@' . substr($domain, 0, 1) . '***.' . $match[3];
        },
        $value,
    );
    return is_string($masked) ? $masked : $value;
}

/** @return array<string, mixed>|null */
function wppilot_get_change(string $id): ?array
{
    if (wppilot_change_table_active()) {
        return wppilot_change_table_get($id);
    }
    foreach (array_reverse(wppilot_change_log_option_rows()) as $entry) {
        if (($entry['id'] ?? null) === $id) {
            return $entry;
        }
    }
    return null;
}

/** @param array<string, mixed> $replacement */
function wppilot_replace_change(string $id, array $replacement): bool
{
    if (wppilot_change_table_active()) {
        return wppilot_change_table_replace($id, $replacement);
    }

    return wppilot_with_change_log_lock(static function () use ($id, $replacement): bool {
        $log = wppilot_change_log_option_rows();
        foreach ($log as $index => $entry) {
            if (($entry['id'] ?? null) !== $id) {
                continue;
            }
            $log[$index] = $replacement;
            update_option(WPPILOT_CHANGE_LOG_OPTION, $log, autoload: false);
            return true;
        }
        return false;
    });
}

/**
 * Record one ledger row per item of a bulk write, and drop the call's own row.
 *
 * The ledger records one row per ability call, holding one before-image. A bulk write touches up
 * to a hundred targets, and one row can neither hold a hundred snapshots nor undo one target
 * without the rest — so an agent that got three titles wrong in a batch of eighty had to roll
 * back all eighty or none. Each item gets its own row instead, and the aggregate row the ledger
 * opened for the call, which could only have said "no before-image", is discarded.
 *
 * Every row carries the same `group`, so the batch can still be undone as one
 * (wppilot_rollback_group()) and the Changes screen can show it as one.
 *
 * Snapshots are whole objects. Where the ledger is still the option (capped at 4 MB) a batch of
 * heavy builder pages could otherwise evict every earlier change on the site, and in the table
 * the images are what the table's size is made of. So one call may add at most
 * WPPILOT_CHANGE_BULK_SNAPSHOT_BUDGET_BYTES of before-images. Items past the budget are still
 * recorded, but their rows say plainly that no before-image was kept. A caller that must not
 * write without one sums wppilot_snapshot_bytes() against the same constant before each write
 * and stops there.
 *
 * @param list<array{
 *     input: array<string, mixed>,
 *     before: array<string, mixed>|null,
 *     result: mixed,
 *     item?: array<string, mixed>,
 *     irreversible_reason?: string|null,
 * }> $items
 * @return array{group: string, change_ids: list<string>, without_before_image: int}
 */
function wppilot_ledger_record_items(string $ability_name, array $items, ?string $group = null): array
{
    wppilot_change_pending($ability_name, value: null, clear: true);

    $group = $group !== null && $group !== '' ? $group : wp_generate_uuid4();
    $user = wp_get_current_user();
    $agent = function_exists('wppilot_current_agent') ? wppilot_current_agent() : [];
    $ability = function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null;
    $risk = $ability instanceof WP_Ability ? wppilot_ability_risk($ability) : 'write';
    $budget = WPPILOT_CHANGE_BULK_SNAPSHOT_BUDGET_BYTES;
    // Read, not cleared: the call's own after-hook still runs and clears it.
    $confirmation = wppilot_change_confirmation($ability_name, clear: false);
    $rows = [];
    $ids = [];
    $without = 0;

    foreach ($items as $item) {
        $before = $item['before'] ?? null;
        $reason = $item['irreversible_reason'] ?? null;
        if ($reason === null && $before !== null) {
            $bytes = wppilot_snapshot_bytes($before);
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

        $id = wp_generate_uuid4();
        $ids[] = $id;
        $input = wppilot_string_keyed_array($item['input'] ?? []);
        $rows[] = [
            'id' => $id,
            'kind' => 'change',
            'group' => $group,
            'ability' => $ability_name,
            'risk' => $risk,
            'recorded_at' => gmdate('c'),
            'duration_ms' => 0,
            'user' => ['id' => (int) $user->ID, 'login' => (string) $user->user_login],
            'agent' => $agent,
            'input' => wppilot_redact_for_log($input),
            'input_sha256' => hash('sha256', (string) wp_json_encode($input)),
            'result' => wppilot_result_summary($item['result'] ?? null),
            'rollback' => $reason !== null
                ? ['reversible' => false, 'reason' => $reason]
                : wppilot_build_rollback_payload($ability_name, $before, $item['result'] ?? null),
            'rolled_back' => false,
            'design' => [],
            'confirmation' => $confirmation,
            'bulk_item' => wppilot_string_keyed_array($item['item'] ?? []),
        ];
    }

    wppilot_store_changes($rows);

    return ['group' => $group, 'change_ids' => $ids, 'without_before_image' => $without];
}

/**
 * Size of a before-image as the ledger will store it.
 *
 * @param array<string, mixed>|null $snapshot
 */
function wppilot_snapshot_bytes(?array $snapshot): int
{
    if ($snapshot === null) {
        return 0;
    }
    $encoded = wp_json_encode($snapshot);
    return is_string($encoded) ? strlen($encoded) : 0;
}

/**
 * Undo several changes, newest first, and report each one.
 *
 * Newest first because two rows can touch the same target: undoing the older one first would
 * restore a state the newer one then "restores" over, and its verification would fail against a
 * target that no longer looks like its after-state. A row that fails does not stop the rest; the
 * result says which did not come back and why.
 *
 * @param list<string> $ids
 * @return array{rolled_back: int, failed: int, skipped: int, results: list<array<string, mixed>>}
 */
function wppilot_rollback_changes(array $ids): array
{
    $wanted = array_fill_keys($ids, true);
    $rows = wppilot_change_table_active()
        ? wppilot_change_table_get_many(array_values(array_map('strval', $ids)))
        : array_values(array_filter(
            array_reverse(wppilot_change_log_option_rows()),
            static fn(array $entry): bool => isset($wanted[(string) ($entry['id'] ?? '')]),
        ));

    $summary = ['rolled_back' => 0, 'failed' => 0, 'skipped' => 0, 'results' => []];
    foreach ($rows as $entry) {
        $id = (string) ($entry['id'] ?? '');
        $rollback = is_array($entry['rollback'] ?? null) ? $entry['rollback'] : [];
        if (($entry['rolled_back'] ?? false) === true || ($rollback['reversible'] ?? false) !== true) {
            $summary['skipped']++;
            $summary['results'][] = [
                'change_id' => $id,
                'status' => 'skipped',
                'reason' => ($entry['rolled_back'] ?? false) === true
                    ? 'Already rolled back.'
                    : (string) ($rollback['reason'] ?? 'Not reversible.'),
            ];
            continue;
        }
        $result = wppilot_rollback_change($id);
        if ($result instanceof WP_Error) {
            $summary['failed']++;
            $summary['results'][] = [
                'change_id' => $id,
                'status' => 'failed',
                'code' => $result->get_error_code(),
                'reason' => $result->get_error_message(),
            ];
            continue;
        }
        $summary['rolled_back']++;
        $summary['results'][] = ['change_id' => $id, 'status' => 'rolled_back'];
    }

    return $summary;
}

/**
 * Undo every change recorded under one group (one bulk call).
 *
 * @return array{rolled_back: int, failed: int, skipped: int, results: list<array<string, mixed>>}|WP_Error
 */
function wppilot_rollback_group(string $group): array|WP_Error
{
    $ids = [];
    if ($group !== '' && wppilot_change_table_active()) {
        $ids = wppilot_change_table_group_ids($group);
    } elseif ($group !== '') {
        foreach (wppilot_change_log_option_rows() as $entry) {
            if (($entry['group'] ?? null) === $group) {
                $ids[] = (string) ($entry['id'] ?? '');
            }
        }
    }
    if ($ids === []) {
        return new WP_Error('wppilot_change_group_not_found', __('No changes were recorded under that group.', domain: 'wppilot'));
    }

    return wppilot_rollback_changes($ids);
}

/**
 * Capture a before-image for known reversible WordPress operations.
 */
/**
 * Meta-abilities that wrap another write and must not record one of their own.
 *
 * Each of these ends up executing some other ability, which records itself. A
 * second entry for the wrapper carries no before-image, so it would be filed as
 * non-reversible with the reason "No supported before-image" — and with the log
 * capped at WPPILOT_CHANGE_LOG_MAX entries, that junk evicts real history at
 * twice the rate it should.
 *
 * @var list<string>
 */
const WPPILOT_CHANGE_META_ABILITIES = [
    // The legacy MCP transport's execute tool runs the target through WP_Ability::execute(), which
    // records the target. Its own row said only "No supported before-image" — one per call, reads
    // included — and would make every legacy session look like it held irreversible changes.
    'mcp-adapter/execute-ability',
    'wppilot/rollback-change',
    'wppilot/apply-preview',
    'wppilot/undo-session',
    'wppilot/redo-session',
];

function wppilot_change_ability_is_meta(string $ability_name): bool
{
    foreach (WPPILOT_CHANGE_META_ABILITIES as $meta) {
        if (str_starts_with($ability_name, $meta)) {
            return true;
        }
    }
    return false;
}

/**
 * Open a pending change record for a mutation that is about to run.
 *
 * WordPress 7.1 passes the `WP_Ability` being executed as a third argument. Taking it means the
 * ledger reads the instance that is actually running rather than looking the name up again, and
 * that matters at the other end of the pair: an ability that changes the safety profile or
 * switches another ability off causes the policy to unregister rows mid-request, and a lookup by
 * name after the write can come back empty for an ability that plainly exists. Older WordPress
 * passes two arguments and the lookup still happens.
 */
function wppilot_change_before(string $ability_name, mixed $input, mixed $ability = null): void
{
    if (wppilot_change_is_suppressed() || wppilot_change_ability_is_meta($ability_name)) {
        return;
    }
    $ability = wppilot_change_resolve_ability($ability_name, $ability);
    $values = wppilot_string_keyed_array($input);
    if ($ability instanceof WP_Ability && wppilot_ability_is_readonly($ability)) {
        // A read changes nothing, so it is normally not recorded. A read that is sensitive in its
        // own right (a raw SQL SELECT) opts in through meta.safety.audit_reads, so the site owner
        // can see who ran what. The input is kept, redacted as for any write; the result never is.
        if (wppilot_ability_safety_policy($ability)['audit_reads']) {
            wppilot_change_pending($ability_name, [
                'started_at' => microtime(true),
                'input_summary' => wppilot_redact_for_log($values),
                'input_sha256' => hash('sha256', (string) wp_json_encode($values)),
                'audit_read' => true,
            ]);
        }
        return;
    }
    wppilot_change_pending($ability_name, [
        'started_at' => microtime(true),
        'input_summary' => wppilot_redact_for_log($values),
        'input_sha256' => hash('sha256', (string) wp_json_encode($values)),
        'before' => wppilot_capture_before_image($ability_name, $values),
    ]);
}

/**
 * Resolve the ability an execution hook is reporting on.
 *
 * `$passed` is the instance WordPress 7.1 hands the hook; on 6.9 and 7.0 it is null and the
 * registry is asked for the name instead.
 */
function wppilot_change_resolve_ability(string $ability_name, mixed $passed): ?WP_Ability
{
    if ($passed instanceof WP_Ability) {
        return $passed;
    }

    $ability = function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null;

    return $ability instanceof WP_Ability ? $ability : null;
}

/**
 * Record a completed mutation. WordPress fires this only after output validation succeeds.
 */
function wppilot_change_after(string $ability_name, mixed $input, mixed $result, mixed $ability = null): void
{
    if (wppilot_change_is_suppressed()) {
        return;
    }
    $pending = wppilot_change_pending($ability_name);
    if ($pending === null) {
        return;
    }
    wppilot_change_pending($ability_name, value: null, clear: true);
    $audit_read = ($pending['audit_read'] ?? false) === true;
    // @mago-expect analysis:mixed-assignment -- Pending data is internal and normalized below.
    $before_value = $pending['before'] ?? null;
    $before = is_array($before_value) ? wppilot_string_keyed_array($before_value) : null;
    $rollback = $audit_read
        ? ['reversible' => false, 'reason' => 'A read-only call, recorded for audit. Nothing changed, so there is nothing to undo.']
        : wppilot_build_rollback_payload($ability_name, $before, $result);
    $ability = wppilot_change_resolve_ability($ability_name, $ability);
    $risk = $ability instanceof WP_Ability ? wppilot_ability_risk($ability) : 'write';
    $user = wp_get_current_user();

    wppilot_store_change([
        // 'audit-read' rows are a record of access, not of a change; the Changes screen and the
        // export filter on it, and nothing offers to undo one.
        'kind' => $audit_read ? 'audit-read' : 'change',
        'id' => wp_generate_uuid4(),
        'ability' => $ability_name,
        'risk' => $risk,
        'recorded_at' => gmdate('c'),
        // @mago-expect analysis:invalid-type-cast -- Pending timestamps are stored internally as floats.
        'duration_ms' => round(
            num: (microtime(true) - (float) ($pending['started_at'] ?? microtime(true))) * 1000,
            precision: 2,
        ),
        'user' => ['id' => (int) $user->ID, 'login' => (string) $user->user_login],
        // Which agent, not just which WordPress user. Several agents share one
        // administrator on most sites, so the user alone cannot attribute a write.
        'agent' => function_exists('wppilot_current_agent') ? wppilot_current_agent() : [],
        'input' => $pending['input_summary'] ?? [],
        'input_sha256' => (string) ($pending['input_sha256'] ?? ''),
        'result' => $audit_read ? wppilot_audit_read_summary($result) : wppilot_result_summary($result),
        'rollback' => $rollback,
        'rolled_back' => false,
        // What the design gate saw, when it saw anything. In warn mode the write
        // proceeds and this is the only record that it drifted, which is the
        // point: a site owner who is not ready to refuse writes can still audit
        // where the direction slipped.
        'design' => wppilot_change_design_findings($ability_name),
        'confirmation' => wppilot_change_confirmation($ability_name, clear: true),
    ]);
}

/**
 * How the write being recorded was confirmed: `argument`, `elicitation`, `approval-url`, `chat`,
 * `approval-queue` or `not-required`, plus who approved it for an approval-url call. Empty when the
 * call reached execute() without passing the gate pipeline, such as an ability another ability ran.
 *
 * @return array{method?: string, approved_by?: int}
 */
function wppilot_change_confirmation(string $ability_name, bool $clear): array
{
    if (!function_exists('wppilot_confirmation_note')) {
        return [];
    }

    return wppilot_confirmation_note($ability_name, method: null, clear: $clear) ?? [];
}

/**
 * Design-gate findings for the write being recorded, if the gate ran.
 *
 * Read from the gate's own request-scoped store rather than passed down the
 * call chain: the gate runs on a filter well before this function, and
 * threading a value through every transport to reach it would put design
 * plumbing in four files that have nothing to do with design.
 *
 * @return array<string, mixed>
 */
function wppilot_change_design_findings(string $ability_name): array
{
    if (!function_exists('WPPilot\Design\Gate\pending_findings')) {
        return [];
    }
    /** @var array<string, mixed> $findings */
    $findings = \WPPilot\Design\Gate\pending_findings();
    if (($findings['ability'] ?? '') !== $ability_name) {
        return [];
    }
    unset($findings['ability']);

    return $findings;
}

/**
 * @param array<string, mixed>|null $value
 * @return array<string, mixed>|null
 */
function wppilot_change_pending(string $ability_name, ?array $value = null, bool $clear = false): ?array
{
    /** @var array<string, array<string, mixed>> $pending */
    static $pending = [];
    if ($clear) {
        $existing = $pending[$ability_name] ?? null;
        unset($pending[$ability_name]);
        return $existing;
    }
    if ($value !== null) {
        $pending[$ability_name] = $value;
    }
    return $pending[$ability_name] ?? null;
}

function wppilot_change_is_suppressed(?bool $set = null): bool
{
    static $suppressed = false;
    if ($set !== null) {
        $suppressed = $set;
    }
    return $suppressed;
}

/** @param array<string, mixed> $input @return array<string, mixed>|null */
// @mago-expect lint:cyclomatic-complexity -- Central snapshot routing keeps mutation coverage auditable.
// @mago-expect lint:halstead -- The explicit operation map is intentionally kept in one reviewable place.
function wppilot_capture_before_image(string $ability_name, array $input): ?array
{
    if (in_array($ability_name, ['wppilot/update-post', 'wppilot/delete-post'], strict: true)) {
        return wppilot_snapshot_post((int) ($input['post_id'] ?? $input['id'] ?? 0));
    }
    if ($ability_name === 'wppilot/create-post') {
        return ['type' => 'post-create'];
    }
    if (in_array($ability_name, ['wppilot/update-media', 'wppilot/delete-media'], strict: true)) {
        return wppilot_snapshot_post((int) ($input['attachment_id'] ?? 0));
    }
    if ($ability_name === 'wppilot/update-site-settings') {
        $values = [];
        foreach (array_keys($input) as $key) {
            $values[$key] = get_option($key);
        }
        return ['type' => 'settings', 'values' => $values, 'fingerprint' => wppilot_snapshot_fingerprint($values)];
    }
    if ($ability_name === 'wppilot/upsert-menu-item') {
        $item_id = (int) ($input['item_id'] ?? 0);
        return $item_id > 0 ? wppilot_snapshot_post($item_id) : ['type' => 'menu-create'];
    }
    if ($ability_name === 'wppilot/delete-menu-item') {
        return wppilot_snapshot_post((int) ($input['item_id'] ?? 0));
    }
    if ($ability_name === 'wppilot/woocommerce-update-order-status' && function_exists('wc_get_order')) {
        $order = wc_get_order((int) ($input['order_id'] ?? 0));
        if ($order instanceof WC_Order) {
            $values = ['order_id' => $order->get_id(), 'status' => $order->get_status()];
            return [
                'type' => 'order-status',
                'values' => $values,
                'fingerprint' => wppilot_snapshot_fingerprint($values),
            ];
        }
    }
    if ($ability_name === 'wppilot/woocommerce-create-coupon') {
        return ['type' => 'coupon-create'];
    }
    if ($ability_name === 'wppilot/woocommerce-update-coupon') {
        return wppilot_snapshot_post((int) ($input['coupon_id'] ?? 0));
    }
    if (in_array(
        $ability_name,
        ['wppilot/woocommerce-moderate-review', 'wppilot/woocommerce-delete-review'],
        strict: true,
    )) {
        // @mago-expect analysis:mixed-assignment -- WordPress returns WP_Comment|null for OBJECT output.
        $comment = get_comment((int) ($input['review_id'] ?? 0));
        if ($comment instanceof WP_Comment) {
            $values = ['comment_id' => (int) $comment->comment_ID, 'status' => wp_get_comment_status($comment)];
            return [
                'type' => 'comment-status',
                'values' => $values,
                'fingerprint' => wppilot_snapshot_fingerprint($values),
            ];
        }
    }
    if ($ability_name === 'wppilot/woocommerce-add-order-note') {
        return ['type' => 'order-note-create'];
    }
    $core = wppilot_capture_core_before_image($ability_name, $input);
    if ($core !== null) {
        return $core;
    }

    /**
     * Before-image for an ability this plugin does not know about.
     *
     * Everything above covers the abilities the free plugin registers. Anything
     * else — every builder, form, field and commerce ability another plugin
     * adds — reached the ledger as a logged-but-irreversible row: recorded,
     * visible in the Changes screen, and impossible to undo. Setting the wrong
     * display condition on a header is exactly the mistake somebody wants back.
     *
     * A listener returns the same shape as the built-ins: an array carrying a
     * `type`, and whatever that type's restore path needs. `wppilot_snapshot_post()`
     * is the one to reach for when the effect lives on a post row — it captures
     * fields, meta and terms, which is enough to restore any builder that keeps
     * its document in post meta. Built-in captures always win; this only fills
     * the gap they leave.
     *
     * @param array<string, mixed>|null $before  Null, unless an earlier listener supplied one.
     * @param string                    $ability_name
     * @param array<string, mixed>      $input
     */
    // @mago-expect analysis:mixed-assignment -- Filter output is validated against the snapshot shape below.
    $supplied = apply_filters('wppilot_capture_before_image', null, $ability_name, $input);

    return is_array($supplied) && is_string($supplied['type'] ?? null) && $supplied['type'] !== ''
        ? $supplied
        : null;
}

/**
 * Before-images for the WordPress-core abilities.
 *
 * Split from wppilot_capture_before_image() so the core surface can grow without
 * pushing that function past its complexity budget. Anything whose effect lives
 * on a post row — terms, featured image, attachment parent, revision restore —
 * reuses wppilot_snapshot_post(), which already captures post fields, meta, and
 * every taxonomy assignment together.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|null
 */
// @mago-expect lint:cyclomatic-complexity -- One explicit branch per ability is the safety contract.
function wppilot_capture_core_before_image(string $ability_name, array $input): ?array
{
    if (in_array($ability_name, [
        'wppilot/assign-terms',
        'wppilot/set-featured-image',
        'wppilot/remove-featured-image',
        'wppilot/restore-post',
    ], strict: true)) {
        return wppilot_snapshot_post((int) ($input['post_id'] ?? 0));
    }
    if (in_array($ability_name, ['wppilot/attach-media', 'wppilot/detach-media'], strict: true)) {
        return wppilot_snapshot_post((int) ($input['attachment_id'] ?? 0));
    }
    if ($ability_name === 'wppilot/restore-revision') {
        // The write lands on the parent post, so that is what has to be captured.
        // wp_get_post_revision() takes its first parameter by reference, so the id
        // has to reach it as a variable: handing it the cast expression directly
        // raised "Only variables should be passed by reference" on every capture.
        $revision_id = (int) ($input['revision_id'] ?? 0);
        $revision = wp_get_post_revision($revision_id);
        return $revision instanceof WP_Post ? wppilot_snapshot_post((int) $revision->post_parent) : null;
    }
    if (in_array($ability_name, ['wppilot/update-term', 'wppilot/delete-term'], strict: true)) {
        return wppilot_snapshot_term((int) ($input['term_id'] ?? 0), (string) ($input['taxonomy'] ?? ''));
    }
    if ($ability_name === 'wppilot/update-menu') {
        return wppilot_snapshot_term((int) ($input['menu_id'] ?? 0), 'nav_menu');
    }
    if ($ability_name === 'wppilot/delete-menu') {
        return ['type' => 'menu-delete', 'menu_id' => (int) ($input['menu_id'] ?? 0)];
    }
    if ($ability_name === 'wppilot/create-term' || $ability_name === 'wppilot/create-menu') {
        return ['type' => 'term-create', 'taxonomy' => (string) ($input['taxonomy'] ?? 'nav_menu')];
    }
    if ($ability_name === 'wppilot/create-comment') {
        return ['type' => 'comment-create'];
    }
    if ($ability_name === 'wppilot/update-comment') {
        return wppilot_snapshot_comment((int) ($input['comment_id'] ?? 0));
    }
    if (in_array($ability_name, ['wppilot/moderate-comment', 'wppilot/delete-comment'], strict: true)) {
        // @mago-expect analysis:mixed-assignment -- WordPress returns WP_Comment|null for OBJECT output.
        $comment = get_comment((int) ($input['comment_id'] ?? 0));
        if ($comment instanceof WP_Comment) {
            $values = ['comment_id' => (int) $comment->comment_ID, 'status' => wp_get_comment_status($comment)];
            return [
                'type' => 'comment-status',
                'values' => $values,
                'fingerprint' => wppilot_snapshot_fingerprint($values),
            ];
        }
        return null;
    }
    if ($ability_name === 'wppilot/assign-menu-location') {
        $values = array_map('intval', (array) get_nav_menu_locations());
        return [
            'type' => 'menu-locations',
            'values' => $values,
            'fingerprint' => wppilot_snapshot_fingerprint($values),
        ];
    }
    if ($ability_name === 'wppilot/reorder-menu-items') {
        return wppilot_snapshot_menu_order((int) ($input['menu_id'] ?? 0));
    }
    if (in_array($ability_name, ['wppilot/activate-plugin', 'wppilot/deactivate-plugin'], strict: true)) {
        return wppilot_snapshot_plugin_state((string) ($input['file'] ?? ''));
    }
    if ($ability_name === 'wppilot/switch-theme') {
        $values = ['stylesheet' => get_stylesheet(), 'template' => get_template()];
        return [
            'type' => 'active-theme',
            'values' => $values,
            'fingerprint' => wppilot_snapshot_fingerprint($values),
        ];
    }
    if (in_array($ability_name, [
        'wppilot/install-plugin',
        'wppilot/install-theme',
        'wppilot/update-plugin',
        'wppilot/update-theme',
        'wppilot/delete-plugin',
        'wppilot/delete-theme',
    ], strict: true)) {
        // No before-image is possible — these move files on disk — but the
        // marker still reaches the rollback builder, which then states the real
        // reason instead of the generic "no strategy registered".
        return ['type' => 'extension-files'];
    }
    return null;
}

/**
 * Capture whether a plugin is active, per-site and network-wide.
 *
 * Activation state is the whole before-image: the files are untouched by
 * activate/deactivate, so restoring the flag restores the change.
 *
 * @return array<string, mixed>|null
 */
function wppilot_snapshot_plugin_state(string $file): ?array
{
    if ($file === '') {
        return null;
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $values = [
        'file' => $file,
        'active' => is_plugin_active($file),
        'network_active' => is_plugin_active_for_network($file),
    ];

    return ['type' => 'plugin-state', 'values' => $values, 'fingerprint' => wppilot_snapshot_fingerprint($values)];
}

/**
 * Capture a term's editable fields, scoped to the taxonomy the caller named.
 *
 * @return array<string, mixed>|null
 */
function wppilot_snapshot_term(int $term_id, string $taxonomy): ?array
{
    if ($term_id <= 0 || $taxonomy === '') {
        return null;
    }

    // @mago-expect analysis:mixed-assignment -- get_term returns WP_Term|WP_Error|null.
    $term = get_term($term_id, $taxonomy);
    if (!$term instanceof WP_Term) {
        return null;
    }

    $values = [
        'term_id' => (int) $term->term_id,
        'taxonomy' => (string) $term->taxonomy,
        'name' => (string) $term->name,
        'slug' => (string) $term->slug,
        'description' => (string) $term->description,
        'parent' => (int) $term->parent,
    ];

    return ['type' => 'term', 'values' => $values, 'fingerprint' => wppilot_snapshot_fingerprint($values)];
}

/**
 * Capture a comment's editable fields.
 *
 * The commenter's email and IP are deliberately excluded: the ledger is readable
 * by any GoBuildWP administrator, and restoring a comment's text never needs them.
 *
 * @return array<string, mixed>|null
 */
function wppilot_snapshot_comment(int $comment_id): ?array
{
    if ($comment_id <= 0) {
        return null;
    }

    // @mago-expect analysis:mixed-assignment -- WordPress returns WP_Comment|null for OBJECT output.
    $comment = get_comment($comment_id);
    if (!$comment instanceof WP_Comment) {
        return null;
    }

    $values = [
        'comment_id' => (int) $comment->comment_ID,
        'content' => (string) $comment->comment_content,
        'author' => (string) $comment->comment_author,
    ];

    return ['type' => 'comment', 'values' => $values, 'fingerprint' => wppilot_snapshot_fingerprint($values)];
}

/**
 * Capture a menu's item order and nesting so a reorder can be undone exactly.
 *
 * @return array<string, mixed>|null
 */
function wppilot_snapshot_menu_order(int $menu_id): ?array
{
    if ($menu_id <= 0) {
        return null;
    }

    $items = wp_get_nav_menu_items($menu_id);
    if (!is_array($items)) {
        return null;
    }

    $values = [];
    foreach ($items as $item) {
        $values[] = [
            'item_id' => (int) $item->ID,
            'position' => (int) $item->menu_order,
            'parent_id' => (int) $item->menu_item_parent,
        ];
    }

    return [
        'type' => 'menu-order',
        'menu_id' => $menu_id,
        'values' => $values,
        'fingerprint' => wppilot_snapshot_fingerprint($values),
    ];
}

/**
 * Product meta WooCommerce keeps current outside any edit: an order adds to `total_sales`, a
 * review moves the rating counts. A before-image must leave them out, or undoing a price edit a
 * week later writes the old sales count back over every order placed since. Listed in
 * `excluded_meta_keys` instead, so the restore neither deletes nor rewrites them and its own check
 * does not read the live count as drift. GoBuildWP Pro hides the same keys during its rollbacks.
 */
function wppilot_post_meta_is_live_counter(string $post_type, string $key): bool
{
    return ($post_type === 'product' || $post_type === 'product_variation')
        && in_array($key, ['total_sales', '_wc_review_count', '_wc_average_rating', '_wc_rating_count'], strict: true);
}

/**
 * @param bool $bounded False skips the size cap. Only for a fingerprint that is compared and then
 *                      dropped (the session conflict check); nothing unbounded is ever stored.
 * @return array<string, mixed>|null
 */
function wppilot_snapshot_post(int $post_id, bool $bounded = true): ?array
{
    // @mago-expect analysis:mixed-assignment -- ARRAY_A is validated immediately below.
    $post = get_post($post_id, output: 'ARRAY_A');
    if (!is_array($post)) {
        return null;
    }
    // @mago-expect analysis:mixed-assignment -- Full post meta is normalized before iteration.
    $all_meta_value = get_post_meta($post_id);
    $all_meta = is_array($all_meta_value) ? $all_meta_value : [];
    $meta = [];
    $excluded_meta_keys = [];
    // @mago-expect analysis:mixed-assignment -- WordPress post meta values are intentionally opaque.
    foreach ($all_meta as $key => $values) {
        if (wppilot_change_key_is_sensitive((string) $key) || wppilot_post_meta_is_live_counter((string) $post['post_type'], (string) $key)) {
            $excluded_meta_keys[] = (string) $key;
            continue;
        }
        // get_post_meta() in its whole-post form hands back what is in the
        // database, still serialized — unlike the single-key form, which
        // unserializes for you. Restoring those raw strings through
        // add_post_meta() serializes them a second time, so an array comes back
        // as the literal string 'a:2:{...}' and every reader of that meta then
        // sees a string where its own data used to be. Any post carrying
        // serialized meta — an Elementor document, an ACF repeater, page
        // settings — was silently corrupted by its own rollback.
        // @mago-expect analysis:mixed-assignment -- Meta values stay opaque; only the serialization layer is removed.
        $meta[(string) $key] = is_array($values)
            ? array_map(static fn(mixed $value): mixed => maybe_unserialize($value), $values)
            : $values;
    }
    $terms = [];
    $taxonomies = get_object_taxonomies((string) $post['post_type'], output: 'names');
    foreach ($taxonomies as $taxonomy) {
        // @mago-expect analysis:mixed-assignment -- fields=ids may return a list or WP_Error and is checked below.
        $ids = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (is_array($ids)) {
            $terms[$taxonomy] = array_map('intval', $ids);
        }
    }
    $snapshot = [
        'type' => 'post',
        'post' => $post,
        'meta' => $meta,
        'excluded_meta_keys' => $excluded_meta_keys,
        'terms' => $terms,
    ];
    $encoded = $bounded ? wp_json_encode($snapshot) : '';
    if ($bounded && (!is_string($encoded) || strlen($encoded) > WPPILOT_CHANGE_SNAPSHOT_MAX_BYTES)) {
        return ['type' => 'oversize', 'post_id' => $post_id, 'bytes' => is_string($encoded) ? strlen($encoded) : 0];
    }
    $snapshot['fingerprint'] = wppilot_post_snapshot_fingerprint($snapshot);
    return $snapshot;
}

/** @param array<string, mixed>|null $before @return array<string, mixed> */
// @mago-expect lint:cyclomatic-complexity -- Central rollback routing must cover each mutation explicitly.
// @mago-expect lint:halstead -- Explicit non-reversible reasons are part of the safety contract.
function wppilot_build_rollback_payload(string $ability_name, ?array $before, mixed $result): array
{
    if ($before === null || ($before['type'] ?? null) === 'oversize') {
        return [
            'reversible' => false,
            'reason' => $before === null
                ? 'No supported before-image.'
                : 'Before-image exceeded the safe storage limit.',
        ];
    }
    if ($ability_name === 'wppilot/delete-media') {
        return ['reversible' => false, 'reason' => 'The attachment file was permanently deleted.'];
    }
    if ($ability_name === 'wppilot/delete-menu-item') {
        return ['reversible' => false, 'reason' => 'The menu item was permanently deleted.'];
    }
    // Checked here, not with the other core deletions below: its before-image is a
    // comment-status snapshot, which the generic branch would file as a reversible
    // status restore for a comment that no longer exists.
    if ($ability_name === 'wppilot/delete-comment') {
        return ['reversible' => false, 'reason' => 'The comment was permanently deleted.'];
    }
    if (in_array(
        $ability_name,
        ['wppilot/woocommerce-delete-coupon', 'wppilot/woocommerce-create-refund'],
        strict: true,
    )) {
        return [
            'reversible' => false,
            'reason' => 'This commerce operation has external or permanent effects and is not automatically reversible.',
        ];
    }
    if (
        $ability_name === 'wppilot/woocommerce-delete-review'
        && is_array($result)
        && ($result['permanent'] ?? false) === true
    ) {
        return ['reversible' => false, 'reason' => 'The review was permanently deleted.'];
    }
    if ($ability_name === 'wppilot/delete-post' && is_array($result) && ($result['result'] ?? null) === 'deleted') {
        return ['reversible' => false, 'reason' => 'The post was permanently deleted.'];
    }
    if (($before['type'] ?? null) === 'post-create') {
        $post_id = is_array($result) ? (int) ($result['post_id'] ?? 0) : 0;
        return (
            $post_id > 0
                ? ['reversible' => true, 'type' => 'delete-created-post', 'post_id' => $post_id]
                : ['reversible' => false, 'reason' => 'Created post ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'menu-create') {
        $item_id = is_array($result) ? (int) ($result['id'] ?? 0) : 0;
        return (
            $item_id > 0
                ? ['reversible' => true, 'type' => 'delete-created-post', 'post_id' => $item_id]
                : ['reversible' => false, 'reason' => 'Created menu item ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'coupon-create') {
        $coupon_id = is_array($result) ? (int) ($result['id'] ?? 0) : 0;
        return (
            $coupon_id > 0
                ? ['reversible' => true, 'type' => 'delete-created-post', 'post_id' => $coupon_id]
                : ['reversible' => false, 'reason' => 'Created coupon ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'order-note-create') {
        $note_id = is_array($result) ? (int) ($result['note_id'] ?? 0) : 0;
        return (
            $note_id > 0
                ? ['reversible' => true, 'type' => 'delete-created-comment', 'comment_id' => $note_id]
                : ['reversible' => false, 'reason' => 'Created order note ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'post') {
        return ['reversible' => true, 'type' => 'restore-post', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'settings') {
        return ['reversible' => true, 'type' => 'restore-settings', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'order-status') {
        return ['reversible' => true, 'type' => 'restore-order-status', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'comment-status') {
        return ['reversible' => true, 'type' => 'restore-comment-status', 'snapshot' => $before];
    }
    return wppilot_build_core_rollback_payload($ability_name, $before, $result);
}

/**
 * Rollback strategies for the WordPress-core abilities.
 *
 * Permanent deletions are declared non-reversible explicitly rather than falling
 * through to the generic "no strategy registered" message, so the ledger states
 * the actual reason a change cannot be undone.
 *
 * @param array<string, mixed> $before
 * @return array<string, mixed>
 */
// @mago-expect lint:cyclomatic-complexity -- Explicit non-reversible reasons are part of the safety contract.
function wppilot_build_core_rollback_payload(string $ability_name, array $before, mixed $result): array
{
    if ($ability_name === 'wppilot/delete-term') {
        return [
            'reversible' => false,
            'reason' => 'WordPress has no trash for terms, so the term was permanently deleted.',
        ];
    }
    if ($ability_name === 'wppilot/delete-menu') {
        return [
            'reversible' => false,
            'reason' => 'The menu and all of its items were permanently deleted.',
        ];
    }
    if (($before['type'] ?? null) === 'term-create') {
        $term_id = is_array($result) ? (int) ($result['term_id'] ?? $result['menu_id'] ?? 0) : 0;
        return (
            $term_id > 0
                ? [
                    'reversible' => true,
                    'type' => 'delete-created-term',
                    'term_id' => $term_id,
                    'taxonomy' => (string) ($before['taxonomy'] ?? ''),
                ]
                : ['reversible' => false, 'reason' => 'Created term ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'comment-create') {
        $comment_id = is_array($result) ? (int) ($result['comment_id'] ?? 0) : 0;
        return (
            $comment_id > 0
                ? ['reversible' => true, 'type' => 'delete-created-comment', 'comment_id' => $comment_id]
                : ['reversible' => false, 'reason' => 'Created comment ID was not returned.']
        );
    }
    if (($before['type'] ?? null) === 'term') {
        return ['reversible' => true, 'type' => 'restore-term', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'comment') {
        return ['reversible' => true, 'type' => 'restore-comment', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'menu-locations') {
        return ['reversible' => true, 'type' => 'restore-menu-locations', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'menu-order') {
        return ['reversible' => true, 'type' => 'restore-menu-order', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'plugin-state') {
        return ['reversible' => true, 'type' => 'restore-plugin-state', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'active-theme') {
        return ['reversible' => true, 'type' => 'restore-active-theme', 'snapshot' => $before];
    }
    if (($before['type'] ?? null) === 'extension-files') {
        return ['reversible' => false, 'reason' => wppilot_extension_files_rollback_reason($ability_name)];
    }
    $strategy = wppilot_get_rollback_strategy((string) ($before['type'] ?? ''));
    if ($strategy !== null) {
        return wppilot_build_registered_rollback_payload($strategy, $ability_name, $before, $result);
    }
    return ['reversible' => false, 'reason' => 'No rollback strategy is registered for this change.'];
}

/**
 * Register a restore path for a before-image type the built-ins do not know.
 *
 * The built-in strategies are a closed `match`, so a module that captured its own before-image
 * (through `wppilot_capture_before_image`) could have it recorded but never undone. A registered
 * type closes that: a before-image whose `type` names it becomes a reversible ledger row, and
 * rollback-change hands the row's payload back to `$restore`.
 *
 * `$type` must contain a `/` (`kits/post-partial`, `woo-subscriptions/state`), which keeps it
 * apart from every built-in name, present and future; a built-in can never be replaced.
 *
 * `$restore(array $payload, array $entry)` returns the restore details, which must include
 * `'verified' => true` once it has re-read the target and found it matching — or a WP_Error. The
 * ledger refuses to mark anything rolled back on less.
 *
 * `$build(array $before, mixed $result, string $ability_name)` is optional and turns the
 * before-image into the stored payload, for a strategy that needs something from the result
 * (the ID of what a create call made). It may return `['reversible' => false, 'reason' => …]`.
 * Without it the payload is the before-image itself, under `snapshot`.
 */
function wppilot_register_rollback_strategy(string $type, callable $restore, ?callable $build = null): bool
{
    if (preg_match('#^[a-z0-9-]+(?:/[a-z0-9-]+)+$#', $type) !== 1) {
        _doing_it_wrong(
            __FUNCTION__,
            esc_html(sprintf('Rollback strategy type "%s" must be lowercase and contain a "/".', $type)),
            '1.14.0',
        );
        return false;
    }

    wppilot_rollback_strategy_registry($type, ['restore' => $restore, 'build' => $build]);
    return true;
}

/**
 * @return array{restore: callable, build: callable|null}|null
 */
function wppilot_get_rollback_strategy(string $type): ?array
{
    return $type === '' || !str_contains($type, '/') ? null : wppilot_rollback_strategy_registry($type);
}

/**
 * @param array{restore: callable, build: callable|null}|null $set
 * @return array{restore: callable, build: callable|null}|null
 */
function wppilot_rollback_strategy_registry(string $type, ?array $set = null): ?array
{
    /** @var array<string, array{restore: callable, build: callable|null}> $strategies */
    static $strategies = [];
    if ($set !== null) {
        $strategies[$type] = $set;
    }
    return $strategies[$type] ?? null;
}

/**
 * @param array{restore: callable, build: callable|null} $strategy
 * @param array<string, mixed> $before
 * @return array<string, mixed>
 */
function wppilot_build_registered_rollback_payload(
    array $strategy,
    string $ability_name,
    array $before,
    mixed $result,
): array {
    $type = (string) $before['type'];
    if ($strategy['build'] === null) {
        return ['reversible' => true, 'type' => $type, 'snapshot' => $before];
    }

    try {
        // @mago-expect analysis:mixed-assignment -- Extension output is validated below.
        $payload = ($strategy['build'])($before, $result, $ability_name);
    } catch (Throwable $error) {
        return ['reversible' => false, 'reason' => 'The rollback strategy could not prepare this change: ' . $error->getMessage()];
    }
    if (!is_array($payload)) {
        return ['reversible' => false, 'reason' => 'The rollback strategy did not describe how to undo this change.'];
    }
    $payload = wppilot_string_keyed_array($payload);
    if (($payload['reversible'] ?? true) === false) {
        return ['reversible' => false, 'reason' => (string) ($payload['reason'] ?? 'This change is not reversible.')];
    }
    // The type is the strategy's own; a build callback cannot route the row to another restore.
    return array_merge($payload, ['reversible' => true, 'type' => $type]);
}

/**
 * Run a registered strategy's restore for a ledger row.
 *
 * @param array<string, mixed> $rollback
 * @param array<string, mixed> $entry
 * @return array<string, mixed>|WP_Error
 */
function wppilot_run_registered_rollback(array $rollback, array $entry): array|WP_Error
{
    $strategy = wppilot_get_rollback_strategy((string) ($rollback['type'] ?? ''));
    if ($strategy === null) {
        return new WP_Error('wppilot_rollback_unknown', __(
            'Unknown rollback strategy. The plugin that recorded this change may be inactive.',
            domain: 'wppilot',
        ));
    }
    // @mago-expect analysis:mixed-assignment -- Extension output is validated below.
    $result = ($strategy['restore'])($rollback, $entry);
    if ($result instanceof WP_Error) {
        return $result;
    }
    return is_array($result)
        ? wppilot_string_keyed_array($result)
        : new WP_Error('wppilot_rollback_failed', __('The rollback strategy returned no result.', domain: 'wppilot'));
}

/**
 * Why a plugin/theme file operation cannot be undone from the ledger.
 *
 * Each reason names the manual path back, because "not reversible" alone tells
 * an operator nothing about what to do next.
 */
function wppilot_extension_files_rollback_reason(string $ability_name): string
{
    return match ($ability_name) {
        'wppilot/install-plugin' => 'Files were written to the server. Remove the plugin with wppilot/delete-plugin.',
        'wppilot/install-theme' => 'Files were written to the server. Remove the theme with wppilot/delete-theme.',
        'wppilot/update-plugin', 'wppilot/update-theme' => 'The previous version was overwritten and is not retained. Reinstall the earlier release from its own source.',
        'wppilot/delete-plugin' => 'The plugin files were permanently deleted. Reinstall it with wppilot/install-plugin.',
        'wppilot/delete-theme' => 'The theme files were permanently deleted. Reinstall it with wppilot/install-theme.',
        default => 'This operation changed files on the server and is not automatically reversible.',
    };
}

/**
 * Delete a term created by a rolled-back change.
 *
 * @return array<string, mixed>|WP_Error
 */
function wppilot_rollback_created_term(int $term_id, string $taxonomy): array|WP_Error
{
    if ($term_id <= 0 || $taxonomy === '') {
        return new WP_Error('wppilot_rollback_invalid_term', __('Created term is unknown.', domain: 'wppilot'));
    }
    // @mago-expect analysis:mixed-assignment -- get_term returns WP_Term|WP_Error|null.
    $term = get_term($term_id, $taxonomy);
    if (!$term instanceof WP_Term) {
        return ['result' => 'already-absent', 'term_id' => $term_id, 'verified' => true];
    }

    $deleted = wp_delete_term($term_id, $taxonomy);
    if (is_wp_error($deleted)) {
        return $deleted;
    }
    if ($deleted !== true) {
        return new WP_Error('wppilot_rollback_term_failed', __('The term could not be deleted.', domain: 'wppilot'));
    }

    return [
        'result' => 'deleted',
        'term_id' => $term_id,
        'taxonomy' => $taxonomy,
        'verified' => !get_term($term_id, $taxonomy) instanceof WP_Term,
    ];
}

/**
 * Restore a term's captured fields, verifying the write landed.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|WP_Error
 */
function wppilot_restore_term_snapshot(array $snapshot): array|WP_Error
{
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    $term_id = (int) ($values['term_id'] ?? 0);
    $taxonomy = (string) ($values['taxonomy'] ?? '');
    if ($term_id <= 0 || $taxonomy === '') {
        return new WP_Error('wppilot_rollback_invalid_term', __('Term snapshot is incomplete.', domain: 'wppilot'));
    }

    // wp_update_term() unslashes name and description; the snapshot is raw.
    $updated = wp_update_term($term_id, $taxonomy, [
        'name' => wp_slash((string) ($values['name'] ?? '')),
        'slug' => (string) ($values['slug'] ?? ''),
        'description' => wp_slash((string) ($values['description'] ?? '')),
        'parent' => (int) ($values['parent'] ?? 0),
    ]);
    if (is_wp_error($updated)) {
        return $updated;
    }

    $observed = wppilot_snapshot_term($term_id, $taxonomy);
    if ($observed === null) {
        return new WP_Error(
            'wppilot_rollback_unverified',
            __('The term could not be re-read after the restore.', domain: 'wppilot'),
        );
    }

    // The caller refuses to mark a change rolled back without `verified`, so a
    // restore that never reported it wrote the old state and then said it had
    // failed.
    return [
        'result' => 'restored',
        'term_id' => $term_id,
        'fingerprint' => $observed['fingerprint'] ?? '',
        'verified' => wppilot_snapshot_matches($snapshot, $observed),
    ];
}

/**
 * Restore a comment's captured content.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|WP_Error
 */
function wppilot_restore_comment_snapshot(array $snapshot): array|WP_Error
{
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    $comment_id = (int) ($values['comment_id'] ?? 0);
    if ($comment_id <= 0) {
        return new WP_Error(
            'wppilot_rollback_invalid_comment',
            __('Comment snapshot is incomplete.', domain: 'wppilot'),
        );
    }

    // wp_update_comment() unslashes what it is given; the snapshot is raw.
    $updated = wp_update_comment([
        'comment_ID' => $comment_id,
        'comment_content' => wp_slash((string) ($values['content'] ?? '')),
        'comment_author' => wp_slash((string) ($values['author'] ?? '')),
    ], wp_error: true);
    if (is_wp_error($updated)) {
        return $updated;
    }

    return [
        'result' => 'restored',
        'comment_id' => $comment_id,
        'verified' => wppilot_snapshot_matches($snapshot, wppilot_snapshot_comment($comment_id)),
    ];
}

/**
 * Restore the theme's navigation-location assignments.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>
 */
function wppilot_restore_menu_locations_snapshot(array $snapshot): array
{
    $values = array_map('intval', wppilot_string_keyed_array($snapshot['values'] ?? null));
    set_theme_mod('nav_menu_locations', $values);

    $observed = array_map('intval', wppilot_string_keyed_array(get_theme_mod('nav_menu_locations', [])));
    ksort($values);
    ksort($observed);

    return ['result' => 'restored', 'locations' => count($values), 'verified' => $observed === $values];
}

/**
 * Put a plugin back into its captured activation state.
 *
 * Reactivation runs the plugin's activation hooks again, which is what the
 * original activation did too — the alternative, a silent flag flip, leaves a
 * plugin marked active with none of its setup performed.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|WP_Error
 */
function wppilot_restore_plugin_state_snapshot(array $snapshot): array|WP_Error
{
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    $file = (string) ($values['file'] ?? '');
    if ($file === '') {
        return new WP_Error('wppilot_rollback_invalid_plugin', __(
            'The captured plugin file is missing from the change record.',
            domain: 'wppilot',
        ));
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (!array_key_exists($file, get_plugins())) {
        return new WP_Error('wppilot_rollback_target_missing', __(
            'The plugin is no longer installed, so its activation state cannot be restored.',
            domain: 'wppilot',
        ));
    }

    $network_active = ($values['network_active'] ?? false) === true;
    $was_active = ($values['active'] ?? false) === true || $network_active;

    if ($was_active) {
        $activated = activate_plugin($file, redirect: '', network_wide: $network_active, silent: false);
        if (is_wp_error($activated)) {
            return $activated;
        }
    } else {
        deactivate_plugins([$file], silent: false, network_wide: $network_active ? true : null);
    }

    $now_active = is_plugin_active($file) || is_plugin_active_for_network($file);

    return [
        'file' => $file,
        'active' => $now_active,
        'verified' => $now_active === $was_active,
    ];
}

/**
 * Switch back to the theme that was active before the change.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|WP_Error
 */
function wppilot_restore_active_theme_snapshot(array $snapshot): array|WP_Error
{
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    $stylesheet = (string) ($values['stylesheet'] ?? '');
    if ($stylesheet === '') {
        return new WP_Error('wppilot_rollback_invalid_theme', __(
            'The captured theme is missing from the change record.',
            domain: 'wppilot',
        ));
    }
    if (!wp_get_theme($stylesheet)->exists()) {
        return new WP_Error('wppilot_rollback_target_missing', __(
            'The previously active theme is no longer installed, so it cannot be restored.',
            domain: 'wppilot',
        ));
    }

    switch_theme($stylesheet);

    return [
        'stylesheet' => $stylesheet,
        'active' => get_stylesheet(),
        'verified' => get_stylesheet() === $stylesheet,
    ];
}

/**
 * Restore a menu's captured item order and nesting.
 *
 * Reports a partial failure honestly rather than claiming a clean rollback: a
 * half-restored menu order is worse than a reported one, because the operator
 * would otherwise believe the original order is back.
 *
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>|WP_Error
 */
function wppilot_restore_menu_order_snapshot(array $snapshot): array|WP_Error
{
    $menu_id = (int) ($snapshot['menu_id'] ?? 0);
    if ($menu_id <= 0 || wp_get_nav_menu_object($menu_id) === false) {
        return new WP_Error(
            'wppilot_rollback_menu_missing',
            __('The menu no longer exists, so its order cannot be restored.', domain: 'wppilot'),
        );
    }

    $restored = 0;
    /** @var mixed $entry */
    foreach (wppilot_string_list_of_arrays($snapshot['values'] ?? null) as $entry) {
        $result = wp_update_nav_menu_item($menu_id, (int) ($entry['item_id'] ?? 0), [
            'menu-item-position' => (int) ($entry['position'] ?? 0),
            'menu-item-parent-id' => (int) ($entry['parent_id'] ?? 0),
        ]);
        if (is_wp_error($result)) {
            return new WP_Error('wppilot_rollback_partial', sprintf(
                /* translators: 1: number of items restored, 2: the underlying error */
                __(
                    'The menu order was only partially restored: %1$d items were moved before the failure (%2$s). Re-read the menu before relying on its order.',
                    domain: 'wppilot',
                ),
                $restored,
                $result->get_error_message(),
            ));
        }
        ++$restored;
    }

    return [
        'result' => 'restored',
        'menu_id' => $menu_id,
        'items' => $restored,
        'verified' => wppilot_snapshot_matches($snapshot, wppilot_snapshot_menu_order($menu_id)),
    ];
}

/**
 * Whether a fresh snapshot of an object matches the one a rollback restored.
 *
 * @param array<string, mixed> $snapshot
 * @param array<string, mixed>|null $observed
 */
function wppilot_snapshot_matches(array $snapshot, ?array $observed): bool
{
    $expected = (string) ($snapshot['fingerprint'] ?? '');
    $actual = is_array($observed) ? (string) ($observed['fingerprint'] ?? '') : '';

    return $expected !== '' && hash_equals($expected, $actual);
}

/**
 * Normalize a stored list of associative arrays.
 *
 * @return list<array<string, mixed>>
 */
function wppilot_string_list_of_arrays(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $rows = [];
    /** @var mixed $row */
    foreach ($value as $row) {
        if (is_array($row)) {
            $rows[] = wppilot_string_keyed_array($row);
        }
    }

    return $rows;
}

/** @return array<string, mixed>|WP_Error */
// @mago-expect lint:cyclomatic-complexity -- The rollback transaction validates every terminal state.
function wppilot_rollback_change(string $id): array|WP_Error
{
    $entry = wppilot_get_change($id);
    if ($entry === null) {
        return new WP_Error('wppilot_change_not_found', __('Change record not found.', domain: 'wppilot'));
    }
    if (($entry['rolled_back'] ?? false) === true) {
        return new WP_Error('wppilot_change_already_rolled_back', __(
            'This change was already rolled back.',
            domain: 'wppilot',
        ));
    }
    $rollback = is_array($entry['rollback'] ?? null) ? $entry['rollback'] : [];
    if (($rollback['reversible'] ?? false) !== true) {
        return new WP_Error(
            'wppilot_change_not_reversible',
            (string) ($rollback['reason'] ?? __('This change is not reversible.', domain: 'wppilot')),
        );
    }

    // The state this undo is about to replace, kept so the undo can itself be undone (redo-session).
    // Taken before the restore, which is the only moment it still exists.
    $redo = function_exists('wppilot_change_redo_image') ? wppilot_change_redo_image($rollback) : null;
    $result = wppilot_change_run_restore($rollback, $entry);
    if ($result instanceof WP_Error) {
        return $result;
    }
    if (($result['verified'] ?? false) !== true) {
        $mismatched = is_array($result['mismatched'] ?? null) ? array_slice($result['mismatched'], 0, 12) : [];
        return new WP_Error(
            'wppilot_rollback_unverified',
            __('Rollback ran but the observed state did not match the before-image.', domain: 'wppilot')
                . ($mismatched !== [] ? ' ' . sprintf(
                    /* translators: %s: comma-separated field names */
                    __('Still different: %s.', domain: 'wppilot'),
                    implode(', ', array_map('strval', $mismatched)),
                ) : ''),
            $result,
        );
    }
    $entry['rolled_back'] = true;
    $entry['rolled_back_at'] = gmdate('c');
    $entry['rollback_result'] = $result;
    if ($redo !== null) {
        $entry['redo'] = $redo;
        // What the undo left behind: a redo proceeds only while the target still looks like this.
        $undone = wppilot_change_state_digest(wppilot_change_current_state($rollback, bounded: false));
        if ($undone !== null) {
            $entry['undone_digest'] = $undone;
        }
    }
    wppilot_replace_change($id, $entry);
    return ['change_id' => $id, 'rolled_back' => true, 'verified' => true, 'details' => $result];
}

/**
 * Run the restore a rollback payload describes, with the ledger suppressed, and hand back what it
 * reported. Undo and redo both come through here, so a restore strategy is written once.
 *
 * @param array<string, mixed> $rollback
 * @param array<string, mixed> $entry
 * @return array<string, mixed>|WP_Error
 */
function wppilot_change_run_restore(array $rollback, array $entry): array|WP_Error
{
    wppilot_change_is_suppressed(true);
    try {
        $result = match ($rollback['type'] ?? '') {
            'delete-created-post' => wppilot_rollback_created_post((int) ($rollback['post_id'] ?? 0)),
            'restore-post' => wppilot_restore_post_snapshot(wppilot_string_keyed_array($rollback['snapshot'] ?? null)),
            'restore-settings' => wppilot_restore_settings_snapshot(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-order-status' => wppilot_restore_order_status(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-comment-status' => wppilot_restore_comment_status(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'delete-created-comment' => wppilot_rollback_created_comment((int) ($rollback['comment_id'] ?? 0)),
            'delete-created-term' => wppilot_rollback_created_term(
                (int) ($rollback['term_id'] ?? 0),
                (string) ($rollback['taxonomy'] ?? ''),
            ),
            'restore-term' => wppilot_restore_term_snapshot(wppilot_string_keyed_array($rollback['snapshot'] ?? null)),
            'restore-comment' => wppilot_restore_comment_snapshot(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-menu-locations' => wppilot_restore_menu_locations_snapshot(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-menu-order' => wppilot_restore_menu_order_snapshot(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-plugin-state' => wppilot_restore_plugin_state_snapshot(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            'restore-active-theme' => wppilot_restore_active_theme_snapshot(wppilot_string_keyed_array(
                $rollback['snapshot'] ?? null,
            )),
            default => wppilot_run_registered_rollback($rollback, $entry),
        };
    } catch (Throwable $error) {
        $result = new WP_Error('wppilot_rollback_failed', $error->getMessage());
    } finally {
        wppilot_change_is_suppressed(false);
    }

    return $result;
}

/** @return array<string, mixed>|WP_Error */
function wppilot_rollback_created_post(int $post_id): array|WP_Error
{
    if ($post_id <= 0 || !get_post($post_id)) {
        return new WP_Error('wppilot_rollback_target_missing', __('Created post no longer exists.', domain: 'wppilot'));
    }
    if (wp_trash_post($post_id) === false) {
        return new WP_Error('wppilot_rollback_delete_failed', __(
            'Could not move the created post to trash.',
            domain: 'wppilot',
        ));
    }
    return [
        'post_id' => $post_id,
        'status' => get_post_status($post_id),
        'verified' => get_post_status($post_id) === 'trash',
    ];
}

/** @param array<string, mixed> $snapshot @return array<string, mixed>|WP_Error */
// @mago-expect lint:cyclomatic-complexity -- Restoring posts requires independent post, meta, and taxonomy steps.
function wppilot_restore_post_snapshot(array $snapshot): array|WP_Error
{
    $post = wppilot_string_keyed_array($snapshot['post'] ?? null);
    $post_id = (int) ($post['ID'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id)) {
        return new WP_Error('wppilot_rollback_target_missing', __(
            'The post required for rollback no longer exists.',
            domain: 'wppilot',
        ));
    }
    $allowed = [
        'ID',
        'post_author',
        'post_date',
        'post_date_gmt',
        'post_content',
        'post_title',
        'post_excerpt',
        'post_status',
        'comment_status',
        'ping_status',
        'post_password',
        'post_name',
        'post_parent',
        'menu_order',
        'post_type',
        'post_mime_type',
    ];
    $postarr = array_intersect_key($post, array_fill_keys(keys: $allowed, value: true));
    foreach (['post_content', 'post_title', 'post_excerpt', 'post_name'] as $key) {
        if (array_key_exists($key, $postarr)) {
            $postarr[$key] = wp_slash((string) $postarr[$key]);
        }
    }
    // A draft that was never scheduled has a floating date (post_date_gmt of zeroes), and
    // wp_update_post() moves a floating date to "now" unless edit_date says the date is
    // deliberate. Without it every undo of a draft change more than a second old restored the
    // content, then failed its own verification on post_date.
    if (array_key_exists('post_date', $postarr)) {
        $postarr['edit_date'] = true;
    }
    // @mago-expect analysis:possibly-invalid-argument -- Keys are restricted to WP's post update allowlist above.
    $updated = wp_update_post($postarr, wp_error: true);
    if (is_wp_error($updated)) {
        return $updated;
    }
    $excluded_meta_keys = wppilot_string_list($snapshot['excluded_meta_keys'] ?? []);
    // @mago-expect analysis:mixed-assignment -- Full post meta is normalized before reading its keys.
    $current_meta_value = get_post_meta($post_id);
    $current_meta = is_array($current_meta_value) ? $current_meta_value : [];
    foreach (array_keys($current_meta) as $key) {
        if (
            in_array((string) $key, $excluded_meta_keys, strict: true)
            || wppilot_post_meta_is_live_counter((string) ($post['post_type'] ?? ''), (string) $key)
        ) {
            continue;
        }
        delete_post_meta($post_id, (string) $key);
    }
    // @mago-expect analysis:mixed-assignment -- Snapshot values are validated at each nesting level.
    foreach (wppilot_string_keyed_array($snapshot['meta'] ?? []) as $key => $values) {
        // A before-image recorded before the counters were left out still carries them; the live
        // row was kept above, so adding the stale value would leave the product with two.
        if (wppilot_post_meta_is_live_counter((string) ($post['post_type'] ?? ''), (string) $key)) {
            continue;
        }
        // @mago-expect analysis:mixed-assignment -- Individual post-meta values are intentionally opaque.
        foreach (is_array($values) ? $values : [] as $value) {
            // add_post_meta() unslashes what it is given, as if it came from a
            // form. Snapshot values are raw, so without this every backslash in
            // builder JSON (Elementor, Bricks, Breakdance, Oxygen) was stripped
            // and the page the rollback restored could no longer be decoded.
            add_post_meta($post_id, $key, wp_slash($value));
        }
    }
    // @mago-expect analysis:mixed-assignment -- Term lists are normalized to integers below.
    foreach (wppilot_string_keyed_array($snapshot['terms'] ?? []) as $taxonomy => $term_ids) {
        wp_set_object_terms(
            $post_id,
            array_map('intval', is_array($term_ids) ? $term_ids : []),
            $taxonomy,
            append: false,
        );
    }
    // Both sides use the current, order-independent fingerprint: rows recorded before 1.15.0 carry
    // one that depended on meta order, so it is recomputed from the stored snapshot itself.
    $observed = wppilot_snapshot_post($post_id);
    $expected = wppilot_post_snapshot_fingerprint($snapshot);
    $actual = is_array($observed) && ($observed['type'] ?? '') === 'post' ? wppilot_post_snapshot_fingerprint($observed) : '';
    $verified = $expected !== '' && hash_equals($expected, $actual);
    $result = [
        'post_id' => $post_id,
        'expected_fingerprint' => $expected,
        'observed_fingerprint' => $actual,
        'verified' => $verified,
    ];
    if (!$verified && is_array($observed)) {
        // Which fields still differ, by name only: "did not match" alone gives a person nothing to
        // look at, and values would copy site content into the change log.
        $result['mismatched'] = wppilot_post_snapshot_mismatch($snapshot, $observed);
    }
    return $result;
}

/**
 * The post fields, meta keys and taxonomies that differ between two post snapshots.
 *
 * @param array<string, mixed> $expected
 * @param array<string, mixed> $observed
 * @return list<string>
 */
function wppilot_post_snapshot_mismatch(array $expected, array $observed): array
{
    $names = [];
    foreach (['post', 'meta', 'terms'] as $part) {
        $a = is_array($expected[$part] ?? null) ? $expected[$part] : [];
        $b = is_array($observed[$part] ?? null) ? $observed[$part] : [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
            if ($part === 'post' && in_array($key, WPPILOT_VOLATILE_POST_FIELDS, strict: true)) {
                continue;
            }
            $left = $a[$key] ?? null;
            $right = $b[$key] ?? null;
            if ($part === 'terms' && is_array($left) && is_array($right)) {
                sort($left);
                sort($right);
            }
            if (wp_json_encode($left) !== wp_json_encode($right)) {
                $names[] = $part . '.' . $key;
            }
        }
    }
    return $names;
}

/** @param array<string, mixed> $snapshot @return array<string, mixed> */
function wppilot_restore_settings_snapshot(array $snapshot): array
{
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    // @mago-expect analysis:mixed-assignment -- Option snapshots preserve their original value types.
    foreach ($values as $key => $value) {
        update_option((string) $key, $value);
    }
    $observed = [];
    foreach (array_keys($values) as $key) {
        // @mago-expect analysis:mixed-assignment -- Option values are fingerprinted without interpretation.
        $observed[$key] = get_option((string) $key);
    }
    $expected = (string) ($snapshot['fingerprint'] ?? '');
    $actual = wppilot_snapshot_fingerprint($observed);
    return [
        'settings' => array_keys($values),
        'expected_fingerprint' => $expected,
        'observed_fingerprint' => $actual,
        'verified' => $expected !== '' && hash_equals($expected, $actual),
    ];
}

/** @param array<string, mixed> $snapshot @return array<string, mixed>|WP_Error */
function wppilot_restore_order_status(array $snapshot): array|WP_Error
{
    if (!function_exists('wc_get_order')) {
        return new WP_Error('wppilot_rollback_dependency_missing', __(
            'WooCommerce is required to restore this order status.',
            domain: 'wppilot',
        ));
    }
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    $order = wc_get_order((int) ($values['order_id'] ?? 0));
    if (!$order instanceof WC_Order) {
        return new WP_Error('wppilot_rollback_target_missing', __(
            'The order required for rollback no longer exists.',
            domain: 'wppilot',
        ));
    }
    $status = sanitize_key((string) ($values['status'] ?? ''));
    $order->update_status($status, __('Order status restored by GoBuildWP rollback.', domain: 'wppilot'), true);
    $observed = ['order_id' => $order->get_id(), 'status' => $order->get_status()];
    $expected = (string) ($snapshot['fingerprint'] ?? '');
    $actual = wppilot_snapshot_fingerprint($observed);
    return [
        'order_id' => $order->get_id(),
        'status' => $order->get_status(),
        'verified' => $expected !== '' && hash_equals($expected, $actual),
    ];
}

/** @param array<string, mixed> $snapshot @return array<string, mixed>|WP_Error */
function wppilot_restore_comment_status(array $snapshot): array|WP_Error
{
    $values = wppilot_string_keyed_array($snapshot['values'] ?? null);
    $comment_id = (int) ($values['comment_id'] ?? 0);
    if (!$comment_id || !get_comment($comment_id)) {
        return new WP_Error('wppilot_rollback_target_missing', __(
            'The comment required for rollback no longer exists.',
            domain: 'wppilot',
        ));
    }
    $status = match ((string) ($values['status'] ?? 'hold')) {
        'approve' => 'approve',
        'spam' => 'spam',
        'trash' => 'trash',
        default => 'hold',
    };
    $updated = wp_set_comment_status($comment_id, $status, wp_error: true);
    if ($updated instanceof WP_Error) {
        return $updated;
    }
    $observed = ['comment_id' => $comment_id, 'status' => wp_get_comment_status($comment_id)];
    $expected = (string) ($snapshot['fingerprint'] ?? '');
    $actual = wppilot_snapshot_fingerprint($observed);
    return [
        'comment_id' => $comment_id,
        'status' => $observed['status'],
        'verified' => $expected !== '' && hash_equals($expected, $actual),
    ];
}

/** @return array<string, mixed>|WP_Error */
function wppilot_rollback_created_comment(int $comment_id): array|WP_Error
{
    if ($comment_id <= 0 || !get_comment($comment_id)) {
        return new WP_Error('wppilot_rollback_target_missing', __(
            'The created comment no longer exists.',
            domain: 'wppilot',
        ));
    }
    if (!wp_delete_comment($comment_id, force_delete: true)) {
        return new WP_Error('wppilot_rollback_delete_failed', __(
            'The created comment could not be deleted.',
            domain: 'wppilot',
        ));
    }
    return ['comment_id' => $comment_id, 'verified' => get_comment($comment_id) === null];
}

/**
 * Post columns that move on their own and must never count as a change.
 *
 * WordPress rewrites post_modified on every save, guid is derived, and filter is
 * a runtime marker rather than stored state. The fingerprint below drops them so
 * an unrelated touch does not read as drift, and includes/preview/diff.php drops
 * the same list so a preview does not report them as edits.
 *
 * The two must stay one list. If the differ ignored a column the fingerprint
 * hashed, a preview would show "no changes" and then fail its own drift check;
 * if the fingerprint ignored a column the differ reported, every preview would
 * carry a phantom entry.
 *
 * @var list<string>
 */
const WPPILOT_VOLATILE_POST_FIELDS = ['post_modified', 'post_modified_gmt', 'guid', 'filter'];

/** @param array<string, mixed> $snapshot */
function wppilot_post_snapshot_fingerprint(array $snapshot): string
{
    $post = is_array($snapshot['post'] ?? null) ? $snapshot['post'] : [];
    foreach (WPPILOT_VOLATILE_POST_FIELDS as $volatile) {
        unset($post[$volatile]);
    }
    // Canonical order. get_post_meta() returns keys in meta_id order, and a restore deletes and
    // re-adds keys while the ones it leaves alone (_edit_lock and friends) keep their old ids, so
    // the same data came back in a different order and hashed differently: a WooCommerce product
    // restored exactly still reported "did not match the before-image". The values of one key keep
    // their order, which is meaningful.
    $meta = is_array($snapshot['meta'] ?? null) ? $snapshot['meta'] : [];
    // Live counters are not part of what a restore puts back (older before-images still hold them).
    foreach (array_keys($meta) as $key) {
        if (wppilot_post_meta_is_live_counter((string) ($post['post_type'] ?? ''), (string) $key)) {
            unset($meta[$key]);
        }
    }
    ksort($meta, SORT_STRING);
    $terms = is_array($snapshot['terms'] ?? null) ? $snapshot['terms'] : [];
    ksort($terms, SORT_STRING);
    foreach ($terms as $taxonomy => $ids) {
        if (is_array($ids)) {
            sort($ids);
            $terms[$taxonomy] = $ids;
        }
    }
    return wppilot_snapshot_fingerprint([
        'post' => $post,
        'meta' => $meta,
        'terms' => $terms,
    ]);
}

function wppilot_snapshot_fingerprint(mixed $value): string
{
    return hash('sha256', (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** @return array<string, mixed> */
function wppilot_result_summary(mixed $result): array
{
    if (is_array($result)) {
        // @mago-expect analysis:mixed-assignment -- Recursive redaction deliberately preserves safe scalar types.
        $safe = wppilot_redact_for_log($result);
        return [
            'type' => 'array',
            'keys' => array_slice(array: array_map('strval', array_keys($result)), offset: 0, length: 50),
            'summary' => $safe,
        ];
    }
    if (is_object($result)) {
        return ['type' => get_class($result)];
    }
    return [
        'type' => gettype($result),
        'value' => is_scalar($result) ? mb_substr((string) $result, start: 0, length: 200) : null,
    ];
}

/**
 * What an audited read returned, without any of it.
 *
 * The point of auditing a SELECT is to know it ran, not to copy the rows it read into an option
 * any administrator can open: those rows can be exactly the data the audit exists to watch. So
 * only the shape survives — the top-level keys and how many rows each list held.
 *
 * @return array<string, mixed>
 */
function wppilot_audit_read_summary(mixed $result): array
{
    if (!is_array($result)) {
        return ['type' => gettype($result)];
    }

    $counts = [];
    foreach (array_slice(array: $result, offset: 0, length: 50, preserve_keys: true) as $key => $value) {
        if (is_array($value)) {
            $counts[(string) $key] = count($value);
        }
    }

    return [
        'type' => 'array',
        'keys' => array_slice(array: array_map('strval', array_keys($result)), offset: 0, length: 50),
        'row_counts' => $counts,
    ];
}

/** @return mixed */
function wppilot_redact_for_log(mixed $value, int $depth = 0): mixed
{
    if ($depth > 4) {
        return '[depth-limited]';
    }
    if (!is_array($value)) {
        return is_string($value) && strlen($value) > 500 ? mb_substr($value, start: 0, length: 500) . '...' : $value;
    }
    $result = [];
    foreach (array_slice(array: $value, offset: 0, length: 100, preserve_keys: true) as $key => $item) {
        if (wppilot_change_key_is_sensitive((string) $key)) {
            $result[$key] = '[redacted]';
            continue;
        }
        // @mago-expect analysis:mixed-assignment -- Recursive redaction deliberately preserves safe scalar types.
        $result[$key] = wppilot_redact_for_log($item, $depth + 1);
    }
    if (count($value) > 100) {
        $result['__truncated_items'] = count($value) - 100;
    }
    return $result;
}

function wppilot_change_key_is_sensitive(string $key): bool
{
    return (
        preg_match(
            // Whole-word pass/pwd/pswd/auth (so "bypass" and "author" stay visible), plus the
            // credential option names cache plugins use: LiteSpeed object-pswd and
            // cdn-cloudflare_key, NitroPack siteId, Autoptimize ccss_key.
            '/password|passwd|pswd|secret|token|authoriz|api[_-]?key|private[_-]?key|access[_-]?key|cloudflare[_-]?key|ccss[_-]?key|license|cookie|credential|php[_-]?code|source[_-]?code|(^|[^a-z])(pass|pwd|auth)([^a-z]|$)|siteid$/',
            strtolower($key),
        ) === 1
    );
}

/** @return array<string, mixed> */
function wppilot_string_keyed_array(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $result[$key] = $item;
        }
    }
    return $result;
}

/** @return list<string> */
function wppilot_string_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    // @mago-expect analysis:mixed-assignment -- Only string members are retained.
    foreach ($value as $item) {
        if (is_string($item)) {
            $result[] = $item;
        }
    }
    return $result;
}
