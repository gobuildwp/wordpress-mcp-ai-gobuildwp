<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use WP_Abilities_Registry;
use WP_Ability;

use function WPPilot\Mcp\normalize_schema;

/**
 * What WordPress 7.1 changed under GoBuildWP, and what GoBuildWP decided to do about it.
 *
 * Each of these is a decision that stays invisible in a running site until it is wrong: an
 * exposure rule that silently drops a third-party ability, an enforcement loop reading a list
 * something else narrowed first, a schema advertised in a dialect the client refuses.
 */
final class WordPress71Test extends TestCase
{
    /**
     * `mcp.public` decides, and WordPress 7.1's channel-independent `public` decides only when
     * `mcp.public` is absent - core's own precedence for a single channel. The row carrying the
     * change is "core public, no mcp block": before 7.1 there was no such flag to read, and the
     * ability was not served.
     *
     * @param array<string, mixed> $meta
     */
    #[DataProvider('exposureCases')]
    public function testExposureFollowsCorePrecedence(array $meta, bool $expected): void
    {
        self::assertSame($expected, \wppilot_ability_is_exposed($meta));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: bool}> */
    public static function exposureCases(): array
    {
        return [
            'mcp public' => [['mcp' => ['public' => true]], true],
            'mcp private' => [['mcp' => ['public' => false]], false],
            'core public, no mcp block' => [['public' => true], true],
            'core public, mcp says no' => [['public' => true, 'mcp' => ['public' => false]], false],
            'core private, mcp says yes' => [['public' => false, 'mcp' => ['public' => true]], true],
            'neither flag' => [['annotations' => ['readonly' => true]], false],
            'no meta at all' => [[], false],
            // The flags are booleans. A truthy string is what a mis-registered
            // ability carries, and reading it as "public" publishes something
            // nobody meant to publish.
            'truthy string is not true' => [['mcp' => ['public' => '1']], false],
            'mcp block is not an array' => [['mcp' => 'yes'], false],
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    #[DataProvider('typeCases')]
    public function testMcpTypeDefaultsToTool(array $meta, string $expected): void
    {
        self::assertSame($expected, \wppilot_ability_mcp_type($meta));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function typeCases(): array
    {
        return [
            'declared prompt' => [['mcp' => ['type' => 'prompt']], 'prompt'],
            'declared resource' => [['mcp' => ['type' => 'resource']], 'resource'],
            // A fourth primitive is a registration mistake. Answering with it
            // would drop the ability out of every list rather than put it in
            // the wrong one, so it reads as a tool, which is what the adapter,
            // the transport and the Abilities screen each already assumed.
            'unknown primitive' => [['mcp' => ['type' => 'widget']], 'tool'],
            'declared tool' => [['mcp' => ['type' => 'tool']], 'tool'],
            'undeclared' => [['mcp' => ['public' => true]], 'tool'],
            'empty string' => [['mcp' => ['type' => '']], 'tool'],
            'not a string' => [['mcp' => ['type' => 3]], 'tool'],
        ];
    }

    /**
     * Enforcement reads the registry, not the discovery list.
     *
     * Since 7.1 every `wp_get_abilities()` call runs its filters, so a plugin can narrow what
     * the site publishes. The ability policy unregisters what an administrator switched off and
     * what the safety profile refuses; run that over the narrowed list and the hidden ability
     * keeps its registration - and stays executable through every path that does not go through
     * discovery. What is asserted here is that the registry read answers with what was
     * registered, whatever discovery has been told to say.
     */
    public function testRegistryReadAnswersWithEverythingRegistered(): void
    {
        $hidden = new WP_Ability('other-plugin/hidden', ['mcp' => ['public' => true]]);
        $shown = new WP_Ability('other-plugin/shown', ['mcp' => ['public' => true]]);

        WP_Abilities_Registry::seed_for_tests([
            'other-plugin/hidden' => $hidden,
            'other-plugin/shown' => $shown,
        ]);

        try {
            $registered = \wppilot_registered_abilities();

            self::assertCount(2, $registered);
            self::assertArrayHasKey('other-plugin/hidden', $registered);
            self::assertArrayHasKey('other-plugin/shown', $registered);
        } finally {
            WP_Abilities_Registry::seed_for_tests([]);
        }
    }

    public function testMetaReadIsSafeForAnythingThatIsNotAnAbility(): void
    {
        self::assertSame([], \wppilot_ability_meta(null));
        self::assertSame([], \wppilot_ability_meta('wppilot/get-post'));
        self::assertSame([], \wppilot_ability_meta(new stdClass()));
        self::assertSame(
            ['mcp' => ['public' => true]],
            \wppilot_ability_meta(new WP_Ability('wppilot/x', ['mcp' => ['public' => true]])),
        );
    }

    /**
     * A schema authored for WordPress is translated before it is advertised.
     *
     * `required: true` on the property is how WordPress spells it and how no JSON Schema
     * validator reads it, and `sanitize_callback` is a PHP callable with no meaning to a client
     * at all. A client validating arguments against the advertised schema dropped the field or
     * refused the tool.
     */
    public function testAdvertisedSchemaIsPreparedForClients(): void
    {
        $prepared = normalize_schema([
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'count' => ['type' => 'integer'],
            ],
        ]);

        self::assertSame(['title'], $prepared['required']);
        self::assertSame(['type' => 'string'], $prepared['properties']['title']);
    }

    /**
     * The preparation must not undo the empty-properties fix: `'properties' => []` still has to
     * reach the client as `{}` rather than `[]`, the shape Anthropic refuses the whole tools
     * payload over.
     */
    public function testPreparationKeepsEmptyPropertiesAnObject(): void
    {
        $prepared = normalize_schema(['type' => 'object', 'properties' => [], 'additionalProperties' => false]);

        self::assertStringContainsString('"properties":{}', (string) json_encode($prepared));
    }

    /**
     * The capability map is probed, not inferred from the version string. A feature plugin, a
     * backport or a release candidate can each put a capability on a WordPress whose reported
     * version says otherwise, and that version is the one thing here allowed to lie.
     */
    public function testWordPressCapabilitiesAreProbed(): void
    {
        $capabilities = \wppilot_wordpress_capabilities();

        foreach (
            [
                'abilities_registry',
                'abilities_query_args',
                'abilities_lifecycle_filters',
                'json_schema_client_prep',
                'ai_client',
            ] as $key
        ) {
            self::assertArrayHasKey($key, $capabilities);
            self::assertIsBool($capabilities[$key]);
        }

        // The doubles define both, so both must read as present. A false here
        // means the probe is asking for something that does not exist.
        self::assertTrue($capabilities['abilities_registry']);
        self::assertTrue($capabilities['json_schema_client_prep']);
    }

    public function testCompatibilityBlockPublishesTheTestedVersion(): void
    {
        $compatibility = \wppilot_server_compatibility();

        self::assertSame(WPPILOT_TESTED_WORDPRESS_VERSION, $compatibility['tested_wordpress_version']);
        self::assertSame(WPPILOT_MINIMUM_WORDPRESS_VERSION, $compatibility['minimum_wordpress_version']);
        self::assertIsArray($compatibility['wordpress_capabilities']);
    }
}
