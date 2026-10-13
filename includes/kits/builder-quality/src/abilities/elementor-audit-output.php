<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BuilderQuality;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/elementor-audit-output', [
    'label' => __('Audit Elementor Output', domain: 'wppilot'),
    'description' => __(
        'Scores how editable an Elementor page is, 0-100, from its saved element tree, and lists what to fix per element: layout pasted into HTML widgets, scripts in content, shortcode widgets, widgets whose plugin is inactive, inline style attributes, custom CSS, colours fixed instead of taken from the global palette, containers nested more than six deep, and empty containers. Classic and atomic (v4) elements alike. Run it after building or changing a page, and fix what it finds before calling the page done. Read-only.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => ['post_id' => ['type' => 'integer', 'minimum' => 1]],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'score' => ['type' => 'integer'],
            'grade' => ['type' => 'string', 'enum' => ['good', 'fair', 'poor']],
            'counts' => ['type' => 'object'],
            'fixes' => ['type' => 'object'],
            'items' => ['type' => 'array', 'items' => ['type' => 'object']],
            'items_total' => ['type' => 'integer'],
        ],
    ],
    'execute_callback' => static fn(array $input): array|WP_Error => audit($input),
    'permission_callback' => static fn(mixed $input = null): bool => Runtime\can_run() && current_user_can('edit_post', (int) (is_array($input) ? ($input['post_id'] ?? 0) : 0)),
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
