<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_SAFETY_PROFILE_OPTION = 'wppilot_safety_profile';

const WPPILOT_SAFETY_POLICY_VERSION_OPTION = 'wppilot_safety_policy_version';

const WPPILOT_SAFETY_POLICY_VERSION = 1;

/**
 * The valid safety profile ids.
 *
 * Kept separate from wppilot_safety_profiles() because validation runs long
 * before the display labels are needed — wppilot_safety_maybe_install() fires on
 * plugins_loaded, and wppilot_get_safety_profile() runs on nearly every request.
 * Calling __() there would load the text domain before `init` and make
 * WordPress emit a _load_textdomain_just_in_time notice on every page.
 *
 * @return list<string>
 */
function wppilot_safety_profile_ids(): array
{
    return ['production', 'readonly', 'developer'];
}

/**
 * Whether a value names a safety profile. Translation-free: safe to call early.
 */
function wppilot_is_safety_profile(mixed $profile): bool
{
    return is_string($profile) && in_array($profile, wppilot_safety_profile_ids(), strict: true);
}

/**
 * Safety profiles are enforced at registry, MCP-dispatch, and REST-run boundaries.
 *
 * The labels and descriptions here are for display only. Anything that merely
 * validates a stored value must use wppilot_is_safety_profile() instead, so it
 * does not pull in translations before `init`.
 *
 * @param bool|null $directory_edition Defaults to the running edition.
 * @return array<string, array{label: string, description: string}>
 */
function wppilot_safety_profiles(?bool $directory_edition = null): array
{
    $directory_edition ??= wppilot_is_wordpress_org_edition();

    return [
        'production' => [
            'label' => __('Production Safe', domain: 'wppilot'),
            'description' => $directory_edition
                ? __(
                    'Allows normal content, design, SEO, form, and commerce work while blocking plugin and theme installation and deletion, user account changes, and similarly critical operations.',
                    domain: 'wppilot',
                )
                : __(
                    'Allows normal content, design, SEO, form, and commerce work while blocking raw code execution, server filesystem access, WP-CLI, temporary administrator access, and similarly critical operations.',
                    domain: 'wppilot',
                ),
        ],
        'readonly' => [
            'label' => __('Read Only', domain: 'wppilot'),
            'description' => __(
                'Allows discovery and inspection abilities only. Every ability that can change site state is blocked.',
                domain: 'wppilot',
            ),
        ],
        'developer' => [
            'label' => __('Developer Full Access', domain: 'wppilot'),
            // The WordPress.org edition ships no PHP execution, WP-CLI, server file or
            // temporary-login abilities, so it describes what this profile adds there.
            'description' => $directory_edition
                ? __(
                    'Allows every enabled ability, including installing and deleting plugins and themes from WordPress.org, creating and editing user accounts, and reading raw rows of the options table. Critical calls still require an explicit confirmation flag.',
                    domain: 'wppilot',
                )
                : __(
                    'Allows every enabled ability, including PHP execution, WP-CLI, filesystem operations, and administrator access. Critical calls still require an explicit confirmation flag.',
                    domain: 'wppilot',
                ),
        ],
    ];
}

function wppilot_get_safety_profile(): string
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_SAFETY_PROFILE_OPTION, default_value: 'production');
    $profile = is_string($stored) ? sanitize_key($stored) : 'production';

    return wppilot_is_safety_profile($profile) ? $profile : 'production';
}

/**
 * The profile that governs this request: the site's, lowered by the access token's ceiling.
 *
 * Only ever stricter than the site profile. A token capped at Read Only on a
 * Production Safe site reads, and a token capped at Production Safe on a Read Only
 * site still only reads. Requests that no access token authenticated (wp-admin,
 * Chat, application passwords, OAuth, WP-CLI) get the site profile unchanged.
 *
 * Display code keeps calling wppilot_get_safety_profile(): the setting the site
 * owner chose is what the screens show; this is what enforcement asks.
 */
function wppilot_effective_safety_profile(): string
{
    $site = wppilot_get_safety_profile();
    $ceiling = function_exists('wppilot_agent_profile_ceiling') ? wppilot_agent_profile_ceiling() : '';

    return $ceiling !== '' && wppilot_safety_profile_rank($ceiling) < wppilot_safety_profile_rank($site)
        ? $ceiling
        : $site;
}

function wppilot_update_safety_profile(string $profile): bool
{
    $profile = sanitize_key($profile);
    if (!wppilot_is_safety_profile($profile)) {
        return false;
    }

    update_option(WPPILOT_SAFETY_PROFILE_OPTION, $profile, autoload: true);
    return true;
}

/**
 * Install or migrate the safety policy without silently granting an existing site more access.
 */
function wppilot_safety_maybe_install(): void
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_SAFETY_PROFILE_OPTION, default_value: null);
    if (!wppilot_is_safety_profile($stored)) {
        // @mago-expect analysis:mixed-assignment -- Legacy option values are compared strictly below.
        $legacy_enabled = get_option('wppilot_ai_abilities_enabled', default_value: false);
        $profile = ($legacy_enabled === '1' || $legacy_enabled === true) && !wppilot_looks_like_production()
            ? 'developer'
            : 'production';
        update_option(WPPILOT_SAFETY_PROFILE_OPTION, $profile, autoload: true);
    }

    update_option(WPPILOT_SAFETY_POLICY_VERSION_OPTION, (string) WPPILOT_SAFETY_POLICY_VERSION, autoload: false);
}

function wppilot_ability_is_readonly(WP_Ability $ability): bool
{
    $meta = $ability->get_meta();
    $mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];
    $type = is_string($mcp['type'] ?? null) ? $mcp['type'] : 'tool';
    if ($type === 'resource' || $type === 'prompt') {
        return true;
    }

    $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];
    return ($annotations['readonly'] ?? null) === true;
}

/**
 * Return a stable risk class used by profiles, confirmations, audit logs, and the UI.
 *
 * @return 'read'|'write'|'destructive'|'critical'
 */
function wppilot_ability_risk(WP_Ability $ability): string
{
    if (wppilot_ability_is_readonly($ability)) {
        return 'read';
    }

    $name = strtolower($ability->get_name());
    $category = strtolower($ability->get_category());
    if (
        in_array(
            $category,
            [
                'code-execution',
                'code-snippets',
                'dynamic-shortcodes',
                'filesystem',
                'admin-access',
                'database',
                'wp-cli',
            ],
            strict: true,
        )
        || wppilot_ability_name_is_critical($name)
    ) {
        return 'critical';
    }

    $meta = $ability->get_meta();
    $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];
    if (($annotations['destructive'] ?? null) === true || wppilot_ability_name_is_destructive($name)) {
        return 'destructive';
    }

    return 'write';
}

function wppilot_ability_name_is_critical(string $name): bool
{
    $critical = [
        'execute-php',
        'run-wp-cli',
        'write-file',
        'edit-file',
        'delete-file',
        'read-file',
        'list-directory',
        'create-upload-link',
        'create-admin-access-link',
        'enable-file',
        'disable-file',
        'install-plugin',
        'delete-plugin',
        'install-theme',
        'delete-theme',
        'database-query',
        'insert-row',
        'update-rows',
        'delete-rows',
        'php-snippet',
        'code-snippets',
        'dynamic-shortcodes',
        'create-user',
        'update-user',
        'delete-user',
    ];

    foreach ($critical as $fragment) {
        if (str_contains($name, $fragment)) {
            return true;
        }
    }

    return false;
}

/**
 * Names that read as ordinary writes but are not.
 *
 * The extension-lifecycle fragments are listed individually rather than as a
 * bare `update-` or `activate-` prefix: those would sweep in update-post,
 * update-media, update-term, activate-design and every other routine content
 * write, and confirmation-gating those would make normal editing unusable.
 * `activate-plugin` covers `deactivate-plugin` as a substring.
 */
function wppilot_ability_name_is_destructive(string $name): bool
{
    foreach ([
        'delete-',
        'remove-',
        'refund',
        'cancel-subscription',
        'reset-',
        'restore-',
        'apply-site',
        'uninstall',
        // Extension lifecycle: no code is fetched or written, so these stay out
        // of the critical list and remain usable on Production Safe — but each
        // one can take a live site down, so none of them runs unconfirmed.
        'activate-plugin',
        'update-plugin',
        'update-theme',
        'switch-theme',
    ] as $fragment) {
        if (str_contains($name, $fragment)) {
            return true;
        }
    }

    return false;
}

/**
 * The ability's own safety policy, `meta.safety`.
 *
 * Neutral on purpose: the key is not GoBuildWP-branded, so a portable kit exported into another
 * plugin keeps the same policy without the exporter having to rewrite it.
 *
 * - `min_profile`: the least permissive profile the ability may run under. A read that is still
 *   too sensitive for Production Safe (a raw SQL SELECT) declares `developer`. Its risk stays
 *   `read`, so it is never confirmation-gated; it is simply absent below that profile.
 * - `audit_reads`: record each call in the change log even though it changes nothing.
 *
 * @return array{min_profile: string, audit_reads: bool}
 */
function wppilot_ability_safety_policy(WP_Ability $ability): array
{
    $meta = $ability->get_meta();
    $safety = is_array($meta['safety'] ?? null) ? $meta['safety'] : [];
    $min_profile = $safety['min_profile'] ?? '';
    $min_profile = is_string($min_profile) && wppilot_is_safety_profile($min_profile) ? $min_profile : '';

    // A site owner's Abilities Hub override can only raise the floor. The ability's own
    // min_profile is its author saying "too sensitive below this", and nothing on a settings
    // screen should be able to quietly lower that.
    $rules = function_exists('wppilot_get_ability_rules') ? wppilot_get_ability_rules() : [];
    $override = (string) ($rules[$ability->get_name()]['min_profile'] ?? '');
    if (
        $override !== ''
        && wppilot_is_safety_profile($override)
        && ($min_profile === '' || wppilot_safety_profile_rank($override) > wppilot_safety_profile_rank($min_profile))
    ) {
        $min_profile = $override;
    }

    return [
        'min_profile' => $min_profile,
        'audit_reads' => ($safety['audit_reads'] ?? false) === true,
    ];
}

/**
 * Order the profiles from least to most permissive, so `min_profile` can be compared.
 */
function wppilot_safety_profile_rank(string $profile): int
{
    return match ($profile) {
        'readonly' => 0,
        'developer' => 2,
        default => 1,
    };
}

function wppilot_safety_profile_allows_ability(WP_Ability $ability): bool
{
    if (wppilot_ability_is_hub_protected($ability->get_name())) {
        return true;
    }

    // An access token's scope is part of the same answer, so discovery (tools/list, the
    // adapter's discover meta-tool, the registry policy) never advertises what the token's
    // next call would be refused.
    if (function_exists('wppilot_agent_scope_error') && wppilot_agent_scope_error($ability) !== null) {
        return false;
    }

    $profile = wppilot_effective_safety_profile();

    // Checked before the risk class, because a read-only ability otherwise always passes: the
    // `readonly` annotation short-circuits wppilot_ability_risk() to `read` before it ever looks
    // at a critical category, which left no way to keep a sensitive read off Production Safe.
    $min_profile = wppilot_ability_safety_policy($ability)['min_profile'];
    if ($min_profile !== '' && wppilot_safety_profile_rank($profile) < wppilot_safety_profile_rank($min_profile)) {
        return false;
    }

    return match ($profile) {
        'developer' => true,
        'readonly' => wppilot_ability_is_readonly($ability),
        default => wppilot_ability_risk($ability) !== 'critical',
    };
}

/**
 * Refuse an ability the safety profile or the Abilities Hub does not allow, whoever is asking.
 *
 * Until WordPress 7.1 the profile could only be enforced where GoBuildWP owned the call path: its
 * own MCP transports check it before dispatch, and the ability policy unregisters what the
 * profile refuses so nothing else can reach it either. Unregistration is a blunt instrument
 * with one real gap — the Abilities Hub screen has to see disabled abilities to list them, so
 * the policy stands down there, and a request to that screen that also executes an ability runs
 * it under no profile at all. It also means a site's Production Safe setting silently stops
 * applying to a caller that arrives before `wp_abilities_api_init` has finished.
 *
 * `wp_ability_permission_result` closes both. It runs inside `WP_Ability::execute()` and during
 * the standalone permission checks the REST API and WP-CLI perform, so core REST, WP-CLI, the AI
 * Client, another MCP adapter and GoBuildWP's own Chat all get the same answer.
 *
 * This filter only ever denies. A profile is a ceiling, not a grant: when something upstream has
 * already refused the call, that refusal is what gets returned.
 *
 * @param bool|WP_Error $permission
 */
function wppilot_safety_filter_ability_permission(
    mixed $permission,
    mixed $ability_name,
    mixed $input,
    mixed $ability,
): mixed {
    if ($permission === false || is_wp_error($permission)) {
        return $permission;
    }
    if (!$ability instanceof WP_Ability || !is_string($ability_name)) {
        return $permission;
    }

    if (wppilot_ability_is_hub_protected($ability_name)) {
        return $permission;
    }

    $rules = function_exists('wppilot_get_ability_rules') ? wppilot_get_ability_rules() : [];
    if (($rules[$ability_name]['disabled'] ?? false) === true) {
        return new WP_Error(
            'wppilot_ability_disabled',
            sprintf(
                /* translators: %s: ability name */
                __('The ability "%s" is switched off in the GoBuildWP Abilities screen.', domain: 'wppilot'),
                $ability_name,
            ),
            ['status' => 403],
        );
    }

    $scope_error = function_exists('wppilot_agent_scope_error') ? wppilot_agent_scope_error($ability) : null;
    if ($scope_error !== null) {
        return $scope_error;
    }

    if (!wppilot_safety_profile_allows_ability($ability)) {
        return new WP_Error(
            'wppilot_safety_profile_blocked',
            sprintf(
                /* translators: 1: ability name, 2: active safety profile */
                __('The ability "%1$s" is not allowed by the active GoBuildWP safety profile (%2$s).', domain: 'wppilot'),
                $ability_name,
                wppilot_effective_safety_profile(),
            ),
            ['status' => 403],
        );
    }

    return $permission;
}

function wppilot_ability_requires_confirmation(WP_Ability $ability): bool
{
    $risk = wppilot_ability_risk($ability);
    if ($risk === 'critical' || $risk === 'destructive') {
        return true;
    }

    // The Abilities Hub lets a site owner demand confirmation for any ability, including a
    // third-party one whose author annotated a risky write as ordinary. It can only add the
    // requirement: a destructive ability stays confirmed whatever the rule says.
    $rules = function_exists('wppilot_get_ability_rules') ? wppilot_get_ability_rules() : [];

    return ($rules[$ability->get_name()]['require_confirmation'] ?? false) === true;
}

/**
 * Enforce the safety profile and explicit confirmation on MCP ability dispatch.
 *
 * The MCP Adapter's public surface is the execute-ability meta-tool. A `confirm:true`
 * member may be included in its `parameters` object. It is removed before the target
 * ability validates input unless that ability declares its own confirm property.
 *
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function wppilot_safety_pre_mcp_tool_call(array $args, string $tool_name): array|WP_Error
{
    if ($tool_name !== 'mcp-adapter-execute-ability') {
        return $args;
    }

    $ability_name = is_string($args['ability_name'] ?? null) ? $args['ability_name'] : '';
    $ability = $ability_name !== '' && function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null;
    if (!$ability instanceof WP_Ability) {
        return $args;
    }

    $allowed = wppilot_safety_check_ability($ability);
    if ($allowed instanceof WP_Error) {
        return $allowed;
    }

    $parameters = is_array($args['parameters'] ?? null) ? $args['parameters'] : [];
    // The legacy era has no per-request capabilities and no input_required, so in `human` mode a
    // legacy client is always given the wp-admin approval link.
    $confirmed = wppilot_confirm_ability_call($ability, $parameters, transport: 'mcp');
    if ($confirmed instanceof WP_Error) {
        return $confirmed;
    }
    wppilot_confirmation_note($ability->get_name(), $confirmed);

    if (
        array_key_exists(key: 'confirm', array: $parameters)
        && !wppilot_ability_schema_has_property($ability, property: 'confirm')
    ) {
        unset($parameters['confirm']);
        $args['parameters'] = $parameters;
    }
    // The legacy adapter executes next; the gate for other MCP servers must not hold it again.
    wppilot_gate_mark_passed($ability->get_name());

    return $args;
}

function wppilot_safety_check_ability(WP_Ability $ability): bool|WP_Error
{
    // Scope first, so a token that may not call this at all is told that, rather than that
    // the profile blocks it, which would send its owner to the wrong setting.
    $scope_error = function_exists('wppilot_agent_scope_error') ? wppilot_agent_scope_error($ability) : null;
    if ($scope_error !== null) {
        return $scope_error;
    }

    if (wppilot_safety_profile_allows_ability($ability)) {
        return true;
    }

    $profile = wppilot_effective_safety_profile();
    $profiles = wppilot_safety_profiles();
    if ($profile !== wppilot_get_safety_profile()) {
        return new WP_Error(
            'wppilot_safety_profile_blocked',
            sprintf(
                /* translators: 1: ability name, 2: safety profile label */
                __(
                    'Ability "%1$s" is blocked: the access token behind this request is capped at the %2$s safety profile. The site owner sets that cap on the token; do not retry.',
                    domain: 'wppilot',
                ),
                $ability->get_name(),
                $profiles[$profile]['label'],
            ),
            ['status' => 403, 'ability' => $ability->get_name(), 'profile' => $profile, 'ceiling' => true],
        );
    }

    return new WP_Error(
        'wppilot_safety_profile_blocked',
        sprintf(
            /* translators: 1: ability name, 2: safety profile label */
            __(
                'Ability "%1$s" is blocked by the %2$s safety profile. Change the profile in GoBuildWP Configuration only if this access is intentional.',
                domain: 'wppilot',
            ),
            $ability->get_name(),
            $profiles[$profile]['label'],
        ),
        ['status' => 403, 'ability' => $ability->get_name(), 'profile' => $profile],
    );
}

function wppilot_confirmation_required_error(WP_Ability $ability): WP_Error
{
    // When the reason is the site owner's rule rather than the ability's risk, say so: calling an
    // alt-text update "destructive or critical" sends the agent and the person looking for a
    // danger that is not there.
    $risk = wppilot_ability_risk($ability);
    $by_rule = $risk !== 'critical' && $risk !== 'destructive';
    return new WP_Error(
        'wppilot_confirmation_required',
        sprintf(
            $by_rule
                /* translators: %s: ability name */
                ? __(
                    'The site owner requires confirmation for ability "%s". Obtain explicit user approval, then retry with confirm=true inside the ability parameters.',
                    domain: 'wppilot',
                )
                /* translators: %s: ability name */
                : __(
                    'Ability "%s" is destructive or critical. Obtain explicit user approval, then retry with confirm=true inside the ability parameters.',
                    domain: 'wppilot',
                ),
            $ability->get_name(),
        ),
        ['status' => 409, 'ability' => $ability->get_name(), 'risk' => wppilot_ability_risk($ability)],
    );
}

function wppilot_ability_schema_has_property(WP_Ability $ability, string $property): bool
{
    $schema = $ability->get_input_schema();
    $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
    return array_key_exists($property, $properties);
}
