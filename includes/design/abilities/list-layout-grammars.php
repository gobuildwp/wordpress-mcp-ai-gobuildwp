<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\Grammars;

use WP_Error;
use WPPilot\Design\Abilities;
use WPPilot\Design\Grammars;
use WPPilot\Design\Library;
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

    wp_register_ability('wppilot/list-layout-grammars', [
        'label' => __('List Layout Grammars', domain: 'wppilot'),
        'description' => __(
            'The compositions a section can be built from, and which one this design should use where. Generated pages share one shape — a vertical stack of full-width bands, each a centred heading over equal columns — not because the shape was chosen but because a stack of flex containers is the only thing most tooling can express. These are the alternatives: an asymmetric split, a panel overlapping the section boundary, content bleeding past one edge, absolutely-placed layers, a sticky index beside a scrolling detail. Each carries its own responsive behaviour, so a composition that works at 1440px is already defined at 390px. Call with a section index to be told which grammar to use there, chosen from the active design\'s variance dial and a per-site seed so the answer is repeatable rather than random.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Return one grammar in full, with its styles and responsive overrides.',
                ],
                'section_index' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'Ask which grammar to use for the section at this position. 0 is the opener, which may be the loudest; later sections are damped toward the baseline so a page has one strong moment rather than six competing ones.',
                ],
                'variance' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                    'description' => 'Override the active design\'s variance dial for this answer.',
                ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            $name = trim((string) ($input['name'] ?? ''));
            if ($name !== '') {
                $grammar = Grammars\get($name);
                if ($grammar === null) {
                    return new WP_Error(
                        'wppilot_unknown_grammar',
                        sprintf(
                            /* translators: 1: requested name, 2: comma-separated grammar names */
                            __('"%1$s" is not a layout grammar. Available: %2$s.', domain: 'wppilot'),
                            $name,
                            implode(', ', Grammars\names()),
                        ),
                        ['status' => 404, 'available' => Grammars\names()],
                    );
                }
                return ['name' => $name, 'grammar' => $grammar];
            }

            // The design decides how adventurous this site is allowed to be.
            $active = Store\get_active_slug();
            $design = $active !== '' ? Library\find($active) : null;
            $content = is_array($design) ? (string) ($design['content'] ?? '') : '';
            $tokens = $content !== '' ? Tokens\extract($content) : [];
            $dials = $tokens !== [] ? Tokens\dials($tokens) : ['variance' => 0.8, 'density' => 0.4, 'motion' => 0.5];

            $variance = array_key_exists('variance', $input)
                ? (float) $input['variance']
                : (float) $dials['variance'];

            // Grammar names the design named for itself, when it named any.
            /** @var mixed $declared */
            $declared = $tokens['layout']['grammars'] ?? '';
            $allowed = is_string($declared) && trim($declared) !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $declared))))
                : [];

            // The seed is the site, so two installs building the same brief do
            // not land on the same composition, and one install rebuilding is
            // reproducible.
            $seed = (string) get_home_url() . '|' . $active;

            /** @var list<array<string, mixed>> $cards */
            $cards = [];
            foreach (Grammars\all() as $key => $grammar) {
                $cards[] = [
                    'name' => $key,
                    'label' => (string) $grammar['label'],
                    'intent' => (string) $grammar['intent'],
                    'variance' => (float) $grammar['variance'],
                    'parts' => $grammar['parts'] ?? [],
                    'permitted' => $allowed === [] || in_array($key, $allowed, strict: true),
                ];
            }

            $result = [
                'grammars' => $cards,
                'design_variance' => $variance,
                'declared_grammars' => $allowed,
                'note' => __(
                    'A grammar carries structure only — no colour, no typeface, no copy — so the same grammar in two designs produces two pages that share a skeleton and nothing else. Read one in full with the name argument before building with it.',
                    domain: 'wppilot',
                ),
            ];

            if (array_key_exists('section_index', $input)) {
                $index = max(0, (int) $input['section_index']);
                $chosen = Grammars\choose($variance, $seed, $index, $allowed);
                $result['section_index'] = $index;
                $result['use'] = $chosen;
                $result['use_grammar'] = Grammars\get($chosen);
            }

            return $result;
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true, 'type' => 'tool'],
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
                'idempotent' => true,
            ],
        ],
    ]);
}
