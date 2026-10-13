<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\BlockTheme;

use WP_Error;
use WPPilot\Kits\Runtime;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * @param array<mixed> $p
 * @return array<string, mixed>
 */
function pattern_summary(array $p, bool $with_content = false): array
{
    $sync = (string) ($p['wp_pattern_sync_status'] ?? ($p['meta']['wp_pattern_sync_status'] ?? ''));

    return [
        'id' => (int) ($p['id'] ?? 0),
        'title' => raw($p['title'] ?? ''),
        'status' => (string) ($p['status'] ?? ''),
        'synced' => $sync !== 'unsynced',
    ] + ($with_content ? ['content' => raw($p['content'] ?? '')] : []);
}

wp_register_ability('wppilot/list-patterns', [
    'label' => __('List Patterns', domain: 'wppilot'),
    'description' => __(
        'Lists the site\'s own patterns (Appearance → Editor → Patterns; synced patterns update everywhere they are used, unsynced ones are copied in) with their content when include_content is true, and the names of the patterns the theme and plugins register, which can be inserted with <!-- wp:pattern {"slug":"..."} /-->.',
        domain: 'wppilot',
    ),
    'category' => 'appearance',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => ['include_content' => ['type' => 'boolean', 'default' => false], 'search' => ['type' => 'string', 'maxLength' => 100]],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input = []): array|WP_Error {
        $params = ['context' => 'edit', 'per_page' => 100, 'status' => 'publish,draft'];
        if (!empty($input['search'])) {
            $params['search'] = (string) $input['search'];
        }
        $mine = rest('GET', '/wp/v2/blocks', $params);
        if ($mine instanceof WP_Error) {
            return $mine;
        }
        $registered = [];
        if (class_exists('WP_Block_Patterns_Registry')) {
            foreach (\WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern) {
                $registered[] = ['slug' => (string) $pattern['name'], 'title' => (string) ($pattern['title'] ?? ''), 'categories' => $pattern['categories'] ?? []];
            }
        }
        $with = ($input['include_content'] ?? false) === true;

        return [
            'site_patterns' => array_values(array_map(static fn(array $p): array => pattern_summary($p, $with), array_filter($mine, 'is_array'))),
            'registered_patterns' => array_slice($registered, 0, 300),
        ];
    },
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('edit_posts'),
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
]);

wp_register_ability('wppilot/create-pattern', [
    'label' => __('Create Pattern', domain: 'wppilot'),
    'description' => __(
        'Saves block markup as a pattern of the site\'s own. synced (default true) makes a synced pattern: inserting it places a reference, and editing it later updates every place it is used. synced false makes a plain pattern that is copied in. Undo removes the pattern.',
        domain: 'wppilot',
    ),
    'category' => 'appearance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            'content' => ['type' => 'string'],
            'synced' => ['type' => 'boolean', 'default' => true],
        ],
        'required' => ['title', 'content'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input): array|WP_Error {
        $content = valid_markup($input['content'] ?? null);
        if ($content instanceof WP_Error) {
            return $content;
        }
        $synced = ($input['synced'] ?? true) !== false;
        $saved = rest('POST', '/wp/v2/blocks', [
            'title' => sanitize_text_field((string) $input['title']),
            'content' => $content,
            'status' => 'publish',
            'wp_pattern_sync_status' => $synced ? '' : 'unsynced',
        ]);
        if ($saved instanceof WP_Error) {
            return $saved;
        }
        $id = (int) ($saved['id'] ?? 0);

        return pattern_summary($saved) + [
            'insert_markup' => $synced ? sprintf('<!-- wp:block {"ref":%d} /-->', $id) : null,
            'created_post_id' => $id,
            'post_type' => 'wp_block',
        ];
    },
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('edit_posts'),
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
]);

wp_register_ability('wppilot/update-pattern', [
    'label' => __('Update Pattern', domain: 'wppilot'),
    'description' => __(
        'Changes one of the site\'s own patterns: its title, its block markup, or both. For a synced pattern this changes every page that uses it. Can be undone from the change log.',
        domain: 'wppilot',
    ),
    'category' => 'appearance',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            'content' => ['type' => 'string'],
        ],
        'required' => ['id'],
        'additionalProperties' => false,
    ],
    'output_schema' => ['type' => 'object'],
    'execute_callback' => static function (array $input): array|WP_Error {
        $params = [];
        if (isset($input['content'])) {
            $content = valid_markup($input['content']);
            if ($content instanceof WP_Error) {
                return $content;
            }
            $params['content'] = $content;
        }
        if (isset($input['title'])) {
            $params['title'] = sanitize_text_field((string) $input['title']);
        }
        if ($params === []) {
            return new WP_Error('kit_block_theme_nothing', 'Send a title, content or both.', ['status' => 400]);
        }
        $saved = rest('POST', '/wp/v2/blocks/' . (int) $input['id'], $params);

        return $saved instanceof WP_Error ? $saved : pattern_summary($saved, true);
    },
    'permission_callback' => static fn(): bool => Runtime\can_run() && current_user_can('edit_posts'),
    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true], 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
]);
