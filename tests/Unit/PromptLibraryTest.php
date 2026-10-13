<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function WPPilot\PromptLibrary\briefs;
use function WPPilot\PromptLibrary\builder_line;
use function WPPilot\PromptLibrary\builders;
use function WPPilot\PromptLibrary\bundled_briefs;
use function WPPilot\PromptLibrary\by_sector;
use function WPPilot\PromptLibrary\compose;
use function WPPilot\PromptLibrary\normalize_brief;
use function WPPilot\PromptLibrary\standards;

use const WPPilot\PromptLibrary\BUILDER_LINE_PREFIX;

/**
 * The industry briefs: ten complete landing-page briefs, each unlike the others.
 *
 * The point of the library is that a bakery and a law firm do not get the same
 * page, so the assertions here are about distinctness as much as shape: no two
 * briefs share a palette, a display face, or a hero signature.
 */
final class PromptLibraryTest extends TestCase
{
    private const REQUIRED_HEADINGS = [
        '## Style guide',
        '## Design signature',
        '## Sections',
        '## Content facts',
    ];

    public function testTenFreeBriefsShip(): void
    {
        $briefs = bundled_briefs();

        self::assertCount(10, $briefs);
        foreach ($briefs as $brief) {
            self::assertFalse($brief['pro'], sprintf('%s must be free.', $brief['slug']));
        }
    }

    public function testEveryBriefIsCompleteAndDistinct(): void
    {
        $briefs = bundled_briefs();

        $slugs = [];
        $industries = [];
        $businesses = [];
        $accents = [];
        $display_faces = [];
        $signatures = [];

        foreach ($briefs as $brief) {
            $label = $brief['slug'];
            foreach (['industry', 'sector', 'business', 'title', 'description', 'signature'] as $key) {
                self::assertNotSame('', $brief[$key], sprintf('%s is missing %s.', $label, $key));
            }
            foreach (self::REQUIRED_HEADINGS as $heading) {
                self::assertStringContainsString($heading, $brief['body'], sprintf('%s lacks "%s".', $label, $heading));
            }
            // The brief names its own business in its H1, so the agent is never
            // building for a placeholder.
            self::assertStringContainsString('# ' . $brief['business'], $brief['body'], sprintf('%s H1 must name the business.', $label));
            // Every brief carries a working form or an explicit fallback for a
            // builder without one.
            self::assertStringContainsString('native form element', $brief['body'], $label);
            // Flat colour is a house rule, and every palette table declares it.
            self::assertStringContainsString('flat colors only, no gradients', $brief['body'], $label);
            // The builder line and the standards are added at compose time, never
            // baked into a file, so a rule change reaches every brief.
            self::assertStringNotContainsString(BUILDER_LINE_PREFIX, $brief['body'], $label);
            self::assertStringNotContainsString('## Standards', $brief['body'], $label);

            $slugs[] = $brief['slug'];
            $industries[] = strtolower($brief['industry']);
            $businesses[] = $brief['business'];
            $accents[] = self::accent_hex($brief['body'], $label);
            $display_faces[] = self::display_face($brief['body'], $label);
            $signatures[] = $brief['signature'];
        }

        foreach (compact('slugs', 'industries', 'businesses', 'accents', 'display_faces', 'signatures') as $name => $values) {
            self::assertSame(
                count($values),
                count(array_unique($values)),
                sprintf('Briefs must not share a %s: %s', rtrim($name, 's'), implode(', ', $values)),
            );
        }
    }

    public function testComposedBriefOpensWithTheBuilderAndClosesWithTheStandards(): void
    {
        $brief = bundled_briefs()[0];
        $text = compose($brief, 'elementor');

        self::assertStringStartsWith(BUILDER_LINE_PREFIX . 'Elementor' . "\n\n# ", $text);
        self::assertStringEndsWith(standards(), $text);
        self::assertStringContainsString('## Standards (non-negotiable)', $text);
        self::assertStringContainsString('WCAG 2.1 AA', $text);
        self::assertStringContainsString('Publish as a **draft**', $text);

        // An unknown builder slug is written through as given rather than dropped:
        // a filter may add one the label table does not know.
        self::assertSame(BUILDER_LINE_PREFIX . 'bricks', builder_line('bricks'));
    }

    public function testBuildersDefaultToWhatFreeCanDrive(): void
    {
        self::assertSame(['elementor', 'gutenberg'], array_keys(builders()));
    }

    public function testMalformedBriefsAreDroppedNotHalfRendered(): void
    {
        self::assertNull(normalize_brief('not an array'));
        self::assertNull(normalize_brief(['slug' => 'x', 'industry' => 'X']), 'no body');
        self::assertNull(normalize_brief(['slug' => 'x', 'body' => '# X']), 'no industry');

        $clean = normalize_brief([
            'slug' => 'Weird Slug!',
            'industry' => ' Florist ',
            'body' => "# Bloom\n\ntext",
            'pro' => 'true',
        ]);
        self::assertNotNull($clean);
        self::assertSame('weirdslug', $clean['slug']);
        self::assertSame('Florist', $clean['industry']);
        self::assertSame('Florist', $clean['title'], 'title falls back to the industry');
        self::assertTrue($clean['pro']);
    }

    public function testSectorsKeepFirstSeenOrderAndEveryBriefLandsInOne(): void
    {
        $briefs = briefs();
        $grouped = by_sector($briefs);

        self::assertSame(count($briefs), array_sum(array_map('count', $grouped)));
        self::assertSame($briefs[0]['sector'], array_key_first($grouped));
        self::assertGreaterThan(3, count($grouped), 'the free set should span several sectors');
    }

    /** The hex in the palette row whose role starts with "Accent". */
    private static function accent_hex(string $body, string $label): string
    {
        self::assertSame(1, preg_match('/^\| Accent[^|]*\| `(#[0-9A-Fa-f]{6})`/m', $body, $m), sprintf('%s has no accent row.', $label));

        return strtoupper($m[1]);
    }

    /** The bold face name after "Display =". */
    private static function display_face(string $body, string $label): string
    {
        self::assertSame(1, preg_match('/Display = \*\*([^*]+)\*\*/', $body, $m), sprintf('%s has no display face.', $label));

        return trim($m[1]);
    }
}
