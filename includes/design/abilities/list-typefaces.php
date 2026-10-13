<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\Typefaces;

use WP_Error;
use WPPilot\Design\Abilities;
use WPPilot\Design\Typefaces;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/list-typefaces', [
        'label' => __('List Typefaces', domain: 'wppilot'),
        'description' => __(
            'A considered set of typefaces to choose a pairing from, and the rule for whether two of them go together. Asked for a font, the habit is Inter, Poppins, Montserrat or Playfair Display, which is why generated pages sound alike even when their palettes differ. Every face here says what it sounds like and what it can carry, so a pairing can be chosen rather than defaulted to. Pass two families to have the pairing checked before committing to it: the check refuses two faces from the same class, which read as one face gone wrong rather than as a decision, and refuses a display face set as body text. Pass one family and a role to be given the faces that pair with it.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => [
                'display' => [
                    'type' => 'string',
                    'description' => 'The heading face. With "body", the pairing is checked and the reasons returned.',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'The body face. With "display", the pairing is checked.',
                ],
                'pairs_with' => [
                    'type' => 'string',
                    'description' => 'Return the faces that pair soundly with this one.',
                ],
                'role' => [
                    'type' => 'string',
                    'enum' => ['display', 'body', 'ui', 'mono'],
                    'description' => 'Which half "pairs_with" is filling, and what to filter the full list by. Defaults to body.',
                ],
                'classification' => [
                    'type' => 'string',
                    'description' => 'Return only faces of one class: grotesque, neo-grotesque, geometric-sans, humanist-sans, transitional-serif, old-style-serif, modern-serif, slab-serif, display, mono.',
                ],
                'include_overused' => [
                    'type' => 'boolean',
                    'description' => 'Include the faces every generated page reaches for. Off by default; they stay in the set so a design that genuinely wants one can name it and be told what it costs.',
                ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            $display = trim((string) ($input['display'] ?? ''));
            $body = trim((string) ($input['body'] ?? ''));
            $role = (string) ($input['role'] ?? 'body');
            $include_overused = (bool) ($input['include_overused'] ?? false);

            // Checking a pairing is the call that matters, so it answers first
            // and answers only that.
            if ($display !== '' && $body !== '') {
                $verdict = Typefaces\pairing($display, $body);
                return [
                    'checked' => ['display' => $display, 'body' => $body],
                    'sound' => $verdict['ok'],
                    // Whether the only objection was that a face is common.
                    // A pairing with no contrast is wrong; a pairing of two
                    // well-worn faces is a decision somebody can defend, and
                    // collapsing the two into one boolean would hide which is
                    // which from the only caller that has to choose.
                    'structurally_sound' => $verdict['structural_ok'],
                    'reasons' => $verdict['reasons'],
                    'display_face' => Typefaces\get($display),
                    'body_face' => Typefaces\get($body),
                ];
            }

            if (($input['pairs_with'] ?? '') !== '') {
                $anchor = trim((string) $input['pairs_with']);
                if (Typefaces\get($anchor) === null) {
                    return new WP_Error(
                        'wppilot_unknown_typeface',
                        sprintf(
                            /* translators: %s: the requested family name */
                            __(
                                '"%s" is not in the set. That is not a reason to avoid it — the set is a starting point, not a licence list — but partners can only be suggested for a face described here.',
                                domain: 'wppilot',
                            ),
                            $anchor,
                        ),
                        ['status' => 404],
                    );
                }

                return [
                    'pairs_with' => $anchor,
                    'role' => $role,
                    'partners' => Typefaces\partners($anchor, $role, $include_overused),
                ];
            }

            $classification = strtolower(trim((string) ($input['classification'] ?? '')));

            /** @var list<array<string, mixed>> $faces */
            $faces = [];
            foreach (Typefaces\all() as $name => $face) {
                if (($face['overused'] ?? false) === true && !$include_overused) {
                    continue;
                }
                if ($classification !== '' && strtolower((string) $face['classification']) !== $classification) {
                    continue;
                }
                if (($input['role'] ?? '') !== '' && !in_array($role, (array) $face['roles'], strict: true)) {
                    continue;
                }
                $faces[] = array_merge(['family' => $name], $face);
            }

            return [
                'typefaces' => $faces,
                'source' => 'Google Fonts',
                'note' => __(
                    'Check a pairing with display and body before writing it into a design. A face from outside this set is never refused: a licensed face chosen for the brand beats anything in a dropdown.',
                    domain: 'wppilot',
                ),
            ];
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
