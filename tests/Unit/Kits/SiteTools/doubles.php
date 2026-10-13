<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * WordPress's cron, transient and Site Health functions, for the kit's own namespaces only.
 *
 * The kit calls them unqualified, so PHP looks in the calling namespace first; defining them there
 * gives these tests faithful doubles without changing what the rest of the suite sees. The cron
 * functions follow wp-includes/cron.php (WordPress 7.1): the same array shape, the same
 * md5(serialize($args)) keys, the same reschedule arithmetic and duplicate-event window.
 */

namespace WPPilot\Tests\Unit\Kits\SiteTools {
    final class SiteState
    {
        /** @var array<int, array<string, array<string, array<string, mixed>>>> */
        public static array $cron = [];

        /** @var array<string, mixed> */
        public static array $transients = [];

        public static int $user = 1;

        /** @var list<array{hook: string, args: array<array-key, mixed>, user: int, doing_cron: bool}> */
        public static array $fired = [];

        /** @var array<string, callable> hook => callback */
        public static array $listeners = [];

        /** @var array<string, array{interval: int, display: string}> */
        public static array $schedules = [];

        public static bool $objectCache = false;

        public static bool $flushGroup = false;

        /** @var list<string> */
        public static array $flushedGroups = [];

        /** @var list<string> */
        public static array $deletedTransients = [];

        public static bool $expiredDeleted = false;

        /** @var array<string, array<string, mixed>> */
        public static array $healthDirect = [];

        /** @var array<string, array<string, mixed>> */
        public static array $healthAsync = [];

        public static function reset(): void
        {
            self::$cron = [];
            self::$transients = [];
            self::$user = 1;
            self::$fired = [];
            self::$listeners = [];
            self::$schedules = [
                'hourly' => ['interval' => 3600, 'display' => 'Once Hourly'],
                'daily' => ['interval' => 86400, 'display' => 'Once Daily'],
            ];
            self::$objectCache = false;
            self::$flushGroup = false;
            self::$flushedGroups = [];
            self::$deletedTransients = [];
            self::$expiredDeleted = false;
            self::$healthDirect = [];
            self::$healthAsync = [];
        }

        /** @param array<array-key, mixed> $args */
        public static function schedule(int $timestamp, string $hook, array $args = [], string|false $schedule = false): string
        {
            $key = md5(serialize($args));
            self::$cron[$timestamp][$hook][$key] = ['schedule' => $schedule, 'args' => $args]
                + ($schedule !== false ? ['interval' => self::$schedules[$schedule]['interval']] : []);
            ksort(self::$cron);
            return $key;
        }
    }
}

namespace WPPilot\Kits\SiteTools\Cron {
    use WP_Error;
    use WPPilot\Tests\Unit\Kits\SiteTools\SiteState;

    if (!function_exists(__NAMESPACE__ . '\\_get_cron_array')) {
        function _get_cron_array(): array
        {
            return SiteState::$cron;
        }

        function wp_get_schedules(): array
        {
            return SiteState::$schedules;
        }

        function wp_get_scheduled_event(string $hook, array $args = [], ?int $timestamp = null): object|false
        {
            $key = md5(serialize($args));
            if ($timestamp === null) {
                foreach (SiteState::$cron as $at => $hooks) {
                    if (isset($hooks[$hook][$key])) {
                        $timestamp = $at;
                        break;
                    }
                }
                if ($timestamp === null) {
                    return false;
                }
            } elseif (!isset(SiteState::$cron[$timestamp][$hook][$key])) {
                return false;
            }
            $stored = SiteState::$cron[$timestamp][$hook][$key];
            $event = (object) ['hook' => $hook, 'timestamp' => $timestamp, 'schedule' => $stored['schedule'], 'args' => $args];
            if (isset($stored['interval'])) {
                $event->interval = $stored['interval'];
            }
            return $event;
        }

        function wp_next_scheduled(string $hook, array $args = []): int|false
        {
            $event = wp_get_scheduled_event($hook, $args);
            return $event === false ? false : (int) $event->timestamp;
        }

        function wp_schedule_single_event(int $timestamp, string $hook, array $args = [], bool $wp_error = false): bool|WP_Error
        {
            $key = md5(serialize($args));
            $min = $timestamp < time() + 600 ? 0 : $timestamp - 600;
            $max = $timestamp < time() ? time() + 600 : $timestamp + 600;
            foreach (SiteState::$cron as $at => $hooks) {
                if ($at >= $min && $at <= $max && isset($hooks[$hook][$key])) {
                    return $wp_error ? new WP_Error('duplicate_event', 'A duplicate event already exists.') : false;
                }
            }
            SiteState::schedule($timestamp, $hook, $args);
            return true;
        }

        function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = [], bool $wp_error = false): bool|WP_Error
        {
            if (!isset(SiteState::$schedules[$recurrence])) {
                return $wp_error ? new WP_Error('invalid_schedule', 'Event schedule does not exist.') : false;
            }
            SiteState::schedule($timestamp, $hook, $args, $recurrence);
            return true;
        }

        function wp_reschedule_event(int $timestamp, string $recurrence, string $hook, array $args = [], bool $wp_error = false): bool|WP_Error
        {
            $interval = SiteState::$schedules[$recurrence]['interval'] ?? 0;
            if ($interval === 0) {
                return $wp_error ? new WP_Error('invalid_schedule', 'Event schedule does not exist.') : false;
            }
            $now = time();
            $next = $timestamp >= $now ? $now + $interval : $now + ($interval - (($now - $timestamp) % $interval));
            return wp_schedule_event($next, $recurrence, $hook, $args, $wp_error);
        }

        function wp_unschedule_event(int $timestamp, string $hook, array $args = [], bool $wp_error = false): bool|WP_Error
        {
            $key = md5(serialize($args));
            unset(SiteState::$cron[$timestamp][$hook][$key]);
            if (empty(SiteState::$cron[$timestamp][$hook])) {
                unset(SiteState::$cron[$timestamp][$hook]);
            }
            if (empty(SiteState::$cron[$timestamp])) {
                unset(SiteState::$cron[$timestamp]);
            }
            return true;
        }

        function has_action(string $hook): bool|int
        {
            return isset(SiteState::$listeners[$hook]) ? 10 : false;
        }

        function do_action_ref_array(string $hook, array $args): void
        {
            SiteState::$fired[] = [
                'hook' => $hook,
                'args' => $args,
                'user' => SiteState::$user,
                'doing_cron' => (bool) apply_filters('wp_doing_cron', false),
            ];
            if (isset(SiteState::$listeners[$hook])) {
                (SiteState::$listeners[$hook])(...array_values($args));
            }
        }

        function get_transient(string $name): mixed
        {
            return SiteState::$transients[$name] ?? false;
        }

        function set_transient(string $name, mixed $value): bool
        {
            SiteState::$transients[$name] = $value;
            return true;
        }

        function delete_transient(string $name): bool
        {
            unset(SiteState::$transients[$name]);
            return true;
        }

        function get_current_user_id(): int
        {
            return SiteState::$user;
        }

        function wp_set_current_user(int $id): object
        {
            SiteState::$user = $id;
            return (object) ['ID' => $id];
        }

        function remove_filter(string $hook, mixed $callback): bool
        {
            foreach ($GLOBALS['wp_filter'][$hook] ?? [] as $priority => $entries) {
                foreach ($entries as $index => $entry) {
                    if ($entry['callback'] === $callback) {
                        unset($GLOBALS['wp_filter'][$hook][$priority][$index]);
                    }
                }
            }
            return true;
        }
    }
}

namespace WPPilot\Kits\SiteTools\Transients {
    use WPPilot\Tests\Unit\Kits\SiteTools\SiteState;

    if (!function_exists(__NAMESPACE__ . '\\wp_using_ext_object_cache')) {
        function wp_using_ext_object_cache(): bool
        {
            return SiteState::$objectCache;
        }

        function delete_expired_transients(bool $force_db = false): void
        {
            SiteState::$expiredDeleted = $force_db;
            $GLOBALS['wpdb']->expired = 0;
        }

        function delete_transient(string $name): bool
        {
            SiteState::$deletedTransients[] = $name;
            return true;
        }

        function delete_site_transient(string $name): bool
        {
            SiteState::$deletedTransients[] = 'site:' . $name;
            return true;
        }

        function delete_option(string $name): bool
        {
            SiteState::$deletedTransients[] = 'option:' . $name;
            return !str_contains($name, 'timeout_');
        }

        function wp_cache_supports(string $feature): bool
        {
            return $feature === 'flush_group' && SiteState::$flushGroup;
        }

        function wp_cache_flush_group(string $group): bool
        {
            SiteState::$flushedGroups[] = $group;
            return true;
        }
    }
}

namespace WPPilot\Kits\SiteTools\Options {
    if (!function_exists(__NAMESPACE__ . '\\is_serialized')) {
        function is_serialized(string $data, bool $strict = true): bool
        {
            return preg_match('/^(a|O|s|i|d|b):/', $data) === 1 || $data === 'N;';
        }

        function wp_autoload_values_to_autoload(): array
        {
            return ['yes', 'on', 'auto-on', 'auto'];
        }
    }
}

namespace {
    use WPPilot\Tests\Unit\Kits\SiteTools\SiteState;

    if (!defined('MINUTE_IN_SECONDS')) {
        define('MINUTE_IN_SECONDS', 60);
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    if (!class_exists('WP_Site_Health')) {
        /**
         * The two members the kit uses; the real class is in wp-admin/includes and runs HTTP.
         */
        final class WP_Site_Health
        {
            private static ?self $instance = null;

            public static function get_instance(): self
            {
                return self::$instance ??= new self();
            }

            /** @return array{direct: array<string, mixed>, async: array<string, mixed>} */
            public static function get_tests(): array
            {
                return ['direct' => SiteState::$healthDirect, 'async' => SiteState::$healthAsync];
            }

            /** @return array<string, mixed> */
            public function get_test_php_version(): array
            {
                return [
                    'label' => 'Your site is running the current version of PHP (8.3)',
                    'status' => 'good',
                    'badge' => ['label' => 'Performance', 'color' => 'blue'],
                    'description' => '<p>PHP is one of the programming languages used to build WordPress &amp; its plugins.</p>',
                    'actions' => '<p><a href="https://wordpress.org/support/update-php/">Learn more</a></p>',
                    'test' => 'php_version',
                ];
            }
        }
    }
}
