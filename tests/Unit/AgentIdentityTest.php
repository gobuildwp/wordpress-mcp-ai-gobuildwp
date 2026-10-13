<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WP_Ability;
use WP_Error;
use WPPilot_Test_State;

use function WPPilot\OAuth\Middleware\record_oauth_identity;
use function WPPilot\OAuth\Middleware\request_authentication_error;
use function WPPilot\OAuth\Middleware\reset_request_context;
use function WPPilot\OAuth\Middleware\resolve_token_identity;

/**
 * An access token's scope and profile ceiling, enforced where the safety profile is.
 *
 * Every transport reaches an ability through one of three doors: the gate pipeline
 * (modern MCP, the REST shim, core's run route, Chat, Pro's approval replay), the
 * legacy adapter's pre-tool-call filter, or `wp_ability_permission_result` inside
 * WP_Ability::execute(). Each test asks the same question of all three, because a
 * limit that one door forgot is a limit that does not exist.
 */
final class AgentIdentityTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    private mixed $savedWpdb = null;

    private FakeTokenWpdb $wpdb;

    protected function setUp(): void
    {
        // WordPress defines it; the shared doubles only do once a kit test has loaded them.
        if (!\defined('ARRAY_A')) {
            \define('ARRAY_A', 'ARRAY_A');
        }
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb = new FakeTokenWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'production';
        unset(WPPilot_Test_State::$options['wppilot_ability_rules']);
        reset_request_context();
    }

    protected function tearDown(): void
    {
        foreach (array_keys($this->wpdb->rows) as $id) {
            wppilot_token_policy_cache($id, forget: true);
        }
        wppilot_token_policy_cache(404, forget: true);
        reset_request_context();
        WPPilot_Test_State::$options = $this->savedOptions;
        $GLOBALS['wpdb'] = $this->savedWpdb;
    }

    public function testRequestsWithoutAnAccessTokenAreUnrestricted(): void
    {
        $this->token(7, scope: ['abilities' => ['wppilot/get-post'], 'categories' => []], ceiling: 'readonly');

        self::assertNull(wppilot_agent_scope_error($this->write('wppilot/update-post')));
        self::assertSame('production', wppilot_effective_safety_profile());
        self::assertSame(['post_id' => 1], wppilot_gate_ability_call($this->write('wppilot/update-post'), ['post_id' => 1], 'rest'));
    }

    /**
     * Tokens minted before schema 2 have a NULL scope and an empty ceiling, and
     * must keep exactly the access they had.
     */
    public function testALegacyTokenKeepsFullAccess(): void
    {
        $this->actAs($this->token(7, scope: null, ceiling: ''));

        self::assertSame('production', wppilot_effective_safety_profile());
        foreach (['mcp', 'rest', 'chat'] as $transport) {
            self::assertSame(['id' => 3], wppilot_gate_ability_call($this->write('acme/save'), ['id' => 3], $transport));
        }
        self::assertTrue(wppilot_safety_filter_ability_permission(true, 'acme/save', [], $this->write('acme/save')));
    }

    public function testAnAbilityOutsideTheScopeIsRefusedThroughEveryDoor(): void
    {
        $this->actAs($this->token(7, name: 'SEO report', scope: ['abilities' => ['wppilot/get-post'], 'categories' => []]));
        $outside = $this->write('wppilot/update-post');

        foreach (['mcp', 'rest', 'chat', 'approval'] as $transport) {
            $gated = wppilot_gate_ability_call($outside, ['post_id' => 1], $transport);
            self::assertInstanceOf(WP_Error::class, $gated, $transport);
            self::assertSame('wppilot_token_scope_denied', $gated->get_error_code(), $transport);
            self::assertStringContainsString('SEO report', $gated->get_error_message());
        }

        $permission = wppilot_safety_filter_ability_permission(true, 'wppilot/update-post', [], $outside);
        self::assertInstanceOf(WP_Error::class, $permission);
        self::assertSame('wppilot_token_scope_denied', $permission->get_error_code());

        self::assertFalse(wppilot_safety_profile_allows_ability($outside));
    }

    public function testAnAbilityInsideTheScopePasses(): void
    {
        $this->actAs($this->token(7, scope: ['abilities' => ['wppilot/get-post'], 'categories' => []]));
        $inside = $this->read('wppilot/get-post');

        self::assertSame(['post_id' => 1], wppilot_gate_ability_call($inside, ['post_id' => 1], 'mcp'));
        self::assertTrue(wppilot_safety_filter_ability_permission(true, 'wppilot/get-post', [], $inside));
    }

    public function testProviderWildcardsAndCategoriesWidenTheScope(): void
    {
        $this->actAs($this->token(7, scope: ['abilities' => ['rank-math/*'], 'categories' => ['content']]));

        self::assertNull(wppilot_agent_scope_error($this->write('rank-math/set-link-settings')));
        self::assertNull(wppilot_agent_scope_error($this->write('acme/save', category: 'content')));
        self::assertNotNull(wppilot_agent_scope_error($this->write('acme/save', category: 'commerce')));
        // A wildcard is a provider, not a string prefix: rank-math-pro is another plugin.
        self::assertNotNull(wppilot_agent_scope_error($this->write('rank-math-pro/set-thing')));
    }

    /**
     * The adapter's meta-tools are how a scoped agent finds what it may call; the
     * target ability is still checked when execute() runs it.
     */
    public function testHubProtectedAbilitiesStayReachable(): void
    {
        $this->actAs($this->token(7, scope: ['abilities' => ['wppilot/get-post'], 'categories' => []]));

        self::assertNull(wppilot_agent_scope_error($this->read('mcp-adapter/discover-abilities')));
        self::assertTrue(wppilot_safety_profile_allows_ability($this->write('mcp-adapter/execute-ability')));
    }

    public function testACeilingLowersTheEffectiveProfile(): void
    {
        $this->actAs($this->token(7, ceiling: 'readonly'));

        self::assertSame('readonly', wppilot_effective_safety_profile());
        self::assertSame('production', wppilot_get_safety_profile(), 'the site setting itself is untouched');

        $write = wppilot_gate_ability_call($this->write('wppilot/update-post'), ['post_id' => 1], 'mcp');
        self::assertInstanceOf(WP_Error::class, $write);
        self::assertSame('wppilot_safety_profile_blocked', $write->get_error_code());
        self::assertTrue($write->get_error_data()['ceiling']);

        $permission = wppilot_safety_filter_ability_permission(true, 'wppilot/update-post', [], $this->write('wppilot/update-post'));
        self::assertInstanceOf(WP_Error::class, $permission);

        self::assertSame(['post_id' => 1], wppilot_gate_ability_call($this->read('wppilot/get-post'), ['post_id' => 1], 'mcp'));
    }

    public function testACeilingNeverRaisesTheSiteProfile(): void
    {
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'readonly';
        $this->actAs($this->token(7, ceiling: 'production'));

        self::assertSame('readonly', wppilot_effective_safety_profile());
        $write = wppilot_gate_ability_call($this->write('wppilot/update-post'), ['post_id' => 1], 'rest');
        self::assertInstanceOf(WP_Error::class, $write);
        self::assertArrayNotHasKey('ceiling', $write->get_error_data(), 'the site profile, not the token, refused it');
    }

    public function testAProductionCeilingKeepsCriticalAbilitiesOffADeveloperSite(): void
    {
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'developer';
        $critical = new WP_Ability('wppilot/execute-php', [], [], 'code-execution');

        self::assertTrue(wppilot_safety_profile_allows_ability($critical));
        $this->actAs($this->token(7, ceiling: 'production'));
        self::assertFalse(wppilot_safety_profile_allows_ability($critical));
    }

    /**
     * A token revoked while its request is in flight, or whose row cannot be read,
     * must not fall back to "no limits".
     */
    public function testARevokedTokenMayDoNothing(): void
    {
        $this->actAs(404);

        $error = wppilot_agent_scope_error($this->read('wppilot/get-post'));
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('wppilot_token_revoked', $error->get_error_code());
        self::assertSame('readonly', wppilot_effective_safety_profile());
        self::assertNull(wppilot_agent_scope_error($this->read('mcp-adapter/discover-abilities')));
    }

    public function testExpiredTokensAreRefusedWithAClearReason(): void
    {
        $secret = 'wpp_expired-secret';
        $this->wpdb->rows[9] = [
            'id' => 9,
            'user_id' => 1,
            'name' => 'Old job',
            'token_hash' => wppilot_token_hash($secret),
            'expires' => gmdate('Y-m-d H:i:s', time() - 60),
            'scope' => null,
            'ceiling' => '',
        ];

        self::assertNull(wppilot_token_authenticate($secret));
        self::assertStringContainsString('expired', wppilot_token_refusal());

        resolve_token_identity(0, 'Bearer ' . $secret);
        $error = request_authentication_error();
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertStringContainsString('expired on', $error->get_error_message());

        // An unknown secret stays generic: nothing about which tokens exist leaks.
        self::assertNull(wppilot_token_authenticate('wpp_never-issued'));
        self::assertSame('', wppilot_token_refusal());
        resolve_token_identity(0, 'Bearer wpp_never-issued');
        self::assertSame('Invalid, expired, or revoked GoBuildWP access token.', request_authentication_error()?->get_error_message());
    }

    /**
     * A plugin that reads the current user during plugins_loaded settles "nobody" before the
     * middleware is registered, so no verdict was recorded and a bad token got core's generic
     * 401. The REST authentication step now judges an unjudged Bearer credential itself.
     */
    public function testAnUnjudgedBearerIsJudgedAtRestAuthentication(): void
    {
        $secret = 'wpp_expired-late';
        $this->wpdb->rows[11] = [
            'id' => 11,
            'user_id' => 1,
            'name' => 'Late',
            'token_hash' => wppilot_token_hash($secret),
            'expires' => gmdate('Y-m-d H:i:s', time() - 60),
            'scope' => null,
            'ceiling' => '',
        ];
        $saved_server = $_SERVER;
        $saved_get = $_GET;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $secret;
        $_GET['rest_route'] = '/mcp/wppilot';
        // The refusal carries a WWW-Authenticate challenge naming the metadata URL, which the
        // discovery module (not loaded in unit tests) builds.
        if (!function_exists('WPPilot\\OAuth\\Endpoints\\Discovery\\protected_resource_metadata_url')) {
            eval('namespace WPPilot\\OAuth\\Endpoints\\Discovery; function protected_resource_metadata_url(): string { return "https://example.test/.well-known/oauth-protected-resource"; }');
        }
        reset_request_context();
        try {
            $result = \WPPilot\OAuth\Middleware\reject_invalid_bearer(null);
        } finally {
            $_SERVER = $saved_server;
            $_GET = $saved_get;
        }

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertStringContainsString('expired on', $result->get_error_message());
    }

    public function testPolicyValidation(): void
    {
        self::assertInstanceOf(WP_Error::class, wppilot_token_validate_policy(['abilities' => [], 'categories' => []], ''));
        self::assertInstanceOf(WP_Error::class, wppilot_token_validate_policy(['abilities' => ['not an ability']], ''));
        self::assertInstanceOf(WP_Error::class, wppilot_token_validate_policy(null, 'root'));
        self::assertSame(['scope' => null, 'ceiling' => ''], wppilot_token_validate_policy(null, 'developer'));
        self::assertSame(
            ['scope' => ['abilities' => ['wppilot/get-post', 'rank-math/*'], 'categories' => ['content']], 'ceiling' => 'readonly'],
            wppilot_token_validate_policy(
                ['abilities' => ['WPPilot/get-post', 'rank-math/*', 'bad', 'wppilot/get-post'], 'categories' => ['content', '']],
                'readonly',
            ),
        );
    }

    public function testAnUnknownStoredCeilingCapsAtReadOnly(): void
    {
        $this->actAs($this->token(7, ceiling: 'mystery'));

        self::assertSame('readonly', wppilot_effective_safety_profile());
    }

    /**
     * Checking a token's owner runs map_meta_cap, where a plugin (Yoast SEO) may ask for the
     * current user before the bearer filter has returned. That asked determine_current_user
     * again and recursed until PHP ran out of memory: every token request 500ed. The inner
     * call must leave the identity alone, and the outer one still authenticate.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAReentrantIdentityLookupDuringTokenValidationDoesNotRecurse(): void
    {
        require_once \dirname(__DIR__) . '/doubles/cloud.php';
        require_once \dirname(__DIR__, 2) . '/includes/capabilities.php';
        if (!\defined('MINUTE_IN_SECONDS')) {
            \define('MINUTE_IN_SECONDS', 60);
        }
        if (!function_exists('wp_set_current_user')) {
            eval('function wp_set_current_user(int $id, string $name = ""): void { \WPPilot_Test_State::$current_user_id = $id; }');
        }
        $GLOBALS['wppilot_test_managers'] = [1];
        $secret = 'wpp_reentrant-secret';
        $this->wpdb->rows[21] = [
            'id' => 21,
            'user_id' => 1,
            'name' => 'Cloud',
            'token_hash' => wppilot_token_hash($secret),
            'expires' => null,
            'scope' => null,
            'ceiling' => '',
        ];
        $inner = [];
        $this->wpdb->onLookup = static function () use (&$inner): void {
            if (count($inner) < 5) {
                $inner[] = \WPPilot\OAuth\Middleware\resolve_bearer_identity(0);
            }
        };
        $saved_server = $_SERVER;
        $saved_get = $_GET;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $secret;
        $_GET['rest_route'] = '/wppilot/v1/cloud/status';
        try {
            $outer = \WPPilot\OAuth\Middleware\resolve_bearer_identity(0);
        } finally {
            $_SERVER = $saved_server;
            $_GET = $saved_get;
        }

        self::assertSame([0], $inner, 'the nested lookup returns the identity it was given, once');
        self::assertSame(1, $outer);
        self::assertSame(1, \WPPilot_Test_State::$current_user_id);
    }

    /**
     * Discovery is the other half of enforcement: tools/list must not advertise
     * what the next tools/call would refuse. Run apart because it needs a
     * wp_get_abilities() double, and defining one for the whole suite would change
     * what the compatibility probe reports to every other test.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testToolsListShowsOnlyWhatTheTokenMayCall(): void
    {
        $GLOBALS['agent_identity_test_abilities'] = [
            $this->read('wppilot/get-post'),
            $this->write('wppilot/update-post'),
            $this->write('rank-math/set-link-settings'),
        ];
        eval('function wp_get_abilities(array $args = []): array { return $GLOBALS["agent_identity_test_abilities"]; }');

        $names = static fn(): array => array_column(\WPPilot\Mcp\list_tools(), 'name');
        self::assertSame(['rank_math_set_link_settings', 'wppilot_get_post', 'wppilot_update_post'], $names());

        $this->actAs($this->token(7, scope: ['abilities' => ['wppilot/get-post', 'rank-math/*'], 'categories' => []], ceiling: 'readonly'));
        self::assertSame(['wppilot_get_post'], $names());
    }

    /**
     * The legacy adapter's execute meta-tool is the fourth door. Apart for the same
     * reason: it looks the target up with wp_get_ability().
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheLegacyAdapterDoorHonoursTheScope(): void
    {
        $GLOBALS['agent_identity_test_abilities'] = [
            'wppilot/get-post' => $this->read('wppilot/get-post'),
            'wppilot/update-post' => $this->write('wppilot/update-post'),
        ];
        eval('function wp_get_ability(string $name): ?WP_Ability { return $GLOBALS["agent_identity_test_abilities"][$name] ?? null; }');
        $this->actAs($this->token(7, scope: ['abilities' => ['wppilot/get-post'], 'categories' => []]));

        $call = static fn(string $name): mixed => wppilot_safety_pre_mcp_tool_call(
            ['ability_name' => $name, 'parameters' => []],
            'mcp-adapter-execute-ability',
        );
        $refused = $call('wppilot/update-post');
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('wppilot_token_scope_denied', $refused->get_error_code());
        self::assertIsArray($call('wppilot/get-post'));
    }

    /**
     * @param array{abilities?: list<string>, categories?: list<string>}|null $scope
     */
    private function token(int $id, string $name = 'Agent', ?array $scope = null, string $ceiling = ''): int
    {
        $this->wpdb->rows[$id] = [
            'id' => $id,
            'user_id' => 1,
            'name' => $name,
            'token_hash' => str_repeat('a', 64),
            'expires' => null,
            'scope' => $scope === null ? null : (string) json_encode($scope),
            'ceiling' => $ceiling,
        ];

        return $id;
    }

    private function actAs(int $token_id): void
    {
        record_oauth_identity(1, ['mcp'], 'token-' . $token_id, via: 'token');
    }

    private function write(string $name, string $category = ''): WP_Ability
    {
        return new WP_Ability($name, ['mcp' => ['public' => true]], ['type' => 'object'], $category);
    }

    private function read(string $name): WP_Ability
    {
        return new WP_Ability($name, ['mcp' => ['public' => true], 'annotations' => ['readonly' => true]], ['type' => 'object']);
    }
}

/**
 * The token table, answering the two lookups tokens.php makes: by id and by digest.
 */
final class FakeTokenWpdb
{
    public string $prefix = 'wp_';

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    /** Called on every token lookup, to simulate code that runs during validation. */
    public ?\Closure $onLookup = null;

    public function query(string $query): int
    {
        return 0;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $index = 0;
        return (string) preg_replace_callback('/%[ds]/', static function (array $match) use (&$index, $args): string {
            $value = $args[$index++] ?? '';
            return $match[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
        }, $query);
    }

    /** @return array<string, mixed>|null */
    public function get_row(string $query, mixed $output = null): ?array
    {
        if ($this->onLookup !== null) {
            ($this->onLookup)();
        }
        if (preg_match('/WHERE id = (\d+)/', $query, $match) === 1) {
            return $this->rows[(int) $match[1]] ?? null;
        }
        if (preg_match("/token_hash = '([0-9a-f]+)'/", $query, $match) === 1) {
            foreach ($this->rows as $row) {
                if ($row['token_hash'] === $match[1]) {
                    return $row;
                }
            }
        }
        return null;
    }
}
