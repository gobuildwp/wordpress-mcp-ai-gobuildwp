<?php

declare(strict_types=1);

/**
 * Real WordPress memory API + the actual early plugin bootstrap, without a DB.
 * php -d memory_limit=128M tests/runtime-memory.php <wordpress-root> 256M 256M allocate
 * Run each case in a new PHP process; WP_MAX_MEMORY_LIMIT is immutable.
 */
$root = rtrim($argv[1] ?? '', '/\\') . '/';
if (!is_file($root . 'wp-includes/functions.php') || !isset($argv[2], $argv[3])) {
    fwrite(STDERR, "Pass a WordPress root, configured maximum and expected limit.\n");
    exit(1);
}
define('ABSPATH', $root);
define('WPINC', 'wp-includes');
define('WP_MAX_MEMORY_LIMIT', $argv[2]);
require $root . 'wp-includes/plugin.php';
require $root . 'wp-includes/load.php';
require $root . 'wp-includes/functions.php';
require $root . 'wp-includes/default-constants.php';
wp_initial_constants();

// Run the real entrypoint until dependency loading starts. No admin context:
// this is the same bootstrap reached by a logged-out health-check request.
$source = file_get_contents(dirname(__DIR__) . '/wppilot.php');
$boundary = strpos($source, "require_once __DIR__ . '/includes/compatibility.php';");
if ($boundary === false) {
    throw new RuntimeException('Plugin bootstrap boundary changed.');
}
eval(substr($source, strlen('<?php'), $boundary - strlen('<?php')));
$effective = (string) ini_get('memory_limit');
if (wp_convert_hr_to_bytes($effective) !== wp_convert_hr_to_bytes($argv[3])) {
    throw new RuntimeException("Expected {$argv[3]}, got {$effective}");
}
if (($argv[4] ?? '') === 'allocate') {
    // Exceeds the old 128M limit: a helper call alone would not prove headroom.
    $page = str_repeat('x', 140 * 1024 * 1024);
    if (strlen($page) !== 140 * 1024 * 1024) {
        throw new RuntimeException('Allocation failed.');
    }
}
echo json_encode(['memory_limit' => $effective, 'peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR) . "\n";
