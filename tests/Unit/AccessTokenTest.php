<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function WPPilot\OAuth\Middleware\bearer_secret;
use function WPPilot\OAuth\Middleware\oauth_identity_may_use_route;
use function WPPilot\OAuth\Middleware\record_oauth_identity;
use function WPPilot\OAuth\Middleware\request_identity_via;
use function WPPilot\OAuth\Middleware\reset_request_context;
use function WPPilot\OAuth\Middleware\route_required_scope;

/**
 * The access-token credential: how it is told apart from an OAuth token, how it
 * is digested, and which routes an identity established by one may cross.
 *
 * Storage is not exercised here — it needs a database — so these cover the parts
 * that decide whether a request is authenticated at all.
 */
final class AccessTokenTest extends TestCase
{
    protected function tearDown(): void
    {
        reset_request_context();
        parent::tearDown();
    }

    /**
     * @return list<array{string, string}>
     */
    public static function bearer_headers(): array
    {
        return [
            'plain' => ['Bearer wpp_abc123', 'wpp_abc123'],
            'lowercase scheme' => ['bearer wpp_abc123', 'wpp_abc123'],
            'surrounding whitespace' => ["  Bearer   wpp_abc123\t", 'wpp_abc123'],
            // Not a Bearer credential: a Basic value that happens to decode to
            // something token-shaped must never be routed to the token validator.
            'basic' => ['Basic d3BwXzEyMzQ1', ''],
            'scheme only' => ['Bearer', ''],
            'scheme and space' => ['Bearer ', ''],
            'empty' => ['', ''],
            // A single credential, so an embedded space is a malformed header
            // rather than a token with a suffix.
            'two values' => ['Bearer wpp_abc other', ''],
        ];
    }

    #[DataProvider('bearer_headers')]
    public function test_bearer_secret_extracts_only_a_single_bearer_credential(string $header, string $expected): void
    {
        $this->assertSame($expected, bearer_secret($header));
    }

    public function test_only_the_prefixed_credential_is_treated_as_an_access_token(): void
    {
        $this->assertTrue(wppilot_token_looks_like('wpp_abc123'));
        $this->assertFalse(wppilot_token_looks_like('eyJhbGciOiJSUzI1NiJ9.body.sig'));
        $this->assertFalse(wppilot_token_looks_like(''));
        // The prefix has to start the credential; finding it anywhere would let a
        // JWT containing the characters be sent to the wrong validator.
        $this->assertFalse(wppilot_token_looks_like('xwpp_abc123'));
    }

    public function test_a_basic_credential_is_never_taken_for_an_access_token(): void
    {
        $this->assertFalse(wppilot_token_looks_like(bearer_secret('Basic ' . base64_encode('wpp_x:secret'))));
    }

    public function test_digest_is_stable_and_distinguishes_secrets(): void
    {
        $digest = wppilot_token_hash('wpp_abc123');

        $this->assertSame($digest, wppilot_token_hash('wpp_abc123'));
        $this->assertSame(64, strlen($digest));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
        $this->assertNotSame($digest, wppilot_token_hash('wpp_abc124'));
    }

    public function test_token_identity_reaches_the_canonical_mcp_endpoint(): void
    {
        record_oauth_identity(1, ['mcp'], 'token-7', via: 'token');

        $this->assertSame('token', request_identity_via());
        $this->assertTrue(oauth_identity_may_use_route('/mcp/wppilot', 'POST'));
        $this->assertTrue(oauth_identity_may_use_route('/mcp/wppilot-oauth', 'POST'));
        $this->assertTrue(oauth_identity_may_use_route('/mcp/mcp-adapter-default-server', 'POST'));
    }

    public function test_oauth_identity_keeps_its_narrower_route_boundary(): void
    {
        record_oauth_identity(1, ['mcp'], 'client-abc');

        $this->assertSame('oauth', request_identity_via());
        $this->assertTrue(oauth_identity_may_use_route('/mcp/wppilot-oauth', 'POST'));
        // The Application Password endpoint stays outside OAuth's boundary; the
        // third method must not have widened it.
        $this->assertFalse(oauth_identity_may_use_route('/mcp/wppilot', 'POST'));
    }

    public function test_an_unauthenticated_request_to_the_password_endpoint_gets_no_oauth_challenge(): void
    {
        // Regression guard for every existing Application Password install: with
        // no identity established, the canonical endpoint must still advertise no
        // required scope, or the OAuth middleware would start answering 401 with a
        // WWW-Authenticate on a route that never used OAuth.
        $this->assertSame('', request_identity_via());
        $this->assertNull(route_required_scope('/mcp/wppilot', 'POST'));
        $this->assertSame('mcp', route_required_scope('/mcp/wppilot-oauth', 'POST'));
    }

    public function test_token_identity_reaches_the_ability_run_route(): void
    {
        record_oauth_identity(1, ['mcp'], 'token-7', via: 'token');

        $this->assertTrue(oauth_identity_may_use_route('/wppilot/v1/abilities/wppilot/diagnostics/run', 'POST'));
        $this->assertFalse(oauth_identity_may_use_route('/wp/v2/users', 'GET'));
    }
}
