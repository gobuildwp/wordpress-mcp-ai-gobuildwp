<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Verify a GoBuildWP signed audit export offline, without WordPress and without the site.
 *
 *   php verify-ledger-export.php gobuildwp-ledger-20261113-120000.ndjson [--public-key=BASE64]
 *
 * Checks, in order:
 *   1. every row's contents against its content hash, and its row_hash against prev_hash + content;
 *   2. every row's prev_hash against the row_hash before it ("chain break at row N");
 *   3. the last row against the head the site recorded;
 *   4. the Ed25519 signature over every byte before the signature line.
 *
 * The public key inside the export proves only that whoever made the file held that key. Pass the
 * key you recorded from the site's Changes screen (or GoBuildWP Cloud) with --public-key, or a
 * forger can simply sign with a key of their own.
 *
 * Exit status: 0 the chain is intact and the signature checks against the pinned key; 1 something
 * failed or could not be proved; 2 usage or an unreadable file. Needs PHP 8.0+ with sodium.
 *
 * The hashing here must match includes/ledger-chain.php exactly.
 */

const VERIFY_UNSEALED_KEYS = [
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

const VERIFY_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

function verify_sorted(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    $sorted = [];
    foreach ($value as $key => $item) {
        $sorted[$key] = verify_sorted($item);
    }
    if ($sorted !== [] && array_keys($sorted) !== range(0, count($sorted) - 1)) {
        ksort($sorted, SORT_STRING);
    }

    return $sorted;
}

function verify_canonical(mixed $value): string
{
    $encoded = json_encode($value, VERIFY_JSON_FLAGS);
    $plain = is_string($encoded) ? json_decode($encoded, true) : null;

    return (string) json_encode(verify_sorted($plain), VERIFY_JSON_FLAGS);
}

function verify_is_hash(mixed $value): bool
{
    return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
}

/** @param list<string> $argv */
function verify_main(array $argv): int
{
    $path = null;
    $pinned = null;
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--public-key=')) {
            $pinned = substr($arg, strlen('--public-key='));
        } elseif ($path === null && !str_starts_with($arg, '--')) {
            $path = $arg;
        } else {
            fwrite(STDERR, "Usage: php verify-ledger-export.php EXPORT.ndjson [--public-key=BASE64]\n");

            return 2;
        }
    }
    if ($path === null) {
        fwrite(STDERR, "Usage: php verify-ledger-export.php EXPORT.ndjson [--public-key=BASE64]\n");

        return 2;
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        fwrite(STDERR, "Cannot read {$path}\n");

        return 2;
    }

    $digest = hash_init('sha256');
    $header = null;
    $signature = null;
    $site_report = null;
    $failures = [];
    $rows = 0;
    $sealed = 0;
    $unsealed = 0;
    $last_hash = '';
    $line_number = 0;
    while (($line = fgets($handle)) !== false) {
        $line_number++;
        if ($signature !== null) {
            $failures[] = "line {$line_number}: data after the signature line";
            break;
        }
        $record = json_decode($line, true);
        if (!is_array($record)) {
            $failures[] = "line {$line_number}: not JSON";
            hash_update($digest, $line);
            continue;
        }
        $type = $record['type'] ?? '';
        if ($type === 'signature') {
            $signature = $record;
            continue;
        }
        hash_update($digest, $line);
        if ($type === 'header') {
            $header = $record;
            if (($record['format'] ?? '') !== 'gobuildwp-ledger' || ($record['version'] ?? 0) !== 1) {
                $failures[] = 'not a GoBuildWP ledger export, or a version this verifier does not know';
            }
            if (($record['unsealed_keys'] ?? null) !== VERIFY_UNSEALED_KEYS) {
                $failures[] = 'the export leaves different keys out of the hash than this verifier does; use the verifier from the same release';
            }
            continue;
        }
        if ($type === 'report') {
            $site_report = $record;
            continue;
        }
        if ($type !== 'row') {
            $failures[] = "line {$line_number}: unknown record type";
            continue;
        }

        $rows++;
        $prev = $record['prev_hash'] ?? null;
        $hash = $record['row_hash'] ?? null;
        $entry = $record['entry'] ?? null;
        if (!verify_is_hash($prev) || !verify_is_hash($hash)) {
            if ($sealed === 0) {
                $unsealed++;
            } else {
                $failures[] = "chain break at row {$rows}: it carries no hash";
            }
            continue;
        }
        if ($sealed > 0 && !hash_equals($last_hash, $prev)) {
            $failures[] = "chain break at row {$rows}: it does not follow the row before it (a row was deleted or reordered)";
        }
        $sealed++;
        $last_hash = $hash;
        $content = is_array($entry) ? hash('sha256', verify_canonical(array_diff_key($entry, array_flip(VERIFY_UNSEALED_KEYS)))) : null;
        if ($content === null || !hash_equals(hash('sha256', $prev . $content), $hash)) {
            $failures[] = "chain break at row {$rows}: its contents do not match its hash (changed after it was recorded)";
        }
    }
    fclose($handle);

    if ($header === null) {
        $failures[] = 'no header line';
    } else {
        $head = (string) ($header['head'] ?? '');
        if ($head !== '' && !hash_equals($head, $last_hash)) {
            $failures[] = 'the last row is not the newest row the site recorded: rows at the end were deleted';
        } elseif ($head === '' && $sealed > 0) {
            $failures[] = 'the site has no record of its newest row, so the end of the chain cannot be proved complete';
        }
    }

    $signed = false;
    $final = hash_final($digest);
    if ($signature === null) {
        $failures[] = 'no signature line: the file is truncated';
    } elseif (($signature['alg'] ?? '') !== 'Ed25519') {
        $failures[] = 'the export is not signed: ' . (string) ($signature['reason'] ?? 'no reason given');
    } elseif (!hash_equals((string) ($signature['sha256'] ?? ''), $final)) {
        $failures[] = 'the file was changed after it was signed (its SHA-256 does not match the signed one)';
    } elseif (!function_exists('sodium_crypto_sign_verify_detached')) {
        $failures[] = 'this PHP has no sodium, so the signature cannot be checked';
    } else {
        $key = base64_decode((string) ($pinned ?? ($signature['public_key'] ?? '')), true);
        $sig = base64_decode((string) ($signature['signature'] ?? ''), true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !is_string($sig) || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            $failures[] = 'the public key or signature is malformed';
        } elseif (!sodium_crypto_sign_verify_detached($sig, $final, $key)) {
            $failures[] = $pinned !== null ? 'the signature does not match the pinned public key' : 'the signature does not match the key in the file';
        } else {
            $signed = true;
        }
    }
    if ($signed && $pinned === null) {
        $failures[] = 'signature valid for the key inside the file, but no --public-key was given, so who signed it is unproven';
    }

    fwrite(STDOUT, sprintf(
        "%d rows: %d hashed, %d from before the chain. Site: %s. Exported: %s.\n",
        $rows,
        $sealed,
        $unsealed,
        (string) ($header['site'] ?? '?'),
        (string) ($header['exported_at'] ?? '?'),
    ));
    if (is_array($site_report) && ($site_report['intact'] ?? null) === false) {
        fwrite(STDOUT, 'The site itself reported ' . (int) ($site_report['break_count'] ?? 0) . " chain break(s) when it exported.\n");
    }
    if ($failures === []) {
        fwrite(STDOUT, "VERIFIED: chain intact and signed by the pinned key.\n");

        return 0;
    }
    foreach ($failures as $failure) {
        fwrite(STDOUT, "FAIL: {$failure}\n");
    }

    return 1;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(verify_main($_SERVER['argv'] ?? []));
}
