<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityTwoFactor {
    // User meta, transients and password hashing live in the kit's namespace so no global double is
    // needed. update_user_meta() honours $prev_value the way WordPress does: an UPDATE … WHERE
    // meta_value = <previous> that changes nothing (and returns false) when the value moved on.
    if (!function_exists(__NAMESPACE__ . '\get_user_meta')) {
        function get_user_meta(int $user_id, string $key = '', bool $single = false): mixed
        {
            return $GLOBALS['gobuildwp_2fa_test_meta'][$user_id][$key] ?? '';
        }

        function update_user_meta(int $user_id, string $key, mixed $value, mixed $prev_value = ''): bool
        {
            $current = $GLOBALS['gobuildwp_2fa_test_meta'][$user_id][$key] ?? null;
            if ($prev_value !== '' && $current !== $prev_value) {
                return false;
            }
            if ($current === $value) {
                return false;
            }
            $GLOBALS['gobuildwp_2fa_test_meta'][$user_id][$key] = $value;
            return true;
        }

        function delete_user_meta(int $user_id, string $key): bool
        {
            unset($GLOBALS['gobuildwp_2fa_test_meta'][$user_id][$key]);
            return true;
        }

        function get_transient(string $key): mixed
        {
            return $GLOBALS['gobuildwp_2fa_test_transients'][$key] ?? false;
        }

        function set_transient(string $key, mixed $value, int $expiration = 0): bool
        {
            $GLOBALS['gobuildwp_2fa_test_transients'][$key] = $value;
            return true;
        }

        function delete_transient(string $key): bool
        {
            unset($GLOBALS['gobuildwp_2fa_test_transients'][$key]);
            return true;
        }

        // A persistent object cache, switched on per test; off means the options-table path runs.
        function wp_using_ext_object_cache(): bool
        {
            return !empty($GLOBALS['gobuildwp_2fa_test_ext_cache']);
        }

        function wp_cache_add(string $key, mixed $value, string $group = '', int $expire = 0): bool
        {
            if (isset($GLOBALS['gobuildwp_2fa_test_cache'][$group][$key])) {
                return false;
            }
            $GLOBALS['gobuildwp_2fa_test_cache'][$group][$key] = $value;
            return true;
        }

        function wp_cache_incr(string $key, int $offset = 1, string $group = ''): int|false
        {
            if (!isset($GLOBALS['gobuildwp_2fa_test_cache'][$group][$key])) {
                return false;
            }
            return $GLOBALS['gobuildwp_2fa_test_cache'][$group][$key] = (int) $GLOBALS['gobuildwp_2fa_test_cache'][$group][$key] + $offset;
        }

        function wp_cache_get(string $key, string $group = ''): mixed
        {
            return $GLOBALS['gobuildwp_2fa_test_cache'][$group][$key] ?? false;
        }

        function wp_cache_delete(string $key, string $group = ''): bool
        {
            unset($GLOBALS['gobuildwp_2fa_test_cache'][$group][$key]);
            return true;
        }

        function wp_hash_password(string $password): string
        {
            return password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
        }

        function wp_check_password(string $password, string $hash, int $user_id = 0): bool
        {
            return password_verify($password, $hash);
        }
    }
}

namespace GoBuildWP\Tests\Unit\Kits\SecurityTwoFactor {

    require_once dirname(__DIR__, 4) . '/includes/kits/security-2fa/src/load.php';
    require_once dirname(__DIR__, 3) . '/doubles/sqlite-wpdb.php';

    use GoBuildWP\Kits\SecurityTwoFactor as TF;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WPPilot_Test_State;

    /**
     * Two-factor login: TOTP against the RFC 6238 vectors, the time window and replay refusal,
     * backup codes, secret encryption, the interim login token, the role policy, and the QR encoder.
     */
    final class TwoFactorTest extends TestCase
    {
        private const RFC_SHA1 = '12345678901234567890';
        private const RFC_SHA256 = '12345678901234567890123456789012';
        private const RFC_SHA512 = '1234567890123456789012345678901234567890123456789012345678901234';

        private mixed $savedWpdb = null;

        protected function setUp(): void
        {
            WPPilot_Test_State::$options = [];
            $GLOBALS['gobuildwp_2fa_test_meta'] = [];
            $GLOBALS['gobuildwp_2fa_test_transients'] = [];
            $GLOBALS['gobuildwp_2fa_test_cache'] = [];
            $GLOBALS['gobuildwp_2fa_test_ext_cache'] = false;
            $this->savedWpdb = $GLOBALS['wpdb'] ?? null;
            $wpdb = new \WPPilot_Test_Sqlite_Wpdb();
            $wpdb->query('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT NOT NULL UNIQUE, option_value TEXT NOT NULL, autoload TEXT NOT NULL)');
            $GLOBALS['wpdb'] = $wpdb;
        }

        protected function tearDown(): void
        {
            $GLOBALS['wpdb'] = $this->savedWpdb;
            $GLOBALS['gobuildwp_2fa_test_ext_cache'] = false;
            parent::tearDown();
        }

        private static function failsRow(int $user_id): ?string
        {
            return $GLOBALS['wpdb']->get_var("SELECT option_value FROM wp_options WHERE option_name = '" . TF\OPT_FAILS . $user_id . "'");
        }

        // ------------------------------------------------------------ TOTP

        /** @return iterable<string, array{int, string, string, string}> */
        public static function rfc6238Vectors(): iterable
        {
            // RFC 6238 appendix B: time, expected 8-digit code, algorithm, seed.
            $table = [
                [59, '94287082', '46119246', '90693936'],
                [1111111109, '07081804', '68084774', '25091201'],
                [1111111111, '14050471', '67062674', '99943326'],
                [1234567890, '89005924', '91819424', '93441116'],
                [2000000000, '69279037', '90698825', '38618901'],
                [20000000000, '65353130', '77737706', '47863826'],
            ];
            foreach ($table as [$time, $sha1, $sha256, $sha512]) {
                yield "sha1 @$time" => [$time, $sha1, 'sha1', self::RFC_SHA1];
                yield "sha256 @$time" => [$time, $sha256, 'sha256', self::RFC_SHA256];
                yield "sha512 @$time" => [$time, $sha512, 'sha512', self::RFC_SHA512];
            }
        }

        #[DataProvider('rfc6238Vectors')]
        public function test_totp_matches_rfc6238(int $time, string $expected, string $algo, string $seed): void
        {
            self::assertSame($expected, TF\totp($seed, $time, 8, $algo));
        }

        public function test_six_digit_code_is_the_low_digits(): void
        {
            self::assertSame('287082', TF\totp(self::RFC_SHA1, 59));
        }

        public function test_base32_round_trip_and_rfc4648_vectors(): void
        {
            self::assertSame('', TF\base32_encode(''));
            self::assertSame('MY', TF\base32_encode('f'));
            self::assertSame('MZXW6YTBOI', TF\base32_encode('foobar'));
            self::assertSame('foobar', TF\base32_decode('mzxw 6ytb-oi======'));
            self::assertNull(TF\base32_decode('MZXW1'));
            $secret = TF\new_secret();
            self::assertSame(20, strlen($secret));
            self::assertSame(32, strlen(TF\base32_encode($secret)));
            self::assertSame($secret, TF\base32_decode(TF\base32_encode($secret)));
        }

        public function test_window_accepts_one_step_either_side_only(): void
        {
            $now = 1_700_000_015;
            $step = TF\time_step($now);
            $secret = self::RFC_SHA1;
            self::assertSame($step, TF\verify_totp($secret, TF\hotp($secret, $step), $now));
            self::assertSame($step - 1, TF\verify_totp($secret, TF\hotp($secret, $step - 1), $now));
            self::assertSame($step + 1, TF\verify_totp($secret, TF\hotp($secret, $step + 1), $now));
            self::assertNull(TF\verify_totp($secret, TF\hotp($secret, $step - 2), $now));
            self::assertNull(TF\verify_totp($secret, TF\hotp($secret, $step + 2), $now));
            self::assertNull(TF\verify_totp($secret, 'abcdef', $now));
            self::assertNull(TF\verify_totp($secret, '12345', $now));
            self::assertSame($step, TF\verify_totp($secret, substr(TF\hotp($secret, $step), 0, 3) . ' ' . substr(TF\hotp($secret, $step), 3), $now));
        }

        public function test_verify_refuses_steps_at_or_before_the_last_used(): void
        {
            $now = 1_700_000_015;
            $step = TF\time_step($now);
            $code = TF\hotp(self::RFC_SHA1, $step);
            self::assertNull(TF\verify_totp(self::RFC_SHA1, $code, $now, $step));
            self::assertNull(TF\verify_totp(self::RFC_SHA1, TF\hotp(self::RFC_SHA1, $step - 1), $now, $step));
            self::assertSame($step + 1, TF\verify_totp(self::RFC_SHA1, TF\hotp(self::RFC_SHA1, $step + 1), $now, $step));
        }

        public function test_otpauth_uri(): void
        {
            $uri = TF\otpauth_uri('foobar', 'jane@example.com', 'My Shop: Main');
            self::assertSame('otpauth://totp/My%20Shop%20Main:jane%40example.com?secret=MZXW6YTBOI&issuer=My%20Shop%20Main&algorithm=SHA1&digits=6&period=30', $uri);
        }

        // ------------------------------------------------------------ per-user state

        public function test_enable_then_a_code_works_once(): void
        {
            $now = 1_700_000_015;
            $secret = TF\new_secret();
            $codes = TF\enable(7, $secret, TF\time_step($now) - 1, $now);
            self::assertCount(10, $codes);
            self::assertTrue(TF\is_enabled(7));
            self::assertSame($secret, TF\user_secret(7));

            $stored = (string) TF\get_user_meta(7, TF\META_SECRET, true);
            self::assertStringStartsWith('v1:', $stored);
            self::assertStringNotContainsString(TF\base32_encode($secret), $stored);
            self::assertStringNotContainsString($secret, (string) base64_decode(substr($stored, 3)));

            $code = TF\totp($secret, $now);
            self::assertTrue(TF\verify_user_totp(7, $code, $now));
            self::assertFalse(TF\verify_user_totp(7, $code, $now), 'the same code must not work twice');
            self::assertFalse(TF\verify_user_totp(7, TF\totp($secret, $now - 30), $now), 'an older code must not work after a newer one');
            self::assertTrue(TF\verify_user_totp(7, TF\totp($secret, $now + 30), $now + 30));
        }

        public function test_replay_is_refused_when_two_requests_race(): void
        {
            $now = 1_700_000_015;
            $secret = TF\new_secret();
            TF\enable(7, $secret, 0, $now);
            // Another request claimed this step after we read the last step but before we wrote.
            $last = (string) TF\get_user_meta(7, TF\META_LAST_STEP, true);
            $GLOBALS['gobuildwp_2fa_test_meta'][7][TF\META_LAST_STEP] = 's' . TF\time_step($now);
            self::assertFalse(TF\swap_meta(7, TF\META_LAST_STEP, $last, 's' . TF\time_step($now)));
            self::assertFalse(TF\verify_user_totp(7, TF\totp($secret, $now), $now));
        }

        public function test_backup_codes_work_once_each(): void
        {
            $codes = TF\enable(9, TF\new_secret(), 0, 1_700_000_000);
            self::assertCount(10, array_unique($codes));
            foreach ($codes as $code) {
                self::assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $code);
                self::assertStringNotContainsString($code, (string) TF\get_user_meta(9, TF\META_BACKUP, true));
            }
            self::assertTrue(TF\use_backup_code(9, strtoupper($codes[3])), 'case and dash do not matter');
            self::assertFalse(TF\use_backup_code(9, $codes[3]), 'a used code is gone');
            self::assertCount(9, TF\backup_hashes(9));
            self::assertFalse(TF\use_backup_code(9, 'aaaaa-aaaaa'));
            self::assertSame('backup', TF\verify_any(9, ' ' . $codes[0] . ' ', 1_700_000_000));
            foreach (array_slice($codes, 1) as $i => $code) {
                if ($i !== 2) {
                    self::assertTrue(TF\use_backup_code(9, $code));
                }
            }
            self::assertSame([], TF\backup_hashes(9));
            self::assertFalse(TF\use_backup_code(9, $codes[1]));
        }

        public function test_regenerating_backup_codes_invalidates_the_old_ones(): void
        {
            $old = TF\enable(9, TF\new_secret(), 0, 1_700_000_000);
            $new = TF\regenerate_backup_codes(9);
            self::assertFalse(TF\use_backup_code(9, $old[0]));
            self::assertTrue(TF\use_backup_code(9, $new[0]));
        }

        public function test_disable_removes_everything(): void
        {
            TF\enable(5, TF\new_secret(), 0, 1_700_000_000);
            TF\stash_codes_for_display(5, ['aaaaa-bbbbb']);
            TF\disable(5);
            self::assertFalse(TF\is_enabled(5));
            self::assertSame([], $GLOBALS['gobuildwp_2fa_test_meta'][5]);
            self::assertSame([], TF\take_codes_for_display(5));
        }

        public function test_codes_for_display_are_encrypted_and_shown_once(): void
        {
            TF\stash_codes_for_display(5, ['aaaaa-bbbbb', 'ccccc-ddddd']);
            $raw = (string) TF\get_transient(TF\TRANSIENT_SHOW . 5);
            self::assertStringNotContainsString('aaaaa', $raw);
            self::assertSame(['aaaaa-bbbbb', 'ccccc-ddddd'], TF\take_codes_for_display(5));
            self::assertSame([], TF\take_codes_for_display(5));
        }

        public function test_decrypt_rejects_tampering_and_foreign_values(): void
        {
            $box = TF\encrypt('secret');
            self::assertSame('secret', TF\decrypt($box));
            $raw = (string) base64_decode(substr($box, 3));
            $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
            self::assertNull(TF\decrypt('v1:' . base64_encode($raw)));
            self::assertNull(TF\decrypt('plain'));
            self::assertNull(TF\decrypt('v1:!!!'));
            self::assertNull(TF\decrypt('v1:' . base64_encode('short')));
        }

        public function test_pending_secret_survives_reloads_until_it_expires(): void
        {
            $first = TF\pending_secret(3, 1_000);
            self::assertSame($first, TF\pending_secret(3, 1_000 + 60));
            self::assertSame($first, TF\read_pending_secret(3, 1_000 + 60));
            self::assertNull(TF\read_pending_secret(3, 1_000 + TF\PENDING_TTL));
            self::assertNotSame($first, TF\pending_secret(3, 1_000 + TF\PENDING_TTL));
        }

        // ------------------------------------------------------------ interim login

        public function test_interim_login_token_round_trip(): void
        {
            $token = TF\open_login(4, 'challenge', ['remember' => true]);
            self::assertMatchesRegularExpression('/^4:[0-9a-f]{64}$/', $token);
            $state = TF\read_login($token);
            self::assertNotNull($state);
            self::assertSame(4, $state['user_id']);
            self::assertTrue($state['remember']);
            // The transient is named by a hash; the token itself is not stored.
            foreach (array_keys($GLOBALS['gobuildwp_2fa_test_transients']) as $key) {
                self::assertStringNotContainsString(explode(':', $token)[1], $key);
            }
            TF\close_login($token);
            self::assertNull(TF\read_login($token));
        }

        public function test_interim_login_token_is_bound_to_user_and_nonce(): void
        {
            $token = TF\open_login(4, 'challenge');
            [, $nonce] = explode(':', $token);
            self::assertNull(TF\read_login('5:' . $nonce));
            self::assertNull(TF\read_login('4:' . str_repeat('0', 64)));
            self::assertNull(TF\read_login('4:short'));
            self::assertNull(TF\read_login(''));
        }

        public function test_interim_login_token_expires(): void
        {
            $token = TF\open_login(4, 'challenge');
            self::assertNotNull(TF\read_login($token, time() + TF\LOGIN_TTL - 5));
            self::assertNull(TF\read_login($token, time() + TF\LOGIN_TTL + 1));
            $enroll = TF\open_login(4, 'enroll');
            self::assertNotNull(TF\read_login($enroll, time() + TF\LOGIN_TTL + 1), 'setting up the app gets longer');
            self::assertNull(TF\read_login($enroll, time() + TF\ENROLL_TTL + 1));
        }

        public function test_interim_login_allows_limited_attempts(): void
        {
            $token = TF\open_login(4, 'challenge');
            for ($i = 1; $i < TF\MAX_ATTEMPTS; $i++) {
                $state = TF\read_login($token);
                self::assertNotNull($state);
                self::assertTrue(TF\fail_login($token, $state));
            }
            $state = TF\read_login($token);
            self::assertNotNull($state);
            self::assertSame(TF\MAX_ATTEMPTS - 1, $state['attempts']);
            self::assertFalse(TF\fail_login($token, $state));
            self::assertNull(TF\read_login($token), 'the token is gone after its last attempt');
        }

        public function test_account_is_throttled_across_interim_logins(): void
        {
            self::assertFalse(TF\user_throttled(4));
            for ($i = 0; $i < TF\MAX_USER_FAILURES; $i++) {
                $token = TF\open_login(4, 'challenge');
                TF\fail_login($token, (array) TF\read_login($token));
            }
            self::assertTrue(TF\user_throttled(4));
            self::assertFalse(TF\user_throttled(5));
        }

        public function test_failure_count_is_one_atomic_row_per_account(): void
        {
            $now = 1_800_000_000;
            self::assertSame(1, TF\count_failure(4, $now));
            self::assertSame(2, TF\count_failure(4, $now + 1));
            // The increment is done by the database, not read-modify-write in PHP.
            $updates = array_values(array_filter($GLOBALS['wpdb']->queries, static fn(string $q): bool => str_starts_with($q, 'UPDATE ')));
            self::assertNotSame([], $updates);
            self::assertStringContainsString('CAST(option_value AS SIGNED) + 1', end($updates));
            self::assertSame((string) ($now * TF\FAIL_STAMP + 2), self::failsRow(4), 'the window keeps the first wrong code as its start');
            self::assertSame('off', $GLOBALS['wpdb']->get_var("SELECT autoload FROM wp_options WHERE option_name = '" . TF\OPT_FAILS . "4'"));
            self::assertStringStartsWith('gobuildwp_kit_2fa_', TF\OPT_FAILS, 'uninstall.php removes options under this prefix');
        }

        public function test_a_row_made_by_a_parallel_request_is_counted_against(): void
        {
            $now = 1_800_000_000;
            // A row another request already made is incremented in place, never overwritten.
            $GLOBALS['wpdb']->query("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('" . TF\OPT_FAILS . "4', '" . ($now * TF\FAIL_STAMP + 3) . "', 'off')");
            self::assertSame(4, TF\count_failure(4, $now + 10));
            self::assertSame(4, TF\failure_count(4, $now + 10));
        }

        public function test_failure_window_runs_out_and_restarts(): void
        {
            $now = 1_800_000_000;
            for ($i = 0; $i < TF\MAX_USER_FAILURES; $i++) {
                TF\count_failure(4, $now);
            }
            self::assertTrue(TF\user_throttled(4, $now + TF\FAIL_WINDOW - 1));
            self::assertFalse(TF\user_throttled(4, $now + TF\FAIL_WINDOW));
            self::assertSame(1, TF\count_failure(4, $now + TF\FAIL_WINDOW), 'a new window starts at one');
            self::assertFalse(TF\user_throttled(4, $now + TF\FAIL_WINDOW));
        }

        public function test_disable_clears_the_failure_count(): void
        {
            TF\count_failure(4);
            self::assertSame(1, TF\failure_count(4));
            TF\disable(4);
            self::assertSame(0, TF\failure_count(4));
            self::assertNull(self::failsRow(4));
        }

        public function test_failure_count_uses_the_object_cache_when_there_is_one(): void
        {
            $GLOBALS['gobuildwp_2fa_test_ext_cache'] = true;
            $before = count($GLOBALS['wpdb']->queries);
            for ($i = 0; $i < TF\MAX_USER_FAILURES; $i++) {
                self::assertSame($i + 1, TF\count_failure(4));
            }
            self::assertTrue(TF\user_throttled(4));
            self::assertFalse(TF\user_throttled(5));
            TF\clear_failures(4);
            self::assertFalse(TF\user_throttled(4));
            self::assertSame($before, count($GLOBALS['wpdb']->queries), 'no options-table queries');
        }

        // ------------------------------------------------------------ policy

        public function test_policy_defaults_to_off(): void
        {
            self::assertSame(['required_roles' => [], 'grace_logins' => 3], TF\policy(['administrator']));
            self::assertSame('none', TF\login_requirement(1, ['administrator'], TF\policy(['administrator'])));
        }

        public function test_sanitize_policy(): void
        {
            $known = ['administrator', 'editor'];
            $base = TF\default_policy();
            self::assertSame(['required_roles' => ['administrator'], 'grace_logins' => 0], TF\sanitize_policy(['required_roles' => ['administrator', 'administrator'], 'grace_logins' => 0], $base, $known));
            self::assertInstanceOf(WP_Error::class, TF\sanitize_policy(['required_roles' => ['ghost']], $base, $known));
            self::assertInstanceOf(WP_Error::class, TF\sanitize_policy(['required_roles' => 'administrator'], $base, $known));
            self::assertInstanceOf(WP_Error::class, TF\sanitize_policy(['grace_logins' => 11], $base, $known));
            self::assertInstanceOf(WP_Error::class, TF\sanitize_policy(['grace_logins' => '2'], $base, $known));
            self::assertInstanceOf(WP_Error::class, TF\sanitize_policy(['other' => true], $base, $known));
        }

        public function test_stored_policy_drops_roles_that_no_longer_exist(): void
        {
            WPPilot_Test_State::$options[TF\OPT_POLICY] = ['required_roles' => ['administrator', 'gone'], 'grace_logins' => 2];
            self::assertSame(['required_roles' => ['administrator'], 'grace_logins' => 2], TF\policy(['administrator']));
            WPPilot_Test_State::$options[TF\OPT_POLICY] = 'junk';
            self::assertSame(TF\default_policy(), TF\policy(['administrator']));
        }

        public function test_requirement_follows_policy_and_grace(): void
        {
            $policy = ['required_roles' => ['administrator'], 'grace_logins' => 2];
            self::assertSame('none', TF\login_requirement(1, ['editor'], $policy));
            self::assertSame('grace', TF\login_requirement(1, ['administrator'], $policy));
            TF\use_grace_login(1);
            self::assertSame(1, TF\grace_left(1, $policy));
            TF\use_grace_login(1);
            self::assertSame('enroll', TF\login_requirement(1, ['administrator'], $policy));
            self::assertSame('enroll', TF\login_requirement(1, ['administrator'], ['required_roles' => ['administrator'], 'grace_logins' => 0]));
            TF\enable(1, TF\new_secret(), 0, 1_700_000_000);
            self::assertSame('challenge', TF\login_requirement(1, ['administrator'], $policy));
            self::assertSame('challenge', TF\login_requirement(1, ['editor'], TF\default_policy()), 'a user who turned it on is always asked');
            self::assertSame([], array_intersect(array_keys($GLOBALS['gobuildwp_2fa_test_meta'][1]), [TF\META_GRACE_USED]), 'enabling clears the grace count');
        }

        public function test_user_summary_has_no_secrets(): void
        {
            $secret = TF\new_secret();
            $codes = TF\enable(8, $secret, 0, 1_700_000_000);
            TF\pending_secret(8, 1_700_000_000);
            $summary = TF\user_summary(8, 'jane', ['administrator'], ['required_roles' => ['administrator'], 'grace_logins' => 3]);
            self::assertSame(10, $summary['backup_codes_left']);
            self::assertTrue($summary['enabled']);
            self::assertTrue($summary['secret_readable']);
            self::assertTrue($summary['required_by_policy']);
            self::assertSame('2023-11-14T22:13:20+00:00', $summary['enabled_at']);
            $json = (string) json_encode($summary);
            self::assertStringNotContainsString(TF\base32_encode($secret), $json);
            self::assertStringNotContainsString('v1:', $json);
            self::assertStringNotContainsString('$2y$', $json);
            foreach ($codes as $code) {
                self::assertStringNotContainsString($code, $json);
            }
        }

        // ------------------------------------------------------------ QR

        public function test_qr_picks_the_smallest_version(): void
        {
            // Version 1-M holds 14 bytes; version 2-M 26.
            self::assertCount(21, (array) TF\qr_matrix(str_repeat('a', 14)));
            self::assertCount(25, (array) TF\qr_matrix(str_repeat('a', 15)));
            $uri = TF\otpauth_uri(TF\new_secret(), 'a-rather-long-user-name@example.com', 'A Site With A Long Name');
            $matrix = TF\qr_matrix($uri);
            self::assertIsArray($matrix);
            // About 190 bytes: version 10-M (57 modules), still an easy scan at 4 px a module.
            self::assertLessThanOrEqual(57, count($matrix));
            self::assertSame(0, (count($matrix) - 17) % 4, 'a QR side is 17 + 4 × version');
            self::assertNull(TF\qr_matrix(str_repeat('a', 2400)), 'over the version 40-M capacity');
        }

        public function test_qr_svg_has_finder_patterns_and_only_numbers(): void
        {
            $svg = TF\qr_svg('otpauth://totp/x?secret=MZXW6YTBOI');
            self::assertStringStartsWith('<svg', $svg);
            self::assertDoesNotMatchRegularExpression('/otpauth|secret/', $svg);
            $matrix = (array) TF\qr_matrix('hello');
            // Top-left finder: dark ring, light ring, dark 3x3 core.
            self::assertTrue($matrix[0][0]);
            self::assertTrue($matrix[0][6]);
            self::assertFalse($matrix[1][1]);
            self::assertTrue($matrix[3][3]);
            self::assertFalse($matrix[7][7]);
        }

        public function test_application_password_only_excuses_the_account_it_belongs_to(): void
        {
            $GLOBALS['gobuildwp_2fa_app_password_users'] = [];
            TF\remember_application_password_user(null);
            TF\remember_application_password_user('7');
            self::assertSame([], TF\application_password_users());
            if (class_exists('WP_User')) {
                $user = new \WP_User();
                $user->ID = 7;
                TF\remember_application_password_user($user);
                self::assertSame([7], TF\application_password_users());
                self::assertNotContains(8, TF\application_password_users());
            }
            $GLOBALS['gobuildwp_2fa_app_password_users'] = [];
        }
    }
}
