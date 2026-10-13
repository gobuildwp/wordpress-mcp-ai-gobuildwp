<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Confirmation the model cannot forge.
 *
 * A destructive or critical ability has always needed `confirm: true`, but the model writes the
 * arguments, so `confirm` only proves the model decided to send it. In `human` mode that flag is
 * no longer enough. A call goes through when one of these vouches for it instead:
 *
 * - Elicitation. A modern MCP client that declares the elicitation capability is answered with
 *   an `input_required` result asking the user, through the client's own UI, to approve a
 *   summary of exactly this call. The retry carries the user's answer and a server-issued token
 *   (the `requestState`) bound by HMAC to the ability, a hash of the exact input, the user and a
 *   five-minute expiry, and usable once.
 * - An approval URL. Every other caller (legacy MCP, REST, a modern client without elicitation)
 *   is refused with a one-time wp-admin link. A logged-in administrator approves or denies the
 *   exact call there, and the agent's identical retry within five minutes then passes once.
 * - Chat's approve button and Pro's approval queue, which already pass `human_approved`.
 *
 * `argument` mode, the default, keeps the old contract so no existing connection changes
 * behaviour until an administrator opts in on the Settings screen.
 */

const WPPILOT_CONFIRMATION_MODE_OPTION = 'wppilot_confirmation_mode';

const WPPILOT_CONFIRMATION_MODES = ['argument', 'human'];

/** How long a token, or an approval once given, stays usable. The brief caps this at five minutes. */
const WPPILOT_CONFIRMATION_TTL = 300;

/** How long an approval link waits for a person before it lapses. */
const WPPILOT_CONFIRMATION_REQUEST_TTL = 900;

/** Approval requests kept at once; the oldest go first so a looping agent cannot grow the option without bound. */
const WPPILOT_CONFIRMATION_REQUEST_MAX = 100;

const WPPILOT_CONFIRMATION_REQUESTS_OPTION = 'wppilot_confirmation_requests';

const WPPILOT_CONFIRMATION_NONCES_OPTION = 'wppilot_confirmation_nonces';

/** The key the elicitation request and its response travel under in an input_required round trip. */
const WPPILOT_CONFIRMATION_INPUT_KEY = 'wppilot_confirmation';

/** Displayed input is capped so one enormous write cannot bloat the option holding pending approvals. */
const WPPILOT_CONFIRMATION_DISPLAY_MAX_BYTES = 65536;

function wppilot_confirmation_mode(): string
{
    /** @var mixed $mode */
    $mode = get_option(WPPILOT_CONFIRMATION_MODE_OPTION, 'argument');

    return is_string($mode) && in_array($mode, WPPILOT_CONFIRMATION_MODES, strict: true) ? $mode : 'argument';
}

/**
 * Decide whether a call is confirmed, and say how.
 *
 * @param array{human_approved?: bool, elicitation?: array{supported?: bool, state?: string, response?: mixed}} $context
 *        `elicitation` is set only by the modern MCP transport: `supported` when the client declared
 *        form elicitation, and on a retry the `state` (requestState) and the user's `response`.
 * @return string|WP_Error How the call was confirmed (`argument`, `elicitation`, `approval-url`,
 *         `chat`, `approval-queue`), `not-required` for an ability that needs none, or the refusal.
 */
function wppilot_confirm_ability_call(WP_Ability $ability, mixed $input, string $transport, array $context = []): string|WP_Error
{
    // The approver belongs to this call only; a stale one would be credited to the next.
    wppilot_confirmation_last_approver(0);

    if (($context['human_approved'] ?? false) === true) {
        return match ($transport) {
            'approval' => 'approval-queue',
            'chat' => 'chat',
            default => 'human-approved',
        };
    }

    if (!wppilot_ability_requires_confirmation($ability)) {
        return 'not-required';
    }

    if (wppilot_confirmation_mode() !== 'human') {
        $values = is_array($input) ? $input : [];

        return ($values['confirm'] ?? null) === true ? 'argument' : wppilot_confirmation_required_error($ability);
    }

    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        // Nothing to bind an approval to, and no person who could be asked.
        return new WP_Error(
            'wppilot_human_confirmation_unavailable',
            __('This site requires a person to approve destructive calls, and this call is not made as a signed-in user.', domain: 'wppilot'),
            ['status' => 403, 'ability' => $ability->get_name()],
        );
    }

    $name = $ability->get_name();
    $hash = wppilot_confirmation_input_hash($input);

    if (wppilot_confirmation_claim_approval($name, $hash, $user_id)) {
        return 'approval-url';
    }

    $denied = wppilot_confirmation_find_request($name, $hash, $user_id, 'denied');
    if ($denied !== null) {
        return new WP_Error(
            'wppilot_confirmation_denied',
            __('A person denied this exact call in wp-admin. Do not retry it; ask the user what they want instead.', domain: 'wppilot'),
            ['status' => 403, 'ability' => $name],
        );
    }

    $elicitation = $context['elicitation'] ?? null;
    if (is_array($elicitation) && ($elicitation['supported'] ?? false) === true) {
        $state = is_string($elicitation['state'] ?? null) ? $elicitation['state'] : '';
        if ($state !== '') {
            $verdict = wppilot_confirmation_answer($state, $elicitation['response'] ?? null, $name, $hash, $user_id);
            if ($verdict !== null) {
                return $verdict;
            }
        }

        // No answer yet, or one that does not belong to this exact call: ask the person again.
        return wppilot_confirmation_input_required_error($ability, $input, $hash, $user_id);
    }

    return wppilot_confirmation_approval_url_error($ability, $input, $transport, $hash, $user_id);
}

/**
 * Judge an elicitation answer.
 *
 * @return string|WP_Error|null `elicitation` when approved, a refusal when declined, or null when the
 *         token is forged, expired, reused or for a different call — which is answered by asking
 *         again rather than refusing, because the person has not yet seen this call.
 */
function wppilot_confirmation_answer(string $token, mixed $response, string $ability_name, string $hash, int $user_id): string|WP_Error|null
{
    $claims = wppilot_confirmation_verify_token($token, $ability_name, $hash, $user_id);
    if ($claims === null) {
        return null;
    }

    // Some clients hand back the whole JSON-RPC result object rather than the ElicitResult alone.
    if (is_array($response) && is_array($response['result'] ?? null)) {
        $response = $response['result'];
    }
    $action = is_array($response) && is_string($response['action'] ?? null) ? $response['action'] : '';
    $content = is_array($response) && is_array($response['content'] ?? null) ? $response['content'] : [];
    $approved = $action === 'accept' && ($content['approve'] ?? null) === true;

    if ($action === '') {
        // A retry without an answer spends nothing, so the same prompt can still be answered.
        return null;
    }
    if (!wppilot_confirmation_claim_nonce($claims['n'], $claims['e'])) {
        return null;
    }
    if ($approved) {
        return 'elicitation';
    }

    return new WP_Error(
        'wppilot_confirmation_declined',
        __('The user did not approve this call. Do not retry it; ask the user what they want instead.', domain: 'wppilot'),
        ['status' => 403, 'ability' => $ability_name, 'action' => $action],
    );
}

/**
 * Hash the input exactly as it will run, minus the `confirm` control field.
 *
 * Keys are sorted so the same object sent with its members in another order is the same call;
 * list order is kept because it is meaning (menu order, block order).
 */
function wppilot_confirmation_input_hash(mixed $input): string
{
    if (is_array($input)) {
        unset($input['confirm']);
    }

    return hash('sha256', (string) wp_json_encode(
        wppilot_confirmation_canonical($input),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
    ));
}

function wppilot_confirmation_canonical(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    // array_is_list() is PHP 8.1; the plugin still supports 8.0.
    if (array_keys($value) !== range(0, count($value) - 1)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $item) {
        $value[$key] = wppilot_confirmation_canonical($item);
    }

    return $value;
}

/**
 * The HMAC key. Derived from the site's auth salt, so it is secret, stable across requests, and
 * rotating the salts (the usual response to a leak) invalidates every outstanding token.
 */
function wppilot_confirmation_key(): string
{
    return hash_hmac('sha256', 'wppilot-confirmation-v1', wp_salt('auth'), binary: true);
}

function wppilot_confirmation_b64(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function wppilot_confirmation_unb64(string $text): string|false
{
    return base64_decode(strtr($text, '-_', '+/'), strict: true);
}

/**
 * Mint a single-use token for one exact call.
 */
function wppilot_confirmation_issue_token(string $ability_name, string $hash, int $user_id, ?int $now = null): string
{
    $claims = [
        'a' => $ability_name,
        'h' => $hash,
        'u' => $user_id,
        'e' => ($now ?? time()) + WPPILOT_CONFIRMATION_TTL,
        'n' => bin2hex(random_bytes(16)),
    ];
    $payload = wppilot_confirmation_b64((string) wp_json_encode($claims));

    return 'v1.' . $payload . '.' . wppilot_confirmation_b64(hash_hmac('sha256', 'v1.' . $payload, wppilot_confirmation_key(), binary: true));
}

/**
 * Check a token against the call it is presented with. Does not spend it.
 *
 * @return array{a: string, h: string, u: int, e: int, n: string}|null The claims, or null when the
 *         token is malformed, forged, expired, or was minted for a different ability, input or user.
 */
function wppilot_confirmation_verify_token(string $token, string $ability_name, string $hash, int $user_id, ?int $now = null): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3 || $parts[0] !== 'v1') {
        return null;
    }
    $mac = wppilot_confirmation_unb64($parts[2]);
    $expected = hash_hmac('sha256', 'v1.' . $parts[1], wppilot_confirmation_key(), binary: true);
    if ($mac === false || !hash_equals($expected, $mac)) {
        return null;
    }

    $json = wppilot_confirmation_unb64($parts[1]);
    /** @var mixed $claims */
    $claims = $json === false ? null : json_decode($json, associative: true);
    if (
        !is_array($claims)
        || !is_string($claims['a'] ?? null)
        || !is_string($claims['h'] ?? null)
        || !is_int($claims['u'] ?? null)
        || !is_int($claims['e'] ?? null)
        || !is_string($claims['n'] ?? null)
    ) {
        return null;
    }

    $now ??= time();
    if (
        !hash_equals($claims['a'], $ability_name)
        || !hash_equals($claims['h'], $hash)
        || $claims['u'] !== $user_id
        || $claims['e'] < $now
        || $claims['e'] > $now + WPPILOT_CONFIRMATION_TTL
    ) {
        return null;
    }

    return ['a' => $claims['a'], 'h' => $claims['h'], 'u' => $claims['u'], 'e' => $claims['e'], 'n' => $claims['n']];
}

/**
 * Spend a token's nonce. False when it was already spent.
 *
 * Kept in an option rather than transients: a persistent object cache may evict a transient
 * early, and a forgotten nonce is a replayable token.
 */
function wppilot_confirmation_claim_nonce(string $nonce, int $expires): bool
{
    return wppilot_confirmation_locked(WPPILOT_CONFIRMATION_NONCES_OPTION, static function () use ($nonce, $expires): bool {
        /** @var mixed $stored */
        $stored = get_option(WPPILOT_CONFIRMATION_NONCES_OPTION, []);
        $used = [];
        $now = time();
        foreach (is_array($stored) ? $stored : [] as $key => $until) {
            if (is_int($until) && $until >= $now) {
                $used[(string) $key] = $until;
            }
        }
        if (array_key_exists($nonce, $used)) {
            return false;
        }
        $used[$nonce] = $expires;
        update_option(WPPILOT_CONFIRMATION_NONCES_OPTION, $used, autoload: false);

        return true;
    });
}

/**
 * Run a read-modify-write of one confirmation option under a MySQL named lock.
 *
 * Two concurrent retries carrying the same token, or claiming the same approval, would otherwise
 * both read "unused" and both run.
 *
 * @template T
 * @param callable(): T $write
 * @return T
 */
function wppilot_confirmation_locked(string $option, callable $write): mixed
{
    global $wpdb;

    $lock = null;
    if (is_object($wpdb) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare')) {
        $lock = $wpdb->prefix . $option;
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 5)) !== '1') {
            $lock = null;
        }
    }
    // A persistent object cache shares the copy read before the lock was held.
    if (function_exists('wp_cache_delete')) {
        wp_cache_delete($option, 'options');
    }

    try {
        return $write();
    } finally {
        if ($lock !== null) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
}

/**
 * Approval requests that have not lapsed, keyed by id.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_confirmation_requests(?int $now = null): array
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_CONFIRMATION_REQUESTS_OPTION, []);
    $now ??= time();
    $requests = [];
    foreach (is_array($stored) ? $stored : [] as $id => $request) {
        if (is_array($request) && (int) ($request['expires_at'] ?? 0) >= $now) {
            /** @var array<string, mixed> $request */
            $requests[(string) $id] = $request;
        }
    }

    return $requests;
}

/**
 * @param array<string, array<string, mixed>> $requests
 */
function wppilot_confirmation_save_requests(array $requests): void
{
    if (count($requests) > WPPILOT_CONFIRMATION_REQUEST_MAX) {
        uasort($requests, static fn(array $a, array $b): int => (int) ($a['created_at'] ?? 0) <=> (int) ($b['created_at'] ?? 0));
        $requests = array_slice($requests, -WPPILOT_CONFIRMATION_REQUEST_MAX, preserve_keys: true);
    }
    update_option(WPPILOT_CONFIRMATION_REQUESTS_OPTION, $requests, autoload: false);
}

/** @return array<string, mixed>|null */
function wppilot_confirmation_get_request(string $id): ?array
{
    return wppilot_confirmation_requests()[$id] ?? null;
}

/** @return array<string, mixed>|null */
function wppilot_confirmation_find_request(string $ability_name, string $hash, int $user_id, string $status): ?array
{
    foreach (wppilot_confirmation_requests() as $request) {
        if (
            ($request['ability'] ?? null) === $ability_name
            && ($request['input_sha256'] ?? null) === $hash
            && (int) ($request['user_id'] ?? 0) === $user_id
            && ($request['status'] ?? null) === $status
        ) {
            return $request;
        }
    }

    return null;
}

/**
 * Spend an approval a person gave in wp-admin for this exact call. False when there is none.
 */
function wppilot_confirmation_claim_approval(string $ability_name, string $hash, int $user_id): bool
{
    return wppilot_confirmation_locked(WPPILOT_CONFIRMATION_REQUESTS_OPTION, static function () use ($ability_name, $hash, $user_id): bool {
        $requests = wppilot_confirmation_requests();
        foreach ($requests as $id => $request) {
            if (
                ($request['ability'] ?? null) === $ability_name
                && ($request['input_sha256'] ?? null) === $hash
                && (int) ($request['user_id'] ?? 0) === $user_id
                && ($request['status'] ?? null) === 'approved'
            ) {
                $requests[$id]['status'] = 'used';
                $requests[$id]['used_at'] = time();
                wppilot_confirmation_save_requests($requests);
                wppilot_confirmation_last_approver((int) ($request['decided_by'] ?? 0));

                return true;
            }
        }

        return false;
    });
}

/**
 * Open (or reuse) an approval request for one exact call and return its id.
 */
function wppilot_confirmation_open_request(WP_Ability $ability, mixed $input, string $transport, string $hash, int $user_id): string
{
    return wppilot_confirmation_locked(WPPILOT_CONFIRMATION_REQUESTS_OPTION, static function () use ($ability, $input, $transport, $hash, $user_id): string {
        $requests = wppilot_confirmation_requests();
        foreach ($requests as $id => $request) {
            // An agent retrying before the person has answered gets the same link, not a new one each time.
            if (
                ($request['ability'] ?? null) === $ability->get_name()
                && ($request['input_sha256'] ?? null) === $hash
                && (int) ($request['user_id'] ?? 0) === $user_id
                && ($request['status'] ?? null) === 'pending'
            ) {
                return $id;
            }
        }

        $user = wp_get_current_user();
        $agent = function_exists('wppilot_current_agent') ? wppilot_current_agent() : [];
        $now = time();
        $id = bin2hex(random_bytes(16));
        $requests[$id] = [
            'id' => $id,
            'ability' => $ability->get_name(),
            'label' => (string) $ability->get_label(),
            'risk' => wppilot_ability_risk($ability),
            'input_sha256' => $hash,
            'input_json' => wppilot_confirmation_display_json($input),
            'summary' => wppilot_confirmation_summary($ability, $input),
            'user_id' => $user_id,
            'user_login' => (string) ($user->user_login ?? ''),
            'agent' => is_string($agent['label'] ?? null) && $agent['label'] !== '' ? $agent['label'] : (string) ($agent['client'] ?? ''),
            'transport' => $transport,
            'created_at' => $now,
            'expires_at' => $now + WPPILOT_CONFIRMATION_REQUEST_TTL,
            'status' => 'pending',
        ];
        wppilot_confirmation_save_requests($requests);

        return $id;
    });
}

/**
 * Record a person's decision on a pending request.
 *
 * An approval is usable for WPPILOT_CONFIRMATION_TTL from the moment it is given, not from when the
 * agent asked. A denial is kept as long, so the agent's retry is told it was denied rather than
 * being handed a fresh link to ask again.
 */
function wppilot_confirmation_decide(string $id, bool $approve, int $decided_by): bool
{
    return wppilot_confirmation_locked(WPPILOT_CONFIRMATION_REQUESTS_OPTION, static function () use ($id, $approve, $decided_by): bool {
        $requests = wppilot_confirmation_requests();
        if (($requests[$id]['status'] ?? null) !== 'pending') {
            return false;
        }
        $now = time();
        $requests[$id]['status'] = $approve ? 'approved' : 'denied';
        $requests[$id]['decided_by'] = $decided_by;
        $requests[$id]['decided_at'] = $now;
        $requests[$id]['expires_at'] = $now + WPPILOT_CONFIRMATION_TTL;
        wppilot_confirmation_save_requests($requests);

        return true;
    });
}

function wppilot_confirmation_request_url(string $id): string
{
    return admin_url('admin.php?page=wppilot-confirm&request=' . rawurlencode($id));
}

/**
 * What a person is asked to approve, in words, followed by the input exactly as it will run.
 */
function wppilot_confirmation_summary(WP_Ability $ability, mixed $input): string
{
    $label = (string) $ability->get_label();
    $user = wp_get_current_user();

    return sprintf(
        /* translators: 1: ability label, 2: ability name, 3: risk class, 4: WordPress username, 5: JSON input */
        __("An AI agent wants to run \"%1\$s\" (%2\$s), a %3\$s action, as %4\$s, with exactly this input:\n\n%5\$s\n\nApprove only if you asked for this.", domain: 'wppilot'),
        $label !== '' ? $label : $ability->get_name(),
        $ability->get_name(),
        wppilot_ability_risk($ability),
        (string) ($user->user_login ?? ''),
        wppilot_confirmation_display_json($input),
    );
}

/**
 * The input as a person reads it: pretty JSON, secrets masked, `confirm` removed.
 *
 * Unlike the ledger's redaction nothing is shortened, because the person is approving this text.
 * Only a payload too large to hold is cut, and the cut is stated.
 */
function wppilot_confirmation_display_json(mixed $input): string
{
    if (is_array($input)) {
        unset($input['confirm']);
    }
    $json = (string) wp_json_encode(
        wppilot_confirmation_mask($input),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    if (strlen($json) > WPPILOT_CONFIRMATION_DISPLAY_MAX_BYTES) {
        return substr($json, 0, WPPILOT_CONFIRMATION_DISPLAY_MAX_BYTES) . "\n… "
            . sprintf(
                /* translators: %d: size in bytes */
                __('[input continues; %d bytes in total]', domain: 'wppilot'),
                strlen($json),
            );
    }

    return $json;
}

function wppilot_confirmation_mask(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    foreach ($value as $key => $item) {
        $value[$key] = is_string($key) && function_exists('wppilot_change_key_is_sensitive') && wppilot_change_key_is_sensitive($key)
            ? '[redacted]'
            : wppilot_confirmation_mask($item);
    }

    return $value;
}

/**
 * The refusal an elicitation-capable client turns into a prompt for its user.
 *
 * The MCP transport renders `data.input_required` as the result; any other caller that somehow
 * receives it still gets a readable refusal.
 */
function wppilot_confirmation_input_required_error(WP_Ability $ability, mixed $input, string $hash, int $user_id): WP_Error
{
    return new WP_Error(
        'wppilot_confirmation_input_required',
        __('Waiting for the user to approve this call in their MCP client.', domain: 'wppilot'),
        [
            'status' => 409,
            'ability' => $ability->get_name(),
            'input_required' => [
                'inputRequests' => [
                    WPPILOT_CONFIRMATION_INPUT_KEY => [
                        'method' => 'elicitation/create',
                        'params' => [
                            'mode' => 'form',
                            'message' => wppilot_confirmation_summary($ability, $input),
                            'requestedSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'approve' => [
                                        'type' => 'boolean',
                                        'title' => __('Approve this action', domain: 'wppilot'),
                                        'description' => __('Tick to let the agent run exactly the call shown above, once.', domain: 'wppilot'),
                                        'default' => false,
                                    ],
                                ],
                                'required' => ['approve'],
                            ],
                        ],
                    ],
                ],
                'requestState' => wppilot_confirmation_issue_token($ability->get_name(), $hash, $user_id),
            ],
        ],
    );
}

function wppilot_confirmation_approval_url_error(WP_Ability $ability, mixed $input, string $transport, string $hash, int $user_id): WP_Error
{
    $id = wppilot_confirmation_open_request($ability, $input, $transport, $hash, $user_id);
    $url = wppilot_confirmation_request_url($id);

    return new WP_Error(
        'wppilot_human_confirmation_required',
        sprintf(
            /* translators: 1: ability name, 2: wp-admin approval URL */
            __('Ability "%1$s" is destructive or critical, and this site requires a person to approve it; confirm=true from the agent is not enough. Give the user this link to review and approve the exact call in wp-admin: %2$s — then, once they say they approved it, retry the identical call (same arguments) within 5 minutes. Do not change the arguments, or the approval will not apply.', domain: 'wppilot'),
            $ability->get_name(),
            $url,
        ),
        ['status' => 409, 'ability' => $ability->get_name(), 'approval_url' => $url, 'expires_in' => WPPILOT_CONFIRMATION_REQUEST_TTL],
    );
}

/**
 * Who approved the approval-url call now running, for the ledger. Request-scoped.
 */
function wppilot_confirmation_last_approver(?int $set = null): int
{
    static $approver = 0;
    if ($set !== null) {
        $approver = $set;
    }

    return $approver;
}

/**
 * How the call about to execute was confirmed, handed from the gate to the ledger.
 *
 * Request-scoped, keyed by ability name, the same way the design gate hands its findings over:
 * the gate runs well before WP_Ability::execute() fires the ledger's hooks, and threading the value
 * through every transport would put confirmation plumbing in files that have no business with it.
 *
 * @return array{method: string, approved_by?: int}|null
 */
function wppilot_confirmation_note(string $ability_name, ?string $method = null, bool $clear = false): ?array
{
    /** @var array<string, array{method: string, approved_by?: int}> $notes */
    static $notes = [];
    if ($method !== null) {
        $note = ['method' => $method];
        if ($method === 'approval-url' && wppilot_confirmation_last_approver() > 0) {
            $note['approved_by'] = wppilot_confirmation_last_approver();
        }
        $notes[$ability_name] = $note;
    }
    $existing = $notes[$ability_name] ?? null;
    if ($clear) {
        unset($notes[$ability_name]);
    }

    return $existing;
}
