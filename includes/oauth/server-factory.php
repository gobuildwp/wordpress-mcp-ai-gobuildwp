<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\OAuth\ServerFactory;

use DateInterval;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use WPPilot\OAuth\Keys;
use WPPilot\OAuth\Repositories\AccessTokenRepository;
use WPPilot\OAuth\Repositories\AuthCodeRepository;
use WPPilot\OAuth\Repositories\ClientRepository;
use WPPilot\OAuth\Repositories\RefreshTokenRepository;
use WPPilot\OAuth\Repositories\ScopeRepository;

if (!defined('ABSPATH')) {
    exit();
}

function build_authorization_server(): AuthorizationServer
{
    $keys = Keys\get();
    $server = new AuthorizationServer(
        new ClientRepository(),
        new AccessTokenRepository(),
        new ScopeRepository(),
        $keys['private'],
        $keys['encryption'],
    );

    $authCodeGrant = new AuthCodeGrant(
        new AuthCodeRepository(),
        new RefreshTokenRepository(),
        new DateInterval('PT1M'),
    );
    $authCodeGrant->setRefreshTokenTTL(new DateInterval('P14D'));
    // PKCE is required by default for public clients in league v8 — no explicit call needed.

    $server->enableGrantType($authCodeGrant, new DateInterval('PT1H'));

    $refreshGrant = new RefreshTokenGrant(new RefreshTokenRepository());
    $refreshGrant->setRefreshTokenTTL(new DateInterval('P14D'));
    $server->enableGrantType($refreshGrant, new DateInterval('PT1H'));

    return $server;
}

function build_resource_server(): ResourceServer
{
    return new ResourceServer(new AccessTokenRepository(), Keys\get()['public']);
}
