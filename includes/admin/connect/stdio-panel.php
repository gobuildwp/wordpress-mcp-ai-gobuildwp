<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The local connection: `wp wppilot mcp serve` over stdio.
 *
 * For a client on the machine (or in the container) that runs WordPress — Claude Code, Cursor, a CI
 * job — there is no credential to create at all: the client launches WP-CLI, and WP-CLI runs as the
 * user it is told to. Shown collapsed, after the three steps, because most people connect over
 * HTTP and this only helps the ones with shell access to the site.
 *
 * The commands are filled in with this install's path and the signed-in user's login, which is
 * right when the client runs on the same machine; the note says what to change when it does not.
 */
function wppilot_render_stdio_section(): void
{
    $user = wp_get_current_user();
    $login = (string) $user->user_login;
    $path = untrailingslashit(wp_normalize_path(ABSPATH));
    $args = ['--path=' . $path, 'wppilot', 'mcp', 'serve', '--user=' . $login];
    $quoted = implode(' ', array_map(
        static fn(string $arg): string => preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $arg) === 1 ? $arg : "'" . str_replace("'", "'\\''", $arg) . "'",
        $args,
    ));
    $claude = 'claude mcp add gobuildwp-local -- wp ' . $quoted;
    $cursor = (string) wp_json_encode(
        ['mcpServers' => ['gobuildwp-local' => ['command' => 'wp', 'args' => $args]]],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    );
    $script = "printf '%s\\n' '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"initialize\",\"params\":{\"protocolVersion\":\"2025-06-18\",\"capabilities\":{},\"clientInfo\":{\"name\":\"ci\",\"version\":\"1\"}}}' | wp " . $quoted;
    ?>
    <details class="wppilot-stdio" id="wppilot-stdio">
        <summary><strong><?php esc_html_e('Local connection over WP-CLI (Claude Code, Cursor, CI)', domain: 'wppilot'); ?></strong></summary>
        <p class="description">
            <?php esc_html_e(
                'When the AI client runs on the machine that runs this site, it can start GoBuildWP itself instead of connecting over HTTP: no password or token to create. It gets the same abilities, safety profile, confirmations and permission checks as HTTP, as the WordPress user named in --user, and every write it makes is one session on the Changes screen, undoable as one.',
                domain: 'wppilot',
            ); ?>
        </p>
        <p><strong><?php esc_html_e('Claude Code', domain: 'wppilot'); ?></strong></p>
        <pre id="wppilot-stdio-claude"><?php echo esc_html($claude); ?></pre>
        <p><button type="button" class="button wppilot-copy-btn" onclick="wppilotCopy('wppilot-stdio-claude', this)"><?php esc_html_e('Copy', domain: 'wppilot'); ?></button></p>
        <p><strong><?php esc_html_e('Cursor (.cursor/mcp.json) and other clients that take a command', domain: 'wppilot'); ?></strong></p>
        <pre id="wppilot-stdio-cursor"><?php echo esc_html($cursor); ?></pre>
        <p><button type="button" class="button wppilot-copy-btn" onclick="wppilotCopy('wppilot-stdio-cursor', this)"><?php esc_html_e('Copy', domain: 'wppilot'); ?></button></p>
        <p><strong><?php esc_html_e('A script or CI job', domain: 'wppilot'); ?></strong></p>
        <pre id="wppilot-stdio-script"><?php echo esc_html($script); ?></pre>
        <p class="description">
            <?php esc_html_e(
                'The path and user are this site\'s. If WordPress runs in Docker, put "docker exec -i <container>" before "wp". wp must be on the client\'s PATH. Restart the client after switching abilities on or off, since one process serves the whole session.',
                domain: 'wppilot',
            ); ?>
        </p>
    </details>
    <?php
}
