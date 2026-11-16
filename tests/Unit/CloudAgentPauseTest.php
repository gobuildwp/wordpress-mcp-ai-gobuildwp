<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Response;
use WPPilot_Test_Signed_Request;
use WPPilot_Test_State;

require_once dirname(__DIR__) . '/doubles/admin.php';
require_once dirname(__DIR__) . '/doubles/mcp-surface.php';
require_once dirname(__DIR__) . '/doubles/sqlite-wpdb.php';
require_once dirname(__DIR__) . '/doubles/cloud.php';
require_once dirname(__DIR__) . '/doubles/signed-request.php';
require_once dirname(__DIR__, 2) . '/includes/capabilities.php';
require_once dirname(__DIR__, 2) . '/includes/clients.php';
require_once dirname(__DIR__, 2) . '/includes/rate-limit.php';
require_once dirname(__DIR__, 2) . '/includes/cloud/bootstrap.php';

/**
 * The Cloud kill switch: signed, site-bound pause and resume, recorded in the ledger, and a resume
 * the site refuses while "only resume locally" is on.
 */
final class CloudAgentPauseTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        unset(
            WPPilot_Test_State::$options[WPPILOT_AGENTS_PAUSED_OPTION],
            WPPilot_Test_State::$options[WPPILOT_AGENTS_PAUSED_META_OPTION],
            WPPilot_Test_State::$options[WPPILOT_AGENTS_RESUME_LOCAL_ONLY_OPTION],
            WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION],
        );
        WPPilot_Test_State::$current_user_id = 3;
        gobuildwp_cloud_request_set_verified(false);
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        WPPilot_Test_State::$current_user_id = 0;
        gobuildwp_cloud_request_set_verified(false);
    }

    /** @param array<string, string> $headers */
    private static function request(string $command, array $headers = ['X-GoBuildWP-Site-Sig' => 'c2ln']): WPPilot_Test_Signed_Request
    {
        return new WPPilot_Test_Signed_Request(
            '/wppilot/v1/cloud/agents-' . $command,
            'POST',
            (string) wp_json_encode(['reason' => "Bad\nplugin update", 'actor' => 'owner@example.com']),
            $headers,
        );
    }

    public function testAnUnverifiedRequestCannotPause(): void
    {
        $result = wppilot_cloud_rest_agents_pause(self::request('pause'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_cloud_signature_required', $result->get_error_code());
        self::assertFalse(wppilot_agents_paused());
    }

    public function testASignatureThatNamesNoSiteCannotPause(): void
    {
        gobuildwp_cloud_request_set_verified(true);
        $result = wppilot_cloud_rest_agents_pause(self::request('pause', []));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertFalse(wppilot_agents_paused());
    }

    public function testASignedPauseIsRecordedWithItsReasonAndResumeLifts(): void
    {
        gobuildwp_cloud_request_set_verified(true);
        $result = wppilot_cloud_rest_agents_pause(self::request('pause'));

        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertTrue($result->data['paused']);
        self::assertTrue($result->data['changed']);
        self::assertNotSame('', $result->data['change_id']);
        $status = wppilot_agents_pause_status();
        self::assertSame('cloud', $status['source']);
        self::assertSame('Bad plugin update', $status['reason']);
        self::assertSame('owner@example.com', $status['actor']);
        self::assertSame(3, $status['by']);

        $rows = wppilot_query_change_log(['ability' => 'wppilot/cloud-agents-pause']);
        self::assertCount(1, $rows);

        // A second pause changes nothing and records nothing.
        $again = wppilot_cloud_rest_agents_pause(self::request('pause'));
        self::assertInstanceOf(WP_REST_Response::class, $again);
        self::assertFalse($again->data['changed']);
        self::assertCount(1, wppilot_query_change_log(['ability' => 'wppilot/cloud-agents-pause']));

        $resumed = wppilot_cloud_rest_agents_resume(self::request('resume'));
        self::assertInstanceOf(WP_REST_Response::class, $resumed);
        self::assertFalse($resumed->data['paused']);
        self::assertFalse(wppilot_agents_paused());
        self::assertCount(1, wppilot_query_change_log(['ability' => 'wppilot/cloud-agents-resume']));
    }

    public function testOnlyResumeLocallyRefusesARemoteResumeButNotAPause(): void
    {
        gobuildwp_cloud_request_set_verified(true);
        wppilot_set_agents_resume_local_only(true);

        self::assertInstanceOf(WP_REST_Response::class, wppilot_cloud_rest_agents_pause(self::request('pause')));
        self::assertTrue(wppilot_agents_pause_status()['resume_local_only']);

        $refused = wppilot_cloud_rest_agents_resume(self::request('resume'));
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('agents_resume_local_only', $refused->get_error_code());
        self::assertTrue(wppilot_agents_paused());

        // A person on the site still resumes.
        self::assertTrue(wppilot_set_agents_paused(false));
        self::assertFalse(wppilot_agents_paused());
    }

    public function testBothCommandsReachAPausedSite(): void
    {
        self::assertTrue(wppilot_agents_pause_route_is_exempt('/wppilot/v1/cloud/agents-pause'));
        self::assertTrue(wppilot_agents_pause_route_is_exempt('/wppilot/v1/cloud/agents-resume'));
        self::assertFalse(wppilot_agents_pause_route_is_exempt('/wppilot/v1/cloud/policy'));
    }

    public function testALocalPauseIsMarkedLocal(): void
    {
        self::assertTrue(wppilot_set_agents_paused(true));
        self::assertSame('local', wppilot_agents_pause_status()['source']);
        self::assertFalse(wppilot_agents_pause_status()['resume_local_only']);
    }
}
