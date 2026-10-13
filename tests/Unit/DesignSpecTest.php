<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot\Design\Spec;

/**
 * A design document says what a site should feel like. A spec says what it
 * should measure. The distinction is the whole feature: asked to match a
 * reference with only a direction to go on, a model gets the palette right
 * because a screenshot gives colour away, and invents the section order, the
 * container, the scale and the weight. Nothing then notices, because every
 * other rule asks whether the page is any good rather than whether it is the
 * page that was asked for.
 *
 * So these tests defend two things: that a spec refuses a guess rather than
 * storing one, and that the comparison is capable of saying no. A grader that
 * only ever returns a pass is worse than no grader, because it converts "we
 * never checked" into "we checked and it was fine".
 */
final class DesignSpecTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function spec(array $overrides = []): array
    {
        return [
            'source' => 'url',
            'reference' => 'https://example.test/',
            'container' => 1380,
            'section_padding' => 144,
            'surfaces' => ['paper' => '#f3f0e6', 'ink' => '#142017', 'lime' => '#d9ff63'],
            'type' => [
                'h1' => ['family' => 'Archivo', 'size' => 87, 'weight' => '900', 'line_height' => 0.92, 'tracking' => -0.035],
                'body' => ['family' => 'Work Sans', 'size' => 20, 'weight' => '400', 'line_height' => 1.625],
            ],
            'sections' => [
                ['surface' => 'paper', 'layout' => 'split-2'],
                ['surface' => 'ink', 'layout' => 'stack'],
                ['surface' => 'lime', 'layout' => 'stack'],
            ],
            ...$overrides,
        ];
    }

    // ------------------------------------------------------------ refusals

    public function test_a_spec_without_surfaces_is_refused(): void
    {
        $result = Spec\normalize(self::spec(['surfaces' => []]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wppilot_spec_surfaces', $result->get_error_code());
    }

    public function test_a_spec_without_a_type_scale_is_refused(): void
    {
        $result = Spec\normalize(self::spec(['type' => []]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wppilot_spec_type', $result->get_error_code());
    }

    /**
     * The section sequence is the part that gets invented when it is not
     * stated, so a spec that omits it is not a spec.
     */
    public function test_a_spec_without_sections_is_refused(): void
    {
        $result = Spec\normalize(self::spec(['sections' => []]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wppilot_spec_sections', $result->get_error_code());
    }

    /**
     * A section naming a surface nobody declared would otherwise compile to a
     * class with no background, which reads as a styling bug rather than as the
     * typo it is.
     */
    public function test_a_section_on_an_undeclared_surface_is_refused(): void
    {
        $result = Spec\normalize(self::spec([
            'sections' => [['surface' => 'midnight', 'layout' => 'stack']],
        ]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wppilot_spec_section_surface', $result->get_error_code());
    }

    public function test_an_unknown_layout_is_refused_with_the_valid_names(): void
    {
        $result = Spec\normalize(self::spec([
            'sections' => [['surface' => 'paper', 'layout' => 'masonry']],
        ]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertStringContainsString('split-3', $result->get_error_message());
    }

    /**
     * A type role with no size cannot be compiled into anything, and defaulting
     * one would put a number nobody chose into a class that is then graded
     * against as though somebody had.
     */
    public function test_a_type_role_without_a_size_is_refused(): void
    {
        $result = Spec\normalize(self::spec([
            'type' => ['h1' => ['family' => 'Archivo', 'weight' => '900']],
        ]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wppilot_spec_type_size', $result->get_error_code());
    }

    public function test_a_valid_spec_normalizes(): void
    {
        $result = Spec\normalize(self::spec());

        self::assertIsArray($result);
        self::assertSame(1380.0, $result['container']);
        self::assertSame('#f3f0e6', $result['surfaces']['paper']);
        self::assertCount(3, $result['sections']);
    }

    // -------------------------------------------------------------- roles

    /**
     * The roles are what a builder turns into classes, so every surface, every
     * layout and every type role on every surface has to be present. Generating
     * them from the spec is the thing that stops a matched design being sixteen
     * classes somebody typed out by hand and could not reproduce.
     */
    public function test_every_surface_layout_and_type_role_is_generated(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);
        $roles = Spec\roles($spec);

        self::assertArrayHasKey('surface-paper', $roles);
        self::assertArrayHasKey('surface-ink', $roles);
        self::assertArrayHasKey('container', $roles);
        self::assertArrayHasKey('layout-split-3', $roles);
        self::assertArrayHasKey('h1-on-ink', $roles);
        self::assertArrayHasKey('body-on-lime', $roles);
    }

    /**
     * Tracking is stored in em because it is the only unit that survives a size
     * change, and a spec is applied at more than one size the moment it has a
     * mobile variant.
     */
    public function test_type_styles_carry_weight_and_tracking(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);
        $styles = Spec\roles($spec)['h1-on-paper']['styles'];

        self::assertSame('87px', $styles['font-size']);
        self::assertSame('900', $styles['font-weight']);
        self::assertSame('-0.035em', $styles['letter-spacing']);
    }

    /**
     * A design that chose a near-black green and a warm off-white chose them for
     * each other. Dropping pure white onto its dark sections is the detail that
     * makes an otherwise faithful copy look slightly wrong, so the foreground is
     * picked from the spec's own surfaces.
     */
    public function test_text_on_a_surface_uses_a_declared_colour(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);
        $roles = Spec\roles($spec);

        self::assertSame('#f3f0e6', $roles['h1-on-ink']['styles']['color']);
        self::assertSame('#142017', $roles['h1-on-lime']['styles']['color']);
    }

    /**
     * Every multi-column layout collapses on a phone. Leaving that to each
     * section would get it stated wrongly exactly once.
     */
    public function test_multi_column_layouts_collapse_on_mobile(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);
        $roles = Spec\roles($spec);

        self::assertSame('1fr', $roles['layout-split-3']['mobile']['grid-template-columns']);
    }

    // ---------------------------------------------------------- comparison

    public function test_a_matching_page_scores_one(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);

        $result = Spec\compare($spec, [
            'surfaces' => ['#f3f0e6', '#142017', '#d9ff63'],
            'container' => 1380,
            'section_padding' => 144,
            'type' => ['h1' => ['size' => 87, 'weight' => '900'], 'body' => ['size' => 20, 'weight' => '400']],
        ]);

        self::assertSame(1.0, $result['score']);
        self::assertSame([], $result['diffs']);
    }

    /**
     * The failure this whole feature exists for: a page that shares the palette
     * and matches nothing else. It has to score badly, and it has to say why.
     */
    public function test_a_page_that_shares_only_the_palette_scores_badly(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);

        $result = Spec\compare($spec, [
            // Same three colours, wrong order, wrong everything else.
            'surfaces' => ['#142017', '#d9ff63', '#f3f0e6'],
            'container' => 1200,
            'section_padding' => 112,
            'type' => ['h1' => ['size' => 72, 'weight' => '700'], 'body' => ['size' => 19, 'weight' => '400']],
        ]);

        self::assertLessThan(0.7, $result['score']);
        $properties = array_column($result['diffs'], 'property');
        self::assertContains('h1 weight', $properties);
        self::assertContains('container', $properties);
    }

    /**
     * Compared as a sequence, not a set. The same grounds in a different order
     * is a different page, and a set comparison would call it perfect.
     */
    public function test_the_surface_order_is_compared_position_by_position(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);

        $result = Spec\compare($spec, [
            'surfaces' => ['#142017', '#f3f0e6', '#d9ff63'],
            'container' => 1380,
            'section_padding' => 144,
            'type' => ['h1' => ['size' => 87, 'weight' => '900'], 'body' => ['size' => 20, 'weight' => '400']],
        ]);

        $properties = array_column($result['diffs'], 'property');
        self::assertContains('section 0 surface', $properties);
        self::assertContains('section 1 surface', $properties);
    }

    /**
     * Weight decides whether a page reads as the same design at all, so it is
     * graded without tolerance even though size is not.
     */
    public function test_weight_has_no_tolerance_but_size_does(): void
    {
        $spec = Spec\normalize(self::spec());
        self::assertIsArray($spec);

        $near = Spec\compare($spec, [
            'surfaces' => ['#f3f0e6', '#142017', '#d9ff63'],
            'container' => 1380,
            'section_padding' => 144,
            // 87.44 is the same decision as 87; 800 is not the same as 900.
            'type' => ['h1' => ['size' => 87.44, 'weight' => '800'], 'body' => ['size' => 20, 'weight' => '400']],
        ]);

        $properties = array_column($near['diffs'], 'property');
        self::assertNotContains('h1 size', $properties);
        self::assertContains('h1 weight', $properties);
    }
    // ---------------------------------------------------- composed items

    /**
     * A section holding one composed card, as a pricing tier would be.
     *
     * @return array<string, mixed>
     */
    private static function tier(array $item_overrides = [], array $block_overrides = []): array
    {
        return self::spec([
            'sections' => [
                [
                    'surface' => 'paper',
                    'layout' => 'stack',
                    'blocks' => [
                        [
                            'type' => 'cards',
                            'columns' => 3,
                            'variants' => ['featured' => ['surface' => 'ink', 'styles' => ['border-radius' => '18px']]],
                            'items' => [
                                [
                                    'blocks' => [
                                        ['type' => 'heading', 'role' => 'h3', 'text' => 'Studio'],
                                        [
                                            'type' => 'group',
                                            'layout' => 'row-baseline',
                                            'blocks' => [
                                                ['type' => 'heading', 'role' => 'h1', 'text' => '$45'],
                                                ['type' => 'text', 'text' => '/month'],
                                            ],
                                        ],
                                    ],
                                    ...$item_overrides,
                                ],
                            ],
                            ...$block_overrides,
                        ],
                    ],
                ],
                ['surface' => 'ink', 'layout' => 'stack'],
                ['surface' => 'lime', 'layout' => 'stack'],
            ],
        ]);
    }

    /**
     * The shape the whole vocabulary change exists for. A price is a figure and
     * a suffix at two sizes on one baseline, and before items could nest there
     * was no way to say it, which is why every card this pipeline built came out
     * as a heading and a paragraph.
     */
    public function test_an_item_can_hold_blocks(): void
    {
        $result = Spec\normalize(self::tier());

        self::assertIsArray($result);
        $item = $result['sections'][0]['blocks'][0]['items'][0];
        self::assertCount(2, $item['blocks']);
        self::assertSame('row-baseline', $item['blocks'][1]['layout']);
        self::assertSame('$45', $item['blocks'][1]['blocks'][0]['text']);
    }

    /**
     * Either shorthand or composed. A hybrid would need an ordering rule nobody
     * can predict from the schema.
     */
    public function test_an_item_cannot_be_both_shorthand_and_composed(): void
    {
        $result = Spec\normalize(self::tier(['title' => 'Studio']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wppilot_spec_item_hybrid', $result->get_error_code());
    }

    public function test_a_plain_shorthand_item_still_works(): void
    {
        $result = Spec\normalize(self::spec([
            'sections' => [
                [
                    'surface' => 'paper',
                    'layout' => 'stack',
                    'blocks' => [['type' => 'cards', 'items' => [['title' => 'Studio', 'text' => 'For one.']]]],
                ],
                ['surface' => 'ink', 'layout' => 'stack'],
                ['surface' => 'lime', 'layout' => 'stack'],
            ],
        ]));

        self::assertIsArray($result);
        $item = $result['sections'][0]['blocks'][0]['items'][0];
        self::assertSame('Studio', $item['title']);
        self::assertSame([], $item['blocks']);
    }

    // ---------------------------------------------------------- variants

    public function test_a_variant_the_block_declares_is_kept(): void
    {
        $result = Spec\normalize(self::tier(['variant' => 'featured']));

        self::assertIsArray($result);
        $block = $result['sections'][0]['blocks'][0];
        self::assertSame('featured', $block['items'][0]['variant']);
        self::assertSame('ink', $block['variants']['featured']['surface']);
    }

    /**
     * Silently ignoring an undeclared variant is how a featured tier ships
     * looking exactly like its neighbours with nothing reporting a problem.
     */
    public function test_an_undeclared_variant_is_refused(): void
    {
        $result = Spec\normalize(self::tier(['variant' => 'highlighted']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wppilot_spec_item_variant', $result->get_error_code());
    }

    // ------------------------------------------------------------ states

    public function test_states_keep_the_known_ones_and_drop_the_rest(): void
    {
        $result = Spec\normalize(self::tier([], ['states' => [
            'hover' => ['opacity' => '0.9'],
            'visited' => ['opacity' => '0.5'],
        ]]));

        self::assertIsArray($result);
        $states = $result['sections'][0]['blocks'][0]['states'];
        self::assertSame(['hover' => ['opacity' => '0.9']], $states);
    }

    // ----------------------------------------------------------- layouts

    /**
     * Sections have always had their layout checked and blocks never did, so a
     * misspelling was accepted at save time and degraded to a stack at build
     * time. Survivable while every layout was a grid; with a baseline row in the
     * vocabulary it turns a price into two lines and says nothing.
     */
    public function test_an_unknown_block_layout_is_refused(): void
    {
        $result = Spec\normalize(self::spec([
            'sections' => [
                [
                    'surface' => 'paper',
                    'layout' => 'stack',
                    'blocks' => [['type' => 'group', 'layout' => 'row-baselines', 'blocks' => []]],
                ],
                ['surface' => 'ink', 'layout' => 'stack'],
                ['surface' => 'lime', 'layout' => 'stack'],
            ],
        ]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wppilot_spec_block_layout', $result->get_error_code());
    }

    /**
     * A card set of one, or of five, used to ask for a layout role nothing
     * generated, so the grid came out as a bare div and the cards stacked.
     */
    public function test_every_column_count_a_card_set_can_ask_for_has_a_role(): void
    {
        $roles = Spec\roles(Spec\normalize(self::spec()));

        foreach (range(1, 6) as $columns) {
            self::assertArrayHasKey('layout-split-' . $columns, $roles);
        }
    }

    public function test_a_baseline_row_is_a_flex_row_that_survives_mobile(): void
    {
        $roles = Spec\roles(Spec\normalize(self::spec()));

        self::assertSame('flex', $roles['layout-row-baseline']['styles']['display']);
        // Elementor's atomic schema has no `baseline` in its align-items enum,
        // so a class asking for one is refused at compile time rather than
        // degrading. Flex-end is the closest the schema allows and is what the
        // price row actually needs.
        self::assertSame('flex-end', $roles['layout-row-baseline']['styles']['align-items']);
        self::assertSame('center', $roles['layout-row']['styles']['align-items']);
        // Collapsing this one would break the price it exists for into two lines.
        self::assertSame([], $roles['layout-row-baseline']['mobile']);
    }
    // ---------------------------------------------------------- skeleton

    /**
     * The skeleton walked only the top level, which was survivable while
     * nesting was rare and became a blind spot the moment items could hold
     * blocks: six plain cards and six composed pricing tiers both reduced to
     * "ca", so distinctiveness called them the same page and said nothing. The
     * structure that got deeper is exactly the structure carrying the
     * difference.
     */
    public function test_the_skeleton_sees_inside_composed_items(): void
    {
        $plain = Spec\normalize(self::spec([
            'sections' => [
                [
                    'surface' => 'paper',
                    'layout' => 'stack',
                    'blocks' => [['type' => 'cards', 'items' => [['title' => 'Studio'], ['title' => 'Agency']]]],
                ],
                ['surface' => 'ink', 'layout' => 'stack'],
                ['surface' => 'lime', 'layout' => 'stack'],
            ],
        ]));

        self::assertIsArray($plain);
        self::assertNotSame(Spec\skeleton($plain), Spec\skeleton(Spec\normalize(self::tier())));
    }

    /** A variant is structural: it is what stops a set reading as a table. */
    public function test_the_skeleton_records_a_variant(): void
    {
        $with = Spec\skeleton(Spec\normalize(self::tier(['variant' => 'featured'])));
        $without = Spec\skeleton(Spec\normalize(self::tier()));

        self::assertNotSame($without, $with);
    }

    public function test_the_skeleton_records_nesting_depth(): void
    {
        $shallow = Spec\normalize(self::spec([
            'sections' => [
                [
                    'surface' => 'paper',
                    'layout' => 'stack',
                    'blocks' => [['type' => 'heading', 'role' => 'h2', 'text' => 'Plans']],
                ],
                ['surface' => 'ink', 'layout' => 'stack'],
                ['surface' => 'lime', 'layout' => 'stack'],
            ],
        ]));
        $nested = Spec\normalize(self::spec([
            'sections' => [
                [
                    'surface' => 'paper',
                    'layout' => 'stack',
                    'blocks' => [[
                        'type' => 'group',
                        'layout' => 'stack',
                        'blocks' => [['type' => 'heading', 'role' => 'h2', 'text' => 'Plans']],
                    ]],
                ],
                ['surface' => 'ink', 'layout' => 'stack'],
                ['surface' => 'lime', 'layout' => 'stack'],
            ],
        ]));

        self::assertNotSame(Spec\skeleton($shallow), Spec\skeleton($nested));
    }
    // ------------------------------------------------------------- craft

    /**
     * Card padding was 32px, its inner gap 12px and a button's inset 18 by 32,
     * written into the plugin and identical on every design it had ever
     * produced. Two specs that agreed about nothing else still produced cards
     * with the same inset, which is a large part of why spec-built pages
     * recognised each other.
     */
    public function test_component_insets_come_from_the_spec_not_the_plugin(): void
    {
        $tight = Spec\roles(Spec\normalize(self::spec(['gap' => 16])));
        $airy = Spec\roles(Spec\normalize(self::spec(['gap' => 48])));

        self::assertNotSame(
            $tight['card-paper']['styles']['padding'],
            $airy['card-paper']['styles']['padding'],
        );
        self::assertNotSame(
            $tight['card-paper']['styles']['gap'],
            $airy['card-paper']['styles']['gap'],
        );
        self::assertNotSame(
            $tight['button-paper']['styles']['padding'],
            $airy['button-paper']['styles']['padding'],
        );
    }

    /**
     * Text running into a corner arc is geometry, not taste — but the geometry
     * is the corner's encroachment, r(1 - 1/root 2), not the radius. "Pad by at
     * least the radius" is the rule of thumb everyone repeats and it is about
     * three times too strict; applied literally it forces padding to equal or
     * exceed the radius on every card, which makes every nested corner exactly
     * zero and the concentric rule unreachable.
     */
    public function test_a_card_clears_its_corner_without_over_padding(): void
    {
        $roles = Spec\roles(Spec\normalize(self::spec([
            'gap' => 8,
            'radius' => ['md' => 40, 'pill' => 999],
        ])));

        $pad = (float) rtrim((string) $roles['card-paper']['styles']['padding']['inline-start'], 'px');
        $radius = (float) rtrim((string) $roles['card-paper']['styles']['border-radius'], 'px');

        self::assertGreaterThanOrEqual($radius * Spec\CORNER_ENCROACHMENT, $pad, 'content must clear the arc');
        self::assertLessThan($radius, $pad, 'a tight design should not be forced to pad by its whole radius');
    }

    /**
     * The concentric rule has to be reachable. With the old floor the inner
     * radius was zero on every design ever generated, which made the role that
     * carries it decorative.
     */
    public function test_a_nested_corner_is_actually_curved_on_a_rounded_design(): void
    {
        $roles = Spec\roles(Spec\normalize(self::spec([
            'gap' => 16,
            'radius' => ['md' => 40, 'pill' => 999],
        ])));

        $inner = (float) rtrim((string) $roles['card-inner-paper']['styles']['border-radius'], 'px');

        self::assertGreaterThan(0.0, $inner);
    }

    /** A card whose children are spaced like its siblings has no inside. */
    public function test_a_cards_inner_gap_is_tighter_than_the_page_gap(): void
    {
        $spec = Spec\normalize(self::spec(['gap' => 32]));
        $roles = Spec\roles($spec);

        $inner = (float) rtrim((string) $roles['card-paper']['styles']['gap'], 'px');
        $outer = (float) rtrim((string) $roles['stack']['styles']['gap'], 'px');

        self::assertLessThan($outer, $inner);
    }

    /**
     * Two curves look like one object when they share a centre, and share one
     * exactly when the inner radius is the outer minus the gap between them.
     * Equal radii at different offsets visibly do not — the inner corner looks
     * too round and nobody can say why, because the error is entirely
     * perceptual.
     */
    public function test_a_nested_fill_takes_a_concentric_corner(): void
    {
        $roles = Spec\roles(Spec\normalize(self::spec([
            'gap' => 16,
            'radius' => ['md' => 40, 'pill' => 999],
        ])));

        $outer = (float) rtrim((string) $roles['card-paper']['styles']['border-radius'], 'px');
        $pad = (float) rtrim((string) $roles['card-paper']['styles']['padding']['inline-start'], 'px');
        $inner = (float) rtrim((string) $roles['card-inner-paper']['styles']['border-radius'], 'px');

        self::assertSame(Spec\inner_radius($outer, $pad), $inner);
        self::assertLessThan($outer, $inner);
    }

    /** A negative corner is not a tighter one. */
    public function test_an_inner_radius_never_goes_negative(): void
    {
        self::assertSame(0.0, Spec\inner_radius(12.0, 32.0));
        self::assertSame(0.0, Spec\inner_radius(0.0, 0.0));
        self::assertSame(16.0, Spec\inner_radius(48.0, 32.0));
    }

    public function test_every_surface_gets_an_inner_card_role(): void
    {
        $roles = Spec\roles(Spec\normalize(self::spec()));

        foreach (['paper', 'ink', 'lime'] as $surface) {
            self::assertArrayHasKey('card-inner-' . $surface, $roles);
        }
    }
    /**
     * A button's proportions come from the button, not from the card it sits
     * near. Floored on the card inset, a design with 40px corners produced 10px
     * of button height against 40px of width: a card's optical floor is driven
     * by its radius, and a button does not have that radius.
     */
    public function test_a_button_stays_in_proportion_whatever_the_corners_are(): void
    {
        foreach ([[16, 12], [16, 40], [32, 24], [48, 64]] as [$gap, $radius]) {
            $roles = Spec\roles(Spec\normalize(self::spec([
                'gap' => $gap,
                'radius' => ['md' => $radius, 'pill' => 999],
            ])));

            $pad = $roles['button-paper']['styles']['padding'];
            $y = (float) rtrim((string) $pad['block-start'], 'px');
            $x = (float) rtrim((string) $pad['inline-start'], 'px');

            self::assertGreaterThan($y, $x, "gap {$gap}/radius {$radius}: a button is wider than it is tall");
            self::assertLessThan(
                2.5,
                $x / $y,
                "gap {$gap}/radius {$radius}: a button should not read as a squat bar",
            );
        }
    }
    // ------------------------------------------------------- compositions

    /**
     * A composition is a shape, and where it goes is decided when it is used.
     * Validating it through the same normalizer a spec's blocks go through is
     * the whole point: anything that passes is expressible, buildable and
     * gradeable, rather than being notes in a second format that drifts.
     */
    public function test_a_composition_normalizes_on_its_own(): void
    {
        $blocks = Spec\normalize_composition([
            [
                'type' => 'cards',
                'columns' => 3,
                'variants' => ['featured' => ['surface' => 'ink']],
                'items' => [
                    ['blocks' => [
                        ['type' => 'heading', 'role' => 'card', 'text' => 'Studio'],
                        ['type' => 'group', 'layout' => 'row-baseline', 'blocks' => [
                            ['type' => 'heading', 'role' => 'h1', 'text' => '$45'],
                            ['type' => 'text', 'text' => '/month'],
                        ]],
                    ]],
                ],
            ],
        ]);

        self::assertIsArray($blocks);
        self::assertSame('cards', $blocks[0]['type']);
        self::assertCount(2, $blocks[0]['items'][0]['blocks']);
    }

    /** The same refusals a spec gets, because it is the same normalizer. */
    public function test_a_composition_is_refused_for_the_same_reasons_a_spec_is(): void
    {
        $hybrid = Spec\normalize_composition([
            ['type' => 'cards', 'items' => [['title' => 'Studio', 'blocks' => [['type' => 'text', 'text' => 'x']]]]],
        ]);
        self::assertInstanceOf(\WP_Error::class, $hybrid);
        self::assertSame('wppilot_spec_item_hybrid', $hybrid->get_error_code());

        $unknown = Spec\normalize_composition([['type' => 'carousel']]);
        self::assertInstanceOf(\WP_Error::class, $unknown);
        self::assertSame('wppilot_spec_block_type', $unknown->get_error_code());
    }

    /**
     * Two captured shapes have to be tellable apart, or a library of them is a
     * list of one shape under several names.
     */
    public function test_two_compositions_have_different_signatures(): void
    {
        $plain = Spec\normalize_composition([
            ['type' => 'cards', 'items' => [['title' => 'Studio'], ['title' => 'Agency']]],
        ]);
        $composed = Spec\normalize_composition([
            ['type' => 'cards', 'items' => [
                ['blocks' => [['type' => 'heading', 'role' => 'card', 'text' => 'Studio']]],
                ['blocks' => [['type' => 'heading', 'role' => 'card', 'text' => 'Agency']]],
            ]],
        ]);

        self::assertIsArray($plain);
        self::assertIsArray($composed);
        self::assertNotSame(Spec\block_signature($plain), Spec\block_signature($composed));
    }
    // --------------------------------------------------------- outline level

    /**
     * A type role was a size and, silently, also an outline level. A hero and a
     * loud closing band both wanting the largest size on the page both got an
     * h1, and a price set at heading size got one per tier. How big a thing
     * looks should not decide what it means to a screen reader.
     */
    public function test_a_block_can_set_its_tag_apart_from_its_role(): void
    {
        $result = Spec\normalize(self::spec([
            'sections' => [
                [
                    'surface' => 'paper',
                    'layout' => 'stack',
                    'blocks' => [
                        ['type' => 'heading', 'role' => 'display', 'text' => 'The page'],
                        ['type' => 'heading', 'role' => 'display', 'tag' => 'h2', 'text' => 'Just as loud'],
                    ],
                ],
                ['surface' => 'ink', 'layout' => 'stack'],
                ['surface' => 'lime', 'layout' => 'stack'],
            ],
        ]));

        self::assertIsArray($result);
        $blocks = $result['sections'][0]['blocks'];
        self::assertSame('', $blocks[0]['tag'], 'no tag means derive it from the role, as every older spec does');
        self::assertSame('h2', $blocks[1]['tag']);
    }

    public function test_a_tag_outside_the_outline_is_refused(): void
    {
        $result = Spec\normalize(self::spec([
            'sections' => [
                [
                    'surface' => 'paper',
                    'layout' => 'stack',
                    'blocks' => [['type' => 'heading', 'role' => 'h1', 'tag' => 'strong', 'text' => 'x']],
                ],
                ['surface' => 'ink', 'layout' => 'stack'],
                ['surface' => 'lime', 'layout' => 'stack'],
            ],
        ]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('wppilot_spec_block_tag', $result->get_error_code());
    }
}
