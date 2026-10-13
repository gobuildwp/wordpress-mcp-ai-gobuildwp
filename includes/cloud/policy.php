<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Safety settings pushed from GoBuildWP Cloud, §7.
 *
 * A Cloud workspace can keep one safety policy for many sites: the safety
 * profile, the approval mode, and which abilities are switched off or need a
 * confirmation. The site stays the final authority:
 *
 * - Nothing is applied unless the site owner opted in on the Cloud panel.
 *   "Cloud may tighten" lets a policy make the site stricter; "Cloud may also
 *   loosen" is a separate opt-in. Both are off by default and both are cleared
 *   on disconnect.
 * - A policy is applied whole or not at all: one loosening change without the
 *   loosen opt-in refuses the lot, naming what would have loosened.
 * - Developer Full Access can never be set from the Cloud.
 * - The Cloud only lifts ability blocks it set itself. A block the owner set in
 *   wp-admin is never removed by a policy.
 * - Changes go through the same setters the wp-admin screens use.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** The owner's opt-in: {tighten: bool, loosen: bool}. */
const WPPILOT_CLOUD_MANAGE_OPTION = 'wppilot_cloud_manage';

/** What the last applied policy set, and a short history: {id, name, hash, applied_at, disabled, confirm, history}. */
const WPPILOT_CLOUD_POLICY_OPTION = 'wppilot_cloud_policy';

/** Profiles a policy may set. Developer Full Access is wp-admin only. */
const WPPILOT_CLOUD_POLICY_PROFILES = ['readonly', 'production'];

/** Longest ability name a policy may carry. */
const WPPILOT_CLOUD_POLICY_MAX_NAME = 128;

/** Most abilities one list may name. */
const WPPILOT_CLOUD_POLICY_MAX_ABILITIES = 500;

const WPPILOT_CLOUD_POLICY_HISTORY = 10;

/**
 * @return array{tighten: bool, loosen: bool}
 */
function wppilot_cloud_manage_settings(): array
{
    $stored = get_option(WPPILOT_CLOUD_MANAGE_OPTION, []);
    $tighten = is_array($stored) && ($stored['tighten'] ?? false) === true;
    $loosen = $tighten && is_array($stored) && ($stored['loosen'] ?? false) === true;

    return ['tighten' => $tighten, 'loosen' => $loosen];
}

/** "May tighten" is the switch: unticking it opts out entirely, whatever "may also loosen" says. */
function wppilot_cloud_save_manage_settings(bool $tighten, bool $loosen): void
{
    update_option(WPPILOT_CLOUD_MANAGE_OPTION, ['tighten' => $tighten, 'loosen' => $tighten && $loosen], autoload: false);
}

/**
 * The settings a policy covers, as the site has them now.
 *
 * @return array{safety_profile: string, confirmation_mode: string, disabled: list<string>, require_confirmation: list<string>}
 */
function wppilot_cloud_policy_current(): array
{
    $disabled = [];
    $confirm = [];
    foreach (wppilot_get_ability_rules() as $name => $rule) {
        if ($rule['disabled']) {
            $disabled[] = $name;
        }
        if ($rule['require_confirmation']) {
            $confirm[] = $name;
        }
    }
    sort($disabled);
    sort($confirm);

    return [
        'safety_profile' => wppilot_get_safety_profile(),
        'confirmation_mode' => wppilot_confirmation_mode(),
        'disabled' => $disabled,
        'require_confirmation' => $confirm,
    ];
}

/**
 * @return array{id: string, name: string, hash: string, applied_at: string, disabled: list<string>, confirm: list<string>, history: list<array<string, mixed>>}|null
 */
function wppilot_cloud_applied_policy(): ?array
{
    $stored = get_option(WPPILOT_CLOUD_POLICY_OPTION, null);
    if (!is_array($stored) || !is_string($stored['id'] ?? null)) {
        return null;
    }
    $list = static fn(mixed $v): array => is_array($v) ? array_values(array_filter($v, 'is_string')) : [];

    return [
        'id' => $stored['id'],
        'name' => is_string($stored['name'] ?? null) ? $stored['name'] : '',
        'hash' => is_string($stored['hash'] ?? null) ? $stored['hash'] : '',
        'applied_at' => is_string($stored['applied_at'] ?? null) ? $stored['applied_at'] : '',
        'disabled' => $list($stored['disabled'] ?? []),
        'confirm' => $list($stored['confirm'] ?? []),
        'history' => is_array($stored['history'] ?? null) ? array_values(array_filter($stored['history'], 'is_array')) : [],
    ];
}

/**
 * Forget the Cloud's claim on every ability whose block or confirmation the owner just changed.
 *
 * Called from wppilot_update_ability_rules() for every write that is not the Cloud's own. Without
 * this, an owner who switched a Cloud-set block off and on again would have it lifted by a later
 * policy, although it is now the owner's block.
 *
 * @param array<string, array{disabled: bool, require_confirmation: bool, min_profile: string}> $before
 * @param array<string, array<string, mixed>> $after Rules about to be stored.
 */
function wppilot_cloud_release_changed_rules(array $before, array $after): void
{
    $applied = wppilot_cloud_applied_policy();
    if ($applied === null) {
        return;
    }
    $changed = static function (string $ability, string $flag) use ($before, $after): bool {
        return ($before[$ability][$flag] ?? false) !== (($after[$ability][$flag] ?? false) === true);
    };
    $disabled = array_values(array_filter($applied['disabled'], static fn(string $a): bool => !$changed($a, 'disabled')));
    $confirm = array_values(array_filter($applied['confirm'], static fn(string $a): bool => !$changed($a, 'require_confirmation')));
    if ($disabled === $applied['disabled'] && $confirm === $applied['confirm']) {
        return;
    }
    $stored = get_option(WPPILOT_CLOUD_POLICY_OPTION, []);
    if (is_array($stored)) {
        $stored['disabled'] = $disabled;
        $stored['confirm'] = $confirm;
        update_option(WPPILOT_CLOUD_POLICY_OPTION, $stored, autoload: false);
    }
}

/**
 * Validate a policy as the Cloud sent it.
 *
 * @param array<mixed> $body
 * @return array{id: string, name: string, safety_profile: string|null, confirmation_mode: string|null, disabled: list<string>, require_confirmation: list<string>}|WP_Error
 */
function wppilot_cloud_policy_parse(array $body): array|WP_Error
{
    $bad = static fn(string $why): WP_Error => new WP_Error('wppilot_cloud_policy_invalid', $why, ['status' => 400]);

    $id = $body['id'] ?? null;
    if (!is_string($id) || preg_match('/^[A-Za-z0-9-]{1,64}$/', $id) !== 1) {
        return $bad('id must be 1-64 letters, digits or dashes.');
    }
    $name = $body['name'] ?? '';
    if (!is_string($name) || mb_strlen($name) > 120) {
        return $bad('name must be a string of at most 120 characters.');
    }

    $profile = $body['safety_profile'] ?? null;
    if ($profile !== null && !in_array($profile, WPPILOT_CLOUD_POLICY_PROFILES, strict: true)) {
        return $bad('safety_profile must be readonly or production. Developer Full Access can only be set in wp-admin.');
    }
    $mode = $body['confirmation_mode'] ?? null;
    if ($mode !== null && !in_array($mode, WPPILOT_CONFIRMATION_MODES, strict: true)) {
        return $bad('confirmation_mode must be argument or human.');
    }

    $lists = [];
    foreach (['disabled', 'require_confirmation'] as $key) {
        $value = $body[$key] ?? [];
        if (!is_array($value) || array_values($value) !== $value || count($value) > WPPILOT_CLOUD_POLICY_MAX_ABILITIES) {
            return $bad(sprintf('%s must be a list of at most %d ability names.', $key, WPPILOT_CLOUD_POLICY_MAX_ABILITIES));
        }
        foreach ($value as $ability) {
            if (!is_string($ability) || strlen($ability) > WPPILOT_CLOUD_POLICY_MAX_NAME || preg_match('#^[a-z0-9-]+/[a-z0-9/-]+\z#', $ability) !== 1) {
                return $bad(sprintf('%s holds something that is not an ability name.', $key));
            }
        }
        $names = array_values(array_unique($value));
        sort($names);
        $lists[$key] = $names;
    }

    return [
        'id' => $id,
        'name' => sanitize_text_field($name),
        'safety_profile' => $profile,
        'confirmation_mode' => $mode,
        'disabled' => $lists['disabled'],
        'require_confirmation' => $lists['require_confirmation'],
    ];
}

/**
 * Every change a policy makes, each marked tighten or loosen.
 *
 * Ability lists: a name the policy lists and the site does not have on is
 * switched on (tighten). A name the previous Cloud policy set and this one no
 * longer lists is switched off again (loosen) - but only names the Cloud set:
 * the owner's own blocks are not the Cloud's to lift. Hub-protected abilities
 * (the discovery tools) are never touched.
 *
 * @param array{safety_profile: string, confirmation_mode: string, disabled: list<string>, require_confirmation: list<string>} $current
 * @param array{safety_profile: string|null, confirmation_mode: string|null, disabled: list<string>, require_confirmation: list<string>} $policy
 * @param array{disabled: list<string>, confirm: list<string>}|null $previous What the last Cloud policy set.
 * @return list<array{setting: string, ability?: string, from: string, to: string, direction: 'tighten'|'loosen'}>
 */
function wppilot_cloud_policy_changes(array $current, array $policy, ?array $previous): array
{
    $changes = [];

    if ($policy['safety_profile'] !== null && $policy['safety_profile'] !== $current['safety_profile']) {
        $changes[] = [
            'setting' => 'safety_profile',
            'from' => $current['safety_profile'],
            'to' => $policy['safety_profile'],
            'direction' => wppilot_safety_profile_rank($policy['safety_profile']) < wppilot_safety_profile_rank($current['safety_profile']) ? 'tighten' : 'loosen',
        ];
    }
    if ($policy['confirmation_mode'] !== null && $policy['confirmation_mode'] !== $current['confirmation_mode']) {
        $changes[] = [
            'setting' => 'confirmation_mode',
            'from' => $current['confirmation_mode'],
            'to' => $policy['confirmation_mode'],
            'direction' => $policy['confirmation_mode'] === 'human' ? 'tighten' : 'loosen',
        ];
    }

    foreach ([['disabled', 'disabled'], ['require_confirmation', 'confirm']] as [$setting, $previous_key]) {
        $on = $current[$setting];
        foreach ($policy[$setting] as $ability) {
            if (!in_array($ability, $on, strict: true) && !wppilot_ability_is_hub_protected($ability)) {
                $changes[] = ['setting' => $setting, 'ability' => $ability, 'from' => 'off', 'to' => 'on', 'direction' => 'tighten'];
            }
        }
        foreach ($previous[$previous_key] ?? [] as $ability) {
            if (in_array($ability, $on, strict: true) && !in_array($ability, $policy[$setting], strict: true)) {
                $changes[] = ['setting' => $setting, 'ability' => $ability, 'from' => 'on', 'to' => 'off', 'direction' => 'loosen'];
            }
        }
    }

    return $changes;
}

/**
 * Preview or apply a policy. The whole policy or nothing.
 *
 * @param array<mixed> $body
 * @return array<string, mixed>|WP_Error
 */
function wppilot_cloud_apply_policy(array $body, bool $dry_run = false): array|WP_Error
{
    $manage = wppilot_cloud_manage_settings();
    if (!$manage['tighten']) {
        return new WP_Error(
            'wppilot_cloud_policy_not_allowed',
            __('The site owner has not let GoBuildWP Cloud manage safety settings. It is switched on in wp-admin: GoBuildWP → Connect → GoBuildWP Cloud.', domain: 'wppilot'),
            ['status' => 403],
        );
    }

    $policy = wppilot_cloud_policy_parse($body);
    if ($policy instanceof WP_Error) {
        return $policy;
    }

    $applied = wppilot_cloud_applied_policy();
    $current = wppilot_cloud_policy_current();
    $changes = wppilot_cloud_policy_changes($current, $policy, $applied);
    $loosening = array_values(array_filter($changes, static fn(array $c): bool => $c['direction'] === 'loosen'));

    if ($loosening !== [] && !$manage['loosen']) {
        return new WP_Error(
            'wppilot_cloud_policy_loosen_refused',
            __('This policy would loosen safety settings, and the site owner only lets GoBuildWP Cloud tighten them. Nothing was changed.', domain: 'wppilot'),
            ['status' => 403, 'loosening' => $loosening],
        );
    }

    $hash = hash('sha256', (string) wp_json_encode([$policy['safety_profile'], $policy['confirmation_mode'], $policy['disabled'], $policy['require_confirmation']]));
    if ($dry_run) {
        return ['applied' => false, 'dry_run' => true, 'changes' => $changes, 'policy' => ['id' => $policy['id'], 'hash' => $hash]];
    }

    if ($policy['safety_profile'] !== null && $policy['safety_profile'] !== $current['safety_profile']) {
        wppilot_update_safety_profile($policy['safety_profile']);
    }
    if ($policy['confirmation_mode'] !== null && $policy['confirmation_mode'] !== $current['confirmation_mode']) {
        update_option(WPPILOT_CONFIRMATION_MODE_OPTION, $policy['confirmation_mode'], autoload: true);
    }

    $rules = wppilot_get_ability_rules();
    // Only blocks still on can still be the Cloud's: one the owner lifted in the meantime is gone from its list.
    $set_by_cloud = [
        'disabled' => array_values(array_intersect($applied['disabled'] ?? [], $current['disabled'])),
        'require_confirmation' => array_values(array_intersect($applied['confirm'] ?? [], $current['require_confirmation'])),
    ];
    foreach ($changes as $change) {
        if (!isset($change['ability'])) {
            continue;
        }
        $rule = $rules[$change['ability']] ?? ['disabled' => false, 'require_confirmation' => false, 'min_profile' => ''];
        $rule[$change['setting']] = $change['to'] === 'on';
        $rules[$change['ability']] = $rule;
        $mine = &$set_by_cloud[$change['setting']];
        $mine = $change['to'] === 'on'
            ? array_values(array_unique([...$mine, $change['ability']]))
            : array_values(array_diff($mine, [$change['ability']]));
        unset($mine);
    }
    $GLOBALS['wppilot_cloud_policy_applying'] = true;
    try {
        wppilot_update_ability_rules($rules);
    } finally {
        unset($GLOBALS['wppilot_cloud_policy_applying']);
    }

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $history = $applied['history'] ?? [];
    array_unshift($history, ['id' => $policy['id'], 'name' => $policy['name'], 'applied_at' => $now, 'changes' => count($changes)]);
    update_option(WPPILOT_CLOUD_POLICY_OPTION, [
        'id' => $policy['id'],
        'name' => $policy['name'],
        'hash' => $hash,
        'applied_at' => $now,
        'disabled' => $set_by_cloud['disabled'],
        'confirm' => $set_by_cloud['require_confirmation'],
        'history' => array_slice($history, 0, WPPILOT_CLOUD_POLICY_HISTORY),
    ], autoload: false);

    return ['applied' => true, 'dry_run' => false, 'changes' => $changes, 'policy' => ['id' => $policy['id'], 'hash' => $hash, 'applied_at' => $now]];
}

/**
 * The policy part of the status answer: what the owner allows, what the Cloud last applied, what the site has now.
 *
 * @return array<string, mixed>
 */
function wppilot_cloud_policy_status(): array
{
    $applied = wppilot_cloud_applied_policy();

    return [
        'manage' => wppilot_cloud_manage_settings(),
        'applied' => $applied === null ? null : ['id' => $applied['id'], 'name' => $applied['name'], 'hash' => $applied['hash'], 'applied_at' => $applied['applied_at']],
        'current' => wppilot_cloud_policy_current(),
    ];
}

/**
 * POST /wppilot/v1/cloud/policy. Body: the policy, plus dry_run to preview.
 */
function wppilot_cloud_rest_policy(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    if (wppilot_cloud_link() === null) {
        return new WP_Error('wppilot_cloud_forbidden', __('This site is not connected to GoBuildWP Cloud.', domain: 'wppilot'), ['status' => 403]);
    }
    $body = $request->get_json_params();
    if (!is_array($body)) {
        return new WP_Error('wppilot_cloud_policy_invalid', 'Send the policy as a JSON object.', ['status' => 400]);
    }

    $result = wppilot_cloud_apply_policy($body, dry_run: ($body['dry_run'] ?? false) === true);

    return $result instanceof WP_Error ? $result : new WP_REST_Response($result, 200);
}

/**
 * admin-post: wppilot_cloud_manage. The owner's opt-in.
 */
function wppilot_cloud_handle_manage(): void
{
    wppilot_cloud_require_manager();
    check_admin_referer('wppilot_cloud_manage');

    wppilot_cloud_save_manage_settings(
        tighten: isset($_POST['wppilot_cloud_manage_tighten']),
        loosen: isset($_POST['wppilot_cloud_manage_loosen']),
    );

    wp_safe_redirect(wppilot_cloud_admin_url(['wppilot_cloud_result' => 'manage_saved']));
    exit();
}
