<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * The Dashboard: what GoBuildWP is currently exposing, and who is using it.
 *
 * Until now the answer to "is anything actually connected?" was spread across
 * Configuration, Troubleshoot, and Connected Apps, and partly not shown at all.
 * This screen answers it in one place from live data only — every number here
 * is read at render time, and nothing is cached or estimated.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_DASHBOARD_PAGE = 'wppilot-dashboard';

/** The setup wizard, credentials and endpoints; the home slug is the Dashboard. */
const WPPILOT_SETUP_PAGE = 'wppilot-setup';

const WPPILOT_FORGET_CLIENT_NONCE = 'wppilot_forget_client';

/**
 * Handle "Forget" on a client card, and the sweep of revoked credentials.
 *
 * Forgetting deletes rows and nothing else. A connection row is a record that
 * some client introduced itself once; there is no live state attached, and if
 * that client connects again it records itself afresh on the next handshake.
 * So this is not "disconnect" — a card with a live credential behind it will
 * reappear the moment the client calls again, which is the honest behaviour:
 * the way to stop a client is to revoke its credential, not to hide its row.
 *
 * @return array{type: string, message: string}|null
 */
function wppilot_dashboard_handle_forget(): ?array
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return null;
    }

    $sweep = array_key_exists('wppilot_forget_stale', $_POST);
    $raw = is_string($_POST['wppilot_forget_client'] ?? null) ? sanitize_text_field(wp_unslash($_POST['wppilot_forget_client'])) : '';
    if (!$sweep && $raw === '') {
        return null;
    }

    check_admin_referer(WPPILOT_FORGET_CLIENT_NONCE);

    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to manage GoBuildWP connections.', domain: 'wppilot'));
    }

    if ($sweep) {
        $removed = wppilot_forget_stale_connections();

        return [
            'type' => $removed > 0 ? 'success' : 'info',
            'message' => $removed > 0
                ? sprintf(
                    /* translators: %d: number of connection records removed */
                    _n(
                        single: 'Removed %d connection whose credential no longer exists.',
                        plural: 'Removed %d connections whose credentials no longer exist.',
                        number: $removed,
                        domain: 'wppilot',
                    ),
                    $removed,
                )
                : __('Every listed connection still has a working credential.', domain: 'wppilot'),
        ];
    }

    $removed = 0;
    foreach (explode(',', $raw) as $id) {
        $id = (int) trim($id);
        if ($id > 0) {
            $removed += wppilot_forget_connection($id);
        }
    }

    return [
        'type' => $removed > 0 ? 'success' : 'error',
        'message' => $removed > 0
            ? __(
                'Forgotten. If that client connects again it will reappear — revoke its credential to keep it out.',
                domain: 'wppilot',
            )
            : __('That connection was already gone.', domain: 'wppilot'),
    ];
}

/**
 * Registered OAuth clients, newest first.
 *
 * @return list<array<string, mixed>>
 */
function wppilot_dashboard_oauth_clients(int $limit = 25): array
{
    if (!function_exists('WPPilot\\OAuth\\ClientValidation\\client_table_exists')) {
        return [];
    }
    if (!\WPPilot\OAuth\ClientValidation\client_table_exists()) {
        return [];
    }

    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;
    /** @var \wpdb $wpdb */
    $table = $wpdb->prefix . 'wppilot_oauth_clients';

    // @mago-expect analysis:possibly-invalid-argument
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The OAuth clients table has no WordPress API. Not cached: the dashboard shows live last-used times.
    $rows = $wpdb->get_results(
        // @mago-expect analysis:possibly-invalid-argument
        $wpdb->prepare("SELECT client_id, client_name, created_at, last_used_at, admin_created
             FROM %i ORDER BY (last_used_at IS NULL), last_used_at DESC, created_at DESC LIMIT %d", $table, $limit),
        ARRAY_A,
    );

    if (!is_array($rows)) {
        return [];
    }

    $clients = [];
    /** @var mixed $row */
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        /** @var array<string, mixed> $client */
        $client = $row;
        $clients[] = $client;
    }

    return $clients;
}

/**
 * What GoBuildWP Pro reports about itself, or null when it is not answering.
 *
 * Published by Pro on a filter rather than fetched by calling into it, so this
 * screen has no dependency on Pro being installed, on its version, or on its
 * function names. An unlicensed Pro never registers the filter, so "installed
 * but locked" and "not installed" both read as null here — which is correct,
 * because in both cases the customer is getting nothing from it.
 *
 * @return array{
 *     active_integrations: list<string>,
 *     inactive_integrations: list<string>,
 *     total_integrations: int,
 *     abilities: int,
 *     version: string
 * }|null
 */
function wppilot_dashboard_pro_status(): ?array
{
    /** @var mixed $status */
    $status = apply_filters('wppilot_pro_status', value: null);
    if (!is_array($status)) {
        return null;
    }

    $list = static function (mixed $value): array {
        if (!is_array($value)) {
            return [];
        }
        $clean = [];
        /** @var mixed $item */
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $clean[] = $item;
            }
        }

        return $clean;
    };

    return [
        'active_integrations' => $list($status['active_integrations'] ?? null),
        'inactive_integrations' => $list($status['inactive_integrations'] ?? null),
        'total_integrations' => (int) ($status['total_integrations'] ?? 0),
        'abilities' => (int) ($status['abilities'] ?? 0),
        'version' => is_string($status['version'] ?? null) ? $status['version'] : '',
    ];
}

/**
 * When each transport last carried a real MCP request.
 *
 * Recorded by includes/troubleshoot/bootstrap.php, at most once a minute per
 * method, so this is a liveness signal rather than a request counter.
 *
 * @return array{oauth: ?int, token: ?int, password: ?int}
 */
function wppilot_dashboard_last_seen(): array
{
    /** @var mixed $stored */
    $stored = get_option('wppilot_mcp_last_request', default_value: []);
    if (!is_array($stored)) {
        $stored = [];
    }

    $read = static function (mixed $value): ?int {
        return is_int($value) && $value > 0 ? $value : null;
    };

    return [
        'oauth' => $read($stored['oauth'] ?? null),
        'token' => $read($stored['token'] ?? null),
        'password' => $read($stored['password'] ?? null),
    ];
}

/**
 * How many abilities are exposed right now, grouped by category.
 *
 * @return array{total: int, by_category: array<string, int>}
 */
function wppilot_dashboard_exposure(): array
{
    if (!function_exists('wp_get_abilities')) {
        return ['total' => 0, 'by_category' => []];
    }

    $by_category = [];
    $total = 0;
    /** @var mixed $ability */
    foreach (wp_get_abilities() as $ability) {
        if (!$ability instanceof WP_Ability) {
            continue;
        }
        $total++;
        $category = $ability->get_category();
        $by_category[$category] = ($by_category[$category] ?? 0) + 1;
    }

    arsort($by_category);

    return ['total' => $total, 'by_category' => $by_category];
}

/**
 * Absolute date for a timestamp, or an em dash. wp_date() returns false on a
 * bad timezone, so the failure is shown as "unknown" rather than an empty cell.
 */
function wppilot_dashboard_date(mixed $timestamp, string $format): string
{
    if (!is_int($timestamp)) {
        return '—';
    }

    $formatted = wp_date($format, $timestamp);

    return $formatted === false ? __('unknown', domain: 'wppilot') : $formatted;
}

/**
 * Relative age of a timestamp, or an em dash when there is none.
 */
function wppilot_dashboard_ago(?int $timestamp): string
{
    if ($timestamp === null) {
        return '—';
    }

    return sprintf(
        /* translators: %s: human-readable time difference, e.g. "5 mins" */
        __('%s ago', domain: 'wppilot'),
        human_time_diff($timestamp, current_time('timestamp')),
    );
}

/**
 * The state half of the Overview screen: what is exposed, and who is using it.
 *
 * Emitted inside the Connect page's `.wrap`, above the setup flow. Split out
 * from a full page render because the two used to be separate screens that each
 * told half the story — "nothing is connected" was on one, and the means to fix
 * that was on the other.
 */
function wppilot_render_dashboard_sections(): void
{
    $notice = wppilot_dashboard_handle_forget();
    if ($notice !== null) {
        printf(
            '<div class="notice notice-%s"><p>%s</p></div>',
            esc_attr($notice['type']),
            esc_html($notice['message']),
        );
    }

    // The home screen now carries the headline numbers, Pro and the recent work, so
    // this keeps what someone setting up or auditing a connection comes here for:
    // who has connected, with which credential, at which address.
    wppilot_dashboard_clients();
    wppilot_dashboard_connection();
}

/**
 * Credentials and endpoints: the access tokens, the addresses a client calls,
 * and when each transport was last used.
 */
function wppilot_dashboard_connection(): void
{
    $last_seen = wppilot_dashboard_last_seen();
    ?>
        <section class="wppilot-panel">
            <h2 class="wppilot-setting-group__title"><?php esc_html_e('Credentials and endpoints', domain: 'wppilot'); ?></h2>

            <?php wppilot_dashboard_access_tokens(); ?>

            <?php wppilot_dashboard_endpoints(); ?>

            <p class="wppilot-legend"><?php esc_html_e('Last request seen', domain: 'wppilot'); ?></p>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Transport', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Last used', domain: 'wppilot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php esc_html_e('Application password', domain: 'wppilot'); ?></td>
                        <td class="wppilot-mono"><?php echo
                            esc_html(wppilot_dashboard_ago($last_seen['password']))
                        ; ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('OAuth', domain: 'wppilot'); ?></td>
                        <td class="wppilot-mono"><?php echo
                            esc_html(wppilot_dashboard_ago($last_seen['oauth']))
                        ; ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Access token', domain: 'wppilot'); ?></td>
                        <td class="wppilot-mono"><?php echo
                            esc_html(wppilot_dashboard_ago($last_seen['token']))
                        ; ?></td>
                    </tr>
                </tbody>
            </table>
            <p class="description"><?php esc_html_e(
                'Recorded at most once a minute per transport, so this shows whether a client is active, not how many calls it made.',
                domain: 'wppilot',
            ); ?></p>
        </section>

    <?php
}

/**
 * Access tokens: what they are for, what exists, and the way to make one.
 *
 * Sits between the connection stats and the endpoint table because that is the
 * order the questions arrive in — is anything live, how would something
 * authenticate, and what address does it call. The credential itself is managed
 * on the Connect screen; this is the pointer to it, because a method that only
 * appears three screens down the setup flow is a method nobody finds.
 */
function wppilot_dashboard_access_tokens(): void
{
    $tokens = function_exists('wppilot_tokens_for_user') ? wppilot_tokens_for_user(get_current_user_id()) : [];
    $connect_url = admin_url('admin.php?page=' . WPPILOT_SETUP_PAGE . '#wppilot-token-method');
    $dt_format = wppilot_get_datetime_format('Y-m-d H:i');

    // Expired rows are still listed on the Connect screen so they can be cleared
    // deliberately, but counting them here would overstate what actually works.
    $live = 0;
    $last_used = null;
    foreach ($tokens as $token) {
        $expires = $token['expires'];
        if ($expires !== '' && (int) strtotime($expires . ' UTC') < time()) {
            continue;
        }
        $live++;
        $used = $token['last_used'] !== '' ? (int) strtotime($token['last_used'] . ' UTC') : 0;
        if ($used > 0 && ($last_used === null || $used > $last_used)) {
            $last_used = $used;
        }
    }
    ?>
        <p class="wppilot-legend"><?php esc_html_e('Access tokens', domain: 'wppilot'); ?></p>

        <p class="description" style="margin:0 0 10px;">
            <?php esc_html_e(
                'A long-lived bearer token, for callers that cannot sign in through a browser: the Claude Messages API MCP connector, the OpenAI Responses API, a cron job, an automation platform, or any client that takes a URL and one header. It authenticates on the same MCP address listed below.',
                domain: 'wppilot',
            ); ?>
        </p>

        <?php if ($live === 0) { ?>
            <p style="margin:0 0 6px;">
                <a class="button" href="<?php echo esc_url($connect_url); ?>"><?php esc_html_e(
                    'Create an access token',
                    domain: 'wppilot',
                ); ?></a>
            </p>
            <p class="description" style="margin:0;">
                <?php esc_html_e(
                    'None exist yet. Creating one shows the value once, then generates the configuration for your client with the token already in it.',
                    domain: 'wppilot',
                ); ?>
            </p>
        <?php } else { ?>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Active tokens', domain: 'wppilot'); ?></th>
                        <th><?php esc_html_e('Last used', domain: 'wppilot'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo esc_html((string) $live); ?></td>
                        <td class="wppilot-mono"><?php echo
                            esc_html(
                                $last_used === null
                                    ? __('Never', domain: 'wppilot')
                                    : wppilot_dashboard_date($last_used, $dt_format),
                            )
                        ; ?></td>
                    </tr>
                </tbody>
            </table>
            <p class="description" style="margin:6px 0 0;">
                <a href="<?php echo esc_url($connect_url); ?>"><?php esc_html_e(
                    'Create, inspect or revoke tokens, and get the configuration for your client',
                    domain: 'wppilot',
                ); ?></a>
            </p>
        <?php } ?>
    <?php
}

/**
 * Roll the connection rows up into one entry per AI client.
 *
 * A client can hold several credentials — an application password on the laptop
 * and an OAuth grant on the desktop are two rows for one Claude Desktop — and
 * the question the screen answers is "is Claude Desktop connected", not "how
 * many rows does it have". Rows are keyed by the client the request identified
 * itself as, so the roll-up is by product rather than by credential.
 *
 * @return array<string, array{
 *     label: string,
 *     known: bool,
 *     versions: list<string>,
 *     methods: list<string>,
 *     users: list<string>,
 *     credentials: int,
 *     requests: int,
 *     first_seen: string,
 *     last_seen: string,
 *     ids: list<int>,
 *     reachable: bool
 * }>
 */
function wppilot_dashboard_client_activity(): array
{
    /** @var array<string, array<string, mixed>> $activity */
    $activity = [];

    foreach (wppilot_get_connections(limit: 200) as $connection) {
        $reported = (string) ($connection['client_name'] ?? '');
        $known = wppilot_client_key($reported);
        $key = $known ?? ($reported !== '' ? 'raw:' . $reported : 'unidentified');

        $activity[$key] = wppilot_dashboard_merge_connection(
            $activity[$key] ?? wppilot_dashboard_blank_client($reported, $known),
            $connection,
        );
    }

    uasort($activity, static fn(array $a, array $b): int => strcmp((string) $b['last_seen'], (string) $a['last_seen']));

    /** @var array<string, array{label: string, known: bool, versions: list<string>, methods: list<string>, users: list<string>, credentials: int, requests: int, first_seen: string, last_seen: string, ids: list<int>, reachable: bool}> $activity */
    return $activity;
}

/**
 * Record which rows a card covers, and whether any of them can still connect.
 *
 * Split out of the merge so that stays readable: the roll-up is about display
 * columns, this is about identity and liveness.
 *
 * Reachable is an OR across the card's rows — one working credential is enough
 * for the client to come back, however many others were revoked.
 *
 * @param array<string, mixed> $entry
 * @param array<string, mixed> $connection
 * @return array<string, mixed>
 */
function wppilot_dashboard_track_credential(array $entry, array $connection): array
{
    /** @var list<int> $ids */
    $ids = is_array($entry['ids'] ?? null) ? $entry['ids'] : [];
    $ids[] = (int) ($connection['id'] ?? 0);
    $entry['ids'] = $ids;

    $entry['reachable'] =
        ($entry['reachable'] ?? false) === true
        || wppilot_connection_credential_exists(
            (string) ($connection['method'] ?? ''),
            (string) ($connection['credential_key'] ?? ''),
            (int) ($connection['user_id'] ?? 0),
        );

    return $entry;
}

/**
 * Whether any card is listing a client that can no longer get in.
 *
 * Drives the sweep button: offering "remove revoked" on a screen where nothing
 * is revoked is a button that does nothing, which teaches people to distrust
 * the ones that do.
 *
 * @param array<string, array<string, mixed>> $activity
 */
function wppilot_dashboard_has_stale_clients(array $activity): bool
{
    foreach ($activity as $client) {
        if (($client['reachable'] ?? false) !== true) {
            return true;
        }
    }

    return false;
}

/**
 * An empty roll-up entry for a client.
 *
 * @return array<string, mixed>
 */
function wppilot_dashboard_blank_client(string $reported, ?string $registry_key): array
{
    return [
        'label' => $reported !== '' ? wppilot_client_label($reported) : __('Unidentified client', domain: 'wppilot'),
        'known' => $registry_key !== null,
        'versions' => [],
        'methods' => [],
        'users' => [],
        'credentials' => 0,
        'requests' => 0,
        'first_seen' => '',
        'last_seen' => '',
        // Row ids behind this card, so Forget can delete exactly what the card
        // represents rather than guessing from the reported name.
        'ids' => [],
        // False once every credential behind the card has been revoked: the
        // client is listed, but it can no longer get in.
        'reachable' => false,
    ];
}

/**
 * Fold one connection row into a client's roll-up.
 *
 * @param array<string, mixed> $entry
 * @param array<string, mixed> $connection
 * @return array<string, mixed>
 */
function wppilot_dashboard_merge_connection(array $entry, array $connection): array
{
    $entry['credentials'] = (int) $entry['credentials'] + 1;
    $entry['requests'] = (int) $entry['requests'] + (int) ($connection['request_count'] ?? 0);

    $entry = wppilot_dashboard_track_credential($entry, $connection);

    $user = get_userdata((int) ($connection['user_id'] ?? 0));

    /** @var array<string, string> $columns */
    $columns = [
        'versions' => (string) ($connection['client_version'] ?? ''),
        'methods' => (string) ($connection['method'] ?? ''),
        'users' => $user instanceof WP_User ? $user->user_login : '',
    ];
    foreach ($columns as $field => $value) {
        /** @var list<string> $seen */
        $seen = is_array($entry[$field] ?? null) ? $entry[$field] : [];
        if ($value !== '' && !in_array($value, $seen, strict: true)) {
            $seen[] = $value;
        }
        $entry[$field] = $seen;
    }

    return wppilot_dashboard_widen_window($entry, $connection);
}

/**
 * Stretch a client's first/last seen window to cover one more connection.
 *
 * String comparison is correct on 'Y-m-d H:i:s': the format sorts
 * lexicographically in the same order it sorts chronologically.
 *
 * @param array<string, mixed> $entry
 * @param array<string, mixed> $connection
 * @return array<string, mixed>
 */
function wppilot_dashboard_widen_window(array $entry, array $connection): array
{
    $first = (string) ($connection['first_seen'] ?? '');
    if ($first !== '' && ((string) $entry['first_seen'] === '' || $first < (string) $entry['first_seen'])) {
        $entry['first_seen'] = $first;
    }

    $last = (string) ($connection['last_seen'] ?? '');
    if ($last > (string) $entry['last_seen']) {
        $entry['last_seen'] = $last;
    }

    return $entry;
}

/**
 * Which AI clients are talking to this site.
 *
 * Reads includes/connections.php, which records one row per credential and
 * client across the whole site. The previous version listed only the current
 * user's own application passwords, so on a site with several administrators it
 * showed a fraction of the truth — and it identified connections by credential
 * label, which is a name the user typed rather than the software connecting.
 *
 * Clients that have never connected are listed too. "Cursor is not connected" is
 * the answer to a question people actually arrive with, and an empty screen
 * cannot give it.
 */
function wppilot_dashboard_clients(): void
{
    $activity = wppilot_dashboard_client_activity();
    ?>
        <section class="wppilot-panel" id="wppilot-clients">
            <h2 class="wppilot-setting-group__title"><?php esc_html_e('AI clients', domain: 'wppilot'); ?></h2>

            <?php if ($activity === []) { ?>
                <p class="description"><?php esc_html_e(
                    'Nothing has called the MCP endpoint yet. A client appears here the moment it authenticates and introduces itself.',
                    domain: 'wppilot',
                ); ?></p>
            <?php } else { ?>
                <div class="wppilot-clients">
                    <?php foreach ($activity as $client) {
                        wppilot_dashboard_client_card($client);
                    } ?>
                </div>
                <p class="description"><?php esc_html_e(
                    'Name and version are what each client said about itself during the handshake, so treat them as a label rather than proof — the credential, transport, request count and times are GoBuildWP\'s own observations. Times are UTC.',
                    domain: 'wppilot',
                ); ?></p>

                <?php if (wppilot_dashboard_has_stale_clients($activity)) { ?>
                    <form method="post">
                        <?php wp_nonce_field(WPPILOT_FORGET_CLIENT_NONCE); ?>
                        <button type="submit" name="wppilot_forget_stale" value="1" class="button"><?php

                        esc_html_e('Remove clients with revoked credentials', domain: 'wppilot'); ?></button>
                    </form>
                <?php } ?>
            <?php } ?>

            <?php

            // Every client GoBuildWP knows about, connected ones marked. This
            // used to list only the ones that had never called, under the
            // heading "Not connected yet" — which, on a site where something
            // *was* connected, read as a flat denial and contradicted the
            // panel directly above it. Showing the whole roster with the
            // connected ones lit up answers both questions people bring here:
            // "is anything talking to my site?" and "is my client supported?".
            $roster = wppilot_selectable_clients();
            ?>
            <?php if ($roster !== []) { ?>
                <p class="wppilot-legend"><?php esc_html_e('Supported clients', domain: 'wppilot'); ?></p>
                <div class="wppilot-clients wppilot-clients--idle">
                    <?php // Not links: the setup flow is further down this same page. ?>
                    <?php foreach ($roster as $key => $client) { ?>
                        <?php $is_connected = array_key_exists((string) $key, $activity); ?>
                        <span class="wppilot-client-chip<?php echo $is_connected ? ' is-connected' : ''; ?>">
                            <?php if ($is_connected) { ?>
                                <span class="wppilot-client-chip__dot" aria-hidden="true"></span>
                            <?php } ?>
                            <?php echo esc_html((string) ($client['label'] ?? '')); ?>
                            <?php if ($is_connected) { ?>
                                <span class="screen-reader-text"><?php esc_html_e(
                                    'connected',
                                    domain: 'wppilot',
                                ); ?></span>
                            <?php } ?>
                        </span>
                    <?php } ?>
                </div>
                <p class="description"><?php

                if ($activity === []) {
                    esc_html_e('Pick one below to get its exact configuration.', domain: 'wppilot');
                } else {
                    printf(
                        /* translators: %d: number of AI clients that have connected */
                        esc_html(_n(
                            single: '%d client has connected. Pick any client below to get its exact configuration.',
                            plural: '%d clients have connected. Pick any client below to get its exact configuration.',
                            number: count($activity),
                            domain: 'wppilot',
                        )),
                        count($activity),
                    );
                }
                ?></p>
            <?php } ?>

            <?php wppilot_dashboard_oauth_list(); ?>
        </section>
    <?php
}

/**
 * One connected client.
 *
 * @param array{
 *     label: string,
 *     known: bool,
 *     versions: list<string>,
 *     methods: list<string>,
 *     users: list<string>,
 *     credentials: int,
 *     requests: int,
 *     first_seen: string,
 *     last_seen: string,
 *     ids: list<int>,
 *     reachable: bool
 * } $client
 */
function wppilot_dashboard_client_card(array $client): void
{
    $transports = [
        'password' => __('Application password', domain: 'wppilot'),
        'oauth' => __('OAuth', domain: 'wppilot'),
        'token' => __('Access token', domain: 'wppilot'),
        'stdio' => __('WP-CLI stdio', domain: 'wppilot'),
    ];
    $methods = [];
    foreach ($client['methods'] as $method) {
        $methods[] = (string) ($transports[$method] ?? $method);
    }
    ?>
    <article class="wppilot-client-card<?php echo $client['known'] ? '' : ' is-unknown'; ?>">
        <header class="wppilot-client-card__head">
            <h3 class="wppilot-client-card__name"><?php echo esc_html($client['label']); ?></h3>
            <?php if ($client['versions'] !== []) { ?>
                <span class="wppilot-client-card__version"><?php echo
                    esc_html(implode(', ', $client['versions']))
                ; ?></span>
            <?php } ?>
        </header>

        <dl class="wppilot-client-card__facts">
            <div>
                <dt><?php esc_html_e('Requests', domain: 'wppilot'); ?></dt>
                <dd class="wppilot-client-card__count"><?php echo
                    esc_html(number_format_i18n($client['requests']))
                ; ?></dd>
            </div>
            <div>
                <dt><?php esc_html_e('Transport', domain: 'wppilot'); ?></dt>
                <dd><?php echo esc_html($methods === [] ? '—' : implode(', ', $methods)); ?></dd>
            </div>
            <div>
                <dt><?php esc_html_e('Signed in as', domain: 'wppilot'); ?></dt>
                <dd><?php echo
                    esc_html(
                        $client['users'] === [] ? __('unknown', domain: 'wppilot') : implode(', ', $client['users']),
                    )
                ; ?></dd>
            </div>
            <div>
                <dt><?php esc_html_e('Credentials', domain: 'wppilot'); ?></dt>
                <dd><?php echo esc_html(number_format_i18n($client['credentials'])); ?></dd>
            </div>
            <div>
                <dt><?php esc_html_e('First seen', domain: 'wppilot'); ?></dt>
                <dd><?php echo esc_html($client['first_seen'] !== '' ? $client['first_seen'] : '—'); ?></dd>
            </div>
            <div>
                <dt><?php esc_html_e('Last seen', domain: 'wppilot'); ?></dt>
                <dd><?php echo esc_html($client['last_seen'] !== '' ? $client['last_seen'] : '—'); ?></dd>
            </div>
        </dl>

        <div class="wppilot-client-card__actions">
            <?php if ($client['reachable'] !== true) { ?>
                <span class="wppilot-pill wppilot-pill--attention"><?php esc_html_e(
                    'Credential revoked',
                    domain: 'wppilot',
                ); ?></span>
            <?php } ?>

            <form method="post" class="wppilot-client-card__forget">
                <?php wp_nonce_field(WPPILOT_FORGET_CLIENT_NONCE); ?>
                <input type="hidden" name="wppilot_forget_client" value="<?php echo
                    esc_attr(implode(',', array_map(static fn(int $id): string => (string) $id, $client['ids'])))
                ; ?>">
                <button type="submit" class="button-link wppilot-revoke-btn"><?php esc_html_e(
                    'Forget',
                    domain: 'wppilot',
                ); ?></button>
            </form>
        </div>
    </article>
    <?php
}

/**
 * OAuth clients that have registered, with their own last-used stamp.
 *
 * Kept beside the connection list rather than merged into it: a registered
 * client that never completed a token exchange has never reached MCP, so it
 * belongs in neither the same table nor the same count.
 */
function wppilot_dashboard_oauth_list(): void
{
    $clients = wppilot_dashboard_oauth_clients();
    ?>
            <p class="wppilot-legend"><?php esc_html_e('Registered OAuth clients', domain: 'wppilot'); ?></p>
            <?php if ($clients === []) { ?>
                <p class="description"><?php esc_html_e(
                    'No OAuth client has registered. Clients appear here after they complete the authorize step.',
                    domain: 'wppilot',
                ); ?></p>
            <?php } else { ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Client', domain: 'wppilot'); ?></th>
                            <th><?php esc_html_e('Client ID', domain: 'wppilot'); ?></th>
                            <th><?php esc_html_e('Registered', domain: 'wppilot'); ?></th>
                            <th><?php esc_html_e('Last used', domain: 'wppilot'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clients as $client) {
                            $used = (string) ($client['last_used_at'] ?? '');
                            ?>
                            <tr>
                                <td><strong><?php echo
                                    esc_html((string) ($client['client_name'] ?? ''))
                                ; ?></strong></td>
                                <td><code><?php echo esc_html((string) ($client['client_id'] ?? '')); ?></code></td>
                                <td class="wppilot-mono"><?php echo
                                    esc_html((string) ($client['created_at'] ?? ''))
                                ; ?></td>
                                <td class="wppilot-mono"><?php echo
                                    esc_html($used !== '' ? $used : __('never', domain: 'wppilot'))
                                ; ?></td>
                            </tr>
                        <?php
                        } ?>
                    </tbody>
                </table>
            <?php } ?>
    <?php
}

/**
 * The addresses a client needs, and how it is expected to reach them.
 *
 * An MCP client is configured by pasting URLs, and when a connection fails the
 * first question is always which URL was wrong. Previously only the application
 * password endpoint appeared here, so the OAuth endpoint and the two discovery
 * documents — the ones a client actually fetches first — had to be reconstructed
 * by hand or found in the Connect screen's generated snippets.
 */
function wppilot_dashboard_endpoints(): void
{
    $rows = wppilot_dashboard_endpoint_rows();
    $oauth_available = count($rows) > 1;
    ?>
        <p class="wppilot-legend"><?php esc_html_e('Endpoints', domain: 'wppilot'); ?></p>

        <?php if (!$oauth_available) { ?>
            <p class="description"><?php esc_html_e(
                'OAuth is not being served on this site, so only the application-password endpoint exists. GoBuildWP withholds the OAuth routes on a public plain-HTTP site because authorization codes and access tokens would cross the network unencrypted. Enable HTTPS and they appear here.',
                domain: 'wppilot',
            ); ?></p>
        <?php } ?>

        <table class="widefat">
            <thead>
                <tr>
                    <th><?php esc_html_e('Purpose', domain: 'wppilot'); ?></th>
                    <th><?php esc_html_e('Address', domain: 'wppilot'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) { ?>
                    <tr>
                        <td><?php echo esc_html($row['label']); ?></td>
                        <td><code><?php echo esc_html($row['url']); ?></code></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
        <p class="description"><?php esc_html_e(
            'Most clients need only the OAuth or application-password endpoint; they fetch the discovery documents themselves. The metadata addresses are here for a client that has to be told explicitly, or for checking what a failing client saw.',
            domain: 'wppilot',
        ); ?></p>
    <?php
}

/**
 * The endpoint table's rows.
 *
 * @return list<array{label: string, url: string}>
 */
function wppilot_dashboard_endpoint_rows(): array
{
    $rows = [
        [
            // Both credentials that travel in a header authenticate here; only
            // OAuth gets a route of its own. Naming just one of them is what sent
            // people looking for a separate access-token address that does not
            // exist.
            'label' => __('MCP endpoint (application password or access token)', domain: 'wppilot'),
            'url' => rest_url('mcp/wppilot'),
        ],
    ];

    // The OAuth routes are registered as a set or not at all — on a public
    // plain-HTTP site GoBuildWP refuses to serve them, because authorization codes
    // and bearer tokens would cross the network in the clear. Listing the
    // addresses anyway would hand someone four URLs that all 404 and no clue why,
    // so the whole group is omitted and the panel says what is missing instead.
    if (!function_exists('WPPilot\\OAuth\\Endpoints\\Discovery\\protected_resource_metadata_url')) {
        return $rows;
    }

    $rows[] = [
        'label' => __('MCP endpoint (OAuth)', domain: 'wppilot'),
        'url' => rest_url('mcp/wppilot-oauth'),
    ];
    $rows[] = [
        'label' => __('Protected resource metadata', domain: 'wppilot'),
        'url' => \WPPilot\OAuth\Endpoints\Discovery\protected_resource_metadata_url(),
    ];
    $rows[] = [
        'label' => __('Authorization server metadata', domain: 'wppilot'),
        'url' => home_url(\WPPilot\OAuth\Endpoints\Discovery\AUTHORIZATION_SERVER),
    ];

    return $rows;
}

// The old `wppilot-dashboard` screen is gone; the home screen is the
// `wppilot-connect` page, the parent slug every other GoBuildWP screen hangs off.
//
// Requests to the old dashboard URL are forwarded rather than 404ed, because it
// was a real page people could have bookmarked.
// On `init`, not `admin_init`: wp-admin/admin.php resolves the `page` query arg
// against the registered submenus and wp_die()s with a 403 for anything it does
// not recognise, and that happens before admin_init fires. A redirect hooked
// there never runs — the user gets "you are not allowed to access this page" for
// a link that used to work.
add_action('init', static function (): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only: a bookmarked link to the retired screen is redirected to its replacement; nothing is changed.
    if (!is_admin() || !isset($_GET['page']) || $_GET['page'] !== WPPILOT_DASHBOARD_PAGE) {
        return;
    }

    wp_safe_redirect(admin_url('admin.php?page=wppilot-connect'));
    exit();
});
