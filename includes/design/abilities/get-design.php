<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\Get;

use WP_Error;
use WPPilot\Design\Abilities;
use WPPilot\Design\Contract;
use WPPilot\Design\Distinct;
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

    wp_register_ability('wppilot/get-design', [
        'label' => __('Get Design', domain: 'wppilot'),
        'description' => __(
            'Return a specific design system by slug as raw DESIGN.md plus its structured contract and readiness. Use to read, repair, or evolve a saved direction.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                    'description' => 'Slug of the design to read.',
                ],
            ],
            'required' => ['slug'],
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => array_merge([
                'found' => ['type' => 'boolean'],
                'slug' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'is_active' => ['type' => 'boolean'],
            ], Contract\ability_output_properties()),
            'required' => ['found'],
        ],
        'execute_callback' => static function (array $input): array|WP_Error {
            $slug = (string) ($input['slug'] ?? '');
            if ($slug === '') {
                return new WP_Error('missing_slug', __('A slug is required.', domain: 'wppilot'));
            }

            $record = Library\find($slug);
            if ($record === null) {
                return ['found' => false];
            }

            // How this one sits against the rest of the library. Designs saved
            // before this comparison existed never got the note at save time,
            // and reviewing a design is the other moment it is worth knowing.
            $distinct = Distinct\check($record['content'], $record['slug']);

            return array_merge([
                'found' => true,
                'slug' => $record['slug'],
                'name' => $record['name'],
                'description' => $record['description'],
                'content' => $record['content'],
                'is_active' => Store\get_active_slug() === $record['slug'],
                'distinctiveness' => [
                    'distinct' => $distinct['distinct'],
                    'compared_against' => $distinct['compared'],
                    'nearest' => $distinct['nearest'],
                    'findings' => $distinct['findings'],
                ],
            ], Contract\inspect($record['content']));
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
