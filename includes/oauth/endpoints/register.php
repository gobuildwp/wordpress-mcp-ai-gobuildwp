<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- OAuth 2.1 protocol endpoint called by external MCP clients; WordPress nonces cannot exist in this flow. Input is validated against the RFC grammar and rejected with protocol errors, and redirects go to client-registered callback URLs as the spec requires.

namespace WPPilot\OAuth\Endpoints\Register;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPPilot\OAuth\ClientValidation;
use WPPilot\OAuth\Repositories\ClientRepository;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    register_rest_route('wppilot/v1', route: '/oauth/register', args: [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => __NAMESPACE__ . '\\handle',
    ]);
}

/**
 * The troubleshooter's registration self-test authenticates itself with a single-use token
 * (minted server-side into a short transient right before the probe). A matching request skips
 * the per-IP limits: every self-test comes from the server's own address, so counting it would
 * let repeated diagnostics runs saturate that bucket and make the check warn about a rate limit
 * the diagnostics themselves caused. Unforgeable (random, single-use, 60s TTL) and it grants
 * nothing else — caps on live connections still apply.
 */
function is_self_test_request(WP_REST_Request $req): bool
{
    $token = trim((string) $req->get_header('x-wppilot-self-test'));
    if ($token === '') {
        return false;
    }
    $key = 'wppilot_oauth_selftest_' . hash('sha256', $token);
    /** @var mixed $found */
    $found = get_transient($key);
    delete_transient($key);
    return $found === '1' || $found === 1;
}

// @mago-expect lint:cyclomatic-complexity
function handle(WP_REST_Request $req): WP_REST_Response|WP_Error
{
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    ClientValidation\prune_dead_clients();
    $self_test = is_self_test_request($req);
    if ($client_ip !== '' && !$self_test && !ClientValidation\check_and_increment_rate_limit($client_ip)) {
        return new WP_Error('rate_limited', 'Too many registrations', ['status' => 429]);
    }
    if (
        $client_ip !== ''
        && !$self_test
        && ClientValidation\client_count_for_ip($client_ip) >= ClientValidation\MAX_CLIENTS_PER_IP
    ) {
        return new WP_Error('rate_limited', 'Too many registered clients from this address', ['status' => 429]);
    }
    // Cap only live connections, not total rows: active_client_count() ignores pending registrations,
    // which are admin-ungated, so an anonymous DCR flood can no longer exhaust the slots.
    if (ClientValidation\active_client_count() >= ClientValidation\max_clients_per_site()) {
        return new WP_Error('cap_reached', 'Client cap reached', ['status' => 503]);
    }

    $body = $req->get_json_params();
    // @mago-expect analysis:mixed-assignment
    $client_name = sanitize_text_field(trim((string) ($body['client_name'] ?? '')));
    if ($client_name === '' || strlen($client_name) > 191) {
        return new WP_Error('invalid_request', 'client_name must be 1..191 chars', ['status' => 400]);
    }

    // @mago-expect analysis:mixed-assignment
    $redirect_uris = $body['redirect_uris'] ?? null;
    if (!is_array($redirect_uris) || $redirect_uris === []) {
        return new WP_Error('invalid_request', 'redirect_uris must be a non-empty array', ['status' => 400]);
    }
    if (count($redirect_uris) > 5) {
        return new WP_Error('invalid_request', 'Max 5 redirect_uris', ['status' => 400]);
    }

    // @mago-expect analysis:mixed-operand
    $dev_mode = defined('WP_DEBUG') && (bool) \WP_DEBUG;
    $clean_uris = [];
    foreach ($redirect_uris as $uri) {
        $uri = is_string($uri) ? trim($uri) : '';
        if (
            $uri === ''
            || strlen($uri) > ClientValidation\MAX_REDIRECT_URI_LENGTH
            || !ClientValidation\is_allowed_redirect_uri($uri, $dev_mode)
        ) {
            return new WP_Error('invalid_redirect_uri', sprintf('redirect_uri not allowed: %s', esc_html($uri)), [
                'status' => 400,
            ]);
        }
        $clean_uris[] = $uri;
    }

    $clean_uris = array_values(array_unique($clean_uris));
    $client_id = (new ClientRepository())->create($client_name, $clean_uris, $client_ip);

    return new WP_REST_Response([
        'client_id' => $client_id,
        'client_name' => $client_name,
        'redirect_uris' => $clean_uris,
        'token_endpoint_auth_method' => 'none',
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
    ], 201);
}
