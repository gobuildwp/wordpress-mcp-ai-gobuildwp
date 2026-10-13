<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- OAuth 2.1 protocol endpoint called by external MCP clients; WordPress nonces cannot exist in this flow. Input is validated against the RFC grammar and rejected with protocol errors, and redirects go to client-registered callback URLs as the spec requires.

namespace WPPilot\OAuth\Endpoints\Revoke;

use Defuse\Crypto\Crypto;
use WP_REST_Request;
use WP_REST_Response;
use WPPilot\OAuth\Bridge;
use WPPilot\OAuth\ClientValidation;
use WPPilot\OAuth\Keys;
use WPPilot\OAuth\Repositories\AccessTokenRepository;
use WPPilot\OAuth\Repositories\RefreshTokenRepository;
use WPPilot\OAuth\ServerFactory;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    register_rest_route('wppilot/v1', route: '/oauth/revoke', args: [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => __NAMESPACE__ . '\\handle',
    ]);
}

function handle(WP_REST_Request $req): WP_REST_Response
{
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($client_ip !== '' && !ClientValidation\within_endpoint_rate_limit('revoke', $client_ip)) {
        return new WP_REST_Response(null, 429);
    }

    $body = $req->get_body_params();
    $token = (string) ($body['token'] ?? '');
    if ($token === '') {
        return new WP_REST_Response(null, 200);
    }
    $client_id = (string) ($body['client_id'] ?? '');

    // Try both types per RFC 7009 §2.1 regardless of token_type_hint; each cascades onto its pair.
    try_revoke_access($token, $client_id);
    try_revoke_refresh($token, $client_id);
    return new WP_REST_Response(null, 200);
}

function try_revoke_access(string $token, string $clientId): void
{
    try {
        $server = ServerFactory\build_resource_server();
        $fake = Bridge\psr7_from_globals()->withHeader('Authorization', 'Bearer ' . $token);
        $validated = $server->validateAuthenticatedRequest($fake);
        $jti = (string) $validated->getAttribute('oauth_access_token_id');
        if ($jti !== '') {
            (new AccessTokenRepository())->revokeGrantByAccessHash(hash('sha256', $jti), $clientId);
        }

        // @mago-expect lint:no-empty-catch-clause
    } catch (\Throwable $e) {
        // Not a valid/active access token — ignore per RFC 7009.
    }
}

function try_revoke_refresh(string $token, string $clientId): void
{
    // Our refresh tokens are hex Defuse ciphertext; skip the deliberately slow password KDF for
    // anything that cannot be one, so a flood of garbage tokens cannot force expensive decryptions.
    if (!ctype_xdigit($token)) {
        return;
    }

    try {
        $keys = Keys\get();
        $decrypted = Crypto::decryptWithPassword($token, $keys['encryption']);
        // @mago-expect analysis:mixed-assignment
        $payload = json_decode($decrypted, associative: true);
        if (!is_array($payload)) {
            return;
        }
        // @mago-expect analysis:mixed-assignment
        $jti = $payload['refresh_token_id'] ?? null;
        if (!is_string($jti) || $jti === '') {
            return;
        }
        // Cascade: resolve the paired access token and revoke both members of the grant.
        $access_hash = (new RefreshTokenRepository())->accessTokenHashFor($jti);
        if ($access_hash !== '') {
            (new AccessTokenRepository())->revokeGrantByAccessHash($access_hash, $clientId);
        }

        // @mago-expect lint:no-empty-catch-clause
    } catch (\Throwable $e) {
        // Not a valid refresh token — ignore per RFC 7009.
    }
}
