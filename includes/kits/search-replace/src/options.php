<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Kits\SearchReplace;

use WP_Error;
use WPPilot\Kits\Runtime;
use WPPilot\Kits\Runtime\Ledger;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The options target: search-replace over wp_options, through the same preview, plan and apply
 * as posts, with one undoable ledger row per option.
 *
 * Values are rewritten in the encoding they are stored in (serialized arrays are unserialized
 * without classes, changed inside their strings and serialized again, so string lengths stay
 * right; JSON is decoded and re-encoded), by the same engine the post meta path uses.
 *
 * Some options are never searched, whatever the caller names. They are listed below with the
 * reason, because each one is either a way to lock everyone out of the site or a secret, and a
 * diff of "replace example.com with example.org" is exactly the kind of change that would touch
 * them without anyone noticing.
 */

/** The ledger strategy that puts one option back. */
const OPTION_STRATEGY = 'kits/search-replace-option';

/** Options one plan may hold. */
const MAX_PLAN_OPTIONS = 200;

/** Option names or patterns a caller may name in one call. */
const MAX_OPTION_PATTERNS = 20;

/** Option rows read per query while scanning; their values are fetched only for rows in scope. */
const OPTION_SCAN_CHUNK = 100;

/**
 * Options no search touches, with why. Matched case-insensitively, because MySQL compares option
 * names that way: "SiteURL" is the same row as "siteurl".
 */
const DENIED_OPTIONS = [
    'siteurl' => 'WordPress builds every admin and login URL from it, so a wrong value locks everyone out. Change the site address in Settings > General.',
    'home' => 'The front-end address of the site; a wrong value sends every visitor, and the login form, to another host. Change it in Settings > General.',
    'active_plugins' => 'The list of active plugins. A rewritten entry silently deactivates that plugin, or points WordPress at a file that is not there.',
    'template' => 'The active parent theme. Change themes in Appearance > Themes instead.',
    'stylesheet' => 'The active theme. Change themes in Appearance > Themes instead.',
    'cron' => 'WP-Cron\'s schedule. Events are keyed by a hash of their arguments, so a rewritten argument orphans the event: it runs with the old data, or never.',
    'rewrite_rules' => 'A cache WordPress rebuilds from code. Re-save Settings > Permalinks instead.',
    'auth_key' => 'A secret key: it signs login cookies, and rewriting it logs everyone out.',
    'auth_salt' => 'A secret salt: it signs login cookies, and rewriting it logs everyone out.',
    'secure_auth_key' => 'A secret key: it signs login cookies, and rewriting it logs everyone out.',
    'secure_auth_salt' => 'A secret salt: it signs login cookies, and rewriting it logs everyone out.',
    'logged_in_key' => 'A secret key: it signs login cookies, and rewriting it logs everyone out.',
    'logged_in_salt' => 'A secret salt: it signs login cookies, and rewriting it logs everyone out.',
    'nonce_key' => 'A secret key: it signs nonces, and rewriting it breaks every open form.',
    'nonce_salt' => 'A secret salt: it signs nonces, and rewriting it breaks every open form.',
    'default_role' => 'The role every new account gets. A rewritten value can hand each new registration more rights than intended.',
    'users_can_register' => 'Whether anyone may register. It is a security setting, not text.',
    'admin_email' => 'The address WordPress sends recovery and security mail to. Change it in Settings > General, which confirms the new address first.',
    'new_admin_email' => 'An admin email change waiting for confirmation.',
    'db_version' => 'WordPress compares it to decide whether to run database upgrades.',
    'initial_db_version' => 'WordPress compares it to decide whether to run database upgrades.',
    'uninstall_plugins' => 'Plugin bookkeeping WordPress reads when a plugin is deleted.',
    'recently_activated' => 'Plugin bookkeeping, not content.',
];

/** Option name prefixes no search touches, with why. */
const DENIED_OPTION_PREFIXES = [
    '_transient_' => 'Transients are caches their owner rebuilds; rewriting one changes nothing for long and can feed stale data back.',
    '_site_transient_' => 'Transients are caches their owner rebuilds; rewriting one changes nothing for long and can feed stale data back.',
    'wppilot_kit_' => 'The kit\'s own plans, locks and state.',
];

/**
 * Option names that read like credentials: passwords, API and licence keys, tokens, secrets and
 * salts. A search-replace that rewrote one would either break the integration or, through the
 * preview's before/after snippets, show the secret to whoever asked for the diff.
 */
const CREDENTIAL_PATTERN = '/password|passwd|passphrase|secret|token|credential|api_?key|apikey|licen[cs]e_?key|private_?key|access_?key|auth_?key|salt|oauth|bearer/i';

/**
 * Why an option must not be searched, or null when it may be.
 */
function option_denied(string $name): ?string
{
    $lower = strtolower($name);
    if (isset(DENIED_OPTIONS[$lower])) {
        return DENIED_OPTIONS[$lower];
    }
    // {prefix}user_roles holds every role's capabilities; renaming a capability inside it takes
    // that right away from every user who has the role, administrators included.
    if (str_ends_with($lower, 'user_roles')) {
        return 'The roles and their capabilities. A rewritten capability takes that right away from every user with the role, administrators included.';
    }
    foreach (denied_prefixes() as $prefix => $reason) {
        if (str_starts_with($lower, strtolower((string) $prefix))) {
            return $reason;
        }
    }
    if (preg_match(CREDENTIAL_PATTERN, $name) === 1) {
        return 'The name reads like a credential (a password, key, token or secret), so it is never searched or shown in a diff.';
    }
    return null;
}

/**
 * DENIED_OPTION_PREFIXES plus the host plugin's own: its settings, tokens and ledger
 * configuration are not content, and the host knows their prefix where the kit does not. A host
 * can only add to the list.
 *
 * @return array<string, string>
 */
function denied_prefixes(): array
{
    $prefixes = DENIED_OPTION_PREFIXES;
    $host = Runtime\host()->extension('protected-option-prefixes');
    foreach (is_array($host) ? $host : [] as $prefix) {
        if (is_string($prefix) && $prefix !== '' && !isset($prefixes[$prefix])) {
            $prefixes[$prefix] = 'The site\'s AI connection plugin keeps its own settings and credentials under this prefix.';
        }
    }
    return $prefixes;
}

/**
 * Whether the search touches a string under a key that reads like a credential, inside a
 * serialized or JSON option. Such an option is left out as a whole: its other values may be
 * content, but the diff would quote the secret and the write would change it.
 */
function touches_credential(string $raw, array $matcher): bool
{
    $data = null;
    if (is_serialized($raw)) {
        $problem = '';
        $data = quietly(static fn(): mixed => unserialize($raw, ['allowed_classes' => false]), $problem);
    } else {
        $trimmed = ltrim($raw);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $data = json_decode($raw, true);
        }
    }
    return is_array($data) && credential_walk($data, $matcher, false);
}

function credential_walk(mixed $value, array $matcher, bool $under_credential): bool
{
    if (is_string($value)) {
        return $under_credential && matches($value, $matcher);
    }
    if (!is_array($value)) {
        return false;
    }
    foreach ($value as $key => $item) {
        if (credential_walk($item, $matcher, $under_credential || (is_string($key) && preg_match(CREDENTIAL_PATTERN, $key) === 1))) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<string, mixed> $input
 * @return array{options: list<string>, after_id: int}|WP_Error
 */
function option_scope(array $input): array|WP_Error
{
    foreach (['post_types', 'statuses', 'post_ids', 'fields', 'meta_keys'] as $post_only) {
        if (array_key_exists($post_only, $input)) {
            return new WP_Error('kit_sr_bad_scope', sprintf('%s applies to target "posts" only; with target "options" name the options to search in options.', $post_only), ['status' => 400]);
        }
    }
    $patterns = strings($input['options'] ?? ['*']);
    if ($patterns === []) {
        return new WP_Error('kit_sr_empty_scope', 'The scope is empty: name at least one option or pattern, or leave options out to search them all.', ['status' => 400]);
    }
    if (count($patterns) > MAX_OPTION_PATTERNS) {
        return new WP_Error('kit_sr_too_many_options', sprintf('Name at most %d options or patterns.', MAX_OPTION_PATTERNS), ['status' => 400]);
    }
    foreach ($patterns as $pattern) {
        if (preg_match('/^[A-Za-z0-9_\-:.*]{1,191}$/', $pattern) !== 1) {
            return new WP_Error('kit_sr_bad_option', sprintf('"%s" is not an option name or pattern; use letters, digits, _ - : . and * as a wildcard.', $pattern), ['status' => 400]);
        }
        if (!str_contains($pattern, '*') && option_denied($pattern) !== null) {
            return new WP_Error('kit_sr_option_protected', sprintf('"%s" is never searched: %s', $pattern, (string) option_denied($pattern)), ['status' => 403]);
        }
    }
    return ['options' => $patterns, 'after_id' => max(0, (int) ($input['after_id'] ?? 0))];
}

/** @param list<string> $patterns */
function option_in_scope(string $name, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (preg_match('/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i', $name) === 1) {
            return true;
        }
    }
    return false;
}

/**
 * The next option rows after a cursor: id, name and value size, without the values.
 *
 * @return list<array{id: int, name: string, bytes: int}>
 */
function option_rows(int $after_id, int $limit): array
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_id, option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE option_id > %d ORDER BY option_id ASC LIMIT %d",
        $after_id,
        $limit,
    ), ARRAY_A);
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $out[] = ['id' => (int) $row['option_id'], 'name' => (string) $row['option_name'], 'bytes' => (int) $row['bytes']];
    }
    return $out;
}

/**
 * Values exactly as stored, for these option ids.
 *
 * @param list<int> $ids
 * @return array<int, string>
 */
function option_values(array $ids): array
{
    global $wpdb;
    if ($ids === []) {
        return [];
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_id, option_value FROM {$wpdb->options} WHERE option_id IN (" . placeholders(count($ids), '%d') . ')',
        $ids,
    ), ARRAY_A);
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $out[(int) $row['option_id']] = (string) $row['option_value'];
    }
    return $out;
}

/** One option's value exactly as stored, or null when there is no such option. */
function raw_option_value(string $name): ?string
{
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
    return is_string($value) ? $value : null;
}

/**
 * The before-image of one option: its stored bytes, so the undo can put back exactly them.
 *
 * @return array{type: string, option: string, value: string, fingerprint: string}
 */
function option_snapshot(string $name, string $raw): array
{
    return ['type' => OPTION_STRATEGY, 'option' => $name, 'value' => $raw, 'fingerprint' => hash('sha256', $raw)];
}

/**
 * The option names an apply call or job payload narrows a plan to; anything not a string is dropped.
 *
 * @return list<string>
 */
function option_subset(mixed $raw): array
{
    $names = [];
    foreach (is_array($raw) ? $raw : [] as $name) {
        if (is_string($name) && $name !== '') {
            $names[$name] = $name;
        }
    }
    return array_values($names);
}

function cannot_manage_options(): WP_Error
{
    return new WP_Error('kit_sr_forbidden', 'Searching options needs the manage_options capability: option values include settings only administrators may read.', ['status' => 403]);
}

/**
 * wppilot/search-replace-preview with target "options".
 *
 * @param array<string, mixed> $input
 * @param array<string, mixed> $matcher
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity -- Each reason to leave an option out is checked before it enters the plan.
function preview_options(array $input, array $matcher): array|WP_Error
{
    if (!current_user_can('manage_options')) {
        return cannot_manage_options();
    }
    $scope = option_scope($input);
    if ($scope instanceof WP_Error) {
        return $scope;
    }
    sweep_plans();

    $budget = Runtime\host()->ledger()->snapshot_budget();
    $deadline = microtime(true) + TIME_BUDGET;
    $after = $scope['after_id'];
    $scanned = 0;
    $protected = 0;
    $items = [];
    $skipped = [];
    $complete = false;
    $stopped_by = null;

    while (true) {
        $limit = min(OPTION_SCAN_CHUNK, SCAN_LIMIT - $scanned);
        if ($limit <= 0) {
            $stopped_by = 'scan_limit';
            break;
        }
        $rows = option_rows($after, $limit);
        $wanted = [];
        foreach ($rows as $row) {
            if (!option_in_scope($row['name'], $scope['options'])) {
                continue;
            }
            if (option_denied($row['name']) !== null) {
                $protected++;
                continue;
            }
            if ($row['bytes'] > MAX_VALUE_BYTES) {
                $skipped[] = ['option' => $row['name'], 'reason' => 'kit_sr_value_too_large', 'message' => 'The value is larger than 4 MB, so it was left alone.'];
                continue;
            }
            $wanted[] = $row['id'];
        }
        $values = option_values($wanted);
        foreach ($rows as $row) {
            if (count($items) >= MAX_PLAN_OPTIONS) {
                $stopped_by = 'plan_full';
                break 2;
            }
            if (microtime(true) >= $deadline) {
                $stopped_by = 'time';
                break 2;
            }
            $after = $row['id'];
            $scanned++;
            if (!isset($values[$row['id']])) {
                continue;
            }
            $raw = $values[$row['id']];
            $name = $row['name'];
            $result = replace_meta($raw, $matcher);
            if ($result === null) {
                continue;
            }
            if (isset($result['skip'])) {
                $skipped[] = ['option' => $name, 'reason' => (string) $result['skip'], 'message' => (string) $result['message']];
                continue;
            }
            if (touches_credential($raw, $matcher)) {
                $skipped[] = ['option' => $name, 'reason' => 'kit_sr_option_credential', 'message' => 'A match sits under a key that reads like a credential (a password, key, token or secret), so the option is left out and the match is not shown.'];
                continue;
            }
            $snapshot = option_snapshot($name, $raw);
            if (wp_json_encode($snapshot) === false) {
                $skipped[] = ['option' => $name, 'reason' => 'kit_sr_option_not_text', 'message' => 'The value is not valid UTF-8, so the ledger could not keep a copy for undo; it was left alone.'];
                continue;
            }
            if (snapshot_bytes($snapshot) > $budget) {
                $skipped[] = ['option' => $name, 'reason' => 'kit_sr_too_large_to_undo', 'message' => 'The value is larger than one call may keep for undo, so the option is left out rather than changed without a way back.'];
                continue;
            }
            $change = ['target' => 'option:' . $name, 'encoding' => (string) $result['encoding'], 'count' => (int) $result['count'], 'samples' => $result['samples']];
            if ($result['encoding'] === 'json') {
                $change['escaping_changes'] = (bool) $result['escaping_changes'];
            }
            $items[] = [
                'option' => $name,
                'option_id' => $row['id'],
                'fingerprint' => $snapshot['fingerprint'],
                'count' => (int) $result['count'],
                'changes' => [$change],
                'status' => 'pending',
                'reason' => '',
            ];
        }
        if (count($rows) < $limit) {
            $complete = true;
            break;
        }
    }

    $totals = [
        'options_scanned' => $scanned,
        'options' => count($items),
        'matches' => array_sum(array_column($items, 'count')),
        'protected' => $protected,
        'skipped' => count($skipped),
    ];
    $cursor = [
        'complete' => $complete,
        'stopped_by' => $complete ? null : $stopped_by,
        'next_after_id' => $complete ? null : $after,
    ];
    if ($items === []) {
        return ['plan_id' => null, 'target' => 'options', 'totals' => $totals, 'options' => [], 'skipped' => array_slice($skipped, 0, 100), 'caches' => [], 'notes' => option_notes([], $protected)] + $cursor;
    }

    $now = time();
    $plan = [
        'id' => new_plan_id(),
        'kind' => 'options',
        'owner' => get_current_user_id(),
        'created_at' => $now,
        'expires_at' => $now + PLAN_TTL,
        'group' => wp_generate_uuid4(),
        'search' => $matcher['search'],
        'replace' => $matcher['replace'],
        'regex' => $matcher['regex'],
        'case_sensitive' => $matcher['case_sensitive'],
        'scope' => ['options' => $scope['options']],
        'items' => $items,
        'builders' => [],
        'skipped' => array_slice($skipped, 0, 200),
        'totals' => $totals,
        'cursor' => $cursor,
        'job_id' => '',
    ];
    save_plan($plan);

    return present_option_plan($plan, $input);
}

/**
 * @param list<array<string, mixed>> $items
 * @return list<string>
 */
function option_notes(array $items, int $protected): array
{
    $notes = [];
    if ($protected > 0) {
        $notes[] = sprintf('%d option(s) in scope were left out because they are never searched: the site address, active plugins and theme, cron, roles, secret keys and salts, transients, and anything named like a credential.', $protected);
    }
    foreach ($items as $item) {
        foreach ($item['changes'] as $change) {
            if (($change['encoding'] ?? '') === 'json' && ($change['escaping_changes'] ?? false) === true) {
                $notes[] = 'Some JSON values cannot be re-encoded byte for byte: after the change their escaping of slashes or non-ASCII characters may differ. The data means the same.';
                break 2;
            }
        }
    }
    $notes[] = 'Options are often cached by the plugin that owns them; check the setting in its own screen after applying.';
    return $notes;
}

/**
 * The reviewable view of an options plan.
 *
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function present_option_plan(array $plan, array $input): array
{
    /** @var list<array<string, mixed>> $items */
    $items = $plan['items'];
    $offset = max(0, (int) ($input['diff_offset'] ?? 0));
    $limit = min(100, max(1, (int) ($input['diff_limit'] ?? 50)));
    $page = [];
    foreach (array_slice($items, $offset, $limit) as $item) {
        $page[] = ['option' => $item['option'], 'fingerprint' => $item['fingerprint'], 'matches' => $item['count'], 'status' => $item['status'], 'changes' => $item['changes']];
    }
    $next = $offset + count($page);
    $pending = count(array_filter($items, static fn(array $item): bool => $item['status'] === 'pending'));

    return [
        'plan_id' => $plan['id'],
        'target' => 'options',
        'expires_at' => gmdate('c', (int) $plan['expires_at']),
        'group' => $plan['group'],
        'search' => $plan['search'],
        'replace' => $plan['replace'],
        'regex' => $plan['regex'],
        'case_sensitive' => $plan['case_sensitive'],
        'totals' => $plan['totals'] + ['pending' => $pending],
        'options' => $page,
        'diff_offset' => $offset,
        'next_diff_offset' => $next < count($items) ? $next : null,
        'skipped' => array_slice((array) $plan['skipped'], 0, 100),
        'caches' => [],
        'notes' => option_notes($items, (int) ($plan['totals']['protected'] ?? 0)),
    ] + (array) $plan['cursor'];
}

/**
 * Apply up to MAX_POSTS_PER_CALL pending options of an options plan. Same contract as
 * run_batch(): each option is written only if its stored bytes still match the preview, gets its
 * own ledger row in the plan's group, and the call stops before a before-image would exceed the
 * ledger's snapshot budget.
 *
 * update_option() does not unslash, unlike the post and meta writers, so values go in as they are.
 *
 * @param array<string, mixed> $plan
 * @param list<string> $subset Only these options of the plan; empty for all.
 * @return array<string, mixed>|WP_Error
 */
// @mago-expect lint:cyclomatic-complexity -- Each refusal is checked before an option is written.
function run_option_batch(string $plan_id, array $subset = []): array|WP_Error
{
    if (!current_user_can('manage_options')) {
        return cannot_manage_options();
    }
    if (!lock_plan($plan_id)) {
        return new WP_Error('kit_sr_plan_busy', 'Another call is applying this plan right now; check search-replace-status, then retry.', ['status' => 409]);
    }

    $ledger = Runtime\host()->ledger();
    $wanted = $subset !== [] ? array_flip($subset) : null;
    $records = [];
    $written = [];
    $skipped = [];
    $stopped_by = null;
    $recorded = [];
    try {
        $plan = load_plan($plan_id);
        if ($plan instanceof WP_Error) {
            return $plan;
        }
        $matcher = matcher($plan);
        if ($matcher instanceof WP_Error) {
            return $matcher;
        }
        $budget = $ledger->snapshot_budget();
        $used = 0;
        $processed = 0;
        $deadline = microtime(true) + TIME_BUDGET;
        try {
            foreach ($plan['items'] as $index => $item) {
                $name = (string) $item['option'];
                if ($item['status'] !== 'pending' || ($wanted !== null && !isset($wanted[$name]))) {
                    continue;
                }
                if ($processed >= MAX_POSTS_PER_CALL) {
                    $stopped_by = 'batch_limit';
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $stopped_by = 'time';
                    break;
                }
                $outcome = apply_option_item($item, $matcher, $budget, $used, $plan);
                if ($outcome['status'] === 'stop') {
                    $stopped_by = 'snapshot_budget';
                    break;
                }
                $processed++;
                $plan['items'][$index]['status'] = $outcome['status'];
                $plan['items'][$index]['reason'] = $outcome['reason'];
                if (isset($outcome['record'])) {
                    $records[] = $outcome['record'];
                    $written[] = $outcome['report'];
                } else {
                    $skipped[] = ['option' => $name, 'reason' => $outcome['reason'], 'message' => $outcome['message']];
                }
            }
        } finally {
            $recorded = $ledger->record_items(APPLY_ABILITY, $records, (string) $plan['group']);
            $plan['expires_at'] = max((int) $plan['expires_at'], time() + PLAN_TTL);
            save_plan($plan);
        }
    } finally {
        unlock_plan($plan_id);
    }

    foreach ($written as $position => $report) {
        $written[$position]['change_id'] = (string) ($recorded['change_ids'][$position] ?? '');
    }
    $remaining = 0;
    $total = 0;
    foreach ($plan['items'] as $item) {
        if ($wanted !== null && !isset($wanted[(string) $item['option']])) {
            continue;
        }
        $total++;
        if ($item['status'] === 'pending') {
            $remaining++;
        }
    }

    return [
        'plan_id' => $plan_id,
        'target' => 'options',
        'group' => (string) $plan['group'],
        'applied' => count($written),
        'options' => $written,
        'posts' => [],
        'skipped' => $skipped,
        'remaining' => $remaining,
        'stopped_by' => $remaining > 0 ? $stopped_by : null,
        'cursor' => $remaining > 0 ? ['plan_id' => $plan_id, 'options' => $subset] : null,
        'total' => $total,
        'without_before_image' => (int) ($recorded['without_before_image'] ?? 0),
        'caches' => [],
        'expires_at' => gmdate('c', (int) $plan['expires_at']),
    ];
}

/**
 * Write one planned option, or say why not.
 *
 * @param array<string, mixed> $item
 * @param array<string, mixed> $matcher
 * @param array<string, mixed> $plan
 * @return array{status: string, reason: string, message: string, record?: array<string, mixed>, report?: array<string, mixed>}
 */
function apply_option_item(array $item, array $matcher, int $budget, int &$used, array $plan): array
{
    $name = (string) $item['option'];
    $skip = static fn(string $reason, string $message): array => ['status' => 'skipped', 'reason' => $reason, 'message' => $message];

    // Checked again: the host's protected prefixes are read per request.
    $denied = option_denied($name);
    if ($denied !== null) {
        return $skip('kit_sr_option_protected', $denied);
    }
    $raw = raw_option_value($name);
    if ($raw === null) {
        return $skip('kit_sr_option_missing', 'The option no longer exists.');
    }
    if (!hash_equals((string) $item['fingerprint'], hash('sha256', $raw))) {
        return $skip('kit_sr_changed_since_preview', 'The option changed after the preview, so the reviewed diff no longer describes it. Preview it again.');
    }
    $snapshot = option_snapshot($name, $raw);
    $bytes = snapshot_bytes($snapshot);
    if ($bytes > $budget) {
        return $skip('kit_sr_too_large_to_undo', 'The before-image is larger than one call may keep, so the option was not changed.');
    }
    if ($used + $bytes > $budget) {
        return ['status' => 'stop', 'reason' => '', 'message' => ''];
    }
    $result = replace_meta($raw, $matcher);
    if ($result === null || isset($result['skip']) || touches_credential($raw, $matcher)) {
        return $skip('kit_sr_changed_since_preview', 'The option no longer matches the way the preview found it. Preview it again.');
    }

    $used += $bytes;
    update_option($name, $result['value']);
    $stored = raw_option_value($name);
    $warnings = [];
    if ($stored === null || $stored === $raw) {
        // update_option() keeps the old value when a sanitize_option filter turns the new one
        // back into it, or when the write fails.
        return ['status' => 'failed', 'reason' => 'kit_sr_write_failed', 'message' => 'WordPress kept the old value; a sanitize filter for this option may have rejected the new one.'];
    }
    $verified = $stored === $result['stored'];
    if (!$verified) {
        $warnings[] = 'The option was stored differently from the preview (a sanitize filter changed it).';
    }
    $snapshot['after'] = hash('sha256', $stored);

    return [
        'status' => 'applied',
        'reason' => '',
        'message' => '',
        'record' => [
            'input' => [
                'plan_id' => (string) $plan['id'],
                'option' => $name,
                'search' => (string) $plan['search'],
                'replace' => (string) $plan['replace'],
                'regex' => (bool) $plan['regex'],
            ],
            'before' => $snapshot,
            'result' => ['option' => $name, 'matches' => (int) $result['count'], 'verified' => $verified],
            'item' => ['option' => $name],
        ],
        'report' => ['option' => $name, 'matches' => (int) $result['count'], 'verified' => $verified, 'warnings' => $warnings],
    ];
}

/**
 * Undo one option write: put back the exact bytes it held before.
 *
 * Refused, unless forced, when the option no longer holds what the apply wrote (someone changed
 * the setting since, and undoing would throw that away), or was deleted since.
 *
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $entry
 * @return array<string, mixed>|WP_Error
 */
function restore_option(array $payload, array $entry = []): array|WP_Error
{
    $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
    $name = (string) ($snapshot['option'] ?? '');
    $before = $snapshot['value'] ?? null;
    if ($name === '' || !is_string($before)) {
        return new WP_Error('kit_rollback_invalid', 'The change record does not name an option and its value.');
    }
    $denied = option_denied($name);
    if ($denied !== null) {
        return new WP_Error('kit_sr_option_protected', sprintf('"%s" is a protected option, so it was left alone: %s', $name, $denied));
    }
    $force = ($entry['force'] ?? false) === true;
    $current = raw_option_value($name);
    $after = (string) ($snapshot['after'] ?? '');
    if (!$force && ($current === null || ($after !== '' && !hash_equals($after, hash('sha256', $current))))) {
        return new WP_Error('kit_sr_option_changed_since', sprintf(
            $current === null
                ? 'The option "%s" was deleted after this change, so restoring it would recreate it. Nothing was changed. Undo with force=true to recreate it anyway.'
                : 'The option "%s" changed after this change, so undoing it would lose that edit. Nothing was changed. Undo with force=true to put the earlier value back anyway.',
            $name,
        ), ['status' => 409]);
    }

    $value = $before;
    if (is_serialized($before)) {
        $problem = '';
        $value = quietly(static fn(): mixed => unserialize($before, ['allowed_classes' => false]), $problem);
        if (($value === false && $before !== 'b:0;') || contains_object($value)) {
            return new WP_Error('kit_rollback_invalid', 'The recorded value could not be read back safely, so the option was left alone.');
        }
    }
    update_option($name, $value);
    $observed = raw_option_value($name);

    return [
        'option' => $name,
        'expected_fingerprint' => (string) ($snapshot['fingerprint'] ?? ''),
        'observed_fingerprint' => is_string($observed) ? hash('sha256', $observed) : '',
        'verified' => $observed === $before,
    ];
}

function register_option_strategy(Ledger $ledger): void
{
    $ledger->register_strategy(OPTION_STRATEGY, static fn(array $payload, array $entry = []): array|WP_Error => restore_option($payload, $entry));
}
