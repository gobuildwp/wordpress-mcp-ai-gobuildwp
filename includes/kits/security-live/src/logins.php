<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityLive;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Failed sign-ins, rolled up: counts per network (IPv4 /24, IPv6 /48) in a short window and
 * records one event when a network or the whole site crosses a threshold, never one per attempt.
 * Nothing is blocked; the counts live in one small option.
 */
const LOGINS_OPTION = 'gobuildwp_live_login_failures';
const LOGIN_WINDOW = 900;
const LOGIN_NETWORK_THRESHOLD = 10;
const LOGIN_SITE_THRESHOLD = 50;
const LOGIN_MAX_NETWORKS = 200;

/** The caller's network, masked so no full address is stored; '' when unknown. */
function login_network(?string $remote = null): string
{
    $ip = trim((string) ($remote ?? ($_SERVER['REMOTE_ADDR'] ?? '')));
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $parts = explode('.', $ip);
        return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return '';
        }
        $masked = substr($packed, 0, 6) . str_repeat("\0", 10);
        $text = inet_ntop($masked);
        return $text === false ? '' : $text . '/48';
    }
    return '';
}

/**
 * @return array{site: array{start: int, count: int, flagged: bool}, nets: array<string, array{start: int, count: int, flagged: bool}>}
 */
function login_state(int $now): array
{
    /** @var mixed $raw */
    $raw = get_option(LOGINS_OPTION, []);
    $raw = is_array($raw) ? $raw : [];
    $fresh = static fn(mixed $w): bool => is_array($w) && isset($w['start'], $w['count']) && is_int($w['start']) && is_int($w['count']) && $w['start'] > $now - LOGIN_WINDOW;
    $norm = static fn(array $w): array => ['start' => (int) $w['start'], 'count' => (int) $w['count'], 'flagged' => !empty($w['flagged'])];
    $site = isset($raw['site']) && $fresh($raw['site']) ? $norm($raw['site']) : ['start' => $now, 'count' => 0, 'flagged' => false];
    $nets = [];
    foreach (is_array($raw['nets'] ?? null) ? $raw['nets'] : [] as $net => $w) {
        if (is_string($net) && $fresh($w)) {
            $nets[$net] = $norm($w);
        }
    }
    return ['site' => $site, 'nets' => $nets];
}

/** wp_login_failed: count, and record an event only when a threshold is crossed. */
function on_login_failed(mixed $username = '', ?int $now = null, ?string $remote = null): void
{
    $now ??= time();
    $net = login_network($remote);
    $state = login_state($now);

    $state['site']['count']++;
    if (!$state['site']['flagged'] && $state['site']['count'] >= LOGIN_SITE_THRESHOLD) {
        $state['site']['flagged'] = true;
        record('high', 'login_failed', 'Failed sign-ins on the site', [
            'rule_id' => 'logins.failed_many',
            'count' => $state['site']['count'],
            'window' => LOGIN_WINDOW,
            'note' => sprintf('%d failed sign-ins within %d minutes.', $state['site']['count'], intdiv(LOGIN_WINDOW, 60)),
        ], $now);
    }

    if ($net !== '') {
        $w = $state['nets'][$net] ?? ['start' => $now, 'count' => 0, 'flagged' => false];
        $w['count']++;
        if (!$w['flagged'] && $w['count'] >= LOGIN_NETWORK_THRESHOLD) {
            $w['flagged'] = true;
            record('medium', 'login_failed', 'Failed sign-ins from ' . $net, [
                'rule_id' => 'logins.failed_burst',
                'network' => $net,
                'count' => $w['count'],
                'window' => LOGIN_WINDOW,
                'note' => sprintf('%d failed sign-ins from one network within %d minutes.', $w['count'], intdiv(LOGIN_WINDOW, 60)),
            ], $now);
        }
        unset($state['nets'][$net]);
        $state['nets'][$net] = $w;
        if (count($state['nets']) > LOGIN_MAX_NETWORKS) {
            $state['nets'] = array_slice($state['nets'], -LOGIN_MAX_NETWORKS, null, true);
        }
    }
    update_option(LOGINS_OPTION, $state, false);
}

/** wp_login: a sign-in from a network that just had a burst of failures is worth a look. */
function on_login(mixed $user_login = '', mixed $user = null, ?int $now = null, ?string $remote = null): void
{
    $now ??= time();
    $net = login_network($remote);
    if ($net === '') {
        return;
    }
    $state = login_state($now);
    if (empty($state['nets'][$net]['flagged'])) {
        return;
    }
    $login = is_string($user_login) ? $user_login : '';
    record('high', 'login_after_failures', 'Sign-in: ' . ($login !== '' ? $login : 'unknown user'), [
        'rule_id' => 'logins.success_after_failures',
        'network' => $net,
        'count' => $state['nets'][$net]['count'],
        'window' => LOGIN_WINDOW,
        'label' => 'user:' . $login,
        'note' => 'Signed in from a network that had many failed sign-ins just before.',
    ], $now);
}
