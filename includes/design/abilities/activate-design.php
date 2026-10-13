<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\Activate;

use WP_Error;
use WPPilot\Design\Abilities;
use WPPilot\Design\Contract;
use WPPilot\Design\Library;
use WPPilot\Design\Parser;
use WPPilot\Design\Store;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/activate-design', [
        'label' => __('Activate Design', domain: 'wppilot'),
        'description' => __(
            'Make a ready design system (by slug) active for this site. Incomplete saved drafts are rejected until their required colors and typography are repaired.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                    'description' => 'The slug of the design to activate.',
                ],
            ],
            'required' => ['slug'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'activated' => ['type' => 'boolean'],
                'slug' => ['type' => 'string'],
            ],
            'required' => ['activated'],
        ],
        'execute_callback' => static function (array $input): array|WP_Error {
            $slug = Parser\normalize_slug((string) ($input['slug'] ?? ''));
            if ($slug === '') {
                return new WP_Error('missing_slug', __('A slug is required.', domain: 'wppilot'));
            }
            $record = Library\find($slug);
            if ($record === null) {
                return new WP_Error('unknown_design', __('No design with that slug exists.', domain: 'wppilot'));
            }
            $inspection = Contract\inspect($record['content']);
            if (!$inspection['readiness']['ready']) {
                return new WP_Error('design_not_ready', Contract\activation_error($inspection));
            }
            Store\set_active($slug);
            return ['activated' => true, 'slug' => $slug];
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'annotations' => [
                'readonly' => false,
                'destructive' => false,
                'idempotent' => true,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}
