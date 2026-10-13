<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\BuilderQuality;

use PHPUnit\Framework\TestCase;
use WPPilot\Kits\BuilderQuality;

/**
 * builder-quality: the audit of an Elementor element tree, classic and atomic.
 */
final class AuditTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once dirname(__DIR__, 4) . '/includes/kits/builder-quality/src/audit.php';
    }

    private static function known(): \Closure
    {
        return static fn(string $type): bool => $type !== 'acme-slider';
    }

    public function test_a_clean_page_scores_100(): void
    {
        $tree = [[
            'id' => 's1', 'elType' => 'container', 'settings' => [],
            'elements' => [
                ['id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Hi', '__globals__' => ['title_color' => 'globals/colors?id=primary'], 'title_color' => '#ff0000']],
                ['id' => 'p1', 'elType' => 'widget', 'widgetType' => 'e-paragraph', 'settings' => ['paragraph' => ['$$type' => 'string', 'value' => 'Text']]],
            ],
        ]];

        $audit = BuilderQuality\audit_tree($tree, self::known());

        self::assertSame(100, $audit['score'], 'a colour with a global is not hard-coded');
        self::assertSame('good', $audit['grade']);
        self::assertSame([], $audit['items']);
    }

    public function test_each_finding_is_reported_with_its_element_and_fix(): void
    {
        $tree = [[
            'id' => 'c1', 'elType' => 'section', 'settings' => ['custom_css' => 'selector { color: red; }', 'background_color' => '#123456'],
            'elements' => [
                ['id' => 'w1', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => ['html' => '<div style="padding:4px">Hi</div><script>x()</script>']],
                ['id' => 'w2', 'elType' => 'widget', 'widgetType' => 'shortcode', 'settings' => ['shortcode' => '[gallery]']],
                ['id' => 'w3', 'elType' => 'widget', 'widgetType' => 'acme-slider', 'settings' => []],
                ['id' => 'e1', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => []],
            ],
        ]];

        $audit = BuilderQuality\audit_tree($tree, self::known());
        $issues = array_column($audit['items'], 'issue', 'element_id');

        self::assertEqualsCanonicalizing(['html_widget' => 1, 'script' => 1, 'inline_style' => 1, 'shortcode_widget' => 1, 'unknown_widget' => 1, 'custom_css' => 1, 'hardcoded_color' => 1, 'empty_container' => 1], $audit['counts']);
        self::assertSame('unknown_widget', $issues['w3']);
        self::assertSame('empty_container', $issues['e1']);
        self::assertSame(100 - 20 - 15 - 3 - 5 - 5 - 3 - 1 - 1, $audit['score']);
        self::assertSame('poor', $audit['grade'], '47 is below 60');
        self::assertArrayHasKey('html_widget', $audit['fixes']);
    }

    public function test_penalties_are_capped_per_kind_and_nesting_is_counted(): void
    {
        $widgets = array_map(static fn(int $i): array => ['id' => "h{$i}", 'elType' => 'widget', 'widgetType' => 'html', 'settings' => ['html' => '<p>x</p>']], range(1, 9));
        $deep = ['id' => 'd8', 'elType' => 'e-div-block', 'settings' => [], 'elements' => $widgets];
        for ($i = 7; $i >= 1; $i--) {
            $deep = ['id' => "d{$i}", 'elType' => 'e-div-block', 'settings' => [], 'elements' => [$deep]];
        }

        $audit = BuilderQuality\audit_tree([$deep], self::known());

        self::assertSame(9, $audit['counts']['html_widget']);
        self::assertSame(2, $audit['counts']['deep_nesting'], 'containers 7 and 8 levels deep');
        self::assertSame(100 - 40 - 4, $audit['score'], 'nine HTML widgets cost at most 40');
        self::assertSame('poor', $audit['grade']);
    }
}
