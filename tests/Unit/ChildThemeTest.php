<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * wppilot/create-child-theme: the files it writes, the refusal of an existing folder, and what
 * the ledger undo treats as "changed since".
 */
final class ChildThemeTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/child-theme.php';
        $this->root = sys_get_temp_dir() . '/wppilot-child-theme-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        \wppilot_child_theme_delete_tree($this->root);
    }

    /** @return array{'style.css': string, 'functions.php': string} */
    private static function files(string $name = 'Parent Child'): array
    {
        return \wppilot_child_theme_files([
            'slug' => 'parent-child',
            'name' => $name,
            'parent' => 'parent',
            'parent_name' => 'Parent',
            'description' => '',
        ]);
    }

    public function test_style_css_names_the_parent_and_cannot_be_broken_out_of(): void
    {
        $style = self::files("Evil */ <?php\nTemplate: other")['style.css'];

        self::assertStringContainsString("\nTemplate: parent\n", $style);
        self::assertStringContainsString('Theme Name: Evil  <?php Template: other', $style);
        self::assertSame(2, substr_count($style, '*/'), 'only the header and the hint close a comment');
        self::assertStringContainsString('Description: Child theme of Parent.', $style);
    }

    public function test_functions_php_loads_the_parent_stylesheet_before_the_child(): void
    {
        $functions = self::files()['functions.php'];

        self::assertStringStartsWith('<?php', $functions);
        self::assertStringContainsString("get_template_directory_uri() . '/style.css'", $functions);
        self::assertStringContainsString("wp_enqueue_style('parent-child-parent'", $functions);
        self::assertStringContainsString("defined('ABSPATH')", $functions);
        $lint = sys_get_temp_dir() . '/wppilot-child-functions-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($lint, $functions);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($lint) . ' 2>&1', $output, $code);
        unlink($lint);
        self::assertSame(0, $code, implode("\n", $output));
    }

    public function test_an_existing_folder_is_refused_and_nothing_is_written(): void
    {
        mkdir($this->root . '/parent-child');

        $result = \wppilot_child_theme_create($this->root, 'parent-child', self::files());

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_child_theme_exists', $result->get_error_code());
        self::assertSame(['.', '..'], scandir($this->root . '/parent-child'));
    }

    public function test_path_segments_in_the_slug_are_refused(): void
    {
        $result = \wppilot_child_theme_create($this->root, '../outside', self::files());

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertFalse(file_exists(dirname($this->root) . '/outside'));
    }

    public function test_created_files_are_hashed_and_later_edits_are_reported(): void
    {
        mkdir($this->root . '/parent');
        file_put_contents($this->root . '/parent/screenshot.png', 'png');
        $screenshot = \wppilot_child_theme_screenshot($this->root . '/parent');

        $created = \wppilot_child_theme_create($this->root, 'parent-child', self::files(), $screenshot);

        self::assertIsArray($created);
        self::assertSame(['style.css', 'functions.php', 'screenshot.png'], array_keys($created['files']));
        self::assertSame([], \wppilot_child_theme_changed_files($created['dir'], $created['files']));

        file_put_contents($created['dir'] . '/style.css', '/* edited */');
        file_put_contents($created['dir'] . '/header.php', '<?php');
        $changed = \wppilot_child_theme_changed_files($created['dir'], $created['files']);
        sort($changed);
        self::assertSame(['header.php (added)', 'style.css (edited)'], $changed);
    }

    public function test_the_undo_is_a_registered_strategy_and_the_ability_is_not_destructive(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/abilities/wordpress/child-theme.php');

        self::assertContains('wppilot/create-child-theme', WPPILOT_TEST_BOOT_ABILITIES);
        self::assertStringContainsString("wordpress_core_mcp_meta(readonly: false, destructive: false, idempotent: false)", $source);
        self::assertStringContainsString("'confirm' => ['type' => 'boolean'", $source, 'confirm must be declared or the gate strips it');
        self::assertStringContainsString('WPPILOT_CHILD_THEME_TYPE', $source);
        self::assertNotNull(\wppilot_get_rollback_strategy(WPPILOT_CHILD_THEME_TYPE));
    }
}
