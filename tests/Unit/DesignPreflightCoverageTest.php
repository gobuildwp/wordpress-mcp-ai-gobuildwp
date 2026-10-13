<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot\Design\Preflight;

/**
 * What the checker claims to have checked.
 *
 * Reporting the whole rule list as checked when half of it was skipped is worse
 * than reporting nothing, because it converts "there was no design to measure
 * against" into "we measured and it was fine". That is the exact failure the
 * `not_checked` list exists to prevent everywhere else in this module, and it
 * was quietly happening in the list right next to it.
 *
 * The seam is pinned here too. Craft rules live in Pro and join through a
 * filter, and a rule that runs without being reported is the same
 * mis-statement of coverage in the opposite direction.
 */
final class DesignPreflightCoverageTest extends TestCase
{
    protected function tearDown(): void
    {
        $GLOBALS['wp_filter'] = [];
        parent::tearDown();
    }

    public function test_with_a_design_active_every_mechanized_rule_is_claimed(): void
    {
        self::assertSame(Preflight\MECHANIZED, Preflight\checked(true));
    }

    /**
     * Without a design there is no palette, no ladder and no Don't list, so the
     * rules that read them never ran.
     */
    public function test_without_a_design_the_conditional_rules_are_not_claimed(): void
    {
        $checked = Preflight\checked(false);

        foreach (Preflight\DESIGN_CONDITIONAL as $rule) {
            self::assertNotContains($rule, $checked, $rule . ' cannot run without a design');
        }
        self::assertContains('em-dash', $checked);
        self::assertContains('filler-copy', $checked);
    }

    public function test_every_conditional_rule_is_a_mechanized_one(): void
    {
        foreach (Preflight\DESIGN_CONDITIONAL as $rule) {
            self::assertContains($rule, Preflight\MECHANIZED);
        }
    }

    /** An extension's rules are reported, and reported once. */
    public function test_a_contributed_rule_is_claimed_without_duplicating(): void
    {
        add_filter(
            'wppilot_design_checked',
            static fn(array $extra): array => [...$extra, 'space-off-scale', 'em-dash'],
        );

        $checked = Preflight\checked(true);

        self::assertContains('space-off-scale', $checked);
        self::assertSame(1, count(array_keys($checked, 'em-dash', strict: true)));
    }

    /** A filter returning something that is not a list cannot corrupt the report. */
    public function test_a_broken_filter_does_not_break_the_report(): void
    {
        add_filter('wppilot_design_checked', static fn(): string => 'nonsense');

        self::assertSame(Preflight\MECHANIZED, Preflight\checked(true));
    }
}
