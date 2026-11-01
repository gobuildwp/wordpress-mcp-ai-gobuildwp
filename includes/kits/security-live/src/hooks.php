<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityLive;

use GoBuildWP\Kits\SecurityIntegrity as SI;

if (!defined('ABSPATH')) {
    exit();
}

/** Options whose change is worth an event, and how bad a change is. */
const WATCHED_OPTIONS = [
    'siteurl' => 'high',
    'home' => 'high',
    'admin_email' => 'medium',
    'users_can_register' => 'medium',
    'default_role' => 'medium',
    'active_plugins' => 'low',
];

/**
 * The instant checks: each is a WordPress hook that costs nothing until the thing it watches
 * happens. They only record events; nothing is blocked or changed.
 */
function install_hooks(): void
{
    add_filter('cron_schedules', __NAMESPACE__ . '\cron_schedules');
    add_action(CRON_HOOK, __NAMESPACE__ . '\cron_tick');
    add_action(CRON_HOOK . '_soon', __NAMESPACE__ . '\cron_tick');
    add_action('rest_api_init', __NAMESPACE__ . '\register_route');
    add_action('admin_init', __NAMESPACE__ . '\admin_tick');

    if (!settings()['enabled']) {
        return;
    }
    add_filter('wp_handle_upload_prefilter', __NAMESPACE__ . '\on_upload_prefilter');
    add_action('add_attachment', __NAMESPACE__ . '\on_add_attachment');
    add_action('upgrader_process_complete', __NAMESPACE__ . '\on_upgrade', 20, 2);
    add_action('wp_ajax_edit-theme-plugin-file', __NAMESPACE__ . '\on_file_editor_save', 0);
    add_action('user_register', __NAMESPACE__ . '\on_user_register', 20, 1);
    add_action('set_user_role', __NAMESPACE__ . '\on_set_user_role', 20, 3);
    add_action('add_user_role', __NAMESPACE__ . '\on_add_user_role', 20, 2);
    add_action('grant_super_admin', __NAMESPACE__ . '\on_grant_super_admin', 20, 1);
    add_action('updated_option', __NAMESPACE__ . '\on_updated_option', 20, 3);
    add_action('wp_after_insert_post', __NAMESPACE__ . '\on_after_insert_post', 20, 4);
    add_action('wp_login_failed', __NAMESPACE__ . '\on_login_failed', 20, 1);
    add_action('wp_login', __NAMESPACE__ . '\on_login', 20, 2);
}

/** @param array<string, mixed> $schedules @return array<string, mixed> */
function cron_schedules(mixed $schedules): mixed
{
    if (!is_array($schedules)) {
        return $schedules;
    }
    $minutes = settings()['interval_minutes'];
    $schedules[CRON_SCHEDULE] = ['interval' => $minutes * 60, 'display' => sprintf('Every %d minutes (GoBuildWP live security)', $minutes)];
    return $schedules;
}

function cron_tick(): void
{
    run_watch(MAX_RUN_SECONDS);
}

/** In the dashboard: keep the scheduled run in place and look for new must-use plugins and drop-ins. */
function admin_tick(): void
{
    if (!settings()['enabled']) {
        return;
    }
    if (function_exists('wp_next_scheduled') && !wp_next_scheduled(CRON_HOOK)) {
        reschedule();
    }
    check_extras(time());
}

function by(): int
{
    return function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
}

/** A path under the WordPress folder as the scans name it, or null when it isn't under it. */
function relative_path(string $abs): ?string
{
    $root = SI\normalize(rtrim(SI\roots()['abspath'], '/\\'));
    $abs = SI\normalize($abs);
    if (!SI\within($abs, $root)) {
        return null;
    }
    return ltrim(substr($abs, strlen($root)), '/');
}

/** Whether the start of a file holds an opening PHP tag. */
function has_php_tag(string $abs): bool
{
    if (is_link($abs) || !is_file($abs) || !is_readable($abs)) {
        return false;
    }
    $text = @file_get_contents($abs, false, null, 0, SI\RULE_READ_BYTES);
    return is_string($text) && preg_match('/<\?(?:php\b|=)/i', $text) === 1;
}

/**
 * Before WordPress accepts an upload: a PHP file, or PHP hidden in another file, is worth an
 * event even when WordPress then refuses it (someone tried).
 *
 * @param mixed $file
 * @return mixed the file, untouched
 */
function on_upload_prefilter(mixed $file): mixed
{
    if (!is_array($file)) {
        return $file;
    }
    $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? '')));
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($name === '') {
        return $file;
    }
    if (SI\is_php_name($name) || SI\has_inner_php_extension($name)) {
        record('high', 'upload', 'Upload of ' . $name, ['result' => 'php_upload', 'rule_id' => 'uploads.php_upload', 'label' => $name, 'by' => by()]);
    } elseif ($tmp !== '' && (function_exists('is_uploaded_file') && (is_uploaded_file($tmp) || is_file($tmp))) && has_php_tag($tmp)) {
        record('high', 'upload', 'Upload of ' . $name, ['result' => 'php_upload', 'rule_id' => SI\PHP_TAG_RULE, 'label' => $name, 'by' => by()]);
    }
    return $file;
}

/** After an attachment is saved: a PHP file in uploads, or PHP inside an image, with its path. */
function on_add_attachment(mixed $post_id): void
{
    $abs = (string) get_attached_file((int) $post_id, true);
    if ($abs === '') {
        return;
    }
    $path = relative_path($abs);
    if ($path === null) {
        return;
    }
    $name = basename($path);
    if (SI\is_php_name($name) || SI\has_inner_php_extension($name)) {
        $rule = SI\is_php_name($name) ? 'uploads.php_file' : 'uploads.php_double_extension';
        record('high', 'file', $path, ['path' => $path, 'area' => 'uploads', 'result' => 'php_in_uploads', 'rule_id' => $rule, 'by' => by()]);
        return;
    }
    if (has_php_tag($abs)) {
        record('high', 'file', $path, ['path' => $path, 'area' => 'uploads', 'result' => 'suspicious', 'rule_id' => SI\PHP_TAG_RULE, 'by' => by()]);
    }
}

/**
 * After an update or install: queue a quick look at the folders it changed.
 *
 * @param mixed $upgrader
 * @param mixed $extra
 */
function on_upgrade(mixed $upgrader, mixed $extra): void
{
    if (!is_array($extra)) {
        return;
    }
    $type = (string) ($extra['type'] ?? '');
    $buckets = [];
    if ($type === 'core') {
        $buckets = ['core:root', 'core:wp-admin', 'core:wp-includes'];
    } elseif ($type === 'plugin') {
        $items = (array) ($extra['plugins'] ?? (isset($extra['plugin']) ? [$extra['plugin']] : []));
        foreach ($items as $file) {
            $file = (string) $file;
            $buckets[] = str_contains($file, '/') ? 'plugin:' . strstr($file, '/', true) : 'plugins';
        }
    } elseif ($type === 'theme') {
        foreach ((array) ($extra['themes'] ?? (isset($extra['theme']) ? [$extra['theme']] : [])) as $slug) {
            $buckets[] = 'theme:' . (string) $slug;
        }
    }
    if ($buckets !== []) {
        queue_buckets($buckets);
    }
}

/** A save from Appearance > Theme File Editor or Plugins > Plugin File Editor (an attempt; WordPress may still refuse it). */
function on_file_editor_save(): void
{
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- read for logging only; core checks the nonce.
    $file = isset($_POST['file']) ? sanitize_text_field(wp_unslash((string) $_POST['file'])) : '';
    $plugin = isset($_POST['plugin']) ? sanitize_text_field(wp_unslash((string) $_POST['plugin'])) : '';
    $theme = isset($_POST['theme']) ? sanitize_text_field(wp_unslash((string) $_POST['theme'])) : '';
    // phpcs:enable
    if ($file === '') {
        return;
    }
    $owner = $plugin !== '' ? 'plugin ' . $plugin : 'theme ' . $theme;
    record('medium', 'file_editor', $file, ['label' => $owner, 'note' => 'Saved from the WordPress file editor.', 'by' => by()]);
}

function users_table(): string
{
    global $wpdb;
    return is_object($wpdb) && isset($wpdb->users) ? (string) $wpdb->users : 'wp_users';
}

function new_admin(int $user_id, string $how): void
{
    $user = get_userdata($user_id);
    $login = is_object($user) && isset($user->user_login) ? (string) $user->user_login : '';
    record('high', 'admin', 'Administrator: ' . ($login !== '' ? $login : '#' . $user_id), [
        'table' => users_table(),
        'entity_id' => (string) $user_id,
        'rule_id' => 'users.new_admin',
        'label' => 'user:' . $login,
        'note' => $how,
        'by' => by(),
    ]);
}

function on_user_register(mixed $user_id): void
{
    if (user_can((int) $user_id, 'manage_options')) {
        new_admin((int) $user_id, 'New user with administrator access.');
    }
}

/** @param mixed $old_roles */
function on_set_user_role(mixed $user_id, mixed $role, mixed $old_roles = []): void
{
    if ($role === 'administrator' && !in_array('administrator', (array) $old_roles, strict: true)) {
        new_admin((int) $user_id, 'User raised to administrator.');
    }
}

function on_add_user_role(mixed $user_id, mixed $role): void
{
    if ($role === 'administrator') {
        new_admin((int) $user_id, 'Administrator role added to a user.');
    }
}

function on_grant_super_admin(mixed $user_id): void
{
    new_admin((int) $user_id, 'User made a network super admin.');
}

function on_updated_option(mixed $option, mixed $old, mixed $value): void
{
    if (!is_string($option) || !isset(WATCHED_OPTIONS[$option])) {
        return;
    }
    $severity = WATCHED_OPTIONS[$option];
    $note = '';
    if ($option === 'active_plugins') {
        $added = array_values(array_diff(array_map('strval', (array) $value), array_map('strval', (array) $old)));
        if ($added === []) {
            return;
        }
        $note = 'Activated: ' . implode(', ', array_slice($added, 0, 5));
    } elseif ($option === 'default_role') {
        $severity = $value === 'administrator' ? 'critical' : 'medium';
        $note = 'New users now get the role ' . (string) $value . '.';
    } elseif ($option === 'users_can_register') {
        if (!$value) {
            return;
        }
        $severity = get_option('default_role') === 'administrator' ? 'critical' : 'medium';
        $note = 'Anyone can now register.';
    } elseif (is_scalar($value)) {
        $note = 'Now: ' . (string) $value;
    }
    record($severity, 'option', 'Setting changed: ' . $option, ['rule_id' => 'options.' . $option, 'note' => $note, 'by' => by()]);
}

/**
 * A post saved with an external script, hidden iframe or obfuscated script that was not there
 * before: the scan's database rules, on this one post.
 */
function on_after_insert_post(mixed $post_id, mixed $post, mixed $update = false, mixed $before = null): void
{
    if (!is_object($post) || !isset($post->post_content)) {
        return;
    }
    if (in_array((string) ($post->post_type ?? ''), ['revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache'], strict: true)
        || in_array((string) ($post->post_status ?? ''), ['auto-draft', 'trash', 'inherit'], strict: true)) {
        return;
    }
    $content = (string) $post->post_content;
    // Cheap pre-check: every content rule needs one of these.
    if (stripos($content, '<script') === false && stripos($content, '<iframe') === false && stripos($content, 'eval') === false
        && stripos($content, 'document.write') === false && stripos($content, '\\x') === false) {
        return;
    }
    $host = strtolower((string) wp_parse_url((string) home_url(), PHP_URL_HOST));
    $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    $had = [];
    if (is_object($before) && isset($before->post_content)) {
        foreach (SI\classify_content((string) $before->post_content, $host) as [$suffix]) {
            $had[$suffix] = true;
        }
    }
    global $wpdb;
    $table = is_object($wpdb) && isset($wpdb->posts) ? (string) $wpdb->posts : 'wp_posts';
    foreach (SI\classify_content($content, $host) as [$suffix, $severity]) {
        if (isset($had[$suffix])) {
            continue;
        }
        $title = clip((string) ($post->post_title ?? ''), 120);
        record((string) $severity, 'post', 'Post #' . (int) $post_id . ($title !== '' ? ': ' . $title : ''), [
            'table' => $table,
            'entity_id' => (string) (int) $post_id,
            'rule_id' => 'posts.' . $suffix,
            'label' => 'post:' . $title,
            'by' => by(),
        ]);
    }
}

/** POST gobuildwp/v1/cloud/live: only the linked GoBuildWP Cloud token, signed like every Cloud write. */
function register_route(): void
{
    if (!function_exists('register_rest_route') || !function_exists('wppilot_cloud_rest_permission')) {
        return;
    }
    register_rest_route('gobuildwp/v1', '/cloud/live', [
        'methods' => 'POST',
        'callback' => __NAMESPACE__ . '\rest_live',
        'permission_callback' => 'wppilot_cloud_rest_permission',
    ]);
}

/**
 * One call does what the Cloud needs: change settings (optional), run a bounded change-watch step
 * (optional), then return the status and the events after `after`.
 *
 * @param mixed $request
 * @return mixed array|\WP_Error
 */
function rest_live(mixed $request): mixed
{
    /** @var mixed $body */
    $body = is_object($request) && method_exists($request, 'get_json_params') ? $request->get_json_params() : [];
    return handle_live(is_array($body) ? $body : []);
}

/**
 * @param array<string, mixed> $body after, limit, run, seconds, configure
 * @return array<string, mixed>|\WP_Error
 */
function handle_live(array $body, ?int $now = null): array|\WP_Error
{
    $after = $body['after'] ?? 0;
    $limit = $body['limit'] ?? 100;
    $seconds = $body['seconds'] ?? 10;
    if (!is_int($after) || $after < 0 || !is_int($limit) || $limit < 1 || $limit > MAX_EVENTS
        || !is_int($seconds) || $seconds < 1 || $seconds > MAX_RUN_SECONDS
        || (isset($body['run']) && !is_bool($body['run']))
        || (isset($body['configure']) && !is_array($body['configure']))) {
        return new \WP_Error('gobuildwp_live_invalid', 'Bad request.', ['status' => 400]);
    }
    $configured = null;
    if (isset($body['configure']) && $body['configure'] !== []) {
        $configured = configure($body['configure']);
        if ($configured instanceof \WP_Error) {
            return $configured;
        }
    }
    $run = null;
    if (($body['run'] ?? false) === true) {
        $run = run_watch($seconds, $now);
        $run = array_intersect_key($run, array_flip(['ran', 'reason', 'checked', 'changed', 'events']));
    }
    $events = events_since($after, $limit);
    return [
        'version' => 1,
        'status' => status(),
        'run' => $run,
        'configured' => $configured !== null,
        'events' => $events,
        'last_event_id' => last_event_id(),
        'more' => $events !== [] && (int) end($events)['id'] < last_event_id(),
    ];
}
