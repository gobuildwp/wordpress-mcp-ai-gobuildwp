<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom tables created in includes/oauth/schema.php; WordPress has no API for them. Table names come from $wpdb->prefix plus fixed suffixes - never from input - and every value goes through $wpdb->prepare().

namespace WPPilot\OAuth\Repositories;

use DateTimeImmutable;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

if (!defined('ABSPATH')) {
    exit();
}

final class RefreshTokenEntity implements RefreshTokenEntityInterface
{
    use EntityTrait;
    use RefreshTokenTrait;
}

// @mago-expect lint:single-class-per-file
final class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->insert($wpdb->prefix . 'wppilot_oauth_refresh_tokens', [
            'identifier_hash' => hash('sha256', $refreshTokenEntity->getIdentifier()),
            'access_token_hash' => hash('sha256', $refreshTokenEntity->getAccessToken()->getIdentifier()),
            'expires_at' => $refreshTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
            'revoked' => 0,
        ]);
    }

    public function revokeRefreshToken(mixed $tokenId): void
    {
        $tokenId = (string) $tokenId;
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // Atomically claim the refresh token for single use. league calls this during a rotation,
        // after validating the token; the compare-and-swap on revoked = 0 (serialized by InnoDB's
        // row lock) lets only the first use win, so a concurrent reuse updates zero rows and is
        // rejected here before any new tokens are issued.
        $claimed = $wpdb->update(
            $wpdb->prefix . 'wppilot_oauth_refresh_tokens',
            ['revoked' => 1],
            ['identifier_hash' => hash('sha256', $tokenId), 'revoked' => 0],
        );
        if ($claimed !== 1) {
            throw OAuthServerException::invalidGrant('Refresh token has already been used');
        }
    }

    public function isRefreshTokenRevoked(mixed $tokenId): bool
    {
        // A pure check with no side effects: league calls this before validating the requested
        // scopes and before persisting the new tokens, so consuming the refresh token here would
        // lose it on a later scope or persistence failure. Single-use enforcement is handled by
        // serializing the exchange in the token endpoint (see endpoints/token.php).
        $tokenId = (string) $tokenId;
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:possibly-invalid-argument
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT revoked, expires_at FROM {$wpdb->prefix}wppilot_oauth_refresh_tokens WHERE identifier_hash = %s",
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

    /**
     * Identifier hash of the access token this refresh token was issued with, or '' if the refresh
     * token is unknown. Lets the /revoke endpoint cascade a refresh-token revocation onto its paired
     * access token (see AccessTokenRepository::revokeGrantByAccessHash()).
     */
    public function accessTokenHashFor(string $refreshJti): string
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:possibly-invalid-argument
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT access_token_hash FROM {$wpdb->prefix}wppilot_oauth_refresh_tokens WHERE identifier_hash = %s",
            hash('sha256', $refreshJti),
        ));
        return is_string($value) ? $value : '';
    }
}
