<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/integrity-scan-start', [
    'label' => __('Start File Integrity Scan', domain: 'wppilot'),
    'description' => __(
        'Starts a background scan of the site\'s files and returns its job: core files compared with WordPress.org checksums, WordPress.org plugins compared with their published checksums, WordPress.org themes compared with the files of their WordPress.org download, PHP files that core, a plugin or a theme does not ship, PHP files and PHP handler settings in uploads, and suspicious-code indicators in the files flagged. scope limits it to core, plugins, themes or uploads (default all). baseline=true also records sha256, size and modified time of the PHP files of themes and plugins without published checksums. deep=true also reads every PHP and JavaScript file without published checksums (premium and custom plugins and themes, must-use plugins, drop-ins and other PHP files in wp-content) with the suspicious-code rules. Only one file scan runs at a time: if one is open it is returned with reused=true, and an incomplete scan with the same scope is resumed. Read-only: nothing on the site is changed. Follow with integrity-scan-status, then integrity-scan-results.',
        domain: 'wppilot',
    ),
    'category' => 'security-integrity',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'scope' => ['type' => 'string', 'enum' => ['all', 'core', 'plugins', 'themes', 'uploads'], 'default' => 'all'],
            'baseline' => ['type' => 'boolean', 'default' => false, 'description' => 'Also record hashes of the PHP files of themes and plugins without published checksums.'],
            'deep' => ['type' => 'boolean', 'default' => false, 'description' => 'Also read every PHP and JavaScript file without published checksums with the suspicious-code rules.'],
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
        $request = ['scope' => (string) ($input['scope'] ?? 'all'), 'baseline' => (bool) ($input['baseline'] ?? false)];
        if (!empty($input['deep'])) {
            // Set only when asked for, so an incomplete scan started without it still resumes.
            $request['deep'] = true;
        }
        return start(KIND_FILES, $request);
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
