<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;

use function WPPilot\Elementor\el_check_unique_ids;
use function WPPilot\Elementor\el_find_duplicate_ids;
use function WPPilot\Elementor\el_regenerate_tree_ids;
use function WPPilot\Elementor\el_tree_has_atomic_elements;

// The Elementor module is not part of the suite bootstrap (see
// ElementorAtomicAuditTest). These three files only declare functions.
foreach ([
    'helpers/wppilot-page-io.php',
    'helpers/wppilot-tree.php',
    'add-element/wppilot-style-classes.php',
] as $wppilot_tree_ids_test_file) {
    require_once dirname(__DIR__, levels: 2) . '/includes/elementor/' . $wppilot_tree_ids_test_file;
}
unset($wppilot_tree_ids_test_file);

/**
 * Element ids are the document's only addressing scheme, and per-element
 * styles are named after them. Two nodes sharing one, or a node whose styles
 * still carry another node's id, does not fail loudly: it renders, and then
 * the wrong element changes on the next edit.
 */
final class ElementorTreeIdsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function style(string $sid, string $color): array
    {
        return [
            'id' => $sid,
            'type' => 'class',
            'label' => 'local',
            'variants' => [
                ['meta' => ['breakpoint' => 'desktop', 'state' => null], 'props' => ['color' => $color]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function card(): array
    {
        return [
            'id' => 'card',
            'elType' => 'e-flexbox',
            'settings' => ['classes' => ['$$type' => 'classes', 'value' => ['s-card', 'g-brand']]],
            'styles' => [
                's-card' => $this->style('s-card', 'red'),
                // Global-ish style id not derived from the element id: left alone.
                'hero-accent' => $this->style('hero-accent', 'blue'),
            ],
            'elements' => [
                [
                    'id' => 'abc1234',
                    'elType' => 'widget',
                    'widgetType' => 'e-heading',
                    // Elementor's editor names local styles e-<element id>-<suffix>.
                    'settings' => ['classes' => ['$$type' => 'classes', 'value' => ['e-abc1234-9f8e7d6']]],
                    'styles' => ['e-abc1234-9f8e7d6' => $this->style('e-abc1234-9f8e7d6', 'green')],
                    'elements' => [],
                ],
            ],
        ];
    }

    public function test_an_atomic_widget_stored_as_widget_plus_widget_type_counts_as_atomic(): void
    {
        $classic_page_with_one_atomic_widget = [[
            'id' => 'sec1',
            'elType' => 'section',
            'settings' => [],
            'elements' => [[
                'id' => 'col1',
                'elType' => 'column',
                'settings' => [],
                'elements' => [
                    ['id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => []],
                    ['id' => 'h2', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [], 'elements' => []],
                ],
            ]],
        ]];

        self::assertTrue(el_tree_has_atomic_elements($classic_page_with_one_atomic_widget));
    }

    public function test_classic_only_trees_are_not_atomic_and_atomic_el_types_still_are(): void
    {
        $classic = [[
            'id' => 'c1',
            'elType' => 'container',
            'settings' => [],
            'elements' => [
                ['id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => []],
                // A widget type that merely contains "e-" is not atomic.
                ['id' => 'w2', 'elType' => 'widget', 'widgetType' => 'image-box', 'settings' => [], 'elements' => []],
            ],
        ]];
        self::assertFalse(el_tree_has_atomic_elements($classic));

        self::assertTrue(el_tree_has_atomic_elements([
            ['id' => 'f1', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => []],
        ]));
    }

    public function test_regenerating_ids_replaces_every_id_and_moves_element_derived_styles(): void
    {
        $copy = el_regenerate_tree_ids($this->card());

        $root_id = $copy['id'];
        $child_id = $copy['elements'][0]['id'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{7}$/', $root_id);
        self::assertMatchesRegularExpression('/^[0-9a-f]{7}$/', $child_id);
        self::assertNotSame('card', $root_id);
        self::assertNotSame('abc1234', $child_id);
        self::assertNotSame($root_id, $child_id);

        // Root: s-<old> becomes s-<new>, inner id too; an unrelated style id and a
        // global class reference are untouched.
        self::assertSame(['s-' . $root_id, 'hero-accent'], array_keys($copy['styles']));
        self::assertSame('s-' . $root_id, $copy['styles']['s-' . $root_id]['id']);
        self::assertSame('red', $copy['styles']['s-' . $root_id]['variants'][0]['props']['color']);
        self::assertSame('hero-accent', $copy['styles']['hero-accent']['id']);
        self::assertSame(
            ['$$type' => 'classes', 'value' => ['s-' . $root_id, 'g-brand']],
            $copy['settings']['classes'],
        );

        // Child: the editor's e-<old>-<suffix> convention keeps its suffix.
        $child_sid = 'e-' . $child_id . '-9f8e7d6';
        self::assertSame([$child_sid], array_keys($copy['elements'][0]['styles']));
        self::assertSame($child_sid, $copy['elements'][0]['styles'][$child_sid]['id']);
        self::assertSame([$child_sid], $copy['elements'][0]['settings']['classes']['value']);
    }

    public function test_a_duplicated_subtree_shares_no_ids_or_local_style_classes_with_its_source(): void
    {
        $original = $this->card();
        $copy = el_regenerate_tree_ids($original);

        // The source is not modified.
        self::assertSame($this->card(), $original);

        // Inserted next to the original, the page still has unique ids.
        self::assertSame([], el_find_duplicate_ids([$original, $copy]));

        // The only style id the two share is the one not derived from an id.
        $local = static function (array $node) use (&$local): array {
            $ids = array_keys($node['styles'] ?? []);
            foreach ($node['elements'] as $child) {
                $ids = [...$ids, ...$local($child)];
            }
            return $ids;
        };
        self::assertSame(['hero-accent'], array_values(array_intersect($local($original), $local($copy))));
    }

    public function test_regenerating_twice_gives_two_distinct_copies(): void
    {
        $a = el_regenerate_tree_ids($this->card());
        $b = el_regenerate_tree_ids($this->card());

        self::assertSame([], el_find_duplicate_ids([$this->card(), $a, $b]));
    }

    public function test_duplicate_ids_within_a_tree_are_refused_and_named(): void
    {
        $tree = [
            ['id' => 'hero', 'elType' => 'container', 'settings' => [], 'elements' => [
                ['id' => 'title', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => []],
                ['id' => 'title', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => []],
            ]],
            ['id' => 'hero', 'elType' => 'container', 'settings' => [], 'elements' => []],
            ['id' => 'footer', 'elType' => 'container', 'settings' => [], 'elements' => []],
        ];

        self::assertSame(['hero', 'title'], el_find_duplicate_ids($tree));

        $error = el_check_unique_ids($tree);
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('wppilot_elementor_duplicate_ids', $error->get_error_code());
        self::assertSame(['duplicate_ids' => ['hero', 'title']], $error->get_error_data());
        self::assertStringContainsString('hero, title', $error->get_error_message());
    }

    public function test_appended_ids_are_checked_against_the_page(): void
    {
        $page = [['id' => 'hero', 'elType' => 'container', 'settings' => [], 'elements' => [
            ['id' => 'cta', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [], 'elements' => []],
        ]]];
        $new = [['id' => 'pricing', 'elType' => 'container', 'settings' => [], 'elements' => [
            ['id' => 'cta', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [], 'elements' => []],
        ]]];

        $error = el_check_unique_ids($new, $page);
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame(['duplicate_ids' => ['cta']], $error->get_error_data());

        self::assertNull(el_check_unique_ids(
            [['id' => 'pricing', 'elType' => 'container', 'settings' => [], 'elements' => []]],
            $page,
        ));
        // Nodes without an id are the normalizer's job, not a collision.
        self::assertNull(el_check_unique_ids([['elType' => 'container'], ['elType' => 'container']]));
    }

    public function test_a_page_whose_own_ids_already_repeat_still_accepts_new_nodes(): void
    {
        $page = [
            ['id' => 'dup1', 'elType' => 'container', 'elements' => []],
            ['id' => 'dup1', 'elType' => 'container', 'elements' => []],
        ];

        self::assertSame([], el_find_duplicate_ids([['id' => 'fresh', 'elType' => 'container', 'elements' => []]], $page));
        self::assertSame(['dup1'], el_find_duplicate_ids([['id' => 'dup1', 'elType' => 'container', 'elements' => []]], $page));
    }
}
