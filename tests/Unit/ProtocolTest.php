<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function WPPilot\Mcp\build_capabilities;
use function WPPilot\Mcp\build_discover_result;
use function WPPilot\Mcp\client_capabilities;

use const WPPilot\Mcp\VERSION_LEGACY;
use const WPPilot\Mcp\VERSION_MODERN;

/**
 * Dual-era protocol behaviour: era selection, header validation, result shape.
 */
final class ProtocolTest extends TestCase
{
    private const MODERN_META = [
        'io.modelcontextprotocol/protocolVersion' => VERSION_MODERN,
        'io.modelcontextprotocol/clientCapabilities' => [],
    ];

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function modernBody(string $method = 'tools/list', array $params = [], mixed $id = 1): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => array_merge($params, ['_meta' => self::MODERN_META]),
        ];
    }

    /** @return array<string, string> */
    private function modernHeaders(string $method = 'tools/list', string $name = ''): array
    {
        $headers = [
            'MCP-Protocol-Version' => VERSION_MODERN,
            'Mcp-Method' => $method,
        ];
        if ($name !== '') {
            $headers['Mcp-Name'] = $name;
        }

        return $headers;
    }

    // ------------------------------------------------------------ era selection

    public function testRequestWithModernMetaIsModern(): void
    {
        self::assertTrue(\WPPilot\Mcp\is_modern_request($this->modernBody()));
    }

    /**
     * A legacy handshake selects legacy semantics. This is the regression that
     * matters most: routing an existing client into the stateless path would
     * break every connection already in the field.
     */
    public function testInitializeWithoutModernMetaIsLegacy(): void
    {
        $body = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => VERSION_LEGACY, 'capabilities' => []],
        ];

        self::assertFalse(\WPPilot\Mcp\is_modern_request($body));
    }

    /**
     * A legacy 2025-06-18+ client also sends MCP-Protocol-Version, so the header
     * must never be what selects the modern era.
     */
    public function testLegacyRequestWithProtocolHeaderIsStillLegacy(): void
    {
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []];

        self::assertFalse(\WPPilot\Mcp\is_modern_request($body));
    }

    public function testRequestWithNoParamsIsLegacy(): void
    {
        self::assertFalse(\WPPilot\Mcp\is_modern_request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
    }

    public function testBothVersionsAreSupported(): void
    {
        self::assertTrue(\WPPilot\Mcp\is_supported_version(VERSION_MODERN));
        self::assertTrue(\WPPilot\Mcp\is_supported_version(VERSION_LEGACY));
        self::assertFalse(\WPPilot\Mcp\is_supported_version('1900-01-01'));
    }

    public function testClientCapabilitiesAreReadAndDistinguishedFromAbsent(): void
    {
        self::assertSame([], client_capabilities($this->modernBody()));
        self::assertTrue(\WPPilot\Mcp\declares_client_capabilities($this->modernBody()));

        $missing = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => VERSION_MODERN,
        ]]];
        self::assertFalse(\WPPilot\Mcp\declares_client_capabilities($missing));
    }

    /**
     * The revision forbids emitting notifications/message unless the request
     * opted in, so absence has to be distinguishable from a level of "".
     */
    public function testLogLevelIsOnlySetWhenSupplied(): void
    {
        self::assertSame('', \WPPilot\Mcp\requested_log_level($this->modernBody()));

        $body = $this->modernBody();
        $body['params']['_meta']['io.modelcontextprotocol/logLevel'] = 'debug';
        self::assertSame('debug', \WPPilot\Mcp\requested_log_level($body));
    }

    public function testTraceContextIsPropagatedOnlyWhenSupplied(): void
    {
        self::assertSame([], \WPPilot\Mcp\trace_context($this->modernBody()));

        $body = $this->modernBody();
        $body['params']['_meta']['traceparent'] = '00-abc-def-01';
        self::assertSame(['traceparent' => '00-abc-def-01'], \WPPilot\Mcp\trace_context($body));
    }

    // ------------------------------------------------------- header validation

    public function testWellFormedModernRequestPassesValidation(): void
    {
        self::assertNull(\WPPilot\Mcp\validate_modern_headers($this->modernHeaders(), $this->modernBody()));
    }

    /**
     * HTTP field names are case-insensitive; a client lowercasing its headers
     * must not be rejected.
     */
    public function testHeaderNamesAreCaseInsensitive(): void
    {
        $headers = [
            'mcp-protocol-version' => VERSION_MODERN,
            'MCP-METHOD' => 'tools/list',
        ];

        self::assertNull(\WPPilot\Mcp\validate_modern_headers($headers, $this->modernBody()));
    }

    /**
     * WP_REST_Request::get_headers() hands back PHP's $_SERVER spelling, where
     * every hyphen is already an underscore. Matching only the hyphenated name
     * passed the hand-built fixtures above and failed every real request.
     */
    public function testServerStyleUnderscoreHeaderNamesAreAccepted(): void
    {
        $headers = [
            'MCP_PROTOCOL_VERSION' => [VERSION_MODERN],
            'MCP_METHOD' => ['tools/list'],
        ];

        self::assertNull(\WPPilot\Mcp\validate_modern_headers($headers, $this->modernBody()));
    }

    public function testServerStyleUnderscoreNameHeaderIsCompared(): void
    {
        $body = $this->modernBody('tools/call', ['name' => 'wppilot_create_post']);
        $headers = [
            'MCP_PROTOCOL_VERSION' => [VERSION_MODERN],
            'MCP_METHOD' => ['tools/call'],
            'MCP_NAME' => ['wppilot_create_post'],
        ];

        self::assertNull(\WPPilot\Mcp\validate_modern_headers($headers, $body));

        $headers['MCP_NAME'] = ['spoofed'];
        $error = \WPPilot\Mcp\validate_modern_headers($headers, $body);
        self::assertNotNull($error);
        self::assertSame(-32020, $error['body']['error']['code']);
    }

    /**
     * A repeated header arrives from WordPress as a list.
     */
    public function testArrayValuedHeadersUseTheFirstValue(): void
    {
        $headers = [
            'MCP_PROTOCOL_VERSION' => [VERSION_MODERN, '2025-11-25'],
            'MCP_METHOD' => ['tools/list'],
        ];

        self::assertNull(\WPPilot\Mcp\validate_modern_headers($headers, $this->modernBody()));
    }

    public function testUnsupportedVersionReturnsMinus32022WithSupportedList(): void
    {
        $body = $this->modernBody();
        $body['params']['_meta']['io.modelcontextprotocol/protocolVersion'] = '1900-01-01';
        $headers = ['MCP-Protocol-Version' => '1900-01-01', 'Mcp-Method' => 'tools/list'];

        $error = \WPPilot\Mcp\validate_modern_headers($headers, $body);

        self::assertNotNull($error);
        self::assertSame(400, $error['status']);
        self::assertSame(-32022, $error['body']['error']['code']);
        self::assertSame([VERSION_MODERN, VERSION_LEGACY], $error['body']['error']['data']['supported']);
        self::assertSame('1900-01-01', $error['body']['error']['data']['requested']);
    }

    public function testVersionHeaderBodyMismatchReturnsMinus32020(): void
    {
        $headers = ['MCP-Protocol-Version' => VERSION_LEGACY, 'Mcp-Method' => 'tools/list'];

        $error = \WPPilot\Mcp\validate_modern_headers($headers, $this->modernBody());

        self::assertNotNull($error);
        self::assertSame(400, $error['status']);
        self::assertSame(-32020, $error['body']['error']['code']);
    }

    public function testMethodHeaderBodyMismatchReturnsMinus32020(): void
    {
        $headers = $this->modernHeaders('resources/list');

        $error = \WPPilot\Mcp\validate_modern_headers($headers, $this->modernBody('tools/list'));

        self::assertNotNull($error);
        self::assertSame(-32020, $error['body']['error']['code']);
    }

    #[DataProvider('missingRequiredHeaders')]
    public function testMissingRequiredHeaderReturnsMinus32020(array $headers): void
    {
        $error = \WPPilot\Mcp\validate_modern_headers($headers, $this->modernBody());

        self::assertNotNull($error);
        self::assertSame(-32020, $error['body']['error']['code']);
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function missingRequiredHeaders(): array
    {
        return [
            'no version' => [['Mcp-Method' => 'tools/list']],
            'no method' => [['MCP-Protocol-Version' => VERSION_MODERN]],
            'empty' => [[]],
        ];
    }

    #[DataProvider('nameHeaderMethods')]
    public function testNameHeaderIsRequiredForNamedMethods(string $method, string $field, string $value): void
    {
        $body = $this->modernBody($method, [$field => $value]);

        $missing = \WPPilot\Mcp\validate_modern_headers($this->modernHeaders($method), $body);
        self::assertNotNull($missing);
        self::assertSame(-32020, $missing['body']['error']['code']);

        self::assertNull(\WPPilot\Mcp\validate_modern_headers($this->modernHeaders($method, $value), $body));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function nameHeaderMethods(): array
    {
        return [
            'tools/call' => ['tools/call', 'name', 'wppilot_create_post'],
            'prompts/get' => ['prompts/get', 'name', 'build_page'],
            'resources/read' => ['resources/read', 'uri', 'file:///wp-config.php'],
        ];
    }

    public function testNameHeaderMismatchReturnsMinus32020(): void
    {
        $body = $this->modernBody('tools/call', ['name' => 'real_tool']);
        $headers = $this->modernHeaders('tools/call', 'spoofed_tool');

        $error = \WPPilot\Mcp\validate_modern_headers($headers, $body);

        self::assertNotNull($error);
        self::assertSame(-32020, $error['body']['error']['code']);
        self::assertStringContainsString('spoofed_tool', $error['body']['error']['message']);
    }

    /**
     * A non-ASCII tool name arrives Base64-wrapped and must be decoded before
     * comparison, or every such call would be rejected as a mismatch.
     */
    public function testBase64SentinelNameHeaderIsDecodedBeforeComparison(): void
    {
        $name = 'wppilot_créer_page';
        $body = $this->modernBody('tools/call', ['name' => $name]);
        $headers = $this->modernHeaders('tools/call', '=?base64?' . base64_encode($name) . '?=');

        self::assertNull(\WPPilot\Mcp\validate_modern_headers($headers, $body));
    }

    public function testMalformedBase64SentinelIsRejected(): void
    {
        $body = $this->modernBody('tools/call', ['name' => 'x']);
        $headers = $this->modernHeaders('tools/call', '=?base64?!!!not-base64!!!?=');

        $error = \WPPilot\Mcp\validate_modern_headers($headers, $body);

        self::assertNotNull($error);
        self::assertSame(-32020, $error['body']['error']['code']);
    }

    public function testPlainValueIsNotTreatedAsSentinel(): void
    {
        self::assertSame('plain', \WPPilot\Mcp\decode_header_value('plain'));
    }

    #[DataProvider('unsafeHeaderValues')]
    public function testControlCharactersInHeaderValueAreRejected(string $value): void
    {
        self::assertFalse(\WPPilot\Mcp\header_value_is_safe($value));
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeHeaderValues(): array
    {
        return [
            'CR injection' => ["value\r\nX-Injected: 1"],
            'LF' => ["value\nmore"],
            'null byte' => ["value\0"],
        ];
    }

    #[DataProvider('paramHeaderComparisons')]
    public function testParamHeaderComparison(string $header, mixed $body, bool $expected): void
    {
        self::assertSame($expected, \WPPilot\Mcp\param_header_matches($header, $body));
    }

    /** @return array<string, array{0: string, 1: mixed, 2: bool}> */
    public static function paramHeaderComparisons(): array
    {
        return [
            'string match' => ['us-west1', 'us-west1', true],
            'string mismatch' => ['us-west1', 'eu-west1', false],
            'integer numeric equality' => ['42', 42, true],
            'integer formatted as float' => ['42.0', 42, true],
            'integer mismatch' => ['43', 42, false],
            'boolean true' => ['true', true, true],
            'boolean false' => ['false', false, true],
            'boolean wrong case' => ['True', true, false],
        ];
    }

    // --------------------------------------------------------- result shaping

    public function testModernResultCarriesResultTypeAndServerInfo(): void
    {
        $result = \WPPilot\Mcp\decorate_modern_result([], 'tools/call', 'GoBuildWP', '1.1.0');

        self::assertSame('complete', $result['resultType']);
        self::assertSame(
            ['name' => 'GoBuildWP', 'version' => '1.1.0'],
            $result['_meta']['io.modelcontextprotocol/serverInfo'],
        );
    }

    public function testOrdinaryResultHasNoCacheHints(): void
    {
        $result = \WPPilot\Mcp\decorate_modern_result([], 'tools/call', 'GoBuildWP', '1.1.0');

        self::assertArrayNotHasKey('ttlMs', $result);
        self::assertArrayNotHasKey('cacheScope', $result);
    }

    #[DataProvider('cacheableMethods')]
    public function testCacheableResultsCarryBoundedPrivateHints(string $method): void
    {
        $result = \WPPilot\Mcp\decorate_modern_result([], $method, 'GoBuildWP', '1.1.0');

        self::assertArrayHasKey('ttlMs', $result);
        self::assertGreaterThan(0, $result['ttlMs']);
        self::assertLessThanOrEqual(300000, $result['ttlMs']);

        // Never public: the ability list is filtered per user, per safety
        // profile, and per site configuration.
        self::assertSame('private', $result['cacheScope']);
    }

    /** @return array<string, array{0: string}> */
    public static function cacheableMethods(): array
    {
        $methods = [
            'tools/list', 'prompts/list', 'resources/list',
            'resources/read', 'resources/templates/list', 'server/discover',
        ];

        return array_combine($methods, array_map(static fn(string $m): array => [$m], $methods));
    }

    public function testExistingResultMetaIsPreserved(): void
    {
        $result = \WPPilot\Mcp\decorate_modern_result(['_meta' => ['acme/key' => 'kept']], 'tools/list', 'W', '1');

        self::assertSame('kept', $result['_meta']['acme/key']);
        self::assertArrayHasKey('io.modelcontextprotocol/serverInfo', $result['_meta']);
    }

    public function testToolsAreOrderedDeterministically(): void
    {
        $tools = [['name' => 'zeta'], ['name' => 'alpha'], ['name' => 'mid']];

        $sorted = \WPPilot\Mcp\sort_tools_deterministically($tools);

        self::assertSame(['alpha', 'mid', 'zeta'], array_column($sorted, 'name'));
    }

    // ------------------------------------------------------- removed methods

    #[DataProvider('removedMethods')]
    public function testRemovedMethodsAnswerMethodNotFound(string $method): void
    {
        self::assertTrue(\WPPilot\Mcp\is_removed_in_modern($method));

        $error = \WPPilot\Mcp\removed_method_error($method, 1);

        self::assertSame(404, $error['status']);
        self::assertSame(-32601, $error['body']['error']['code']);
    }

    /** @return array<string, array{0: string}> */
    public static function removedMethods(): array
    {
        $methods = [
            'ping', 'logging/setLevel', 'initialize', 'notifications/initialized',
            'notifications/roots/list_changed', 'resources/subscribe', 'resources/unsubscribe',
        ];

        return array_combine($methods, array_map(static fn(string $m): array => [$m], $methods));
    }

    public function testStillSupportedMethodsAreNotFlaggedRemoved(): void
    {
        foreach (['tools/list', 'tools/call', 'server/discover', 'prompts/list'] as $method) {
            self::assertFalse(\WPPilot\Mcp\is_removed_in_modern($method), $method);
        }
    }

    public function testResourceNotFoundUsesInvalidParamsUnderModern(): void
    {
        self::assertSame(-32602, \WPPilot\Mcp\not_found_error('gone', 1)['body']['error']['code']);
        self::assertSame(-32002, \WPPilot\Mcp\not_found_error('gone', 1, modern: false)['body']['error']['code']);
    }

    public function testMissingClientCapabilityErrorCode(): void
    {
        $error = \WPPilot\Mcp\missing_client_capability_error('sampling', 1);

        self::assertSame(400, $error['status']);
        self::assertSame(-32021, $error['body']['error']['code']);
        self::assertSame('sampling', $error['body']['error']['data']['capability']);
    }

    // ------------------------------------------------------------- discovery

    public function testDiscoverAdvertisesBothVersions(): void
    {
        $result = build_discover_result(build_capabilities(50, 3, 0), 'production-safe');

        self::assertSame([VERSION_MODERN, VERSION_LEGACY], $result['supportedVersions']);
    }

    public function testCapabilitiesReflectWhatIsRegistered(): void
    {
        $both = build_capabilities(10, 2, 0);
        self::assertArrayHasKey('tools', $both);
        self::assertArrayHasKey('prompts', $both);
        self::assertArrayNotHasKey('resources', $both);

        $none = build_capabilities(0, 0, 0);
        self::assertSame([], $none);
    }

    /**
     * The schema types each capability as an object. An empty PHP array encodes
     * as `[]`, which a strictly-validating client rejects.
     */
    public function testCapabilityValuesEncodeAsJsonObjects(): void
    {
        $encoded = json_encode(build_capabilities(10, 2, 0));

        self::assertSame('{"tools":{},"prompts":{}}', $encoded);
    }

    public function testDiscoverResultEncodesCapabilitiesAsObjects(): void
    {
        $encoded = json_encode(build_discover_result(build_capabilities(1, 0, 0), 'production-safe'));

        self::assertStringContainsString('"capabilities":{"tools":{}}', (string) $encoded);
    }

    /**
     * Advertising a subscription capability GoBuildWP cannot honour would leave a
     * client holding an open stream that never delivers anything.
     */
    public function testSubscriptionsAndTasksAreNeverAdvertised(): void
    {
        $capabilities = build_capabilities(50, 5, 5);

        self::assertArrayNotHasKey('subscriptions', $capabilities);
        self::assertArrayNotHasKey('logging', $capabilities);
        self::assertArrayNotHasKey('extensions', $capabilities);
    }

    public function testDiscoverResultGetsCacheHintsFromTheSharedDecorator(): void
    {
        $result = \WPPilot\Mcp\decorate_modern_result(
            build_discover_result(build_capabilities(1, 0, 0), 'read-only'),
            'server/discover',
            'GoBuildWP',
            '1.1.0',
        );

        self::assertSame('complete', $result['resultType']);
        self::assertSame(300000, $result['ttlMs']);
        self::assertSame('private', $result['cacheScope']);
        self::assertStringContainsString('read-only', $result['instructions']);
    }
}
