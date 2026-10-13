<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BackupStatus;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The two abilities' callbacks: status and list.
 */

const DEFAULT_LIST_LIMIT = 20;

const MAX_LIST_LIMIT = 100;

/**
 * @param array<string, mixed> $input
 * @return list<string>|WP_Error|null Null for every active provider.
 */
function requested_providers(array $input): array|WP_Error|null
{
    $provider = $input['provider'] ?? null;
    if ($provider === null || $provider === '') {
        return null;
    }
    if (!is_string($provider) || !in_array($provider, PROVIDERS, strict: true)) {
        return new WP_Error('kit_backups_provider', sprintf('Unknown provider; use one of %s.', implode(', ', PROVIDERS)), ['status' => 400]);
    }
    if (!in_array($provider, active_providers(), strict: true)) {
        return new WP_Error(
            'kit_backups_provider_inactive',
            sprintf('%s is not active on this site. Active: %s.', LABELS[$provider], implode(', ', active_providers()) ?: 'none'),
            ['status' => 400],
        );
    }

    return [$provider];
}

/**
 * wppilot/backup-status
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function status(array $input = []): array|WP_Error
{
    $only = requested_providers($input);
    if ($only instanceof WP_Error) {
        return $only;
    }
    $result = statuses($only);
    $freshest = null;
    foreach ($result['providers'] as $provider) {
        $success = $provider['last_successful_backup'] ?? null;
        if (!is_array($success)) {
            continue;
        }
        $time = (int) ($success['finished_timestamp'] ?? $success['timestamp']);
        if ($freshest === null || $time > $freshest['timestamp']) {
            $freshest = array_merge(['provider' => $provider['provider'], 'timestamp' => $time], times($time));
        }
    }

    return [
        'active_providers' => $result['active'],
        'supported_providers' => array_values(LABELS),
        'providers' => $result['providers'],
        'newest_successful_backup' => $freshest,
    ];
}

/**
 * wppilot/backup-list
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function list_backups(array $input = []): array|WP_Error
{
    $only = requested_providers($input);
    if ($only instanceof WP_Error) {
        return $only;
    }
    $limit = isset($input['limit']) && is_int($input['limit']) ? $input['limit'] : DEFAULT_LIST_LIMIT;
    $limit = max(1, min(MAX_LIST_LIMIT, $limit));

    $out = [];
    foreach ($only ?? active_providers() as $provider) {
        try {
            /** @var list<array<string, mixed>> $backups */
            $backups = adapter($provider, 'list', $limit);
            $out[] = ['provider' => $provider, 'label' => LABELS[$provider], 'readable' => true, 'backups' => $backups];
        } catch (\Throwable $error) {
            $out[] = ['provider' => $provider, 'label' => LABELS[$provider], 'readable' => false, 'error' => unreadable_reason($error), 'backups' => []];
        }
    }

    return ['limit' => $limit, 'providers' => $out];
}
