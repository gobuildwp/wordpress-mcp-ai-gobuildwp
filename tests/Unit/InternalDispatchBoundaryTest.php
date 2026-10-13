<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot_Test_Rest_Request;
use WPPilot_Test_State;

use function WPPilot\OAuth\Middleware\authorize_routed_request;
use function WPPilot\OAuth\Middleware\record_oauth_identity;
use function WPPilot\OAuth\Middleware\reset_request_context;

require_once dirname(__DIR__) . '/doubles/mcp-surface.php';

/**
 * The OAuth / access-token route boundary against internal REST dispatches.
 *
 * An ability that calls rest_do_request() while serving an admitted MCP request (the block theme
 * kit, a backup plugin's export) must get through the route boundary; the same token sent straight
 * at that route over HTTP, or smuggled in as a REST batch subrequest, must not.
 */
final class InternalDispatchBoundaryTest extends TestCase
{
    private mixed $savedWp = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedWp = $GLOBALS['wp'] ?? null;
        WPPilot_Test_State::$current_user_id = 1;
        WPPilot_Test_State::$capabilities = ['manage_options'];
        reset_request_context();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp'] = $this->savedWp;
        WPPilot_Test_State::$current_user_id = 0;
        WPPilot_Test_State::$capabilities = [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
        reset_request_context();
        parent::tearDown();
    }

    private static function serving(string $url_route): void
    {
        $GLOBALS['wp'] = (object) ['query_vars' => ['rest_route' => $url_route]];
    }

    private static function authorize(string $route, string $method = 'GET'): mixed
    {
        return authorize_routed_request(null, null, new WPPilot_Test_Rest_Request($route, $method));
    }

    private static function assertRouteForbidden(mixed $verdict): void
    {
        self::assertInstanceOf(WP_Error::class, $verdict);
        self::assertSame('rest_oauth_route_forbidden', $verdict->get_error_code());
        self::assertSame(403, $verdict->get_error_data()['status']);
    }

    public function test_a_token_sent_straight_at_a_wordpress_route_is_still_refused(): void
    {
        record_oauth_identity(1, ['mcp'], 'token-7', via: 'token');
        self::serving('/wp/v2/posts');

        self::assertRouteForbidden(self::authorize('/wp/v2/posts', 'POST'));
        self::assertRouteForbidden(self::authorize('/ai1wm/v1/exports', 'POST'));
    }

    public function test_an_ability_may_dispatch_internally_during_an_admitted_mcp_request(): void
    {
        record_oauth_identity(1, ['mcp'], 'token-7', via: 'token');
        self::serving('/mcp/wppilot');

        self::assertNull(self::authorize('/mcp/wppilot', 'POST'), 'the MCP request itself');
        self::assertNull(self::authorize('/wp/v2/global-styles/12', 'POST'), 'the block theme kit');
        self::assertNull(self::authorize('/ai1wm/v1/exports', 'POST'), 'a backup export');
    }

    public function test_an_oauth_connector_gets_the_same_allowance_on_its_endpoint(): void
    {
        record_oauth_identity(1, ['mcp'], 'client-abc');
        self::serving('/mcp/wppilot-oauth/');

        self::assertNull(self::authorize('/mcp/wppilot-oauth', 'POST'));
        self::assertNull(self::authorize('/wp/v2/global-styles/themes/twentytwentyfive'));
    }

    public function test_the_ability_run_route_counts_as_an_ability_request(): void
    {
        record_oauth_identity(1, ['mcp'], 'token-7', via: 'token');
        self::serving('/wppilot/v1/abilities/wppilot/global-styles/run');

        self::assertNull(self::authorize('/wppilot/v1/abilities/wppilot/global-styles/run', 'POST'));
        self::assertNull(self::authorize('/wp/v2/global-styles/12'));
    }

    public function test_nothing_is_allowed_before_the_http_request_itself_was_admitted(): void
    {
        record_oauth_identity(1, ['mcp'], 'token-7', via: 'token');
        self::serving('/mcp/wppilot');

        self::assertRouteForbidden(self::authorize('/wp/v2/posts', 'POST'));
    }

    public function test_an_mcp_endpoint_the_identity_may_not_use_admits_nothing(): void
    {
        // An OAuth identity is confined to /mcp/wppilot-oauth; the Application Password endpoint
        // refuses it, and must not open the boundary for what follows.
        record_oauth_identity(1, ['mcp'], 'client-abc');
        self::serving('/mcp/wppilot');

        self::assertRouteForbidden(self::authorize('/mcp/wppilot', 'POST'));
        self::assertRouteForbidden(self::authorize('/wp/v2/posts', 'POST'));
    }

    public function test_batch_subrequests_stay_enforced(): void
    {
        record_oauth_identity(1, ['mcp'], 'token-7', via: 'token');
        self::serving('/batch/v1');

        self::assertRouteForbidden(self::authorize('/batch/v1', 'POST'));
        // A subrequest aimed at an MCP route is inside the boundary on its own merits, but it is
        // not the HTTP request, so it must not open the boundary for the subrequests after it.
        self::assertNull(self::authorize('/mcp/wppilot', 'POST'));
        self::assertRouteForbidden(self::authorize('/wp/v2/posts', 'POST'));
        self::assertRouteForbidden(self::authorize('/wp/v2/users/1', 'POST'));
    }

    public function test_an_internal_mcp_dispatch_cannot_admit_a_foreign_http_request(): void
    {
        record_oauth_identity(1, ['mcp'], 'token-7', via: 'token');
        self::serving('/wp/v2/posts');

        self::assertNull(self::authorize('/mcp/wppilot', 'POST'));
        self::assertRouteForbidden(self::authorize('/wp/v2/posts', 'POST'));
    }

    public function test_the_admission_belongs_to_the_identity_it_was_decided_for(): void
    {
        record_oauth_identity(1, ['mcp'], 'token-7', via: 'token');
        self::serving('/mcp/wppilot');
        self::assertNull(self::authorize('/mcp/wppilot', 'POST'));

        record_oauth_identity(2, ['mcp'], 'token-8', via: 'token');
        self::assertRouteForbidden(self::authorize('/wp/v2/posts', 'POST'));
    }

    public function test_an_internal_dispatch_still_needs_wppilot_manage_access(): void
    {
        record_oauth_identity(1, ['mcp'], 'token-7', via: 'token');
        self::serving('/mcp/wppilot');
        self::assertNull(self::authorize('/mcp/wppilot', 'POST'));

        WPPilot_Test_State::$capabilities = [];
        $verdict = self::authorize('/wp/v2/global-styles/12', 'POST');
        self::assertInstanceOf(WP_Error::class, $verdict);
        self::assertSame('rest_oauth_error', $verdict->get_error_code());
        self::assertSame(403, $verdict->get_error_data()['status']);
    }

    public function test_a_denied_mcp_request_admits_nothing(): void
    {
        record_oauth_identity(1, ['mcp'], 'token-7', via: 'token');
        self::serving('/mcp/wppilot');
        WPPilot_Test_State::$capabilities = [];
        self::assertInstanceOf(WP_Error::class, self::authorize('/mcp/wppilot', 'POST'));

        WPPilot_Test_State::$capabilities = ['manage_options'];
        self::assertRouteForbidden(self::authorize('/wp/v2/posts', 'POST'));
    }

    public function test_requests_without_a_wppilot_identity_are_unchanged(): void
    {
        WPPilot_Test_State::$current_user_id = 0;
        self::serving('/mcp/wppilot');

        // No credential at all: WordPress routes are none of OAuth's business, and the OAuth
        // endpoint answers with its challenge.
        self::assertNull(self::authorize('/wp/v2/posts'));
        $challenge = self::authorize('/mcp/wppilot-oauth', 'POST');
        self::assertInstanceOf(WP_Error::class, $challenge);
        self::assertSame('rest_oauth_required', $challenge->get_error_code());

        // A cookie or Application Password identity is left alone everywhere.
        WPPilot_Test_State::$current_user_id = 1;
        self::assertNull(self::authorize('/wp/v2/posts', 'POST'));
        self::assertNull(self::authorize('/mcp/wppilot-oauth', 'POST'));
    }
}
