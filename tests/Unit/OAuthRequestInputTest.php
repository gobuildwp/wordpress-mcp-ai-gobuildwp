<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function WPPilot\OAuth\Endpoints\Authorize\get_param;
use function WPPilot\OAuth\Middleware\get_authorization_header;

require_once dirname(__DIR__, 2) . '/includes/oauth/endpoints/authorize.php';

/**
 * OAuth protocol values reach the code exactly as the client sent them: WordPress's
 * slashing (wp_magic_quotes) is undone, and nothing else is rewritten, because the
 * redirect_uri is matched byte for byte, state is echoed back, and credentials are hashed.
 */
final class OAuthRequestInputTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedGet = [];

    /** @var array<string, mixed> */
    private array $savedServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedGet = $_GET;
        $this->savedServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_GET = $this->savedGet;
        $_SERVER = $this->savedServer;
        parent::tearDown();
    }

    public function testAuthorizationParametersAreUnslashedButNotRewritten(): void
    {
        // As wp_magic_quotes() leaves them: quotes and backslashes slashed.
        $_GET = [
            'state' => addslashes("a'b  c%2F<x>"),
            'redirect_uri' => addslashes('http://127.0.0.1:33418/callback?next=%2Fdone'),
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'scope' => ['mcp'],
        ];

        self::assertSame("a'b  c%2F<x>", get_param('state'), 'state is returned to the client unchanged');
        self::assertSame('http://127.0.0.1:33418/callback?next=%2Fdone', get_param('redirect_uri'), 'percent-encoding survives');
        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', get_param('code_challenge'));
        self::assertSame('', get_param('scope'), 'a non-string parameter reads as absent');
        self::assertSame('', get_param('resource'));
    }

    public function testTheAuthorizationHeaderIsUnslashedAndTrimmedOnly(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = addslashes("  Bearer wpp_a\\b'c  ");
        unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        self::assertSame("Bearer wpp_a\\b'c", get_authorization_header());

        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';
        self::assertSame('Basic dXNlcjpwYXNz', get_authorization_header(), 'the post-rewrite copy is the fallback');

        unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        self::assertSame('', get_authorization_header());
    }
}
