<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The return leg (§2) is a GET redirect from app.gobuildwp.com and cannot carry a WordPress nonce. It is bound instead to a 256-bit state held in a transient for the current user, and it only reads; the state-changing Confirm is a nonce-checked POST.

/**
 * Pairing, §1-§3 of the protocol, plus the link record and the HTTP client
 * every Cloud call goes through.
 *
 * The flow has one browser leg and two server-to-server calls. The browser
 * only ever carries the state, the PKCE-style challenge and a single-use code:
 * the verifier stays in a transient on this site, and the access token is
 * minted and handed to the Cloud from here, so it never reaches a browser.
 *
 * The admin-post handlers are thin. Each one checks the capability and nonce,
 * calls a function that does the work and returns a value, and redirects to
 * the Connect screen with the outcome; the functions are what the tests call.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The Cloud's base URL, or '' when the configured one may not be used.
 *
 * `WPPILOT_CLOUD_URL` in wp-config.php points a development site at a local
 * Cloud; the `wppilot_cloud_url` filter exists for the same reason.
 */
function wppilot_cloud_url(): string
{
    $url = defined('WPPILOT_CLOUD_URL') ? (string) constant('WPPILOT_CLOUD_URL') : WPPILOT_CLOUD_DEFAULT_URL;

    /**
     * Filter the GoBuildWP Cloud base URL.
     *
     * @param string $url Base URL without a trailing slash, e.g. https://app.gobuildwp.com.
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_cloud_url', $url);

    return wppilot_cloud_normalize_url(is_string($filtered) ? $filtered : '');
}

/**
 * Validate a Cloud base URL and strip its trailing slash; '' when it is unusable.
 *
 * Plain HTTP only on a local or development environment: every call carries
 * either the raw access token (§3) or a pairing code and verifier (§2), and
 * those must not cross a network in the clear. A base URL with credentials, a
 * query or a fragment is refused rather than repaired, because each of those
 * would be silently carried into every request built from it.
 */
function wppilot_cloud_normalize_url(string $url): string
{
    $url = rtrim(trim($url), '/');
    if ($url === '') {
        return '';
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
        return '';
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return '';
    }

    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme === 'https') {
        return $url;
    }
    if ($scheme === 'http' && in_array(wp_get_environment_type(), ['local', 'development'], strict: true)) {
        return $url;
    }

    return '';
}

/**
 * base64url without padding, §0.
 */
function wppilot_cloud_b64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), from: '+/', to: '-_'), characters: '=');
}

/**
 * The challenge sent through the browser for a verifier kept on the site, §1.
 */
function wppilot_cloud_challenge(string $verifier): string
{
    return wppilot_cloud_b64url(hash('sha256', $verifier, binary: true));
}

/**
 * Whether a value is shaped like a state this site generated: b64url of 32 bytes.
 */
function wppilot_cloud_is_state(string $state): bool
{
    return preg_match('/^[A-Za-z0-9_-]{43}$/', $state) === 1;
}

/**
 * Whether a pairing code from the Cloud is safe to carry on. Format is the
 * Cloud's; this only refuses what could not be a code at all.
 */
function wppilot_cloud_is_code(string $code): bool
{
    return preg_match('/^[A-Za-z0-9._~-]{8,512}$/', $code) === 1;
}

/**
 * Where the browser is sent to sign in to the Cloud and pick the access level, §1.
 */
function wppilot_cloud_connect_url(string $base, string $challenge, string $state): string
{
    $name = wp_specialchars_decode((string) get_option('blogname', default_value: ''), ENT_QUOTES);

    return $base . '/connect/site?' . http_build_query(
        [
            'site' => home_url(),
            'name' => $name,
            'challenge' => $challenge,
            'state' => $state,
            'return' => admin_url('admin-post.php?action=wppilot_cloud_return'),
            'v' => WPPILOT_VERSION,
        ],
        numeric_prefix: '',
        arg_separator: '&',
        encoding_type: PHP_QUERY_RFC3986,
    );
}

/**
 * The Connect screen, opened on the Cloud card, with outcome arguments.
 *
 * @param array<string, string> $args
 */
function wppilot_cloud_admin_url(array $args = []): string
{
    $url = admin_url('admin.php?page=' . WPPILOT_SETUP_PAGE);
    if ($args !== []) {
        $url .= '&' . http_build_query($args, numeric_prefix: '', arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);
    }

    return $url . '#wppilot-cloud-method';
}

/**
 * POST a JSON body to the Cloud and decode the answer.
 *
 * TLS is verified, redirects are not followed - a redirect would carry the
 * token or code to wherever it pointed - and the timeout is 45 seconds, which
 * is what §3's round trip needs: the Cloud calls this site's MCP endpoint back
 * before it answers.
 *
 * @param array<string, string> $headers Extra headers, e.g. the §5 signature.
 * @return array{status: int, body: array<array-key, mixed>}|WP_Error
 */
function wppilot_cloud_post(string $base, string $path, string $raw_body, array $headers = []): array|WP_Error
{
    $response = wp_remote_post($base . $path, [
        'timeout' => 45,
        'redirection' => 0,
        'sslverify' => true,
        'headers' => array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json'], $headers),
        'body' => $raw_body,
        'user-agent' => 'GoBuildWP/' . WPPILOT_VERSION . '; ' . home_url(),
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    /** @var mixed $decoded */
    $decoded = json_decode((string) wp_remote_retrieve_body($response), associative: true);

    return [
        'status' => (int) wp_remote_retrieve_response_code($response),
        'body' => is_array($decoded) ? $decoded : [],
    ];
}

/**
 * JSON for a Cloud request body. Slashes unescaped so a URL reads as itself.
 *
 * @param array<string, mixed> $data
 */
function wppilot_cloud_json(array $data): string
{
    return (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * The `{error}` a Cloud refusal carried, reduced to something safe to show.
 *
 * @param array<array-key, mixed> $body
 */
function wppilot_cloud_error_detail(array $body): string
{
    $error = $body['error'] ?? '';
    if (!is_string($error)) {
        return '';
    }
    // A failed connection check also names why (e.g. unauthorized_403), which is what the site owner can act on.
    $reason = $body['reason'] ?? '';
    $detail = is_string($reason) && $reason !== '' ? $error . '-' . $reason : $error;

    return substr(sanitize_key($detail), offset: 0, length: 64);
}

/**
 * Versions sent with §3 and §5.
 *
 * @return array{wp: string, php: string, plugin: string, pro: string|null}
 */
function wppilot_cloud_versions(): array
{
    return [
        'wp' => (string) get_bloginfo('version'),
        'php' => PHP_VERSION,
        'plugin' => WPPILOT_VERSION,
        'pro' => defined('WPPILOT_PRO_VERSION') ? (string) constant('WPPILOT_PRO_VERSION') : null,
    ];
}

/**
 * The pairing record, or null when this site is not connected.
 *
 * A record that does not have the §3.4 shape reads as "not connected" rather
 * than half-connected: every consumer - the REST permission check above all -
 * must be able to trust each field it reads.
 *
 * @return array{site_id: string|int, token_id: int, account_hint: string, cloud_url: string, paired_at: string}|null
 */
function wppilot_cloud_link(): ?array
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_CLOUD_LINK_OPTION, default_value: null);

    return wppilot_cloud_parse_link($stored);
}

/**
 * @return array{site_id: string|int, token_id: int, account_hint: string, cloud_url: string, paired_at: string}|null
 */
function wppilot_cloud_parse_link(mixed $stored): ?array
{
    if (!is_array($stored)) {
        return null;
    }

    $site_id = wppilot_cloud_site_id($stored['site_id'] ?? null);
    $token_id = $stored['token_id'] ?? null;
    if ($site_id === null || !is_numeric($token_id) || (int) $token_id <= 0) {
        return null;
    }

    return [
        'site_id' => $site_id,
        'token_id' => (int) $token_id,
        'account_hint' => is_string($stored['account_hint'] ?? null) ? $stored['account_hint'] : '',
        'cloud_url' => is_string($stored['cloud_url'] ?? null) ? $stored['cloud_url'] : '',
        'paired_at' => is_string($stored['paired_at'] ?? null) ? $stored['paired_at'] : '',
    ];
}

/**
 * A site id as the Cloud issued it, or null when it is not one.
 *
 * Kept in the type it arrived in, so /cloud/status (§4) returns exactly the
 * value the Cloud assigned.
 */
function wppilot_cloud_site_id(mixed $value): string|int|null
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }
    if (is_string($value) && preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $value) === 1) {
        return $value;
    }

    return null;
}

/**
 * Whether the presented token is the one the Cloud was given, §4.
 *
 * @param array{site_id: string|int, token_id: int, account_hint: string, cloud_url: string, paired_at: string}|null $link
 */
function wppilot_cloud_token_is_linked(int $presented_token_id, ?array $link): bool
{
    return $presented_token_id > 0 && $link !== null && $link['token_id'] === $presented_token_id;
}

/**
 * Normalise the policy the Cloud answered §2 with, or refuse it.
 *
 * The ceiling must be one this site can enforce; anything else is refused
 * rather than mapped, since an unknown ceiling was meant to restrict something.
 * The scope may arrive as the token's own shape or as a plain list of ability
 * names, which is read as that list; null is every ability.
 *
 * @param array<array-key, mixed> $body
 * @return array{account_hint: string, workspace: string, ceiling: string, scope: array{abilities: list<string>, categories: list<string>}|null, label: string}|WP_Error
 */
// Inherent: one guard per field of an untrusted answer, each refusing on its own terms.
// @mago-expect lint:cyclomatic-complexity
function wppilot_cloud_parse_policy(array $body): array|WP_Error
{
    $hint = $body['account_hint'] ?? null;
    $ceiling = $body['ceiling'] ?? null;
    if (!is_string($hint) || trim($hint) === '' || !is_string($ceiling)) {
        return new WP_Error('invalid_response', __('GoBuildWP Cloud sent an incomplete answer.', domain: 'wppilot'));
    }
    if (!in_array($ceiling, wppilot_token_ceiling_ids(), strict: true)) {
        return new WP_Error('invalid_response', __('GoBuildWP Cloud asked for an access level this site does not know.', domain: 'wppilot'));
    }

    /** @var mixed $scope */
    $scope = $body['scope'] ?? null;
    if ($scope !== null) {
        if (!is_array($scope)) {
            return new WP_Error('invalid_response', __('GoBuildWP Cloud sent an unreadable ability scope.', domain: 'wppilot'));
        }
        // array_is_list() is PHP 8.1; this plugin still runs on 8.0.
        if ($scope === [] || array_keys($scope) === range(0, count($scope) - 1)) {
            $scope = ['abilities' => $scope];
        }
        $scope = wppilot_token_normalize_scope($scope);
        if ($scope === null || ($scope['abilities'] === [] && $scope['categories'] === [])) {
            return new WP_Error('invalid_response', __('GoBuildWP Cloud sent an ability scope that allows nothing.', domain: 'wppilot'));
        }
    }

    /** @var mixed $workspace */
    $workspace = $body['workspace'] ?? '';
    if (is_array($workspace)) {
        $workspace = $workspace['name'] ?? '';
    }
    $label = $body['label'] ?? '';

    return [
        'account_hint' => mb_substr(sanitize_text_field(trim($hint)), start: 0, length: 191),
        'workspace' => is_string($workspace) ? mb_substr(sanitize_text_field($workspace), start: 0, length: 191) : '',
        'ceiling' => $ceiling,
        'scope' => $scope,
        'label' => is_string($label) ? mb_substr(sanitize_text_field($label), start: 0, length: 191) : '',
    ];
}

/**
 * A pairing in flight for this user, or null when there is none or it expired.
 *
 * The transient's own expiry is not trusted alone: an object cache may keep an
 * entry past its TTL, and updating the entry in §2 would otherwise restart the
 * ten minutes. The deadline set at §1 travels inside the value.
 *
 * @return array{verifier: string, user_id: int, cloud_url: string, expires: int, code?: string, policy?: array<string, mixed>}|null
 */
function wppilot_cloud_pending(string $state, int $user_id): ?array
{
    if ($user_id <= 0 || !wppilot_cloud_is_state($state)) {
        return null;
    }

    /** @var mixed $data */
    $data = get_transient(WPPILOT_CLOUD_PAIR_TRANSIENT_PREFIX . $state);
    if (
        !is_array($data)
        || !is_string($data['verifier'] ?? null)
        || ($data['user_id'] ?? null) !== $user_id
        || !is_string($data['cloud_url'] ?? null)
        || !is_int($data['expires'] ?? null)
        || $data['expires'] <= time()
    ) {
        return null;
    }

    /** @var array{verifier: string, user_id: int, cloud_url: string, expires: int, code?: string, policy?: array<string, mixed>} $data */
    return $data;
}

/**
 * Store a pairing in flight until its original deadline.
 *
 * @param array{verifier: string, user_id: int, cloud_url: string, expires: int, code?: string, policy?: array<string, mixed>} $data
 */
function wppilot_cloud_store_pending(string $state, array $data): void
{
    set_transient(WPPILOT_CLOUD_PAIR_TRANSIENT_PREFIX . $state, $data, max(1, $data['expires'] - time()));
}

function wppilot_cloud_forget_pending(string $state): void
{
    if (wppilot_cloud_is_state($state)) {
        delete_transient(WPPILOT_CLOUD_PAIR_TRANSIENT_PREFIX . $state);
    }
}

/**
 * §1: start a pairing and return where to send the browser.
 *
 * @return array{url: string, state: string}|WP_Error
 */
function wppilot_cloud_begin(int $user_id): array|WP_Error
{
    if (wppilot_cloud_link() !== null) {
        return new WP_Error('already_connected', __('This site is already connected to GoBuildWP Cloud. Disconnect it first.', domain: 'wppilot'));
    }

    $base = wppilot_cloud_url();
    if ($base === '') {
        return new WP_Error('unavailable', __('The GoBuildWP Cloud address configured on this site is not usable. It must be HTTPS outside a local or development environment.', domain: 'wppilot'));
    }

    $state = wppilot_cloud_b64url(random_bytes(32));
    $verifier = wppilot_cloud_b64url(random_bytes(32));

    wppilot_cloud_store_pending($state, [
        'verifier' => $verifier,
        'user_id' => $user_id,
        'cloud_url' => $base,
        'expires' => time() + WPPILOT_CLOUD_PAIR_TTL,
    ]);

    return ['url' => wppilot_cloud_connect_url($base, wppilot_cloud_challenge($verifier), $state), 'state' => $state];
}

/**
 * §2: the browser is back with a code; ask the Cloud what it is asking for.
 *
 * The answer is kept with the pairing for the confirm screen. Any failure ends
 * the pairing: a code the Cloud refused once will not be accepted on a retry.
 */
function wppilot_cloud_receive(string $state, string $code, int $user_id): bool|WP_Error
{
    $pending = wppilot_cloud_pending($state, $user_id);
    if ($pending === null) {
        return new WP_Error('expired', __('This connection request expired or was started by another user. Start again.', domain: 'wppilot'));
    }
    if (!wppilot_cloud_is_code($code)) {
        wppilot_cloud_forget_pending($state);
        return new WP_Error('invalid_response', __('GoBuildWP Cloud returned without a usable pairing code.', domain: 'wppilot'));
    }

    $response = wppilot_cloud_post($pending['cloud_url'], '/api/pair/policy', wppilot_cloud_json([
        'code' => $code,
        'verifier' => $pending['verifier'],
        'site_url' => home_url(),
    ]));

    if (is_wp_error($response)) {
        wppilot_cloud_forget_pending($state);
        return new WP_Error('network', __('This site could not reach GoBuildWP Cloud.', domain: 'wppilot'), [
            'detail' => $response->get_error_message(),
        ]);
    }
    if ($response['status'] !== 200) {
        wppilot_cloud_forget_pending($state);
        return new WP_Error('policy', __('GoBuildWP Cloud did not accept this connection request.', domain: 'wppilot'), [
            'detail' => wppilot_cloud_error_detail($response['body']),
        ]);
    }

    $policy = wppilot_cloud_parse_policy($response['body']);
    if (is_wp_error($policy)) {
        wppilot_cloud_forget_pending($state);
        return $policy;
    }

    $pending['code'] = $code;
    $pending['policy'] = $policy;
    wppilot_cloud_store_pending($state, $pending);

    return true;
}

/**
 * §3: the user confirmed. Mint the token, hand it to the Cloud, save the link.
 *
 * The pairing is consumed before anything else happens, so a double-submitted
 * Confirm cannot mint two tokens. Every failure after the token exists revokes
 * it: a token the Cloud never acknowledged is a live credential nobody tracks.
 *
 * @return array{site_id: string|int, token_id: int, account_hint: string, cloud_url: string, paired_at: string}|WP_Error
 */
// Inherent: §3 is a sequence of steps that each fail differently, and every failure after the
// token exists has to revoke it, so the branches stay together where that can be checked.
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:halstead
function wppilot_cloud_complete(string $state, int $user_id): array|WP_Error
{
    $pending = wppilot_cloud_pending($state, $user_id);
    $policy = $pending['policy'] ?? null;
    $code = $pending['code'] ?? null;
    if ($pending === null || !is_array($policy) || !is_string($code)) {
        return new WP_Error('expired', __('This connection request expired or was started by another user. Start again.', domain: 'wppilot'));
    }
    wppilot_cloud_forget_pending($state);

    if (wppilot_cloud_link() !== null) {
        return new WP_Error('already_connected', __('This site is already connected to GoBuildWP Cloud. Disconnect it first.', domain: 'wppilot'));
    }

    $keys = wppilot_cloud_keys();
    if (is_wp_error($keys)) {
        return $keys;
    }

    /** @var array{abilities: list<string>, categories: list<string>}|null $scope */
    $scope = $policy['scope'] ?? null;
    $token = wppilot_token_create($user_id, WPPILOT_CLOUD_TOKEN_NAME, 0, $scope, (string) ($policy['ceiling'] ?? 'readonly'));
    if (is_wp_error($token)) {
        return new WP_Error('token', $token->get_error_message());
    }

    $response = wppilot_cloud_post($pending['cloud_url'], '/api/pair/complete', wppilot_cloud_json([
        'code' => $code,
        'verifier' => $pending['verifier'],
        'site_url' => home_url(),
        'token' => $token['secret'],
        'token_id' => $token['id'],
        'site_pubkey' => $keys['public'],
        'versions' => wppilot_cloud_versions(),
    ]));

    $site_id = null;
    $error = null;
    if (is_wp_error($response)) {
        $error = new WP_Error('network', __('This site could not reach GoBuildWP Cloud.', domain: 'wppilot'), [
            'detail' => $response->get_error_message(),
        ]);
    } elseif ($response['status'] !== 200) {
        $error = new WP_Error('complete', __('GoBuildWP Cloud could not finish connecting this site.', domain: 'wppilot'), [
            'detail' => wppilot_cloud_error_detail($response['body']),
        ]);
    } else {
        $site_id = wppilot_cloud_site_id($response['body']['site_id'] ?? null);
        if ($site_id === null) {
            $error = new WP_Error('invalid_response', __('GoBuildWP Cloud sent an incomplete answer.', domain: 'wppilot'));
        }
    }

    if ($error !== null || $site_id === null) {
        wppilot_token_revoke($token['id'], $user_id);
        return $error ?? new WP_Error('invalid_response', __('GoBuildWP Cloud sent an incomplete answer.', domain: 'wppilot'));
    }

    $link = [
        'site_id' => $site_id,
        'token_id' => $token['id'],
        'account_hint' => (string) ($policy['account_hint'] ?? ''),
        'cloud_url' => $pending['cloud_url'],
        'paired_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    update_option(WPPILOT_CLOUD_LINK_OPTION, $link, autoload: false);
    update_option(WPPILOT_CLOUD_SEEN_VERSION_OPTION, WPPILOT_VERSION, autoload: false);
    // Every pairing starts unmanaged, whatever an earlier one was allowed: the owner opts in to this account.
    delete_option(WPPILOT_CLOUD_MANAGE_OPTION);
    delete_option(WPPILOT_CLOUD_POLICY_OPTION);
    wppilot_cloud_schedule_heartbeat();

    return $link;
}

/**
 * Revoke the Cloud's token whoever owns it.
 */
function wppilot_cloud_revoke_token(int $token_id): void
{
    $owner = wppilot_token_owner($token_id);
    if ($owner > 0) {
        wppilot_token_revoke($token_id, $owner);
    }
}

/**
 * Drop the link and everything that runs because of it. Does not touch the token.
 */
function wppilot_cloud_clear_link(): void
{
    delete_option(WPPILOT_CLOUD_LINK_OPTION);
    wp_clear_scheduled_hook(WPPILOT_CLOUD_HEARTBEAT_HOOK);
    // A new pairing, perhaps to another account, starts unmanaged: the owner opts in again.
    // The settings the Cloud applied stay as they are; only its right to change them goes.
    delete_option(WPPILOT_CLOUD_MANAGE_OPTION);
    delete_option(WPPILOT_CLOUD_POLICY_OPTION);
}

/**
 * The Cloud no longer knows this site (§5, 404 unknown_site): forget it here too.
 *
 * Only if the link still names the site the Cloud answered about - a heartbeat
 * in flight across a disconnect and re-pair must not clear the new link. The
 * token goes with it: nobody holds a link for it any more.
 */
function wppilot_cloud_forget_link(string|int $site_id): void
{
    $link = wppilot_cloud_link();
    if ($link === null || $link['site_id'] !== $site_id) {
        return;
    }

    wppilot_cloud_revoke_token($link['token_id']);
    wppilot_cloud_clear_link();
}

/**
 * Disconnect from wp-admin: revoke the token, drop the link, then tell the
 * Cloud (§5, best effort - the site is disconnected whether or not it hears).
 */
function wppilot_cloud_disconnect(): bool
{
    $link = wppilot_cloud_link();
    if ($link === null) {
        return false;
    }

    wppilot_cloud_revoke_token($link['token_id']);
    wppilot_cloud_clear_link();

    $base = wppilot_cloud_normalize_url($link['cloud_url']);
    if ($base !== '') {
        wppilot_cloud_signed_post($base, '/api/sites/unlink', $link['site_id'], []);
    }

    return true;
}

/**
 * Redirect to the Connect screen with an error from a pairing step, and stop.
 */
function wppilot_cloud_redirect_error(WP_Error $error): void
{
    $args = ['wppilot_cloud_error' => sanitize_key((string) $error->get_error_code())];
    /** @var mixed $data */
    $data = $error->get_error_data();
    if (is_array($data) && is_string($data['detail'] ?? null) && $data['detail'] !== '') {
        $args['wppilot_cloud_detail'] = substr(sanitize_key($data['detail']), offset: 0, length: 64);
    }

    wp_safe_redirect(wppilot_cloud_admin_url($args));
    exit();
}

/**
 * Refuse a request from someone who may not manage GoBuildWP.
 */
function wppilot_cloud_require_manager(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You do not have permission to connect this site to GoBuildWP Cloud.', domain: 'wppilot'), '', ['response' => 403]);
    }
}

/**
 * admin-post: wppilot_cloud_begin (§1).
 */
function wppilot_cloud_handle_begin(): void
{
    wppilot_cloud_require_manager();
    check_admin_referer('wppilot_cloud_begin');

    if (!wppilot_cloud_available()) {
        wppilot_cloud_redirect_error(new WP_Error('unavailable', ''));
        return;
    }
    if (!wppilot_is_enabled()) {
        wppilot_cloud_redirect_error(new WP_Error('abilities_off', ''));
        return;
    }

    $begun = wppilot_cloud_begin(get_current_user_id());
    if (is_wp_error($begun)) {
        wppilot_cloud_redirect_error($begun);
        return;
    }

    // The Cloud is an external host, so it is allowed for this one redirect
    // rather than by switching to the unchecked wp_redirect().
    $host = (string) wp_parse_url($begun['url'], PHP_URL_HOST);
    add_filter('allowed_redirect_hosts', static fn(array $hosts): array => array_merge($hosts, [$host]));
    wp_safe_redirect($begun['url']);
    exit();
}

/**
 * admin-post: wppilot_cloud_return (§2). A GET redirect from the Cloud.
 */
function wppilot_cloud_handle_return(): void
{
    wppilot_cloud_require_manager();

    $state = is_string($_GET['state'] ?? null) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
    $code = is_string($_GET['code'] ?? null) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';

    // Declined on the Cloud side, if it says so: nothing to ask it about.
    if ($code === '' && isset($_GET['error'])) {
        if (wppilot_cloud_pending($state, get_current_user_id()) !== null) {
            wppilot_cloud_forget_pending($state);
        }
        wppilot_cloud_redirect_error(new WP_Error('declined', ''));
        return;
    }

    $received = wppilot_cloud_receive($state, $code, get_current_user_id());
    if (is_wp_error($received)) {
        wppilot_cloud_redirect_error($received);
        return;
    }

    wp_safe_redirect(wppilot_cloud_admin_url(['wppilot_cloud_state' => $state]));
    exit();
}

/**
 * The state posted by the confirm and cancel forms.
 */
function wppilot_cloud_posted_state(): string
{
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read to build the nonce action; verified by the caller before use.
    $state = is_string($_POST['state'] ?? null) ? sanitize_text_field(wp_unslash($_POST['state'])) : '';

    return wppilot_cloud_is_state($state) ? $state : '';
}

/**
 * admin-post: wppilot_cloud_confirm (§3).
 */
function wppilot_cloud_handle_confirm(): void
{
    wppilot_cloud_require_manager();
    $state = wppilot_cloud_posted_state();
    check_admin_referer('wppilot_cloud_confirm_' . $state);

    $linked = wppilot_cloud_complete($state, get_current_user_id());
    if (is_wp_error($linked)) {
        wppilot_cloud_redirect_error($linked);
        return;
    }

    wp_safe_redirect(wppilot_cloud_admin_url(['wppilot_cloud_result' => 'connected']));
    exit();
}

/**
 * admin-post: wppilot_cloud_cancel. The Cloud's code simply expires unused.
 */
function wppilot_cloud_handle_cancel(): void
{
    wppilot_cloud_require_manager();
    $state = wppilot_cloud_posted_state();
    check_admin_referer('wppilot_cloud_confirm_' . $state);

    if (wppilot_cloud_pending($state, get_current_user_id()) !== null) {
        wppilot_cloud_forget_pending($state);
    }

    wp_safe_redirect(wppilot_cloud_admin_url(['wppilot_cloud_result' => 'cancelled']));
    exit();
}

/**
 * admin-post: wppilot_cloud_disconnect.
 */
function wppilot_cloud_handle_disconnect(): void
{
    wppilot_cloud_require_manager();
    check_admin_referer('wppilot_cloud_disconnect');

    wppilot_cloud_disconnect();

    wp_safe_redirect(wppilot_cloud_admin_url(['wppilot_cloud_result' => 'disconnected']));
    exit();
}

/**
 * Whether this site can be connected at all.
 *
 * The Cloud reaches the site with an access token, and tokens are
 * authenticated by the OAuth middleware, which only loads over HTTPS or on a
 * local environment - the same rule the Access token card follows.
 */
function wppilot_cloud_available(): bool
{
    return wppilot_oauth_transport_allowed() && wppilot_cloud_url() !== '';
}
