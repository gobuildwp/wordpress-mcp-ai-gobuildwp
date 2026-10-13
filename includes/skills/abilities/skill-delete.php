<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Skills\Abilities\SkillDelete;

use WP_Error;
use WPPilot\Skills\Abilities\SkillWrite;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/skill-delete', [
        'label' => __('Delete Skill', domain: 'wppilot'),
        'description' => __(
            'Move a GoBuildWP user skill to trash. Pass `permanent=true` to skip trash and delete immediately.',
            domain: 'wppilot',
        ),
        'category' => 'skill',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string'],
                'permanent' => ['type' => 'boolean'],
            ],
            'required' => ['slug'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'deleted' => ['type' => 'boolean'],
                'trashed' => ['type' => 'boolean'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['success'],
        ],
        'execute_callback' => static function (array $input): array|WP_Error {
            $slug = (string) ($input['slug'] ?? '');
            if ($slug === '') {
                return new WP_Error('missing_slug', __('A slug is required.', domain: 'wppilot'));
            }
            // Only user-cpt skills can be deleted. If the slug exists in
            // an external source, refuse cleanly without raising an error.
            $post = SkillWrite\find_user_post_by_slug($slug);
            if ($post === null) {
                return [
                    'success' => true,
                    'deleted' => false,
                    'trashed' => false,
                    'reason' => 'not_user_managed_or_not_found',
                ];
            }
            $permanent = filter_var($input['permanent'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($permanent) {
                $result = wp_delete_post($post->ID, force_delete: true);
                return [
                    'success' => (bool) $result,
                    'deleted' => (bool) $result,
                    'trashed' => false,
                ];
            }
            $result = wp_trash_post($post->ID);
            return [
                'success' => $result !== false && $result !== null,
                'deleted' => false,
                'trashed' => $result !== false && $result !== null,
            ];
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'annotations' => [
                'readonly' => false,
                'destructive' => true,
                'idempotent' => true,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}
