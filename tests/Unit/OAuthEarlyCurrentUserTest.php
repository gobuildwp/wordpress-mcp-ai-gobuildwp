<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot_Test_State;

use function WPPilot\OAuth\Middleware\reject_invalid_bearer;
use function WPPilot\OAuth\Middleware\request_authentication_error;
use function WPPilot\OAuth\Middleware\request_oauth_identity;
use function WPPilot\OAuth\Middleware\reset_request_context;
use function WPPilot\OAuth\Middleware\resolve_bearer_identity_now;

/**
 * A plugin that asks for the current user during plugins_loaded must not break OAuth.
 *
 * WPS Hide Login calls site_url() on plugins_loaded 9999, and its own filter asks
 * is_user_logged_in(). That runs determine_current_user, and so the Bearer
 * middleware, before WordPress has created $wp_rewrite. The token audience is
 * rest_url(), which dereferences $wp_rewrite, so validation fataled; the catch
 * turned the fatal into HTTP 500 "OAuth authentication failed." on every MCP call.
 *
 * Each test runs in its own process: they define WordPress functions and set
 * $wp_rewrite, and a later test must not inherit either.
 */
final class OAuthEarlyCurrentUserTest extends TestCase
{
    private const MCP_ROUTE = '/mcp/wppilot-oauth';

    protected function setUp(): void
    {
        self::define_core_doubles();
        unset($GLOBALS['wp_rewrite']);
        WPPilot_Test_State::$current_user_id = 0;
        reset_request_context();
        $_GET['rest_route'] = self::MCP_ROUTE;
        // An OAuth access token is a JWT, never a GoBuildWP token's fixed prefix.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxIn0.c2ln';
    }

    protected function tearDown(): void
    {
        unset($_GET['rest_route'], $_SERVER['HTTP_AUTHORIZATION'], $GLOBALS['wp_rewrite']);
        reset_request_context();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_bearer_asked_about_during_plugins_loaded_authenticates_once_rewrite_exists(): void
    {
        // The validator reads the audience the way production does, through rest_url().
        $calls = 0;
        $validator = static function (string $authorization) use (&$calls): array {
            $calls++;
            self::assertSame('http://example.test/wp-json' . self::MCP_ROUTE, \rest_url('mcp/wppilot-oauth'));

            return ['user_id' => 7, 'scopes' => ['mcp'], 'client_id' => 'client-1'];
        };

        // plugins_loaded 9999: the hide-login plugin's is_user_logged_in().
        $early = resolve_bearer_identity_now(false, $validator);

        self::assertFalse($early, 'No identity can be settled before $wp_rewrite exists.');
        self::assertSame(0, $calls, 'The validator would dereference a null $wp_rewrite.');
        self::assertNull(request_authentication_error(), 'Deferring must not record the 500 that broke every MCP call.');
        self::assertNull(request_oauth_identity());

        // WordPress creates $wp_rewrite after plugins_loaded; rest_authentication_errors re-judges
        // the bearer through the same resolver.
        $GLOBALS['wp_rewrite'] = new \WP_Rewrite();
        $late = resolve_bearer_identity_now(0, $validator);

        self::assertSame(7, $late);
        self::assertSame(1, $calls);
        self::assertNull(request_authentication_error());
        self::assertSame(7, request_oauth_identity()['user_id'] ?? null);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_deferred_bearer_is_judged_at_rest_authentication_and_never_left_anonymous(): void
    {
        // plugins_loaded: deferred with nothing recorded, and WordPress caches "nobody".
        self::assertSame(0, resolve_bearer_identity_now(0));
        self::assertNull(request_authentication_error());
        self::assertSame(0, \get_current_user_id());

        // rest_authentication_errors: the bearer nothing has judged is judged now. This suite has
        // no signing key, so the credential cannot validate; what matters is that the request is
        // refused rather than served as anonymous.
        $GLOBALS['wp_rewrite'] = new \WP_Rewrite();
        $result = reject_invalid_bearer(null);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame(0, \get_current_user_id());
    }

    /**
     * Core's rest_url() reaches $wp_rewrite->using_index_permalinks() when pretty permalinks are on;
     * this double fails the same way, so a validator that runs too early fails here as on a real site.
     */
    private static function define_core_doubles(): void
    {
        // The challenge a refused Bearer credential carries names the discovery document.
        require_once dirname(__DIR__, 2) . '/includes/oauth/endpoints/discovery.php';
        if (!class_exists('WP_Rewrite', false)) {
            eval('class WP_Rewrite { public function using_index_permalinks(): bool { return false; } }');
        }
        if (!function_exists('rest_url')) {
            eval('function rest_url(string $path = ""): string {
                $prefix = $GLOBALS["wp_rewrite"]->using_index_permalinks() ? "/index.php/wp-json/" : "/wp-json/";
                return "http://example.test" . $prefix . ltrim($path, "/");
            }');
        }
        if (!function_exists('wp_set_current_user')) {
            eval('function wp_set_current_user(int $id): object {
                WPPilot_Test_State::$current_user_id = $id;
                return (object) ["ID" => $id];
            }');
        }
    }
}
