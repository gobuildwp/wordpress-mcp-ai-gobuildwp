<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Ability;
use WP_Error;
use WPPilot_Test_State;

use function WPPilot\Mcp\confirmation_context;

require_once dirname(__DIR__) . '/doubles/admin.php';
require_once dirname(__DIR__) . '/doubles/mcp-surface.php';
require_once dirname(__DIR__) . '/doubles/sqlite-wpdb.php';
require_once dirname(__DIR__) . '/doubles/cloud.php';
require_once dirname(__DIR__) . '/doubles/signed-request.php';
require_once dirname(__DIR__, 2) . '/includes/capabilities.php';
require_once dirname(__DIR__, 2) . '/includes/clients.php';
require_once dirname(__DIR__, 2) . '/includes/rate-limit.php';
require_once dirname(__DIR__, 2) . '/includes/cloud/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/admin/confirm.php';
require_once dirname(__DIR__, 2) . '/includes/approvals/session.php';

/**
 * Cloud bulk jobs: a write that needs a person is queued on the site, approved in wp-admin, and
 * only then run, with the site (never Cloud) setting confirm.
 */
final class CloudApprovalTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    private int $savedUser = 0;

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedUser = WPPilot_Test_State::$current_user_id;
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'production';
        unset(
            WPPilot_Test_State::$options[WPPILOT_CONFIRMATION_MODE_OPTION],
            WPPilot_Test_State::$options[WPPILOT_CONFIRMATION_REQUESTS_OPTION],
            WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION],
        );
        WPPilot_Test_State::$current_user_id = 7;
        gobuildwp_cloud_request_set_verified(true);
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        WPPilot_Test_State::$current_user_id = $this->savedUser;
        gobuildwp_cloud_request_set_verified(false);
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    public function testTheJobIdIsBelievedOnlyFromAVerifiedSignedCall(): void
    {
        self::assertSame('job_1', wppilot_cloud_approval_job_from_meta([WPPILOT_CLOUD_APPROVAL_JOB_META => 'job_1']));
        self::assertSame('', wppilot_cloud_approval_job_from_meta([WPPILOT_CLOUD_APPROVAL_JOB_META => 'bad id!']));
        self::assertSame('', wppilot_cloud_approval_job_from_meta([]));
        self::assertSame(['cloud_job' => 'job_1'], array_intersect_key(
            confirmation_context(['_meta' => [WPPILOT_CLOUD_APPROVAL_JOB_META => 'job_1']]),
            ['cloud_job' => true],
        ));

        gobuildwp_cloud_request_set_verified(false);
        self::assertSame('', wppilot_cloud_approval_job_from_meta([WPPILOT_CLOUD_APPROVAL_JOB_META => 'job_1']));
        self::assertArrayNotHasKey('cloud_job', confirmation_context(['_meta' => [WPPILOT_CLOUD_APPROVAL_JOB_META => 'job_1']]));
    }

    public function testEnableIsQueuedWithARequestIdAndTheJobScreen(): void
    {
        $refused = $this->enable('job_1');

        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('wppilot_cloud_approval_required', $refused->get_error_code());
        $approval = $refused->get_error_data()['cloud_approval'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $approval['request_id']);
        self::assertSame('job_1', $approval['job']);
        self::assertStringEndsWith('admin.php?page=wppilot-confirm&job=job_1', $approval['approval_url']);
        self::assertSame($approval['request_id'], $this->enable('job_1')->get_error_data()['cloud_approval']['request_id'], 'a retry before the decision reuses the request');
    }

    public function testDisableStaysOneCallWithNoApproval(): void
    {
        self::assertSame(['enabled' => false], wppilot_gate_ability_call($this->maintenance(), ['enabled' => false], 'mcp', ['cloud_job' => 'job_1']));
    }

    public function testTheSameCallOutsideAJobKeepsTheAbilitysOwnRule(): void
    {
        // No job: the gate leaves confirm to the ability (which refuses enable without it).
        self::assertSame(['enabled' => true, 'minutes' => 30], wppilot_gate_ability_call($this->maintenance(), ['enabled' => true, 'minutes' => 30], 'mcp'));
        // A job id on another transport is ignored.
        self::assertSame(['enabled' => true, 'minutes' => 30], wppilot_gate_ability_call($this->maintenance(), ['enabled' => true, 'minutes' => 30], 'rest', ['cloud_job' => 'job_1']));
    }

    public function testCloudCannotSkipTheQueueBySendingConfirm(): void
    {
        $refused = wppilot_gate_ability_call($this->maintenance(), ['enabled' => true, 'minutes' => 30, 'confirm' => true], 'mcp', ['cloud_job' => 'job_1']);

        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('wppilot_cloud_approval_required', $refused->get_error_code());
    }

    public function testApprovedJobRunsTheIdenticalRetryOnceAndTheSiteSetsConfirm(): void
    {
        $id = $this->enable('job_1')->get_error_data()['cloud_approval']['request_id'];
        self::assertSame(1, wppilot_cloud_approval_decide_job('job_1', true, 1));

        $other = wppilot_gate_ability_call($this->maintenance(), ['enabled' => true, 'minutes' => 31], 'mcp', ['cloud_job' => 'job_1']);
        self::assertSame('wppilot_cloud_approval_required', $other->get_error_code(), 'the approval binds the exact input');

        self::assertSame(['enabled' => true, 'minutes' => 30, 'confirm' => true], $this->enable('job_1'));
        self::assertSame(['method' => 'cloud-approval', 'approved_by' => 1], wppilot_confirmation_note('wppilot/set-maintenance-mode'));
        self::assertSame('used', wppilot_confirmation_get_request($id)['status'], 'Cloud reads used as done');

        $again = $this->enable('job_1');
        self::assertInstanceOf(WP_Error::class, $again, 'single use');
    }

    public function testDeniedJobIsReportedAsDenied(): void
    {
        $this->enable('job_1');
        self::assertSame(1, wppilot_cloud_approval_decide_job('job_1', false, 1));
        self::assertSame(0, wppilot_cloud_approval_decide_job('job_1', true, 1), 'a decision is final');

        self::assertSame('wppilot_confirmation_denied', $this->enable('job_1')->get_error_code());
    }

    public function testStatusListsOnlyCloudRequestsWithoutTheirInput(): void
    {
        $this->enable('job_1');
        $this->enable('job_2', minutes: 45);
        WPPilot_Test_State::$options[WPPILOT_CONFIRMATION_MODE_OPTION] = 'human';
        wppilot_gate_ability_call(new WP_Ability('wppilot/delete-post', [], ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']]]), ['post_id' => 3], 'rest');

        $status = wppilot_cloud_approvals_status();
        self::assertSame(['job_1', 'job_2'], array_column($status, 'job'));
        self::assertSame(['pending', 'pending'], array_column($status, 'status'));
        self::assertSame(['id', 'job', 'ability', 'status', 'created_at', 'decided_at', 'expires_at'], array_keys($status[0]));
        self::assertGreaterThan(time() + 3600, $status[0]['expires_at'], 'a Cloud request waits a day for a person');
    }

    public function testDecidingOneJobLeavesTheOthersAlone(): void
    {
        $this->enable('job_1');
        $this->enable('job_2', minutes: 45);

        self::assertSame(1, wppilot_cloud_approval_decide_job('job_1', true, 1));
        self::assertSame(['approved', 'pending'], array_column(wppilot_cloud_approvals_status(), 'status'));
    }

    public function testACloudRequestCannotBeApprovedForTheSession(): void
    {
        $id = $this->enable('job_1')->get_error_data()['cloud_approval']['request_id'];

        self::assertFalse(wppilot_confirmation_session_allowed(wppilot_confirmation_get_request($id)));
    }

    public function testTheJobScreenApprovesEveryPendingRequestWithTheNonce(): void
    {
        $this->enable('job_1');
        $this->enable('job_1', minutes: 45);
        $saved = WPPilot_Test_State::$capabilities;
        WPPilot_Test_State::$capabilities = ['manage_options'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = ['page' => 'wppilot-confirm', 'job' => 'job_1'];
        $_POST = ['wppilot_confirm_job_decision' => 'approve', '_wpnonce' => 'nonce-wppilot_confirm_job_job_1'];
        $_REQUEST = $_GET + $_POST;

        try {
            wppilot_confirm_handle_load();
            self::fail('The handler neither died nor redirected.');
        } catch (\WPPilot_Test_Halt $halt) {
            self::assertSame('redirect', $halt->kind);
            self::assertStringContainsString('decided=approve', $halt->getMessage());
        } finally {
            WPPilot_Test_State::$capabilities = $saved;
        }
        self::assertSame(['approved', 'approved'], array_column(wppilot_cloud_approvals_status(), 'status'));
    }

    private function maintenance(): WP_Ability
    {
        return new WP_Ability('wppilot/set-maintenance-mode', [], [
            'type' => 'object',
            'properties' => [
                'enabled' => ['type' => 'boolean'],
                'minutes' => ['type' => 'integer'],
                'confirm' => ['type' => 'boolean'],
            ],
        ]);
    }

    private function enable(string $job, int $minutes = 30): array|WP_Error
    {
        return wppilot_gate_ability_call($this->maintenance(), ['enabled' => true, 'minutes' => $minutes], 'mcp', ['cloud_job' => $job]);
    }
}
