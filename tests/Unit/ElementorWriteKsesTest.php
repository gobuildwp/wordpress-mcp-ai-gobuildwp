<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPPilot_Test_State;

use function WPPilot\Elementor\el_kses_elements_for_current_user;

// The Elementor module is not part of the suite bootstrap (see
// ElementorAtomicAuditTest). page-io only declares functions.
require_once dirname(__DIR__, levels: 2) . '/includes/elementor/helpers/wppilot-page-io.php';

/**
 * GoBuildWP must not let a user store more than Elementor's own editor would.
 * For a user without unfiltered_html, Elementor runs every string in the
 * document through wp_kses_post before it is saved; the raw write path, which
 * skips Document::save(), has to do the same or an HTML widget written through
 * it keeps its <script>.
 */
final class ElementorWriteKsesTest extends TestCase
{
    /** @var list<string> */
    private array $filtered = [];

    protected function setUp(): void
    {
        WPPilot_Test_State::reset();
        $this->filtered = [];
    }

    /** Stand-in for wp_kses_post, which the doubles do not provide: strips script elements and on* attributes. */
    private function kses(): callable
    {
        return function (string $value): string {
            $this->filtered[] = $value;
            $value = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', replacement: '', subject: $value);
            return (string) preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', replacement: '', subject: $value);
        };
    }

    /** @return list<array<string, mixed>> */
    private function tree(): array
    {
        return [[
            'id' => 'aaaaaaa',
            'elType' => 'e-flexbox',
            'isInner' => false,
            'settings' => [],
            'elements' => [
                ['id' => 'bbbbbbb', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [
                    'html' => '<script>steal()</script><b>kept</b>',
                ], 'elements' => []],
                ['id' => 'ccccccc', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [
                    'title' => 'Hi<img src=x onerror="steal()">',
                    'custom_css' => 'selector{color:red}</style><script>steal()</script>',
                    'link' => ['url' => 'https://example.test/', 'is_external' => '', 'nofollow' => ''],
                    'typography_font_size' => ['unit' => 'px', 'size' => 24],
                ], 'elements' => [
                    ['id' => 'ddddddd', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [
                        'title' => ['$$type' => 'html-v3', 'value' => '<script>steal()</script>Atomic'],
                    ], 'elements' => []],
                ]],
            ],
        ]];
    }

    public function test_a_user_with_unfiltered_html_writes_the_tree_as_given(): void
    {
        WPPilot_Test_State::$capabilities = ['unfiltered_html'];

        $tree = $this->tree();

        self::assertSame($tree, el_kses_elements_for_current_user($tree, $this->kses()));
        self::assertSame([], $this->filtered, 'kses must not run at all for a user Elementor does not filter either.');
    }

    public function test_a_user_without_unfiltered_html_has_every_string_filtered_at_every_depth(): void
    {
        WPPilot_Test_State::$capabilities = ['manage_options', 'edit_posts'];

        $out = el_kses_elements_for_current_user($this->tree(), $this->kses());

        $html = $out[0]['elements'][0]['settings']['html'];
        $heading = $out[0]['elements'][1]['settings'];
        $atomic = $out[0]['elements'][1]['elements'][0]['settings']['title'];

        self::assertSame('<b>kept</b>', $html);
        self::assertSame('Hi<img src=x>', $heading['title']);
        self::assertStringNotContainsString('<script>', $heading['custom_css']);
        self::assertSame('Atomic', $atomic['value']);
        self::assertSame('html-v3', $atomic['$$type']);

        $encoded = (string) json_encode($out);
        self::assertStringNotContainsString('<script', $encoded);
        self::assertStringNotContainsString('onerror', $encoded);
    }

    public function test_structure_keys_and_non_strings_are_left_alone(): void
    {
        WPPilot_Test_State::$capabilities = [];

        $out = el_kses_elements_for_current_user($this->tree(), $this->kses());

        self::assertSame(['id', 'elType', 'isInner', 'settings', 'elements'], array_keys($out[0]));
        self::assertFalse($out[0]['isInner']);
        self::assertSame(['unit' => 'px', 'size' => 24], $out[0]['elements'][1]['settings']['typography_font_size']);
        self::assertSame('https://example.test/', $out[0]['elements'][1]['settings']['link']['url']);
        self::assertSame('e-heading', $out[0]['elements'][1]['elements'][0]['widgetType']);
        // Ids, types and every other string went through the filter too, as in Elementor's kses_post_deep.
        self::assertContains('aaaaaaa', $this->filtered);
        self::assertContains('html', $this->filtered);
    }
}
