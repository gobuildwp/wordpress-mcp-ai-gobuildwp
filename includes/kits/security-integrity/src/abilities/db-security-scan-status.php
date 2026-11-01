<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/db-security-scan-status', [
    'label' => __('Database Security Scan Status', domain: 'wppilot'),
    'description' => __(
        'Reports a database security scan by job_id: state (queued, running, done, incomplete, failed, cancelled), phase and progress, rows checked, coverage per area (users, roles, urls, options, posts, cron, app_passwords, settings, config) and the number of results. With advance=true (the default) it first runs one budgeted step of the scan when no other request is running it, so a scan finishes even where WP-Cron does not run; poll every few seconds until the state is done, incomplete, failed or cancelled. Read-only.',
        domain: 'wppilot',
    ),
    'category' => 'security-integrity',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'job_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'description' => 'The job_id the start call returned.'],
            'advance' => ['type' => 'boolean', 'default' => true, 'description' => 'Run one step of the scan before reporting.'],
        ],
        'required' => ['job_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'string'],
            'kind' => ['type' => 'string'],
            'state' => ['type' => 'string'],
            'scope' => ['type' => 'string'],
            'started_at' => ['type' => ['integer', 'null']],
            'finished_at' => ['type' => ['integer', 'null']],
            'progress' => ['type' => 'object'],
            'coverage' => ['type' => 'object'],
            'results_count' => ['type' => 'integer'],
            'error' => ['type' => 'string'],
            'env' => ['type' => 'object'],
        ],
    ],
    'execute_callback' => static function (array $input = []): array|\WP_Error {
        require_once __DIR__ . '/../load.php';
        return status(KIND_DB, (string) ($input['job_id'] ?? ''), (bool) ($input['advance'] ?? true));
    },
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_options'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => false,
        ],
    ],
]);
