<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

/**
 * Config as code: wp-config locks for every setting, a JSON export and import, and the file
 * constants WordPress already has.
 *
 * Locks. Any switch or choice on the Settings screen can be pinned from wp-config.php with
 * `define('WPPILOT_SETTING_<KEY>', value)`, where KEY is the field's name without its `wppilot_`
 * prefix, upper-cased: `WPPILOT_SETTING_SAFETY_PROFILE`, `WPPILOT_SETTING_CONFIRMATION_MODE`,
 * `WPPILOT_SETTING_MCP_COMPACT_TOOLS`. The screen shows the field as locked and the save path
 * replaces whatever was posted for it with the pinned value. On every request that has a lock,
 * a setting whose stored value has drifted from its lock is saved back through the section's own
 * save callback, so the option keeps the shape its owner writes and nothing here guesses at it.
 * A lock whose value the field does not accept pins the field to what is stored now and says so.
 * Secret fields (API keys) cannot be locked here.
 *
 * Export and import. The Settings screen downloads every switch and choice as JSON, and takes
 * such a file back. An import runs through the same save callbacks as the form, skips locked
 * fields, ignores names it does not know, and never carries secrets either way.
 *
 * File constants. With DISALLOW_FILE_EDIT, abilities that write files or run PHP are refused;
 * with DISALLOW_FILE_MODS, plugin and theme installs, updates and deletions are refused as well.
 * Only `define('WPPILOT_ALLOW_FILE_ABILITIES', true)` in wp-config.php overrides that: the site
 * owner chose those constants, and an agent cannot change wp-config.php to undo them through the
 * very abilities they switch off.
 */

if (!defined('ABSPATH')) {
    exit();
}

const WPPILOT_SETTING_LOCK_PREFIX = 'WPPILOT_SETTING_';

const WPPILOT_CONFIG_EXPORT_ACTION = 'wppilot_settings_export';

const WPPILOT_CONFIG_IMPORT_ACTION = 'wppilot_settings_import';

const WPPILOT_CONFIG_FORMAT = 'gobuildwp-settings';

/** An exported file is a few hundred bytes; anything far larger is not one. */
const WPPILOT_CONFIG_IMPORT_MAX_BYTES = 65536;

/**
 * The wp-config constant that locks a settings field.
 */
function wppilot_setting_lock_constant(string $field_name): string
{
    $key = (string) preg_replace('/^wppilot_/', '', $field_name);

    return WPPILOT_SETTING_LOCK_PREFIX . strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $key), '_'));
}

/**
 * Whether any lock constant is defined on this site. Cheap enough to ask on every request.
 */
function wppilot_setting_locks_defined(): bool
{
    $constants = get_defined_constants(categorize: true)['user'] ?? [];
    foreach (array_keys($constants) as $name) {
        if (str_starts_with((string) $name, WPPILOT_SETTING_LOCK_PREFIX)) {
            return true;
        }
    }

    return false;
}

/**
 * The value a field holds in an export or a lock: a bool for a toggle, an option key for a select.
 * Null for a value the field does not accept.
 *
 * @param array<array-key, mixed> $field
 */
function wppilot_setting_field_accepts(array $field, mixed $value): bool|string|null
{
    $type = (string) ($field['type'] ?? 'toggle');
    if ($type === 'toggle') {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return null;
    }
    if ($type === 'select') {
        $options = is_array($field['options'] ?? null) ? array_map('strval', array_keys($field['options'])) : [];
        $value = is_int($value) ? (string) $value : $value;

        return is_string($value) && in_array($value, $options, strict: true) ? $value : null;
    }

    return null;
}

/**
 * Whether a field can be exported, imported and locked here at all.
 *
 * @param array<array-key, mixed> $field
 */
function wppilot_setting_field_is_portable(array $field): bool
{
    return in_array((string) ($field['type'] ?? 'toggle'), ['toggle', 'select'], strict: true)
        && is_string($field['name'] ?? null)
        && $field['name'] !== '';
}

/**
 * The current value of a portable field, as the Settings screen shows it.
 *
 * @param array<array-key, mixed> $field
 */
function wppilot_setting_field_value(array $field): bool|string
{
    return ((string) ($field['type'] ?? 'toggle')) === 'select'
        ? (string) ($field['value'] ?? '')
        : ($field['value'] ?? false) === true;
}

/**
 * A field's lock, or null when it has none.
 *
 * `value` is what the field is pinned to; `valid` is false when the constant holds something the
 * field does not accept, and the field is then pinned to its current value instead.
 *
 * @param array<array-key, mixed> $field
 * @return array{constant: string, value: bool|string, valid: bool}|null
 */
function wppilot_setting_lock(array $field): ?array
{
    if (!wppilot_setting_field_is_portable($field)) {
        return null;
    }
    $constant = wppilot_setting_lock_constant((string) $field['name']);
    if (!defined($constant)) {
        return null;
    }
    $value = wppilot_setting_field_accepts($field, constant($constant));

    return $value === null
        ? ['constant' => $constant, 'value' => wppilot_setting_field_value($field), 'valid' => false]
        : ['constant' => $constant, 'value' => $value, 'valid' => true];
}

/**
 * Write one field's value into a settings POST the way the form would send it.
 *
 * @param array<string, mixed>    $post
 * @param array<array-key, mixed> $field
 * @return array<string, mixed>
 */
function wppilot_setting_post_value(array $post, array $field, bool|string $value): array
{
    $name = (string) $field['name'];
    if (((string) ($field['type'] ?? 'toggle')) === 'select') {
        $post[$name] = (string) $value;
    } elseif ($value === true) {
        $post[$name] = '1';
    } else {
        // An unchecked checkbox is absent from the POST entirely.
        unset($post[$name]);
    }

    return $post;
}

/**
 * Replace whatever was posted for each locked field with its pinned value.
 *
 * @param array<string, mixed> $post
 * @return array<string, mixed>
 */
function wppilot_settings_apply_locks(array $post): array
{
    foreach (wppilot_settings_sections() as $section) {
        foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
            $lock = is_array($field) ? wppilot_setting_lock($field) : null;
            if ($lock !== null) {
                $post = wppilot_setting_post_value($post, $field, $lock['value']);
            }
        }
    }

    return $post;
}

/**
 * The POST a section's save callback would receive if the form were submitted unchanged, or null
 * when the section has a field this file cannot reproduce (its callback would read the missing
 * field as cleared).
 *
 * Secret fields are left blank, which every secret field reads as "keep the saved value".
 *
 * @param array<array-key, mixed> $section
 * @return array<string, mixed>|null
 */
function wppilot_settings_section_post(array $section): ?array
{
    $post = [];
    foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
        if (!is_array($field)) {
            return null;
        }
        $type = (string) ($field['type'] ?? 'toggle');
        if ($type === 'secret') {
            continue;
        }
        if (!wppilot_setting_field_is_portable($field)) {
            return null;
        }
        $post = wppilot_setting_post_value($post, $field, wppilot_setting_field_value($field));
    }

    return $post;
}

/**
 * Save a section through its own callback with the given field values, locks applied.
 *
 * @param array<array-key, mixed> $section
 * @param array<string, bool|string> $values Field name => value; fields not named keep their value.
 */
function wppilot_settings_save_section_values(array $section, array $values): bool
{
    $save = $section['save'] ?? null;
    $post = wppilot_settings_section_post($section);
    if (!is_callable($save) || $post === null) {
        return false;
    }
    foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
        if (!is_array($field) || !wppilot_setting_field_is_portable($field)) {
            continue;
        }
        $lock = wppilot_setting_lock($field);
        if ($lock !== null) {
            $post = wppilot_setting_post_value($post, $field, $lock['value']);
        } elseif (array_key_exists((string) $field['name'], $values)) {
            $post = wppilot_setting_post_value($post, $field, $values[(string) $field['name']]);
        }
    }
    $save($post);

    return true;
}

/**
 * Save back every locked setting whose stored value has drifted from its lock.
 *
 * init, late: every section's owner has loaded, translations included. Does nothing on a site
 * with no lock constant.
 *
 * @return list<string> The locked fields that were saved back.
 */
function wppilot_setting_locks_sync(): array
{
    if (!wppilot_setting_locks_defined() || !function_exists('wppilot_settings_sections')) {
        return [];
    }
    $synced = [];
    foreach (wppilot_settings_sections() as $section) {
        $drifted = [];
        foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
            $lock = is_array($field) ? wppilot_setting_lock($field) : null;
            if ($lock !== null && $lock['valid'] && $lock['value'] !== wppilot_setting_field_value($field)) {
                $drifted[] = (string) $field['name'];
            }
        }
        if ($drifted !== [] && wppilot_settings_save_section_values($section, [])) {
            array_push($synced, ...$drifted);
        }
    }

    return $synced;
}

/**
 * Every portable setting and its current value.
 *
 * @return array{format: string, version: int, exported_at: string, settings: array<string, bool|string>}
 */
function wppilot_settings_export_document(): array
{
    $settings = [];
    foreach (wppilot_settings_sections() as $section) {
        foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
            if (is_array($field) && wppilot_setting_field_is_portable($field)) {
                $settings[(string) $field['name']] = wppilot_setting_field_value($field);
            }
        }
    }
    ksort($settings);

    return [
        'format' => WPPILOT_CONFIG_FORMAT,
        'version' => 1,
        'exported_at' => gmdate('c'),
        'settings' => $settings,
    ];
}

/**
 * Apply an exported document.
 *
 * @return array{applied: list<string>, locked: list<string>, rejected: list<string>, unknown: list<string>}|WP_Error
 */
function wppilot_settings_import_document(string $json): array|WP_Error
{
    if (strlen($json) > WPPILOT_CONFIG_IMPORT_MAX_BYTES) {
        return new WP_Error('wppilot_config_import_size', __('That file is too large to be a GoBuildWP settings export.', domain: 'wppilot'));
    }
    /** @var mixed $document */
    $document = json_decode($json, associative: true);
    if (
        !is_array($document)
        || ($document['format'] ?? null) !== WPPILOT_CONFIG_FORMAT
        || ($document['version'] ?? null) !== 1
        || !is_array($document['settings'] ?? null)
    ) {
        return new WP_Error('wppilot_config_import_format', __('That is not a GoBuildWP settings export.', domain: 'wppilot'));
    }
    $incoming = $document['settings'];
    $report = ['applied' => [], 'locked' => [], 'rejected' => [], 'unknown' => []];
    $seen = [];

    foreach (wppilot_settings_sections() as $section) {
        $values = [];
        $names = [];
        foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
            if (!is_array($field) || !wppilot_setting_field_is_portable($field)) {
                continue;
            }
            $name = (string) $field['name'];
            if (!array_key_exists($name, $incoming)) {
                continue;
            }
            $seen[$name] = true;
            if (wppilot_setting_lock($field) !== null) {
                $report['locked'][] = $name;
                continue;
            }
            $value = wppilot_setting_field_accepts($field, $incoming[$name]);
            if ($value === null) {
                $report['rejected'][] = $name;
                continue;
            }
            $values[$name] = $value;
            $names[] = $name;
        }
        if ($values === []) {
            continue;
        }
        if (wppilot_settings_save_section_values($section, $values)) {
            array_push($report['applied'], ...$names);
        } else {
            array_push($report['rejected'], ...$names);
        }
    }
    foreach (array_keys($incoming) as $name) {
        if (!isset($seen[(string) $name])) {
            $report['unknown'][] = sanitize_key((string) $name);
        }
    }

    return $report;
}

function wppilot_config_handle_export(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to manage GoBuildWP settings.', domain: 'wppilot'), '', ['response' => 403]);
    }
    check_admin_referer(WPPILOT_CONFIG_EXPORT_ACTION);

    $host = sanitize_key((string) wp_parse_url(home_url(), PHP_URL_HOST));
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="gobuildwp-settings-' . $host . '-' . gmdate('Y-m-d') . '.json"');
    echo wp_json_encode(wppilot_settings_export_document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}

function wppilot_config_handle_import(): void
{
    if (!wppilot_current_user_can_manage()) {
        wp_die(esc_html__('You are not allowed to manage GoBuildWP settings.', domain: 'wppilot'), '', ['response' => 403]);
    }
    check_admin_referer(WPPILOT_CONFIG_IMPORT_ACTION);

    $json = '';
    $upload = $_FILES['wppilot_settings_file'] ?? null;
    if (
        is_array($upload)
        && is_string($upload['tmp_name'] ?? null)
        && $upload['tmp_name'] !== ''
        && is_uploaded_file($upload['tmp_name'])
        && (int) ($upload['size'] ?? 0) <= WPPILOT_CONFIG_IMPORT_MAX_BYTES
    ) {
        $json = (string) file_get_contents($upload['tmp_name']);
    } elseif (is_string($_POST['wppilot_settings_json'] ?? null)) {
        // Raw JSON: sanitize_text_field() would mangle it. It is only json_decode()d and every value
        // is checked against the field it targets before a save callback sees it.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $json = trim((string) wp_unslash($_POST['wppilot_settings_json']));
    }

    $report = wppilot_settings_import_document($json);
    $args = $report instanceof WP_Error
        ? ['wppilot_import' => 'invalid']
        : [
            'wppilot_import' => 'done',
            'applied' => count($report['applied']),
            'locked' => count($report['locked']),
            'skipped' => count($report['rejected']) + count($report['unknown']),
        ];
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=wppilot-settings')) . '#wppilot-config');
    exit();
}

/**
 * The lock note shown under a locked field.
 *
 * @param array<array-key, mixed> $field
 */
function wppilot_setting_lock_note(array $field): string
{
    $lock = wppilot_setting_lock($field);
    if ($lock === null) {
        return '';
    }

    return $lock['valid']
        ? sprintf(
            /* translators: %s: the constant name, e.g. WPPILOT_SETTING_SAFETY_PROFILE */
            __('Locked by %s in wp-config.php. Remove that line to change it here.', domain: 'wppilot'),
            $lock['constant'],
        )
        : sprintf(
            /* translators: %s: the constant name */
            __('Locked by %s in wp-config.php, but its value is not one this setting accepts, so the saved value is kept. Fix or remove that line.', domain: 'wppilot'),
            $lock['constant'],
        );
}

/**
 * Settings screen panel: file constants in force, export, import.
 */
function wppilot_config_render_panel(): void
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only result of this panel's own redirect.
    $import = is_string($_GET['wppilot_import'] ?? null) ? sanitize_key(wp_unslash($_GET['wppilot_import'])) : '';
    ?>
    <section class="wppilot-panel" id="wppilot-config">
        <h2 class="wppilot-setting-group__title"><?php esc_html_e('Config as code', domain: 'wppilot'); ?></h2>
        <p class="wppilot-setting-group__note"><?php esc_html_e(
            'Lock any switch above from wp-config.php with define( \'WPPILOT_SETTING_<NAME>\', value ), where NAME is the setting\'s name without its wppilot_ prefix, in capitals: for example WPPILOT_SETTING_SAFETY_PROFILE or WPPILOT_SETTING_READ_MODE. Export and import carry switches and choices only, never keys.',
            domain: 'wppilot',
        ); ?></p>
        <?php if ($import === 'invalid') { ?>
            <div class="notice notice-error inline"><p><?php esc_html_e('That is not a GoBuildWP settings export. Nothing was changed.', domain: 'wppilot'); ?></p></div>
        <?php } elseif ($import === 'done') { ?>
            <div class="notice notice-success inline"><p><?php
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only counts from this panel's own redirect.
            echo esc_html(sprintf(
                /* translators: 1: settings applied, 2: settings skipped because they are locked, 3: settings skipped as unknown or invalid */
                __('Import done: %1$d applied, %2$d locked in wp-config.php, %3$d unknown or not accepted.', domain: 'wppilot'),
                absint($_GET['applied'] ?? 0),
                absint($_GET['locked'] ?? 0),
                absint($_GET['skipped'] ?? 0),
            ));
            // phpcs:enable
            ?></p></div>
        <?php } ?>
        <?php foreach (wppilot_file_constants_in_force() as $constant) { ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html(sprintf(
                /* translators: %s: DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS */
                __('%s is set in wp-config.php, so agents are refused the abilities it covers. Only WPPILOT_ALLOW_FILE_ABILITIES in wp-config.php overrides that.', domain: 'wppilot'),
                $constant,
            )); ?></p></div>
        <?php } ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(WPPILOT_CONFIG_EXPORT_ACTION); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(WPPILOT_CONFIG_EXPORT_ACTION); ?>">
            <p><button type="submit" class="button"><?php esc_html_e('Export settings (JSON)', domain: 'wppilot'); ?></button></p>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field(WPPILOT_CONFIG_IMPORT_ACTION); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(WPPILOT_CONFIG_IMPORT_ACTION); ?>">
            <p>
                <label for="wppilot-settings-file"><?php esc_html_e('Import a settings export', domain: 'wppilot'); ?></label><br>
                <input type="file" id="wppilot-settings-file" name="wppilot_settings_file" accept="application/json,.json">
            </p>
            <p>
                <label for="wppilot-settings-json"><?php esc_html_e('or paste it', domain: 'wppilot'); ?></label><br>
                <textarea id="wppilot-settings-json" name="wppilot_settings_json" rows="4" class="large-text code"></textarea>
            </p>
            <p><button type="submit" class="button"><?php esc_html_e('Import settings', domain: 'wppilot'); ?></button></p>
        </form>
    </section>
    <?php
}

/**
 * The abilities DISALLOW_FILE_EDIT covers: anything that writes a file or runs PHP.
 *
 * @return list<string>
 */
function wppilot_file_edit_abilities(): array
{
    /** @var mixed $names */
    $names = apply_filters('wppilot_file_edit_abilities', [
        'wppilot/write-file',
        'wppilot/edit-file',
        'wppilot/delete-file',
        'wppilot/enable-file',
        'wppilot/disable-file',
        'wppilot/execute-php',
    ]);

    return is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
}

/**
 * The abilities DISALLOW_FILE_MODS covers on top: installing, updating or deleting code.
 *
 * @return list<string>
 */
function wppilot_file_mod_abilities(): array
{
    /** @var mixed $names */
    $names = apply_filters('wppilot_file_mod_abilities', [
        'wppilot/install-plugin',
        'wppilot/update-plugin',
        'wppilot/delete-plugin',
        'wppilot/install-theme',
        'wppilot/update-theme',
        'wppilot/delete-theme',
    ]);

    return is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
}

/**
 * DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS, when set to true.
 *
 * @return list<string>
 */
function wppilot_file_constants_in_force(): array
{
    $in_force = [];
    foreach (['DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS'] as $constant) {
        if (defined($constant) && constant($constant) === true) {
            $in_force[] = $constant;
        }
    }

    return $in_force;
}

/**
 * wppilot_pre_ability_execute: refuse the abilities the file constants cover, on every transport.
 */
function wppilot_file_constants_pre_ability_execute(mixed $input, mixed $ability): mixed
{
    if ($input instanceof WP_Error || !$ability instanceof WP_Ability) {
        return $input;
    }
    if (defined('WPPILOT_ALLOW_FILE_ABILITIES') && constant('WPPILOT_ALLOW_FILE_ABILITIES') === true) {
        return $input;
    }
    $in_force = wppilot_file_constants_in_force();
    if ($in_force === []) {
        return $input;
    }
    $name = $ability->get_name();
    $mods = in_array('DISALLOW_FILE_MODS', $in_force, strict: true);
    if (in_array($name, wppilot_file_edit_abilities(), strict: true)) {
        // WordPress itself treats DISALLOW_FILE_MODS as implying DISALLOW_FILE_EDIT.
        $constant = in_array('DISALLOW_FILE_EDIT', $in_force, strict: true) ? 'DISALLOW_FILE_EDIT' : 'DISALLOW_FILE_MODS';
    } elseif ($mods && in_array($name, wppilot_file_mod_abilities(), strict: true)) {
        $constant = 'DISALLOW_FILE_MODS';
    } else {
        return $input;
    }

    return new WP_Error(
        'wppilot_file_constant_refused',
        sprintf(
            /* translators: 1: ability name, 2: DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS */
            __(
                'The site owner set %2$s in wp-config.php, so "%1$s" is refused for every agent. This is deliberate; do not retry or look for another way to change files. Tell the user, who can change it only by editing wp-config.php.',
                domain: 'wppilot',
            ),
            $name,
            $constant,
        ),
        ['status' => 403, 'ability' => $name, 'constant' => $constant],
    );
}

function wppilot_config_locks_register_hooks(): void
{
    add_action('init', 'wppilot_setting_locks_sync', 99);
    add_action('admin_post_' . WPPILOT_CONFIG_EXPORT_ACTION, 'wppilot_config_handle_export');
    add_action('admin_post_' . WPPILOT_CONFIG_IMPORT_ACTION, 'wppilot_config_handle_import');
    // Ahead of the rate limit (6): a call refused outright should not use up a rate bucket.
    add_filter('wppilot_pre_ability_execute', 'wppilot_file_constants_pre_ability_execute', 5, 2);
}
