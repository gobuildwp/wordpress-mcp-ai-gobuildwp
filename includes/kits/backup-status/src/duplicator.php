<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BackupStatus;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Duplicator ("Duplicator – Backups & Migration", verified against 5.0.4).
 *
 * 5.x is a namespaced rewrite: the `DUP_Package` class and the `duplicator_package_active` option
 * older guides describe are gone. Backups are rows of `{base_prefix}duplicator_backups`, read
 * through `Duplicator\Package\DupPackage::dbSelect()`, which also drops the scaffolding rows a
 * build in progress leaves behind (FLAG_TEMPORARY) and completed rows whose files are gone.
 * `created` is stored in UTC. A status of 100 is complete, 0–99 is a build in progress, and a
 * negative status is a failure or a cancellation.
 *
 * Read-only here. The free version has no schedule, and starting a backup is a browser-driven
 * sequence (temporary package, chunked scan, promotion, then a worker the page keeps kicking);
 * there is no single server-side call that starts one, so `trigger.supported` is false. Duplicator Pro is a separate codebase whose API could not be
 * checked here, so it is not read.
 */

const DUPLICATOR_PACKAGE = 'Duplicator\\Package\\DupPackage';

const DUPLICATOR_COMPLETE = 100;

function duplicator_active(): bool
{
    return defined('DUPLICATOR_VERSION')
        && class_exists(DUPLICATOR_PACKAGE)
        && method_exists(DUPLICATOR_PACKAGE, 'dbSelect');
}

/**
 * @return list<object>
 */
function duplicator_select(string $where, int $limit): array
{
    /** @var mixed $rows */
    $rows = call_user_func([DUPLICATOR_PACKAGE, 'dbSelect'], $where, $limit, 0, '`id` DESC', 'objs');

    return is_array($rows) ? array_values(array_filter($rows, 'is_object')) : [];
}

function duplicator_result(int $status): string
{
    if ($status === DUPLICATOR_COMPLETE) {
        return 'success';
    }
    if ($status >= 0) {
        return 'running';
    }

    // -2 build cancelled, -3 cancel pending, -4 storage cancelled.
    return in_array($status, [-2, -3, -4], strict: true) ? 'cancelled' : 'failed';
}

/**
 * @return array<string, mixed>
 */
function duplicator_record(object $package): array
{
    $status = method_exists($package, 'getStatus') ? (int) $package->getStatus() : 0;
    $created = method_exists($package, 'getCreated') ? (string) $package->getCreated() : '';
    $timestamp = $created !== '' ? strtotime($created . ' UTC') : false;

    $contents = null;
    if (method_exists($package, 'isDBOnly') && $package->isDBOnly()) {
        $contents = ['db'];
    } elseif (property_exists($package, 'components') && is_array($package->components)) {
        $contents = [];
        foreach ($package->components as $component) {
            if (is_string($component) && str_starts_with($component, 'package_component_')) {
                $contents[] = substr($component, 18) === 'other' ? 'others' : substr($component, 18);
            }
        }
        if (method_exists($package, 'isDBExcluded') && $package->isDBExcluded()) {
            $contents = array_values(array_diff($contents, ['db']));
        }
    }

    $storage = [];
    if (method_exists($package, 'getStorages')) {
        foreach ((array) $package->getStorages() as $store) {
            if (!is_object($store) || !method_exists($store, 'getName')) {
                continue;
            }
            // The default storage is named "Default"; its type ("Default Local", "Local", or a
            // remote service) says where that is.
            $type = method_exists($store, 'getStypeName') ? (string) call_user_func([get_class($store), 'getStypeName']) : '';
            $name = (string) $store->getName();
            $storage[] = $type !== '' && $type !== $name ? sprintf('%s (%s)', $name, $type) : $name;
        }
    }

    $archive = property_exists($package, 'Archive') ? $package->Archive : null;
    $finished = null;
    if ($status === DUPLICATOR_COMPLETE && method_exists($package, 'getStateTimes')) {
        $times = $package->getStateTimes();
        $done = is_array($times[DUPLICATOR_COMPLETE] ?? null) ? ($times[DUPLICATOR_COMPLETE]['start'] ?? null) : null;
        $finished = is_numeric($done) ? (int) $done : null;
    }

    return array_merge(
        record('duplicator', [
            'id' => method_exists($package, 'getId') ? (string) $package->getId() : '',
            'timestamp' => $timestamp !== false ? $timestamp : 0,
            'result' => duplicator_result($status),
            'contents' => $contents,
            'size_bytes' => is_object($archive) && property_exists($archive, 'Size') && $status === DUPLICATOR_COMPLETE ? bytes($archive->Size) : null,
            'storage' => array_values(array_unique($storage)),
            'label' => method_exists($package, 'getName') ? (string) $package->getName() : null,
            'kind' => 'backup',
        ]),
        $finished !== null ? ['finished_timestamp' => $finished, 'finished' => times($finished)] : [],
    );
}

/**
 * @return list<array<string, mixed>>
 */
function duplicator_list(int $limit): array
{
    $records = [];
    foreach (duplicator_select('', $limit) as $package) {
        $records[] = duplicator_record($package);
    }

    return newest_first($records, $limit);
}

/**
 * @return array<string, mixed>
 */
function duplicator_status(): array
{
    global $wpdb;
    $latest = duplicator_select('', 1);
    $success = duplicator_select($wpdb->prepare('`status` = %d', DUPLICATOR_COMPLETE), 1);
    $running = duplicator_select($wpdb->prepare('`status` >= %d AND `status` < %d', 0, DUPLICATOR_COMPLETE), 5);

    $jobs = [];
    foreach ($running as $package) {
        $record = duplicator_record($package);
        $jobs[] = ['state' => 'running', 'started' => times((int) $record['timestamp'])];
    }

    $storage = [];
    foreach ($success !== [] ? [$success[0]] : [] as $package) {
        $storage = duplicator_record($package)['storage'];
    }

    return [
        'version' => defined('DUPLICATOR_VERSION') ? (string) constant('DUPLICATOR_VERSION') : null,
        'last_backup' => $latest !== [] ? duplicator_record($latest[0]) : null,
        'last_successful_backup' => $success !== [] ? duplicator_record($success[0]) : null,
        'running' => ['running' => $jobs !== [], 'jobs' => $jobs],
        'next_scheduled' => [],
        'schedule' => null,
        'storage' => $storage !== [] ? $storage : ['Local (web server)'],
        'trigger' => [
            'supported' => false,
            'reason' => 'Duplicator starts a backup only from its Backups screen in wp-admin (a browser-driven build); there is no server-side call to start one.',
        ],
        'notes' => ['Duplicator (free) has no backup schedule; scheduled backups are a Duplicator Pro feature.'],
    ];
}
