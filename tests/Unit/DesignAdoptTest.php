<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot\Design\Adopt;

require_once dirname(__DIR__, 2) . '/includes/design/adopt.php';

/**
 * Turning what a site already looks like into a draft DESIGN.md.
 */
final class DesignAdoptTest extends TestCase
{
    /**
     * Twenty Twenty-Five names its spacing sizes "20" to "80". PHP stores those
     * slugs as int array keys, and under strict types the draft writer threw a
     * TypeError on the first one, so adopting the default theme's design failed.
     */
    public function test_numeric_slugs_from_theme_json_are_written_as_keys(): void
    {
        $markdown = Adopt\to_markdown('Site', [
            'colors' => ['1' => '#111111', 'base' => '#ffffff'],
            'typography' => ['7' => ['fontFamily' => 'Inter']],
            'spacing' => ['20' => '10px', '30' => '20px'],
            'sources' => [['source' => 'theme.json']],
        ]);

        self::assertStringContainsString('  20: "10px"', $markdown);
        self::assertStringContainsString('  30: "20px"', $markdown);
        self::assertStringContainsString('  1: "#111111"', $markdown);
        self::assertStringContainsString("  7:\n", $markdown);
    }
}
