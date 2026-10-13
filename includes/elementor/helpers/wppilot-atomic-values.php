<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor v4: coercing values into atomic prop envelopes.
 *
 * Atomic widgets do not take bare scalars — every prop is an envelope of
 * `{$$type, value}`. These accept the ergonomic form an agent naturally writes
 * and wrap it into the shape Elementor stores.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Fill schema defaults into a settings map for any control the caller did
 * not provide. Only runs for atomic (v4) schemas: the editor reads the raw
 * persisted JSON on page load and throws on compound atomic props that were
 * never written (link, tag, attributes, classes on e-button, etc.), while
 * the frontend renderer tolerates missing keys. v3 schemas are left alone
 * so legacy widget nodes stay compact on disk.
 *
 * Must be called *after* `el_validate_settings` so any bad user-supplied
 * values have already been stripped / flagged — otherwise we might leave a
 * broken value in place while also re-adding the default, or skip a key
 * that we thought the caller supplied but was actually invalid.
 *
 * @param array<string, mixed>      $settings
 * @param array<string, mixed>|null $schema
 * @return array<string, mixed>
 */
function el_fill_atomic_schema_defaults(array $settings, ?array $schema): array
{
    if ($schema === null || ($schema['is_atomic'] ?? false) !== true) {
        return $settings;
    }
    $controls = el_schema_controls($schema);
    if ($controls === null) {
        return $settings;
    }
    foreach (array_keys($controls) as $key) {
        if (array_key_exists($key, $settings)) {
            continue;
        }
        /** @var array<string, mixed> $control */
        $control = is_array($controls[$key]) ? $controls[$key] : [];
        if (!array_key_exists('def', $control)) {
            continue;
        }
        $settings[$key] = $control['def'];
    }
    return $settings;
}

/**
 * Coerce a value to the atomic `{"$$type", "value"}` shape, validating its
 * inner scalar against the prop's enum (if present) and verifying that an
 * already-wrapped value has the right `$$type`.
 *
 * Returns `{value, violation}` where `violation` is null on success or a
 * validation record on failure. On success `value` is the coerced wrapped
 * shape ready to be written to `_elementor_data`.
 *
 * @param array<string, mixed> $control
 * @return array{value: mixed, violation: array{key: string, value: mixed, opts: list<mixed>}|null}
 */
function el_coerce_atomic_value(string $key, mixed $value, array $control): array
{
    /** @var mixed $expected_type */
    $expected_type = $control['t'] ?? null;
    if (!is_string($expected_type) || $expected_type === '') {
        // Schema does not carry a prop key — leave the value untouched.
        return ['value' => $value, 'violation' => null];
    }

    // Pre-wrapped shape {"$$type": "...", "value": ...}.
    if (is_array($value) && array_key_exists('$$type', $value) && array_key_exists('value', $value)) {
        /** @var array<string, mixed> $value */
        return el_coerce_prewrapped_atomic_value($key, $value, $control, $expected_type);
    }

    // Scalar / null → auto-wrap. Validate the scalar first against enum,
    // then build the transformable envelope. Object-shaped prop types
    // (e.g. html-v3) need a structured inner value, which we auto-build
    // from the scalar when we know the shape.
    if (is_scalar($value) || $value === null) {
        return el_coerce_scalar_atomic_value($key, $value, $control, $expected_type);
    }

    // v3-style image object {id, url} → wrap into v4 image shape.
    $image_wrapped = el_try_wrap_image_value($expected_type, $value);
    if ($image_wrapped !== null) {
        return ['value' => $image_wrapped, 'violation' => null];
    }

    // Partial / unwrapped object for a known compound prop type
    // (e.g. `{destination: "…", isTargetBlank: true}` for a link). Wrap
    // the outer envelope and delegate to the pre-wrapped path so the
    // inner shape gets normalized — otherwise the raw object would slip
    // through untouched and the frontend renderer would drop the prop.
    if (is_array($value) && el_is_known_compound_prop_type($expected_type)) {
        /** @var array<string, mixed> $value */
        return el_coerce_prewrapped_atomic_value(
            $key,
            ['$$type' => $expected_type, 'value' => $value],
            $control,
            $expected_type,
        );
    }

    // Unwrapped value for a control whose default is itself a typed envelope
    // (e.g. `classes`, persisted as {$$type:"classes", value:[…]} while its
    // prop-type label `t` is the generic "plain"). The v4 editor rejects such
    // a value left unwrapped, yet the missing-value path fills the envelope
    // from `def` — so wrap the raw value here too, deriving the $$type from
    // the default so it matches exactly what the editor stores. Delegating to
    // the pre-wrapped path validates the inner value rather than passing an
    // unsupported shape through silently.
    $default_type = el_atomic_default_envelope_type($control);
    if ($default_type !== null && is_array($value)) {
        /** @var array<string, mixed> $value */
        return el_coerce_prewrapped_atomic_value(
            $key,
            ['$$type' => $default_type, 'value' => $value],
            $control,
            $expected_type,
        );
    }

    // Complex shape we don't know how to coerce — pass through untouched.
    return ['value' => $value, 'violation' => null];
}

/**
 * Whether the prop type has a known inner object shape that
 * `el_normalize_atomic_inner_shape()` can re-wrap in place.
 */
function el_is_known_compound_prop_type(string $type_key): bool
{
    return in_array($type_key, ['link', 'html-v3', 'image'], strict: true);
}

/**
 * When an atomic control's default is itself a typed envelope
 * (`{$$type, value}`), returns that `$$type` — the shape the v4 editor
 * persists. Some controls (notably `classes`) carry a prop-type label
 * ("plain") that differs from this stored type ("classes"), so the default is
 * the only reliable source for wrapping an unwrapped value correctly. Returns
 * null when the control has no enveloped default.
 *
 * @param array<string, mixed> $control
 */
function el_atomic_default_envelope_type(array $control): ?string
{
    /** @var mixed $def */
    $def = $control['def'] ?? null;
    if (is_array($def) && is_string($def['$$type'] ?? null) && array_key_exists('value', $def)) {
        return $def['$$type'];
    }
    return null;
}

/**
 * Validate and normalize a pre-wrapped atomic value.
 *
 * @param array<string, mixed> $value
 * @param array<string, mixed> $control
 * @return array{value: mixed, violation: array{key: string, value: mixed, opts: list<mixed>}|null}
 */
function el_coerce_prewrapped_atomic_value(string $key, array $value, array $control, string $expected_type): array
{
    // Allow mismatched $$type in two cases:
    // 1. "plain" prop types are pass-through — Elementor stores them with
    //    their own internal key (e.g. "classes") which differs from the
    //    prop-type label "plain".
    // 2. "dynamic" values can replace any prop type — they are resolved at
    //    render time and the underlying prop type is irrelevant.
    if ($value['$$type'] !== $expected_type && $expected_type !== 'plain' && $value['$$type'] !== 'dynamic') {
        return [
            'value' => $value,
            'violation' => ['key' => $key, 'value' => $value, 'opts' => [$expected_type]],
        ];
    }

    // Dynamic values are resolved at render time — their inner structure
    // ({name, group, settings}) must NOT be normalized as if it were the
    // target prop type (e.g. link or image). Skip both normalization and
    // inner validation for dynamic values.
    if ($value['$$type'] === 'dynamic') {
        return ['value' => $value, 'violation' => null];
    }

    // Normalize the inner shape for known object prop types. Agents
    // sometimes pre-wrap the outer envelope but leave the inner shape
    // partially unwrapped (e.g. `destination: "url"` instead of
    // `destination: {$$type: "url", value: "url"}`). Without this
    // normalization, Elementor's frontend renderer crashes on the
    // malformed structure.
    $value['value'] = el_normalize_atomic_inner_shape($expected_type, $value['value']);
    $inner_violation = el_validate_atomic_inner_value($key, $value['value'], $control);
    if ($inner_violation !== null) {
        return ['value' => $value, 'violation' => $inner_violation];
    }

    return ['value' => $value, 'violation' => null];
}

/**
 * Validate and wrap a scalar atomic value.
 *
 * @param array<string, mixed> $control
 * @return array{value: mixed, violation: array{key: string, value: mixed, opts: list<mixed>}|null}
 */
function el_coerce_scalar_atomic_value(string $key, mixed $value, array $control, string $expected_type): array
{
    $inner_violation = el_validate_atomic_inner_value($key, $value, $control);
    if ($inner_violation !== null) {
        return ['value' => $value, 'violation' => $inner_violation];
    }

    return [
        'value' => [
            '$$type' => $expected_type,
            'value' => el_build_atomic_object_shape($expected_type, $value) ?? $value,
        ],
        'violation' => null,
    ];
}

/**
 * When the expected prop type is `image` and the value is a v3-style
 * `{id, url}` object, return the wrapped v4 image shape. Returns null
 * otherwise.
 */
function el_try_wrap_image_value(string $expected_type, mixed $value): ?array
{
    if ($expected_type !== 'image' || !is_array($value)) {
        return null;
    }
    if (!array_key_exists('id', $value) && !array_key_exists('url', $value)) {
        return null;
    }
    /** @var array<string, mixed> $value */
    return ['$$type' => 'image', 'value' => el_build_image_shape($value)];
}

/**
 * Build the v4 wrapped shape for an image prop from a v3-style
 * `{id, url}` object. Either id or url (or both) can be provided.
 *
 * @param array<string, mixed> $image
 * @return array<string, mixed>
 */
function el_build_image_shape(array $image): array
{
    /** @var array<string, mixed> $src_value */
    $src_value = [];
    if (array_key_exists('id', $image) && $image['id'] !== '' && $image['id'] !== null) {
        $src_value['id'] = ['$$type' => 'image-attachment-id', 'value' => (int) $image['id']];
    }
    if (array_key_exists('url', $image) && is_string($image['url']) && $image['url'] !== '') {
        $src_value['url'] = ['$$type' => 'url', 'value' => $image['url']];
    }

    /** @var mixed $size_value */
    $size_value = $image['size'] ?? null;
    $size = is_string($size_value) && $size_value !== '' ? $size_value : 'full';

    return [
        'src' => ['$$type' => 'image-src', 'value' => $src_value],
        'size' => ['$$type' => 'string', 'value' => $size],
    ];
}

/**
 * Normalize the inner value of a pre-wrapped atomic object prop so that
 * its child fields are wrapped in the `{$$type, value}` format Elementor
 * expects. No-op for types we don't know, or when the inner value is
 * already well-formed.
 */
function el_normalize_atomic_inner_shape(string $type_key, mixed $inner): mixed
{
    if (!is_array($inner)) {
        return $inner;
    }
    /** @var array<string, mixed> $inner */
    if ($type_key === 'link') {
        return el_normalize_link_inner_shape($inner);
    }
    if ($type_key === 'html-v3') {
        return el_normalize_html_v3_inner_shape($inner);
    }
    if ($type_key === 'image') {
        return el_normalize_image_inner_shape($inner);
    }
    return $inner;
}

/**
 * Normalize a `link` prop's inner value.
 *
 * @param array<string, mixed> $inner
 * @return array<string, mixed>
 */
function el_normalize_link_inner_shape(array $inner): array
{
    /** @var mixed $destination */
    $destination = $inner['destination'] ?? null;
    if (is_string($destination)) {
        $inner['destination'] = ['$$type' => 'url', 'value' => $destination];
    }
    /** @var mixed $is_target_blank */
    $is_target_blank = $inner['isTargetBlank'] ?? null;
    if (is_bool($is_target_blank)) {
        $inner['isTargetBlank'] = ['$$type' => 'boolean', 'value' => $is_target_blank];
    }
    if (!array_key_exists('isTargetBlank', $inner) || $inner['isTargetBlank'] === null) {
        $inner['isTargetBlank'] = ['$$type' => 'boolean', 'value' => false];
    }
    /** @var mixed $tag */
    $tag = $inner['tag'] ?? null;
    if (is_string($tag)) {
        $inner['tag'] = ['$$type' => 'string', 'value' => $tag];
    }
    if (!array_key_exists('tag', $inner) || $inner['tag'] === null) {
        $inner['tag'] = ['$$type' => 'string', 'value' => 'a'];
    }
    // Drop unknown fields like `label` that aren't part of the v4 link shape.
    unset($inner['label']);
    return $inner;
}

/**
 * Normalize an `html-v3` prop's inner value.
 *
 * @param array<string, mixed> $inner
 * @return array<string, mixed>
 */
function el_normalize_html_v3_inner_shape(array $inner): array
{
    /** @var mixed $content */
    $content = $inner['content'] ?? null;
    if (is_string($content)) {
        $inner['content'] = ['$$type' => 'string', 'value' => $content];
    }
    if (!array_key_exists('children', $inner) || !is_array($inner['children'])) {
        $inner['children'] = [];
    }
    return $inner;
}

/**
 * Normalize an `image` prop's inner value.
 *
 * @param array<string, mixed> $inner
 * @return array<string, mixed>
 */
function el_normalize_image_inner_shape(array $inner): array
{
    /** @var mixed $src */
    $src = $inner['src'] ?? null;
    if (
        is_array($src)
        && (array_key_exists('id', $src) || array_key_exists('url', $src))
        && !array_key_exists('$$type', $src)
    ) {
        /** @var array<string, mixed> $src */
        $inner['src'] = ['$$type' => 'image-src', 'value' => el_build_image_shape($src)['src']['value'] ?? $src];
    }
    /** @var mixed $size */
    $size = $inner['size'] ?? null;
    if (is_string($size)) {
        $inner['size'] = ['$$type' => 'string', 'value' => $size];
    }
    return $inner;
}

/**
 * Build the expected inner structure for an atomic object prop type from a
 * scalar value, or return null when the type is not a known object shape.
 *
 * Handled object prop types:
 * - html-v3: shape content + children, scalar becomes the inner string
 *   content of a rich-text field.
 * - link: shape destination + isTargetBlank + tag, scalar is treated as a
 *   URL and wrapped into the destination slot.
 *
 * Adding more object prop types is straightforward: map the type key to a
 * builder callback that emits the expected wrapped shape.
 *
 * @return array<string, mixed>|null
 */
function el_build_atomic_object_shape(string $type_key, mixed $scalar): ?array
{
    if ($type_key === 'html-v3') {
        return [
            'content' => ['$$type' => 'string', 'value' => $scalar],
            'children' => [],
        ];
    }

    if ($type_key === 'link') {
        return [
            'destination' => ['$$type' => 'url', 'value' => $scalar],
            'isTargetBlank' => ['$$type' => 'boolean', 'value' => false],
            'tag' => ['$$type' => 'string', 'value' => 'a'],
        ];
    }

    return null;
}

/**
 * Validate the inner (unwrapped) scalar of an atomic prop against the
 * control's enum, if any. Empty string / null are accepted as "unset".
 *
 * @param array<string, mixed> $control
 * @return array{key: string, value: mixed, opts: list<mixed>}|null
 */
function el_validate_atomic_inner_value(string $key, mixed $inner, array $control): ?array
{
    /** @var mixed $raw_opts */
    $raw_opts = $control['opts'] ?? null;
    if (!is_array($raw_opts) || $raw_opts === []) {
        return null;
    }

    if ($inner === '' || $inner === null) {
        return null;
    }

    /** @var list<mixed> $opts_list */
    $opts_list = array_values($raw_opts);

    if (!is_scalar($inner)) {
        return ['key' => $key, 'value' => $inner, 'opts' => $opts_list];
    }

    $normalized = (string) $inner;
    $allowed = array_map(static fn(mixed $opt): string => (string) $opt, $raw_opts);
    if (in_array($normalized, $allowed, strict: true)) {
        return null;
    }

    return ['key' => $key, 'value' => $inner, 'opts' => $opts_list];
}

/**
 * Run Elementor's `Props_Parser::validate()` against already-coerced atomic
 * settings and convert any errors into the validator's `{key, value, opts}`
 * violation shape. Catches malformed inner shapes that the shallow validator
 * does not inspect (e.g. bogus html-v3 inner content, broken link objects).
 *
 * Returns an empty list when the payload is valid, when the widget has no
 * resolvable raw schema, or when `Props_Parser` is not available.
 *
 * @param array<string, mixed>       $settings
 * @param array<string, object>|null $raw_schema
 * @return list<array{key: string, value: mixed, opts: list<mixed>}>
 */
function el_validate_atomic_props_deep(array $settings, ?array $raw_schema): array
{
    if ($raw_schema === null) {
        return [];
    }
    if (!class_exists(\Elementor\Modules\AtomicWidgets\Parsers\Props_Parser::class)) {
        return [];
    }

    $parser = \Elementor\Modules\AtomicWidgets\Parsers\Props_Parser::make($raw_schema);
    $result = $parser->validate($settings);
    if ($result->is_valid()) {
        return [];
    }

    /** @var list<array{key: string, error: string}> $raw_errors */
    $raw_errors = $result->errors()->all();

    /** @var list<array{key: string, value: mixed, opts: list<mixed>}> $violations */
    $violations = [];
    foreach ($raw_errors as $err) {
        // Skip keys the caller didn't submit: Props_Parser also validates
        // defaults, so a default that fails schema validation would surface
        // here as a "false positive" from the agent's perspective.
        if (!array_key_exists($err['key'], $settings)) {
            continue;
        }
        $violations[] = [
            'key' => $err['key'],
            'value' => $settings[$err['key']],
            'opts' => [$err['error']],
        ];
    }
    return $violations;
}

/**
 * Deep-validate an atomic element's coerced settings against the widget's
 * raw prop schema. Returns any violations in the `{key, value, opts}` shape
 * used by the shallow validator.
 *
 * @param array<string, mixed> $element
 * @return list<array{key: string, value: mixed, opts: list<mixed>}>
 */
function el_deep_validate_atomic_element(array $element, string $widget_type): array
{
    $raw_schema = el_get_raw_props_schema($widget_type);
    if ($raw_schema === null) {
        return [];
    }
    /** @var array<string, mixed> $coerced */
    $coerced = $element['settings'];
    return el_validate_atomic_props_deep($coerced, $raw_schema);
}
