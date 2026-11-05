<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot\Design\Preflight;
use WPPilot\Design\Spec;
use WPPilot\Design\Tokens;

/**
 * Two ways a design check used to misread its input.
 *
 * A colour scale names its steps with numbers (`50`, `900`), and PHP turns a
 * numeric string key into an int. Under strict types that int reached
 * strtolower() and threw, so one ordinary DESIGN.md broke every check, and a
 * numeric surface name in a spec failed its own strict membership test.
 *
 * The font scan read a declaration past the end of its style attribute, into
 * the next attribute and the element's text, so a page about "Inter Milan
 * tickets" was told it used Inter and `class="lead"` became a font name.
 */
final class DesignNumericNamesAndFontScanTest extends TestCase
{
    private const NUMERIC_DESIGN = "---\ncolors:\n  50: \"#f5f5f5\"\n  900: \"#111111\"\n  accent: \"#2255aa\"\ntypography:\n  100:\n    fontFamily: Fraunces\n  body:\n    fontFamily: Work Sans\n---\n# Design\n";

    // ------------------------------------------------------- numeric names

    public function test_pick_value_accepts_numeric_colour_keys(): void
    {
        self::assertSame('#2255aa', Tokens\pick_value([50 => '#f5f5f5', 'Accent' => '#2255aa'], ['accent']));
        self::assertSame('#111111', Tokens\pick_value([900 => '#111111'], ['900']));
    }

    public function test_pick_typography_prop_accepts_numeric_role_keys(): void
    {
        $typography = [100 => ['fontFamily' => 'Fraunces'], 'body-md' => ['fontFamily' => 'Work Sans']];

        self::assertSame('Fraunces', Tokens\pick_typography_prop($typography, ['100'], prop: 'fontFamily'));
        // The scale-prefix fallback walks every key, the numeric one included.
        self::assertSame('Work Sans', Tokens\pick_typography_prop($typography, ['body'], prop: 'fontFamily'));
    }

    public function test_a_design_with_a_numeric_colour_scale_builds_a_preflight_context(): void
    {
        $ctx = Preflight\context(self::NUMERIC_DESIGN);

        self::assertTrue($ctx['has_active']);
        self::assertSame('#2255aa', $ctx['accent']);
        self::assertContains('fraunces', $ctx['allowed_fonts']);
        self::assertContains('#f5f5f5', $ctx['allowed_colors']);
    }

    public function test_a_spec_section_can_name_a_numeric_surface(): void
    {
        $result = Spec\normalize([
            'source' => 'url',
            'reference' => 'https://example.test/',
            'container' => 1200,
            'section_padding' => 96,
            'surfaces' => ['50' => '#f5f5f5', '900' => '#111111'],
            'type' => [
                'h1' => ['family' => 'Archivo', 'size' => 64, 'weight' => '800', 'line_height' => 1.0, 'tracking' => -0.02],
                'body' => ['family' => 'Work Sans', 'size' => 18, 'weight' => '400', 'line_height' => 1.6],
            ],
            'sections' => [
                ['surface' => '50', 'layout' => 'stack'],
                ['surface' => '900', 'layout' => 'stack'],
            ],
        ]);

        self::assertIsArray($result, $result instanceof \WP_Error ? $result->get_error_message() : '');
        self::assertSame(['50', '900'], array_column($result['sections'], 'surface'));
    }

    // ----------------------------------------------------------- font scan

    public function test_inter_in_element_text_after_a_style_attribute_is_not_a_font(): void
    {
        $html = '<p style="font-family:Fraunces; color:#111">Inter Milan tickets</p>';

        self::assertSame([], Preflight\inter_font($html, ['fraunces']));
    }

    public function test_inter_declared_in_a_style_attribute_or_stylesheet_is_still_caught(): void
    {
        self::assertCount(1, Preflight\inter_font('<p style="font-family: Inter, sans-serif">x</p>', []));
        self::assertCount(1, Preflight\inter_font("<style>body{font-family:'Work Sans', Inter, sans-serif}</style>", []));
    }

    public function test_font_families_stop_at_the_style_attribute_quote(): void
    {
        $html = '<p style="font-family:Fraunces" class="lead">Body text</p>';

        self::assertSame(['fraunces'], Preflight\font_families($html));
    }

    public function test_font_families_read_quoted_names_in_stylesheets_and_encoded_attributes(): void
    {
        $html = '<style>h1{font-family: "Work Sans", serif}</style>'
            . '<div style="font-family:&quot;DM Serif Display&quot;, Georgia; color:#000">x</div>'
            . "<span style='font-family:\"Space Grotesk\"'>y</span>";

        $families = Preflight\font_families($html);
        sort($families);

        self::assertSame(['dm serif display', 'georgia', 'serif', 'space grotesk', 'work sans'], $families);
    }

    public function test_a_numeric_font_family_is_returned_as_a_string(): void
    {
        self::assertSame(['900'], Preflight\font_families('<style>p{font-family: 900}</style>'));
        // font_off_palette reads every family with str_starts_with(); an int threw.
        self::assertCount(1, Preflight\font_off_palette('<style>p{font-family: 900}</style>', ['fraunces']));
    }
}
