<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/schema.php';

// Function definitions only — the module registers nothing until its loader runs.
require_once __DIR__ . '/wordpress/bootstrap.php';

/**
 * Load the Ability execution REST shim only when this WordPress version supports Abilities.
 */
function wppilot_boot_ability_rest_surface(): bool
{
    if (!wppilot_wordpress_abilities_supported()) {
        return false;
    }

    require_once dirname(__DIR__) . '/rest/shim.php';
    add_action('rest_api_init', callback: 'wppilot_register_ability_run_rest_shim');
    // Core's own runner gets the same controls as the shim; it needs the shim's helpers.
    require_once dirname(__DIR__) . '/rest/core-run-gate.php';

    return true;
}

/**
 * Register GoBuildWP Ability hooks independently of protocol-adapter initialization.
 */
function wppilot_register_ability_hooks(): bool
{
    if (!wppilot_wordpress_abilities_supported()) {
        return false;
    }

    add_action('wp_abilities_api_categories_init', callback: 'wppilot_register_ability_categories', priority: 20);
    add_action('wp_abilities_api_init', callback: 'wppilot_register_builtin_abilities', priority: 20);

    return true;
}

/**
 * Keep policy enforcement active even when GoBuildWP is disabled so independently registered skill
 * or extension Abilities are removed according to the existing rules.
 */
function wppilot_register_ability_policy_hook(): bool
{
    if (!wppilot_wordpress_abilities_supported()) {
        return false;
    }

    add_action('wp_abilities_api_init', callback: 'wppilot_apply_ability_policy', priority: PHP_INT_MAX);

    return true;
}

/**
 * Register categories owned by GoBuildWP's built-in Abilities.
 */
function wppilot_register_ability_categories(): void
{
    if (!wppilot_is_wordpress_org_edition()) {
        wp_register_ability_category('code-execution', [
            'label' => __('Code Execution', domain: 'wppilot'),
            'description' => __('Abilities that execute code on the WordPress server.', domain: 'wppilot'),
        ]);

        wp_register_ability_category('filesystem', [
            'label' => __('Filesystem', domain: 'wppilot'),
            'description' => __('Server filesystem operations.', domain: 'wppilot'),
        ]);

        wp_register_ability_category('admin-access', [
            'label' => __('Admin Access', domain: 'wppilot'),
            'description' => __('Temporary browser access to WordPress admin.', domain: 'wppilot'),
        ]);
    }

    if (!wp_has_ability_category('mcp-adapter')) {
        wp_register_ability_category('mcp-adapter', [
            'label' => __('MCP Adapter', domain: 'wppilot'),
            'description' => __('Meta-abilities for MCP protocol bridging.', domain: 'wppilot'),
        ]);
    }

    wppilot_register_wordpress_ability_category();

    wp_register_ability_category('gutenberg', [
        'label' => __('Gutenberg', domain: 'wppilot'),
        'description' => __(
            'Gutenberg content abilities, including the Block Editor Queue for native/static blocks that need browser JS finalization. At the start of Gutenberg work, check the queue runtime and ask the user to keep the Block Editor Queue page open when static/native blocks may be queued.',
            domain: 'wppilot',
        ),
    ]);

    wp_register_ability_category('changes', [
        'label' => __('Changes', domain: 'wppilot'),
        'description' => __('Redacted change history and verified rollback operations.', domain: 'wppilot'),
    ]);

    wp_register_ability_category('diagnostics', [
        'label' => __('Diagnostics', domain: 'wppilot'),
        'description' => __(
            'Read-only operational, performance, security, and integration diagnostics.',
            domain: 'wppilot',
        ),
    ]);

    wp_register_ability_category('preview', [
        'label' => __('Preview', domain: 'wppilot'),
        'description' => __(
            'Compute what a write would change without performing it, then apply the reviewed result.',
            domain: 'wppilot',
        ),
    ]);
}

/**
 * Register every built-in Ability. The optional adapter may consume these registrations later but
 * is not a prerequisite for them.
 */
function wppilot_register_builtin_abilities(): void
{
    $dir = __DIR__ . '/';
    if (!wppilot_is_wordpress_org_edition()) {
        require_once $dir . 'execute-php.php';
        require_once $dir . 'read-file.php';
        require_once $dir . 'write-file.php';
        require_once $dir . 'edit-file.php';
        require_once $dir . 'delete-file.php';
        require_once $dir . 'create-upload-link.php';
        require_once $dir . 'create-admin-access-link.php';
        require_once $dir . 'disable-file.php';
        require_once $dir . 'enable-file.php';
        require_once $dir . 'list-directory.php';
        require_once $dir . 'run-wp-cli.php';
    }
    require_once $dir . 'discover-abilities.php';
    require_once $dir . 'agent-context.php';
    require_once $dir . 'change-log.php';
    require_once $dir . 'sessions.php';
    require_once $dir . 'diagnostics.php';
    require_once $dir . 'preview.php';
    wppilot_load_wordpress_abilities();
    wppilot_load_gutenberg_abilities();
}
