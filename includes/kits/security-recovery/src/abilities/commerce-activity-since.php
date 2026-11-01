<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityRecovery;

use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('gobuildwp/commerce-activity-since', [
    'label' => __('Activity Since', domain: 'wppilot'),
    'description' => __(
        'Counts what was added or changed on the site since a unix time (seconds): WooCommerce orders created and customers registered (null when WooCommerce is not active; orders come from the HPOS table when it is in use), users registered, approved and pending comments, posts, pages and products edited, and entries in WPForms, Gravity Forms and Fluent Forms when their tables exist. Use it before restoring a backup taken at that time, to see what the restore would lose. A count whose query failed is null. Counts only, never the rows. Read-only.',
        domain: 'wppilot',
    ),
    'category' => 'security-recovery',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'since' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Unix time in seconds, e.g. when the backup was taken.'],
        ],
        'required' => ['since'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'since' => ['type' => 'integer'],
            'now' => ['type' => 'integer'],
            'woocommerce' => ['type' => 'boolean'],
            'orders' => ['type' => ['integer', 'null']],
            'customers' => ['type' => ['integer', 'null']],
            'users' => ['type' => ['integer', 'null']],
            'comments' => ['type' => ['integer', 'null']],
            'posts_changed' => ['type' => ['integer', 'null']],
            'form_entries' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
        ],
    ],
    'execute_callback' => static function (array $input = []): array {
        require_once __DIR__ . '/../commerce.php';
        $result = activity_since(max(0, (int) ($input['since'] ?? 0)));
        // An empty map must reach JSON as {}, not [].
        $result['form_entries'] = (object) $result['form_entries'];
        return $result;
    },
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('manage_options'),
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
