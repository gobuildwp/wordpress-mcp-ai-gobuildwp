<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Whose copy of the MCP Adapter is loaded decides whether GoBuildWP may rename the
 * adapter's default server. Get it wrong in the permissive direction and GoBuildWP
 * takes a route another plugin owns; get it wrong in the strict direction and
 * `/wp-json/mcp/wppilot` never exists. Both failures are silent on the site that
 * has them, so the classification is worth testing directly.
 */
final class AdapterOriginTest extends TestCase
{
    private string $temp = '';

    protected function tearDown(): void
    {
        if ($this->temp !== '' && is_dir($this->temp)) {
            foreach (array_reverse((array) glob($this->temp . '/**/*', GLOB_BRACE)) as $path) {
                if (is_string($path) && is_file($path)) {
                    unlink($path);
                }
            }
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------ owner plugin

    public function test_names_the_plugin_directory_that_owns_the_file(): void
    {
        $file = '/var/www/html/wp-content/plugins/elementor/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php';

        self::assertSame('elementor', wppilot_mcp_adapter_owner_plugin($file));
    }

    public function test_recognises_a_must_use_plugin(): void
    {
        $file = '/srv/site/wp-content/mu-plugins/company-mcp/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php';

        self::assertSame('company-mcp', wppilot_mcp_adapter_owner_plugin($file));
    }

    /**
     * wp-content is movable, and the constants are absent outside a WordPress
     * request. Naming the plugin is the entire value of the field, so the path's
     * own shape is used rather than reporting nothing.
     */
    public function test_falls_back_to_the_path_shape_when_the_content_dir_moved(): void
    {
        $file = '/srv/app/public/assets/plugins/some-mcp-plugin/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php';

        self::assertSame('some-mcp-plugin', wppilot_mcp_adapter_owner_plugin($file));
    }

    public function test_reports_no_owner_for_a_path_outside_any_plugin(): void
    {
        self::assertNull(wppilot_mcp_adapter_owner_plugin('/usr/local/lib/php/McpAdapter.php'));
    }

    // ------------------------------------------------------------ version

    /**
     * The adapter carries no version constant. The Jetpack classmap is what the
     * arbitration itself read to pick this copy, so it is the honest source.
     */
    public function test_reads_the_version_from_the_owning_installs_classmap(): void
    {
        $root = $this->plugin_tree(adapter_version: '1.0.16');

        $version = wppilot_mcp_adapter_version(
            $root . '/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php',
        );

        self::assertSame('1.0.16', $version);
    }

    public function test_reports_no_version_when_the_classmap_is_missing(): void
    {
        $root = $this->plugin_tree(adapter_version: null);

        $version = wppilot_mcp_adapter_version(
            $root . '/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php',
        );

        self::assertNull($version);
    }

    public function test_reports_no_version_for_a_file_outside_a_vendor_tree(): void
    {
        self::assertNull(wppilot_mcp_adapter_version('/opt/mcp/McpAdapter.php'));
    }

    // ------------------------------------------------------------ summary

    public function test_the_summary_says_the_adapter_is_not_loaded(): void
    {
        // The suite never loads a real adapter class, so this is the honest state
        // and the one a site with a broken vendor directory reports.
        self::assertStringContainsString('not loaded', wppilot_mcp_adapter_origin_summary());
    }

    public function test_origin_is_resolved_once_and_reports_the_documented_keys(): void
    {
        $origin = wppilot_mcp_adapter_origin();

        self::assertSame(
            ['loaded', 'ours', 'file', 'version', 'owner'],
            array_keys($origin),
        );
        self::assertFalse($origin['loaded']);
        self::assertFalse($origin['ours']);
        self::assertSame($origin, wppilot_mcp_adapter_origin());
    }

    public function test_wppilot_does_not_claim_the_default_server_without_its_own_adapter(): void
    {
        self::assertFalse(wppilot_owns_mcp_adapter());
    }

    /**
     * A plugin directory holding an adapter and, optionally, the Jetpack classmap
     * that names its version.
     */
    private function plugin_tree(?string $adapter_version): string
    {
        $this->temp = sys_get_temp_dir() . '/wppilot-adapter-origin-' . bin2hex(random_bytes(4));
        $adapter_dir = $this->temp . '/vendor/wordpress/mcp-adapter/includes/Core';
        mkdir($adapter_dir, 0o777, true);
        file_put_contents($adapter_dir . '/McpAdapter.php', "<?php\n");

        if ($adapter_version !== null) {
            $composer_dir = $this->temp . '/vendor/composer';
            mkdir($composer_dir, 0o777, true);
            file_put_contents(
                $composer_dir . '/jetpack_autoload_classmap.php',
                sprintf(
                    "<?php\nreturn [%s => ['version' => %s, 'path' => 'x']];\n",
                    var_export(WPPILOT_MCP_ADAPTER_CLASS, true),
                    var_export($adapter_version, true),
                ),
            );
        }

        return wp_normalize_path($this->temp);
    }
}
