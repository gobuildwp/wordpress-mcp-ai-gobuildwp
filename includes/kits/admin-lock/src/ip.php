<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\AdminLock;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Address parsing and matching, copied from the security-protection kit so this kit carries no
 * dependency on it. Keep the two in step when one of them is fixed.
 */

/**
 * Cloudflare's published edge ranges (cloudflare.com/ips). A CF-Connecting-IP header is only
 * believed when the connection itself comes from one of these; anyone else can set the header.
 */
const CLOUDFLARE_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
    '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
    '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
    '2a06:98c0::/29', '2c0f:f248::/32',
];

/**
 * The visitor's address, from REMOTE_ADDR unless the setting says the site sits behind Cloudflare
 * or a named proxy and this connection really comes from it. A forged header sent straight to the
 * server would otherwise let anyone claim an allowed address. With X-Forwarded-For the rightmost
 * address that is not one of the trusted proxies is used: anything to its left was sent by the
 * client and can be forged.
 *
 * @param array<string, mixed> $server
 * @param list<string> $proxies Normalised trusted proxy addresses and ranges.
 */
function client_ip(array $server, string $source, array $proxies): string
{
    $remote = is_string($server['REMOTE_ADDR'] ?? null) ? trim($server['REMOTE_ADDR']) : '';
    if (!is_ip($remote)) {
        return '';
    }
    $remote = normalize_ip($remote);
    if ($source === 'cloudflare' && ip_in_list($remote, cidr_list(CLOUDFLARE_RANGES))) {
        $cf = is_string($server['HTTP_CF_CONNECTING_IP'] ?? null) ? trim($server['HTTP_CF_CONNECTING_IP']) : '';
        if (is_ip($cf)) {
            return normalize_ip($cf);
        }
    } elseif ($source === 'x_forwarded_for' && $proxies !== [] && ip_in_list($remote, $proxies)) {
        $xff = is_string($server['HTTP_X_FORWARDED_FOR'] ?? null) ? $server['HTTP_X_FORWARDED_FOR'] : '';
        foreach (array_reverse(array_map('trim', explode(',', $xff))) as $part) {
            if (is_ip($part) && !ip_in_list($part, $proxies)) {
                return normalize_ip($part);
            }
        }
    }
    return $remote;
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

/**
 * @return list<string>
 */
function cidr_list(mixed $values): array
{
    $out = [];
    foreach (is_array($values) ? $values : [] as $value) {
        $cidr = is_string($value) ? normalize_cidr($value) : '';
        if ($cidr !== '') {
            $out[] = $cidr;
        }
    }
    return array_values(array_unique($out));
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
 * An address or a CIDR range, normalised; '' when it is neither.
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
