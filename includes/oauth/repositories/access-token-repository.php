<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom tables created in includes/oauth/schema.php; WordPress has no API for them. Table names come from $wpdb->prefix plus fixed suffixes - never from input - and every value goes through $wpdb->prepare().

namespace WPPilot\OAuth\Repositories;

use DateTimeImmutable;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

if (!defined('ABSPATH')) {
    exit();
}

final class AccessTokenEntity implements AccessTokenEntityInterface
{
    use AccessTokenTrait;
    use EntityTrait;
    use TokenEntityTrait;

    /**
     * Identical to league's AccessTokenTrait::convertToJWT(), except the audience is the MCP
     * resource (RFC 8707 / MCP authorization) rather than the client id. The middleware validates
     * this aud, so a token minted for this server cannot be presented to a different resource.
     */
    public function __toString(): string
    {
        $this->initJwtConfiguration();

        $audience = \WPPilot\OAuth\resource_identifier();
        $tokenId = (string) $this->getIdentifier();
        $subject = (string) $this->getUserIdentifier();
        $now = new DateTimeImmutable();

        $builder = $this->jwtConfiguration
            ->builder()
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($this->getExpiryDateTime())
            ->withClaim('scopes', $this->getScopes());
        // Guards double as type narrowing to non-empty-string for the lcobucci builder. In practice
        // all three are always set (a URL, a random token id, and a positive user id).
        if ($audience !== '') {
            $builder = $builder->permittedFor($audience);
        }
        if ($tokenId !== '') {
            $builder = $builder->identifiedBy($tokenId);
        }
        if ($subject !== '') {
            $builder = $builder->relatedTo($subject);
        }

        return $builder->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey())->toString();
    }
}

// @mago-expect lint:single-class-per-file
final class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    /** @param array<array-key, ScopeEntityInterface> $scopes */
    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        mixed $userIdentifier = null,
    ): AccessTokenEntityInterface {
        $token = new AccessTokenEntity();
        $token->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        if ($userIdentifier !== null) {
            // @mago-expect analysis:mixed-argument
            $token->setUserIdentifier($userIdentifier);
        }
        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->insert($wpdb->prefix . 'wppilot_oauth_access_tokens', [
            'identifier_hash' => hash('sha256', $accessTokenEntity->getIdentifier()),
            'client_id' => $accessTokenEntity->getClient()->getIdentifier(),
            'user_id' => (int) $accessTokenEntity->getUserIdentifier(),
            'expires_at' => $accessTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
            'scopes' => wp_json_encode(array_map(
                static fn(ScopeEntityInterface $s): string => $s->getIdentifier(),
                $accessTokenEntity->getScopes(),
            )),
            'revoked' => 0,
        ]);
    }

    public function revokeAccessToken(mixed $tokenId): void
    {
        $tokenId = (string) $tokenId;
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->update(
            $wpdb->prefix . 'wppilot_oauth_access_tokens',
            ['revoked' => 1],
            ['identifier_hash' => hash('sha256', $tokenId)],
        );
    }

    /**
     * Cascade-revoke a whole grant, identified by its access token's identifier hash: the access
     * token and every refresh token paired with it (refresh_tokens.access_token_hash matches). Used
     * by the RFC 7009 /revoke endpoint so revoking one member of the pair takes down the other — no
     * access token surviving a refresh revoke, no refresh minting a fresh access token after an
     * access revoke. When $requestClientId is non-empty it must match the token's owning client, so
     * one client cannot revoke another's grant; an unresolvable owner does not block the caller.
     */
    public function revokeGrantByAccessHash(string $accessTokenHash, string $requestClientId): void
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:possibly-invalid-argument
        $owner = $wpdb->get_var($wpdb->prepare(
            "SELECT client_id FROM {$wpdb->prefix}wppilot_oauth_access_tokens WHERE identifier_hash = %s",
            $accessTokenHash,
        ));
        if ($requestClientId !== '' && is_string($owner) && !hash_equals($owner, $requestClientId)) {
            return;
        }
        $wpdb->update(
            $wpdb->prefix . 'wppilot_oauth_access_tokens',
            ['revoked' => 1],
            ['identifier_hash' => $accessTokenHash],
        );
        $wpdb->update(
            $wpdb->prefix . 'wppilot_oauth_refresh_tokens',
            ['revoked' => 1],
            ['access_token_hash' => $accessTokenHash],
        );
    }

    public function isAccessTokenRevoked(mixed $tokenId): bool
    {
        $tokenId = (string) $tokenId;
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:possibly-invalid-argument
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT revoked, expires_at FROM {$wpdb->prefix}wppilot_oauth_access_tokens WHERE identifier_hash = %s",
                hash('sha256', $tokenId),
            ),
            ARRAY_A,
        );
        if (!is_array($row)) {
            return true;
        }
        if ((int) $row['revoked'] === 1) {
            return true;
        }
        return new DateTimeImmutable((string) $row['expires_at'] . ' UTC') < new DateTimeImmutable('now');
    }

    public function get_client_id_by_token_identifier(string $tokenId): ?string
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:possibly-invalid-argument
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT client_id FROM {$wpdb->prefix}wppilot_oauth_access_tokens WHERE identifier_hash = %s",
            hash('sha256', $tokenId),
        ));
        return is_string($value) ? $value : null;
    }
}
