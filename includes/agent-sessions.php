<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Agent sessions: which run of an agent a ledger row belongs to.
 *
 * The ledger already says which credential and which client made a write. That is not enough to
 * answer "undo what the agent did just now": one Claude Code credential makes writes for weeks,
 * and an undo scoped to it would reach back through every earlier task. A session is the unit a
 * person means by "that run".
 *
 * Where it comes from, in order of how much the transport tells us:
 *
 * - `mcp-…`   The legacy MCP transport hands out an `Mcp-Session-Id` the client echoes on every
 *             call. That is exactly one client connection. Stored as a keyed hash, never raw:
 *             the ledger is readable by every GoBuildWP administrator and the id is the handle the
 *             adapter looks the session up by.
 * - `stdio-…` `wp wppilot mcp serve` is one process per client connection; the command mints the
 *             id when it starts.
 * - `agent-…` The 2026-07-28 revision is stateless, and so is the REST run route: nothing on the
 *             wire marks where one run ends. Writes from the same credential and client belong to
 *             one session until the agent has been quiet for WPPILOT_SESSION_IDLE_SECONDS.
 *
 * Writes with no agent behind them (wp-admin, cron, plain WP-CLI, another plugin) carry no session:
 * a person clicking Save is not an agent run, and must never be swept into one's undo.
 *
 * Resolved lazily. The entry point only records what the request offers; the id — and for a
 * derived session the one transient write that keeps it alive — is produced only when the ledger
 * records a row, so read-only traffic costs nothing.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** How long a stateless agent may be quiet before its next write starts a new session. */
const WPPILOT_SESSION_IDLE_SECONDS = 1800;

/** Longest session id the ledger stores; the table column is sized to it. */
const WPPILOT_SESSION_ID_MAX = 64;

/**
 * What this request offers to identify its session: a kind (`mcp`, `stdio`, `derived` or '') and
 * the key for it. Set by the MCP entry points only.
 *
 * @param array{kind: string, key: string}|null $set
 * @return array{kind: string, key: string}
 */
function wppilot_session_basis(?array $set = null): array
{
    /** @var array{kind: string, key: string} $basis */
    static $basis = ['kind' => '', 'key' => ''];
    if ($set !== null) {
        $basis = ['kind' => (string) $set['kind'], 'key' => (string) $set['key']];
        wppilot_current_session_id(reset: true);
    }

    return $basis;
}

/**
 * The session id the ledger records for writes made in this request, or '' when none.
 */
function wppilot_current_session_id(bool $reset = false): string
{
    /** @var string|null $resolved */
    static $resolved = null;
    if ($reset) {
        $resolved = null;
        return '';
    }
    if ($resolved !== null) {
        return $resolved;
    }

    $basis = wppilot_session_basis();
    $resolved = match ($basis['kind']) {
        'mcp' => $basis['key'] === '' ? '' : 'mcp-' . substr(hash_hmac('sha256', $basis['key'], wppilot_session_salt()), 0, 24),
        'stdio' => wppilot_session_clip($basis['key']),
        'derived' => wppilot_session_derived_id($basis['key']),
        default => '',
    };

    return $resolved;
}

/**
 * The session a stateless agent is in, starting a new one after WPPILOT_SESSION_IDLE_SECONDS.
 *
 * Each write pushes the expiry forward, so a long task stays one session however long it runs,
 * as long as it keeps working.
 */
function wppilot_session_derived_id(string $key): string
{
    if ($key === '') {
        return '';
    }
    $transient = 'wppilot_session_' . md5($key);
    /** @var mixed $stored */
    $stored = get_transient($transient);
    $id = is_string($stored) && str_starts_with($stored, 'agent-') ? $stored : 'agent-' . str_replace('-', '', (string) wp_generate_uuid4());
    $id = wppilot_session_clip($id);
    set_transient($transient, $id, WPPILOT_SESSION_IDLE_SECONDS);

    return $id;
}

function wppilot_session_clip(string $id): string
{
    $id = (string) preg_replace('/[^A-Za-z0-9_.:-]/', '', $id);

    return substr($id, offset: 0, length: WPPILOT_SESSION_ID_MAX);
}

function wppilot_session_salt(): string
{
    return function_exists('wp_salt') ? wp_salt('auth') : 'wppilot-session';
}

/**
 * Record the session basis of an authenticated MCP or token request.
 *
 * @param array{method: string, credential: string, label: string, client: string, client_version: string} $agent
 */
function wppilot_session_from_request(WP_REST_Request $request, array $agent): void
{
    $header = trim((string) $request->get_header('mcp_session_id'));
    if ($header !== '') {
        wppilot_session_basis(['kind' => 'mcp', 'key' => $header]);
        return;
    }

    // Stateless: the credential and the client are all that separate one agent from another.
    $credential = $agent['credential'] !== '' ? $agent['credential'] : 'user-' . get_current_user_id();
    wppilot_session_basis([
        'kind' => 'derived',
        'key' => $agent['method'] . '|' . $credential . '|' . strtolower($agent['client']),
    ]);
}
