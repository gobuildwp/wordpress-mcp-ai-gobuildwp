<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/db-security-scan-start', [
    'label' => __('Start Database Security Scan', domain: 'wppilot'),
    'description' => __(
        'Starts a background security scan of the site\'s database and configuration and returns its job. mode light checks administrator accounts (new, common or generated-looking logins, application password counts), role tampering, site address settings, open registration with a privileged default role, and configuration (file editor, debug display, XML-RPC, wp-config.php permissions, PHP end of life, SSL for admin, automatic updates). mode full (the default) adds options, posts and widgets with injected scripts, hidden iframes or obfuscated code, and scheduled events. Only one database scan runs at a time: if one is open it is returned with reused=true, and an incomplete scan with the same mode is resumed. Read-only: nothing on the site is changed. Follow with db-security-scan-status, then db-security-scan-results.',
        domain: 'wppilot',
    ),
    'category' => 'security-integrity',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'mode' => ['type' => 'string', 'enum' => ['light', 'full'], 'default' => 'full'],
        ],
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
        return start(KIND_DB, ['mode' => (string) ($input['mode'] ?? 'full')]);
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
            'idempotent' => false,
        ],
    ],
]);
