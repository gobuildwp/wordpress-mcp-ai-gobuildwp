<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit\Kits\Pagespeed;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPPilot\Kits\Pagespeed as P;
use WPPilot\Kits\Runtime;

/**
 * The pagespeed kit: both Lighthouse generations reduced to one shape, the Cloud contract held to
 * it, the source order (Site Kit, Cloud, saved key, keyless) and when the chain stops, the exact
 * request the Cloud proxy receives, the reuse window, and that a saved key never leaves the site.
 */
final class PagespeedTest extends TestCase
{
    private static SpeedHost $host;

    /** @var list<array{route: string, params: array<string, mixed>}> */
    private static array $siteKitCalls = [];

    /** @var array<string, mixed> */
    private static array $siteKit = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
        require_once __DIR__ . '/doubles.php';
        self::$host = new SpeedHost();
        Runtime\host(self::$host);
        require dirname(__DIR__, 4) . '/includes/kits/pagespeed/bootstrap.php';
        if (!defined('GOOGLESITEKIT_VERSION')) {
            define('GOOGLESITEKIT_VERSION', '1.189.0');
        }
    }

    protected function setUp(): void
    {
        Runtime\host(self::$host);
        self::$host->cloudUrl = 'https://cloud.example';
        self::$host->signer = null;
        Net::reset();
        self::$siteKitCalls = [];
        self::$siteKit = ['connected' => false, 'authenticated' => false, 'pagespeed' => null];
        remove_all_filters('wppilot_kit_pagespeed_pre_site_kit_request');
        remove_all_filters('wppilot_kit_pagespeed_api_key');
        remove_all_filters('wppilot_kit_pagespeed_proxy_url');
        add_filter('wppilot_kit_pagespeed_pre_site_kit_request', [self::class, 'siteKit'], 10, 3);
    }

    /**
     * Site Kit's three routes as this test configured them.
     *
     * @param array<string, mixed> $params
     */
    public static function siteKit(mixed $answer, string $route, array $params): mixed
    {
        self::$siteKitCalls[] = ['route' => $route, 'params' => $params];
        if ($route === 'core/modules/data/list') {
            return [['slug' => 'pagespeed-insights', 'active' => self::$siteKit['connected'], 'connected' => self::$siteKit['connected'], 'owner' => self::$siteKit['owner'] ?? null]];
        }
        if ($route === 'core/site/data/connection') {
            return ['connected' => self::$siteKit['setup'] ?? true, 'setupCompleted' => self::$siteKit['setup'] ?? true];
        }
        if ($route === 'core/user/data/authentication') {
            return ['authenticated' => self::$siteKit['authenticated']];
        }
        return self::$siteKit['pagespeed'];
    }

    // Normalisation.

    public function testLighthouseTwelveOpportunitiesAndMetricsAreRead(): void
    {
        $result = P\from_psi(self::legacyResponse(), 'https://example.test/', 'mobile');

        self::assertIsArray($result);
        self::assertSame(['performance' => 42, 'seo' => 91, 'accessibility' => 88, 'best_practices' => 100], $result['scores']);
        self::assertSame(['fcp_ms' => 2100, 'lcp_ms' => 5400, 'tbt_ms' => 610, 'cls' => 0.215, 'si_ms' => 4300, 'ttfb_ms' => 820], $result['metrics']);
        self::assertSame(['render-blocking-resources', 'unused-css-rules', 'offscreen-images'], array_column($result['opportunities'], 'id'));
        self::assertSame(1500, $result['opportunities'][0]['savings_ms']);
        self::assertSame(2, $result['opportunities'][0]['items_count']);
        // Passing audits and the SEO category's audits are not opportunities or diagnostics.
        self::assertNotContains('uses-text-compression', array_column($result['opportunities'], 'id'));
        self::assertNotContains('image-alt', array_column($result['diagnostics'], 'id'));
        self::assertSame(['dom-size'], array_column($result['diagnostics'], 'id'));
        self::assertSame('Avoid an excessive DOM size', $result['diagnostics'][0]['title']);
        self::assertSame('12.2.1', $result['lighthouse_version']);
        self::assertSame('2026-10-09T10:00:00.000Z', $result['fetched_at']);
        self::assertSame(
            ['scope' => 'url', 'overall' => 'AVERAGE', 'lcp_ms' => 3100, 'inp_ms' => null, 'cls' => 0.12, 'fcp_ms' => null, 'ttfb_ms' => null],
            $result['field_data'],
            'the Cloud proxy field_data shape',
        );
    }

    public function testLighthouseThirteenInsightsCarryTheirSavingsInMetricSavings(): void
    {
        $result = P\from_psi(self::insightsResponse(), 'https://example.test/', 'desktop');

        self::assertIsArray($result);
        self::assertSame(['render-blocking-insight', 'image-delivery-insight'], array_column($result['opportunities'], 'id'));
        self::assertSame(900, $result['opportunities'][0]['savings_ms']);
        self::assertSame(120000, $result['opportunities'][1]['savings_bytes']);
        self::assertSame(300, $result['metrics']['ttfb_ms'], 'Lighthouse 13 keeps TTFB only in the metrics summary');
        self::assertNull($result['field_data']);
        self::assertNull($result['scores']['seo'], 'a category that was not run is null, not zero');
    }

    public function testARuntimeErrorIsThePagesFaultAndSaysSo(): void
    {
        $raw = ['lighthouseResult' => ['runtimeError' => ['code' => 'NO_FCP', 'message' => 'The page did not paint any content.']]];
        $error = P\from_psi($raw, 'https://example.test/', 'mobile');

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('kit_pagespeed_page_too_slow', $error->get_error_code());
        self::assertStringStartsWith('Google could not load the page in time; the uncached page may be too slow.', $error->get_error_message());
        self::assertTrue($error->get_error_data()['page']);
    }

    public function testTheCloudAnswerIsHeldToTheContract(): void
    {
        $result = P\from_cloud(self::cloudBody() + ['secret' => 'x'], 'https://example.test/', 'mobile');

        self::assertIsArray($result);
        self::assertArrayNotHasKey('secret', $result);
        self::assertSame(['performance' => 71, 'seo' => 100, 'accessibility' => null, 'best_practices' => 92], $result['scores']);
        self::assertTrue($result['cached']);
        self::assertSame('unused-javascript', $result['opportunities'][0]['id'], 'reordered by savings');
        self::assertSame('Reduce unused JavaScript', $result['opportunities'][0]['title'], 'markup stripped');
        self::assertInstanceOf(WP_Error::class, P\from_cloud(['scores' => 'x'], 'https://example.test/', 'mobile'));
    }

    // Source order.

    public function testSiteKitAnswersFirstWhenTheModuleIsConnectedAndReadable(): void
    {
        self::$siteKit = ['connected' => true, 'authenticated' => true, 'pagespeed' => self::legacyResponse()];
        Net::$caps[] = 'googlesitekit_setup';

        $result = P\check(['url' => '/about/']);

        self::assertSame('site-kit', $result['source']);
        self::assertSame([], Net::$requests, 'no HTTP request when Site Kit answered');
        self::assertSame(['url' => 'https://example.test/about/', 'strategy' => 'mobile'], end(self::$siteKitCalls)['params']);
        self::assertStringContainsString('performance category only', $result['note']);
    }

    public function testTheCloudProxyGetsTheContractRequestWhenSiteKitCannotAnswer(): void
    {
        Net::$caps[] = 'googlesitekit_setup';
        Net::$answers[] = Net::json(200, self::cloudBody());

        $result = P\check(['strategy' => 'desktop']);

        self::assertSame('cloud', $result['source']);
        self::assertSame([['source' => 'site-kit', 'outcome' => 'skipped', 'reason' => 'site_kit_module_not_connected'], ['source' => 'cloud', 'outcome' => 'answered']], $result['attempts']);
        $request = Net::$requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://cloud.example/api/pagespeed/v1/run', $request['url']);
        self::assertSame(['url' => 'https://example.test/', 'strategy' => 'desktop', 'site_url' => 'https://example.test'], json_decode($request['args']['body'], true));
        self::assertArrayNotHasKey('Authorization', $request['args']['headers'], 'the site holds no bearer credential for the Cloud');
        self::assertArrayNotHasKey('X-Site-Signature', $request['args']['headers'], 'an unpaired site sends the plain body');
        self::assertSame(0, $request['args']['redirection']);
        self::assertGreaterThanOrEqual(100, $request['args']['timeout'], 'the Cloud gives Google 90 s');
    }

    public function testAPairedSiteSignsTheCallAndFallsBackUnsignedWhenTheCloudForgotIt(): void
    {
        self::$host->signer = static fn(array $payload): array => [
            'base' => 'https://paired.example',
            'body' => (string) json_encode(array_merge(['site_id' => 's1', 'ts' => 1, 'nonce' => 'n'], $payload)),
            'headers' => ['X-Site-Signature' => 'c2ln', 'Authorization' => 'Bearer never'],
        ];
        Net::$answers[] = Net::json(200, self::cloudBody());

        $result = P\check([]);

        self::assertSame('cloud', $result['source']);
        $signed = Net::$requests[0];
        self::assertSame('https://paired.example/api/pagespeed/v1/run', $signed['url']);
        self::assertSame('c2ln', $signed['args']['headers']['X-Site-Signature']);
        self::assertArrayNotHasKey('Authorization', $signed['args']['headers'], 'the Cloud answers 401 unsupported_auth to any bearer');
        self::assertSame(['site_id' => 's1', 'ts' => 1, 'nonce' => 'n', 'url' => 'https://example.test/', 'strategy' => 'mobile', 'site_url' => 'https://example.test'], json_decode($signed['args']['body'], true));

        Net::$answers[] = Net::json(404, ['error' => ['code' => 'unknown_site', 'message' => 'This site is not connected.']]);
        Net::$answers[] = Net::json(200, self::cloudBody());
        $again = P\check(['refresh' => true]);

        self::assertSame('cloud', $again['source']);
        self::assertTrue(json_decode(Net::$requests[1]['args']['body'], true)['refresh'], 'refresh asks the Cloud to skip its hour-long cache, on the signed call');
        self::assertSame('https://cloud.example/api/pagespeed/v1/run', Net::$requests[2]['url']);
        self::assertArrayNotHasKey('X-Site-Signature', Net::$requests[2]['args']['headers']);
        $unsigned = json_decode(Net::$requests[2]['args']['body'], true);
        self::assertArrayNotHasKey('site_id', $unsigned);
        self::assertArrayNotHasKey('refresh', $unsigned, 'the Cloud honours refresh only when signed, so the plain body never carries it');
    }

    public function testTheCloudsOwnMessageReachesTheAgent(): void
    {
        Net::$answers[] = Net::json(502, ['error' => ['code' => 'page_timeout', 'message' => 'Google could not load the page in time; the uncached page may be too slow.']]);
        $slow = P\check([]);
        self::assertSame('kit_pagespeed_page_too_slow', $slow->get_error_code());
        self::assertCount(1, Net::$requests, 'a page that is too slow is not tried again elsewhere');

        Net::reset();
        Net::$answers[] = Net::json(429, ['error' => ['code' => 'host_limit', 'message' => 'This site has used its 10 free checks today.', 'retry_after' => 3600]], ['retry-after' => '3600']);
        Net::$answers[] = Net::json(429, ['error' => ['code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']]);
        $limited = P\check(['refresh' => true]);
        self::assertSame('kit_pagespeed_quota', $limited->get_error_code());
        self::assertStringContainsString('Do not retry in a loop', $limited->get_error_message());
        self::assertStringContainsString('This site has used its 10 free checks today.', $limited->get_error_data()['attempts'][1]['message'], 'the Cloud message reaches the agent');
    }

    public function testSharingIsNamedWhenItIsWhatKeptSiteKitFromAnswering(): void
    {
        self::$siteKit = ['connected' => true, 'authenticated' => false, 'pagespeed' => null];
        Net::$caps[] = 'googlesitekit_setup';
        Net::$abilities[] = 'wppilot/site-kit-enable-sharing';
        Net::$answers[] = Net::json(200, self::cloudBody());

        $result = P\check([]);

        self::assertSame('site_kit_not_shared_with_user', $result['attempts'][0]['reason']);
        self::assertSame('wppilot/site-kit-enable-sharing', $result['fix']['ability']);

        // Shared with the user's role: Site Kit answers with the owner's account.
        Net::$caps[] = 'googlesitekit_read_shared_module_data:pagespeed-insights';
        self::$siteKit['pagespeed'] = self::legacyResponse();
        $shared = P\check(['refresh' => true]);
        self::assertSame('site-kit', $shared['source']);
        self::assertArrayNotHasKey('fix', $shared);
    }

    public function testASignedOutOwnerIsToldToSignInRatherThanOfferedSharing(): void
    {
        // Seen live on biasmd.com: the module's owner had lost their Google sign-in. Sharing serves
        // reads from that same token and Site Kit accepts it only from a signed-in owner.
        self::$siteKit = ['connected' => true, 'authenticated' => false, 'pagespeed' => null, 'owner' => ['id' => 1, 'login' => 'admin']];
        Net::$caps[] = 'googlesitekit_setup';
        Net::$abilities[] = 'wppilot/site-kit-enable-sharing';
        Net::$answers[] = Net::json(200, self::cloudBody());

        $result = P\check([]);

        self::assertSame(['source' => 'site-kit', 'outcome' => 'skipped', 'reason' => 'site_kit_owner_signed_out'], $result['attempts'][0]);
        self::assertSame('cloud', $result['source']);
        self::assertSame('site_kit_sign_in', $result['fix']['action']);
        self::assertArrayNotHasKey('ability', $result['fix'], 'sharing is not the fix');
        self::assertSame('https://example.test/wp-admin/admin.php?page=googlesitekit-dashboard', $result['fix']['url']);
        self::assertSame(
            'This user owns Site Kit\'s PageSpeed Insights module but is no longer signed in to Site Kit with Google, so another source answered. Sign in once at Site Kit > Dashboard as this user; then it can be shared read-only with Administrators (wppilot/site-kit-enable-sharing).',
            $result['fix']['message'],
        );

        // Without free's sharing ability the follow-up is not promised.
        Net::$abilities = [];
        Net::$answers[] = Net::json(200, self::cloudBody());
        $bare = P\check(['refresh' => true]);
        self::assertStringEndsWith('Sign in once at Site Kit > Dashboard as this user.', $bare['fix']['message']);
    }

    public function testASignedOutAdminWhoIsNotTheOwnerIsStillOfferedSharing(): void
    {
        self::$siteKit = ['connected' => true, 'authenticated' => false, 'pagespeed' => null, 'owner' => ['id' => 2, 'login' => 'editor-in-chief']];
        Net::$caps[] = 'googlesitekit_setup';
        Net::$abilities[] = 'wppilot/site-kit-enable-sharing';
        Net::$answers[] = Net::json(200, self::cloudBody());

        $result = P\check([]);

        self::assertSame('site_kit_not_shared_with_user', $result['attempts'][0]['reason']);
        self::assertSame('wppilot/site-kit-enable-sharing', $result['fix']['ability']);
    }

    public function testTheOwnerSignedInIsAnsweredBySiteKit(): void
    {
        self::$siteKit = ['connected' => true, 'authenticated' => true, 'pagespeed' => self::legacyResponse(), 'owner' => ['id' => 1, 'login' => 'admin']];
        Net::$caps[] = 'googlesitekit_setup';

        $result = P\check([]);

        self::assertSame('site-kit', $result['source']);
        self::assertArrayNotHasKey('fix', $result);
    }

    public function testSiteKitNotSetUpIsSkippedWithoutOfferingSharing(): void
    {
        // Seen live on Site Kit 1.189.0: the PageSpeed module reads active and connected before setup.
        self::$siteKit = ['connected' => true, 'authenticated' => false, 'pagespeed' => null, 'setup' => false];
        Net::$caps[] = 'googlesitekit_setup';
        Net::$abilities[] = 'wppilot/site-kit-enable-sharing';
        Net::$answers[] = Net::json(200, self::cloudBody());

        $result = P\check([]);

        self::assertSame(['source' => 'site-kit', 'outcome' => 'skipped', 'reason' => 'site_kit_not_set_up'], $result['attempts'][0]);
        self::assertSame('cloud', $result['source']);
        self::assertArrayNotHasKey('fix', $result, 'sharing cannot help until Site Kit setup is finished');
    }

    public function testAQuotaRefusalFallsThroughToTheSavedKeyWhichNeverLeavesTheSite(): void
    {
        Net::$options[P\API_KEY_OPTION] = 'AIzaSySecretKeyValue1234567890';
        Net::$answers[] = Net::json(429, ['error' => ['code' => 'rate_limited', 'message' => 'Slow down', 'retry_after' => 30]]);
        Net::$answers[] = Net::json(200, self::legacyResponse());

        $result = P\check([]);

        self::assertSame('google-api-key', $result['source']);
        self::assertSame(['failed', 'answered'], array_column(array_slice($result['attempts'], 1), 'outcome'));
        $google = Net::$requests[1]['url'];
        self::assertStringStartsWith('https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=https%3A%2F%2Fexample.test%2F&strategy=mobile', $google);
        self::assertStringContainsString('&category=performance&category=seo&category=accessibility&category=best-practices', $google);
        self::assertStringContainsString('&key=AIzaSySecretKeyValue1234567890', $google);
        self::assertStringNotContainsString('AIzaSySecretKeyValue', (string) json_encode($result));
    }

    public function testAKeyGoogleRejectsFallsThroughToKeylessAndTheErrorIsScrubbed(): void
    {
        Net::$options[P\API_KEY_OPTION] = 'AIzaSyBadKeyValue12345678901234';
        Net::$answers[] = Net::json(503, ['error' => ['code' => 'upstream_unavailable', 'message' => 'Try later']]);
        Net::$answers[] = Net::json(400, ['error' => ['code' => 400, 'message' => 'API key not valid. key=AIzaSyBadKeyValue12345678901234', 'status' => 'INVALID_ARGUMENT', 'details' => [['reason' => 'API_KEY_INVALID']]]]);
        Net::$answers[] = Net::json(429, ['error' => ['code' => 429, 'message' => 'Quota exceeded for quota metric', 'status' => 'RESOURCE_EXHAUSTED']]);

        $error = P\check([]);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('kit_pagespeed_quota', $error->get_error_code());
        self::assertSame(['site-kit', 'cloud', 'google-api-key', 'google-keyless'], array_column($error->get_error_data()['attempts'], 'source'));
        self::assertSame('kit_pagespeed_bad_key', $error->get_error_data()['attempts'][2]['code']);
        self::assertStringNotContainsString('key=', Net::$requests[2]['url']);
        self::assertStringNotContainsString('AIzaSyBadKey', (string) json_encode($error->get_error_data()));
    }

    public function testAPageTooSlowForTheCloudIsNotRetriedAtGoogle(): void
    {
        Net::$answers[] = Net::json(502, ['error' => ['code' => 'NO_FCP', 'message' => 'Lighthouse returned error: NO_FCP']]);

        $error = P\check([]);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('kit_pagespeed_page_too_slow', $error->get_error_code());
        self::assertCount(1, Net::$requests, 'the chain stops on a failure that belongs to the page');
    }

    public function testAnUnusableCloudUrlSkipsTheProxy(): void
    {
        self::$host->cloudUrl = '';
        Net::$answers[] = Net::json(200, self::legacyResponse());

        $result = P\check([]);

        self::assertSame('google-keyless', $result['source']);
        self::assertSame('cloud_url_not_usable', $result['attempts'][1]['reason']);
        self::assertSame('no_api_key_saved', $result['attempts'][2]['reason']);
    }

    public function testResultsAreReusedUntilRefreshIsAsked(): void
    {
        Net::$answers[] = Net::json(200, array_merge(self::cloudBody(), ['cached' => false]));
        $first = P\check([]);
        $again = P\check([]);

        self::assertFalse($first['cached']);
        self::assertTrue($again['cached']);
        self::assertCount(1, Net::$requests);

        Net::$answers[] = Net::json(200, self::cloudBody());
        P\check(['refresh' => true]);
        self::assertCount(2, Net::$requests);
    }

    public function testBothStrategiesRunAndOnlyThisSitesPagesAreTested(): void
    {
        Net::$answers[] = Net::json(200, self::cloudBody());
        Net::$answers[] = Net::json(429, ['error' => ['code' => 'rate_limited', 'message' => 'no']]);
        Net::$answers[] = Net::json(429, ['error' => ['code' => 429, 'message' => 'Quota exceeded']]);

        $both = P\check(['strategy' => 'both']);
        self::assertSame('both', $both['strategy']);
        self::assertSame('cloud', $both['results']['mobile']['source']);
        self::assertSame('kit_pagespeed_quota', $both['results']['desktop']['error']['code']);

        foreach (['https://other.example/', 'ftp://example.test/x', '//other.example/x'] as $url) {
            $refused = P\check(['url' => $url]);
            self::assertInstanceOf(WP_Error::class, $refused, $url);
            self::assertSame('kit_pagespeed_invalid_url', $refused->get_error_code());
        }
        self::assertSame('https://www.example.test/x', P\resolve_url('https://www.example.test/x'));
    }

    public function testTheAbilityRegistersReadOnlyUnderPerformance(): void
    {
        require dirname(__DIR__, 4) . '/includes/kits/pagespeed/src/abilities/pagespeed-check.php';
        $args = Registrations::$args['wppilot/pagespeed-check'];

        self::assertSame('performance', $args['category']);
        self::assertTrue($args['meta']['annotations']['readonly']);
        self::assertFalse($args['meta']['annotations']['destructive']);
        self::assertTrue(($args['permission_callback'])());
    }

    // Fixtures.

    /** @return array<string, mixed> */
    private static function cloudBody(): array
    {
        return [
            'url' => 'https://example.test/',
            'strategy' => 'mobile',
            'fetched_at' => '2026-10-09T10:00:00Z',
            'cached' => true,
            'scores' => ['performance' => 71, 'seo' => 100, 'accessibility' => 'n/a', 'best_practices' => 92],
            'metrics' => ['fcp_ms' => 1800, 'lcp_ms' => 3200, 'tbt_ms' => 150, 'cls' => 0.05, 'si_ms' => 2900, 'ttfb_ms' => 400],
            'field_data' => null,
            'opportunities' => [
                ['id' => 'offscreen-images', 'title' => 'Defer offscreen images', 'savings_ms' => 300, 'savings_bytes' => 50000, 'items_count' => 4],
                ['id' => 'unused-javascript', 'title' => '<b>Reduce</b> unused JavaScript', 'savings_ms' => 1200, 'savings_bytes' => 210000, 'items_count' => 3],
            ],
            'diagnostics' => [['id' => 'dom-size', 'title' => 'Avoid an excessive DOM size', 'display' => '1,900 elements']],
            'lighthouse_version' => '13.0.1',
        ];
    }

    /** @return array<string, mixed> */
    private static function legacyResponse(): array
    {
        return [
            'loadingExperience' => [
                'id' => 'https://example.test/',
                'overall_category' => 'AVERAGE',
                'metrics' => [
                    'LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 3100, 'category' => 'AVERAGE'],
                    'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 12, 'category' => 'AVERAGE'],
                    'UNKNOWN_METRIC' => ['percentile' => 1],
                ],
            ],
            'lighthouseResult' => [
                'lighthouseVersion' => '12.2.1',
                'fetchTime' => '2026-10-09T10:00:00.000Z',
                'categories' => [
                    'performance' => ['score' => 0.42, 'auditRefs' => [
                        ['id' => 'first-contentful-paint', 'group' => 'metrics'],
                        ['id' => 'largest-contentful-paint', 'group' => 'metrics'],
                        ['id' => 'render-blocking-resources'],
                        ['id' => 'unused-css-rules'],
                        ['id' => 'offscreen-images'],
                        ['id' => 'uses-text-compression'],
                        ['id' => 'dom-size', 'group' => 'diagnostics'],
                        ['id' => 'server-response-time'],
                    ]],
                    'seo' => ['score' => 0.91, 'auditRefs' => [['id' => 'image-alt']]],
                    'accessibility' => ['score' => 0.88],
                    'best-practices' => ['score' => 1],
                ],
                'audits' => [
                    'first-contentful-paint' => ['numericValue' => 2100.4, 'score' => 0.5],
                    'largest-contentful-paint' => ['numericValue' => 5399.6, 'score' => 0.1],
                    'total-blocking-time' => ['numericValue' => 610],
                    'cumulative-layout-shift' => ['numericValue' => 0.21512],
                    'speed-index' => ['numericValue' => 4300],
                    'server-response-time' => ['numericValue' => 820, 'score' => 0.95],
                    'render-blocking-resources' => ['title' => 'Eliminate render-blocking resources', 'score' => 0.2, 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 1500, 'items' => [[], []]]],
                    'unused-css-rules' => ['title' => 'Reduce unused CSS', 'score' => 0.5, 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 450, 'overallSavingsBytes' => 80000, 'items' => [[]]]],
                    'offscreen-images' => ['title' => 'Defer offscreen images', 'score' => 0.5, 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 450, 'overallSavingsBytes' => 30000, 'items' => [[]]]],
                    'uses-text-compression' => ['title' => 'Enable text compression', 'score' => 1, 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 0, 'items' => []]],
                    'dom-size' => ['title' => 'Avoid an excessive DOM size', 'score' => 0.3, 'displayValue' => '1,900 elements', 'scoreDisplayMode' => 'numeric'],
                    'image-alt' => ['title' => 'Images have alt', 'score' => 0],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function insightsResponse(): array
    {
        return [
            'lighthouseResult' => [
                'lighthouseVersion' => '13.0.1',
                'fetchTime' => '2026-10-09T10:00:00.000Z',
                'categories' => ['performance' => ['score' => 0.77]],
                'audits' => [
                    'metrics' => ['details' => ['items' => [['timeToFirstByte' => 300]]]],
                    'render-blocking-insight' => ['title' => 'Render blocking requests', 'score' => 0, 'metricSavings' => ['FCP' => 900, 'LCP' => 850, 'CLS' => 0.4], 'details' => ['type' => 'table', 'items' => [[]]]],
                    'image-delivery-insight' => ['title' => 'Improve image delivery', 'score' => 0.5, 'metricSavings' => ['LCP' => 0], 'details' => ['type' => 'table', 'overallSavingsBytes' => 120000, 'items' => [[], []]]],
                    'font-display-insight' => ['title' => 'Font display', 'score' => 1, 'metricSavings' => ['FCP' => 0]],
                ],
            ],
        ];
    }
}
