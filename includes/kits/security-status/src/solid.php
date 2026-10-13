<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SecurityStatus;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Solid Security (formerly iThemes Security; the `better-wp-security` plugin, branded "Kadence
 * Security" since 10.0), read through its own classes (verified against 10.0.4).
 *
 * - Identity: ITSEC_Core::get_plugin_name(), get_plugin_version(), is_pro(). is_licensed() is not
 *   called: it includes the updater's licensing code, and a licence is not this kit's business.
 * - Features: ITSEC_Modules::is_active() for 'firewall', 'brute-force', 'network-brute-force',
 *   'two-factor' and 'malware-scheduling'; thresholds from ITSEC_Modules::get_setting().
 * - Scans: the site scanner runs on the vendor's cloud and stores each result in its log. The
 *   latest one comes from the container's Scans_Repository; with the log type set to "file" that
 *   repository only knows scans from the current request, so the result says no scan is on
 *   record. The next scheduled scan is the scheduler's recurring 'malware-scan' event.
 * - Lockouts: global $itsec_lockout, ITSEC_Lockout::get_lockouts(), which reads current and
 *   lifted lockouts alike; bans come from the Ban_Hosts Multi_Repository.
 */

const SOLID_SCANS_REPOSITORY = 'iThemesSecurity\\Site_Scanner\\Repository\\Scans_Repository';
const SOLID_SCAN_QUERY = 'iThemesSecurity\\Site_Scanner\\Repository\\Scans_Options';
const SOLID_VULNERABILITIES = 'iThemesSecurity\\Site_Scanner\\Repository\\Vulnerabilities_Repository';
const SOLID_BANS = 'iThemesSecurity\\Ban_Hosts\\Multi_Repository';
const SOLID_BAN_FILTERS = 'iThemesSecurity\\Ban_Hosts\\Filters';

/** Site-scanner priorities (Priority::HIGH … NONE) to the shared scale. */
const SOLID_PRIORITIES = [3 => 'high', 2 => 'medium', 1 => 'low', 0 => 'info'];

function solid_active(): bool
{
    return class_exists('ITSEC_Core') && class_exists('ITSEC_Modules');
}

/** @return array<string, mixed> */
function solid_status(): array
{
    $status = [
        'provider' => 'solid-security',
        'name' => (string) \ITSEC_Core::get_plugin_name(),
        'version' => (string) \ITSEC_Core::get_plugin_version(),
        'premium' => (bool) \ITSEC_Core::is_pro(),
    ];

    $firewall = (bool) \ITSEC_Modules::is_active('firewall');
    $status['firewall'] = ['enabled' => $firewall, 'mode' => $firewall ? 'enabled' : 'disabled'];

    $scans = [
        'scheduled' => (bool) \ITSEC_Modules::is_active('malware-scheduling'),
        'next_scheduled_at' => null,
    ];
    if (method_exists('ITSEC_Core', 'get_scheduler')) {
        foreach ((array) \ITSEC_Core::get_scheduler()->get_recurring_events() as $event) {
            if (($event['id'] ?? '') === 'malware-scan') {
                $scans['next_scheduled_at'] = iso($event['fire_at'] ?? null);
            }
        }
    }
    $scan = solid_latest_scan();
    if ($scan === null) {
        $scans['last_scan_at'] = null;
        $scans['last_scan_result'] = 'never_run';
    } else {
        $scans['last_scan_at'] = iso($scan->get_time()->getTimestamp());
        // 'clean', 'warn' (issues found) or 'error' (the scan did not complete).
        $scans['last_scan_result'] = (string) $scan->get_status();
        if ($scan->is_error() && $scan->get_error() instanceof \WP_Error) {
            $scans['last_scan_failure'] = redact_text((string) $scan->get_error()->get_error_message(), 200);
        }
    }
    $scans['open_issues'] = solid_counts($scan);
    $status['scans'] = $scans;

    // Brute-force protection and every lockout need an IP detection method. Until one is chosen
    // (the 'automatic' default counts as none), the module reads as active on the settings screen
    // but never runs: on the test site failed logins produced no log entry and no lockout at all.
    $ip_configured = class_exists('ITSEC_Lib_IP_Detector') ? (bool) \ITSEC_Lib_IP_Detector::is_configured() : true;
    $brute_force = (bool) \ITSEC_Modules::is_active('brute-force');
    $status['login_protection'] = [
        'brute_force_protection' => $brute_force && $ip_configured,
        'brute_force_module_enabled' => $brute_force,
        'ip_detection_configured' => $ip_configured,
        'max_attempts_per_host' => (int) \ITSEC_Modules::get_setting('brute-force', 'max_attempts_host'),
        'max_attempts_per_user' => (int) \ITSEC_Modules::get_setting('brute-force', 'max_attempts_user'),
        'lockout_minutes' => (int) \ITSEC_Modules::get_setting('global', 'lockout_period'),
        'network_brute_force' => (bool) \ITSEC_Modules::is_active('network-brute-force'),
        'two_factor' => [
            'available' => true,
            'enabled' => (bool) \ITSEC_Modules::is_active('two-factor'),
        ],
    ];

    $lockout = solid_lockout_service();
    if ($lockout !== null) {
        $status['active_lockouts'] = (int) $lockout->get_lockouts('all', ['current' => true, 'return' => 'count']);
    }

    return $status;
}

/** @return object|null An ITSEC_Lockout. */
function solid_lockout_service(): ?object
{
    $lockout = $GLOBALS['itsec_lockout'] ?? null;
    return is_object($lockout) && method_exists($lockout, 'get_lockouts') ? $lockout : null;
}

/** @return object|null The latest iThemesSecurity\Site_Scanner\Scan. */
function solid_latest_scan(): ?object
{
    if (!interface_exists(SOLID_SCANS_REPOSITORY) || !class_exists(SOLID_SCAN_QUERY)) {
        return null;
    }
    $repository = \ITSEC_Modules::get_container()->get(SOLID_SCANS_REPOSITORY);
    $class = SOLID_SCAN_QUERY;
    $options = new $class();
    $options->set_per_page(1);
    $scans = $repository->get_scans($options);
    return is_array($scans) && isset($scans[0]) && is_object($scans[0]) ? $scans[0] : null;
}

/**
 * Open (not muted) issues of a scan, by shared severity.
 *
 * @return array<string, int>
 */
function solid_counts(?object $scan): array
{
    $counts = array_fill_keys(SEVERITIES, 0);
    foreach (solid_open_issues($scan) as $pair) {
        ++$counts[solid_severity($pair[1])];
    }
    return $counts;
}

/**
 * @return list<array{0: object, 1: object}> [entry, issue] pairs whose status is 'warn'.
 */
function solid_open_issues(?object $scan): array
{
    if ($scan === null) {
        return [];
    }
    $open = [];
    foreach ((array) $scan->get_entries() as $entry) {
        foreach ((array) $entry->get_issues() as $issue) {
            // 'clean' issues are muted by the owner; only 'warn' ones are open.
            if ((string) $issue->get_status() === 'warn') {
                $open[] = [$entry, $issue];
            }
        }
    }
    return $open;
}

function solid_severity(object $issue): string
{
    // Vulnerability issues carry a CVSS-derived severity including 'critical'; the others only a
    // priority, which tops out at high.
    if (method_exists($issue, 'get_severity')) {
        $severity = (string) $issue->get_severity();
        if (in_array($severity, SEVERITIES, true)) {
            return $severity;
        }
    }
    return SOLID_PRIORITIES[(int) $issue->get_priority()] ?? 'info';
}

/**
 * @param list<string> $severities Empty for all.
 * @return array<string, mixed>
 */
function solid_findings(array $severities, int $limit): array
{
    $scan = solid_latest_scan();
    $scan_time = $scan !== null ? iso($scan->get_time()->getTimestamp()) : null;
    $vulnerabilities = null;
    if (class_exists(SOLID_VULNERABILITIES)) {
        $vulnerabilities = \ITSEC_Modules::get_container()->get(SOLID_VULNERABILITIES);
    }

    $findings = [];
    $matched = 0;
    foreach (solid_open_issues($scan) as [$entry, $issue]) {
        $severity = solid_severity($issue);
        if ($severities !== [] && !in_array($severity, $severities, true)) {
            continue;
        }
        ++$matched;
        if (count($findings) >= $limit) {
            continue;
        }
        $finding = [
            'provider' => 'solid-security',
            'id' => 'solid-security:' . sanitize_text_field((string) $issue->get_id()),
            'severity' => $severity,
            'type' => sanitize_key((string) $entry->get_slug()),
            'description' => redact_text((string) $issue->get_description()),
            'first_seen' => $scan_time,
            'last_seen' => $scan_time,
        ];
        $meta = (array) $issue->get_meta();
        if (isset($meta['type']) && is_string($meta['type'])) {
            $slug = is_array($meta['software'] ?? null) ? (string) ($meta['software']['slug'] ?? '') : '';
            $finding['component'] = array_filter(
                ['type' => $meta['type'] === 'wordpress' ? 'core' : sanitize_key($meta['type']), 'slug' => sanitize_key($slug)],
                static fn($v): bool => $v !== '',
            );
        }
        // A vulnerability is tracked across scans, so it knows when it was first reported.
        if ($vulnerabilities !== null && method_exists($issue, 'get_severity')) {
            $found = $vulnerabilities->find((string) $issue->get_id());
            if ($found->is_success() && is_object($found->get_data())) {
                $first = $found->get_data()->get_first_seen();
                if ($first instanceof \DateTimeInterface) {
                    $finding['first_seen'] = iso($first->getTimestamp());
                }
            }
        }
        $findings[] = $finding;
    }

    $source = [
        'provider' => 'solid-security',
        'last_scan_at' => $scan_time,
        'last_scan_result' => $scan !== null ? (string) $scan->get_status() : 'never_run',
        'counts' => solid_counts($scan),
        'findings' => $findings,
        'truncated' => $matched > count($findings),
    ];
    if ($scan === null) {
        $source['note'] = 'No site scan is on record. Solid Security scans through its own cloud service; run one from its dashboard, or check that its log type is not "file" (file logs keep no scan history to read back).';
    }
    return $source;
}

/**
 * Lockouts (current and lifted, newest first) and permanent bans.
 *
 * @return array<string, mixed>
 */
function solid_lockouts(int $limit, bool $include_expired): array
{
    $entries = [];
    $lockout = solid_lockout_service();
    if ($lockout !== null) {
        $reasons = [];
        foreach ((array) $lockout->get_lockout_modules() as $module) {
            if (is_array($module) && isset($module['type'])) {
                $reasons[(string) $module['type']] = (string) ($module['reason'] ?? $module['label'] ?? $module['type']);
            }
        }
        $rows = $lockout->get_lockouts('all', [
            'current' => !$include_expired,
            'limit' => $limit,
            'orderby' => 'lockout_start',
            'order' => 'DESC',
        ]);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $entries[] = solid_lockout((array) $row, $reasons);
        }
    }

    foreach (solid_bans($limit) as $ban) {
        $entries[] = $ban;
    }

    $active = $lockout !== null ? (int) $lockout->get_lockouts('all', ['current' => true, 'return' => 'count']) : 0;
    if (class_exists(SOLID_BANS) && class_exists(SOLID_BAN_FILTERS)) {
        $class = SOLID_BAN_FILTERS;
        $active += (int) \ITSEC_Modules::get_container()->get(SOLID_BANS)->count_bans(new $class());
    }

    return ['provider' => 'solid-security', 'active_total' => $active, 'lockouts' => $entries];
}

/**
 * @param array<string, mixed> $row A row of the itsec_lockouts table.
 * @param array<string, string> $reasons
 * @return array<string, mixed>
 */
function solid_lockout(array $row, array $reasons): array
{
    $type = (string) ($row['lockout_type'] ?? '');
    $expires = iso_gmt($row['lockout_expire_gmt'] ?? null);
    $entry = [
        'provider' => 'solid-security',
        'id' => 'solid-security:' . (int) ($row['lockout_id'] ?? 0),
        'kind' => 'login_lockout',
        'type' => $type,
        'reason' => redact_text($reasons[$type] ?? $type),
        'active' => (int) ($row['lockout_active'] ?? 0) === 1 && $expires !== null && $expires > gmdate('Y-m-d\TH:i:s\Z'),
        'started_at' => iso_gmt($row['lockout_start_gmt'] ?? null),
        'expires_at' => $expires,
        'permanent' => false,
    ];
    $host = is_string($row['lockout_host'] ?? null) ? mask_ip($row['lockout_host']) : null;
    if ($host !== null) {
        $entry['ip_network'] = $host;
    }
    $user_id = (int) ($row['lockout_user'] ?? 0);
    if ($user_id > 0 && get_userdata($user_id)) {
        $entry['user_id'] = $user_id;
    } elseif (is_string($row['lockout_username'] ?? null) && $row['lockout_username'] !== '') {
        $entry = array_merge($entry, describe_login($row['lockout_username']));
    }
    return $entry;
}

/**
 * Permanent IP bans, newest first.
 *
 * @return list<array<string, mixed>>
 */
function solid_bans(int $limit): array
{
    if (!class_exists(SOLID_BANS) || !class_exists(SOLID_BAN_FILTERS)) {
        return [];
    }
    $repository = \ITSEC_Modules::get_container()->get(SOLID_BANS);
    $class = SOLID_BAN_FILTERS;
    $filters = (new $class())->with_limit($limit);
    $bans = [];
    foreach ((array) $repository->get_bans($filters)->get_bans() as $ban) {
        $created = $ban->get_created_at();
        $network = mask_host(method_exists($ban, 'get_host') ? (string) $ban->get_host() : (string) $ban);
        $entry = [
            'provider' => 'solid-security',
            'id' => 'solid-security:ban:' . sanitize_text_field((string) $ban->get_id()),
            'kind' => 'ip_ban',
            'type' => 'ban',
            'reason' => redact_text((string) $ban->get_comment()),
            'active' => true,
            'started_at' => $created instanceof \DateTimeInterface ? iso($created->getTimestamp()) : null,
            'expires_at' => null,
            'permanent' => true,
        ];
        if ($network !== null) {
            $entry['ip_network'] = $network;
        }
        $bans[] = $entry;
    }
    return $bans;
}
