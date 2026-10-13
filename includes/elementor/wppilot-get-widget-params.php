<?php

// SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace WPPilot\Elementor;

use WP_Error;

/**
 * Ability: the settings of a widget that a build actually uses.
 *
 * `wppilot/elementor-get-schema` answers with everything, which for a heading
 * is 186 controls and 22kb — the widget's four real settings buried under
 * motion effects, sticky behaviour, transform popovers, hover backgrounds,
 * per-breakpoint duplicates and the editor's own bookkeeping. Elementor's own
 * `tab` metadata does not separate them: it files motion_fx, sticky and the
 * background group under `content` alongside the heading text.
 *
 * So the split is made here, by rule rather than by a hand-written catalog.
 * A curated list maintained by hand — the approach the competition takes —
 * drifts the moment Elementor ships a control, and says nothing about the
 * addon widgets on this particular site. These rules read whatever is
 * registered right now, which means a third-party widget gets the same
 * treatment as a core one on the day it is installed.
 *
 * Nothing is hidden silently: the response reports how many controls were set
 * aside and under which rule, and `wppilot/elementor-get-schema` still returns
 * the complete set for anything this view leaves out.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Control-name prefixes that belong to Elementor's chrome rather than to the
 * widget's own job.
 *
 * @return list<string>
 */
function el_param_noise_prefixes(): array
{
    return [
        '_',                    // editor bookkeeping: _title, _element_id, _css_classes, _transform_*, _background_*
        'motion_fx',            // scroll and mouse effects
        'sticky',               // sticky behaviour
        'hide_',                // per-device visibility
        'handle_',              // asset-loading toggles
        'e_display_conditions', // per-element display conditions
        'animation',            // entrance animation timing
    ];
}

/**
 * Name fragments that mark a control as appearance rather than substance.
 *
 * Elementor registers the background group — colour, image, video, slideshow,
 * ken burns — and hover appearance under the content tab on widgets like the
 * button, so a tab filter never separates them. On a button they are 33 of the
 * 39 controls that otherwise survive, and none of them is what somebody means
 * by "add a button".
 *
 * @return list<string>
 */
function el_param_appearance_fragments(): array
{
    return ['background_', 'hover_animation', '_hover_color', 'hover_transition'];
}

/**
 * Is this control part of the widget's own job, or Elementor's chrome?
 *
 * @param array<string, mixed> $entry
 */
function el_param_is_essential(string $name, array $entry, bool $include_styles): bool
{
    foreach (el_param_noise_prefixes() as $prefix) {
        if (str_starts_with($name, $prefix)) {
            return false;
        }
    }

    if (!$include_styles) {
        foreach (el_param_appearance_fragments() as $fragment) {
            if (str_contains($name, $fragment)) {
                return false;
            }
        }
    }

    // Responsive duplicates carry the extractor's `r` marker. The base control
    // is kept and says everything the suffixed ones repeat per breakpoint.
    if (array_key_exists('r', $entry)) {
        return false;
    }

    return true;
}

wp_register_ability('wppilot/elementor-get-widget-params', [
    'label' => __('Get Elementor Widget Params', domain: 'wppilot'),
    'description' => __(
        'Returns the settings of an Elementor widget that a build actually sets — the heading\'s text, link, tag and size — instead of the complete control list, which for one heading is 186 controls and around 22kb of motion effects, sticky rules, transform popovers, hover backgrounds and per-breakpoint duplicates. Use this before adding or editing a widget; reach for wppilot/elementor-get-schema when you need a control this view sets aside, such as a motion effect or a per-device override. Works on any registered widget, including ones an addon pack installed, because the rules read the live registry rather than a fixed catalog. The response says how many controls were set aside and why, so nothing disappears quietly.',
        domain: 'wppilot',
    ),
    'category' => 'elementor',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'widget_types' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'minItems' => 1,
                'maxItems' => 20,
                'description' => 'Widget types to describe, e.g. ["heading", "button"]. Names come from wppilot/elementor-get-schema with action "list".',
            ],
            'include_styles' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Also include style-tab controls that survive the noise rules. Off by default — styling should come from the design system and global classes, not per-widget values.',
            ],
        ],
        'required' => ['widget_types'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'widgets' => [
                'type' => 'object',
                'description' => 'Keyed by widget type. Each carries label, params, and what was set aside.',
            ],
            'unknown' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Requested types that are not registered on this site.',
            ],
        ],
        'required' => ['widgets'],
    ],
    'execute_callback' => 'WPPilot\\Elementor\\elementor_get_widget_params',
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
function elementor_get_widget_params(array $input): array|WP_Error
{
    if (!el_min_runtime_available()) {
        return new WP_Error(
            'elementor_required',
            __('Elementor is not active on this site, or is below the minimum supported version.', domain: 'wppilot'),
            ['status' => 400],
        );
    }

    /** @var mixed $raw_types */
    $raw_types = $input['widget_types'] ?? [];
    if (!is_array($raw_types) || $raw_types === []) {
        return new WP_Error(
            'elementor_missing_widget_types',
            __('widget_types must list at least one widget type.', domain: 'wppilot'),
            ['status' => 422],
        );
    }

    $include_styles = ($input['include_styles'] ?? false) === true;

    /** @var array<string, mixed> $widgets */
    $widgets = [];
    /** @var list<string> $unknown */
    $unknown = [];

    /** @var mixed $raw_type */
    foreach ($raw_types as $raw_type) {
        $widget_type = is_string($raw_type) ? trim($raw_type) : '';
        if ($widget_type === '') {
            continue;
        }

        $schema = resolve_compact_schema($widget_type, ['include_styles' => $include_styles]);
        if ($schema === null) {
            $unknown[] = $widget_type;
            continue;
        }

        /** @var mixed $controls_raw */
        $controls_raw = $schema['controls'] ?? [];
        $controls = is_array($controls_raw) ? $controls_raw : [];

        /** @var array<string, mixed> $params */
        $params = [];
        $set_aside_chrome = 0;
        $set_aside_responsive = 0;

        /** @var mixed $entry */
        foreach ($controls as $cid => $entry) {
            $name = (string) $cid;
            $shaped = is_array($entry) ? $entry : [];
            if (el_param_is_essential($name, $shaped, $include_styles)) {
                $params[$name] = $shaped;
                continue;
            }
            if (array_key_exists('r', $shaped)) {
                $set_aside_responsive++;
                continue;
            }
            $set_aside_chrome++;
        }

        $widgets[$widget_type] = [
            'label' => (string) ($schema['label'] ?? $widget_type),
            'description' => (string) ($schema['description'] ?? ''),
            'is_atomic' => ($schema['is_atomic'] ?? false) === true,
            'params' => $params,
            'param_count' => count($params),
            'set_aside' => [
                'chrome' => $set_aside_chrome,
                'responsive_variants' => $set_aside_responsive,
                'total_controls' => count($controls),
                'note' => 'Set-aside controls are still reachable through wppilot/elementor-get-schema.',
            ],
        ];
    }

    if ($widgets === []) {
        return new WP_Error(
            'elementor_unknown_widget_types',
            sprintf(
                /* translators: %s: comma-separated widget types */
                __('None of these widget types are registered on this site: %s.', domain: 'wppilot'),
                implode(', ', $unknown),
            ),
            ['status' => 404, 'unknown' => $unknown],
        );
    }

    return ['widgets' => $widgets, 'unknown' => $unknown];
}
