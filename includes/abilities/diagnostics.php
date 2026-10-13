<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/system-status', [
    'label' => __('System Status', domain: 'wppilot'),
    'description' => __(
        'Return a concise WordPress, PHP, database, cron, cache, and GoBuildWP runtime status report.',
        domain: 'wppilot',
    ),
    'input_schema' => WPPILOT_NO_INPUT_SCHEMA,
    'category' => 'diagnostics',
    'execute_callback' => 'wppilot_system_status',
    'permission_callback' => static fn(): bool => current_user_can('manage_options'),
    'meta' => wppilot_diagnostic_meta(),
]);

wp_register_ability('wppilot/performance-audit', [
    'label' => __('Performance Audit', domain: 'wppilot'),
    'description' => __(
        'Run bounded server-side performance checks without synthetic speed claims.',
        domain: 'wppilot',
    ),
    'input_schema' => WPPILOT_NO_INPUT_SCHEMA,
    'category' => 'diagnostics',
    'execute_callback' => 'wppilot_performance_audit',
    'permission_callback' => static fn(): bool => current_user_can('manage_options'),
    'meta' => wppilot_diagnostic_meta(),
]);

wp_register_ability('wppilot/security-audit', [
    'label' => __('Security Configuration Audit', domain: 'wppilot'),
    'description' => __(
        'Inspect bounded WordPress hardening signals and outstanding updates; this is not a malware scan.',
        domain: 'wppilot',
    ),
    'input_schema' => WPPILOT_NO_INPUT_SCHEMA,
    'category' => 'diagnostics',
    'execute_callback' => 'wppilot_security_audit',
    'permission_callback' => static fn(): bool => current_user_can('manage_options'),
    'meta' => wppilot_diagnostic_meta(),
]);

/** @return array<string, mixed> */
function wppilot_diagnostic_meta(): array
{
    return [
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        'mcp' => ['public' => true],
    ];
}

/** @return array<string, mixed> */
function wppilot_system_status(): array
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var wpdb $wpdb */
    $cron = function_exists('_get_cron_array') ? _get_cron_array() : [];
    $now = time();
    $overdue = 0;
    foreach (array_keys($cron) as $timestamp) {
        if ((int) $timestamp < ($now - 300)) {
            ++$overdue;
        }
    }

    return [
        'generated_at' => gmdate('c'),
        'site' => [
            'url' => home_url('/'),
            'environment_type' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown',
            'multisite' => is_multisite(),
            'https' => is_ssl() || str_starts_with(home_url('/'), 'https://'),
        ],
        'runtime' => [
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION,
            'database_server' => method_exists($wpdb, 'db_server_info') ? $wpdb->db_server_info() : $wpdb->db_version(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
        ],
        'cache' => [
            'persistent_object_cache' => wp_using_ext_object_cache(),
            'page_cache_constant' => defined('WP_CACHE') && WP_CACHE === true,
        ],
        'cron' => [
            'disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON === true,
            'scheduled_timestamps' => count($cron),
            'overdue_timestamps' => $overdue,
        ],
        'wppilot' => [
            'version' => defined('WPPILOT_VERSION') ? WPPILOT_VERSION : null,
            // The real function names. The previous probes named functions
            // that have never existed anywhere in this plugin, and the
            // function_exists guards meant nobody noticed: every site reported
            // enabled=false and profile=unknown while everything worked.
            'enabled' => function_exists('wppilot_is_enabled') && wppilot_is_enabled(),
            'safety_profile' => function_exists('wppilot_get_safety_profile')
                ? wppilot_get_safety_profile()
                : 'unknown',
            'change_records' => function_exists('wppilot_count_change_log') ? wppilot_count_change_log() : 0,
            // Where the ledger lives, and why when that is still the option (no CREATE privilege).
            'change_storage' => function_exists('wppilot_change_storage_status') ? wppilot_change_storage_status() : null,
        ],
        // Which copy of the MCP Adapter this site actually loaded. The classes are
        // global and unprefixed, so one copy serves every plugin that bundles it,
        // and a foreign copy is invisible to class_exists(). When a report says MCP
        // behaves oddly on a site running Elementor 4.3 or another MCP plugin, this
        // is the first line to read.
        'mcp_adapter' => function_exists('wppilot_mcp_adapter_origin')
            ? wppilot_mcp_adapter_origin()
            : ['loaded' => false, 'ours' => false, 'file' => '', 'version' => null, 'owner' => null],
    ];
}

/** @return array<string, mixed> */
function wppilot_performance_audit(): array
{
    $autoloaded_options = wp_load_alloptions();
    $autoload_bytes = 0;
    // @mago-expect analysis:mixed-assignment -- Autoloaded option values can contain any serializable type.
    foreach ($autoloaded_options as $value) {
        $autoload_bytes += strlen(is_string($value) ? $value : serialize($value));
    }
    $autoload_count = count($autoloaded_options);

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    // @mago-expect analysis:mixed-assignment -- WordPress option values are normalized immediately below.
    $active_plugins_option = get_option('active_plugins', []);
    $active_plugins = is_array($active_plugins_option) ? $active_plugins_option : [];
    $all_plugins = get_plugins();
    $autoload_status = 'pass';
    if ($autoload_bytes > 1_500_000) {
        $autoload_status = 'warning';
    } elseif ($autoload_bytes > 800_000) {
        $autoload_status = 'recommendation';
    }
    /** @var list<array<string, mixed>> $checks */
    $checks = [
        wppilot_diagnostic_check(
            'persistent_object_cache',
            wp_using_ext_object_cache() ? 'pass' : 'recommendation',
            wp_using_ext_object_cache()
                ? 'A persistent object cache is active.'
                : 'No persistent object cache was detected.',
        ),
        wppilot_diagnostic_check(
            'autoloaded_options',
            $autoload_status,
            sprintf('%d autoloaded options use %s.', $autoload_count, size_format($autoload_bytes, decimals: 1)),
            ['count' => $autoload_count, 'bytes' => $autoload_bytes],
        ),
        wppilot_diagnostic_check(
            'active_plugins',
            count($active_plugins) > 50 ? 'recommendation' : 'pass',
            sprintf('%d of %d installed plugins are active.', count($active_plugins), count($all_plugins)),
        ),
        wppilot_diagnostic_check(
            'php_memory_limit',
            wp_convert_hr_to_bytes((string) ini_get('memory_limit')) < 268_435_456 ? 'recommendation' : 'pass',
            sprintf('PHP memory_limit is %s.', (string) ini_get('memory_limit')),
        ),
    ];

    return [
        'status' => wppilot_diagnostic_overall_status($checks),
        'generated_at' => gmdate('c'),
        'scope' => 'Bounded server configuration audit; no browser Core Web Vitals or load test was run.',
        'checks' => $checks,
    ];
}

/** @return array<string, mixed> */
// @mago-expect lint:cyclomatic-complexity -- One bounded report intentionally assembles independent checks.
function wppilot_security_audit(): array
{
    require_once ABSPATH . 'wp-admin/includes/update.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    // @mago-expect analysis:mixed-assignment -- WordPress transients are shape-checked before access.
    $plugin_updates = get_site_transient('update_plugins');
    // @mago-expect analysis:mixed-assignment -- WordPress transients are shape-checked before access.
    $theme_updates = get_site_transient('update_themes');
    // @mago-expect analysis:mixed-assignment -- WordPress transients are shape-checked before access.
    $core_updates = get_site_transient('update_core');
    $plugin_count = is_object($plugin_updates) && is_array($plugin_updates->response ?? null)
        ? count($plugin_updates->response)
        : 0;
    $theme_count = is_object($theme_updates) && is_array($theme_updates->response ?? null)
        ? count($theme_updates->response)
        : 0;
    $core_count = 0;
    if (is_object($core_updates) && is_array($core_updates->updates ?? null)) {
        /** @var mixed $update */
        foreach ($core_updates->updates as $update) {
            if (is_object($update) && ($update->response ?? '') === 'upgrade') {
                ++$core_count;
            }
        }
    }
    $admin_named_users = get_users([
        'login__in' => ['admin', 'administrator'],
        'role' => 'administrator',
        'fields' => 'ids',
        'number' => 3,
    ]);
    $updates_total = $core_count + $plugin_count + $theme_count;
    $home_uses_https = str_starts_with(home_url('/'), 'https://');
    $file_editor_disabled = defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT === true;
    $debug_display = defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY === true;
    $safety_profile = function_exists('wppilot_get_safety_profile') ? wppilot_get_safety_profile() : 'unknown';
    /** @var list<array<string, mixed>> $checks */
    $checks = [
        wppilot_diagnostic_check(
            'https',
            $home_uses_https ? 'pass' : 'warning',
            $home_uses_https ? 'The public home URL uses HTTPS.' : 'The public home URL does not use HTTPS.',
        ),
        wppilot_diagnostic_check(
            'file_editor',
            $file_editor_disabled ? 'pass' : 'recommendation',
            $file_editor_disabled
                ? 'The built-in plugin and theme file editors are disabled.'
                : 'The built-in plugin and theme file editors are not explicitly disabled.',
        ),
        wppilot_diagnostic_check(
            'debug_display',
            !$debug_display ? 'pass' : 'warning',
            $debug_display
                ? 'WP_DEBUG_DISPLAY is enabled and may expose runtime details.'
                : 'WP_DEBUG_DISPLAY is not enabled.',
        ),
        wppilot_diagnostic_check(
            'updates',
            $updates_total > 0 ? 'warning' : 'pass',
            $updates_total > 0
                ? sprintf(
                    '%d core, %d plugin, and %d theme updates are recorded as outstanding.',
                    $core_count,
                    $plugin_count,
                    $theme_count,
                )
                : 'No outstanding core, plugin, or theme updates are recorded in current transients.',
            ['core' => $core_count, 'plugins' => $plugin_count, 'themes' => $theme_count],
        ),
        wppilot_diagnostic_check(
            'default_admin_login',
            $admin_named_users === [] ? 'pass' : 'recommendation',
            $admin_named_users === []
                ? 'No administrator account uses the common admin or administrator login.'
                : 'An administrator account uses a commonly targeted login name.',
        ),
        wppilot_diagnostic_check(
            'wppilot_safety_profile',
            $safety_profile === 'production' ? 'pass' : 'recommendation',
            sprintf('GoBuildWP safety profile is %s.', $safety_profile),
        ),
    ];

    return [
        'status' => wppilot_diagnostic_overall_status($checks),
        'generated_at' => gmdate('c'),
        'scope' => 'Configuration and update posture only. This does not prove the site is secure and is not a malware, dependency-vulnerability, penetration, or external-header scan.',
        'checks' => $checks,
    ];
}

/** @param array<string, mixed> $details @return array<string, mixed> */
function wppilot_diagnostic_check(string $id, string $status, string $message, array $details = []): array
{
    return ['id' => $id, 'status' => $status, 'message' => $message, 'details' => $details];
}

/** @param list<array<string, mixed>> $checks */
function wppilot_diagnostic_overall_status(array $checks): string
{
    $statuses = array_map(static fn(array $check): string => (string) ($check['status'] ?? 'warning'), $checks);
    if (in_array('warning', $statuses, strict: true)) {
        return 'attention_required';
    }
    if (in_array('recommendation', $statuses, strict: true)) {
        return 'recommendations_available';
    }
    return 'healthy_within_scope';
}
