<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Pause agents: one reversible switch that stops every agent on this site at once.
 *
 * Meant for the moment something looks wrong: an administrator presses Pause agents (admin bar,
 * Settings, or `wp wppilot pause on`) and every agent call is refused with `agents_paused` until
 * someone resumes. Nothing is deleted or disconnected: tokens, OAuth grants, the Cloud link and
 * every setting stay as they were, so Resume puts the site back exactly where it was.
 *
 * While paused:
 *
 * - Every ability call is refused, reads included, on every transport: the gate pipeline (MCP,
 *   the REST shim, Chat, approval replay), the legacy MCP adapter's pre-tool hook, and on
 *   WordPress 7.1+ `wp_ability_permission_result`, which also covers core's run route and
 *   WP-CLI's `wp ability run`.
 * - A REST request made with an agent credential (application password, access token, OAuth) is
 *   refused before any route runs, the MCP endpoints included. The Cloud status route stays open
 *   so GoBuildWP Cloud can show the site as paused, and the Cloud unlink route stays open because
 *   it only removes access. People signed in to wp-admin are not agents and keep working.
 *
 * How it differs from the neighbours:
 *
 * - Read Mode keeps agents working, read-only. Pause stops them entirely.
 * - The AI abilities switch takes the MCP endpoint away, so clients see a missing server rather
 *   than a reason. Pause keeps the endpoint answering with a clear refusal.
 * - `WPPILOT_DISABLE_MCP` (wp-config.php) is a configuration hard-off for hosts. `WPPILOT_AGENTS_PAUSED`
 *   is the wp-config.php pin of this switch: while it is true the screens cannot resume.
 *
 * Fails closed: only a stored `'0'` (or no option) reads as running.
 */

const WPPILOT_AGENTS_PAUSED_OPTION = 'wppilot_agents_paused';

/** Who paused and when: {by: int user id, at: int unix time, source?: local|cloud, reason?: string, actor?: string}. */
const WPPILOT_AGENTS_PAUSED_META_OPTION = 'wppilot_agents_paused_meta';

/**
 * "Only resume locally": when '1', GoBuildWP Cloud may still pause this site but never resume it;
 * a person in wp-admin (or WP-CLI) has to. Off by default.
 */
const WPPILOT_AGENTS_RESUME_LOCAL_ONLY_OPTION = 'wppilot_agents_resume_local_only';

function wppilot_agents_resume_local_only(): bool
{
    return get_option(WPPILOT_AGENTS_RESUME_LOCAL_ONLY_OPTION, '0') === '1';
}

function wppilot_set_agents_resume_local_only(bool $on): void
{
    update_option(WPPILOT_AGENTS_RESUME_LOCAL_ONLY_OPTION, $on ? '1' : '0', autoload: false);
}

/**
 * Whether agents are paused on this site.
 */
function wppilot_agents_paused(): bool
{
    if (wppilot_agents_pause_forced()) {
        return true;
    }

    /** @var mixed $stored */
    $stored = get_option(WPPILOT_AGENTS_PAUSED_OPTION, default_value: '0');

    return !($stored === '0' || $stored === 0 || $stored === false);
}

/**
 * Whether wp-config.php pins the pause on, so the screens cannot resume.
 */
function wppilot_agents_pause_forced(): bool
{
    return defined('WPPILOT_AGENTS_PAUSED') && (bool) constant('WPPILOT_AGENTS_PAUSED') === true;
}

/**
 * Pause or resume. Returns false when wp-config.php pins the pause on.
 *
 * @param array{source?: string, reason?: string, actor?: string} $meta Kept with a pause: where it
 *        came from (`local` or `cloud`), the reason given and, for Cloud, who pressed it there.
 */
function wppilot_set_agents_paused(bool $paused, array $meta = []): bool
{
    if (!$paused && wppilot_agents_pause_forced()) {
        return false;
    }

    $was = wppilot_agents_paused();
    update_option(WPPILOT_AGENTS_PAUSED_OPTION, $paused ? '1' : '0', autoload: true);
    if ($was === $paused) {
        return true;
    }

    if ($paused) {
        update_option(WPPILOT_AGENTS_PAUSED_META_OPTION, [
            'by' => get_current_user_id(),
            'at' => time(),
            'source' => ($meta['source'] ?? 'local') === 'cloud' ? 'cloud' : 'local',
            'reason' => wppilot_agents_pause_clean((string) ($meta['reason'] ?? ''), 500),
            'actor' => wppilot_agents_pause_clean((string) ($meta['actor'] ?? ''), 191),
        ], autoload: false);
    } else {
        delete_option(WPPILOT_AGENTS_PAUSED_META_OPTION);
    }

    /**
     * Fires after agents are paused or resumed.
     *
     * @param bool $paused The new state.
     */
    do_action('wppilot_agents_pause_changed', $paused);

    return true;
}

/** One line of plain text: control characters become spaces, then it is cut to $max characters. */
function wppilot_agents_pause_clean(string $value, int $max): string
{
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '');

    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

/**
 * The pause state as GoBuildWP Cloud and the screens report it.
 *
 * @return array{paused: bool, pinned: bool, by: int, at: int, source: string, reason: string, actor: string, resume_local_only: bool}
 */
function wppilot_agents_pause_status(): array
{
    $paused = wppilot_agents_paused();
    /** @var mixed $meta */
    $meta = $paused ? get_option(WPPILOT_AGENTS_PAUSED_META_OPTION, default_value: []) : [];
    $meta = is_array($meta) ? $meta : [];

    return [
        'paused' => $paused,
        'pinned' => wppilot_agents_pause_forced(),
        'by' => (int) ($meta['by'] ?? 0),
        'at' => (int) ($meta['at'] ?? 0),
        'source' => is_string($meta['source'] ?? null) ? $meta['source'] : ($paused ? 'local' : ''),
        'reason' => is_string($meta['reason'] ?? null) ? $meta['reason'] : '',
        'actor' => is_string($meta['actor'] ?? null) ? $meta['actor'] : '',
        // Cloud reads this to say why it cannot resume, and to grey its Resume button out.
        'resume_local_only' => wppilot_agents_resume_local_only(),
    ];
}

/**
 * The refusal every agent gets while paused.
 */
function wppilot_agents_paused_error(string $ability_name = ''): WP_Error
{
    $data = ['status' => 503, 'agents_paused' => true];
    if ($ability_name !== '') {
        $data['ability'] = $ability_name;
    }

    return new WP_Error(
        'agents_paused',
        wppilot_agents_pause_forced()
            ? __(
                'A site administrator has paused all AI agents on this site (WPPILOT_AGENTS_PAUSED in wp-config.php). Nothing was run. Stop and tell the person you are working for; do not retry until they say the site is resumed.',
                domain: 'wppilot',
            )
            : __(
                'A site administrator has paused all AI agents on this site. Nothing was run. Stop and tell the person you are working for; do not retry until an administrator resumes agents (admin bar > Resume agents, GoBuildWP Settings, or `wp wppilot pause off`).',
                domain: 'wppilot',
            ),
        $data,
    );
}

/**
 * `wp_ability_permission_result` (WordPress 7.1+): refuse every ability while paused.
 *
 * Priority 1, ahead of the safety filter, so the pause is the reason an agent is given.
 */
function wppilot_agents_pause_filter_permission(mixed $permission, mixed $ability_name): mixed
{
    if (!wppilot_agents_paused()) {
        return $permission;
    }

    return wppilot_agents_paused_error(is_string($ability_name) ? $ability_name : '');
}

/**
 * The gate pipeline's first check (includes/gate-pipeline.php), for WordPress 6.9 and 7.0 too.
 */
function wppilot_agents_pause_gate_error(WP_Ability $ability): ?WP_Error
{
    return wppilot_agents_paused() ? wppilot_agents_paused_error($ability->get_name()) : null;
}

/**
 * `mcp_adapter_pre_tool_call`: the legacy adapter never passes through the gate pipeline.
 *
 * Priority 99, after the safety hook (5): that callback is typed `array $args`, so a refusal
 * handed to it earlier would be a TypeError rather than an answer. An earlier refusal is kept.
 *
 * @param mixed $args
 */
function wppilot_agents_pause_mcp_tool_call(mixed $args, mixed $tool_name = ''): mixed
{
    if (!wppilot_agents_paused() || $args instanceof WP_Error) {
        return $args;
    }

    return wppilot_agents_paused_error(is_string($tool_name) ? $tool_name : '');
}

/**
 * Routes an agent credential still reaches while paused.
 */
function wppilot_agents_pause_route_is_exempt(string $route): bool
{
    // agents-pause and agents-resume are the signed Cloud kill switch (includes/cloud/agent-pause.php):
    // resume must reach a paused site. The signature check (rest_pre_dispatch, 22) still runs on both.
    $exempt = in_array($route, [
        '/wppilot/v1/cloud/status',
        '/wppilot/v1/cloud/unlink',
        '/wppilot/v1/cloud/agents-pause',
        '/wppilot/v1/cloud/agents-resume',
    ], strict: true);

    /**
     * Filter whether an agent may reach a REST route while agents are paused.
     *
     * Only for routes that report state or reduce access; anything that acts must stay refused.
     *
     * @param bool   $exempt Default true for the Cloud status, unlink and signed pause/resume routes.
     * @param string $route  The REST route.
     */
    return apply_filters('wppilot_agents_pause_route_is_exempt', $exempt, $route) === true;
}

/**
 * `rest_pre_dispatch`: refuse every REST request made with an agent credential while paused.
 *
 * @param mixed $result Response short-circuit from an earlier filter.
 */
function wppilot_agents_pause_rest_guard(mixed $result, mixed $server, mixed $request): mixed
{
    if ($result !== null || !$request instanceof WP_REST_Request || !wppilot_agents_paused()) {
        return $result;
    }
    if (wppilot_agents_pause_route_is_exempt($request->get_route()) || !wppilot_read_mode_request_is_agent()) {
        return $result;
    }

    return wppilot_agents_paused_error();
}

/**
 * The admin-bar switch: "Pause agents" while running, "Agents paused" (click to resume) while paused.
 */
function wppilot_agents_pause_admin_bar(\WP_Admin_Bar $wp_admin_bar): void
{
    if (!wppilot_current_user_can_manage()) {
        return;
    }

    $paused = wppilot_agents_paused();
    $pinned = wppilot_agents_pause_forced();
    $url = wp_nonce_url(
        admin_url('admin-post.php?action=wppilot_agents_pause&wppilot_target=' . ($paused ? 'off' : 'on')),
        action: 'wppilot_agents_pause',
    );

    $wp_admin_bar->add_node([
        'id' => 'wppilot-agents-pause',
        'title' => $paused
            ? esc_html__('Agents paused', domain: 'wppilot')
            : esc_html__('Pause agents', domain: 'wppilot'),
        'href' => $paused && $pinned ? admin_url('admin.php?page=wppilot-settings#agent-access') : $url,
        'meta' => [
            'class' => $paused ? 'wppilot-agents-paused-badge' : 'wppilot-agents-pause',
            'title' => $paused
                ? ($pinned
                    ? __('Every AI agent call is refused. Pinned by WPPILOT_AGENTS_PAUSED in wp-config.php.', domain: 'wppilot')
                    : __('Every AI agent call is refused. Click to resume agents.', domain: 'wppilot'))
                : __('Refuse every AI agent call on this site until you resume. Nothing is disconnected.', domain: 'wppilot'),
        ],
    ]);
}

/**
 * admin-post.php?action=wppilot_agents_pause&wppilot_target=on|off.
 */
function wppilot_agents_pause_handle_toggle(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to manage GoBuildWP settings.', domain: 'wppilot'));
    }
    check_admin_referer('wppilot_agents_pause');

    $target = isset($_GET['wppilot_target']) && is_string($_GET['wppilot_target'])
        ? sanitize_key(wp_unslash($_GET['wppilot_target']))
        : '';
    $ok = ($target === 'on' || $target === 'off') && wppilot_set_agents_paused($target === 'on');

    $redirect = wp_get_referer();
    if (!is_string($redirect) || $redirect === '') {
        $redirect = admin_url('admin.php?page=wppilot-settings');
    }
    wp_safe_redirect(add_query_arg(['wppilot_agents_pause' => $ok ? $target : 'failed'], $redirect));
    exit();
}

/**
 * A notice on every admin screen while paused, so nobody forgets the site is paused.
 */
function wppilot_agents_pause_notice(): void
{
    if (!wppilot_agents_paused() || !wppilot_current_user_can_manage()) {
        return;
    }
    $resume = wp_nonce_url(admin_url('admin-post.php?action=wppilot_agents_pause&wppilot_target=off'), action: 'wppilot_agents_pause');
    ?>
    <div class="notice notice-warning"><p>
        <strong><?php esc_html_e('GoBuildWP: AI agents are paused.', domain: 'wppilot'); ?></strong>
        <?php if (wppilot_agents_pause_forced()) {
            esc_html_e('Every agent call is refused until WPPILOT_AGENTS_PAUSED is removed from wp-config.php.', domain: 'wppilot');
        } else {
            esc_html_e('Every agent call is refused. Connections and settings are kept.', domain: 'wppilot'); ?>
            <a href="<?php echo esc_url($resume); ?>"><?php esc_html_e('Resume agents', domain: 'wppilot'); ?></a>
        <?php } ?>
    </p></div>
    <?php
}

/**
 * `wp wppilot pause on|off|status`.
 *
 * @param list<string> $args
 */
function wppilot_agents_pause_cli(array $args): void
{
    $action = $args[0] ?? 'status';

    if ($action === 'status') {
        $status = wppilot_agents_pause_status();
        \WP_CLI::log($status['paused'] ? 'paused' : 'running');
        if ($status['pinned']) {
            \WP_CLI::log('Pinned by WPPILOT_AGENTS_PAUSED in wp-config.php.');
        }
        return;
    }
    if ($action !== 'on' && $action !== 'off') {
        \WP_CLI::error('Usage: wp wppilot pause on|off|status');
    }
    if (!wppilot_set_agents_paused($action === 'on')) {
        \WP_CLI::error('Agents are pinned paused by WPPILOT_AGENTS_PAUSED in wp-config.php.');
    }

    \WP_CLI::success($action === 'on' ? 'Agents are paused: every agent call is refused.' : 'Agents are resumed.');
}

function wppilot_agents_pause_register_hooks(): void
{
    add_filter('wp_ability_permission_result', callback: 'wppilot_agents_pause_filter_permission', priority: 1, accepted_args: 2);
    add_filter('mcp_adapter_pre_tool_call', callback: 'wppilot_agents_pause_mcp_tool_call', priority: 99, accepted_args: 2);
    // Ahead of Read Mode (5): a paused site refuses reads as well as writes.
    add_filter('rest_pre_dispatch', callback: 'wppilot_agents_pause_rest_guard', priority: 4, accepted_args: 3);
    add_action('admin_bar_menu', callback: 'wppilot_agents_pause_admin_bar', priority: 999);
    add_action('admin_post_wppilot_agents_pause', callback: 'wppilot_agents_pause_handle_toggle');
    add_action('admin_notices', callback: 'wppilot_agents_pause_notice');

    if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
        \WP_CLI::add_command('wppilot pause', 'wppilot_agents_pause_cli', [
            'shortdesc' => 'Pause or resume every AI agent on this site, or show whether agents are paused.',
            'synopsis' => [
                [
                    'type' => 'positional',
                    'name' => 'action',
                    'optional' => true,
                    'default' => 'status',
                    'options' => ['on', 'off', 'status'],
                ],
            ],
        ]);
    }
}
