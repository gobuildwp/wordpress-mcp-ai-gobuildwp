<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPPilot_Test_Rest_Request;
use WPPilot_Test_Rest_Response;
use WPPilot_Test_State;

use function WPPilot\Mcp\ServerCard\build_card;
use function WPPilot\Mcp\ServerCard\cacheable_rest_response;
use function WPPilot\Mcp\ServerCard\card;
use function WPPilot\Mcp\ServerCard\encode;
use function WPPilot\Mcp\ServerCard\etag;
use function WPPilot\Mcp\ServerCard\is_card_request;

require_once dirname(__DIR__) . '/doubles/mcp-surface.php';
require_once dirname(__DIR__, 2) . '/includes/capabilities.php';
require_once dirname(__DIR__, 2) . '/includes/mcp/server-card.php';

/**
 * The public server card.
 *
 * It is served to anyone, cached publicly, and read before a client holds a credential, so the
 * promise under test is the one 1.13.0 made for discovery: nothing about who is asking, which
 * abilities exist, or which safety profile is active reaches an anonymous caller.
 */
final class ServerCardTest extends TestCase
{
    protected function setUp(): void
    {
        WPPilot_Test_State::reset();
        remove_all_filters('wppilot_server_card');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wppilot_server_card');
    }

    /**
     * @return array{version: string, mcp_url: string, oauth_url: string, alias_url: string, card_url: string, well_known_url: string, modern_urls: list<string>, app_passwords: bool, protected_resource_metadata: string, authorization_server_metadata: string, profiles: array<string, string>, modern_version: string, legacy_version: string}
     */
    private static function context(bool $oauth = true): array
    {
        return [
            'version' => '1.14.0',
            'mcp_url' => 'https://example.test/wp-json/mcp/wppilot',
            'oauth_url' => $oauth ? 'https://example.test/wp-json/mcp/wppilot-oauth' : '',
            'alias_url' => 'https://example.test/wp-json/mcp/mcp-adapter-default-server',
            'card_url' => 'https://example.test/wp-json/mcp/wppilot/server-card',
            'well_known_url' => 'https://example.test/.well-known/mcp/server-card.json',
            'modern_urls' => ['https://example.test/wp-json/mcp/wppilot'],
            'app_passwords' => true,
            'protected_resource_metadata' => $oauth ? 'https://example.test/.well-known/oauth-protected-resource' : '',
            'authorization_server_metadata' => $oauth ? 'https://example.test/.well-known/openid-configuration' : '',
            'profiles' => ['production' => 'Production Safe', 'readonly' => 'Read Only', 'developer' => 'Developer Full Access'],
            'modern_version' => '2026-07-28',
            'legacy_version' => '2025-11-25',
        ];
    }

    public function testCardCarriesTheServerJsonSubsetFields(): void
    {
        $card = build_card(self::context());

        self::assertSame('com.gobuildwp/gobuildwp', $card['name']);
        self::assertSame('GoBuildWP', $card['title']);
        self::assertSame('1.14.0', $card['version']);
        self::assertLessThanOrEqual(100, strlen($card['description']));
        self::assertSame('https://gobuildwp.com', $card['websiteUrl']);
        self::assertSame('github', $card['repository']['source']);
    }

    public function testRemotesDescribeEachEndpointWithItsOwnVersionsAndAuth(): void
    {
        $remotes = build_card(self::context())['remotes'];

        self::assertCount(2, $remotes);
        self::assertSame('streamable-http', $remotes[0]['type']);
        self::assertSame('https://example.test/wp-json/mcp/wppilot', $remotes[0]['url']);
        self::assertSame(['2026-07-28', '2025-11-25'], $remotes[0]['supportedProtocolVersions']);
        self::assertSame('Authorization', $remotes[0]['headers'][0]['name']);
        self::assertTrue($remotes[0]['headers'][0]['isSecret']);

        // The modern dispatcher does not claim the OAuth route, so the card must not promise it.
        self::assertSame(['2025-11-25'], $remotes[1]['supportedProtocolVersions']);
        // An OAuth client is never asked for a secret header; it signs in from the challenge.
        self::assertArrayNotHasKey('headers', $remotes[1]);
    }

    public function testOauthDetailsAppearOnlyWhenOauthIsAvailable(): void
    {
        $with = build_card(self::context(true))['_meta']['co.wppilot/server-card'];
        self::assertSame('https://example.test/.well-known/oauth-protected-resource', $with['auth']['oauth']['protectedResourceMetadata']);
        self::assertSame('https://example.test/.well-known/openid-configuration', $with['auth']['oauth']['authorizationServerMetadata']);
        self::assertSame(['S256'], $with['auth']['oauth']['codeChallengeMethods']);

        $without = build_card(self::context(false));
        self::assertCount(1, $without['remotes']);
        self::assertArrayNotHasKey('oauth', $without['_meta']['co.wppilot/server-card']['auth']);
        self::assertArrayNotHasKey('oauth', $without['_meta']['co.wppilot/server-card']['endpoints']);
    }

    public function testSafetyProfilesAreNamedButNoneIsMarkedActive(): void
    {
        $meta = build_card(self::context())['_meta']['co.wppilot/server-card'];

        self::assertSame(['production', 'readonly', 'developer'], array_column($meta['safetyProfiles'], 'id'));
        foreach ($meta['safetyProfiles'] as $profile) {
            self::assertSame(['id', 'label'], array_keys($profile));
        }
        self::assertStringNotContainsStringIgnoringCase('active', (string) encode(build_card(self::context())));
    }

    public function testCapabilitiesEncodeAsObjects(): void
    {
        $json = encode(build_card(self::context()));

        self::assertStringContainsString('"tools":{}', $json);
        self::assertStringContainsString('"resources":{}', $json);
        self::assertStringContainsString('"extensions":{"io.modelcontextprotocol/skills":{}}', $json);
    }

    public function testServedCardIsTheSameForEveryCallerAndProfile(): void
    {
        WPPilot_Test_State::$current_user_id = 0;
        WPPilot_Test_State::$logged_in = false;
        WPPilot_Test_State::$capabilities = [];
        WPPilot_Test_State::$options['wppilot_safety_profile'] = 'readonly';
        $anonymous = encode(card());

        WPPilot_Test_State::$current_user_id = 1;
        WPPilot_Test_State::$logged_in = true;
        WPPilot_Test_State::$capabilities = ['manage_options'];
        WPPilot_Test_State::$options['wppilot_safety_profile'] = 'developer';
        $administrator = encode(card());

        self::assertSame($anonymous, $administrator);
    }

    public function testServedCardLeaksNoAbilitiesSchemasOrEnvironment(): void
    {
        $json = encode(card());

        // No ability surface: the per-caller list stays behind authentication (TransportAccessTest).
        foreach (['inputSchema', 'outputSchema', 'system-status', 'execute-php', 'wppilot_', '"tools":['] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        // No environment fingerprint beyond the plugin version the OAuth metadata already publishes.
        foreach (['wordpress_version', 'php', 'email', 'user'] as $needle) {
            self::assertStringNotContainsStringIgnoringCase('"' . $needle, $json);
        }
        self::assertStringNotContainsString('"7.0"', $json);
    }

    public function testFilterCanExtendTheCard(): void
    {
        add_filter('wppilot_server_card', static function (array $card): array {
            $card['title'] = 'Agency Pilot';

            return $card;
        });

        self::assertSame('Agency Pilot', card()['title']);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string, bool}>
     */
    public static function requests(): iterable
    {
        yield 'root install' => ['/.well-known/mcp/server-card.json', [], '', true];
        yield 'root install with a query string' => ['/.well-known/mcp/server-card.json?x=1', [], '', true];
        yield 'subdirectory install' => ['/blog/.well-known/mcp/server-card.json', [], '/blog', true];
        yield 'no pretty permalinks' => ['/index.php', ['wppilot_mcp_server_card' => '1'], '', true];
        yield 'another well-known document' => ['/.well-known/oauth-protected-resource', [], '', false];
        yield 'a suffix of the card path' => ['/x/.well-known/mcp/server-card.json', [], '/blog', false];
        yield 'an ordinary page' => ['/about/', [], '', false];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('requests')]
    public function testCardRequestsAreRecognised(string $uri, array $query, string $home_path, bool $expected): void
    {
        self::assertSame($expected, is_card_request($uri, $query, $home_path));
    }

    public function testRestCopyIsMadeCacheableAndOnlyThatRoute(): void
    {
        $card = build_card(self::context());
        $response = new WPPilot_Test_Rest_Response($card, 200);
        $response->set_headers(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'Expires' => '0']);

        cacheable_rest_response($response, null, new WPPilot_Test_Rest_Request('/mcp/wppilot/server-card', 'GET'));

        $headers = $response->get_headers();
        self::assertSame('public, max-age=3600', $headers['Cache-Control']);
        self::assertSame(etag(encode($card)), $headers['ETag']);
        self::assertSame('*', $headers['Access-Control-Allow-Origin']);
        self::assertArrayNotHasKey('Pragma', $headers);

        $rpc = new WPPilot_Test_Rest_Response(['jsonrpc' => '2.0'], 200);
        $rpc->set_headers(['Cache-Control' => 'no-store']);
        cacheable_rest_response($rpc, null, new WPPilot_Test_Rest_Request('/mcp/wppilot', 'POST'));
        self::assertSame(['Cache-Control' => 'no-store'], $rpc->get_headers());
    }

    public function testCommittedRegistryEntryMatchesTheCard(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/server.json')) {
            // The scratch copies some runners test from carry includes/ and tests/ only; the release
            // script checks the committed file itself (scripts/package.sh).
            self::markTestSkipped('server.json is not part of this test tree.');
        }
        $entry = json_decode((string) file_get_contents($root . '/server.json'), true);
        $card = build_card(self::context());

        self::assertIsArray($entry);
        self::assertSame($card['name'], $entry['name']);
        self::assertSame($card['description'], $entry['description']);
        self::assertSame($card['repository'], $entry['repository']);
        preg_match('/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents($root . '/wppilot.php'), $m);
        self::assertSame($m[1] ?? '', $entry['version'], 'server.json is stale: php scripts/generate-registry-server-json.php --write');
    }
}
