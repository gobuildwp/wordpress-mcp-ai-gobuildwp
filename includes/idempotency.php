<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Idempotency keys for writes: an agent that retries a write after a timeout, with the same
 * `idempotency_key`, gets the first call's result back (`replayed: true`) instead of a second
 * post, order or file. The same key with different arguments is refused with 409.
 *
 * Keys are scoped to the WordPress user and the agent credential, so two agents cannot read each
 * other's results by guessing keys, and last 24 hours. The cached result is the redacted one.
 *
 * Records live in wp_options, read and written with direct queries: option_name is unique, so a
 * plain INSERT is an atomic claim (a second request with the same key fails the insert instead of
 * racing it), and the object cache never holds a stale copy. Each value starts with its expiry,
 * `{"exp":<unix time>,`, so expired records can be swept with one string comparison.
 *
 * The key is declared on every wrapped write's input schema by the write envelope
 * (write-receipts.php), which strips it before the ability runs and calls begin/finish around it.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_IDEMPOTENCY_KEY = 'idempotency_key';
const WPPILOT_IDEMPOTENCY_PREFIX = '_wppilot_idem_';
const WPPILOT_IDEMPOTENCY_TTL = 86400;
// A result bigger than this is not cached; a replay says the write was done instead.
const WPPILOT_IDEMPOTENCY_MAX_BYTES = 262144;

add_action('shutdown', 'wppilot_idempotency_release_unfinished');

/** @return array<string, mixed> */
function wppilot_idempotency_schema(): array
{
    return [
        'type' => 'string',
        'pattern' => '^[A-Za-z0-9._:-]{1,128}$',
        'description' => 'Optional. Send the same key when retrying this write: within 24 hours the first result comes back with replayed: true instead of the write running twice. A different request with a used key is refused (409).',
    ];
}

/**
 * The open claims of the wrapped writes now running, innermost last. A write without a key
 * pushes null, so nested writes pop their own entry and never the outer one.
 *
 * @param 'push'|'pop'|'peek'|'all' $op
 */
function wppilot_idempotency_claims(string $op, ?string $slot = null): mixed
{
    /** @var list<string|null> $stack */
    static $stack = [];
    if ($op === 'push') {
        $stack[] = $slot;
        return null;
    }
    if ($op === 'pop') {
        return array_pop($stack);
    }
    if ($op === 'all') {
        $all = $stack;
        $stack = [];
        return $all;
    }

    return $stack === [] ? null : $stack[count($stack) - 1];
}

/**
 * Claim a key before a write runs.
 *
 * @param array<string, mixed> $controls The control keys the envelope took off the input.
 * @param array<string, mixed> $input    The input the ability will see.
 * @return null|WP_Error|array<string, mixed> Null to run the write, an error to refuse it, or the
 *         first call's result (with `replayed: true`) to return instead.
 */
function wppilot_idempotency_begin(string $ability, array $controls, array $input): mixed
{
    if (!array_key_exists(WPPILOT_IDEMPOTENCY_KEY, $controls)) {
        wppilot_idempotency_claims('push');
        return null;
    }
    $key = $controls[WPPILOT_IDEMPOTENCY_KEY];
    if (!is_string($key) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $key) !== 1) {
        return new WP_Error(
            'wppilot_idempotency_key_invalid',
            __('idempotency_key must be 1 to 128 letters, digits, dots, colons, underscores or hyphens.', domain: 'wppilot'),
            ['status' => 400],
        );
    }

    $slot = wppilot_idempotency_slot($ability, $key);
    $fingerprint = wppilot_idempotency_fingerprint($input);
    wppilot_idempotency_sweep();

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $now = time();
        if (wppilot_idempotency_insert($slot, ['exp' => $now + WPPILOT_IDEMPOTENCY_TTL, 'fp' => $fingerprint, 'state' => 'pending', 'at' => $now])) {
            wppilot_idempotency_claims('push', $slot);
            return null;
        }
        $record = wppilot_idempotency_read($slot);
        if ($record === null || (int) ($record['exp'] ?? 0) < $now) {
            // Expired (or unreadable): free the slot and claim it on the next pass.
            wppilot_idempotency_delete($slot);
            continue;
        }

        return wppilot_idempotency_answer($ability, $record, $fingerprint);
    }

    return new WP_Error('wppilot_idempotency_unavailable', __('The idempotency key could not be claimed. Try again.', domain: 'wppilot'), ['status' => 503]);
}

/**
 * What a call with an already used key gets.
 *
 * @param array<string, mixed> $record
 * @return WP_Error|array<string, mixed>
 */
function wppilot_idempotency_answer(string $ability, array $record, string $fingerprint): WP_Error|array
{
    if (!hash_equals((string) ($record['fp'] ?? ''), $fingerprint)) {
        return new WP_Error(
            'wppilot_idempotency_conflict',
            __('This idempotency key was already used for a different request. Use a new key for a new write.', domain: 'wppilot'),
            ['status' => 409],
        );
    }
    if (($record['state'] ?? '') !== 'done') {
        $age = time() - (int) ($record['at'] ?? time());
        return new WP_Error(
            'wppilot_idempotency_in_progress',
            $age > 900
                ? __('A write with this idempotency key started more than 15 minutes ago and never reported back. Check wppilot/list-changes to see whether it landed before writing again with a new key.', domain: 'wppilot')
                : __('A write with this idempotency key is still running. Wait for it, then retry with the same key to get its result.', domain: 'wppilot'),
            ['status' => 409],
        );
    }

    // The replay changes nothing, so the ledger's after hook must not record it as a write.
    $pending = function_exists('wppilot_change_pending') ? wppilot_change_pending($ability) : null;
    if (is_array($pending)) {
        $pending['replayed'] = true;
        wppilot_change_pending($ability, $pending);
    }

    $result = $record['result'] ?? null;
    if (!is_array($result)) {
        return new WP_Error(
            'wppilot_idempotency_already_done',
            __('This write already ran with this idempotency key; its result was too large to keep. Check wppilot/list-changes for it.', domain: 'wppilot'),
            ['status' => 409, 'change_id' => $record['change_id'] ?? null],
        );
    }
    $result['replayed'] = true;

    return $result;
}

/**
 * Record the result of the write the innermost claim belongs to. A failed write frees its key,
 * so the retry can run.
 */
function wppilot_idempotency_finish(mixed $result): void
{
    $slot = wppilot_idempotency_claims('pop');
    if (!is_string($slot)) {
        return;
    }
    if ($result instanceof WP_Error) {
        wppilot_idempotency_delete($slot);
        return;
    }

    $record = wppilot_idempotency_read($slot) ?? [];
    $redacted = function_exists('wppilot_redact_for_output') ? wppilot_redact_for_output($result) : $result;
    $receipt = is_array($result) && is_array($result['receipt'] ?? null) ? $result['receipt'] : [];
    $record['state'] = 'done';
    $record['change_id'] = $receipt['change_id'] ?? null;
    $record['result'] = $redacted;
    if (strlen((string) wp_json_encode($record)) > WPPILOT_IDEMPOTENCY_MAX_BYTES) {
        $record['result'] = null;
    }
    wppilot_idempotency_write($slot, $record);
}

/**
 * Free the innermost claim after the write threw.
 */
function wppilot_idempotency_abandon(): void
{
    $slot = wppilot_idempotency_claims('pop');
    if (is_string($slot)) {
        wppilot_idempotency_delete($slot);
    }
}

/**
 * A request that died mid-write (a fatal, a timeout) frees the keys it claimed, so the agent's
 * retry is not stuck behind "in progress" for 24 hours.
 */
function wppilot_idempotency_release_unfinished(): void
{
    foreach ((array) wppilot_idempotency_claims('all') as $slot) {
        if (is_string($slot)) {
            wppilot_idempotency_delete($slot);
        }
    }
}

function wppilot_idempotency_slot(string $ability, string $key): string
{
    $agent = function_exists('wppilot_current_agent') ? wppilot_current_agent() : [];
    $credential = is_array($agent) ? (string) ($agent['credential'] ?? '') : '';
    $scope = implode('|', [(string) get_current_user_id(), $credential, $ability, $key]);

    // 14 + 40 characters, well inside option_name's 191.
    return WPPILOT_IDEMPOTENCY_PREFIX . substr(hash('sha256', $scope), 0, 40);
}

/**
 * A hash of the input that does not depend on the order its keys arrived in.
 *
 * @param array<string, mixed> $input
 */
function wppilot_idempotency_fingerprint(array $input): string
{
    return hash('sha256', (string) wp_json_encode(wppilot_idempotency_canonical($input)));
}

function wppilot_idempotency_canonical(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    $value = array_map('wppilot_idempotency_canonical', $value);
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return $value;
}

/** @param array<string, mixed> $record */
function wppilot_idempotency_encode(array $record): string
{
    // The expiry leads so the sweep can compare values as strings: Unix times stay ten digits until 2286.
    $exp = (string) (int) ($record['exp'] ?? 0);
    unset($record['exp']);
    $rest = (string) wp_json_encode($record);

    return '{"exp":' . $exp . ($rest === '{}' || $rest === '[]' ? '}' : ',' . substr($rest, 1));
}

/** @param array<string, mixed> $record */
function wppilot_idempotency_insert(string $slot, array $record): bool
{
    global $wpdb;
    $suppress = $wpdb->suppress_errors(true);
    // A duplicate option_name fails the insert: that failure is the "already claimed" answer.
    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
        $slot,
        wppilot_idempotency_encode($record),
    ));
    $wpdb->suppress_errors($suppress);

    return is_int($inserted) && $inserted > 0;
}

/** @return array<string, mixed>|null */
function wppilot_idempotency_read(string $slot): ?array
{
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $slot));
    if (!is_string($value)) {
        return null;
    }
    $record = json_decode($value, true);

    return is_array($record) ? $record : null;
}

/** @param array<string, mixed> $record */
function wppilot_idempotency_write(string $slot, array $record): void
{
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
        wppilot_idempotency_encode($record),
        $slot,
    ));
}

function wppilot_idempotency_delete(string $slot): void
{
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $slot));
}

/**
 * Remove expired records, once per request that uses a key.
 */
function wppilot_idempotency_sweep(bool $again = false): void
{
    static $swept = false;
    if ($swept && !$again) {
        return;
    }
    $swept = true;
    global $wpdb;
    // '!' rather than a backslash as the LIKE escape: MySQL and SQLite read '\\' differently.
    $like = str_replace('_', '!_', WPPILOT_IDEMPOTENCY_PREFIX) . '%';
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s ESCAPE '!' AND option_value < %s",
        $like,
        '{"exp":' . time(),
    ));
}
