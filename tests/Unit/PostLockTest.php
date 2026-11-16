<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace {
    if (!function_exists('get_userdata')) {
        /** Display names for the lock holders the test sets up; unknown ids are absent users. */
        function get_userdata(int $id): object|false
        {
            $names = $GLOBALS['wppilot_test_user_names'] ?? [];

            return isset($names[$id]) ? (object) ['ID' => $id, 'display_name' => $names[$id]] : false;
        }
    }
}

namespace WPPilot\Tests\Unit {

    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WPPilot_Test_State;

    /**
     * Edit-lock respect: an agent write refuses while another person has the post open.
     */
    final class PostLockTest extends TestCase
    {
        private const POST = 501;

        /** @var array<int|string, mixed> */
        private array $savedMeta = [];

        private int $savedUser = 0;

        protected function setUp(): void
        {
            $this->savedMeta = WPPilot_Test_State::$post_meta;
            $this->savedUser = WPPilot_Test_State::$current_user_id;
            WPPilot_Test_State::$current_user_id = 7;
            $GLOBALS['wppilot_test_user_names'] = [9 => 'Dana Editor'];
        }

        protected function tearDown(): void
        {
            WPPilot_Test_State::$post_meta = $this->savedMeta;
            WPPilot_Test_State::$current_user_id = $this->savedUser;
            unset($GLOBALS['wppilot_test_user_names']);
        }

        private function lock(int $age, int $user): void
        {
            WPPilot_Test_State::$post_meta[self::POST]['_edit_lock'] = [(time() - $age) . ':' . $user];
        }

        public function test_fresh_lock_by_another_user_refuses_with_their_name(): void
        {
            $this->lock(10, 9);

            $error = wppilot_post_lock_guard(self::POST);

            $this->assertInstanceOf(WP_Error::class, $error);
            $this->assertSame('post_locked', $error->get_error_code());
            $this->assertStringContainsString('Dana Editor', $error->get_error_message());
            $this->assertStringContainsString('wait_seconds', $error->get_error_message());
            $data = $error->get_error_data();
            $this->assertSame(409, $data['status']);
            $this->assertSame('Dana Editor', $data['locked_by']);
            $this->assertSame(9, $data['locked_by_user_id']);
            $this->assertSame(0, $data['waited_seconds']);
        }

        public function test_stale_lock_does_not_block(): void
        {
            $this->lock(151, 9);

            $this->assertNull(wppilot_post_lock_holder(self::POST));
            $this->assertNull(wppilot_post_lock_guard(self::POST));
        }

        public function test_own_lock_does_not_block(): void
        {
            $this->lock(5, 7);

            $this->assertNull(wppilot_post_lock_guard(self::POST));
        }

        public function test_no_lock_or_userless_lock_does_not_block(): void
        {
            $this->assertNull(wppilot_post_lock_guard(self::POST));

            WPPilot_Test_State::$post_meta[self::POST]['_edit_lock'] = [(string) time()];
            $this->assertNull(wppilot_post_lock_guard(self::POST));
        }

        public function test_unknown_holder_is_named_generically(): void
        {
            $this->lock(5, 404);

            $error = wppilot_post_lock_guard(self::POST);

            $this->assertInstanceOf(WP_Error::class, $error);
            $this->assertSame('another user', $error->get_error_data()['locked_by']);
        }

        public function test_wait_returns_once_the_lock_clears(): void
        {
            $this->lock(5, 9);
            $slept = [];
            $sleep = function (int $seconds) use (&$slept): void {
                $slept[] = $seconds;
                if (count($slept) === 2) {
                    // The editor closed: the heartbeat stops and the person's tab releases the lock.
                    unset(WPPilot_Test_State::$post_meta[self::POST]['_edit_lock']);
                }
            };

            $this->assertNull(wppilot_post_lock_guard(self::POST, 30, $sleep));
            $this->assertSame([5, 5], $slept);
        }

        public function test_wait_gives_up_after_the_requested_seconds(): void
        {
            $this->lock(5, 9);
            $slept = [];
            $sleep = function (int $seconds) use (&$slept): void {
                $slept[] = $seconds;
            };

            $error = wppilot_post_lock_guard(self::POST, 12, $sleep);

            $this->assertInstanceOf(WP_Error::class, $error);
            $this->assertSame([5, 5, 2], $slept);
            $this->assertSame(12, $error->get_error_data()['waited_seconds']);
        }

        public function test_wait_input_is_clamped(): void
        {
            $this->assertSame(0, wppilot_post_lock_wait_seconds(-5));
            $this->assertSame(120, wppilot_post_lock_wait_seconds(999));
            $this->assertSame(30, wppilot_post_lock_wait_seconds('30'));
            $this->assertSame(0, wppilot_post_lock_wait_seconds('soon'));
            $this->assertSame(0, wppilot_post_lock_wait_seconds(null));
            $this->assertSame(120, wppilot_post_lock_wait_schema()['maximum']);
        }
    }
}
