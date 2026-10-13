<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

use WP_Error;

/**
 * Abilities: read and write an Elementor document's page settings.
 *
 * Page settings are everything in the editor's gear panel — the page layout
 * template, whether the theme's title is hidden, page background, page-level
 * custom CSS — and they decide as much about how a page lands as the elements
 * inside it do. A hero built full-bleed on a page still set to the theme's
 * boxed template renders with the theme's margins around it, and nothing in
 * the element tree explains why.
 *
 * They live in the `_elementor_page_settings` post meta, and the writable
 * vocabulary is the document's own Controls_Stack, which differs by document
 * type: a page has a page-layout template control, a header does not. So the
 * schema is read live from the document being edited rather than from a fixed
 * list.
 *
 * Writes go through `$document->save(['settings' => …])` rather than
 * `update_settings()`, for the reason recorded in wppilot-manage-global-styles-v3.php:
 * update_settings() merges lists by index and cannot remove an entry.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolve a post id to an Elementor document that has page settings.
 *
 * Returns WP_Error as a plain object — PHP rejects `object|WP_Error`.
 *
 * @return object The Document on success, a WP_Error on refusal.
 */
function el_require_settings_document(int $post_id): object
{
    if (!class_exists('Elementor\\Plugin')) {
        return new WP_Error(
            'elementor_required',
            __('Elementor is not active on this site.', domain: 'wppilot'),
            ['status' => 400],
        );
    }
    if (get_post($post_id) === null) {
        return new WP_Error(
            'elementor_post_not_found',
            sprintf(
                /* translators: %d: post id */
                __('No post with id %d exists.', domain: 'wppilot'),
                $post_id,
            ),
            ['status' => 404],
        );
    }

    /** @var mixed $document */
    $document = \Elementor\Plugin::$instance->documents->get($post_id);
    if (!is_object($document) || !method_exists($document, 'get_controls')) {
        return new WP_Error(
            'elementor_document_unavailable',
            sprintf(
                /* translators: %d: post id */
                __('Elementor could not open post %d as a document. Only Elementor-enabled posts have page settings.', domain: 'wppilot'),
                $post_id,
            ),
            ['status' => 422],
        );
    }

    return $document;
}

/**
 * The writable page-setting controls of a document, keyed by control name.
 *
 * Layout-only controls carry no value, so offering them as settings would
 * invite writes that do nothing.
 *
 * @return array<string, array{type: string, label: string, options: array<string, mixed>, default: mixed}>
 */
function el_page_setting_controls(object $document): array
{
    /** @var mixed $controls */
    $controls = $document->get_controls();
    if (!is_array($controls)) {
        return [];
    }

    /** @var array<string, array{type: string, label: string, options: array<string, mixed>, default: mixed}> $out */
    $out = [];
    /** @var mixed $control */
    foreach ($controls as $name => $control) {
        if (!is_array($control)) {
            continue;
        }
        $type = (string) ($control['type'] ?? '');
        if (in_array($type, ['section', 'heading', 'raw_html', 'divider', 'tab', 'tabs', 'alert'], strict: true)) {
            continue;
        }
        /** @var mixed $options */
        $options = $control['options'] ?? [];
        $out[(string) $name] = [
            'type' => $type,
            'label' => (string) ($control['label'] ?? ''),
            'options' => is_array($options) ? $options : [],
            'default' => $control['default'] ?? null,
        ];
    }

    // Page-level custom CSS is a real document setting — Elementor Pro's
    // CustomCss module reads it straight off the document and substitutes the
    // `selector` token with the page's CSS wrapper — but its control is only
    // registered while the editor is building panels, so it never appears in a
    // controls list read from PHP. Without this the setting is unwritable
    // through any ability, despite working perfectly once written.
    if (!array_key_exists('custom_css', $out) && class_exists('ElementorPro\\Modules\\CustomCss\\Module')) {
        $out['custom_css'] = [
            'type' => 'code',
            'label' => __('Custom CSS', domain: 'wppilot'),
            'options' => [],
            'default' => '',
        ];
    }

    return $out;
}

/**
 * Page-template slugs this site accepts, Elementor's own three plus whatever
 * the active theme registers, and 'default' for "leave it to the theme".
 *
 * @return list<string>
 */
function el_page_template_slugs(): array
{
    $slugs = ['default'];
    /** @var mixed $templates */
    $templates = wp_get_theme()->get_page_templates(null, 'page');
    if (is_array($templates)) {
        foreach (array_keys($templates) as $slug) {
            $slugs[] = (string) $slug;
        }
    }
    return array_values(array_unique($slugs));
}

// ------------------------------------------------------ get page settings

wp_register_ability('wppilot/elementor-get-page-settings', [
    'label' => __('Get Elementor Page Settings', domain: 'wppilot'),
    'description' => __(
        'Returns a document\'s page settings — the editor\'s gear panel — together with the settings this particular document type accepts, read live from its own controls. Covers the page layout template (default, Elementor Canvas, Elementor Full Width), hide title, page background, page-level custom CSS, excerpt, featured image and comment status. Worth reading before deciding a layout problem lives in the element tree: a full-bleed hero inside a boxed page template is a page setting, not a container setting.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'The Elementor document to read.'],
            'include_schema' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Include the accepted settings and their types. Set false for just the stored values.',
            ],
        ],
        'required' => ['post_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'document_type' => ['type' => 'string'],
            'settings' => ['type' => 'object', 'description' => 'The stored page settings.'],
            'page_template' => ['type' => 'string', 'description' => 'Resolved page layout template, when the document has one.'],
            'schema' => ['type' => 'object', 'description' => 'Accepted setting names with type, label, options and default.'],
        ],
        'required' => ['post_id', 'settings'],
    ],
    'execute_callback' => 'WPPilot\\Elementor\\elementor_get_page_settings',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function elementor_get_page_settings(array $input): array|WP_Error
{
    $post_id = (int) ($input['post_id'] ?? 0);
    $document = el_require_settings_document($post_id);
    if ($document instanceof WP_Error) {
        return $document;
    }

    /** @var mixed $stored */
    $stored = get_post_meta($post_id, key: '_elementor_page_settings', single: true);
    $settings = is_array($stored) ? $stored : [];

    // The page layout is the one setting Elementor does not keep in its own
    // meta: it writes WordPress's `_wp_page_template`, because that is the key
    // WordPress itself reads when choosing a template file. Reading it back out
    // of the Elementor settings would report every page as having no template
    // however many times it was set.
    $page_template = (string) get_post_meta($post_id, key: '_wp_page_template', single: true);
    if ($page_template === '') {
        $page_template = 'default';
    }
    if (!array_key_exists('template', $settings)) {
        $settings['template'] = $page_template;
    }

    $document_type = '';
    if (method_exists($document, 'get_name')) {
        $document_type = (string) $document->get_name();
    }

    $result = [
        'post_id' => $post_id,
        'document_type' => $document_type,
        'settings' => $settings,
        'page_template' => $page_template,
    ];

    if (($input['include_schema'] ?? true) !== false) {
        $result['schema'] = el_page_setting_controls($document);
    }

    return $result;
}

// ------------------------------------------------------ set page settings

wp_register_ability('wppilot/elementor-set-page-settings', [
    'label' => __('Set Elementor Page Settings', domain: 'wppilot'),
    'description' => __(
        'Writes page settings on an Elementor document, merged into what is stored so an unmentioned setting is left alone. Every key is validated against the settings that document type actually accepts — they differ between a page, a header and a popup — and an unknown one is refused with the valid names rather than saved into meta nothing reads. The most useful key is template: "elementor_canvas" strips the theme\'s header, footer and container entirely, "elementor_header_footer" keeps header and footer but drops the theme\'s content wrapper, "elementor_theme" hands the whole page to the theme\'s own template, and "default" leaves the theme in charge. Call wppilot/elementor-get-page-settings first to see what this document accepts.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'The Elementor document to change.'],
            'settings' => [
                'type' => 'object',
                'description' => 'Settings to merge, e.g. {"template": "elementor_canvas", "hide_title": "yes"}.',
            ],
        ],
        'required' => ['post_id', 'settings'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'settings' => ['type' => 'object', 'description' => 'The stored settings after the write.'],
            'changed' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Setting names this call actually altered.'],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['post_id', 'settings'],
    ],
    'execute_callback' => 'WPPilot\\Elementor\\elementor_set_page_settings',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'instructions' => 'Read wppilot/elementor-get-page-settings first for the settings this document type accepts — they differ between a page, a header and a popup.',
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>|WP_Error
 */
function elementor_set_page_settings(array $input): array|WP_Error
{
    $post_id = (int) ($input['post_id'] ?? 0);
    $document = el_require_settings_document($post_id);
    if ($document instanceof WP_Error) {
        return $document;
    }

    /** @var mixed $supplied */
    $supplied = $input['settings'] ?? null;
    if (!is_array($supplied) || $supplied === []) {
        return new WP_Error(
            'elementor_no_page_settings',
            __('settings must be a non-empty object of setting name to value.', domain: 'wppilot'),
            ['status' => 422],
        );
    }

    $controls = el_page_setting_controls($document);
    if ($controls === []) {
        return new WP_Error(
            'elementor_page_settings_unavailable',
            sprintf(
                /* translators: %d: post id */
                __('Elementor exposed no page settings for document %d.', domain: 'wppilot'),
                $post_id,
            ),
            ['status' => 500],
        );
    }

    /** @var mixed $stored_raw */
    $stored_raw = get_post_meta($post_id, key: '_elementor_page_settings', single: true);
    $stored = is_array($stored_raw) ? $stored_raw : [];
    $next = $stored;

    /** @var list<string> $changed */
    $changed = [];
    /** @var list<string> $warnings */
    $warnings = [];

    /** @var mixed $value */
    foreach ($supplied as $name => $value) {
        $key = (string) $name;
        if (!array_key_exists($key, $controls)) {
            return new WP_Error(
                'elementor_unknown_page_setting',
                sprintf(
                    /* translators: 1: supplied setting name, 2: comma-separated valid names */
                    __('"%1$s" is not a page setting on this document. Valid names: %2$s.', domain: 'wppilot'),
                    $key,
                    implode(', ', array_keys($controls)),
                ),
                ['status' => 422, 'valid_names' => array_keys($controls)],
            );
        }

        $type = (string) ($controls[$key]['type'] ?? '');
        if ($type === 'switcher') {
            $truthy = $value === true || $value === 'yes' || $value === 1 || $value === '1';
            $value = $truthy ? 'yes' : '';
        }

        // `template` is the one control whose options Elementor fills in at
        // render time rather than at registration, so it reaches us with an
        // empty option list and would skip the check below. An invalid page
        // template saves without complaint and silently falls back to the
        // theme, which reads as "the ability did nothing" — exactly the class
        // of failure worth refusing.
        if ($key === 'template' && is_scalar($value)) {
            $valid = el_page_template_slugs();
            if (!in_array((string) $value, $valid, strict: true)) {
                return new WP_Error(
                    'elementor_invalid_page_setting_value',
                    sprintf(
                        /* translators: 1: value, 2: comma-separated valid values */
                        __('"%1$s" is not a page template on this site. Valid values: %2$s.', domain: 'wppilot'),
                        (string) $value,
                        implode(', ', $valid),
                    ),
                    ['status' => 422, 'valid_values' => $valid],
                );
            }
        }

        // A select with a fixed option list is worth checking for the same
        // reason.
        if ($type === 'select' && $controls[$key]['options'] !== [] && is_scalar($value)) {
            $options = array_map(static fn(int|string $k): string => (string) $k, array_keys($controls[$key]['options']));
            if (!in_array((string) $value, $options, strict: true)) {
                return new WP_Error(
                    'elementor_invalid_page_setting_value',
                    sprintf(
                        /* translators: 1: value, 2: setting name, 3: comma-separated valid values */
                        __('"%1$s" is not a valid value for %2$s. Valid values: %3$s.', domain: 'wppilot'),
                        (string) $value,
                        $key,
                        implode(', ', $options),
                    ),
                    ['status' => 422, 'valid_values' => $options],
                );
            }
        }

        if (($stored[$key] ?? null) !== $value) {
            $changed[] = $key;
        }
        $next[$key] = $value;
    }

    if (!method_exists($document, 'save')) {
        return new WP_Error(
            'elementor_page_settings_unavailable',
            __('This Elementor document cannot be saved.', domain: 'wppilot'),
            ['status' => 500],
        );
    }

    // save() rather than update_settings(): the latter merges lists by index
    // and cannot remove an entry.
    $saved = $document->save(['settings' => $next]);
    if ($saved === false) {
        return new WP_Error(
            'elementor_page_settings_save_failed',
            sprintf(
                /* translators: %d: post id */
                __('Elementor refused to save page settings for document %d.', domain: 'wppilot'),
                $post_id,
            ),
            ['status' => 500],
        );
    }

    // Elementor's save() writes the page layout to WordPress's own
    // `_wp_page_template`, and quietly drops it when the post has no Elementor
    // document yet: the control does not exist on a document that was never
    // built, so there is nothing to save it from. The `changed` list is
    // computed before the save, so the call reported changing the template and
    // the template was never written, and a page created and configured before
    // its first build silently kept the theme's header. Writing the key
    // directly afterwards is what Elementor does anyway, and the read below
    // then reports what actually landed rather than what was asked for.
    if (array_key_exists('template', $supplied)) {
        $wanted = (string) $supplied['template'];
        if ($wanted !== '' && (string) get_post_meta($post_id, key: '_wp_page_template', single: true) !== $wanted) {
            update_post_meta($post_id, meta_key: '_wp_page_template', meta_value: $wanted);
        }
    }

    /** @var mixed $fresh_raw */
    $fresh_raw = get_post_meta($post_id, key: '_elementor_page_settings', single: true);
    $fresh = is_array($fresh_raw) ? $fresh_raw : [];

    // Reported alongside the Elementor settings even though WordPress owns the
    // key, so a caller who set the layout sees the layout they set.
    $stored_template = (string) get_post_meta($post_id, key: '_wp_page_template', single: true);
    $fresh['template'] = $stored_template === '' ? 'default' : $stored_template;

    // Reported from what is stored, not from what was asked. A caller that is
    // told a setting changed and finds it did not has no way to tell a bug in
    // the ability from a bug in its own call.
    if (array_key_exists('template', $supplied) && $fresh['template'] !== (string) $supplied['template']) {
        $changed = array_values(array_filter($changed, static fn(string $k): bool => $k !== 'template'));
        $warnings[] = sprintf(
            /* translators: 1: requested template, 2: the template actually stored */
            __('The page layout could not be set to "%1$s"; it is still "%2$s".', domain: 'wppilot'),
            (string) $supplied['template'],
            $fresh['template'],
        );
    } elseif (array_key_exists('template', $supplied) && !in_array('template', $changed, strict: true)) {
        $changed[] = 'template';
    }

    if (array_key_exists('template', $supplied) && $fresh['template'] === 'elementor_canvas') {
        $warnings[] = __(
            'Elementor Canvas removes the theme\'s header and footer from this page, including any Theme Builder header assigned to it.',
            domain: 'wppilot',
        );
    }

    return ['post_id' => $post_id, 'settings' => $fresh, 'changed' => $changed, 'warnings' => $warnings];
}
