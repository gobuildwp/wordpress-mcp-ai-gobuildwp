<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Response;
use WPPilot_Test_Halt;
use WPPilot_Test_Sqlite_Wpdb;
use WPPilot_Test_State;

use function WPPilot\OAuth\Middleware\oauth_identity_may_use_route;
use function WPPilot\OAuth\Middleware\record_oauth_identity;
use function WPPilot\OAuth\Middleware\reset_request_context;

require_once dirname(__DIR__) . '/doubles/admin.php';
require_once dirname(__DIR__) . '/doubles/mcp-surface.php';
require_once dirname(__DIR__) . '/doubles/sqlite-wpdb.php';
require_once dirname(__DIR__) . '/doubles/cloud.php';
require_once dirname(__DIR__, 2) . '/includes/capabilities.php';
require_once dirname(__DIR__, 2) . '/includes/clients.php';
require_once dirname(__DIR__, 2) . '/includes/rate-limit.php';
require_once dirname(__DIR__, 2) . '/includes/cloud/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/admin/connect/cloud-panel.php';

if (!defined('WPPILOT_SETUP_PAGE')) {
    define('WPPILOT_SETUP_PAGE', 'wppilot-setup');
}

/**
 * GoBuildWP Cloud pairing, against docs/pairing-protocol.md in the platform repo.
 *
 * The Cloud is played by queued HTTP answers, and the token table is real SQL
 * on SQLite, so "the token was revoked" is proven by the row being gone.
 */
final class CloudPairingTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    /** @var array<string, int> */
    private array $savedCron = [];

    private mixed $savedWpdb = null;

    private WPPilot_Test_Sqlite_Wpdb $db;

    protected function setUp(): void
    {
        $this->savedOptions = WPPilot_Test_State::$options;
        $this->savedCron = WPPilot_Test_State::$cron;
        $this->savedWpdb = $GLOBALS['wpdb'] ?? null;

        foreach ([
            WPPILOT_CLOUD_LINK_OPTION,
            WPPILOT_CLOUD_KEYS_OPTION,
            WPPILOT_CLOUD_SEEN_VERSION_OPTION,
            WPPILOT_TOKENS_SCHEMA_OPTION,
            WPPILOT_SAFETY_PROFILE_OPTION,
        ] as $option) {
            unset(WPPilot_Test_State::$options[$option]);
        }
        unset(WPPilot_Test_State::$cron[WPPILOT_CLOUD_HEARTBEAT_HOOK]);

        $this->db = new WPPilot_Test_Sqlite_Wpdb();
        $GLOBALS['wpdb'] = $this->db;
        $GLOBALS['wppilot_test_transients'] = [];
        $GLOBALS['wppilot_test_http_responses'] = [];
        $GLOBALS['wppilot_test_option_autoload'] = [];
        $GLOBALS['wppilot_test_single_events'] = [];
        $GLOBALS['wppilot_test_managers'] = [1, 2];
        unset($GLOBALS['wppilot_test_environment_type']);
        WPPilot_Test_State::$http_posts = [];
        WPPilot_Test_State::$current_user_id = 1;
        WPPilot_Test_State::$capabilities = ['manage_options'];
        for ($id = 1; $id <= 10; $id++) {
            wppilot_token_policy_cache($id, forget: true);
        }
        reset_request_context();
    }

    protected function tearDown(): void
    {
        WPPilot_Test_State::$options = $this->savedOptions;
        WPPilot_Test_State::$cron = $this->savedCron;
        WPPilot_Test_State::$http_posts = [];
        WPPilot_Test_State::$current_user_id = 0;
        WPPilot_Test_State::$capabilities = [];
        $GLOBALS['wpdb'] = $this->savedWpdb;
        $GLOBALS['wppilot_test_http_responses'] = [];
        unset($GLOBALS['wppilot_test_environment_type'], $_POST['state'], $_REQUEST['_wpnonce'], $_GET['state'], $_GET['code']);
        remove_all_filters('wppilot_cloud_url');
        reset_request_context();
        parent::tearDown();
    }

    /** @param array<string, mixed> $body */
    private static function answer(int $status, array $body): array
    {
        return ['response' => ['code' => $status], 'body' => (string) json_encode($body)];
    }

    /** @param array<string, mixed>|WP_Error ...$answers */
    private static function queue(array|WP_Error ...$answers): void
    {
        foreach ($answers as $answer) {
            $GLOBALS['wppilot_test_http_responses'][] = $answer;
        }
    }

    /** @return array<string, mixed> */
    private static function sent(int $index): array
    {
        return (array) json_decode((string) WPPilot_Test_State::$http_posts[$index]['body'], associative: true);
    }

    private function tokenExists(int $token_id): bool
    {
        return $this->db->get_var('SELECT id FROM wp_wppilot_tokens WHERE id = ' . $token_id) !== null;
    }

    /**
     * Run §1 and §2 with a Cloud that grants $policy; return the state.
     *
     * @param array<string, mixed> $policy
     */
    private function pendingPairing(array $policy = []): string
    {
        $begun = wppilot_cloud_begin(1);
        self::assertIsArray($begun);
        self::queue(self::answer(200, array_merge([
            'account_hint' => 'a***@example.com',
            'workspace' => 'Agency',
            'ceiling' => 'production',
            'scope' => null,
            'label' => 'Example',
        ], $policy)));
        self::assertTrue(wppilot_cloud_receive($begun['state'], 'code-1234567890', 1));
        WPPilot_Test_State::$http_posts = [];

        return $begun['state'];
    }

    /** @return array{site_id: string|int, token_id: int, account_hint: string, cloud_url: string, paired_at: string} */
    private function pairedLink(): array
    {
        $state = $this->pendingPairing();
        self::queue(self::answer(200, ['site_id' => 'site_abc']));
        $link = wppilot_cloud_complete($state, 1);
        self::assertIsArray($link);
        WPPilot_Test_State::$http_posts = [];

        return $link;
    }

    public function test_challenge_is_the_s256_of_the_verifier(): void
    {
        // RFC 7636 appendix B: the same construction, so the same vector holds.
        self::assertSame(
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            wppilot_cloud_challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'),
        );
        self::assertSame('-__8', wppilot_cloud_b64url("\xfb\xff\xfc"), 'base64 +//8, url-safe and unpadded');
    }

    /** @return array<string, array{string, string, string}> */
    public static function cloud_urls(): array
    {
        return [
            'https' => ['https://app.gobuildwp.com/', 'production', 'https://app.gobuildwp.com'],
            'https with path' => ['https://example.com/cloud', 'production', 'https://example.com/cloud'],
            'http in production' => ['http://app.localhost:3310', 'production', ''],
            'http on staging' => ['http://app.localhost:3310', 'staging', ''],
            'http on local' => ['http://app.localhost:3310', 'local', 'http://app.localhost:3310'],
            'http on development' => ['http://app.localhost:3310/', 'development', 'http://app.localhost:3310'],
            'credentials' => ['https://user:pass@app.gobuildwp.com', 'production', ''],
            'query' => ['https://app.gobuildwp.com?x=1', 'production', ''],
            'no host' => ['https:///path', 'production', ''],
            'other scheme' => ['ftp://app.gobuildwp.com', 'local', ''],
            'empty' => ['', 'production', ''],
        ];
    }

    #[DataProvider('cloud_urls')]
    public function test_cloud_url_is_https_outside_local_and_development(string $url, string $env, string $expected): void
    {
        $GLOBALS['wppilot_test_environment_type'] = $env;

        self::assertSame($expected, wppilot_cloud_normalize_url($url));
    }

    public function test_cloud_url_defaults_to_app_gobuildwp_com_and_can_be_filtered(): void
    {
        self::assertSame('https://app.gobuildwp.com', wppilot_cloud_url());

        add_filter('wppilot_cloud_url', static fn(): string => 'http://app.localhost:3310');
        self::assertSame('', wppilot_cloud_url(), 'plain HTTP is refused on a production site');

        $GLOBALS['wppilot_test_environment_type'] = 'local';
        self::assertSame('http://app.localhost:3310', wppilot_cloud_url());
    }

    public function test_begin_binds_the_state_to_the_user_and_sends_only_the_challenge(): void
    {
        $begun = wppilot_cloud_begin(1);
        self::assertIsArray($begun);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $begun['state']);

        $pending = wppilot_cloud_pending($begun['state'], 1);
        self::assertIsArray($pending);
        self::assertSame(1, $pending['user_id']);
        self::assertSame('https://app.gobuildwp.com', $pending['cloud_url']);
        self::assertGreaterThan(time() + 590, $pending['expires']);

        $url = $begun['url'];
        self::assertStringStartsWith('https://app.gobuildwp.com/connect/site?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('https://example.test', $query['site']);
        self::assertSame($begun['state'], $query['state']);
        self::assertSame(wppilot_cloud_challenge($pending['verifier']), $query['challenge']);
        self::assertSame('https://example.test/wp-admin/admin-post.php?action=wppilot_cloud_return', $query['return']);
        self::assertSame(WPPILOT_VERSION, $query['v']);
        self::assertStringNotContainsString($pending['verifier'], $url, 'the verifier never leaves the site');
        // The return URL is one encoded parameter, so its own ?action= is not split off.
        self::assertStringContainsString('return=https%3A%2F%2Fexample.test%2Fwp-admin%2Fadmin-post.php%3Faction%3Dwppilot_cloud_return', $url);
    }

    public function test_a_pairing_belongs_to_the_user_who_started_it_and_expires(): void
    {
        $begun = wppilot_cloud_begin(1);
        self::assertIsArray($begun);

        self::assertNull(wppilot_cloud_pending($begun['state'], 2), 'another administrator');
        self::assertNull(wppilot_cloud_pending('not-a-state', 1));

        $key = WPPILOT_CLOUD_PAIR_TRANSIENT_PREFIX . $begun['state'];
        $GLOBALS['wppilot_test_transients'][$key]['expires'] = time() - 1;
        self::assertNull(wppilot_cloud_pending($begun['state'], 1), 'past the ten minutes, even if the cache kept it');
    }

    public function test_begin_refuses_a_second_pairing_and_an_unusable_cloud_url(): void
    {
        WPPilot_Test_State::$options[WPPILOT_CLOUD_LINK_OPTION] = ['site_id' => 's', 'token_id' => 3];
        $error = wppilot_cloud_begin(1);
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('already_connected', $error->get_error_code());

        unset(WPPilot_Test_State::$options[WPPILOT_CLOUD_LINK_OPTION]);
        add_filter('wppilot_cloud_url', static fn(): string => 'http://cloud.example');
        $error = wppilot_cloud_begin(1);
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('unavailable', $error->get_error_code());
        self::assertSame([], $GLOBALS['wppilot_test_transients']);
    }

    public function test_receive_asks_the_cloud_for_the_policy_and_keeps_it_for_the_confirm_screen(): void
    {
        $begun = wppilot_cloud_begin(1);
        self::assertIsArray($begun);
        self::queue(self::answer(200, [
            'account_hint' => 'a***@example.com',
            'workspace' => ['id' => 'w1', 'name' => 'Agency'],
            'ceiling' => 'readonly',
            'scope' => ['wppilot/read-content', 'rank-math/*'],
            'label' => 'Main site',
        ]));

        self::assertTrue(wppilot_cloud_receive($begun['state'], 'code-1234567890', 1));

        self::assertSame('https://app.gobuildwp.com/api/pair/policy', WPPilot_Test_State::$http_posts[0]['url']);
        $pending = wppilot_cloud_pending($begun['state'], 1);
        self::assertIsArray($pending);
        self::assertSame(
            ['code' => 'code-1234567890', 'verifier' => $pending['verifier'], 'site_url' => 'https://example.test'],
            self::sent(0),
        );
        $args = WPPilot_Test_State::$http_posts[0]['args'];
        self::assertTrue($args['sslverify']);
        self::assertSame(45, $args['timeout']);
        self::assertSame(0, $args['redirection']);
        self::assertSame('application/json', $args['headers']['Content-Type']);

        self::assertSame('code-1234567890', $pending['code']);
        self::assertSame([
            'account_hint' => 'a***@example.com',
            'workspace' => 'Agency',
            'ceiling' => 'readonly',
            'scope' => ['abilities' => ['wppilot/read-content', 'rank-math/*'], 'categories' => []],
            'label' => 'Main site',
        ], $pending['policy']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function refused_policies(): array
    {
        return [
            'unknown ceiling' => [['account_hint' => 'a***@e.com', 'ceiling' => 'developer', 'scope' => null]],
            'no ceiling' => [['account_hint' => 'a***@e.com', 'scope' => null]],
            'no account' => [['ceiling' => 'readonly', 'scope' => null]],
            'empty scope' => [['account_hint' => 'a***@e.com', 'ceiling' => 'readonly', 'scope' => []]],
            'scope of junk' => [['account_hint' => 'a***@e.com', 'ceiling' => 'readonly', 'scope' => ['not an ability']]],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('refused_policies')]
    public function test_a_policy_this_site_cannot_enforce_ends_the_pairing(array $body): void
    {
        $begun = wppilot_cloud_begin(1);
        self::assertIsArray($begun);
        self::queue(self::answer(200, $body));

        $result = wppilot_cloud_receive($begun['state'], 'code-1234567890', 1);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertNull(wppilot_cloud_pending($begun['state'], 1));
    }

    public function test_a_refused_code_ends_the_pairing_and_carries_the_cloud_reason(): void
    {
        $begun = wppilot_cloud_begin(1);
        self::assertIsArray($begun);
        self::queue(self::answer(404, ['error' => 'invalid_code']));

        $result = wppilot_cloud_receive($begun['state'], 'code-1234567890', 1);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('policy', $result->get_error_code());
        self::assertSame('invalid_code', $result->get_error_data()['detail']);
        self::assertNull(wppilot_cloud_pending($begun['state'], 1));
    }

    public function test_receive_for_another_user_does_not_call_the_cloud(): void
    {
        $begun = wppilot_cloud_begin(1);
        self::assertIsArray($begun);

        $result = wppilot_cloud_receive($begun['state'], 'code-1234567890', 2);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('expired', $result->get_error_code());
        self::assertSame([], WPPilot_Test_State::$http_posts);
    }

    public function test_complete_mints_the_token_hands_it_over_and_saves_the_link(): void
    {
        $state = $this->pendingPairing(['ceiling' => 'readonly', 'scope' => ['abilities' => ['wppilot/*']]]);
        self::queue(self::answer(200, ['site_id' => 'site_abc', 'cloud_pubkey' => 'ignored']));
        // Left over from an earlier pairing that was never disconnected properly.
        WPPilot_Test_State::$options[WPPILOT_CLOUD_MANAGE_OPTION] = ['tighten' => true, 'loosen' => true];

        $link = wppilot_cloud_complete($state, 1);
        self::assertSame(['tighten' => false, 'loosen' => false], wppilot_cloud_manage_settings(), 'a new pairing starts unmanaged');

        self::assertIsArray($link);
        self::assertSame('https://app.gobuildwp.com/api/pair/complete', WPPilot_Test_State::$http_posts[0]['url']);
        $sent = self::sent(0);
        self::assertSame('code-1234567890', $sent['code']);
        self::assertSame('https://example.test', $sent['site_url']);
        self::assertStringStartsWith('wpp_', $sent['token']);
        self::assertSame($link['token_id'], $sent['token_id']);
        self::assertSame(32, strlen((string) base64_decode((string) $sent['site_pubkey'], true)));
        self::assertSame(
            ['wp' => '7.0', 'php' => PHP_VERSION, 'plugin' => WPPILOT_VERSION, 'pro' => null],
            $sent['versions'],
        );

        // The token the Cloud holds authenticates, with the scope and ceiling it confirmed.
        $identity = wppilot_token_authenticate((string) $sent['token']);
        self::assertIsArray($identity);
        self::assertSame($link['token_id'], $identity['id']);
        self::assertSame(WPPILOT_CLOUD_TOKEN_NAME, $identity['name']);
        $policy = wppilot_token_policy($link['token_id']);
        self::assertIsArray($policy);
        self::assertSame('readonly', $policy['ceiling']);
        self::assertSame(['abilities' => ['wppilot/*'], 'categories' => []], $policy['scope']);

        self::assertSame('site_abc', $link['site_id']);
        self::assertSame('a***@example.com', $link['account_hint']);
        self::assertSame('https://app.gobuildwp.com', $link['cloud_url']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $link['paired_at']);
        self::assertSame(
            ['site_id', 'token_id', 'account_hint', 'cloud_url', 'paired_at'],
            array_keys(WPPilot_Test_State::$options[WPPILOT_CLOUD_LINK_OPTION]),
        );
        self::assertFalse($GLOBALS['wppilot_test_option_autoload'][WPPILOT_CLOUD_LINK_OPTION]);
        self::assertFalse($GLOBALS['wppilot_test_option_autoload'][WPPILOT_CLOUD_KEYS_OPTION]);
        self::assertArrayHasKey(WPPILOT_CLOUD_HEARTBEAT_HOOK, WPPilot_Test_State::$cron);
        self::assertNull(wppilot_cloud_pending($state, 1), 'the transient is gone');
        self::assertNotFalse(json_encode(WPPilot_Test_State::$options[WPPILOT_CLOUD_LINK_OPTION]));
        self::assertStringNotContainsString(
            (string) $sent['token'],
            (string) json_encode(WPPilot_Test_State::$options),
            'the raw token is stored nowhere on the site',
        );
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function failed_completions(): array
    {
        return [
            'refused' => [self::answer(400, ['error' => 'mcp_check_failed']), 'complete'],
            'no site id' => [self::answer(200, ['ok' => true]), 'invalid_response'],
            'unusable site id' => [self::answer(200, ['site_id' => 'has spaces']), 'invalid_response'],
        ];
    }

    /** @param array<string, mixed> $answer */
    #[DataProvider('failed_completions')]
    public function test_a_failed_completion_revokes_the_token_and_saves_nothing(array $answer, string $code): void
    {
        $state = $this->pendingPairing();
        self::queue($answer);

        $result = wppilot_cloud_complete($state, 1);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame($code, $result->get_error_code());
        $sent = self::sent(0);
        self::assertFalse($this->tokenExists((int) $sent['token_id']));
        self::assertArrayNotHasKey(WPPILOT_CLOUD_LINK_OPTION, WPPilot_Test_State::$options);
        self::assertArrayNotHasKey(WPPILOT_CLOUD_HEARTBEAT_HOOK, WPPilot_Test_State::$cron);
    }

    public function test_a_failed_connection_check_carries_the_cloud_reason_and_a_hint(): void
    {
        $state = $this->pendingPairing();
        self::queue(self::answer(422, ['error' => 'verification_failed', 'reason' => 'unauthorized_403', 'message' => 'the site rejected the Cloud credential']));

        $result = wppilot_cloud_complete($state, 1);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame(['detail' => 'verification_failed-unauthorized_403'], $result->get_error_data());
        self::assertStringContainsString('WPPilot-Cloud/1', wppilot_cloud_detail_hint('verification_failed-unauthorized_403'));
        self::assertStringContainsString('challenge page', wppilot_cloud_detail_hint('verification_failed-protocol_200'));
        self::assertSame('', wppilot_cloud_detail_hint('invalid_or_expired_code'));
    }

    public function test_a_network_failure_during_completion_revokes_the_token(): void
    {
        $state = $this->pendingPairing();
        self::queue(new WP_Error('http_request_failed', 'cURL error 28'));

        $result = wppilot_cloud_complete($state, 1);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('network', $result->get_error_code());
        self::assertFalse($this->tokenExists((int) self::sent(0)['token_id']));
    }

    public function test_confirm_is_single_use(): void
    {
        $state = $this->pendingPairing();
        self::queue(self::answer(500, []));
        self::assertInstanceOf(WP_Error::class, wppilot_cloud_complete($state, 1));

        $again = wppilot_cloud_complete($state, 1);

        self::assertInstanceOf(WP_Error::class, $again);
        self::assertSame('expired', $again->get_error_code());
        self::assertCount(1, WPPilot_Test_State::$http_posts, 'a second submit never reaches the Cloud');
    }

    public function test_confirm_handler_needs_the_nonce_and_redirects_on_success(): void
    {
        $state = $this->pendingPairing();
        $_POST['state'] = $state;
        $_REQUEST['_wpnonce'] = 'nonce-wppilot_cloud_confirm_other';
        try {
            wppilot_cloud_handle_confirm();
            self::fail('a wrong nonce must stop the handler');
        } catch (WPPilot_Test_Halt $halt) {
            self::assertSame('die', $halt->kind);
        }
        self::assertSame([], WPPilot_Test_State::$http_posts);

        $_REQUEST['_wpnonce'] = 'nonce-wppilot_cloud_confirm_' . $state;
        self::queue(self::answer(200, ['site_id' => 42]));
        try {
            wppilot_cloud_handle_confirm();
            self::fail('the handler redirects');
        } catch (WPPilot_Test_Halt $halt) {
            self::assertSame('redirect', $halt->kind);
            self::assertStringContainsString('wppilot_cloud_result=connected', $halt->getMessage());
        }
        self::assertSame(42, wppilot_cloud_link()['site_id'] ?? null, 'a numeric site id keeps its type');
    }

    public function test_handlers_refuse_a_user_who_cannot_manage_wppilot(): void
    {
        WPPilot_Test_State::$capabilities = [];
        $this->expectException(WPPilot_Test_Halt::class);
        wppilot_cloud_handle_begin();
    }

    public function test_signed_body_leads_with_site_ts_and_nonce_and_verifies(): void
    {
        $keys = wppilot_cloud_keys();
        self::assertIsArray($keys);

        $body = wppilot_cloud_signed_body('site_abc', ['home_url' => 'https://example.test'], now: 1_760_000_000);
        $decoded = json_decode($body, associative: true);
        self::assertSame(['site_id', 'ts', 'nonce', 'home_url'], array_keys($decoded));
        self::assertSame(1_760_000_000, $decoded['ts']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $decoded['nonce'], 'b64url of 16 bytes');
        self::assertStringContainsString('"home_url":"https://example.test"', $body);

        $signature = base64_decode(wppilot_cloud_sign($body, $keys['secret']), true);
        self::assertIsString($signature);
        $public = (string) base64_decode($keys['public'], true);
        self::assertTrue(sodium_crypto_sign_verify_detached($signature, $body, $public));
        self::assertFalse(sodium_crypto_sign_verify_detached($signature, $body . ' ', $public));

        self::assertSame($keys, wppilot_cloud_keys(), 'the keypair is created once and reused');
    }

    public function test_heartbeat_is_signed_over_the_exact_bytes_sent(): void
    {
        $link = $this->pairedLink();
        WPPilot_Test_State::$options[WPPILOT_SAFETY_PROFILE_OPTION] = 'developer';

        wppilot_cloud_send_heartbeat();

        self::assertCount(1, WPPilot_Test_State::$http_posts);
        $post = WPPilot_Test_State::$http_posts[0];
        self::assertSame('https://app.gobuildwp.com/api/sites/heartbeat', $post['url']);
        $body = (string) $post['body'];
        $sent = self::sent(0);
        self::assertSame($link['site_id'], $sent['site_id']);
        self::assertEqualsWithDelta(time(), $sent['ts'], 5);
        self::assertSame(
            ['wp' => '7.0', 'php' => PHP_VERSION, 'plugin' => WPPILOT_VERSION, 'pro' => null],
            $sent['versions'],
        );
        self::assertSame('production', $sent['safety_profile'], 'the site profile lowered by the Cloud token ceiling');
        self::assertSame('https://example.test', $sent['home_url']);

        $keys = wppilot_cloud_keys(create: false);
        self::assertIsArray($keys);
        $signature = base64_decode((string) $post['args']['headers']['X-WPPilot-Signature'], true);
        self::assertIsString($signature);
        self::assertTrue(sodium_crypto_sign_verify_detached($signature, $body, (string) base64_decode($keys['public'], true)));
    }

    public function test_unknown_site_clears_the_link_and_revokes_the_token(): void
    {
        $link = $this->pairedLink();
        self::queue(self::answer(404, ['error' => 'unknown_site']));

        wppilot_cloud_send_heartbeat();

        self::assertNull(wppilot_cloud_link());
        self::assertFalse($this->tokenExists($link['token_id']));
        self::assertArrayNotHasKey(WPPILOT_CLOUD_HEARTBEAT_HOOK, WPPilot_Test_State::$cron);
    }

    public function test_a_cloud_outage_does_not_disconnect_the_site(): void
    {
        $link = $this->pairedLink();
        self::queue(self::answer(404, ['error' => 'not_found']), self::answer(503, []));

        wppilot_cloud_send_heartbeat();
        wppilot_cloud_send_heartbeat();

        self::assertSame($link, wppilot_cloud_link());
        self::assertTrue($this->tokenExists($link['token_id']));
    }

    public function test_an_unknown_site_answer_about_an_older_pairing_leaves_the_new_link(): void
    {
        $link = $this->pairedLink();

        wppilot_cloud_handle_signed_response(['status' => 404, 'body' => ['error' => 'unknown_site']], 'site_old');

        self::assertSame($link, wppilot_cloud_link());
    }

    public function test_a_heartbeat_for_a_revoked_token_disconnects_properly(): void
    {
        $link = $this->pairedLink();
        wppilot_token_revoke($link['token_id'], 1);
        wppilot_token_policy_cache($link['token_id'], forget: true);

        wppilot_cloud_send_heartbeat();

        self::assertNull(wppilot_cloud_link());
        self::assertCount(1, WPPilot_Test_State::$http_posts);
        self::assertSame('https://app.gobuildwp.com/api/sites/unlink', WPPilot_Test_State::$http_posts[0]['url']);
    }

    public function test_disconnect_revokes_first_then_tells_the_cloud(): void
    {
        $link = $this->pairedLink();
        // Paired by user 1, disconnected by user 2: the token is still revoked.
        WPPilot_Test_State::$current_user_id = 2;

        self::assertTrue(wppilot_cloud_disconnect());

        self::assertFalse($this->tokenExists($link['token_id']));
        self::assertNull(wppilot_cloud_link());
        self::assertArrayNotHasKey(WPPILOT_CLOUD_HEARTBEAT_HOOK, WPPilot_Test_State::$cron);
        self::assertSame('https://app.gobuildwp.com/api/sites/unlink', WPPilot_Test_State::$http_posts[0]['url']);
        $sent = self::sent(0);
        self::assertSame(['site_id', 'ts', 'nonce'], array_keys($sent));
        self::assertSame($link['site_id'], $sent['site_id']);
        self::assertFalse(wppilot_cloud_disconnect(), 'nothing left to disconnect');
    }

    public function test_a_site_paired_before_the_domain_move_keeps_its_cloud_host(): void
    {
        // A link written by a release whose default was app.wppilot.co. The new default must not
        // redirect its signed calls: that host still serves the same Cloud, and only it was told
        // about this pairing's key when the site was paired.
        $link = $this->pairedLink();
        $stored = WPPilot_Test_State::$options[WPPILOT_CLOUD_LINK_OPTION];
        $stored['cloud_url'] = 'https://app.wppilot.co';
        WPPilot_Test_State::$options[WPPILOT_CLOUD_LINK_OPTION] = $stored;
        self::assertSame('https://app.gobuildwp.com', wppilot_cloud_url(), 'new pairings use the new host');

        wppilot_cloud_send_heartbeat();
        self::assertTrue(wppilot_cloud_disconnect());

        self::assertCount(2, WPPilot_Test_State::$http_posts);
        self::assertSame('https://app.wppilot.co/api/sites/heartbeat', WPPilot_Test_State::$http_posts[0]['url']);
        self::assertSame('https://app.wppilot.co/api/sites/unlink', WPPilot_Test_State::$http_posts[1]['url']);
        self::assertSame($link['site_id'], self::sent(1)['site_id']);
    }

    public function test_cloud_routes_accept_only_the_linked_token(): void
    {
        $link = $this->pairedLink();

        record_oauth_identity(1, ['mcp'], 'token-' . $link['token_id'], via: 'token');
        self::assertTrue(wppilot_cloud_rest_permission());

        record_oauth_identity(1, ['mcp'], 'token-' . ($link['token_id'] + 1), via: 'token');
        $other = wppilot_cloud_rest_permission();
        self::assertInstanceOf(WP_Error::class, $other);
        self::assertSame(403, $other->get_error_data()['status']);

        // An OAuth client that happens to be named like a token is not a token.
        record_oauth_identity(1, ['mcp'], 'token-' . $link['token_id']);
        self::assertInstanceOf(WP_Error::class, wppilot_cloud_rest_permission());

        reset_request_context();
        self::assertInstanceOf(WP_Error::class, wppilot_cloud_rest_permission(), 'an administrator cookie');
        WPPilot_Test_State::$current_user_id = 0;
        $anonymous = wppilot_cloud_rest_permission();
        self::assertInstanceOf(WP_Error::class, $anonymous);
        self::assertSame(401, $anonymous->get_error_data()['status']);
    }

    public function test_the_middleware_lets_only_token_identities_reach_the_cloud_routes(): void
    {
        record_oauth_identity(1, ['mcp'], 'token-7', via: 'token');
        self::assertTrue(oauth_identity_may_use_route('/wppilot/v1/cloud/status', 'GET'));
        self::assertTrue(oauth_identity_may_use_route('/wppilot/v1/cloud/unlink', 'POST'));
        self::assertFalse(oauth_identity_may_use_route('/wppilot/v1/cloudy', 'GET'));
        self::assertFalse(oauth_identity_may_use_route('/wppilot/v1/settings', 'GET'));

        record_oauth_identity(1, ['mcp'], 'client-abc');
        self::assertFalse(oauth_identity_may_use_route('/wppilot/v1/cloud/status', 'GET'));

        reset_request_context();
        self::assertFalse(oauth_identity_may_use_route('/wppilot/v1/cloud/status', 'GET'));
    }

    public function test_status_describes_the_site(): void
    {
        $link = $this->pairedLink();

        $response = wppilot_cloud_rest_status();

        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame([
            'site_id' => $link['site_id'],
            'plugin_version' => WPPILOT_VERSION,
            'wp_version' => '7.0',
            'php_version' => PHP_VERSION,
            'pro_version' => null,
            'safety_profile' => 'production',
            'abilities_enabled' => true,
            'home_url' => 'https://example.test',
            'updates' => ['checked_at' => null, 'core' => null, 'plugins' => [], 'themes' => [], 'translations' => 0],
            'backup' => null,
            'policy' => [
                'manage' => ['tighten' => false, 'loosen' => false],
                'applied' => null,
                'current' => ['safety_profile' => 'production', 'confirmation_mode' => 'argument', 'disabled' => [], 'require_confirmation' => []],
            ],
        ], $response->data);
    }

    public function test_status_lists_pending_updates_from_the_transients_only(): void
    {
        $this->pairedLink();
        WPPilot_Test_State::$plugins = [
            'akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.3'],
            'hello.php' => ['Name' => 'Hello Dolly', 'Version' => '1.7.2'],
        ];
        WPPilot_Test_State::$options['auto_update_plugins'] = ['akismet/akismet.php'];
        $GLOBALS['wppilot_test_themes'] = ['twentytwentyfive' => ['Name' => 'Twenty Twenty-Five', 'Version' => '1.2']];
        $GLOBALS['wppilot_test_site_transients'] = [
            'update_plugins' => (object) [
                'last_checked' => 1_700_000_500,
                'response' => [
                    'akismet/akismet.php' => (object) ['new_version' => '5.4'],
                    'gone/gone.php' => (object) ['new_version' => '2.0'],
                ],
                'translations' => [['language' => 'de_DE']],
            ],
            'update_themes' => (object) [
                'last_checked' => 1_700_000_000,
                'response' => ['twentytwentyfive' => ['theme' => 'twentytwentyfive', 'new_version' => '1.3']],
            ],
            'update_core' => (object) [
                'last_checked' => 1_700_000_900,
                'updates' => [(object) ['response' => 'upgrade', 'current' => '7.1'], (object) ['response' => 'latest', 'current' => '7.0']],
            ],
        ];

        try {
            $updates = wppilot_cloud_rest_status()->data['updates'];
        } finally {
            unset($GLOBALS['wppilot_test_site_transients'], $GLOBALS['wppilot_test_themes']);
        }

        self::assertSame(1_700_000_000, $updates['checked_at'], 'the oldest check, so every list is at least that fresh');
        self::assertSame(['current' => '7.0', 'new_version' => '7.1'], $updates['core']);
        self::assertSame([
            ['file' => 'akismet/akismet.php', 'name' => 'Akismet', 'version' => '5.3', 'new_version' => '5.4', 'auto_update' => true],
        ], $updates['plugins'], 'an offer for a plugin no longer installed is dropped');
        self::assertSame([
            ['stylesheet' => 'twentytwentyfive', 'name' => 'Twenty Twenty-Five', 'version' => '1.2', 'new_version' => '1.3', 'auto_update' => false],
        ], $updates['themes']);
        self::assertSame(1, $updates['translations']);
        self::assertSame([], WPPilot_Test_State::$http_posts, 'a status call never contacts an update source');
    }

    /**
     * The summary reads the backup-status kit through its ability; wp_get_ability
     * is defined here only, in its own process, so no other test sees it.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_status_carries_the_newest_backup_and_whether_one_is_running(): void
    {
        $this->pairedLink();
        $GLOBALS['cloud_test_backup_status'] = [
            'active_providers' => ['updraftplus', 'backwpup'],
            'providers' => [
                ['provider' => 'updraftplus', 'label' => 'UpdraftPlus', 'readable' => true, 'running' => ['running' => true, 'jobs' => [['state' => 'queued']]], 'trigger' => ['supported' => true]],
                ['provider' => 'backwpup', 'label' => 'BackWPup', 'readable' => false, 'error' => 'unreadable'],
                'junk',
            ],
            'newest_successful_backup' => ['provider' => 'updraftplus', 'timestamp' => '1700000000', 'iso' => 'x'],
        ];
        eval('function wp_get_ability(string $name) { return $name === "wppilot/backup-status" ? new class { public function execute(array $input = []): mixed { return $GLOBALS["cloud_test_backup_status"]; } } : null; }');

        self::assertSame([
            'providers' => [
                ['provider' => 'updraftplus', 'label' => 'UpdraftPlus', 'readable' => true, 'startable' => true, 'running' => true],
                ['provider' => 'backwpup', 'label' => 'BackWPup', 'readable' => false, 'startable' => false, 'running' => false, 'trigger_reason' => 'unreadable'],
            ],
            'newest' => ['provider' => 'updraftplus', 'timestamp' => 1_700_000_000],
            'running' => true,
        ], wppilot_cloud_rest_status()->data['backup']);

        // An idle adapter still answers with its running array: only the flag counts.
        $GLOBALS['cloud_test_backup_status']['providers'][0]['running'] = ['running' => false, 'jobs' => []];
        self::assertFalse(wppilot_cloud_backup_summary()['running']);

        $GLOBALS['cloud_test_backup_status'] = new WP_Error('kit_down', 'no');
        self::assertNull(wppilot_cloud_backup_summary(), 'a failing ability reads as no summary, never an error in the status answer');
    }

    /**
     * Why a backup is stuck reaches Cloud (1.18.4): each provider's running flag, the site's own
     * reason it can't be started, and the export Pro started - never a path, archive name or job id.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_status_says_why_a_backup_is_stuck_without_naming_files(): void
    {
        $this->pairedLink();
        $GLOBALS['cloud_test_backup_status'] = [
            'active_providers' => ['ai1wm', 'duplicator'],
            'providers' => [
                [
                    'provider' => 'ai1wm',
                    'label' => 'All-in-One WP Migration',
                    'readable' => true,
                    'running' => ['running' => false, 'jobs' => []],
                    'trigger' => ['supported' => true, 'scopes' => []],
                    'export' => [
                        'state' => 'failed',
                        'reason' => 'All-in-One WP Migration\'s export failed at "archiving media": Could not open /var/www/html/wp-content/ai1wm-backups/site-20261009-abc123.wpress (C:\\sites\\x\\y.wpress) in job 6ac7aa9072d44; see wppilot/backup-status.',
                        'step' => 'archiving media',
                        'started' => ['time' => '2026-10-09T10:00:00+00:00', 'time_utc' => '2026-10-09T10:00:00Z'],
                        'last_progress' => ['time' => '2026-10-09T10:03:00+00:00', 'time_utc' => '2026-10-09T10:03:00Z'],
                        'finished' => ['time' => '2026-10-09T10:04:00+00:00', 'time_utc' => '2026-10-09T10:04:00Z'],
                        'backup_id' => 'deadbeefdeadbeef',
                    ],
                ],
                [
                    'provider' => 'duplicator',
                    'label' => 'Duplicator',
                    'readable' => true,
                    'running' => ['running' => false, 'jobs' => []],
                    'trigger' => ['supported' => false, 'reason' => 'Duplicator <b>cannot</b> be started from here.'],
                ],
            ],
            'newest_successful_backup' => null,
        ];
        eval('function wp_get_ability(string $name) { return $name === "wppilot/backup-status" ? new class { public function execute(array $input = []): mixed { return $GLOBALS["cloud_test_backup_status"]; } } : null; }');

        $backup = wppilot_cloud_rest_status()->data['backup'];

        self::assertSame([
            'provider' => 'ai1wm',
            'label' => 'All-in-One WP Migration',
            'readable' => true,
            'startable' => true,
            'running' => false,
            'export' => [
                'state' => 'failed',
                'reason' => 'All-in-One WP Migration\'s export failed at "archiving media": Could not open [path] ([path]) in job [id]; see wppilot/backup-status.',
                'step' => 'archiving media',
                'started_at' => 1_791_540_000,
                'last_progress_at' => 1_791_540_180,
                'finished_at' => 1_791_540_240,
            ],
        ], $backup['providers'][0], 'backup_id and every path, archive name and job id stay on the site');
        self::assertSame('Duplicator cannot be started from here.', $backup['providers'][1]['trigger_reason']);
        self::assertArrayNotHasKey('export', $backup['providers'][1], 'a provider that reports no export sends none');
        self::assertFalse($backup['running']);

        // A state Cloud does not know, or no export at all, reads as null rather than a guess.
        $GLOBALS['cloud_test_backup_status']['providers'][0]['export'] = ['state' => 'exploded', 'reason' => 'x'];
        self::assertNull(wppilot_cloud_backup_summary()['providers'][0]['export']);
        $GLOBALS['cloud_test_backup_status']['providers'][0]['export'] = null;
        self::assertNull(wppilot_cloud_backup_summary()['providers'][0]['export']);

        // A running export makes the whole summary running.
        $GLOBALS['cloud_test_backup_status']['providers'][0]['running'] = ['running' => true, 'jobs' => [['state' => 'running']]];
        $GLOBALS['cloud_test_backup_status']['providers'][0]['export'] = ['state' => 'running', 'reason' => null, 'step' => 'exporting the database', 'started' => ['time_utc' => '2026-10-09T10:00:00Z'], 'last_progress' => ['time_utc' => '2026-10-09T10:01:00Z'], 'finished' => null];
        $summary = wppilot_cloud_backup_summary();
        self::assertTrue($summary['running']);
        self::assertSame(['state' => 'running', 'reason' => null, 'step' => 'exporting the database', 'started_at' => 1_791_540_000, 'last_progress_at' => 1_791_540_060, 'finished_at' => null], $summary['providers'][0]['export']);
    }

    public function test_core_already_current_is_not_an_update(): void
    {
        $this->pairedLink();
        $GLOBALS['wppilot_test_site_transients'] = [
            'update_core' => (object) ['updates' => [(object) ['response' => 'upgrade', 'current' => '7.0']]],
        ];

        try {
            self::assertNull(wppilot_cloud_rest_status()->data['updates']['core']);
        } finally {
            unset($GLOBALS['wppilot_test_site_transients']);
        }
    }

    public function test_rest_unlink_revokes_the_token_without_calling_back(): void
    {
        $link = $this->pairedLink();

        $response = wppilot_cloud_rest_unlink();

        self::assertSame(['ok' => true], $response->data);
        self::assertSame(200, $response->status);
        self::assertFalse($this->tokenExists($link['token_id']));
        self::assertNull(wppilot_cloud_link());
        self::assertSame([], WPPilot_Test_State::$http_posts);
    }

    public function test_the_cloud_credential_gets_at_least_600_writes_a_minute(): void
    {
        $link = $this->pairedLink();
        $cloud = 'oauth:' . hash('sha256', 'token-' . $link['token_id']);

        self::assertSame(600, wppilot_cloud_rate_limit(120, $cloud));
        self::assertSame(1000, wppilot_cloud_rate_limit(1000, $cloud), 'never lowered');
        self::assertSame(0, wppilot_cloud_rate_limit(0, $cloud), 'a switched-off limit stays off');
        self::assertSame(120, wppilot_cloud_rate_limit(120, 'oauth:' . hash('sha256', 'token-999')));
        self::assertSame(120, wppilot_cloud_rate_limit(120, 'ap:uuid'));
        self::assertSame(120, wppilot_cloud_rate_limit(120), 'a caller that passes no credential');

        // Through the real filter: the credential now reaches it.
        remove_all_filters('wppilot_tool_call_rate_limit');
        add_filter('wppilot_tool_call_rate_limit', 'wppilot_cloud_rate_limit', 10, 2);
        try {
            self::assertSame(600, wppilot_rate_limit($cloud));
            self::assertSame(WPPILOT_RATE_DEFAULT_LIMIT, wppilot_rate_limit('user:1'));
            self::assertSame(WPPILOT_RATE_DEFAULT_LIMIT, wppilot_rate_limit());

            // A one-argument callback written before 1.17.0 still works.
            add_filter('wppilot_tool_call_rate_limit', static fn(int $limit): int => $limit + 1, 20);
            self::assertSame(601, wppilot_rate_limit($cloud));
        } finally {
            remove_all_filters('wppilot_tool_call_rate_limit');
        }
    }

    public function test_the_cloud_is_labelled_by_its_client_name_and_user_agent(): void
    {
        self::assertSame('GoBuildWP Cloud', wppilot_client_label('wppilot-cloud'));
        self::assertSame('GoBuildWP Cloud', wppilot_client_label('WPPilot-Cloud/1 (+https://app.wppilot.co)'));
        self::assertArrayNotHasKey('wppilot-cloud', wppilot_selectable_clients(), 'nothing to configure by hand');
    }

    public function test_the_cloud_user_agent_is_not_shown_beside_its_label(): void
    {
        self::assertSame('GoBuildWP Cloud', wppilot_client_display('GoBuildWP Cloud', 'WPPilot-Cloud/1 (+https://app.gobuildwp.com)'));
        self::assertSame('Cursor (cursor-vscode)', wppilot_client_display('Cursor', 'cursor-vscode'), 'a configurable client keeps its reported name');
        self::assertSame('Team laptop (WPPilot-Cloud/1)', wppilot_client_display('Team laptop', 'WPPilot-Cloud/1'), 'hidden only when it adds nothing to the label');
        self::assertSame('Cursor', wppilot_client_display('Cursor', ''));
    }

    public function test_an_upgrade_of_this_plugin_queues_a_heartbeat_while_connected(): void
    {
        wppilot_cloud_on_upgrade(null, ['type' => 'plugin', 'plugins' => ['wppilot/wppilot.php']]);
        self::assertSame([], $GLOBALS['wppilot_test_single_events'], 'not connected');

        $this->pairedLink();
        wppilot_cloud_on_upgrade(null, ['type' => 'plugin', 'plugins' => ['other/other.php']]);
        self::assertSame([], $GLOBALS['wppilot_test_single_events']);

        wppilot_cloud_on_upgrade(null, ['type' => 'plugin', 'plugins' => ['wppilot/wppilot.php']]);
        self::assertSame(WPPILOT_CLOUD_HEARTBEAT_HOOK, $GLOBALS['wppilot_test_single_events'][0]['hook'] ?? null);
    }

    public function test_a_single_or_auto_update_also_queues_a_heartbeat(): void
    {
        $this->pairedLink();
        wppilot_cloud_on_upgrade(null, ['type' => 'plugin', 'action' => 'update', 'plugin' => 'wppilot/wppilot.php']);
        self::assertSame(WPPILOT_CLOUD_HEARTBEAT_HOOK, $GLOBALS['wppilot_test_single_events'][0]['hook'] ?? null);
    }

    public function test_a_database_error_during_the_heartbeat_does_not_unpair_the_site(): void
    {
        global $wpdb;
        $link = $this->pairedLink();
        $table = wppilot_tokens_table();
        wppilot_token_policy_cache($link['token_id'], forget: true);
        $wpdb->query("ALTER TABLE {$table} RENAME TO {$table}_away");
        try {
            wppilot_cloud_send_heartbeat();
        } finally {
            $wpdb->query("ALTER TABLE {$table}_away RENAME TO {$table}");
            wppilot_token_policy_cache($link['token_id'], forget: true);
        }

        self::assertSame($link, wppilot_cloud_link(), 'still connected');
        self::assertTrue($this->tokenExists($link['token_id']));
        foreach (WPPilot_Test_State::$http_posts as $post) {
            self::assertStringNotContainsString('/api/sites/unlink', $post['url']);
        }
    }

    public function test_the_schedule_follows_the_link(): void
    {
        WPPilot_Test_State::$cron[WPPILOT_CLOUD_HEARTBEAT_HOOK] = time();
        wppilot_cloud_maintain_schedule();
        self::assertArrayNotHasKey(WPPILOT_CLOUD_HEARTBEAT_HOOK, WPPilot_Test_State::$cron, 'no link, no heartbeat');

        $this->pairedLink();
        unset(WPPilot_Test_State::$cron[WPPILOT_CLOUD_HEARTBEAT_HOOK]);
        WPPilot_Test_State::$options[WPPILOT_CLOUD_SEEN_VERSION_OPTION] = '1.0.0';
        wppilot_cloud_maintain_schedule();

        self::assertArrayHasKey(WPPILOT_CLOUD_HEARTBEAT_HOOK, WPPilot_Test_State::$cron);
        self::assertSame(WPPILOT_VERSION, WPPilot_Test_State::$options[WPPILOT_CLOUD_SEEN_VERSION_OPTION]);
        self::assertCount(1, $GLOBALS['wppilot_test_single_events'], 'the new version is reported once');
        wppilot_cloud_maintain_schedule();
        self::assertCount(1, $GLOBALS['wppilot_test_single_events']);
    }
}
