<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The "ask your AI coder to do it" prompt, shared by all three methods.
 *
 * Every connection method ends the same way: a snippet, a file path, and a
 * restart. That is three chances to put the right text in the wrong place — and
 * the people setting this up already have an agent sitting in the editor that
 * owns those files. So each method's configuration section also offers the same
 * thing as a paragraph addressed to that agent: the server name, the URL, the
 * exact snippet, where it goes, and the rules that stop the agent improvising a
 * transport or a port that was never mentioned.
 *
 * The text is assembled in the browser rather than here, because the snippet it
 * quotes changes with the client tab and the server-name field without a page
 * load. This file owns the wording and the markup; each method's own script
 * hands it a context object.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * How the prompt describes the credential, per method.
 *
 * The distinction that matters to an agent writing a config file is whether
 * there is a secret in it. OAuth has none — the client signs in itself — and
 * saying so stops an agent inventing a client id and secret to fill fields it
 * expects to exist.
 */
function wppilot_agent_prompt_auth_line(string $method): string
{
    return match ($method) {
        'oauth' => __(
            'OAuth 2.1. The client runs the browser sign-in itself, so the configuration contains no secret — do not add a client id, client secret, token or password to it.',
            domain: 'wppilot',
        ),
        'token' => __(
            'A GoBuildWP access token, sent as an Authorization: Bearer header. The value is already in the configuration below. Treat it as a secret: never commit it, never echo it back to me in full, and never send it anywhere except this site.',
            domain: 'wppilot',
        ),
        default => __(
            'A WordPress application password, sent as HTTP Basic. The credentials are already in the configuration below. Treat them as a secret: never commit them, never echo them back to me in full, and never send them anywhere except this site.',
            domain: 'wppilot',
        ),
    };
}

/**
 * Behaviour an agent will otherwise treat as a fault, per method.
 *
 * Every one of these is something that looks broken, is not, and costs a round
 * of "fixes" that leave a working connection worse. They are in the prompt for
 * the same reason they are in the on-screen hints: the agent is the one who will
 * see them first.
 *
 * @return list<string>
 */
function wppilot_agent_prompt_token_notes(): array
{
    return [
        __(
            'Some clients show this server as "needs authentication" or offer a sign-in button even after the header is configured. That is a display quirk — this site advertises OAuth metadata for its other connection method, and those clients read that as a requirement. If the tools list, the connection is authenticated. Do not start an OAuth flow to make the label go away.',
            domain: 'wppilot',
        ),
        __(
            'A 401 after this used to work usually means the token expired or was revoked, not that the configuration drifted. Tell me rather than editing the config.',
            domain: 'wppilot',
        ),
    ];
}

/**
 * @return list<string>
 */
function wppilot_agent_prompt_password_notes(): array
{
    return [
        __(
            'This route launches a helper with npx, so the first start downloads a package and is slower than later ones. A client that reports "connected" only proves the helper started — the credentials are not checked until the first real call.',
            domain: 'wppilot',
        ),
        __(
            'A 401 with credentials that are definitely right is usually the web server stripping the Authorization header before PHP sees it, not a wrong password. Say so rather than regenerating credentials.',
            domain: 'wppilot',
        ),
    ];
}

/**
 * @return list<string>
 */
function wppilot_agent_prompt_oauth_notes(): array
{
    return [
        __(
            'The first connection opens a browser window for sign-in. That is expected and cannot be skipped; if you are running without a browser, say so rather than looking for an API key.',
            domain: 'wppilot',
        ),
        __(
            'Access tokens are short-lived and refresh automatically. An occasional re-authorization prompt is normal and is not a misconfiguration.',
            domain: 'wppilot',
        ),
    ];
}

/**
 * Render the prompt box for one method's configuration section.
 *
 * $prefix namespaces the element ids, because more than one of these is rendered
 * on the Connect screen at a time — one per method panel — and a shared id would
 * make one panel's copy button read another panel's prompt.
 */
function wppilot_render_agent_prompt_block(string $prefix, string $method): void
{
    wppilot_enqueue_agent_prompt_script();
    $is_secret = $method !== 'oauth';
    ?>
    <div class="wppilot-agent-prompt" id="<?php echo esc_attr($prefix); ?>-agent-prompt-wrap" style="margin-top:14px;">
        <p style="margin:0 0 6px;">
            <strong><?php esc_html_e('Easier: let your AI coder set this up', domain: 'wppilot'); ?></strong>
            <button
                type="button"
                class="button-link"
                aria-expanded="true"
                aria-controls="<?php echo esc_attr($prefix); ?>-agent-prompt-body"
                onclick="wppilotToggleAgentPrompt('<?php echo esc_js($prefix); ?>', this)"
                style="margin-left:8px;"
            ><?php esc_html_e('Hide', domain: 'wppilot'); ?></button>
        </p>
        <?php /*
         * Open by default. Behind a toggle it was the least discoverable thing on
         * the screen — and it is the shortest path for most people, since the
         * agent they would paste it into is already open next to this page.
         */ ?>
        <div id="<?php echo esc_attr($prefix); ?>-agent-prompt-body">
            <p class="description" style="margin:0 0 8px;">
                <?php esc_html_e(
                    'Paste this into Claude Code, Codex, Cursor or any other agent that can edit your files. It carries the settings shown above, so it stays correct as you change the client or the server name.',
                    domain: 'wppilot',
                ); ?>
            </p>
            <div class="wppilot-config-block">
                <pre id="<?php echo esc_attr($prefix); ?>-agent-prompt"></pre>
                <button
                    type="button"
                    class="button wppilot-copy-btn"
                    onclick="wppilotCopyAgentPrompt('<?php echo esc_js($prefix); ?>', this)"
                ><?php esc_html_e('Copy prompt', domain: 'wppilot'); ?></button>
            </div>
            <?php if ($is_secret): ?>
                <p style="margin:8px 0 0; color:#d63638; font-size:13px;">
                    <?php esc_html_e(
                        'This prompt contains the credential, so pasting it hands that credential to your AI provider. Prefer OAuth, or paste the snippet into the config file yourself, if that matters for this site.',
                        domain: 'wppilot',
                    ); ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * The builder and the toggle (includes/assets/connect-agent-prompt.js), enqueued
 * once no matter how many blocks render.
 *
 * The method panels' own scripts call window.wppilotAgentPrompt() as they start,
 * so they name 'wppilot-agent-prompt' as a dependency and call this first.
 */
function wppilot_enqueue_agent_prompt_script(): void
{
    if (wp_script_is('wppilot-agent-prompt')) {
        return;
    }

    $strings = [
        'intro' => __('Set up this WordPress site as an MCP server in %s.', domain: 'wppilot'),
        'serverName' => __('Server name to use:', domain: 'wppilot'),
        'serverUrl' => __('Server URL:', domain: 'wppilot'),
        'auth' => __('Authentication:', domain: 'wppilot'),
        'runThis' => __('Run exactly this command:', domain: 'wppilot'),
        'writeThis' => __('Use exactly this configuration:', domain: 'wppilot'),
        'writeTo' => __('Write it to the configuration file for this client:', domain: 'wppilot'),
        'uiSteps' => __(
            'This client has no configuration file for MCP servers — it is added through its own interface. Walk me through these steps:',
            domain: 'wppilot',
        ),
        'rules' => __('Rules:', domain: 'wppilot'),
        'ruleExact' => __(
            '1. Use exactly the values above. Do not change the URL, swap the transport, add a port, rename a field, or add fields that are not shown. Anything that looks unusual is deliberate — this configuration was generated by the server being connected to.',
            domain: 'wppilot',
        ),
        'ruleMerge' => __(
            '2. Merge into the existing configuration file; never replace it. If an entry for this server is already there, update that entry instead of adding a second one.',
            domain: 'wppilot',
        ),
        'ruleRestart' => __(
            '3. Restart or reload the MCP session afterwards. Most clients read their configuration only at startup, and skipping this is the most common reason a correct configuration looks broken.',
            domain: 'wppilot',
        ),
        'ruleSecret' => __(
            '4. Do not commit the credential, do not copy it into another file, and do not print it back to me in full.',
            domain: 'wppilot',
        ),
        'ruleReport' => __(
            '5. If it does not connect, show me the client\'s own error output — with the HTTP status if there is one — before changing anything. Do not try other URLs or transports to see what sticks.',
            domain: 'wppilot',
        ),
        'ruleNoAsk' => __('6. Do not ask me to confirm choices already specified above.', domain: 'wppilot'),
        'verify' => __('Then verify, and tell me what you found:', domain: 'wppilot'),
        'verifyTools' => __(
            '- List this server\'s tools. A working connection returns a long list of names beginning with wppilot_. An empty list means the connection is up but the site is exposing nothing, which is a different problem and not a configuration error.',
            domain: 'wppilot',
        ),
        'verifyCall' => __(
            '- Then call the discover-abilities tool once. It is read-only, and its answer names the safety profile the site is running — which is what decides how much you are allowed to do there.',
            domain: 'wppilot',
        ),
        'notesTitle' => __('Known quirks, so you do not chase them:', domain: 'wppilot'),
        'copied' => __('Copied!', domain: 'wppilot'),
        'show' => __('Show', domain: 'wppilot'),
        'hide' => __('Hide', domain: 'wppilot'),
    ];

    wppilot_enqueue_admin_script(
        'wppilot-agent-prompt',
        'connect-agent-prompt.js',
        data_var: 'wppilotAgentPromptStrings',
        data: $strings,
    );
}
