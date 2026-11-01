<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityProtection;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The visitor's address, from REMOTE_ADDR unless the site says it sits behind Cloudflare or a
 * proxy that appends X-Forwarded-For. With X-Forwarded-For the rightmost valid address is used:
 * the proxy appends the address it saw, and anything to its left was sent by the client and can
 * be forged.
 *
 * @param array<string, mixed> $server
 */
function client_ip(array $server, string $source): string
{
    $remote = is_string($server['REMOTE_ADDR'] ?? null) ? trim($server['REMOTE_ADDR']) : '';
    if ($source === 'cloudflare') {
        $cf = is_string($server['HTTP_CF_CONNECTING_IP'] ?? null) ? trim($server['HTTP_CF_CONNECTING_IP']) : '';
        if (is_ip($cf)) {
            return normalize_ip($cf);
        }
    } elseif ($source === 'x_forwarded_for') {
        $xff = is_string($server['HTTP_X_FORWARDED_FOR'] ?? null) ? $server['HTTP_X_FORWARDED_FOR'] : '';
        $parts = array_reverse(array_map('trim', explode(',', $xff)));
        foreach ($parts as $part) {
            if (is_ip($part)) {
                return normalize_ip($part);
            }
        }
    }
    return is_ip($remote) ? normalize_ip($remote) : '';
}

/**
 * Proxy headers present on this request, so the status can hint that ip_source is wrong.
 *
 * @param array<string, mixed> $server
 * @return list<string>
 */
function proxy_headers_seen(array $server): array
{
    $seen = [];
    if (!empty($server['HTTP_CF_CONNECTING_IP'])) {
        $seen[] = 'cloudflare';
    }
    if (!empty($server['HTTP_X_FORWARDED_FOR'])) {
        $seen[] = 'x_forwarded_for';
    }
    return $seen;
}

function is_ip(string $value): bool
{
    return $value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false;
}

function normalize_ip(string $ip): string
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return $ip;
    }
    // An IPv4-mapped IPv6 address is the IPv4 address.
    if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
        $packed = substr($packed, 12);
    }
    $out = inet_ntop($packed);
    return $out === false ? $ip : $out;
}

/**
 * An address or a CIDR range, normalized; '' when it's neither.
 */
function normalize_cidr(string $value): string
{
    $value = trim($value);
    if (is_ip($value)) {
        return normalize_ip($value);
    }
    if (!preg_match('#^([0-9a-fA-F:.]+)/(\d{1,3})$#', $value, $m) || !is_ip($m[1])) {
        return '';
    }
    $ip = normalize_ip($m[1]);
    $max = str_contains($ip, ':') ? 128 : 32;
    $bits = (int) $m[2];
    if ($bits < 1 || $bits > $max) {
        return '';
    }
    return $bits === $max ? $ip : $ip . '/' . $bits;
}

/**
 * Is $ip the address, or inside the range, $cidr?
 */
function ip_matches(string $ip, string $cidr): bool
{
    if ($ip === '' || $cidr === '') {
        return false;
    }
    if (!str_contains($cidr, '/')) {
        return normalize_ip($ip) === $cidr;
    }
    [$net, $bits] = explode('/', $cidr, 2);
    $a = @inet_pton(normalize_ip($ip));
    $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) {
        return false;
    }
    $bits = (int) $bits;
    $bytes = intdiv($bits, 8);
    if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xff << (8 - $rest)) & 0xff;
    return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
}

/**
 * @param list<string> $list
 */
function ip_in_list(string $ip, array $list): bool
{
    foreach ($list as $cidr) {
        if (ip_matches($ip, $cidr)) {
            return true;
        }
    }
    return false;
}
