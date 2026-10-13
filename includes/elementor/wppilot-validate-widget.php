<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

use const ELEMENTOR_VERSION;

/**
 * Validator for Elementor v4 atomic widget creation.
 *
 * Validates all input before the widget generator writes any files.
 * Returns specific, actionable error messages so the AI can self-correct.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reserved element types that cannot be used as widget names.
 *
 * @return list<string>
 */
function reserved_element_types(): array
{
    return [
        'section',
        'column',
        'container',
        'widget',
        'e-heading',
        'e-paragraph',
        'e-button',
        'e-image',
        'e-divider',
        'e-svg',
        'e-youtube',
        'e-self-hosted-video',
        'e-component',
        'e-form',
        'e-tabs',
    ];
}

/** @var list<string> Valid prop types. */
define('WPPILOT_VALID_PROP_TYPES', [
    'image',
    'string',
    'link',
    'html',
    'number',
    'boolean',
    'email',
    'color',
    'select',
    'video',
    'textarea',
    'date_time',
]);

/**
 * @var list<string> Prop types for which a missing default surfaces as a
 * blank control at render time AND where a caller-supplied default is
 * threaded into the generated prop schema. Used to filter the "default not
 * set" warning so it fires only when passing a default would actually
 * change behavior — image gets Elementor's placeholder, number/boolean get
 * zero-values, html gets "Enter text here", and link/date_time ignore the
 * user default entirely, so warnings on those are noise.
 */
define('WPPILOT_DEFAULTLESS_PROP_TYPES', [
    'string',
    'email',
    'textarea',
    'select',
    'color',
    'video',
]);

/** @var list<string> Reserved prop names used internally by Elementor. */
define('WPPILOT_RESERVED_PROP_NAMES', [
    'classes',
    'attributes',
    '_cssid',
    'tag',
    '__dynamic__',
    '_element_id',
    '_css_classes',
    'request_method',
]);

/** @var list<string> Dangerous Twig patterns. */
define('WPPILOT_TWIG_BLOCKED_PATTERNS', [
    '{% include',
    '{% extends',
    '{% import',
    '{% embed',
    '{% macro',
    '_self.env',
    '<script',
    '</script',
    'javascript:',
    'onerror=',
    'onload=',
    'onclick=',
    'onmouseover=',
]);

/** @var list<string> Dangerous JS patterns. */
define('WPPILOT_JS_BLOCKED_PATTERNS', [
    '<script',
    '</script',
    'document.cookie',
    'localStorage',
]);

/**
 * Validate the full create-elementor-widget input.
 *
 * @param array<string, mixed> $input
 * @return array{errors: list<string>, warnings: list<string>}
 */
function validate_widget_input(array $input): array
{
    $errors = [];
    $warnings = [];

    // --- Name ---
    $name = (string) ($input['name'] ?? '');
    array_push($errors, ...validate_widget_name($name));

    // --- Title ---
    $title = (string) ($input['title'] ?? '');
    if ($title === '') {
        $errors[] = 'title is required.';
    }
    if ($title !== '' && mb_strlen($title) > 60) {
        $errors[] = 'title must be 60 characters or less.';
    }

    // --- Props ---
    /** @var list<array<string, mixed>> $props */
    $props = $input['props'] ?? [];
    $prop_result = validate_widget_props($props);
    array_push($errors, ...$prop_result['errors']);
    array_push($warnings, ...$prop_result['warnings']);

    // --- Twig ---
    $twig = (string) ($input['twig'] ?? '');
    if ($twig !== '') {
        $twig_result = validate_widget_twig($twig, $props);
        array_push($errors, ...$twig_result['errors']);
        array_push($warnings, ...$twig_result['warnings']);
    }

    // --- Assets (js, js_deps, css, css_deps) ---
    array_push($errors, ...validate_widget_assets($input));

    // --- Icon ---
    $icon = (string) ($input['icon'] ?? '');
    if ($icon !== '' && !str_starts_with($icon, 'eicon-')) {
        $errors[] = "icon must start with 'eicon-', got '{$icon}'.";
    }

    // --- Elementor v4 availability ---
    array_push($errors, ...validate_elementor_environment());

    return ['errors' => $errors, 'warnings' => $warnings];
}

/**
 * Validate the asset-related inputs (js, js_deps, css, css_deps) as a group.
 * Extracted so `validate_widget_input` stays under the cyclomatic complexity
 * threshold as more asset types are added.
 *
 * @param array<string, mixed> $input
 * @return list<string>
 */
function validate_widget_assets(array $input): array
{
    $errors = [];

    $js = (string) ($input['js'] ?? '');
    if ($js !== '') {
        array_push($errors, ...validate_widget_js($js));
    }

    /** @var list<string> $js_deps */
    $js_deps = $input['js_deps'] ?? [];
    if ($js_deps !== []) {
        array_push($errors, ...validate_widget_js_deps($js_deps));
    }

    $css = (string) ($input['css'] ?? '');
    if ($css !== '') {
        array_push($errors, ...validate_widget_css($css));
    }

    /** @var list<string> $css_deps */
    $css_deps = $input['css_deps'] ?? [];
    if ($css_deps !== []) {
        array_push($errors, ...validate_widget_css_deps($css_deps));
    }

    return $errors;
}

/**
 * Validate widget name.
 *
 * @return list<string>
 */
function validate_widget_name(string $name): array
{
    $errors = [];

    if ($name === '') {
        $errors[] = 'name is required.';
        return $errors;
    }

    if (!preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
        $errors[] = "name must be kebab-case (lowercase letters, numbers, hyphens), got '{$name}'.";
    }

    if (mb_strlen($name) > 50) {
        $errors[] = 'name must be 50 characters or less.';
    }

    if (mb_strlen($name) < 3) {
        $errors[] = 'name must be at least 3 characters.';
    }

    if (in_array($name, reserved_element_types(), strict: true)) {
        $errors[] = "name '{$name}' is reserved by Elementor. Choose a different name.";
    }

    return $errors;
}

/**
 * Validate a single prop's default value based on its type.
 *
 * @param scalar|null $default
 * @param list<string> $errors
 */
function vwp_validate_prop_default(string $prefix, string $prop_type, mixed $default, array &$errors): void
{
    if ($prop_type === 'number') {
        if ($default !== null && !is_numeric($default)) {
            $errors[] = "{$prefix}.default must be numeric for number props.";
        }
        return;
    }

    if ($prop_type === 'boolean') {
        if ($default !== null && !is_bool($default)) {
            $errors[] = "{$prefix}.default must be a boolean for boolean props.";
        }
        return;
    }

    if ($default !== null && !is_string($default)) {
        $errors[] = "{$prefix}.default must be a string.";
    }
}

/**
 * Validate select prop options.
 *
 * @param array<string, mixed> $prop
 * @param list<string> $errors
 */
function vwp_validate_prop_options(string $prefix, array $prop, array &$errors): void
{
    /** @var list<array<string, mixed>|scalar|null>|null $options */
    $options = $prop['options'] ?? null;
    if (!is_array($options) || $options === []) {
        $errors[] = "{$prefix}.options is required for select type and must contain at least 1 item.";
        return;
    }

    foreach ($options as $opt_index => $opt) {
        $opt_prefix = "{$prefix}.options[{$opt_index}]";
        if (!is_array($opt)) {
            $errors[] = "{$opt_prefix} must be an object with value and label.";
            continue;
        }
        /** @var string|null $opt_value */
        $opt_value = $opt['value'] ?? null;
        /** @var string|null $opt_label */
        $opt_label = $opt['label'] ?? null;
        if (!is_string($opt_value) || $opt_value === '') {
            $errors[] = "{$opt_prefix}.value must be a non-empty string.";
        }
        if (!is_string($opt_label) || $opt_label === '') {
            $errors[] = "{$opt_prefix}.label must be a non-empty string.";
        }
    }
}

/**
 * Validate the name field of a prop entry. Returns the resolved prop name (or empty string on failure).
 *
 * @param array<string, bool> $seen_names
 * @param list<string> $errors
 */
function vwp_validate_prop_name(string $prefix, array $prop, array &$seen_names, array &$errors): string
{
    $prop_name = (string) ($prop['name'] ?? '');
    if ($prop_name === '') {
        $errors[] = "{$prefix}.name is required.";
        return '';
    }

    if (!preg_match('/^[a-z][a-z0-9_]*$/', $prop_name)) {
        $errors[] = "{$prefix}.name must be snake_case (lowercase letters, numbers, underscores), got '{$prop_name}'.";
    }

    if (in_array($prop_name, WPPILOT_RESERVED_PROP_NAMES, strict: true)) {
        $errors[] = "{$prefix}.name '{$prop_name}' is reserved by Elementor. Choose a different name.";
    }

    if (array_key_exists($prop_name, $seen_names)) {
        $errors[] = "{$prefix}.name '{$prop_name}' is duplicated. Each prop must have a unique name.";
    }
    $seen_names[$prop_name] = true;

    return $prop_name;
}

/**
 * Validate type-specific constraints for a prop (select options, number range, email/image defaults).
 *
 * @param array<string, mixed> $prop
 * @param scalar|null $default
 * @param list<string> $errors
 */
function vwp_validate_prop_type_specifics(
    string $prefix,
    string $prop_type,
    array $prop,
    mixed $default,
    array &$errors,
): void {
    if (
        is_string($default)
        && $prop_type === 'image'
        && $default !== ''
        && !filter_var($default, FILTER_VALIDATE_URL)
    ) {
        $errors[] = "{$prefix}.default must be a valid URL for image props.";
    }

    if ($prop_type === 'select') {
        vwp_validate_prop_options($prefix, $prop, $errors);
    }

    if ($prop_type === 'number') {
        /** @var float|int|null $min */
        $min = $prop['min'] ?? null;
        /** @var float|int|null $max */
        $max = $prop['max'] ?? null;
        if ($min !== null && $max !== null && (float) $min >= (float) $max) {
            $errors[] = "{$prefix}.min must be less than {$prefix}.max.";
        }
    }

    if ($prop_type === 'email' && is_string($default) && $default !== '') {
        if (!filter_var($default, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "{$prefix}.default must be a valid email address for email props.";
        }
    }
}

/**
 * Validate a single prop entry in the props array.
 *
 * @param array<string, mixed> $prop
 * @param array<string, bool> $seen_names
 * @param list<string> $errors
 * @param list<string> $warnings
 */
function vwp_validate_prop_entry(
    string $prefix,
    array $prop,
    array &$seen_names,
    array &$errors,
    array &$warnings,
): void {
    $prop_name = vwp_validate_prop_name($prefix, $prop, $seen_names, $errors);
    if ($prop_name === '') {
        return;
    }

    // Type.
    $prop_type = (string) ($prop['type'] ?? '');
    if ($prop_type === '') {
        $errors[] = "{$prefix}.type is required.";
    }
    if ($prop_type !== '' && !in_array($prop_type, WPPILOT_VALID_PROP_TYPES, strict: true)) {
        $errors[] =
            "{$prefix}.type must be one of: " . implode(', ', WPPILOT_VALID_PROP_TYPES) . ". Got '{$prop_type}'.";
    }

    // Label.
    $label = (string) ($prop['label'] ?? '');
    if ($label !== '' && mb_strlen($label) > 60) {
        $errors[] = "{$prefix}.label must be 60 characters or less.";
    }

    // Default.
    /** @var scalar|null $default */
    $default = $prop['default'] ?? null;

    vwp_validate_prop_default($prefix, $prop_type, $default, $errors);

    // 'url' type removed — use 'link' for clickable links or 'string' for raw URLs.
    vwp_validate_prop_type_specifics($prefix, $prop_type, $prop, $default, $errors);

    // Warning: default not set on prop types where no sensible fallback is
    // baked into the generated schema. `image` gets Elementor's placeholder,
    // `number`/`boolean` get zero-values, `html` gets "Enter text here",
    // `date_time` is always user-supplied — warning on those is noise.
    if ($default === null && in_array($prop_type, WPPILOT_DEFAULTLESS_PROP_TYPES, strict: true)) {
        $warnings[] = sprintf(
            '%s ("%s").default is not set — the control renders empty by default and any Dynamic Tag attached to it has nothing to fall back to when the tag resolves to blank. Pass a string/URL/hex default if the widget should render cleanly without editor input.',
            $prefix,
            $prop_name,
        );
    }
}

/**
 * Validate widget props array.
 *
 * @param list<array<string, mixed>> $props
 * @return array{errors: list<string>, warnings: list<string>}
 */
function validate_widget_props(array $props): array
{
    $errors = [];
    $warnings = [];

    if ($props === []) {
        $errors[] = 'props must contain at least one prop.';
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    if (count($props) > 20) {
        $errors[] = 'props must contain 20 or fewer items.';
    }

    $seen_names = [];

    foreach ($props as $index => $prop) {
        vwp_validate_prop_entry("props[{$index}]", $prop, $seen_names, $errors, $warnings);
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}

/**
 * Check Twig template for inline CSS properties that should be in base_styles.
 *
 * @param list<string> $errors
 */
function vwt_check_inline_styles(string $twig, array &$errors): void
{
    $styleable_props = [
        // Background
        'background',
        'background-color',
        'background-image',
        // Spacing
        'padding',
        'margin',
        // Border
        'border-radius',
        'border',
        'border-width',
        'border-color',
        'border-style',
        // Typography
        'font-size',
        'font-weight',
        'font-family',
        'line-height',
        'letter-spacing',
        'text-align',
        'text-decoration',
        'text-transform',
        // Color
        'color',
        // Effects
        'box-shadow',
        'opacity',
        'filter',
        'backdrop-filter',
        // Dimensions
        'width',
        'height',
        'min-width',
        'min-height',
        'max-width',
        'max-height',
    ];
    $found_inline = [];
    foreach ($styleable_props as $css_prop) {
        if (!preg_match('/style="[^"]*' . preg_quote($css_prop, delimiter: '/') . '\s*:/', $twig)) {
            continue;
        }

        $found_inline[] = $css_prop;
    }
    if ($found_inline !== []) {
        $errors[] =
            'twig template has inline styles for properties that should live outside the template: '
            . implode(', ', $found_inline)
            . '. Pass them in the "base_styles" input (CSS prop → value map) if you want users to edit them in the Style tab, '
            . 'or put them in the "css" input (linked stylesheet) for static shell styling.';
    }
}

/**
 * Check that Twig settings references match declared props.
 *
 * @param list<array<string, mixed>> $props
 * @param list<string> $errors
 */
function vwt_check_settings_refs(string $twig, array $props, array &$errors): void
{
    // Internal props (classes, _cssid) are always added by the generator.
    $internal_props = ['classes', '_cssid'];
    $matches = null;
    preg_match_all('/settings\.([a-z_][a-z0-9_]*)/', $twig, $matches);
    if ($matches[1] === []) {
        return;
    }

    $prop_names = array_merge(array_column($props, 'name'), $internal_props);
    $referenced = array_unique($matches[1]);
    foreach ($referenced as $ref) {
        if (in_array($ref, $prop_names, strict: true)) {
            continue;
        }

        $errors[] = "twig template references 'settings.{$ref}' but no prop named '{$ref}' is defined. Add it to props or fix the template.";
    }
}

/**
 * Validate custom Twig template.
 *
 * @param list<array<string, mixed>> $props
 * @return array{errors: list<string>, warnings: list<string>}
 */
function validate_widget_twig(string $twig, array $props): array
{
    $errors = [];
    $warnings = [];

    if (mb_strlen($twig) > 10_000) {
        $errors[] = 'twig template must be 10,000 characters or less.';
    }

    // Check for dangerous patterns.
    foreach (WPPILOT_TWIG_BLOCKED_PATTERNS as $pattern) {
        if (!str_contains($twig, $pattern)) {
            continue;
        }

        $errors[] = "twig template contains blocked pattern: '{$pattern}'.";
    }

    // Check that | raw is preceded by striptags.
    $raw_matches = null;
    if (preg_match_all('/\|\s*raw\s*\}\}/', $twig, $raw_matches, PREG_OFFSET_CAPTURE)) {
        foreach ($raw_matches[0] as $match) {
            $offset = (int) $match[1];
            // Look at the 200 characters before | raw to find striptags.
            $before = substr($twig, max(0, $offset - 200), min($offset, 200));
            if (!str_contains($before, 'striptags')) {
                $errors[] = "twig template uses '| raw' without preceding 'striptags' filter. Use '| striptags | raw' for safety.";
                break;
            }
        }
    }

    vwt_check_settings_refs($twig, $props, $errors);

    // Check balanced Twig tags.
    $unused1 = null;
    $opens = preg_match_all('/\\{%/', $twig, $unused1);
    $unused2 = null;
    $closes = preg_match_all('/%\\}/', $twig, $unused2);
    if ($opens !== $closes) {
        $errors[] = 'twig template has unbalanced {% %} tags.';
    }

    vwt_check_inline_styles($twig, $errors);

    // Inline <style> blocks duplicate per widget instance on the rendered page.
    // Push agents toward the `css` parameter instead, which emits a single
    // linked stylesheet loaded once per page.
    if (preg_match('/<style\b/i', $twig)) {
        $warnings[] =
            'twig template contains a <style> block. Move widget-scoped styles into the "css" parameter — '
            . 'inline <style> duplicates on every widget instance rendered on the page.';
    }

    // A custom Twig that omits base_styles.base silently breaks the Style tab:
    // define_base_styles() and user-added Style-tab edits target the widget's
    // -base class, which only lands on the rendered element if the template
    // merges base_styles.base into the root element's classes.
    if (!str_contains($twig, 'base_styles.base')) {
        $warnings[] =
            'twig template does not reference base_styles.base — the widget\'s Style tab will not apply. '
            . 'The outermost element must carry base_styles.base (and typically settings.classes). Idiomatic first line: '
            . '{% set classes = settings.classes | merge( [ base_styles.base ] ) | join(\' \') %} '
            . 'then <div class="{{ classes }}">.';
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}

/**
 * Validate custom JS code.
 *
 * @return list<string>
 */
function validate_widget_js(string $js): array
{
    $errors = [];

    if (mb_strlen($js) > 50_000) {
        $errors[] = 'js must be 50,000 characters or less.';
    }

    foreach (WPPILOT_JS_BLOCKED_PATTERNS as $pattern) {
        if (!str_contains($js, $pattern)) {
            continue;
        }

        $errors[] = "js contains blocked pattern: '{$pattern}'.";
    }

    // Check for eval( separately to keep it out of the constant array.
    if (str_contains($js, 'eval(')) {
        $errors[] = "js contains blocked pattern: 'eval('.";
    }

    return $errors;
}

/**
 * Validate JS dependency handles/URLs.
 *
 * @param list<mixed> $js_deps
 * @return list<string>
 */
function validate_widget_js_deps(array $js_deps): array
{
    $errors = [];
    /** @var list<scalar|null> $js_deps */

    if (count($js_deps) > 10) {
        $errors[] = 'js_deps must contain 10 or fewer items.';
    }

    foreach ($js_deps as $index => $dep) {
        $prefix = "js_deps[{$index}]";

        if (!is_string($dep) || $dep === '') {
            $errors[] = "{$prefix} must be a non-empty string.";
            continue;
        }

        // If it looks like a URL, must be https.
        if (str_contains($dep, '://')) {
            if (!str_starts_with($dep, 'https://')) {
                $errors[] = "{$prefix} URL must start with https://, got '{$dep}'.";
            }
            continue;
        }

        // Must be a valid WP handle.
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $dep)) {
            $errors[] = "{$prefix} handle must match /^[a-z][a-z0-9-]*$/, got '{$dep}'.";
        }
    }

    return $errors;
}

/**
 * Validate custom CSS. Unlike JS, CSS is written to a dedicated `.css` file
 * and loaded via `<link rel="stylesheet">`, so it cannot escape into an HTML
 * context — we only enforce a length ceiling here.
 *
 * @return list<string>
 */
function validate_widget_css(string $css): array
{
    $errors = [];

    if (mb_strlen($css) > 100_000) {
        $errors[] = 'css must be 100,000 characters or less.';
    }

    return $errors;
}

/**
 * Validate CSS dependency handles/URLs. Mirrors `validate_widget_js_deps`:
 * registered WP style handles or `https://` URLs only.
 *
 * @param list<mixed> $css_deps
 * @return list<string>
 */
function validate_widget_css_deps(array $css_deps): array
{
    $errors = [];
    /** @var list<scalar|null> $css_deps */

    if (count($css_deps) > 10) {
        $errors[] = 'css_deps must contain 10 or fewer items.';
    }

    foreach ($css_deps as $index => $dep) {
        $prefix = "css_deps[{$index}]";

        if (!is_string($dep) || $dep === '') {
            $errors[] = "{$prefix} must be a non-empty string.";
            continue;
        }

        // If it looks like a URL, must be https.
        if (str_contains($dep, '://')) {
            if (!str_starts_with($dep, 'https://')) {
                $errors[] = "{$prefix} URL must start with https://, got '{$dep}'.";
            }
            continue;
        }

        // Must be a valid WP handle.
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $dep)) {
            $errors[] = "{$prefix} handle must match /^[a-z][a-z0-9-]*$/, got '{$dep}'.";
        }
    }

    return $errors;
}

/**
 * Validate that Elementor v4 atomic widgets are available.
 *
 * @return list<string>
 */
function validate_elementor_environment(): array
{
    $errors = [];

    if (!defined('ELEMENTOR_VERSION')) {
        $errors[] = 'Elementor is not installed or not active.';
        return $errors;
    }

    if (version_compare((string) ELEMENTOR_VERSION, version2: '4.0.0-beta1', operator: '<')) {
        $errors[] =
            'Elementor v4+ is required for atomic widgets. Current version: ' . (string) ELEMENTOR_VERSION . '.';
    }

    // Check that atomic widgets experiment is active.
    if (class_exists('\\Elementor\\Plugin')) {
        $experiments = \Elementor\Plugin::$instance->experiments;
        if (!$experiments->is_feature_active('e_atomic_elements')) {
            $errors[] = 'The "Atomic Widgets" experiment must be enabled in Elementor > Settings > Features.';
        }
    }

    // Check that the base class file exists.
    $base_file = WP_PLUGIN_DIR . '/elementor/modules/atomic-widgets/elements/base/atomic-widget-base.php';
    if (!file_exists($base_file)) {
        $errors[] = 'Atomic_Widget_Base class file not found. Elementor installation may be corrupted.';
    }

    return $errors;
}
