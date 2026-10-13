<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom tables created in includes/oauth/schema.php; WordPress has no API for them. Table names come from $wpdb->prefix plus fixed suffixes - never from input - and every value goes through $wpdb->prepare().

namespace WPPilot\OAuth\Repositories;

use DateTimeImmutable;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

if (!defined('ABSPATH')) {
    exit();
}

final class AuthCodeEntity implements AuthCodeEntityInterface
{
    use AuthCodeTrait;
    use EntityTrait;
    use TokenEntityTrait;
}

// @mago-expect lint:single-class-per-file
final class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->insert($wpdb->prefix . 'wppilot_oauth_auth_codes', [
            'identifier_hash' => hash('sha256', $authCodeEntity->getIdentifier()),
            'client_id' => $authCodeEntity->getClient()->getIdentifier(),
            'user_id' => (int) $authCodeEntity->getUserIdentifier(),
            'expires_at' => $authCodeEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
            'scopes' => wp_json_encode(array_map(
                static fn(ScopeEntityInterface $s): string => $s->getIdentifier(),
                $authCodeEntity->getScopes(),
            )),
            'redirect_uri' => $authCodeEntity->getRedirectUri() ?? '',
            'revoked' => 0,
        ]);
    }

    public function revokeAuthCode(mixed $codeId): void
    {
        $codeId = (string) $codeId;
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // Atomically claim the code for single use. league calls this once, at the end of a valid
        // exchange; the compare-and-swap on revoked = 0 (serialized by InnoDB's row lock) lets only
        // the first redemption win, so a concurrent or repeated exchange of the same code updates
        // zero rows and is rejected here instead of yielding a second token.
        $claimed = $wpdb->update(
            $wpdb->prefix . 'wppilot_oauth_auth_codes',
            ['revoked' => 1],
            ['identifier_hash' => hash('sha256', $codeId), 'revoked' => 0],
        );
        if ($claimed !== 1) {
            throw OAuthServerException::invalidGrant('Authorization code has already been redeemed');
        }
    }

    public function isAuthCodeRevoked(mixed $codeId): bool
    {
        // A pure check with no side effects: league calls this before the client, redirect URI, and
        // PKCE code_verifier are validated, so it must not consume the code. Single-use is enforced
        // atomically in revokeAuthCode(), which league calls after a valid exchange.
        $codeId = (string) $codeId;
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:possibly-invalid-argument
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT revoked, expires_at FROM {$wpdb->prefix}wppilot_oauth_auth_codes WHERE identifier_hash = %s",
                hash('sha256', $codeId),
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
}
