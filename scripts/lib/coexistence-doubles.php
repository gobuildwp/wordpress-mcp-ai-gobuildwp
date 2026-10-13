<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Recording WordPress doubles for scripts/test-kit-coexistence.php.
 *
 * Loaded before tests/doubles/wordpress.php, whose doubles are all function_exists()-guarded,
 * so these win where both exist and the unit suite's doubles fill in the rest. They differ from
 * the unit suite's on purpose: actions really run, and every hook, option, ability, cron event,
 * route, post type, handle and nonce a call names is recorded against the copy of the kit code
 * that named it — GoBuildWP's or the export's — worked out from the call stack.
 */

final class Kit_Coexistence
{
    /** @var array<string, string> owner label => directory prefix, forward slashes */
    public static array $owners = [];

    /** @var array<string, array<string, array<string, true>>> kind => name => owner => true */
    public static array $names = [];

    /** @var array<string, array<int, list<array{callback: mixed, args: int}>>> */
    public static array $hooks = [];

    /** @var array<string, int> */
    public static array $fired = [];

    /** @var array<string, array<string, mixed>> */
    public static array $abilities = [];

    /** @var array<string, array<string, mixed>> */
    public static array $categories = [];

    /** @var array<string, mixed> */
    public static array $options = [];

    /** @var list<string> */
    public static array $notices = [];

    public static int $user = 1;

    /** Which copy of the kit code made the current call, from the innermost matching frame. */
    public static function owner(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            foreach (self::$owners as $owner => $prefix) {
                if (str_starts_with($file, $prefix)) {
                    return $owner;
                }
            }
        }
        return 'harness';
    }

    public static function record(string $kind, string $name): void
    {
        self::$names[$kind][$name][self::owner()] = true;
    }
}

foreach (['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800] as $constant => $value) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
{
    return add_filter($hook, $callback, $priority, $accepted_args);
}

function add_filter(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
{
    Kit_Coexistence::record('hook', $hook);
    Kit_Coexistence::$hooks[$hook][$priority][] = ['callback' => $callback, 'args' => $accepted_args];
    return true;
}

function has_action(string $hook, mixed $callback = false): bool|int
{
    return has_filter($hook, $callback);
}

function has_filter(string $hook, mixed $callback = false): bool|int
{
    foreach (Kit_Coexistence::$hooks[$hook] ?? [] as $priority => $callbacks) {
        foreach ($callbacks as $entry) {
            if ($callback === false || $entry['callback'] === $callback) {
                return $callback === false ? true : $priority;
            }
        }
    }
    return false;
}

function do_action(string $hook, mixed ...$args): void
{
    Kit_Coexistence::record('fired', $hook);
    Kit_Coexistence::$fired[$hook] = (Kit_Coexistence::$fired[$hook] ?? 0) + 1;
    $registered = Kit_Coexistence::$hooks[$hook] ?? [];
    ksort($registered);
    foreach ($registered as $callbacks) {
        foreach ($callbacks as $entry) {
            ($entry['callback'])(...array_slice($args, 0, max(0, $entry['args'])));
        }
    }
}

function did_action(string $hook): int
{
    return Kit_Coexistence::$fired[$hook] ?? 0;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    Kit_Coexistence::record('fired', $hook);
    $registered = Kit_Coexistence::$hooks[$hook] ?? [];
    ksort($registered);
    foreach ($registered as $callbacks) {
        foreach ($callbacks as $entry) {
            $value = ($entry['callback'])($value, ...array_slice($args, 0, max(0, $entry['args'] - 1)));
        }
    }
    return $value;
}

function wp_register_ability(string $name, array $args): ?WP_Ability
{
    Kit_Coexistence::record('ability', $name);
    if (preg_match('/^[a-z0-9-]+\/[a-z0-9-]+$/', $name) !== 1) {
        Kit_Coexistence::$notices[] = "ability name {$name} is refused by WordPress";
        return null;
    }
    if (isset(Kit_Coexistence::$abilities[$name])) {
        Kit_Coexistence::$notices[] = "ability {$name} registered twice; WordPress keeps the first";
        return null;
    }
    if (!isset(Kit_Coexistence::$categories[(string) ($args['category'] ?? '')])) {
        Kit_Coexistence::$notices[] = sprintf('ability %s names category "%s", which is not registered', $name, (string) ($args['category'] ?? ''));
    }
    Kit_Coexistence::$abilities[$name] = $args;
    return wp_get_ability($name);
}

function wp_has_ability(string $name): bool
{
    return isset(Kit_Coexistence::$abilities[$name]);
}

function wp_get_ability(string $name): ?WP_Ability
{
    $args = Kit_Coexistence::$abilities[$name] ?? null;
    return $args === null ? null : new WP_Ability($name, is_array($args['meta'] ?? null) ? $args['meta'] : []);
}

function wp_register_ability_category(string $slug, array $args): ?array
{
    Kit_Coexistence::record('category', $slug);
    if (isset(Kit_Coexistence::$categories[$slug])) {
        Kit_Coexistence::$notices[] = "ability category {$slug} registered twice; WordPress refuses the second with a notice";
        return null;
    }
    Kit_Coexistence::$categories[$slug] = $args;
    return $args;
}

function wp_has_ability_category(string $slug): bool
{
    return isset(Kit_Coexistence::$categories[$slug]);
}

function get_option(string $option, mixed $default_value = false): mixed
{
    Kit_Coexistence::record('option', $option);
    return array_key_exists($option, Kit_Coexistence::$options) ? Kit_Coexistence::$options[$option] : $default_value;
}

function add_option(string $option, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
{
    Kit_Coexistence::record('option', $option);
    if (array_key_exists($option, Kit_Coexistence::$options)) {
        return false;
    }
    Kit_Coexistence::$options[$option] = $value;
    return true;
}

function update_option(string $option, mixed $value, mixed $autoload = null): bool
{
    Kit_Coexistence::record('option', $option);
    Kit_Coexistence::$options[$option] = $value;
    return true;
}

function delete_option(string $option): bool
{
    Kit_Coexistence::record('option', $option);
    unset(Kit_Coexistence::$options[$option]);
    return true;
}

function get_transient(string $transient): mixed
{
    Kit_Coexistence::record('transient', $transient);
    return false;
}

function set_transient(string $transient, mixed $value, int $expiration = 0): bool
{
    Kit_Coexistence::record('transient', $transient);
    return true;
}

function delete_transient(string $transient): bool
{
    Kit_Coexistence::record('transient', $transient);
    return true;
}

function wp_next_scheduled(string $hook, array $args = []): int|false
{
    Kit_Coexistence::record('cron', $hook);
    return false;
}

function wp_schedule_single_event(int $timestamp, string $hook, array $args = [], bool $wp_error = false): bool
{
    Kit_Coexistence::record('cron', $hook);
    return true;
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = [], bool $wp_error = false): bool
{
    Kit_Coexistence::record('cron', $hook);
    return true;
}

function wp_clear_scheduled_hook(string $hook, array $args = [], bool $wp_error = false): int
{
    Kit_Coexistence::record('cron', $hook);
    return 0;
}

function spawn_cron(int $gmt_time = 0): bool
{
    return true;
}

function register_rest_route(string $route_namespace, string $route, array $args = [], bool $override = false): bool
{
    Kit_Coexistence::record('rest_route', trim($route_namespace, '/') . '/' . ltrim($route, '/'));
    return true;
}

function register_post_type(string $post_type, array $args = []): object
{
    Kit_Coexistence::record('post_type', $post_type);
    return (object) ['name' => $post_type];
}

function wp_register_script(string $handle, mixed ...$rest): bool
{
    Kit_Coexistence::record('handle', 'script:' . $handle);
    return true;
}

function wp_enqueue_script(string $handle, mixed ...$rest): void
{
    Kit_Coexistence::record('handle', 'script:' . $handle);
}

function wp_register_style(string $handle, mixed ...$rest): bool
{
    Kit_Coexistence::record('handle', 'style:' . $handle);
    return true;
}

function wp_enqueue_style(string $handle, mixed ...$rest): void
{
    Kit_Coexistence::record('handle', 'style:' . $handle);
}

function wp_create_nonce(string|int $action = -1): string
{
    Kit_Coexistence::record('nonce', (string) $action);
    return 'nonce';
}

function wp_verify_nonce(string $nonce, string|int $action = -1): int|false
{
    Kit_Coexistence::record('nonce', (string) $action);
    return 1;
}

function add_menu_page(string $page_title, string $menu_title, string $capability, string $menu_slug, mixed ...$rest): string
{
    Kit_Coexistence::record('admin_page', $menu_slug);
    return $menu_slug;
}

function add_submenu_page(string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, mixed ...$rest): string|false
{
    Kit_Coexistence::record('admin_page', $menu_slug);
    return $menu_slug;
}

function current_user_can(string $capability, mixed ...$args): bool
{
    return true;
}

function get_current_user_id(): int
{
    return Kit_Coexistence::$user;
}

function wp_set_current_user(int $id, string $name = ''): object
{
    Kit_Coexistence::$user = $id;
    return (object) ['ID' => $id];
}

function wp_get_current_user(): object
{
    return (object) ['ID' => Kit_Coexistence::$user, 'user_login' => 'user' . Kit_Coexistence::$user];
}

/** No other users: a kit listing administrators (routines' recipients) gets an empty list. */
function get_users(array $args = []): array
{
    return [];
}

function wp_timezone_string(): string
{
    return 'UTC';
}

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone('UTC');
}

function wp_date(string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null): string|false
{
    return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone($timezone ?? wp_timezone())->format($format);
}

function wp_cache_delete(int|string $key, string $group = ''): bool
{
    return true;
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}

function is_admin(): bool
{
    return false;
}

function _doing_it_wrong(string $function_name, string $message, string $version): void
{
    Kit_Coexistence::$notices[] = "{$function_name}: {$message}";
}

/**
 * An empty media library, so a read-only kit ability that scans it runs rather than fatals.
 *
 * @param array<string, mixed> $args
 * @return list<int>
 */
function get_posts(array $args = []): array
{
    return [];
}

/** An empty schedule: the read-only cron ability lists nothing, and must not fail doing it. */
function _get_cron_array(): array
{
    return [];
}

/** @param string|list<string> $mime_type */
function wp_count_attachments(string|array $mime_type = ''): object
{
    return (object) ['trash' => 0];
}

function wp_get_schedules(): array
{
    return [
        'hourly' => ['interval' => HOUR_IN_SECONDS, 'display' => 'Once Hourly'],
        'daily' => ['interval' => DAY_IN_SECONDS, 'display' => 'Once Daily'],
    ];
}

/**
 * Site Health with no tests registered. The real class lives in wp-admin/includes, which the
 * read-only Site Health ability loads on demand and this harness does not have.
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
        return ['direct' => [], 'async' => []];
    }
}

if (!function_exists('get_post_types')) {
    /**
     * No registered post types. A kit's check-setup that lists the types an SEO plugin covers
     * runs here once its plugin is stood in (requires.any), and has nothing to list.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    function get_post_types(array $args = [], string $output = 'names', string $operator = 'and'): array
    {
        return [];
    }
}

/**
 * No network here. A GET or POST is recorded and answered 200 with the smallest Lighthouse result,
 * so a read that calls out (pagespeed-check) runs its whole path in both copies without leaving
 * the machine.
 *
 * @param array<string, mixed> $args
 * @return array{response: array{code: int}, headers: array<string, string>, body: string}
 */
function wp_remote_get(string $url, array $args = []): array
{
    Kit_Coexistence::record('http', (string) strtok($url, '?'));
    return ['response' => ['code' => 200], 'headers' => [], 'body' => '{"lighthouseResult":{"lighthouseVersion":"13.0.1","categories":{"performance":{"score":0.9}},"audits":{}}}'];
}

/**
 * @param array<string, mixed> $args
 * @return array{response: array{code: int}, headers: array<string, string>, body: string}
 */
function wp_remote_post(string $url, array $args = []): array
{
    return wp_remote_get($url, $args);
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(array|WP_Error $response): int|string
    {
        return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
    }

    function wp_remote_retrieve_body(array|WP_Error $response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }
}

if (!function_exists('wp_remote_retrieve_header')) {
    /** @return string|list<string> */
    function wp_remote_retrieve_header(array|WP_Error $response, string $header): string|array
    {
        return is_array($response) ? ($response['headers'][strtolower($header)] ?? '') : '';
    }
}
