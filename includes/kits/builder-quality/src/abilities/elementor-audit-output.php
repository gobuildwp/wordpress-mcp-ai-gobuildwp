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
    'execute_callback' => static function (array $input): array|WP_Error {
        $result = audit($input);
        if (is_array($result)) {
            // An empty map is an object in the output schema, not a JSON list.
            foreach (['counts', 'fixes'] as $key) {
                if (array_key_exists($key, $result) && $result[$key] === []) {
                    $result[$key] = new \stdClass();
                }
            }
        }
        return $result;
    },
    // A post that does not exist falls back to edit_posts, so the caller gets "No such post"
    // instead of a permission error; an existing post still needs edit_post on it.
    'permission_callback' => static function (mixed $input = null): bool {
        if (!Runtime\can_run()) {
            return false;
        }
        $post_id = (int) (is_array($input) ? ($input['post_id'] ?? 0) : 0);
        return $post_id > 0 && get_post($post_id) instanceof \WP_Post
            ? current_user_can('edit_post', $post_id)
            : current_user_can('edit_posts');
    },
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);
