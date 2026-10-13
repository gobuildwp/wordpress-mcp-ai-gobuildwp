<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BackupStatus;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

// GoBuildWP Pro 1.10.0 registers this name itself, earlier in the same hook; its copy then answers.
if (Runtime\unclaimed('wppilot/backup-status')) {
    wp_register_ability('wppilot/backup-status', [
        'label' => __('Backup Status', domain: 'wppilot'),
        'description' => __(
            'Backup health for each active backup plugin (UpdraftPlus, Duplicator, BackWPup, All-in-One WP Migration as provider "ai1wm"): the last backup and its result (success, failed, cancelled, running or unknown), the last successful one, what it contained (db, plugins, themes, uploads… when the plugin records it), its size, whether a backup is running or queued now, the next scheduled backup, and the names of the storage archives go to. Times are given in the site timezone (time) and UTC (time_utc). Each provider says whether it can be read, and in trigger whether the Pro edition could start a backup for it. Never returns archive paths, download links or storage credentials. Use before a risky change to confirm a recent good backup exists.',
            domain: 'wppilot',
        ),
        'category' => 'backups',
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => [
                'provider' => ['type' => 'string', 'enum' => PROVIDERS, 'description' => 'Only this backup plugin. Defaults to every active one.'],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'active_providers' => ['type' => 'array', 'items' => ['type' => 'string']],
                'supported_providers' => ['type' => 'array', 'items' => ['type' => 'string']],
                'providers' => ['type' => 'array', 'items' => ['type' => 'object']],
                'newest_successful_backup' => ['type' => ['object', 'null']],
            ],
        ],
        'execute_callback' => static fn(array $input = []): array|\WP_Error => status($input),
        'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_options'),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'instructions' => 'A provider with readable false could not be read: say so rather than treating it as having no backups. result "unknown" means the plugin kept no verdict for that backup.',
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
        ],
    ]);
}
