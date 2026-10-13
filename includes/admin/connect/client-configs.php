<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * Configuration page: generated client configuration.
 *
 * One connection, many client formats — Claude Code, Zed, OpenCode, Codex,
 * and the .mcpb bundle. Every builder here returns a string; nothing in
 * this file echoes, so the same value can be rendered, copied, or zipped.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Compute the default MCP server name from the current site host.
 *
 * Capped at 25 characters total ("gobuildwp-" prefix + up to 15 chars of host slug)
 * because some MCP clients reject longer server names. Used as the placeholder default
 * when no name has been saved by the user.
 */
function wppilot_get_mcp_server_name_default(): string
{
    /** @var string $site_host */
    $site_host = wp_parse_url(home_url(), PHP_URL_HOST) ?? 'wordpress';
    $site_slug = (string) preg_replace(pattern: '/^www\./', replacement: '', subject: $site_host);
    $site_slug = (string) preg_replace(pattern: '/[^a-z0-9-]+/', replacement: '-', subject: strtolower($site_slug));
    $site_slug = trim($site_slug, characters: '-');
    $site_slug = substr($site_slug, offset: 0, length: 15);
    $site_slug = rtrim($site_slug, characters: '-');
    return 'gobuildwp-' . $site_slug;
}

/**
 * Build the paste-to-agent paragraph displayed in Option A of the Connect section.
 *
 * Returns a plain-text block the user can copy and paste into their AI client / agent.
 * The MCP server name uses the same placeholder as the JSON snippets so the live JS
 * preview can swap it in without re-rendering the page.
 */
function wppilot_build_paste_to_agent_paragraph(
    string $rest_url,
    string $username,
    string $display_password,
    string $name_placeholder = '__WPPILOT_MCP_NAME__',
    ?string $password_placeholder = null,
): string {
    $password_value = $password_placeholder ?? $display_password;
    $lines = [
        'I want to add this WordPress site as an MCP server to this AI client.',
        '',
        'Connection details:',
        '- Server URL: ' . $rest_url,
        '- Username: ' . $username,
        '- Application password: ' . $password_value,
        '- Server name to use in the config: ' . $name_placeholder,
        '- Transport: @automattic/mcp-wordpress-remote via npx',
        '',
        'Setup rules:',
        '- Pass credentials ONLY as env vars: WP_API_URL, WP_API_USERNAME, WP_API_PASSWORD. Do NOT use CLI flags like --url or --password (the package ignores them).',
        '- args array must be exactly ["-y", "@automattic/mcp-wordpress-remote@latest"].'
            . (
                wppilot_likely_self_signed_https()
                    ? "\n"
                    . '- Also set NODE_TLS_REJECT_UNAUTHORIZED="0" in env (this site uses a local self-signed TLS certificate).'
                    : ''
            ),
        '',
        'Don\'t ask me to confirm choices already specified above. After writing the config, restart or reload the MCP session (most clients require it), then verify by listing the server\'s tools. If it fails, show me the stderr from the npx process before proposing changes.',
        '',
        'If you cannot modify the config of this AI client from here, tell me to expand "Manual configuration for your AI client" on the GoBuildWP Connect page and copy the snippet manually.',
    ];

    return implode("\n", $lines);
}

/**
 * Build the npx server config array shared across multiple MCP clients.
 *
 * @param string $rest_url        MCP REST endpoint URL.
 * @param string $username        Current WordPress username.
 * @param string $display_password Plaintext password or placeholder.
 * @return array{command: string, args: list<string>, env: array<string, string>}
 */
function wppilot_build_npx_server(string $rest_url, string $username, string $display_password): array
{
    $env = [
        'WP_API_URL' => $rest_url,
        'WP_API_USERNAME' => $username,
        'WP_API_PASSWORD' => $display_password,
    ];
    if (wppilot_likely_self_signed_https()) {
        $env['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
    }
    return [
        'command' => 'npx',
        'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
        'env' => $env,
    ];
}

/**
 * Config-file locations shared by the three connection methods.
 *
 * Kept in one place because each method's builder used to restate them, and a
 * vendor moving a file then needed three identical edits — the ones below had
 * drifted into being wrong in all three at once. Each carries the vendor page
 * it was checked against.
 */

/**
 * Devin Desktop (formerly Windsurf). Devin's MCP page gives the ~/.config/devin
 * path; its transition FAQ says the app still reads the legacy Windsurf/Codeium
 * locations, so a Windsurf install that has not updated keeps working there.
 * Sources, checked 2026-09-30: https://docs.devin.ai/desktop/cascade/mcp and
 * https://docs.devin.ai/desktop/devin-desktop-faq
 *
 * @return array<string, string>
 */
function wppilot_devin_desktop_paths(): array
{
    return [
        'Devin Desktop — macOS / Linux' => '~/.config/devin/mcp_config.json',
        'Devin Desktop — Windows' => '%APPDATA%\\devin\\mcp_config.json',
        __('Windsurf (before the rename)', domain: 'wppilot') => '~/.codeium/windsurf/mcp_config.json',
    ];
}

/**
 * Copilot CLI. Source, checked 2026-09-30:
 * https://docs.github.com/en/copilot/how-tos/copilot-cli/customize-copilot/add-mcp-servers
 *
 * @return array<string, string>
 */
function wppilot_copilot_cli_paths(): array
{
    return [
        __('User', domain: 'wppilot') => '~/.copilot/mcp-config.json',
        __('Project (committed)', domain: 'wppilot') => '.github/mcp.json',
        __('Project (local)', domain: 'wppilot') => '.mcp.json',
    ];
}

/**
 * Kimi Code CLI. Source, checked 2026-09-30:
 * https://moonshotai.github.io/kimi-code/en/customization/mcp.html
 *
 * @return array<string, string>
 */
function wppilot_kimi_code_paths(): array
{
    return [
        __('Global', domain: 'wppilot') => '~/.kimi-code/mcp.json',
        __('Project', domain: 'wppilot') => '.kimi-code/mcp.json',
    ];
}

/**
 * Kilo Code. Source, checked 2026-09-30:
 * https://kilo.ai/docs/automate/mcp/using-in-kilo-code
 *
 * @return array<string, string>
 */
function wppilot_kilo_code_paths(): array
{
    return [
        __('Global', domain: 'wppilot') => '~/.config/kilo/kilo.jsonc',
        __('Project', domain: 'wppilot') => 'kilo.jsonc',
    ];
}

/**
 * Kiro, then the Amazon Q Developer files it replaces. Sources, checked
 * 2026-09-30: https://kiro.dev/docs/mcp/configuration/ and
 * https://docs.aws.amazon.com/amazonq/latest/qdeveloper-ug/mcp-ide.html
 *
 * @return array<string, string>
 */
function wppilot_amazon_q_kiro_paths(): array
{
    return [
        'Kiro — ' . __('Global', domain: 'wppilot') => '~/.kiro/settings/mcp.json',
        'Kiro — ' . __('Workspace', domain: 'wppilot') => '.kiro/settings/mcp.json',
        'Amazon Q — ' . __('Global', domain: 'wppilot') => '~/.aws/amazonq/mcp.json',
        'Amazon Q — ' . __('Project', domain: 'wppilot') => '.amazonq/mcp.json',
    ];
}

/**
 * Zed. The settings file is opened with the `zed: open settings file` action.
 *
 * @return array<string, string>
 */
function wppilot_zed_paths(): array
{
    return ['macOS / Linux' => '~/.config/zed/settings.json'];
}

/**
 * OpenClaw. Source, checked 2026-09-30: https://docs.openclaw.ai/gateway/configuration
 *
 * @return array<string, string>
 */
function wppilot_openclaw_paths(): array
{
    return [__('Global', domain: 'wppilot') => '~/.openclaw/openclaw.json'];
}

/**
 * Build the MCPB bundle manifest (manifest.json contents) for this site.
 *
 * The bundle wraps the same npx proxy used by the JSON snippets, with the
 * connection credentials embedded directly so it installs without further
 * prompts. The plaintext application password is therefore written into the
 * file — callers must warn the user before offering the download.
 *
 * @param string $rest_url        MCP REST endpoint URL.
 * @param string $username        WordPress username.
 * @param string $display_password Plaintext application password.
 * @return array<string, mixed>
 */
function wppilot_build_mcpb_manifest(
    string $rest_url,
    string $username,
    string $display_password,
    string $mcp_name,
): array {
    $env = [
        'WP_API_URL' => $rest_url,
        'WP_API_USERNAME' => $username,
        'WP_API_PASSWORD' => $display_password,
    ];
    if (wppilot_likely_self_signed_https()) {
        $env['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
    }

    $site_name = trim(get_bloginfo('name'));
    $display_name = $site_name !== '' ? 'GoBuildWP — ' . $site_name : 'GoBuildWP';

    return [
        'manifest_version' => '0.3',
        'name' => $mcp_name,
        'display_name' => $display_name,
        'version' => WPPILOT_VERSION,
        'description' => __(
            'Full WordPress control for your AI agent. Runs real PHP, queries the database, edits files — on your dev or staging site.',
            domain: 'wppilot',
        ),
        'author' => ['name' => 'GoBuildWP'],
        'server' => [
            // entry_point is required by the MCPB schema even though the server
            // is launched via mcp_config (npx); the bundled stub is never run.
            'type' => 'node',
            'entry_point' => 'server/index.js',
            'mcp_config' => [
                'command' => 'npx',
                'args' => ['-y', '@automattic/mcp-wordpress-remote@latest'],
                'env' => $env,
            ],
        ],
    ];
}

/**
 * Zed local server: `command`, `args`, `env` under `context_servers`. The
 * `source: "custom"` marker is no longer in Zed's documentation.
 * Source, checked 2026-09-30: https://zed.dev/docs/ai/mcp
 *
 * @param array<string, mixed> $npx_server
 */
function wppilot_build_zed_json(string $mcp_name, array $npx_server, int $opts): string
{
    return (string) json_encode(['context_servers' => [$mcp_name => $npx_server]], $opts);
}

/**
 * OpenCode local server. Kilo Code uses the identical `mcp` / `local` shape in
 * kilo.jsonc, so it shares this builder.
 */
function wppilot_build_opencode_json(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
    int $opts,
): string {
    $environment = [
        'WP_API_URL' => $rest_url,
        'WP_API_USERNAME' => $username,
        'WP_API_PASSWORD' => $display_password,
    ];
    if (wppilot_likely_self_signed_https()) {
        $environment['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
    }
    return (string) json_encode([
        'mcp' => [
            $mcp_name => [
                'type' => 'local',
                'command' => ['npx', '-y', '@automattic/mcp-wordpress-remote@latest'],
                'environment' => $environment,
            ],
        ],
    ], $opts);
}

function wppilot_build_codex_toml(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
): string {
    $esc = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';

    $lines = [
        '[mcp_servers.' . $mcp_name . ']',
        'command = "npx"',
        'args = ["-y", "@automattic/mcp-wordpress-remote@latest"]',
        '',
        '[mcp_servers.' . $mcp_name . '.env]',
        'WP_API_URL = ' . $esc($rest_url),
        'WP_API_USERNAME = ' . $esc($username),
        'WP_API_PASSWORD = ' . $esc($display_password),
    ];
    if (wppilot_likely_self_signed_https()) {
        $lines[] = 'NODE_TLS_REJECT_UNAUTHORIZED = "0"';
    }
    return implode("\n", $lines);
}

/**
 * The one-line form: Claude Code talks to the endpoint directly over HTTP.
 *
 * The npx form below shells out to a Node wrapper that re-implements the
 * transport, which means Node, a package download, and four environment
 * variables all have to be right before anything works. GoBuildWP already serves
 * MCP over HTTP with Basic authentication, so a client that speaks HTTP needs
 * none of that — just the URL and one header.
 *
 * It also fixes a failure this page used to cause. With the npx form, the
 * client's health check only confirms that a Node process started — so a config
 * carrying the placeholder password still reports "Connected" while every real
 * call fails, which is close to undiagnosable. Over HTTP the health check is a
 * real authenticated handshake against this endpoint: wrong credentials answer
 * 401 and the client says so, and a successful one shows up in the connection
 * ledger on the Overview screen. The status finally means something.
 *
 * The npx bridge is still reachable for anyone who needs it — every other
 * client's snippet on this page uses it, and the paste-to-agent paragraph
 * describes it — so nothing is lost by making the direct form the one Claude
 * Code is handed.
 */
function wppilot_build_claude_code_http_cmd(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
): string {
    $sq = static fn(string $v): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $v) . "'";
    $basic = base64_encode($username . ':' . $display_password);

    return implode(" \\\n  ", [
        'claude mcp add --transport http ' . $sq($mcp_name) . ' ' . $sq($rest_url),
        '--header ' . $sq('Authorization: Basic ' . $basic),
        '--scope user',
    ]);
}

function wppilot_build_claude_code_cmd(
    string $mcp_name,
    string $rest_url,
    string $username,
    string $display_password,
): string {
    $sq = static fn(string $v): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $v) . "'";

    $parts = [
        'claude mcp add ' . $sq($mcp_name),
        '--env WP_API_URL=' . $sq($rest_url),
        '--env WP_API_USERNAME=' . $sq($username),
        '--env WP_API_PASSWORD=' . $sq($display_password),
    ];
    if (wppilot_likely_self_signed_https()) {
        $parts[] = '--env NODE_TLS_REJECT_UNAUTHORIZED=' . $sq('0');
    }
    $parts[] = '-- npx -y @automattic/mcp-wordpress-remote@latest';

    return implode(" \\\n  ", $parts);
}

/**
 * Build all per-client, per-transport config entries.
 *
 * @param string $rest_url        MCP REST endpoint URL.
 * @param string $username        Current WordPress username.
 * @param string $display_password Plaintext password or placeholder.
 * @param string $mcp_name        MCP server name used as the config key.
 * @return array<string, array{code: string, hint: string, paths: array<string, string>, isShell: bool}>
 */
function wppilot_build_configs(string $rest_url, string $username, string $display_password, string $mcp_name): array
{
    $opts = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
    $npx_server = wppilot_build_npx_server($rest_url, $username, $display_password);
    $mcp_servers_json = (string) json_encode(['mcpServers' => [$mcp_name => $npx_server]], $opts);
    $vscode_servers_json = (string) json_encode(['servers' => [$mcp_name => $npx_server]], $opts);

    /* translators: %s: config file name wrapped in <code> tags */
    $add_to = __('Add to %s.', domain: 'wppilot');

    $special = [
        // The direct-HTTP form first: fewest moving parts, and the one that
        // cannot half-succeed. See wppilot_build_claude_code_http_cmd().
        'claude-code' => [
            'code' => wppilot_build_claude_code_http_cmd($mcp_name, $rest_url, $username, $display_password),
            'hint' => __('Run in your terminal, then restart Claude Code. No Node or npx needed.', domain: 'wppilot'),
            'paths' => [],
            'isShell' => true,
        ],
        'codex' => [
            'code' => wppilot_build_codex_toml($mcp_name, $rest_url, $username, $display_password),
            'hint' => sprintf($add_to, '<code>config.toml</code>'),
            'paths' => [
                'macOS / Linux' => '~/.codex/config.toml',
                'Windows' => '%USERPROFILE%\\.codex\\config.toml',
            ],
            'isShell' => false,
        ],
        'zed' => [
            'code' => wppilot_build_zed_json($mcp_name, $npx_server, $opts),
            'hint' => sprintf($add_to, '<code>settings.json</code>'),
            'paths' => ['macOS / Linux' => '~/.config/zed/settings.json'],
            'isShell' => false,
        ],
        'opencode' => [
            'code' => wppilot_build_opencode_json($mcp_name, $rest_url, $username, $display_password, $opts),
            'hint' => sprintf($add_to, '<code>opencode.json</code>'),
            'paths' => [
                __('Project', domain: 'wppilot') => 'opencode.json',
                __('Global', domain: 'wppilot') => '~/.config/opencode/opencode.json',
            ],
            'isShell' => false,
        ],
        'kilo-code' => [
            'code' => wppilot_build_opencode_json($mcp_name, $rest_url, $username, $display_password, $opts),
            'hint' => sprintf($add_to, '<code>kilo.jsonc</code>'),
            'paths' => wppilot_kilo_code_paths(),
            'isShell' => false,
        ],
        // Copilot CLI marks a stdio server `type: "local"` and requires `tools`.
        'github-copilot' => [
            'code' => (string) json_encode([
                'mcpServers' => [$mcp_name => array_merge(['type' => 'local'], $npx_server, ['tools' => ['*']])],
            ], $opts),
            'hint' => sprintf(
                /* translators: %s: config file name wrapped in <code> tags */
                __(
                    'For Copilot CLI: add to %s. Copilot Chat in VS Code reads the VS Code configuration instead — use the VS Code tab.',
                    domain: 'wppilot',
                ),
                '<code>mcp-config.json</code>',
            ),
            'paths' => wppilot_copilot_cli_paths(),
            'isShell' => false,
        ],
        'openclaw' => [
            'code' => (string) json_encode(['mcp' => ['servers' => [$mcp_name => $npx_server]]], $opts),
            'hint' => sprintf($add_to, '<code>openclaw.json</code>'),
            'paths' => wppilot_openclaw_paths(),
            'isShell' => false,
        ],
    ];

    return array_merge(
        wppilot_build_standard_configs($mcp_servers_json, $vscode_servers_json),
        wppilot_build_cli_agent_configs($mcp_servers_json),
        $special,
    );
}

/**
 * Build per-client config entries that reuse the standard mcpServers/servers JSON payloads.
 *
 * @return array<string, array{code: string, hint: string, paths: array<string, string>, isShell: bool}>
 */
function wppilot_build_standard_configs(string $mcp_servers_json, string $vscode_servers_json): array
{
    /* translators: %s: config file name wrapped in <code> tags */
    $add_to = __('Add to %s.', domain: 'wppilot');

    return [
        'claude-desktop' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>claude_desktop_config.json</code>'),
            'paths' => [
                'macOS' => '~/Library/Application Support/Claude/claude_desktop_config.json',
                'Windows' => '%APPDATA%\\Claude\\claude_desktop_config.json',
            ],
            'isShell' => false,
        ],
        'cursor' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Global', domain: 'wppilot') => '~/.cursor/mcp.json',
                __('Project', domain: 'wppilot') => '.cursor/mcp.json',
            ],
            'isShell' => false,
        ],
        'factory-droid' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Global', domain: 'wppilot') => '~/.factory/mcp.json',
                __('Project', domain: 'wppilot') => '.factory/mcp.json',
            ],
            'isShell' => false,
        ],
        'vscode' => [
            'code' => $vscode_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Workspace', domain: 'wppilot') => '.vscode/mcp.json',
                __('User', domain: 'wppilot') => __(
                    'Run: MCP: Open User Configuration (command palette)',
                    domain: 'wppilot',
                ),
            ],
            'isShell' => false,
        ],
        'windsurf' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp_config.json</code>'),
            'paths' => wppilot_devin_desktop_paths(),
            'isShell' => false,
        ],
        'cline' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>cline_mcp_settings.json</code>'),
            'paths' => [
                __('Via UI', domain: 'wppilot') => __(
                    'Cline sidebar → MCP Servers → Configure MCP Servers',
                    domain: 'wppilot',
                ),
            ],
            'isShell' => false,
        ],
        'roo-code' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => [
                __('Project', domain: 'wppilot') => '.roo/mcp.json',
                __('Via UI', domain: 'wppilot') => __(
                    'Roo Code sidebar → MCP Servers → Configure MCP Servers',
                    domain: 'wppilot',
                ),
            ],
            'isShell' => false,
        ],
        'amazon-q' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => wppilot_amazon_q_kiro_paths(),
            'isShell' => false,
        ],
        'antigravity-cli' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp_config.json</code>'),
            'paths' => [
                __('Global', domain: 'wppilot') => '~/.gemini/config/mcp_config.json',
                __('Workspace', domain: 'wppilot') => '.agents/mcp_config.json',
            ],
            'isShell' => false,
        ],
        'antigravity-ide' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp_config.json</code>'),
            'paths' => [
                __('Global', domain: 'wppilot') => '~/.gemini/config/mcp_config.json',
                __('Workspace', domain: 'wppilot') => '.agents/mcp_config.json',
            ],
            'isShell' => false,
        ],
    ];
}

/**
 * The newer coding CLIs, which all take the same stdio server object.
 *
 * Split from the list above only to keep either function readable. They are
 * grouped because the application-password route launches a local helper, so the
 * differences these clients have over a remote URL — Qwen and Gemini insisting on
 * `httpUrl`, ZCode using a manager screen — do not arise on this route at all.
 *
 * @return array<string, array{code: string, hint: string, paths: array<string, string>, isShell: bool}>
 */
function wppilot_build_cli_agent_configs(string $mcp_servers_json): array
{
    /* translators: %s: config file name wrapped in <code> tags */
    $add_to = __('Add to %s.', domain: 'wppilot');

    return [
        'kimi-cli' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>mcp.json</code>'),
            'paths' => wppilot_kimi_code_paths(),
            'isShell' => false,
        ],
        // Qwen Code and Gemini CLI put MCP servers in settings.json. The
        // `httpUrl` quirk both share applies to remote servers only; this route
        // launches a local process, so the ordinary command/args shape is right.
        'qwen-code' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>settings.json</code>'),
            'paths' => [
                __('Global', domain: 'wppilot') => '~/.qwen/settings.json',
                __('Project', domain: 'wppilot') => '.qwen/settings.json',
            ],
            'isShell' => false,
        ],
        'gemini-cli' => [
            'code' => $mcp_servers_json,
            'hint' => sprintf($add_to, '<code>settings.json</code>'),
            'paths' => [
                __('Global', domain: 'wppilot') => '~/.gemini/settings.json',
                __('Project', domain: 'wppilot') => '.gemini/settings.json',
            ],
            'isShell' => false,
        ],
        'zcode' => [
            'code' => $mcp_servers_json,
            'hint' => __('Add through ZCode\'s MCP server manager, choosing the stdio transport.', domain: 'wppilot'),
            'paths' => [],
            'isShell' => false,
        ],
    ];
}

/**
 * Stream a downloadable .mcpb bundle for Claude Desktop. Hooked on admin_post.
 *
 * The bundle embeds the plaintext application password, which WordPress only
 * exposes right after creation — so the password is posted back from the
 * connect page (where it was just shown) rather than read from storage.
 */
function wppilot_handle_download_mcpb(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to download this bundle.', domain: 'wppilot'));
    }

    check_admin_referer('wppilot_download_mcpb');

    if (!class_exists('ZipArchive')) {
        wp_die(esc_html__(
            'Cannot build the bundle: the PHP zip extension is not available on this server. Use the JSON config instead.',
            domain: 'wppilot',
        ));
    }

    $raw_password = $_POST['wppilot_mcpb_password'] ?? '';
    $password = is_string($raw_password) ? (string) preg_replace('/\s+/', replacement: '', subject: $raw_password) : '';
    if ($password === '') {
        wp_die(esc_html__('Missing application password for the bundle.', domain: 'wppilot'));
    }

    $username = wp_get_current_user()->user_login;
    $rest_url = rest_url('mcp/wppilot');

    $raw_name = $_POST['wppilot_mcpb_name'] ?? '';
    $mcp_name = is_string($raw_name)
        ? (string) preg_replace('/[^a-z0-9-]/', replacement: '', subject: strtolower($raw_name))
        : '';
    if ($mcp_name === '' || strlen($mcp_name) > 25) {
        $mcp_name = wppilot_get_mcp_server_name_default();
    }

    $manifest = wppilot_build_mcpb_manifest($rest_url, $username, $password, $mcp_name);
    $manifest_json = (string) wp_json_encode(
        $manifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );

    $stub =
        "// Placeholder entry point. The actual MCP server is launched via mcp_config\n"
        . "// (npx @automattic/mcp-wordpress-remote), so this file is never executed.\n"
        . "// It exists only to satisfy the manifest's required entry_point field.\n";

    $tmp = wp_tempnam('wppilot-mcpb');
    $zip = new ZipArchive();
    if ($tmp === '' || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        wp_die(esc_html__('Could not create the bundle archive.', domain: 'wppilot'));
    }
    $zip->addFromString('manifest.json', $manifest_json);
    $zip->addFromString('server/index.js', $stub);
    $zip->close();

    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $filename = 'wppilot-' . sanitize_file_name($host !== '' ? $host : 'site') . '.mcpb';

    nocache_headers();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) filesize($tmp));
    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.file_system_operations_readfile,WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem is not usable here: it takes credentials from an interactive admin form, which a REST/MCP request has no way to show. This streams a generated .mcpb bundle from a temporary file straight to the browser.
    readfile($tmp);
    wp_delete_file($tmp);
    exit();
}

/**
 * Render the "download .mcpb bundle" option (shown only for the Claude Desktop
 * tab via JS). Hidden when no real password is available, since the bundle must
 * embed the plaintext password. Warns the user that the file carries it.
 */
function wppilot_render_mcpb_download(string $display_password, string $mcp_name): void
{
    // Without the zip extension the download handler can't build the bundle, so
    // omit the option entirely rather than send the user to an error page.
    if (!class_exists('ZipArchive')) {
        return;
    }
    $confirm_msg = wp_json_encode(__(
        'This bundle contains your password. The .mcpb file embeds your application password in plaintext so it installs without prompts. Anyone who gets the file can control this site — don\'t share it, and delete it after installing.',
        domain: 'wppilot',
    ));
    $confirm_msg = $confirm_msg !== false ? $confirm_msg : '""';
    ?>
    <div id="wppilot-mcpb-download" style="display:none; margin-top:20px; margin-bottom:4px;">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
            <input type="hidden" name="action" value="wppilot_download_mcpb">
            <?php wp_nonce_field('wppilot_download_mcpb'); ?>
            <input type="hidden" name="wppilot_mcpb_password" value="<?php echo esc_attr($display_password); ?>">
            <input type="hidden" name="wppilot_mcpb_name" id="wppilot-mcpb-name" value="<?php echo
                esc_attr($mcp_name)
            ; ?>">
            <button
                type="submit"
                class="button button-primary"
                style="display:inline-flex; flex-direction:column; align-items:flex-start; width:auto; padding:12px 24px; height:auto; gap:3px;"
                onclick="return confirm(<?php echo esc_attr($confirm_msg); ?>)"
            ><span style="font-size:15px; line-height:1.2;"><?php esc_html_e(
                'Download .mcpb bundle',
                domain: 'wppilot',
            ); ?></span><span style="font-size:12px; font-weight:400; opacity:0.88; line-height:1.2;"><?php esc_html_e(
                'Requires Claude Desktop 1.24012.1 or later',
                domain: 'wppilot',
            ); ?></span></button>
        </form>
        <p style="margin:8px 0 4px;">
            <button
                type="button"
                class="button-link"
                onclick="wppilotShowPromptForDesktop(this)"
            ><?php esc_html_e('Use the prompt for Claude Desktop instead', domain: 'wppilot'); ?></button>
        </p>
    </div>
    <?php
}

/** Render the JSON config block. */
function wppilot_render_json_config_block(): void
{ ?>
    <div class="wppilot-tab-content" style="border-radius:4px;">
        <div class="wppilot-config-block">
            <pre id="wppilot-config-code"></pre>
            <button type="button" class="button wppilot-copy-btn" onclick="wppilotCopyConfig(this)"><?php esc_html_e(
                'Copy',
                domain: 'wppilot',
            ); ?></button>
        </div>
        <div id="wppilot-config-footer" style="font-size:13px; color:#666; border-top: 1px solid #c3c4c7;">
            <div id="wppilot-config-merge-note" style="padding: 10px 16px 0;">
                <?php esc_html_e(
                    'If your config file already has content, merge this into your existing config instead of replacing it.',
                    domain: 'wppilot',
                ); ?>
            </div>
            <div id="wppilot-config-hint" style="padding: 10px 16px;"></div>
            <div id="wppilot-config-paths" style="padding: 0 16px 10px;"></div>
        </div>
    </div>
    <?php }
