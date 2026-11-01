<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityTwoFactor;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Time-based one-time passwords (RFC 6238 over RFC 4226 HOTP): 30-second steps, 6 digits, SHA1,
 * which is what every authenticator app reads from an otpauth:// URI without asking. Pure
 * functions; nothing here touches WordPress.
 */

const PERIOD = 30;
const DIGITS = 6;
/** Steps either side of now that still count, for a phone clock that drifts. */
const WINDOW = 1;
/** 20 bytes, the HMAC-SHA1 block RFC 4226 recommends; 32 base32 characters. */
const SECRET_BYTES = 20;

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32_encode(string $bytes): string
{
    // str_split('') is [''] before PHP 8.2.
    if ($bytes === '') {
        return '';
    }
    $bits = '';
    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= BASE32_ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

/**
 * Decode base32, ignoring case, spaces, hyphens and padding. Null for any other character.
 */
function base32_decode(string $text): ?string
{
    $text = strtoupper((string) preg_replace('/[\s\-=]+/', '', $text));
    $bits = '';
    foreach (str_split($text) as $char) {
        if ($char === '') {
            continue;
        }
        $value = strpos(BASE32_ALPHABET, $char);
        if ($value === false) {
            return null;
        }
        $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $out .= chr((int) bindec($chunk));
        }
    }
    return $out;
}

function new_secret(): string
{
    return random_bytes(SECRET_BYTES);
}

/**
 * RFC 4226 HOTP: dynamic truncation of HMAC(secret, counter) to $digits decimal digits.
 */
function hotp(string $secret, int $counter, int $digits = DIGITS, string $algo = 'sha1'): string
{
    $hash = hash_hmac($algo, pack('J', $counter), $secret, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3]);
    return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

function time_step(int $now): int
{
    return intdiv($now, PERIOD);
}

function totp(string $secret, int $now, int $digits = DIGITS, string $algo = 'sha1'): string
{
    return hotp($secret, time_step($now), $digits, $algo);
}

/**
 * The time step a code matches, or null.
 *
 * Only steps after $last_step count, so a code that was used once is refused the second time
 * (and so is any older one): that is the replay protection. Every candidate in the window is
 * compared with hash_equals() and the loop never stops early, so the time taken does not say
 * which step matched or whether one did.
 */
function verify_totp(string $secret, string $code, int $now, int $last_step = 0): ?int
{
    $code = (string) preg_replace('/\s+/', '', $code);
    if ($secret === '' || preg_match('/^\d{' . DIGITS . '}$/', $code) !== 1) {
        return null;
    }
    $current = time_step($now);
    $matched = null;
    for ($step = $current - WINDOW; $step <= $current + WINDOW; $step++) {
        $ok = hash_equals(hotp($secret, $step), $code);
        if ($ok && $step > $last_step && $matched === null) {
            $matched = $step;
        }
    }
    return $matched;
}

/**
 * The otpauth:// URI authenticator apps scan: label "Issuer:account", the secret in base32 without
 * padding, and the issuer again as a parameter (Google Authenticator reads the parameter, older
 * apps the label prefix).
 */
function otpauth_uri(string $secret, string $account, string $issuer): string
{
    $issuer = trim(str_replace(':', '', $issuer));
    $account = trim(str_replace(':', '', $account));
    $label = rawurlencode($issuer) . ':' . rawurlencode($account);
    return 'otpauth://totp/' . $label . '?' . http_build_query(
        [
            'secret' => base32_encode($secret),
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => DIGITS,
            'period' => PERIOD,
        ],
        '',
        '&',
        PHP_QUERY_RFC3986,
    );
}
