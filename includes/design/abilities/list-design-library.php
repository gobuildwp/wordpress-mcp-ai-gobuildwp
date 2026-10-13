<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\ListLibrary;

use WPPilot\Design\Abilities;
use WPPilot\Design\Contract;
use WPPilot\Design\Library;
use WPPilot\Design\Store;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/list-design-library', [
        'label' => __('List Design Library', domain: 'wppilot'),
        'description' => __(
            'List saved design systems, readiness, sync readiness, and which is active.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => new \stdClass(),
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'designs' => ['type' => 'array'],
                'active_slug' => ['type' => 'string'],
            ],
            'required' => ['designs', 'active_slug'],
        ],
        'execute_callback' => static function (array $input): array {
            $designs = [];
            foreach (Library\all() as $record) {
                $inspection = Contract\inspect($record['content']);
                $designs[] = [
                    'slug' => $record['slug'],
                    'name' => $record['name'],
                    'description' => $record['description'],
                    'ready' => $inspection['readiness']['ready'],
                    'sync_ready' => $inspection['readiness']['sync_ready'],
                ];
            }
            return [
                'designs' => $designs,
                'active_slug' => Store\get_active_slug(),
            ];
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
            'mcp' => ['public' => true, 'type' => 'tool'],
        ],
    ]);
}
