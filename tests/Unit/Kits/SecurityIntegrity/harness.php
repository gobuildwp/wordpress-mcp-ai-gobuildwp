<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Shared set-up for the security-integrity kit tests: WordPress constants, network and transient
 * doubles in the kit's namespace (so no test ever reaches the network), a scripted $wpdb, a host
 * that runs the real job runner, and a throwaway WordPress folder tree on disk.
 */

namespace {
    foreach (['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400] as $name => $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    // WP-Cron's storage and the post cache, as the fixes reach them.
    if (!function_exists('_get_cron_array')) {
        function _get_cron_array(): array|false
        {
            return \GoBuildWP\Kits\SecurityIntegrity\TestNet::$cron;
        }

        function _set_cron_array(array $cron, bool $wp_error = false): bool
        {
            if (\GoBuildWP\Kits\SecurityIntegrity\TestNet::$cron_fails) {
                return false;
            }
            \GoBuildWP\Kits\SecurityIntegrity\TestNet::$cron = $cron;
            return true;
        }
    }
    if (!function_exists('clean_post_cache')) {
        function clean_post_cache(int $post_id): void {}
    }
    // The runner schedules its next step through WP-Cron.
    if (!function_exists('wp_schedule_single_event') && is_file(dirname(__DIR__, 3) . '/doubles/cloud.php')) {
        require_once dirname(__DIR__, 3) . '/doubles/cloud.php';
    }
    require_once dirname(__DIR__, 4) . '/includes/kits/_runtime/runtime.php';
    if (!class_exists('Kit_Test_Site')) {
        require_once dirname(__DIR__, 3) . '/doubles/kit-site.php';
    }
}

namespace WPPilot\Kits\Runtime {
    // The runner steps a job as its owner; WordPress's own function is not doubled globally.
    if (!function_exists(__NAMESPACE__ . '\wp_set_current_user')) {
        function wp_set_current_user(int $id): void
        {
            \Kit_Test_Site::switch_user($id);
        }
    }
}

namespace GoBuildWP\Kits\SecurityIntegrity {
    /** What the kit's network and transient calls see; reset by every test. */
    final class TestNet
    {
        /** @var list<array<string, mixed>|\WP_Error> responses handed out in order */
        public static array $responses = [];

        /** @var list<string> */
        public static array $calls = [];

        /** @var array<string, mixed> */
        public static array $transients = [];

        /** @var array<string, mixed> */
        public static array $site_transients = [];

        /** @var list<string> hooks that have a callback */
        public static array $actions = [];

        /** @var array<string, array<string, mixed>>|null what get_plugins() returns; null for WordPress's own */
        public static ?array $plugins = null;

        /** @var array<int, array<string, array<string, array<string, mixed>>>>|false what _get_cron_array() returns */
        public static array|false $cron = [];

        public static bool $cron_fails = false;

        public static function reset(): void
        {
            self::$cron = [];
            self::$cron_fails = false;
            self::$responses = [];
            self::$calls = [];
            self::$transients = [];
            self::$site_transients = [];
            self::$actions = [];
            self::$plugins = null;
        }

        /** @param array<string, mixed> $body */
        public static function json(array $body, int $code = 200): array
        {
            return ['response' => ['code' => $code], 'body' => (string) json_encode($body)];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\wp_remote_get')) {
        /** @param array<string, mixed> $args */
        function wp_remote_get(string $url, array $args = []): array|\WP_Error
        {
            TestNet::$calls[] = $url;
            $response = array_shift(TestNet::$responses) ?? new \WP_Error('http_request_failed', 'No network in tests.');
            if (is_array($response) && !empty($args['stream']) && is_string($args['filename'] ?? null)) {
                // A streamed download lands in the file asked for, as WordPress does it.
                file_put_contents($args['filename'], (string) ($response['body'] ?? ''));
                $response['body'] = '';
            }
            return $response;
        }

        function wp_remote_retrieve_response_code(array|\WP_Error $response): int|string
        {
            return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
        }

        function wp_remote_retrieve_body(array|\WP_Error $response): string
        {
            return is_array($response) ? (string) ($response['body'] ?? '') : '';
        }

        function get_transient(string $key): mixed
        {
            return TestNet::$transients[$key] ?? false;
        }

        function set_transient(string $key, mixed $value, int $expiration = 0): bool
        {
            TestNet::$transients[$key] = $value;
            return true;
        }

        function get_site_transient(string $key): mixed
        {
            return TestNet::$site_transients[$key] ?? false;
        }

        /** @return array<string, array<string, mixed>> */
        function get_plugins(): array
        {
            return TestNet::$plugins ?? \get_plugins();
        }

        function has_action(string $hook, mixed $callback = false): bool
        {
            return in_array($hook, TestNet::$actions, strict: true);
        }
    }

    require_once dirname(__DIR__, 4) . '/includes/kits/security-integrity/src/load.php';
}

namespace GoBuildWP\Tests\Unit\Kits\SecurityIntegrity {
    use GoBuildWP\Kits\SecurityIntegrity as SI;
    use WP_Error;
    use WPPilot\Kits\Runtime;
    use WPPilot\Kits\Runtime\Host;
    use WPPilot\Kits\Runtime\Jobs;
    use WPPilot\Kits\Runtime\Ledger;
    use Kit_Test_Site;

    /**
     * A $wpdb holding a few users, options and posts, answering the queries the database scan
     * makes. prepare() really substitutes, and each reader parses its numbers back out of the SQL,
     * so a query with the wrong placeholders fails here.
     */
    final class FakeWpdb
    {
        public string $prefix = 'wp_';
        public string $users = 'wp_users';
        public string $usermeta = 'wp_usermeta';
        public string $options = 'wp_options';
        public string $posts = 'wp_posts';
        public string $comments = 'wp_comments';
        public string $postmeta = 'wp_postmeta';

        /** @var array<int, array{content: string, approved: string}> */
        public array $commentRows = [];

        /** @var array<int, array{post: int, key: string, value: string}> */
        public array $postmetaRows = [];

        /** @var array<int, array{login: string, registered: string}> */
        public array $userRows = [];

        /** @var list<array{0: int, 1: string, 2: string}> user id, meta key, meta value */
        public array $meta = [];

        /** @var list<array{id: int, name: string, value: string, autoload: string}> */
        public array $optionRows = [];

        /** @var array<int, array{content: string, status: string, type: string, title?: string, excerpt?: string}> */
        public array $postRows = [];

        /** @var list<string> */
        public array $queries = [];

        public function get_blog_prefix(): string
        {
            return $this->prefix;
        }

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function prepare(string $query, mixed ...$args): string
        {
            $index = 0;
            return (string) preg_replace_callback('/%[ds]/', static function (array $match) use (&$index, $args): string {
                $value = $args[$index++] ?? '';
                return $match[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
            }, $query);
        }

        public function admin(int $id, string $login, string $registered, bool $session = true): void
        {
            $this->userRows[$id] = ['login' => $login, 'registered' => $registered];
            $this->meta[] = [$id, 'wp_capabilities', serialize(['administrator' => true])];
            if ($session) {
                $this->meta[] = [$id, 'session_tokens', serialize(['x' => []])];
            }
        }

        public function option(string $name, string $value, string $autoload = 'off'): void
        {
            $this->optionRows[] = ['id' => count($this->optionRows) + 1, 'name' => $name, 'value' => $value, 'autoload' => $autoload];
        }

        /** @return list<int> */
        private static function ids(string $query): array
        {
            preg_match('/IN \(([\d,\s]+)\)/', $query, $match);
            return array_map('intval', explode(',', $match[1] ?? ''));
        }

        /** What the scan's link_filter() keeps: a link plus a hiding style or a spam word. */
        private static function linky(string $value): bool
        {
            if (!str_contains($value, '<a ')) {
                return false;
            }
            foreach (array_merge(\GoBuildWP\Kits\SecurityIntegrity\HIDDEN_STYLE_HINTS, \GoBuildWP\Kits\SecurityIntegrity\SPAM_LINK_WORDS) as $word) {
                if (stripos($value, $word) !== false) {
                    return true;
                }
            }
            return false;
        }

        private static function scripty(string $value): bool
        {
            return str_contains($value, '<script') || str_contains($value, '<iframe') || str_contains($value, 'eval(');
        }

        /** @return array{0: int, 1: int} */
        private static function between(string $query): array
        {
            preg_match('/BETWEEN (\d+) AND (\d+)/', $query, $match);
            return [(int) ($match[1] ?? 0), (int) ($match[2] ?? 0)];
        }

        /** @return list<array<string, mixed>>|null */
        public function get_results(string $query, mixed $output = null): ?array
        {
            $this->queries[] = $query;
            if (preg_match("/meta_key = 'wp_capabilities' AND user_id > (\\d+) ORDER BY user_id ASC LIMIT (\\d+)/", $query, $match) === 1) {
                $rows = [];
                foreach ($this->meta as [$id, $key, $value]) {
                    if ($key === 'wp_capabilities' && $id > (int) $match[1]) {
                        $rows[$id] = ['user_id' => (string) $id, 'meta_value' => $value];
                    }
                }
                ksort($rows);
                return array_slice(array_values($rows), 0, (int) $match[2]);
            }
            if (str_contains($query, 'FROM wp_users WHERE ID IN')) {
                $rows = [];
                foreach (self::ids($query) as $id) {
                    if (isset($this->userRows[$id])) {
                        $rows[] = ['ID' => (string) $id, 'user_login' => $this->userRows[$id]['login'], 'user_registered' => $this->userRows[$id]['registered']];
                    }
                }
                return $rows;
            }
            if (str_contains($query, "meta_key = '_application_passwords'")) {
                $ids = self::ids($query);
                $rows = [];
                foreach ($this->meta as [$id, $key, $value]) {
                    if ($key === '_application_passwords' && in_array($id, $ids, strict: true)) {
                        $rows[] = ['user_id' => (string) $id, 'meta_value' => $value];
                    }
                }
                return $rows;
            }
            if (str_contains($query, ' AS s,')) {
                [$from, $to] = self::between($query);
                $rows = [];
                foreach ($this->optionRows as $row) {
                    $flags = [
                        's' => (int) str_contains($row['value'], '<script'),
                        'e' => (int) str_contains($row['value'], 'eval('),
                        'b' => (int) str_contains($row['value'], 'base64_decode'),
                        'i' => (int) str_contains($row['value'], '<iframe'),
                        'a' => (int) self::linky($row['value']),
                    ];
                    if ($row['id'] >= $from && $row['id'] <= $to && array_sum($flags) > 0) {
                        $rows[] = ['option_id' => (string) $row['id'], 'option_name' => $row['name']] + array_map('strval', $flags);
                    }
                }
                return $rows;
            }
            if (str_contains($query, 'SELECT comment_ID, comment_approved FROM wp_comments')) {
                [$from, $to] = self::between($query);
                preg_match('/LIMIT (\d+)/', $query, $limit);
                $rows = [];
                ksort($this->commentRows);
                foreach ($this->commentRows as $id => $row) {
                    if ($id >= $from && $id <= $to && in_array($row['approved'], ['1', '0'], strict: true)
                        && (self::scripty($row['content']) || self::linky($row['content']))) {
                        $rows[] = ['comment_ID' => (string) $id, 'comment_approved' => $row['approved']];
                    }
                }
                return array_slice($rows, 0, (int) ($limit[1] ?? 500));
            }
            if (str_contains($query, 'SELECT meta_id, post_id, meta_key FROM wp_postmeta')) {
                [$from, $to] = self::between($query);
                preg_match('/LIMIT (\d+)/', $query, $limit);
                $rows = [];
                ksort($this->postmetaRows);
                foreach ($this->postmetaRows as $id => $row) {
                    if ($id >= $from && $id <= $to && !str_starts_with($row['key'], '_oembed_')
                        && (self::scripty($row['value']) || str_contains($row['value'], 'base64_decode'))) {
                        $rows[] = ['meta_id' => (string) $id, 'post_id' => (string) $row['post'], 'meta_key' => $row['key']];
                    }
                }
                return array_slice($rows, 0, (int) ($limit[1] ?? 500));
            }
            if (str_contains($query, "option_name LIKE 'widget\\\\_%'")) {
                $rows = [];
                foreach ($this->optionRows as $row) {
                    if (str_starts_with($row['name'], 'widget_')) {
                        $rows[] = ['option_name' => $row['name'], 'option_value' => $row['value']];
                    }
                }
                return $rows;
            }
            return [];
        }

        /** @return list<string> */
        public function get_col(string $query): array
        {
            $this->queries[] = $query;
            if (str_contains($query, "meta_key = 'session_tokens'")) {
                $ids = self::ids($query);
                $found = [];
                foreach ($this->meta as [$id, $key]) {
                    if ($key === 'session_tokens' && in_array($id, $ids, strict: true)) {
                        $found[] = (string) $id;
                    }
                }
                return $found;
            }
            if (str_contains($query, 'SELECT ID FROM wp_posts')) {
                [$from, $to] = self::between($query);
                $ids = [];
                foreach ($this->postRows as $id => $post) {
                    $title = $post['title'] ?? '';
                    $excerpt = $post['excerpt'] ?? '';
                    $match = self::scripty($post['content']) || self::scripty($title) || self::scripty($excerpt)
                        || self::linky($post['content']) || self::linky($excerpt)
                        || preg_match('/[A-Za-z0-9+\/]{255}/', $post['content']) === 1;
                    if ($id >= $from && $id <= $to && $match && $post['type'] !== 'revision') {
                        $ids[] = (string) $id;
                    }
                }
                return $ids;
            }
            return [];
        }

        public function get_var(string $query): ?string
        {
            $this->queries[] = $query;
            if (preg_match("/option_name = '(home|siteurl)'/", $query, $match) === 1) {
                foreach ($this->optionRows as $row) {
                    if ($row['name'] === $match[1]) {
                        return $row['value'];
                    }
                }
                return null;
            }
            if (str_contains($query, 'MAX(option_id)')) {
                return (string) count($this->optionRows);
            }
            if (str_contains($query, 'MAX(ID)')) {
                return (string) ($this->postRows === [] ? 0 : max(array_keys($this->postRows)));
            }
            if (str_contains($query, 'SUM(LENGTH(option_value))')) {
                $sum = 0;
                foreach ($this->optionRows as $row) {
                    $sum += in_array($row['autoload'], ['yes', 'on', 'auto-on', 'auto'], strict: true) ? strlen($row['value']) : 0;
                }
                return (string) $sum;
            }
            if (str_contains($query, "SELECT COUNT(*) FROM wp_comments")) {
                // Rows may carry 'days' (age) and 'url' (author website); held spam is linked and recent.
                $since = preg_match("/comment_date_gmt >= '([^']+)'/", $query, $match) === 1 ? strtotime($match[1] . ' UTC') : null;
                $count = 0;
                foreach ($this->commentRows as $row) {
                    $linked = str_contains($row['content'], 'http') || str_contains($row['content'], 'www.') || ($row['url'] ?? '') !== '';
                    if ($row['approved'] === '0' && ($since === null || (time() - ($row['days'] ?? 0) * 86400 >= $since && $linked))) {
                        $count++;
                    }
                }
                return (string) $count;
            }
            if (str_contains($query, 'MAX(comment_ID)')) {
                return (string) ($this->commentRows === [] ? 0 : max(array_keys($this->commentRows)));
            }
            if (str_contains($query, 'MAX(meta_id)')) {
                return (string) ($this->postmetaRows === [] ? 0 : max(array_keys($this->postmetaRows)));
            }
            if (preg_match('/SUBSTRING\(post_content, 1, \d+\)\) FROM wp_posts WHERE ID = (\d+)/', $query, $match) === 1) {
                $post = $this->postRows[(int) $match[1]] ?? null;
                return $post === null ? null : implode("\n", [$post['title'] ?? '', $post['excerpt'] ?? '', $post['content']]);
            }
            if (preg_match('/SUBSTRING\(comment_content, 1, \d+\) FROM wp_comments WHERE comment_ID = (\d+)/', $query, $match) === 1) {
                return $this->commentRows[(int) $match[1]]['content'] ?? null;
            }
            if (preg_match('/SUBSTRING\(meta_value, 1, \d+\) FROM wp_postmeta WHERE meta_id = (\d+)/', $query, $match) === 1) {
                return $this->postmetaRows[(int) $match[1]]['value'] ?? null;
            }
            if (preg_match('/SUBSTRING\(option_value, 1, \d+\) FROM wp_options WHERE option_id = (\d+)/', $query, $match) === 1) {
                foreach ($this->optionRows as $row) {
                    if ($row['id'] === (int) $match[1]) {
                        return $row['value'];
                    }
                }
                return null;
            }
            if (preg_match('/^SELECT post_content FROM wp_posts WHERE ID = (\d+)$/', $query, $match) === 1) {
                return $this->postRows[(int) $match[1]]['content'] ?? null;
            }
            if (preg_match("/^SELECT option_value FROM wp_options WHERE option_name = '((?:[^'\\\\]|\\\\.)*)' LIMIT 1$/s", $query, $match) === 1) {
                $row = $this->find_option(stripslashes($match[1]));
                return $row === null ? null : $this->optionRows[$row]['value'];
            }
            return null;
        }

        /** Writes fail while set, as a read-only database would. */
        public bool $failWrites = false;

        private function find_option(string $name): ?int
        {
            foreach ($this->optionRows as $index => $row) {
                if ($row['name'] === $name) {
                    return $index;
                }
            }
            return null;
        }

        /** @return array<string, string>|null */
        public function get_row(string $query, mixed $output = null): ?array
        {
            $this->queries[] = $query;
            if (preg_match("/^SELECT option_value, autoload FROM wp_options WHERE option_name = '((?:[^'\\\\]|\\\\.)*)' LIMIT 1$/s", $query, $match) === 1) {
                $row = $this->find_option(stripslashes($match[1]));
                return $row === null ? null : ['option_value' => $this->optionRows[$row]['value'], 'autoload' => $this->optionRows[$row]['autoload']];
            }
            return null;
        }

        /**
         * @param array<string, mixed> $data
         * @param array<string, mixed> $where
         */
        public function update(string $table, array $data, array $where): int|false
        {
            if ($this->failWrites) {
                return false;
            }
            if ($table === $this->options) {
                $row = $this->find_option((string) ($where['option_name'] ?? ''));
                if ($row === null) {
                    return 0;
                }
                $this->optionRows[$row]['value'] = (string) ($data['option_value'] ?? $this->optionRows[$row]['value']);
                $this->optionRows[$row]['autoload'] = (string) ($data['autoload'] ?? $this->optionRows[$row]['autoload']);
                return 1;
            }
            if ($table === $this->posts && isset($this->postRows[(int) ($where['ID'] ?? 0)])) {
                $this->postRows[(int) $where['ID']]['content'] = (string) $data['post_content'];
                return 1;
            }
            return 0;
        }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int|false
        {
            if ($this->failWrites || $table !== $this->options) {
                return false;
            }
            $this->option((string) $data['option_name'], (string) $data['option_value'], (string) ($data['autoload'] ?? 'off'));
            return 1;
        }

        /** @param array<string, mixed> $where */
        public function delete(string $table, array $where): int|false
        {
            if ($this->failWrites) {
                return false;
            }
            $row = $table === $this->options ? $this->find_option((string) ($where['option_name'] ?? '')) : null;
            if ($row === null) {
                return 0;
            }
            unset($this->optionRows[$row]);
            $this->optionRows = array_values($this->optionRows);
            return 1;
        }
    }

    final class FakeLedger implements Ledger
    {
        public function capture_for(string $ability_name, callable $capture): void {}

        public function record_items(string $ability_name, array $items, ?string $group = null): array
        {
            return ['group' => (string) $group, 'change_ids' => [], 'without_before_image' => 0];
        }

        public function register_strategy(string $type, callable $restore, ?callable $build = null): bool
        {
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
            return 0;
        }

        public function download_url(): string
        {
            return '';
        }
    }

    /** A host on the real runtime Runner, so start/advance/resume/cancel run as on a site. */
    final class FakeHost implements Host
    {
        public Runtime\Runner $jobs;

        /** What `cloud-verified` answers: whether this request came signed from the Cloud. */
        public bool $cloudVerified = false;

        public function __construct()
        {
            $this->jobs = new Runtime\Runner();
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
            return 'readonly';
        }

        public function ledger(): Ledger
        {
            return new FakeLedger();
        }

        public function jobs(): Jobs
        {
            return $this->jobs;
        }

        public function extension(string $point): mixed
        {
            return $point === 'cloud-verified' ? $this->cloudVerified : null;
        }

        public function admin_parent_slug(): string
        {
            return 'tools.php';
        }

        public function confirm_guard(string $ability_name, array $input): bool|WP_Error
        {
            return true;
        }
    }

    /** A throwaway WordPress folder on disk. */
    final class Fixture
    {
        public string $root;

        public function __construct()
        {
            $this->root = str_replace('\\', '/', sys_get_temp_dir()) . '/kit-si-' . bin2hex(random_bytes(6));
            mkdir($this->root, 0777, true);
            SI\roots([
                'abspath' => $this->root . '/',
                'plugins' => $this->root . '/wp-content/plugins',
                'mu_plugins' => $this->root . '/wp-content/mu-plugins',
                'themes' => $this->root . '/wp-content/themes',
                'uploads' => $this->root . '/wp-content/uploads',
                'content' => $this->root . '/wp-content',
            ]);
        }

        public function put(string $path, string $content): string
        {
            $file = $this->root . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $content);
            return $file;
        }

        public function dir(string $path): string
        {
            $dir = $this->root . '/' . $path;
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            return $dir;
        }

        public function remove(): void
        {
            SI\roots([]);
            self::delete($this->root);
        }

        private static function delete(string $path): void
        {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
                return;
            }
            foreach (array_diff((array) @scandir($path), ['.', '..']) as $entry) {
                self::delete($path . '/' . $entry);
            }
            @rmdir($path);
        }
    }

    /** Drop every option and filter the kit and the runner leave behind. */
    function reset_state(): void
    {
        // Kit and runner options (job index, results, start mutex, jobs, leases, the safe-updates lock).
        Kit_Test_Site::reset();
        foreach (['core_updater.lock', 'auto_updater.lock', 'wp_user_roles', 'users_can_register', 'default_role', 'active_plugins', 'home'] as $name) {
            delete_option($name);
        }
        unset($GLOBALS['wp_filter']['gobuildwp_kit_security_integrity_budgets']);
        delete_option(SI\PACK_OPTION);
        SI\pack_reset();
        SI\TestNet::reset();
    }

    /** Run a file scan to the end in one budget, outside the runner. @return list<array<string, mixed>> */
    function run_files(array $payload, int $max_steps = 1): array
    {
        $state = [];
        SI\files_init($payload, $state);
        $items = [];
        for ($i = 0; $i < $max_steps; $i++) {
            $out = new SI\Collector($state);
            $done = SI\files_run($payload, $state, new SI\Budget(SI\budgets(), 30), $out);
            array_push($items, ...$out->items);
            if ($done) {
                return ['items' => $items, 'state' => $state, 'steps' => $i + 1];
            }
        }
        return ['items' => $items, 'state' => $state, 'steps' => -1];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<string> "rule_id path" (or "result path" when there is no rule)
     */
    function summary(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $key = (string) $item['rule_id'] !== '' ? (string) $item['rule_id'] : (string) $item['result'];
            $lines[] = $key . ' ' . (string) ($item['path'] ?? $item['entity']['label'] ?? '');
        }
        sort($lines);
        return $lines;
    }
}
