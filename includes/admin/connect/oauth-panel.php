<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Configuration page: the OAuth connection method.
 *
 * The OAuth endpoints exist only when the transport gate allows them, so
 * this panel also explains why it is unavailable when it is.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Render the OAuth method panel (Step 2). OAuth has no setup action of its own: the per-client
 * connect instructions live in Step 3 (wppilot_render_oauth_config_section), so this panel only
 * links to the connected-apps manager.
 */
function wppilot_render_oauth_panel(): void
{
    if (!wppilot_oauth_transport_allowed()) {
        return;
    }
    $connected_apps_url = admin_url('admin.php?page=wppilot-connected-apps');
    ?>
    <p class="description" style="margin:0;">
        <a href="<?php echo esc_url($connected_apps_url); ?>">
            <?php esc_html_e('Manage connected apps', domain: 'wppilot'); ?>
        </a>
    </p>
    <?php
}

/**
 * Notice for a local self-signed HTTPS site, explaining the NODE_TLS_REJECT_UNAUTHORIZED flag the
 * connection config carries. Shared by the app-password and OAuth flows so the wording is identical;
 * renders nothing unless the site looks self-signed.
 */
function wppilot_render_local_https_notice(): void
{
    if (!wppilot_likely_self_signed_https()) {
        return;
    }
    ?>
    <div class="notice notice-warning inline" style="margin:0 0 12px;">
        <p style="margin:0;">
            <strong><?php esc_html_e('Local HTTPS detected.', domain: 'wppilot'); ?></strong>
            <?php printf(
                /* translators: %s: the NODE_TLS_REJECT_UNAUTHORIZED=0 flag, wrapped in <code> tags */
                esc_html__(
                    'Your certificate is not publicly trusted (normal for local development), so the config sets %s. This turns off TLS certificate verification for the whole npx process, including while it downloads the package, so only use it on a network you trust.',
                    domain: 'wppilot',
                ),
                '<code>NODE_TLS_REJECT_UNAUTHORIZED=0</code>',
            ); ?>
        </p>
    </div>
    <?php
}

/**
 * Render the tabbed OAuth client-config section (Step 3). One tab per client; a native URL
 * snippet, a connector button, or the mcp-remote bridge, depending on the client and whether
 * the site is reachable from the cloud. Uses its own id namespace so it can coexist in the DOM
 * with the app-password config section (only one method panel is visible at a time).
 */
function wppilot_render_oauth_config_section(string $rest_url): void
{
    if (!wppilot_oauth_transport_allowed()) {
        return;
    }
    $default_name = wppilot_get_mcp_server_name_default();
    $name_placeholder = '__WPPILOT_MCP_NAME__';
    $configs = wppilot_build_oauth_configs($rest_url, $name_placeholder);

    // Same order as the app-password config section; Claude.ai (public-only, absent locally) is
    // slotted next to the other Claude clients so the local order matches the app-password one exactly.
    $clients = [
        'claude-code' => 'Claude Code',
        'claude-desktop' => 'Claude Desktop',
        'claude-ai' => 'Claude.ai',
        'chatgpt' => 'ChatGPT',
        'mistral-lechat' => 'Mistral Le Chat',
        'perplexity' => 'Perplexity',
        'manus' => 'Manus',
        'codex' => 'Codex',
        'antigravity-cli' => 'Antigravity CLI',
        'antigravity-ide' => 'Antigravity IDE',
        'cursor' => 'Cursor',
        'vscode' => 'VS Code',
        'github-copilot' => 'GitHub Copilot',
        'windsurf' => 'Devin Desktop (Windsurf)',
        'cline' => 'Cline',
        'roo-code' => 'Roo Code',
        'amazon-q' => 'Amazon Q / Kiro',
        'zed' => 'Zed',
        'kilo-code' => 'Kilo Code',
        'opencode' => 'OpenCode',
        // These have had OAuth entries in the local (bridge) set for a while but
        // no tab to reach them, so the method was unreachable for them.
        'factory-droid' => 'Factory Droid',
        'kimi-cli' => 'Kimi Code CLI',
        'qwen-code' => 'Qwen Code',
        'gemini-cli' => 'Gemini CLI',
        'zcode' => 'ZCode (GLM)',
        'openclaw' => 'OpenClaw',
    ];
    ?>
    <h2 class="wppilot-step-heading">
        <span class="wppilot-step-badge">2</span>
        <?php esc_html_e('Connect your AI client', domain: 'wppilot'); ?>
    </h2>

    <?php wppilot_render_local_https_notice(); ?>

    <div class="wppilot-client-tabs" style="gap:8px; margin-top:16px; margin-bottom:0;">
    <?php foreach ($clients as $key => $label): ?>
        <?php if (array_key_exists($key, $configs)): ?>
        <button
            type="button"
            class="wppilot-client-tab wppilot-top-client-tab wppilot-oauth-tab"
            data-client="<?php echo esc_attr($key); ?>"
            onclick="wppilotOauthSetClient('<?php echo esc_js($key); ?>', this)"
        ><?php echo esc_html($label); ?></button>
        <?php endif; ?>
    <?php endforeach; ?>
    </div>

    <div id="wppilot-oauth-content" style="display:none; margin-top:16px;">
        <div id="wppilot-oauth-connector-wrap" style="display:none; margin-bottom:12px;">
            <a
                id="wppilot-oauth-connector-btn"
                class="button button-primary"
                style="padding:12px 24px; height:auto; font-size:15px;"
                href="#"
                target="_blank"
                rel="noopener"
            ><?php esc_html_e('Add the connector', domain: 'wppilot'); ?></a>
        </div>

        <div id="wppilot-oauth-deeplink-wrap" style="display:none; margin-bottom:12px;">
            <a
                id="wppilot-oauth-deeplink-btn"
                class="button button-primary"
                style="padding:12px 24px; height:auto; font-size:15px;"
                href="#"
            ><?php esc_html_e('One-click install', domain: 'wppilot'); ?></a>
        </div>

        <div
            id="wppilot-oauth-notice"
            style="display:none; margin:4px 0 0; padding:12px 14px; background:#f0f6fc; border:1px solid #c3d9ed; border-radius:6px; font-size:14px;"
        ></div>

        <div id="wppilot-oauth-name-wrap">
        <p style="margin:8px 0 4px;">
            <button
                type="button"
                class="button-link"
                id="wppilot-oauth-name-toggle"
                aria-expanded="false"
                aria-controls="wppilot-oauth-name-field"
                onclick="wppilotOauthToggleName(this)"
            ><?php esc_html_e('Change server name (optional)', domain: 'wppilot'); ?></button>
        </p>
        <div id="wppilot-oauth-name-field" hidden style="display:none; margin:6px 0 14px;">
            <input
                type="text"
                id="wppilot-oauth-name"
                value="<?php echo esc_attr($default_name); ?>"
                placeholder="<?php echo esc_attr($default_name); ?>"
                maxlength="25"
                style="width:220px;"
                oninput="wppilotOauthUpdateName(this.value)"
            >
            <p class="description" style="margin:6px 0 0;">
                <?php esc_html_e(
                    'Give the server a name you’ll recognize. The steps and snippets below update as you type.',
                    domain: 'wppilot',
                ); ?>
            </p>
            <div id="wppilot-oauth-name-warning" class="notice notice-warning inline" style="display:none; margin:8px 0 0;">
                <p style="margin:0;">
                    <?php esc_html_e(
                        'Maximum 25 characters reached. Required for client compatibility.',
                        domain: 'wppilot',
                    ); ?>
                </p>
            </div>
            <div
                id="wppilot-oauth-name-suggestion"
                class="notice notice-warning inline"
                style="display:none; margin:8px 0 0;"
            >
                <p style="margin:0;">
                    <?php esc_html_e(
                        'Tip: keep "wppilot" in the name so you (and your AI agent) can tell this MCP server apart from others.',
                        domain: 'wppilot',
                    ); ?>
                </p>
            </div>
        </div>
        </div>

        <div id="wppilot-oauth-hint" style="font-size:13px; color:#666; padding:6px 0 0;"></div>

        <div id="wppilot-oauth-manual-btn-wrap" style="display:none;">
            <hr style="border:none; border-top:1px solid #dcdcde; margin:12px 0 8px;">
            <button
                type="button"
                class="button button-secondary"
                id="wppilot-oauth-manual-toggle"
                aria-expanded="false"
                aria-controls="wppilot-oauth-manual"
                onclick="wppilotOauthToggleManual(this)"
            ><?php esc_html_e('Manual configuration', domain: 'wppilot'); ?></button>
        </div>

        <div id="wppilot-oauth-manual" style="display:none; margin-top:14px;">
            <ol
                id="wppilot-oauth-steps"
                style="display:none; list-style-type:lower-alpha; margin:0 0 4px; padding-left:22px;"
            ></ol>
        </div>

        <?php wppilot_render_agent_prompt_block('wppilot-oauth', method: 'oauth'); ?>
    </div>

    <?php
    wppilot_enqueue_agent_prompt_script();
    wppilot_enqueue_admin_script(
        'wppilot-connect-oauth',
        'connect-oauth.js',
        ['wppilot-agent-prompt'],
        'wppilotConnectOauth',
        [
            'configs' => $configs,
            'defaultName' => $default_name,
            'namePlaceholder' => $name_placeholder,
            'clientLabels' => $clients,
            'endpointUrl' => $rest_url,
            'authLine' => wppilot_agent_prompt_auth_line('oauth'),
            'notes' => wppilot_agent_prompt_oauth_notes(),
            'labels' => [
                'manualPrefix' => __('Manual configuration for', domain: 'wppilot'),
                'connectorPrefix' => __('Add the connector to', domain: 'wppilot'),
                'deeplinkPrefix' => __('One-click install in', domain: 'wppilot'),
                'stepOpen' => __('Open your config', domain: 'wppilot'),
                'stepAdd' => __('Add this server', domain: 'wppilot'),
                'stepAddNote' => __(
                    'If your config file already has content, merge this into your existing config instead of replacing it.',
                    domain: 'wppilot',
                ),
                'stepRun' => __('Run this in your terminal', domain: 'wppilot'),
                'copy' => __('Copy', domain: 'wppilot'),
                'copied' => __('Copied!', domain: 'wppilot'),
                'stepSignIn' => __('Sign in', domain: 'wppilot'),
                'stepSignInNote' => __(
                    'The next time your AI client connects, your browser opens so you can authorize it. Approve to finish connecting.',
                    domain: 'wppilot',
                ),
                'stepSignInRestart' => __('Restart and sign in', domain: 'wppilot'),
                'stepSignInRestartNote' => __(
                    'Restart your AI client so it loads the server. On the next start your browser opens to sign in and authorize. Approve to finish.',
                    domain: 'wppilot',
                ),
                'editConfigNote' => __(
                    'In Claude Desktop, open Settings → Developer → Edit Config to open this file.',
                    domain: 'wppilot',
                ),
            ],
        ],
    );
}
