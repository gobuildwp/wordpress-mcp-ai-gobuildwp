<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/integrity-scan-cancel', [
    'label' => __('Cancel File Integrity Scan', domain: 'wppilot'),
    'description' => __(
        'Stops a file integrity scan by job_id and returns its job. Results found so far stay readable. Cancelling a finished scan changes nothing. Read-only for the site: it only stops the scan.',
        domain: 'wppilot',
    ),
    'category' => 'security-integrity',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'job_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'description' => 'The job_id the start call returned.'],
        ],
        'required' => ['job_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'job_id' => ['type' => 'string'],
            'kind' => ['type' => 'string', 'enum' => ['integrity', 'db']],
            'state' => ['type' => 'string', 'enum' => ['queued', 'running', 'done', 'incomplete', 'failed', 'cancelled']],
            'reused' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback' => static function (array $input = []): array|\WP_Error {
        require_once __DIR__ . '/../load.php';
        return cancel(KIND_FILES, (string) ($input['job_id'] ?? ''));
    },
    // Security Guardian is a GoBuildWP Cloud Pro feature: this changes the site, so it runs only for
    // a signed Cloud request. Reading what it reports stays local.
    'permission_callback' => static fn(): bool|\WP_Error => (Runtime\can_run() && current_user_can('manage_options')) ? Runtime\require_cloud_request() : false,
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);
