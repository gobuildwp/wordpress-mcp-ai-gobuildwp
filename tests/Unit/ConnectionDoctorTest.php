<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPPilot_Test_Rest_Request;
use WPPilot_Test_State;

use function WPPilot\Troubleshoot\Doctor\authorization_fix;
use function WPPilot\Troubleshoot\Doctor\check_application_passwords;
use function WPPilot\Troubleshoot\Doctor\authorization_source;
use function WPPilot\Troubleshoot\Doctor\check_authorization_echo;
use function WPPilot\Troubleshoot\Doctor\check_clock;
use function WPPilot\Troubleshoot\Doctor\check_mcp_probe;
use function WPPilot\Troubleshoot\Doctor\check_oauth_challenge;
use function WPPilot\Troubleshoot\Doctor\check_oauth_metadata;
use function WPPilot\Troubleshoot\Doctor\check_oauth_urls;
use function WPPilot\Troubleshoot\Doctor\classify_response;
use function WPPilot\Troubleshoot\Doctor\detect_stack;
use function WPPilot\Troubleshoot\Doctor\echo_permission;
use function WPPilot\Troubleshoot\Doctor\echo_verdict;
use function WPPilot\Troubleshoot\Doctor\layer_fix;
use function WPPilot\Troubleshoot\Doctor\probe_evidence;
use function WPPilot\Troubleshoot\Doctor\run;
use function WPPilot\Troubleshoot\Doctor\summarize;

require_once dirname(__DIR__) . '/doubles/mcp-surface.php';
require_once dirname(__DIR__, 2) . '/includes/troubleshoot/checks.php';
require_once dirname(__DIR__, 2) . '/includes/troubleshoot/doctor.php';
require_once dirname(__DIR__, 2) . '/includes/app-password-blockers.php';

/**
 * The Connection Doctor's classifiers, fed captured responses.
 *
 * Each fixture is the shape a real layer answers with: the point of the doctor is to name who answered,
 * so every verdict is pinned against the headers and body that identify it.
 */
final class ConnectionDoctorTest extends TestCase
{
    private const MCP_PATH = '/wp-json/mcp/';

    /**
     * WordPress refuses Application Passwords on a non-HTTPS site that is not `local`, which is
     * the commonest 401 and invisible to every network probe.
     */
    public function testApplicationPasswordsOffOverPlainHttpIsNamed(): void
    {
        $off = check_application_passwords(false, false, 'production', true);
        self::assertSame('fail', $off['status']);
        self::assertStringContainsString('HTTPS', $off['finding']);
        self::assertStringContainsString('access token', $off['finding']);
        self::assertStringContainsString('WP_ENVIRONMENT_TYPE', $off['fix']);

        $filtered = check_application_passwords(false, true, 'production', false);
        self::assertSame('fail', $filtered['status']);
        self::assertStringContainsString('wp_is_application_passwords_available', $filtered['finding']);

        self::assertSame('pass', check_application_passwords(true, false, 'local', true)['status']);
    }

    /**
     * Wordfence switches Application Passwords off by default with a bare `__return_false`, so the
     * doctor has to read its setting to name it; the finding must carry Wordfence's own switch.
     */
    public function testWordfenceIsNamedWithItsExactSetting(): void
    {
        $blocker = \wppilot_app_passwords_blocker_from(true, ['/srv/wp/wp-includes/functions.php'], '/srv/wp/wp-content/plugins', '', '');
        self::assertIsArray($blocker);
        self::assertSame('wordfence', $blocker['source']);
        self::assertStringContainsString('Wordfence', $blocker['message']);
        self::assertStringContainsString('Disable WordPress application passwords', $blocker['remedy']);
        self::assertStringContainsString('OAuth', $blocker['remedy']);

        $check = check_application_passwords(false, true, 'production', true, $blocker);
        self::assertSame('fail', $check['status']);
        self::assertStringContainsString('Wordfence', $check['finding']);
        self::assertStringContainsString('Disable WordPress application passwords', $check['fix']);
        self::assertContains('Switched off by: Wordfence', $check['evidence']);
    }

    /**
     * Another plugin's callback is traced to its folder; a core callback such as `__return_false`
     * says nothing about who added it, so it must not be blamed on anyone.
     */
    public function testFilterCallbacksAreAttributedToTheirPlugin(): void
    {
        $plugins = 'C:\wp\wp-content\plugins';
        $blocker = \wppilot_app_passwords_blocker_from(false, [
            'C:\wp\wp-includes\functions.php',
            'C:\wp\wp-content\plugins\lockdown\src\Rules.php',
        ], $plugins, 'C:/wp/wp-content/mu-plugins', 'C:/wp/wp-content/themes');
        self::assertIsArray($blocker);
        self::assertSame('filter', $blocker['source']);
        self::assertStringContainsString('lockdown (plugins/lockdown/src/Rules.php)', $blocker['message']);

        $mu = \wppilot_app_passwords_blocker_from(false, ['/wp/wp-content/mu-plugins/no-app-pw.php'], '/wp/wp-content/plugins', '/wp/wp-content/mu-plugins', '');
        self::assertIsArray($mu);
        self::assertStringContainsString('mu-plugins/no-app-pw.php', $mu['name']);

        self::assertNull(\wppilot_app_passwords_blocker_from(false, ['/wp/wp-includes/functions.php'], '/wp/wp-content/plugins', '', ''));
        self::assertNull(\wppilot_app_passwords_blocker_from(false, [], '/wp/wp-content/plugins', '', ''));
    }

    public function testCallbackFilesAreReflected(): void
    {
        self::assertSame(__FILE__, \wppilot_callback_file(static fn(): bool => false));
        self::assertSame(__FILE__, \wppilot_callback_file([self::class, 'response']));
        self::assertSame('', \wppilot_callback_file('no_such_function_anywhere'));
        self::assertSame('', \wppilot_callback_file(null));
    }

    protected function setUp(): void
    {
        WPPilot_Test_State::reset();
        $GLOBALS['wppilot_test_transients'] = [];
        unset($GLOBALS['wppilot_test_site_url'], $GLOBALS['wppilot_test_is_ssl']);
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @return array{code: int, headers: array<array-key, mixed>, body: string, error: string}
     */
    private static function response(int $code, array $headers = [], string $body = '', string $error = ''): array
    {
        return ['code' => $code, 'headers' => $headers, 'body' => $body, 'error' => $error];
    }

    private static function wp_error_json(string $code, int $status): string
    {
        return (string) json_encode(['code' => $code, 'message' => 'Denied.', 'data' => ['status' => $status]]);
    }

    /**
     * @return iterable<string, array{array{code: int, headers: array<array-key, mixed>, body: string, error: string}, string, string}>
     */
    public static function responses(): iterable
    {
        yield 'WordPress refusal behind Cloudflare' => [
            self::response(401, ['Server' => 'cloudflare', 'CF-RAY' => '8a1b2c3d4e5f-LHR'], self::wp_error_json('rest_forbidden', 401)),
            'wordpress', '',
        ];
        yield 'JSON-RPC answer' => [
            self::response(200, [], '{"jsonrpc":"2.0","id":1,"result":{}}'),
            'wordpress', '',
        ];
        yield 'Cloudflare WAF block page' => [
            self::response(403, ['server' => 'cloudflare', 'cf-ray' => 'abc'], '<!DOCTYPE html><title>Attention Required! | Cloudflare</title><div id="cf-error-details">Sorry, you have been blocked</div>'),
            'cloudflare_block', 'Cloudflare',
        ];
        yield 'Cloudflare managed challenge' => [
            self::response(403, ['server' => 'cloudflare', 'cf-ray' => 'abc', 'cf-mitigated' => 'challenge'], '<html><title>Just a moment...</title></html>'),
            'cloudflare_challenge', 'Cloudflare',
        ];
        yield 'Cloudflare challenge without the header' => [
            self::response(503, ['server' => 'cloudflare', 'cf-ray' => 'abc'], '<script src="/cdn-cgi/challenge-platform/h/b/orchestrate"></script>'),
            'cloudflare_challenge', 'Cloudflare',
        ];
        yield 'ModSecurity 406' => [
            self::response(406, ['Server' => 'Apache'], '<html><head><title>Not Acceptable!</title></head><body><h1>Not Acceptable!</h1><p>An appropriate representation of the requested resource could not be found on this server. This error was generated by Mod_Security.</p></body></html>'),
            'modsecurity', 'ModSecurity',
        ];
        yield 'ModSecurity 403 page' => [
            self::response(403, ['Server' => 'Apache'], '<h1>Forbidden</h1><p>ModSecurity: Access denied with code 403 (phase 2).</p>'),
            'modsecurity', 'ModSecurity',
        ];
        yield 'Wordfence block behind Cloudflare' => [
            self::response(403, ['server' => 'cloudflare', 'cf-ray' => 'abc'], '<title>403 Forbidden</title><p>Your access to this site has been limited by the site owner</p><p>Generated by Wordfence</p>'),
            'wordfence', 'Wordfence',
        ];
        yield 'Sucuri firewall' => [
            self::response(403, ['x-sucuri-id' => '11005', 'x-sucuri-block' => 'BNP005'], '<title>Sucuri WebSite Firewall - Access Denied</title>'),
            'sucuri', 'Sucuri',
        ];
        yield 'Imunify360 bot protection' => [
            self::response(403, [], '<h1>Access denied by Imunify360 bot-protection. IPs used for automation should be whitelisted</h1>'),
            'imunify360', 'Imunify360',
        ];
        yield 'staging password' => [
            self::response(401, ['WWW-Authenticate' => 'Basic realm="Staging"'], '<h1>401 Authorization Required</h1>'),
            'http_basic_auth', 'HTTP authentication',
        ];
        yield 'anonymous host firewall' => [
            self::response(403, ['Server' => 'nginx'], '<html><body><h1>403 Forbidden</h1></body></html>'),
            'host_waf', 'Host firewall',
        ];
        yield 'web server 404' => [
            self::response(404, ['Server' => 'nginx'], '<html><body>404 Not Found</body></html>'),
            'not_routed', '',
        ];
        yield 'gateway error' => [
            self::response(502, [], '<h1>Bad Gateway</h1>'),
            'server_error', '',
        ];
        yield 'loopback failure' => [
            self::response(0, [], '', 'cURL error 28: Connection timed out'),
            'unreachable', '',
        ];
    }

    /**
     * @param array{code: int, headers: array<array-key, mixed>, body: string, error: string} $response
     */
    #[DataProvider('responses')]
    public function testResponsesAreAttributedToWhoeverSentThem(array $response, string $kind, string $layer): void
    {
        $class = classify_response($response);

        self::assertSame($kind, $class['kind']);
        self::assertSame($layer, $class['layer']);
    }

    public function testEvidenceQuotesTellingHeadersButNeverCookies(): void
    {
        $class = classify_response(self::response(403, [
            'Server' => 'cloudflare',
            'CF-RAY' => 'abc-LHR',
            'Set-Cookie' => 'wordpress_logged_in_secret=value',
        ], '<title>Attention Required! | Cloudflare</title>'));
        $evidence = implode("\n", probe_evidence('POST https://example.test/wp-json/mcp/wppilot', $class));

        self::assertStringContainsString('HTTP 403', $evidence);
        self::assertStringContainsString('cf-ray: abc-LHR', $evidence);
        self::assertStringContainsString('Attention Required! | Cloudflare', $evidence);
        self::assertStringNotContainsString('wordpress_logged_in', $evidence);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function stacks(): iterable
    {
        yield 'Apache' => ['Apache/2.4.58 (Ubuntu)', '', 'apache'];
        yield 'nginx from the response' => ['', 'nginx/1.25.3', 'nginx'];
        yield 'OpenResty' => ['openresty', '', 'nginx'];
        yield 'LiteSpeed' => ['LiteSpeed', 'LiteSpeed', 'litespeed'];
        yield 'IIS' => ['Microsoft-IIS/10.0', '', 'iis'];
        yield 'unknown' => ['', 'cloudflare', 'unknown'];
    }

    #[DataProvider('stacks')]
    public function testWebServerIsDetected(string $software, string $header, string $expected): void
    {
        self::assertSame($expected, detect_stack($software, $header));
    }

    public function testAuthorizationSourceFollowsTheCgiFallback(): void
    {
        self::assertSame('HTTP_AUTHORIZATION', authorization_source(['HTTP_AUTHORIZATION' => 'Basic eA==']));
        self::assertSame('REDIRECT_HTTP_AUTHORIZATION', authorization_source(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer x']));
        self::assertSame('PHP_AUTH_USER', authorization_source(['PHP_AUTH_USER' => 'admin']));
        self::assertSame('', authorization_source(['HTTP_AUTHORIZATION' => '  ']));
    }

    public function testEchoVerdictComparesWhatArrivedWithWhatWasSent(): void
    {
        self::assertSame(['authorization' => 'intact', 'source' => 'HTTP_AUTHORIZATION'], echo_verdict(['HTTP_AUTHORIZATION' => 'WPPilot-Doctor abc'], 'WPPilot-Doctor abc'));
        self::assertSame(['authorization' => 'intact', 'source' => 'REDIRECT_HTTP_AUTHORIZATION'], echo_verdict(['REDIRECT_HTTP_AUTHORIZATION' => 'WPPilot-Doctor abc'], 'WPPilot-Doctor abc'));
        self::assertSame('altered', echo_verdict(['HTTP_AUTHORIZATION' => 'Basic c3RhZ2luZw=='], 'WPPilot-Doctor abc')['authorization']);
        self::assertSame('missing', echo_verdict([], 'WPPilot-Doctor abc')['authorization']);
    }

    public function testStrippedHeaderFailsWithTheFixForTheServerInUse(): void
    {
        $echo = classify_response(self::response(200, [], '{"authorization":"missing","source":""}'));

        $apache = check_authorization_echo($echo, [], 'apache', '');
        self::assertSame('fail', $apache['status']);
        self::assertStringContainsString('SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1', $apache['fix']);
        self::assertStringContainsString('RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]', $apache['fix']);
        self::assertStringContainsString('CGIPassAuth On', $apache['fix']);

        self::assertStringContainsString('fastcgi_param HTTP_AUTHORIZATION $http_authorization;', check_authorization_echo($echo, [], 'nginx', '')['fix']);

        $litespeed = check_authorization_echo($echo, [], 'litespeed', '')['fix'];
        self::assertStringContainsString('OpenLiteSpeed ignores .htaccess', $litespeed);
        self::assertStringContainsString('Graceful Restart', $litespeed);
    }

    public function testIntactHeaderPassesAndABlockedEchoFallsBackToThisRequest(): void
    {
        $intact = classify_response(self::response(200, [], '{"authorization":"intact","source":"REDIRECT_HTTP_AUTHORIZATION"}'));
        self::assertSame('pass', check_authorization_echo($intact, [], 'apache', '')['status']);

        $blocked = classify_response(self::response(403, [], '<h1>Forbidden</h1>'));
        self::assertSame('warn', check_authorization_echo($blocked, [], 'apache', '')['status']);
        self::assertSame('pass', check_authorization_echo($blocked, [], 'apache', 'HTTP_AUTHORIZATION')['status']);
    }

    public function testWordPressRefusalPassesTheMcpProbe(): void
    {
        $class = classify_response(self::response(401, ['cf-ray' => 'abc'], self::wp_error_json('rest_forbidden', 401)));

        self::assertSame('pass', check_mcp_probe('mcp_anonymous', 'MCP', $class, [], true, self::MCP_PATH, '')['status']);
    }

    public function testAnonymousHandshakeIsAFailure(): void
    {
        $class = classify_response(self::response(200, [], '{"jsonrpc":"2.0","id":1,"result":{"serverInfo":{"name":"GoBuildWP"}}}'));

        self::assertSame('fail', check_mcp_probe('mcp_anonymous', 'MCP', $class, [], true, self::MCP_PATH, '')['status']);
    }

    public function testBlockedCredentialedRequestNamesTheLayerAndItsPathScopedFix(): void
    {
        $class = classify_response(self::response(403, ['server' => 'cloudflare', 'cf-ray' => 'abc'], '<title>Attention Required! | Cloudflare</title>'));
        $check = check_mcp_probe('mcp_with_credentials', 'MCP', $class, [], false, self::MCP_PATH, '/blog');

        self::assertSame('fail', $check['status']);
        self::assertStringContainsString('Cloudflare answered with HTTP 403', $check['finding']);
        self::assertStringContainsString('carry credentials', $check['finding']);
        self::assertStringContainsString('starts_with(http.request.uri.path, "/wp-json/mcp/")', $check['fix']);
        self::assertStringContainsString('"/blog/.well-known/"', $check['fix']);
        self::assertStringContainsString('Skip', $check['fix']);
    }

    public function testModSecurityFixRemovesARuleRatherThanTheFirewall(): void
    {
        $fix = layer_fix('modsecurity', self::MCP_PATH, '');

        self::assertStringContainsString('SecRuleRemoveById', $fix);
        self::assertStringContainsString('<LocationMatch "^/wp-json/mcp/">', $fix);
        self::assertStringNotContainsString('SecRuleEngine Off', $fix);
    }

    public function testUnreachableLoopbackIsAWarningNotAFailure(): void
    {
        $class = classify_response(self::response(0, [], '', 'cURL error 7'));

        self::assertSame('warn', check_mcp_probe('mcp_anonymous', 'MCP', $class, [], true, self::MCP_PATH, '')['status']);
    }

    public function testOauthChallengeNeedsTheResourceMetadataPointer(): void
    {
        $with = classify_response(self::response(401, ['WWW-Authenticate' => 'Bearer resource_metadata="https://example.test/.well-known/oauth-protected-resource"'], self::wp_error_json('rest_oauth_required', 401)));
        self::assertSame('pass', check_oauth_challenge($with, [], self::MCP_PATH, '')['status']);

        $stripped = classify_response(self::response(401, [], self::wp_error_json('rest_oauth_required', 401)));
        self::assertSame('fail', check_oauth_challenge($stripped, [], self::MCP_PATH, '')['status']);
    }

    public function testOauthMetadataMustBeServedByWordPress(): void
    {
        $ok = classify_response(self::response(200, ['content-type' => 'application/json'], '{"resource":"https://example.test/wp-json/mcp/wppilot-oauth"}'));
        self::assertSame('pass', check_oauth_metadata($ok, [], self::MCP_PATH, '')['status']);

        $server_404 = classify_response(self::response(404, ['Server' => 'nginx'], '<h1>404 Not Found</h1>'));
        self::assertSame('fail', check_oauth_metadata($server_404, [], self::MCP_PATH, '')['status']);
    }

    public function testClockSkewThresholds(): void
    {
        $now = 1_800_000_000;

        self::assertSame('pass', check_clock($now - 5, $now, 'date')['status']);
        self::assertSame('warn', check_clock($now - 90, $now, 'date')['status']);
        self::assertSame('fail', check_clock($now + 600, $now, 'date')['status']);
        self::assertStringContainsString('invalid_grant', check_clock($now + 600, $now, 'date')['finding']);
        self::assertSame('info', check_clock(null, $now, 'no response')['status']);
    }

    public function testOauthUrlMismatchesBehindInvalidGrant(): void
    {
        self::assertSame('pass', check_oauth_urls('https://example.test', 'https://example.test', true)['status']);

        $host = check_oauth_urls('https://www.example.test', 'https://example.test', true);
        self::assertSame('warn', $host['status']);
        self::assertStringContainsString('different hosts', $host['finding']);

        self::assertSame('warn', check_oauth_urls('https://example.test', 'http://example.test', true)['status']);

        $proxy = check_oauth_urls('https://example.test', 'https://example.test', false);
        self::assertSame('warn', $proxy['status']);
        self::assertStringContainsString("\$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'", $proxy['fix']);

        // The invalid_grant causes are always spelled out, pass or not.
        self::assertStringContainsString('redirect_uri', check_oauth_urls('https://example.test', 'https://example.test', true)['finding']);
    }

    public function testAuthorizationFixForUnknownServersCoversBoth(): void
    {
        $fix = authorization_fix('unknown');

        self::assertStringContainsString('SetEnvIf', $fix);
        self::assertStringContainsString('fastcgi_param', $fix);
    }

    public function testEchoEndpointAnswersOnlyAFreshProbeAndOnlyOnce(): void
    {
        $id = 'probe-id-123';
        set_transient('wppilot_doctor_' . hash('sha256', $id), 1, 120);
        $request = new WPPilot_Test_Rest_Request('/wppilot/v1/troubleshoot/doctor-echo', 'POST', [], ['X-WPPilot-Doctor' => $id]);

        self::assertTrue(echo_permission($request));
        self::assertFalse(echo_permission($request), 'A probe id is single-use.');
        self::assertFalse(echo_permission(new WPPilot_Test_Rest_Request('/wppilot/v1/troubleshoot/doctor-echo', 'POST', [], [])));
        self::assertFalse(echo_permission(new WPPilot_Test_Rest_Request('/wppilot/v1/troubleshoot/doctor-echo', 'POST', [], ['X-WPPilot-Doctor' => 'guess'])));
    }

    public function testRunReportsAStrippedHeaderBehindAHealthyEndpoint(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $sent = [];
        $fetch = static function (string $method, string $url, array $headers, string $body) use (&$sent): array {
            $sent[] = [$method, $url, $headers];
            if (str_contains($url, 'doctor-echo')) {
                return ['code' => 200, 'headers' => [], 'body' => '{"authorization":"missing","source":""}', 'error' => ''];
            }
            if (str_contains($url, 'api.wordpress.org')) {
                return ['code' => 200, 'headers' => ['date' => gmdate('D, d M Y H:i:s \G\M\T')], 'body' => '', 'error' => ''];
            }

            return ['code' => 401, 'headers' => ['server' => 'Apache'], 'body' => '{"code":"rest_forbidden","message":"No.","data":{"status":401}}', 'error' => ''];
        };

        $report = run($fetch);
        $by_id = array_column($report['checks'], null, 'id');

        self::assertSame('fail', $report['summary']['status']);
        self::assertSame('pass', $by_id['mcp_anonymous']['status']);
        self::assertSame('pass', $by_id['mcp_with_credentials']['status']);
        self::assertSame('fail', $by_id['authorization_header']['status']);
        self::assertStringContainsString('SetEnvIf', $by_id['authorization_header']['fix']);
        self::assertSame('pass', $by_id['clock_skew']['status']);
        self::assertSame('skip', $by_id['oauth_challenge']['status']);
        self::assertSame('server_stack', $report['checks'][0]['id']);

        // The credentialed probe really carried an Authorization header, and the echo probe carried
        // its one-time id in a header that survives even when Authorization does not.
        self::assertStringStartsWith('Bearer ', $sent[1][2]['Authorization']);
        self::assertSame(substr($sent[2][2]['Authorization'], strlen('WPPilot-Doctor ')), $sent[2][2]['X-WPPilot-Doctor']);
    }

    public function testSummaryIsTheWorstStatus(): void
    {
        $check = static fn(string $status): array => ['id' => $status, 'status' => $status, 'label' => '', 'finding' => '', 'evidence' => [], 'fix' => ''];

        self::assertSame('pass', summarize([$check('pass'), $check('info'), $check('skip')])['status']);
        self::assertSame('warn', summarize([$check('pass'), $check('warn')])['status']);
        self::assertSame('fail', summarize([$check('warn'), $check('fail')])['status']);
    }
}
