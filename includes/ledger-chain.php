<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Provable audit: a hash chain over the change ledger, and a signed export of it.
 *
 * Every row recorded from now on carries `prev_hash` and `row_hash`:
 *
 *   content_hash = sha256(canonical JSON of the row, without the keys listed in
 *                  WPPILOT_LEDGER_CHAIN_UNSEALED_KEYS)
 *   row_hash     = sha256(prev_hash . content_hash)
 *
 * `prev_hash` is the `row_hash` of the row recorded before it (64 zeros for the first). The newest
 * `row_hash` is also kept in an option, so deleting the newest rows breaks the chain as surely as
 * deleting one in the middle. A row edited in the database, a row deleted from the middle and a row
 * inserted by hand are all reported as "chain break at row N", never repaired and never hidden.
 *
 * What the chain cannot tell apart: rows removed from the oldest end. Retention pruning does
 * exactly that, so the oldest remaining row is the chain's anchor and its `prev_hash` is taken
 * as given. Rows recorded before this release carry no hashes and are counted, not judged.
 *
 * Canonical JSON is the row put through json_encode/json_decode once (objects become arrays, as
 * they would for anyone reading the export), then encoded with keys sorted at every level. The
 * offline verifier (scripts/verify-ledger-export.php) repeats exactly this, so keep them in step.
 *
 * The export is NDJSON: a header line, one line per row in ledger order, the site's own chain
 * report, and a last line signing the SHA-256 of every byte before it with the site's Ed25519 key
 * (the same key GoBuildWP Cloud pairing uses, includes/cloud/signing.php).
 */

if (!defined('ABSPATH')) {
    exit();
}

/** The newest row_hash. Not autoloaded: only a ledger write or a verification reads it. */
const WPPILOT_LEDGER_CHAIN_HEAD_OPTION = 'wppilot_ledger_chain_head';

/** prev_hash of the first row ever sealed. */
const WPPILOT_LEDGER_CHAIN_GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

/**
 * Keys a row may legitimately change after it is recorded, so they are left out of its hash.
 *
 * Undo and redo write their outcome onto the row they reverse (includes/change-log.php,
 * includes/change-sessions.php), and a visual capture attaches its diff score
 * (includes/design/abilities/captures.php). Everything else a row holds - what was asked, by
 * whom, the result and the before-image - is sealed. Not filterable on purpose: a filter that
 * widened this list would be a way to edit rows without breaking the chain.
 */
const WPPILOT_LEDGER_CHAIN_UNSEALED_KEYS = [
    'prev_hash',
    'row_hash',
    'rolled_back',
    'rolled_back_at',
    'rollback_result',
    'redo',
    'undone_digest',
    'redone_at',
    'redo_result',
    'visual',
];

const WPPILOT_LEDGER_EXPORT_FORMAT = 'gobuildwp-ledger';

const WPPILOT_LEDGER_EXPORT_ACTION = 'wppilot_ledger_export';

const WPPILOT_LEDGER_VERIFY_ACTION = 'wppilot_ledger_verify';

/** Rows read from the table per query while verifying or exporting. */
const WPPILOT_LEDGER_CHAIN_PAGE = 200;

/** Breaks listed in a report; the count stays exact past it. */
const WPPILOT_LEDGER_CHAIN_MAX_LISTED = 50;

const WPPILOT_LEDGER_CHAIN_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

/**
 * The canonical JSON text of a value: as a JSON reader would see it, keys sorted at every level.
 */
function wppilot_ledger_chain_canonical(mixed $value): string
{
    $encoded = json_encode($value, WPPILOT_LEDGER_CHAIN_JSON_FLAGS);
    $plain = is_string($encoded) ? json_decode($encoded, true) : null;

    return (string) json_encode(wppilot_ledger_chain_sorted($plain), WPPILOT_LEDGER_CHAIN_JSON_FLAGS);
}

function wppilot_ledger_chain_sorted(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    $sorted = [];
    foreach ($value as $key => $item) {
        $sorted[$key] = wppilot_ledger_chain_sorted($item);
    }
    // A list keeps its order; only maps are sorted. array_is_list() is PHP 8.1.
    if ($sorted !== [] && array_keys($sorted) !== range(0, count($sorted) - 1)) {
        ksort($sorted, SORT_STRING);
    }

    return $sorted;
}

/**
 * The row without the keys it may change after it is recorded.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function wppilot_ledger_chain_sealed(array $row): array
{
    return array_diff_key($row, array_flip(WPPILOT_LEDGER_CHAIN_UNSEALED_KEYS));
}

/** @param array<string, mixed> $row */
function wppilot_ledger_chain_content_hash(array $row): string
{
    return hash('sha256', wppilot_ledger_chain_canonical(wppilot_ledger_chain_sealed($row)));
}

function wppilot_ledger_chain_link(string $prev_hash, string $content_hash): string
{
    return hash('sha256', $prev_hash . $content_hash);
}

function wppilot_ledger_chain_is_hash(mixed $value): bool
{
    return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
}

/** The newest row_hash, or '' before the first sealed row. Read past any cached copy. */
function wppilot_ledger_chain_head(): string
{
    if (function_exists('wp_cache_delete')) {
        wp_cache_delete(WPPILOT_LEDGER_CHAIN_HEAD_OPTION, 'options');
    }
    $head = get_option(WPPILOT_LEDGER_CHAIN_HEAD_OPTION, default_value: '');

    return wppilot_ledger_chain_is_hash($head) ? (string) $head : '';
}

/**
 * Give rows about to be recorded their place in the chain.
 *
 * Called under the ledger lock (wppilot_store_changes()), after every listener has amended the
 * rows: anything that changes a row after this breaks its hash, which is the point. The id is
 * fixed here too, because table storage would otherwise give an id-less row one after sealing.
 *
 * @param list<array<string, mixed>> $entries
 * @return list<array<string, mixed>>
 */
function wppilot_ledger_chain_seal(array $entries, string $head): array
{
    $prev = $head !== '' ? $head : WPPILOT_LEDGER_CHAIN_GENESIS;
    $sealed = [];
    foreach ($entries as $entry) {
        $entry = wppilot_change_row_with_new_id($entry);
        unset($entry['prev_hash'], $entry['row_hash']);
        $entry['prev_hash'] = $prev;
        $entry['row_hash'] = wppilot_ledger_chain_link($prev, wppilot_ledger_chain_content_hash($entry));
        $prev = $entry['row_hash'];
        $sealed[] = $entry;
    }

    return $sealed;
}

/**
 * Seal and store rows as one step under the ledger lock, then move the head.
 *
 * @param list<array<string, mixed>> $entries Rows that have been through wppilot_change_rows_before_store().
 * @param callable(list<array<string, mixed>>): void $store
 */
function wppilot_ledger_chain_store(array $entries, callable $store): void
{
    wppilot_with_change_log_lock(static function () use ($entries, $store): void {
        $sealed = wppilot_ledger_chain_seal($entries, wppilot_ledger_chain_head());
        $store($sealed);
        $last = $sealed[array_key_last($sealed)] ?? null;
        if (is_array($last) && wppilot_ledger_chain_is_hash($last['row_hash'] ?? null)) {
            update_option(WPPILOT_LEDGER_CHAIN_HEAD_OPTION, $last['row_hash'], autoload: false);
        }
    });
}

/**
 * Every ledger row, oldest first, wherever the ledger lives.
 *
 * @return Generator<int, array<string, mixed>>
 */
function wppilot_ledger_chain_rows(): Generator
{
    if (!wppilot_change_table_active()) {
        foreach (wppilot_change_log_option_rows() as $row) {
            yield $row;
        }

        return;
    }
    $table = wppilot_changes_table();
    $after = 0;
    while (true) {
        $records = wppilot_changes_results(
            "SELECT * FROM {$table} WHERE seq > %d ORDER BY seq ASC LIMIT %d",
            [$after, WPPILOT_LEDGER_CHAIN_PAGE],
        );
        foreach ($records as $record) {
            $after = max($after, (int) ($record['seq'] ?? 0));
            yield wppilot_change_row_from_record($record);
        }
        if (count($records) < WPPILOT_LEDGER_CHAIN_PAGE) {
            return;
        }
    }
}

/**
 * A verifier that takes one row at a time, so the whole ledger never has to be held at once.
 *
 * @return array{rows: int, sealed: int, unsealed: int, anchor: string, last_hash: string, breaks: list<array{row: int, id: string, reason: string}>, break_count: int}
 */
function wppilot_ledger_chain_report_start(): array
{
    return ['rows' => 0, 'sealed' => 0, 'unsealed' => 0, 'anchor' => '', 'last_hash' => '', 'breaks' => [], 'break_count' => 0];
}

/**
 * @param array{rows: int, sealed: int, unsealed: int, anchor: string, last_hash: string, breaks: list<array{row: int, id: string, reason: string}>, break_count: int} $report
 * @param array<string, mixed> $row
 * @return array{rows: int, sealed: int, unsealed: int, anchor: string, last_hash: string, breaks: list<array{row: int, id: string, reason: string}>, break_count: int}
 */
function wppilot_ledger_chain_report_row(array $report, array $row): array
{
    $report['rows']++;
    $position = $report['rows'];
    $id = is_string($row['id'] ?? null) ? $row['id'] : '';
    $prev = $row['prev_hash'] ?? null;
    $hash = $row['row_hash'] ?? null;

    if (!wppilot_ledger_chain_is_hash($prev) || !wppilot_ledger_chain_is_hash($hash)) {
        if ($report['sealed'] === 0) {
            // Recorded before the chain existed.
            $report['unsealed']++;

            return $report;
        }

        return wppilot_ledger_chain_report_break($report, $position, $id, 'it carries no hash, so it was added outside GoBuildWP or its hashes were removed');
    }

    if ($report['sealed'] === 0) {
        $report['anchor'] = (string) $prev;
    } elseif ($report['last_hash'] !== '' && !hash_equals($report['last_hash'], (string) $prev)) {
        $report = wppilot_ledger_chain_report_break($report, $position, $id, 'it does not follow the row before it, so a row between them was deleted or the order was changed');
    }
    $report['sealed']++;
    $report['last_hash'] = (string) $hash;
    if (!hash_equals(wppilot_ledger_chain_link((string) $prev, wppilot_ledger_chain_content_hash($row)), (string) $hash)) {
        $report = wppilot_ledger_chain_report_break($report, $position, $id, 'its contents no longer match its hash, so it was changed after it was recorded');
    }

    return $report;
}

/**
 * @param array{rows: int, sealed: int, unsealed: int, anchor: string, last_hash: string, breaks: list<array{row: int, id: string, reason: string}>, break_count: int} $report
 * @return array{rows: int, sealed: int, unsealed: int, anchor: string, last_hash: string, breaks: list<array{row: int, id: string, reason: string}>, break_count: int}
 */
function wppilot_ledger_chain_report_break(array $report, int $position, string $id, string $reason): array
{
    $report['break_count']++;
    if (count($report['breaks']) < WPPILOT_LEDGER_CHAIN_MAX_LISTED) {
        $report['breaks'][] = ['row' => $position, 'id' => $id, 'reason' => $reason];
    }

    return $report;
}

/**
 * Close the report against the head: the newest sealed row must be the one the site last wrote.
 *
 * @param array{rows: int, sealed: int, unsealed: int, anchor: string, last_hash: string, breaks: list<array{row: int, id: string, reason: string}>, break_count: int} $report
 * @return array{rows: int, sealed: int, unsealed: int, anchor: string, last_hash: string, breaks: list<array{row: int, id: string, reason: string}>, break_count: int, head: string, intact: bool}
 */
function wppilot_ledger_chain_report_finish(array $report, string $head): array
{
    if ($head !== '' && !hash_equals($head, $report['last_hash'])) {
        $report = wppilot_ledger_chain_report_break(
            $report,
            $report['rows'] + 1,
            '',
            $report['sealed'] === 0
                ? 'the site recorded hashed rows but none are left, so they were deleted'
                : 'the newest rows were deleted: the last row is not the one the site last recorded',
        );
    } elseif ($head === '' && $report['sealed'] > 0) {
        $report = wppilot_ledger_chain_report_break($report, $report['rows'], '', 'the record of the newest row is missing, so rows at the end cannot be proved complete');
    }
    $report['head'] = $head;
    $report['intact'] = $report['break_count'] === 0;

    return $report;
}

/**
 * Verify this site's ledger end to end.
 *
 * @return array{rows: int, sealed: int, unsealed: int, anchor: string, last_hash: string, breaks: list<array{row: int, id: string, reason: string}>, break_count: int, head: string, intact: bool}
 */
function wppilot_ledger_chain_verify(): array
{
    $report = wppilot_ledger_chain_report_start();
    foreach (wppilot_ledger_chain_rows() as $row) {
        $report = wppilot_ledger_chain_report_row($report, $row);
    }

    return wppilot_ledger_chain_report_finish($report, wppilot_ledger_chain_head());
}

/** One line of the report, for a notice or a log. */
function wppilot_ledger_chain_summary(array $report): string
{
    if (($report['intact'] ?? false) === true) {
        return sprintf(
            /* translators: 1: number of rows checked, 2: number of rows from before the chain */
            __('Ledger chain intact: %1$d hashed rows verified, none changed, deleted or added by hand. %2$d older rows predate the chain and carry no hash.', domain: 'wppilot'),
            (int) ($report['sealed'] ?? 0),
            (int) ($report['unsealed'] ?? 0),
        );
    }
    $first = is_array($report['breaks'][0] ?? null) ? $report['breaks'][0] : ['row' => 0, 'reason' => ''];

    return sprintf(
        /* translators: 1: number of breaks, 2: row number, 3: reason */
        _n('%1$d chain break. Chain break at row %2$d: %3$s.', '%1$d chain breaks. First: chain break at row %2$d: %3$s.', (int) ($report['break_count'] ?? 0), 'wppilot'),
        (int) ($report['break_count'] ?? 0),
        (int) $first['row'],
        (string) $first['reason'],
    );
}

/**
 * Write the signed NDJSON export through $write, one line per call.
 *
 * @param callable(string): void $write
 * @return array{report: array<string, mixed>, signed: bool}
 */
function wppilot_ledger_export(callable $write): array
{
    $digest = hash_init('sha256');
    $emit = static function (array $line) use ($write, $digest): void {
        $text = json_encode($line, WPPILOT_LEDGER_CHAIN_JSON_FLAGS) . "\n";
        hash_update($digest, $text);
        $write($text);
    };

    $head = wppilot_ledger_chain_head();
    $emit([
        'type' => 'header',
        'format' => WPPILOT_LEDGER_EXPORT_FORMAT,
        'version' => 1,
        'site' => home_url('/'),
        'exported_at' => gmdate('c'),
        'head' => $head,
        'unsealed_keys' => WPPILOT_LEDGER_CHAIN_UNSEALED_KEYS,
    ]);
    $report = wppilot_ledger_chain_report_start();
    foreach (wppilot_ledger_chain_rows() as $row) {
        $report = wppilot_ledger_chain_report_row($report, $row);
        $prev = $row['prev_hash'] ?? null;
        $hash = $row['row_hash'] ?? null;
        $emit([
            'type' => 'row',
            'row' => $report['rows'],
            'prev_hash' => wppilot_ledger_chain_is_hash($prev) ? $prev : null,
            'row_hash' => wppilot_ledger_chain_is_hash($hash) ? $hash : null,
            'content_hash' => wppilot_ledger_chain_content_hash($row),
            'entry' => array_diff_key($row, ['prev_hash' => true, 'row_hash' => true]),
        ]);
    }
    $report = wppilot_ledger_chain_report_finish($report, $head);
    $emit(['type' => 'report'] + $report);

    $signed_digest = hash_final($digest);
    $keys = function_exists('wppilot_cloud_sodium_available') && wppilot_cloud_sodium_available() && function_exists('wppilot_cloud_keys')
        ? wppilot_cloud_keys(create: true)
        : null;
    $signature = is_array($keys) ? wppilot_cloud_sign($signed_digest, $keys['secret']) : '';
    $line = $signature !== ''
        ? ['type' => 'signature', 'alg' => 'Ed25519', 'sha256' => $signed_digest, 'public_key' => $keys['public'], 'signature' => $signature]
        : ['type' => 'signature', 'alg' => 'none', 'sha256' => $signed_digest, 'reason' => 'This PHP build has no sodium, so the export could not be signed.'];
    $write(json_encode($line, WPPILOT_LEDGER_CHAIN_JSON_FLAGS) . "\n");

    return ['report' => $report, 'signed' => $signature !== ''];
}

/** The site's public signing key, for the person to record somewhere the site cannot change it. */
function wppilot_ledger_public_key(): string
{
    if (!function_exists('wppilot_cloud_keys')) {
        return '';
    }
    $keys = wppilot_cloud_keys(create: false);

    return is_array($keys) ? $keys['public'] : '';
}

function wppilot_ledger_handle_export(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('Not allowed.', domain: 'wppilot'), '', ['response' => 403]);
    }
    check_admin_referer(WPPILOT_LEDGER_EXPORT_ACTION);

    nocache_headers();
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Content-Disposition: attachment; filename="gobuildwp-ledger-' . gmdate('Ymd-His') . '.ndjson"');
    header('X-Content-Type-Options: nosniff');
    wppilot_ledger_export(static function (string $line): void {
        echo $line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- An NDJSON download, every line built by json_encode().
    });
    exit();
}

function wppilot_ledger_handle_verify(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('Not allowed.', domain: 'wppilot'), '', ['response' => 403]);
    }
    check_admin_referer(WPPILOT_LEDGER_VERIFY_ACTION);
    $report = wppilot_ledger_chain_verify();
    set_transient('wppilot_changes_admin_notice_' . get_current_user_id(), [
        'type' => $report['intact'] ? 'success' : 'error',
        'message' => wppilot_ledger_chain_summary($report),
    ], 300);
    wp_safe_redirect(admin_url('admin.php?page=wppilot-changes'));
    exit();
}

/** The two buttons on the Changes screen, beside its CSV and JSON downloads. */
function wppilot_ledger_render_controls(): void
{
    if (!wppilot_current_user_can_manage()) {
        return;
    }
    $public_key = wppilot_ledger_public_key();
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wppilot-changes__export">
        <input type="hidden" name="action" value="<?php echo esc_attr(WPPILOT_LEDGER_EXPORT_ACTION); ?>">
        <?php wp_nonce_field(WPPILOT_LEDGER_EXPORT_ACTION); ?>
        <button type="submit" class="button"><?php esc_html_e('Download signed audit (NDJSON)', domain: 'wppilot'); ?></button>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wppilot-changes__export">
        <input type="hidden" name="action" value="<?php echo esc_attr(WPPILOT_LEDGER_VERIFY_ACTION); ?>">
        <?php wp_nonce_field(WPPILOT_LEDGER_VERIFY_ACTION); ?>
        <button type="submit" class="button"><?php esc_html_e('Verify ledger chain', domain: 'wppilot'); ?></button>
    </form>
    <p class="description">
        <?php esc_html_e('The signed audit holds the whole ledger, oldest first, with each row\'s hash. Check it away from this site with scripts/verify-ledger-export.php from the GoBuildWP repository.', domain: 'wppilot'); ?>
        <?php if ($public_key !== '') { ?>
            <?php esc_html_e('Keep a copy of this site\'s public key somewhere this site cannot change, and pass it to the verifier with --public-key:', domain: 'wppilot'); ?>
            <code><?php echo esc_html($public_key); ?></code>
        <?php } ?>
    </p>
    <?php
}

function wppilot_ledger_chain_register_hooks(): void
{
    add_action('admin_post_' . WPPILOT_LEDGER_EXPORT_ACTION, 'wppilot_ledger_handle_export');
    add_action('admin_post_' . WPPILOT_LEDGER_VERIFY_ACTION, 'wppilot_ledger_handle_verify');
}
