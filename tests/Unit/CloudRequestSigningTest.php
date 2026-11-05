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
use WP_REST_Response;
use WPPilot_Test_Signed_Request;
use WPPilot_Test_State;

use function WPPilot\OAuth\Middleware\record_oauth_identity;
use function WPPilot\OAuth\Middleware\reset_request_context;

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
 * Signed Cloud-to-site requests, against SECURITY-GUARDIAN-R3-CONTRACT.md §1.
 *
 * The Cloud is played by a fixed Ed25519 seed; the link option is written
 * directly, and the Cloud token is the one the link names.
 */
final class CloudRequestSigningTest extends TestCase
{
    /** Contract test vector (plugin): seed bytes 0x01..0x20. */
    private const SEED_HEX = '0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f20';
    private const VECTOR_PUBLIC = 'ebVWLo/mVPlAeLES6KmLp5AfhTrmlb7X4OORC60ElmQ=';
    private const VECTOR_KID = '65b60673d6ed884b';
    private const VECTOR_BODY = '{"ceiling":"production"}';
    private const VECTOR_TS = 1767225600;
    private const VECTOR_NONCE = 'AAECAwQFBgcICQoLDA0ODxAR';
    private const VECTOR_REQUEST_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const VECTOR_SIG = '/GyQWbgKVu7jd88mB7xmMJ5nQu5Jy9lX2712hCp7h7kcxoqUb/STy+3mO7LM8aXhOVvRNP75XrUaYiXA6umxBA==';

    private const CLOUD_TOKEN = 7;
    private const POLICY = '/wppilot/v1/cloud/policy';
    private const MCP = '/mcp/wppilot';

    /** @var array<string, mixed> */
    private array $savedOptions = [];

    private int $nonceCounter = 0;

    private mixed $savedWpdb = null;

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        // Nonces are claimed in the options table.
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
        $wpdb = new \WPPilot_Test_Sqlite_Wpdb();
        $wpdb->query('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT NOT NULL UNIQUE, option_value TEXT NOT NULL, autoload TEXT NOT NULL)');
        $GLOBALS['wpdb'] = $wpdb;
        unset(WPPilot_Test_State::$options[WPPILOT_CLOUD_LINK_OPTION]);
        $GLOBALS['wppilot_test_transients'] = [];
        remove_all_filters('gobuildwp_cloud_signing_supported');
        WPPilot_Test_State::$options[WPPILOT_CLOUD_LINK_OPTION] = [
            'site_id' => 'site-1',
            'token_id' => self::CLOUD_TOKEN,
            'account_hint' => 'a***@example.com',
            'cloud_url' => 'https://app.gobuildwp.com',
            'paired_at' => '2026-10-01T00:00:00Z',
        ];
        reset_request_context();
        record_oauth_identity(1, ['mcp'], 'token-' . self::CLOUD_TOKEN, via: 'token');
        gobuildwp_cloud_request_set_verified(false);
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        $GLOBALS['wpdb'] = $this->savedWpdb;
        $GLOBALS['wppilot_test_transients'] = [];
        remove_all_filters('gobuildwp_cloud_signing_supported');
        reset_request_context();
        gobuildwp_cloud_request_set_verified(false);
        parent::tearDown();
    }

    private static function keypair(string $seed_hex = self::SEED_HEX): string
    {
        return sodium_crypto_sign_seed_keypair((string) hex2bin($seed_hex));
    }

    /** @return array{kid: string, public: string} */
    private static function offer(string $seed_hex = self::SEED_HEX, ?string $kid = null): array
    {
        $public = sodium_crypto_sign_publickey(self::keypair($seed_hex));

        return ['kid' => $kid ?? substr(hash('sha256', $public), 0, 16), 'public' => base64_encode($public)];
    }

    private static function trustVectorKey(): void
    {
        self::assertSame('stored', gobuildwp_cloud_signing_accept(self::offer(), 'site-1'));
    }

    /**
     * Headers the Cloud would send. $signed overrides what goes into the signature
     * (path, body, method) when a test wants it to differ from what is sent.
     *
     * @param array<string, string> $signed
     * @return array<string, string>
     */
    private function sign(string $method, string $path, string $body, string $op, array $signed = [], ?int $ts = null, string $seed_hex = self::SEED_HEX, ?string $kid = null, ?string $site_id = null): array
    {
        $ts ??= time();
        $nonce = rtrim(strtr(base64_encode(str_pad((string) ++$this->nonceCounter, 18, 'n', STR_PAD_LEFT)), '+/', '-_'), '=');
        $request_id = '123e4567-e89b-42d3-a456-' . str_pad((string) $this->nonceCounter, 12, '0', STR_PAD_LEFT);
        $message = gobuildwp_cloud_signing_message(
            $signed['method'] ?? $method,
            $signed['path'] ?? $path,
            $signed['body'] ?? $body,
            (string) $ts,
            $nonce,
            $request_id,
            $op,
            '',
        );
        $keypair = self::keypair($seed_hex);
        $site_sig = [];
        if ($site_id !== null) {
            $site_message = gobuildwp_cloud_signing_site_message(
                $signed['method'] ?? $method,
                $signed['path'] ?? $path,
                $signed['body'] ?? $body,
                (string) $ts,
                $nonce,
                $request_id,
                $op,
                '',
                $site_id,
            );
            $site_sig = ['X-GoBuildWP-Site-Sig' => base64_encode(sodium_crypto_sign_detached($site_message, sodium_crypto_sign_secretkey($keypair)))];
        }

        return $site_sig + [
            'X-GoBuildWP-Ts' => (string) $ts,
            'X-GoBuildWP-Nonce' => $nonce,
            'X-GoBuildWP-Request-Id' => $request_id,
            'X-GoBuildWP-Op' => $op,
            'X-GoBuildWP-Approval' => '',
            'X-GoBuildWP-Key-Id' => $kid ?? self::offer($seed_hex)['kid'],
            'X-GoBuildWP-Sig' => base64_encode(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($keypair))),
        ];
    }

    /** @param array<string, string> $headers */
    private static function dispatch(string $route, string $body, array $headers, string $method = 'POST'): mixed
    {
        return gobuildwp_cloud_signing_pre_dispatch(null, null, new WPPilot_Test_Signed_Request($route, $method, $body, $headers));
    }

    private static function assertRefused(string $code, mixed $result): void
    {
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame($code, $result->get_error_code());
        self::assertSame(401, $result->get_error_data()['status']);
    }

    private static function call(string $tool, int $id = 5): string
    {
        return (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => []]]);
    }

    public function testTheFixedVectorVerifies(): void
    {
        self::assertSame(self::VECTOR_PUBLIC, self::offer()['public']);
        self::assertSame(self::VECTOR_KID, self::offer()['kid']);
        $message = gobuildwp_cloud_signing_message('post', self::POLICY . '/', self::VECTOR_BODY, (string) self::VECTOR_TS, self::VECTOR_NONCE, self::VECTOR_REQUEST_ID, 'cloud/policy', '');
        self::assertSame(
            "gobuildwp-cloud-request-v1\nPOST\n/wppilot/v1/cloud/policy\n928d92441865948baf5e5b5491f05c04cdce0344d05f3a3ec5d295cdd924cf26\n1767225600\nAAECAwQFBgcICQoLDA0ODxAR\n123e4567-e89b-42d3-a456-426614174000\ncloud/policy\n",
            $message,
        );

        self::trustVectorKey();
        $signing = gobuildwp_cloud_signing_stored();
        self::assertNotNull($signing);
        $request = new WPPilot_Test_Signed_Request(self::POLICY, 'POST', self::VECTOR_BODY, [
            'X-GoBuildWP-Ts' => (string) self::VECTOR_TS,
            'X-GoBuildWP-Nonce' => self::VECTOR_NONCE,
            'X-GoBuildWP-Request-Id' => self::VECTOR_REQUEST_ID,
            'X-GoBuildWP-Op' => 'cloud/policy',
            'X-GoBuildWP-Approval' => '',
            'X-GoBuildWP-Key-Id' => self::VECTOR_KID,
            'X-GoBuildWP-Sig' => self::VECTOR_SIG,
        ]);
        self::assertTrue(gobuildwp_cloud_signing_verify($request, $signing, true, ['cloud/policy'], self::VECTOR_TS));
    }

    /**
     * The Cloud's vector, SECURITY-GUARDIAN-R3-CONTRACT.md "Test vector (signing, §1)": produced by
     * platform/lib/sites/request-signing.ts, sent to /wp-json/mcp/wppilot, which WordPress routes as /mcp/wppilot.
     */
    public function testTheCloudContractVectorVerifies(): void
    {
        $seed = (string) base64_decode('AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=', true);
        $public = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)));
        self::assertSame('A6EHv/POEL4dcN0Y50vAmWfk1jCbpQ1fHdyGZBJVMbg=', $public);
        self::assertSame('stored', gobuildwp_cloud_signing_accept(['kid' => 'test-kid', 'public' => $public], 'site-1'));

        $body = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wppilot_safe_update","arguments":{"slug":"hello-dolly"}}}';
        self::assertSame('a20956417a9d634b71280aa3607844d3a689fe4923c942a7d6d2eeb8164fa386', hash('sha256', $body));
        $headers = [
            'X-GoBuildWP-Ts' => '1767225600',
            'X-GoBuildWP-Nonce' => 'AAECAwQFBgcICQoLDA0ODxAR',
            'X-GoBuildWP-Request-Id' => '6f1c2a3b-4d5e-4f60-8172-839405a6b7c8',
            'X-GoBuildWP-Op' => 'wppilot_safe_update',
            'X-GoBuildWP-Approval' => '11111111-2222-4333-8444-555555555555',
            'X-GoBuildWP-Key-Id' => 'test-kid',
            'X-GoBuildWP-Sig' => '5tltdpKoOC+kH86Z6j//9076UMDVjLKeqzplKhR3DtsbhDLAPz+xgjUoW7SG0AG7CsSz+UGDSEKiIxvjx0OPBQ==',
        ];
        $request = new WPPilot_Test_Signed_Request(self::MCP, 'POST', $body, $headers);
        $requirement = gobuildwp_cloud_signing_requirement($request);
        self::assertNotNull($requirement);
        self::assertTrue($requirement['required']);
        self::assertSame(['wppilot_safe_update'], $requirement['ops']);
        $signing = gobuildwp_cloud_signing_stored();
        self::assertNotNull($signing);
        self::assertTrue(gobuildwp_cloud_signing_verify($request, $signing, true, $requirement['ops'], 1767225600));

        // The approval id is signed: a different one fails.
        $headers['X-GoBuildWP-Approval'] = '';
        $tampered = new WPPilot_Test_Signed_Request(self::MCP, 'POST', $body, $headers);
        self::assertInstanceOf(WP_Error::class, gobuildwp_cloud_signing_verify($tampered, $signing, true, ['wppilot_safe_update'], 1767225600));
    }

    public function testAGoodSignatureLetsAWriteThrough(): void
    {
        self::trustVectorKey();
        $body = '{"ceiling":"readonly"}';
        self::assertNull(self::dispatch(self::POLICY, $body, $this->sign('POST', self::POLICY, $body, 'cloud/policy')));
        // ?rest_route= and /wp-json/ both reach here as the route; a trailing slash is not part of the signed path.
        self::assertNull(self::dispatch(self::POLICY . '/', $body, $this->sign('POST', self::POLICY, $body, 'cloud/policy')));
        $unlink = '/wppilot/v1/cloud/unlink';
        self::assertNull(self::dispatch($unlink, '', $this->sign('POST', $unlink, '', 'cloud/unlink')));
    }

    public function testABadSignatureIsRefused(): void
    {
        self::trustVectorKey();
        $body = '{"ceiling":"readonly"}';
        $headers = $this->sign('POST', self::POLICY, $body, 'cloud/policy');
        $headers['X-GoBuildWP-Sig'] = base64_encode(str_repeat("\x01", 64));
        self::assertRefused('gobuildwp_signature_invalid', self::dispatch(self::POLICY, $body, $headers));

        // Signed with another key under the stored key id.
        $other = $this->sign('POST', self::POLICY, $body, 'cloud/policy', seed_hex: str_repeat('ab', 32), kid: self::VECTOR_KID);
        self::assertRefused('gobuildwp_signature_invalid', self::dispatch(self::POLICY, $body, $other));

        // An unknown key id.
        $unknown = $this->sign('POST', self::POLICY, $body, 'cloud/policy', kid: 'not-the-key');
        self::assertRefused('gobuildwp_signature_invalid', self::dispatch(self::POLICY, $body, $unknown));
    }

    public function testAReplayIsRefused(): void
    {
        self::trustVectorKey();
        $body = '{"ceiling":"readonly"}';
        $headers = $this->sign('POST', self::POLICY, $body, 'cloud/policy');
        self::assertNull(self::dispatch(self::POLICY, $body, $headers));
        self::assertRefused('gobuildwp_replay', self::dispatch(self::POLICY, $body, $headers));
        self::assertSame(1, self::nonceRows());
    }

    private static function nonceRows(): int
    {
        return (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM wp_options WHERE SUBSTR(option_name, 1, 22) = 'gobuildwp_cloud_nonce_'");
    }

    public function testANonceIsClaimedByOneInsertAndOnlyOnce(): void
    {
        $now = 1_800_000_000;
        self::assertTrue(gobuildwp_cloud_signing_claim_nonce('AAECAwQFBgcICQoLDA0ODxAR', $now));
        // A second request with the same nonce, at the same moment: the INSERT hits the unique
        // option_name and the row is not expired, so nothing can take it over.
        self::assertFalse(gobuildwp_cloud_signing_claim_nonce('AAECAwQFBgcICQoLDA0ODxAR', $now));
        self::assertFalse(gobuildwp_cloud_signing_claim_nonce('AAECAwQFBgcICQoLDA0ODxAR', $now + GOBUILDWP_CLOUD_SIGNING_NONCE_TTL - 1));
        $inserts = array_filter($GLOBALS['wpdb']->queries, static fn(string $q): bool => str_starts_with($q, 'INSERT INTO wp_options'));
        self::assertCount(3, $inserts, 'claimed by INSERT, never by a read followed by a write');
        self::assertSame('off', $GLOBALS['wpdb']->get_var("SELECT autoload FROM wp_options WHERE option_name LIKE 'gobuildwp_cloud_nonce_%'"));
        self::assertSame(1, self::nonceRows());
    }

    public function testExpiredNoncesAreDeletedAndMayBeTakenOverOnce(): void
    {
        $now = 1_800_000_000;
        self::assertTrue(gobuildwp_cloud_signing_claim_nonce('old-nonce-aaaaaaaaaaaaaaa', $now));
        $GLOBALS['wpdb']->query("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('gobuildwpXcloud_nonce_other', '1', 'off')");
        $later = $now + GOBUILDWP_CLOUD_SIGNING_NONCE_TTL;

        // An expired row with the same name is taken over by exactly one request.
        self::assertTrue(gobuildwp_cloud_signing_claim_nonce('old-nonce-aaaaaaaaaaaaaaa', $later));
        self::assertFalse(gobuildwp_cloud_signing_claim_nonce('old-nonce-aaaaaaaaaaaaaaa', $later));

        // A fresh claim after the window deletes the rows that ran out, and only nonce rows.
        self::assertTrue(gobuildwp_cloud_signing_claim_nonce('new-nonce-bbbbbbbbbbbbbbb', $later + GOBUILDWP_CLOUD_SIGNING_NONCE_TTL));
        self::assertSame(1, self::nonceRows());
        self::assertSame('1', $GLOBALS['wpdb']->get_var("SELECT option_value FROM wp_options WHERE option_name = 'gobuildwpXcloud_nonce_other'"));
    }

    public function testAForgedRequestDoesNotUseUpTheNonce(): void
    {
        self::trustVectorKey();
        $body = '{"ceiling":"readonly"}';
        $headers = $this->sign('POST', self::POLICY, $body, 'cloud/policy');
        $forged = $headers;
        $forged['X-GoBuildWP-Sig'] = base64_encode(str_repeat("\x02", 64));
        self::assertRefused('gobuildwp_signature_invalid', self::dispatch(self::POLICY, $body, $forged));
        self::assertNull(self::dispatch(self::POLICY, $body, $headers));
    }

    public function testAStaleOrFutureTimestampIsRefused(): void
    {
        self::trustVectorKey();
        $body = '{}';
        self::assertRefused('gobuildwp_stale', self::dispatch(self::POLICY, $body, $this->sign('POST', self::POLICY, $body, 'cloud/policy', ts: time() - 301)));
        self::assertRefused('gobuildwp_stale', self::dispatch(self::POLICY, $body, $this->sign('POST', self::POLICY, $body, 'cloud/policy', ts: time() + 301)));
        self::assertNull(self::dispatch(self::POLICY, $body, $this->sign('POST', self::POLICY, $body, 'cloud/policy', ts: time() - 290)));
    }

    public function testTheWrongOpIsRefused(): void
    {
        self::trustVectorKey();
        $body = '{}';
        self::assertRefused('gobuildwp_signature_invalid', self::dispatch(self::POLICY, $body, $this->sign('POST', self::POLICY, $body, 'cloud/unlink')));

        $mcp = self::call('wppilot_delete_post');
        $result = self::dispatch(self::MCP, $mcp, $this->sign('POST', self::MCP, $mcp, 'wppilot_update_post'));
        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertSame('gobuildwp_signature_invalid', $result->data['error']['message']);
    }

    public function testTheWrongBodyIsRefused(): void
    {
        self::trustVectorKey();
        $headers = $this->sign('POST', self::POLICY, '{"ceiling":"readonly"}', 'cloud/policy');
        self::assertRefused('gobuildwp_signature_invalid', self::dispatch(self::POLICY, '{"ceiling":"development"}', $headers));
    }

    public function testTheWrongPathOrMethodIsRefused(): void
    {
        self::trustVectorKey();
        $body = '{}';
        $headers = $this->sign('POST', self::POLICY, $body, 'cloud/policy', ['path' => '/gobuildwp/v1/cloud/policy']);
        self::assertRefused('gobuildwp_signature_invalid', self::dispatch(self::POLICY, $body, $headers));
        $headers = $this->sign('POST', self::POLICY, $body, 'cloud/policy', ['method' => 'PUT']);
        self::assertRefused('gobuildwp_signature_invalid', self::dispatch(self::POLICY, $body, $headers));
    }

    public function testAnUnsignedWriteIsRefusedOnceAKeyIsStored(): void
    {
        // No key yet: nothing is enforced (a Cloud that does not sign, or a site paired before R3).
        self::assertNull(self::dispatch(self::POLICY, '{}', []));
        self::assertNull(self::dispatch(self::MCP, self::call('wppilot_delete_post'), []));

        self::trustVectorKey();
        self::assertRefused('gobuildwp_signature_required', self::dispatch(self::POLICY, '{}', []));
        self::assertRefused('gobuildwp_signature_required', self::dispatch('/wppilot/v1/cloud/unlink', '', []));
        // Any other write the token could reach is held to the same rule.
        self::assertRefused('gobuildwp_signature_required', self::dispatch('/wppilot/v1/abilities/wppilot/delete-post/run', '{}', []));
        // Some headers but not all.
        $partial = array_intersect_key($this->sign('POST', self::POLICY, '{}', 'cloud/policy'), ['X-GoBuildWP-Sig' => 1]);
        self::assertRefused('gobuildwp_signature_required', self::dispatch(self::POLICY, '{}', $partial));

        // An MCP write is answered as JSON-RPC, with the call's id and HTTP 200.
        $result = self::dispatch(self::MCP, self::call('wppilot_delete_post', 42), []);
        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertSame(200, $result->status);
        self::assertSame(42, $result->data['id']);
        self::assertSame(-32001, $result->data['error']['code']);
        self::assertSame('gobuildwp_signature_required', $result->data['error']['message']);
        self::assertSame(['code' => 'gobuildwp_signature_required'], $result->data['error']['data']);

        // A signed MCP write goes through.
        $mcp = self::call('wppilot_delete_post');
        self::assertNull(self::dispatch(self::MCP, $mcp, $this->sign('POST', self::MCP, $mcp, 'wppilot_delete_post')));
    }

    public function testReadsAndOtherMcpMethodsNeedNoSignature(): void
    {
        self::trustVectorKey();
        self::assertNull(self::dispatch('/wppilot/v1/cloud/status', '', [], 'GET'));
        self::assertNull(self::dispatch('/gobuildwp/v1/cloud/inventory', '', [], 'GET'));
        self::assertNull(self::dispatch(self::MCP, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', []));
        self::assertNull(self::dispatch(self::MCP, '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}', []));

        // A read that carries a signature must carry a good one.
        $headers = $this->sign('GET', '/wppilot/v1/cloud/status', '', 'cloud/status');
        self::assertNull(self::dispatch('/wppilot/v1/cloud/status', '', $headers, 'GET'));
        $headers = $this->sign('GET', '/wppilot/v1/cloud/status', '', 'cloud/status');
        $headers['X-GoBuildWP-Sig'] = base64_encode(str_repeat("\x03", 64));
        self::assertRefused('gobuildwp_signature_invalid', self::dispatch('/wppilot/v1/cloud/status', '', $headers, 'GET'));
    }

    /**
     * Only a request that carried a signature which verified counts as a verified Cloud request;
     * an unsigned read passes the check without it, and a refused request never sets it.
     */
    public function testOnlyAVerifiedSignatureMarksTheRequestAsFromTheCloud(): void
    {
        self::trustVectorKey();

        self::assertNull(self::dispatch('/wppilot/v1/cloud/status', '', [], 'GET'));
        self::assertNull(self::dispatch(self::MCP, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', []));
        self::assertFalse(gobuildwp_cloud_request_verified(), 'an unsigned read');

        $body = '{"ceiling":"production"}';
        $headers = $this->sign('POST', self::POLICY, $body, 'cloud/policy');
        $headers['X-GoBuildWP-Sig'] = base64_encode(str_repeat("\x03", 64));
        self::assertRefused('gobuildwp_signature_invalid', self::dispatch(self::POLICY, $body, $headers));
        self::assertRefused('gobuildwp_signature_required', self::dispatch(self::POLICY, $body, []));
        self::assertFalse(gobuildwp_cloud_request_verified(), 'a refused request');

        $mcp = self::call('gobuildwp_security_fix_apply');
        self::assertNull(self::dispatch(self::MCP, $mcp, $this->sign('POST', self::MCP, $mcp, 'gobuildwp_security_fix_apply')));
        self::assertTrue(gobuildwp_cloud_request_verified(), 'a signed tools/call');
    }

    public function testAnotherTokensSignatureNeverMarksTheRequest(): void
    {
        self::trustVectorKey();
        reset_request_context();
        record_oauth_identity(1, ['mcp'], 'token-8', via: 'token');
        $mcp = self::call('gobuildwp_security_fix_apply');
        self::assertNull(self::dispatch(self::MCP, $mcp, $this->sign('POST', self::MCP, $mcp, 'gobuildwp_security_fix_apply')));
        self::assertFalse(gobuildwp_cloud_request_verified());
    }

    public function testASiteWithoutAStoredKeyNeverMarksTheRequest(): void
    {
        $mcp = self::call('gobuildwp_security_fix_apply');
        self::assertNull(self::dispatch(self::MCP, $mcp, $this->sign('POST', self::MCP, $mcp, 'gobuildwp_security_fix_apply')));
        self::assertFalse(gobuildwp_cloud_request_verified());
    }

    public function testOtherCredentialsAreUnaffected(): void
    {
        self::trustVectorKey();
        reset_request_context();
        record_oauth_identity(1, ['mcp'], 'token-8', via: 'token');
        self::assertNull(self::dispatch(self::POLICY, '{}', []));
        self::assertNull(self::dispatch(self::MCP, self::call('wppilot_delete_post'), ['X-GoBuildWP-Sig' => 'garbage']));

        // Cookie or application-password requests carry no token at all.
        reset_request_context();
        self::assertNull(self::dispatch(self::MCP, self::call('wppilot_delete_post'), []));

        // An earlier filter's answer is passed on untouched.
        record_oauth_identity(1, ['mcp'], 'token-' . self::CLOUD_TOKEN, via: 'token');
        $earlier = new WP_Error('earlier', 'earlier');
        self::assertSame($earlier, gobuildwp_cloud_signing_pre_dispatch($earlier, null, new WPPilot_Test_Signed_Request(self::POLICY)));
    }

    public function testThePreviousKeyVerifiesOnlyDuringItsGrace(): void
    {
        $now = 1_800_000_000;
        self::assertSame('stored', gobuildwp_cloud_signing_accept(self::offer(), 'site-1', $now - 100));
        $next = self::offer(str_repeat('cd', 32));
        self::assertSame('rotated', gobuildwp_cloud_signing_accept($next + ['previous' => self::offer()], 'site-1', $now));
        $signing = gobuildwp_cloud_signing_stored();
        self::assertNotNull($signing);
        self::assertSame($next['kid'], $signing['kid']);
        self::assertSame($now + 7 * 86400, $signing['previous_until']);

        $body = '{}';
        $old = fn(int $at): WPPilot_Test_Signed_Request => new WPPilot_Test_Signed_Request(self::POLICY, 'POST', $body, $this->sign('POST', self::POLICY, $body, 'cloud/policy', ts: $at));
        $new = fn(int $at): WPPilot_Test_Signed_Request => new WPPilot_Test_Signed_Request(self::POLICY, 'POST', $body, $this->sign('POST', self::POLICY, $body, 'cloud/policy', ts: $at, seed_hex: str_repeat('cd', 32)));

        $within = $now + 6 * 86400;
        self::assertTrue(gobuildwp_cloud_signing_verify($old($within), $signing, true, ['cloud/policy'], $within));
        self::assertTrue(gobuildwp_cloud_signing_verify($new($within), $signing, true, ['cloud/policy'], $within));

        $after = $now + 7 * 86400 + 1;
        $refused = gobuildwp_cloud_signing_verify($old($after), $signing, true, ['cloud/policy'], $after);
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('gobuildwp_signature_invalid', $refused->get_error_code());
        self::assertTrue(gobuildwp_cloud_signing_verify($new($after), $signing, true, ['cloud/policy'], $after));

        // Once the grace is over, the next offer of the same key drops the old one.
        self::assertSame('unchanged', gobuildwp_cloud_signing_accept($next, 'site-1', $after));
        self::assertNull(gobuildwp_cloud_signing_stored()['previous'] ?? null);
    }

    public function testTrustOnFirstUseAndMismatch(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'gbw');
        $saved = (string) ini_get('error_log');
        ini_set('error_log', $log);
        try {
            self::assertSame('none', gobuildwp_cloud_signing_status());
            self::assertSame('absent', gobuildwp_cloud_signing_accept(null, 'site-1'));
            self::assertSame('absent', gobuildwp_cloud_signing_accept(['kid' => 'k', 'public' => base64_encode('short')], 'site-1'));
            self::assertSame('none', gobuildwp_cloud_signing_status());

            // A heartbeat that speaks for another link stores nothing.
            self::assertSame('absent', gobuildwp_cloud_signing_accept(self::offer(), 'site-2'));
            self::trustVectorKey();
            self::assertSame('enforced', gobuildwp_cloud_signing_status());
            self::assertSame('unchanged', gobuildwp_cloud_signing_accept(self::offer(), 'site-1'));

            // A different key that does not name the stored one as previous is ignored and flagged.
            $intruder = self::offer(str_repeat('ef', 32));
            self::assertSame('mismatch', gobuildwp_cloud_signing_accept($intruder, 'site-1'));
            self::assertSame('mismatch', gobuildwp_cloud_signing_accept($intruder + ['previous' => self::offer(str_repeat('99', 32))], 'site-1'));
            self::assertSame(self::VECTOR_KID, gobuildwp_cloud_signing_stored()['kid'] ?? null);
            self::assertSame($intruder['kid'], $GLOBALS['wppilot_test_transients'][GOBUILDWP_CLOUD_SIGNING_MISMATCH_TRANSIENT]['kid'] ?? null);
            self::assertStringContainsString($intruder['kid'], (string) file_get_contents($log));

            // An answer without the object keeps the stored key: no downgrade.
            self::assertSame('absent', gobuildwp_cloud_signing_accept(null, 'site-1'));
            self::assertSame('enforced', gobuildwp_cloud_signing_status());
            // The rest of the link is untouched by storing the key.
            self::assertSame(self::CLOUD_TOKEN, wppilot_cloud_link()['token_id'] ?? null);
        } finally {
            ini_set('error_log', $saved);
            @unlink($log);
        }
    }

    public function testWithoutSodiumNoKeyIsStoredAndStatusSaysUnavailable(): void
    {
        add_filter('gobuildwp_cloud_signing_supported', static fn(): bool => false);
        self::assertSame('unavailable', gobuildwp_cloud_signing_status());
        self::assertSame('unavailable', gobuildwp_cloud_signing_accept(self::offer(), 'site-1'));
        self::assertNull(gobuildwp_cloud_signing_stored());
        self::assertNull(self::dispatch(self::POLICY, '{}', []));

        // A key stored before sodium went away refuses signed calls rather than waving them through.
        remove_all_filters('gobuildwp_cloud_signing_supported');
        self::trustVectorKey();
        add_filter('gobuildwp_cloud_signing_supported', static fn(): bool => false);
        self::assertRefused('gobuildwp_signature_invalid', self::dispatch(self::POLICY, '{}', $this->sign('POST', self::POLICY, '{}', 'cloud/policy')));
        self::assertRefused('gobuildwp_signature_required', self::dispatch(self::POLICY, '{}', []));
    }

    public function testTheHeartbeatAnswerDeliversTheKeyAndDisconnectRemovesIt(): void
    {
        wppilot_cloud_handle_signed_response(['status' => 500, 'body' => ['cloud_signing' => self::offer()]], 'site-1');
        self::assertNull(gobuildwp_cloud_signing_stored());
        wppilot_cloud_handle_signed_response(['status' => 200, 'body' => ['ok' => true, 'cloud_signing' => self::offer()]], 'site-1');
        self::assertSame(self::VECTOR_KID, gobuildwp_cloud_signing_stored()['kid'] ?? null);

        $GLOBALS['wppilot_test_transients'][GOBUILDWP_CLOUD_SIGNING_MISMATCH_TRANSIENT] = ['kid' => 'x', 'at' => 1];
        wppilot_cloud_clear_link();
        self::assertNull(gobuildwp_cloud_signing_stored());
        self::assertArrayNotHasKey(WPPILOT_CLOUD_LINK_OPTION, WPPilot_Test_State::$options);
        self::assertArrayNotHasKey(GOBUILDWP_CLOUD_SIGNING_MISMATCH_TRANSIENT, $GLOBALS['wppilot_test_transients']);
    }

    /**
     * Read-only tools resolve through wp_get_abilities(), which no other test may see.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReadOnlyToolCallsMayGoUnsignedButNotBadlySigned(): void
    {
        $GLOBALS['signing_test_abilities'] = [
            new WP_Ability('gobuildwp/commerce-activity-since', ['annotations' => ['readonly' => true]]),
            new WP_Ability('wppilot/delete-post', ['annotations' => ['readonly' => false, 'destructive' => true]]),
        ];
        eval('function wp_get_abilities(array $args = []): array { return $GLOBALS["signing_test_abilities"]; }');
        self::trustVectorKey();

        $read = self::call('gobuildwp_commerce_activity_since');
        self::assertNull(self::dispatch(self::MCP, $read, []));
        self::assertNull(self::dispatch(self::MCP, $read, $this->sign('POST', self::MCP, $read, 'gobuildwp_commerce_activity_since')));
        $bad = $this->sign('POST', self::MCP, $read, 'gobuildwp_commerce_activity_since');
        $bad['X-GoBuildWP-Sig'] = base64_encode(str_repeat("\x04", 64));
        $result = self::dispatch(self::MCP, $read, $bad);
        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertSame('gobuildwp_signature_invalid', $result->data['error']['message']);

        // The write next to it still needs one, and a batch is held to its strictest call.
        $write = self::call('wppilot_delete_post');
        self::assertInstanceOf(WP_REST_Response::class, self::dispatch(self::MCP, $write, []));
        $batch = '[' . $read . ',' . $write . ']';
        $result = self::dispatch(self::MCP, $batch, []);
        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertNull($result->data['id']);
        self::assertSame('gobuildwp_signature_required', $result->data['error']['message']);
        self::assertNull(self::dispatch(self::MCP, '[' . $read . ',' . $read . ']', []));
    }

    public function testTheSiteBoundMessageAddsTheSiteIdUnderAV2Header(): void
    {
        $message = gobuildwp_cloud_signing_site_message('post', self::POLICY . '/', self::VECTOR_BODY, (string) self::VECTOR_TS, self::VECTOR_NONCE, self::VECTOR_REQUEST_ID, 'cloud/policy', '', 'site-1');
        self::assertSame(
            "gobuildwp-cloud-request-v2\nPOST\n/wppilot/v1/cloud/policy\n928d92441865948baf5e5b5491f05c04cdce0344d05f3a3ec5d295cdd924cf26\n1767225600\nAAECAwQFBgcICQoLDA0ODxAR\n123e4567-e89b-42d3-a456-426614174000\ncloud/policy\n\nsite-1",
            $message,
        );
    }

    public function testASiteBoundSignatureBindsTheSiteAndRefusesOtherSitesAndTheOldForm(): void
    {
        self::trustVectorKey();
        $body = '{"policy":{"id":"p"}}';
        self::assertSame(0, gobuildwp_cloud_signing_stored()['site_bound']);

        // Before any site-bound signature, the v1 form still passes (older Cloud).
        self::assertNull(self::dispatch(self::POLICY, $body, $this->sign('POST', self::POLICY, $body, 'cloud/policy')));

        // A signature bound to another site is refused outright.
        self::assertRefused('gobuildwp_signature_invalid', self::dispatch(self::POLICY, $body, $this->sign('POST', self::POLICY, $body, 'cloud/policy', site_id: 'site-2')));
        self::assertSame(0, gobuildwp_cloud_signing_stored()['site_bound']);

        // One bound to this site verifies and latches.
        self::assertNull(self::dispatch(self::POLICY, $body, $this->sign('POST', self::POLICY, $body, 'cloud/policy', site_id: 'site-1')));
        self::assertGreaterThan(0, gobuildwp_cloud_signing_stored()['site_bound']);

        // From now on a signature that names no site is refused, even a fresh valid one.
        self::assertRefused('gobuildwp_site_signature_required', self::dispatch(self::POLICY, $body, $this->sign('POST', self::POLICY, $body, 'cloud/policy')));
        self::assertNull(self::dispatch(self::POLICY, $body, $this->sign('POST', self::POLICY, $body, 'cloud/policy', site_id: 'site-1')));

        // A tampered site-bound signature fails even though the v1 header beside it is good.
        $headers = $this->sign('POST', self::POLICY, $body, 'cloud/policy', site_id: 'site-1');
        $headers['X-GoBuildWP-Site-Sig'] = base64_encode(str_repeat("", 64));
        self::assertRefused('gobuildwp_signature_invalid', self::dispatch(self::POLICY, $body, $headers));

        // The latch survives a key rotation.
        $next = self::offer('ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff');
        self::assertSame('rotated', gobuildwp_cloud_signing_accept($next + ['previous' => self::offer()], 'site-1'));
        self::assertGreaterThan(0, gobuildwp_cloud_signing_stored()['site_bound']);
    }

    public function testStatusReportsCloudSigning(): void
    {
        $saved = $GLOBALS['wpdb'] ?? null;
        // The status answer reads the Cloud token's ceiling from the token table.
        $GLOBALS['wpdb'] = new \WPPilot_Test_Sqlite_Wpdb();
        try {
            self::assertSame('none', wppilot_cloud_rest_status()->data['cloud_signing']);
            self::trustVectorKey();
            self::assertSame('enforced', wppilot_cloud_rest_status()->data['cloud_signing']);
        } finally {
            $GLOBALS['wpdb'] = $saved;
            wppilot_token_policy_cache(self::CLOUD_TOKEN, forget: true);
        }
    }
}
