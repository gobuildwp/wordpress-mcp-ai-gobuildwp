<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Ability: Update an existing Elementor widget's settings.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/elementor-edit-element', [
    'label' => __('Edit Elementor Element', domain: 'wppilot'),
    'description' => __(
        'Updates the settings of an existing element (widget or container) on an Elementor document. Settings are merged on top of existing settings by default — pass replace=true to overwrite the whole settings object. Validation runs server-side on every call: unknown control IDs or invalid enum values abort the write and return the compact content-only schema of the target element INLINE in the error, so you can correct and retry in a single roundtrip. wppilot/elementor-get-schema is an OPTIONAL discovery tool for unfamiliar widgets — it is NOT required before writing. Shape rules: use only keys present in the element\'s "controls" map; select/choose values MUST come from the control\'s "opts"; switchers use "rv" for on and "" for off; typography requires typography_typography="custom" first; dimensions are {unit,top,right,bottom,left,isLinked}; sliders are {size,unit}; colors are #RRGGBB; v3 controls carrying an "r" flag in the schema accept responsive overrides via the suffixed key `<key>_<breakpoint>` (e.g. typography_font_size_tablet, padding_mobile, _padding_widescreen) — `r:1` means every breakpoint is allowed, while `r:{min:<bp>}` / `r:{max:<bp>}` / `r:{min,max}` restrict the suffix to a closed window in the canonical size order (mobile < mobile_extra < tablet < tablet_extra < laptop < desktop < widescreen). Breakpoint names are the v4 ones listed in check-setup.kit.active_breakpoints and the same value shape applies to every variant; ATOMIC (v4) widgets accept ergonomic scalar values which the server auto-wraps into {"$$type": "<prop_key>", "value": <scalar>} — for both `settings` AND inside the `styles` map\'s `props` (e.g. color:"#FFFFFF", font-size:72, padding:{block-start:16,...}); or you can pass the wrapped shape directly; v4 atomic responsive uses the `styles` map\'s `variants[]` with `meta.breakpoint` (NOT suffixed keys); CONTENT FIELDS — single-line text controls (heading.title, button.text, icon-list[].text, image.caption, …) take plain text only; wysiwyg controls (text-editor.editor, testimonial.testimonial_content, …) take text-level HTML: paragraphs (`<p>`), lists (`<ul>`/`<ol>` with `<li>`), `<strong>`, `<em>`, `<a>`, `<br>`; NEVER put `style` attributes, wrapper `<div>`s or other layout markup in them — alignment goes in `align`, font sizing/weight/family in `typography_*`, max-width in `_element_custom_width`, spacing in `_padding`/`_margin` (all responsive via the suffix shape above); the dedicated `html` widget IS the place for arbitrary HTML; do not emit controls whose "if" condition is not satisfied.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'element_id' => ['type' => 'string'],
            'settings' => [
                'type' => 'object',
                'description' => 'Settings to merge into the element (or replace when replace=true). Shape depends on the widget type — use wppilot/elementor-get-schema to inspect it. For atomic v4 widgets, scalar values are auto-wrapped into the $$type envelope. Unknown keys and invalid enum values cause the call to fail with the schema included in the error.',
            ],
            'styles' => [
                'type' => 'object',
                'description' => 'Per-element styles to set on the element. TWO shapes are accepted. (1) ERGONOMIC — a flat `{css-prop: value}` map, the same shape `wppilot/elementor-create-atomic-widget.base_styles` and `wppilot/elementor-create-global-class.styles` take (e.g. {"background":{"color":"#ffffff"}, "padding":28, "border-radius":12}); scalars auto-wrap, compound types (background/border/box-shadow/…) need their nested shape, long-form `{$$type, value}` also works. Synthesized into a single desktop/null-state variant under `s-<element_id>` so repeated ergonomic edits on the same element overwrite the same style entry instead of piling up. The rendered CSS class follows that style id (`.s-<element_id>`), so semantic class names come from giving the element a semantic `element_id` at create time (via wppilot/elementor-add-element). Use this when one breakpoint is enough (the common case). (2) VERBOSE — a `{"<style_id>": {id, type, label, variants: [{meta: {breakpoint, state}, props: {CSS_PROP: <value>, ...}}]}}` map when you need explicit control over breakpoints / states / multiple variants; `id` (and not `label`) drives the rendered CSS class name — the value must be 2–50 chars, `[a-zA-Z0-9_-]+`, can\'t start with a digit / `--` / `-<digit>`, can\'t be `container`; `label` is editor-only metadata, set it to `"local"` to match Elementor\'s native convention for per-element styles. Inside `props`, scalar values are auto-wrapped against the v4 Style Schema (e.g. color:"#FFFFFF" → {$$type:"color",value:"#FFFFFF"}; font-size:72 → {$$type:"size",value:{size:72,unit:"px"}}; padding:{block-start:16, inline-end:32, ...} → wrapped dimensions). Shapes are auto-detected: every top-level key that matches a v4 Style Schema CSS property (and has no `variants` field) routes through the ergonomic path; otherwise verbose. Merged on top of existing styles by default. Pass replace=true to overwrite all styles; pass replace=true with an empty object ({}) to clear every per-element style. Only valid on v4 atomic elements. Use wppilot/elementor-delete-element-style to remove a single style by id.',
            ],
            'replace' => [
                'type' => 'boolean',
                'description' => 'When true, replaces the entire settings (and styles) object instead of merging. Combined with styles:{}, this clears all per-element styles. Defaults to false.',
            ],
        ],
        'required' => ['post_id', 'element_id'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'element_id' => ['type' => 'string'],
            'widget_type' => ['type' => 'string'],
            'dropped_keys' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Unknown control IDs. Only present on validation failures.',
            ],
            'invalid_values' => [
                'type' => 'array',
                'description' => 'Enum violations ({key, value, opts}). Only present on validation failures.',
            ],
            'unknown_style_properties' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Ergonomic-styles validation failure: CSS property names that are not in the v4 Style Schema.',
            ],
            'invalid_style_values' => [
                'type' => 'object',
                'description' => 'Ergonomic-styles validation failure: per-property {received_type, expected_types, reason} describing why the value failed schema validation.',
            ],
            'schema' => [
                'type' => 'object',
                'description' => 'Compact schema of the target widget. Only present on validation failures.',
            ],
            'error' => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback' => 'WPPilot\Elementor\elementor_edit_element',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => [
            'readonly' => false,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function elementor_edit_element(array $input): array
{
    $parsed = el_parse_edit_element_input($input);
    if (array_key_exists('error', $parsed)) {
        return ['success' => false, 'error' => $parsed['error']];
    }

    /** @var array{post_id: int, element_id: string, settings: array<string, mixed>, styles: array<string, mixed>, replace: bool} $parsed */
    [$elements, $read_error] = el_read_page($parsed['post_id']);
    if ($elements === null) {
        return ['success' => false, 'error' => $read_error ?? 'Unknown error.'];
    }

    $target = el_find($elements, $parsed['element_id']);
    if ($target === null) {
        return [
            'success' => false,
            'error' => "Element '{$parsed['element_id']}' not found on post {$parsed['post_id']}.",
        ];
    }

    $validated_settings = [];
    if ($parsed['settings'] !== []) {
        $validation_result = el_validate_update_settings($target, $parsed['settings']);
        if (array_key_exists('error_response', $validation_result)) {
            return $validation_result['error_response'];
        }
        $validated_settings = $validation_result['settings'] ?? [];
    }

    $validated_styles = [];
    if ($parsed['styles'] !== []) {
        $styles_validation = el_validate_update_styles($target, $parsed['styles']);
        if (array_key_exists('error_response', $styles_validation)) {
            return $styles_validation['error_response'];
        }
        $validated_styles = $styles_validation['styles'] ?? [];
    }

    /** @var list<array{element_id: string, widget_type: string, setting_name: string, tag: mixed, reason: string}> $dynamic_tag_errors */
    $dynamic_tag_errors = [];

    $mode = $parsed['replace'] ? 'replace' : 'merge';
    el_mutate(
        $elements,
        $parsed['element_id'],
        el_build_edit_mutator($validated_settings, $validated_styles, $mode, $dynamic_tag_errors),
    );

    if ($dynamic_tag_errors !== []) {
        return [
            'success' => false,
            'error' => sprintf(
                'Edit aborted: %d dynamic tag parse error(s) on the mutated element. Fix the reported tag string(s) and retry.',
                count($dynamic_tag_errors),
            ),
            'dynamic_tag_errors' => $dynamic_tag_errors,
        ];
    }

    $write = el_write_page($parsed['post_id'], $elements);
    if (is_wp_error($write)) {
        return ['success' => false, 'error' => $write->get_error_message()];
    }

    return ['success' => true, 'element_id' => $parsed['element_id']];
}

/**
 * Build the closure passed to `el_mutate` that updates a single
 * element's settings + styles and then runs the universal atomic
 * finalize on the mutated subtree.
 *
 * `$mode` is `"replace"` (overwrite the existing settings/styles map
 * wholesale) or `"merge"` (shallow-merge into what is already there).
 * The mutator captures `$dynamic_tag_errors` by reference so any
 * dynamic tag parse failures the finalize hits during coercion bubble
 * back to the top-level edit handler, which decides whether to abort.
 *
 * @param array<string, mixed> $validated_settings
 * @param array<string, mixed> $styles
 * @param list<array{element_id: string, widget_type: string, setting_name: string, tag: mixed, reason: string}> $dynamic_tag_errors
 * @return callable(array<string, mixed>): array<string, mixed>
 */
function el_build_edit_mutator(
    array $validated_settings,
    array $styles,
    string $mode,
    array &$dynamic_tag_errors,
): callable {
    return static function (array $element) use ($validated_settings, $styles, $mode, &$dynamic_tag_errors): array {
        /** @var array<string, mixed> $element */
        $replace = $mode === 'replace';
        if ($validated_settings !== []) {
            $element = $replace
                ? el_replace_settings($element, $validated_settings)
                : el_merge_settings($element, $validated_settings);
        }

        // On replace we must apply the styles overwrite even when the map
        // is empty — that's the only way to *clear* styles from an element.
        // For merge mode an empty map is a no-op, which is what the caller
        // asked for.
        if ($replace || $styles !== []) {
            /** @var array<string, mixed> $existing_styles */
            $existing_styles = is_array($element['styles'] ?? null) ? $element['styles'] : [];
            $element['styles'] = $replace ? $styles : array_merge($existing_styles, $styles);
        }

        return el_finalize_atomic_node($element, $dynamic_tag_errors);
    };
}

/**
 * Parse and validate the raw RPC input for edit-element.
 *
 * @param array<string, mixed> $input
 * @return array{error: string}|array{post_id: int, element_id: string, settings: array<string, mixed>, styles: array<string, mixed>, replace: bool}
 */
function el_parse_edit_element_input(array $input): array
{
    if (!class_exists('Elementor\\Plugin')) {
        return ['error' => 'Elementor is not active.'];
    }

    $post_id = (int) ($input['post_id'] ?? 0);
    if ($post_id <= 0 || !get_post($post_id)) {
        return ['error' => "Post {$post_id} not found."];
    }

    $element_id = (string) ($input['element_id'] ?? '');
    if ($element_id === '') {
        return ['error' => 'Parameter "element_id" is required.'];
    }

    /** @var array<string, mixed> $settings */
    $settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
    /** @var array<string, mixed> $styles */
    $styles = is_array($input['styles'] ?? null) ? $input['styles'] : [];
    // "At least one provided" uses key-presence, not emptiness: passing
    // `styles: {}` with `replace: true` is the canonical way to clear every
    // per-element style, so an empty map is a valid input — not a missing one.
    $styles_provided = array_key_exists('styles', $input);
    $settings_provided = array_key_exists('settings', $input);

    if (!$settings_provided && !$styles_provided) {
        return ['error' => 'At least one of "settings" or "styles" must be provided.'];
    }

    return [
        'post_id' => $post_id,
        'element_id' => $element_id,
        'settings' => $settings,
        'styles' => $styles,
        'replace' => ($input['replace'] ?? false) === true,
    ];
}

/**
 * Validate the provided settings against the target element's compact schema.
 * Returns `{settings: ...}` on success or `{error_response: ...}` on failure,
 * with the schema inlined in the error so the caller can self-correct.
 *
 * @param array<string, mixed> $target
 * @param array<string, mixed> $settings
 * @return array{settings: array<string, mixed>}|array{error_response: array<string, mixed>}
 */
function el_validate_update_settings(array $target, array $settings): array
{
    $widget_type = el_element_widget_type($target);

    if ($widget_type === '') {
        return [
            'error_response' => [
                'success' => false,
                'error' => 'Could not resolve the target element\'s widget type. The element on disk has no elType/widgetType — that element is broken and cannot be updated.',
            ],
        ];
    }

    $schema = el_extract_schema($widget_type);
    if ($schema === null) {
        return [
            'error_response' => [
                'success' => false,
                'error' => sprintf(
                    'Target element has widget_type "%s" which is not registered with Elementor on this site. The element may come from a plugin that was deactivated — cannot safely update its settings.',
                    $widget_type,
                ),
                'widget_type' => $widget_type,
            ],
        ];
    }

    $validation = el_validate_settings($settings, $schema);
    if ($validation['ok']) {
        return ['settings' => $validation['settings']];
    }

    $element_type = match (true) {
        $widget_type === WPPILOT_COMPACT_SCHEMA_CONTAINER_KEY => 'container',
        in_array($widget_type, WPPILOT_LEGACY_LAYOUT_TYPES, strict: true) => $widget_type,
        default => 'widget',
    };
    return ['error_response' => build_single_element_error_response($element_type, $widget_type, $validation)];
}

/**
 * Validate the provided per-element styles map against Elementor's
 * `Style_Parser`. Returns `{styles: ...}` on success or `{error_response: ...}`
 * on failure, with per-entry reasons the caller can surface to the agent.
 * Styles on v3 widgets/containers are rejected outright since only atomic
 * elements support the v4 styles map.
 *
 * Accepts two input shapes:
 *   - Verbose: `{"<style_id>": {id, type, label, variants: [...]}}` — the
 *     shape Style_Parser consumes directly.
 *   - Ergonomic: `{"<css_prop>": <value>, ...}` — the same shape
 *     `create-atomic-widget.base_styles` and `create-global-class.styles`
 *     accept. Synthesized into a single desktop variant under a
 *     deterministic `s-<element_id>` style id before Style_Parser runs.
 *
 * @param array<string, mixed> $target
 * @param array<string, mixed> $styles
 * @return array{styles: array<string, mixed>}|array{error_response: array<string, mixed>}
 */
function el_validate_update_styles(array $target, array $styles): array
{
    $widget_type = el_element_widget_type($target);
    $schema = $widget_type === '' ? null : el_extract_schema($widget_type);
    $is_atomic = is_array($schema) && ($schema['is_atomic'] ?? false) === true;

    if (!$is_atomic) {
        return [
            'error_response' => [
                'success' => false,
                'error' => 'Per-element styles are a v4 atomic feature — the target element is not an atomic widget or atomic container. Apply styling via the widget\'s own controls instead.',
                'widget_type' => $widget_type,
            ],
        ];
    }

    if (el_looks_like_ergonomic_styles_map($styles)) {
        $expanded = el_expand_ergonomic_styles_for_target($target, $styles, $widget_type);
        if (array_key_exists('error_response', $expanded)) {
            return $expanded;
        }
        /** @var array{styles: array<string, mixed>} $expanded */
        $styles = $expanded['styles'];
    }

    // Normalize `meta.breakpoint: null` → "desktop" before validation so the
    // base variant shape Elementor itself emits (Style_Variant::build) works
    // the same way through edit-element as through add-element tree mode.
    // Without this the downstream Style_Parser rejects null breakpoints with
    // `missing_or_invalid_value`, which is the same shape add-element
    // silently accepts.
    $normalized_styles = el_normalize_styles_map_breakpoints($styles);

    $result = el_validate_styles_map($normalized_styles);
    if ($result['ok']) {
        return ['styles' => $result['styles']];
    }

    $message = sprintf(
        'Invalid styles for widget "%s": %d style entry error(s). Each style must be {id, type, label, variants:[{meta:{breakpoint,state}, props:{CSS_PROP: {$$type, value}, ...}}]}. Use wppilot/elementor-get-style-schema to inspect the prop shapes.',
        $widget_type,
        count($result['errors']),
    );
    $summary = el_summarize_style_errors($result['errors'], limit: 3);
    if ($summary !== '') {
        $message .= ' ' . $summary;
    }

    return [
        'error_response' => [
            'success' => false,
            'error' => $message,
            'widget_type' => $widget_type,
            'style_errors' => $result['errors'],
        ],
    ];
}

/**
 * Expand an ergonomic CSS-property styles map into the verbose
 * Style_Definition shape. Validates props against the v4 Style Schema up
 * front so unknown CSS props / type mismatches surface as
 * `unknown_style_properties` / `invalid_style_values` (the same per-property
 * payload `create-atomic-widget.base_styles` and `create-global-class.styles`
 * emit, just under style-scoped field names so it does not collide with
 * add/edit-element's settings-side `invalid_values` enum-violation list)
 * rather than as Style_Parser `style_entry_not_object` noise with a
 * synthesized style id the caller never typed.
 *
 * @param array<string, mixed> $target
 * @param array<string, mixed> $styles
 * @return array{styles: array<string, mixed>}|array{error_response: array<string, mixed>}
 */
function el_expand_ergonomic_styles_for_target(array $target, array $styles, string $widget_type): array
{
    $props_validation = el_validate_style_props($styles);
    if (array_key_exists('error', $props_validation)) {
        $parts = [];
        $error_response = [
            'success' => false,
            'widget_type' => $widget_type,
        ];
        if (array_key_exists('unknown_properties', $props_validation)) {
            $error_response['unknown_style_properties'] = $props_validation['unknown_properties'];
            $parts[] = sprintf('unknown properties: %s', implode(', ', $props_validation['unknown_properties']));
        }
        if (array_key_exists('invalid_values', $props_validation)) {
            $error_response['invalid_style_values'] = $props_validation['invalid_values'];
            $parts[] = sprintf('invalid values for: %s', implode(
                ', ',
                array_keys($props_validation['invalid_values']),
            ));
        }
        $error_response['error'] = sprintf(
            'Invalid ergonomic styles for widget "%s" — %s. See "unknown_style_properties"/"invalid_style_values" for per-property details; call wppilot/elementor-get-style-schema only if you need the full list of valid CSS properties.',
            $widget_type,
            $parts === [] ? 'validation failed' : implode('; ', $parts),
        );
        return ['error_response' => $error_response];
    }

    /** @var array{props: array<string, mixed>} $props_validation */
    $element_id = is_string($target['id'] ?? null) ? $target['id'] : '';
    return [
        'styles' => el_wrap_ergonomic_styles_as_style_def($element_id, $props_validation['props']),
    ];
}

/**
 * Replace an element's settings entirely.
 *
 * @param array<string, mixed> $element
 * @param array<string, mixed> $settings
 * @return array<string, mixed>
 */
function el_replace_settings(array $element, array $settings): array
{
    $element['settings'] = $settings;
    return $element;
}

/**
 * Merge new settings on top of an element's existing settings.
 *
 * @param array<string, mixed> $element
 * @param array<string, mixed> $settings
 * @return array<string, mixed>
 */
function el_merge_settings(array $element, array $settings): array
{
    /** @var array<string, mixed> $existing */
    $existing = is_array($element['settings'] ?? null) ? $element['settings'] : [];
    $element['settings'] = array_merge($existing, $settings);
    return $element;
}
