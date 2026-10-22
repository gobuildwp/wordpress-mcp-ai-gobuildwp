<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\OAuth\Endpoints\Token;

use League\OAuth2\Server\Exception\OAuthServerException;
use WP_REST_Request;
use WP_REST_Response;
use WPPilot\OAuth\Bridge;
use WPPilot\OAuth\ClientValidation;
use WPPilot\OAuth\Repositories\ClientRepository;
use WPPilot\OAuth\ServerFactory;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    register_rest_route('wppilot/v1', route: '/oauth/token', args: [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => __NAMESPACE__ . '\\handle',
    ]);
}

function handle(WP_REST_Request $req): WP_REST_Response
{
    $client_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    if ($client_ip !== '' && !ClientValidation\within_endpoint_rate_limit('token', $client_ip)) {
        return new WP_REST_Response(['error' => 'temporarily_unavailable'], 429);
    }

    // RFC 8707: refuse a token request that explicitly targets a resource this server does not
    // expose. An absent resource is fine — the issued token's audience is bound to ours regardless.
    $resource = (string) $req->get_param('resource');
    if (!\WPPilot\OAuth\resource_request_allowed($resource, \WPPilot\OAuth\resource_identifier())) {
        return new WP_REST_Response([
            'error' => 'invalid_target',
            'error_description' => 'The requested resource is not served here.',
        ], 400);
    }

    // The authorization endpoint normalises a `localhost` callback onto the IPv4 literal
    // before the code is issued, so the code carries the normalised form. League compares
    // that against this request's redirect_uri as an exact string, and a client that sends
    // back the spelling it started with would fail the comparison on the host name alone.
    // Normalising here puts both sides in the same form; a client that never used a
    // loopback callback is unaffected, because the helper returns anything else untouched.
    require_once dirname(__DIR__) . '/client-id-metadata.php';
    $body_params = $req->get_body_params();
    if (isset($body_params['redirect_uri']) && is_string($body_params['redirect_uri'])) {
        $body_params['redirect_uri'] = \WPPilot\OAuth\ClientIdMetadata\normalize_loopback_uri($body_params['redirect_uri']);
        $req->set_body_params($body_params);
    }

    // Single use is enforced durably in the repositories: revokeAuthCode()/revokeRefreshToken()
    // atomically claim the credential (UPDATE ... WHERE revoked = 0) after league has validated it,
    // so a concurrent or repeated redemption cannot complete. No advisory lock is involved.
    try {
        $server = ServerFactory\build_authorization_server();
        $psr7Response = $server->respondToAccessTokenRequest(Bridge\to_psr7($req), Bridge\new_psr7_response());

        $body = $req->get_body_params();
        $client_id = (string) ($body['client_id'] ?? '');
        if ($client_id !== '') {
            (new ClientRepository())->touchLastUsed($client_id);
        }

        return Bridge\from_psr7($psr7Response);
    } catch (OAuthServerException $e) {
        return Bridge\from_psr7($e->generateHttpResponse(Bridge\new_psr7_response()));
    } catch (\Exception $e) {
        return Bridge\from_psr7(
            OAuthServerException::serverError('Internal server error', $e)->generateHttpResponse(
                Bridge\new_psr7_response(),
            ),
        );
    }
}
