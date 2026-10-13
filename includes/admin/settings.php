<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every state-changing request on this screen verifies a nonce via check_admin_referer() before acting; the sniff cannot trace that across function boundaries. Reads are type-checked, whitelist-compared, and escaped on output.

/**
 * The Settings screen: one home for every site-wide GoBuildWP switch.
 *
 * These switches used to live on whichever screen happened to use them — the
 * abilities toggle on Configuration, user context on Context, memory on Pro's
 * own page — so there was no single place to see or change how GoBuildWP behaves.
 *
 * Sections are collected through the `wppilot_settings_sections` filter and
 * each one carries its own save callback, so a section's owner keeps ownership
 * of its option. That is how Pro contributes Memory here without the free
 * plugin knowing anything about it.
 *
 * Per-item screens (Abilities Hub, Skills, Design) are deliberately not folded
 * in: those are lists of things, not settings, and they belong on their own
 * screens.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_SETTINGS_PAGE = 'wppilot-settings';

const WPPILOT_SETTINGS_NONCE = 'wppilot_save_settings';

const WPPILOT_SETTINGS_PAGESPEED_KEY_FIELD = 'wppilot_pagespeed_api_key';

/**
 * Every settings section, in display order.
 *
 * Shape per section: id, title, description, fields, and a save callback.
 * Typed loosely because the list passes through a filter and may carry sections
 * this file has never seen.
 *
 * @return list<array<string, mixed>>
 */
function wppilot_settings_sections(): array
{
    $sections = [
        [
            'id' => 'agent-access',
            'title' => __('Agent access', domain: 'wppilot'),
            'description' => __(
                'Whether connected AI agents can act on this site at all, and how much they are allowed to do when they can.',
                domain: 'wppilot',
            ),
            'fields' => [
                [
                    'type' => 'toggle',
                    'name' => 'wppilot_ai_abilities_enabled',
                    'label' => __('AI abilities', domain: 'wppilot'),
                    'help' => __(
                        'Off means no MCP endpoint and no ability is exposed, whatever else is configured here.',
                        domain: 'wppilot',
                    ),
                    'value' => wppilot_is_enabled(),
                    'state' => 'armed',
                ],
                [
                    'type' => 'select',
                    'name' => 'wppilot_safety_profile',
                    'label' => __('Safety profile', domain: 'wppilot'),
                    'help' => __(
                        'Enforced on the server for MCP and REST alike. Critical and destructive calls always require explicit confirmation on top of this.',
                        domain: 'wppilot',
                    ),
                    'value' => wppilot_get_safety_profile(),
                    'options' => wppilot_settings_profile_options(),
                ],
            ],
            'save' => 'wppilot_settings_save_agent_access',
        ],
        [
            'id' => 'context',
            'title' => __('Context', domain: 'wppilot'),
            'description' => __(
                'Site-specific instructions prepended to what every connected agent receives. Write the text itself on the Context screen.',
                domain: 'wppilot',
            ),
            'fields' => [
                [
                    'type' => 'toggle',
                    'name' => 'wppilot_instructions_enabled',
                    'label' => __('Send user context to agents', domain: 'wppilot'),
                    'help' => __('When off, the context you wrote is kept but not sent.', domain: 'wppilot'),
                    'value' => \WPPilot\Context\instructions_is_enabled(),
                ],
            ],
            'save' => 'wppilot_settings_save_context',
        ],
    ];

    // The pagespeed kit's optional key. Only shown while the kit is loaded, since it owns the option.
    if (defined('WPPilot\\Kits\\Pagespeed\\API_KEY_OPTION')) {
        $sections[] = [
            'id' => 'pagespeed',
            'title' => __('PageSpeed', domain: 'wppilot'),
            'description' => __(
                'PageSpeed checks need no Google key: Site Kit by Google answers them when it can, and GoBuildWP Cloud otherwise, paired or not, with GoBuildWP\'s own key. You do not need to add one.',
                domain: 'wppilot',
            ),
            'fields' => [
                [
                    'type' => 'secret',
                    'name' => WPPILOT_SETTINGS_PAGESPEED_KEY_FIELD,
                    'label' => __('PageSpeed API key (optional)', domain: 'wppilot'),
                    'help' => __(
                        'Optional and not needed: leave it blank and GoBuildWP Cloud runs your checks. Only for power users who run many checks and reach the shared quota. In Google Cloud console, create an API key (APIs & Services > Credentials > Create credentials > API key) and enable the PageSpeed Insights API for its project. The key is stored on this site, used only for PageSpeed requests to Google, and never shown again or sent to agents.',
                        domain: 'wppilot',
                    ),
                    'link' => ['url' => 'https://console.cloud.google.com/apis/credentials', 'text' => __('Open Google Cloud credentials', domain: 'wppilot')],
                    'value' => wppilot_settings_pagespeed_key() !== '',
                ],
            ],
            'save' => 'wppilot_settings_save_pagespeed',
        ];
    }

    /**
     * Filter the GoBuildWP settings sections.
     *
     * Add a section to expose a site-wide switch on the Settings screen. Keep
     * the save callback with whichever code owns the option.
     *
     * @param list<array<string, mixed>> $sections
     */
    /** @var mixed $filtered */
    $filtered = apply_filters('wppilot_settings_sections', $sections);
    if (!is_array($filtered)) {
        return $sections;
    }

    // A filter can return anything. Keep only entries that are actually
    // sections, so one malformed contribution cannot break the whole screen.
    $clean = [];
    /** @var mixed $section */
    foreach ($filtered as $section) {
        if (!is_array($section)) {
            continue;
        }
        /** @var array<string, mixed> $normalized */
        $normalized = $section;
        $clean[] = $normalized;
    }

    return $clean;
}

/**
 * Safety profile choices, labelled for display.
 *
 * @return array<string, string>
 */
function wppilot_settings_profile_options(): array
{
    $options = [];
    foreach (wppilot_safety_profiles() as $id => $profile) {
        $options[(string) $id] = (string) ($profile['label'] ?? $id);
    }

    return $options;
}

/**
 * @param array<string, mixed> $post
 */
function wppilot_settings_save_agent_access(array $post): void
{
    $profile = is_string($post['wppilot_safety_profile'] ?? null)
        ? sanitize_key((string) $post['wppilot_safety_profile'])
        : '';
    if ($profile !== '') {
        wppilot_update_safety_profile($profile);
    }

    // An unchecked checkbox is absent from the POST entirely.
    if (($post['wppilot_ai_abilities_enabled'] ?? null) !== null) {
        wppilot_enable_ai_abilities();
        return;
    }

    wppilot_disable_ai_abilities();
}

/**
 * @param array<string, mixed> $post
 */
function wppilot_settings_save_context(array $post): void
{
    update_option(
        \WPPilot\Context\INSTRUCTIONS_ENABLED_OPTION,
        ($post['wppilot_instructions_enabled'] ?? null) !== null ? '1' : '0',
        autoload: true,
    );
}

function wppilot_settings_pagespeed_option(): string
{
    return (string) constant('WPPilot\\Kits\\Pagespeed\\API_KEY_OPTION');
}

function wppilot_settings_pagespeed_key(): string
{
    /** @var mixed $stored */
    $stored = get_option(wppilot_settings_pagespeed_option(), default_value: '');

    return is_string($stored) ? $stored : '';
}

/**
 * A blank field keeps the saved key, so the form never has to echo it back; the checkbox removes it.
 *
 * @param array<string, mixed> $post
 */
function wppilot_settings_save_pagespeed(array $post): void
{
    if (($post[WPPILOT_SETTINGS_PAGESPEED_KEY_FIELD . '_clear'] ?? null) !== null) {
        delete_option(wppilot_settings_pagespeed_option());
        return;
    }
    $raw = $post[WPPILOT_SETTINGS_PAGESPEED_KEY_FIELD] ?? '';
    // Google API keys are letters, digits, "-" and "_"; anything else is a paste accident.
    $key = is_string($raw) ? (string) preg_replace('/[^A-Za-z0-9_\-]/', '', $raw) : '';
    if ($key !== '') {
        update_option(wppilot_settings_pagespeed_option(), substr($key, offset: 0, length: 200), autoload: false);
    }
}

/**
 * Persist the whole form, then redirect so a refresh cannot resubmit it.
 */
function wppilot_handle_settings_save(): void
{
    if (($_POST['wppilot_settings_submit'] ?? null) === null) {
        return;
    }
    if (!wppilot_current_user_can_manage()) {
        return;
    }

    check_admin_referer(WPPILOT_SETTINGS_NONCE);

    /** @var array<string, mixed> $post */
    $post = wp_unslash($_POST);

    foreach (wppilot_settings_sections() as $section) {
        $save = $section['save'] ?? null;
        if (is_callable($save)) {
            $save($post);
        }
    }

    wp_safe_redirect(add_query_arg(['updated' => '1'], admin_url('admin.php?page=' . WPPILOT_SETTINGS_PAGE)));
    exit();
}

function wppilot_render_settings_screen(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to manage GoBuildWP settings.', domain: 'wppilot'));
    }

    wppilot_render_admin_header();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(wppilot_nav_label('wppilot-settings')); ?></h1>
        <p class="wppilot-lede"><?php esc_html_e(
            'Every site-wide GoBuildWP switch, in one place. Per-ability and per-skill controls stay on their own screens.',
            domain: 'wppilot',
        ); ?></p>

        <?php if (($_GET['updated'] ?? null) === '1') { ?>
            <div class="notice notice-success"><p><?php

            esc_html_e('Settings saved.', domain: 'wppilot'); ?></p></div>
        <?php } ?>

        <form method="post" action="">
            <?php wp_nonce_field(WPPILOT_SETTINGS_NONCE); ?>
            <?php foreach (wppilot_settings_sections() as $section) {
                wppilot_render_settings_section($section);
            } ?>
            <p>
                <button type="submit" name="wppilot_settings_submit" value="1" class="button button-primary">
                    <?php esc_html_e('Save settings', domain: 'wppilot'); ?>
                </button>
            </p>
        </form>
    </div>
    <?php
}

/**
 * @param array<array-key, mixed> $section
 */
function wppilot_render_settings_section(array $section): void
{
    $fields = is_array($section['fields'] ?? null) ? $section['fields'] : [];
    if ($fields === []) {
        return;
    }
    ?>
    <section class="wppilot-panel">
        <h2 class="wppilot-setting-group__title"><?php echo esc_html((string) ($section['title'] ?? '')); ?></h2>
        <?php if (($section['description'] ?? '') !== '') { ?>
            <p class="wppilot-setting-group__note"><?php

            echo esc_html((string) $section['description']); ?></p>
        <?php } ?>
        <?php foreach ($fields as $field) {
            wppilot_render_settings_field(is_array($field) ? $field : []);
        } ?>
    </section>
    <?php
}

/**
 * @param array<array-key, mixed> $field
 */
function wppilot_render_settings_field(array $field): void
{
    $name = (string) ($field['name'] ?? '');
    if ($name === '') {
        return;
    }

    $type = (string) ($field['type'] ?? 'toggle');
    $label = (string) ($field['label'] ?? $name);
    $help = (string) ($field['help'] ?? '');
    $id = 'wppilot-field-' . sanitize_key($name);
    ?>
    <div class="wppilot-setting">
        <div class="wppilot-setting__text">
            <label class="wppilot-setting__label" for="<?php echo esc_attr($id); ?>"><?php

            echo esc_html($label); ?></label>
            <?php if ($help !== '') { ?>
                <p class="wppilot-setting__help"><?php echo esc_html($help); ?></p>
            <?php } ?>
            <?php if (is_array($field['link'] ?? null) && is_string($field['link']['url'] ?? null)) { ?>
                <p class="wppilot-setting__help"><a href="<?php echo esc_url((string) $field['link']['url']); ?>" target="_blank" rel="noopener noreferrer"><?php
                echo esc_html((string) ($field['link']['text'] ?? $field['link']['url'])); ?></a></p>
            <?php } ?>
        </div>
        <div class="wppilot-setting__control">
            <?php if ($type === 'secret') {
                $saved = ($field['value'] ?? false) === true;
                ?>
                <input
                    type="password"
                    id="<?php echo esc_attr($id); ?>"
                    name="<?php echo esc_attr($name); ?>"
                    value=""
                    autocomplete="off"
                    spellcheck="false"
                    class="regular-text"
                    placeholder="<?php echo esc_attr($saved ? __('Saved. Leave blank to keep it.', domain: 'wppilot') : __('Not set', domain: 'wppilot')); ?>"
                >
                <?php if ($saved) { ?>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($name . '_clear'); ?>" value="1">
                        <?php esc_html_e('Remove the saved key', domain: 'wppilot'); ?>
                    </label>
                <?php } ?>
            <?php
            } elseif ($type === 'select') {
                $options = is_array($field['options'] ?? null) ? $field['options'] : [];
                ?>
                <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>">
                    <?php foreach ($options as $value => $text) { ?>
                        <option
                            value="<?php echo esc_attr((string) $value); ?>"
                            <?php selected((string) $value, (string) ($field['value'] ?? '')); ?>
                        ><?php echo esc_html((string) $text); ?></option>
                    <?php } ?>
                </select>
            <?php
            } else {
                $on = ($field['value'] ?? false) === true;
                $state = (string) ($field['state'] ?? '');
                ?>
                <label class="wppilot-switch<?php echo $state === 'armed' ? ' wppilot-switch--armed' : ''; ?>">
                    <input
                        type="checkbox"
                        id="<?php echo esc_attr($id); ?>"
                        name="<?php echo esc_attr($name); ?>"
                        value="1"
                        <?php checked($on); ?>
                    >
                    <span class="wppilot-switch__track" aria-hidden="true"></span>
                </label>
            <?php
            } ?>
        </div>
    </div>
    <?php
}

add_action('admin_init', callback: 'wppilot_handle_settings_save');

// Priority 15 places Settings directly after Configuration (10) and before
// Troubleshoot (20).
add_action(
    'admin_menu',
    static function (): void {
        add_submenu_page(
            parent_slug: 'wppilot-connect',
            page_title: wppilot_nav_label('wppilot-settings'),
            menu_title: wppilot_nav_label('wppilot-settings'),
            capability: wppilot_manage_capability(),
            menu_slug: WPPILOT_SETTINGS_PAGE,
            callback: 'wppilot_render_settings_screen',
        );
    },
    priority: 15,
);
