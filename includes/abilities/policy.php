<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Per-ability rules: enable/disable, and the governance overrides.
 *
 * The Abilities Hub writes rules here; the policy filter applies them at
 * execution time. Some abilities are hub-protected and cannot be turned
 * off, or the agent would lose the means to turn anything back on.
 *
 * Besides `disabled`, a rule may carry `require_confirmation` (the gate pipeline
 * then demands a confirmed call even for an ability its author annotated as an
 * ordinary write) and `min_profile` (the ability is refused below that safety
 * profile). Both only ever tighten: see wppilot_ability_requires_confirmation()
 * and wppilot_ability_safety_policy(). They apply to any registered ability,
 * third-party ones included, which is the point: a builder's own abilities pass
 * through the same pipeline as GoBuildWP's.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Return the persisted per-ability hub rules.
 *
 * @return array<string, array{disabled: bool, require_confirmation: bool, min_profile: string}>
 */
function wppilot_get_ability_rules(): array
{
    /** @var mixed $stored */
    $stored = get_option('wppilot_ability_rules', default_value: []);
    if (!is_array($stored)) {
        return [];
    }

    $rules = [];
    /** @var mixed $rule */
    foreach ($stored as $ability_name => $rule) {
        if (!is_string($ability_name) || !is_array($rule) || !wppilot_is_valid_ability_name($ability_name)) {
            continue;
        }
        $rules[$ability_name] = wppilot_normalize_ability_rule($rule);
    }

    return $rules;
}

/**
 * One rule in its canonical shape. Unknown profiles are dropped, not guessed at.
 *
 * @param array<mixed> $rule
 * @return array{disabled: bool, require_confirmation: bool, min_profile: string}
 */
function wppilot_normalize_ability_rule(array $rule): array
{
    $min_profile = is_string($rule['min_profile'] ?? null) ? $rule['min_profile'] : '';

    return [
        'disabled' => in_array($rule['disabled'] ?? false, [true, '1', 1], strict: true),
        'require_confirmation' => in_array($rule['require_confirmation'] ?? false, [true, '1', 1], strict: true),
        'min_profile' => function_exists('wppilot_is_safety_profile') && wppilot_is_safety_profile($min_profile)
            ? $min_profile
            : '',
    ];
}

/**
 * Persist the per-ability hub rules.
 *
 * Only rules that change something are kept, so an ability returned to its
 * defaults leaves no row behind.
 *
 * @param array<string, array{disabled?: bool, require_confirmation?: bool, min_profile?: string}> $rules
 */
function wppilot_update_ability_rules(array $rules): void
{
    $clean = [];
    foreach ($rules as $ability_name => $rule) {
        if (!wppilot_is_valid_ability_name($ability_name)) {
            continue;
        }
        $rule = wppilot_normalize_ability_rule($rule);
        if (!$rule['disabled'] && !$rule['require_confirmation'] && $rule['min_profile'] === '') {
            continue;
        }
        $clean[$ability_name] = array_filter($rule, static fn(mixed $value): bool => $value !== false && $value !== '');
    }

    // A rule the owner changes is the owner's from then on: GoBuildWP Cloud may only lift blocks it set
    // and nobody has touched since. The Cloud's own apply skips this, see includes/cloud/policy.php.
    if (function_exists('wppilot_cloud_release_changed_rules') && empty($GLOBALS['wppilot_cloud_policy_applying'])) {
        wppilot_cloud_release_changed_rules(wppilot_get_ability_rules(), $clean);
    }

    update_option('wppilot_ability_rules', $clean, autoload: false);
}

/**
 * Set an ability's governance overrides, leaving its enable/disable state alone.
 *
 * Hub-protected abilities are left untouched: they are the discovery meta-tools,
 * and gating them would stop an agent finding anything at all.
 *
 * @param array<string, array{disabled: bool, require_confirmation?: bool, min_profile?: string}> $rules
 * @return array<string, array{disabled: bool, require_confirmation?: bool, min_profile?: string}>
 */
function wppilot_set_ability_governance_rule(
    array $rules,
    string $ability_name,
    bool $require_confirmation,
    string $min_profile,
): array {
    if (wppilot_ability_is_hub_protected($ability_name)) {
        return $rules;
    }

    $rules[$ability_name] ??= ['disabled' => false];
    $rules[$ability_name]['require_confirmation'] = $require_confirmation;
    $rules[$ability_name]['min_profile'] = function_exists('wppilot_is_safety_profile')
        && wppilot_is_safety_profile($min_profile)
        && $min_profile !== 'readonly'
            ? $min_profile
            : '';

    return $rules;
}

function wppilot_is_valid_ability_name(string $ability_name): bool
{
    return preg_match('/^[a-z0-9-]+\/[a-z0-9-\/]+$/', $ability_name) === 1;
}

/**
 * Normalize an ability name received from a request before validating it.
 *
 * PHP normally URL-decodes form fields while populating $_POST. Some proxies
 * and security plugins can re-encode a value, leaving the namespacing slash as
 * "%2F". Decode that transport encoding before sanitize_text_field() removes
 * percent-encoded octets altogether. The strict ability-name validator remains
 * the authority on whether the resulting value is accepted.
 */
function wppilot_sanitize_requested_ability_name(string $ability_name): string
{
    return sanitize_text_field(rawurldecode(wp_unslash($ability_name)));
}

/**
 * Ability names kept always-on and out of the abilities screen, alongside the mcp-adapter meta-tools:
 * the skills loader the discovery flow points agents to. Filterable so other infrastructure abilities
 * can opt in.
 *
 * @return list<string>
 */
function wppilot_always_on_ability_names(): array
{
    /** @var list<string> */
    return apply_filters('wppilot_always_on_ability_names', ['wppilot/skill-get']);
}

function wppilot_ability_is_hub_protected(string $ability_name): bool
{
    return (
        str_starts_with($ability_name, 'mcp-adapter/')
        || in_array($ability_name, wppilot_always_on_ability_names(), strict: true)
    );
}

/**
 * Whether the current request is rendering the Abilities Hub admin screen.
 *
 * The Hub manages every ability, disabled ones included, so it must see the
 * full registry; the disable policy is therefore not enforced while it renders.
 */
function wppilot_is_ability_hub_screen(): bool
{
    if (!is_admin()) {
        return false;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection: the admin page slug only decides whether the disable policy is skipped while the Hub lists abilities; nothing is changed.
    $page = is_string($_GET['page'] ?? null) ? sanitize_key(wp_unslash($_GET['page'])) : '';

    return $page === 'wppilot-abilities';
}

/**
 * Apply persisted Abilities Hub rules after all providers have registered.
 */
function wppilot_apply_ability_policy(): void
{
    if (!function_exists('wp_unregister_ability')) {
        return;
    }

    // The Hub screen lists disabled abilities with their full metadata, so do not
    // unregister them there. Enforcement still runs on REST/MCP and front-end
    // requests, which is where ability exposure actually matters.
    if (wppilot_is_ability_hub_screen()) {
        return;
    }

    $rules = wppilot_get_ability_rules();

    // Read the registry, not the filtered discovery list. Since WordPress 7.1
    // every wp_get_abilities() call runs the wp_get_abilities_item_include and
    // wp_get_abilities_result filters, so a third-party filter that hides an
    // ability from discovery would also hide it from this loop — and a
    // disabled or profile-blocked ability would then stay registered and stay
    // executable through every path that does not go through discovery.
    foreach (wppilot_registered_abilities() as $ability) {
        if (!$ability instanceof WP_Ability) {
            continue;
        }
        wppilot_apply_ability_policy_rule($ability, $rules);
    }
}

/**
 * @param array<string, array{disabled: bool, require_confirmation?: bool, min_profile?: string}> $rules
 */
function wppilot_apply_ability_policy_rule(WP_Ability $ability, array $rules): void
{
    $ability_name = $ability->get_name();
    $rule = $rules[$ability_name] ?? null;
    $manually_disabled = ($rule['disabled'] ?? false) === true;
    $profile_blocked =
        function_exists('wppilot_safety_profile_allows_ability') && !wppilot_safety_profile_allows_ability($ability);

    if (($manually_disabled || $profile_blocked) && !wppilot_ability_is_hub_protected($ability_name)) {
        wp_unregister_ability($ability_name);
    }
}

/**
 * Turn the MCP adapter's generic "ability not found" result into a clear "switched off" message
 * when the requested ability is one an admin disabled in the Abilities screen. A disabled ability is
 * unregistered, so the adapter cannot otherwise tell it apart from one that was never installed —
 * which leaves an agent following a skill that calls it with a misleading "not found".
 *
 * Filters mcp_adapter_tool_call_result (the execute-ability / get-ability-info result).
 *
 * @param mixed $result The raw tool result.
 * @param mixed $args   The tool arguments; carries ability_name for the ability meta-tools.
 * @return mixed
 */
function wppilot_enrich_disabled_ability_error(mixed $result, mixed $args): mixed
{
    if (!is_array($result) || ($result['success'] ?? null) !== false) {
        return $result;
    }

    $name = is_array($args) && is_string($args['ability_name'] ?? null) ? $args['ability_name'] : '';
    if ($name === '' || ($result['error'] ?? null) !== "Ability '{$name}' not found") {
        return $result;
    }

    $rules = wppilot_get_ability_rules();
    if (($rules[$name]['disabled'] ?? false) !== true) {
        return $result;
    }

    $result['error'] = sprintf(
        /* translators: %s: the ability name, e.g. wppilot/execute-php */
        __(
            "Ability '%s' exists but is switched off in GoBuildWP's AI Abilities settings. Ask the site admin to re-enable it there, then retry.",
            domain: 'wppilot',
        ),
        $name,
    );

    return $result;
}
