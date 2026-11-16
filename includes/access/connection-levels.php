<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Connection levels: an access level per OAuth app and application password.
 *
 * Access tokens already carry a ceiling and a scope (includes/agent-identities.php). An OAuth app
 * or an application password carried its user's full access, so the only way to limit Claude.ai
 * was to limit the whole site. Each of those connections can now be given a level:
 *
 * - Full: what the site's safety profile and the user's role allow (the default);
 * - Production Safe, Drafts only or Read only: the same ceilings a token can have;
 * - Custom: an allowlist of ability categories, on top of the ceiling.
 *
 * A level is enforced by the token machinery itself: wppilot_current_token_policy() returns this
 * connection's policy when no access token is in play, so discovery, the safety profile, Drafts
 * only and the permission filter all answer the same way without a check of their own.
 *
 * The rules:
 * - A level can only be narrowed. Widening needs the credential revoked and connected again,
 *   which needs a person: OAuth consent, or a new application password. Revoking a credential
 *   forgets its level, since the credential is gone.
 * - A stored level the code does not recognise means Read only.
 * - An OAuth grant of `mcp:read` is Read only, whatever level is stored.
 * - Every change is written to the ledger, marked irreversible so an undo (which would widen)
 *   cannot be run from the Changes screen or by an agent.
 * - Levels are set in wp-admin only. No ability can read or change them.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_CONNECTION_LEVELS_OPTION = 'wppilot_connection_levels';

const WPPILOT_CONNECTION_LEVEL_SAVE_ACTION = 'wppilot_connection_level_save';

const WPPILOT_OAUTH_READ_SCOPE = 'mcp:read';

/**
 * Ceilings a connection may have, widest first. '' is Full.
 *
 * @return list<string>
 */
function wppilot_connection_level_ids(): array
{
    return ['', 'production', 'drafts', 'readonly'];
}

/**
 * How narrow a ceiling is: higher is narrower. Anything unknown is as narrow as Read only.
 */
function wppilot_connection_level_rank(string $ceiling): int
{
    $rank = array_search($ceiling, wppilot_connection_level_ids(), strict: true);

    return is_int($rank) ? $rank : 3;
}

/**
 * The stricter of two ceilings.
 */
function wppilot_connection_level_stricter(string $a, string $b): string
{
    return wppilot_connection_level_rank($a) >= wppilot_connection_level_rank($b)
        ? (in_array($a, wppilot_connection_level_ids(), strict: true) ? $a : 'readonly')
        : $b;
}

/**
 * Level labels for the screen and for refusals, keyed by ceiling.
 *
 * @return array<string, string>
 */
function wppilot_connection_level_labels(): array
{
    $profiles = function_exists('wppilot_safety_profiles') ? wppilot_safety_profiles() : [];

    return [
        '' => __('Full (site profile and the user\'s role)', domain: 'wppilot'),
        'production' => (string) ($profiles['production']['label'] ?? __('Production Safe', domain: 'wppilot')),
        'drafts' => __('Drafts only (read, and write drafts)', domain: 'wppilot'),
        'readonly' => (string) ($profiles['readonly']['label'] ?? __('Read Only', domain: 'wppilot')),
    ];
}

/**
 * A level as people read it: the ceiling, plus the categories when it is Custom.
 *
 * @param array{ceiling: string, categories: list<string>|null} $level
 */
function wppilot_connection_level_label(array $level): string
{
    $labels = wppilot_connection_level_labels();
    $label = $labels[$level['ceiling']] ?? $labels['readonly'];
    if ($level['categories'] === null) {
        return $label;
    }

    return sprintf(
        /* translators: 1: level label, 2: comma-separated ability categories */
        __('Custom: %1$s, categories %2$s', domain: 'wppilot'),
        $label,
        implode(', ', $level['categories']),
    );
}

/**
 * Read a stored level into its canonical shape. Fails closed: anything malformed is Read only.
 *
 * @return array{ceiling: string, categories: list<string>|null, label: string, set_by: int, set_at: int}
 */
function wppilot_connection_level_normalize(mixed $row): array
{
    $closed = ['ceiling' => 'readonly', 'categories' => null, 'label' => '', 'set_by' => 0, 'set_at' => 0];
    if (!is_array($row)) {
        return $closed;
    }
    $ceiling = $row['ceiling'] ?? null;
    if (!is_string($ceiling) || !in_array($ceiling, wppilot_connection_level_ids(), strict: true)) {
        $ceiling = 'readonly';
    }
    $categories = null;
    if (array_key_exists('categories', $row) && $row['categories'] !== null) {
        $categories = is_array($row['categories'])
            ? wppilot_connection_level_clean_categories($row['categories'])
            : [];
        // An empty allowlist allows nothing; keep it as such rather than as "every category".
    }

    return [
        'ceiling' => $ceiling,
        'categories' => $categories,
        'label' => is_string($row['label'] ?? null) ? $row['label'] : '',
        'set_by' => is_numeric($row['set_by'] ?? null) ? (int) $row['set_by'] : 0,
        'set_at' => is_numeric($row['set_at'] ?? null) ? (int) $row['set_at'] : 0,
    ];
}

/**
 * @param array<array-key, mixed> $categories
 * @return list<string>
 */
function wppilot_connection_level_clean_categories(array $categories): array
{
    $clean = [];
    foreach ($categories as $category) {
        if (is_string($category) && sanitize_key($category) !== '') {
            $clean[] = sanitize_key($category);
        }
    }
    $clean = array_values(array_unique($clean));
    sort($clean);

    return $clean;
}

/**
 * Every stored level, keyed by connection key.
 *
 * @return array<string, array{ceiling: string, categories: list<string>|null, label: string, set_by: int, set_at: int}>
 */
function wppilot_connection_levels(): array
{
    $stored = get_option(WPPILOT_CONNECTION_LEVELS_OPTION, []);
    if (!is_array($stored)) {
        return [];
    }
    $levels = [];
    foreach ($stored as $key => $row) {
        if (is_string($key) && $key !== '') {
            $levels[$key] = wppilot_connection_level_normalize($row);
        }
    }

    return $levels;
}

/**
 * The key a connection's level is stored under: method, credential, user.
 *
 * The credential part is the one includes/connections.php records, so a row on the Connections
 * table and the request it describes resolve to the same key.
 */
function wppilot_connection_level_key(string $method, string $credential_key, int $user_id): string
{
    return $method . ':' . $credential_key . ':' . $user_id;
}

function wppilot_connection_level_key_is_valid(string $key): bool
{
    return preg_match('/^(?:oauth|password):[A-Za-z0-9-]{1,80}:[1-9][0-9]{0,19}$/', $key) === 1;
}

/**
 * A connection's level, or the Full default when none was ever set.
 *
 * @return array{ceiling: string, categories: list<string>|null, label: string, set_by: int, set_at: int}
 */
function wppilot_connection_level_get(string $key): array
{
    $levels = wppilot_connection_levels();

    return $levels[$key] ?? ['ceiling' => '', 'categories' => null, 'label' => '', 'set_by' => 0, 'set_at' => 0];
}

/**
 * Whether going from one level to another only takes access away (or changes nothing).
 *
 * @param array{ceiling: string, categories: list<string>|null} $from
 * @param array{ceiling: string, categories: list<string>|null} $to
 */
function wppilot_connection_level_narrows(array $from, array $to): bool
{
    if (wppilot_connection_level_rank($to['ceiling']) < wppilot_connection_level_rank($from['ceiling'])) {
        return false;
    }
    if ($from['categories'] === null) {
        return true;
    }
    if ($to['categories'] === null) {
        return false;
    }

    return array_diff($to['categories'], $from['categories']) === [];
}

/**
 * Narrow a connection's level. Refuses anything that would widen it.
 *
 * @param list<string>|null $categories Null for every category.
 */
function wppilot_connection_level_set(
    string $key,
    string $ceiling,
    ?array $categories,
    string $label,
    int $set_by,
): bool|WP_Error {
    if (!wppilot_connection_level_key_is_valid($key)) {
        return new WP_Error('wppilot_connection_level_key', __('Unknown connection.', domain: 'wppilot'));
    }
    if (!in_array($ceiling, wppilot_connection_level_ids(), strict: true)) {
        return new WP_Error('wppilot_connection_level_unknown', __('Unknown access level.', domain: 'wppilot'));
    }
    if ($categories !== null) {
        $categories = wppilot_connection_level_clean_categories($categories);
        if ($categories === []) {
            return new WP_Error('wppilot_connection_level_empty', __(
                'A Custom level needs at least one ability category.',
                domain: 'wppilot',
            ));
        }
    }

    $before = wppilot_connection_level_get($key);
    $after = [
        'ceiling' => $ceiling,
        'categories' => $categories,
        'label' => mb_substr(wp_strip_all_tags($label), start: 0, length: 191),
        'set_by' => $set_by,
        'set_at' => time(),
    ];
    if (!wppilot_connection_level_narrows($before, $after)) {
        return new WP_Error('wppilot_connection_level_widen', __(
            'A connection\'s level can only be narrowed. To give it more access, revoke the connection and connect it again.',
            domain: 'wppilot',
        ));
    }
    if ($before['ceiling'] === $after['ceiling'] && $before['categories'] === $after['categories']) {
        return true;
    }

    $stored = get_option(WPPILOT_CONNECTION_LEVELS_OPTION, []);
    $stored = is_array($stored) ? $stored : [];
    $stored[$key] = $after;
    update_option(WPPILOT_CONNECTION_LEVELS_OPTION, $stored, autoload: false);

    if (function_exists('wppilot_ledger_record_items')) {
        wppilot_ledger_record_items('wppilot/connection-level', [[
            'input' => ['connection' => $key, 'level' => wppilot_connection_level_label($after)],
            'before' => null,
            'result' => [
                'connection' => $after['label'] !== '' ? $after['label'] : $key,
                'from' => wppilot_connection_level_label($before),
                'to' => wppilot_connection_level_label($after),
            ],
            'irreversible_reason' => 'A connection level only narrows. Undoing this would widen it; revoke and reconnect the connection instead.',
        ]]);
    }

    return true;
}

/**
 * Forget a connection's level once its credential is gone.
 */
function wppilot_connection_level_forget(string $key): void
{
    $stored = get_option(WPPILOT_CONNECTION_LEVELS_OPTION, []);
    if (!is_array($stored) || !array_key_exists($key, $stored)) {
        return;
    }
    unset($stored[$key]);
    update_option(WPPILOT_CONNECTION_LEVELS_OPTION, $stored, autoload: false);
}

/**
 * Forget the level of an OAuth app for one user, after its grants were revoked.
 */
function wppilot_connection_level_forget_oauth(string $client_id, int $user_id): void
{
    if ($client_id !== '' && $user_id > 0) {
        wppilot_connection_level_forget(wppilot_connection_level_key('oauth', 'client-' . hash('sha256', $client_id), $user_id));
    }
}

/**
 * `wp_delete_application_password`: the password is gone, so is its level.
 */
function wppilot_connection_level_forget_password(mixed $user_id, mixed $item): void
{
    $uuid = is_array($item) && is_string($item['uuid'] ?? null) ? $item['uuid'] : '';
    if ($uuid !== '' && is_numeric($user_id)) {
        wppilot_connection_level_forget(wppilot_connection_level_key('password', $uuid, (int) $user_id));
    }
}

/**
 * `deleted_user`: drop every level stored for that user's connections.
 */
function wppilot_connection_level_forget_user(mixed $user_id): void
{
    if (!is_numeric($user_id) || (int) $user_id <= 0) {
        return;
    }
    $stored = get_option(WPPILOT_CONNECTION_LEVELS_OPTION, []);
    if (!is_array($stored)) {
        return;
    }
    $suffix = ':' . (int) $user_id;
    $kept = array_filter(
        $stored,
        static fn(mixed $key): bool => !is_string($key) || !str_ends_with($key, $suffix),
        ARRAY_FILTER_USE_KEY,
    );
    if (count($kept) !== count($stored)) {
        update_option(WPPILOT_CONNECTION_LEVELS_OPTION, $kept, autoload: false);
    }
}

/**
 * The connection behind this request, when it is an OAuth app or an application password.
 *
 * Read from the Bearer middleware's request-local identity and from the UUID WordPress publishes
 * after an application password authenticates, never from anything the caller sends.
 *
 * @return array{key: string, label: string, read_scope: bool}|null
 */
function wppilot_current_connection(): ?array
{
    $user_id = get_current_user_id();
    if ($user_id <= 0 || !function_exists('wppilot_connection_credential')) {
        return null;
    }

    $identity = function_exists('WPPilot\\OAuth\\Middleware\\request_oauth_identity')
        ? \WPPilot\OAuth\Middleware\request_oauth_identity()
        : null;

    if (is_array($identity)) {
        if (($identity['via'] ?? 'oauth') !== 'oauth' || (int) ($identity['user_id'] ?? 0) !== $user_id) {
            return null;
        }
        $credential = wppilot_connection_credential('oauth', $user_id);
        if ($credential === null) {
            return null;
        }
        $scopes = is_array($identity['scopes'] ?? null) ? $identity['scopes'] : [];

        return [
            'key' => wppilot_connection_level_key('oauth', $credential['key'], $user_id),
            'label' => $credential['label'],
            'read_scope' => in_array(WPPILOT_OAUTH_READ_SCOPE, $scopes, strict: true) && !in_array('mcp', $scopes, strict: true),
        ];
    }

    // @mago-expect lint:no-global -- WordPress publishes the application password's UUID only here.
    $uuid = $GLOBALS['wp_rest_application_password_uuid'] ?? null;
    if (!is_string($uuid) || $uuid === '') {
        return null;
    }
    $credential = wppilot_connection_credential('password', $user_id);
    if ($credential === null) {
        return null;
    }

    return [
        'key' => wppilot_connection_level_key('password', $credential['key'], $user_id),
        'label' => $credential['label'],
        'read_scope' => false,
    ];
}

/**
 * This request's connection policy, in the shape wppilot_token_policy() returns, or null when the
 * connection has no level (Full) or is not an OAuth app or application password.
 *
 * @return array{id: int, name: string, scope: array{abilities: list<string>, categories: list<string>}|null, ceiling: string, connection: string, level: string}|null
 */
function wppilot_connection_policy(): ?array
{
    $connection = wppilot_current_connection();
    if ($connection === null) {
        return null;
    }
    $levels = wppilot_connection_levels();
    $stored = $levels[$connection['key']] ?? null;
    if ($stored === null && !$connection['read_scope']) {
        return null;
    }
    $level = $stored ?? ['ceiling' => '', 'categories' => null];
    $ceiling = $connection['read_scope'] ? wppilot_connection_level_stricter($level['ceiling'], 'readonly') : $level['ceiling'];
    $effective = ['ceiling' => $ceiling, 'categories' => $level['categories']];

    return [
        'id' => 0,
        'name' => $connection['label'],
        'scope' => $level['categories'] === null ? null : ['abilities' => [], 'categories' => $level['categories']],
        'ceiling' => $ceiling,
        'connection' => $connection['key'],
        'level' => wppilot_connection_level_label($effective),
    ];
}

/**
 * The Connections table rows a level can be set on: one per credential and user.
 *
 * @return list<array{key: string, method: string, label: string, client: string, user_id: int, level: array{ceiling: string, categories: list<string>|null, label: string, set_by: int, set_at: int}}>
 */
function wppilot_connection_level_rows(): array
{
    if (!function_exists('wppilot_get_connections')) {
        return [];
    }
    $rows = [];
    foreach (wppilot_get_connections(limit: 200) as $connection) {
        $method = (string) ($connection['method'] ?? '');
        if ($method !== 'oauth' && $method !== 'password') {
            continue;
        }
        $user_id = (int) ($connection['user_id'] ?? 0);
        $key = wppilot_connection_level_key($method, (string) ($connection['credential_key'] ?? ''), $user_id);
        if (!wppilot_connection_level_key_is_valid($key)) {
            continue;
        }
        $client = (string) ($connection['client_name'] ?? '');
        if (isset($rows[$key])) {
            if ($client !== '' && !str_contains($rows[$key]['client'], $client)) {
                $rows[$key]['client'] = trim($rows[$key]['client'] . ', ' . $client, ', ');
            }
            continue;
        }
        $rows[$key] = [
            'key' => $key,
            'method' => $method,
            'label' => (string) ($connection['label'] ?? ''),
            'client' => $client,
            'user_id' => $user_id,
            'level' => wppilot_connection_level_get($key),
        ];
    }

    return array_values($rows);
}

/**
 * Save one row's level from the Connect screen.
 */
function wppilot_connection_level_handle_save(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to change connection levels.', domain: 'wppilot'), status_code: 403);
    }
    check_admin_referer(WPPILOT_CONNECTION_LEVEL_SAVE_ACTION);

    $key = isset($_POST['wppilot_connection_key']) && is_string($_POST['wppilot_connection_key'])
        ? sanitize_text_field(wp_unslash($_POST['wppilot_connection_key']))
        : '';
    $ceiling = isset($_POST['wppilot_connection_ceiling']) && is_string($_POST['wppilot_connection_ceiling'])
        ? sanitize_key(wp_unslash($_POST['wppilot_connection_ceiling']))
        : 'readonly';
    $custom = isset($_POST['wppilot_connection_custom']) && $_POST['wppilot_connection_custom'] === '1';
    $categories = null;
    if ($custom) {
        $posted = isset($_POST['wppilot_connection_categories']) && is_array($_POST['wppilot_connection_categories'])
            ? map_deep(wp_unslash($_POST['wppilot_connection_categories']), 'sanitize_key')
            : [];
        $categories = is_array($posted) ? array_values(array_filter($posted, 'is_string')) : [];
    }

    $row = null;
    foreach (wppilot_connection_level_rows() as $candidate) {
        if ($candidate['key'] === $key) {
            $row = $candidate;
            break;
        }
    }

    $status = 'saved';
    if ($row === null) {
        $status = 'unknown';
    } else {
        $result = wppilot_connection_level_set($key, $ceiling, $categories, $row['label'], get_current_user_id());
        if ($result instanceof WP_Error) {
            $status = $result->get_error_code() === 'wppilot_connection_level_widen' ? 'widen' : 'invalid';
        }
    }

    wp_safe_redirect(add_query_arg('wppilot_level_result', $status, admin_url('admin.php?page=' . WPPILOT_SETUP_PAGE)) . '#wppilot-connection-levels');
    exit();
}

/**
 * The Connection levels panel on the Connect screen.
 */
function wppilot_connection_level_render_panel(): void
{
    if (!wppilot_current_user_can_manage()) {
        return;
    }
    $rows = wppilot_connection_level_rows();
    $labels = wppilot_connection_level_labels();
    $categories = function_exists('wppilot_token_scope_category_choices') ? wppilot_token_scope_category_choices() : [];
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: picks one of the fixed result messages after a redirect.
    $result = isset($_GET['wppilot_level_result']) && is_string($_GET['wppilot_level_result']) ? sanitize_key(wp_unslash($_GET['wppilot_level_result'])) : '';
    $messages = [
        'saved' => ['success', __('Connection level saved.', domain: 'wppilot')],
        'widen' => ['error', __('Not saved. A level can only be narrowed. To give a connection more access, revoke it and connect it again.', domain: 'wppilot')],
        'invalid' => ['error', __('Not saved. Pick a level, and at least one category for Custom.', domain: 'wppilot')],
        'unknown' => ['error', __('Not saved. That connection is no longer listed.', domain: 'wppilot')],
    ];
    ?>
    <div class="wppilot-connect-section" id="wppilot-connection-levels">
        <h2><?php esc_html_e('Connection levels', domain: 'wppilot'); ?></h2>
        <p class="description" style="max-width:70ch;"><?php esc_html_e(
            'Limit what one OAuth app or application password may do, without changing the rest of the site. A level can only be narrowed: to give a connection more access, revoke it and connect it again. Every change is recorded on the Changes screen. Access tokens set their own level when you create or edit them.',
            domain: 'wppilot',
        ); ?></p>
        <?php if (isset($messages[$result])) { ?>
            <div class="notice notice-<?php echo esc_attr($messages[$result][0]); ?> inline"><p><?php echo esc_html($messages[$result][1]); ?></p></div>
        <?php } ?>
        <?php if ($rows === []) { ?>
            <p><?php esc_html_e('No OAuth app or application password has connected yet.', domain: 'wppilot'); ?></p>
        <?php } else { ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Connection', domain: 'wppilot'); ?></th>
                    <th><?php esc_html_e('User', domain: 'wppilot'); ?></th>
                    <th><?php esc_html_e('Level', domain: 'wppilot'); ?></th>
                    <th><?php esc_html_e('Narrow to', domain: 'wppilot'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $index => $row) {
                    $user = get_userdata($row['user_id']);
                    $current_rank = wppilot_connection_level_rank($row['level']['ceiling']);
                    $offered = $row['level']['categories'] === null
                        ? $categories
                        : array_intersect_key($categories, array_flip($row['level']['categories']));
                    $field = 'wppilot-connection-level-' . $index;
                    ?>
                    <tr>
                        <td><?php echo esc_html($row['label'] !== '' ? $row['label'] : $row['method']); ?><?php if ($row['client'] !== '') { ?><br /><span class="description"><?php echo esc_html($row['client']); ?></span><?php } ?></td>
                        <td><?php echo esc_html($user instanceof WP_User ? $user->user_login : '#' . $row['user_id']); ?></td>
                        <td><?php echo esc_html(wppilot_connection_level_label($row['level'])); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="<?php echo esc_attr(WPPILOT_CONNECTION_LEVEL_SAVE_ACTION); ?>">
                                <input type="hidden" name="wppilot_connection_key" value="<?php echo esc_attr($row['key']); ?>">
                                <?php wp_nonce_field(WPPILOT_CONNECTION_LEVEL_SAVE_ACTION); ?>
                                <label for="<?php echo esc_attr($field); ?>" class="screen-reader-text"><?php esc_html_e('Level', domain: 'wppilot'); ?></label>
                                <select id="<?php echo esc_attr($field); ?>" name="wppilot_connection_ceiling">
                                    <?php foreach ($labels as $value => $label) {
                                        if (wppilot_connection_level_rank((string) $value) < $current_rank) {
                                            continue;
                                        } ?>
                                        <option value="<?php echo esc_attr((string) $value); ?>" <?php selected((string) $value, $row['level']['ceiling']); ?>><?php echo esc_html($label); ?></option>
                                    <?php } ?>
                                </select>
                                <?php if ($offered !== []) { ?>
                                    <details<?php echo $row['level']['categories'] !== null ? ' open' : ''; ?>>
                                        <summary><label><input type="checkbox" name="wppilot_connection_custom" value="1" <?php checked($row['level']['categories'] !== null); ?>> <?php esc_html_e('Custom: only these categories', domain: 'wppilot'); ?></label></summary>
                                        <?php foreach ($offered as $slug => $category_label) { ?>
                                            <label style="display:block;"><input type="checkbox" name="wppilot_connection_categories[]" value="<?php echo esc_attr((string) $slug); ?>" <?php checked(in_array((string) $slug, $row['level']['categories'] ?? [], strict: true)); ?>> <?php echo esc_html($category_label); ?></label>
                                        <?php } ?>
                                    </details>
                                <?php } ?>
                                <?php submit_button(__('Save level', domain: 'wppilot'), 'secondary small', 'submit', false); ?>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        <?php } ?>
    </div>
    <?php
}

function wppilot_connection_levels_register_hooks(): void
{
    add_action('admin_post_' . WPPILOT_CONNECTION_LEVEL_SAVE_ACTION, callback: 'wppilot_connection_level_handle_save');
    add_action('wp_delete_application_password', callback: 'wppilot_connection_level_forget_password', accepted_args: 2);
    add_action('deleted_user', callback: 'wppilot_connection_level_forget_user');
}
