<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// Standalone-testable: allow require under PHP CLI without a WordPress bootstrap.
if (!defined('ABSPATH')) {
    exit();
}

/**
 * True when an IPv4/IPv6 literal is in a private, loopback, link-local, or reserved range.
 */
function wppilot_ip_is_private_or_loopback(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        // Fails validation (=== false) exactly when the address is private or reserved.
        return (
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            === false
        );
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $lower = strtolower($ip);
        return (
            $lower === '::1'
            || str_starts_with($lower, 'fe80:')
            || str_starts_with($lower, 'fc')
            || str_starts_with($lower, 'fd')
        );
    }
    return false;
}

/**
 * True when a host is only reachable from the local machine and never from the public
 * internet: single-label hosts, private/loopback IP literals, and local-dev suffixes.
 * Expects a bare host without a port.
 */
function wppilot_host_is_local_only(string $host): bool
{
    $host = strtolower(trim($host));
    $host = trim($host, characters: '[]');
    if ($host === '') {
        return false;
    }

    // IP literal → private/loopback ranges are local-only.
    $ip = filter_var($host, FILTER_VALIDATE_IP);
    if ($ip !== false) {
        return wppilot_ip_is_private_or_loopback($ip);
    }

    // Single-label host (no dot), e.g. "localhost" or "wordpress".
    if (!str_contains($host, '.')) {
        return true;
    }

    /** @var array<int, string> $suffixes */
    $suffixes = apply_filters('wppilot_local_only_host_patterns', [
        '.local',
        '.test',
        '.localhost',
        '.ddev.site',
        '.lndo.site',
    ]);
    foreach ($suffixes as $suffix) {
        if ($suffix !== '' && str_ends_with($host, $suffix)) {
            return true;
        }
    }

    return false;
}

/**
 * True when this site's host cannot be reached from Anthropic's cloud, so a native remote
 * connector will not work and the OAuth flow must run through a local stdio bridge.
 */
function wppilot_host_unreachable_from_cloud(): bool
{
    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    return wppilot_host_is_local_only($host);
}

/**
 * True when it is safe to run the OAuth flow: the site is served over HTTPS, or WordPress reports a
 * local environment. On a plain-HTTP site the authorization code and the bearer tokens travel in
 * cleartext (RFC 6749/6750 require TLS), and "not reachable from the public internet" is not enough
 * on its own: a private-IP or *.local/*.test host still shares a LAN wire another device can sniff.
 * So HTTP is allowed only when wp_get_environment_type() is 'local' — the same transport policy
 * wp_is_application_passwords_supported() applies. Keyed off the canonical home_url() scheme, not
 * is_ssl(), so a TLS-terminating reverse proxy (https to the public, http to PHP) is not misjudged.
 */
function wppilot_oauth_transport_allowed(): bool
{
    if (str_starts_with(strtolower(home_url()), 'https://')) {
        return true;
    }
    return wp_get_environment_type() === 'local';
}

/**
 * Build the Claude "add custom connector" prefill link. Opens the dialog with the name and
 * URL pre-filled; the user still reviews and confirms. No secret is embedded.
 */
function wppilot_build_connector_install_link(string $mcp_url, string $connector_name): string
{
    return (
        'https://claude.ai/customize/connectors?modal=add-custom-connector'
        . '&connectorName='
        . rawurlencode($connector_name)
        . '&connectorUrl='
        . rawurlencode($mcp_url)
    );
}

/**
 * Human-readable connector name shown in Claude. Empty site name falls back to "GoBuildWP".
 */
function wppilot_build_connector_display_name(string $site_name): string
{
    $site_name = trim($site_name);
    return $site_name !== '' ? 'GoBuildWP - ' . $site_name : 'GoBuildWP';
}

/**
 * npx mcp-remote stdio bridge server object. The bridge runs the OAuth browser flow on behalf
 * of clients that do not perform it natively. $env carries the self-signed TLS bypass on local
 * sites and is empty on public ones.
 *
 * @param array<string, string> $env
 * @return array<string, mixed>
 */
function wppilot_oauth_bridge_server(string $mcp_url, array $env): array
{
    $server = ['command' => 'npx', 'args' => ['-y', 'mcp-remote', $mcp_url]];
    if ($env !== []) {
        $server['env'] = $env;
    }
    return $server;
}

/**
 * Wrap a per-client server object in its JSON envelope (mcpServers or servers).
 *
 * @param array<string, mixed> $server
 */
function wppilot_oauth_json(string $wrapper, string $mcp_name, array $server): string
{
    return (string) json_encode([$wrapper => [$mcp_name => $server]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * Build a plain code-snippet client entry (no shell, no connector button). The optional note is
 * extra HTML rendered under the snippet (e.g. a CLI alternative); empty means none.
 *
 * @param array<string, string> $paths
 * @return array<string, mixed>
 */
function wppilot_oauth_code_entry(string $code, string $hint, array $paths, string $note = ''): array
{
    return ['kind' => 'code', 'code' => $code, 'hint' => $hint, 'paths' => $paths, 'isShell' => false, 'note' => $note];
}

/**
 * "Add to <code>file</code>." hint, shared by the client config entries.
 */
function wppilot_oauth_add_to(string $file): string
{
    return sprintf(
        /* translators: %s: config file name wrapped in <code> tags */
        __(
            'Add to %s, then restart the client. It will report the server as needing sign-in — approve it, and a browser window opens. Nothing here contains a secret, so this snippet is safe to commit.',
            domain: 'wppilot',
        ),
        '<code>' . $file . '</code>',
    );
}

/**
 * Codex config.toml snippet for the mcp-remote bridge (stdio launch, optional env block).
 *
 * @param array<string, string> $env
 */
function wppilot_oauth_bridge_codex(string $mcp_name, string $mcp_url, array $env): string
{
    $tq = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    $lines = [
        '[mcp_servers.' . $mcp_name . ']',
        'command = "npx"',
        'args = ["-y", "mcp-remote", ' . $tq($mcp_url) . ']',
    ];
    if ($env !== []) {
        $lines[] = '';
        $lines[] = '[mcp_servers.' . $mcp_name . '.env]';
        foreach ($env as $key => $value) {
            $lines[] = $key . ' = ' . $tq($value);
        }
    }
    return implode("\n", $lines);
}

/**
 * `claude mcp add` command for the mcp-remote bridge (stdio launch, optional --env flags).
 *
 * @param array<string, string> $env
 */
function wppilot_oauth_bridge_claude_code(string $mcp_name, string $mcp_url, array $env): string
{
    $sq = static fn(string $v): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $v) . "'";
    $parts = ['claude mcp add ' . $sq($mcp_name)];
    foreach ($env as $key => $value) {
        $parts[] = '--env ' . $key . '=' . $sq($value);
    }
    $parts[] = '-- npx -y mcp-remote ' . $sq($mcp_url);
    return implode(" \\\n  ", $parts);
}

/**
 * Steps for ChatGPT's developer-mode plugin: turn on developer mode, create a plugin, paste the
 * OAuth server URL. ChatGPT reaches the server from OpenAI's cloud, so this is only offered on a
 * publicly reachable site (see wppilot_build_oauth_configs()).
 *
 * @return list<array<string, string>>
 */
function wppilot_oauth_chatgpt_steps(string $mcp_name, string $mcp_url): array
{
    return [
        [
            'title' => __('Turn on developer mode', domain: 'wppilot'),
            'body' => __(
                'In ChatGPT on the web, open Settings, choose Apps in the sidebar, open Advanced settings, and turn on Developer mode. It is the only way to add an MCP server OpenAI has not reviewed, and yours will always be one of those — it connects to your own site, so it can never be a listed app. The warning ChatGPT shows is expected. Available on Plus, Pro, Business, Enterprise and Edu, on the web app; the mobile apps cannot add one.',
                domain: 'wppilot',
            ),
        ],
        [
            'title' => __('Business, Enterprise or Edu: have an admin allow it first', domain: 'wppilot'),
            'body' => __(
                'On a workspace plan the toggle above stays off until an admin enables it in Workspace settings, under Permissions & roles, as "Developer mode / Create custom MCP connectors". A personal Plus or Pro account needs nothing here.',
                domain: 'wppilot',
            ),
        ],
        [
            'title' => __('Create the app', domain: 'wppilot'),
            'body' => __(
                'Back on the Apps screen, press Create app. Give it this name, or one you will recognize with "GoBuildWP" in it:',
                domain: 'wppilot',
            ),
            'copy' => $mcp_name,
        ],
        [
            'title' => __('Enter the server URL', domain: 'wppilot'),
            'body' => __(
                'Paste the URL below as the MCP server URL — include the whole path, ChatGPT does not add one. Set Authentication to OAuth, confirm the trust prompt, and create it. Then sign in when the browser opens.',
                domain: 'wppilot',
            ),
            'copy' => $mcp_url,
        ],
        [
            'title' => __('Use it in a chat', domain: 'wppilot'),
            'body' => __(
                'A new connector is not on by default. Start a chat, open the + menu, and enable this one for the conversation. ChatGPT connects from OpenAI\'s servers, so the site has to be reachable over public HTTPS — a localhost URL cannot work.',
                domain: 'wppilot',
            ),
        ],
    ];
}

/**
 * Manus: Settings, then Connectors, then the Custom MCP tab.
 *
 * @return list<array<string, string>>
 */
function wppilot_oauth_manus_steps(string $mcp_name, string $mcp_url): array
{
    return [
        [
            'title' => __('Open Connectors', domain: 'wppilot'),
            'body' => __(
                'In Manus, open Settings from the sidebar and choose Connectors, then press "+ Add connectors".',
                domain: 'wppilot',
            ),
        ],
        [
            'title' => __('Add a custom MCP server', domain: 'wppilot'),
            'body' => __(
                'Select the Custom MCP tab, press "+ Add custom MCP", and choose Direct configuration. Name it:',
                domain: 'wppilot',
            ),
            'copy' => $mcp_name,
        ],
        [
            'title' => __('Enter the server URL', domain: 'wppilot'),
            'body' => __(
                'Leave the transport type as HTTP, paste this as the Server URL, and save. Manus then checks it can reach the server and lists the tools it found. Leave the OAuth client id and secret under Advanced settings empty, and sign in when prompted.',
                domain: 'wppilot',
            ),
            'copy' => $mcp_url,
        ],
    ];
}

/**
 * Mistral Le Chat: Connectors, then the Custom MCP Connector tab.
 *
 * @return list<array<string, string>>
 */
function wppilot_oauth_mistral_steps(string $mcp_name, string $mcp_url): array
{
    return [
        [
            'title' => __('Open Connectors', domain: 'wppilot'),
            'body' => __(
                'In Le Chat, open the Connectors page and press "+ Add Connector", then switch to the Custom MCP Connector tab.',
                domain: 'wppilot',
            ),
        ],
        [
            'title' => __('Name the connector', domain: 'wppilot'),
            'body' => __(
                'Le Chat treats the name as an identifier, so it takes no spaces or punctuation. Use this:',
                domain: 'wppilot',
            ),
            'copy' => $mcp_name,
        ],
        [
            'title' => __('Enter the server URL and connect', domain: 'wppilot'),
            'body' => __(
                'Paste this as the server URL and press Connect. Le Chat detects how the server authenticates and runs the sign-in itself. Afterwards the connector appears in the tools dropdown — enable it in the chat you want to use it in.',
                domain: 'wppilot',
            ),
            'copy' => $mcp_url,
        ],
    ];
}

/**
 * Perplexity: Settings, then Connectors.
 *
 * @return list<array<string, string>>
 */
function wppilot_oauth_perplexity_steps(string $mcp_name, string $mcp_url): array
{
    return [
        [
            'title' => __('Open Connectors', domain: 'wppilot'),
            'body' => __(
                'Click your profile picture at the bottom left, open Settings, and go to Connectors. Custom remote connectors need Pro, Max or Enterprise.',
                domain: 'wppilot',
            ),
        ],
        [
            'title' => __('Add a custom connector', domain: 'wppilot'),
            'body' => __('Choose to add a custom connector and name it:', domain: 'wppilot'),
            'copy' => $mcp_name,
        ],
        [
            'title' => __('Enter the server URL', domain: 'wppilot'),
            'body' => __(
                'Paste this as the remote MCP server URL and save, then complete the sign-in. Perplexity requires HTTPS and connects from its own servers, so a localhost URL cannot work. On a team plan you can keep the connector private or share it with the organization.',
                domain: 'wppilot',
            ),
            'copy' => $mcp_url,
        ],
    ];
}

/**
 * A message-only client entry: no config, just an explanation. Used for a cloud client on a local
 * site, where the client's servers cannot reach the site so no working config exists.
 *
 * @return array<string, string>
 */
function wppilot_oauth_cloud_only_notice(string $client_label): array
{
    return [
        'kind' => 'notice',
        'message' => sprintf(
            /* translators: %s: the AI client name, e.g. ChatGPT */
            __(
                '%s connects to your site from its own servers, so it can only reach a site that is available over the public internet, not one that runs only on your local machine.',
                domain: 'wppilot',
            ),
            $client_label,
        ),
    ];
}

/**
 * OAuth per-client connection configs, mirroring the app-password client list.
 *
 * On a publicly reachable site each client gets its native remote-MCP form (a URL the client
 * connects to and runs OAuth against itself), except a tail of clients whose native OAuth flow
 * is not verified, which fall back to the mcp-remote stdio bridge. On a local site every client
 * uses the bridge (which also carries the self-signed TLS bypass), and the browser-only
 * Claude.ai target is omitted because a local URL is unreachable for it.
 *
 * ChatGPT is cloud-only like Claude.ai but always kept in the list: publicly it gets the
 * developer-mode plugin steps, locally a notice explaining it needs a public site.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_build_oauth_configs(string $mcp_url, string $mcp_name): array
{
    // The TLS-verification bypass rides along only for a self-signed local certificate, the same
    // condition the app-password flow uses; a plain-HTTP local site or a trusted cert needs no flag.
    $env = wppilot_likely_self_signed_https() ? ['NODE_TLS_REJECT_UNAUTHORIZED' => '0'] : [];
    if (wppilot_host_unreachable_from_cloud()) {
        return (
            wppilot_build_oauth_bridge_configs($mcp_url, $mcp_name, $env)
            + [
                'chatgpt' => wppilot_oauth_cloud_only_notice('ChatGPT'),
                'manus' => wppilot_oauth_cloud_only_notice('Manus'),
                'mistral-lechat' => wppilot_oauth_cloud_only_notice('Mistral Le Chat'),
                'perplexity' => wppilot_oauth_cloud_only_notice('Perplexity'),
            ]
        );
    }
    return (
        wppilot_build_oauth_public_configs($mcp_url, $mcp_name)
        + wppilot_build_oauth_web_ui_configs($mcp_url, $mcp_name)
    );
}

/**
 * The hosted web UIs, which add an MCP server through their own interface.
 *
 * None of them reads a configuration file, so each is a list of steps rather
 * than a snippet — and each names a different menu for the same action, which is
 * exactly the thing worth writing down. All of them connect from their own
 * servers, so they only appear on a publicly reachable site; the local set
 * replaces them with a notice explaining why.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_build_oauth_web_ui_configs(string $mcp_url, string $mcp_name): array
{
    $steps_entry = static fn(array $steps): array => [
        'kind' => 'code',
        'code' => '',
        'hint' => '',
        'paths' => [],
        'isShell' => false,
        'steps' => $steps,
    ];

    return [
        'chatgpt' => $steps_entry(wppilot_oauth_chatgpt_steps($mcp_name, $mcp_url)),
        'manus' => $steps_entry(wppilot_oauth_manus_steps($mcp_name, $mcp_url)),
        'mistral-lechat' => $steps_entry(wppilot_oauth_mistral_steps($mcp_name, $mcp_url)),
        'perplexity' => $steps_entry(wppilot_oauth_perplexity_steps($mcp_name, $mcp_url)),
    ];
}

/**
 * Public site: native form per client, with the mcp-remote bridge as the fallback for the tail
 * of clients whose native OAuth flow is not verified. A public host has a trusted certificate,
 * so the bridge needs no TLS bypass.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_build_oauth_public_configs(string $mcp_url, string $mcp_name): array
{
    $bridge = wppilot_build_oauth_bridge_configs($mcp_url, $mcp_name, []);
    // Only Roo Code is left on the bridge: every other client that used to be
    // here now documents its own OAuth flow (see wppilot_build_oauth_native_more()).
    $tail = ['roo-code'];

    return array_merge(
        array_intersect_key($bridge, array_flip($tail)),
        wppilot_build_oauth_native_configs($mcp_url, $mcp_name),
        wppilot_build_oauth_native_more($mcp_url, $mcp_name),
        wppilot_build_oauth_native_agents($mcp_url, $mcp_name),
    );
}

/**
 * Native OAuth entries for the clients whose own documentation now describes a
 * browser sign-in against a remote URL. Each gets the bare remote shape its
 * docs show — no header, so the client discovers this site's OAuth metadata —
 * plus the one command that starts the sign-in where the client needs one.
 * Sources, all checked 2026-09-30, are on each entry.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_build_oauth_native_more(string $mcp_url, string $mcp_name): array
{
    $opts = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
    $opencode = (string) json_encode(['mcp' => [$mcp_name => ['type' => 'remote', 'url' => $mcp_url]]], $opts);

    return [
        // https://zed.dev/docs/ai/mcp — no Authorization header means Zed runs
        // the standard MCP OAuth flow.
        'zed' => wppilot_oauth_code_entry(
            (string) json_encode(['context_servers' => [$mcp_name => ['url' => $mcp_url]]], $opts),
            wppilot_oauth_add_to('settings.json'),
            wppilot_zed_paths(),
        ),
        // https://docs.cline.bot/mcp/mcp-overview — omitting `type` means SSE.
        'cline' => wppilot_oauth_code_entry(
            wppilot_oauth_json('mcpServers', $mcp_name, ['type' => 'streamableHttp', 'url' => $mcp_url]),
            wppilot_oauth_add_to('cline_mcp_settings.json'),
            [
                __('Via UI', domain: 'wppilot') => __(
                    'Cline sidebar → MCP Servers → Configure MCP Servers',
                    domain: 'wppilot',
                ),
                'Cline CLI' => '~/.cline/mcp.json',
            ],
        ),
        // https://kilo.ai/docs/automate/mcp/using-in-kilo-code
        'kilo-code' => wppilot_oauth_code_entry(
            $opencode,
            wppilot_oauth_add_to('kilo.jsonc'),
            wppilot_kilo_code_paths(),
        ),
        // https://opencode.ai/docs/mcp-servers/ — `opencode mcp auth <name>`
        // starts the sign-in by hand if the automatic one did not.
        'opencode' => wppilot_oauth_code_entry(
            $opencode,
            wppilot_oauth_add_to('opencode.json'),
            [
                __('Project', domain: 'wppilot') => 'opencode.json',
                __('Global', domain: 'wppilot') => '~/.config/opencode/opencode.json',
            ],
        ),
        // https://kiro.dev/docs/mcp/configuration/ and
        // https://docs.aws.amazon.com/amazonq/latest/qdeveloper-ug/mcp-ide.html
        'amazon-q' => wppilot_oauth_code_entry(
            wppilot_oauth_json('mcpServers', $mcp_name, ['url' => $mcp_url]),
            wppilot_oauth_add_to('mcp.json'),
            wppilot_amazon_q_kiro_paths(),
        ),
    ];
}

/**
 * The terminal agents in the same position as wppilot_build_oauth_native_more(): each documents
 * its own OAuth sign-in, and each starts it differently, which is why the entries differ in kind.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_build_oauth_native_agents(string $mcp_url, string $mcp_name): array
{
    $sq = static fn(string $v): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $v) . "'";

    return [
        // https://docs.openclaw.ai/cli/mcp/transports
        'openclaw' => [
            'kind' => 'code',
            'code' => implode("\n", [
                'openclaw mcp set ' . $sq($mcp_name) . ' ' . $sq((string) json_encode([
                    'url' => $mcp_url,
                    'transport' => 'streamable-http',
                    'auth' => 'oauth',
                ], JSON_UNESCAPED_SLASHES)),
                'openclaw mcp login ' . $sq($mcp_name),
            ]),
            'hint' => __(
                'Run both commands. The login prints an authorization URL — approve it in the browser and OpenClaw finishes the exchange.',
                domain: 'wppilot',
            ),
            'paths' => [],
            'isShell' => true,
        ],
        // https://moonshotai.github.io/kimi-code/en/customization/mcp.html
        'kimi-cli' => wppilot_oauth_code_entry(
            wppilot_oauth_json('mcpServers', $mcp_name, ['url' => $mcp_url]),
            wppilot_oauth_add_to('mcp.json'),
            wppilot_kimi_code_paths(),
            sprintf(
                /* translators: %s: sign-in command in <code> tags */
                __('To sign in, start a new Kimi Code session and run %s.', domain: 'wppilot'),
                '<code>/mcp-config login ' . esc_html($mcp_name) . '</code>',
            ),
        ),
        // https://qwenlm.github.io/qwen-code-docs/en/users/features/mcp/ —
        // client id and endpoints are optional with dynamic registration.
        'qwen-code' => wppilot_oauth_code_entry(
            wppilot_oauth_json('mcpServers', $mcp_name, ['httpUrl' => $mcp_url, 'oauth' => ['enabled' => true]]),
            wppilot_oauth_add_to('settings.json'),
            [
                __('Global', domain: 'wppilot') => '~/.qwen/settings.json',
                __('Project', domain: 'wppilot') => '.qwen/settings.json',
            ],
        ),
        // https://zcode.z.ai/en/docs/mcp-services — the manager's form, then an
        // "Open authorization" button on the server's row.
        'zcode' => [
            'kind' => 'code',
            'code' => '',
            'hint' => '',
            'paths' => [],
            'isShell' => false,
            'steps' => [
                [
                    'title' => __('Add the server', domain: 'wppilot'),
                    'body' => __(
                        'In ZCode open Settings, MCP Servers, and click New MCP Server. Choose HTTP as the type, name it, and paste this URL. Enable OAuth for it.',
                        domain: 'wppilot',
                    ),
                    'copy' => $mcp_url,
                ],
                [
                    'title' => __('Authorize', domain: 'wppilot'),
                    'body' => __(
                        'An Open authorization button appears on the server\'s row. Click it, approve in the browser, and ZCode reconnects and loads the tools.',
                        domain: 'wppilot',
                    ),
                ],
            ],
        ],
        // https://docs.factory.ai/cli/configuration/mcp
        'factory-droid' => [
            'kind' => 'code',
            'code' => 'droid mcp add ' . $sq($mcp_name) . ' ' . $sq($mcp_url) . ' --type http',
            'hint' => __('Run in your terminal, then open /mcp inside Droid and complete the browser sign-in.', domain: 'wppilot'),
            'paths' => [],
            'isShell' => true,
        ],
    ];
}

/**
 * Manual walkthrough for the Claude connector clients, which add remote MCP servers from the
 * Connectors UI rather than a config file. The server name keeps the placeholder that the page
 * script swaps for the live name.
 *
 * @return list<array<string, string>>
 */
function wppilot_oauth_connector_steps(string $app_label, string $mcp_name, string $mcp_url): array
{
    return [
        [
            'title' => __('Open Connectors', domain: 'wppilot'),
            /* translators: %s: the client name, e.g. Claude Desktop */
            'body' => sprintf(
                __(
                    'In %s, open Customize and go to Connectors. Custom connectors are available on Free, Pro and Max as well as Team and Enterprise.',
                    domain: 'wppilot',
                ),
                $app_label,
            ),
        ],
        [
            'title' => __('Team or Enterprise: an Owner adds it first', domain: 'wppilot'),
            'body' => __(
                'On those plans the member-level screen cannot create a connector. An Owner adds it once under Organization settings, Connectors, Add, Custom — choosing Web if asked for a type — and everyone else then finds it under Customize, Connectors and presses Connect. On Free, Pro or Max, skip this step.',
                domain: 'wppilot',
            ),
        ],
        [
            'title' => __('Add a custom connector', domain: 'wppilot'),
            'body' => __(
                'Click "Add custom connector" and give it this name, or one you’ll recognize with "GoBuildWP" in it:',
                domain: 'wppilot',
            ),
            'copy' => $mcp_name,
        ],
        [
            'title' => __('Enter the server URL', domain: 'wppilot'),
            'body' => __(
                'Paste the URL below and save. Leave the OAuth Client ID and Secret (under Advanced settings) empty, then sign in when the browser opens.',
                domain: 'wppilot',
            ),
            'copy' => $mcp_url,
        ],
        [
            'title' => __('Turn it on in a chat', domain: 'wppilot'),
            'body' => __(
                'A connector is not enabled in conversations by default. Use the "+" button in the chat, open Connectors, and switch this one on. The same menu is where you turn it off again.',
                domain: 'wppilot',
            ),
        ],
    ];
}

/**
 * CLI alternative shown under the Codex config.toml snippet, for users who prefer the terminal to
 * editing the file. The server name is the placeholder swapped in client-side; both it and the URL
 * are HTML-escaped here because the note is rendered as raw markup, not through the escaping step
 * the config snippets pass through.
 */
function wppilot_oauth_codex_cli_note(string $mcp_name, string $mcp_url): string
{
    return sprintf(
        /* translators: 1: codex mcp add command, 2: codex mcp login command, both in <code> tags */
        __('Prefer the terminal? Run %1$s, then %2$s.', domain: 'wppilot'),
        '<code>codex mcp add ' . esc_html($mcp_name) . ' --url ' . esc_html($mcp_url) . '</code>',
        '<code>codex mcp login ' . esc_html($mcp_name) . '</code>',
    );
}

/**
 * CLI alternative shown under the local (bridge) Codex config.toml snippet. Unlike the public form,
 * this adds the stdio bridge command (npx mcp-remote), carrying the same TLS-bypass env the snippet
 * above uses, and needs no separate login step because the bridge runs the OAuth flow itself.
 *
 * @param array<string, string> $env
 */
function wppilot_oauth_codex_bridge_cli_note(string $mcp_name, string $mcp_url, array $env): string
{
    $cmd = 'codex mcp add ' . esc_html($mcp_name);
    foreach ($env as $key => $value) {
        $cmd .= ' --env ' . esc_html($key) . '=' . esc_html($value);
    }
    $cmd .= ' -- npx -y mcp-remote ' . esc_html($mcp_url);

    /* translators: %s: codex mcp add command in <code> tags */
    return sprintf(__('Prefer the terminal? Run %s.', domain: 'wppilot'), '<code>' . $cmd . '</code>');
}

/**
 * Note under the Copilot snippets: which Copilot surface the file is for, and the one surface that
 * cannot use this method at all. HTML, like the Codex notes; the name is the client-side placeholder.
 */
function wppilot_oauth_copilot_bridge_note(): string
{
    return __(
        'This is Copilot CLI\'s file. Copilot Chat in VS Code uses the VS Code configuration instead, and the Copilot cloud agent cannot use OAuth servers at all — give it an access token.',
        domain: 'wppilot',
    );
}

/**
 * The native-OAuth Copilot note: the bridge note plus the command that restarts Copilot CLI's own
 * sign-in.
 */
function wppilot_oauth_copilot_note(string $mcp_name): string
{
    return sprintf(
        /* translators: 1: which Copilot surfaces this is for, 2: /mcp auth command in <code> tags */
        __('%1$s To sign in again later, run %2$s.', domain: 'wppilot'),
        wppilot_oauth_copilot_bridge_note(),
        '<code>/mcp auth ' . esc_html($mcp_name) . '</code>',
    );
}

/**
 * Antigravity CLI and IDE share the same current remote MCP schema and config locations.
 *
 * @return array<string, mixed>
 */
function wppilot_build_antigravity_oauth_entry(string $mcp_url, string $mcp_name): array
{
    return wppilot_oauth_code_entry(
        wppilot_oauth_json('mcpServers', $mcp_name, ['serverUrl' => $mcp_url]),
        wppilot_oauth_add_to('mcp_config.json'),
        [
            __('Global', domain: 'wppilot') => '~/.gemini/config/mcp_config.json',
            __('Workspace', domain: 'wppilot') => '.agents/mcp_config.json',
        ],
    );
}

/**
 * Native remote configs for clients that run the OAuth flow themselves. Claude Desktop and
 * Claude.ai share the custom-connector prefill (a button, not a snippet); Claude.ai is
 * public-only, which is why it never appears in the local (bridge) set.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_build_oauth_native_configs(string $mcp_url, string $mcp_name): array
{
    // The Gemini-lineage clients are appended from their own builder: they are
    // the only entries here whose URL field disagrees with everyone else's, and
    // keeping them separate is what stops that difference being "tidied" away.
    $tq = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    $connector = wppilot_build_connector_install_link(
        $mcp_url,
        wppilot_build_connector_display_name(get_bloginfo('name')),
    );
    $deeplink =
        'cursor://anysphere.cursor-deeplink/mcp/install?name='
        . $mcp_name
        . '&config='
        . base64_encode((string) json_encode(['url' => $mcp_url]));

    $connector_entry = static fn(string $hint): array => [
        'kind' => 'connector',
        'code' => '',
        'connector' => $connector,
        'hint' => $hint,
        'paths' => [],
        'isShell' => false,
    ];
    $antigravity_entry = wppilot_build_antigravity_oauth_entry($mcp_url, $mcp_name);

    return [
        'claude-code' => [
            'kind' => 'code',
            'code' => 'claude mcp add ' . $mcp_name . ' --transport http ' . $mcp_url,
            'hint' => __('Run in your terminal, then sign in when your browser opens.', domain: 'wppilot'),
            'paths' => [],
            'isShell' => true,
        ],
        // Claude Desktop adds remote servers from its own Connectors UI. The one-click button goes
        // through claude.ai (account level) and only reaches Desktop after a sync, so the in-app
        // walkthrough is the primary here rather than that button.
        'claude-desktop' => [
            'kind' => 'code',
            'code' => '',
            'hint' => '',
            'paths' => [],
            'isShell' => false,
            'steps' => wppilot_oauth_connector_steps('Claude Desktop', $mcp_name, $mcp_url),
        ],
        'claude-ai' => array_merge(
            $connector_entry(__(
                'Add it as a custom connector on claude.ai, then sign in. Works in the browser and in Claude Desktop.',
                domain: 'wppilot',
            )),
            ['steps' => wppilot_oauth_connector_steps('claude.ai', $mcp_name, $mcp_url)],
        ),
        'codex' => wppilot_oauth_code_entry(
            "[mcp_servers.{$mcp_name}]\nurl = " . $tq($mcp_url),
            sprintf(
                /* translators: 1: config.toml file name, 2: codex mcp login command, both in <code> tags */
                __('Add to %1$s. Then sign in with %2$s.', domain: 'wppilot'),
                '<code>config.toml</code>',
                '<code>codex mcp login</code>',
            ),
            ['macOS / Linux' => '~/.codex/config.toml', 'Windows' => '%USERPROFILE%\\.codex\\config.toml'],
            wppilot_oauth_codex_cli_note($mcp_name, $mcp_url),
        ),
        'cursor' => array_merge(
            wppilot_oauth_code_entry(
                wppilot_oauth_json('mcpServers', $mcp_name, ['url' => $mcp_url]),
                /* translators: %s: the config file name, wrapped in a code tag */
                sprintf(__('Use the one-click button, or add to %s.', domain: 'wppilot'), '<code>mcp.json</code>'),
                [
                    __('Global', domain: 'wppilot') => '~/.cursor/mcp.json',
                    __('Project', domain: 'wppilot') => '.cursor/mcp.json',
                ],
            ),
            ['deeplink' => $deeplink],
        ),
        'vscode' => wppilot_oauth_code_entry(
            wppilot_oauth_json('servers', $mcp_name, ['type' => 'http', 'url' => $mcp_url]),
            wppilot_oauth_add_to('mcp.json'),
            [
                __('Workspace', domain: 'wppilot') => '.vscode/mcp.json',
                __('User', domain: 'wppilot') => __(
                    'Run: MCP: Open User Configuration (command palette)',
                    domain: 'wppilot',
                ),
            ],
        ),
        // Copilot CLI: `mcpServers`, `type: "http"`, required `tools`; it runs
        // the OAuth flow itself and /mcp auth <name> restarts it. Sources,
        // checked 2026-09-30:
        // https://docs.github.com/en/copilot/reference/copilot-cli-reference/cli-command-reference
        // https://docs.github.com/en/copilot/concepts/agents/cloud-agent/mcp-and-cloud-agent
        'github-copilot' => wppilot_oauth_code_entry(
            wppilot_oauth_json('mcpServers', $mcp_name, ['type' => 'http', 'url' => $mcp_url, 'tools' => ['*']]),
            wppilot_oauth_add_to('mcp-config.json'),
            wppilot_copilot_cli_paths(),
            wppilot_oauth_copilot_note($mcp_name),
        ),
        'antigravity-cli' => $antigravity_entry,
        'antigravity-ide' => $antigravity_entry,
        'windsurf' => wppilot_oauth_code_entry(
            wppilot_oauth_json('mcpServers', $mcp_name, ['serverUrl' => $mcp_url]),
            wppilot_oauth_add_to('mcp_config.json'),
            wppilot_devin_desktop_paths(),
        ),
    ];
}

/**
 * mcp-remote bridge configs for every client except the browser-only Claude.ai. Used for the
 * whole list on local sites and for the unverified tail on public sites. File paths mirror
 * wppilot_build_configs()'s locations, kept here so the OAuth flow stays isolated from the
 * proven app-password renderer.
 *
 * @param array<string, string> $env
 * @return array<string, array<string, mixed>>
 */
function wppilot_build_oauth_bridge_configs(string $mcp_url, string $mcp_name, array $env): array
{
    $server = wppilot_oauth_bridge_server($mcp_url, $env);

    return array_merge(
        wppilot_build_oauth_bridge_special($mcp_url, $mcp_name, $env, $server),
        wppilot_build_oauth_bridge_standard(
            wppilot_oauth_json('mcpServers', $mcp_name, $server),
            wppilot_oauth_json('servers', $mcp_name, $server),
        ),
    );
}

/**
 * Bridge entries whose format is unique to the client (shell command, TOML, or a bespoke JSON
 * envelope) rather than the shared mcpServers/servers payload.
 *
 * @param array<string, string> $env
 * @param array<string, mixed>  $server
 * @return array<string, array<string, mixed>>
 */
function wppilot_build_oauth_bridge_special(string $mcp_url, string $mcp_name, array $env, array $server): array
{
    $opts = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    $zed_json = (string) json_encode(['context_servers' => [$mcp_name => $server]], $opts);

    $opencode_server = ['type' => 'local', 'command' => ['npx', '-y', 'mcp-remote', $mcp_url]];
    if ($env !== []) {
        $opencode_server['environment'] = $env;
    }
    $opencode_json = (string) json_encode(['mcp' => [$mcp_name => $opencode_server]], $opts);
    $copilot_json = (string) json_encode([
        'mcpServers' => [$mcp_name => array_merge(['type' => 'local'], $server, ['tools' => ['*']])],
    ], $opts);
    $openclaw_json = (string) json_encode(['mcp' => ['servers' => [$mcp_name => $server]]], $opts);

    return [
        'claude-code' => [
            'kind' => 'code',
            'code' => wppilot_oauth_bridge_claude_code($mcp_name, $mcp_url, $env),
            'hint' => __('Run in your terminal, then sign in when your browser opens.', domain: 'wppilot'),
            'paths' => [],
            'isShell' => true,
        ],
        'codex' => wppilot_oauth_code_entry(
            wppilot_oauth_bridge_codex($mcp_name, $mcp_url, $env),
            wppilot_oauth_add_to('config.toml'),
            ['macOS / Linux' => '~/.codex/config.toml', 'Windows' => '%USERPROFILE%\\.codex\\config.toml'],
            wppilot_oauth_codex_bridge_cli_note($mcp_name, $mcp_url, $env),
        ),
        'zed' => wppilot_oauth_code_entry($zed_json, wppilot_oauth_add_to('settings.json'), [
            'macOS / Linux' => '~/.config/zed/settings.json',
        ]),
        'opencode' => wppilot_oauth_code_entry($opencode_json, wppilot_oauth_add_to('opencode.json'), [
            __('Project', domain: 'wppilot') => 'opencode.json',
            __('Global', domain: 'wppilot') => '~/.config/opencode/opencode.json',
        ]),
        'kilo-code' => wppilot_oauth_code_entry(
            $opencode_json,
            wppilot_oauth_add_to('kilo.jsonc'),
            wppilot_kilo_code_paths(),
        ),
        'github-copilot' => wppilot_oauth_code_entry(
            $copilot_json,
            wppilot_oauth_add_to('mcp-config.json'),
            wppilot_copilot_cli_paths(),
            wppilot_oauth_copilot_bridge_note(),
        ),
        'openclaw' => wppilot_oauth_code_entry(
            $openclaw_json,
            wppilot_oauth_add_to('openclaw.json'),
            wppilot_openclaw_paths(),
        ),
    ];
}

/**
 * Bridge entries that reuse the shared mcpServers/servers JSON payloads. File paths mirror
 * wppilot_build_configs()'s locations.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_build_oauth_bridge_standard(string $mcp_servers_json, string $servers_json): array
{
    return [
        'claude-desktop' => wppilot_oauth_code_entry(
            $mcp_servers_json,
            wppilot_oauth_add_to('claude_desktop_config.json'),
            [
                'macOS' => '~/Library/Application Support/Claude/claude_desktop_config.json',
                'Windows' => '%APPDATA%\\Claude\\claude_desktop_config.json',
            ],
        ),
        'antigravity-cli' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('mcp_config.json'), [
            __('Global', domain: 'wppilot') => '~/.gemini/config/mcp_config.json',
            __('Workspace', domain: 'wppilot') => '.agents/mcp_config.json',
        ]),
        'antigravity-ide' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('mcp_config.json'), [
            __('Global', domain: 'wppilot') => '~/.gemini/config/mcp_config.json',
            __('Workspace', domain: 'wppilot') => '.agents/mcp_config.json',
        ]),
        'cursor' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('mcp.json'), [
            __('Global', domain: 'wppilot') => '~/.cursor/mcp.json',
            __('Project', domain: 'wppilot') => '.cursor/mcp.json',
        ]),
        'kimi-cli' => wppilot_oauth_code_entry(
            $mcp_servers_json,
            wppilot_oauth_add_to('mcp.json'),
            wppilot_kimi_code_paths(),
        ),
        'factory-droid' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('mcp.json'), [
            __('Global', domain: 'wppilot') => '~/.factory/mcp.json',
            __('Project', domain: 'wppilot') => '.factory/mcp.json',
        ]),
        'qwen-code' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('settings.json'), [
            __('Global', domain: 'wppilot') => '~/.qwen/settings.json',
            __('Project', domain: 'wppilot') => '.qwen/settings.json',
        ]),
        'gemini-cli' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('settings.json'), [
            __('Global', domain: 'wppilot') => '~/.gemini/settings.json',
            __('Project', domain: 'wppilot') => '.gemini/settings.json',
        ]),
        'zcode' => wppilot_oauth_code_entry(
            $mcp_servers_json,
            __('Add through ZCode\'s MCP server manager, choosing the stdio transport.', domain: 'wppilot'),
            [],
        ),
        'vscode' => wppilot_oauth_code_entry($servers_json, wppilot_oauth_add_to('mcp.json'), [
            __('Workspace', domain: 'wppilot') => '.vscode/mcp.json',
            __('User', domain: 'wppilot') => __(
                'Run: MCP: Open User Configuration (command palette)',
                domain: 'wppilot',
            ),
        ]),
        'windsurf' => wppilot_oauth_code_entry(
            $mcp_servers_json,
            wppilot_oauth_add_to('mcp_config.json'),
            wppilot_devin_desktop_paths(),
        ),
        'cline' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('cline_mcp_settings.json'), [
            __('Via UI', domain: 'wppilot') => __(
                'Cline sidebar → MCP Servers → Configure MCP Servers',
                domain: 'wppilot',
            ),
        ]),
        'roo-code' => wppilot_oauth_code_entry($mcp_servers_json, wppilot_oauth_add_to('mcp.json'), [
            __('Project', domain: 'wppilot') => '.roo/mcp.json',
            __('Via UI', domain: 'wppilot') => __(
                'Roo Code sidebar → MCP Servers → Configure MCP Servers',
                domain: 'wppilot',
            ),
        ]),
        'amazon-q' => wppilot_oauth_code_entry(
            $mcp_servers_json,
            wppilot_oauth_add_to('mcp.json'),
            wppilot_amazon_q_kiro_paths(),
        ),
    ];
}
