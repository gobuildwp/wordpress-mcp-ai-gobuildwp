<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\Contrast;

use WP_Error;
use WPPilot\Design\Abilities;
use WPPilot\Design\Contrast;
use WPPilot\Design\Library;
use WPPilot\Design\Parser;
use WPPilot\Design\Store;
use WPPilot\Design\Tokens;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/check-contrast', [
        'label' => __('Check Design Contrast', domain: 'wppilot'),
        'description' => __(
            'Computes WCAG 2.x contrast ratios for every colour pair in a design\'s palette, and reports which surfaces can carry body text at AA. Call this when establishing or editing a design, and before choosing a text colour for a section: it answers "what can I put on this background" with the design\'s own colours instead of defaulting to black or white. Pass `background` to get the palette\'s strongest foreground for one colour. Pass `foreground` and `background` together to check a single pair, including colours not in the palette. This reports; it does not refuse anything — whether a pair is a problem depends on how the design uses it.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => [
                'slug' => [
                    'type' => 'string',
                    'description' => 'Design to analyse. Defaults to the active design.',
                ],
                'background' => [
                    'type' => 'string',
                    'description' => 'A background colour to find the best palette foreground for, e.g. "#14161B".',
                ],
                'foreground' => [
                    'type' => 'string',
                    'description' => 'Check one specific pair. Requires background.',
                ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string'],
                'pairs' => ['type' => 'array'],
                'readable_pairs' => ['type' => 'integer'],
                'total_pairs' => ['type' => 'integer'],
                'text_safe' => ['type' => 'array', 'items' => ['type' => 'string']],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'thresholds' => ['type' => 'object'],
            ],
        ],
        'execute_callback' => static function (array $input): array|WP_Error {
            $thresholds = [
                'aa_normal' => Contrast\AA_NORMAL,
                'aa_large' => Contrast\AA_LARGE,
                'aaa_normal' => Contrast\AAA_NORMAL,
            ];

            $foreground = trim((string) ($input['foreground'] ?? ''));
            $background = trim((string) ($input['background'] ?? ''));

            // A one-off pair check needs no design at all, so it is answered
            // before the design is resolved. Asking "is this readable on that"
            // is a fair question on a site that has never saved a direction.
            if ($foreground !== '' && $background !== '') {
                $ratio = Contrast\ratio($foreground, $background);
                if ($ratio === null) {
                    return new WP_Error(
                        'wppilot_contrast_unreadable_color',
                        __('Both foreground and background must be hex colours.', domain: 'wppilot'),
                    );
                }

                return [
                    'foreground' => $foreground,
                    'background' => $background,
                    'ratio' => round($ratio, precision: 2),
                    'grade' => Contrast\grade($ratio),
                    'thresholds' => $thresholds,
                ];
            }
            if ($foreground !== '' && $background === '') {
                return new WP_Error(
                    'wppilot_contrast_missing_background',
                    __('Checking a single pair needs background as well as foreground.', domain: 'wppilot'),
                );
            }

            $explicit = ($input['slug'] ?? null) !== null && $input['slug'] !== '';
            $slug = $explicit ? Parser\normalize_slug((string) $input['slug']) : Store\get_active_slug();
            if ($slug === '') {
                return new WP_Error(
                    'wppilot_contrast_no_design',
                    __('No design is active. Pass a slug, or pass foreground and background to check one pair.', domain: 'wppilot'),
                );
            }
            $record = Library\find($slug);
            if ($record === null) {
                return new WP_Error('unknown_design', __('No design with that slug exists.', domain: 'wppilot'));
            }

            if ($background !== '') {
                $colors = Tokens\extract((string) $record['content'])['colors'];
                $best = Contrast\best_foreground($background, $colors);
                if ($best === '') {
                    return new WP_Error(
                        'wppilot_contrast_no_foreground',
                        __('No colour in this palette can be measured against that background.', domain: 'wppilot'),
                    );
                }
                $ratio = (float) Contrast\ratio($best, $background);

                return [
                    'slug' => $slug,
                    'background' => $background,
                    'best_foreground' => $best,
                    'ratio' => round($ratio, precision: 2),
                    'grade' => Contrast\grade($ratio),
                    'thresholds' => $thresholds,
                ];
            }

            $analysis = Contrast\analyze_palette((string) $record['content']);

            return [
                'slug' => $slug,
                'pairs' => $analysis['pairs'],
                'readable_pairs' => $analysis['readable_pairs'],
                'total_pairs' => $analysis['total_pairs'],
                'text_safe' => $analysis['text_safe'],
                'warnings' => $analysis['warnings'],
                'thresholds' => $thresholds,
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
