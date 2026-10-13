<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * WordPress, WP-Cron, the clock and the audits, stubbed down to the calls the scheduled-audits kit
 * makes.
 *
 * The kit calls WordPress unqualified, so PHP looks in the kit's namespace first; defining the
 * doubles there gives these tests options that fire their change hooks, a cron list keyed by
 * arguments and a clock the tests move, without changing what the rest of the suite sees. The few
 * functions the kit first checks with function_exists() have to be global; they answer from this
 * state only while Site::$active is set, and otherwise as if the site had nothing scheduled.
 */

namespace WPPilot\Tests\Unit\Kits\ScheduledAudits {
    use WP_Error;
    use WPPilot\Kits\Runtime\Host;
    use WPPilot\Kits\Runtime\Jobs;
    use WPPilot\Kits\Runtime\Ledger;

    final class Site
    {
        public static bool $active = false;

        public static int $now = 0;

        /** @var array<string, mixed> */
        public static array $options = [];

        /** @var list<array{timestamp: int, hook: string, args: array<array-key, mixed>, schedule: string|false}> */
        public static array $cron = [];

        /** @var list<array{to: string, subject: string, body: string}> */
        public static array $mail = [];

        public static int $user = 1;

        /** @var list<array<array-key, mixed>> */
        public static array $calls = [];

        public static string $tz = 'Asia/Karachi';

        /** @var array<int, array{email: string, name: string, admin: bool}> */
        public static array $users = [];

        /** @var array<string, list<callable>> Kept across tests: the kit registers its hooks once per process. */
        public static array $actions = [];

        /** @var array<string, array{meta: array<string, mixed>, schema: array<string, mixed>, run: callable}> */
        public static array $abilities = [];

        public static int $uuid = 0;

        /** @var array<string, array<string, mixed>> What the kit's ability files registered. */
        public static array $registered = [];

        public static function reset(): void
        {
            self::$active = true;
            self::$now = (int) strtotime('2026-09-28T10:00:00Z'); // A Monday.
            self::$options = [];
            self::$cron = [];
            self::$mail = [];
            self::$user = 1;
            self::$calls = [];
            self::$tz = 'Asia/Karachi';
            self::$uuid = 0;
            self::$users = [
                1 => ['email' => 'owner@example.test', 'name' => 'Owner', 'admin' => true],
                2 => ['email' => 'editor@example.test', 'name' => 'Editor', 'admin' => false],
                3 => ['email' => 'second@example.test', 'name' => 'Second Admin', 'admin' => true],
            ];
            self::$abilities = [];
        }

        public static function fire(string $hook, mixed ...$args): void
        {
            foreach (self::$actions[$hook] ?? [] as $callback) {
                $callback(...$args);
            }
        }
    }

    final class TestLedger implements Ledger
    {
        /** @var list<array<string, mixed>> */
        public array $rows = [];

        /** @var array<string, callable> */
        public array $strategies = [];

        public function capture_for(string $ability_name, callable $capture): void
        {
        }

        /** @param list<array<string, mixed>> $items */
        public function record_items(string $ability_name, array $items, ?string $group = null): array
        {
            $ids = [];
            foreach ($items as $item) {
                $this->rows[] = array_merge(['ability' => $ability_name], $item);
                $ids[] = 'change-' . count($this->rows);
            }
            return ['group' => 'g', 'change_ids' => $ids, 'without_before_image' => 0];
        }

        public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
        {
            $this->strategies[$type] = $restore;
            return true;
        }

        public function query(array $filters = []): array
        {
            return [];
        }

        public function export_row(array $entry): array
        {
            return $entry;
        }

        public function snapshot_budget(): int
        {
            return 1048576;
        }

        public function download_url(): string
        {
            return '';
        }
    }

    final class TestHost implements Host
    {
        public TestLedger $ledger;

        /** @var array<string, mixed> */
        public array $extensions = [];

        public function __construct()
        {
            $this->ledger = new TestLedger();
        }

        public function id(): string
        {
            return 'test';
        }

        public function can_manage(): bool
        {
            return true;
        }

        public function is_enabled(): bool
        {
            return true;
        }

        public function safety_profile(): string
        {
            return 'production';
        }

        public function ledger(): Ledger
        {
            return $this->ledger;
        }

        public function jobs(): Jobs
        {
            throw new \LogicException('Routines use no jobs of their own.');
        }

        /** The runner calls the stubbed audits; other points answer from $extensions. */
        public function extension(string $point): mixed
        {
            if ($point === 'ability-runner') {
                return static function (\WP_Ability $ability, mixed $input): mixed {
                    $entry = Site::$abilities[$ability->get_name()] ?? null;
                    return $entry === null ? new WP_Error('missing', 'gone') : ($entry['run'])($input);
                };
            }
            return $this->extensions[$point] ?? null;
        }

        public function admin_parent_slug(): string
        {
            return 'tools.php';
        }

        public function confirm_guard(string $ability_name, array $input): bool|WP_Error
        {
            return ($input['confirm'] ?? false) === true ? true : new WP_Error('confirm_required', 'Confirm first.');
        }
    }
}

namespace {
    use WPPilot\Tests\Unit\Kits\ScheduledAudits\Site;

    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }

    if (!class_exists('WP_User')) {
        class WP_User
        {
            public function __construct(public int $ID = 0, public string $user_email = '', public string $display_name = '')
            {
            }
        }
    }

    if (!function_exists('rest_validate_value_from_schema')) {
        /** Only what the kit relies on: an object schema refuses a property it does not declare. */
        function rest_validate_value_from_schema(mixed $value, array $schema, string $param = ''): bool|WP_Error
        {
            foreach (array_keys(is_array($value) ? $value : []) as $key) {
                if (!isset($schema['properties'][$key])) {
                    return new WP_Error('rest_additional_properties_forbidden', sprintf('%s is not a valid property of Object.', $key));
                }
            }
            return true;
        }
    }

    if (!function_exists('get_nav_menu_locations')) {
        /** @return array<string, int> */
        function get_nav_menu_locations(): array
        {
            return Site::$active ? ['primary' => 7] : [];
        }
    }

    if (!function_exists('_get_cron_array')) {
        /** @return array<int, array<string, array<string, array<string, mixed>>>> */
        function _get_cron_array(): array
        {
            if (!Site::$active) {
                return [];
            }
            $crons = [];
            foreach (Site::$cron as $index => $event) {
                $crons[$event['timestamp']][$event['hook']]['k' . $index] = ['args' => $event['args'], 'schedule' => $event['schedule']];
            }
            return $crons;
        }
    }

    if (!function_exists('spawn_cron')) {
        function spawn_cron(int $gmt_time = 0): bool
        {
            return false;
        }
    }
}

namespace WPPilot\Kits\ScheduledAudits {
    use DateTimeImmutable;
    use DateTimeZone;
    use WPPilot\Tests\Unit\Kits\ScheduledAudits\Site;

    function time(): int
    {
        return Site::$now;
    }

    /**
     * Keeps what the kit's ability files register and passes the call on to whatever the harness
     * provides, so a test can tell the kit's registrations from a name another plugin claimed.
     *
     * @param array<string, mixed> $args
     */
    function wp_register_ability(string $name, array $args): mixed
    {
        Site::$registered[$name] = $args;
        return \wp_register_ability($name, $args);
    }

    function microtime(bool $as_float = false): float
    {
        return 0.0;
    }

    function get_option(string $name, mixed $default = false): mixed
    {
        return array_key_exists($name, Site::$options) ? Site::$options[$name] : $default;
    }

    function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
    {
        if (array_key_exists($name, Site::$options)) {
            return false;
        }
        Site::$options[$name] = $value;
        Site::fire('add_option_' . $name, $name, $value);
        return true;
    }

    function update_option(string $name, mixed $value, mixed $autoload = null): bool
    {
        if (!array_key_exists($name, Site::$options)) {
            return add_option($name, $value);
        }
        $old = Site::$options[$name];
        if ($old === $value) {
            return false;
        }
        Site::$options[$name] = $value;
        Site::fire('update_option_' . $name, $old, $value, $name);
        return true;
    }

    function delete_option(string $name): bool
    {
        if (!array_key_exists($name, Site::$options)) {
            return false;
        }
        unset(Site::$options[$name]);
        Site::fire('delete_option_' . $name, $name);
        return true;
    }

    function wp_cache_delete(int|string $key, string $group = ''): bool
    {
        return true;
    }

    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        Site::$actions[$hook][] = $callback;
        return true;
    }

    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $hook === 'wppilot_kit_routines_report_url'
            ? 'https://site.test/wp-admin/admin.php?page=routines&routine=' . (string) $args[0]
            : $value;
    }

    function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
    {
        Site::$cron[] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'schedule' => false];
        return true;
    }

    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
    {
        Site::$cron[] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'schedule' => $recurrence];
        return true;
    }

    function wp_clear_scheduled_hook(string $hook, array $args = []): int
    {
        $before = count(Site::$cron);
        Site::$cron = array_values(array_filter(
            Site::$cron,
            static fn(array $event): bool => !($event['hook'] === $hook && $event['args'] === $args),
        ));
        return $before - count(Site::$cron);
    }

    function wp_next_scheduled(string $hook, array $args = []): int|false
    {
        $times = [];
        foreach (Site::$cron as $event) {
            if ($event['hook'] === $hook && $event['args'] === $args) {
                $times[] = $event['timestamp'];
            }
        }
        return $times === [] ? false : min($times);
    }

    function wp_timezone(): DateTimeZone
    {
        return new DateTimeZone(Site::$tz);
    }

    function wp_timezone_string(): string
    {
        return Site::$tz;
    }

    function wp_date(string $format, int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(wp_timezone())->format($format);
    }

    function home_url(string $path = ''): string
    {
        return 'https://site.test' . $path;
    }

    function admin_url(string $path = ''): string
    {
        return 'https://site.test/wp-admin/' . $path;
    }

    function wp_parse_url(string $url): array|false
    {
        return parse_url($url);
    }

    function sanitize_text_field(string $text): string
    {
        return trim(strip_tags($text));
    }

    function remove_accents(string $text): string
    {
        return $text;
    }

    function wp_json_encode(mixed $value): string|false
    {
        return json_encode($value);
    }

    function wp_generate_uuid4(): string
    {
        return 'uuid-' . (++Site::$uuid);
    }

    function get_userdata(int $id): \WP_User|false
    {
        $user = Site::$users[$id] ?? null;
        return $user === null ? false : new \WP_User($id, $user['email'], $user['name']);
    }

    function user_can(\WP_User $user, string $capability): bool
    {
        return (Site::$users[$user->ID]['admin'] ?? false) === true;
    }

    function is_multisite(): bool
    {
        return false;
    }

    function is_super_admin(int $id = 0): bool
    {
        return false;
    }

    function get_current_user_id(): int
    {
        return Site::$user;
    }

    function wp_set_current_user(int $id): void
    {
        Site::$user = $id;
    }

    /** @return list<\WP_User> */
    function get_users(array $args = []): array
    {
        $admins = [];
        foreach (Site::$users as $id => $user) {
            if ($user['admin']) {
                $admins[] = new \WP_User($id, $user['email'], $user['name']);
            }
        }
        return $admins;
    }

    function is_email(string $email): bool
    {
        return str_contains($email, '@');
    }

    function wp_mail(string $to, string $subject, string $body): bool
    {
        Site::$mail[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
        return true;
    }

    function get_bloginfo(string $show = ''): string
    {
        return 'Test Site';
    }

    function wp_specialchars_decode(string $text, int $quote_style = 0): string
    {
        return $text;
    }

    function wp_get_ability(string $name): ?\WP_Ability
    {
        $entry = Site::$abilities[$name] ?? null;
        return $entry === null ? null : new \WP_Ability($name, $entry['meta'], $entry['schema']);
    }

    /** @return list<object> */
    function wp_get_nav_menu_items(int $menu): array
    {
        return [
            (object) ['type' => 'post_type', 'object' => 'page', 'object_id' => 12],
            (object) ['type' => 'custom', 'object' => 'custom', 'object_id' => 0],
        ];
    }

    /** @return list<int> */
    function get_posts(array $args = []): array
    {
        return [12, 14, 15];
    }

    function get_post_status(int $id): string
    {
        return $id === 15 ? 'draft' : 'publish';
    }

    function get_permalink(int $id): string
    {
        return 'https://site.test/page-' . $id . '/';
    }
}
