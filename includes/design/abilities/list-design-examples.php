<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\Examples;

use WP_Error;
use WPPilot\Design\Abilities;
use WPPilot\Design\Contract;
use WPPilot\Design\Contrast;
use WPPilot\Design\Examples;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/list-design-examples', [
        'label' => __('List Design Examples', domain: 'wppilot'),
        'description' => __(
            'Worked example design directions to read and learn from before writing one. Call with no arguments for short cards describing each; call with a slug for the full DESIGN.md, its contrast analysis and its readiness check. These are references, not presets: there is no ability that applies one, and copying a palette onto a site it was not chosen for produces exactly the generic result the design gate exists to catch. Read the closest one to see how a direction is argued and structured, then write one for this site — the site\'s own existing design is usually the better starting point, via wppilot/adopt-design-from-site.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => [
                'slug' => [
                    'type' => 'string',
                    'description' => 'Return one example in full instead of the list.',
                ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            $slug = trim((string) ($input['slug'] ?? ''));
            if ($slug === '') {
                return [
                    'examples' => Examples\summaries(),
                    'note' => __(
                        'Read one with the slug argument. These are examples to learn from, not presets to apply: adapt the reasoning to this site rather than copying the palette.',
                        domain: 'wppilot',
                    ),
                ];
            }

            $record = Examples\find($slug);
            if ($record === null) {
                return new WP_Error(
                    'wppilot_example_not_found',
                    sprintf(
                        /* translators: 1: requested slug, 2: comma-separated list of valid slugs. */
                        __('No example named %1$s. Available: %2$s.', domain: 'wppilot'),
                        $slug,
                        implode(', ', Examples\slugs()),
                    ),
                );
            }

            $inspection = Contract\inspect($record['content']);

            return [
                'slug' => $record['slug'],
                'name' => $record['name'],
                'description' => $record['description'],
                'design_markdown' => $record['content'],
                'tokens' => $inspection['tokens'],
                'dials' => $inspection['dials'],
                'waivers' => $inspection['waivers'],
                'readiness' => $inspection['readiness'],
                'contrast' => Contrast\analyze_palette($record['content']),
                'next_step' => __(
                    'To use it, adapt it: change the palette and faces to this site\'s brand, rewrite the reasoning for this business, then save with wppilot/save-design. Saving it verbatim gives the site a design that was argued for somebody else.',
                    domain: 'wppilot',
                ),
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
