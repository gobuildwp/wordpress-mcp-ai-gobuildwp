<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Ability;
use WP_Error;
use WPPilot_Test_Halt;
use WPPilot_Test_State;

use function WPPilot\Mcp\client_supports_form_elicitation;
use function WPPilot\Mcp\confirmation_context;
use function WPPilot\Mcp\input_required_payload;
use function WPPilot\Mcp\input_required_response;

require_once dirname(__DIR__) . '/doubles/admin.php';
require_once dirname(__DIR__, 2) . '/includes/capabilities.php';
require_once dirname(__DIR__, 2) . '/includes/admin/confirm.php';

/**
 * Confirmation the model cannot forge: elicitation tokens, the wp-admin approval link, and the
 * default `argument` mode left exactly as it was.
 */
final class ConfirmationTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    /** @var array<string, mixed> */
    private array $savedFilters = [];

    /** @var list<string> */
    private array $savedCapabilities = [];

    private int $savedUser = 0;

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedFilters = $GLOBALS['wp_filter'] ?? [];
        $this->savedCapabilities = WPPilot_Test_State::$capabilities;
        $this->savedUser = WPPilot_Test_State::$current_user_id;
        unset($GLOBALS['wp_filter']['wppilot_pre_ability_execute']);
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'production';
        WPPilot_Test_State::$options[WPPILOT_CONFIRMATION_MODE_OPTION] = 'human';
        unset(
            WPPilot_Test_State::$options[WPPILOT_CONFIRMATION_REQUESTS_OPTION],
            WPPilot_Test_State::$options[WPPILOT_CONFIRMATION_NONCES_OPTION],
            WPPilot_Test_State::$options[WPPILOT_CHANGE_LOG_OPTION],
        );
        WPPilot_Test_State::$current_user_id = 7;
        wppilot_confirmation_note('wppilot/delete-post', clear: true);
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        $GLOBALS['wp_filter'] = $this->savedFilters;
        WPPilot_Test_State::$capabilities = $this->savedCapabilities;
        WPPilot_Test_State::$current_user_id = $this->savedUser;
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    // Mode off.

    public function testArgumentModeIsTheDefaultAndKeepsTheOldContract(): void
    {
        unset(WPPilot_Test_State::$options[WPPILOT_CONFIRMATION_MODE_OPTION]);

        self::assertSame('argument', wppilot_confirmation_mode());
        $refused = wppilot_gate_ability_call($this->destructive(), ['post_id' => 3], 'mcp', $this->elicitation());
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('wppilot_confirmation_required', $refused->get_error_code());
        self::assertSame(['post_id' => 3], wppilot_gate_ability_call($this->destructive(), ['post_id' => 3, 'confirm' => true], 'rest'));
        self::assertSame(['method' => 'argument'], wppilot_confirmation_note('wppilot/delete-post'));
    }

    public function testAnUnknownModeFallsBackToArgument(): void
    {
        WPPilot_Test_State::$options[WPPILOT_CONFIRMATION_MODE_OPTION] = 'off';

        self::assertSame('argument', wppilot_confirmation_mode());
    }

    // Token binding.

    public function testTokenVerifiesOnlyForTheCallItWasIssuedFor(): void
    {
        $hash = wppilot_confirmation_input_hash(['post_id' => 3]);
        $token = wppilot_confirmation_issue_token('wppilot/delete-post', $hash, 7);

        self::assertNotNull(wppilot_confirmation_verify_token($token, 'wppilot/delete-post', $hash, 7));
        self::assertNull(wppilot_confirmation_verify_token($token, 'wppilot/delete-post', wppilot_confirmation_input_hash(['post_id' => 4]), 7), 'different input');
        self::assertNull(wppilot_confirmation_verify_token($token, 'wppilot/delete-post', $hash, 8), 'different user');
        self::assertNull(wppilot_confirmation_verify_token($token, 'wppilot/delete-term', $hash, 7), 'different ability');
        self::assertNull(wppilot_confirmation_verify_token($token, 'wppilot/delete-post', $hash, 7, time() + WPPILOT_CONFIRMATION_TTL + 1), 'expired');
    }

    public function testTokenCannotBeForgedOrEdited(): void
    {
        $hash = wppilot_confirmation_input_hash(['post_id' => 3]);
        [$version, $payload, $mac] = explode('.', wppilot_confirmation_issue_token('wppilot/delete-post', $hash, 7));
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $claims['u'] = 8;
        $edited = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');

        self::assertNull(wppilot_confirmation_verify_token("$version.$edited.$mac", 'wppilot/delete-post', $hash, 8));
        self::assertNull(wppilot_confirmation_verify_token("$version.$payload.AAAA", 'wppilot/delete-post', $hash, 7));
        self::assertNull(wppilot_confirmation_verify_token('garbage', 'wppilot/delete-post', $hash, 7));
    }

    public function testTokenLifetimeIsCappedAtFiveMinutes(): void
    {
        $hash = wppilot_confirmation_input_hash([]);
        $issued = time() - 10;
        $token = wppilot_confirmation_issue_token('wppilot/delete-post', $hash, 7, $issued);
        $claims = wppilot_confirmation_verify_token($token, 'wppilot/delete-post', $hash, 7);

        self::assertNotNull($claims);
        self::assertSame($issued + 300, $claims['e']);
    }

    public function testNonceIsSingleUse(): void
    {
        self::assertTrue(wppilot_confirmation_claim_nonce('abc', time() + 60));
        self::assertFalse(wppilot_confirmation_claim_nonce('abc', time() + 60));
    }

    public function testInputHashIgnoresConfirmAndKeyOrderButNotListOrder(): void
    {
        self::assertSame(
            wppilot_confirmation_input_hash(['a' => 1, 'b' => ['y' => 2, 'x' => 1]]),
            wppilot_confirmation_input_hash(['confirm' => true, 'b' => ['x' => 1, 'y' => 2], 'a' => 1]),
        );
        self::assertNotSame(
            wppilot_confirmation_input_hash(['ids' => [1, 2]]),
            wppilot_confirmation_input_hash(['ids' => [2, 1]]),
        );
    }

    // Elicitation.

    public function testCapabilityDetection(): void
    {
        self::assertTrue(client_supports_form_elicitation(['elicitation' => []]));
        self::assertTrue(client_supports_form_elicitation(['elicitation' => ['form' => [], 'url' => []]]));
        self::assertFalse(client_supports_form_elicitation(['elicitation' => ['url' => []]]));
        self::assertFalse(client_supports_form_elicitation([]));
        self::assertFalse(client_supports_form_elicitation(['elicitation' => true]));
    }

    public function testContextReadsCapabilitiesStateAndResponse(): void
    {
        $context = confirmation_context([
            'name' => 'x',
            '_meta' => ['io.modelcontextprotocol/clientCapabilities' => ['elicitation' => []]],
            'requestState' => 'v1.a.b',
            'inputResponses' => ['wppilot_confirmation' => ['action' => 'accept']],
        ]);

        self::assertSame(
            ['elicitation' => ['supported' => true, 'state' => 'v1.a.b', 'response' => ['action' => 'accept']]],
            $context,
        );
        self::assertFalse(confirmation_context([])['elicitation']['supported']);
    }

    public function testElicitationCapableClientGetsAnInputRequiredResult(): void
    {
        $refused = wppilot_gate_ability_call($this->destructive(), ['post_id' => 3, 'confirm' => true], 'mcp', $this->elicitation());

        self::assertInstanceOf(WP_Error::class, $refused);
        $payload = input_required_payload($refused);
        self::assertNotNull($payload);

        $body = input_required_response($payload, 42)['body'];
        self::assertSame(42, $body['id']);
        self::assertSame('input_required', $body['result']['resultType']);
        self::assertIsString($body['result']['requestState']);
        $request = $body['result']['inputRequests']['wppilot_confirmation'];
        self::assertSame('elicitation/create', $request['method']);
        self::assertSame('form', $request['params']['mode']);
        self::assertSame('boolean', $request['params']['requestedSchema']['properties']['approve']['type']);
        self::assertSame(['approve'], $request['params']['requestedSchema']['required']);
        self::assertStringContainsString('wppilot/delete-post', $request['params']['message']);
        self::assertStringContainsString('"post_id": 3', $request['params']['message']);
        self::assertStringNotContainsString('confirm', $request['params']['message']);
    }

    public function testApprovedElicitationRunsOnceAndIsRecordedAsElicitation(): void
    {
        $state = $this->requestState(['post_id' => 3]);
        $answer = $this->elicitation($state, ['action' => 'accept', 'content' => ['approve' => true]]);

        self::assertSame(['post_id' => 3], wppilot_gate_ability_call($this->destructive(), ['post_id' => 3, 'confirm' => true], 'mcp', $answer));
        self::assertSame(['method' => 'elicitation'], wppilot_confirmation_note('wppilot/delete-post'));

        // The same answer replayed is asked again, not run again.
        $replayed = wppilot_gate_ability_call($this->destructive(), ['post_id' => 3], 'mcp', $answer);
        self::assertInstanceOf(WP_Error::class, $replayed);
        self::assertSame('wppilot_confirmation_input_required', $replayed->get_error_code());
    }

    public function testAnApprovalForOtherInputIsNotAccepted(): void
    {
        $state = $this->requestState(['post_id' => 3]);

        $result = wppilot_gate_ability_call(
            $this->destructive(),
            ['post_id' => 4],
            'mcp',
            $this->elicitation($state, ['action' => 'accept', 'content' => ['approve' => true]]),
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_confirmation_input_required', $result->get_error_code());
    }

    public function testAnApprovalFromAnotherUserIsNotAccepted(): void
    {
        $state = $this->requestState(['post_id' => 3]);
        WPPilot_Test_State::$current_user_id = 8;

        $result = wppilot_gate_ability_call(
            $this->destructive(),
            ['post_id' => 3],
            'mcp',
            $this->elicitation($state, ['action' => 'accept', 'content' => ['approve' => true]]),
        );

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_confirmation_input_required', $result->get_error_code());
    }

    public function testDeclinedOrUntickedElicitationIsRefused(): void
    {
        foreach ([['action' => 'decline'], ['action' => 'cancel'], ['action' => 'accept', 'content' => ['approve' => false]]] as $response) {
            $state = $this->requestState(['post_id' => 3]);
            $result = wppilot_gate_ability_call($this->destructive(), ['post_id' => 3], 'mcp', $this->elicitation($state, $response));

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('wppilot_confirmation_declined', $result->get_error_code());
        }
    }

    // Approval URL.

    public function testClientWithoutElicitationGetsOneApprovalLink(): void
    {
        $first = wppilot_gate_ability_call($this->destructive(), ['post_id' => 3, 'confirm' => true], 'rest');
        $again = wppilot_gate_ability_call($this->destructive(), ['post_id' => 3, 'confirm' => true], 'rest');

        self::assertInstanceOf(WP_Error::class, $first);
        self::assertSame('wppilot_human_confirmation_required', $first->get_error_code());
        $url = $first->get_error_data()['approval_url'];
        self::assertMatchesRegularExpression('#wp-admin/admin\.php\?page=wppilot-confirm&request=[a-f0-9]{32}$#', $url);
        self::assertStringContainsString($url, $first->get_error_message());
        self::assertSame($url, $again->get_error_data()['approval_url'], 'a retry before the person answers reuses the link');
    }

    public function testApprovedLinkLetsTheIdenticalRetryThroughOnce(): void
    {
        $id = $this->openRequest(['post_id' => 3]);
        self::assertTrue(wppilot_confirmation_decide($id, approve: true, decided_by: 1));

        $other = wppilot_gate_ability_call($this->destructive(), ['post_id' => 4, 'confirm' => true], 'rest');
        self::assertInstanceOf(WP_Error::class, $other, 'an approval binds the exact input');

        self::assertSame(['post_id' => 3], wppilot_gate_ability_call($this->destructive(), ['post_id' => 3, 'confirm' => true], 'rest'));
        self::assertSame(['method' => 'approval-url', 'approved_by' => 1], wppilot_confirmation_note('wppilot/delete-post'));

        $second = wppilot_gate_ability_call($this->destructive(), ['post_id' => 3, 'confirm' => true], 'rest');
        self::assertInstanceOf(WP_Error::class, $second);
        self::assertSame('wppilot_human_confirmation_required', $second->get_error_code(), 'single use');
    }

    public function testApprovalIsBoundToTheRequestingUser(): void
    {
        $id = $this->openRequest(['post_id' => 3]);
        wppilot_confirmation_decide($id, approve: true, decided_by: 1);
        WPPilot_Test_State::$current_user_id = 8;

        self::assertInstanceOf(WP_Error::class, wppilot_gate_ability_call($this->destructive(), ['post_id' => 3], 'rest'));
    }

    public function testDeniedLinkTellsTheAgentToStop(): void
    {
        $id = $this->openRequest(['post_id' => 3]);
        wppilot_confirmation_decide($id, approve: false, decided_by: 1);

        $result = wppilot_gate_ability_call($this->destructive(), ['post_id' => 3, 'confirm' => true], 'mcp');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_confirmation_denied', $result->get_error_code());
        self::assertFalse(wppilot_confirmation_decide($id, approve: true, decided_by: 1), 'a decision is final');
    }

    public function testExpiredApprovalNoLongerCounts(): void
    {
        $id = $this->openRequest(['post_id' => 3]);
        wppilot_confirmation_decide($id, approve: true, decided_by: 1);
        WPPilot_Test_State::$options[WPPILOT_CONFIRMATION_REQUESTS_OPTION][$id]['expires_at'] = time() - 1;

        self::assertInstanceOf(WP_Error::class, wppilot_gate_ability_call($this->destructive(), ['post_id' => 3], 'rest'));
    }

    public function testRequestRecordsWhatThePersonIsShown(): void
    {
        $id = $this->openRequest(['post_id' => 3, 'password' => 'hunter2']);
        $request = wppilot_confirmation_get_request($id);

        self::assertSame('wppilot/delete-post', $request['ability']);
        self::assertSame(7, $request['user_id']);
        self::assertSame(wppilot_confirmation_input_hash(['post_id' => 3, 'password' => 'hunter2']), $request['input_sha256']);
        self::assertStringContainsString('"post_id": 3', $request['input_json']);
        self::assertStringNotContainsString('hunter2', $request['input_json']);
        self::assertStringNotContainsString('hunter2', (string) json_encode(WPPilot_Test_State::$options[WPPILOT_CONFIRMATION_REQUESTS_OPTION]));
    }

    public function testAnonymousCallerCannotBeAskedForApproval(): void
    {
        WPPilot_Test_State::$current_user_id = 0;

        $result = wppilot_gate_ability_call($this->destructive(), ['post_id' => 3, 'confirm' => true], 'rest');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('wppilot_human_confirmation_unavailable', $result->get_error_code());
    }

    // Human-approved paths and non-destructive calls.

    public function testChatAndApprovalQueueAreUnaffectedByHumanMode(): void
    {
        self::assertSame(['post_id' => 3], wppilot_gate_ability_call($this->destructive(), ['post_id' => 3], 'chat', ['human_approved' => true]));
        self::assertSame(['method' => 'chat'], wppilot_confirmation_note('wppilot/delete-post'));

        self::assertSame(['post_id' => 3], wppilot_gate_ability_call($this->destructive(), ['post_id' => 3], 'approval', ['human_approved' => true]));
        self::assertSame(['method' => 'approval-queue'], wppilot_confirmation_note('wppilot/delete-post'));
    }

    public function testOrdinaryWritesNeedNoConfirmationInHumanMode(): void
    {
        self::assertSame(['post_id' => 3], wppilot_gate_ability_call(new WP_Ability('wppilot/update-post'), ['post_id' => 3], 'rest'));
        self::assertSame(['method' => 'not-required'], wppilot_confirmation_note('wppilot/update-post', clear: true));
    }

    // Ledger.

    public function testLedgerRowsRecordHowTheCallWasConfirmed(): void
    {
        wppilot_confirmation_note('wppilot/tests-confirmed', 'elicitation');
        wppilot_change_pending('wppilot/tests-confirmed', ['started_at' => microtime(true)]);
        wppilot_change_after('wppilot/tests-confirmed', [], ['ok' => true]);

        $log = wppilot_get_change_log();
        self::assertSame(['method' => 'elicitation'], $log[0]['confirmation']);
        self::assertSame('elicitation', wppilot_change_export_row($log[0])['confirmation']);
        self::assertNull(wppilot_confirmation_note('wppilot/tests-confirmed'), 'spent by the row that recorded it');
    }

    public function testBulkRowsCarryTheConfirmationToo(): void
    {
        wppilot_confirmation_note('wppilot/tests-confirmed', 'approval-url');
        wppilot_ledger_record_items('wppilot/tests-confirmed', [
            ['input' => ['a' => 1], 'before' => null, 'result' => null, 'irreversible_reason' => 'test'],
        ]);

        self::assertSame(['method' => 'approval-url'], wppilot_get_change_log()[0]['confirmation']);
    }

    // The wp-admin decision.

    public function testApproveNeedsTheNonce(): void
    {
        $id = $this->openRequest(['post_id' => 3]);
        WPPilot_Test_State::$capabilities = ['manage_options'];
        $this->post($id, 'approve', 'wrong');

        $halt = $this->runLoad();
        self::assertSame('die', $halt->kind);
        self::assertSame(403, $halt->status);
        self::assertSame('pending', wppilot_confirmation_get_request($id)['status']);
    }

    public function testApproveNeedsTheCapability(): void
    {
        $id = $this->openRequest(['post_id' => 3]);
        WPPilot_Test_State::$capabilities = [];
        $this->post($id, 'approve', 'nonce-wppilot_confirm_' . $id);

        $halt = $this->runLoad();
        self::assertSame('die', $halt->kind);
        self::assertSame(403, $halt->status);
        self::assertSame('pending', wppilot_confirmation_get_request($id)['status']);
    }

    public function testApproveWithNonceAndCapabilityRecordsTheApprover(): void
    {
        $id = $this->openRequest(['post_id' => 3]);
        WPPilot_Test_State::$capabilities = ['manage_options'];
        WPPilot_Test_State::$current_user_id = 1;
        $this->post($id, 'approve', 'nonce-wppilot_confirm_' . $id);

        $halt = $this->runLoad();
        self::assertSame('redirect', $halt->kind);
        self::assertStringContainsString('decided=approve', $halt->getMessage());
        $request = wppilot_confirmation_get_request($id);
        self::assertSame('approved', $request['status']);
        self::assertSame(1, $request['decided_by']);
    }

    public function testDenyIsRecorded(): void
    {
        $id = $this->openRequest(['post_id' => 3]);
        WPPilot_Test_State::$capabilities = ['manage_options'];
        $this->post($id, 'deny', 'nonce-wppilot_confirm_' . $id);

        $this->runLoad();
        self::assertSame('denied', wppilot_confirmation_get_request($id)['status']);
    }

    public function testMalformedRequestIdIsRejected(): void
    {
        WPPilot_Test_State::$capabilities = ['manage_options'];
        $this->post('../../etc', 'approve', 'nonce-wppilot_confirm_../../etc');

        $halt = $this->runLoad();
        self::assertSame(400, $halt->status);
    }

    public function testSettingSectionSavesOnlyKnownModes(): void
    {
        $sections = wppilot_confirm_register_setting([]);
        self::assertSame(WPPILOT_CONFIRMATION_MODE_OPTION, $sections[0]['fields'][0]['name']);

        wppilot_confirm_save_setting([WPPILOT_CONFIRMATION_MODE_OPTION => 'argument']);
        self::assertSame('argument', wppilot_confirmation_mode());
        wppilot_confirm_save_setting([WPPILOT_CONFIRMATION_MODE_OPTION => 'everything']);
        self::assertSame('argument', wppilot_confirmation_mode());
    }

    // Helpers.

    private function destructive(): WP_Ability
    {
        return new WP_Ability('wppilot/delete-post', [], [
            'type' => 'object',
            'properties' => ['post_id' => ['type' => 'integer']],
        ]);
    }

    /**
     * @return array{elicitation: array{supported: bool, state: string, response: mixed}}
     */
    private function elicitation(string $state = '', mixed $response = null): array
    {
        return ['elicitation' => ['supported' => true, 'state' => $state, 'response' => $response]];
    }

    /** @param array<string, mixed> $input */
    private function requestState(array $input): string
    {
        $refused = wppilot_gate_ability_call($this->destructive(), $input, 'mcp', $this->elicitation());
        self::assertInstanceOf(WP_Error::class, $refused);

        return $refused->get_error_data()['input_required']['requestState'];
    }

    /** @param array<string, mixed> $input */
    private function openRequest(array $input): string
    {
        $refused = wppilot_gate_ability_call($this->destructive(), $input, 'rest');
        self::assertInstanceOf(WP_Error::class, $refused);
        preg_match('/request=([a-f0-9]{32})/', $refused->get_error_data()['approval_url'], $match);

        return $match[1];
    }

    private function post(string $id, string $decision, string $nonce): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = ['page' => 'wppilot-confirm', 'request' => $id];
        $_POST = ['wppilot_confirm_decision' => $decision, '_wpnonce' => $nonce];
        $_REQUEST = $_GET + $_POST;
    }

    private function runLoad(): WPPilot_Test_Halt
    {
        try {
            wppilot_confirm_handle_load();
        } catch (WPPilot_Test_Halt $halt) {
            return $halt;
        }
        self::fail('The handler neither died nor redirected.');
    }
}
