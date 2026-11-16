<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Hold new abilities: an ability this site has never seen before stays off until an admin allows it.
 *
 * Before, activating any plugin that uses the Abilities API put every ability it registers in front
 * of connected agents at once, writes included, without anyone on the site deciding that. Now the
 * first time a name appears in the registry it is recorded as held, the policy pass unregisters it
 * like a switched-off ability, and the Abilities screen shows "N new abilities from X" with Allow
 * and Keep off.
 *
 * - The first pass after this code ships records every registered name as already known, so an
 *   upgrade holds nothing that was already running.
 * - GoBuildWP's own namespace (`wppilot/`, Free, Pro and kits) and the hub-protected meta-tools are
 *   never held: a GoBuildWP update would otherwise switch off the features it ships.
 * - Only an admin decides, on a nonce-checked wp-admin form. No ability can release a hold, so an
 *   agent cannot allow itself new tools. Keep off turns the abilities into ordinary switched-off
 *   rules, which the Abilities screen can switch back on later.
 * - Settings > Hold new abilities (on by default) stops new names being held; turning it off also
 *   stops enforcing the holds that are still waiting.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_ABILITY_HOLD_OPTION = 'wppilot_ability_hold';

const WPPILOT_ABILITY_HOLD_STATE_OPTION = 'wppilot_ability_hold_state';

const WPPILOT_ABILITY_HOLD_ACTIVATED_OPTION = 'wppilot_ability_hold_activated';

const WPPILOT_ABILITY_HOLD_DECIDE_ACTION = 'wppilot_ability_hold_decide';

/** How long a plugin activation names the source of abilities that appear after it. */
const WPPILOT_ABILITY_HOLD_ACTIVATION_WINDOW = 3600;

function wppilot_ability_hold_enabled(): bool
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_ABILITY_HOLD_OPTION, default_value: '1');

    return !in_array($stored, ['0', 0, false, ''], strict: true);
}

function wppilot_ability_hold_set_enabled(bool $enabled): void
{
    update_option(WPPILOT_ABILITY_HOLD_OPTION, $enabled ? '1' : '0', autoload: false);
}

/**
 * The stored state: every name seen so far, and the ones still waiting for a decision.
 *
 * `known` is null until the first pass has recorded a baseline.
 *
 * @return array{known: ?array<string, true>, held: array<string, array{source: string, since: int}>}
 */
function wppilot_ability_hold_state(): array
{
    /** @var mixed $stored */
    $stored = get_option(WPPILOT_ABILITY_HOLD_STATE_OPTION, default_value: null);
    if (!is_array($stored) || !is_array($stored['known'] ?? null)) {
        return ['known' => null, 'held' => []];
    }

    $known = [];
    /** @var mixed $name */
    foreach ($stored['known'] as $name) {
        if (is_string($name) && wppilot_is_valid_ability_name($name)) {
            $known[$name] = true;
        }
    }
    $held = [];
    /** @var mixed $entry */
    foreach (is_array($stored['held'] ?? null) ? $stored['held'] : [] as $name => $entry) {
        if (!is_string($name) || !wppilot_is_valid_ability_name($name) || !is_array($entry)) {
            continue;
        }
        $held[$name] = [
            'source' => is_string($entry['source'] ?? null) ? $entry['source'] : '',
            'since' => (int) ($entry['since'] ?? 0),
        ];
    }

    return ['known' => $known, 'held' => $held];
}

/**
 * @param array{known: ?array<string, true>, held: array<string, array{source: string, since: int}>} $state
 */
function wppilot_ability_hold_save_state(array $state): void
{
    update_option(
        WPPILOT_ABILITY_HOLD_STATE_OPTION,
        ['known' => array_keys($state['known'] ?? []), 'held' => $state['held']],
        autoload: false,
    );
}

function wppilot_ability_hold_exempt(string $ability_name): bool
{
    return str_starts_with($ability_name, 'wppilot/') || wppilot_ability_is_hub_protected($ability_name);
}

/**
 * Record the newly seen names among the registered abilities, holding the ones that need a decision.
 *
 * Runs from the policy pass, before anything is unregistered, so it sees the full registry.
 *
 * @param iterable<mixed> $abilities
 * @return array<string, array{source: string, since: int}> The held abilities, after this pass.
 */
function wppilot_ability_hold_observe(iterable $abilities): array
{
    $state = wppilot_ability_hold_state();
    $baseline = $state['known'] === null;
    $known = $state['known'] ?? [];
    $hold = !$baseline && wppilot_ability_hold_enabled();
    $changed = $baseline;

    /** @var mixed $ability */
    foreach ($abilities as $ability) {
        if (!$ability instanceof WP_Ability) {
            continue;
        }
        $name = $ability->get_name();
        if (isset($known[$name]) || !wppilot_is_valid_ability_name($name)) {
            continue;
        }
        $known[$name] = true;
        $changed = true;
        if ($hold && !wppilot_ability_hold_exempt($name)) {
            $state['held'][$name] = ['source' => wppilot_ability_hold_source($ability), 'since' => time()];
        }
    }

    if ($changed) {
        $state['known'] = $known;
        wppilot_ability_hold_save_state($state);
    }

    return $state['held'];
}

/**
 * Who to name in "N new abilities from X": the label a provider gives its abilities, else the plugin
 * activated in the last hour, else the ability's namespace.
 */
function wppilot_ability_hold_source(WP_Ability $ability): string
{
    if (function_exists('wppilot_ability_source_label')) {
        $label = wppilot_ability_source_label($ability);
        if ($label !== '') {
            return $label;
        }
    }
    /** @var mixed $activated */
    $activated = get_option(WPPILOT_ABILITY_HOLD_ACTIVATED_OPTION, default_value: null);
    if (
        is_array($activated)
        && is_string($activated['name'] ?? null)
        && $activated['name'] !== ''
        && time() - (int) ($activated['at'] ?? 0) <= WPPILOT_ABILITY_HOLD_ACTIVATION_WINDOW
    ) {
        return $activated['name'];
    }

    return explode('/', $ability->get_name(), limit: 2)[0];
}

/**
 * activated_plugin: remember which plugin was just switched on, to name it beside what it adds.
 */
function wppilot_ability_hold_note_activation(mixed $plugin): void
{
    if (!is_string($plugin) || $plugin === '') {
        return;
    }
    $name = '';
    $file = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/' . $plugin : '';
    if ($file !== '' && function_exists('get_plugin_data') && is_readable($file)) {
        $data = get_plugin_data($file, markup: false, translate: false);
        $name = is_string($data['Name'] ?? null) ? $data['Name'] : '';
    }
    if ($name === '') {
        $name = dirname($plugin) !== '.' ? dirname($plugin) : basename($plugin, '.php');
    }
    update_option(
        WPPILOT_ABILITY_HOLD_ACTIVATED_OPTION,
        ['name' => sanitize_text_field($name), 'at' => time()],
        autoload: false,
    );
}

/**
 * The held abilities this request must refuse: none while the setting is off.
 *
 * @return array<string, array{source: string, since: int}>
 */
function wppilot_ability_hold_enforced(): array
{
    return wppilot_ability_hold_enabled() ? wppilot_ability_hold_state()['held'] : [];
}

function wppilot_ability_is_held(string $ability_name): bool
{
    return isset(wppilot_ability_hold_enforced()[$ability_name]);
}

/**
 * Held abilities grouped by source, for the Abilities screen.
 *
 * @return array<string, list<string>>
 */
function wppilot_ability_hold_groups(): array
{
    $groups = [];
    foreach (wppilot_ability_hold_enforced() as $name => $entry) {
        $source = $entry['source'] !== '' ? $entry['source'] : explode('/', $name, limit: 2)[0];
        $groups[$source][] = $name;
    }
    ksort($groups);

    return $groups;
}

/**
 * Release or keep off every held ability from one source.
 *
 * @param 'allow'|'keep_off' $decision
 * @return list<string> The abilities decided.
 */
function wppilot_ability_hold_decide(string $source, string $decision, int $decided_by): array
{
    if (!in_array($decision, ['allow', 'keep_off'], strict: true)) {
        return [];
    }
    $state = wppilot_ability_hold_state();
    $names = [];
    foreach ($state['held'] as $name => $entry) {
        $entry_source = $entry['source'] !== '' ? $entry['source'] : explode('/', $name, limit: 2)[0];
        if ($entry_source === $source) {
            $names[] = $name;
        }
    }
    if ($names === []) {
        return [];
    }

    if ($decision === 'keep_off') {
        $rules = wppilot_get_ability_rules();
        foreach ($names as $name) {
            $rules[$name] = ['disabled' => true] + ($rules[$name] ?? []);
        }
        wppilot_update_ability_rules($rules);
    }
    foreach ($names as $name) {
        unset($state['held'][$name]);
    }
    wppilot_ability_hold_save_state($state);

    if (function_exists('wppilot_ledger_record_items')) {
        wppilot_ledger_record_items('wppilot/ability-hold', [[
            'input' => ['source' => $source, 'decision' => $decision, 'decided_by' => $decided_by],
            'before' => null,
            'result' => ['abilities' => $names, 'decision' => $decision],
            'irreversible_reason' => 'An administrator decides held abilities on the Abilities screen; the decision is not undone from the ledger.',
        ]]);
    }

    return $names;
}

/**
 * admin_post: Allow or Keep off, from the Abilities screen banner.
 */
function wppilot_ability_hold_handle_decide(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to manage GoBuildWP abilities.', domain: 'wppilot'), '', ['response' => 403]);
    }
    check_admin_referer(WPPILOT_ABILITY_HOLD_DECIDE_ACTION);

    $source = is_string($_POST['source'] ?? null) ? sanitize_text_field(wp_unslash($_POST['source'])) : '';
    $decision = is_string($_POST['decision'] ?? null) ? sanitize_key(wp_unslash($_POST['decision'])) : '';
    $decided = wppilot_ability_hold_decide($source, $decision, get_current_user_id());

    $result = $decided === [] ? 'hold_none' : ($decision === 'allow' ? 'hold_allowed' : 'hold_kept_off');
    wp_safe_redirect(admin_url('admin.php?page=wppilot-abilities&wppilot_result=' . $result));
    exit();
}

/**
 * The "N new abilities from X — allow?" banner at the top of the Abilities screen.
 */
function wppilot_ability_hold_render_banner(): void
{
    $groups = wppilot_ability_hold_groups();
    if ($groups === []) {
        return;
    }
    foreach ($groups as $source => $names) { ?>
        <div class="notice notice-warning inline wppilot-ability-hold">
            <p><strong><?php echo esc_html(sprintf(
                /* translators: 1: number of abilities, 2: plugin or provider name */
                _n('%1$d new ability from %2$s is held. Allow it?', '%1$d new abilities from %2$s are held. Allow them?', count($names), domain: 'wppilot'),
                count($names),
                $source,
            )); ?></strong></p>
            <p><?php esc_html_e(
                'Held abilities are switched off until you decide: agents cannot see or call them, and no agent can release them.',
                domain: 'wppilot',
            ); ?></p>
            <p><code><?php echo esc_html(implode(', ', $names)); ?></code></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(WPPILOT_ABILITY_HOLD_DECIDE_ACTION); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(WPPILOT_ABILITY_HOLD_DECIDE_ACTION); ?>">
                <input type="hidden" name="source" value="<?php echo esc_attr($source); ?>">
                <button type="submit" name="decision" value="allow" class="button button-primary"><?php esc_html_e('Allow', domain: 'wppilot'); ?></button>
                <button type="submit" name="decision" value="keep_off" class="button"><?php esc_html_e('Keep off', domain: 'wppilot'); ?></button>
            </form>
        </div>
    <?php }
}

/**
 * Settings > Hold new abilities.
 */
function wppilot_ability_hold_register_setting(mixed $sections): mixed
{
    if (!is_array($sections)) {
        return $sections;
    }
    $sections[] = [
        'id' => 'wppilot-ability-hold',
        'title' => __('New abilities', domain: 'wppilot'),
        'description' => __('What happens when a plugin adds abilities this site has not seen before.', domain: 'wppilot'),
        'fields' => [
            [
                'type' => 'toggle',
                'name' => WPPILOT_ABILITY_HOLD_OPTION,
                'label' => __('Hold new abilities', domain: 'wppilot'),
                'help' => __(
                    'On: abilities a newly activated plugin adds stay switched off until you allow them on the Abilities screen. GoBuildWP\'s own abilities are never held. Off: new abilities are available at once, and abilities still waiting are released.',
                    domain: 'wppilot',
                ),
                'value' => wppilot_ability_hold_enabled(),
            ],
        ],
        'save' => 'wppilot_ability_hold_save_setting',
    ];

    return $sections;
}

/**
 * @param array<string, mixed> $post
 */
function wppilot_ability_hold_save_setting(array $post): void
{
    // An unchecked checkbox is absent from the POST entirely.
    wppilot_ability_hold_set_enabled(($post[WPPILOT_ABILITY_HOLD_OPTION] ?? null) !== null);
}

function wppilot_ability_hold_register_hooks(): void
{
    add_action('activated_plugin', 'wppilot_ability_hold_note_activation', 10, 1);
    add_action('admin_post_' . WPPILOT_ABILITY_HOLD_DECIDE_ACTION, 'wppilot_ability_hold_handle_decide');
    add_filter('wppilot_settings_sections', 'wppilot_ability_hold_register_setting');
}
