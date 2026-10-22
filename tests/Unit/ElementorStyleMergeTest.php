<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function WPPilot\Elementor\el_merge_style_entries;

// The Elementor module is not part of the suite bootstrap (see
// ElementorAtomicAuditTest). styles-map only declares functions.
require_once dirname(__DIR__, levels: 2) . '/includes/elementor/helpers/wppilot-styles-map.php';

/**
 * edit-element promises that styles merge on top of what is there. The
 * ergonomic shorthand always lands on the element's own `s-<id>` entry, so an
 * entry-level merge turned "right-align this row" into "delete the row's
 * layout and every responsive variant".
 */
final class ElementorStyleMergeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function row(): array
    {
        return ['s-row' => [
            'id' => 's-row',
            'type' => 'class',
            'label' => 'local',
            'variants' => [
                ['meta' => ['breakpoint' => 'desktop', 'state' => null], 'props' => ['display' => 'flex', 'gap' => '32px']],
                ['meta' => ['breakpoint' => 'mobile', 'state' => null], 'props' => ['display' => 'none']],
            ],
        ]];
    }

    public function test_a_shorthand_edit_keeps_the_other_properties_and_variants(): void
    {
        $merged = el_merge_style_entries($this->row(), ['s-row' => [
            'id' => 's-row',
            'type' => 'class',
            'label' => 'local',
            'variants' => [
                ['meta' => ['breakpoint' => 'desktop', 'state' => null], 'props' => ['justify-content' => 'flex-end', 'gap' => '24px']],
            ],
        ]]);

        $variants = $merged['s-row']['variants'];
        self::assertCount(2, $variants);
        self::assertSame(['display' => 'flex', 'gap' => '24px', 'justify-content' => 'flex-end'], $variants[0]['props']);
        self::assertSame(['display' => 'none'], $variants[1]['props']);
    }

    public function test_a_new_breakpoint_or_state_is_added_and_a_new_id_is_set(): void
    {
        $merged = el_merge_style_entries($this->row(), [
            's-row' => ['variants' => [
                ['meta' => ['breakpoint' => 'desktop', 'state' => 'hover'], 'props' => ['opacity' => '0.8']],
            ]],
            's-other' => ['id' => 's-other', 'variants' => []],
        ]);

        self::assertCount(3, $merged['s-row']['variants']);
        self::assertSame(['opacity' => '0.8'], $merged['s-row']['variants'][2]['props']);
        self::assertSame('local', $merged['s-row']['label']);
        self::assertSame(['id' => 's-other', 'variants' => []], $merged['s-other']);
    }
}
