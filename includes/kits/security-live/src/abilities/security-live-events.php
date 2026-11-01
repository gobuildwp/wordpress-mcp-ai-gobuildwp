<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityLive;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/security-live-events', [
    'label' => __('List Live Security Events', domain: 'wppilot'),
    'description' => __(
        'Lists live security events, oldest first, after an event id: PHP uploads, new administrators, sensitive settings changes, file editor saves, new must-use plugins and drop-ins, changed or unexpected code files, and posts saved with injected scripts. The newest 200 are kept. Read-only.',
        domain: 'wppilot',
    ),
    'category' => 'security-live',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [
            'after' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100],
            'min_severity' => ['type' => 'string', 'enum' => ['info', 'low', 'medium', 'high', 'critical'], 'default' => 'info'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'events' => ['type' => 'array', 'items' => ['type' => 'object']],
            'last_event_id' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static function (array $input = []): array {
        require_once __DIR__ . '/../load.php';
        $severity = isset($input['min_severity']) && in_array($input['min_severity'], SEVERITIES, true) ? (string) $input['min_severity'] : 'info';
        return [
            'events' => events_since(max(0, (int) ($input['after'] ?? 0)), max(1, min(MAX_EVENTS, (int) ($input['limit'] ?? 100))), $severity),
            'last_event_id' => last_event_id(),
        ];
    },
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_options'),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
