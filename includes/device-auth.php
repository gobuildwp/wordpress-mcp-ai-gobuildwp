<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Device sign-in, after RFC 8628 (OAuth 2.0 Device Authorization Grant).
 *
 * An agent with no browser (a CLI, a headless runner) asks the site for a code,
 * shows the person a short user code, and polls. An administrator enters that
 * code on a wp-admin screen, sees which client asked and from where, picks the
 * token's safety ceiling and lifetime, and approves or denies. On the next poll
 * the agent receives an ordinary GoBuildWP access token owned by the approver,
 * held to the ceiling and scope like any token made on the Connect screen.
 *
 * Endpoints (no authentication, by design):
 *
 *     POST /wp-json/gobuildwp/v1/device/code   client_name
 *       → device_code, user_code, verification_uri, verification_uri_complete, expires_in, interval
 *     POST /wp-json/gobuildwp/v1/device/token  grant_type=urn:ietf:params:oauth:grant-type:device_code, device_code
 *       → access_token, token_type, expires_in | error: authorization_pending, slow_down,
 *         access_denied, expired_token, invalid_grant
 *
 * Codes live 10 minutes and work once. Neither is stored in plain text: a
 * record is keyed by the SHA-256 of the device code, and the user code is kept
 * only as an HMAC under the site's auth salt. The decision is a separate option
 * created with add_option(), which is an atomic insert, so an approval cannot
 * be lost to a poll that rewrites the record at the same moment. The token is
 * claimed by deleting that option, so of two concurrent polls only the one
 * whose delete removed the row mints a token.
 *
 * At most WPPILOT_DEVICE_MAX_PENDING codes are pending at once, and
 * WPPILOT_DEVICE_MAX_PENDING_PER_IP from one address, so the public endpoint
 * cannot fill the options table.
 */

const WPPILOT_DEVICE_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:device_code';
const WPPILOT_DEVICE_INDEX_OPTION = 'wppilot_device_codes';
const WPPILOT_DEVICE_RECORD_PREFIX = 'wppilot_device_';
const WPPILOT_DEVICE_DECISION_PREFIX = 'wppilot_device_ok_';
const WPPILOT_DEVICE_TTL = 600;
const WPPILOT_DEVICE_INTERVAL = 5;
const WPPILOT_DEVICE_MAX_PENDING = 20;
const WPPILOT_DEVICE_MAX_PENDING_PER_IP = 5;
/** No vowels, no 0/1/O/I lookalikes: a user code cannot spell a word or be misread. */
const WPPILOT_DEVICE_USER_CODE_ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ';
const WPPILOT_DEVICE_ADMIN_PAGE = 'wppilot-device';

/**
 * Whether device sign-in is offered on this site.
 */
function wppilot_device_enabled(): bool
{
    $enabled = function_exists('wppilot_is_enabled') ? (bool) wppilot_is_enabled() : true;

    /**
     * Filter whether agents may request device sign-in codes.
     *
     * @param bool $enabled
     */
    return apply_filters('wppilot_device_sign_in_enabled', $enabled) === true;
}

function wppilot_device_code_hash(string $device_code): string
{
    return hash('sha256', $device_code);
}

/**
 * The user code as typed, reduced to its alphabet: case, spaces and the hyphen do not matter.
 */
function wppilot_device_normalize_user_code(string $user_code): string
{
    $upper = strtoupper($user_code);

    return (string) preg_replace('/[^' . WPPILOT_DEVICE_USER_CODE_ALPHABET . ']/', '', $upper);
}

function wppilot_device_user_code_hmac(string $user_code): string
{
    return hash_hmac('sha256', wppilot_device_normalize_user_code($user_code), wp_salt('auth'));
}

function wppilot_device_random_user_code(): string
{
    $alphabet = WPPILOT_DEVICE_USER_CODE_ALPHABET;
    $max = strlen($alphabet) - 1;
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, $max)];
    }

    return substr($code, 0, 4) . '-' . substr($code, 4);
}

function wppilot_device_record_option(string $hash): string
{
    return WPPILOT_DEVICE_RECORD_PREFIX . substr($hash, 0, 40);
}

function wppilot_device_decision_option(string $hash): string
{
    return WPPILOT_DEVICE_DECISION_PREFIX . substr($hash, 0, 40);
}

/**
 * Pending codes by device-code hash, expired ones removed.
 *
 * @return array<string, array{user: string, expires: int, ip: string}>
 */
function wppilot_device_index(bool $prune = true): array
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_DEVICE_INDEX_OPTION, default_value: []);
    $index = [];
    foreach (is_array($stored) ? $stored : [] as $hash => $entry) {
        if (!is_string($hash) || !is_array($entry)) {
            continue;
        }
        $index[$hash] = [
            'user' => (string) ($entry['user'] ?? ''),
            'expires' => (int) ($entry['expires'] ?? 0),
            'ip' => (string) ($entry['ip'] ?? ''),
        ];
    }
    if (!$prune) {
        return $index;
    }

    $now = time();
    $live = [];
    foreach ($index as $hash => $entry) {
        if ($entry['expires'] > $now) {
            $live[$hash] = $entry;
            continue;
        }
        delete_option(wppilot_device_record_option($hash));
        delete_option(wppilot_device_decision_option($hash));
    }
    if (count($live) !== count($index)) {
        wppilot_device_save_index($live);
    }

    return $live;
}

/**
 * @param array<string, array{user: string, expires: int, ip: string}> $index
 */
function wppilot_device_save_index(array $index): void
{
    if ($index === []) {
        delete_option(WPPILOT_DEVICE_INDEX_OPTION);
        return;
    }
    update_option(WPPILOT_DEVICE_INDEX_OPTION, $index, autoload: false);
}

function wppilot_device_remove(string $hash): void
{
    delete_option(wppilot_device_record_option($hash));
    delete_option(wppilot_device_decision_option($hash));
    $index = wppilot_device_index(prune: false);
    if (isset($index[$hash])) {
        unset($index[$hash]);
        wppilot_device_save_index($index);
    }
}

function wppilot_device_request_ip(): string
{
    $raw = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

    return filter_var($raw, FILTER_VALIDATE_IP) !== false ? $raw : '';
}

/**
 * Issue a new device code.
 *
 * @return array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string, expires_in: int, interval: int}|WP_Error
 */
function wppilot_device_start(string $client_name, string $ip, string $user_agent = ''): array|WP_Error
{
    if (!wppilot_device_enabled()) {
        return new WP_Error('access_denied', __('Device sign-in is not available on this site.', domain: 'wppilot'), ['status' => 403]);
    }

    $index = wppilot_device_index();
    $from_ip = count(array_filter($index, static fn (array $entry): bool => $ip !== '' && $entry['ip'] === $ip));
    if (count($index) >= WPPILOT_DEVICE_MAX_PENDING || $from_ip >= WPPILOT_DEVICE_MAX_PENDING_PER_IP) {
        return new WP_Error(
            'slow_down',
            __('Too many sign-in codes are waiting for approval. Wait for one to be used or to expire (10 minutes), then try again.', domain: 'wppilot'),
            ['status' => 429],
        );
    }

    $device_code = rtrim(strtr(base64_encode(random_bytes(32)), from: '+/', to: '-_'), characters: '=');
    $hash = wppilot_device_code_hash($device_code);
    $user_code = wppilot_device_random_user_code();
    $expires = time() + WPPILOT_DEVICE_TTL;

    $client_name = trim(sanitize_text_field($client_name));
    $client_name = $client_name === '' ? __('Unnamed agent', domain: 'wppilot') : mb_substr($client_name, 0, 60);

    $record = [
        'user' => wppilot_device_user_code_hmac($user_code),
        'client' => $client_name,
        'ip' => $ip,
        'agent' => mb_substr(sanitize_text_field($user_agent), 0, 200),
        'created' => time(),
        'expires' => $expires,
        'interval' => WPPILOT_DEVICE_INTERVAL,
        'last_poll' => 0,
    ];
    if (!add_option(wppilot_device_record_option($hash), $record, '', autoload: false)) {
        return new WP_Error('server_error', __('Could not store the sign-in code.', domain: 'wppilot'), ['status' => 500]);
    }
    $index[$hash] = ['user' => $record['user'], 'expires' => $expires, 'ip' => $ip];
    wppilot_device_save_index($index);

    $uri = admin_url('admin.php?page=' . WPPILOT_DEVICE_ADMIN_PAGE);

    return [
        'device_code' => $device_code,
        'user_code' => $user_code,
        'verification_uri' => $uri,
        'verification_uri_complete' => add_query_arg(['user_code' => $user_code], $uri),
        'expires_in' => WPPILOT_DEVICE_TTL,
        'interval' => WPPILOT_DEVICE_INTERVAL,
    ];
}

/**
 * The pending request a user code names, for the approval screen.
 *
 * @return array{hash: string, client: string, ip: string, agent: string, created: int, expires: int}|null
 */
function wppilot_device_find_by_user_code(string $user_code): ?array
{
    if (strlen(wppilot_device_normalize_user_code($user_code)) !== 8) {
        return null;
    }
    $hmac = wppilot_device_user_code_hmac($user_code);
    foreach (wppilot_device_index() as $hash => $entry) {
        if (!hash_equals($entry['user'], $hmac)) {
            continue;
        }
        /** @var mixed $record */
        $record = get_option(wppilot_device_record_option($hash), default_value: false);
        if (!is_array($record) || (int) ($record['expires'] ?? 0) <= time()) {
            return null;
        }
        if (get_option(wppilot_device_decision_option($hash), default_value: false) !== false) {
            // Already decided: a code is approved or denied once.
            return null;
        }

        return [
            'hash' => $hash,
            'client' => (string) ($record['client'] ?? ''),
            'ip' => (string) ($record['ip'] ?? ''),
            'agent' => (string) ($record['agent'] ?? ''),
            'created' => (int) ($record['created'] ?? 0),
            'expires' => (int) ($record['expires'] ?? 0),
        ];
    }

    return null;
}

/**
 * Record an administrator's decision on a pending code. False when it was already decided or gone.
 */
function wppilot_device_decide(string $user_code, bool $approve, int $user_id, string $ceiling = '', int $ttl_days = 30): bool
{
    $pending = wppilot_device_find_by_user_code($user_code);
    if ($pending === null || $user_id <= 0) {
        return false;
    }
    if ($approve && (!wppilot_user_can_manage($user_id) || !in_array($ceiling, ['', 'production', 'drafts', 'readonly'], strict: true) || $ttl_days < 0)) {
        return false;
    }

    // add_option() inserts or fails, so a second decision on the same code changes nothing.
    return add_option(wppilot_device_decision_option($pending['hash']), [
        'decision' => $approve ? 'approve' : 'deny',
        'user_id' => $user_id,
        'ceiling' => $ceiling,
        'ttl_days' => $ttl_days,
        'decided' => time(),
    ], '', autoload: false);
}

/**
 * Answer one poll.
 *
 * @return array{access_token: string, token_type: string, expires_in?: int}|WP_Error The error code is the RFC 8628 error.
 */
function wppilot_device_poll(string $device_code): array|WP_Error
{
    if ($device_code === '' || strlen($device_code) > 128) {
        return wppilot_device_error('invalid_grant');
    }
    $hash = wppilot_device_code_hash($device_code);
    $record_option = wppilot_device_record_option($hash);

    /** @var mixed $record */
    $record = get_option($record_option, default_value: false);
    if (!is_array($record)) {
        return wppilot_device_error('invalid_grant');
    }
    if ((int) ($record['expires'] ?? 0) <= time()) {
        wppilot_device_remove($hash);
        return wppilot_device_error('expired_token');
    }

    /** @var mixed $decision */
    $decision = get_option(wppilot_device_decision_option($hash), default_value: false);
    if (!is_array($decision)) {
        $now = time();
        $interval = max(WPPILOT_DEVICE_INTERVAL, (int) ($record['interval'] ?? WPPILOT_DEVICE_INTERVAL));
        if ($now - (int) ($record['last_poll'] ?? 0) < $interval) {
            // RFC 8628 3.5: a client polling too fast waits five seconds longer from now on.
            $record['interval'] = min($interval + 5, 60);
            $record['last_poll'] = $now;
            update_option($record_option, $record, autoload: false);
            return wppilot_device_error('slow_down');
        }
        $record['last_poll'] = $now;
        update_option($record_option, $record, autoload: false);
        return wppilot_device_error('authorization_pending');
    }

    // Claim: only the request whose delete removed the decision row goes on.
    if (!delete_option(wppilot_device_decision_option($hash))) {
        return wppilot_device_error('invalid_grant');
    }
    wppilot_device_remove($hash);

    if (($decision['decision'] ?? '') !== 'approve') {
        return wppilot_device_error('access_denied');
    }

    $ttl_days = max(0, (int) ($decision['ttl_days'] ?? 30));
    $token = wppilot_token_create(
        (int) ($decision['user_id'] ?? 0),
        sprintf(
            /* translators: %s: the client name the agent gave */
            __('Device sign-in: %s', domain: 'wppilot'),
            (string) ($record['client'] ?? ''),
        ),
        $ttl_days,
        null,
        (string) ($decision['ceiling'] ?? ''),
    );
    if ($token instanceof WP_Error) {
        return wppilot_device_error('access_denied', $token->get_error_message());
    }

    $response = ['access_token' => $token['secret'], 'token_type' => 'Bearer'];
    if ($ttl_days > 0) {
        $response['expires_in'] = $ttl_days * DAY_IN_SECONDS;
    }

    return $response;
}

function wppilot_device_error(string $code, string $description = ''): WP_Error
{
    $descriptions = [
        'authorization_pending' => __('Waiting for a site administrator to approve the code.', domain: 'wppilot'),
        'slow_down' => __('Polling too fast. Wait the interval before asking again.', domain: 'wppilot'),
        'access_denied' => __('The sign-in was denied.', domain: 'wppilot'),
        'expired_token' => __('The code expired. Request a new one.', domain: 'wppilot'),
        'invalid_grant' => __('The device code is not valid or was already used.', domain: 'wppilot'),
    ];

    return new WP_Error($code, $description !== '' ? $description : ($descriptions[$code] ?? $code), ['status' => 400]);
}

/**
 * An RFC 8628 error body: `{"error": …, "error_description": …}`.
 */
function wppilot_device_error_response(WP_Error $error): WP_REST_Response
{
    $data = $error->get_error_data();
    $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;
    $response = new WP_REST_Response([
        'error' => (string) $error->get_error_code(),
        'error_description' => $error->get_error_message(),
    ], $status);
    $response->header('Cache-Control', 'no-store');

    return $response;
}

function wppilot_device_rest_code(WP_REST_Request $request): WP_REST_Response
{
    $name = $request->get_param('client_name');
    if (!is_string($name) || $name === '') {
        $name = $request->get_param('client_id');
    }
    $started = wppilot_device_start(
        is_string($name) ? $name : '',
        wppilot_device_request_ip(),
        (string) $request->get_header('user_agent'),
    );
    if ($started instanceof WP_Error) {
        return wppilot_device_error_response($started);
    }

    $response = new WP_REST_Response($started, 200);
    $response->header('Cache-Control', 'no-store');

    return $response;
}

function wppilot_device_rest_token(WP_REST_Request $request): WP_REST_Response
{
    if ($request->get_param('grant_type') !== WPPILOT_DEVICE_GRANT_TYPE) {
        return wppilot_device_error_response(new WP_Error('unsupported_grant_type', __('grant_type must be urn:ietf:params:oauth:grant-type:device_code.', domain: 'wppilot'), ['status' => 400]));
    }
    $device_code = $request->get_param('device_code');
    $result = wppilot_device_poll(is_string($device_code) ? $device_code : '');
    if ($result instanceof WP_Error) {
        return wppilot_device_error_response($result);
    }

    $response = new WP_REST_Response($result, 200);
    $response->header('Cache-Control', 'no-store');

    return $response;
}

function wppilot_device_register_routes(): void
{
    register_rest_route('gobuildwp/v1', '/device/code', [
        'methods' => 'POST',
        'callback' => 'wppilot_device_rest_code',
        // Public by design: the code is worthless until an administrator approves it in wp-admin.
        'permission_callback' => '__return_true',
        'args' => [
            'client_name' => ['type' => 'string', 'required' => false],
            'client_id' => ['type' => 'string', 'required' => false],
        ],
    ]);
    register_rest_route('gobuildwp/v1', '/device/token', [
        'methods' => 'POST',
        'callback' => 'wppilot_device_rest_token',
        // Public by design: the device code is the credential, and it is checked in the callback.
        'permission_callback' => '__return_true',
        'args' => [
            'grant_type' => ['type' => 'string', 'required' => true],
            'device_code' => ['type' => 'string', 'required' => true],
        ],
    ]);
}
