<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor: dynamic tag values in element settings.
 *
 * A setting may be a literal or a dynamic tag reference; overrides are wrapped
 * into the prop envelope the widget's schema expects.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Extract v3 `__dynamic__` entries and convert them to v4 `{$$type: "dynamic"}`
 * values. Returns a map of setting key → dynamic value.
 *
 * @param array<string, mixed> $settings
 * @return array{overrides: array<string, array<string, mixed>>, errors: list<array{element_id: string, widget_type: string, setting_name: string, tag: mixed, reason: string}>}
 */
function el_build_dynamic_overrides(array $settings, string $element_id, string $widget_type): array
{
    /** @var array<string, mixed> $dynamic */
    $dynamic = is_array($settings['__dynamic__'] ?? null) ? $settings['__dynamic__'] : [];
    $overrides = [];
    $errors = [];
    foreach (array_keys($dynamic) as $setting_name) {
        /** @var mixed $tag */
        $tag = $dynamic[$setting_name];
        if (!is_string($tag) || $tag === '') {
            $errors[] = [
                'element_id' => $element_id,
                'widget_type' => $widget_type,
                'setting_name' => $setting_name,
                'tag' => $tag,
                'reason' => 'Expected a non-empty v3 dynamic tag string.',
            ];
            continue;
        }

        $parsed = el_parse_v3_dynamic_tag_result($tag)['parsed'] ?? null;
        if ($parsed === null) {
            $errors[] = [
                'element_id' => $element_id,
                'widget_type' => $widget_type,
                'setting_name' => $setting_name,
                'tag' => $tag,
                'reason' =>
                    el_parse_v3_dynamic_tag_result($tag)['error'] ?? 'Failed to parse the v3 dynamic tag string.',
            ];
            continue;
        }

        $overrides[$setting_name] = ['$$type' => 'dynamic', 'value' => $parsed];
    }
    return ['overrides' => $overrides, 'errors' => $errors];
}

/**
 * Coerce settings values applying dynamic overrides and {$$type, value} wrapping.
 *
 * @param array<string, mixed> $settings
 * @param array<string, mixed> $controls
 * @param array<string, array<string, mixed>> $dynamic_overrides
 * @return array<string, mixed>
 */
function el_coerce_settings_with_overrides(array $settings, array $controls, array $dynamic_overrides): array
{
    $coerced = [];
    foreach (array_keys($settings) as $key) {
        if ($key === '__dynamic__' || $key === '__globals__') {
            continue;
        }
        if (array_key_exists($key, $dynamic_overrides)) {
            /** @var array<string, mixed> $dyn_control */
            $dyn_control = is_array($controls[$key] ?? null) ? $controls[$key] : [];
            $coerced[$key] = el_wrap_dynamic_override_for_prop($dynamic_overrides[$key], $dyn_control);
            continue;
        }
        if (!array_key_exists($key, $controls)) {
            $coerced[$key] = $settings[$key];
            continue;
        }
        /** @var array<string, mixed> $control */
        $control = $controls[$key];
        $result = el_coerce_atomic_value($key, $settings[$key], $control);
        $coerced[$key] = $result['value'];
    }

    // Add dynamic overrides for keys not in settings (e.g. link from __dynamic__ only).
    foreach ($dynamic_overrides as $dk => $dv) {
        if (array_key_exists($dk, $coerced)) {
            continue;
        }
        /** @var array<string, mixed> $dk_control */
        $dk_control = is_array($controls[$dk] ?? null) ? $controls[$dk] : [];
        $coerced[$dk] = el_wrap_dynamic_override_for_prop($dv, $dk_control);
    }

    return $coerced;
}

/**
 * Wrap a dynamic override value for the target prop type. For most prop
 * types, the dynamic value replaces the entire prop. For `link`, the
 * dynamic tag goes inside `destination` because Elementor's dynamic tag
 * system supports `Url_Prop_Type` but not `Link_Prop_Type` directly.
 *
 * @param array<string, mixed> $dynamic_value  e.g. {$$type: "dynamic", value: {name, group, settings}}
 * @param array<string, mixed> $control        compact schema control for the target prop
 * @return array<string, mixed>
 */
function el_wrap_dynamic_override_for_prop(array $dynamic_value, array $control): array
{
    $prop_type = (string) ($control['t'] ?? '');
    if ($prop_type === 'link') {
        return [
            '$$type' => 'link',
            'value' => [
                'destination' => $dynamic_value,
                'isTargetBlank' => ['$$type' => 'boolean', 'value' => false],
                'tag' => ['$$type' => 'string', 'value' => 'a'],
            ],
        ];
    }
    return $dynamic_value;
}

/**
 * Parse a v3 dynamic tag string into the v4 {name, settings} shape.
 *
 * v3 format: `[elementor-tag id="xxx" name="tag-name" settings="url-encoded-json"]`
 * v4 format: `{name: "tag-name", settings: {key: value, ...}}`
 *
 * @return array{name: string, group: string, settings: array<string, mixed>}|null
 */
function el_parse_v3_dynamic_tag(string $tag): ?array
{
    $result = el_parse_v3_dynamic_tag_result($tag);
    return $result['parsed'] ?? null;
}

/**
 * Parse a v3 dynamic tag string and return either the normalized v4 payload
 * or a human-readable parse error.
 *
 * @return array{parsed: array{name: string, group: string, settings: array<string, mixed>}}|array{error: string}
 */
function el_parse_v3_dynamic_tag_result(string $tag): array
{
    // Match [elementor-tag ... name="xxx" ... settings="xxx" ...]
    if (!preg_match('/\[elementor-tag\s/', $tag)) {
        return ['error' => 'Expected a v3 [elementor-tag ...] string.'];
    }

    $name_matches = [];
    if (preg_match('/name="([^"]+)"/', $tag, $name_matches) !== 1) {
        return ['error' => 'Dynamic tag string is missing the name attribute.'];
    }
    $name = $name_matches[1];

    $settings = [];
    $settings_matches = [];
    if (preg_match('/settings="([^"]*)"/', $tag, $settings_matches) === 1) {
        $decoded = urldecode($settings_matches[1]);
        if ($decoded !== '') {
            /** @var array<string, mixed>|null $parsed */
            $parsed = json_decode(json: $decoded, associative: true);
            if (!is_array($parsed)) {
                return ['error' => 'Failed to decode the dynamic tag settings JSON.'];
            }
            // Wrap each setting value in {$$type: "string", value: ...}
            // to match the v4 format the editor produces.
            array_walk($parsed, static function (mixed $value, string $key) use (&$settings): void {
                if (!is_string($value)) {
                    $settings[$key] = $value;
                    return;
                }
                $settings[$key] = ['$$type' => 'string', 'value' => $value];
            });
        }
    }

    // Resolve the tag group from the Elementor registry.
    $group = el_resolve_dynamic_tag_group($name);

    return ['parsed' => ['name' => $name, 'group' => $group, 'settings' => $settings]];
}

/**
 * Look up the group of a dynamic tag by name from the Elementor registry.
 */
function el_resolve_dynamic_tag_group(string $tag_name): string
{
    if (!class_exists('Elementor\\Plugin')) {
        return '';
    }

    /** @var object $plugin */
    $plugin = \Elementor\Plugin::$instance;
    /** @var object|null $dt_manager */
    $dt_manager = $plugin->dynamic_tags ?? null;
    if (!is_object($dt_manager) || !method_exists($dt_manager, 'get_tag_info')) {
        return '';
    }

    /** @var array<string, mixed>|null $info */
    $info = $dt_manager->get_tag_info($tag_name);
    if (!is_array($info)) {
        return '';
    }

    // The group is on the tag instance, not in the info array.
    /** @var object|null $instance */
    $instance = $info['instance'] ?? null;
    if (is_object($instance) && method_exists($instance, 'get_group')) {
        /** @var mixed $group */
        $group = $instance->get_group();
        if (is_string($group) && $group !== '') {
            return $group;
        }
        // Some tags return group as an array.
        if (is_array($group) && $group !== []) {
            return (string) $group[0];
        }
    }

    return '';
}
