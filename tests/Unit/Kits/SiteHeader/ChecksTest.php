<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\SiteHeader;

use PHPUnit\Framework\TestCase;
use WPPilot\Kits\SiteHeader;

/**
 * site-header: the caller designs the header; these are the pieces that fill it in (tokens) and
 * judge it (the checks before saving, the checks on the served page, contrast).
 */
final class ChecksTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/site-header/src/checks.php';
    }

    private const CONTEXT = ['woo' => true, 'languages' => 3, 'globals' => ['primary' => '#1f2937', 'pale' => '#e5e7eb'], 'page_background' => '#ffffff'];

    /** @return list<array<string, mixed>> */
    private static function good(): array
    {
        return [[
            'id' => 'a000001',
            'elType' => 'container',
            'settings' => ['flex_direction' => 'row', 'flex_wrap_mobile' => 'nowrap', 'background_background' => 'classic', 'background_color' => '#fbfaf7'],
            'elements' => [
                ['id' => 'a000002', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => ['image' => ['id' => 5, 'url' => 'https://example.test/logo.png'], 'link_to' => 'custom', 'link' => ['url' => '{{home_url}}'], '_element_width' => 'auto']],
                ['id' => 'a000003', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => '{{site_title}}', 'title_color' => '#13233f', '_element_width' => 'auto']],
                ['id' => 'a000004', 'elType' => 'widget', 'widgetType' => 'nav-menu', 'settings' => ['menu' => '{{menu}}', 'menu_name' => 'Main menu', 'dropdown' => 'tablet', 'color_menu_item' => '#13233f', '__globals__' => ['color_menu_item_hover' => 'globals/colors?id=primary'], '_element_width' => 'auto', '_flex_size' => 'grow']],
                ['id' => 'a000005', 'elType' => 'widget', 'widgetType' => 'nav-menu', 'settings' => ['menu' => '{{language_switcher}}', 'menu_name' => 'Language', 'dropdown' => 'none', '_element_width' => 'auto']],
                ['id' => 'a000006', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => ['text' => '{{label:cta}}', 'link' => ['url' => '/contact/'], 'background_color' => '#13233f', 'button_text_color' => '#ffffff', '_element_width' => 'auto']],
                ['id' => 'a000007', 'elType' => 'widget', 'widgetType' => 'woocommerce-menu-cart', 'settings' => ['icon' => 'bag-medium', '_element_width' => 'auto']],
            ],
        ]];
    }

    /** @return list<string> */
    private static function checks(array $findings, string $severity = 'error'): array
    {
        return array_values(array_map(static fn(array $f): string => $f['check'], array_filter($findings, static fn(array $f): bool => $f['severity'] === $severity)));
    }

    public function test_tokens_put_each_languages_pieces_into_the_design(): void
    {
        $unknown = [];
        $tree = SiteHeader\substitute(self::good(), [
            'menu' => 'main-ru',
            'language_switcher' => 'language-switcher-header',
            'home_url' => 'https://example.test/ru/',
            'site_title' => 'Hartwell & Mercer',
            'labels' => ['cta' => 'Записаться'],
        ], $unknown);

        $this->assertSame([], $unknown);
        $widgets = $tree[0]['elements'];
        $this->assertSame('https://example.test/ru/', $widgets[0]['settings']['link']['url']);
        $this->assertSame('Hartwell & Mercer', $widgets[1]['settings']['title'], 'Elementor settings take raw text');
        $this->assertSame('main-ru', $widgets[2]['settings']['menu']);
        $this->assertSame('language-switcher-header', $widgets[3]['settings']['menu']);
        $this->assertSame('Записаться', $widgets[4]['settings']['text']);
    }

    public function test_an_unknown_token_or_label_is_reported_not_left_in(): void
    {
        $unknown = [];
        $out = SiteHeader\substitute(['a' => '{{menu}} {{nope}} {{label:missing}}'], ['menu' => 'main'], $unknown);

        $this->assertSame('main  ', $out['a']);
        $this->assertSame(['{{nope}}', '{{label:missing}}'], $unknown);
    }

    public function test_block_markup_gets_a_numeric_navigation_ref_and_escaped_labels(): void
    {
        $unknown = [];
        $markup = '<!-- wp:navigation {"ref":"{{navigation_ref}}"} /--><a href="{{home_url}}">{{label:cta}}</a>';
        $out = SiteHeader\substitute($markup, ['navigation_ref' => 41, 'home_url' => 'https://example.test/', 'labels' => ['cta' => 'Tom & <Jerry>']], $unknown, true);

        $this->assertSame('<!-- wp:navigation {"ref":41} /--><a href="https://example.test/">Tom &amp; &lt;Jerry&gt;</a>', $out);
    }

    public function test_a_sound_design_has_no_errors(): void
    {
        $findings = SiteHeader\precheck_elementor(self::good(), self::CONTEXT);

        $this->assertSame([], self::checks($findings), (string) json_encode($findings));
        $this->assertSame([], self::checks($findings, 'warning'), (string) json_encode($findings));
    }

    public function test_the_problems_a_design_can_have_before_it_is_saved_are_named_by_element(): void
    {
        $tree = self::good();
        $w = &$tree[0]['elements'];
        $w[0]['settings']['image'] = ['id' => 0, 'url' => ''];
        $w[1]['settings']['title_color'] = '#bbbbbb';
        $w[2]['settings']['dropdown'] = 'none';
        $w[3] = ['id' => 'a000005', 'elType' => 'widget', 'widgetType' => 'wp-widget-polylang', 'settings' => []];
        $w[4]['settings']['button_text_color'] = '#ffffff';
        $w[4]['settings']['background_color'] = '#f5c542';
        unset($w);
        $findings = SiteHeader\precheck_elementor($tree, ['woo' => false] + self::CONTEXT);
        $errors = array_values(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'error'));
        $by = array_combine(array_map(static fn(array $f): string => $f['check'] . '@' . $f['element_id'], $errors), $errors);

        $this->assertArrayHasKey('broken_image@a000002', $by);
        $this->assertArrayHasKey('contrast@a000003', $by);
        $this->assertStringContainsString('on #fbfaf7 is 1.84:1', $by['contrast@a000003']['detail'], 'measured on the container behind it, not the page');
        $this->assertArrayHasKey('no_mobile_menu_button@a000004', $by);
        $this->assertArrayHasKey('raw_language_list@a000005', $by);
        $this->assertArrayHasKey('contrast@a000006', $by);
        $this->assertArrayHasKey('cart_without_shop@a000007', $by);
        $this->assertContains('no_language_switcher', self::checks($findings, 'warning'));
        foreach ($errors as $error) {
            $this->assertNotSame('', $error['fix'], 'every finding says what to change');
        }
    }

    public function test_kit_global_colours_are_resolved_and_large_text_needs_three_to_one(): void
    {
        $tree = self::good();
        $tree[0]['elements'][2]['settings']['__globals__'] = ['color_menu_item_hover' => 'globals/colors?id=pale'];
        $tree[0]['elements'][1]['settings'] += ['title_color' => '#888888', 'typography_font_size' => ['unit' => 'px', 'size' => 28]];
        $tree[0]['elements'][1]['settings']['title_color'] = '#777777';
        $findings = SiteHeader\precheck_elementor($tree, self::CONTEXT);
        $contrast = array_values(array_filter($findings, static fn(array $f): bool => $f['check'] === 'contrast'));

        $this->assertCount(1, $contrast, 'the pale global fails as hover text; #777 at 28px passes as large text');
        $this->assertSame('a000004', $contrast[0]['element_id']);
    }

    public function test_a_saved_switcher_is_known_by_its_menu_slug(): void
    {
        $tree = self::good();
        $tree[0]['elements'][3]['settings']['menu'] = 'language-switcher-header';
        $findings = SiteHeader\precheck_elementor($tree, self::CONTEXT + ['switcher_slug' => 'language-switcher-header']);

        $this->assertSame([], self::checks($findings), 'a switcher has no menu button, by design');
        $this->assertNotContains('no_language_switcher', self::checks($findings, 'warning'));
    }

    public function test_an_element_without_an_id_is_located_by_its_path(): void
    {
        $tree = self::good();
        unset($tree[0]['elements'][1]['id']);
        $tree[0]['elements'][1]['settings']['title_color'] = '#cccccc';
        $findings = SiteHeader\precheck_elementor($tree, self::CONTEXT);

        $this->assertSame('elements[0].elements[1] (heading)', $findings[0]['element_id']);
    }

    public function test_a_row_that_wraps_on_phones_and_zero_width_widgets_are_warned_about(): void
    {
        $tree = self::good();
        unset($tree[0]['settings']['flex_wrap_mobile'], $tree[0]['elements'][1]['settings']['_element_width']);
        $warnings = SiteHeader\precheck_elementor($tree, self::CONTEXT);

        $this->assertContains('row_wraps_on_phones', self::checks($warnings, 'warning'));
        $this->assertContains('zero_width_in_row', self::checks($warnings, 'warning'));
        $this->assertSame([], self::checks($warnings), 'warnings do not block the save');
    }

    public function test_without_unfiltered_html_raw_html_and_script_are_refused(): void
    {
        $tree = self::good();
        $tree[0]['settings']['custom_css'] = 'selector { color: red } </style><script>alert(1)</script>';
        $tree[0]['elements'][1]['settings']['_attributes'] = "data-x|1\nonclick|alert(1)";
        $tree[0]['elements'][] = ['id' => 'a000009', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => ['html' => '<script>alert(1)</script>', '_element_width' => 'auto']];

        $denied = SiteHeader\precheck_elementor($tree, self::CONTEXT + ['unfiltered_html' => false]);
        $this->assertSame(['a000001', 'a000003', 'a000009'], array_values(array_map(
            static fn(array $f): string => $f['element_id'],
            array_filter($denied, static fn(array $f): bool => $f['check'] === 'html_not_allowed'),
        )));
        // A user who may save unfiltered HTML (an administrator, by default) is not limited.
        $this->assertNotContains('html_not_allowed', self::checks(SiteHeader\precheck_elementor($tree, self::CONTEXT)));
    }

    public function test_markup_in_a_design_is_filtered_and_everything_else_left_alone(): void
    {
        $kses = static fn(string $html): string => (string) preg_replace('#<script\b[^>]*>.*?</script>|</?style\b[^>]*>#is', '', $html);
        $tree = [['settings' => ['title' => 'Hi <script>alert(1)</script><b>there</b>', 'link' => ['url' => '/a?b=1&c=2'], 'size' => 44, 'css' => 'a > b { }']]];

        $this->assertSame(
            [['settings' => ['title' => 'Hi <b>there</b>', 'link' => ['url' => '/a?b=1&c=2'], 'size' => 44, 'css' => 'a > b { }']]],
            SiteHeader\kses_design($tree, $kses),
        );
    }

    public function test_block_markup_checks(): void
    {
        $markup = '<!-- wp:navigation {"overlayMenu":"never"} /--><!-- wp:polylang/language-switcher /--><!-- wp:html --><style></style><!-- /wp:html -->';
        $blocks = [
            ['blockName' => 'core/navigation', 'attrs' => ['overlayMenu' => 'never'], 'innerBlocks' => [], 'innerHTML' => ''],
            ['blockName' => 'polylang/language-switcher', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => ''],
            ['blockName' => 'core/html', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<style></style>'],
        ];
        $findings = SiteHeader\precheck_blocks($blocks, $markup, ['woo' => true, 'languages' => 2, 'unfiltered_html' => false]);

        foreach (['no_mobile_menu_button', 'raw_language_list', 'html_stripped'] as $check) {
            $this->assertContains($check, self::checks($findings));
        }
        $this->assertContains('menu_not_set', self::checks($findings, 'warning'));
    }

    private static function served(string $header): string
    {
        return '<html><body><header data-elementor-type="header" data-elementor-id="77">' . $header . '</header><main>page</main></body></html>';
    }

    private const MENU = '<div class="elementor-widget-nav-menu" data-id="m1"><nav aria-label="Main menu" class="elementor-nav-menu--main"><ul class="elementor-nav-menu"><li><a href="/about/">About</a></li><li><a href="/shop/">Shop</a></li></ul></nav><div class="elementor-menu-toggle" aria-label="Menu Toggle"></div></div>';
    private const SWITCHER = '<div class="elementor-widget-nav-menu elementor-nav-menu--dropdown-none" data-id="s1"><nav aria-label="Language" class="elementor-nav-menu--main"><ul class="elementor-nav-menu"><li class="menu-item-has-children"><a href="#">English</a><ul class="sub-menu"><li class="lang-item"><a href="/lv/">Latviešu</a></li></ul></li></ul></nav></div>';
    private const CART = '<div class="elementor-widget-woocommerce-menu-cart" data-id="c1"><a class="elementor-menu-cart__toggle_button" href="#"><svg></svg><span class="elementor-screen-only">Cart</span></a></div>';
    private const LOGO = '<div data-id="l1"><a href="/"><img src="/logo.png" alt="Northwind"></a></div>';

    public function test_a_good_served_header_passes_and_lists_what_to_fetch(): void
    {
        $result = SiteHeader\check_served(self::served(self::LOGO . self::MENU . self::SWITCHER . self::CART), ['kind' => 'elementor', 'template_id' => 77, 'languages' => 2, 'cart' => true]);

        $this->assertTrue($result['passed'], (string) json_encode($result['findings']));
        $this->assertSame([], $result['findings']);
        $this->assertSame(['/logo.png' => 'l1'], $result['images']);
        $this->assertArrayHasKey('/about/', $result['links']);
    }

    public function test_served_problems_are_found_with_their_element(): void
    {
        $bare = '<div data-id="p1"><ul><li class="lang-item"><a href="/lv/">Latviešu</a></li></ul></div>';
        $no_button = '<div class="elementor-widget-nav-menu elementor-nav-menu--dropdown-none" data-id="m2"><nav aria-label="Menu" class="elementor-nav-menu--main"><ul class="elementor-nav-menu"><li><a href="/a/">A</a></li><li><a href="/b/">B</a></li></ul></nav></div>';
        $twin = '<div class="elementor-widget-nav-menu" data-id="m3"><nav aria-label="Menu" class="elementor-nav-menu--main"><ul class="elementor-nav-menu"><li><a href="/c/">C</a></li></ul></nav><div class="elementor-menu-toggle" aria-label="Menu Toggle"></div></div>';
        $empty_cart = '<div class="elementor-widget-woocommerce-menu-cart" data-id="c2"><a class="elementor-menu-cart__toggle_button" href="#"></a></div>';
        $result = SiteHeader\check_served(self::served($bare . $no_button . $twin . $empty_cart . '<div data-id="x1"><img src=""></div>'), ['kind' => 'elementor', 'template_id' => 77, 'languages' => 2, 'cart' => true]);
        $found = array_map(static fn(array $f): string => $f['check'] . '@' . $f['element_id'], $result['findings']);

        $this->assertFalse($result['passed']);
        foreach (['raw_language_list@p1', 'no_mobile_menu_button@m2', 'accessible_names@m2', 'accessible_names@m3', 'empty_cart@c2', 'broken_image@x1'] as $expected) {
            $this->assertContains($expected, $found);
        }
    }

    public function test_another_header_winning_is_reported(): void
    {
        $result = SiteHeader\check_served(self::served(self::MENU), ['kind' => 'elementor', 'template_id' => 78, 'languages' => 1, 'cart' => false]);

        $this->assertFalse($result['passed']);
        $this->assertSame('header_served', $result['findings'][0]['check']);
    }

    public function test_a_served_block_header(): void
    {
        $html = '<html><body><header class="wp-block-template-part"><nav aria-label="Header navigation" class="wp-block-navigation"><button class="wp-block-navigation__responsive-container-open" aria-label="Open menu"></button><ul class="wp-block-navigation__container"><li><a href="/a/">A</a></li><li><a href="/b/">B</a></li><li><a href="/c/">C</a></li><li class="lang-item"><a href="/lv/">LV</a></li></ul></nav><div class="wc-block-mini-cart"></div></header></body></html>';
        $ok = SiteHeader\check_served($html, ['kind' => 'block-theme', 'languages' => 2, 'cart' => true]);
        $this->assertTrue($ok['passed'], (string) json_encode($ok['findings']));

        $broken = str_replace('<button class="wp-block-navigation__responsive-container-open" aria-label="Open menu"></button>', '', $html);
        $this->assertContains('no_mobile_menu_button', array_column(SiteHeader\check_served($broken, ['kind' => 'block-theme', 'languages' => 2, 'cart' => true])['findings'], 'check'));
    }

    public function test_contrast_and_colour_parsing(): void
    {
        $this->assertSame(21.0, SiteHeader\contrast('#000000', '#ffffff'));
        $this->assertSame(['#112233', 1.0], SiteHeader\parse_color('#123'));
        $this->assertSame(['#ff0000', 0.5], SiteHeader\parse_color('rgba(255, 0, 0, .5)'));
        $this->assertSame(['#000000', 0.0], SiteHeader\parse_color('transparent'));
        $this->assertNull(SiteHeader\parse_color('var(--e-global-color-primary)'));
        $this->assertSame('#808080', SiteHeader\over(['#000000', 0.5], '#ffffff'));
        $this->assertTrue(SiteHeader\is_dark('#13233f'));
    }

    public function test_a_logos_brand_colours(): void
    {
        $this->assertSame(['accent' => '#e9a23b', 'text' => '#211b12'], SiteHeader\logo_colors(['#e9a23b' => 2, '#211b12' => 1, '#ffffff' => 5]));
        $this->assertSame([], SiteHeader\logo_colors(['#ffffff' => 3, '#cccccc' => 1]));
    }
}
