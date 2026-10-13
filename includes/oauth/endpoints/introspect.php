<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\OAuth\Endpoints\Introspect;

use League\OAuth2\Server\Exception\OAuthServerException;
use WP_REST_Request;
use WP_REST_Response;
use WPPilot\OAuth\Bridge;
use WPPilot\OAuth\ServerFactory;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    register_rest_route('wppilot/v1', route: '/oauth/introspect', args: [
        'methods' => 'POST',
        'permission_callback' => static fn(): bool => \wppilot_current_user_can_manage(),
        'callback' => __NAMESPACE__ . '\\handle',
    ]);
}

function handle(WP_REST_Request $req): WP_REST_Response
{
    $body = $req->get_body_params();
    $token = (string) ($body['token'] ?? '');
    if ($token === '') {
        return new WP_REST_Response(['active' => false], 200);
    }

    try {
        $server = ServerFactory\build_resource_server();
        $fake = Bridge\psr7_from_globals()->withHeader('Authorization', 'Bearer ' . $token);
        $validated = $server->validateAuthenticatedRequest($fake);

        $user_id = (string) $validated->getAttribute('oauth_user_id');
        $jti = (string) $validated->getAttribute('oauth_access_token_id');
        $scope = granted_scope_string($validated->getAttribute('oauth_scopes'));

        $exp = 0;
        $iat = 0;
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            $json = base64_decode(strtr($parts[1], from: '-_', to: '+/'), strict: true);
            if ($json !== false) {
                // @mago-expect analysis:mixed-assignment
                $jwt_payload = json_decode($json, associative: true);
                if (is_array($jwt_payload)) {
                    $exp = (int) ($jwt_payload['exp'] ?? 0);
                    $iat = (int) ($jwt_payload['iat'] ?? 0);
                }
            }
        }

        return new WP_REST_Response([
            'active' => true,
            'sub' => $user_id,
            'scope' => $scope,
            'jti' => $jti,
            'exp' => $exp,
            'iat' => $iat,
        ], 200);
    } catch (OAuthServerException $e) {
        return new WP_REST_Response(['active' => false], 200);
    } catch (\Throwable $e) {
        return new WP_REST_Response(['active' => false], 200);
    }
}

function granted_scope_string(mixed $scopes): string
{
    if (is_string($scopes)) {
        $parts = preg_split('/\s+/', trim($scopes));
        return $parts === false ? '' : implode(' ', $parts);
    }
    if (!is_array($scopes)) {
        return '';
    }

    $granted = [];
    // @mago-expect analysis:mixed-assignment
    foreach ($scopes as $scope) {
        if (!is_string($scope) || $scope === '') {
            continue;
        }
        $granted[] = $scope;
    }
    return implode(' ', $granted);
}
