<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use League\OAuth2\Server\RedirectUriValidators\RedirectUriValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

use function WPPilot\OAuth\ClientIdMetadata\decode_document;
use function WPPilot\OAuth\ClientIdMetadata\is_blocked_ip;
use function WPPilot\OAuth\ClientIdMetadata\is_metadata_document_client_id;
use function WPPilot\OAuth\ClientIdMetadata\normalize_loopback_uri;
use function WPPilot\OAuth\ClientIdMetadata\normalize_loopback_uris;
use function WPPilot\OAuth\ClientIdMetadata\redirect_uri_is_registered;
use function WPPilot\OAuth\ClientIdMetadata\resolved_addresses_are_public;
use function WPPilot\OAuth\ClientIdMetadata\validate_client_id_url;
use function WPPilot\OAuth\ClientIdMetadata\validate_document;
use function WPPilot\OAuth\ClientIdMetadata\validate_redirect_uri;

/**
 * Client ID Metadata Documents, and the SSRF surface they open.
 */
final class ClientIdMetadataTest extends TestCase
{
    // ------------------------------------------------------------ SSRF ranges

    /**
     * The authorization server fetches a URL chosen by an unauthenticated
     * caller. Every one of these would otherwise reach something inside the
     * network the WordPress host sits in.
     */
    #[DataProvider('blockedAddresses')]
    public function testInternalAddressesAreBlocked(string $ip): void
    {
        self::assertTrue(is_blocked_ip($ip), sprintf('%s must be blocked.', $ip));
    }

    /** @return array<string, array{0: string}> */
    public static function blockedAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'loopback high' => ['127.255.255.254'],
            'this network' => ['0.0.0.0'],
            'private 10' => ['10.0.0.1'],
            'private 172' => ['172.16.5.4'],
            'private 172 top' => ['172.31.255.255'],
            'private 192' => ['192.168.1.1'],
            'cgnat' => ['100.64.0.1'],
            'link local' => ['169.254.1.1'],
            'cloud metadata' => ['169.254.169.254'],
            'ietf assignments' => ['192.0.0.8'],
            'documentation' => ['192.0.2.1'],
            '6to4 relay' => ['192.88.99.1'],
            'benchmarking' => ['198.18.0.1'],
            'documentation 2' => ['198.51.100.1'],
            'documentation 3' => ['203.0.113.1'],
            'multicast' => ['224.0.0.1'],
            'reserved' => ['240.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'ipv6 loopback' => ['::1'],
            'ipv6 unspecified' => ['::'],
            'ipv6 ULA' => ['fc00::1'],
            'ipv6 ULA fd' => ['fd12:3456::1'],
            'ipv6 link local' => ['fe80::1'],
            'ipv6 multicast' => ['ff02::1'],
            'ipv6 documentation' => ['2001:db8::1'],
            'nat64' => ['64:ff9b::7f00:1'],
            'garbage' => ['not-an-ip'],
            'empty' => [''],
        ];
    }

    /**
     * An IPv4-mapped IPv6 literal is the classic bypass: checked only against
     * the IPv6 table, ::ffff:127.0.0.1 looks like an ordinary global address.
     */
    #[DataProvider('mappedAddresses')]
    public function testIpv4MappedAddressesAreUnwrappedBeforeChecking(string $ip): void
    {
        self::assertTrue(is_blocked_ip($ip), sprintf('%s must be blocked.', $ip));
    }

    /** @return array<string, array{0: string}> */
    public static function mappedAddresses(): array
    {
        return [
            'mapped loopback' => ['::ffff:127.0.0.1'],
            'mapped private' => ['::ffff:10.0.0.1'],
            'mapped metadata' => ['::ffff:169.254.169.254'],
        ];
    }

    #[DataProvider('publicAddresses')]
    public function testPublicAddressesAreAllowed(string $ip): void
    {
        self::assertFalse(is_blocked_ip($ip), sprintf('%s should be reachable.', $ip));
    }

    /** @return array<string, array{0: string}> */
    public static function publicAddresses(): array
    {
        return [
            'google dns' => ['8.8.8.8'],
            'cloudflare' => ['1.1.1.1'],
            'ordinary' => ['93.184.216.34'],
            'just below private 172' => ['172.15.255.255'],
            'just above private 172' => ['172.32.0.0'],
            'just below cgnat' => ['100.63.255.255'],
            'ipv6 global' => ['2606:4700::1111'],
        ];
    }

    /**
     * DNS rebinding: the attacker publishes one public and one internal record.
     * Checking only the first would pass while the socket reaches the internal
     * one, so every resolved address has to be clean.
     */
    public function testMixedResolutionIsRejected(): void
    {
        self::assertFalse(resolved_addresses_are_public(['93.184.216.34', '127.0.0.1']));
        self::assertFalse(resolved_addresses_are_public(['127.0.0.1', '93.184.216.34']));
    }

    public function testAllPublicResolutionIsAccepted(): void
    {
        self::assertTrue(resolved_addresses_are_public(['93.184.216.34', '8.8.8.8']));
    }

    public function testHostWithNoRecordsIsRejected(): void
    {
        self::assertFalse(resolved_addresses_are_public([]));
    }

    // -------------------------------------------------------- client_id shape

    public function testWellFormedHttpsClientIdIsAccepted(): void
    {
        self::assertNull(validate_client_id_url('https://app.example.com/mcp-client.json'));
    }

    #[DataProvider('malformedClientIds')]
    public function testMalformedClientIdsAreRejected(string $client_id): void
    {
        self::assertInstanceOf(WP_Error::class, validate_client_id_url($client_id));
    }

    /** @return array<string, array{0: string}> */
    public static function malformedClientIds(): array
    {
        return [
            'http' => ['http://app.example.com/c.json'],
            'ftp' => ['ftp://app.example.com/c.json'],
            'file' => ['file:///etc/passwd'],
            'relative' => ['/c.json'],
            'no scheme' => ['app.example.com/c.json'],
            // Reads as app.example.com to a human, resolves to internal.
            'userinfo confusion' => ['https://app.example.com@169.254.169.254/c.json'],
            'password' => ['https://user:pw@app.example.com/c.json'],
            'fragment' => ['https://app.example.com/c.json#frag'],
            'empty' => [''],
        ];
    }

    public function testOverlongClientIdIsRejected(): void
    {
        $url = 'https://app.example.com/' . str_repeat('a', 2100);

        self::assertInstanceOf(WP_Error::class, validate_client_id_url($url));
    }

    public function testOnlyHttpsClientIdsUseTheDocumentPath(): void
    {
        self::assertTrue(is_metadata_document_client_id('https://app.example.com/c.json'));
        self::assertTrue(is_metadata_document_client_id('HTTPS://app.example.com/c.json'));

        // A DCR-issued opaque id must keep flowing through the fallback path.
        self::assertFalse(is_metadata_document_client_id('a1b2c3d4e5'));
        self::assertFalse(is_metadata_document_client_id('http://app.example.com/c.json'));
    }

    // ------------------------------------------------------ document contents

    /** @return array<string, mixed> */
    private function document(array $overrides = []): array
    {
        return array_merge([
            'client_id' => 'https://app.example.com/c.json',
            'client_name' => 'Example Client',
            'application_type' => 'web',
            'redirect_uris' => ['https://app.example.com/callback'],
        ], $overrides);
    }

    public function testValidDocumentIsAccepted(): void
    {
        $result = validate_document($this->document(), 'https://app.example.com/c.json');

        self::assertIsArray($result);
        self::assertSame('Example Client', $result['client_name']);
        self::assertSame(['https://app.example.com/callback'], $result['redirect_uris']);
    }

    /**
     * Without exact equality, a document hosted anywhere could claim an
     * identifier belonging to someone else.
     */
    #[DataProvider('mismatchedIds')]
    public function testClientIdMustMatchTheFetchedUrlExactly(string $declared): void
    {
        $result = validate_document(
            $this->document(['client_id' => $declared]),
            'https://app.example.com/c.json',
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('client_id_mismatch', $result->get_error_code());
    }

    /** @return array<string, array{0: string}> */
    public static function mismatchedIds(): array
    {
        return [
            'different host' => ['https://evil.example/c.json'],
            'trailing slash' => ['https://app.example.com/c.json/'],
            'case differs' => ['https://APP.example.com/c.json'],
            'query added' => ['https://app.example.com/c.json?x=1'],
            'absent' => [''],
        ];
    }

    #[DataProvider('invalidDocuments')]
    public function testInvalidDocumentsAreRejected(array $overrides, string $code): void
    {
        $result = validate_document($this->document($overrides), 'https://app.example.com/c.json');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame($code, $result->get_error_code());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidDocuments(): array
    {
        return [
            'no name' => [['client_name' => null], 'invalid_client_name'],
            'blank name' => [['client_name' => '   '], 'invalid_client_name'],
            'name not a string' => [['client_name' => 42], 'invalid_client_name'],
            'no redirect uris' => [['redirect_uris' => null], 'invalid_redirect_uris'],
            'empty redirect uris' => [['redirect_uris' => []], 'invalid_redirect_uris'],
            'redirect uri not a string' => [['redirect_uris' => [123]], 'invalid_redirect_uris'],
            'bad application type' => [['application_type' => 'service'], 'invalid_application_type'],
        ];
    }

    public function testApplicationTypeDefaultsToWeb(): void
    {
        $document = $this->document();
        unset($document['application_type']);

        $result = validate_document($document, 'https://app.example.com/c.json');

        self::assertIsArray($result);
        self::assertSame('web', $result['application_type']);
    }

    /**
     * A desktop client that publishes loopback callbacks without declaring
     * application_type is native by the only reading that can be correct.
     * Falling back to the OIDC "web" default rejects the document outright,
     * because a web client may not redirect to loopback - which is how Claude
     * Code's own metadata document failed with "Unknown client_id".
     */
    public function testApplicationTypeIsInferredNativeFromLoopbackRedirects(): void
    {
        $document = $this->document([
            'redirect_uris' => ['http://localhost/callback', 'http://127.0.0.1/callback'],
        ]);
        unset($document['application_type']);

        $result = validate_document($document, 'https://app.example.com/c.json');

        self::assertIsArray($result);
        self::assertSame('native', $result['application_type']);
        // Both spellings normalise onto the IPv4 literal and collapse to one entry.
        self::assertSame(['http://127.0.0.1/callback'], $result['redirect_uris']);
    }

    /**
     * Inference never rescues a document that is not unambiguously native: one
     * remote HTTPS callback in the list keeps the whole document on the web
     * path, where the loopback entry is refused as before.
     */
    public function testMixedRedirectsStayWebAndAreRefused(): void
    {
        $document = $this->document([
            'redirect_uris' => ['https://app.example.com/callback', 'http://localhost/callback'],
        ]);
        unset($document['application_type']);

        $result = validate_document($document, 'https://app.example.com/c.json');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('invalid_redirect_uris', $result->get_error_code());
    }

    /**
     * An explicitly declared web client is never re-read as native, so the
     * inference cannot be used to smuggle a loopback callback past the check.
     */
    public function testDeclaredWebTypeIsNotOverriddenByInference(): void
    {
        $result = validate_document(
            $this->document(['redirect_uris' => ['http://localhost/callback']]),
            'https://app.example.com/c.json',
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('invalid_redirect_uris', $result->get_error_code());
    }

    /**
     * A logo URL is display metadata. Dereferencing it would reopen the SSRF
     * sink the rest of this module closes, so it is carried, never fetched.
     */
    public function testLogoUriIsCarriedButNeverDereferenced(): void
    {
        $result = validate_document(
            $this->document(['logo_uri' => 'https://app.example.com/logo.png']),
            'https://app.example.com/c.json',
        );

        self::assertIsArray($result);
        self::assertSame('https://app.example.com/logo.png', $result['logo_uri']);
    }

    // ------------------------------------------------------- redirect rules

    #[DataProvider('webRedirects')]
    public function testWebRedirectRules(string $uri, bool $valid): void
    {
        $error = validate_redirect_uri($uri, 'web');

        $valid ? self::assertNull($error) : self::assertInstanceOf(WP_Error::class, $error);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function webRedirects(): array
    {
        return [
            'https ok' => ['https://app.example.com/cb', true],
            'http refused' => ['http://app.example.com/cb', false],
            'loopback refused' => ['https://localhost/cb', false],
            'loopback ip refused' => ['https://127.0.0.1/cb', false],
            'fragment refused' => ['https://app.example.com/cb#x', false],
            'custom scheme refused' => ['com.example.app:/cb', false],
        ];
    }

    #[DataProvider('nativeRedirects')]
    public function testNativeRedirectRules(string $uri, bool $valid): void
    {
        $error = validate_redirect_uri($uri, 'native');

        $valid ? self::assertNull($error) : self::assertInstanceOf(WP_Error::class, $error);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function nativeRedirects(): array
    {
        return [
            'loopback http ok' => ['http://127.0.0.1:8080/cb', true],
            'localhost http ok' => ['http://localhost:1410/cb', true],
            'private use scheme ok' => ['com.example.app:/oauth', true],
            'remote https refused' => ['https://app.example.com/cb', false],
            'remote http refused' => ['http://app.example.com/cb', false],
        ];
    }

    /**
     * Exact comparison only. Prefix matching would let a client registered for
     * /cb collect the code at /cb/../../evil.
     */
    public function testRegisteredRedirectComparisonIsExact(): void
    {
        $client = ['redirect_uris' => ['https://app.example.com/cb']];

        self::assertTrue(redirect_uri_is_registered($client, 'https://app.example.com/cb'));
        self::assertFalse(redirect_uri_is_registered($client, 'https://app.example.com/cb/'));
        self::assertFalse(redirect_uri_is_registered($client, 'https://app.example.com/cb/../../evil'));
        self::assertFalse(redirect_uri_is_registered($client, 'https://app.example.com/cb?x=1'));
        self::assertFalse(redirect_uri_is_registered($client, 'https://evil.example/cb'));
        self::assertFalse(redirect_uri_is_registered([], 'https://app.example.com/cb'));
    }

    // ------------------------------------------------------------- decoding

    public function testOversizeDocumentIsRejected(): void
    {
        $result = decode_document(str_repeat('a', 70000));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('client_id_document_too_large', $result->get_error_code());
    }

    #[DataProvider('undecodableBodies')]
    public function testUndecodableBodiesAreRejected(string $raw): void
    {
        $result = decode_document($raw);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('client_id_document_invalid', $result->get_error_code());
    }

    /** @return array<string, array{0: string}> */
    public static function undecodableBodies(): array
    {
        return [
            'html error page' => ['<!doctype html><html></html>'],
            'truncated json' => ['{"client_id": "https://a'],
            'empty' => [''],
            'json scalar' => ['"a string"'],
            'json number' => ['42'],
            // Nesting past the depth bound must fail closed, not consume memory.
            'too deep' => [str_repeat('[', 40) . str_repeat(']', 40)],
        ];
    }

    public function testWellFormedJsonObjectDecodes(): void
    {
        self::assertSame(['client_id' => 'x'], decode_document('{"client_id":"x"}'));
    }

    // ------------------------------------------------- loopback normalisation

    /**
     * The regression 1.10.1 introduced. A Dynamic Client Registration row keeps
     * the URI as posted, the authorization endpoint now normalises the URI the
     * client sends onto 127.0.0.1, and league's loopback comparison ignores the
     * port but not the host. Both sides have to be normalised or a client that
     * registered `localhost` (MCP Inspector, for one) is refused on the host
     * name alone.
     */
    public function testStoredLocalhostCallbackMatchesNormalisedRequest(): void
    {
        $stored = ['http://localhost:6274/oauth/callback'];
        $sent = normalize_loopback_uri('http://localhost:6274/oauth/callback');

        // What the repository used to hand league, and why it failed.
        self::assertFalse((new RedirectUriValidator($stored))->validateRedirectUri($sent));

        // What it hands league now.
        $validator = new RedirectUriValidator(normalize_loopback_uris($stored));
        self::assertTrue($validator->validateRedirectUri($sent));
        // RFC 8252 §7.3: the ephemeral port the client actually bound is ignored.
        self::assertTrue($validator->validateRedirectUri(normalize_loopback_uri('http://localhost:53821/oauth/callback')));
        // The path is still compared.
        self::assertFalse($validator->validateRedirectUri(normalize_loopback_uri('http://localhost:6274/other')));
    }

    public function testNormalizeLoopbackUrisTouchesOnlyHttpLocalhost(): void
    {
        self::assertSame(
            [
                'http://127.0.0.1:6274/oauth/callback',
                'http://127.0.0.1/cb?x=1',
                'https://app.example.com/cb',
                'cursor://anysphere.cursor-retrieval/oauth/callback',
                'http://[::1]:9000/cb',
            ],
            normalize_loopback_uris([
                'http://localhost:6274/oauth/callback',
                'http://LOCALHOST/cb?x=1',
                'https://app.example.com/cb',
                'cursor://anysphere.cursor-retrieval/oauth/callback',
                'http://[::1]:9000/cb',
                // A client listing both spellings collapses to one entry.
                'http://127.0.0.1:6274/oauth/callback',
                // Garbage a hand-edited row could carry is dropped, not fatal.
                '',
                42,
                null,
            ]),
        );
    }
}
