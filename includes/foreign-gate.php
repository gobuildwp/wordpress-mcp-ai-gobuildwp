<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * GoBuildWP's approvals for writes made through other plugins' MCP servers.
 *
 * Elementor (4.3+) and WooCommerce (10.3+) ship MCP servers of their own. An agent connected to
 * one of them reaches their abilities without passing GoBuildWP's gate pipeline: the safety profile
 * and Hub rules still apply (they live in `wp_ability_permission_result`, inside execute()) and
 * the change ledger still records the write (it hooks execute() too), but the confirmation
 * contract, a person's approval and Pro's approval queue and backup gate do not.
 *
 * WordPress 7.1 added `wp_pre_execute_ability`, which runs inside every execute(). With this
 * setting on, a write that reaches it through some other REST route - another plugin's MCP
 * server - is put through the same controls:
 *
 * - The safety profile, as GoBuildWP's own transports apply it.
 * - A destructive or critical ability needs a person's approval on a one-time wp-admin link, in
 *   either confirmation mode: those servers' clients cannot send GoBuildWP's `confirm` flag (their
 *   input schemas refuse unknown properties), so the agent flag is not an option there.
 * - `wppilot_pre_ability_execute` with transport `foreign`, so Pro's holds apply.
 *
 * Off by default: switching it on changes how agents on other servers behave. Calls GoBuildWP has
 * already gated pass once per gate (see wppilot_gate_mark_passed()), reads are never held, and
 * code that runs an ability outside a REST request (a plugin's own cron, say) is left alone.
 */

const WPPILOT_FOREIGN_GATE_OPTION = 'wppilot_foreign_mcp_gate';

function wppilot_foreign_gate_enabled(): bool
{
    return get_option(WPPILOT_FOREIGN_GATE_OPTION, 'off') === 'on';
}

/**
 * `wp_pre_execute_ability`: hold a write that arrives through another plugin's MCP server.
 *
 * @param mixed $pre The core sentinel, or a value another filter already short-circuited with.
 */
function wppilot_foreign_gate(mixed $pre, string $ability_name, mixed $input, mixed $ability = null): mixed
{
    // Another filter already decided; and a call GoBuildWP gated passes, using up its pass.
    if (!$pre instanceof WP_Filter_Sentinel || wppilot_gate_take_pass($ability_name)) {
        return $pre;
    }
    if (!wppilot_foreign_gate_enabled() || !defined('REST_REQUEST') || !REST_REQUEST) {
        return $pre;
    }
    $ability = $ability instanceof WP_Ability ? $ability : (function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null);
    if (!$ability instanceof WP_Ability || wppilot_ability_is_readonly($ability) || wppilot_change_ability_is_meta($ability_name)) {
        return $pre;
    }

    $allowed = wppilot_safety_check_ability($ability);
    if ($allowed instanceof WP_Error) {
        return $allowed;
    }

    if (wppilot_ability_requires_confirmation($ability)) {
        $approved = wppilot_foreign_gate_approval($ability, $input);
        if ($approved instanceof WP_Error) {
            return $approved;
        }
        wppilot_confirmation_note($ability_name, $approved);
    }

    /** See wppilot_gate_ability_call(); the input cannot be changed from here, only refused. */
    // @mago-expect lint:literal-named-argument -- WordPress filter arguments after value are variadic.
    $held = apply_filters('wppilot_pre_ability_execute', $input, $ability, 'foreign', []);

    return $held instanceof WP_Error ? $held : $pre;
}

/**
 * A person's approval for this exact call, through the one-time wp-admin link, whatever the
 * site's confirmation mode: the agent-flag mode needs a `confirm` the foreign client cannot send.
 */
function wppilot_foreign_gate_approval(WP_Ability $ability, mixed $input): string|WP_Error
{
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        return new WP_Error(
            'wppilot_human_confirmation_unavailable',
            __('This site requires a person to approve destructive calls, and this call is not made as a signed-in user.', domain: 'wppilot'),
            ['status' => 403, 'ability' => $ability->get_name()],
        );
    }
    $name = $ability->get_name();
    $hash = wppilot_confirmation_input_hash($input);
    if (wppilot_confirmation_claim_approval($name, $hash, $user_id)) {
        return 'approval-url';
    }
    if (wppilot_confirmation_find_request($name, $hash, $user_id, 'denied') !== null) {
        return new WP_Error(
            'wppilot_confirmation_denied',
            __('A person denied this exact call in wp-admin. Do not retry it; ask the user what they want instead.', domain: 'wppilot'),
            ['status' => 403, 'ability' => $name],
        );
    }

    return wppilot_confirmation_approval_url_error($ability, $input, 'foreign', $hash, $user_id);
}

/**
 * The switch, on the Settings screen next to the confirmation mode.
 */
function wppilot_foreign_gate_register_setting(mixed $sections): mixed
{
    if (!is_array($sections)) {
        return $sections;
    }
    $sections[] = [
        'id' => 'wppilot-foreign-gate',
        'title' => __('Other MCP servers', domain: 'wppilot'),
        'description' => __(
            'Elementor and WooCommerce have MCP servers of their own. Their writes are always recorded in the change log; this decides whether they also need GoBuildWP\'s approvals.',
            domain: 'wppilot',
        ),
        'fields' => [
            [
                'type' => 'select',
                'name' => WPPILOT_FOREIGN_GATE_OPTION,
                'label' => __('Approvals for their writes', domain: 'wppilot'),
                'help' => __(
                    'On: a write an agent makes through another plugin\'s MCP server follows this site\'s safety profile, a destructive one waits for a person to approve it on a wp-admin link, and GoBuildWP Pro\'s approval queue and backup gate apply. Reads are never held. Needs WordPress 7.1.',
                    domain: 'wppilot',
                ),
                'value' => wppilot_foreign_gate_enabled() ? 'on' : 'off',
                'options' => [
                    'off' => __('Off: record their writes only', domain: 'wppilot'),
                    'on' => __('On: apply GoBuildWP\'s approvals', domain: 'wppilot'),
                ],
            ],
        ],
        'save' => 'wppilot_foreign_gate_save_setting',
    ];

    return $sections;
}

/**
 * @param array<string, mixed> $post
 */
function wppilot_foreign_gate_save_setting(array $post): void
{
    $value = ($post[WPPILOT_FOREIGN_GATE_OPTION] ?? '') === 'on' ? 'on' : 'off';
    update_option(WPPILOT_FOREIGN_GATE_OPTION, $value, autoload: true);
}

add_filter('wp_pre_execute_ability', 'wppilot_foreign_gate', 10, 4);
add_filter('wppilot_settings_sections', 'wppilot_foreign_gate_register_setting');
