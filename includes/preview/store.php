<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Preview\Store;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Storage for pending previews.
 *
 * One autoloaded-off option per preview plus a capped index, rather than the
 * custom post type the Gutenberg queue uses. That queue is a CPT because its
 * items are long-lived, human-visible work-queue entries needing post_parent
 * batching; a preview is one short-lived record with no children. A CPT would
 * have cost a register_post_type block, a retention cron, an entry in
 * wppilot_deactivate_current_site(), and another in wppilot_uninstall_post_types()
 * — for storage that a bounded option already provides. The bound-by-count-then-
 * by-bytes discipline is the one wppilot_store_change() already uses.
 *
 * Transients were rejected for the same feature: with a persistent object cache
 * a transient can be evicted before it expires, and a human review window is
 * measured in hours. Expiry is enforced on read instead.
 */

const OPTION_PREFIX = 'wppilot_preview_';
const LOCK_PREFIX = 'wppilot_preview_lock_';
const INDEX_OPTION = 'wppilot_preview_index';

/** How long a preview stays applicable. An agent proposes; a person reviews later. */
const TTL_SECONDS = 86_400;

/** Hard ceiling on live previews. Oldest are pruned first. */
const MAX_LIVE = 50;

/** A single stored record. Beyond this the diff was already truncated. */
const MAX_RECORD_BYTES = 262_144;

/** A crashed request may leave an applying record or lock behind. */
const APPLY_STALE_SECONDS = 3_600;

const PAYLOAD_AAD = 'wppilot-preview-v1';

const STATUS_PENDING = 'pending';
const STATUS_APPLYING = 'applying';
const STATUS_APPLIED = 'applied';
const STATUS_DISCARDED = 'discarded';
const STATUS_CONFLICTED = 'conflicted';
const STATUS_FAILED = 'failed';
const STATUS_EXPIRED = 'expired';

function option_name(string $id): string
{
    return OPTION_PREFIX . $id;
}

/**
 * The id index, newest first.
 *
 * @return list<string>
 */
function index(): array
{
    /** @var mixed $raw */
    $raw = get_option(INDEX_OPTION, default_value: []);
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $id) {
        if (is_string($id) && $id !== '') {
            $ids[] = $id;
        }
    }
    return $ids;
}

/** @param list<string> $ids */
function write_index(array $ids): void
{
    update_option(INDEX_OPTION, array_values($ids), autoload: false);
}

/**
 * Store a new preview and return its id.
 *
 * @param array<string, mixed> $record
 */
function create(array $record): string|\WP_Error
{
    $id = wp_generate_uuid4();
    $record['preview_id'] = $id;
    $record['status'] = STATUS_PENDING;

    $encoded = wp_json_encode($record);
    if (is_string($encoded) && strlen($encoded) > MAX_RECORD_BYTES) {
        // The diff is the only unbounded part, and it is already capped. If a
        // record still exceeds the budget, drop the entries before deciding
        // whether the complete, encrypted input itself is too large to retain.
        $record['diff']['entries'] = [];
        $record['diff']['truncated'] = true;
        $record['diff']['dropped_for_size'] = true;

        $encoded = wp_json_encode($record);
        if (!is_string($encoded) || strlen($encoded) > MAX_RECORD_BYTES) {
            return new \WP_Error(
                'wppilot_preview_too_large',
                'This preview is too large to store safely. Reduce the input and create a new preview.',
                ['status' => 413],
            );
        }
    }

    update_option(option_name($id), $record, autoload: false);
    write_index([$id, ...index()]);
    prune();

    return $id;
}

/**
 * Read a preview, marking it expired once its window has passed.
 *
 * @return array<string, mixed>|null
 */
function get(string $id): ?array
{
    if (!is_valid_id($id)) {
        return null;
    }
    /** @var mixed $record */
    $record = get_option(option_name($id), default_value: null);
    if (!is_array($record)) {
        return null;
    }

    if (($record['status'] ?? '') === STATUS_PENDING && is_expired($record)) {
        $record['status'] = STATUS_EXPIRED;
        update_option(option_name($id), $record, autoload: false);
    }

    if (($record['status'] ?? '') === STATUS_APPLYING && applying_is_stale($record)) {
        $record['status'] = STATUS_FAILED;
        $record['error'] = [
            'code' => 'wppilot_preview_apply_interrupted',
            'message' => 'The apply request did not finish. Its result is unknown; review the site before retrying.',
        ];
        $record['failed_at'] = gmdate('c');
        update_option(option_name($id), $record, autoload: false);
        release_lock($id);
    }

    return $record;
}

/** @param array<string, mixed> $record */
function is_expired(array $record): bool
{
    $expires = (string) ($record['expires_at'] ?? '');
    if ($expires === '') {
        return false;
    }
    return strtotime($expires) < time();
}

/** @param array<string, mixed> $record */
function applying_is_stale(array $record): bool
{
    $started = (string) ($record['applying_at'] ?? $record['created_at'] ?? '');
    $timestamp = $started !== '' ? strtotime($started) : false;
    return $timestamp !== false && $timestamp <= time() - APPLY_STALE_SECONDS;
}

/**
 * Every live preview, newest first.
 *
 * @return list<array<string, mixed>>
 */
function all(): array
{
    $records = [];
    foreach (index() as $id) {
        $record = get($id);
        if ($record !== null) {
            $records[] = $record;
        }
    }
    return $records;
}

/**
 * Merge fields into a stored preview.
 *
 * @param array<string, mixed> $changes
 */
function update(string $id, array $changes): bool
{
    $record = get($id);
    if ($record === null) {
        return false;
    }
    update_option(option_name($id), array_merge($record, $changes), autoload: false);
    return true;
}

function delete(string $id): void
{
    if (!is_valid_id($id)) {
        return;
    }
    delete_option(option_name($id));
    delete_option(LOCK_PREFIX . $id);
    write_index(array_values(array_filter(index(), static fn(string $known): bool => $known !== $id)));
}

/**
 * Drop expired and over-cap records.
 *
 * Runs on every create rather than on a cron, so the bound holds without another
 * scheduled hook to register, unschedule on deactivation, and reason about.
 */
function prune(): void
{
    $kept = [];
    foreach (index() as $id) {
        /** @var mixed $record */
        $record = get_option(option_name($id), default_value: null);
        if (!is_array($record)) {
            continue;
        }
        if (is_expired($record) && ($record['status'] ?? '') !== STATUS_APPLIED) {
            delete_option(option_name($id));
            delete_option(LOCK_PREFIX . $id);
            continue;
        }
        $kept[] = $id;
    }

    if (count($kept) > MAX_LIVE) {
        foreach (array_slice($kept, offset: MAX_LIVE) as $id) {
            delete_option(option_name($id));
            delete_option(LOCK_PREFIX . $id);
        }
        $kept = array_slice($kept, offset: 0, length: MAX_LIVE);
    }

    write_index($kept);
}

/**
 * Claim a preview for application.
 *
 * add_option() returns false when the row already exists, which makes it a
 * compare-and-set without a transaction — the idiom the Gutenberg queue already
 * relies on in status_transition_lock(). Two administrators clicking Apply on
 * the same preview cannot both proceed.
 */
function claim_lock(string $id): bool
{
    if (!is_valid_id($id)) {
        return false;
    }
    $name = LOCK_PREFIX . $id;
    if (add_option($name, (string) time(), deprecated: '', autoload: 'no')) {
        return true;
    }

    $claimed_at = (int) get_option($name, default_value: 0);
    if ($claimed_at > time() - APPLY_STALE_SECONDS) {
        return false;
    }

    delete_option($name);
    return add_option($name, (string) time(), deprecated: '', autoload: 'no');
}

function release_lock(string $id): void
{
    if (is_valid_id($id)) {
        delete_option(LOCK_PREFIX . $id);
    }
}

/**
 * Reject anything that is not a UUID before it reaches an option name.
 */
function is_valid_id(string $id): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
}

/**
 * Encrypt the exact ability input retained for a later apply.
 */
function encode_input(mixed $input): string|\WP_Error
{
    if (!function_exists('openssl_encrypt')) {
        return new \WP_Error(
            'wppilot_preview_crypto_unavailable',
            'This server cannot encrypt preview inputs, so the preview was not stored.',
            ['status' => 500],
        );
    }

    $json = wp_json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return new \WP_Error('wppilot_preview_encode_failed', 'The preview input could not be encoded.', ['status' => 500]);
    }

    try {
        $iv = random_bytes(12);
    } catch (\Throwable) {
        return new \WP_Error('wppilot_preview_crypto_failed', 'The preview input could not be encrypted.', ['status' => 500]);
    }

    $tag = '';
    $ciphertext = openssl_encrypt(
        $json,
        'aes-256-gcm',
        hash('sha256', wp_salt('auth'), binary: true),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        PAYLOAD_AAD,
        16,
    );
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        return new \WP_Error('wppilot_preview_crypto_failed', 'The preview input could not be encrypted.', ['status' => 500]);
    }

    $envelope = wp_json_encode([
        'v' => 1,
        'alg' => 'A256GCM',
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'ciphertext' => base64_encode($ciphertext),
    ], JSON_UNESCAPED_SLASHES);

    return is_string($envelope)
        ? $envelope
        : new \WP_Error('wppilot_preview_encode_failed', 'The encrypted preview input could not be encoded.', ['status' => 500]);
}

/**
 * Decode and authenticate an exact ability input.
 */
function decode_input(string $payload): mixed
{
    if (!function_exists('openssl_decrypt')) {
        return new \WP_Error('wppilot_preview_crypto_unavailable', 'This server cannot decrypt the preview input.', ['status' => 500]);
    }

    /** @var mixed $envelope */
    $envelope = json_decode($payload, associative: true);
    if (!is_array($envelope) || ($envelope['v'] ?? null) !== 1 || ($envelope['alg'] ?? '') !== 'A256GCM') {
        return new \WP_Error('wppilot_preview_payload_invalid', 'The stored preview input is invalid.', ['status' => 409]);
    }

    $iv = base64_decode((string) ($envelope['iv'] ?? ''), strict: true);
    $tag = base64_decode((string) ($envelope['tag'] ?? ''), strict: true);
    $ciphertext = base64_decode((string) ($envelope['ciphertext'] ?? ''), strict: true);
    if (!is_string($iv) || strlen($iv) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($ciphertext)) {
        return new \WP_Error('wppilot_preview_payload_invalid', 'The stored preview input is invalid.', ['status' => 409]);
    }

    $json = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        hash('sha256', wp_salt('auth'), binary: true),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        PAYLOAD_AAD,
    );
    if (!is_string($json)) {
        return new \WP_Error('wppilot_preview_payload_tampered', 'The stored preview input failed authentication.', ['status' => 409]);
    }

    /** @var mixed $decoded */
    $decoded = json_decode($json, associative: true);
    return json_last_error() === JSON_ERROR_NONE
        ? $decoded
        : new \WP_Error('wppilot_preview_payload_invalid', 'The stored preview input is invalid.', ['status' => 409]);
}
