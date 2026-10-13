<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Configuration page: generated client configuration for the Access token method.
 *
 * One credential, one URL, one header — so unlike the application-password
 * method there is nothing to install for almost every client. The exception is
 * Claude Desktop, whose config file is documented for local stdio servers only,
 * so the header rides on the mcp-remote bridge there. What differs between
 * clients is mostly where the header goes, and the field names disagree more
 * than they should: VS Code nests servers under `servers`, most others under
 * `mcpServers`, OpenCode and Kilo under `mcp`, OpenClaw under `mcp.servers`;
 * Antigravity and Devin Desktop call the URL `serverUrl`; Cline spells the
 * transport `streamableHttp` where Roo spells it `streamable-http` and OpenClaw
 * wants `transport: "streamable-http"`; Codex uses TOML with `http_headers`.
 * Each builder below writes the shape that client actually parses, verified
 * against its own documentation rather than assumed from the others.
 *
 * Every builder returns a string; nothing in this file echoes, so the same value
 * can be rendered, copied, or handed to JavaScript.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The placeholder shown until a token has been minted.
 *
 * Deliberately not a plausible-looking token: it must be obvious in a config file
 * that it was never replaced.
 */
const WPPILOT_TOKEN_PLACEHOLDER = 'wpp_YOUR-ACCESS-TOKEN';

/**
 * The Authorization header value for a token.
 */
function wppilot_token_auth_header(string $token): string
{
    return 'Bearer ' . $token;
}

/**
 * JSON with the options every snippet on this page shares.
 *
 * @param array<string, mixed> $data
 */
function wppilot_token_json(array $data): string
{
    return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * The `mcpServers` shape most clients read: `type`, `url`, `headers`.
 *
 * @param string $type Transport spelling this client expects.
 */
function wppilot_token_mcp_servers_json(string $name, string $url, string $token, string $type = 'http'): string
{
    return wppilot_token_json([
        'mcpServers' => [
            $name => [
                'type' => $type,
                'url' => $url,
                'headers' => ['Authorization' => wppilot_token_auth_header($token)],
            ],
        ],
    ]);
}

/**
 * The `serverUrl` shape: Antigravity and Devin Desktop (Windsurf) reject `url`.
 */
function wppilot_token_server_url_json(string $name, string $url, string $token): string
{
    return wppilot_token_json([
        'mcpServers' => [
            $name => [
                'serverUrl' => $url,
                'headers' => ['Authorization' => wppilot_token_auth_header($token)],
            ],
        ],
    ]);
}

/**
 * The `httpUrl` shape: Gemini CLI and Qwen Code reject `url` for a remote server.
 *
 * Shared lineage, shared quirk. A snippet copied from any other client's
 * documentation parses cleanly here and then connects to nothing, which is the
 * worst kind of wrong — so it is written out rather than folded into the common
 * builder.
 */
function wppilot_token_http_url_json(string $name, string $url, string $token): string
{
    return wppilot_token_json([
        'mcpServers' => [
            $name => [
                'httpUrl' => $url,
                'headers' => ['Authorization' => wppilot_token_auth_header($token)],
            ],
        ],
    ]);
}

/**
 * Codex reads TOML, and puts request headers under `http_headers`.
 */
function wppilot_token_codex_toml(string $name, string $url, string $token): string
{
    $esc = static fn(string $value): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';

    return implode("\n", [
        '[mcp_servers.' . $name . ']',
        'url = ' . $esc($url),
        '',
        '[mcp_servers.' . $name . '.http_headers]',
        'Authorization = ' . $esc(wppilot_token_auth_header($token)),
    ]);
}

/**
 * Claude Code takes the whole server in one command.
 *
 * `--scope user` so the server follows the user across projects rather than
 * landing in whichever repository happened to be open.
 */
function wppilot_token_claude_code_cmd(string $name, string $url, string $token): string
{
    $sq = static fn(string $value): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $value) . "'";

    return implode(" \\\n  ", [
        'claude mcp add --transport http ' . $sq($name) . ' ' . $sq($url),
        '--header ' . $sq('Authorization: ' . wppilot_token_auth_header($token)),
        '--scope user',
    ]);
}

/**
 * Droid's own command, with OAuth discovery switched off.
 *
 * `--no-oauth` matters here specifically: this site advertises OAuth metadata at
 * /.well-known/oauth-authorization-server for the OAuth method, and a client that
 * finds it will try to start a browser sign-in even though the header it was
 * given already authenticates every call.
 */
function wppilot_token_droid_cmd(string $name, string $url, string $token): string
{
    $sq = static fn(string $value): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $value) . "'";

    return implode(" \\\n  ", [
        'droid mcp add ' . $sq($name) . ' ' . $sq($url),
        '--type http',
        '--header ' . $sq('Authorization: ' . wppilot_token_auth_header($token)),
        '--no-oauth',
    ]);
}

/**
 * Zed connects to a remote server natively: `url` plus `headers` under
 * `context_servers`. The old `source: "custom"` marker is gone from its docs,
 * and with a header set Zed skips its own OAuth prompt.
 *
 * Source, checked 2026-09-30: https://zed.dev/docs/ai/mcp
 */
function wppilot_token_zed_json(string $name, string $url, string $token): string
{
    return wppilot_token_json([
        'context_servers' => [
            $name => [
                'url' => $url,
                'headers' => ['Authorization' => wppilot_token_auth_header($token)],
            ],
        ],
    ]);
}

/**
 * OpenCode and Kilo Code share a format: servers under `mcp`, the remote
 * transport named `remote`. `oauth: false` is what both document for a server
 * authenticated by a header — it stops either client starting a browser sign-in
 * because this site also advertises OAuth metadata.
 *
 * Sources, checked 2026-09-30: https://opencode.ai/docs/mcp-servers/ and
 * https://kilo.ai/docs/automate/mcp/using-in-kilo-code
 */
function wppilot_token_opencode_json(string $name, string $url, string $token): string
{
    return wppilot_token_json([
        'mcp' => [
            $name => [
                'type' => 'remote',
                'url' => $url,
                'enabled' => true,
                'oauth' => false,
                'headers' => ['Authorization' => wppilot_token_auth_header($token)],
            ],
        ],
    ]);
}

/**
 * Claude Desktop's config file is documented for local stdio servers; remote
 * servers are added as custom connectors, which connect from Anthropic's cloud.
 * So a token for the desktop app goes through the mcp-remote bridge, which runs
 * locally and therefore also reaches a site that is not on the public internet.
 *
 * The header travels as `Authorization:${AUTH_HEADER}` with the value in env:
 * mcp-remote's README warns that Claude Desktop on Windows does not escape spaces
 * inside `args`, which would split "Bearer <token>" in two.
 *
 * Sources, checked 2026-09-30:
 * https://modelcontextprotocol.io/docs/develop/connect-local-servers
 * https://modelcontextprotocol.io/docs/develop/connect-remote-servers
 * https://github.com/geelen/mcp-remote (README, "--header")
 */
function wppilot_token_claude_desktop_json(string $name, string $url, string $token): string
{
    $env = ['AUTH_HEADER' => wppilot_token_auth_header($token)];
    if (wppilot_likely_self_signed_https()) {
        $env['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
    }

    return wppilot_token_json([
        'mcpServers' => [
            $name => [
                'command' => 'npx',
                'args' => ['-y', 'mcp-remote', $url, '--header', 'Authorization:${AUTH_HEADER}'],
                'env' => $env,
            ],
        ],
    ]);
}

/**
 * Copilot CLI: `mcpServers`, `type: "http"`, and a required `tools` list. It
 * rejects the `servers` key VS Code uses.
 *
 * Sources, checked 2026-09-30:
 * https://docs.github.com/en/copilot/how-tos/copilot-cli/customize-copilot/add-mcp-servers
 * https://docs.github.com/en/copilot/reference/copilot-cli-reference/cli-command-reference
 */
function wppilot_token_copilot_cli_json(string $name, string $url, string $token): string
{
    return wppilot_token_json([
        'mcpServers' => [
            $name => [
                'type' => 'http',
                'url' => $url,
                'headers' => ['Authorization' => wppilot_token_auth_header($token)],
                'tools' => ['*'],
            ],
        ],
    ]);
}

/**
 * Kimi Code CLI: an entry with `url` and no `transport` is Streamable HTTP;
 * static headers go in `headers`.
 *
 * Source, checked 2026-09-30:
 * https://moonshotai.github.io/kimi-code/en/customization/mcp.html
 */
function wppilot_token_kimi_json(string $name, string $url, string $token): string
{
    return wppilot_token_json([
        'mcpServers' => [
            $name => [
                'url' => $url,
                'headers' => ['Authorization' => wppilot_token_auth_header($token)],
            ],
        ],
    ]);
}

/**
 * OpenClaw reads `mcp.servers` from ~/.openclaw/openclaw.json. Without
 * `transport: "streamable-http"` a remote entry is treated as SSE.
 *
 * Source, checked 2026-09-30: https://docs.openclaw.ai/cli/mcp/transports
 */
function wppilot_token_openclaw_json(string $name, string $url, string $token): string
{
    return wppilot_token_json([
        'mcp' => [
            'servers' => [
                $name => [
                    'url' => $url,
                    'transport' => 'streamable-http',
                    'headers' => ['Authorization' => wppilot_token_auth_header($token)],
                ],
            ],
        ],
    ]);
}

/**
 * Claude's Messages API MCP connector: the reason this credential exists.
 *
 * The connector takes a URL and a bearer token and runs the MCP client itself,
 * inside Anthropic's infrastructure — which is also why this site must be
 * reachable over public HTTPS for it to work.
 */
function wppilot_token_anthropic_api_snippet(string $name, string $url, string $token): string
{
    $payload = wppilot_token_json([
        'model' => 'claude-opus-5',
        'max_tokens' => 1024,
        'messages' => [['role' => 'user', 'content' => 'List the abilities this WordPress site exposes.']],
        'mcp_servers' => [
            ['type' => 'url', 'url' => $url, 'name' => $name, 'authorization_token' => $token],
        ],
        'tools' => [['type' => 'mcp_toolset', 'mcp_server_name' => $name]],
    ]);

    return implode("\n", [
        'curl https://api.anthropic.com/v1/messages \\',
        '  -H "content-type: application/json" \\',
        '  -H "x-api-key: $ANTHROPIC_API_KEY" \\',
        '  -H "anthropic-version: 2023-06-01" \\',
        '  -H "anthropic-beta: mcp-client-2025-11-20" \\',
        "  -d '" . $payload . "'",
    ]);
}

/**
 * OpenAI's Responses API takes the same idea with different field names: the
 * server is a tool, and the token goes in `authorization` rather than a header
 * map.
 */
function wppilot_token_openai_api_snippet(string $name, string $url, string $token): string
{
    $payload = wppilot_token_json([
        'model' => 'gpt-5.2',
        'input' => 'List the abilities this WordPress site exposes.',
        'tools' => [
            [
                'type' => 'mcp',
                'server_label' => $name,
                'server_url' => $url,
                'authorization' => $token,
                'require_approval' => 'always',
            ],
        ],
    ]);

    return implode("\n", [
        'curl https://api.openai.com/v1/responses \\',
        '  -H "content-type: application/json" \\',
        '  -H "authorization: Bearer $OPENAI_API_KEY" \\',
        "  -d '" . $payload . "'",
    ]);
}

/**
 * The protocol itself, with nothing in between.
 *
 * Useful as the first thing to run when a client will not connect: it separates
 * "the token is wrong" from "the client is misconfigured", and it is the shape
 * anything scripted against this endpoint will send. `_meta` carries the
 * per-request protocol version and client capabilities the 2026-07-28 revision
 * requires on every call.
 */
function wppilot_token_curl_snippet(string $url, string $token): string
{
    $payload = wppilot_token_json([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => [
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => (object) [],
            ],
        ],
    ]);

    return implode("\n", [
        'curl ' . $url . ' \\',
        '  -H "content-type: application/json" \\',
        '  -H "authorization: ' . wppilot_token_auth_header($token) . '" \\',
        '  -H "mcp-protocol-version: 2026-07-28" \\',
        '  -H "mcp-method: tools/list" \\',
        "  -d '" . $payload . "'",
    ]);
}

/**
 * The "add to <file>" hint, with what success looks like.
 *
 * Naming the file was never the hard part. The two things that actually go wrong
 * — not restarting, and treating a saved config as a working connection — are
 * what the sentence has to cover, and they are the same for every client here.
 */
function wppilot_token_add_to(): string
{
    /* translators: %s: config file name wrapped in <code> tags */
    return __(
        'Add to %s, then restart the client. No sign-in step follows: the header authenticates every call, so the next thing you should see is this server\'s tools.',
        domain: 'wppilot',
    );
}

/**
 * One config entry.
 *
 * @param array<string, string> $paths
 * @return array{code: string, hint: string, paths: array<string, string>, isShell: bool, steps: list<string>}
 */
function wppilot_token_entry(string $code, string $hint, array $paths = [], bool $is_shell = false): array
{
    return ['code' => $code, 'hint' => $hint, 'paths' => $paths, 'isShell' => $is_shell, 'steps' => []];
}

/**
 * A client configured through its own interface rather than a file.
 *
 * The hosted web UIs have no snippet to paste — the token goes into a field in a
 * dialog — so they carry an ordered list of what to click instead. Same entry
 * shape either way, so the renderer and the agent prompt need no special case
 * beyond choosing which of the two to show.
 *
 * @param list<string> $steps
 * @return array{code: string, hint: string, paths: array<string, string>, isShell: bool, steps: list<string>}
 */
function wppilot_token_ui_entry(array $steps, string $hint): array
{
    return ['code' => '', 'hint' => $hint, 'paths' => [], 'isShell' => false, 'steps' => $steps];
}

/**
 * The hosted web UIs that can store a fixed Authorization header.
 *
 * This is the newer half of the story: a year ago a browser UI meant OAuth or
 * nothing. claude.ai, Le Chat and Perplexity now each keep a header per
 * connector, which is what lets an access token work in a browser at all —
 * useful when a site's OAuth flow cannot be completed, or when one shared
 * service-account credential is wanted rather than a sign-in per person.
 *
 * ChatGPT is absent: developer mode offers OAuth or no authentication and has no
 * header field, so a token has nowhere to go there. Manus is present because its
 * custom-MCP documentation lists a Bearer token among the credentials a custom
 * server can take (https://manus.im/docs/integrations/custom-mcp, checked
 * 2026-09-30); that page does not name the dialog's fields, so the steps stay
 * general about them.
 *
 * @return array<string, array{code: string, hint: string, paths: array<string, string>, isShell: bool, steps: list<string>}>
 */
function wppilot_build_token_web_ui_configs(string $url, string $token): array
{
    $header = wppilot_token_auth_header($token);

    return [
        'claude-web' => wppilot_token_ui_entry(
            [
                __('Open Customize, then Connectors, then Add custom connector.', domain: 'wppilot'),
                sprintf(
                    /* translators: %s: the MCP endpoint URL */
                    __('Enter %s as the remote MCP server URL.', domain: 'wppilot'),
                    $url,
                ),
                __(
                    'Open the Request headers section, choose the Authorization header, and paste the value below. Enter it in full, including the word Bearer and the space — Claude sends the value exactly as typed and adds no scheme of its own.',
                    domain: 'wppilot',
                ),
                $header,
                __(
                    'Leave the OAuth Client ID and Secret empty, save, then enable the connector for a conversation from the chat "+" menu.',
                    domain: 'wppilot',
                ),
            ],
            __(
                'Request-header authentication on claude.ai is in beta and is being rolled out gradually — if the Request headers section is not in your Add custom connector dialog yet, use the OAuth method instead. On a Team or Enterprise plan an Owner adds the connector under Organization settings first.',
                domain: 'wppilot',
            ),
        ),
        'mistral-lechat' => wppilot_token_ui_entry(
            [
                __(
                    'Open Connectors, press Add Connector, and switch to the Custom MCP Connector tab.',
                    domain: 'wppilot',
                ),
                __(
                    'Name it — the name is an identifier, so no spaces or punctuation — and paste the server URL:',
                    domain: 'wppilot',
                ),
                $url,
                __(
                    'Choose HTTP Bearer Token as the authentication method and paste the token itself, without the word Bearer: Le Chat adds the scheme.',
                    domain: 'wppilot',
                ),
                $token,
                __('Press Connect, then enable the connector from the tools dropdown in a chat.', domain: 'wppilot'),
            ],
            __(
                'Le Chat also accepts Basic authentication, which is how the application-password method connects there.',
                domain: 'wppilot',
            ),
        ),
        'perplexity' => wppilot_token_ui_entry(
            [
                __(
                    'Click your profile picture at the bottom left, open Settings, and go to Connectors.',
                    domain: 'wppilot',
                ),
                __('Add a custom connector and paste the server URL:', domain: 'wppilot'),
                $url,
                __(
                    'Where it asks how the server authenticates, choose the bearer-token option and paste the token:',
                    domain: 'wppilot',
                ),
                $token,
                __('Save, then enable the connector in the conversation you want to use it in.', domain: 'wppilot'),
            ],
            __(
                'Needs Pro, Max or Enterprise, and an HTTPS URL. On a team plan you can keep the connector private or share it with the organization.',
                domain: 'wppilot',
            ),
        ),
        'manus' => wppilot_token_ui_entry(
            [
                __(
                    'In Manus, open Settings and add a custom MCP server (Connectors, then Custom MCP, in current versions).',
                    domain: 'wppilot',
                ),
                __('Paste the server URL:', domain: 'wppilot'),
                $url,
                __('For authentication choose the Bearer token option and paste the token:', domain: 'wppilot'),
                $token,
                __(
                    'If the form asks for a request header instead, name it Authorization and give it this value:',
                    domain: 'wppilot',
                ),
                $header,
                __('Save. Manus checks it can reach the server and lists the tools it found.', domain: 'wppilot'),
            ],
            __(
                'Manus connects from its own servers, so this site must be reachable over public HTTPS.',
                domain: 'wppilot',
            ),
        ),
    ];
}

/**
 * Every access-token config snippet, keyed the same way as the client registry so
 * the tab strip can be built from wppilot_selectable_clients().
 *
 * The hosted web UIs that can store a fixed credential — claude.ai, Mistral Le
 * Chat, Perplexity, Manus — appear here as click-through steps rather than a
 * snippet, because that is how a token is entered there. ChatGPT and the Codex
 * desktop app are absent on purpose: their connector dialogs offer OAuth or no
 * authentication and have no header field, so a token has nowhere to go, and
 * showing one would be the page inventing a route that does not exist.
 *
 * @return array<string, array{code: string, hint: string, paths: array<string, string>, isShell: bool}>
 */
function wppilot_build_token_configs(string $url, string $name, string $token): array
{
    $add_to = wppilot_token_add_to();
    $standard = wppilot_token_mcp_servers_json($name, $url, $token);

    return array_merge(
        [
            'claude-code' => wppilot_token_entry(
                wppilot_token_claude_code_cmd($name, $url, $token),
                // A server whose Authorization header you configured is never
                // flagged "needs authentication": a good token shows connected, a
                // bad one "failed" with the HTTP status. Source, checked
                // 2026-09-30: https://code.claude.com/docs/en/mcp
                __(
                    'Run in your terminal, then restart Claude Code. Run /mcp to check: the server should read connected. If it reads failed, the detail shows the HTTP status — a 401 means the token is wrong or revoked.',
                    domain: 'wppilot',
                ),
                is_shell: true,
            ),
            'claude-desktop' => wppilot_token_entry(
                wppilot_token_claude_desktop_json($name, $url, $token),
                sprintf(
                    /* translators: %s: config file name wrapped in <code> tags */
                    __(
                        'Add to %s, then fully quit and reopen Claude Desktop. This file runs local servers only, so the token goes through the mcp-remote bridge (needs Node.js). On a public HTTPS site you can instead add a custom connector under Settings, Connectors — see the Claude (web) steps.',
                        domain: 'wppilot',
                    ),
                    '<code>claude_desktop_config.json</code>',
                ),
                [
                    'macOS' => '~/Library/Application Support/Claude/claude_desktop_config.json',
                    'Windows' => '%APPDATA%\\Claude\\claude_desktop_config.json',
                ],
            ),
            'codex' => wppilot_token_entry(
                wppilot_token_codex_toml($name, $url, $token),
                sprintf($add_to, '<code>config.toml</code>'),
                [
                    'macOS / Linux' => '~/.codex/config.toml',
                    'Windows' => '%USERPROFILE%\\.codex\\config.toml',
                ],
            ),
            'cursor' => wppilot_token_entry($standard, sprintf($add_to, '<code>mcp.json</code>'), [
                __('Global', domain: 'wppilot') => '~/.cursor/mcp.json',
                __('Project', domain: 'wppilot') => '.cursor/mcp.json',
            ]),
            'vscode' => wppilot_token_entry(
                wppilot_token_json([
                    'servers' => [
                        $name => [
                            'type' => 'http',
                            'url' => $url,
                            'headers' => ['Authorization' => wppilot_token_auth_header($token)],
                        ],
                    ],
                ]),
                sprintf(
                    /* translators: %s: config file name wrapped in <code> tags */
                    __('Add to %s. VS Code nests servers under "servers", not "mcpServers".', domain: 'wppilot'),
                    '<code>mcp.json</code>',
                ),
                [
                    __('Workspace', domain: 'wppilot') => '.vscode/mcp.json',
                    __('User', domain: 'wppilot') => __(
                        'Run: MCP: Open User Configuration (command palette)',
                        domain: 'wppilot',
                    ),
                ],
            ),
            'github-copilot' => wppilot_token_entry(
                wppilot_token_copilot_cli_json($name, $url, $token),
                sprintf(
                    /* translators: %s: config file name wrapped in <code> tags */
                    __(
                        'For Copilot CLI: add to %s, or run copilot mcp add. Copilot Chat in VS Code reads the VS Code configuration instead — use the VS Code tab.',
                        domain: 'wppilot',
                    ),
                    '<code>mcp-config.json</code>',
                ),
                wppilot_copilot_cli_paths(),
            ),
            'factory-droid' => wppilot_token_entry(
                wppilot_token_droid_cmd($name, $url, $token),
                __(
                    '--no-oauth keeps Droid from starting a browser sign-in it does not need: the header already authenticates the connection.',
                    domain: 'wppilot',
                ),
                is_shell: true,
            ),
            'windsurf' => wppilot_token_entry(
                wppilot_token_server_url_json($name, $url, $token),
                sprintf(
                    /* translators: %s: config file name wrapped in <code> tags */
                    __(
                        'Add to %s — the Cascade panel\'s … menu, then Open MCP config file, opens the one your install reads. Remote servers use "serverUrl".',
                        domain: 'wppilot',
                    ),
                    '<code>mcp_config.json</code>',
                ),
                wppilot_devin_desktop_paths(),
            ),
            'antigravity-cli' => wppilot_token_entry(
                wppilot_token_server_url_json($name, $url, $token),
                sprintf(
                    /* translators: %s: config file name wrapped in <code> tags */
                    __(
                        'Add to %s. Antigravity expects "serverUrl"; "url" and "httpUrl" are ignored.',
                        domain: 'wppilot',
                    ),
                    '<code>mcp_config.json</code>',
                ),
                [
                    __('Global', domain: 'wppilot') => '~/.gemini/config/mcp_config.json',
                    __('Workspace', domain: 'wppilot') => '.agents/mcp_config.json',
                ],
            ),
            'antigravity-ide' => wppilot_token_entry(
                wppilot_token_server_url_json($name, $url, $token),
                // "Settings, Customizations" is the Antigravity 2.0 desktop app;
                // the IDE opens the file from the agent side panel. Source,
                // checked 2026-09-30: https://antigravity.google/docs/mcp
                __(
                    'Click … at the top of the agent side panel, choose MCP Servers, then Manage MCP Servers and View raw config. Antigravity expects "serverUrl".',
                    domain: 'wppilot',
                ),
                [
                    __('Global', domain: 'wppilot') => '~/.gemini/config/mcp_config.json',
                    __('Workspace', domain: 'wppilot') => '.agents/mcp_config.json',
                ],
            ),
        ],
        wppilot_build_token_vscode_family_configs($url, $name, $token),
        wppilot_build_token_web_ui_configs($url, $token),
        wppilot_build_token_api_configs($url, $name, $token),
    );
}

/**
 * The VS Code-extension family and the remaining editors.
 *
 * Split from the list above only to keep either function readable; the grouping
 * is loose. What they have in common is that each disagrees with the others about
 * how to spell the same transport.
 *
 * @return array<string, array{code: string, hint: string, paths: array<string, string>, isShell: bool}>
 */
function wppilot_build_token_vscode_family_configs(string $url, string $name, string $token): array
{
    $add_to = wppilot_token_add_to();
    $standard = wppilot_token_mcp_servers_json($name, $url, $token);

    return [
        'cline' => wppilot_token_entry(
            wppilot_token_mcp_servers_json($name, $url, $token, type: 'streamableHttp'),
            __('Cline sidebar, then MCP Servers, then Configure MCP Servers.', domain: 'wppilot'),
            [__('Via UI', domain: 'wppilot') => 'cline_mcp_settings.json'],
        ),
        'roo-code' => wppilot_token_entry(
            wppilot_token_mcp_servers_json($name, $url, $token, type: 'streamable-http'),
            __(
                'Roo Code spells the transport "streamable-http", with a hyphen. The extension was discontinued in May 2026 — for a new setup, use Cline or ZooCode.',
                domain: 'wppilot',
            ),
            [__('Project', domain: 'wppilot') => '.roo/mcp.json'],
        ),
        'kilo-code' => wppilot_token_entry(
            wppilot_token_opencode_json($name, $url, $token),
            sprintf(
                /* translators: %s: config file name wrapped in <code> tags */
                __(
                    'Add to %s, or use Settings, Agent Behaviour, MCP Servers. Kilo names the remote transport "remote"; "oauth": false stops it offering a sign-in the header makes unnecessary.',
                    domain: 'wppilot',
                ),
                '<code>kilo.jsonc</code>',
            ),
            wppilot_kilo_code_paths(),
        ),
        // Kiro's documented remote shape is plain `url` + `headers`; Amazon Q
        // Developer reads the same mcpServers file format.
        'amazon-q' => wppilot_token_entry(
            wppilot_token_json([
                'mcpServers' => [
                    $name => [
                        'url' => $url,
                        'headers' => ['Authorization' => wppilot_token_auth_header($token)],
                    ],
                ],
            ]),
            sprintf($add_to, '<code>mcp.json</code>'),
            wppilot_amazon_q_kiro_paths(),
        ),
        'opencode' => wppilot_token_entry(
            wppilot_token_opencode_json($name, $url, $token),
            sprintf($add_to, '<code>opencode.json</code>'),
            [
                __('Project', domain: 'wppilot') => 'opencode.json',
                __('Global', domain: 'wppilot') => '~/.config/opencode/opencode.json',
            ],
        ),
        'zed' => wppilot_token_entry(
            wppilot_token_zed_json($name, $url, $token),
            sprintf(
                /* translators: %s: config file name wrapped in <code> tags */
                __(
                    'Add to %s (zed: open settings file), or use Settings, AI, MCP Servers, Add Remote Server. Zed connects directly — no Node or bridge needed.',
                    domain: 'wppilot',
                ),
                '<code>settings.json</code>',
            ),
            wppilot_zed_paths(),
        ),
        'kimi-cli' => wppilot_token_entry(
            wppilot_token_kimi_json($name, $url, $token),
            sprintf(
                /* translators: %s: config file name wrapped in <code> tags */
                __(
                    'Add to %s, or add it with /mcp-config inside Kimi Code. New sessions pick it up; no sign-in step follows.',
                    domain: 'wppilot',
                ),
                '<code>mcp.json</code>',
            ),
            wppilot_kimi_code_paths(),
        ),
        'qwen-code' => wppilot_token_entry(
            wppilot_token_http_url_json($name, $url, $token),
            sprintf(
                /* translators: %s: config file name wrapped in <code> tags */
                __('Add to %s. Qwen Code names a remote URL "httpUrl"; "url" is ignored.', domain: 'wppilot'),
                '<code>settings.json</code>',
            ),
            [
                __('Global', domain: 'wppilot') => '~/.qwen/settings.json',
                __('Project', domain: 'wppilot') => '.qwen/settings.json',
            ],
        ),
        'gemini-cli' => wppilot_token_entry(
            wppilot_token_http_url_json($name, $url, $token),
            sprintf(
                /* translators: %s: config file name wrapped in <code> tags */
                __('Add to %s. Gemini CLI names a remote URL "httpUrl"; "url" is ignored.', domain: 'wppilot'),
                '<code>settings.json</code>',
            ),
            [
                __('Global', domain: 'wppilot') => '~/.gemini/settings.json',
                __('Project', domain: 'wppilot') => '.gemini/settings.json',
            ],
        ),
        'zcode' => wppilot_token_entry($standard, __(
            'ZCode adds servers through its own MCP manager. Choose the HTTP transport, paste the URL, and add the Authorization header shown here.',
            domain: 'wppilot',
        )),
        'openclaw' => wppilot_token_entry(
            wppilot_token_openclaw_json($name, $url, $token),
            sprintf(
                /* translators: 1: config file name, 2: command, both wrapped in <code> tags */
                __(
                    'Merge into %1$s, or save the inner server object with %2$s. Keep "transport": "streamable-http" — without it OpenClaw falls back to SSE.',
                    domain: 'wppilot',
                ),
                '<code>openclaw.json</code>',
                '<code>openclaw mcp set</code>',
            ),
            wppilot_openclaw_paths(),
        ),
    ];
}

/**
 * The callers that are not an IDE at all.
 *
 * These are the shapes an access token unlocks that neither OAuth nor an
 * application password can serve: a hosted model provider connecting to this site
 * from its own infrastructure, and anything scripted. They are listed alongside
 * the editors because from this screen's point of view they are the same
 * question — where does the header go.
 *
 * @return array<string, array{code: string, hint: string, paths: array<string, string>, isShell: bool}>
 */
function wppilot_build_token_api_configs(string $url, string $name, string $token): array
{
    return [
        'anthropic-api' => wppilot_token_entry(
            wppilot_token_anthropic_api_snippet($name, $url, $token),
            __(
                'Anthropic connects from its own servers, so this site must be reachable over public HTTPS. The connector calls tools only — prompts and resources are not exposed through it.',
                domain: 'wppilot',
            ),
            is_shell: true,
        ),
        'openai-api' => wppilot_token_entry(
            wppilot_token_openai_api_snippet($name, $url, $token),
            __(
                'Also requires a publicly reachable HTTPS URL. require_approval is set to always — lower it only once you trust what this site exposes.',
                domain: 'wppilot',
            ),
            is_shell: true,
        ),
        'curl' => wppilot_token_entry(
            wppilot_token_curl_snippet($url, $token),
            __(
                'Lists the tools this endpoint exposes. A 200 with a tools array proves the token, the URL, and the endpoint all work — run this before debugging any client.',
                domain: 'wppilot',
            ),
            is_shell: true,
        ),
    ];
}

/**
 * Display names for the non-client entries above, which have no registry entry.
 *
 * @return array<string, string>
 */
function wppilot_token_api_client_labels(): array
{
    return [
        'anthropic-api' => __('Claude API', domain: 'wppilot'),
        'openai-api' => __('OpenAI API', domain: 'wppilot'),
        'curl' => __('curl / any HTTP client', domain: 'wppilot'),
    ];
}
