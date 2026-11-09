<?php
declare(strict_types=1);

// Uses real WordPress filter dispatch to reproduce its priority-10 deactivation.
// php tests/self-update-activation.php <wordpress-root>
$root = rtrim($argv[1] ?? '', '/\\');
if (!is_file($root . '/wp-includes/plugin.php')) { throw new RuntimeException('Pass a WordPress root.'); }
require $root . '/wp-includes/plugin.php';
$GLOBALS['wp_plugin_paths'] = [];
define('ABSPATH', '/test/');
define('WP_PLUGIN_DIR', '/test/plugins');
define('WPMU_PLUGIN_DIR', '/test/mu-plugins');
define('WPPILOT_PLUGIN_FILE', '/test/plugins/wppilot/wppilot.php');
define('WPPILOT_PRO_FILE', '/test/plugins/wppilot-pro/wppilot-pro.php');
function register_core_ability(...$args): void {}
function wordpress_core_mcp_meta(...$args): array { return []; }
function __(string $text, string $domain = ''): string { return $text; }
function wp_normalize_path(string $file): string { return str_replace('\\', '/', $file); }
class WP_Error { public function __construct(...$args) {} }
class Plugin_Upgrader {
    public bool $active = true;
    public function deactivate_plugin_before_upgrade($response) { $this->active = false; return $response; }
}
require dirname(__DIR__) . '/includes/abilities/wordpress/extensions.php';
foreach (['wppilot/wppilot.php' => true, 'wppilot-pro/wppilot-pro.php' => true, 'other/plugin.php' => false] as $file => $expected) {
    $upgrader = new Plugin_Upgrader();
    $guard = \WPPilot\Abilities\WordPress\wordpress_keep_self_update_active($upgrader, $file);
    add_filter('upgrader_pre_install', [$upgrader, 'deactivate_plugin_before_upgrade'], 10, 2);
    add_filter('upgrader_pre_install', $guard, 9, 2);
    apply_filters('upgrader_pre_install', true, ['plugin' => $file]);
    if ($upgrader->active !== $expected) { throw new RuntimeException('Wrong activation state: ' . $file); }
    remove_filter('upgrader_pre_install', $guard, 9);
    remove_filter('upgrader_pre_install', [$upgrader, 'deactivate_plugin_before_upgrade'], 10);
}
echo "PASS: self updates keep Free and Pro active; third-party updates keep core's behavior.\n";
