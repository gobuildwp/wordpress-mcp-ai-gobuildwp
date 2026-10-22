<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SecurityStatus;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Wordfence, read through its own classes (verified against Wordfence 9.0.1).
 *
 * - Settings: wfConfig::get(). Only the named keys below are read; apiKey and the licence live
 *   in the same store and are never touched.
 * - Firewall: wfFirewall::firewallMode() ('enabled', 'learning-mode', 'disabled'),
 *   protectionMode() ('extended' when auto_prepend_file loads the WAF, else 'basic') and
 *   ruleMode() ('premium' or 'community').
 * - Scans: wfScanner::shared() — isEnabled() (scheduled scans), nextScheduledScanTime() (the
 *   wordfence_start_scheduled_scan cron), lastScanTime(), isRunning(); the outcome is the
 *   lastScanCompleted setting, 'ok' or the failure message, with lastScanFailureType.
 * - Findings: the issues table named by wfIssues::shared()->getIssuesTable(), rows with status
 *   'new' (ignored ones are the owner's decision). Severity is 100/75/50/25/0.
 * - Blocks: wfBlock::allBlocks(), which returns only unexpired blocks; Wordfence deletes expired
 *   ones (wfBlock::vacuum()), so there is no history of lifted blocks to read.
 * - Two-factor: the bundled Login Security module, \WordfenceLS\Controller_Users.
 */

/** Wordfence severity numbers to the shared scale. */
const WORDFENCE_SEVERITIES = ['critical' => 100, 'high' => 75, 'medium' => 50, 'low' => 25, 'info' => 0];

function wordfence_active(): bool
{
    return defined('WORDFENCE_VERSION') && class_exists('wfConfig');
}

function wordfence_severity(int $number): string
{
    foreach (WORDFENCE_SEVERITIES as $name => $floor) {
        if ($number >= $floor) {
            return $name;
        }
    }
    return 'info';
}

/** @return array<string, mixed> */
function wordfence_status(): array
{
    $status = [
        'provider' => 'wordfence',
        'name' => 'Wordfence Security',
        'version' => (string) WORDFENCE_VERSION,
        'premium' => (bool) \wfConfig::get('isPaid'),
    ];

    if (class_exists('wfFirewall')) {
        $firewall = new \wfFirewall();
        $mode = (string) $firewall->firewallMode();
        $status['firewall'] = [
            'enabled' => $mode !== 'disabled',
            'mode' => $mode,
            'protection' => (string) $firewall->protectionMode(),
            'rules' => (string) $firewall->ruleMode(),
        ];
        $learning = $firewall->learningModeStatus();
        if (is_int($learning)) {
            $status['firewall']['learning_mode_until'] = iso($learning);
        }
    }

    $scans = ['scheduled' => (bool) \wfConfig::get('scheduledScansEnabled')];
    if (class_exists('wfScanner')) {
        $scanner = \wfScanner::shared();
        $scans['scheduled'] = (bool) $scanner->isEnabled();
        $scans['next_scheduled_at'] = iso($scanner->nextScheduledScanTime());
        $scans['scan_type'] = (string) $scanner->scanType();
        $scans['running'] = (bool) $scanner->isRunning();
        $scans['last_scan_at'] = iso($scanner->lastScanTime());
    }
    $completed = \wfConfig::get('lastScanCompleted');
    $scans['last_scan_result'] = wordfence_last_scan_result();
    if ($scans['last_scan_result'] === 'failed') {
        $scans['last_scan_failure'] = redact_text((string) $completed, 200);
        $type = \wfConfig::get('lastScanFailureType');
        if (is_string($type) && $type !== '') {
            $scans['last_scan_failure_type'] = $type;
        }
    }
    $scans['open_issues'] = wordfence_issue_counts();
    $status['scans'] = $scans;

    $login = [
        'brute_force_protection' => (bool) \wfConfig::get('loginSecurityEnabled'),
        'max_login_failures' => (int) \wfConfig::get('loginSec_maxFailures'),
        'lockout_minutes' => (int) \wfConfig::get('loginSec_lockoutMins'),
        'lock_out_invalid_usernames' => (bool) \wfConfig::get('loginSec_lockInvalidUsers'),
        'network_brute_force' => (bool) \wfConfig::get('other_WFNet'),
        'rate_limiting' => (bool) \wfConfig::get('firewallEnabled'),
    ];
    if (class_exists('WordfenceLS\\Controller_Users')) {
        $login['two_factor'] = [
            'available' => true,
            'users_with_two_factor' => (int) \WordfenceLS\Controller_Users::shared()->active_count(),
        ];
    } else {
        $login['two_factor'] = ['available' => false];
    }
    $status['login_protection'] = $login;

    if (class_exists('wfBlock')) {
        $status['active_blocks'] = wordfence_active_block_count();
    }

    return $status;
}

/**
 * Counted in SQL with allBlocks()' own "unexpired" condition: a site under attack can hold tens
 * of thousands of blocks, and allBlocks() would build an object for each.
 */
function wordfence_active_block_count(): int
{
    global $wpdb;
    $table = (string) \wfBlock::blocksTable();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Wordfence's own blocks table has no WordPress API; counted live so the status reflects blocks added or expired moments ago.
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE `expiration` = 0 OR `expiration` > UNIX_TIMESTAMP()', $table));
}

function wordfence_last_scan_result(): string
{
    $completed = \wfConfig::get('lastScanCompleted');
    return $completed === 'ok' ? 'ok' : (empty($completed) ? 'never_run' : 'failed');
}

function wordfence_issues_table(): string
{
    return (string) \wfIssues::shared()->getIssuesTable();
}

/** @return array<string, int> */
function wordfence_issue_counts(): array
{
    global $wpdb;
    $counts = array_fill_keys(SEVERITIES, 0);
    if (!class_exists('wfIssues')) {
        return $counts;
    }
    $table = wordfence_issues_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Wordfence's own issues table has no WordPress API; counted live so a scan that just finished is reflected.
    $rows = $wpdb->get_results($wpdb->prepare("SELECT `severity`, COUNT(*) AS `n` FROM %i WHERE `status` = 'new' GROUP BY `severity`", $table), ARRAY_A);
    foreach (is_array($rows) ? $rows : [] as $row) {
        $counts[wordfence_severity((int) $row['severity'])] += (int) $row['n'];
    }
    return $counts;
}

/**
 * Open issues, most severe first.
 *
 * @param list<string> $severities Empty for all.
 * @return array<string, mixed>
 */
function wordfence_findings(array $severities, int $limit): array
{
    global $wpdb;
    $counts = wordfence_issue_counts();
    $table = wordfence_issues_table();

    $where = "`status` = 'new'";
    $args = [];
    if ($severities !== []) {
        $ranges = [];
        foreach ($severities as $severity) {
            // Each named band is [its floor, the next band's floor).
            $floor = WORDFENCE_SEVERITIES[$severity];
            $names = array_keys(WORDFENCE_SEVERITIES);
            $above = array_search($severity, $names, true);
            $ceiling = $above === 0 ? 256 : WORDFENCE_SEVERITIES[$names[$above - 1]];
            $ranges[] = '(`severity` >= %d AND `severity` < %d)';
            $args[] = $floor;
            $args[] = $ceiling;
        }
        $where .= ' AND (' . implode(' OR ', $ranges) . ')';
    }
    $args[] = $limit + 1;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Wordfence's own issues table has no WordPress API, read live so a scan that just finished is reflected. $where holds only the fixed SQL fragments built above; their values are bound as %d.
    $rows = $wpdb->get_results($wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built above only from fixed SQL fragments with %d placeholders; the table is bound as %i and every value through $args.
        "SELECT `id`, `time`, `lastUpdated`, `type`, `severity`, `shortMsg`, `data` FROM %i WHERE {$where} ORDER BY `severity` DESC, `time` DESC LIMIT %d",
        $table,
        ...$args,
    ), ARRAY_A);
    $rows = is_array($rows) ? $rows : [];

    $findings = [];
    foreach (array_slice($rows, 0, $limit) as $row) {
        $findings[] = wordfence_finding($row);
    }

    return [
        'provider' => 'wordfence',
        'last_scan_at' => class_exists('wfScanner') ? iso(\wfScanner::shared()->lastScanTime()) : null,
        'last_scan_result' => wordfence_last_scan_result(),
        'counts' => $counts,
        'findings' => $findings,
        'truncated' => count($rows) > $limit,
    ];
}

/**
 * One issue row, reduced to what a model needs to act on it.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function wordfence_finding(array $row): array
{
    $type = (string) $row['type'];
    $finding = [
        'provider' => 'wordfence',
        'id' => 'wordfence:' . (int) $row['id'],
        'severity' => wordfence_severity((int) $row['severity']),
        'type' => $type,
        'description' => redact_text((string) $row['shortMsg']),
        'first_seen' => iso($row['time']),
        'last_seen' => iso($row['lastUpdated']),
    ];

    // The issue data is PHP-serialized by Wordfence. No objects are allowed back out of it.
    $data = is_string($row['data']) ? @unserialize($row['data'], ['allowed_classes' => false]) : null;
    $data = is_array($data) ? $data : [];

    $file = is_string($data['file'] ?? null) ? $data['file'] : (is_string($data['realFile'] ?? null) ? $data['realFile'] : '');
    if ($file !== '') {
        $finding['path'] = relative_path($file);
    }
    $kind = str_starts_with($type, 'wfPlugin') ? 'plugin' : (str_starts_with($type, 'wfTheme') ? 'theme' : '');
    if ($kind === '' && in_array($type, ['wfUpgrade', 'wfUpgradeError', 'coreUnknown'], true)) {
        $kind = 'core';
    }
    if ($kind !== '') {
        $slug = '';
        if (is_string($data['slug'] ?? null)) {
            $slug = $data['slug'];
        } elseif (is_string($data['pluginFile'] ?? null)) {
            $slug = dirname($data['pluginFile']) !== '.' ? dirname($data['pluginFile']) : basename($data['pluginFile'], '.php');
        } elseif (is_string($data['Stylesheet'] ?? null)) {
            $slug = $data['Stylesheet'];
        }
        $finding['component'] = array_filter(['type' => $kind, 'slug' => sanitize_key($slug)], static fn($v): bool => $v !== '');
    }

    return $finding;
}

/**
 * Unexpired blocks and lockouts, newest first.
 *
 * @return array<string, mixed>
 */
function wordfence_lockouts(int $limit): array
{
    $entries = [];
    foreach (\wfBlock::allBlocks(true, [], 0, $limit, 'ruleAdded', 'descending') as $block) {
        $entries[] = wordfence_lockout($block);
    }
    return [
        'provider' => 'wordfence',
        'active_total' => wordfence_active_block_count(),
        'lockouts' => $entries,
        'note' => 'Wordfence deletes blocks once they expire, so only current blocks and lockouts are listed.',
    ];
}

/**
 * @param object $block A wfBlock.
 * @return array<string, mixed>
 */
function wordfence_lockout(object $block): array
{
    $type = (int) $block->type;
    $expires = (int) $block->expiration;
    $entry = [
        'provider' => 'wordfence',
        'id' => 'wordfence:' . (int) $block->id,
        'kind' => wordfence_block_kind($type),
        'type' => (string) \wfBlock::nameForType($type),
        'active' => $expires === 0 || $expires > time(),
        'started_at' => iso($block->blockedTime),
        'expires_at' => $expires === 0 ? null : iso($expires),
        'permanent' => $expires === 0,
        'blocked_hits' => (int) $block->blockedHits,
        'last_attempt_at' => iso($block->lastAttempt),
    ];
    $ip = is_string($block->ip) ? mask_ip($block->ip) : null;
    if ($ip !== null) {
        $entry['ip_network'] = $ip;
    }

    // Login lockout reasons quote the username that was tried ("…sign in with was: 'bob'"), and
    // the forgot-password one may quote an email. The quoted part is replaced by who it refers to.
    $reason = (string) $block->reason;
    if (preg_match("/'([^']+)'/", $reason, $m)) {
        $who = describe_login($m[1]);
        $entry = array_merge($entry, $who);
        $label = isset($who['user_id']) ? 'user #' . $who['user_id'] : ($who['username'] ?? '');
        $reason = str_replace("'" . $m[1] . "'", "'" . $label . "'", $reason);
    }
    $entry['reason'] = redact_text($reason);

    return $entry;
}

function wordfence_block_kind(int $type): string
{
    return match ($type) {
        7 => 'login_lockout',
        5, 6 => 'rate_limit',
        3 => 'country_block',
        4 => 'pattern_block',
        default => 'ip_block',
    };
}
