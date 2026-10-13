<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Agent identities: what the access token behind a request may do.
 *
 * An access token used to carry its owner's full access. On most sites that is an
 * administrator, so a token minted for a nightly SEO report could equally delete
 * every page, and the only control was the site-wide safety profile, which also
 * binds the people using Chat. A token now carries its own limits:
 *
 * - a scope: an allowlist of abilities (`rank-math/set-link-settings`), whole
 *   providers (`rank-math/*`) and ability categories. Null means every ability,
 *   which is what every token minted before schema 2 has.
 * - a ceiling: a safety profile the token can never exceed. The effective profile
 *   is the stricter of the site's and the token's, so a ceiling only takes access
 *   away — a Read Only site stays Read Only whatever a token says.
 *
 * Both are enforced where the safety profile already is — wppilot_safety_profile_allows_ability(),
 * wppilot_safety_check_ability() and the `wp_ability_permission_result` filter — so
 * every transport, discovery included, answers the same way without a check of its
 * own. The request's token is read from the Bearer middleware's request-local
 * identity, never from anything the caller sends in the body.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The id of the access token that authenticated this request, or 0.
 *
 * 0 for cookies, application passwords, OAuth, WP-CLI and cron: none of them is
 * an access token, and none of them is restricted by this module.
 */
function wppilot_current_token_id(): int
{
    if (!function_exists('WPPilot\\OAuth\\Middleware\\request_oauth_identity')) {
        return 0;
    }

    $identity = \WPPilot\OAuth\Middleware\request_oauth_identity();
    if (!is_array($identity) || ($identity['via'] ?? '') !== 'token') {
        return 0;
    }

    $client_id = (string) ($identity['client_id'] ?? '');

    return str_starts_with($client_id, 'token-') ? (int) substr($client_id, offset: strlen('token-')) : 0;
}

/**
 * The limits of the token behind this request, or null when no token is in play.
 *
 * A token whose row cannot be read (revoked mid-request, or its owner deleted)
 * comes back as a policy that allows nothing. Treating a missing row as "no
 * limits" would turn a failed lookup into full access.
 *
 * @return array{id: int, name: string, scope: array{abilities: list<string>, categories: list<string>}|null, ceiling: string, missing?: bool}|null
 */
function wppilot_current_token_policy(): ?array
{
    $token_id = wppilot_current_token_id();
    if ($token_id <= 0 || !function_exists('wppilot_token_policy')) {
        return null;
    }

    $policy = wppilot_token_policy($token_id);
    if ($policy !== null) {
        return $policy;
    }

    return [
        'id' => $token_id,
        'name' => '',
        'scope' => ['abilities' => [], 'categories' => []],
        'ceiling' => 'readonly',
        'missing' => true,
    ];
}

/**
 * Whether a scope lets an ability through. A null scope lets everything through.
 *
 * @param array{abilities: list<string>, categories: list<string>}|null $scope
 */
function wppilot_token_scope_allows(?array $scope, WP_Ability $ability): bool
{
    if ($scope === null) {
        return true;
    }

    $name = $ability->get_name();
    foreach ($scope['abilities'] as $entry) {
        if ($entry === $name) {
            return true;
        }
        // `provider/*` covers what a plugin adds later, which is the point of
        // granting a provider rather than listing its abilities one by one.
        if (str_ends_with($entry, '/*') && str_starts_with($name, substr($entry, offset: 0, length: -1))) {
            return true;
        }
    }

    $category = $ability->get_category();

    return $category !== '' && in_array($category, $scope['categories'], strict: true);
}

/**
 * The profile this request's token may not exceed, or '' when there is none.
 */
function wppilot_agent_profile_ceiling(): string
{
    $policy = wppilot_current_token_policy();

    return $policy === null ? '' : $policy['ceiling'];
}

/**
 * Refuse an ability outside the scope of the token behind this request.
 *
 * Null when the call may proceed. Hub-protected abilities (the MCP adapter's
 * discovery meta-tools, the skills loader) always pass: without them a scoped
 * agent could not even find the abilities it is allowed, and they execute
 * nothing themselves — the adapter's execute meta-tool runs the target ability
 * through WP_Ability::execute(), which asks this again for the real target.
 */
function wppilot_agent_scope_error(WP_Ability $ability): ?WP_Error
{
    $policy = wppilot_current_token_policy();
    if ($policy === null) {
        return null;
    }

    $name = $ability->get_name();
    if (function_exists('wppilot_ability_is_hub_protected') && wppilot_ability_is_hub_protected($name)) {
        return null;
    }

    if (($policy['missing'] ?? false) === true) {
        return new WP_Error(
            'wppilot_token_revoked',
            __('The access token behind this request has been revoked. Create a new one on the GoBuildWP Configuration screen.', domain: 'wppilot'),
            ['status' => 403, 'ability' => $name],
        );
    }

    if (wppilot_token_scope_allows($policy['scope'], $ability)) {
        return null;
    }

    return new WP_Error(
        'wppilot_token_scope_denied',
        sprintf(
            /* translators: 1: access token name, 2: ability name */
            __(
                'The access token "%1$s" is not allowed to use "%2$s". This is a limit the site owner set on the token, not a fault: do not retry. Ask them to widen the token\'s scope on the GoBuildWP Configuration screen if the task needs it.',
                domain: 'wppilot',
            ),
            $policy['name'],
            $name,
        ),
        ['status' => 403, 'ability' => $name, 'token' => $policy['id']],
    );
}

/**
 * Credit a write made through the REST run route with an access token to that token.
 *
 * The MCP routes resolve the agent in the Troubleshoot module, which only looks at
 * MCP routes. A token is also accepted on `/wppilot/v1/abilities/…/run`, and a
 * write there was recorded in the ledger as `direct` — as though nobody's agent had
 * made it — which is exactly the attribution a scoped token exists to provide.
 *
 * Runs after that module (priority 20), and only fills an identity that is still
 * `direct`, so an MCP request keeps the richer one resolved there.
 */
function wppilot_agent_attribute_token_request(mixed $result, mixed $server, WP_REST_Request $request): mixed
{
    if (wppilot_current_token_id() <= 0 || !function_exists('wppilot_current_agent')) {
        return $result;
    }
    if (wppilot_current_agent()['method'] !== 'direct') {
        return $result;
    }

    $client = function_exists('wppilot_connection_client')
        ? wppilot_connection_client($request)
        : ['name' => '', 'version' => ''];
    $agent = wppilot_resolve_agent('token', get_current_user_id(), $client);
    wppilot_current_agent($agent);
    if (function_exists('wppilot_session_from_request')) {
        wppilot_session_from_request($request, $agent);
    }

    return $result;
}

add_filter('rest_pre_dispatch', callback: 'wppilot_agent_attribute_token_request', priority: 21, accepted_args: 3);
