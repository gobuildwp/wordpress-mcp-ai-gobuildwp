<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * A per-credential budget on ability execution.
 *
 * OAuth client registration has been rate limited from the start, but tool
 * execution has not: an authenticated client could call execute-ability in a
 * loop, and under Developer Full Access that includes execute-php and WP-CLI.
 * A runaway agent is the likely cause rather than an attacker — but the effect
 * on a production site is the same either way.
 *
 * The budget is a fixed window per credential, not per user: two agents holding
 * different application passwords get their own allowance, so one misbehaving
 * client cannot starve another. Reads are exempt, because throttling discovery
 * only makes agents retry, and the damage a read can do is bounded already.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_RATE_WINDOW_SECONDS = 60;

const WPPILOT_RATE_DEFAULT_LIMIT = 120;

/**
 * Calls a single credential may execute per minute.
 *
 * Zero or a negative value disables the limit entirely, which is the documented
 * escape hatch for a site doing a large scripted migration.
 *
 * @param string $credential The credential being budgeted, as wppilot_rate_credential_id() names it.
 */
function wppilot_rate_limit(string $credential = ''): int
{
    /**
     * Filter the per-credential ability execution budget, per minute.
     *
     * The second argument arrived in 1.17.0; a callback registered with one
     * accepted argument keeps receiving only the limit.
     *
     * @param int    $limit      Calls per minute. 0 or less disables the limit.
     * @param string $credential The credential being budgeted: `ap:<uuid>`, `oauth:<sha256>` or `user:<id>`.
     */
    /** @var mixed $limit */
    $limit = apply_filters('wppilot_tool_call_rate_limit', WPPILOT_RATE_DEFAULT_LIMIT, $credential);

    return is_int($limit) ? $limit : WPPILOT_RATE_DEFAULT_LIMIT;
}

/**
 * Transient key for the current window.
 *
 * The window start is folded into the key, so an expired window simply misses
 * rather than needing to be reset — there is no cleanup path to get wrong.
 */
function wppilot_rate_bucket_key(string $credential): string
{
    $window = (int) floor(time() / WPPILOT_RATE_WINDOW_SECONDS);

    return 'wppilot_rate_' . md5($credential . '|' . $window);
}

/**
 * Count this call against the credential's budget.
 *
 * @return array{allowed: bool, used: int, limit: int, retry_after: int}
 */
function wppilot_rate_consume(string $credential): array
{
    $limit = wppilot_rate_limit($credential);
    if ($limit <= 0) {
        return ['allowed' => true, 'used' => 0, 'limit' => 0, 'retry_after' => 0];
    }

    $key = wppilot_rate_bucket_key($credential);
    /** @var mixed $stored */
    $stored = get_transient($key);

    // A scalar option comes back from the database as a string, so the counter
    // written as int 1 reads back as "1". Testing with is_int() here silently
    // reset the count on every request, and the limit only ever fired within a
    // single request — which no real client produces.
    $used = is_numeric($stored) ? (int) $stored : 0;

    // Seconds left in this window, so the client is told when to come back
    // rather than being left to guess.
    $retry_after = WPPILOT_RATE_WINDOW_SECONDS - (time() % WPPILOT_RATE_WINDOW_SECONDS);

    if ($used >= $limit) {
        return ['allowed' => false, 'used' => $used, 'limit' => $limit, 'retry_after' => $retry_after];
    }

    // Expiry is a little past the window so a call landing on the boundary
    // cannot resurrect a stale count.
    set_transient($key, $used + 1, WPPILOT_RATE_WINDOW_SECONDS + 5);

    return ['allowed' => true, 'used' => $used + 1, 'limit' => $limit, 'retry_after' => $retry_after];
}

/**
 * Identify the caller for budgeting.
 *
 * Application passwords are budgeted per credential: WordPress exposes the
 * password uuid, so two agents authenticating as the same user get separate
 * allowances.
 *
 * OAuth uses the registered client id recovered from the validated token. A
 * user-id fallback remains for legacy/internal OAuth identities that predate
 * request-local client accounting.
 */
function wppilot_rate_credential_id(): string
{
    // @mago-expect lint:no-global -- WordPress publishes the authenticating
    // application password only as a global; there is no accessor for it.
    global $wp_rest_application_password_uuid;

    /** @var mixed $wp_rest_application_password_uuid */
    $uuid = $wp_rest_application_password_uuid;
    if (is_string($uuid) && $uuid !== '') {
        return 'ap:' . $uuid;
    }

    if (function_exists('WPPilot\\OAuth\\Middleware\\request_oauth_identity')) {
        $identity = \WPPilot\OAuth\Middleware\request_oauth_identity();
        $client_id = is_array($identity) && is_string($identity['client_id'] ?? null) ? $identity['client_id'] : '';
        if ($client_id !== '') {
            return 'oauth:' . hash('sha256', $client_id);
        }
    }

    return 'user:' . get_current_user_id();
}

/**
 * Refuse an ability call once the credential is over budget.
 *
 * Runs at priority 6, after the safety policy at 5. Filters on this hook chain,
 * and every other callback types its first parameter as `array` — so returning
 * a WP_Error before them makes the next one fail on the type, which the adapter
 * reports as a bare "Internal error" with the real reason lost. Refusing last
 * means the WP_Error goes straight back to the adapter.
 *
 * Accepts `mixed` and passes non-arrays through for the same reason: whatever
 * runs before this must be able to refuse without breaking it.
 *
 * @param mixed $args
 * @return mixed
 */
function wppilot_rate_pre_mcp_tool_call(mixed $args, string $tool_name): mixed
{
    if (!is_array($args) || $tool_name !== 'mcp-adapter-execute-ability') {
        return $args;
    }

    $ability_name = is_string($args['ability_name'] ?? null) ? $args['ability_name'] : '';
    $ability = $ability_name !== '' && function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null;

    // Discovery and reads stay free: throttling them just makes an agent retry,
    // and a read cannot damage the site.
    if ($ability instanceof WP_Ability && wppilot_ability_is_readonly($ability)) {
        return $args;
    }

    $result = wppilot_rate_consume(wppilot_rate_credential_id());
    if ($result['allowed']) {
        return $args;
    }

    return wppilot_rate_limit_error($result);
}

/**
 * Apply the same write budget to direct Ability execution.
 *
 * @param mixed $input Validated Ability input.
 * @return mixed The unchanged input, or a rate-limit error.
 */
function wppilot_rate_pre_ability_execute(mixed $input, WP_Ability $ability, string $transport): mixed
{
    // 'mcp' is the modern transport and 'chat' the admin Chat; both reach this
    // through wppilot_gate_ability_call(). 'approval' is deliberately absent: a
    // held call was charged when the agent sent it, and charging the approver
    // again would refuse an approval for a reason they cannot act on. 'nested'
    // is absent for the same reason: the kit ability that runs it was charged.
    if (!in_array($transport, ['rest', 'mcp', 'chat'], strict: true) || wppilot_ability_is_readonly($ability)) {
        return $input;
    }

    $result = wppilot_rate_consume(wppilot_rate_credential_id());
    if ($result['allowed']) {
        return $input;
    }

    return wppilot_rate_limit_error($result);
}

/**
 * Build the stable error returned by every execution transport.
 *
 * @param array{allowed: bool, used: int, limit: int, retry_after: int} $result
 */
function wppilot_rate_limit_error(array $result): WP_Error
{
    return new WP_Error(
        'wppilot_rate_limited',
        sprintf(
            /* translators: 1: calls allowed per minute, 2: seconds until the window resets */
            __(
                'Rate limit reached: this credential may run %1$d write calls per minute. Wait %2$d seconds and retry. Read-only abilities are not limited.',
                domain: 'wppilot',
            ),
            $result['limit'],
            $result['retry_after'],
        ),
        ['status' => 429, 'retry_after' => $result['retry_after'], 'limit' => $result['limit']],
    );
}

add_filter('mcp_adapter_pre_tool_call', callback: 'wppilot_rate_pre_mcp_tool_call', priority: 6, accepted_args: 2);
add_filter('wppilot_pre_ability_execute', callback: 'wppilot_rate_pre_ability_execute', priority: 6, accepted_args: 3);
