<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BackupStatus;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The backup providers this kit reads, and the one shape every answer is given in.
 *
 * Each vendor keeps its history differently — UpdraftPlus as an option keyed by start time with
 * a separate "last backup" verdict, Duplicator as a table of packages with a status code,
 * BackWPup as jobs whose results only exist in their log headers, All-in-One WP Migration as the
 * finished archives in its backups folder — so each adapter translates
 * into one record, and only into fields that are safe to hand an agent: when, whether it worked,
 * what it held, how big, and the names of the storage it went to. Never an archive path, a
 * download URL, a storage credential or a job secret: an agent that can read those can fetch the
 * whole database.
 *
 * GoBuildWP Pro 1.10.0 registered these two abilities from its own copy of these readers, and still
 * does until its next release; the output here is kept identical, so an agent, or a Pro skill,
 * reads either copy the same way. The `trigger` field each provider carries says whether Pro's
 * wppilot/backup-trigger could start a backup for it; without Pro that ability does not exist.
 */

require_once __DIR__ . '/updraftplus.php';
require_once __DIR__ . '/duplicator.php';
require_once __DIR__ . '/backwpup.php';
require_once __DIR__ . '/ai1wm.php';

const PROVIDERS = ['updraftplus', 'duplicator', 'backwpup', 'ai1wm'];

const LABELS = [
    'updraftplus' => 'UpdraftPlus',
    'duplicator' => 'Duplicator',
    'backwpup' => 'BackWPup',
    'ai1wm' => 'All-in-One WP Migration',
];

/**
 * The providers whose plugin is active on this request, in a fixed order.
 *
 * @return list<string>
 */
function active_providers(): array
{
    $active = [];
    foreach (PROVIDERS as $provider) {
        $is_active = __NAMESPACE__ . '\\' . $provider . '_active';
        if (is_callable($is_active) && $is_active() === true) {
            $active[] = $provider;
        }
    }

    return $active;
}

/**
 * Call one adapter function by provider, e.g. adapter('updraftplus', 'status').
 *
 * @param mixed ...$args
 * @return mixed
 */
function adapter(string $provider, string $what, ...$args)
{
    $function = __NAMESPACE__ . '\\' . $provider . '_' . $what;
    if (!in_array($provider, PROVIDERS, strict: true) || !is_callable($function)) {
        throw new \RuntimeException(sprintf('No %s adapter for %s.', $what, $provider));
    }

    return $function(...$args);
}

/**
 * A timestamp as the pair every record carries: site-local and UTC.
 *
 * @return array{time: string, time_utc: string}
 */
function times(int $timestamp): array
{
    return [
        'time' => wp_date('c', $timestamp) ?: gmdate('c', $timestamp),
        'time_utc' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
    ];
}

/**
 * One backup in the shape the abilities return. Fields a vendor does not record stay null rather
 * than being guessed.
 *
 * @param array{
 *     id: string,
 *     timestamp: int,
 *     result: string,
 *     contents?: list<string>|null,
 *     size_bytes?: int|null,
 *     storage?: list<string>,
 *     label?: string|null,
 *     errors?: int|null,
 *     warnings?: int|null,
 *     kind?: string|null,
 * } $fields
 * @return array<string, mixed>
 */
function record(string $provider, array $fields): array
{
    return array_merge(
        [
            'provider' => $provider,
            'id' => $fields['id'],
            'timestamp' => $fields['timestamp'],
        ],
        times($fields['timestamp']),
        [
            'result' => $fields['result'],
            'contents' => $fields['contents'] ?? null,
            'size_bytes' => $fields['size_bytes'] ?? null,
            'storage' => $fields['storage'] ?? [],
            'label' => isset($fields['label']) && $fields['label'] !== '' ? $fields['label'] : null,
            'errors' => $fields['errors'] ?? null,
            'warnings' => $fields['warnings'] ?? null,
            'kind' => $fields['kind'] ?? null,
        ],
    );
}

/**
 * A scheduled run in the shape the abilities return.
 *
 * @return array<string, mixed>
 */
function scheduled(int $timestamp, string $what): array
{
    return array_merge(['timestamp' => $timestamp], times($timestamp), ['what' => $what]);
}

/**
 * Newest first, then cut to the limit.
 *
 * @param list<array<string, mixed>> $records
 * @return list<array<string, mixed>>
 */
function newest_first(array $records, int $limit): array
{
    usort($records, static fn(array $a, array $b): int => (int) $b['timestamp'] <=> (int) $a['timestamp']);

    return array_slice($records, 0, max(0, $limit));
}

/**
 * The status of every active provider, one failing adapter never hiding the others.
 *
 * @param list<string>|null $only
 * @return array{providers: list<array<string, mixed>>, active: list<string>}
 */
function statuses(?array $only = null): array
{
    $active = active_providers();
    $out = [];
    foreach ($active as $provider) {
        if ($only !== null && !in_array($provider, $only, strict: true)) {
            continue;
        }
        try {
            /** @var array<string, mixed> $status */
            $status = adapter($provider, 'status');
            $out[] = array_merge(['provider' => $provider, 'label' => LABELS[$provider], 'readable' => true], $status);
        } catch (\Throwable $error) {
            $out[] = [
                'provider' => $provider,
                'label' => LABELS[$provider],
                'readable' => false,
                'error' => unreadable_reason($error),
            ];
        }
    }

    return ['providers' => $out, 'active' => $active];
}

/**
 * Why an adapter could not answer, without the file paths a vendor's exception may carry.
 */
function unreadable_reason(\Throwable $error): string
{
    $message = preg_replace('#(?:[A-Za-z]:)?[\\\\/][^\s\'"]+#', '[path]', $error->getMessage()) ?? '';

    return mb_substr($message !== '' ? $message : get_class($error), 0, 300);
}

/**
 * Bytes as an int when the vendor recorded a number, otherwise null.
 */
function bytes(mixed $value): ?int
{
    if (is_int($value)) {
        return $value >= 0 ? $value : null;
    }
    if (is_float($value) || (is_string($value) && is_numeric($value))) {
        $int = (int) $value;
        return $int >= 0 ? $int : null;
    }

    return null;
}
