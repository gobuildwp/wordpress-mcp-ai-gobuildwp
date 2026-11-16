<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Approve for a session, and the always-ask list.
 *
 * With "Ask a person in wp-admin" on, every destructive call waits for a click. That is the right
 * default and the wrong one for an agent deleting forty spam comments. A session approval lets the
 * person say "this agent may run this one ability, without asking, for a while": it covers later
 * calls to the same ability, by the same WordPress user, through the same connection credential,
 * until it runs out of time or calls, whichever is first. It never widens to another ability,
 * another user or another connection, and a call made with no credential (a direct PHP call)
 * is never covered.
 *
 * Critical-risk abilities and every ability on the always-ask list are never covered: those are
 * asked about one call at a time, always. Putting an ability on the always-ask list also makes it
 * need confirmation even when its risk level would not, so an admin can fence off an ability that
 * the risk rating undersells.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_CONFIRMATION_SESSIONS_OPTION = 'wppilot_confirmation_session_grants';

const WPPILOT_CONFIRMATION_ALWAYS_ASK_OPTION = 'wppilot_confirmation_always_ask';

/** Defaults and hard ceilings for one session approval. */
const WPPILOT_CONFIRMATION_SESSION_MINUTES = 30;

const WPPILOT_CONFIRMATION_SESSION_MAX_MINUTES = 240;

const WPPILOT_CONFIRMATION_SESSION_CALLS = 25;

const WPPILOT_CONFIRMATION_SESSION_MAX_CALLS = 200;

/** The always-ask list is a short admin-picked list, not a second ability registry. */
const WPPILOT_CONFIRMATION_ALWAYS_ASK_MAX = 200;

/**
 * Length and call budget a new session approval gets.
 *
 * @return array{seconds: int, calls: int}
 */
function wppilot_confirmation_session_limits(): array
{
    /** @var mixed $minutes */
    $minutes = apply_filters('wppilot_confirmation_session_minutes', WPPILOT_CONFIRMATION_SESSION_MINUTES);
    /** @var mixed $calls */
    $calls = apply_filters('wppilot_confirmation_session_calls', WPPILOT_CONFIRMATION_SESSION_CALLS);
    $minutes = is_numeric($minutes) ? (int) $minutes : WPPILOT_CONFIRMATION_SESSION_MINUTES;
    $calls = is_numeric($calls) ? (int) $calls : WPPILOT_CONFIRMATION_SESSION_CALLS;

    return [
        'seconds' => max(1, min(WPPILOT_CONFIRMATION_SESSION_MAX_MINUTES, $minutes)) * MINUTE_IN_SECONDS,
        'calls' => max(1, min(WPPILOT_CONFIRMATION_SESSION_MAX_CALLS, $calls)),
    ];
}

/** @return list<string> */
function wppilot_confirmation_always_ask(): array
{
    /** @var mixed $list */
    $list = get_option(WPPILOT_CONFIRMATION_ALWAYS_ASK_OPTION, []);

    return is_array($list) ? wppilot_confirmation_ability_names($list) : [];
}

/**
 * Unique, well-formed ability names, capped.
 *
 * @param array<array-key, mixed> $names
 * @return list<string>
 */
function wppilot_confirmation_ability_names(array $names): array
{
    $out = [];
    foreach ($names as $name) {
        $name = is_string($name) ? strtolower(trim($name)) : '';
        if ($name !== '' && preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#', $name) === 1) {
            $out[$name] = true;
        }
    }

    return array_slice(array_keys($out), 0, WPPILOT_CONFIRMATION_ALWAYS_ASK_MAX);
}

function wppilot_confirmation_is_always_ask(string $ability_name): bool
{
    return in_array($ability_name, wppilot_confirmation_always_ask(), strict: true);
}

/**
 * Replace the always-ask list. Names that do not look like ability names are dropped.
 *
 * @param array<array-key, mixed> $names
 * @return list<string> The list as saved.
 */
function wppilot_confirmation_set_always_ask(array $names, int $actor_id): array
{
    $before = wppilot_confirmation_always_ask();
    $after = wppilot_confirmation_ability_names($names);
    update_option(WPPILOT_CONFIRMATION_ALWAYS_ASK_OPTION, $after, autoload: false);

    $added = array_values(array_diff($after, $before));
    $removed = array_values(array_diff($before, $after));
    if ($added !== [] || $removed !== []) {
        // Ability names are short and fixed-shape, so the detail stays inside the column for any
        // realistic edit; the clip in the log only matters for a pathological bulk paste.
        $detail = trim(
            ($added !== [] ? '+' . implode(' +', $added) : '') . ' ' . ($removed !== [] ? '-' . implode(' -', $removed) : ''),
        );
        wppilot_approval_log_record('always-ask-changed', ['actor_id' => $actor_id, 'detail' => $detail]);
        // A session must not outlive the admin's decision that the ability is always asked about.
        foreach ($added as $name) {
            wppilot_confirmation_revoke_sessions_for($name, $actor_id);
        }
    }

    return $after;
}

/**
 * Whether a request may be turned into a session approval.
 *
 * @param array<string, mixed> $request
 */
function wppilot_confirmation_session_allowed(array $request): bool
{
    $ability = (string) ($request['ability'] ?? '');

    return (
        $ability !== ''
        && (string) ($request['credential'] ?? '') !== ''
        && (int) ($request['user_id'] ?? 0) > 0
        && ($request['risk'] ?? '') !== 'critical'
        && !wppilot_confirmation_is_always_ask($ability)
        // A Cloud job's request is approved once, for that job, never for a session.
        && (string) ($request['cloud_job'] ?? '') === ''
    );
}

/**
 * Live session approvals, expired and spent ones dropped.
 *
 * @return array<string, array<string, mixed>>
 */
function wppilot_confirmation_sessions(?int $now = null): array
{
    $now ??= time();
    /** @var mixed $grants */
    $grants = get_option(WPPILOT_CONFIRMATION_SESSIONS_OPTION, []);
    $live = [];
    foreach (is_array($grants) ? $grants : [] as $id => $grant) {
        if (
            is_string($id)
            && is_array($grant)
            && (int) ($grant['expires_at'] ?? 0) > $now
            && (int) ($grant['calls_left'] ?? 0) > 0
        ) {
            $live[$id] = $grant;
        }
    }

    return $live;
}

/** @param array<string, array<string, mixed>> $grants */
function wppilot_confirmation_save_sessions(array $grants): void
{
    update_option(WPPILOT_CONFIRMATION_SESSIONS_OPTION, $grants, autoload: false);
}

/**
 * Approve a pending request, and let the same agent run the same ability again without asking
 * for the session window. The call that asked is approved as well, exactly as "Approve this once".
 */
function wppilot_confirmation_grant_session(string $request_id, int $decided_by): bool
{
    $request = wppilot_confirmation_get_request($request_id);
    if ($request === null || ($request['status'] ?? '') !== 'pending' || !wppilot_confirmation_session_allowed($request)) {
        return false;
    }
    // The session row below is the log entry for this decision, so decide() writes none.
    if (!wppilot_confirmation_decide($request_id, true, $decided_by, log: false)) {
        return false;
    }

    $limits = wppilot_confirmation_session_limits();
    $now = time();
    $grant = [
        'id' => bin2hex(random_bytes(12)),
        'ability' => (string) $request['ability'],
        'label' => (string) ($request['label'] ?? ''),
        'user_id' => (int) $request['user_id'],
        'credential' => (string) $request['credential'],
        'agent' => (string) ($request['agent'] ?? ''),
        'granted_by' => $decided_by,
        'created_at' => $now,
        'expires_at' => $now + $limits['seconds'],
        'calls_left' => $limits['calls'],
        'calls_max' => $limits['calls'],
    ];
    wppilot_confirmation_locked(WPPILOT_CONFIRMATION_SESSIONS_OPTION, static function () use ($grant): bool {
        $grants = wppilot_confirmation_sessions();
        $grants[$grant['id']] = $grant;
        wppilot_confirmation_save_sessions($grants);

        return true;
    });

    wppilot_approval_log_record('session-granted', [
        'ability' => $grant['ability'],
        'user_id' => $grant['user_id'],
        'actor_id' => $decided_by,
        'agent' => $grant['agent'],
        'input_sha256' => (string) ($request['input_sha256'] ?? ''),
        'detail' => sprintf('%d min, %d calls', intdiv($limits['seconds'], MINUTE_IN_SECONDS), $limits['calls']),
    ]);

    return true;
}

/**
 * Whether a session approval covers this call, spending one of its calls if so. Credits the
 * person who granted the session as this call's approver.
 */
function wppilot_confirmation_session_covers(WP_Ability $ability, string $hash, int $user_id): bool
{
    $agent = function_exists('wppilot_current_agent') ? wppilot_current_agent() : [];
    $credential = is_string($agent['credential'] ?? null) ? $agent['credential'] : '';
    $granted_by = wppilot_confirmation_claim_session($ability->get_name(), wppilot_ability_risk($ability), $hash, $user_id, $credential);
    if ($granted_by <= 0) {
        return false;
    }
    wppilot_confirmation_last_approver($granted_by);

    return true;
}

/**
 * Spend one call of a session approval that covers this call. Returns the granting user's id,
 * or 0 when no session covers it.
 */
function wppilot_confirmation_claim_session(string $ability_name, string $risk, string $hash, int $user_id, string $credential): int
{
    if ($credential === '' || $user_id <= 0 || $risk === 'critical' || wppilot_confirmation_is_always_ask($ability_name)) {
        return 0;
    }

    /** @var array<string, mixed>|null $claimed */
    $claimed = wppilot_confirmation_locked(WPPILOT_CONFIRMATION_SESSIONS_OPTION, static function () use ($ability_name, $user_id, $credential): ?array {
        $grants = wppilot_confirmation_sessions();
        foreach ($grants as $id => $grant) {
            if (
                ($grant['ability'] ?? null) === $ability_name
                && (int) ($grant['user_id'] ?? 0) === $user_id
                && is_string($grant['credential'] ?? null)
                && hash_equals((string) $grant['credential'], $credential)
            ) {
                $grants[$id]['calls_left'] = (int) $grant['calls_left'] - 1;
                $grants[$id]['last_used_at'] = time();
                wppilot_confirmation_save_sessions(wppilot_confirmation_drop_spent($grants));

                return $grants[$id];
            }
        }

        return null;
    });

    if (!is_array($claimed)) {
        return 0;
    }

    $granted_by = (int) ($claimed['granted_by'] ?? 0);
    wppilot_approval_log_record('session-used', [
        'ability' => $ability_name,
        'user_id' => $user_id,
        'actor_id' => $granted_by,
        'agent' => (string) ($claimed['agent'] ?? ''),
        'input_sha256' => $hash,
        'detail' => sprintf('%d of %d calls left', (int) $claimed['calls_left'], (int) ($claimed['calls_max'] ?? 0)),
    ]);

    return max(1, $granted_by);
}

/**
 * @param array<string, array<string, mixed>> $grants
 * @return array<string, array<string, mixed>>
 */
function wppilot_confirmation_drop_spent(array $grants): array
{
    return array_filter($grants, static fn(array $g): bool => (int) ($g['calls_left'] ?? 0) > 0);
}

function wppilot_confirmation_revoke_session(string $id, int $actor_id): bool
{
    /** @var array<string, mixed>|null $revoked */
    $revoked = wppilot_confirmation_locked(WPPILOT_CONFIRMATION_SESSIONS_OPTION, static function () use ($id): ?array {
        $grants = wppilot_confirmation_sessions();
        if (!isset($grants[$id])) {
            return null;
        }
        $grant = $grants[$id];
        unset($grants[$id]);
        wppilot_confirmation_save_sessions($grants);

        return $grant;
    });
    if (!is_array($revoked)) {
        return false;
    }

    wppilot_approval_log_record('session-revoked', [
        'ability' => (string) ($revoked['ability'] ?? ''),
        'user_id' => (int) ($revoked['user_id'] ?? 0),
        'actor_id' => $actor_id,
        'agent' => (string) ($revoked['agent'] ?? ''),
        'detail' => sprintf('%d calls unused', (int) ($revoked['calls_left'] ?? 0)),
    ]);

    return true;
}

function wppilot_confirmation_revoke_sessions_for(string $ability_name, int $actor_id): int
{
    $count = 0;
    foreach (wppilot_confirmation_sessions() as $id => $grant) {
        if (($grant['ability'] ?? null) === $ability_name && wppilot_confirmation_revoke_session($id, $actor_id)) {
            $count++;
        }
    }

    return $count;
}
