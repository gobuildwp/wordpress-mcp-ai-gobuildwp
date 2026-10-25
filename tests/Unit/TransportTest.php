<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use WP_Ability;

use function WPPilot\Mcp\ability_for_tool;
use function WPPilot\Mcp\advertise_confirmation;
use function WPPilot\Mcp\client_ready_tool;
use function WPPilot\Mcp\empty_input_for;
use function WPPilot\Mcp\is_mcp_route;
use function WPPilot\Mcp\normalize_schema;
use function WPPilot\Mcp\repair_tool_list;
use function WPPilot\Mcp\route_is_served;
use function WPPilot\Mcp\tool_name;

/**
 * Routing and naming decisions the modern dispatcher makes before any I/O.
 */
final class TransportTest extends TestCase
{
    public function testModernSafetyAnnotationsPreserveFalseAndRejectNonBooleans(): void
    {
        self::assertSame(['readOnlyHint' => true, 'destructiveHint' => false], \WPPilot\Mcp\tool_safety_annotations([
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => 'true'],
        ]));
        self::assertSame([], \WPPilot\Mcp\tool_safety_annotations(['annotations' => ['readonly' => 'false']]));
        self::assertSame([], \WPPilot\Mcp\tool_safety_annotations([]));
    }

    #[DataProvider('mcpRoutes')]
    public function testMcpRoutesAreClaimed(string $route): void
    {
        self::assertTrue(is_mcp_route($route));
    }

    /** @return array<string, array{0: string}> */
    public static function mcpRoutes(): array
    {
        return [
            'wppilot endpoint' => ['/mcp/wppilot'],
            'wppilot subpath' => ['/mcp/wppilot/messages'],
            // The OAuth mirror advertises the modern revision in its protected-resource
            // metadata, so it must be claimed like the canonical endpoint.
            'oauth endpoint' => ['/mcp/wppilot-oauth'],
            'oauth subpath' => ['/mcp/wppilot-oauth/x'],
            'adapter default' => ['/mcp/mcp-adapter-default-server'],
            'adapter subpath' => ['/mcp/mcp-adapter-default-server/x'],
        ];
    }

    /**
     * The dispatcher must not intercept GoBuildWP's own REST surface or the OAuth
     * protocol endpoints under /wppilot/v1/oauth — those are ordinary routes with
     * their own handlers.
     */
    #[DataProvider('nonMcpRoutes')]
    public function testUnrelatedRoutesAreLeftAlone(string $route): void
    {
        self::assertFalse(is_mcp_route($route));
    }

    /** @return array<string, array{0: string}> */
    public static function nonMcpRoutes(): array
    {
        return [
            'oauth token endpoint' => ['/wppilot/v1/oauth/token'],
            'oauth lookalike' => ['/mcp/wppilot-oauthx'],
            'wppilot rest' => ['/wppilot/v1/run'],
            'wp core' => ['/wp/v2/posts'],
            'prefix lookalike' => ['/mcp/wppilot-other'],
            'root' => ['/'],
        ];
    }

    /**
     * The OAuth server exists only where the OAuth transport is allowed. The dispatcher runs
     * before route matching, so it asks the same question rather than answering on a route
     * legacy clients get a 404 from. The suite's home_url() is HTTPS, where OAuth is allowed.
     */
    public function testOAuthRouteIsServedOnlyWhereTheOAuthTransportIs(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/admin/connect-methods.php';

        self::assertTrue(route_is_served('/mcp/wppilot'));
        require_once dirname(__DIR__, 2) . '/includes/mcp/adapter-origin.php';
        // The default server's route is GoBuildWP's only when its adapter copy is the loaded one.
        self::assertSame(\wppilot_owns_mcp_adapter(), route_is_served('/mcp/mcp-adapter-default-server'));
        self::assertSame(\wppilot_oauth_transport_allowed(), route_is_served('/mcp/wppilot-oauth'));
        self::assertTrue(route_is_served('/mcp/wppilot-oauth'));
    }

    /**
     * Tool names must match what the bundled adapter produces, or the same
     * ability would be called one name under legacy and another under modern.
     */
    #[DataProvider('toolNames')]
    public function testAbilityNamesMapToToolNames(string $ability, string $expected): void
    {
        self::assertSame($expected, tool_name($ability));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function toolNames(): array
    {
        return [
            'namespaced' => ['wppilot/create-post', 'wppilot_create_post'],
            'multi hyphen' => ['wppilot/list-menu-locations', 'wppilot_list_menu_locations'],
            'adapter meta tool' => ['mcp-adapter/discover-abilities', 'mcp_adapter_discover_abilities'],
        ];
    }

    public function testUnknownToolResolvesToNothing(): void
    {
        self::assertNull(ability_for_tool('definitely_not_a_tool'));
    }

    /**
     * A tool whose schema is not an object breaks client-side validation, so the
     * outer shape is guaranteed even when an ability declares nothing.
     */
    #[DataProvider('emptySchemas')]
    public function testEmptySchemaBecomesAnObjectSchema(mixed $schema): void
    {
        self::assertSame(['type' => 'object'], normalize_schema($schema));
    }

    /** @return array<string, array{0: mixed}> */
    public static function emptySchemas(): array
    {
        return [
            'null' => [null],
            'empty array' => [[]],
            'string' => ['nonsense'],
            'boolean' => [true],
        ];
    }

    /**
     * The revision loosened inputSchema to any JSON Schema 2020-12 keyword, so
     * an authored schema passes through unchanged rather than being filtered to
     * a known keyword list.
     */
    public function testAuthoredSchemaPassesThroughUnchanged(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
            'additionalProperties' => false,
            '$defs' => ['thing' => ['type' => 'string']],
            'unevaluatedProperties' => false,
        ];

        self::assertSame($schema, normalize_schema($schema));
    }

    /**
     * `json_encode()` renders an empty PHP array as `[]`, so the
     * `'properties' => []` that every no-input ability declares reached clients
     * as a JSON array. Anthropic rejects the entire tools payload for it —
     * "tools.41.custom.input_schema.properties: Input should be an object" —
     * which fails every call on the connection, not the one ability at fault.
     */
    public function testEmptyPropertiesEncodeAsAnObject(): void
    {
        $schema = normalize_schema([
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ]);

        self::assertInstanceOf(stdClass::class, $schema['properties']);
        self::assertStringContainsString('"properties":{}', (string) json_encode($schema));
        self::assertStringNotContainsString('"properties":[]', (string) json_encode($schema));
    }

    /**
     * An object property may declare an empty `properties` of its own, and it
     * fails validation exactly the same way as one at the root.
     */
    public function testNestedEmptyPropertiesEncodeAsAnObject(): void
    {
        $schema = normalize_schema([
            'type' => 'object',
            'properties' => [
                'options' => ['type' => 'object', 'properties' => []],
                'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => []]],
            ],
        ]);

        self::assertStringNotContainsString('"properties":[]', (string) json_encode($schema));
        self::assertInstanceOf(stdClass::class, $schema['properties']['options']['properties']);
        self::assertInstanceOf(stdClass::class, $schema['properties']['items']['items']['properties']);
    }

    /**
     * `additionalProperties` holds a schema, not a map of them, so a `false`
     * there is a meaningful constraint and must survive the coercion that fixes
     * the sibling keyword.
     */
    public function testFalseAdditionalPropertiesIsNotCoerced(): void
    {
        $schema = normalize_schema([
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
            'required' => [],
        ]);

        self::assertFalse($schema['additionalProperties']);
        self::assertSame([], $schema['required']);
    }

    /**
     * A no-argument call has to send something, and which "nothing" is correct
     * differs by ability. One declaring WPPILOT_NO_INPUT_SCHEMA validates its
     * input with rest_is_object(), which rejects null, so passing null there
     * refused every no-argument tool with "input is not of type object".
     */
    public function testNoInputSchemaAbilityIsCalledWithAnEmptyObject(): void
    {
        $ability = new WP_Ability(
            'wppilot/system-status',
            [],
            [
                'type' => 'object',
                'properties' => [],
                'additionalProperties' => false,
            ],
        );

        self::assertSame([], empty_input_for($ability));
    }

    /**
     * The mirror case: an ability declaring no schema at all rejects any input,
     * including an empty array, so it is the one that must be passed null.
     */
    public function testAbilityWithoutAnInputSchemaIsCalledWithNull(): void
    {
        self::assertNull(empty_input_for(new WP_Ability('core/example')));
    }

    /**
     * call_tool() refuses a destructive ability without `confirm: true`, but
     * `confirm` belongs to the transport rather than to any ability's schema.
     * Abilities also declare `additionalProperties: false`, so a client that
     * validates arguments against the advertised schema drops the field and the
     * refusal repeats forever. Advertising it is what makes it sendable.
     */
    public function testDestructiveToolAdvertisesConfirm(): void
    {
        $schema = advertise_confirmation($this->destructiveAbility(), [
            'type' => 'object',
            'properties' => ['post_id' => ['type' => 'integer']],
            'required' => ['post_id'],
            'additionalProperties' => false,
        ]);

        self::assertSame('boolean', $schema['properties']['confirm']['type']);
        self::assertSame(['post_id', 'confirm'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
    }

    /**
     * An ability that is not gated gains nothing: advertising `confirm` there
     * would invite a field call_tool() strips again before execute().
     */
    public function testReadOnlyToolDoesNotAdvertiseConfirm(): void
    {
        $ability = new WP_Ability('wppilot/list-content', [
            'annotations' => ['readonly' => true],
        ]);
        $schema = ['type' => 'object', 'properties' => []];

        self::assertSame($schema, advertise_confirmation($ability, $schema));
    }

    /**
     * An ability declaring its own `confirm` keeps its own description and
     * requirement: the transport reads that same property, so overwriting it
     * would replace a deliberate contract with a generic one.
     */
    public function testAbilityDeclaringItsOwnConfirmIsLeftAlone(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['confirm' => ['type' => 'boolean', 'description' => 'Own wording.']],
            'required' => [],
        ];

        self::assertSame($schema, advertise_confirmation($this->destructiveAbility(), $schema));
    }

    /**
     * The advertised copy is the only thing that changes. call_tool() strips
     * `confirm` by asking the ability's real schema, so injecting it here must
     * not make the field look declared to that check.
     */
    public function testAdvertisedConfirmDoesNotReachTheAbilitySchema(): void
    {
        $ability = $this->destructiveAbility();
        advertise_confirmation($ability, $ability->get_input_schema());

        self::assertArrayNotHasKey('confirm', $ability->get_input_schema()['properties']);
    }

    private function destructiveAbility(): WP_Ability
    {
        return new WP_Ability(
            'wppilot/delete-post',
            [],
            [
                'type' => 'object',
                'properties' => ['post_id' => ['type' => 'integer']],
            ],
        );
    }

    /**
     * ACF Pro 6.8's native abilities declare `"type": "null"` (no input) and
     * `["object", "null"]` (optional object) roots. MCP requires `"object"`, and
     * Cloudflare MCP Portals rejected the whole tools/list for eight such tools.
     */
    public function testNullInputRootIsAdvertisedAsAnEmptyObject(): void
    {
        $tool = client_ready_tool(['name' => 'acf-list', 'inputSchema' => ['type' => 'null', 'description' => 'No input.']]);

        self::assertNotNull($tool);
        self::assertSame('object', $tool['inputSchema']['type']);
        self::assertFalse($tool['inputSchema']['additionalProperties']);
        self::assertSame('No input.', $tool['inputSchema']['description']);
        self::assertSame(
            '{"type":"object","properties":{},"additionalProperties":false,"description":"No input."}',
            json_encode($tool['inputSchema']),
        );
    }

    public function testNullableObjectRootBecomesObject(): void
    {
        $tool = client_ready_tool([
            'name' => 'acf-get',
            'inputSchema' => ['type' => ['object', 'null'], 'properties' => ['id' => ['type' => 'integer']]],
        ]);

        self::assertNotNull($tool);
        self::assertSame('object', $tool['inputSchema']['type']);
        self::assertSame(['id' => ['type' => 'integer']], $tool['inputSchema']['properties']);
    }

    public function testObjectSchemaIsLeftAsAuthored(): void
    {
        $schema = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];
        $tool = client_ready_tool(['name' => 'x', 'inputSchema' => $schema, 'outputSchema' => $schema]);

        self::assertSame($schema, $tool['inputSchema'] ?? null);
        self::assertSame($schema, $tool['outputSchema'] ?? null);
    }

    public function testToolWithNoObjectFormOfItsInputIsLeftOut(): void
    {
        self::assertNull(client_ready_tool(['name' => 'scalar', 'inputSchema' => ['type' => 'string']]));
    }

    public function testNonObjectOutputSchemaIsDroppedButTheToolKept(): void
    {
        $tool = client_ready_tool([
            'name' => 'list',
            'inputSchema' => ['type' => 'object'],
            'outputSchema' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]);

        self::assertNotNull($tool);
        self::assertArrayNotHasKey('outputSchema', $tool);
    }

    /**
     * The legacy adapter answers clients without modern `_meta`; its list gets
     * the same rules, and one unusable tool must not take the rest with it.
     */
    public function testLegacyToolListIsRepairedWithoutLosingTheRest(): void
    {
        $tools = repair_tool_list([
            ['name' => 'a', 'inputSchema' => ['type' => 'null']],
            ['name' => 'b', 'inputSchema' => ['type' => 'string']],
            ['name' => 'c', 'inputSchema' => ['type' => ['object', 'null'], 'properties' => []]],
        ]);

        self::assertSame(['a', 'c'], array_column($tools, 'name'));
        self::assertSame('object', $tools[0]['inputSchema']['type']);
        self::assertSame('object', $tools[1]['inputSchema']['type']);
        self::assertSame('{}', json_encode($tools[1]['inputSchema']['properties']));
    }

    public function testNullRootAbilityIsCalledWithNull(): void
    {
        self::assertNull(empty_input_for(new WP_Ability('acf/list', input_schema: ['type' => 'null'])));
    }

    public function testNullableObjectAbilityIsCalledWithAnEmptyObject(): void
    {
        self::assertSame([], empty_input_for(new WP_Ability('acf/get', input_schema: ['type' => ['object', 'null']])));
    }

    /**
     * The REST run shim takes `{"input":{}}` as "no arguments" the same way.
     */
    public function testRestShimPassesNullToANullRootAbility(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/rest/shim.php';

        self::assertNull(\wppilot_normalize_empty_ability_input(new WP_Ability('acf/list', input_schema: ['type' => 'null']), []));
        self::assertSame([], \wppilot_normalize_empty_ability_input(
            new WP_Ability('acf/get', input_schema: ['type' => ['object', 'null']]),
            [],
        ));
        self::assertSame(['id' => 1], \wppilot_normalize_empty_ability_input(
            new WP_Ability('acf/list', input_schema: ['type' => 'null']),
            ['id' => 1],
        ));
    }
}
