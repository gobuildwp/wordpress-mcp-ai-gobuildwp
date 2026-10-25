<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Internal compatibility contract shared by metadata, startup gates, and agent context.
 */
define(constant_name: 'WPPILOT_VERSION', value: '1.18.8');
define(constant_name: 'WPPILOT_REST_API_VERSION', value: 1);
define(constant_name: 'WPPILOT_MINIMUM_WORDPRESS_VERSION', value: '6.9');
// The newest WordPress this release was exercised against. Advertised rather
// than enforced: a newer WordPress is served exactly as an older one is, and
// the number exists so a client can tell "tested here" from "assumed to work".
define(constant_name: 'WPPILOT_TESTED_WORDPRESS_VERSION', value: '7.1');

/**
 * Return the feature signals implemented by this build.
 *
 * Features remain false until their owning implementation session lands. This makes an in-progress
 * build fail closed at the public compatibility gate instead of advertising a surface it cannot serve.
 *
 * @return array{
 *     abilities_bearer_auth: bool,
 *     agent_context: bool,
 *     rest_skills: bool,
 *     generalized_execution_shim: bool
 * }
 */
function wppilot_rest_api_features(): array
{
    return [
        'abilities_bearer_auth' => true,
        'agent_context' => true,
        'rest_skills' => true,
        'generalized_execution_shim' => true,
        // Legacy MCP is served by the bundled adapter; modern MCP by
        // includes/mcp/. Both are true only because both are wired — see
        // wppilot_supported_protocol_versions() for what is actually claimed.
        'mcp_legacy_protocol' => true,
        'mcp_modern_protocol' => true,
        // Not implemented, and advertised as false rather than omitted so a
        // client can tell "absent" from "unsupported": GoBuildWP has no
        // change-notification producer, so there is nothing to subscribe to.
        'mcp_subscriptions' => false,
        'mcp_tasks_extension' => false,
    ];
}

/**
 * MCP protocol revisions this build serves.
 *
 * @return list<string>
 */
function wppilot_supported_protocol_versions(): array
{
    return \WPPilot\Mcp\SUPPORTED_VERSIONS;
}

/**
 * Return the installed WordPress version without coupling metadata to an HTTP request.
 */
function wppilot_wordpress_version(): string
{
    return get_bloginfo('version');
}

/**
 * Whether this WordPress installation has the minimum Abilities API generation GoBuildWP supports.
 */
function wppilot_wordpress_abilities_supported(?string $wordpress_version = null): bool
{
    $wordpress_version ??= wppilot_wordpress_version();

    return $wordpress_version !== ''
    && version_compare($wordpress_version, WPPILOT_MINIMUM_WORDPRESS_VERSION, operator: '>=');
}

/**
 * What the host WordPress offers, detected rather than inferred from its version.
 *
 * Each entry is a capability GoBuildWP changes its behaviour for. They are probed by asking for
 * the function or class that implements them, not by comparing version strings, because a
 * feature plugin, a backport, or a site running a release candidate can all put a capability
 * on a WordPress whose reported version says otherwise — and the version is the one thing here
 * that is allowed to lie.
 *
 * - `abilities_registry`: the ability registry can be read directly, so policy enforcement can
 *   see abilities that discovery filters hide (WordPress 6.9+).
 * - `abilities_query_args`: `wp_get_abilities()` accepts filtering arguments (WordPress 7.1+).
 * - `abilities_lifecycle_filters`: the execution lifecycle can be mediated, so the safety
 *   profile applies to every caller and not only to GoBuildWP's own transports (WordPress 7.1+).
 * - `json_schema_client_prep`: core can translate a WordPress schema into a client-facing one
 *   (WordPress 7.1+).
 * - `ai_client`: the AI Client is present, which is what GoBuildWP Chat runs on (WordPress 7.0+).
 *
 * @return array<string, bool>
 */
function wppilot_wordpress_capabilities(): array
{
    $registry_readable = false;
    if (class_exists('WP_Abilities_Registry')) {
        $registry_readable = method_exists('WP_Abilities_Registry', 'get_all_registered');
    }

    $query_args = false;
    if (function_exists('wp_get_abilities')) {
        $query_args = (new ReflectionFunction('wp_get_abilities'))->getNumberOfParameters() > 0;
    }

    return [
        'abilities_registry' => $registry_readable,
        'abilities_query_args' => $query_args,
        // The four lifecycle filters landed together, and the input normalizer
        // is the one with a name nothing else could plausibly claim.
        'abilities_lifecycle_filters' => $query_args,
        'json_schema_client_prep' => function_exists('wp_prepare_json_schema_for_client'),
        'ai_client' => function_exists('wp_ai_client_prompt'),
    ];
}

/**
 * Stable compatibility block published before and after authentication.
 *
 * @return array{
 *     plugin_version: string,
 *     rest_api_version: int,
 *     wordpress_version: string,
 *     minimum_wordpress_version: string,
 *     tested_wordpress_version: string,
 *     features: array<string, bool>,
 *     wordpress_capabilities: array<string, bool>
 * }
 */
function wppilot_server_compatibility(): array
{
    return [
        'plugin_version' => WPPILOT_VERSION,
        'rest_api_version' => WPPILOT_REST_API_VERSION,
        'wordpress_version' => wppilot_wordpress_version(),
        'minimum_wordpress_version' => WPPILOT_MINIMUM_WORDPRESS_VERSION,
        'tested_wordpress_version' => WPPILOT_TESTED_WORDPRESS_VERSION,
        'features' => wppilot_rest_api_features(),
        'wordpress_capabilities' => wppilot_wordpress_capabilities(),
        // Guarded: this block is published from startup gates that can run
        // before the MCP modules are loaded.
        'mcp_protocol_versions' => defined('WPPilot\\Mcp\\VERSION_MODERN')
            ? wppilot_supported_protocol_versions()
            : [],
    ];
}

/**
 * Register the unsupported-WordPress administrator warning when the Abilities API cannot be used.
 */
function wppilot_register_wordpress_compatibility_notice(): void
{
    if (wppilot_wordpress_abilities_supported()) {
        return;
    }

    add_action('admin_notices', callback: 'wppilot_render_wordpress_compatibility_notice');
    add_action('network_admin_notices', callback: 'wppilot_render_wordpress_compatibility_notice');
}

/**
 * Explain why Ability registration and its REST shim were skipped.
 */
function wppilot_render_wordpress_compatibility_notice(): void
{
    if (!wppilot_current_user_can_manage()) {
        return;
    }

    wp_admin_notice(
        sprintf(
            /* translators: 1: minimum required WordPress version, 2: installed WordPress version */
            esc_html__(
                'GoBuildWP requires WordPress %1$s or newer for the Abilities API. WordPress %2$s is installed, so GoBuildWP Ability and REST registration is disabled.',
                domain: 'wppilot',
            ),
            esc_html(WPPILOT_MINIMUM_WORDPRESS_VERSION),
            esc_html(wppilot_wordpress_version()),
        ),
        ['type' => 'warning', 'dismissible' => false],
    );
}
