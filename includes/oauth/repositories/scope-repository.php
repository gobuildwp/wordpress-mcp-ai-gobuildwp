<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

if (!defined('ABSPATH')) {
    exit();
}

final class ScopeEntity implements ScopeEntityInterface
{
    use EntityTrait;
    use ScopeTrait;
}

// @mago-expect lint:single-class-per-file
final class ScopeRepository implements ScopeRepositoryInterface
{
    public function getScopeEntityByIdentifier(mixed $identifier): ?ScopeEntityInterface
    {
        $identifier = (string) $identifier;
        if (!in_array($identifier, \WPPilot\OAuth\supported_scopes(), strict: true)) {
            return null;
        }
        $entity = new ScopeEntity();
        $entity->setIdentifier($identifier);
        return $entity;
    }

    /** @param array<array-key, ScopeEntityInterface> $scopes */
    public function finalizeScopes(
        array $scopes,
        mixed $grantType,
        ClientEntityInterface $clientEntity,
        mixed $userIdentifier = null,
    ): array {
        // A grant asked for as `mcp:read` alone stays read-only; every other accepted flow converges
        // on the full-access grant. Refresh keeps the original scopes without coming through here,
        // and League refuses a refresh that asks for a scope the original grant lacked.
        $identifiers = [];
        foreach ($scopes as $scope) {
            $identifiers[] = $scope->getIdentifier();
        }
        $granted = new ScopeEntity();
        $granted->setIdentifier(array_values(array_unique($identifiers)) === ['mcp:read'] ? 'mcp:read' : 'mcp');
        return [$granted];
    }
}
