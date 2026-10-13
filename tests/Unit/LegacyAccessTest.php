<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Ability;
use WP_Error;
use WPPilot_Test_State;

use function WPPilot\Mcp\Legacy\admit_core_public_ability;
use function WPPilot\Mcp\Legacy\bridge_meta_tool_permission;
use function WPPilot\Mcp\Legacy\default_transport_capability;
use function WPPilot\Mcp\Legacy\exposed_only_by_core_flag;
use function WPPilot\Mcp\Legacy\is_own_route;
use function WPPilot\Mcp\Legacy\publishes;
use function WPPilot\Mcp\Legacy\serving_own_server;
use function WPPilot\Mcp\Legacy\stdio_server;
use function WPPilot\Mcp\Legacy\transport_permission;

require_once dirname(__DIR__, 2) . '/includes/capabilities.php';
require_once dirname(__DIR__, 2) . '/includes/mcp/legacy-access.php';

/**
 * What GoBuildWP's servers on the bundled MCP Adapter admit and publish.
 *
 * Two end-to-end findings drive this: a subscriber with an Application Password could open a
 * legacy session and read every skill resource, because GoBuildWP's servers left the adapter's
 * `read` default in place; and abilities public through WordPress 7.1's core flag were served on
 * the modern transport but refused on the legacy one. Both fixes are scoped to GoBuildWP's own
 * servers, because another plugin's server can share the adapter.
 */
final class LegacyAccessTest extends TestCase
{
    private mixed $saved_wp = null;

    protected function setUp(): void
    {
        WPPilot_Test_State::reset();
        $this->saved_wp = $GLOBALS['wp'] ?? null;
        stdio_server('');
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp'] = $this->saved_wp;
        stdio_server('');
        parent::tearDown();
    }

    // ------------------------------------------------------------ transport permission

    public function testSubscriberIsRefusedTheTransport(): void
    {
        WPPilot_Test_State::$current_user_id = 9;
        WPPilot_Test_State::$capabilities = ['read'];

        self::assertFalse(transport_permission());
    }

    public function testEditorIsRefusedTheTransport(): void
    {
        WPPilot_Test_State::$current_user_id = 8;
        WPPilot_Test_State::$capabilities = ['read', 'edit_posts', 'edit_others_posts', 'publish_posts'];

        self::assertFalse(transport_permission());
    }

    public function testAdministratorIsAdmitted(): void
    {
        WPPilot_Test_State::$current_user_id = 1;
        WPPilot_Test_State::$capabilities = ['read', 'manage_options'];

        self::assertTrue(transport_permission());
    }

    public function testSwitchedOffRefusesEvenAnAdministrator(): void
    {
        WPPilot_Test_State::$current_user_id = 1;
        WPPilot_Test_State::$capabilities = ['read', 'manage_options'];
        WPPilot_Test_State::$wppilot_enabled = false;

        self::assertFalse(transport_permission());
    }

    /**
     * The adapter's default server cannot carry a callback, so its gate is the capability filter.
     * On GoBuildWP's route a caller GoBuildWP refuses gets a capability nobody has.
     */
    public function testDefaultServerCapabilityIsClosedForRefusedCallersOnGoBuildWpRoutes(): void
    {
        WPPilot_Test_State::$current_user_id = 9;
        WPPilot_Test_State::$capabilities = ['read'];

        self::assertSame('do_not_allow', default_transport_capability('read', self::context('/mcp/wppilot')));
    }

    public function testDefaultServerCapabilityIsUnchangedForAdmittedCallers(): void
    {
        WPPilot_Test_State::$current_user_id = 1;
        WPPilot_Test_State::$capabilities = ['read', 'manage_options'];

        self::assertSame('read', default_transport_capability('read', self::context('/mcp/wppilot')));
    }

    /**
     * Elementor 4.3 bundles the adapter. Its servers keep whatever the adapter or Elementor
     * decided, however GoBuildWP would answer the caller.
     */
    #[DataProvider('foreignRoutes')]
    public function testOtherPluginsServersKeepTheirCapability(string $route): void
    {
        WPPilot_Test_State::$current_user_id = 9;
        WPPilot_Test_State::$capabilities = ['read'];

        self::assertSame('read', default_transport_capability('read', self::context($route)));
    }

    /** @return array<string, array{0: string}> */
    public static function foreignRoutes(): array
    {
        return [
            'elementor server' => ['/mcp/elementor-mcp'],
            'prefix lookalike' => ['/mcp/wppilot-other'],
            'no route' => [''],
        ];
    }

    public function testContextWithoutARequestIsLeftAlone(): void
    {
        self::assertSame('read', default_transport_capability('read', null));
        self::assertSame('edit_posts', default_transport_capability('edit_posts', new \stdClass()));
    }

    // ------------------------------------------------------------ routes

    #[DataProvider('ownRoutes')]
    public function testOwnRoutes(string $route, bool $expected): void
    {
        self::assertSame($expected, is_own_route($route));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function ownRoutes(): array
    {
        return [
            'canonical' => ['/mcp/wppilot', true],
            'oauth' => ['/mcp/wppilot-oauth', true],
            'canonical subpath' => ['/mcp/wppilot/x', true],
            'lookalike' => ['/mcp/wppilot-other', false],
            'rest api' => ['/wppilot/v1/run', false],
            'other server' => ['/mcp/elementor-mcp', false],
        ];
    }

    /**
     * The pre-rename slug is the adapter's own default server. It is GoBuildWP's only when the
     * adapter copy is.
     */
    public function testAliasSlugIsOwnOnlyWithOurAdapter(): void
    {
        self::assertSame(\wppilot_owns_mcp_adapter(), is_own_route('/mcp/mcp-adapter-default-server'));
    }

    public function testStdioNamesTheServerWhenThereIsNoRoute(): void
    {
        unset($GLOBALS['wp']);
        self::assertFalse(serving_own_server());

        stdio_server('wppilot');
        self::assertTrue(serving_own_server());

        stdio_server('elementor-mcp');
        self::assertFalse(serving_own_server());
    }

    // ------------------------------------------------------------ exposure

    /**
     * @param array<string, mixed> $meta
     */
    #[DataProvider('coreFlagCases')]
    public function testExposedOnlyByCoreFlag(array $meta, bool $expected): void
    {
        self::assertSame($expected, exposed_only_by_core_flag($meta));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: bool}> */
    public static function coreFlagCases(): array
    {
        return [
            'core public only' => [['public' => true], true],
            'core public, empty mcp block' => [['public' => true, 'mcp' => ['type' => 'tool']], true],
            'mcp public already' => [['mcp' => ['public' => true]], false],
            'core public, mcp says no' => [['public' => true, 'mcp' => ['public' => false]], false],
            'neither' => [[], false],
        ];
    }

    /**
     * discover-abilities lists by GoBuildWP's rule on GoBuildWP's servers, so it matches the
     * modern tools/list, and by the adapter's rule anywhere else.
     */
    public function testDiscoveryRuleFollowsTheServer(): void
    {
        $core_public = ['public' => true];

        self::serving('/mcp/wppilot');
        self::assertTrue(publishes($core_public));
        self::assertTrue(publishes(['mcp' => ['public' => true]]));
        self::assertFalse(publishes(['public' => true, 'mcp' => ['public' => false]]));

        self::serving('/mcp/elementor-mcp');
        self::assertFalse(publishes($core_public));
        self::assertTrue(publishes(['mcp' => ['public' => true]]));
    }

    // ------------------------------------------------------------ meta-tool bridge

    public function testOnlyTheTwoMetaToolsAreWrapped(): void
    {
        $inner = static fn(): bool => true;
        $args = ['permission_callback' => $inner];

        self::assertSame($args, bridge_meta_tool_permission($args, 'wppilot/create-post'));
        self::assertSame($args, bridge_meta_tool_permission($args, 'mcp-adapter/discover-abilities'));
        self::assertNotSame($inner, bridge_meta_tool_permission($args, 'mcp-adapter/execute-ability')['permission_callback']);
        self::assertNotSame($inner, bridge_meta_tool_permission($args, 'mcp-adapter/get-ability-info')['permission_callback']);
    }

    /**
     * Every answer of the adapter's own check other than the one exposure refusal stands.
     */
    public function testOtherAdapterAnswersPassThrough(): void
    {
        self::serving('/mcp/wppilot');
        foreach ([true, false, new WP_Error('authentication_required', 'x')] as $answer) {
            $wrapped = bridge_meta_tool_permission(
                ['permission_callback' => static fn(mixed $input = null): mixed => $answer],
                'mcp-adapter/execute-ability',
            )['permission_callback'];

            self::assertSame($answer, $wrapped(['ability_name' => 'core/get-site-info']));
        }
    }

    public function testGetAbilityInfoReachesACorePublicAbilityOnGoBuildWp(): void
    {
        self::serving('/mcp/wppilot');

        self::assertTrue(admit_core_public_ability(
            'mcp-adapter/get-ability-info',
            ['ability_name' => 'core/get-site-info'],
            self::refusal(),
            self::ability(['public' => true], true),
        ));
    }

    public function testExecuteAbilityAppliesTheTargetsOwnPermission(): void
    {
        self::serving('/mcp/wppilot');
        $input = ['ability_name' => 'core/get-site-info', 'parameters' => []];

        self::assertTrue(admit_core_public_ability('mcp-adapter/execute-ability', $input, self::refusal(), self::ability(['public' => true], true)));
        self::assertFalse(admit_core_public_ability('mcp-adapter/execute-ability', $input, self::refusal(), self::ability(['public' => true], false)));

        $denied = new WP_Error('rest_forbidden', 'no');
        self::assertSame($denied, admit_core_public_ability('mcp-adapter/execute-ability', $input, self::refusal(), self::ability(['public' => true], $denied)));
    }

    public function testRefusalStandsOnAnotherPluginsServer(): void
    {
        self::serving('/mcp/elementor-mcp');
        $refusal = self::refusal();

        self::assertSame($refusal, admit_core_public_ability('mcp-adapter/execute-ability', ['ability_name' => 'core/x'], $refusal, self::ability(['public' => true], true)));
    }

    public function testRefusalStandsForAnAbilityGoBuildWpDoesNotPublish(): void
    {
        self::serving('/mcp/wppilot');
        $refusal = self::refusal();

        self::assertSame($refusal, admit_core_public_ability('mcp-adapter/execute-ability', ['ability_name' => 'x/hidden'], $refusal, self::ability([], true)));
        self::assertSame($refusal, admit_core_public_ability('mcp-adapter/execute-ability', ['ability_name' => 'x/no'], $refusal, self::ability(['public' => true, 'mcp' => ['public' => false]], true)));
        self::assertSame($refusal, admit_core_public_ability('mcp-adapter/execute-ability', ['ability_name' => 'x/missing'], $refusal, null));
    }

    // ------------------------------------------------------------ helpers

    private static function context(string $route): object
    {
        $request = new class ($route) {
            public function __construct(private string $route)
            {
            }

            public function get_route(): string
            {
                return $this->route;
            }
        };

        $context = new \stdClass();
        $context->request = $request;

        return $context;
    }

    private static function serving(string $route): void
    {
        $GLOBALS['wp'] = (object) ['query_vars' => ['rest_route' => $route]];
    }

    private static function refusal(): WP_Error
    {
        return new WP_Error('ability_not_public_mcp', 'Ability is not exposed via MCP (mcp.public!=true)');
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function ability(array $meta, bool|WP_Error $permitted): WP_Ability
    {
        return new class ('core/get-site-info', $meta, $permitted) extends WP_Ability {
            /** @param array<string, mixed> $meta */
            public function __construct(string $name, array $meta, private bool|WP_Error $permitted)
            {
                parent::__construct($name, $meta);
            }

            public function check_permissions(mixed $input = null): bool|WP_Error
            {
                return $this->permitted;
            }
        };
    }
}
