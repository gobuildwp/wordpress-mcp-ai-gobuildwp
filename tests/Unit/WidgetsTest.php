<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot_Test_State;

use function WPPilot\Abilities\WordPress\wordpress_add_widget;
use function WPPilot\Abilities\WordPress\wordpress_list_widgets;
use function WPPilot\Abilities\WordPress\wordpress_remove_widget;
use function WPPilot\Abilities\WordPress\wordpress_update_widget;

/**
 * Classic widget abilities: what they write to sidebars_widgets and widget_{id_base}, that
 * settings go through the widget's own update(), and that the ledger undo restores only the
 * parts a change touched.
 */
final class WidgetsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    /** @var list<string> */
    private array $savedCaps = [];

    private mixed $savedFactory = null;

    private mixed $savedSidebars = null;

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedCaps = WPPilot_Test_State::$capabilities;
        $this->savedFactory = $GLOBALS['wp_widget_factory'] ?? null;
        $this->savedSidebars = $GLOBALS['wp_registered_sidebars'] ?? null;
        unset(WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION]);
        WPPilot_Test_State::$capabilities = ['edit_theme_options'];

        $GLOBALS['wp_widget_factory'] = (object) ['widgets' => [
            'WP_Widget_Block' => new FakeWidget('block', 'Block'),
            'WP_Widget_Text' => new FakeWidget('text', 'Text'),
            'Refusing' => new FakeWidget('refusing', 'Refusing'),
        ]];
        $GLOBALS['wp_registered_sidebars'] = [
            'sidebar-1' => ['id' => 'sidebar-1', 'name' => 'Blog Sidebar', 'description' => ''],
            'footer-1' => ['id' => 'footer-1', 'name' => 'Footer', 'description' => ''],
        ];
        WPPilot_Test_State::$options['sidebars_widgets'] = [
            'wp_inactive_widgets' => [],
            'sidebar-1' => ['text-2', 'block-2'],
            'footer-1' => ['block-3'],
            'array_version' => 3,
        ];
        WPPilot_Test_State::$options['widget_text'] = [2 => ['title' => 'About', 'text' => 'Hi', 'filter' => true], '_multiwidget' => 1];
        WPPilot_Test_State::$options['widget_block'] = [
            2 => ['content' => '<!-- wp:search /-->'],
            3 => ['content' => '<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph -->'],
            '_multiwidget' => 1,
        ];
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        WPPilot_Test_State::$capabilities = $this->savedCaps;
        $GLOBALS['wp_widget_factory'] = $this->savedFactory;
        $GLOBALS['wp_registered_sidebars'] = $this->savedSidebars;
    }

    public function test_the_four_abilities_are_registered(): void
    {
        foreach (['list-widgets', 'add-widget', 'update-widget', 'remove-widget'] as $name) {
            self::assertContains('wppilot/' . $name, WPPILOT_TEST_BOOT_ABILITIES);
        }
    }

    public function test_list_shows_each_area_in_order_with_block_and_legacy_widgets(): void
    {
        $list = wordpress_list_widgets([]);

        self::assertIsArray($list);
        self::assertSame(['sidebar-1', 'footer-1'], array_column($list['sidebars'], 'sidebar_id'));
        self::assertSame(['text-2', 'block-2'], array_column($list['sidebars'][0]['widgets'], 'widget_id'));
        self::assertSame('legacy', $list['sidebars'][0]['widgets'][0]['kind']);
        self::assertSame('About', $list['sidebars'][0]['widgets'][0]['title']);
        self::assertSame('<!-- wp:search /-->', $list['sidebars'][0]['widgets'][1]['content']);
        self::assertContains('text', array_column($list['widget_types'], 'id_base'));

        $one = wordpress_list_widgets(['widget_id' => 'block-3']);
        self::assertIsArray($one);
        self::assertSame('footer-1', $one['sidebar_id']);
    }

    public function test_add_a_block_widget_at_a_position_runs_the_widget_update(): void
    {
        $result = wordpress_add_widget([
            'sidebar_id' => 'sidebar-1',
            'content' => '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph --><script>x</script>',
            'position' => 0,
        ]);

        self::assertIsArray($result);
        self::assertSame('block-4', $result['widget_id']);
        self::assertSame(0, $result['position']);
        self::assertSame(['block-4', 'text-2', 'block-2'], WPPilot_Test_State::$options['sidebars_widgets']['sidebar-1']);
        self::assertStringNotContainsString('<script>', WPPilot_Test_State::$options['widget_block'][4]['content'], 'update() sanitised it');
        self::assertSame(1, WPPilot_Test_State::$options['widget_block']['_multiwidget']);
        self::assertSame(3, WPPilot_Test_State::$options['sidebars_widgets']['array_version']);
        self::assertNotEmpty($result['change_id']);
    }

    public function test_add_refuses_an_unknown_area_type_or_mixed_input(): void
    {
        $area = wordpress_add_widget(['sidebar_id' => 'nope', 'content' => 'x']);
        $type = wordpress_add_widget(['sidebar_id' => 'sidebar-1', 'id_base' => 'calendar']);
        $mixed = wordpress_add_widget(['sidebar_id' => 'sidebar-1', 'id_base' => 'text', 'content' => 'x']);
        $legacy = wordpress_add_widget(['sidebar_id' => 'sidebar-1', 'id_base' => 'refusing', 'settings' => ['a' => 1]]);

        self::assertInstanceOf(WP_Error::class, $area);
        self::assertSame('wppilot_widget_sidebar_unknown', $area->get_error_code());
        self::assertStringContainsString('sidebar-1, footer-1', $area->get_error_message());
        self::assertInstanceOf(WP_Error::class, $type);
        self::assertSame('wppilot_widget_type_unknown', $type->get_error_code());
        self::assertInstanceOf(WP_Error::class, $mixed);
        self::assertInstanceOf(WP_Error::class, $legacy);
        self::assertSame('wppilot_widget_rejected', $legacy->get_error_code());
        self::assertSame(['text-2', 'block-2'], WPPilot_Test_State::$options['sidebars_widgets']['sidebar-1'], 'nothing was written');
    }

    public function test_update_merges_legacy_settings_and_moves_between_areas(): void
    {
        $result = wordpress_update_widget(['widget_id' => 'text-2', 'settings' => ['title' => 'About us'], 'sidebar_id' => 'footer-1', 'position' => 0]);

        self::assertIsArray($result);
        self::assertSame(['title' => 'About us', 'text' => 'Hi', 'filter' => true], WPPilot_Test_State::$options['widget_text'][2]);
        self::assertSame(['block-2'], WPPilot_Test_State::$options['sidebars_widgets']['sidebar-1']);
        self::assertSame(['text-2', 'block-3'], WPPilot_Test_State::$options['sidebars_widgets']['footer-1']);

        $wrong = wordpress_update_widget(['widget_id' => 'text-2', 'content' => 'x']);
        self::assertInstanceOf(WP_Error::class, $wrong);
        self::assertSame('wppilot_widget_bad_input', $wrong->get_error_code());
    }

    public function test_remove_moves_to_inactive_and_delete_needs_confirm(): void
    {
        $moved = wordpress_remove_widget(['widget_id' => 'block-2']);
        self::assertIsArray($moved);
        self::assertSame('deactivated', $moved['result']);
        self::assertSame(['block-2'], WPPilot_Test_State::$options['sidebars_widgets']['wp_inactive_widgets']);
        self::assertArrayHasKey(2, WPPilot_Test_State::$options['widget_block']);

        $unconfirmed = wordpress_remove_widget(['widget_id' => 'block-3', 'delete' => true]);
        self::assertInstanceOf(WP_Error::class, $unconfirmed);
        self::assertSame('wppilot_confirmation_required', $unconfirmed->get_error_code());
        self::assertStringContainsString('Deletes the widget "block-3" from "footer-1"', $unconfirmed->get_error_message());

        $deleted = wordpress_remove_widget(['widget_id' => 'block-3', 'delete' => true, 'confirm' => true]);
        self::assertIsArray($deleted);
        self::assertSame([], WPPilot_Test_State::$options['sidebars_widgets']['footer-1']);
        self::assertArrayNotHasKey(3, WPPilot_Test_State::$options['widget_block']);
    }

    public function test_undo_restores_only_what_the_change_touched(): void
    {
        $deleted = wordpress_remove_widget(['widget_id' => 'block-3', 'delete' => true, 'confirm' => true]);
        self::assertIsArray($deleted);
        // An unrelated edit afterwards, in another area.
        $other = wordpress_update_widget(['widget_id' => 'text-2', 'settings' => ['title' => 'Later']]);
        self::assertIsArray($other);

        $undo = \wppilot_rollback_change($deleted['change_id']);

        self::assertIsArray($undo, $undo instanceof WP_Error ? $undo->get_error_message() : '');
        self::assertSame(['block-3'], WPPilot_Test_State::$options['sidebars_widgets']['footer-1']);
        self::assertSame('<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph -->', WPPilot_Test_State::$options['widget_block'][3]['content']);
        self::assertSame('Later', WPPilot_Test_State::$options['widget_text'][2]['title'], 'the later edit stays');
    }

    public function test_undo_is_refused_when_the_widget_changed_since(): void
    {
        $first = wordpress_update_widget(['widget_id' => 'block-2', 'content' => '<!-- wp:archives /-->']);
        self::assertIsArray($first);
        $second = wordpress_update_widget(['widget_id' => 'block-2', 'content' => '<!-- wp:calendar /-->']);
        self::assertIsArray($second);

        $refused = \wppilot_rollback_change($first['change_id']);
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('wppilot_change_changed_since', $refused->get_error_code());
        self::assertSame('<!-- wp:calendar /-->', WPPilot_Test_State::$options['widget_block'][2]['content']);

        $forced = \wppilot_rollback_change($first['change_id'], force: true);
        self::assertIsArray($forced);
        self::assertSame('<!-- wp:search /-->', WPPilot_Test_State::$options['widget_block'][2]['content']);
    }

    public function test_writes_need_edit_theme_options(): void
    {
        WPPilot_Test_State::$capabilities = [];

        $result = wordpress_add_widget(['sidebar_id' => 'sidebar-1', 'content' => 'x']);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_widgets_forbidden', $result->get_error_code());
    }
}

/** A widget type whose update() behaves like core's: block strips script, "refusing" returns false. */
final class FakeWidget
{
    public function __construct(public string $id_base, public string $name)
    {
    }

    /**
     * @param array<string, mixed> $new
     * @param array<string, mixed> $old
     * @return array<string, mixed>|false
     */
    public function update(array $new, array $old): array|false
    {
        if ($this->id_base === 'refusing') {
            return false;
        }
        if ($this->id_base === 'block') {
            $new['content'] = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', (string) $new['content']);
        }
        return $new;
    }
}
