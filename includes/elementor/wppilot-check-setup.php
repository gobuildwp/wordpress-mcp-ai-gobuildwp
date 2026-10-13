<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

/**
 * Ability: Check Elementor setup and report environment status.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wppilot/elementor-check-setup', [
    'label' => __('Check Elementor Setup', domain: 'wppilot'),
    'description' => __(
        'Reports the Elementor environment: core version, Pro version, whether the v4 atomic runtime is available (and its sub-features: style schema, global classes, variables, interactions), active kit values (container_width, container_padding, enabled breakpoints), and a list of detected configuration issues. Call this BEFORE a conversion so you know which features to rely on.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'default' => [],
        'properties' => [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'elementor' => [
                'type' => 'object',
                'properties' => [
                    'active' => ['type' => 'boolean'],
                    'version' => ['type' => 'string'],
                    'min_version_met' => ['type' => 'boolean'],
                ],
            ],
            'elementor_pro' => [
                'type' => 'object',
                'properties' => [
                    'active' => ['type' => 'boolean'],
                    'version' => ['type' => 'string'],
                ],
            ],
            'atomic' => [
                'type' => 'object',
                'properties' => [
                    'runtime_available' => ['type' => 'boolean'],
                    'style_schema_available' => ['type' => 'boolean'],
                    'global_classes_available' => ['type' => 'boolean'],
                    'variables_available' => ['type' => 'boolean'],
                    'interactions_available' => ['type' => 'boolean'],
                ],
            ],
            'kit' => [
                'type' => 'object',
                'properties' => [
                    'container_width' => [
                        'type' => ['object', 'null'],
                        'description' => 'The kit-level max-width for boxed containers, as `{unit, size}`, or null when unset.',
                    ],
                    'container_padding' => [
                        'type' => ['object', 'null'],
                        'description' => 'The kit-level default container padding, as `{unit, top, right, bottom, left, isLinked}`, or null when unset.',
                    ],
                    'active_breakpoints' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Elementor v4 breakpoint names currently enabled on the site (e.g. `desktop`, `tablet`, `mobile`, `widescreen`, `laptop`, `tablet_extra`, `mobile_extra`).',
                    ],
                ],
            ],
            'issues' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'List of detected configuration issues or capability gaps.',
            ],
        ],
    ],
    'execute_callback' => 'WPPilot\\Elementor\\elementor_check_setup',
    'permission_callback' => 'wppilot_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => 'Run this at the start of any Elementor work. Key signals: elementor.active+min_version_met (required for everything), atomic.runtime_available (required for v4 atomic conversions and for any `e-*` widget), elementor_pro.active (required for Theme Builder templates, loop-grid, nav-menu, forms, and other Pro widgets — if Pro is NOT active, keep those widgets v3 and expect the page to fall back to default header/footer), kit.container_width (max-width used by the server for default-boxed v3 → v4 split). The `issues` array summarizes actionable warnings.',
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
    ],
]);

/**
 * @return array<string, mixed>
 */
function elementor_check_setup(): array
{
    $elementor = el_check_setup_elementor_block();
    $elementor_pro = el_check_setup_elementor_pro_block();
    $atomic = el_check_setup_atomic_block();
    $kit = el_check_setup_kit_block();
    $issues = el_check_setup_issues($elementor, $elementor_pro, $atomic);

    return [
        'elementor' => $elementor,
        'elementor_pro' => $elementor_pro,
        'atomic' => $atomic,
        'kit' => $kit,
        'issues' => $issues,
    ];
}

/**
 * @return array{active: bool, version: string, min_version_met: bool}
 */
function el_check_setup_elementor_block(): array
{
    $active = defined('ELEMENTOR_VERSION');
    $version = el_elementor_version();
    $min_version_met = el_min_runtime_available();
    return [
        'active' => $active,
        'version' => $version,
        'min_version_met' => $min_version_met,
    ];
}

/**
 * @return array{active: bool, version: string}
 */
function el_check_setup_elementor_pro_block(): array
{
    $active = defined('ELEMENTOR_PRO_VERSION');
    $version = $active ? (string) constant('ELEMENTOR_PRO_VERSION') : '';
    return [
        'active' => $active,
        'version' => $version,
    ];
}

/**
 * @return array{runtime_available: bool, style_schema_available: bool, global_classes_available: bool, variables_available: bool, interactions_available: bool}
 */
function el_check_setup_atomic_block(): array
{
    return [
        'runtime_available' => el_atomic_runtime_available(),
        'style_schema_available' => el_style_schema_available(),
        'global_classes_available' => el_global_classes_available(),
        'variables_available' => el_variables_available(),
        'interactions_available' => el_interactions_available(),
    ];
}

/**
 * @return array{container_width: array<string, mixed>|null, container_padding: array<string, mixed>|null, active_breakpoints: list<string>}
 */
function el_check_setup_kit_block(): array
{
    return [
        'container_width' => el_check_setup_kit_value('container_width'),
        'container_padding' => el_check_setup_kit_value('container_padding'),
        'active_breakpoints' => el_check_setup_active_breakpoints(),
    ];
}

/**
 * Read a single kit setting and normalize the return to array|null.
 *
 * @return array<string, mixed>|null
 */
function el_check_setup_kit_value(string $key): ?array
{
    if (!class_exists('Elementor\\Plugin')) {
        return null;
    }
    /** @var object $plugin */
    $plugin = \Elementor\Plugin::$instance;
    /** @var object|null $kits */
    $kits = $plugin->kits_manager ?? null;
    if (!is_object($kits) || !method_exists($kits, 'get_current_settings')) {
        return null;
    }
    /** @var mixed $val */
    $val = $kits->get_current_settings($key);
    if (!is_array($val)) {
        return null;
    }
    /** @var array<string, mixed> $val */
    return $val;
}

/**
 * Resolve which Elementor v4 breakpoints are enabled on this site.
 * `desktop` and `mobile` are always on. Other breakpoints appear in
 * the kit's `active_breakpoints` list when the admin has enabled them
 * under Site Settings → Layout → Breakpoints.
 *
 * @return list<string>
 */
function el_check_setup_active_breakpoints(): array
{
    $out = ['desktop', 'mobile'];
    if (!class_exists('Elementor\\Plugin')) {
        return $out;
    }
    /** @var object $plugin */
    $plugin = \Elementor\Plugin::$instance;
    /** @var object|null $kits */
    $kits = $plugin->kits_manager ?? null;
    if (!is_object($kits) || !method_exists($kits, 'get_current_settings')) {
        return $out;
    }
    /** @var mixed $active */
    $active = $kits->get_current_settings('active_breakpoints');
    if (!is_array($active)) {
        return $out;
    }
    foreach (array_keys($active) as $i) {
        /** @var mixed $bp */
        $bp = $active[$i];
        if (!is_string($bp) || $bp === '') {
            continue;
        }
        // Elementor prefixes the active entries with `viewport_` (e.g.
        // `viewport_tablet`). Strip the prefix for the v4 breakpoint
        // name.
        $name = str_starts_with($bp, 'viewport_') ? substr($bp, offset: strlen('viewport_')) : $bp;
        if ($name !== '' && !in_array($name, $out, strict: true)) {
            $out[] = $name;
        }
    }
    return $out;
}

/**
 * Compose the `issues` diagnostic list from the individual blocks.
 *
 * @param array{active: bool, version: string, min_version_met: bool} $elementor
 * @param array{active: bool, version: string} $elementor_pro
 * @param array{runtime_available: bool, style_schema_available: bool, global_classes_available: bool, variables_available: bool, interactions_available: bool} $atomic
 * @return list<string>
 */
function el_check_setup_issues(array $elementor, array $elementor_pro, array $atomic): array
{
    $issues = [];

    if (!$elementor['active']) {
        $issues[] = 'Elementor is not active. No Elementor abilities will work.';
        return $issues;
    }

    if (!$elementor['min_version_met']) {
        $issues[] = sprintf(
            'Elementor %s is older than the minimum supported version (%s). Some abilities may fail or behave unpredictably.',
            $elementor['version'],
            WPPILOT_ELEMENTOR_MIN_VERSION,
        );
    }

    $atomic_issue = el_check_setup_atomic_issue($atomic);
    if ($atomic_issue !== null) {
        $issues[] = $atomic_issue;
    }

    if (!$elementor_pro['active']) {
        $issues[] = 'Elementor Pro is not active. Theme Builder templates (header/footer/single-post) and Pro widgets (nav-menu, mega-menu, loop-grid, form, search-form, price-table, etc.) will not render. Keep those widgets as v3 on the source page and expect the site to fall back to the default header/footer.';
    }

    return $issues;
}

/**
 * Resolve the diagnostic issue line for the v4 atomic runtime — null when
 * the runtime is healthy, otherwise a targeted message that distinguishes
 * the "experiment OFF" case from the "module missing entirely" case.
 *
 * @param array{runtime_available: bool, style_schema_available: bool, global_classes_available: bool, variables_available: bool, interactions_available: bool} $atomic
 */
function el_check_setup_atomic_issue(array $atomic): ?string
{
    if ($atomic['runtime_available']) {
        return null;
    }

    if (el_atomic_runtime_classes_loaded_but_experiment_off()) {
        return 'v4 atomic runtime classes are loaded but the `e_atomic_elements` experiment is OFF on this site. The `e-*` widgets (e-heading, e-flexbox, e-paragraph, e-button, …) are not registered and add-element/edit-element will fail with "type not available". Enable Elementor → Settings → Features → "Atomic Widgets" (and optionally "Editor V4") to use the v4 atomic path. Without it, only the v3 path is usable on this site.';
    }

    return 'v4 atomic runtime is not available. All `e-*` atomic widgets and the v3 → v4 conversion skill will be unusable on this site.';
}
