<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\CompareReferences;

use WP_Error;
use WPPilot\Design\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Turn measurements of several reference pages into a convention report.
 *
 * The alternative is what everybody else ships: a file per industry saying
 * dental sites have these eight sections in this order. It works instantly, it
 * is stale the month it is written, it covers only the industries somebody
 * thought of, and — worst — it hands every dentist the identical page. A
 * prescribed structure is a template; moving it from the pixel level to the
 * section level does not stop it being one.
 *
 * Measured evidence answers the same question and one more. A property that
 * appears in every reference is a convention, and whether to honour it or break
 * it depends entirely on which kind it is. A booking button above the fold is
 * functional: eight out of eight means it works, and breaking it costs the
 * client money. Three equal cards is decorative: eight out of eight means it is
 * the thing that makes all eight look alike, and matching it is how the ninth
 * site becomes invisible.
 *
 * So the report splits on that line and refuses to collapse it. A single
 * frequency table cannot tell you which way to move, and the split is the whole
 * value: it says what to keep and what to avoid, from the same data.
 */

/**
 * Which dimensions are functional and which are decorative.
 *
 * Functional dimensions carry a job — somebody has to be able to call, book, or
 * find the price. Decorative ones carry only a look. The list is short and
 * deliberately conservative: when a dimension is arguable it belongs in
 * decorative, because wrongly treating a look as a job produces a page that
 * copies its competitors and defends it as best practice.
 *
 * @return array<string, string>
 */
function dimensions(): array
{
    return [
        'cta_above_fold' => 'functional',
        'phone_visible' => 'functional',
        'nav_top' => 'functional',
        'form_present' => 'functional',
        'proof_present' => 'functional',
        'section_count' => 'structural',
        'section_order' => 'decorative',
        'hero_centred' => 'decorative',
        'three_card_grid' => 'decorative',
        'card_grid_present' => 'decorative',
        'accent_hue' => 'decorative',
        'display_face' => 'decorative',
        'ground_tone' => 'decorative',
        'corner_style' => 'decorative',
    ];
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/compare-references', [
        'label' => __('Compare References', domain: 'wppilot'),
        'description' => __(
            'Turn measurements of several reference pages into a report of what is convention and what is cliche. Supply what you observed on each reference, one object per site, using the dimension names this returns when called with no observations. A fixed per-industry checklist of what a homepage contains goes stale and gives every site in that industry the same page; this answers the question from the references you measured instead. A property present on every reference is a convention, and what to do about it depends on which kind: a booking button above the fold on eight of eight references is probably functional and worth keeping, while three equal cards on eight of eight is decoration that makes the sites look alike. The report keeps those apart, because a single frequency table cannot tell you which way to move. Honour the functional agreements, diverge from the decorative ones, and say in the design which you broke and why.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => [
                'observations' => [
                    'type' => 'array',
                    'description' => 'One object per reference: {reference: "url or name", <dimension>: <value>}. Booleans for the yes/no dimensions, strings or numbers for the rest. Omit a dimension you did not check rather than guessing it.',
                    'items' => ['type' => 'object'],
                ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            /** @var mixed $raw */
            $raw = $input['observations'] ?? [];
            $observations = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];

            if ($observations === []) {
                return [
                    'dimensions' => dimensions(),
                    'note' => __(
                        'Measure each reference and call again with one object per site. Read them from a browser rather than from the markup: a rendered page is a resolved cascade, and what a section looks like is not what its HTML says. Omit anything you did not actually check, because a guessed observation becomes a convention nobody observed.',
                        domain: 'wppilot',
                    ),
                ];
            }

            if (count($observations) < 3) {
                return new WP_Error(
                    'wppilot_references_too_few',
                    __(
                        'Three references is the minimum worth reporting on. Below that a shared property is a coincidence, and calling it a convention is how one competitor\'s taste becomes a rule.',
                        domain: 'wppilot',
                    ),
                    ['status' => 422],
                );
            }

            $known = dimensions();
            $total = count($observations);

            /** @var array<string, array<string, int>> $tally */
            $tally = [];
            foreach ($observations as $observation) {
                foreach ($observation as $key => $value) {
                    $key = sanitize_key((string) $key);
                    if ($key === 'reference' || !array_key_exists($key, $known)) {
                        continue;
                    }
                    $bucket = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
                    $tally[$key][$bucket] = ($tally[$key][$bucket] ?? 0) + 1;
                }
            }

            $honour = [];
            $diverge = [];
            $open = [];

            foreach ($tally as $dimension => $counts) {
                arsort($counts);
                $top = (string) array_key_first($counts);
                $count = (int) $counts[$top];
                $share = $count / $total;
                $entry = [
                    'dimension' => $dimension,
                    'common_value' => $top,
                    'seen' => $count . ' of ' . $total,
                    'share' => round($share, 2),
                ];

                // Two thirds is the line between "most of them" and "all of
                // them do this". Below it there is no convention to honour or
                // break, and reporting one would invent agreement that is not
                // in the data.
                if ($share < 0.66) {
                    $open[] = $entry;
                    continue;
                }

                if (($known[$dimension] ?? 'decorative') === 'functional') {
                    $honour[] = $entry;
                    continue;
                }
                $diverge[] = $entry;
            }

            return [
                'references' => $total,
                'honour' => $honour,
                'diverge' => $diverge,
                'open' => $open,
                'note' => __(
                    'Honour is what the references agree on where agreement means it works. Diverge is what they agree on where agreement is only what makes them look alike; matching those is how a new site becomes indistinguishable from the ones it is competing with. Open is where the references disagree, which means the decision is genuinely yours and nothing is riding on it.',
                    domain: 'wppilot',
                ),
            ];
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true, 'type' => 'tool'],
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        ],
    ]);
}
