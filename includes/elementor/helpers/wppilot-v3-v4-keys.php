<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Elementor: telling v3 and v4 settings keys apart.
 *
 * A document can mix classic and atomic widgets, and responsive keys are
 * suffixed per breakpoint, so key classification decides which validator a
 * setting is routed to.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Decide how `el_validate_settings()` should treat a single settings key
 * on an ATOMIC (v4) widget. Atomic schemas are exhaustive — anything not
 * present in the controls map is unknown and dropped. The `key` field is
 * always populated (echoes the input on `drop`) so the return shape is
 * uniform with the v3 classifier and downstream code can read it without
 * a presence check.
 *
 * @param array<string, mixed> $schema_controls
 * @return array{action: 'drop'|'validate_against', key: string}
 */
function el_classify_atomic_settings_key(string $key, array $schema_controls): array
{
    if (array_key_exists($key, $schema_controls)) {
        return ['action' => 'validate_against', 'key' => $key];
    }
    return ['action' => 'drop', 'key' => $key];
}

/**
 * Decide how `el_validate_settings()` should treat a single settings key
 * on a v3 widget or container. Returns one of three actions, with a flat
 * uniform shape so the validator loop can branch via early `continue`s:
 *
 *  - `drop`            : key is unknown and unrecoverable.
 *  - `accept_raw`      : key is a known v3 passthrough meta-key (margin /
 *                        padding / dynamic / globals / …); persist as-is.
 *  - `validate_against`: key resolves to a control in the schema (either
 *                        directly, or as a responsive `<base>_<bp>` variant
 *                        of a control flagged `r:1`); `key` is the control
 *                        id to validate against.
 *
 * @param array<string, mixed> $schema_controls
 * @return array{action: 'drop'|'accept_raw'|'validate_against', key: string}
 */
function el_classify_v3_settings_key(string $key, array $schema_controls): array
{
    if (array_key_exists($key, $schema_controls)) {
        return ['action' => 'validate_against', 'key' => $key];
    }
    if (el_is_v3_passthrough_key($key)) {
        return ['action' => 'accept_raw', 'key' => $key];
    }
    $base_key = el_v3_resolve_responsive_base($key, $schema_controls);
    if ($base_key !== null) {
        return ['action' => 'validate_against', 'key' => $base_key];
    }
    return ['action' => 'drop', 'key' => $key];
}

/**
 * If `$key` looks like a v3 responsive variant of a base control in
 * `$schema_controls` (e.g. `padding_tablet`, `typography_font_size_mobile`),
 * return the base key name. Otherwise return null.
 *
 * The base control must be present in the compact schema AND carry the
 * `r` flag emitted by `extract_control_responsive_flag()`. Two flag
 * shapes are honored:
 *
 *  - `r: 1`                           — the control is responsive on
 *                                       every breakpoint; any suffix is
 *                                       accepted.
 *  - `r: {min?: <bp>, max?: <bp>}`    — the control is restricted; the
 *                                       suffix breakpoint must lie within
 *                                       the [min, max] window in the
 *                                       canonical size ordering.
 *
 * The size ordering is the one Elementor uses internally (mobile up to
 * widescreen, see `el_v3_breakpoint_size_order`). Unknown shapes — or
 * absent flags — cause the suffix to be treated as unknown so we do not
 * accidentally accept coincidental `_tablet` substrings on non-responsive
 * controls.
 *
 * @param array<string, mixed> $schema_controls
 */
function el_v3_resolve_responsive_base(string $key, array $schema_controls): ?string
{
    $resolved = el_v3_resolve_breakpoint_key($key);
    if ($resolved['breakpoint'] === null) {
        return null;
    }
    $base = $resolved['base'];
    if (!array_key_exists($base, $schema_controls)) {
        return null;
    }
    /** @var mixed $control */
    $control = $schema_controls[$base];
    if (!is_array($control)) {
        return null;
    }
    /** @var mixed $flag */
    $flag = $control['r'] ?? null;
    if ($flag === 1) {
        return $base;
    }
    if (!is_array($flag)) {
        return null;
    }
    /** @var array<string, mixed> $flag */
    if (el_v3_breakpoint_in_range($resolved['breakpoint'], $flag)) {
        return $base;
    }
    return null;
}

/**
 * Whether `$breakpoint` falls within the {min, max} window declared by a
 * compact `r` flag. Both bounds are inclusive; either may be absent.
 * Unknown breakpoint names (e.g. a future Elementor addition we do not
 * have in the canonical order yet) are accepted permissively rather than
 * silently dropped.
 *
 * @param array<string, mixed> $constraints
 */
function el_v3_breakpoint_in_range(string $breakpoint, array $constraints): bool
{
    $order = el_v3_breakpoint_size_order();
    if (!array_key_exists($breakpoint, $order)) {
        return true;
    }
    $idx = $order[$breakpoint];

    /** @var mixed $min */
    $min = $constraints['min'] ?? null;
    if (is_string($min) && array_key_exists($min, $order) && $idx < $order[$min]) {
        return false;
    }

    /** @var mixed $max */
    $max = $constraints['max'] ?? null;
    if (is_string($max) && array_key_exists($max, $order) && $idx > $order[$max]) {
        return false;
    }

    return true;
}

/**
 * Canonical size ordering for Elementor v4 breakpoint names — smallest
 * viewport first. Mirrors the default pixel ranges declared in
 * `core/breakpoints/manager::get_default_config` (mobile 767, mobile_extra
 * 880, tablet 1024, tablet_extra 1200, laptop 1366, then desktop as the
 * implicit base above laptop, and widescreen above 2400).
 *
 * @return array<string, int>
 */
function el_v3_breakpoint_size_order(): array
{
    return [
        'mobile' => 0,
        'mobile_extra' => 1,
        'tablet' => 2,
        'tablet_extra' => 3,
        'laptop' => 4,
        'desktop' => 5,
        'widescreen' => 6,
    ];
}

/**
 * Check whether a settings key is a well-known Elementor meta-key that
 * should be passed through for v3 widgets even though it does not appear
 * in the compact schema extracted from widget controls.
 */
function el_is_v3_passthrough_key(string $key): bool
{
    /** @var list<string> */
    static $prefixes = [
        '__dynamic__',
        '__globals__',
        'custom_css',
        '_margin',
        '_padding',
        '_element_width',
        '_css_classes',
        '_title',
        '_z_index',
        '_flex_align_self',
        // A classic column's width: written by the editor into every column and
        // read by the column's render, but never registered as a control.
        '_column_size',
    ];
    foreach ($prefixes as $prefix) {
        if ($key === $prefix || str_starts_with($key, $prefix . '_')) {
            return true;
        }
    }
    return false;
}
