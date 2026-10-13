<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\Adopt;

use WP_Error;
use WPPilot\Design\Abilities;
use WPPilot\Design\Adopt;
use WPPilot\Design\Contrast;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/adopt-design-from-site', [
        'label' => __('Adopt Design From Site', domain: 'wppilot'),
        'description' => __(
            'Reads the design this site already has — the theme.json global styles a block theme defines, the customizer\'s standard colours, and whatever a builder module can contribute — and returns a DESIGN.md draft built from it. Use this before inventing anything on a site that is not brand new: the brand already exists in the theme and the existing pages, and a fresh direction invented alongside it produces work that clashes with every page already there. Nothing is saved; review the draft, add the reasoning it cannot know, then save it with wppilot/save-design.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Name for the draft. Defaults to the site title.',
                ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            $gathered = Adopt\gather();
            if ($gathered['colors'] === [] && $gathered['typography'] === []) {
                return new WP_Error(
                    'wppilot_adopt_nothing_found',
                    __(
                        'Nothing readable was found: no theme.json palette, no customizer colours, and no builder module contributed any. This site may define its design only in CSS, which cannot be read from here. Establish a direction from a brief instead.',
                        domain: 'wppilot',
                    ),
                );
            }

            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') {
                // get_bloginfo returns the stored title, which is HTML-encoded:
                // an ampersand in a site name arrives as &amp; and would be
                // written into the design's front matter verbatim.
                $name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
            }
            if ($name === '') {
                $name = __('Adopted design', domain: 'wppilot');
            }

            $markdown = Adopt\to_markdown($name, $gathered);

            // Contrast is reported with the draft rather than left for later:
            // an adopted palette is inherited, not chosen, and finding out it
            // cannot carry body text is more useful before anybody builds on it.
            $contrast = null;
            if (function_exists('WPPilot\\Design\\Contrast\\analyze_palette')) {
                $analysis = Contrast\analyze_palette($markdown);
                $contrast = [
                    'readable_pairs' => $analysis['readable_pairs'],
                    'total_pairs' => $analysis['total_pairs'],
                    'text_safe' => $analysis['text_safe'],
                    'warnings' => $analysis['warnings'],
                ];
            }

            return [
                'name' => $name,
                'design_markdown' => $markdown,
                'sources' => $gathered['sources'],
                'token_counts' => [
                    'colors' => count($gathered['colors']),
                    'typography' => count($gathered['typography']),
                    'spacing' => count($gathered['spacing']),
                ],
                'notes' => $gathered['notes'],
                'contrast' => $contrast,
                'next_step' => __(
                    'Review the draft, add the reasoning it cannot know — which colour is the accent, what the site should never do — then save with wppilot/save-design.',
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
