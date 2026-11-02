<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace GoBuildWP\Kits\SecurityIntegrity;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The database scan: fixed, prepared queries in chunks, with a cursor saved after every step.
 *
 * Values are read only to be judged here and are never returned: a result names a user login, an
 * option name, a post id, a role or a setting, with a rule id and at most a count or a date.
 * Options whose names look like they hold secrets are not looked at.
 */

const DB_AREAS = ['users', 'roles', 'urls', 'options', 'posts', 'cron', 'app_passwords', 'settings', 'config'];
const DB_LIGHT = ['users', 'app_passwords', 'roles', 'urls', 'settings', 'config'];
const DB_FULL = ['users', 'app_passwords', 'roles', 'urls', 'settings', 'config', 'options', 'posts', 'cron'];

/** Capabilities that make an account able to take over the site. */
const PRIVILEGED_CAPS = ['manage_options', 'edit_users', 'promote_users'];

/** Logins attackers try first, and that attackers' own accounts often use. */
const COMMON_LOGINS = ['admin', 'administrator', 'root', 'test', 'tester', 'user', 'wordpress', 'wpadmin', 'webmaster', 'demo', 'support', 'sysadmin'];

/** Capabilities a stock low role has; anything else granted to it was added later. */
const LOW_ROLE_CAPS = [
    'subscriber' => ['read', 'level_0'],
    'customer' => ['read'],
];

/** Roles WordPress and the most common plugins create. */
const KNOWN_ROLES = ['administrator', 'editor', 'author', 'contributor', 'subscriber', 'shop_manager', 'customer'];

/** Capabilities that, added to a low role, hand it control of content, users or code. */
const DANGEROUS_CAPS = [
    'manage_options', 'edit_users', 'promote_users', 'create_users', 'delete_users', 'list_users', 'edit_plugins', 'install_plugins',
    'activate_plugins', 'update_plugins', 'delete_plugins', 'edit_themes', 'install_themes', 'switch_themes', 'edit_theme_options',
    'edit_files', 'update_core', 'unfiltered_html', 'unfiltered_upload', 'import', 'export', 'edit_others_posts', 'edit_others_pages',
    'publish_posts', 'publish_pages', 'manage_network', 'manage_sites',
];

/**
 * Other WordPress core capabilities a low role doesn't have. Capabilities a plugin defines for its
 * own features (for example its own two-factor screen) are not counted on a low role.
 */
const CORE_ROLE_CAPS = [
    'edit_posts', 'delete_posts', 'upload_files', 'edit_published_posts', 'delete_published_posts', 'edit_pages', 'edit_published_pages',
    'delete_pages', 'delete_published_pages', 'edit_private_posts', 'edit_private_pages', 'read_private_posts', 'read_private_pages',
    'delete_private_posts', 'delete_private_pages', 'delete_others_posts', 'delete_others_pages', 'manage_categories', 'manage_links',
    'moderate_comments', 'edit_dashboard', 'customize', 'delete_site', 'delete_themes', 'update_themes',
];

/** IDs per query window when a table is read by primary key range. */
const ID_WINDOW = 2000;

/** Rows of option values looked at for these patterns are skipped when their name says they are ours. */
const OWN_OPTION_PREFIXES = ['gobuildwp_kit_si_', 'wppilot_kit_job_', 'wppilot_kit_sr_plan_', 'wppilot_preview_', '_transient_', '_site_transient_'];

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $state
 */
function db_init(array $payload, array &$state): void
{
    $areas = ($payload['mode'] ?? 'full') === 'light' ? DB_LIGHT : DB_FULL;
    foreach (DB_AREAS as $area) {
        $in = in_array($area, $areas, strict: true);
        $state['coverage'][$area] = [
            'state' => $in ? 'pending' : 'skipped',
            'checked' => 0,
            'skipped' => 0,
            'reason' => $in ? '' : 'Checked by a full database scan only.',
        ];
    }
    $state['db'] = ['areas' => $areas, 'area' => 0, 'cursor' => 0, 'sub' => '', 'privileged' => []];
}

/**
 * @param array<string, mixed> $detail
 * @return array<string, mixed>
 */
function db_item(string $area, string $rule, string $severity, string $table, string $id, string $label, array $detail = []): array
{
    return [
        'area' => $area,
        'rule_id' => $rule,
        'severity_hint' => $severity,
        'entity' => ['table' => $table, 'id' => $id, 'label' => mb_substr($label, 0, 191)],
        'detail' => (object) $detail,
    ];
}

/** @param array<string, mixed> $state */
function db_progress(array $state): float
{
    $db = $state['db'] ?? null;
    if (!is_array($db) || $db['areas'] === []) {
        return 0.0;
    }
    return min(1.0, (int) $db['area'] / count($db['areas']));
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $state
 */
function db_run(array $payload, array &$state, Budget $budget, Collector $out): bool
{
    $restore = limit_query_time($budget->limits['query_seconds']);
    try {
        $db = &$state['db'];
        while ($db['area'] < count($db['areas'])) {
            $area = (string) $db['areas'][$db['area']];
            $state['phase'] = $area;
            if ($budget->spent()) {
                return false;
            }
            $finished = match ($area) {
                'users' => area_users($db, $budget, $out, $state),
                'app_passwords' => area_app_passwords($db, $budget, $out, $state),
                'roles' => area_roles($out, $state),
                'urls' => area_urls($out, $state),
                'settings' => area_settings($out, $state),
                'config' => area_config($out),
                'options' => area_options($db, $budget, $out, $state),
                'posts' => area_posts($db, $budget, $out, $state),
                'cron' => area_cron($out, $state),
                default => true,
            };
            if (!$finished) {
                return false;
            }
            $out->finish($area);
            $db['area']++;
            $db['cursor'] = 0;
            $db['sub'] = '';
        }
        $state['phase'] = 'finished';
        return true;
    } finally {
        $restore();
    }
}

/**
 * Cap each query's run time where the database server supports it, and return a function that puts
 * the session back as it was.
 */
function limit_query_time(int $seconds): callable
{
    global $wpdb;
    if (!is_object($wpdb) || !method_exists($wpdb, 'db_server_info') || !method_exists($wpdb, 'suppress_errors')) {
        return static function (): void {};
    }
    $mariadb = stripos((string) $wpdb->db_server_info(), 'mariadb') !== false;
    $variable = $mariadb ? 'max_statement_time' : 'max_execution_time';
    $value = $mariadb ? $seconds : $seconds * 1000;
    $suppressed = $wpdb->suppress_errors(true);
    $previous = $wpdb->get_var('SELECT @@SESSION.' . $variable);
    if ($previous === null) {
        $wpdb->suppress_errors($suppressed);
        return static function (): void {};
    }
    $wpdb->query($wpdb->prepare('SET SESSION ' . $variable . ' = %d', $value));
    $wpdb->suppress_errors($suppressed);
    return static function () use ($wpdb, $variable, $previous): void {
        $suppressed = $wpdb->suppress_errors(true);
        // MariaDB's limit is a double and rejects a quoted value, which would leave the cap in place.
        $wpdb->query($wpdb->prepare('SET SESSION ' . $variable . ' = %f', (float) $previous));
        $wpdb->suppress_errors($suppressed);
    };
}

/** Read a stored value without creating objects from it. */
function safe_unserialize(mixed $value): mixed
{
    if (!is_string($value) || !is_serialized($value)) {
        return $value;
    }
    return @unserialize(trim($value), ['allowed_classes' => false]);
}

/** @param array<string, mixed> $state */
function rows_checked(array &$state, int $rows): void
{
    $state['rows_checked'] = (int) ($state['rows_checked'] ?? 0) + $rows;
}

/**
 * Roles whose capabilities include a privileged one.
 *
 * @return array<string, array<string, mixed>> all roles, slug => capabilities
 */
function role_map(): array
{
    global $wpdb;
    /** @var mixed $roles */
    $roles = get_option($wpdb->get_blog_prefix() . 'user_roles', []);
    $map = [];
    foreach (is_array($roles) ? $roles : [] as $slug => $role) {
        $map[(string) $slug] = is_array($role) && is_array($role['capabilities'] ?? null) ? $role['capabilities'] : [];
    }
    return $map;
}

/** @param array<string, mixed> $caps */
function grants_privilege(array $caps): bool
{
    foreach (PRIVILEGED_CAPS as $cap) {
        if (!empty($caps[$cap])) {
            return true;
        }
    }
    return false;
}

/** A login that reads like a generated one: long, letters and digits interleaved, or hex. */
function random_login(string $login): bool
{
    $login = strtolower($login);
    if (preg_match('/^[a-f0-9]{12,}$/', $login) === 1 && preg_match('/\d/', $login) === 1 && preg_match('/[a-f]/', $login) === 1) {
        return true;
    }
    if (strlen($login) < 8 || preg_match('/^[a-z0-9]+$/', $login) !== 1) {
        return false;
    }
    $digits = preg_match_all('/\d/', $login);
    $letters = preg_match_all('/[a-z]/', $login);
    $switches = preg_match_all('/(?<=\d)[a-z]|(?<=[a-z])\d/', $login);
    return $digits >= 3 && $letters >= 3 && $switches >= 4;
}

/** A login as a label; one that looks like an email address is replaced by the user id. */
function login_label(string $login, int $id): string
{
    return $login === '' || str_contains($login, '@') ? 'user #' . $id : $login;
}

/**
 * Accounts with a privileged role or capability, in chunks by user id.
 *
 * @param array<string, mixed> $db
 * @param array<string, mixed> $state
 */
function area_users(array &$db, Budget $budget, Collector $out, array &$state): bool
{
    global $wpdb;
    $roles = role_map();
    $key = $wpdb->get_blog_prefix() . 'capabilities';
    $limit = $budget->limits['rows'];
    while (!$budget->spent()) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id > %d ORDER BY user_id ASC LIMIT %d",
            $key,
            (int) $db['cursor'],
            $limit,
        ), ARRAY_A);
        if (!is_array($rows)) {
            $out->partial('users', 'The user list could not be read.');
            return true;
        }
        rows_checked($state, count($rows));
        $out->count('users', 'checked', count($rows));
        $privileged = [];
        foreach ($rows as $row) {
            $id = (int) $row['user_id'];
            $db['cursor'] = max((int) $db['cursor'], $id);
            $caps = safe_unserialize($row['meta_value']);
            if (!is_array($caps)) {
                continue;
            }
            $is = grants_privilege($caps);
            foreach ($caps as $name => $on) {
                if ($on && isset($roles[(string) $name]) && grants_privilege($roles[(string) $name])) {
                    $is = true;
                }
            }
            if ($is) {
                $privileged[] = $id;
            }
        }
        if ($privileged !== []) {
            report_privileged($privileged, $db, $out);
        }
        if (count($rows) < $limit) {
            $count = count($db['privileged']);
            $out->add(db_item('users', 'users.privileged_count', 'info', $wpdb->users, 'privileged', 'privileged users', ['count' => $count]));
            return true;
        }
    }
    return false;
}

/**
 * @param list<int> $ids
 * @param array<string, mixed> $db
 */
function report_privileged(array $ids, array &$db, Collector $out): void
{
    global $wpdb;
    $in = implode(',', array_fill(0, count($ids), '%d'));
    $users = $wpdb->get_results($wpdb->prepare("SELECT ID, user_login, user_registered FROM {$wpdb->users} WHERE ID IN ($in)", ...$ids), ARRAY_A);
    $sessions = $wpdb->get_col($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id IN ($in)",
        'session_tokens',
        ...$ids,
    ));
    $with_session = array_map('intval', is_array($sessions) ? $sessions : []);
    foreach (is_array($users) ? $users : [] as $user) {
        $id = (int) $user['ID'];
        $login = (string) $user['user_login'];
        $label = login_label($login, $id);
        $registered = (int) strtotime((string) $user['user_registered'] . ' UTC');
        $db['privileged'][(string) $id] = $label;
        if ($registered > time() - 30 * DAY_IN_SECONDS) {
            $out->add(db_item('users', 'users.new_admin', 'high', $wpdb->users, (string) $id, $label, ['registered' => $registered]));
        }
        if (in_array(strtolower($login), COMMON_LOGINS, strict: true)) {
            $out->add(db_item('users', 'users.common_admin_login', 'medium', $wpdb->users, (string) $id, $label, ['registered' => $registered]));
        } elseif (random_login($login)) {
            $out->add(db_item('users', 'users.random_login', 'medium', $wpdb->users, (string) $id, $label, ['registered' => $registered]));
        }
        if (!in_array($id, $with_session, strict: true)) {
            // WordPress keeps no last-login time; an account with no session record has not
            // logged in since sessions were last cleared.
            $out->add(db_item('users', 'users.admin_no_session', 'info', $wpdb->users, (string) $id, $label, ['registered' => $registered]));
        }
    }
}

/**
 * How many application passwords each privileged account has (the count only).
 *
 * @param array<string, mixed> $db
 * @param array<string, mixed> $state
 */
function area_app_passwords(array &$db, Budget $budget, Collector $out, array &$state): bool
{
    global $wpdb;
    if (!in_array('users', $db['areas'], strict: true)) {
        return true;
    }
    $ids = array_map('intval', array_keys($db['privileged']));
    $chunk = array_slice($ids, (int) $db['cursor'], $budget->limits['rows']);
    while ($chunk !== []) {
        if ($budget->spent()) {
            return false;
        }
        $in = implode(',', array_fill(0, count($chunk), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id IN ($in)",
            '_application_passwords',
            ...$chunk,
        ), ARRAY_A);
        rows_checked($state, is_array($rows) ? count($rows) : 0);
        $out->count('app_passwords', 'checked', count($chunk));
        foreach (is_array($rows) ? $rows : [] as $row) {
            $list = safe_unserialize($row['meta_value']);
            $count = is_array($list) ? count($list) : 0;
            if ($count > 0) {
                $id = (string) (int) $row['user_id'];
                $out->add(db_item('app_passwords', 'app_passwords.privileged_user', 'low', $wpdb->users, $id, (string) ($db['privileged'][$id] ?? 'user #' . $id), ['count' => $count]));
            }
        }
        $db['cursor'] = (int) $db['cursor'] + count($chunk);
        $chunk = array_slice($ids, (int) $db['cursor'], $budget->limits['rows']);
    }
    return true;
}

/** @param array<string, mixed> $state */
function area_roles(Collector $out, array &$state): bool
{
    global $wpdb;
    $roles = role_map();
    $table = $wpdb->options;
    $option = $wpdb->get_blog_prefix() . 'user_roles';
    if ($roles === []) {
        $out->coverage('roles', 'unavailable', 'The roles setting could not be read.');
        return true;
    }
    foreach ($roles as $slug => $caps) {
        $out->count('roles', 'checked');
        $granted = array_keys(array_filter($caps));
        if (isset(LOW_ROLE_CAPS[$slug])) {
            $added = array_values(array_filter(
                array_diff(array_map('strval', $granted), LOW_ROLE_CAPS[$slug]),
                static fn(string $cap): bool => in_array($cap, DANGEROUS_CAPS, strict: true) || in_array($cap, CORE_ROLE_CAPS, strict: true) || preg_match('/^level_([1-9]|10)$/', $cap) === 1,
            ));
            if ($added !== []) {
                $dangerous = array_intersect($added, DANGEROUS_CAPS) !== [];
                $out->add(db_item('roles', 'roles.low_role_elevated', $dangerous ? 'high' : 'medium', $table, $option, $slug, ['count' => count($added)]));
            }
        } elseif (!in_array($slug, KNOWN_ROLES, strict: true) && grants_privilege($caps)) {
            $out->add(db_item('roles', 'roles.unknown_admin_role', 'medium', $table, $option, $slug));
        }
    }
    return true;
}

/** The host of a URL, without a leading www. */
function url_host(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
}

/** @param array<string, mixed> $state */
function area_urls(Collector $out, array &$state): bool
{
    global $wpdb;
    $stored = [];
    foreach (['home', 'siteurl'] as $name) {
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
        $stored[$name] = is_string($value) ? $value : '';
        $out->count('urls', 'checked');
    }
    rows_checked($state, 2);
    $table = $wpdb->options;
    if ($stored['home'] !== '' && $stored['siteurl'] !== '' && url_host($stored['home']) !== url_host($stored['siteurl'])) {
        $out->add(db_item('urls', 'urls.host_mismatch', 'high', $table, 'siteurl', 'siteurl', ['reason' => 'The site address and the WordPress address point to different hosts.']));
    }
    foreach (['home' => 'WP_HOME', 'siteurl' => 'WP_SITEURL'] as $name => $constant) {
        if (defined($constant) && $stored[$name] !== '' && url_host((string) constant($constant)) !== url_host($stored[$name])) {
            $out->add(db_item('urls', 'urls.constant_mismatch', 'medium', 'config', $constant, $constant, ['reason' => 'The stored ' . $name . ' option points to a different host than ' . $constant . '.']));
        }
    }
    $effective = [
        'home' => defined('WP_HOME') ? (string) constant('WP_HOME') : $stored['home'],
        'siteurl' => defined('WP_SITEURL') ? (string) constant('WP_SITEURL') : $stored['siteurl'],
    ];
    $schemes = array_map(static fn(string $url): string => strtolower((string) parse_url($url, PHP_URL_SCHEME)), $effective);
    if ($schemes['home'] === 'http' && $schemes['siteurl'] === 'http') {
        $out->add(db_item('urls', 'urls.http', 'low', $table, 'home', 'home'));
    } elseif ($schemes['home'] !== '' && $schemes['siteurl'] !== '' && $schemes['home'] !== $schemes['siteurl']) {
        $out->add(db_item('urls', 'urls.mixed_scheme', 'low', $table, 'siteurl', 'siteurl'));
    }
    return true;
}

/** @param array<string, mixed> $state */
function area_settings(Collector $out, array &$state): bool
{
    global $wpdb;
    $out->count('settings', 'checked', 2);
    if (!get_option('users_can_register')) {
        return true;
    }
    $role = (string) get_option('default_role', 'subscriber');
    if ($role === 'subscriber' || $role === '') {
        return true;
    }
    $roles = role_map();
    $privileged = isset($roles[$role]) && grants_privilege($roles[$role]);
    $out->add(db_item('settings', 'settings.open_registration_role', $privileged ? 'critical' : 'high', $wpdb->options, 'default_role', $role));
    return true;
}

function area_config(Collector $out): bool
{
    foreach (config_results() as $item) {
        $out->add($item);
    }
    $out->count('config', 'checked', 8);
    return true;
}

/** Whether an option name may hold a secret and must not be looked at. */
function is_secret_name(string $name): bool
{
    return preg_match('/pass(word|wd)?|pwd|secret|token|key|salt|nonce|hash|auth|credential|licen[cs]e|private|cookie|session|api|smtp|oauth|bearer|webhook|signature|e_?mail/i', $name) === 1;
}

function skipped_option(string $name): bool
{
    foreach (OWN_OPTION_PREFIXES as $prefix) {
        if (str_starts_with($name, $prefix)) {
            return true;
        }
    }
    return str_starts_with($name, 'widget_') || is_secret_name($name);
}

/**
 * Option values with script, eval, base64_decode or iframes, by name only; then autoload size and
 * active plugins whose files are gone.
 *
 * @param array<string, mixed> $db
 * @param array<string, mixed> $state
 */
function area_options(array &$db, Budget $budget, Collector $out, array &$state): bool
{
    global $wpdb;
    $table = $wpdb->options;
    if ($db['sub'] === '') {
        $db['max'] = (int) $wpdb->get_var("SELECT MAX(option_id) FROM {$wpdb->options}");
        $db['sub'] = 'scan';
    }
    $likes = [
        '%' . $wpdb->esc_like('<script') . '%',
        '%' . $wpdb->esc_like('eval(') . '%',
        '%' . $wpdb->esc_like('base64_decode') . '%',
        '%' . $wpdb->esc_like('<iframe') . '%',
    ];
    $rules = [
        's' => ['options.script_tag', 'medium'],
        'e' => ['options.eval', 'high'],
        'b' => ['options.base64_decode', 'medium'],
        'i' => ['options.iframe', 'low'],
    ];
    [$links, $link_args] = link_filter('option_value');
    $site_host = url_host((string) home_url());
    while ($db['sub'] === 'scan') {
        if ((int) $db['cursor'] >= (int) $db['max']) {
            $db['sub'] = 'extra';
            break;
        }
        if ($budget->spent()) {
            return false;
        }
        $from = (int) $db['cursor'] + 1;
        $to = (int) $db['cursor'] + ID_WINDOW;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_name, option_value LIKE %s AS s, option_value LIKE %s AS e, option_value LIKE %s AS b, option_value LIKE %s AS i, {$links} AS a
             FROM {$wpdb->options}
             WHERE option_id BETWEEN %d AND %d AND (option_value LIKE %s OR option_value LIKE %s OR option_value LIKE %s OR option_value LIKE %s OR {$links})
             ORDER BY option_id ASC",
            ...[...$likes, ...$link_args, $from, $to, ...$likes, ...$link_args], // kit-lint: list-spread
        ), ARRAY_A);
        if (!is_array($rows)) {
            $out->partial('options', 'Some options could not be read.');
            $rows = [];
        }
        $out->count('options', 'checked', min(ID_WINDOW, max(0, (int) $db['max'] - (int) $db['cursor'])));
        rows_checked($state, count($rows));
        foreach ($rows as $row) {
            $name = (string) $row['option_name'];
            if (skipped_option($name)) {
                continue;
            }
            foreach ($rules as $flag => [$rule, $severity]) {
                if ((int) $row[$flag] === 1) {
                    $out->add(db_item('options', $rule, $severity, $table, $name, $name));
                }
            }
            if ((int) ($row['a'] ?? 0) === 1) {
                $value = $wpdb->get_var($wpdb->prepare("SELECT SUBSTRING(option_value, 1, %d) FROM {$wpdb->options} WHERE option_id = %d", RULE_READ_BYTES, (int) $row['option_id']));
                foreach (classify_links(is_string($value) ? $value : '', $site_host) as [$rule, $severity]) {
                    $out->add(db_item('options', 'options.' . $rule, $severity, $table, $name, $name));
                }
            }
        }
        $db['cursor'] = $to;
    }
    $autoload = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN (%s, %s, %s, %s)",
        'yes',
        'on',
        'auto-on',
        'auto',
    ));
    if ($autoload > 1024 * 1024) {
        $out->add(db_item('options', 'options.autoload_large', 'low', $table, 'autoload', 'autoloaded options', ['count' => $autoload]));
    }
    /** @var mixed $active */
    $active = get_option('active_plugins', []);
    $plugins_dir = roots()['plugins'];
    $missing = 0;
    foreach (is_array($active) ? $active : [] as $file) {
        $file = (string) $file;
        if ($plugins_dir !== '' && ($file === '' || has_parent_segment($file) || !is_file($plugins_dir . '/' . $file))) {
            $missing++;
        }
    }
    if ($missing > 0) {
        $out->add(db_item('options', 'options.active_plugin_missing', 'medium', $table, 'active_plugins', 'active_plugins', ['count' => $missing]));
    }
    return true;
}

/**
 * What a piece of post, comment or widget content holds, judged here; only rule ids leave this
 * function. $blobs also looks for a packed base64 payload, which only post content is checked for;
 * $links looks for hidden or spam links to other sites (off when judging a lone script or iframe).
 *
 * @return list<array{0: string, 1: string}> rule suffix, severity
 */
function classify_content(string $content, string $site_host, bool $blobs = false, bool $links = true): array
{
    $found = [];
    $previous = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '100000');
    try {
        if (preg_match_all('/<script\b[^>]*\bsrc\s*=\s*["\']?\s*(?:https?:)?\/\/([^\/"\'\s>]+)/i', $content, $matches) > 0) {
            foreach ($matches[1] as $host) {
                $host = strtolower((string) $host);
                $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
                if ($host !== $site_host && !str_ends_with($host, '.' . $site_host)) {
                    $found[] = ['external_script', 'medium'];
                    break;
                }
            }
        }
        if (preg_match('/<iframe\b[^>]*(?:display\s*:\s*none|visibility\s*:\s*hidden|\b(?:width|height)\s*=\s*["\']?\s*[01](?:px)?\b|\b(?:width|height)\s*:\s*[01]px)/i', $content) === 1) {
            $found[] = ['hidden_iframe', 'high'];
        }
        if (preg_match('/\beval\s*\(\s*(?:String\.fromCharCode|atob|unescape|decodeURIComponent)\s*\(|document\.write\s*\(\s*unescape\s*\(|\\\\x[0-9a-f]{2}(?:\\\\x[0-9a-f]{2}){40,}/i', $content) === 1) {
            $found[] = ['obfuscated_js', 'high'];
        } elseif (preg_match('/<script\b[^>]*>[^<]{0,5000}?\beval\s*\(/i', $content) === 1) {
            $found[] = ['eval', 'medium'];
        }
        // Inline scripts that name another site: reading cookies there is the usual session-theft
        // shape, sending the visitor's browser there the usual redirect injection.
        $exfil = false;
        $redirect = false;
        if (preg_match_all('/<script\b[^>]*>([^<]{0,5000})/i', $content, $scripts) > 0) {
            foreach ($scripts[1] as $body) {
                $cookie = stripos($body, 'document.cookie') !== false;
                $moves = preg_match('/\b(?:window\.|document\.|top\.|self\.)?location(?:\.href)?\s*=(?!=)|\blocation\.(?:replace|assign)\s*\(/i', $body) === 1;
                if ((!$cookie && !$moves) || preg_match_all('/(?:https?:)?\/\/([a-z0-9.-]+\.[a-z]{2,})/i', $body, $urls) < 1) {
                    continue;
                }
                foreach ($urls[1] as $host) {
                    $host = strtolower((string) $host);
                    $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
                    if ($host !== $site_host && !str_ends_with($host, '.' . $site_host)) {
                        $exfil = $exfil || $cookie;
                        $redirect = $redirect || $moves;
                        break;
                    }
                }
            }
        }
        if ($exfil) {
            $found[] = ['cookie_exfil', 'high'];
        }
        if ($redirect) {
            $found[] = ['redirect', 'high'];
        }
        if ($links) {
            array_push($found, ...classify_links($content, $site_host));
        }
        if ($blobs && preg_match(BASE64_BLOB, $content) === 1) {
            $found[] = ['base64_blob', 'medium'];
        }
    } finally {
        if (is_string($previous)) {
            ini_set('pcre.backtrack_limit', $previous);
        }
    }
    return $found;
}

/**
 * Words that mark a link as paid spam when they appear, as whole words, in its text or address.
 * SPAM_LINK_WORDS are the same words as plain substrings, for the database pre-filter.
 */
const SPAM_LINK_PATTERN = '/\b(?:viagra|cialis|levitra|kamagra|casinos?|gambling|betting|sportsbook|roulette|mostbet|1win|pinco|payday[\s_-]*loans?|porn(?:o|hub)?|replicas?|essay[\s_-]*writ(?:ing|ers?)|xanax|tramadol|phentermine)\b/i';
const SPAM_LINK_WORDS = ['viagra', 'cialis', 'levitra', 'kamagra', 'casino', 'gambling', 'betting', 'sportsbook', 'roulette', 'mostbet', '1win', 'pinco', 'payday', 'porn', 'replica', 'essay', 'xanax', 'tramadol', 'phentermine'];

/** Inline style fragments that may hide an element, for the database pre-filter; HIDDEN_STYLE decides. */
const HIDDEN_STYLE_HINTS = [
    'display:none', 'display: none', 'visibility:hidden', 'visibility: hidden', 'font-size:0', 'font-size: 0',
    'text-indent:-', 'text-indent: -', 'position:absolute', 'position: absolute', 'height:0', 'height: 0',
];

/**
 * An inline style that keeps an element's content from being seen: display none, visibility
 * hidden, a zero font size, a large negative indent or absolute offset, or a zero height that
 * hides overflow.
 */
const HIDDEN_STYLE = '/display\s*:\s*none|visibility\s*:\s*hidden|font-size\s*:\s*0+(?:\.0+)?(?:px|pt|em|rem|%)?\s*(?:;|!|$)|text-indent\s*:\s*-\s*\d{3,}|position\s*:\s*absolute.*?(?<![\w-])(?:left|top)\s*:\s*-\s*\d{3,}|(?<![\w-])(?:left|top)\s*:\s*-\s*\d{3,}.*?position\s*:\s*absolute|(?<![\w-])height\s*:\s*0(?:px)?\s*(?:;|!|$).*?overflow\s*:\s*hidden|overflow\s*:\s*hidden.*?(?<![\w-])height\s*:\s*0(?:px)?\s*(?:;|!|$)/is';

/** Elements without content: a hidden one hides nothing after it. */
const VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

/** A run of base64 this long outside a data: URI is a packed payload, not text. */
const BASE64_BLOB = '/(?<![A-Za-z0-9+\/])(?<!base64,)[A-Za-z0-9+\/]{2048,}+={0,2}/';

/** Whether a host (from a URL) is neither the site's nor one of its subdomains. */
function foreign_host(string $host, string $site_host): bool
{
    $host = strtolower(rtrim($host, '.'));
    $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    return $host !== '' && $host !== $site_host && !str_ends_with($host, '.' . $site_host);
}

/**
 * The link-spam a piece of content holds: a link to another site inside an element styled to be
 * invisible (high), or a link to another site whose text or address names a spam product
 * (medium, reported only when no hidden link was found).
 *
 * @return list<array{0: string, 1: string}> rule suffix, severity
 */
function classify_links(string $content, string $site_host): array
{
    if (stripos($content, '<a') === false) {
        return [];
    }
    if (preg_match_all('/<([a-z][a-z0-9]{0,15})\b[^>]{0,1000}?\bstyle\s*=\s*(["\'])(.{0,1000}?)\2[^>]{0,1000}>/is', $content, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
        foreach ($tags as $tag) {
            $name = strtolower((string) $tag[1][0]);
            if (in_array($name, VOID_ELEMENTS, strict: true) || preg_match(HIDDEN_STYLE, (string) $tag[3][0]) !== 1) {
                continue;
            }
            $region = $name === 'a' ? (string) $tag[0][0] : element_body($content, $name, (int) $tag[0][1] + strlen((string) $tag[0][0]));
            if (foreign_link_in($region, $site_host)) {
                return [['hidden_link', 'high']];
            }
        }
    }
    if (preg_match_all('/<a\b([^>]{0,2000})>(.{0,1000}?)<\/a\s*>/is', $content, $links, PREG_SET_ORDER) > 0) {
        foreach ($links as $link) {
            if (preg_match('/\bhref\s*=\s*["\']?\s*((?:https?:)?\/\/([^\/"\'\s>?#:]+)[^"\'\s>]*)/i', (string) $link[1], $href) !== 1
                || !foreign_host((string) $href[2], $site_host)) {
                continue;
            }
            if (preg_match(SPAM_LINK_PATTERN, strip_tags((string) $link[2])) === 1 || preg_match(SPAM_LINK_PATTERN, rawurldecode((string) $href[1])) === 1) {
                return [['spam_link', 'medium']];
            }
        }
    }
    return [];
}

/**
 * What an element holds, from just after its opening tag to its matching closing tag, at most
 * 20 KB; empty when the closing tag is not found in that span.
 */
function element_body(string $content, string $name, int $after): string
{
    $window = substr($content, $after, 20000);
    if (preg_match_all('#<(/?)' . preg_quote($name, '#') . '\b#i', $window, $marks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
        $depth = 0;
        foreach ($marks as $mark) {
            if ($mark[1][0] === '') {
                $depth++;
                continue;
            }
            if ($depth === 0) {
                return substr($window, 0, (int) $mark[0][1]);
            }
            $depth--;
        }
    }
    return '';
}

/** Whether markup holds a link to another site. */
function foreign_link_in(string $markup, string $site_host): bool
{
    if (preg_match_all('/<a\b[^>]{0,1000}?\bhref\s*=\s*["\']?\s*(?:https?:)?\/\/([^\/"\'\s>?#:]+)/i', $markup, $matches) < 1) {
        return false;
    }
    foreach ($matches[1] as $host) {
        if (foreign_host((string) $host, $site_host)) {
            return true;
        }
    }
    return false;
}

/**
 * SQL that keeps rows whose column may hold link spam: a link plus a hiding style or a spam word.
 * Only a pre-filter; classify_links() decides.
 *
 * @return array{0: string, 1: list<string>} the condition and its LIKE arguments, in order
 */
function link_filter(string $column): array
{
    global $wpdb;
    $words = array_merge(HIDDEN_STYLE_HINTS, SPAM_LINK_WORDS);
    $args = ['%' . $wpdb->esc_like('<a ') . '%'];
    foreach ($words as $word) {
        $args[] = '%' . $wpdb->esc_like($word) . '%';
    }
    $sql = "({$column} LIKE %s AND (" . implode(' OR ', array_fill(0, count($words), "{$column} LIKE %s")) . '))';
    return [$sql, $args];
}

/** Flatten a widget's settings to the strings in them. */
function strings_in(mixed $value, int $depth = 0): string
{
    if (is_string($value)) {
        return $value . "\n";
    }
    if (!is_array($value) || $depth > 5) {
        return '';
    }
    $text = '';
    foreach ($value as $item) {
        $text .= strings_in($item, $depth + 1);
        if (strlen($text) > RULE_READ_BYTES) {
            break;
        }
    }
    return $text;
}

/**
 * Posts (published and drafts) and widgets with external scripts, hidden iframes or obfuscated
 * script. A database pattern narrows the rows; the content of those few is judged here.
 *
 * @param array<string, mixed> $db
 * @param array<string, mixed> $state
 */
function area_posts(array &$db, Budget $budget, Collector $out, array &$state): bool
{
    global $wpdb;
    $site_host = url_host((string) home_url());
    if ($db['sub'] === '') {
        $db['max'] = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");
        $db['sub'] = 'posts';
    }
    $likes = [
        '%' . $wpdb->esc_like('<script') . '%',
        '%' . $wpdb->esc_like('<iframe') . '%',
        '%' . $wpdb->esc_like('eval(') . '%',
    ];
    [$content_links, $content_link_args] = link_filter('post_content');
    [$excerpt_links, $excerpt_link_args] = link_filter('post_excerpt');
    while ($db['sub'] === 'posts') {
        if ((int) $db['cursor'] >= (int) $db['max']) {
            $db['sub'] = 'widgets';
            $db['cursor'] = 0;
            break;
        }
        if ($budget->spent()) {
            return false;
        }
        $from = (int) $db['cursor'] + 1;
        $to = (int) $db['cursor'] + ID_WINDOW;
        // Title and excerpt are matched like the content; a base64 run of 255 characters (the
        // longest repeat every MySQL and MariaDB regex engine accepts) marks a possible payload.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE ID BETWEEN %d AND %d AND post_status IN (%s, %s, %s, %s, %s) AND post_type <> %s
               AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s
                 OR post_title LIKE %s OR post_title LIKE %s OR post_title LIKE %s
                 OR post_excerpt LIKE %s OR post_excerpt LIKE %s OR post_excerpt LIKE %s
                 OR {$content_links} OR {$excerpt_links} OR post_content REGEXP %s)
             ORDER BY ID ASC LIMIT %d",
            $from,
            $to,
            'publish',
            'draft',
            'pending',
            'private',
            'future',
            'revision',
            ...[...$likes, ...$likes, ...$likes, ...$content_link_args, ...$excerpt_link_args, '[A-Za-z0-9+/]{255}', $budget->limits['rows']], // kit-lint: list-spread
        ));
        if (!is_array($ids)) {
            $out->partial('posts', 'Some posts could not be read.');
            $ids = [];
        }
        $out->count('posts', 'checked', min(ID_WINDOW, max(0, (int) $db['max'] - (int) $db['cursor'])));
        rows_checked($state, count($ids));
        foreach ($ids as $id) {
            $content = $wpdb->get_var($wpdb->prepare(
                "SELECT CONCAT_WS(%s, post_title, SUBSTRING(post_excerpt, 1, %d), SUBSTRING(post_content, 1, %d)) FROM {$wpdb->posts} WHERE ID = %d",
                "\n",
                RULE_READ_BYTES,
                RULE_READ_BYTES,
                (int) $id,
            ));
            foreach (classify_content(is_string($content) ? $content : '', $site_host, blobs: true) as [$rule, $severity]) {
                $out->add(db_item('posts', 'posts.' . $rule, $severity, $wpdb->posts, (string) (int) $id, (string) (int) $id));
            }
        }
        // A window with more matches than one chunk holds is finished from its last id next time.
        $db['cursor'] = count($ids) >= $budget->limits['rows'] ? (int) end($ids) : $to;
    }
    if ($db['sub'] === 'widgets') {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC",
            $wpdb->esc_like('widget_') . '%',
        ), ARRAY_A);
        rows_checked($state, is_array($rows) ? count($rows) : 0);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $name = (string) $row['option_name'];
            $text = substr(strings_in(safe_unserialize($row['option_value'])), 0, RULE_READ_BYTES);
            foreach (classify_content($text, $site_host) as [$rule, $severity]) {
                $out->add(db_item('posts', 'widgets.' . $rule, $severity, $wpdb->options, $name, $name));
            }
        }
        $db['sub'] = 'comments';
        $db['cursor'] = 0;
        $db['max'] = (int) $wpdb->get_var("SELECT MAX(comment_ID) FROM {$wpdb->comments}");
    }
    if ($db['sub'] === 'comments' && !area_comments($db, $budget, $out, $state, $site_host, $likes)) {
        return false;
    }
    if ($db['sub'] === 'postmeta' && !area_postmeta($db, $budget, $out, $state, $site_host)) {
        return false;
    }
    return true;
}

/**
 * Approved and pending comments, judged like post content. Spam and trashed comments are never
 * shown, so they are not read; a spam link in a pending comment is the moderation queue doing
 * its job, so only approved comments are reported for one (held_comments() reports a spam run).
 *
 * @param array<string, mixed> $db
 * @param array<string, mixed> $state
 * @param list<string> $likes
 */
function area_comments(array &$db, Budget $budget, Collector $out, array &$state, string $site_host, array $likes): bool
{
    global $wpdb;
    [$links, $link_args] = link_filter('comment_content');
    while ((int) $db['cursor'] < (int) $db['max']) {
        if ($budget->spent()) {
            return false;
        }
        $from = (int) $db['cursor'] + 1;
        $to = (int) $db['cursor'] + ID_WINDOW;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT comment_ID, comment_approved FROM {$wpdb->comments}
             WHERE comment_ID BETWEEN %d AND %d AND comment_approved IN (%s, %s)
               AND (comment_content LIKE %s OR comment_content LIKE %s OR comment_content LIKE %s OR {$links})
             ORDER BY comment_ID ASC LIMIT %d",
            $from,
            $to,
            '1',
            '0',
            ...[...$likes, ...$link_args, $budget->limits['rows']], // kit-lint: list-spread
        ), ARRAY_A);
        if (!is_array($rows)) {
            $out->partial('posts', 'Some comments could not be read.');
            $rows = [];
        }
        $out->count('posts', 'checked', min(ID_WINDOW, max(0, (int) $db['max'] - (int) $db['cursor'])));
        rows_checked($state, count($rows));
        $last = 0;
        foreach ($rows as $row) {
            $id = (int) $row['comment_ID'];
            $last = $id;
            $content = $wpdb->get_var($wpdb->prepare("SELECT SUBSTRING(comment_content, 1, %d) FROM {$wpdb->comments} WHERE comment_ID = %d", RULE_READ_BYTES, $id));
            foreach (classify_content(is_string($content) ? $content : '', $site_host) as [$rule, $severity]) {
                if ($rule === 'spam_link' && (string) $row['comment_approved'] !== '1') {
                    continue;
                }
                $out->add(db_item('posts', 'comments.' . $rule, $severity, $wpdb->comments, (string) $id, (string) $id));
            }
        }
        $db['cursor'] = count($rows) >= $budget->limits['rows'] ? $last : $to;
    }
    held_comments($out);
    $db['sub'] = 'postmeta';
    $db['cursor'] = 0;
    $db['max'] = (int) $wpdb->get_var("SELECT MAX(meta_id) FROM {$wpdb->postmeta}");
    return true;
}

/** Held comments with links in the last HELD_WINDOW_DAYS that mark a spam run reaching the site. */
const HELD_WINDOW_DAYS = 7;
const HELD_LINKED_MIN = 25;

/**
 * A spam run held for moderation. One held spam comment is the queue doing its job, so the
 * per-comment check skips them; dozens a week with links means bots have found the site, the
 * queue fills faster than anyone clears it, and one wrong click publishes them. Counts only, no
 * content: a link in the text or in the author's website field is what this spam carries.
 */
function held_comments(Collector $out): void
{
    global $wpdb;
    $since = gmdate('Y-m-d H:i:s', time() - HELD_WINDOW_DAYS * 86400);
    $linked = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->comments}
         WHERE comment_approved = %s AND comment_date_gmt >= %s
           AND (comment_content LIKE %s OR comment_content LIKE %s OR comment_author_url <> %s)",
        '0',
        $since,
        '%' . $wpdb->esc_like('http') . '%',
        '%' . $wpdb->esc_like('www.') . '%',
        '',
    ));
    if ($linked < HELD_LINKED_MIN) {
        return;
    }
    $waiting = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s", '0'));
    $out->add(db_item('posts', 'comments.held_spam', 'low', $wpdb->comments, 'held', 'comments waiting for moderation', [
        'count' => $linked,
        'reason' => sprintf(
            '%d comments with links arrived for moderation in the last %d days (%d waiting in all): spam is reaching the site. A spam filter would stop it before the queue.',
            $linked,
            HELD_WINDOW_DAYS,
            max($waiting, $linked),
        ),
    ]));
}

/**
 * Post meta keys whose values are not page content: caches WordPress fills itself (oEmbed HTML
 * from YouTube or X carries their scripts), attachment data, editor bookkeeping, and anything
 * named like a secret.
 */
function skipped_meta(string $key): bool
{
    return str_starts_with($key, '_oembed_')
        || in_array($key, ['_wp_attachment_metadata', '_wp_attached_file', '_edit_lock', '_edit_last', '_wp_old_slug', '_thumbnail_id', '_wp_page_template', '_elementor_css'], strict: true)
        || is_secret_name($key);
}

/**
 * Post meta values (page-builder data, custom fields) with scripts, iframes, eval( or
 * base64_decode, by meta key and post id only.
 *
 * @param array<string, mixed> $db
 * @param array<string, mixed> $state
 */
function area_postmeta(array &$db, Budget $budget, Collector $out, array &$state, string $site_host): bool
{
    global $wpdb;
    $likes = [
        '%' . $wpdb->esc_like('<script') . '%',
        '%' . $wpdb->esc_like('<iframe') . '%',
        '%' . $wpdb->esc_like('eval(') . '%',
        '%' . $wpdb->esc_like('base64_decode') . '%',
    ];
    while ((int) $db['cursor'] < (int) $db['max']) {
        if ($budget->spent()) {
            return false;
        }
        $from = (int) $db['cursor'] + 1;
        $to = (int) $db['cursor'] + ID_WINDOW;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, post_id, meta_key FROM {$wpdb->postmeta}
             WHERE meta_id BETWEEN %d AND %d AND meta_key NOT LIKE %s
               AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)
             ORDER BY meta_id ASC LIMIT %d",
            $from,
            $to,
            $wpdb->esc_like('_oembed_') . '%',
            ...[...$likes, $budget->limits['rows']], // kit-lint: list-spread
        ), ARRAY_A);
        if (!is_array($rows)) {
            $out->partial('posts', 'Some post meta could not be read.');
            $rows = [];
        }
        $out->count('posts', 'checked', min(ID_WINDOW, max(0, (int) $db['max'] - (int) $db['cursor'])));
        rows_checked($state, count($rows));
        $last = 0;
        foreach ($rows as $row) {
            $id = (int) $row['meta_id'];
            $last = $id;
            $key = (string) $row['meta_key'];
            if (skipped_meta($key)) {
                continue;
            }
            $value = $wpdb->get_var($wpdb->prepare("SELECT SUBSTRING(meta_value, 1, %d) FROM {$wpdb->postmeta} WHERE meta_id = %d", RULE_READ_BYTES, $id));
            $value = is_string($value) ? $value : '';
            $found = classify_content($value, $site_host);
            if (stripos($value, 'base64_decode') !== false) {
                $found[] = ['base64_decode', 'medium'];
            }
            foreach ($found as [$rule, $severity]) {
                $out->add(db_item('posts', 'postmeta.' . $rule, $severity, $wpdb->postmeta, (string) $id, 'post ' . (int) $row['post_id'] . ' ' . $key));
            }
        }
        $db['cursor'] = count($rows) >= $budget->limits['rows'] ? $last : $to;
    }
    $db['sub'] = 'done';
    return true;
}

/**
 * Scheduled events whose hook nothing handles, and events whose arguments point at another host.
 *
 * @param array<string, mixed> $state
 * @param array<int, mixed>|null $crons the cron array; read from WordPress when null
 */
function area_cron(Collector $out, array &$state, ?array $crons = null): bool
{
    global $wpdb;
    if ($crons === null) {
        $crons = function_exists('_get_cron_array') ? _get_cron_array() : [];
    }
    if (!is_array($crons)) {
        $out->coverage('cron', 'unavailable', 'The scheduled events could not be read.');
        return true;
    }
    $site_host = url_host((string) home_url());
    $seen = [];
    foreach ($crons as $hooks) {
        if (!is_array($hooks)) {
            continue;
        }
        foreach ($hooks as $hook => $events) {
            $hook = (string) $hook;
            foreach (is_array($events) ? $events : [] as $event) {
                $out->count('cron', 'checked');
                if (!isset($seen['no_callback'][$hook]) && !has_action($hook)) {
                    $seen['no_callback'][$hook] = true;
                    $out->add(db_item('cron', 'cron.no_callback', 'low', $wpdb->options, 'cron', $hook));
                }
                $args = is_array($event) && is_array($event['args'] ?? null) ? (string) wp_json_encode($event['args']) : '';
                if (!isset($seen['remote'][$hook]) && foreign_url_in($args, $site_host)) {
                    $seen['remote'][$hook] = true;
                    $out->add(db_item('cron', 'cron.remote_url_args', 'medium', $wpdb->options, 'cron', $hook));
                }
            }
        }
    }
    rows_checked($state, 1);
    return true;
}

/** Whether JSON-encoded cron arguments carry a URL on a host other than the site's (or its subdomains). */
function foreign_url_in(string $json, string $site_host): bool
{
    if (preg_match_all('#https?:\\\\?/\\\\?/([^/\\\\"\s]+)#i', $json, $matches) < 1) {
        return false;
    }
    foreach ($matches[1] as $host) {
        $host = strtolower((string) $host);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        if ($host !== $site_host && !str_ends_with($host, '.' . $site_host)) {
            return true;
        }
    }
    return false;
}
