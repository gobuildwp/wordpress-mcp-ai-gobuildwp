<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SecurityStatus;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * What the site's security plugin says about itself, its latest scan and who it is blocking.
 *
 * Two vendors answer, each optional: Wordfence (src/wordfence.php) and Solid Security, which
 * ships from the `better-wp-security` slug under the ITSEC_Core class and is branded "Kadence
 * Security" since 10.0 (src/solid.php). A site may run both, so every result lists what each
 * provider said and names it; one provider throwing is reported against that provider and never
 * hides the other's answer.
 *
 * Everything here is read-only. The data is also the kind a model should not repeat: lockout
 * reasons embed the username an attacker typed (sometimes an email), block lists hold visitor
 * IPs, and the vendors keep licence and API keys next to the settings read here. So IPs leave as
 * their /24 (IPv4) or /48 (IPv6) network, a username becomes the user id when the account exists
 * and a masked form when it does not, email addresses are removed from any free text, and no key,
 * licence or secret is ever read into a result.
 */

const PROVIDERS = ['wordfence', 'solid-security'];

/** Severities, most severe first. Each vendor's own scale is mapped onto these. */
const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

const DEFAULT_LIMIT = 50;
const MAX_LIMIT = 200;

/**
 * The providers active on this site, in PROVIDERS order.
 *
 * @return list<string>
 */
function active_providers(): array
{
    $active = [];
    if (wordfence_active()) {
        $active[] = 'wordfence';
    }
    if (solid_active()) {
        $active[] = 'solid-security';
    }
    return $active;
}

/**
 * The providers one call should ask: all active ones, or the one named.
 *
 * @param array<string, mixed> $input
 * @return list<string>|WP_Error
 */
function selected_providers(array $input): array|WP_Error
{
    $active = active_providers();
    $wanted = (string) ($input['provider'] ?? 'any');
    if ($wanted === '' || $wanted === 'any') {
        if ($active === []) {
            return new WP_Error(
                'security_no_provider',
                __('Neither Wordfence nor Solid Security is active on this site.', domain: 'wppilot'),
            );
        }
        return $active;
    }
    if (!in_array($wanted, PROVIDERS, true)) {
        return new WP_Error('security_unknown_provider', sprintf(
            /* translators: %s: provider slug */
            __('Unknown provider "%s". Use wordfence or solid-security.', domain: 'wppilot'),
            $wanted,
        ));
    }
    if (!in_array($wanted, $active, true)) {
        return new WP_Error('security_provider_inactive', sprintf(
            /* translators: %s: provider slug */
            __('%s is not active on this site.', domain: 'wppilot'),
            $wanted,
        ));
    }
    return [$wanted];
}

/**
 * Run one provider's reader, turning a vendor failure into a note against that provider.
 *
 * Vendor internals change between releases; a method that moved must cost the caller one
 * provider's answer, not the whole call.
 *
 * @param callable(): array<string, mixed> $reader
 * @return array<string, mixed>
 */
function guarded(string $provider, callable $reader): array
{
    try {
        return $reader();
    } catch (\Throwable $e) {
        return [
            'provider' => $provider,
            'error' => sprintf(
                /* translators: 1: provider slug, 2: exception class */
                __('%1$s could not be read (%2$s); its plugin version may have changed an internal API.', domain: 'wppilot'),
                $provider,
                get_class($e),
            ),
        ];
    }
}

/**
 * wppilot/security-plugin-status.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function plugin_status(array $input = []): array|WP_Error
{
    $providers = selected_providers($input);
    if ($providers instanceof WP_Error) {
        return $providers;
    }
    $out = [];
    foreach ($providers as $provider) {
        $out[] = guarded($provider, static fn(): array => $provider === 'wordfence' ? wordfence_status() : solid_status());
    }
    return [
        'providers' => $out,
        'inactive' => array_values(array_diff(PROVIDERS, active_providers())),
    ];
}

/**
 * wppilot/security-scan-findings.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function scan_findings(array $input = []): array|WP_Error
{
    $providers = selected_providers($input);
    if ($providers instanceof WP_Error) {
        return $providers;
    }
    $limit = clamp_limit($input['limit'] ?? null);
    $severities = array_values(array_intersect(SEVERITIES, array_map('strval', (array) ($input['severity'] ?? []))));

    $findings = [];
    $counts = array_fill_keys(SEVERITIES, 0);
    $sources = [];
    $truncated = false;
    foreach ($providers as $provider) {
        $result = guarded($provider, static fn(): array => $provider === 'wordfence'
            ? wordfence_findings($severities, $limit)
            : solid_findings($severities, $limit));
        foreach ((array) ($result['counts'] ?? []) as $severity => $n) {
            if (isset($counts[$severity])) {
                $counts[$severity] += (int) $n;
            }
        }
        foreach ((array) ($result['findings'] ?? []) as $finding) {
            $findings[] = $finding;
        }
        $truncated = $truncated || !empty($result['truncated']);
        unset($result['findings'], $result['counts'], $result['truncated']);
        $sources[] = $result;
    }

    // Most severe first across providers, then newest.
    $rank = array_flip(SEVERITIES);
    usort($findings, static function (array $a, array $b) use ($rank): int {
        return [$rank[$a['severity']] ?? 9, (string) ($b['first_seen'] ?? '')]
            <=> [$rank[$b['severity']] ?? 9, (string) ($a['first_seen'] ?? '')];
    });
    if (count($findings) > $limit) {
        $findings = array_slice($findings, 0, $limit);
        $truncated = true;
    }

    return [
        'counts' => $counts,
        'total' => array_sum($counts),
        'returned' => count($findings),
        'truncated' => $truncated,
        'findings' => $findings,
        'sources' => $sources,
    ];
}

/**
 * wppilot/security-lockouts.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function lockouts(array $input = []): array|WP_Error
{
    $providers = selected_providers($input);
    if ($providers instanceof WP_Error) {
        return $providers;
    }
    $limit = clamp_limit($input['limit'] ?? null);
    $include_expired = (bool) ($input['include_expired'] ?? true);

    $entries = [];
    $sources = [];
    foreach ($providers as $provider) {
        // One more than asked, so a list cut at exactly `limit` still reports truncated.
        $result = guarded($provider, static fn(): array => $provider === 'wordfence'
            ? wordfence_lockouts($limit + 1)
            : solid_lockouts($limit + 1, $include_expired));
        foreach ((array) ($result['lockouts'] ?? []) as $entry) {
            $entries[] = $entry;
        }
        unset($result['lockouts']);
        $sources[] = $result;
    }
    if (!$include_expired) {
        $entries = array_values(array_filter($entries, static fn(array $e): bool => !empty($e['active'])));
    }
    usort($entries, static fn(array $a, array $b): int => strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? '')));
    $truncated = count($entries) > $limit;
    // Each provider counts its active blocks itself, so the total holds when limit cuts the list.
    $active = 0;
    foreach ($sources as $source) {
        $active += (int) ($source['active_total'] ?? 0);
    }

    return [
        'active' => $active,
        'returned' => min($limit, count($entries)),
        'truncated' => $truncated,
        'lockouts' => array_slice($entries, 0, $limit),
        'sources' => $sources,
    ];
}

function clamp_limit(mixed $limit): int
{
    $limit = is_numeric($limit) ? (int) $limit : DEFAULT_LIMIT;
    return max(1, min(MAX_LIMIT, $limit));
}

// --- Redaction ----------------------------------------------------------------------------

/**
 * The network an address belongs to: /24 for IPv4, /48 for IPv6. Null when it is not an IP, so
 * an unexpected value (a hostname, a range string) is dropped rather than echoed back whole.
 */
function mask_ip(string $ip): ?string
{
    return mask_network(trim($ip), PHP_INT_MAX);
}

/**
 * A banned host or range, no narrower than mask_ip() allows. A range already wider than /24 or
 * /48 is kept at its own width; a legacy wildcard (`203.0.113.*`) counts its fixed octets.
 */
function mask_host(string $host): ?string
{
    $host = trim($host);
    if (str_contains($host, '*')) {
        $octets = explode('.', $host);
        $fixed = 0;
        foreach ($octets as $octet) {
            if ($octet === '*') {
                break;
            }
            ++$fixed;
        }
        $address = implode('.', array_map(static fn(string $o): string => $o === '*' ? '0' : $o, $octets));
        return mask_network($address, $fixed * 8);
    }
    if (str_contains($host, '/')) {
        [$address, $bits] = explode('/', $host, 2);
        return is_numeric($bits) ? mask_network($address, (int) $bits) : null;
    }
    return mask_network($host, PHP_INT_MAX);
}

function mask_network(string $address, int $prefix): ?string
{
    if (filter_var($address, FILTER_VALIDATE_IP) === false) {
        return null;
    }
    $binary = (string) inet_pton($address);
    // An IPv4 address stored as IPv6 (::ffff:a.b.c.d), as Wordfence does, is masked as IPv4.
    if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")) {
        $binary = substr($binary, 12);
        $prefix = $prefix === PHP_INT_MAX ? $prefix : max(0, $prefix - 96);
    }
    $bits = min($prefix, strlen($binary) === 4 ? 24 : 48);
    $masked = '';
    for ($i = 0, $n = strlen($binary); $i < $n; ++$i) {
        $keep = max(0, min(8, $bits - $i * 8));
        $mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
        $masked .= chr(ord($binary[$i]) & $mask);
    }
    return inet_ntop($masked) . '/' . $bits;
}

/**
 * Who a username or login identifier refers to, without repeating it.
 *
 * An account on this site is named by its id, which the caller can look up with the rights it
 * already has. Anything else is an attacker's guess, or a real person's email address typed
 * into the login form; it becomes its first character and asterisks.
 *
 * @return array{user_id?: int, username?: string}
 */
function describe_login(string $login): array
{
    $login = trim($login);
    if ($login === '') {
        return [];
    }
    $user = get_user_by('login', $login);
    if (!$user && str_contains($login, '@')) {
        $user = get_user_by('email', $login);
    }
    if ($user) {
        return ['user_id' => (int) $user->ID];
    }
    return ['username' => mask_login($login)];
}

function mask_login(string $login): string
{
    $first = function_exists('mb_substr') ? mb_substr($login, 0, 1) : substr($login, 0, 1);
    return $first . '****';
}

/**
 * Vendor free text with email addresses removed and IPs cut to their network.
 */
function redact_text(string $text, int $max = 300): string
{
    // Only real tags are removed. wp_strip_all_tags() reads the "<" of a vulnerability title such
    // as "Plugin <= 1.7.2 - Stored XSS" as a tag opening and drops everything after it.
    $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $text);
    $text = (string) preg_replace('#</?[a-z!][^<>]*>#i', '', $text);
    $text = trim((string) preg_replace('/\s+/', ' ', $text));
    $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text);
    $text = (string) preg_replace_callback(
        '/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])/',
        static fn(array $m): string => mask_ip($m[0]) ?? $m[0],
        $text,
    );
    $text = (string) preg_replace_callback(
        '/(?<![0-9A-F:])(?:[0-9A-F]{0,4}:){2,7}[0-9A-F]{0,4}(?![0-9A-F:])/i',
        static fn(array $m): string => mask_ip($m[0]) ?? $m[0],
        $text,
    );
    if (strlen($text) > $max) {
        $text = rtrim(substr($text, 0, $max - 1)) . '…';
    }
    return $text;
}

/**
 * A file path relative to ABSPATH. A path outside it keeps only its file name: the server's
 * directory layout is not the model's business.
 */
function relative_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $root = rtrim(str_replace('\\', '/', ABSPATH), '/') . '/';
    if (str_starts_with($path, $root)) {
        return ltrim(substr($path, strlen($root)), '/');
    }
    if ($path !== '' && $path[0] !== '/' && !preg_match('#^[A-Za-z]:/#', $path)) {
        return ltrim((string) preg_replace('#^(\./)+#', '', $path), '/');
    }
    return '…/' . basename($path);
}

/** A Unix timestamp as ISO 8601 UTC, or null for "never". */
function iso(mixed $timestamp): ?string
{
    if (!is_numeric($timestamp) || (float) $timestamp <= 0) {
        return null;
    }
    return gmdate('Y-m-d\TH:i:s\Z', (int) $timestamp);
}

/** A MySQL UTC datetime as ISO 8601 UTC, or null. */
function iso_gmt(mixed $datetime): ?string
{
    if (!is_string($datetime) || $datetime === '' || str_starts_with($datetime, '0000')) {
        return null;
    }
    $time = strtotime($datetime . ' UTC');
    return $time === false ? null : iso($time);
}
