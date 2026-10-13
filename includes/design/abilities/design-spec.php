<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Design\Abilities\DesignSpec;

use WP_Error;
use WPPilot\Design\Abilities;
use WPPilot\Design\Spec;
use WPPilot\Design\Store;

if (!defined('ABSPATH')) {
    exit();
}

/** The spec shape, described once and reused by both writing abilities. */
function spec_schema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'source' => [
                'type' => 'string',
                'enum' => Spec\SOURCES,
                'description' => 'How the measurements were arrived at. "url" when read off a rendered page, "image" from a screenshot or mockup, "figma" from a file, "prompt" when the design was described rather than shown.',
            ],
            'reference' => ['type' => 'string', 'description' => 'The page, file or image the spec came from.'],
            'container' => ['type' => 'number', 'description' => 'Max width of the content column in px. Content stops here while section colour runs edge to edge.'],
            'section_padding' => ['type' => 'number', 'description' => 'Vertical padding on a section in px.'],
            'section_padding_mobile' => ['type' => 'number', 'description' => 'The same on a phone. Omit and it is derived.'],
            'gap' => ['type' => 'number', 'description' => 'Default gap between stacked or gridded children, in px.'],
            'surfaces' => [
                'type' => 'object',
                'description' => 'The named backgrounds sections sit on, as name => hex. Name them for their job (paper, raised, sunk, ink, accent) rather than their colour, because the sequence is what gets reproduced.',
            ],
            'radius' => ['type' => 'object', 'description' => 'Named corner radii in px, e.g. {"sm":12,"md":24,"lg":38}.'],
            'type' => [
                'type' => 'object',
                'description' => 'Type roles => {family, size, size_mobile, weight, line_height, tracking, color, measure}. Tracking is in em. Weight and tracking matter as much as size: the same scale at the wrong weight does not read as the same design.',
            ],
            'sections' => [
                'type' => 'array',
                'description' => 'The page in order: [{surface, layout, gap, note}]. Layout is one of the named layouts; call wppilot/get-design-spec with no slug to list them.',
                'items' => ['type' => 'object'],
            ],
        ],
        'required' => ['surfaces', 'type', 'sections'],
    ];
}

function register(): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    wp_register_ability('wppilot/save-design-spec', [
        'label' => __('Save Design Spec', domain: 'wppilot'),
        'description' => __(
            'Attach reproduction measurements to a saved design, so a page can be built to match a reference instead of merely built in its spirit. A design document is a direction: it names a palette and two faces, deliberately leaves layout open, and cannot answer "does this match the screenshot". A spec is the numbers, in order: the surface each section sits on, the container width, the section padding, and the type scale with weight and tracking rather than size alone. Supply them from whatever you were given, a URL you measured, a screenshot, a Figma file, or your own reading of a written brief, and say which in "source". Nothing is inferred here and nothing is repaired: a missing value is refused rather than guessed, because a guessed number is then compiled into classes and graded against as though somebody had chosen it. Compile it with wppilot/elementor-compile-spec and grade the result with wppilot/compare-to-spec.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'The saved design to attach the spec to.'],
                'spec' => spec_schema(),
            ],
            'required' => ['slug', 'spec'],
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            $slug = trim((string) ($input['slug'] ?? ''));
            /** @var mixed $raw */
            $raw = $input['spec'] ?? null;
            if (!is_array($raw)) {
                return new WP_Error('wppilot_spec_missing', __('A spec object is required.', domain: 'wppilot'), ['status' => 400]);
            }

            $saved = Spec\save($slug, $raw);
            if ($saved instanceof WP_Error) {
                return $saved;
            }

            return [
                'saved' => true,
                'slug' => $slug,
                'spec' => $saved,
                'roles' => array_keys(Spec\roles($saved)),
                'next' => __(
                    'Compile the spec into builder classes with wppilot/elementor-compile-spec, build with role names rather than class ids, then grade the built page with wppilot/compare-to-spec.',
                    domain: 'wppilot',
                ),
            ];
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true, 'type' => 'tool'],
            'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
        ],
    ]);

    wp_register_ability('wppilot/get-design-spec', [
        'label' => __('Get Design Spec', domain: 'wppilot'),
        'description' => __(
            'Read a design\'s reproduction spec, the roles it compiles to, and the vocabulary a spec may use. Call it with no slug to get the layout names and type roles before writing one.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'default' => [],
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Design to read. Defaults to the active design.'],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array {
            $vocabulary = [
                'layouts' => Spec\layouts(),
                'type_roles' => Spec\type_roles(),
                'block_types' => Spec\block_types(),
                'states' => Spec\states(),
                'sources' => Spec\SOURCES,
                // An item used to be eight strings, and that is most of why
                // every card this pipeline built looked like every other one.
                // A model with the taste to design a pricing tier still emitted
                // a heading and a paragraph, because a heading and a paragraph
                // was the only sentence the vocabulary could say. Spelling the
                // composed form out here is the difference between the shape
                // being possible and it being discovered.
                'items' => [
                    'shorthand' => __(
                        'icon, src, eyebrow, title, text, value. For the sets that really are a mark and two lines, which is most of them. Terser than composing, and correct.',
                        domain: 'wppilot',
                    ),
                    'composed' => __(
                        'blocks: a full block list, nested. Use it the moment the member is more than a mark and two lines. A pricing tier is a ribbon, a name, a muted eligibility line, a price row, a feature list and a button; none of that is expressible as shorthand, and reaching for shorthand anyway is what makes a set look like a table with padding.',
                        domain: 'wppilot',
                    ),
                    'exclusive' => __(
                        'An item is shorthand or composed, never both. Declaring blocks alongside a title is refused rather than guessed at.',
                        domain: 'wppilot',
                    ),
                    'variant' => __(
                        'Name a variant in the block\'s "variants" map, then set "variant" on the members that take it. The plugin ships no variant names: they are yours. This is how one tier reads as the chosen one, and a set whose members are all identical is the shape that reads as generated.',
                        domain: 'wppilot',
                    ),
                    'styles_and_states' => __(
                        'styles is a flat CSS map for what the vocabulary cannot name; states holds hover and focus. Both are available on blocks, items and variants, and both are validated against Elementor\'s real style schema at build time. Reaching for styles where a named field exists makes the spec ungradeable, so use it for what is genuinely unnameable.',
                        domain: 'wppilot',
                    ),
                ],
                'row_baseline' => __(
                    'The layout a price needs: a figure at sixty pixels and a suffix at fourteen, sharing one baseline. Centring them instead is what makes a price look pasted on. Rows stay rows on a phone.',
                    domain: 'wppilot',
                ),
            ];

            $slug = trim((string) ($input['slug'] ?? ''));
            if ($slug === '') {
                $slug = Store\get_active_slug();
            }
            $spec = $slug !== '' ? Spec\get($slug) : null;
            if ($spec === null) {
                return [
                    'found' => false,
                    'slug' => $slug,
                    'vocabulary' => $vocabulary,
                    'note' => __(
                        'This design has no reproduction spec. Without one a build can only follow the direction, which is enough to be in the brand and not enough to match a reference.',
                        domain: 'wppilot',
                    ),
                ];
            }

            return [
                'found' => true,
                'slug' => $slug,
                'spec' => $spec,
                'roles' => Spec\roles($spec),
                'vocabulary' => $vocabulary,
            ];
        },
        'permission_callback' => 'wppilot_permission_callback',
        'meta' => [
            'show_in_rest' => true,
            'mcp' => ['public' => true, 'type' => 'tool'],
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        ],
    ]);

    wp_register_ability('wppilot/compare-to-spec', [
        'label' => __('Compare To Spec', domain: 'wppilot'),
        'description' => __(
            'Grade a built page against the spec it was meant to match, and return a score with the properties that differ. Supply what the page actually renders as: the surface of each section in order, the container width, the section padding, and the size and weight of each type role. Read those from a real browser, because a rendered page is a resolved cascade and no amount of reading the source will tell you what it came out as. The comparison itself is deliberately not yours to make: asked to grade its own work an agent grades it generously, and a build that lands at a fifth of the target looks finished from the inside. Surfaces are weighted hardest because their sequence is what a person recognises first, and weight is graded without tolerance because it is the property that decides whether a page reads as the same design at all.',
            domain: 'wppilot',
        ),
        'category' => Abilities\CATEGORY,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Design whose spec to grade against. Defaults to the active design.'],
                'actual' => [
                    'type' => 'object',
                    'description' => 'What the page renders as: {surfaces: [hex in section order], container, section_padding, type: {role: {size, weight}}}.',
                ],
            ],
            'required' => ['actual'],
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array|WP_Error {
            $slug = trim((string) ($input['slug'] ?? ''));
            if ($slug === '') {
                $slug = Store\get_active_slug();
            }
            $spec = $slug !== '' ? Spec\get($slug) : null;
            if ($spec === null) {
                return new WP_Error(
                    'wppilot_spec_absent',
                    __('That design has no spec to grade against. Save one with wppilot/save-design-spec first.', domain: 'wppilot'),
                    ['status' => 404],
                );
            }

            /** @var mixed $actual */
            $actual = $input['actual'] ?? null;
            if (!is_array($actual)) {
                return new WP_Error('wppilot_spec_actual', __('An "actual" object is required.', domain: 'wppilot'), ['status' => 400]);
            }

            $result = Spec\compare($spec, $actual);

            return [
                'slug' => $slug,
                'score' => $result['score'],
                'percent' => round($result['score'] * 100, 1),
                'diffs' => $result['diffs'],
                'reference' => $spec['reference'] ?? '',
                'note' => $result['diffs'] === []
                    ? __('Every measured property matches the spec.', domain: 'wppilot')
                    : __(
                        'The properties listed differ from the spec. A score here grades the measurements only: it says the page is built to the right numbers, not that every section of the reference exists.',
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
