<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor: validating settings against a widget's schema.
 *
 * Errors are collected per element rather than thrown, so one bad widget in a
 * large tree reports precisely instead of failing the whole write.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Validate and normalize a settings map against a compact schema.
 *
 * Returns a structure describing exactly what happened:
 *   ok:        true only when both dropped and invalid are empty
 *   settings:  the (coerced) settings that the caller should write
 *   dropped:   list of keys that are not part of the widget's schema
 *   invalid:   list of {key, value, opts} entries for enum violations on
 *              select / choose / switcher controls (v3) OR mismatched
 *              `$$type` / out-of-enum inner values (atomic v4)
 *
 * Atomic (v4) widgets are treated specially: every value must reach
 * Elementor as `{"$$type": "<prop_key>", "value": <scalar>}`. The validator
 * auto-wraps scalar inputs and verifies that pre-wrapped inputs match the
 * expected `$$type`, so a caller can always pass the ergonomic scalar form.
 *
 * When $schema is null the function is a no-op and always returns ok=true
 * (the caller could not resolve the widget's schema and we must not
 * destroy legitimate settings on unknown widgets).
 *
 * @param array<string, mixed>      $settings
 * @param array<string, mixed>|null $schema
 * @return array{ok: bool, settings: array<string, mixed>, dropped: list<string>, invalid: list<array{key: string, value: mixed, opts: list<mixed>}>}
 */
function el_validate_settings(array $settings, ?array $schema): array
{
    if ($schema === null) {
        return ['ok' => true, 'settings' => $settings, 'dropped' => [], 'invalid' => []];
    }

    $schema_controls = el_schema_controls($schema);
    if ($schema_controls === null) {
        return ['ok' => true, 'settings' => $settings, 'dropped' => [], 'invalid' => []];
    }

    $is_atomic = ($schema['is_atomic'] ?? false) === true;

    $out = [];
    /** @var list<string> $dropped */
    $dropped = [];
    /** @var list<array{key: string, value: mixed, opts: list<mixed>}> $invalid */
    $invalid = [];

    foreach (array_keys($settings) as $key) {
        /** @var mixed $value */
        $value = $settings[$key];

        $resolution = $is_atomic
            ? el_classify_atomic_settings_key($key, $schema_controls)
            : el_classify_v3_settings_key($key, $schema_controls);

        if ($resolution['action'] === 'drop') {
            $dropped[] = $key;
            continue;
        }

        if ($resolution['action'] === 'accept_raw') {
            $out[$key] = $value;
            continue;
        }

        /** @var array<string, mixed> $control */
        $control = is_array($schema_controls[$resolution['key']]) ? $schema_controls[$resolution['key']] : [];

        if ($is_atomic) {
            $atomic_result = el_coerce_atomic_value($key, $value, $control);
            if ($atomic_result['violation'] !== null) {
                $invalid[] = $atomic_result['violation'];
                continue;
            }
            $out[$key] = $atomic_result['value'];
            continue;
        }

        // scalar → array coercion for v3 controls flagged as array-valued.
        if (($control['arr'] ?? false) === true && !is_array($value)) {
            $value = [$value];
        }

        $violation = el_validate_control_value($key, $value, $control);
        if ($violation !== null) {
            $invalid[] = $violation;
            continue;
        }

        $out[$key] = $value;
    }

    return [
        'ok' => $dropped === [] && $invalid === [],
        'settings' => $out,
        'dropped' => $dropped,
        'invalid' => $invalid,
    ];
}

/**
 * Check a single control's value against its declared options. Returns null
 * when the value is acceptable, or an `{key, value, opts}` violation record.
 *
 * @param array<string, mixed> $control
 * @return array{key: string, value: mixed, opts: list<mixed>}|null
 */
function el_validate_control_value(string $key, mixed $value, array $control): ?array
{
    $type = (string) ($control['t'] ?? '');

    if ($type === 'select' || $type === 'choose') {
        return el_validate_enum_value($key, $value, $control);
    }

    if ($type === 'switcher') {
        return el_validate_switcher_value($key, $value, $control);
    }

    return null;
}

/**
 * Enum-style validation for select / choose controls.
 *
 * @param array<string, mixed> $control
 * @return array{key: string, value: mixed, opts: list<mixed>}|null
 */
function el_validate_enum_value(string $key, mixed $value, array $control): ?array
{
    /** @var mixed $raw_opts */
    $raw_opts = $control['opts'] ?? null;
    if (!is_array($raw_opts) || $raw_opts === []) {
        return null;
    }

    // Empty string / null is accepted as "unset" by almost every select — do
    // not fail on it even when not explicitly listed in the option keys.
    if ($value === '' || $value === null) {
        return null;
    }

    if (!is_scalar($value)) {
        /** @var list<mixed> $opts_list */
        $opts_list = array_values($raw_opts);
        return ['key' => $key, 'value' => $value, 'opts' => $opts_list];
    }

    $normalized = (string) $value;
    $allowed = array_map(static fn(mixed $opt): string => (string) $opt, $raw_opts);
    if (in_array($normalized, $allowed, strict: true)) {
        return null;
    }

    /** @var list<mixed> $opts_list */
    $opts_list = array_values($raw_opts);
    return ['key' => $key, 'value' => $value, 'opts' => $opts_list];
}

/**
 * Boolean-ish validation for switcher controls: value must be empty string
 * (off) or match the `rv` (on).
 *
 * @param array<string, mixed> $control
 * @return array{key: string, value: mixed, opts: list<mixed>}|null
 */
function el_validate_switcher_value(string $key, mixed $value, array $control): ?array
{
    /** @var mixed $rv */
    $rv = $control['rv'] ?? 'yes';

    if ($value === '' || $value === null) {
        return null;
    }

    if (is_scalar($value) && (string) $value === (string) $rv) {
        return null;
    }

    return ['key' => $key, 'value' => $value, 'opts' => ['', $rv]];
}

/**
 * Walk an element tree and validate every widget / container's settings.
 *
 * Returns the (coerced) tree, per-element errors, and the compact schemas of
 * every widget type that failed validation — schemas are collected inline
 * from each error record so the response can embed them for self-correction
 * without re-extracting.
 *
 * @param list<array<string, mixed>> $elements
 * @return array{ok: bool, tree: list<array<string, mixed>>, errors: list<array<string, mixed>>, schemas: array<string, array<string, mixed>>}
 */
function el_validate_tree(array $elements): array
{
    /** @var list<array<string, mixed>> $errors */
    $errors = [];
    $tree = el_validate_tree_recurse($elements, $errors);

    // Strip the internal `_schema` marker that the per-element validator used
    // to flag which widget types had errors — the response schema is built
    // separately below with a content-only extraction so the error payload
    // stays compact (embedding include_styles schemas would blow up the size).
    /** @var list<array<string, mixed>> $cleaned_errors */
    $cleaned_errors = [];
    foreach ($errors as $err) {
        unset($err['_schema']);
        $cleaned_errors[] = $err;
    }

    $schemas = el_build_response_schemas($cleaned_errors);

    return [
        'ok' => $cleaned_errors === [],
        'tree' => $tree,
        'errors' => $cleaned_errors,
        'schemas' => $schemas,
    ];
}

/**
 * Internal recursive walker for `el_validate_tree`. Kept separate so the
 * entry point owns the post-processing (schema collection) and the recursive
 * step only has to worry about traversal.
 *
 * @param list<array<string, mixed>> $elements
 * @param list<array<string, mixed>> $errors
 * @return list<array<string, mixed>>
 */
function el_validate_tree_recurse(array $elements, array &$errors): array
{
    $out = [];
    foreach ($elements as $element) {
        $out[] = el_validate_tree_node($element, $errors);
    }
    return $out;
}

/**
 * Validate a single element and recurse into its children.
 *
 * @param array<string, mixed>       $element
 * @param list<array<string, mixed>> $errors
 * @return array<string, mixed>
 */
function el_validate_tree_node(array $element, array &$errors): array
{
    $element = el_validate_element_settings($element, $errors);

    if (is_array($element['elements'] ?? null)) {
        /** @var list<array<string, mixed>> $children */
        $children = $element['elements'];
        $element['elements'] = el_validate_tree_recurse($children, $errors);
    }

    return $element;
}

/**
 * Validate (and normalize) the `settings` of a single element, appending to
 * `$errors` when anything is dropped or invalid. The freshly extracted schema
 * is stashed on the error record under `_schema` so the tree entry point
 * can dedupe and emit it in the response without re-extracting.
 *
 * Atomic widgets/elements also get two extra validation passes on top of the
 * shallow check: `Props_Parser::validate` on the coerced settings (catches
 * malformed inner shapes) and `Style_Parser::parse` on the element's `styles`
 * map (catches the flat-map shape that crashes the frontend renderer).
 *
 * @param array<string, mixed>       $element
 * @param list<array<string, mixed>> $errors
 * @return array<string, mixed>
 */
function el_validate_element_settings(array $element, array &$errors): array
{
    $widget_type = el_element_widget_type($element);
    if ($widget_type === '' || !is_array($element['settings'] ?? null)) {
        return $element;
    }

    $schema = el_extract_schema($widget_type);

    // Unknown widget type — cannot validate and cannot render. Record the
    // error so the whole tree write aborts.
    if ($schema === null) {
        $errors[] = [
            'element_id' => (string) ($element['id'] ?? ''),
            'widget_type' => $widget_type,
            'dropped_keys' => [],
            'invalid_values' => [],
            'unknown_widget_type' => true,
        ];
        return $element;
    }

    /** @var array<string, mixed> $settings */
    $settings = $element['settings'];
    $settings_result = el_validate_settings($settings, $schema);
    $element['settings'] = el_fill_atomic_schema_defaults($settings_result['settings'], $schema);

    $is_atomic = ($schema['is_atomic'] ?? false) === true;
    $invalid = $settings_result['invalid'];
    if ($is_atomic) {
        $invalid = [...$invalid, ...el_deep_validate_atomic_element($element, $widget_type)];
    }

    /** @var list<array{style_id: string, reason: string, path: string}> $style_errors */
    $style_errors = [];
    if ($is_atomic) {
        [$element, $style_errors] = el_validate_atomic_element_styles($element);
    }

    el_record_element_errors($errors, [
        'element_id' => (string) ($element['id'] ?? ''),
        'widget_type' => $widget_type,
        'dropped_keys' => $settings_result['dropped'],
        'invalid_values' => $invalid,
        'style_errors' => $style_errors,
    ]);
    return $element;
}

/**
 * Append an aggregated error record for the element when any of settings,
 * deep validation, or styles surfaced problems. No-op when everything
 * validated cleanly.
 *
 * @param list<array<string, mixed>>                                                                                                                                           $errors
 * @param array{element_id: string, widget_type: string, dropped_keys: list<string>, invalid_values: list<array{key: string, value: mixed, opts: list<mixed>}>, style_errors: list<array{style_id: string, reason: string, path: string}>} $entry_fields
 */
function el_record_element_errors(array &$errors, array $entry_fields): void
{
    if (
        $entry_fields['dropped_keys'] === []
        && $entry_fields['invalid_values'] === []
        && $entry_fields['style_errors'] === []
    ) {
        return;
    }

    $entry = [
        'element_id' => $entry_fields['element_id'],
        'widget_type' => $entry_fields['widget_type'],
        'dropped_keys' => $entry_fields['dropped_keys'],
        'invalid_values' => $entry_fields['invalid_values'],
    ];
    if ($entry_fields['style_errors'] !== []) {
        $entry['style_errors'] = $entry_fields['style_errors'];
    }
    $errors[] = $entry;
}
