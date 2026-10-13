<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SecurityStatus;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

// GoBuildWP Pro 1.10.0 registers this name itself, earlier in the same hook; its copy then answers.
if (Runtime\unclaimed('wppilot/security-plugin-status')) {
    wp_register_ability('wppilot/security-plugin-status', [
        'label' => __('Security Plugin Status', domain: 'wppilot'),
        'description' => __(
            'What the site\'s security plugin (Wordfence and/or Solid Security, formerly iThemes Security) reports about itself, one entry per active provider, each naming the provider that answered: version; whether its firewall is on and in which mode (Wordfence: enabled, learning-mode or disabled, with basic or extended protection); whether scans are scheduled, the next run, when the last scan finished, its result (ok/clean, warn, failed, never_run) and open findings by severity; brute-force/login protection, lockout thresholds and two-factor; active lockouts; and whether the vendor\'s premium tier is installed. Read-only. Never returns licence keys, API keys or secrets. Use wppilot/security-scan-findings for the findings themselves and wppilot/security-lockouts for who is blocked.',
            domain: 'wppilot',
        ),
        'category' => 'security',
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => [
                'provider' => ['type' => 'string', 'enum' => ['any', 'wordfence', 'solid-security'], 'default' => 'any', 'description' => 'Ask one provider only. Defaults to every active one.'],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'providers' => ['type' => 'array', 'items' => ['type' => 'object']],
                'inactive' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ],
        'execute_callback' => static fn(array $input = []): array|\WP_Error => plugin_status($input),
        'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_options'),
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true],
            'annotations' => [
                'instructions' => 'Start here for "is the site protected" questions. An entry with "error" means that provider could not be read; the others still stand.',
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
        ],
    ]);
}
