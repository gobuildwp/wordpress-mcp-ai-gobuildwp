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
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wppilot_script_json() hex-escapes for script context.
    $configs_json = (string) wppilot_script_json($configs);

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

    <?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Every value emitted in this block goes through wppilot_script_json(), which hex-escapes <, >, & and quotes for <script> context. Plugin Check cannot recognise a project-local escaper. ?>
    <script>
    (function () {
        var configs = <?php

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wppilot_script_json() hex-escapes for script context.
        echo $configs_json; ?>;
        var client = '';
        var defaultName = <?php

        echo wppilot_script_json($default_name); ?>;
        var mcpName = defaultName;
        var namePlaceholder = <?php

        echo wppilot_script_json($name_placeholder); ?>;
        var clientLabels = <?php

        echo wppilot_script_json($clients); ?>;
        var oauthEndpointUrl = <?php

        echo wppilot_script_json($rest_url); ?>;
        var oauthAuthLine = <?php

        echo wppilot_script_json(wppilot_agent_prompt_auth_line('oauth')); ?>;
        var oauthNotes = <?php

        echo wppilot_script_json(wppilot_agent_prompt_oauth_notes()); ?>;
        var manualLabelPrefix = <?php

        echo wppilot_script_json(__('Manual configuration for', domain: 'wppilot')); ?>;
        var connectorLabelPrefix = <?php

        echo wppilot_script_json(__('Add the connector to', domain: 'wppilot')); ?>;
        var deeplinkLabelPrefix = <?php

        echo wppilot_script_json(__('One-click install in', domain: 'wppilot')); ?>;
        var stepOpenLabel = <?php

        echo wppilot_script_json(__('Open your config', domain: 'wppilot')); ?>;
        var stepAddLabel = <?php

        echo wppilot_script_json(__('Add this server', domain: 'wppilot')); ?>;
        var stepAddNote = <?php echo
            wppilot_script_json(__(
                'If your config file already has content, merge this into your existing config instead of replacing it.',
                domain: 'wppilot',
            ))
        ; ?>;
        var stepRunLabel = <?php

        echo wppilot_script_json(__('Run this in your terminal', domain: 'wppilot')); ?>;
        var copyLabel = <?php

        echo wppilot_script_json(__('Copy', domain: 'wppilot')); ?>;
        var copiedLabel = <?php

        echo wppilot_script_json(__('Copied!', domain: 'wppilot')); ?>;
        var stepSignInLabel = <?php

        echo wppilot_script_json(__('Sign in', domain: 'wppilot')); ?>;
        var stepSignInNote = <?php echo
            wppilot_script_json(__(
                'The next time your AI client connects, your browser opens so you can authorize it. Approve to finish connecting.',
                domain: 'wppilot',
            ))
        ; ?>;
        var stepSignInRestartLabel = <?php

        echo wppilot_script_json(__('Restart and sign in', domain: 'wppilot')); ?>;
        var stepSignInRestartNote = <?php echo
            wppilot_script_json(__(
                'Restart your AI client so it loads the server. On the next start your browser opens to sign in and authorize. Approve to finish.',
                domain: 'wppilot',
            ))
        ; ?>;
        var editConfigNote = <?php echo
            wppilot_script_json(__(
                'In Claude Desktop, open Settings → Developer → Edit Config to open this file.',
                domain: 'wppilot',
            ))
        ; ?>;

        var manualOpen = false;

        function setDisplay(id, show) {
            document.getElementById(id).style.display = show ? '' : 'none';
        }

        function esc(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function withName(s) {
            return esc(String(s).split(namePlaceholder).join(mcpName));
        }

        // Config-file clients: turn the snippet + its file locations into a two-step guide (open the
        // config at its real path, then paste the snippet). Connector clients ship explicit steps.
        function signInStep(needsRestart) {
            return needsRestart
                ? { title: stepSignInRestartLabel, body: stepSignInRestartNote }
                : { title: stepSignInLabel, body: stepSignInNote };
        }

        function buildConfigSteps(cfg) {
            if (cfg.isShell) {
                return [{ title: stepRunLabel, code: cfg.code }, signInStep(false)];
            }
            var steps = [];
            var keys = cfg.paths ? Object.keys(cfg.paths) : [];
            if (keys.length) {
                var bodyHtml = keys.map(function (label) {
                    return '<code>' + esc(cfg.paths[label]) + '</code> (' + esc(label) + ')';
                }).join('<br>');
                // Claude Desktop opens this exact file from its own UI, so point there first.
                if (client === 'claude-desktop') {
                    bodyHtml = esc(editConfigNote) + '<br>' + bodyHtml;
                }
                steps.push({ title: stepOpenLabel, bodyHtml: bodyHtml });
            }
            var addStep = { title: stepAddLabel, body: stepAddNote, code: cfg.code };
            if (cfg.note) { addStep.noteHtml = cfg.note; }
            steps.push(addStep);
            steps.push(signInStep(true));
            return steps;
        }

        function renderSteps(steps) {
            var html = '';
            steps.forEach(function (s) {
                html += '<li style="margin:0 0 12px;"><strong>' + esc(s.title) + '</strong>';
                if (s.bodyHtml) {
                    html += '<div style="margin-top:2px;">' + s.bodyHtml + '</div>';
                } else if (s.body) {
                    html += '<div style="margin-top:2px;">' + withName(s.body) + '</div>';
                }
                if (s.copy) {
                    html +=
                        '<div style="margin-top:6px;">' +
                        '<span style="display:inline-flex; align-items:center; gap:10px; max-width:100%; ' +
                        'background:#f6f7f7; border:1px solid #dcdcde; border-radius:6px; padding:5px 6px 5px 12px;">' +
                        '<span style="font-weight:600; word-break:break-all;">' +
                        withName(s.copy) +
                        '</span><button type="button" class="button button-small" style="flex:none;" ' +
                        'onclick="wppilotOauthCopyChip(this)">' +
                        esc(copyLabel) +
                        '</button></span></div>';
                }
                if (s.code) {
                    html +=
                        '<div class="wppilot-config-block" style="margin-top:6px;">' +
                        '<pre>' + withName(s.code) + '</pre>' +
                        '<button type="button" class="button wppilot-copy-btn" onclick="wppilotOauthCopyStep(this)">' +
                        esc(copyLabel) +
                        '</button></div>';
                }
                if (s.noteHtml) {
                    html +=
                        '<div style="margin-top:6px; color:#646970; font-size:13px;">' +
                        s.noteHtml.split(namePlaceholder).join(esc(mcpName)) +
                        '</div>';
                }
                html += '</li>';
            });
            document.getElementById('wppilot-oauth-steps').innerHTML = html;
        }

        function render() {
            if (!client) { return; }
            var cfg = configs[client];
            if (!cfg) { return; }

            // A message-only client (e.g. a cloud client on a local site): show the explanation and
            // hide every interactive part, including the server-name field.
            var isNotice = cfg.kind === 'notice';
            setDisplay('wppilot-oauth-notice', isNotice);
            setDisplay('wppilot-oauth-name-wrap', !isNotice);
            if (isNotice) {
                document.getElementById('wppilot-oauth-notice').textContent = cfg.message || '';
                setDisplay('wppilot-oauth-connector-wrap', false);
                setDisplay('wppilot-oauth-deeplink-wrap', false);
                setDisplay('wppilot-oauth-hint', false);
                setDisplay('wppilot-oauth-steps', false);
                setDisplay('wppilot-oauth-manual-btn-wrap', false);
                setDisplay('wppilot-oauth-manual', false);
                setDisplay('wppilot-oauth-agent-prompt-wrap', false);
                return;
            }

            var isConnector = cfg.kind === 'connector';
            var hasDeeplink = !!cfg.deeplink;
            var hasSteps = !!(cfg.steps && cfg.steps.length);
            var hasCode = !!cfg.code;
            var hasManual = hasSteps || hasCode;
            var hasPrimary = isConnector || hasDeeplink;

            var label = clientLabels[client] || '';
            setDisplay('wppilot-oauth-connector-wrap', isConnector);
            if (isConnector) {
                var connBtn = document.getElementById('wppilot-oauth-connector-btn');
                connBtn.setAttribute('href', cfg.connector);
                connBtn.textContent = connectorLabelPrefix + ' ' + label;
            }

            setDisplay('wppilot-oauth-deeplink-wrap', hasDeeplink);
            if (hasDeeplink) {
                var dl = cfg.deeplink.split(namePlaceholder).join(encodeURIComponent(mcpName));
                var dlBtn = document.getElementById('wppilot-oauth-deeplink-btn');
                dlBtn.setAttribute('href', dl);
                dlBtn.textContent = deeplinkLabelPrefix + ' ' + label;
            }

            // The hint describes the OAuth connection method, so it only belongs with the one-click
            // primaries (connector / deeplink). For manual config-file clients the steps cover it.
            var showHint = hasPrimary && cfg.hint;
            var hintEl = document.getElementById('wppilot-oauth-hint');
            hintEl.innerHTML = showHint ? cfg.hint : '';
            hintEl.style.display = showHint ? '' : 'none';

            setDisplay('wppilot-oauth-steps', hasManual);
            if (hasSteps) {
                renderSteps(cfg.steps);
            } else if (hasCode) {
                renderSteps(buildConfigSteps(cfg));
            }

            setDisplay('wppilot-oauth-agent-prompt-wrap', true);
            window.wppilotAgentPrompt('wppilot-oauth', {
                clientLabel: label || client,
                serverName: mcpName,
                url: oauthEndpointUrl,
                authLine: oauthAuthLine,
                code: hasCode ? cfg.code.split(namePlaceholder).join(mcpName) : '',
                paths: cfg.paths || {},
                isShell: !!cfg.isShell,
                steps: hasSteps ? cfg.steps.map(function (step) {
                    var body = (step.body || '').replace(/<[^>]+>/g, '');
                    return step.copy ? body + ' ' + step.copy.split(namePlaceholder).join(mcpName) : body;
                }) : null,
                hasSecret: false,
                notes: oauthNotes
            });

            // The manual guide is a fallback behind a toggle when there is a one-click primary
            // (connector or deeplink); with no primary it is the only way in, so show it directly.
            setDisplay('wppilot-oauth-manual-btn-wrap', hasManual && hasPrimary);
            setDisplay('wppilot-oauth-manual', hasManual && (!hasPrimary || manualOpen));
            document.getElementById('wppilot-oauth-manual-toggle')
                .setAttribute('aria-expanded', manualOpen ? 'true' : 'false');
        }

        // Open on the first client instead of an empty panel. Everything under
        // the step 2 heading — the steps, the hint, the "let your AI coder do it"
        // prompt — lives inside #wppilot-oauth-content, which stayed display:none
        // until a tab was clicked. The heading was therefore followed by a row of
        // tabs and nothing else, which reads as "this method has no instructions".
        function selectFirstOauthClient() {
            var first = document.querySelector('.wppilot-oauth-tab');
            if (first) {
                window.wppilotOauthSetClient(first.getAttribute('data-client'), first);
            }
        }

        window.wppilotOauthSetClient = function (key, btn) {
            client = key;
            manualOpen = false;
            document.querySelectorAll('.wppilot-oauth-tab').forEach(function (t) { t.classList.remove('active'); });
            btn.classList.add('active');
            if (clientLabels[key]) {
                document.getElementById('wppilot-oauth-manual-toggle').textContent =
                    manualLabelPrefix + ' ' + clientLabels[key];
            }
            document.getElementById('wppilot-oauth-content').style.display = '';
            render();
        };

        window.wppilotOauthToggleManual = function (btn) {
            manualOpen = !manualOpen;
            btn.setAttribute('aria-expanded', manualOpen ? 'true' : 'false');
            setDisplay('wppilot-oauth-manual', manualOpen);
        };

        function updateOauthNameWarning(value) {
            document.getElementById('wppilot-oauth-name-warning').style.display = value.length >= 25 ? 'block' : 'none';
            var trimmed = value.trim();
            var missing = trimmed.length > 0 && trimmed.toLowerCase().indexOf('wppilot') === -1;
            document.getElementById('wppilot-oauth-name-suggestion').style.display = missing ? 'block' : 'none';
        }

        window.wppilotOauthUpdateName = function (value) {
            mcpName = value.trim() || defaultName;
            updateOauthNameWarning(value);
            render();
        };

        window.wppilotOauthToggleName = function (btn) {
            var field = document.getElementById('wppilot-oauth-name-field');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            field.style.display = expanded ? 'none' : 'block';
            field.hidden = expanded;
            btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        };

        window.wppilotOauthCopyStep = function (btn) {
            var pre = btn.previousElementSibling;
            if (!pre) { return; }
            window.wppilotClipboardCopy(pre.textContent).then(function () {
                var orig = btn.textContent;
                btn.textContent = copiedLabel;
                setTimeout(function () { btn.textContent = orig; }, 1500);
            });
        };

        window.wppilotOauthCopyChip = function (btn) {
            var value = btn.previousElementSibling;
            if (!value) { return; }
            window.wppilotClipboardCopy(value.textContent).then(function () {
                var orig = btn.textContent;
                btn.textContent = copiedLabel;
                setTimeout(function () { btn.textContent = orig; }, 1500);
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', selectFirstOauthClient);
        } else {
            selectFirstOauthClient();
        }
    }());
    </script>
    <?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php
}
